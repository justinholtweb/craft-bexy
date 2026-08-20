<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use justinholtweb\bexy\helpers\Address;
use justinholtweb\bexy\helpers\Money;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\models\DocumentPayload;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\Plugin;

/**
 * The one place a Commerce order becomes a bexio document body.
 *
 * The CP preview, `bexy/sync/preview` and the real push all come through here, so what a merchant
 * is shown before they click is byte-identical to what bexio receives after they do.
 */
class Builder extends Component
{
    /** bexio's position type names. They are sent verbatim in `type`. */
    public const POSITION_CUSTOM = 'KbPositionCustom';
    public const POSITION_ARTICLE = 'KbPositionArticle';
    public const POSITION_TEXT = 'KbPositionText';
    public const POSITION_DISCOUNT = 'KbPositionDiscount';

    /** `mwst_type` values. 0 means the prices sent already include tax. */
    public const MWST_INCLUDED = 0;
    public const MWST_EXCLUDED = 1;
    public const MWST_EXEMPT = 2;

    /** Commerce's own adjustment types, which get positions of their own. */
    private const ADJUSTMENT_TAX = 'tax';
    private const ADJUSTMENT_SHIPPING = 'shipping';
    private const ADJUSTMENT_DISCOUNT = 'discount';

    /**
     * Build the document body for an order.
     */
    public function forOrder(Order $order, ?int $contactId = null): DocumentPayload
    {
        $settings = Plugin::getInstance()->getSettings();
        $meta = Plugin::getInstance()->getMeta();

        $payload = new DocumentPayload([
            'documentType' => $settings->documentType,
            'orderTotal' => (float)$order->getTotalPrice(),
            'contactId' => $contactId,
        ]);

        $mwstType = $this->resolveMwstType($order, $settings);
        $positions = [];
        $index = 1;

        foreach ($order->getLineItems() as $lineItem) {
            $positions[] = $this->buildLineItemPosition($lineItem, $settings, $payload, $index++);
        }

        $shipping = $this->sumAdjustments($order, self::ADJUSTMENT_SHIPPING);

        if (Money::differs($shipping, 0.0, 0.0)) {
            $positions[] = $this->buildSimplePosition(
                $settings->shippingLabel,
                $shipping,
                $settings->shippingTaxId ?? $settings->defaultTaxId,
                $settings->shippingAccountId ?? $settings->defaultAccountId,
                $settings,
                $index++,
            );
        }

        // Anything a third-party adjuster added — a handling fee, a surcharge, a deposit — gets a
        // position of its own. Folding it into rounding would hide a real charge in the books.
        foreach ($this->otherAdjustments($order) as $label => $amount) {
            $positions[] = $this->buildSimplePosition(
                $label,
                $amount,
                $settings->defaultTaxId,
                $settings->defaultAccountId,
                $settings,
                $index++,
            );
        }

        $discount = $this->sumAdjustments($order, self::ADJUSTMENT_DISCOUNT);

        if (Money::differs($discount, 0.0, 0.0)) {
            // Commerce records discounts as negative adjustments; bexio wants the magnitude and
            // subtracts it itself. Sending the negative would add the discount to the total.
            $positions[] = [
                'type' => self::POSITION_DISCOUNT,
                'text' => $settings->discountLabel,
                'is_percentual' => false,
                'value' => Money::amount(abs($discount)),
            ];
        }

        if ($settings->includeOrderNote && trim((string)$order->message) !== '') {
            $positions[] = [
                'type' => self::POSITION_TEXT,
                'text' => mb_substr(trim((string)$order->message), 0, 1000),
                'show_pos_nr' => false,
            ];
        }

        $this->applyTotals($payload, $positions, $mwstType);

        // bexio does the arithmetic on its side, so the only way to know it agrees with Commerce
        // is to do it here too and compare. A silent franc of drift per order is a reconciliation
        // that never balances.
        $payload->delta = round($payload->orderTotal - $payload->documentTotal, 4);

        if (Money::differs($payload->orderTotal, $payload->documentTotal, $settings->roundingTolerance)) {
            $withinLimit = $settings->roundingLimit <= 0 || abs($payload->delta) <= $settings->roundingLimit;

            if ($settings->addRoundingPosition && $withinLimit) {
                $positions[] = $this->buildRoundingPosition($payload->delta, $settings);
                $payload->hasRoundingPosition = true;
                $this->applyTotals($payload, $positions, $mwstType);
            } elseif (!$withinLimit) {
                // A difference this size is not rounding. Closing it would produce a document that
                // balances and is still wrong — the usual causes are a tax category mapped to the
                // wrong bexio rate, or gross prices sent as net.
                $payload->addWarning(Craft::t('bexy', 'The document comes to {document} but the order is {order} — a difference of {delta}. That is too large to be rounding: check the tax mapping and whether prices are being sent gross or net.', [
                    'document' => Money::amount($payload->documentTotal),
                    'order' => Money::amount($payload->orderTotal),
                    'delta' => Money::amount($payload->delta),
                ]));
            } else {
                $payload->addWarning(Craft::t('bexy', 'The document total ({document}) does not match the order total ({order}). Turn on the rounding position, or check the tax mapping.', [
                    'document' => Money::amount($payload->documentTotal),
                    'order' => Money::amount($payload->orderTotal),
                ]));
            }
        }

        $payload->body = $this->buildDocumentBody($order, $positions, $mwstType, $contactId, $settings, $payload);

        return $payload;
    }

    /**
     * `api_reference` — the string that makes a push idempotent.
     *
     * bexio has no idempotency key, but `api_reference` is a free field only the API can touch and
     * `/search` can match on. Bexy writes the order's reference there and looks for it before
     * creating anything, so a retry after a timeout that actually went through adopts the existing
     * document instead of invoicing the customer a second time.
     */
    public function apiReference(Order $order): string
    {
        $reference = $order->reference ?: $order->number ?: (string)$order->id;

        return mb_substr('bexy:' . $reference, 0, 255);
    }

    /**
     * Which `mwst_type` to send.
     *
     * `0` means the unit prices carry tax inside them, `1` that bexio should add it, `2` that
     * there is none. Commerce already knows which of the first two it is — a tax rate flagged
     * "included in price" makes every price on the order gross — so the default reads it off the
     * store rather than asking the merchant to know.
     */
    public function resolveMwstType(Order $order, Settings $settings): int
    {
        return match ($settings->mwstMode) {
            Settings::MWST_INCLUDED => self::MWST_INCLUDED,
            Settings::MWST_EXCLUDED => self::MWST_EXCLUDED,
            Settings::MWST_EXEMPT => self::MWST_EXEMPT,
            default => $this->detectMwstType($order),
        };
    }

    /**
     * Read the tax treatment off the order itself.
     */
    private function detectMwstType(Order $order): int
    {
        $hasIncluded = false;
        $hasAdded = false;

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->type !== self::ADJUSTMENT_TAX) {
                continue;
            }

            if ($adjustment->included) {
                $hasIncluded = true;
            } else {
                $hasAdded = true;
            }
        }

        if ($hasIncluded && !$hasAdded) {
            return self::MWST_INCLUDED;
        }

        if ($hasAdded) {
            return self::MWST_EXCLUDED;
        }

        // No tax adjustments at all. That is either a genuinely exempt order or a shop that has
        // not set tax up; either way, sending "excluded" with a zero-rate tax is the safe reading
        // and keeps the account mapping intact.
        return self::MWST_EXCLUDED;
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    private function buildDocumentBody(
        Order $order,
        array $positions,
        int $mwstType,
        ?int $contactId,
        Settings $settings,
        DocumentPayload $payload,
    ): array {
        $meta = Plugin::getInstance()->getMeta();
        $reference = $order->reference ?: $order->number;

        $body = [
            'title' => $this->render($settings->titleTemplate, $order),
            'contact_id' => $contactId,
            'user_id' => Plugin::getInstance()->getContacts()->bexioUserId(),
            'mwst_type' => $mwstType,
            // Only consulted when `mwst_type` is 0, where it decides whether the total shown is
            // the net or the gross one. Gross prices mean the total is gross.
            'mwst_is_net' => $mwstType !== self::MWST_INCLUDED,
            'show_position_taxes' => false,
            'is_valid_from' => $this->documentDate($order),
            'api_reference' => $this->apiReference($order),
            'positions' => $positions,
        ];

        if ($settings->documentType === Document::TYPE_INVOICE) {
            $body['is_valid_to'] = $this->dueDate($order, $settings);
        }

        $currencyId = $meta->getCurrencyId($order->currency);

        if ($currencyId) {
            $body['currency_id'] = $currencyId;
        } elseif ($order->currency) {
            $payload->addWarning(Craft::t('bexy', 'bexio has no currency called {currency}. The document will be booked in the company’s default currency.', [
                'currency' => $order->currency,
            ]));
        }

        foreach ([
            'bank_account_id' => $settings->bankAccountId,
            'payment_type_id' => $settings->paymentTypeId,
            'language_id' => $settings->languageId,
            'logopaper_id' => $settings->logopaperId,
        ] as $key => $value) {
            if ($value) {
                $body[$key] = $value;
            }
        }

        if (trim($settings->header) !== '') {
            $body['header'] = $this->render($settings->header, $order);
        }

        if (trim($settings->footer) !== '') {
            $body['footer'] = $this->render($settings->footer, $order);
        }

        // The address on the order wins over the address on the contact record. A customer who
        // bought once from a company address and once from home should get two correct invoices,
        // not two copies of whichever address bexio happens to hold.
        $block = Address::toBlock($order->getBillingAddress());

        if ($block !== '') {
            $body['contact_address_manual'] = $block;
        }

        if ($settings->documentType === Document::TYPE_ORDER) {
            $shippingBlock = Address::toBlock($order->getShippingAddress());

            if ($shippingBlock !== '' && $shippingBlock !== $block) {
                $body['delivery_address_type'] = 1;
                $body['delivery_address_manual'] = $shippingBlock;
            }
        }

        if (!$contactId) {
            $payload->addWarning(Craft::t('bexy', 'No bexio contact was resolved for this order. bexio will reject a document with no contact on it.'));
        }

        return array_filter($body, static fn($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLineItemPosition(
        LineItem $lineItem,
        Settings $settings,
        DocumentPayload $payload,
        int $index,
    ): array {
        $taxCategory = null;

        try {
            $taxCategory = $lineItem->getTaxCategory()->handle;
        } catch (\Throwable) {
            // A line item whose tax category was deleted still has to be invoiced.
        }

        $taxId = $settings->taxIdFor($taxCategory);
        $accountId = $settings->accountIdFor($taxCategory);

        if (!$taxId) {
            $payload->addWarning(Craft::t('bexy', 'No bexio tax is mapped for “{item}”. bexio will book it at the document default, which may be the wrong rate.', [
                'item' => $lineItem->getDescription(),
            ]));
        }

        if (!$accountId) {
            $payload->addWarning(Craft::t('bexy', 'No revenue account is mapped for “{item}”.', [
                'item' => $lineItem->getDescription(),
            ]));
        }

        $position = [
            'type' => self::POSITION_CUSTOM,
            'text' => $this->lineItemText($lineItem),
            'amount' => Money::quantity($lineItem->qty),
            'unit_price' => Money::price($lineItem->getSalePrice()),
            'internal_pos' => $index,
        ];

        if ($taxId) {
            $position['tax_id'] = $taxId;
        }

        if ($accountId) {
            $position['account_id'] = $accountId;
        }

        if ($settings->defaultUnitId) {
            $position['unit_id'] = $settings->defaultUnitId;
        }

        // A SKU that matches a bexio item turns the line into a real item position, which is what
        // makes bexio's per-item revenue reporting work at all.
        if ($settings->matchArticlesBySku) {
            $articleId = Plugin::getInstance()->getArticles()->resolve($lineItem, $payload);

            if ($articleId) {
                $position['type'] = self::POSITION_ARTICLE;
                $position['article_id'] = $articleId;
            }
        }

        return $position;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSimplePosition(
        string $text,
        float $amount,
        ?int $taxId,
        ?int $accountId,
        Settings $settings,
        int $index,
    ): array {
        $position = [
            'type' => self::POSITION_CUSTOM,
            'text' => $text,
            'amount' => '1',
            'unit_price' => Money::price($amount),
            'internal_pos' => $index,
        ];

        if ($taxId) {
            $position['tax_id'] = $taxId;
        }

        if ($accountId) {
            $position['account_id'] = $accountId;
        }

        if ($settings->defaultUnitId) {
            $position['unit_id'] = $settings->defaultUnitId;
        }

        return $position;
    }

    /**
     * The position that closes a totals mismatch.
     *
     * Deliberately untaxed: its whole job is to move the gross total by exactly the delta, and a
     * taxed rounding line would move it by the delta plus tax and miss again.
     *
     * @return array<string, mixed>
     */
    private function buildRoundingPosition(float $delta, Settings $settings): array
    {
        $position = [
            'type' => self::POSITION_CUSTOM,
            'text' => $settings->roundingLabel,
            'amount' => '1',
            'unit_price' => Money::price($delta),
        ];

        if ($settings->defaultAccountId) {
            $position['account_id'] = $settings->defaultAccountId;
        }

        return $position;
    }

    /**
     * Work out what bexio will make of these positions, and record it on the payload.
     *
     * @param array<int, array<string, mixed>> $positions
     */
    private function applyTotals(DocumentPayload $payload, array $positions, int $mwstType): void
    {
        $meta = Plugin::getInstance()->getMeta();
        $net = 0.0;
        $tax = 0.0;
        $discount = 0.0;

        foreach ($positions as $position) {
            if (($position['type'] ?? '') === self::POSITION_DISCOUNT) {
                $discount += Money::toFloat($position['value'] ?? 0);
                continue;
            }

            if (!isset($position['unit_price'])) {
                continue;
            }

            $lineTotal = Money::toFloat($position['unit_price']) * Money::toFloat($position['amount'] ?? 1);
            $rate = $mwstType === self::MWST_EXEMPT ? 0.0 : ($meta->getTaxRate($position['tax_id'] ?? null) ?? 0.0);

            if ($mwstType === self::MWST_INCLUDED) {
                // The price already carries the tax; peel it back out for the net figure.
                $lineNet = $rate > 0 ? $lineTotal / (1 + $rate / 100) : $lineTotal;
                $net += $lineNet;
                $tax += $lineTotal - $lineNet;
            } else {
                $net += $lineTotal;
                $tax += $lineTotal * $rate / 100;
            }
        }

        // bexio applies a document-level discount to the running total, tax and all.
        if ($discount > 0) {
            $gross = $net + $tax;
            $factor = $gross > 0 ? max(0.0, ($gross - $discount) / $gross) : 0.0;
            $net *= $factor;
            $tax *= $factor;
        }

        $payload->netTotal = round($net, 4);
        $payload->taxTotal = round($tax, 4);
        $payload->documentTotal = round($net + $tax, 4);
    }

    /**
     * What the position says on the invoice.
     *
     * The customer's chosen options belong here — an invoice line reading "T-shirt" when they
     * bought a blue one in medium is an invoice line that generates a support email.
     */
    private function lineItemText(LineItem $lineItem): string
    {
        $text = $lineItem->getDescription();
        $sku = $lineItem->getSku();

        if ($sku !== '') {
            $text .= ' (' . $sku . ')';
        }

        $options = [];

        foreach ($lineItem->getOptions() as $key => $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $options[] = sprintf('%s: %s', $key, $value);
            }
        }

        if ($options) {
            $text .= "\n" . implode(', ', $options);
        }

        if (trim($lineItem->note) !== '') {
            $text .= "\n" . trim($lineItem->note);
        }

        return mb_substr($text, 0, 1000);
    }

    /**
     * Total of every adjustment of one type.
     */
    private function sumAdjustments(Order $order, string $type): float
    {
        $total = 0.0;

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->type === $type && !$adjustment->included) {
                $total += (float)$adjustment->amount;
            }
        }

        return round($total, 4);
    }

    /**
     * Adjustments that are not tax, shipping or discount, grouped by their name.
     *
     * @return array<string, float>
     */
    private function otherAdjustments(Order $order): array
    {
        $grouped = [];

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->included) {
                continue;
            }

            if (in_array($adjustment->type, [self::ADJUSTMENT_TAX, self::ADJUSTMENT_SHIPPING, self::ADJUSTMENT_DISCOUNT], true)) {
                continue;
            }

            $label = $this->adjustmentLabel($adjustment);
            $grouped[$label] = ($grouped[$label] ?? 0.0) + (float)$adjustment->amount;
        }

        return array_filter($grouped, static fn(float $amount): bool => abs($amount) > 0.000001);
    }

    private function adjustmentLabel(OrderAdjustment $adjustment): string
    {
        $label = trim((string)($adjustment->name ?: $adjustment->description ?: $adjustment->type));

        return mb_substr($label !== '' ? $label : Craft::t('bexy', 'Adjustment'), 0, 255);
    }

    private function documentDate(Order $order): string
    {
        return ($order->dateOrdered ?? $order->dateCreated ?? new \DateTime())->format('Y-m-d');
    }

    private function dueDate(Order $order, Settings $settings): string
    {
        $date = clone ($order->dateOrdered ?? $order->dateCreated ?? new \DateTime());

        return $date->modify(sprintf('+%d days', max(0, $settings->paymentTermDays)))->format('Y-m-d');
    }

    /**
     * Substitute the handful of tokens the settings screen advertises.
     */
    private function render(string $template, Order $order): string
    {
        return strtr($template, [
            '{number}' => (string)($order->reference ?: $order->number),
            '{reference}' => (string)$order->reference,
            '{email}' => (string)$order->getEmail(),
            '{name}' => (string)($order->getBillingAddress()?->fullName ?? ''),
            '{date}' => $this->documentDate($order),
            '{total}' => Money::amount($order->getTotalPrice()),
            '{currency}' => (string)$order->currency,
        ]);
    }
}

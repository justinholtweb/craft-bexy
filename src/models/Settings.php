<?php

namespace justinholtweb\bexy\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Bexy's settings.
 *
 * Nothing here is `required`. A required plugin setting makes a fresh install unsavable — Craft
 * writes the defaults during install, validation fails, and the merchant is left with a plugin
 * that cannot be configured because it cannot be saved.
 */
class Settings extends Model
{
    public const AUTH_OAUTH = 'oauth';
    public const AUTH_PAT = 'pat';

    public const DOC_INVOICE = 'invoice';
    public const DOC_ORDER = 'order';

    public const MWST_AUTO = 'auto';
    public const MWST_INCLUDED = 'included';
    public const MWST_EXCLUDED = 'excluded';
    public const MWST_EXEMPT = 'exempt';

    // ------------------------------------------------------------------ connection

    /** @var string `oauth` or `pat`. */
    public string $authMode = self::AUTH_OAUTH;

    /** @var string OAuth client ID from developer.bexio.com. Supports env vars. */
    public string $clientId = '';

    /** @var string OAuth client secret. Supports env vars. */
    public string $clientSecret = '';

    /**
     * @var string A Personal Access Token. Supports env vars.
     *
     * bexio expires every PAT 60 days after it is created, which is why this is not the default
     * mode: an integration authenticated this way stops working roughly every two months, with
     * no warning from bexio's side.
     */
    public string $personalAccessToken = '';

    // ------------------------------------------------------------------ document

    /** @var string `invoice` or `order`. */
    public string $documentType = self::DOC_INVOICE;

    /** @var bool Queue a push when an order completes. */
    public bool $autoSync = true;

    /**
     * @var string[] Order status handles that trigger a push. Empty means "on completion",
     *               whatever status the order lands in.
     */
    public array $syncOnStatuses = [];

    /** @var bool Issue (book and number) the invoice straight after creating it. */
    public bool $issueDocument = false;

    /** @var bool Have bexio email the issued invoice to the customer. */
    public bool $sendDocument = false;

    /** @var string Subject line for the invoice email bexio sends. */
    public string $emailSubject = 'Invoice for order {number}';

    /**
     * @var string Body of the invoice email. bexio replaces `[Network Link]` with the link to the
     *             hosted document; without it the customer gets a mail with no invoice in it.
     */
    public string $emailBody = "Dear {name}\n\nThank you for your order. Your invoice is available here:\n\n[Network Link]\n\nKind regards";

    /** @var string Document title. `{number}` is the Commerce order reference. */
    public string $titleTemplate = 'Order {number}';

    /** @var string Text printed above the positions. */
    public string $header = '';

    /** @var string Text printed below the positions. */
    public string $footer = '';

    /** @var bool Copy the customer's order note onto the document as a text position. */
    public bool $includeOrderNote = false;

    // ------------------------------------------------------------------ bexio defaults

    /** @var int|null bexio user the documents and contacts are filed under. */
    public ?int $bexioUserId = null;

    /** @var int|null Bank account printed on the document. */
    public ?int $bankAccountId = null;

    /** @var int|null bexio payment type (payment terms). */
    public ?int $paymentTypeId = null;

    /** @var int|null Document language. */
    public ?int $languageId = null;

    /** @var int|null Letterhead. */
    public ?int $logopaperId = null;

    /** @var int|null Unit used for line-item quantities. */
    public ?int $defaultUnitId = null;

    /** @var int|null Revenue account every position falls back to. */
    public ?int $defaultAccountId = null;

    /** @var int|null Sales tax every position falls back to. */
    public ?int $defaultTaxId = null;

    /** @var int Days until the invoice is due, written into `is_valid_to`. */
    public int $paymentTermDays = 30;

    // ------------------------------------------------------------------ tax

    /** @var string `auto`, `included`, `excluded` or `exempt`. */
    public string $mwstMode = self::MWST_AUTO;

    /**
     * @var array<int, array{taxCategory: string, taxId: string, accountId: string}>
     *      Commerce tax category handle → bexio tax and revenue account.
     */
    public array $taxMap = [];

    /** @var int|null Tax applied to the shipping position. Falls back to `defaultTaxId`. */
    public ?int $shippingTaxId = null;

    /** @var int|null Account the shipping position is booked to. */
    public ?int $shippingAccountId = null;

    /** @var string Label for the shipping position. */
    public string $shippingLabel = 'Shipping';

    /** @var string Label for the discount position. */
    public string $discountLabel = 'Discount';

    // ------------------------------------------------------------------ rounding

    /**
     * @var bool Add an explicit rounding position when bexio's arithmetic and Commerce's disagree.
     *
     * Off, a mismatch is silent: the shop says one number and the books say another, and nobody
     * finds out until a VAT return.
     */
    public bool $addRoundingPosition = true;

    /** @var float Difference, in the order currency, that counts as a mismatch. */
    public float $roundingTolerance = 0.01;

    /**
     * @var float The largest difference a rounding position is allowed to close.
     *
     * Beyond this, the difference is not rounding — it is a wrong tax mapping, or prices sent
     * gross that bexio was told were net. Papering over a figure that size would put a plausible
     * document in the books and hide the real problem until a VAT return, so past this limit Bexy
     * refuses to adjust and says what it found instead. 0 removes the limit.
     */
    public float $roundingLimit = 1.00;

    /** @var string Label for the rounding position. */
    public string $roundingLabel = 'Rounding';

    // ------------------------------------------------------------------ contacts

    /** @var bool Create a bexio contact when no match is found. */
    public bool $createContacts = true;

    /** @var bool Push address changes onto an existing bexio contact. */
    public bool $updateContacts = false;

    /** @var string Comma-separated bexio contact group IDs for new contacts. */
    public string $contactGroupIds = '';

    // ------------------------------------------------------------------ articles

    /** @var bool Look a line item's SKU up as a bexio item and link the position to it. */
    public bool $matchArticlesBySku = false;

    /** @var bool Create the bexio item when the SKU does not match one. */
    public bool $createMissingArticles = false;

    // ------------------------------------------------------------------ payments

    /** @var bool Post successful Commerce transactions to bexio as invoice payments. */
    public bool $pushPayments = true;

    /** @var int|null Bank account the payments are booked against. Falls back to `bankAccountId`. */
    public ?int $paymentBankAccountId = null;

    /** @var bool Cancel the bexio invoice when the Commerce order is fully refunded. */
    public bool $cancelOnFullRefund = false;

    // ------------------------------------------------------------------ reconciliation

    /** @var bool Pull document status back from bexio. */
    public bool $reconcileEnabled = true;

    /**
     * @var array<int, array{bexioStatus: string, orderStatus: string}>
     *      bexio document status → Commerce order status handle.
     */
    public array $statusMap = [];

    /** @var int Only reconcile documents touched in the last N days. 0 means all of them. */
    public int $reconcileWindowDays = 120;

    // ------------------------------------------------------------------ logging

    public bool $loggingEnabled = true;

    /** @var bool Keep request and response bodies. Tokens and secrets are redacted either way. */
    public bool $logPayloads = true;

    /** @var int Days of log to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['authMode'], 'in', 'range' => [self::AUTH_OAUTH, self::AUTH_PAT]],
            [['documentType'], 'in', 'range' => [self::DOC_INVOICE, self::DOC_ORDER]],
            [['mwstMode'], 'in', 'range' => [self::MWST_AUTO, self::MWST_INCLUDED, self::MWST_EXCLUDED, self::MWST_EXEMPT]],
            [['paymentTermDays', 'logRetentionDays', 'reconcileWindowDays'], 'integer', 'min' => 0],
            [['roundingTolerance', 'roundingLimit'], 'number', 'min' => 0],
            [
                [
                    'bexioUserId', 'bankAccountId', 'paymentTypeId', 'languageId', 'logopaperId',
                    'defaultUnitId', 'defaultAccountId', 'defaultTaxId', 'shippingTaxId',
                    'shippingAccountId', 'paymentBankAccountId',
                ],
                'integer',
                'min' => 1,
            ],
            [['contactGroupIds'], 'match', 'pattern' => '/^[0-9,\s]*$/', 'message' => 'Use bexio contact group IDs separated by commas.'],
            [['emailBody'], 'validateNetworkLink'],
        ];
    }

    /**
     * bexio only puts the document into the email where `[Network Link]` appears. A body without
     * it produces a mail that says an invoice is attached when nothing is.
     */
    public function validateNetworkLink(string $attribute): void
    {
        if ($this->sendDocument && !str_contains((string)$this->$attribute, '[Network Link]')) {
            $this->addError($attribute, 'Add the [Network Link] placeholder, or the customer gets an email with no invoice in it.');
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        // Craft's editable tables post a row per line, including the blank one at the bottom.
        $this->taxMap = $this->cleanRows($this->taxMap, 'taxCategory');
        $this->statusMap = $this->cleanRows($this->statusMap, 'bexioStatus');
        $this->syncOnStatuses = array_values(array_filter(
            array_map('strval', $this->syncOnStatuses),
            static fn(string $handle): bool => $handle !== ''
        ));

        return parent::beforeValidate();
    }

    /**
     * The resolved OAuth client ID, with `$ENV_VAR` expanded.
     */
    public function getClientId(): string
    {
        return (string)App::parseEnv($this->clientId);
    }

    public function getClientSecret(): string
    {
        return (string)App::parseEnv($this->clientSecret);
    }

    public function getPersonalAccessToken(): string
    {
        return (string)App::parseEnv($this->personalAccessToken);
    }

    /**
     * Whether enough is configured for the API service to attempt a call.
     */
    public function isConfigured(): bool
    {
        return $this->authMode === self::AUTH_PAT
            ? $this->getPersonalAccessToken() !== ''
            : ($this->getClientId() !== '' && $this->getClientSecret() !== '');
    }

    /**
     * bexio tax ID for a Commerce tax category handle.
     */
    public function taxIdFor(?string $taxCategoryHandle): ?int
    {
        foreach ($this->taxMap as $row) {
            if (($row['taxCategory'] ?? null) === $taxCategoryHandle && !empty($row['taxId'])) {
                return (int)$row['taxId'];
            }
        }

        return $this->defaultTaxId;
    }

    /**
     * bexio revenue account for a Commerce tax category handle.
     */
    public function accountIdFor(?string $taxCategoryHandle): ?int
    {
        foreach ($this->taxMap as $row) {
            if (($row['taxCategory'] ?? null) === $taxCategoryHandle && !empty($row['accountId'])) {
                return (int)$row['accountId'];
            }
        }

        return $this->defaultAccountId;
    }

    /**
     * Commerce order status handle to move to for a bexio document status ID.
     */
    public function orderStatusFor(int $bexioStatusId): ?string
    {
        foreach ($this->statusMap as $row) {
            if ((int)($row['bexioStatus'] ?? 0) === $bexioStatusId && !empty($row['orderStatus'])) {
                return (string)$row['orderStatus'];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function cleanRows(array $rows, string $keyColumn): array
    {
        return array_values(array_filter(
            $rows,
            static fn($row): bool => is_array($row) && !empty($row[$keyColumn])
        ));
    }
}

<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\helpers\Money;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\Plugin;

/**
 * Posting the money to bexio.
 *
 * Without this an integration leaves every invoice it raises sitting open in the books, and the
 * merchant reconciles by hand — which is most of the work they were trying to avoid. The unique
 * index on `transactionId` is what stops a retry booking the same payment twice.
 */
class Payments extends Component
{
    /** Transaction types that represent money actually taken. */
    private const SETTLING_TYPES = [
        TransactionRecord::TYPE_PURCHASE,
        TransactionRecord::TYPE_CAPTURE,
    ];

    /**
     * Post every settled transaction on this order that bexio has not seen.
     *
     * @return int How many payments were created.
     */
    public function pushForOrder(Order $order, Document $document): int
    {
        if (!$document->bexioId || $document->documentType !== Document::TYPE_INVOICE) {
            return 0;
        }

        $created = 0;

        foreach ($this->settledTransactions($order) as $transaction) {
            if ($this->isRecorded($transaction->id)) {
                continue;
            }

            try {
                $created += $this->post($document, $transaction, $order) ? 1 : 0;
            } catch (BexioApiException $e) {
                // A payment that will not post is worth flagging, not worth losing the invoice
                // over. The invoice is already in bexio and correct; the money is what is missing.
                $document->needsAttention = Craft::t('bexy', 'Payment of {amount} could not be posted to bexio: {message}', [
                    'amount' => Money::amount($transaction->amount),
                    'message' => Plugin::getInstance()->getApi()->explain($e),
                ]);
                Plugin::getInstance()->getDocuments()->record($document);

                break;
            }
        }

        if ($created > 0) {
            $this->refreshTotals($document);
        }

        $this->handleRefunds($order, $document);

        return $created;
    }

    /**
     * Post one transaction.
     *
     * @throws BexioApiException
     */
    public function post(Document $document, Transaction $transaction, ?Order $order = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        $body = [
            'value' => Money::amount($transaction->amount),
            'date' => ($transaction->dateCreated ?? new DateTime())->format('Y-m-d'),
        ];

        $bankAccountId = $settings->paymentBankAccountId ?: $settings->bankAccountId;

        if ($bankAccountId) {
            $body['bank_account_id'] = $bankAccountId;
        }

        $result = Plugin::getInstance()->getApi()->post(
            sprintf('/2.0/kb_invoice/%d/payment', $document->bexioId),
            $body,
            $document->orderId,
        );

        $this->remember($document, $transaction, isset($result['id']) ? (int)$result['id'] : null);

        Plugin::getInstance()->getLog()->write('payment.post', [
            'orderId' => $document->orderId,
            'summary' => Craft::t('bexy', 'Posted {amount} {currency} against {number}', [
                'amount' => Money::amount($transaction->amount),
                'currency' => (string)$transaction->currency,
                'number' => $document->bexioNumber ?: $document->bexioId,
            ]),
        ]);

        return true;
    }

    /**
     * What a refund means for the bexio document.
     *
     * bexio's API cannot raise a credit note — the only credit-voucher endpoint it exposes fetches
     * a PDF of one that already exists. So Bexy does not invent a negative payment, which would
     * balance the invoice while leaving the VAT wrong. It cancels where cancelling is legitimate,
     * and otherwise says plainly that a human needs to look.
     */
    public function handleRefunds(Order $order, Document $document): void
    {
        $refunded = $this->refundedTotal($order);

        if ($refunded <= 0.0) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $isFullRefund = !Money::differs($refunded, (float)$order->getTotalPrice(), 0.01);
        $nothingPaidInBexio = $document->amountPaid <= 0.0;

        if ($isFullRefund && $settings->cancelOnFullRefund && $nothingPaidInBexio) {
            try {
                Plugin::getInstance()->getDocuments()->cancel($document);

                return;
            } catch (BexioApiException $e) {
                $document->needsAttention = Craft::t('bexy', 'This order was fully refunded but the bexio invoice could not be cancelled: {message}', [
                    'message' => Plugin::getInstance()->getApi()->explain($e),
                ]);
                Plugin::getInstance()->getDocuments()->record($document);

                return;
            }
        }

        $document->needsAttention = Craft::t('bexy', '{amount} {currency} was refunded in Commerce. bexio’s API cannot raise a credit note, so this needs a credit voucher in bexio.', [
            'amount' => Money::amount($refunded),
            'currency' => (string)$order->currency,
        ]);
        Plugin::getInstance()->getDocuments()->record($document);
    }

    /**
     * Ask bexio what it thinks has been paid, and store the answer.
     *
     * @throws BexioApiException
     */
    public function refreshTotals(Document $document): void
    {
        if (!$document->bexioId || $document->documentType !== Document::TYPE_INVOICE) {
            return;
        }

        $invoice = Plugin::getInstance()->getApi()->get(
            sprintf('/2.0/kb_invoice/%d', $document->bexioId),
            [],
            $document->orderId,
        );

        $document->amountPaid = Money::toFloat($invoice['total_received_payments'] ?? 0);
        $document->amountRemaining = Money::toFloat($invoice['total_remaining_payments'] ?? 0);
        $document->bexioStatusId = isset($invoice['kb_item_status_id']) ? (int)$invoice['kb_item_status_id'] : $document->bexioStatusId;
        $document->bexioNumber = $invoice['document_nr'] ?? $document->bexioNumber;

        Plugin::getInstance()->getDocuments()->record($document);
    }

    /**
     * Transactions that took money and succeeded.
     *
     * An authorization is not money — it is a promise — so it is left out. A capture against an
     * authorization is, and so is a direct purchase.
     *
     * @return Transaction[]
     */
    public function settledTransactions(Order $order): array
    {
        return array_values(array_filter(
            $order->getTransactions(),
            static fn(Transaction $transaction): bool => in_array($transaction->type, self::SETTLING_TYPES, true)
                && $transaction->status === TransactionRecord::STATUS_SUCCESS,
        ));
    }

    public function refundedTotal(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === TransactionRecord::TYPE_REFUND && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
                $total += (float)$transaction->amount;
            }
        }

        return round($total, 4);
    }

    public function isRecorded(?int $transactionId): bool
    {
        if (!$transactionId) {
            return false;
        }

        return (new Query())
            ->from(Table::PAYMENTS)
            ->where(['transactionId' => $transactionId])
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentsForDocument(int $documentId): array
    {
        return (new Query())
            ->from(Table::PAYMENTS)
            ->where(['documentId' => $documentId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function remember(Document $document, Transaction $transaction, ?int $bexioPaymentId): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, [
            'documentId' => $document->id,
            'transactionId' => $transaction->id,
            'bexioPaymentId' => $bexioPaymentId,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'paidOn' => Db::prepareDateForDb($transaction->dateCreated ?? new DateTime()),
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }
}

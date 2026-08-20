<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\helpers\Money;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\Plugin;
use Throwable;

/**
 * Bringing bexio's verdict back into Commerce.
 *
 * Pushing an order out is half an integration. The other half is that somebody in accounts marks
 * the invoice paid in bexio, and the shop never hears about it — so the order sits in Commerce
 * looking unpaid, and the merchant checks two systems for the rest of the order's life.
 */
class Reconcile extends Component
{
    /** bexio's invoice status IDs, by name. */
    public const STATUS_DRAFT = 7;
    public const STATUS_PENDING = 8;
    public const STATUS_PAID = 9;
    public const STATUS_PARTIAL = 16;
    public const STATUS_CANCELLED = 19;
    public const STATUS_UNPAID = 31;

    /**
     * Pull one document's current state from bexio.
     *
     * @return bool Whether anything changed.
     * @throws BexioApiException
     */
    public function reconcile(Document $document): bool
    {
        if (!$document->bexioId) {
            return false;
        }

        $path = $document->documentType === Document::TYPE_ORDER
            ? sprintf('/2.0/kb_order/%d', $document->bexioId)
            : sprintf('/2.0/kb_invoice/%d', $document->bexioId);

        try {
            $remote = Plugin::getInstance()->getApi()->get($path, [], $document->orderId);
        } catch (BexioApiException $e) {
            if ($e->statusCode === 404) {
                // Somebody deleted it in bexio. Say so rather than reporting it as synced forever.
                $document->needsAttention = Craft::t('bexy', 'This document no longer exists in bexio. It was deleted there.');
                $document->dateReconciled = new DateTime();
                Plugin::getInstance()->getDocuments()->record($document);

                return true;
            }

            throw $e;
        }

        $before = [$document->bexioStatusId, $document->amountPaid, $document->bexioNumber];

        $document->bexioNumber = $remote['document_nr'] ?? $document->bexioNumber;
        $document->bexioStatusId = isset($remote['kb_item_status_id']) ? (int)$remote['kb_item_status_id'] : null;
        $document->amountPaid = Money::toFloat($remote['total_received_payments'] ?? 0);
        $document->amountRemaining = Money::toFloat($remote['total_remaining_payments'] ?? 0);
        $document->dateReconciled = new DateTime();

        if ($document->bexioStatusId === self::STATUS_CANCELLED) {
            $document->status = Document::STATUS_CANCELLED;
        }

        $changed = $before !== [$document->bexioStatusId, $document->amountPaid, $document->bexioNumber];

        Plugin::getInstance()->getDocuments()->record($document);

        if ($changed) {
            $this->applyOrderStatus($document);
        }

        return $changed;
    }

    /**
     * Move the Commerce order to the status the merchant mapped this bexio status to.
     *
     * Only the status is touched. Bexy does not invent a Commerce transaction to make an order
     * look paid — a transaction is a record of money moving through a gateway, and fabricating
     * one puts a payment in the shop's books that no gateway ever processed.
     */
    public function applyOrderStatus(Document $document): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$document->bexioStatusId) {
            return false;
        }

        $handle = $settings->orderStatusFor($document->bexioStatusId);

        if (!$handle) {
            return false;
        }

        $order = $document->getOrder();

        if (!$order instanceof Order) {
            return false;
        }

        $status = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $order->storeId);

        if (!$status || $order->orderStatusId === $status->id) {
            return false;
        }

        $order->orderStatusId = $status->id;

        try {
            $saved = Craft::$app->getElements()->saveElement($order, false);
        } catch (Throwable $e) {
            Plugin::getInstance()->getLog()->write('reconcile.status', [
                'level' => 'error',
                'orderId' => $order->id,
                'summary' => Craft::t('bexy', 'Could not move order {number} to {status}', [
                    'number' => $order->reference ?: $order->number,
                    'status' => $status->name,
                ]),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if ($saved) {
            Plugin::getInstance()->getLog()->write('reconcile.status', [
                'orderId' => $order->id,
                'summary' => Craft::t('bexy', 'Order {number} moved to {status} after bexio reported {bexioStatus}', [
                    'number' => $order->reference ?: $order->number,
                    'status' => $status->name,
                    'bexioStatus' => $document->getBexioStatusLabel(),
                ]),
            ]);
        }

        return $saved;
    }

    /**
     * Reconcile every document worth re-checking.
     *
     * A document that is paid and closed will not change again, so it drops out of the window;
     * so does anything older than the merchant's retention setting. Otherwise a shop with ten
     * thousand orders spends its whole rate limit asking bexio about invoices from 2019.
     *
     * @return array{checked: int, changed: int, failed: int}
     */
    public function reconcileAll(int $limit = 100): array
    {
        $result = ['checked' => 0, 'changed' => 0, 'failed' => 0];

        foreach ($this->getReconcilable($limit) as $document) {
            $result['checked']++;

            try {
                if ($this->reconcile($document)) {
                    $result['changed']++;
                }
            } catch (BexioApiException) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @return Document[]
     */
    public function getReconcilable(int $limit = 100): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $documents = Plugin::getInstance()->getDocuments();

        $query = (new Query())
            ->select(['id'])
            ->from(Table::DOCUMENTS)
            ->where(['status' => Document::STATUS_SYNCED])
            ->andWhere(['not', ['bexioId' => null]])
            // Paid and cancelled documents are final. Asking again just spends rate limit.
            ->andWhere(['not', ['bexioStatusId' => [self::STATUS_PAID, self::STATUS_CANCELLED]]])
            ->orderBy(['dateReconciled' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit);

        if ($settings->reconcileWindowDays > 0) {
            $cutoff = (new DateTime())->modify(sprintf('-%d days', $settings->reconcileWindowDays));
            $query->andWhere(['>=', 'dateSynced', Db::prepareDateForDb($cutoff)]);
        }

        return array_values(array_filter(array_map(
            static fn($id): ?Document => $documents->getDocument((int)$id),
            $query->column(),
        )));
    }

    /**
     * Options for the status-mapping table in settings.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getBexioStatusOptions(string $documentType = Document::TYPE_INVOICE): array
    {
        $map = $documentType === Document::TYPE_ORDER ? Meta::ORDER_STATUSES : Meta::INVOICE_STATUSES;
        $options = [['label' => Craft::t('bexy', '— none —'), 'value' => '']];

        foreach ($map as $id => $label) {
            $options[] = ['label' => Craft::t('bexy', $label), 'value' => (string)$id];
        }

        return $options;
    }
}

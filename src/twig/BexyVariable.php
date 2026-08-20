<?php

namespace justinholtweb\bexy\twig;

use craft\commerce\elements\Order;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\Plugin;
use yii\base\BaseObject;

/**
 * `craft.bexy` — read-only, so a template can show a customer their invoice status without being
 * able to raise one.
 */
class BexyVariable extends BaseObject
{
    /**
     * The bexio document for an order, if it has one.
     */
    public function documentForOrder(Order|int|null $order): ?Document
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if (!$orderId) {
            return null;
        }

        return Plugin::getInstance()->getDocuments()->getDocumentForOrder((int)$orderId);
    }

    /**
     * Whether an order has reached bexio.
     */
    public function isSynced(Order|int|null $order): bool
    {
        return (bool)$this->documentForOrder($order)?->bexioId;
    }

    /**
     * The bexio document number for an order — the number a customer would quote to accounts.
     */
    public function documentNumber(Order|int|null $order): ?string
    {
        return $this->documentForOrder($order)?->bexioNumber;
    }

    public function isConnected(): bool
    {
        return Plugin::getInstance()->getAuth()->isConnected();
    }

    /**
     * @return array{total: int, pending: int, synced: int, failed: int, attention: int}
     */
    public function counts(): array
    {
        return Plugin::getInstance()->getDocuments()->getCounts();
    }
}

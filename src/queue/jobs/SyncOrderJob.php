<?php

namespace justinholtweb\bexy\queue\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\Plugin;

/**
 * Push one order to bexio, off the request.
 *
 * Everything that talks to bexio during a checkout lives here. Order completion writes a pending
 * row and queues this; a bexio outage, an expired token or a rate limit then delays a document
 * instead of standing between a customer and their payment confirmation.
 */
class SyncOrderJob extends BaseJob
{
    public int $orderId;

    /** Push even if the order already has a bexio document. Only set from a deliberate action. */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted between completion and this job running. Nothing to book.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('bexy', 'Building the document'));

        try {
            $document = Plugin::getInstance()->getDocuments()->push($order, $this->force);
        } catch (BexioApiException $e) {
            // Transient failures go back on the queue — that is what Craft's retry is for.
            // A 422 will fail identically forever, so it is left as a failed job the merchant can
            // see, with the reason already written onto the document row.
            if ($e->getIsTransient()) {
                throw $e;
            }

            Plugin::getInstance()->getLog()->write('sync.failed', [
                'level' => 'error',
                'orderId' => $this->orderId,
                'summary' => Craft::t('bexy', 'Order {id} was rejected by bexio', ['id' => $this->orderId]),
                'message' => Plugin::getInstance()->getApi()->explain($e),
            ]);

            return;
        }

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // Long enough to survive a rate-limit wait plus an issue-and-send round trip.
        return 300;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('bexy', 'Sending order {id} to bexio', ['id' => $this->orderId]);
    }
}

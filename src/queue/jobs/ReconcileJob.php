<?php

namespace justinholtweb\bexy\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\bexy\Plugin;

/**
 * Ask bexio what has been paid since last time.
 */
class ReconcileJob extends BaseJob
{
    public int $limit = 100;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $documents = Plugin::getInstance()->getReconcile()->getReconcilable($this->limit);
        $total = max(1, count($documents));
        $index = 0;

        foreach ($documents as $document) {
            $this->setProgress($queue, $index++ / $total, Craft::t('bexy', 'Checking {number}', [
                'number' => $document->bexioNumber ?: $document->orderNumber,
            ]));

            try {
                Plugin::getInstance()->getReconcile()->reconcile($document);
            } catch (\Throwable) {
                // One unreadable document must not stop the other ninety-nine being checked.
            }
        }

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return 600;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('bexy', 'Reconciling documents with bexio');
    }
}

<?php

namespace justinholtweb\bexy\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Pushing orders to bexio from the command line.
 *
 * `craft bexy/sync/order 1234`, `craft bexy/sync/pending`, `craft bexy/sync/preview 1234`.
 */
class SyncController extends Controller
{
    /** @var bool Push even when the order already has a bexio document. */
    public bool $force = false;

    /** @var int How many orders to handle in one run. */
    public int $limit = 50;

    /** @var bool Build and check the payload without sending anything. */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'order' => ['force', 'dryRun'],
            'pending' => ['limit', 'force'],
            default => [],
        });
    }

    /**
     * Push one order.
     */
    public function actionOrder(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with ID $orderId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            return $this->actionPreview($orderId);
        }

        try {
            $document = Plugin::getInstance()->getDocuments()->push($order, $this->force);
        } catch (BexioApiException $e) {
            $this->stderr(Plugin::getInstance()->getApi()->explain($e) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::SOFTWARE;
        }

        $this->stdout(sprintf(
            "Order %s → bexio %s (#%d)\n",
            $order->reference ?: $order->number,
            $document->bexioNumber ?: '—',
            (int)$document->bexioId,
        ), Console::FG_GREEN);

        if ($document->needsAttention) {
            $this->stdout('  ! ' . $document->needsAttention . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Push everything still waiting, and retry what failed.
     */
    public function actionPending(): int
    {
        $documents = Plugin::getInstance()->getDocuments();
        $queue = $documents->getRetryable($this->limit);

        if (!$queue) {
            $this->stdout("Nothing waiting.\n");

            return ExitCode::OK;
        }

        $ok = 0;
        $failed = 0;

        foreach ($queue as $document) {
            $order = $document->getOrder();

            if (!$order instanceof Order) {
                continue;
            }

            try {
                $pushed = $documents->push($order, $this->force);
                $ok++;
                $this->stdout(sprintf("  ✓ %s → %s\n", $document->orderNumber, $pushed->bexioNumber ?: $pushed->bexioId), Console::FG_GREEN);
            } catch (Throwable $e) {
                $failed++;
                $this->stdout(sprintf("  ✗ %s — %s\n", $document->orderNumber, $e->getMessage()), Console::FG_RED);
            }
        }

        $this->stdout(sprintf("\n%d sent, %d failed.\n", $ok, $failed), $failed ? Console::FG_YELLOW : Console::FG_GREEN);
        Plugin::getInstance()->getLog()->prune();

        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }

    /**
     * Print the exact JSON that would be sent for an order, and what it comes to.
     */
    public function actionPreview(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with ID $orderId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $contactId = $plugin->getContacts()->getMappedContactId((string)$order->getEmail());
        $payload = $plugin->getBuilder()->forOrder($order, $contactId);

        $this->stdout($payload->toJson() . "\n\n");
        $this->stdout(sprintf("Order total:    %10.2f %s\n", $payload->orderTotal, (string)$order->currency));
        $this->stdout(sprintf("Document total: %10.2f %s\n", $payload->documentTotal, (string)$order->currency));
        $this->stdout(sprintf("  net %.2f + tax %.2f\n", $payload->netTotal, $payload->taxTotal));

        if (abs($payload->delta) > 0.000001) {
            $this->stdout(sprintf(
                "Difference:     %10.2f%s\n",
                $payload->delta,
                $payload->hasRoundingPosition ? ' (closed by a rounding position)' : '',
            ), Console::FG_YELLOW);
        }

        foreach ($payload->warnings as $warning) {
            $this->stdout('  ! ' . $warning . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * What Bexy currently holds, by status.
     */
    public function actionStatus(): int
    {
        $counts = Plugin::getInstance()->getDocuments()->getCounts();

        foreach ($counts as $label => $count) {
            $this->stdout(sprintf("%-12s %d\n", $label, $count));
        }

        return ExitCode::OK;
    }
}

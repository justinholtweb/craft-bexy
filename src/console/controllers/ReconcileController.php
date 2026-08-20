<?php

namespace justinholtweb\bexy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bexy\Plugin;
use yii\console\ExitCode;

/**
 * Pulling bexio's verdict back into Commerce. Point cron at `craft bexy/reconcile/run`.
 */
class ReconcileController extends Controller
{
    /** @var int How many documents to check in one run. */
    public int $limit = 100;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'run' ? ['limit'] : []);
    }

    /**
     * Check every document that could still change.
     */
    public function actionRun(): int
    {
        if (!Plugin::getInstance()->getAuth()->isConnected()) {
            $this->stderr("Bexy is not connected to bexio.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $result = Plugin::getInstance()->getReconcile()->reconcileAll($this->limit);

        $this->stdout(sprintf(
            "%d checked, %d changed, %d failed.\n",
            $result['checked'],
            $result['changed'],
            $result['failed'],
        ), $result['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

        Plugin::getInstance()->getLog()->prune();

        return $result['failed'] ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }
}

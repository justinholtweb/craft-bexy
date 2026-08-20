<?php

namespace justinholtweb\bexy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bexy\Plugin;
use yii\console\ExitCode;

/**
 * bexio's lookup tables, printed.
 *
 * Every ID Bexy needs in settings lives in one of these, and none of them are guessable.
 * `craft bexy/meta/taxes` beats clicking through bexio to find a tax rate's numeric ID.
 */
class MetaController extends Controller
{
    public function actionTaxes(): int
    {
        return $this->printTable(
            Plugin::getInstance()->getMeta()->getTaxes(),
            ['id', 'display_name', 'value', 'code'],
        );
    }

    public function actionAccounts(): int
    {
        return $this->printTable(
            Plugin::getInstance()->getMeta()->getAccounts(),
            ['id', 'account_no', 'name'],
        );
    }

    public function actionUsers(): int
    {
        return $this->printTable(
            Plugin::getInstance()->getMeta()->getUsers(),
            ['id', 'firstname', 'lastname', 'email'],
        );
    }

    public function actionCurrencies(): int
    {
        return $this->printTable(
            Plugin::getInstance()->getMeta()->getCurrencies(),
            ['id', 'name', 'exchange_rate'],
        );
    }

    public function actionUnits(): int
    {
        return $this->printTable(Plugin::getInstance()->getMeta()->getUnits(), ['id', 'name']);
    }

    public function actionLanguages(): int
    {
        return $this->printTable(Plugin::getInstance()->getMeta()->getLanguages(), ['id', 'name', 'iso_639_1']);
    }

    public function actionPaymentTypes(): int
    {
        return $this->printTable(Plugin::getInstance()->getMeta()->getPaymentTypes(), ['id', 'name']);
    }

    public function actionBankAccounts(): int
    {
        return $this->printTable(Plugin::getInstance()->getMeta()->getBankAccounts(), ['id', 'name', 'iban']);
    }

    /**
     * Drop every cached lookup.
     */
    public function actionFlush(): int
    {
        Plugin::getInstance()->getMeta()->flush();
        Plugin::getInstance()->getArticles()->flush();

        $this->stdout("Cleared.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Not `table()`: `craft\console\Controller` already has a public method by that name, and a
     * private override of it is a fatal compile error that takes `craft help` down with it.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $columns
     */
    private function printTable(array $rows, array $columns): int
    {
        if (!$rows) {
            $this->stdout("Nothing came back. Is Bexy connected?\n", Console::FG_YELLOW);

            return ExitCode::UNAVAILABLE;
        }

        $widths = [];

        foreach ($columns as $column) {
            $widths[$column] = max(
                mb_strlen($column),
                ...array_map(static fn(array $row): int => mb_strlen((string)($row[$column] ?? '')), $rows),
            );
        }

        $line = static function(array $values) use ($columns, $widths): string {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = str_pad((string)($values[$column] ?? ''), $widths[$column]);
            }

            return implode('  ', $cells);
        };

        $this->stdout($line(array_combine($columns, $columns)) . "\n", Console::BOLD);

        foreach ($rows as $row) {
            $this->stdout($line($row) . "\n");
        }

        $this->stdout(sprintf("\n%d rows.\n", count($rows)));

        return ExitCode::OK;
    }
}

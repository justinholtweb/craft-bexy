<?php

namespace justinholtweb\bexy\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\bexy\db\Table;

/**
 * Bexy install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::TOKENS);
        $this->dropTableIfExists(Table::CONTACTS);
        $this->dropTableIfExists(Table::PAYMENTS);
        $this->dropTableIfExists(Table::DOCUMENTS);

        return true;
    }

    private function createTables(): void
    {
        // One row per Commerce order. The unique index on orderId is what makes "push this order"
        // idempotent locally; `apiReference` is what makes it idempotent at bexio's end.
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            'orderNumber' => $this->string(64),
            'apiReference' => $this->string(255)->notNull(),
            'documentType' => $this->string(16)->notNull()->defaultValue('invoice'),
            'bexioId' => $this->integer(),
            'bexioNumber' => $this->string(64),
            'bexioContactId' => $this->integer(),
            'bexioStatusId' => $this->integer(),
            // pending | synced | failed | cancelled
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'isIssued' => $this->boolean()->notNull()->defaultValue(false),
            'isSent' => $this->boolean()->notNull()->defaultValue(false),
            'currency' => $this->string(8),
            'orderTotal' => $this->decimal(14, 4),
            'documentTotal' => $this->decimal(14, 4),
            'roundingDelta' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'amountPaid' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'amountRemaining' => $this->decimal(14, 4),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            // Set when a refund needs a human in bexio, because the API cannot raise a credit note.
            'needsAttention' => $this->string(255),
            'payload' => $this->mediumText(),
            'dateSynced' => $this->dateTime(),
            'dateReconciled' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // A Commerce transaction is posted to bexio at most once, ever.
        $this->createTable(Table::PAYMENTS, [
            'id' => $this->primaryKey(),
            'documentId' => $this->integer()->notNull(),
            'transactionId' => $this->integer()->notNull(),
            'bexioPaymentId' => $this->integer(),
            'amount' => $this->decimal(14, 4)->notNull(),
            'currency' => $this->string(8),
            'paidOn' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Repeat customers keep their bexio contact instead of collecting duplicates.
        $this->createTable(Table::CONTACTS, [
            'id' => $this->primaryKey(),
            'email' => $this->string(255)->notNull(),
            'customerId' => $this->integer(),
            'bexioContactId' => $this->integer()->notNull(),
            'name' => $this->string(255),
            'source' => $this->string(16)->notNull()->defaultValue('created'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Exactly one row, id = 1. Tokens are secrets and environment-specific, so they live here
        // rather than in project config, which is committed.
        $this->createTable(Table::TOKENS, [
            'id' => $this->primaryKey(),
            'accessToken' => $this->text(),
            'refreshToken' => $this->text(),
            'scope' => $this->text(),
            'companyId' => $this->string(64),
            'companyName' => $this->string(255),
            'userEmail' => $this->string(255),
            'bexioUserId' => $this->integer(),
            'dateExpires' => $this->dateTime(),
            'dateRefreshed' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(64)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'method' => $this->string(8),
            'endpoint' => $this->string(255),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'orderId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::DOCUMENTS, ['orderId'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['apiReference'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['status'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['bexioId'], false);

        $this->createIndex(null, Table::PAYMENTS, ['transactionId'], true);
        $this->createIndex(null, Table::PAYMENTS, ['documentId'], false);

        $this->createIndex(null, Table::CONTACTS, ['email'], true);
        $this->createIndex(null, Table::CONTACTS, ['bexioContactId'], false);

        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['orderId'], false);
    }

    private function addForeignKeys(): void
    {
        // Orders are elements; deleting one takes its Bexy row with it. The bexio document is
        // deliberately left alone — it is an accounting record and not ours to delete.
        $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::PAYMENTS, ['documentId'], Table::DOCUMENTS, ['id'], 'CASCADE', null);
    }
}

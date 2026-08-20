<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\models\DocumentPayload;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\Plugin;
use Throwable;

/**
 * Pushing orders to bexio, and everything Bexy knows about what happened.
 *
 * `record()` is the only place a `bexy_documents` row is written. The queue job, the CP button,
 * the console command and the adopt-an-existing-document path all end up there, so the decision
 * about what state an order is in is made once and cannot contradict itself.
 */
class Documents extends Component
{
    /**
     * Push an order to bexio.
     *
     * @param bool $force Push even when this order already has a synced document. Only ever set
     *                    from an explicit human action, because it can raise a second invoice.
     * @throws BexioApiException
     */
    public function push(Order $order, bool $force = false): Document
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $existing = $this->getDocumentForOrder($order->id);

        if ($existing && $existing->bexioId && !$force) {
            return $existing;
        }

        $document = $existing ?? new Document([
            'orderId' => $order->id,
            'storeId' => $order->storeId,
            'orderNumber' => $order->reference ?: $order->number,
            'apiReference' => $plugin->getBuilder()->apiReference($order),
            'documentType' => $settings->documentType,
        ]);

        $document->setOrder($order);
        $document->attempts++;

        try {
            $contactId = $plugin->getContacts()->resolveForOrder($order);

            if ($contactId && $settings->updateContacts) {
                $plugin->getContacts()->update($contactId, $order);
            }

            $payload = $plugin->getBuilder()->forOrder($order, $contactId);

            // Look before creating. A previous attempt that timed out on our side may well have
            // been committed on bexio's, and `api_reference` is the only way to find out.
            $adopted = $this->findByApiReference($payload->body['api_reference'] ?? '', $settings->documentType);

            $result = $adopted ?? $this->create($payload, $order, $settings);

            $document->bexioId = (int)$result['id'];
            $document->bexioNumber = $result['document_nr'] ?? null;
            $document->bexioContactId = $contactId;
            $document->bexioStatusId = isset($result['kb_item_status_id']) ? (int)$result['kb_item_status_id'] : null;
            $document->currency = $order->currency;
            $document->orderTotal = $payload->orderTotal;
            $document->documentTotal = $payload->documentTotal;
            $document->roundingDelta = $payload->delta;
            $document->payload = $payload->toJson();
            $document->status = Document::STATUS_SYNCED;
            $document->lastError = $payload->warnings ? implode("\n", $payload->warnings) : null;
            $document->dateSynced = new DateTime();

            if ($adopted) {
                $plugin->getLog()->write('document.adopt', [
                    'level' => 'warning',
                    'orderId' => $order->id,
                    'summary' => Craft::t('bexy', 'Adopted existing bexio document {number}', [
                        'number' => $result['document_nr'] ?? $result['id'],
                    ]),
                    'message' => Craft::t('bexy', 'A document with this order’s api_reference already existed, so no second one was created.'),
                ]);
            }

            $this->record($document);

            if ($settings->documentType === Document::TYPE_INVOICE) {
                $this->finaliseInvoice($document, $order, $settings);

                if ($settings->pushPayments) {
                    $plugin->getPayments()->pushForOrder($order, $document);
                }
            }

            return $document;
        } catch (BexioApiException $e) {
            $document->status = Document::STATUS_FAILED;
            $document->lastError = $plugin->getApi()->explain($e);
            $this->record($document);

            throw $e;
        } catch (Throwable $e) {
            $document->status = Document::STATUS_FAILED;
            $document->lastError = $e->getMessage();
            $this->record($document);

            throw $e;
        }
    }

    /**
     * Issue and, if asked, send the invoice.
     *
     * Deliberately separate from the push and deliberately forgiving: an invoice that exists but
     * was not emailed is a nuisance, and one that does not exist because the mail server was down
     * is a missing accounting record.
     */
    public function finaliseInvoice(Document $document, Order $order, Settings $settings): void
    {
        if (!$document->bexioId) {
            return;
        }

        $api = Plugin::getInstance()->getApi();

        if ($settings->issueDocument && !$document->isIssued) {
            try {
                $api->post(sprintf('/2.0/kb_invoice/%d/issue', $document->bexioId), [], $order->id);
                $document->isIssued = true;
                $document->bexioStatusId = 31; // Issued and not yet paid.
            } catch (BexioApiException $e) {
                $document->needsAttention = Craft::t('bexy', 'The invoice was created but could not be issued: {message}', [
                    'message' => $api->explain($e),
                ]);
            }
        }

        if ($settings->sendDocument && $document->isIssued && !$document->isSent) {
            try {
                $api->post(sprintf('/2.0/kb_invoice/%d/send', $document->bexioId), [
                    'recipient_email' => $order->getEmail(),
                    'subject' => $this->tokens($settings->emailSubject, $order),
                    'message' => $this->tokens($settings->emailBody, $order),
                    'mark_as_open' => false,
                ], $order->id);
                $document->isSent = true;
            } catch (BexioApiException $e) {
                $document->needsAttention = Craft::t('bexy', 'The invoice was issued but bexio could not email it: {message}', [
                    'message' => $api->explain($e),
                ]);
            }
        }

        $this->record($document);
    }

    /**
     * Cancel the bexio document.
     *
     * bexio's API can raise an invoice and take a payment against it, but it cannot raise a credit
     * note — the only credit-voucher endpoint is the one that fetches a PDF. So a refund is either
     * a cancellation, which bexio only allows while nothing has been paid, or a job for a human.
     *
     * @throws BexioApiException
     */
    public function cancel(Document $document): bool
    {
        if (!$document->bexioId || $document->documentType !== Document::TYPE_INVOICE) {
            return false;
        }

        Plugin::getInstance()->getApi()->post(
            sprintf('/2.0/kb_invoice/%d/cancel', $document->bexioId),
            [],
            $document->orderId,
        );

        $document->status = Document::STATUS_CANCELLED;
        $document->bexioStatusId = 19;
        $document->needsAttention = null;
        $this->record($document);

        return true;
    }

    /**
     * Fetch the document's PDF from bexio.
     *
     * @return array{name: string, mime: string, content: string}|null
     * @throws BexioApiException
     */
    public function getPdf(Document $document): ?array
    {
        if (!$document->bexioId) {
            return null;
        }

        $path = $document->documentType === Document::TYPE_ORDER
            ? sprintf('/2.0/kb_order/%d/pdf', $document->bexioId)
            : sprintf('/2.0/kb_invoice/%d/pdf', $document->bexioId);

        $response = Plugin::getInstance()->getApi()->get($path, [], $document->orderId);

        if (empty($response['content'])) {
            return null;
        }

        // bexio hands the PDF back as base64 inside a JSON envelope, not as a binary body.
        return [
            'name' => (string)($response['name'] ?? sprintf('%s.pdf', $document->bexioNumber ?: $document->bexioId)),
            'mime' => (string)($response['mime'] ?? 'application/pdf'),
            'content' => (string)base64_decode((string)$response['content'], true),
        ];
    }

    /**
     * Look for a document already carrying this `api_reference`.
     *
     * @return array<string, mixed>|null
     * @throws BexioApiException
     */
    public function findByApiReference(string $reference, string $documentType): ?array
    {
        if ($reference === '') {
            return null;
        }

        $path = $documentType === Document::TYPE_ORDER ? '/2.0/kb_order' : '/2.0/kb_invoice';

        $matches = Plugin::getInstance()->getApi()->search($path, [
            ['field' => 'api_reference', 'value' => $reference, 'criteria' => '='],
        ], ['limit' => 2]);

        return $matches[0] ?? null;
    }

    /**
     * The only place a document row is written.
     */
    public function record(Document $document): bool
    {
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'orderId' => $document->orderId,
            'storeId' => $document->storeId,
            'orderNumber' => $document->orderNumber,
            'apiReference' => $document->apiReference,
            'documentType' => $document->documentType,
            'bexioId' => $document->bexioId,
            'bexioNumber' => $document->bexioNumber,
            'bexioContactId' => $document->bexioContactId,
            'bexioStatusId' => $document->bexioStatusId,
            'status' => $document->status,
            'isIssued' => $document->isIssued,
            'isSent' => $document->isSent,
            'currency' => $document->currency,
            'orderTotal' => $document->orderTotal,
            'documentTotal' => $document->documentTotal,
            'roundingDelta' => $document->roundingDelta,
            'amountPaid' => $document->amountPaid,
            'amountRemaining' => $document->amountRemaining,
            'attempts' => $document->attempts,
            'lastError' => $document->lastError,
            'needsAttention' => $document->needsAttention,
            'payload' => $document->payload,
            'dateSynced' => $document->dateSynced ? Db::prepareDateForDb($document->dateSynced) : null,
            'dateReconciled' => $document->dateReconciled ? Db::prepareDateForDb($document->dateReconciled) : null,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();

        if ($document->id) {
            $db->createCommand()->update(Table::DOCUMENTS, $values, ['id' => $document->id])->execute();

            return true;
        }

        // The unique index on orderId is the backstop. Two workers picking up the same order at
        // the same moment both get here; the second one updates rather than inserting a twin.
        $existingId = (new Query())
            ->select(['id'])
            ->from(Table::DOCUMENTS)
            ->where(['orderId' => $document->orderId])
            ->scalar();

        if ($existingId) {
            $document->id = (int)$existingId;
            $db->createCommand()->update(Table::DOCUMENTS, $values, ['id' => $document->id])->execute();

            return true;
        }

        $db->createCommand()->insert(Table::DOCUMENTS, $values + [
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $document->id = (int)$db->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::DOCUMENTS));

        return true;
    }

    /**
     * Note an order as awaiting its push, before any network call happens.
     *
     * This is what runs during order completion. It touches the database and nothing else, so a
     * bexio outage can delay a document but can never hold up a checkout.
     */
    public function markPending(Order $order): Document
    {
        $document = $this->getDocumentForOrder($order->id) ?? new Document([
            'orderId' => $order->id,
            'storeId' => $order->storeId,
        ]);

        if ($document->bexioId) {
            return $document;
        }

        $document->orderNumber = $order->reference ?: $order->number;
        $document->apiReference = Plugin::getInstance()->getBuilder()->apiReference($order);
        $document->documentType = Plugin::getInstance()->getSettings()->documentType;
        $document->currency = $order->currency;
        $document->orderTotal = (float)$order->getTotalPrice();
        $document->status = Document::STATUS_PENDING;
        $document->setOrder($order);

        $this->record($document);

        return $document;
    }

    public function getDocumentForOrder(int $orderId): ?Document
    {
        $row = (new Query())->from(Table::DOCUMENTS)->where(['orderId' => $orderId])->one();

        return $row ? $this->toModel($row) : null;
    }

    public function getDocument(int $id): ?Document
    {
        $row = (new Query())->from(Table::DOCUMENTS)->where(['id' => $id])->one();

        return $row ? $this->toModel($row) : null;
    }

    /**
     * @param array{status?: string|null, type?: string|null, needsAttention?: bool, search?: string|null} $criteria
     * @return Document[]
     */
    public function getDocuments(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $rows = $this->baseQuery($criteria)
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row): Document => $this->toModel($row), $rows);
    }

    /**
     * @param array{status?: string|null, type?: string|null, needsAttention?: bool, search?: string|null} $criteria
     */
    public function getTotal(array $criteria = []): int
    {
        return (int)$this->baseQuery($criteria)->count('[[id]]');
    }

    /**
     * Documents that still need pushing — pending ones, and failures worth another go.
     *
     * @return Document[]
     */
    public function getRetryable(int $limit = 50, int $maxAttempts = 5): array
    {
        $rows = (new Query())
            ->from(Table::DOCUMENTS)
            ->where(['status' => [Document::STATUS_PENDING, Document::STATUS_FAILED]])
            ->andWhere(['bexioId' => null])
            ->andWhere(['<', 'attempts', $maxAttempts])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row): Document => $this->toModel($row), $rows);
    }

    /**
     * Counts for the CP index tabs and the doctor command.
     *
     * @return array{total: int, pending: int, synced: int, failed: int, attention: int}
     */
    public function getCounts(): array
    {
        $counts = [
            'total' => $this->getTotal(),
            'pending' => $this->getTotal(['status' => Document::STATUS_PENDING]),
            'synced' => $this->getTotal(['status' => Document::STATUS_SYNCED]),
            'failed' => $this->getTotal(['status' => Document::STATUS_FAILED]),
            'attention' => (int)(new Query())
                ->from(Table::DOCUMENTS)
                ->where(['not', ['needsAttention' => null]])
                ->count('[[id]]'),
        ];

        return $counts;
    }

    /**
     * Forget Bexy's record of an order. The bexio document is left where it is — it is an
     * accounting record, and deleting it is the merchant's decision to make in bexio.
     */
    public function forget(int $documentId): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $documentId])
            ->execute();
    }

    /**
     * @param array<string, mixed> $payloadBody
     * @return array<string, mixed>
     * @throws BexioApiException
     */
    private function create(DocumentPayload $payload, Order $order, Settings $settings): array
    {
        $path = $settings->documentType === Document::TYPE_ORDER ? '/2.0/kb_order' : '/2.0/kb_invoice';
        $result = Plugin::getInstance()->getApi()->post($path, $payload->body, $order->id);

        if (empty($result['id'])) {
            throw new BexioApiException(
                Craft::t('bexy', 'bexio accepted the document but returned no ID.'),
                0,
                $path,
                $result,
            );
        }

        return $result;
    }

    /**
     * @param array{status?: string|null, type?: string|null, needsAttention?: bool, search?: string|null} $criteria
     */
    private function baseQuery(array $criteria): Query
    {
        $query = (new Query())->from(Table::DOCUMENTS);

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['type'])) {
            $query->andWhere(['documentType' => $criteria['type']]);
        }

        if (!empty($criteria['needsAttention'])) {
            $query->andWhere(['not', ['needsAttention' => null]]);
        }

        if (!empty($criteria['search'])) {
            $query->andWhere([
                'or',
                ['like', 'orderNumber', $criteria['search']],
                ['like', 'bexioNumber', $criteria['search']],
                ['like', 'apiReference', $criteria['search']],
            ]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toModel(array $row): Document
    {
        return new Document([
            'id' => (int)$row['id'],
            'orderId' => $row['orderId'] !== null ? (int)$row['orderId'] : null,
            'storeId' => $row['storeId'] !== null ? (int)$row['storeId'] : null,
            'orderNumber' => $row['orderNumber'],
            'apiReference' => (string)$row['apiReference'],
            'documentType' => (string)$row['documentType'],
            'bexioId' => $row['bexioId'] !== null ? (int)$row['bexioId'] : null,
            'bexioNumber' => $row['bexioNumber'],
            'bexioContactId' => $row['bexioContactId'] !== null ? (int)$row['bexioContactId'] : null,
            'bexioStatusId' => $row['bexioStatusId'] !== null ? (int)$row['bexioStatusId'] : null,
            'status' => (string)$row['status'],
            'isIssued' => (bool)$row['isIssued'],
            'isSent' => (bool)$row['isSent'],
            'currency' => $row['currency'],
            'orderTotal' => $row['orderTotal'] !== null ? (float)$row['orderTotal'] : null,
            'documentTotal' => $row['documentTotal'] !== null ? (float)$row['documentTotal'] : null,
            'roundingDelta' => (float)$row['roundingDelta'],
            'amountPaid' => (float)$row['amountPaid'],
            'amountRemaining' => $row['amountRemaining'] !== null ? (float)$row['amountRemaining'] : null,
            'attempts' => (int)$row['attempts'],
            'lastError' => $row['lastError'],
            'needsAttention' => $row['needsAttention'],
            'payload' => $row['payload'],
            'dateSynced' => $row['dateSynced'] ? DateTimeHelper::toDateTime($row['dateSynced'], true) : null,
            'dateReconciled' => $row['dateReconciled'] ? DateTimeHelper::toDateTime($row['dateReconciled'], true) : null,
            'dateCreated' => $row['dateCreated'] ? DateTimeHelper::toDateTime($row['dateCreated'], true) : null,
            'dateUpdated' => $row['dateUpdated'] ? DateTimeHelper::toDateTime($row['dateUpdated'], true) : null,
        ]);
    }

    private function tokens(string $template, Order $order): string
    {
        return strtr($template, [
            '{number}' => (string)($order->reference ?: $order->number),
            '{name}' => (string)($order->getBillingAddress()?->fullName ?? ''),
            '{email}' => (string)$order->getEmail(),
        ]);
    }
}

<?php

namespace justinholtweb\bexy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bexy\db\Table;
use justinholtweb\bexy\models\LogEntry;
use justinholtweb\bexy\Plugin;
use Throwable;

/**
 * The connection log.
 *
 * An accounting integration that quietly books nothing is the hardest kind to debug: the shop
 * looks fine, bexio looks empty, and neither end volunteers a reason. Every call Bexy makes lands
 * here with its status, its timing and — unless the merchant turns payloads off — the bodies.
 */
class Log extends Component
{
    /** Bodies longer than this are truncated. Nobody reads past the first screen. */
    public const MAX_PAYLOAD = 65535;

    /**
     * @param array{
     *     level?: string,
     *     method?: string|null,
     *     endpoint?: string|null,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     orderId?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => mb_substr($action, 0, 64),
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'method' => isset($data['method']) ? mb_substr((string)$data['method'], 0, 8) : null,
                'endpoint' => isset($data['endpoint']) ? mb_substr((string)$data['endpoint'], 0, 255) : null,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $settings->logPayloads ? $this->truncate($data['request'] ?? null) : null,
                'response' => $settings->logPayloads ? $this->truncate($data['response'] ?? null) : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (Throwable $e) {
            // The log is diagnostics. It must never be the reason a sync fails.
            Craft::error('Bexy could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @param array{level?: string|null, action?: string|null, orderId?: int|null, search?: string|null} $criteria
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->baseQuery($criteria)
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return array_map(fn(array $row): LogEntry => $this->toModel($row), $query->all());
    }

    /**
     * @param array{level?: string|null, action?: string|null, orderId?: int|null, search?: string|null} $criteria
     */
    public function getTotal(array $criteria = []): int
    {
        return (int)$this->baseQuery($criteria)->count('[[id]]');
    }

    public function getEntry(int $id): ?LogEntry
    {
        $row = (new Query())->from(Table::LOG)->where(['id' => $id])->one();

        return $row ? $this->toModel($row) : null;
    }

    /**
     * Distinct action names, for the filter dropdown.
     *
     * @return string[]
     */
    public function getActions(): array
    {
        return (new Query())
            ->select(['action'])
            ->distinct()
            ->from(Table::LOG)
            ->orderBy(['action' => SORT_ASC])
            ->column();
    }

    public function clear(): int
    {
        return Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    /**
     * Drop entries past the retention window. Called from the sync command, so a shop that never
     * opens the CP still does not grow an unbounded log table.
     */
    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify(sprintf('-%d days', $days));

        return Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    /**
     * @param array{level?: string|null, action?: string|null, orderId?: int|null, search?: string|null} $criteria
     */
    private function baseQuery(array $criteria): Query
    {
        $query = (new Query())->from(Table::LOG);

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => (int)$criteria['orderId']]);
        }

        if (!empty($criteria['search'])) {
            $query->andWhere([
                'or',
                ['like', 'summary', $criteria['search']],
                ['like', 'message', $criteria['search']],
                ['like', 'endpoint', $criteria['search']],
            ]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toModel(array $row): LogEntry
    {
        return new LogEntry([
            'id' => (int)$row['id'],
            'action' => (string)$row['action'],
            'level' => (string)$row['level'],
            'method' => $row['method'] ?: null,
            'endpoint' => $row['endpoint'] ?: null,
            'statusCode' => $row['statusCode'] !== null ? (int)$row['statusCode'] : null,
            'durationMs' => $row['durationMs'] !== null ? (int)$row['durationMs'] : null,
            'orderId' => $row['orderId'] !== null ? (int)$row['orderId'] : null,
            'summary' => $row['summary'] ?: null,
            'message' => $row['message'] ?: null,
            'request' => $row['request'] ?: null,
            'response' => $row['response'] ?: null,
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated'], true) ?: null,
        ]);
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > self::MAX_PAYLOAD
            ? mb_substr($value, 0, self::MAX_PAYLOAD) . "\n… truncated"
            : $value;
    }
}

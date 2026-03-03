<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\helpers\Db;
use DateInterval;
use DateTimeImmutable;
use pragmatic\webtoolkit\domains\sync\models\TransferLogModel;
use yii\db\Query;

class TransferLogService
{
    public const TABLE = '{{%pragmatic_toolkit_sync_transfer_logs}}';

    public function create(string $direction, string $status, string $packageName, array $summary = [], ?string $errorMessage = null): ?int
    {
        if (!$this->tableExists()) {
            return null;
        }

        $userId = Craft::$app->getUser()->getId();
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'direction' => $direction,
            'status' => $status,
            'triggeredByUserId' => $userId ?: null,
            'packageName' => $packageName,
            'packageSummaryJson' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errorMessage' => $errorMessage,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    public function update(?int $id, string $status, ?array $summary = null, ?string $errorMessage = null): void
    {
        if (!$id || !$this->tableExists()) {
            return;
        }

        $payload = [
            'status' => $status,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];

        if ($summary !== null) {
            $payload['packageSummaryJson'] = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($errorMessage !== null) {
            $payload['errorMessage'] = $errorMessage;
        }

        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, $payload, ['id' => $id])
            ->execute();
    }

    /**
     * @return TransferLogModel[]
     */
    public function recent(int $limit = 15): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = (new Query())
            ->select([
                'logs.id',
                'logs.direction',
                'logs.status',
                'logs.packageName',
                'logs.packageSummaryJson',
                'logs.errorMessage',
                'logs.dateCreated',
                'users.username AS triggeredBy',
            ])
            ->from(['logs' => self::TABLE])
            ->leftJoin('{{%users}} users', '[[users.id]] = [[logs.triggeredByUserId]]')
            ->orderBy(['logs.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(function(array $row): TransferLogModel {
            $summary = json_decode((string)($row['packageSummaryJson'] ?? ''), true);
            if (!is_array($summary)) {
                $summary = [];
            }

            return new TransferLogModel([
                'id' => (int)($row['id'] ?? 0),
                'direction' => (string)($row['direction'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'packageName' => (string)($row['packageName'] ?? ''),
                'summary' => $this->formatSummary($summary),
                'errorMessage' => (string)($row['errorMessage'] ?? ''),
                'createdAt' => (string)($row['dateCreated'] ?? ''),
                'triggeredBy' => (string)($row['triggeredBy'] ?? ''),
            ]);
        }, $rows);
    }

    public function prune(int $retentionDays): void
    {
        if ($retentionDays < 1 || !$this->tableExists()) {
            return;
        }

        $cutoff = (new DateTimeImmutable())->sub(new DateInterval(sprintf('P%dD', $retentionDays)));

        Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function tableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    private function formatSummary(array $summary): string
    {
        $parts = [];

        if (isset($summary['dbDriver']) && $summary['dbDriver'] !== '') {
            $parts[] = 'DB: ' . $summary['dbDriver'];
        }

        if (isset($summary['volumeCount'])) {
            $parts[] = 'Volumes: ' . (int)$summary['volumeCount'];
        }

        if (isset($summary['fileCount'])) {
            $parts[] = 'Files: ' . (int)$summary['fileCount'];
        }

        if (isset($summary['totalBytes'])) {
            $parts[] = 'Bytes: ' . (int)$summary['totalBytes'];
        }

        return implode(' | ', $parts);
    }
}

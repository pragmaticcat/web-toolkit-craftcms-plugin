<?php

namespace pragmatic\webtoolkit\domains\sync\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTimeImmutable;
use pragmatic\webtoolkit\domains\sync\models\TransferLogModel;
use yii\db\Query;

class TransferLogService
{
    public const TABLE = '{{%pragmatic_toolkit_sync_transfer_logs}}';

    public function create(string $direction, string $status, string $packageName, array $summary = [], ?string $errorMessage = null, array $options = []): ?int
    {
        if (!$this->tableExists()) {
            return null;
        }

        $userId = Craft::$app->getUser()->getId();
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'direction' => $direction,
                'status' => $status,
                'triggeredByUserId' => $userId ?: null,
                'jobId' => $options['jobId'] ?? null,
                'packageName' => $packageName,
                'packageSummaryJson' => $this->encode($summary),
                'packageManifestJson' => isset($options['manifest']) ? $this->encode((array)$options['manifest']) : null,
                'warningJson' => isset($options['warnings']) ? $this->encode(array_values((array)$options['warnings'])) : null,
                'artifactPath' => $options['artifactPath'] ?? null,
                'artifactFilename' => $options['artifactFilename'] ?? null,
                'artifactExpiresAt' => isset($options['artifactExpiresAt']) ? Db::prepareDateForDb($options['artifactExpiresAt']) : null,
                'progressLabel' => $options['progressLabel'] ?? null,
                'startedAt' => isset($options['startedAt']) ? Db::prepareDateForDb($options['startedAt']) : null,
                'finishedAt' => isset($options['finishedAt']) ? Db::prepareDateForDb($options['finishedAt']) : null,
                'errorMessage' => $errorMessage,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return (int)Craft::$app->getDb()->getLastInsertID();
        } catch (\Throwable) {
            return null;
        }
    }

    public function update(?int $id, array $attributes): bool
    {
        if (!$id || !$this->tableExists()) {
            return false;
        }

        $payload = ['dateUpdated' => Db::prepareDateForDb(new \DateTime())];

        $map = [
            'status' => 'status',
            'jobId' => 'jobId',
            'packageName' => 'packageName',
            'artifactPath' => 'artifactPath',
            'artifactFilename' => 'artifactFilename',
            'progressLabel' => 'progressLabel',
            'errorMessage' => 'errorMessage',
        ];

        foreach ($map as $inputKey => $column) {
            if (array_key_exists($inputKey, $attributes)) {
                $payload[$column] = $attributes[$inputKey];
            }
        }

        if (array_key_exists('summary', $attributes)) {
            $payload['packageSummaryJson'] = $this->encode((array)$attributes['summary']);
        }
        if (array_key_exists('manifest', $attributes)) {
            $payload['packageManifestJson'] = $this->encode((array)$attributes['manifest']);
        }
        if (array_key_exists('warnings', $attributes)) {
            $payload['warningJson'] = $this->encode(array_values((array)$attributes['warnings']));
        }
        if (array_key_exists('artifactExpiresAt', $attributes)) {
            $payload['artifactExpiresAt'] = $attributes['artifactExpiresAt'] ? Db::prepareDateForDb($attributes['artifactExpiresAt']) : null;
        }
        if (array_key_exists('startedAt', $attributes)) {
            $payload['startedAt'] = $attributes['startedAt'] ? Db::prepareDateForDb($attributes['startedAt']) : null;
        }
        if (array_key_exists('finishedAt', $attributes)) {
            $payload['finishedAt'] = $attributes['finishedAt'] ? Db::prepareDateForDb($attributes['finishedAt']) : null;
        }

        try {
            $rows = Craft::$app->getDb()->createCommand()
                ->update(self::TABLE, $payload, ['id' => $id])
                ->execute();

            return $rows > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getById(int $id): ?TransferLogModel
    {
        if ($id <= 0 || !$this->tableExists()) {
            return null;
        }

        $row = $this->baseQuery()
            ->andWhere(['logs.id' => $id])
            ->one();

        return is_array($row) ? $this->toModel($row) : null;
    }

    /**
     * @return TransferLogModel[]
     */
    public function recent(int $limit = 15): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = $this->baseQuery()
            ->orderBy(['logs.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row): TransferLogModel => $this->toModel($row), $rows);
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

    private function baseQuery(): Query
    {
        return (new Query())
            ->select([
                'logs.id',
                'logs.jobId',
                'logs.direction',
                'logs.status',
                'logs.packageName',
                'logs.packageSummaryJson',
                'logs.packageManifestJson',
                'logs.warningJson',
                'logs.errorMessage',
                'logs.dateCreated',
                'logs.progressLabel',
                'logs.artifactFilename',
                'logs.artifactExpiresAt',
                'users.username AS triggeredBy',
            ])
            ->from(['logs' => self::TABLE])
            ->leftJoin('{{%users}} users', '[[users.id]] = [[logs.triggeredByUserId]]');
    }

    private function toModel(array $row): TransferLogModel
    {
        $summary = $this->decodeJson((string)($row['packageSummaryJson'] ?? ''));
        $warnings = array_values(array_filter(array_map('strval', $this->decodeJson((string)($row['warningJson'] ?? '')))));
        $artifactExpiresAt = (string)($row['artifactExpiresAt'] ?? '');
        $canDownload = (string)($row['status'] ?? '') === 'success'
            && (string)($row['artifactFilename'] ?? '') !== ''
            && ($artifactExpiresAt === '' || strtotime($artifactExpiresAt) === false || strtotime($artifactExpiresAt) >= time());

        return new TransferLogModel([
            'id' => (int)($row['id'] ?? 0),
            'jobId' => (int)($row['jobId'] ?? 0),
            'direction' => (string)($row['direction'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'packageName' => (string)($row['packageName'] ?? ''),
            'summary' => $this->formatSummary($summary),
            'errorMessage' => (string)($row['errorMessage'] ?? ''),
            'createdAt' => (string)($row['dateCreated'] ?? ''),
            'triggeredBy' => (string)($row['triggeredBy'] ?? ''),
            'progressLabel' => (string)($row['progressLabel'] ?? ''),
            'artifactFilename' => (string)($row['artifactFilename'] ?? ''),
            'canDownload' => $canDownload,
            'warnings' => $warnings,
        ]);
    }

    private function encode(array $data): ?string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatSummary(array $summary): string
    {
        $parts = [];

        if (isset($summary['dbEngine']) && $summary['dbEngine'] !== '') {
            $parts[] = 'DB: ' . $summary['dbEngine'];
        } elseif (isset($summary['dbDriver']) && $summary['dbDriver'] !== '') {
            $parts[] = 'DB: ' . $summary['dbDriver'];
        }

        if (isset($summary['tableCount'])) {
            $parts[] = 'Tables: ' . (int)$summary['tableCount'];
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

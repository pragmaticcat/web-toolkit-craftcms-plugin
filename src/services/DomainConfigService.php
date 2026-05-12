<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class DomainConfigService extends Component
{
    private const TABLE = '{{%pragmatic_toolkit_domain_config}}';

    /**
     * @param array<string, FeatureProviderInterface> $providers
     * @return array<string, array{enabled:bool,order:int}>
     */
    public function getConfiguration(array $providers): array
    {
        $base = $this->buildDefaultConfiguration($providers);

        if (!$this->tableExists()) {
            return $base;
        }

        $rows = (new Query())
            ->select(['domainKey', 'enabled', 'sortOrder'])
            ->from(self::TABLE)
            ->all();

        foreach ($rows as $row) {
            $key = (string)($row['domainKey'] ?? '');
            if ($key === '' || !isset($base[$key])) {
                continue;
            }

            $base[$key]['enabled'] = (bool)($row['enabled'] ?? true);
            $base[$key]['order'] = max(1, (int)($row['sortOrder'] ?? $base[$key]['order']));
        }

        return $base;
    }

    /**
     * @param array<string, FeatureProviderInterface> $providers
     * @param array<string, array{enabled:bool,order:int}> $config
     */
    public function saveConfiguration(array $providers, array $config): bool
    {
        if (!$this->tableExists() && !$this->ensureTableExists()) {
            Craft::error('Cannot save domain configuration: table missing and auto-create failed.', __METHOD__);
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            $now = Db::prepareDateForDb(new \DateTime());
            foreach ($providers as $key => $_provider) {
                $row = $config[$key] ?? null;
                if (!is_array($row)) {
                    continue;
                }

                $db->createCommand()->upsert(
                    self::TABLE,
                    [
                        'domainKey' => $key,
                        'enabled' => !empty($row['enabled']) ? 1 : 0,
                        'sortOrder' => max(1, (int)($row['order'] ?? 1)),
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ],
                    [
                        'enabled' => !empty($row['enabled']) ? 1 : 0,
                        'sortOrder' => max(1, (int)($row['order'] ?? 1)),
                        'dateUpdated' => $now,
                    ]
                )->execute();
            }

            $db->createCommand()
                ->delete(self::TABLE, ['not in', 'domainKey', array_keys($providers)])
                ->execute();

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error('Could not save domain configuration: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * @param array<string, FeatureProviderInterface> $providers
     * @return array<string, array{enabled:bool,order:int}>
     */
    private function buildDefaultConfiguration(array $providers): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $configuredOrder = array_values(array_filter(
            (array)($settings->domainOrder ?? []),
            static fn(mixed $value): bool => is_string($value) && $value !== ''
        ));
        $orderLookup = array_flip($configuredOrder);

        $result = [];
        $index = 1;
        foreach ($providers as $key => $provider) {
            $flag = 'enable' . ucfirst($provider::domainKey());
            $enabled = property_exists($settings, $flag) ? (bool)$settings->{$flag} : true;
            $order = isset($orderLookup[$key]) ? ((int)$orderLookup[$key] + 1) : $index;

            $result[$key] = [
                'enabled' => $enabled,
                'order' => $order,
            ];
            $index++;
        }

        return $result;
    }

    private function tableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    private function ensureTableExists(): bool
    {
        if ($this->tableExists()) {
            return true;
        }

        $db = Craft::$app->getDb();
        try {
            $db->createCommand()->createTable(self::TABLE, [
                'id' => 'pk',
                'domainKey' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
                'enabled' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_BOOLEAN)->notNull()->defaultValue(true),
                'sortOrder' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_INTEGER)->notNull()->defaultValue(1),
                'dateCreated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
                'dateUpdated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
                'uid' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_CHAR, 36),
            ])->execute();

            $db->createCommand()->createIndex(
                'pwt_domain_config_domain_key_unique',
                self::TABLE,
                ['domainKey'],
                true
            )->execute();

            return true;
        } catch (\Throwable $e) {
            Craft::error('Could not auto-create domain config table: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}

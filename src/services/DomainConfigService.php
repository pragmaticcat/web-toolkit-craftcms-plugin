<?php

namespace pragmatic\webtoolkit\services;

use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use pragmatic\webtoolkit\db\Command;
use Illuminate\Support\Facades\DB;

class DomainConfigService
{
    private const TABLE = 'pragmatic_toolkit_domain_config';

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    /**
     * @param array<string, FeatureProviderInterface> $providers
     * @return array<string, array{enabled:bool,order:int}>
     */
    public function getConfiguration(array $providers): array
    {
        $base = $this->buildDefaultConfiguration($providers);

        // Les files es llegeixen una sola vegada per petició: isEnabled() es crida molt sovint.
        $rows = $this->rows ??= $this->tableExists()
            ? (new Query())->select(['domainKey', 'enabled', 'sortOrder'])->from(self::TABLE)->all()
            : [];

        foreach ($rows as $row) {
            $key = (string)($row['domainKey'] ?? '');
            if ($key === '' || !isset($base[$key])) {
                continue;
            }

            $base[$key]['enabled'] = (bool)($row['enabled'] ?? false);
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
            Log::error('Cannot save domain configuration: table missing and auto-create failed.');
            return false;
        }
        DB::beginTransaction();
        try {
            $now = DbHelper::prepareDateForDb(new \DateTime());
            foreach ($providers as $key => $_provider) {
                $row = $config[$key] ?? null;
                if (!is_array($row)) {
                    continue;
                }

                Command::make()->upsert(
                    self::TABLE,
                    [
                        'domainKey' => $key,
                        'enabled' => !empty($row['enabled']) ? 1 : 0,
                        'sortOrder' => max(1, (int)($row['order'] ?? 1)),
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => Str::uuid()->toString(),
                    ],
                    [
                        'enabled' => !empty($row['enabled']) ? 1 : 0,
                        'sortOrder' => max(1, (int)($row['order'] ?? 1)),
                        'dateUpdated' => $now,
                    ]
                )->execute();
            }

            Command::make()
                ->delete(self::TABLE, ['not in', 'domainKey', array_keys($providers)])
                ->execute();

            DB::commit();
            $this->rows = null;
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Could not save domain configuration: ' . $e->getMessage());
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
            $enabled = property_exists($settings, $flag) ? (bool)$settings->{$flag} : false;
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
        return Schema::hasTable(self::TABLE);
    }

    private function ensureTableExists(): bool
    {
        return $this->tableExists();
    }
}

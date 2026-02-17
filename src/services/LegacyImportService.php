<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class LegacyImportService extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function importLegacyPluginData(): array
    {
        $db = Craft::$app->getDb();
        $settings = PragmaticWebToolkit::$plugin->getSettings();

        $legacyHandles = [
            'pragmatic-analytics' => 'analytics',
            'pragmatic-cookies' => 'cookies',
            'pragmatic-mcp' => 'mcp',
            'pragmatic-seo' => 'seo',
            'pragmatic-translations' => 'translations',
            'pragmatic-plus18' => 'plus18',
        ];

        $imported = [];

        foreach ($legacyHandles as $legacyHandle => $domain) {
            $row = (new \craft\db\Query())
                ->from('{{%plugins}}')
                ->where(['handle' => $legacyHandle])
                ->one();

            if (!$row) {
                continue;
            }

            $pluginSettings = $row['settings'] ?? null;
            if (is_string($pluginSettings) && $pluginSettings !== '') {
                $decoded = Json::decodeIfJson($pluginSettings);
                if (is_array($decoded)) {
                    $settings->{$domain} = array_merge($settings->{$domain} ?? [], $decoded);
                    $imported[$domain] = array_keys($decoded);
                }
            }
        }

        PragmaticWebToolkit::$plugin->setSettings($settings);

        return $imported;
    }

    /**
     * @return array<string, bool>
     */
    public function detectLegacyTables(): array
    {
        $schema = Craft::$app->getDb()->getSchema();
        $tables = [
            'analytics_daily_stats' => '{{%pragmaticanalytics_daily_stats}}',
            'analytics_page_daily' => '{{%pragmaticanalytics_page_daily_stats}}',
            'analytics_unique' => '{{%pragmaticanalytics_daily_unique_visitors}}',
            'cookies_categories' => '{{%pragmatic_cookies_categories}}',
            'cookies_cookies' => '{{%pragmatic_cookies_cookies}}',
            'cookies_scans' => '{{%pragmatic_cookies_scans}}',
            'cookies_scan_results' => '{{%pragmatic_cookies_scan_results}}',
            'cookies_consent_logs' => '{{%pragmatic_cookies_consent_logs}}',
            'cookies_site_settings' => '{{%pragmatic_cookies_site_settings}}',
            'cookies_category_site_values' => '{{%pragmatic_cookies_category_site_values}}',
            'translations' => '{{%pragmatic_statictranslations}}',
            'translation_values' => '{{%pragmatic_statictranslation_values}}',
            'translation_groups' => '{{%pragmatic_translation_groups}}',
            'seo_meta_site_settings' => '{{%pragmaticseo_meta_site_settings}}',
        ];

        $result = [];
        foreach ($tables as $key => $table) {
            $result[$key] = (bool)$schema->getTableSchema($table, true);
        }

        return $result;
    }
}

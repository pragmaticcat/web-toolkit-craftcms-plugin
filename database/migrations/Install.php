<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function CraftCms\Cms\t;

/**
 * Crea l’esquema complet del plugin.
 *
 * Representa l’estat final de totes les migracions de la versió 1.x (Craft 5), que ja no
 * s’inclouen: les instal·lacions existents ja tenen aquestes taules.
 */
return new class extends Migration
{
    private const TABLES = [
        'pragmatic_toolkit_seo_sitemap_entrytypes',
        'pragmatic_toolkit_seo_blocks',
        'pragmatic_toolkit_seo_meta_section_settings',
        'pragmatic_toolkit_seo_meta_site_settings',
        'pragmatic_toolkit_seo_asset_ai_instructions',
        'pragmatic_toolkit_seo_content_ai_instructions',
        'pragmatic_toolkit_favicon_site_settings',
        'pragmatic_toolkit_cookies_category_site_values',
        'pragmatic_toolkit_cookies_cookie_site_values',
        'pragmatic_toolkit_cookies_site_settings',
        'pragmatic_toolkit_cookies_consent_logs',
        'pragmatic_toolkit_cookies_cookies',
        'pragmatic_toolkit_cookies_categories',
        'pragmatic_toolkit_analytics_daily_unique_visitors',
        'pragmatic_toolkit_analytics_page_daily_stats',
        'pragmatic_toolkit_analytics_daily_stats',
        'pragmatic_toolkit_sync_transfer_logs',
        'pragmatic_toolkit_translations_values',
        'pragmatic_toolkit_translations_keys',
        'pragmatic_toolkit_translations_groups',
        'pragmatic_toolkit_redirect_404s',
        'pragmatic_toolkit_redirects',
        'pragmatic_toolkit_domain_settings',
        'pragmatic_toolkit_domain_config',
        'pragmatic_toolkit_migration_log',
    ];

    public function up(): void
    {
        $this->createIfMissing('pragmatic_toolkit_migration_log', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('status');
            $table->text('details')->nullable();
            $this->auditColumns($table);
        });

        $this->createDomainTables();
        $this->createCookiesTables();
        $this->createFaviconTables();
        $this->createSeoTables();
        $this->createTranslationsTables();
        $this->createAnalyticsTables();
        $this->createRedirectsTables();
        $this->createSyncTables();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createIfMissing(string $name, Closure $callback): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $callback);
        }
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->dateTime('dateCreated');
        $table->dateTime('dateUpdated');
        $table->char('uid', 36)->nullable();
    }

    private function createDomainTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_domain_config', function (Blueprint $table) {
            $table->id();
            $table->string('domainKey')->unique('pwt_domain_config_domain_key_unique');
            $table->boolean('enabled')->default(true);
            $table->integer('sortOrder')->default(1);
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_domain_settings', function (Blueprint $table) {
            $table->id();
            $table->string('domainKey')->unique('pwt_domain_settings_domain_key_unique');
            $table->text('settingsJson');
            $this->auditColumns($table);
        });
    }

    private function createFaviconTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_favicon_site_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId')->unique('pwt_favicon_site_unique');
            $table->boolean('enabled')->default(true);
            $table->boolean('autoGenerateManifest')->default(true);
            $table->integer('faviconIcoAssetId')->nullable();
            $table->integer('faviconSvgAssetId')->nullable();
            $table->integer('appleTouchIconAssetId')->nullable();
            $table->integer('maskIconAssetId')->nullable();
            $table->string('maskIconColor', 32)->default('#000000');
            $table->integer('manifestAssetId')->nullable();
            $table->string('themeColor', 32)->default('#ffffff');
            $table->string('msTileColor', 32)->default('#ffffff');
            $this->auditColumns($table);
            $table->foreign('siteId', 'pwt_favicon_site_settings_site_fk')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    private function createCookiesTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_cookies_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('handle')->unique();
            $table->text('description')->nullable();
            $table->boolean('isRequired')->default(false);
            $table->integer('sortOrder')->default(0);
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_cookies_cookies', function (Blueprint $table) {
            $table->id();
            $table->integer('categoryId')->nullable()->index();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->string('duration')->nullable();
            $table->boolean('isRegex')->default(false);
            $this->auditColumns($table);
            $table->foreign('categoryId')->references('id')->on('pragmatic_toolkit_cookies_categories')->nullOnDelete();
        });

        $this->createIfMissing('pragmatic_toolkit_cookies_consent_logs', function (Blueprint $table) {
            $table->id();
            $table->string('visitorId')->index();
            $table->text('consent')->nullable();
            $table->string('ipAddress')->nullable();
            $table->text('userAgent')->nullable();
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_cookies_site_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId')->unique();
            $table->string('popupTitle');
            $table->text('popupDescription')->nullable();
            $table->string('acceptAllLabel');
            $table->string('rejectAllLabel');
            $table->string('savePreferencesLabel');
            $table->string('cookiePolicyUrl')->nullable();
            $table->string('popupLayout', 32)->default('bar');
            $table->string('popupPosition', 32)->default('bottom');
            $table->string('primaryColor', 32)->default('#2563eb');
            $table->string('backgroundColor', 32)->default('#ffffff');
            $table->string('textColor', 32)->default('#1f2937');
            $table->string('overlayEnabled', 8)->default('true');
            $table->string('autoShowPopup', 8)->default('true');
            $table->string('consentExpiry', 16)->default('365');
            $table->string('logConsent', 8)->default('true');
            $table->string('showPreferencesButton', 8)->default('true');
            $table->string('preferencesButtonLabel')->default('Cookie Settings');
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_cookies_category_site_values', function (Blueprint $table) {
            $table->id();
            $table->integer('categoryId');
            $table->integer('siteId')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $this->auditColumns($table);
            $table->unique(['categoryId', 'siteId']);
            $table->foreign('categoryId')->references('id')->on('pragmatic_toolkit_cookies_categories')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('siteId')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
        });

        $this->createIfMissing('pragmatic_toolkit_cookies_cookie_site_values', function (Blueprint $table) {
            $table->id();
            $table->integer('cookieId');
            $table->integer('siteId')->index();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->string('duration')->nullable();
            $this->auditColumns($table);
            $table->unique(['cookieId', 'siteId']);
            $table->foreign('cookieId')->references('id')->on('pragmatic_toolkit_cookies_cookies')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('siteId')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
        });

        $now = now();

        if (DB::table('pragmatic_toolkit_cookies_categories')->count() === 0) {
            $rows = [
                ['Necessary', 'necessary', 'Essential cookies required for the website to function properly.', true, 1],
                ['Analytics', 'analytics', 'Cookies used to analyze website traffic and usage patterns.', false, 2],
                ['Marketing', 'marketing', 'Cookies used for advertising and tracking across websites.', false, 3],
                ['Preferences', 'preferences', 'Cookies that remember user preferences and settings.', false, 4],
            ];

            DB::table('pragmatic_toolkit_cookies_categories')->insert(array_map(fn (array $row) => [
                'name' => $row[0],
                'handle' => $row[1],
                'description' => $row[2],
                'isRequired' => $row[3],
                'sortOrder' => $row[4],
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => Str::uuid()->toString(),
            ], $rows));
        }

        if (DB::table('pragmatic_toolkit_cookies_site_settings')->count() === 0) {
            $sites = DB::table('sites')->whereNull('dateDeleted')->get(['id', 'language']);
            $rows = [];

            foreach ($sites as $site) {
                $language = (string) $site->language;
                $rows[] = [
                    'siteId' => (int) $site->id,
                    'popupTitle' => t('defaults.cookies.popup-title', [], 'pragmatic-web-toolkit', $language),
                    'popupDescription' => t('defaults.cookies.popup-description', [], 'pragmatic-web-toolkit', $language),
                    'acceptAllLabel' => t('defaults.cookies.accept-all-label', [], 'pragmatic-web-toolkit', $language),
                    'rejectAllLabel' => t('defaults.cookies.reject-all-label', [], 'pragmatic-web-toolkit', $language),
                    'savePreferencesLabel' => t('defaults.cookies.save-preferences-label', [], 'pragmatic-web-toolkit', $language),
                    'cookiePolicyUrl' => '',
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => Str::uuid()->toString(),
                ];
            }

            if ($rows !== []) {
                DB::table('pragmatic_toolkit_cookies_site_settings')->insert($rows);
            }
        }
    }

    private function createSeoTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_seo_meta_site_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId')->unique('pwt_seo_meta_site_unique');
            $table->string('ogType', 16)->default('auto');
            $table->string('robots', 128)->nullable();
            $table->text('robotsTxt')->nullable();
            $table->string('googleSiteVerification')->nullable();
            $table->string('twitterSite', 64)->nullable();
            $table->string('twitterCreator', 64)->nullable();
            $table->string('siteNameOverride')->nullable();
            $table->boolean('enableHreflang')->default(true);
            $table->integer('xDefaultSiteId')->nullable();
            $table->integer('canonicalSiteId')->nullable();
            $table->string('schemaMode', 16)->default('auto');
            $table->boolean('enableArticleMeta')->default(true);
            $table->boolean('includeImageMeta')->default(true);
            $table->string('titleSiteName')->nullable();
            $table->text('defaultSiteDescription')->nullable();
            $table->integer('defaultSiteImageId')->nullable();
            $table->text('defaultSiteImageDescription')->nullable();
            $table->string('titleSiteNamePosition', 16)->default('after');
            $table->string('titleSeparator', 16)->default('|');
            foreach (['strategyAudience', 'strategyBusinessGoals', 'strategyTone', 'strategyBrandTerms', 'strategyForbiddenTerms', 'strategyPrimaryKeywords', 'strategySecondaryKeywords', 'strategyCtaStyle', 'strategyNotes'] as $column) {
                $table->text($column)->nullable();
            }
            $table->integer('maxImageCandidates')->default(12);
            $table->integer('maxSourceTextChars')->default(6000);
            $table->string('mainEntityType', 120)->nullable();
            $table->boolean('excludeFromHreflang')->default(false);
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_seo_meta_section_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId');
            $table->integer('sectionId');
            $table->string('titleSiteName')->nullable();
            $table->string('titleSiteNamePosition', 16)->nullable();
            $table->string('titleSeparator', 16)->nullable();
            $table->text('defaultSiteDescription')->nullable();
            $table->integer('defaultSiteImageId')->nullable();
            $table->text('defaultSiteImageDescription')->nullable();
            $table->string('mainEntityType', 120)->nullable();
            $table->boolean('enableSectionSeo')->default(false);
            $table->string('defaultSiteImageFieldHandle')->nullable();
            $this->auditColumns($table);
            $table->unique(['siteId', 'sectionId'], 'pwt_seo_meta_section_unique');
        });

        $this->createIfMissing('pragmatic_toolkit_seo_blocks', function (Blueprint $table) {
            $table->id();
            $table->integer('canonicalId');
            $table->integer('siteId');
            $table->integer('fieldId');
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('imageId')->nullable();
            $table->text('imageDescription')->nullable();
            $table->boolean('sitemapEnabled')->nullable();
            $table->boolean('sitemapIncludeImages')->nullable();
            $table->boolean('useSectionDefaults')->default(false);
            $table->string('imageFieldHandle')->nullable();
            $this->auditColumns($table);
            $table->unique(['canonicalId', 'siteId', 'fieldId'], 'pwt_seo_blocks_unique');
        });

        $this->createIfMissing('pragmatic_toolkit_seo_sitemap_entrytypes', function (Blueprint $table) {
            $table->integer('entryTypeId')->primary();
            $table->boolean('enabled')->default(true);
            $table->boolean('includeImages')->default(false);
            $table->foreign('entryTypeId', 'pwt_seo_sitemap_entrytypes_entrytype_fk')->references('id')->on('entrytypes')->cascadeOnDelete()->cascadeOnUpdate();
        });

        $this->createIfMissing('pragmatic_toolkit_seo_asset_ai_instructions', function (Blueprint $table) {
            $table->id();
            $table->integer('assetId');
            $table->integer('siteId');
            $table->text('instructions')->nullable();
            $this->auditColumns($table);
            $table->unique(['assetId', 'siteId'], 'pwt_seo_asset_ai_instructions_unique');
        });

        $this->createIfMissing('pragmatic_toolkit_seo_content_ai_instructions', function (Blueprint $table) {
            $table->id();
            $table->integer('entryId');
            $table->string('fieldHandle');
            $table->integer('siteId');
            $table->text('instructions')->nullable();
            $this->auditColumns($table);
            $table->unique(['entryId', 'fieldHandle', 'siteId'], 'pwt_seo_content_ai_instructions_unique');
        });
    }

    private function createTranslationsTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_translations_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique('pwt_translations_groups_name_unique');
            $table->integer('sortOrder')->default(0);
            $table->boolean('isActive')->default(true);
            $this->auditColumns($table);
        });

        $this->createIfMissing('pragmatic_toolkit_translations_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index('pwt_translations_keys_key_idx');
            $table->string('group')->default('site')->index('pwt_translations_keys_group_idx');
            $table->text('description')->nullable();
            $this->auditColumns($table);
            $table->unique(['group', 'key'], 'pwt_translations_keys_group_key_unique');
        });

        $this->createIfMissing('pragmatic_toolkit_translations_values', function (Blueprint $table) {
            $table->id();
            $table->integer('translationId');
            $table->integer('siteId');
            $table->text('value')->nullable();
            $this->auditColumns($table);
            $table->unique(['translationId', 'siteId'], 'pwt_translations_values_unique');
            $table->foreign('translationId', 'pwt_translations_values_translation_fk')->references('id')->on('pragmatic_toolkit_translations_keys')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('siteId', 'pwt_translations_values_site_fk')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
        });

        if (! DB::table('pragmatic_toolkit_translations_groups')->where('name', 'site')->exists()) {
            DB::table('pragmatic_toolkit_translations_groups')->insert([
                'name' => 'site',
                'sortOrder' => 0,
                'isActive' => true,
                'dateCreated' => now(),
                'dateUpdated' => now(),
                'uid' => Str::uuid()->toString(),
            ]);
        }
    }

    private function createAnalyticsTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_analytics_daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('uniqueVisitors')->default(0);
        });

        $this->createIfMissing('pragmatic_toolkit_analytics_page_daily_stats', function (Blueprint $table) {
            $table->date('date');
            $table->string('path', 191)->index('pwt_analytics_page_daily_stats_path_idx');
            $table->unsignedInteger('visits')->default(0);
            $table->primary(['date', 'path']);
        });

        $this->createIfMissing('pragmatic_toolkit_analytics_daily_unique_visitors', function (Blueprint $table) {
            $table->date('date');
            $table->char('visitorHash', 64);
            $table->primary(['date', 'visitorHash']);
        });
    }

    private function createRedirectsTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_redirects', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId')->nullable();
            $table->string('sourceUri', 512);
            $table->string('matchType', 16)->default('exact');
            $table->text('destination')->nullable();
            $table->integer('destinationElementId')->nullable()->index('pwt_redirect_destination_element');
            $table->smallInteger('statusCode')->default(301);
            $table->boolean('enabled')->default(true);
            $table->boolean('preserveQueryString')->default(true);
            $table->boolean('monitorExpected')->default(false);
            $table->text('notes')->nullable();
            $table->bigInteger('hitCount')->default(0);
            $table->dateTime('lastHitAt')->nullable();
            $this->auditColumns($table);
            $table->index(['matchType', 'sourceUri', 'siteId', 'enabled'], 'pwt_redirect_exact_lookup');
            $table->index(['statusCode', 'matchType', 'enabled', 'hitCount'], 'pwt_redirect_filters');
            $table->foreign('siteId', 'pwt_redirect_site_fk')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('destinationElementId', 'pwt_redirect_element_fk')->references('id')->on('elements')->nullOnDelete()->cascadeOnUpdate();
        });

        $this->createIfMissing('pragmatic_toolkit_redirect_404s', function (Blueprint $table) {
            $table->id();
            $table->integer('siteId')->nullable();
            $table->text('path');
            $table->integer('siteKey')->default(0);
            $table->char('pathHash', 64);
            $table->text('referrer')->nullable();
            $table->text('userAgent')->nullable();
            $table->bigInteger('hitCount')->default(1);
            $table->dateTime('firstSeenAt');
            $table->dateTime('lastSeenAt')->index('pwt_redirect_404_retention');
            $table->boolean('handled')->default(false);
            $this->auditColumns($table);
            $table->unique(['siteKey', 'pathHash'], 'pwt_redirect_404_unique');
            $table->index(['hitCount', 'lastSeenAt'], 'pwt_redirect_404_hits');
            $table->foreign('siteId', 'pwt_redirect_404_site_fk')->references('id')->on('sites')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    private function createSyncTables(): void
    {
        $this->createIfMissing('pragmatic_toolkit_sync_transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 16)->index('pwt_sync_transfer_logs_direction');
            $table->string('status', 16)->index('pwt_sync_transfer_logs_status');
            $table->integer('triggeredByUserId')->nullable();
            $table->integer('jobId')->nullable()->index('pwt_sync_transfer_logs_job');
            $table->string('packageName');
            $table->text('packageSummaryJson')->nullable();
            $table->text('packageManifestJson')->nullable();
            $table->text('warningJson')->nullable();
            $table->text('artifactPath')->nullable();
            $table->string('artifactFilename')->nullable();
            $table->dateTime('artifactExpiresAt')->nullable();
            $table->string('progressLabel')->nullable();
            $table->dateTime('startedAt')->nullable();
            $table->dateTime('finishedAt')->nullable();
            $table->text('errorMessage')->nullable();
            $this->auditColumns($table);
            $table->foreign('triggeredByUserId', 'pwt_sync_transfer_logs_user_fk')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
        });
    }
};

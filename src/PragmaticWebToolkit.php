<?php

namespace pragmatic\webtoolkit;

use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Element\Events\ElementSidebarHtmlResolving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\GarbageCollection\Events\RunningGarbageCollection;
use CraftCms\Cms\Plugin\Events\PluginDisabled;
use CraftCms\Cms\Plugin\Events\PluginEnabled;
use CraftCms\Cms\Plugin\Events\PluginEvent;
use CraftCms\Cms\Plugin\Events\PluginInstalled;
use CraftCms\Cms\Plugin\Events\PluginUninstalled;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\ProjectConfig\Events\ChangesApplied;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Twig;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\Utility\UtilityTypes;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use pragmatic\webtoolkit\console\commands\MonitorTestErrorCommand;
use pragmatic\webtoolkit\console\commands\RedirectsImportCommand;
use pragmatic\webtoolkit\console\commands\RedirectsPruneCommand;
use pragmatic\webtoolkit\console\commands\SyncSetupCommand;
use pragmatic\webtoolkit\console\commands\SyncTransferCommand;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsService;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsSettingsService;
use pragmatic\webtoolkit\domains\cookies\services\CategoriesService;
use pragmatic\webtoolkit\domains\cookies\services\ConsentService as CookiesConsentService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesService as CookiesDataService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesSettingsService;
use pragmatic\webtoolkit\domains\cookies\services\SiteSettingsService as CookiesSiteSettingsService;
use pragmatic\webtoolkit\domains\cookies\twig\CookiesTwigExtension;
use pragmatic\webtoolkit\domains\emailTester\services\EmailTesterService;
use pragmatic\webtoolkit\domains\emailTester\utilities\EmailTesterUtility;
use pragmatic\webtoolkit\domains\externalCode\services\ExternalCodeService;
use pragmatic\webtoolkit\domains\favicon\services\FaviconSettingsService;
use pragmatic\webtoolkit\domains\favicon\services\FaviconTagService;
use pragmatic\webtoolkit\domains\languageRedirect\services\LanguageRedirectService;
use pragmatic\webtoolkit\domains\languageRedirect\services\LanguageRedirectSettingsService;
use pragmatic\webtoolkit\domains\languageRedirect\twig\LanguageRedirectTwigExtension;
use pragmatic\webtoolkit\domains\plus18\services\Plus18SettingsService;
use pragmatic\webtoolkit\domains\redirects\services\RedirectService;
use pragmatic\webtoolkit\domains\redirects\services\RedirectSettingsService;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\services\AssetAiInstructionsService;
use pragmatic\webtoolkit\domains\seo\services\ContentAiInstructionsService;
use pragmatic\webtoolkit\domains\seo\services\MetaSettingsService as SeoMetaSettingsService;
use pragmatic\webtoolkit\domains\seo\services\SeoAiService;
use pragmatic\webtoolkit\domains\seo\services\SeoPromptsService;
use pragmatic\webtoolkit\domains\seo\services\SitemapService as SeoSitemapService;
use pragmatic\webtoolkit\domains\seo\variables\PragmaticSeoVariable;
use pragmatic\webtoolkit\domains\translations\services\TranslationsService;
use pragmatic\webtoolkit\domains\translations\services\TranslationsSettingsService;
use pragmatic\webtoolkit\domains\translations\twig\PragmaticTranslationsTwigExtension;
use pragmatic\webtoolkit\domains\translations\variables\PragmaticTranslationsVariable;
use pragmatic\webtoolkit\http\middleware\CpMaintenanceMode;
use pragmatic\webtoolkit\http\middleware\LanguageRedirect;
use pragmatic\webtoolkit\http\RedirectExceptionRenderer;
use pragmatic\webtoolkit\models\Settings;
use pragmatic\webtoolkit\services\DomainConfigService;
use pragmatic\webtoolkit\services\DomainManager;
use pragmatic\webtoolkit\services\DomainSettingsStoreService;
use pragmatic\webtoolkit\services\ExtensionManager;
use pragmatic\webtoolkit\services\MonitorService;
use pragmatic\webtoolkit\services\NavService;
use pragmatic\webtoolkit\twig\FormsHelper;
use pragmatic\webtoolkit\variables\PragmaticToolkitVariable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

use function CraftCms\Cms\template;

/**
 * Pragmatic Web Toolkit.
 *
 * Els serveis del plugin són singletons del contenidor de Laravel i es poden obtenir
 * com a propietats de la instància, igual que a Craft 5: `PragmaticWebToolkit::$plugin->seoMetaSettings`.
 *
 * @property-read DomainManager $domains
 * @property-read DomainConfigService $domainConfig
 * @property-read DomainSettingsStoreService $domainSettingsStore
 * @property-read ExtensionManager $extensions
 * @property-read AnalyticsService $analytics
 * @property-read AnalyticsSettingsService $analyticsSettings
 * @property-read CategoriesService $cookiesCategories
 * @property-read CookiesConsentService $cookiesConsent
 * @property-read CookiesDataService $cookiesData
 * @property-read CookiesSettingsService $cookiesSettings
 * @property-read CookiesSiteSettingsService $cookiesSiteSettings
 * @property-read EmailTesterService $emailTester
 * @property-read ExternalCodeService $externalCode
 * @property-read FaviconSettingsService $faviconSettings
 * @property-read FaviconTagService $faviconTags
 * @property-read LanguageRedirectSettingsService $languageRedirectSettings
 * @property-read LanguageRedirectService $languageRedirect
 * @property-read Plus18SettingsService $plus18Settings
 * @property-read RedirectService $redirects
 * @property-read RedirectSettingsService $redirectSettings
 * @property-read AssetAiInstructionsService $seoAssetAiInstructions
 * @property-read ContentAiInstructionsService $seoContentAiInstructions
 * @property-read SeoAiService $seoAi
 * @property-read SeoPromptsService $seoPrompts
 * @property-read SeoMetaSettingsService $seoMetaSettings
 * @property-read SeoSitemapService $seoSitemap
 * @property-read TranslationsService $translations
 * @property-read TranslationsSettingsService $translationsSettings
 * @property-read NavService $nav
 * @property-read MonitorService $monitor
 *
 * @method Settings getSettings()
 */
class PragmaticWebToolkit extends Plugin
{
    public static ?PragmaticWebToolkit $plugin = null;

    public bool $hasCpSection = true;

    public string $schemaVersion = '2.0.0';

    protected array $fieldTypes = [
        SeoField::class,
    ];

    protected array $commands = [
        MonitorTestErrorCommand::class,
        RedirectsPruneCommand::class,
        RedirectsImportCommand::class,
        SyncSetupCommand::class,
    ];

    /**
     * @var array<string, class-string>
     */
    private const SERVICES = [
        'domains' => DomainManager::class,
        'domainConfig' => DomainConfigService::class,
        'domainSettingsStore' => DomainSettingsStoreService::class,
        'extensions' => ExtensionManager::class,
        'analytics' => AnalyticsService::class,
        'analyticsSettings' => AnalyticsSettingsService::class,
        'externalCode' => ExternalCodeService::class,
        'cookiesCategories' => CategoriesService::class,
        'cookiesConsent' => CookiesConsentService::class,
        'cookiesData' => CookiesDataService::class,
        'cookiesSettings' => CookiesSettingsService::class,
        'cookiesSiteSettings' => CookiesSiteSettingsService::class,
        'emailTester' => EmailTesterService::class,
        'faviconSettings' => FaviconSettingsService::class,
        'faviconTags' => FaviconTagService::class,
        'languageRedirectSettings' => LanguageRedirectSettingsService::class,
        'languageRedirect' => LanguageRedirectService::class,
        'plus18Settings' => Plus18SettingsService::class,
        'redirects' => RedirectService::class,
        'redirectSettings' => RedirectSettingsService::class,
        'seoAssetAiInstructions' => AssetAiInstructionsService::class,
        'seoContentAiInstructions' => ContentAiInstructionsService::class,
        'seoAi' => SeoAiService::class,
        'seoPrompts' => SeoPromptsService::class,
        'seoMetaSettings' => SeoMetaSettingsService::class,
        'seoSitemap' => SeoSitemapService::class,
        'translations' => TranslationsService::class,
        'translationsSettings' => TranslationsSettingsService::class,
        'nav' => NavService::class,
        'monitor' => MonitorService::class,
    ];

    private bool $seoFieldsTranslationEnsured = false;

    public function register(): void
    {
        self::$plugin = $this;

        if ($this->app->runningInConsole()) {
            $this->commands(SyncTransferCommand::commands());
        }

        foreach (self::SERVICES as $class) {
            $this->app->singleton($class);
        }
    }

    public function __get(string $name): mixed
    {
        if (isset(self::SERVICES[$name])) {
            return $this->app->make(self::SERVICES[$name]);
        }

        throw new \LogicException(sprintf('Unknown property %s::$%s', static::class, $name));
    }

    public function __isset(string $name): bool
    {
        return isset(self::SERVICES[$name]);
    }

    public function boot(UtilityTypes $utilityTypes): void
    {
        self::$plugin = $this;

        $this->domains->bootstrapCoreDomains();

        $this->registerVariables();
        $this->registerTwigExtensions();
        $this->registerSeoEntrySidebarPreview();
        $this->registerCpSaveShortcut();
        $this->registerMiddleware();
        $this->registerRedirectHandling();
        $this->registerMonitorEvents();

        if ($this->domains->isEnabled('emailTester')) {
            $utilityTypes->register(EmailTesterUtility::class);
        }

        $this->app->booted(function () {
            $this->extensions->discoverInstalledExtensions();
            $this->ensureSeoFieldsAreTranslatable();
        });
    }

    protected static function createSettings(): ?PluginSettings
    {
        return new Settings();
    }

    public function getCpNavItem(): array|null
    {
        return $this->nav->navItem();
    }

    protected function getPermissions(): array
    {
        $permissions = [];
        foreach ($this->domains->permissionMap() as $key => $permission) {
            $permissions[] = new Permission(
                key: $key,
                label: (string)($permission['label'] ?? $key),
            );
        }

        return $permissions;
    }

    private function registerVariables(): void
    {
        CraftVariable::macro('pragmaticToolkit', fn () => new PragmaticToolkitVariable());
        CraftVariable::macro('pragmaticTranslations', fn () => new PragmaticTranslationsVariable());
        CraftVariable::macro('pragmaticSEO', fn () => new PragmaticSeoVariable());
        CraftVariable::macro('pragmaticSeo', fn () => new PragmaticSeoVariable());

        Event::listen(function (TemplateGlobalsResolving $event): void {
            $seoVariable = new PragmaticSeoVariable();
            $event->globals['pragmaticSEO'] = $seoVariable;
            $event->globals['pragmaticSeo'] = $seoVariable;
        });
    }

    private function registerTwigExtensions(): void
    {
        Twig::registerExtension(new CookiesTwigExtension());
        Twig::registerExtension(new LanguageRedirectTwigExtension());
        Twig::registerExtension(new FormsHelper());

        if (!$this->app->runningInConsole() && !request()->isCpRequest()) {
            Twig::registerExtension(new PragmaticTranslationsTwigExtension());
        }
    }

    private function registerCpSaveShortcut(): void
    {
        if ($this->app->runningInConsole() || !request()->isCpRequest()) {
            return;
        }

        HtmlStack::js(<<<JS
(function() {
  if (window.__pwtSaveShortcutAttached) return;
  window.__pwtSaveShortcutAttached = true;

  function findSaveTarget() {
    var scope = document.getElementById('content') || document.body;
    var button = scope.querySelector('form button.btn.submit[type="submit"], form input.btn.submit[type="submit"]');
    if (button) return { type: 'button', el: button };
    var form = scope.querySelector('form');
    if (form) return { type: 'form', el: form };
    return null;
  }

  document.addEventListener('keydown', function(event) {
    if (!(event.metaKey || event.ctrlKey)) return;
    if (!event.key || event.key.toLowerCase() !== 's') return;
    if (!/\/pragmatic-toolkit(\/|$)/.test(location.pathname)) return;
    var target = findSaveTarget();
    if (!target) return;
    event.preventDefault();
    if (target.type === 'button') {
      target.el.click();
    } else if (typeof target.el.requestSubmit === 'function') {
      target.el.requestSubmit();
    } else {
      target.el.submit();
    }
  });
})();
JS, key: 'pwt-save-shortcut');
    }

    private function registerMiddleware(): void
    {
        // Peticions POST públiques sense sessió ni token CSRF: les signades de Pragmatic Web
        // Monitor i el desament del consentiment de cookies (pot venir d’una pàgina en memòria cau).
        PreventRequestForgery::except([
            'pragmatic-web-toolkit/monitor/*',
            '*/pragmatic-web-toolkit/monitor/*',
            'pragmatic-toolkit/cookies/consent/save',
            '*/pragmatic-toolkit/cookies/consent/save',
        ]);

        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('craft.web', LanguageRedirect::class);

        if (filter_var(env('PWT_CP_MAINTENANCE_MODE'), FILTER_VALIDATE_BOOLEAN)) {
            $router->pushMiddlewareToGroup('craft.cp', CpMaintenanceMode::class);
        }
    }

    private function registerSeoEntrySidebarPreview(): void
    {
        Event::listen(function (ElementSidebarHtmlResolving $event): void {
            $entry = $event->element;
            if (!$entry instanceof Entry) {
                return;
            }

            $seoFieldHandle = null;
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof SeoField) {
                    $seoFieldHandle = (string)$field->handle;
                    break;
                }
            }

            if ($seoFieldHandle === null || $seoFieldHandle === '') {
                return;
            }

            $preview = $this->seoPreviewDataForEntry($entry, $seoFieldHandle);
            $html = template('pragmatic-web-toolkit/seo/_google-search-preview', [
                'previewId' => 'pwt-entry-sidebar-seo-preview-' . ($entry->id ?: 'new') . '-' . $seoFieldHandle,
                'title' => $preview['title'] ?? '',
                'url' => $preview['url'] ?? '',
                'description' => $preview['description'] ?? '',
                'entryTitleSelector' => '#title, [name="title"]',
                'titleInputSelector' => '[name$="[' . $seoFieldHandle . '][title]"], [id$="-' . $seoFieldHandle . '-title"]',
                'descriptionInputSelector' => '[name$="[' . $seoFieldHandle . '][description]"], [id$="-' . $seoFieldHandle . '-description"]',
                'containerHeading' => 'SEO Preview',
                'entryTitleFallback' => (string)($entry->title ?? ''),
            ], TemplateMode::Cp);

            $event->html = $this->insertSidebarHtmlInPenultimatePosition((string)$event->html, (string)$html);
        });
    }

    private function insertSidebarHtmlInPenultimatePosition(string $existingHtml, string $injectedHtml): string
    {
        $existingHtml = trim($existingHtml);
        if ($existingHtml === '') {
            return $injectedHtml;
        }

        $markers = ['<fieldset', '<div class="field"', '<hr'];
        $positions = [];
        foreach ($markers as $marker) {
            $offset = 0;
            while (($pos = strpos($existingHtml, $marker, $offset)) !== false) {
                $positions[] = $pos;
                $offset = $pos + 1;
            }
        }

        $positions = array_values(array_unique($positions));
        sort($positions);

        if (count($positions) < 2) {
            return $existingHtml . $injectedHtml;
        }

        $insertPos = $positions[count($positions) - 1];
        return substr($existingHtml, 0, $insertPos) . $injectedHtml . substr($existingHtml, $insertPos);
    }

    private function seoPreviewDataForEntry(Entry $entry, string $fieldHandle): array
    {
        $seo = new PragmaticSeoVariable();
        $preview = $seo->getSearchPreviewData($entry, $fieldHandle);
        $entryFallbacks = $seo->getSearchPreviewFallbackData($entry, $fieldHandle);

        return [
            ...$preview,
            'entryResolvedTitle' => trim((string)($preview['title'] ?? '')),
            'entryResolvedDescription' => trim((string)($preview['description'] ?? '')),
            'entryFallbackTitle' => trim((string)($entryFallbacks['title'] ?? '')),
            'entryFallbackDescription' => trim((string)($entryFallbacks['description'] ?? '')),
        ];
    }

    /**
     * Redireccions per als 404, seguiment d’URI de les entrades i neteja periòdica del registre de 404.
     */
    private function registerRedirectHandling(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (NotFoundHttpException $exception, Request $request) {
                return $this->app->make(RedirectExceptionRenderer::class)->render($request);
            });
        }

        Event::listen(function (ElementSaving $event): void {
            if ($this->domains->isEnabled('redirects')) {
                $this->redirects->captureEntryUriBeforeSave($event);
            }
        });

        Event::listen(function (ElementSaved $event): void {
            if ($this->domains->isEnabled('redirects')) {
                $this->redirects->handleEntrySave($event);
            }
            if ($event->element instanceof Entry) {
                $this->monitor->reportEntrySave($event->element, $event->isNew);
            }
        });

        Event::listen(function (RunningGarbageCollection $event): void {
            if ($this->domains->isEnabled('redirects')) {
                $this->redirects->prune404s();
            }
        });
    }

    /**
     * Avisa Pragmatic Web Monitor dels desplegaments perquè revisi el lloc de seguida.
     */
    private function registerMonitorEvents(): void
    {
        Event::listen(function (ChangesApplied $event): void {
            $this->monitor->queue('project_config.applied', [], [
                'project_config' => $this->monitor->projectConfigFingerprint(),
            ]);
        });

        $pluginEvents = [
            PluginInstalled::class => 'installed',
            PluginUninstalled::class => 'uninstalled',
            PluginEnabled::class => 'enabled',
            PluginDisabled::class => 'disabled',
        ];
        foreach ($pluginEvents as $eventClass => $action) {
            Event::listen($eventClass, function (PluginEvent $event) use ($action): void {
                $this->monitor->queue('plugin.updated', [
                    'plugin' => $event->plugin->handle,
                    'version' => $event->plugin->version,
                    'action' => $action,
                ]);
            });
        }

        // Les excepcions no gestionades i les feines de la cua que fallen definitivament s’informen de seguida.
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $exception): void {
                $this->monitor->reportException($exception);
            });
        }

        Event::listen(function (JobFailed $event): void {
            $this->monitor->reportFailedJob($event);
        });

        // Els desplegaments executen `craft up` des de la consola, així que els canvis de versió es comproven després de cada ordre.
        if ($this->app->runningInConsole()) {
            Event::listen(function (CommandFinished $event): void {
                try {
                    $this->monitor->checkForVersionChanges();
                } catch (Throwable $e) {
                    Log::warning('Could not check for version changes: ' . $e->getMessage());
                }
            });
        }
    }

    private function ensureSeoFieldsAreTranslatable(): void
    {
        if ($this->seoFieldsTranslationEnsured) {
            return;
        }
        $this->seoFieldsTranslationEnsured = true;

        try {
            foreach (Fields::getAllFields() as $field) {
                if (!$field instanceof SeoField || $field->translationMethod === TranslationMethod::Site->value) {
                    continue;
                }

                $field->translationMethod = TranslationMethod::Site;
                Fields::saveField($field, false);
            }
        } catch (Throwable $e) {
            Log::warning('Could not ensure SEO fields are translatable: ' . $e->getMessage());
        }
    }
}

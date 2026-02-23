<?php

namespace pragmatic\webtoolkit;

use Craft;
use craft\base\Field as BaseField;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineFieldActionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\PlainText;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsService;
use pragmatic\webtoolkit\domains\analytics\services\AnalyticsSettingsService;
use pragmatic\webtoolkit\domains\cookies\services\CategoriesService;
use pragmatic\webtoolkit\domains\cookies\services\ConsentService as CookiesConsentService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesService as CookiesDataService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesSettingsService;
use pragmatic\webtoolkit\domains\cookies\services\SiteSettingsService as CookiesSiteSettingsService;
use pragmatic\webtoolkit\domains\cookies\twig\CookiesTwigExtension;
use pragmatic\webtoolkit\domains\favicon\services\FaviconSettingsService;
use pragmatic\webtoolkit\domains\favicon\services\FaviconTagService;
use pragmatic\webtoolkit\domains\mcp\services\McpSettingsService;
use pragmatic\webtoolkit\domains\mcp\services\QueryService as McpQueryService;
use pragmatic\webtoolkit\domains\mcp\services\ResourceService as McpResourceService;
use pragmatic\webtoolkit\domains\mcp\services\ToolService as McpToolService;
use pragmatic\webtoolkit\domains\plus18\services\Plus18SettingsService;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\services\MetaSettingsService as SeoMetaSettingsService;
use pragmatic\webtoolkit\domains\seo\variables\PragmaticSeoVariable;
use pragmatic\webtoolkit\domains\translations\services\GoogleTranslateService as TranslationsGoogleTranslateService;
use pragmatic\webtoolkit\domains\translations\services\TranslationsService;
use pragmatic\webtoolkit\domains\translations\services\TranslationsSettingsService;
use pragmatic\webtoolkit\domains\translations\assets\AutotranslateFieldMenuAsset;
use pragmatic\webtoolkit\domains\translations\twig\PragmaticTranslationsTwigExtension;
use pragmatic\webtoolkit\domains\translations\variables\PragmaticTranslationsVariable;
use pragmatic\webtoolkit\models\Settings;
use pragmatic\webtoolkit\services\DomainManager;
use pragmatic\webtoolkit\services\ExtensionManager;
use pragmatic\webtoolkit\services\NavService;
use pragmatic\webtoolkit\services\RouteService;
use pragmatic\webtoolkit\variables\PragmaticToolkitVariable;
use yii\base\Event;

/**
 * @property DomainManager $domains
 * @property ExtensionManager $extensions
 * @property AnalyticsService $analytics
 * @property AnalyticsSettingsService $analyticsSettings
 * @property CategoriesService $cookiesCategories
 * @property CookiesConsentService $cookiesConsent
 * @property CookiesDataService $cookiesData
 * @property CookiesSettingsService $cookiesSettings
 * @property CookiesSiteSettingsService $cookiesSiteSettings
 * @property FaviconSettingsService $faviconSettings
 * @property FaviconTagService $faviconTags
 * @property McpSettingsService $mcpSettings
 * @property McpResourceService $mcpResource
 * @property McpToolService $mcpTool
 * @property McpQueryService $mcpQuery
 * @property Plus18SettingsService $plus18Settings
 * @property SeoMetaSettingsService $seoMetaSettings
 * @property TranslationsService $translations
 * @property TranslationsGoogleTranslateService $googleTranslate
 * @property TranslationsSettingsService $translationsSettings
 * @property NavService $nav
 * @property RouteService $routes
 */
class PragmaticWebToolkit extends Plugin
{
    public const EDITION_FREE = 'free';
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public static PragmaticWebToolkit $plugin;

    public bool $hasCpSection = true;
    public string $templateRoot = 'src/templates';
    public string $schemaVersion = '1.0.0';
    private bool $seoFieldsTranslationEnsured = false;

    public static function editions(): array
    {
        return [self::EDITION_FREE, self::EDITION_LITE, self::EDITION_PRO];
    }

    public function atLeast(string $edition): bool
    {
        $order = [self::EDITION_FREE, self::EDITION_LITE, self::EDITION_PRO];
        return array_search($this->edition, $order) >= array_search($edition, $order);
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->applyEditionOverrideFromEnv();

        Craft::$app->i18n->translations['pragmatic-web-toolkit'] = [
            'class' => \yii\i18n\PhpMessageSource::class,
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
        ];
        Craft::$app->i18n->translations['pragmatic-analytics'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];
        Craft::$app->i18n->translations['pragmatic-favicon'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];
        Craft::$app->i18n->translations['pragmatic-mcp'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];
        Craft::$app->i18n->translations['pragmatic-plus18'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];
        Craft::$app->i18n->translations['pragmatic-seo'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];
        Craft::$app->i18n->translations['pragmatic-translations'] = Craft::$app->i18n->translations['pragmatic-web-toolkit'];

        $this->setComponents([
            'domains' => DomainManager::class,
            'extensions' => ExtensionManager::class,
            'analytics' => AnalyticsService::class,
            'analyticsSettings' => AnalyticsSettingsService::class,
            'cookiesCategories' => CategoriesService::class,
            'cookiesConsent' => CookiesConsentService::class,
            'cookiesData' => CookiesDataService::class,
            'cookiesSettings' => CookiesSettingsService::class,
            'cookiesSiteSettings' => CookiesSiteSettingsService::class,
            'faviconSettings' => FaviconSettingsService::class,
            'faviconTags' => FaviconTagService::class,
            'mcpSettings' => McpSettingsService::class,
            'mcpResource' => McpResourceService::class,
            'mcpTool' => McpToolService::class,
            'mcpQuery' => McpQueryService::class,
            'plus18Settings' => Plus18SettingsService::class,
            'seoMetaSettings' => SeoMetaSettingsService::class,
            'translations' => TranslationsService::class,
            'googleTranslate' => TranslationsGoogleTranslateService::class,
            'translationsSettings' => TranslationsSettingsService::class,
            'nav' => NavService::class,
            'routes' => RouteService::class,
        ]);

        $this->domains->bootstrapCoreDomains();
        $this->extensions->discoverInstalledExtensions();

        $this->registerRoutes();
        $this->registerNavigation();
        $this->registerVariables();
        $this->registerPermissions();
        $this->registerFrontendHooks();
        $this->registerSeoFieldType();
        $this->registerSeoVariables();
        Craft::$app->getView()->registerTwigExtension(new CookiesTwigExtension());
        Craft::$app->getView()->registerTwigExtension(new PragmaticTranslationsTwigExtension());
        $this->registerTranslationsFieldActionMenu();

        Craft::$app->onInit(function () {
            $this->ensureSeoFieldsAreTranslatable();
        });
    }

    private function applyEditionOverrideFromEnv(): void
    {
        $override = strtolower(trim((string)App::env('PWT_EDITION_OVERRIDE')));
        if ($override === '') {
            return;
        }

        if (!in_array($override, self::editions(), true)) {
            Craft::warning(
                sprintf(
                    'Ignoring invalid PWT_EDITION_OVERRIDE value "%s". Allowed values: %s.',
                    $override,
                    implode(', ', self::editions())
                ),
                __METHOD__
            );
            return;
        }

        $this->edition = $override;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getCpNavItem(): ?array
    {
        return null;
    }

    private function registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $this->routes->registerCpRoutes($event);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $this->routes->registerSiteRoutes($event);
            }
        );
    }

    private function registerNavigation(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                $this->nav->registerToolkitNav($event);
            }
        );
    }

    private function registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pragmaticToolkit', PragmaticToolkitVariable::class);
                $variable->set('pragmaticTranslations', PragmaticTranslationsVariable::class);
            }
        );
    }

    private function registerSeoVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pragmaticSEO', PragmaticSeoVariable::class);
                $variable->set('pragmaticSeo', PragmaticSeoVariable::class);
            }
        );

        $twig = Craft::$app->getView()->getTwig();
        $seoVariable = new PragmaticSeoVariable();
        $twig->addGlobal('pragmaticSEO', $seoVariable);
        $twig->addGlobal('pragmaticSeo', $seoVariable);
    }

    private function registerSeoFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SeoField::class;
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function ($event) {
                $event->permissions[] = [
                    'heading' => 'Pragmatic Web Toolkit',
                    'permissions' => $this->domains->permissionMap(),
                ];
            }
        );
    }

    private function registerFrontendHooks(): void
    {
        if (!Craft::$app->getRequest()->getIsSiteRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event) {
                $event->output = $this->domains->injectFrontendHtml($event->output);
            }
        );
    }

    private function registerTranslationsFieldActionMenu(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $view = Craft::$app->getView();
        $settings = $this->translationsSettings->get();
        $apiKey = $this->resolveGoogleApiKey((string)$settings->googleApiKeyEnv);
        $currentUser = Craft::$app->getUser()->getIdentity();
        $canManageTranslations = (bool)($currentUser && $currentUser->can('pragmatic-toolkit:translations-manage'));
        $autotranslateEnabled = (bool)$settings->enableAutotranslate && $this->atLeast(self::EDITION_PRO);
        $googleConfigured = $autotranslateEnabled && $apiKey !== '';

        $sites = Craft::$app->getSites()->getAllSites();
        $siteData = array_map(static function($site) {
            return [
                'id' => $site->id,
                'name' => $site->name,
                'handle' => $site->handle,
                'language' => $site->language,
            ];
        }, $sites);

        $view->registerJs('window.PragmaticWebToolkitTranslations = ' . json_encode([
            'sites' => $siteData,
            'currentSiteId' => Craft::$app->getSites()->getCurrentSite()->id,
            'autotranslateUrl' => UrlHelper::actionUrl('pragmatic-web-toolkit/translations/autotranslate'),
            'autotranslateSourcesUrl' => UrlHelper::actionUrl('pragmatic-web-toolkit/translations/autotranslate-sources'),
            'googleTranslateConfigured' => $googleConfigured,
            'canManageTranslations' => $canManageTranslations,
            'autotranslateEnabled' => $autotranslateEnabled,
            'settingsUrl' => UrlHelper::cpUrl('pragmatic-toolkit/translations/options'),
        ]) . ';', View::POS_HEAD);
        $view->registerAssetBundle(AutotranslateFieldMenuAsset::class);

        if (!class_exists(DefineFieldActionsEvent::class)) {
            return;
        }

        Event::on(
            CustomField::class,
            BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function (DefineFieldActionsEvent $event) {
                if ($event->static) {
                    return;
                }

                $element = $event->element;
                if (!$element || !$element->id) {
                    return;
                }

                /** @var CustomField $sender */
                $sender = $event->sender;
                try {
                    $field = $sender->getField();
                } catch (\Throwable) {
                    return;
                }

                $isEligibleField = ($field instanceof PlainText) || (get_class($field) === 'craft\\ckeditor\\Field');
                if (!$isEligibleField) {
                    return;
                }

                if ($field->translationMethod === \craft\base\Field::TRANSLATION_METHOD_NONE) {
                    return;
                }

                if (count(Craft::$app->getSites()->getAllSites()) < 2) {
                    return;
                }

                $view = Craft::$app->getView();
                $itemId = sprintf('action-pwt-autotranslate-%s', mt_rand());
                $containerId = $view->namespaceInputId((string)$field->handle) . '-field';

                $view->registerJsWithVars(
                    fn($btnId, $cId, $eId, $fHandle) => <<<JS
$('#' + $btnId).on('activate', function() {
  var container = document.getElementById($cId);
  if (window.PragmaticWebToolkitTranslations && window.PragmaticWebToolkitTranslations.openModal) {
    window.PragmaticWebToolkitTranslations.openModal(container, $eId, $fHandle);
  }
});
JS,
                    [
                        $view->namespaceInputId($itemId),
                        $containerId,
                        $element->id,
                        (string)$field->handle,
                    ]
                );

                $event->items[] = [
                    'id' => $itemId,
                    'icon' => 'language',
                    'label' => Craft::t('pragmatic-web-toolkit', 'Translate from site…'),
                ];
            }
        );

        Event::on(
            TitleField::class,
            BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function (DefineFieldActionsEvent $event) {
                if ($event->static) {
                    return;
                }

                $element = $event->element;
                if (!$element || !$element->id) {
                    return;
                }

                if (count(Craft::$app->getSites()->getAllSites()) < 2) {
                    return;
                }

                $view = Craft::$app->getView();
                $itemId = sprintf('action-pwt-autotranslate-%s', mt_rand());
                $containerId = $view->namespaceInputId('title') . '-field';

                $view->registerJsWithVars(
                    fn($btnId, $cId, $eId, $fHandle) => <<<JS
$('#' + $btnId).on('activate', function() {
  var container = document.getElementById($cId);
  if (window.PragmaticWebToolkitTranslations && window.PragmaticWebToolkitTranslations.openModal) {
    window.PragmaticWebToolkitTranslations.openModal(container, $eId, $fHandle);
  }
});
JS,
                    [
                        $view->namespaceInputId($itemId),
                        $containerId,
                        $element->id,
                        'title',
                    ]
                );

                $event->items[] = [
                    'id' => $itemId,
                    'icon' => 'language',
                    'label' => Craft::t('pragmatic-web-toolkit', 'Translate from site…'),
                ];
            }
        );
    }

    private function resolveGoogleApiKey(string $envReference): string
    {
        $reference = trim($envReference);
        if ($reference === '') {
            return '';
        }

        $parsed = App::parseEnv($reference);
        if (is_string($parsed) && $parsed !== '' && $parsed !== $reference) {
            return trim($parsed);
        }

        $normalized = ltrim($reference, '$');
        $resolved = App::env($normalized);
        if (!is_string($resolved)) {
            return '';
        }

        return trim($resolved);
    }

    private function ensureSeoFieldsAreTranslatable(): void
    {
        if ($this->seoFieldsTranslationEnsured) {
            return;
        }
        $this->seoFieldsTranslationEnsured = true;

        $fieldsService = Craft::$app->getFields();
        foreach ($fieldsService->getAllFields() as $field) {
            if (!$field instanceof SeoField) {
                continue;
            }

            if ($field->translationMethod === SeoField::TRANSLATION_METHOD_SITE) {
                continue;
            }

            $field->translationMethod = SeoField::TRANSLATION_METHOD_SITE;
            $fieldsService->saveField($field, false);
        }
    }
}

<?php

namespace pragmatic\webtoolkit;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use pragmatic\webtoolkit\domains\cookies\services\CategoriesService;
use pragmatic\webtoolkit\domains\cookies\services\ConsentService as CookiesConsentService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesService as CookiesDataService;
use pragmatic\webtoolkit\domains\cookies\services\CookiesSettingsService;
use pragmatic\webtoolkit\domains\cookies\services\SiteSettingsService as CookiesSiteSettingsService;
use pragmatic\webtoolkit\domains\cookies\twig\CookiesTwigExtension;
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
 * @property CategoriesService $cookiesCategories
 * @property CookiesConsentService $cookiesConsent
 * @property CookiesDataService $cookiesData
 * @property CookiesSettingsService $cookiesSettings
 * @property CookiesSiteSettingsService $cookiesSiteSettings
 * @property NavService $nav
 * @property RouteService $routes
 */
class PragmaticWebToolkit extends Plugin
{
    public static PragmaticWebToolkit $plugin;

    public bool $hasCpSection = true;
    public string $templateRoot = 'src/templates';
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::$app->i18n->translations['pragmatic-web-toolkit'] = [
            'class' => \yii\i18n\PhpMessageSource::class,
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
        ];

        $this->setComponents([
            'domains' => DomainManager::class,
            'extensions' => ExtensionManager::class,
            'cookiesCategories' => CategoriesService::class,
            'cookiesConsent' => CookiesConsentService::class,
            'cookiesData' => CookiesDataService::class,
            'cookiesSettings' => CookiesSettingsService::class,
            'cookiesSiteSettings' => CookiesSiteSettingsService::class,
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
        Craft::$app->getView()->registerTwigExtension(new CookiesTwigExtension());
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
}

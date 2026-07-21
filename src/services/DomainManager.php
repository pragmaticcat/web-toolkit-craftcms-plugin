<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\base\Component;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\analytics\AnalyticsFeature;
use pragmatic\webtoolkit\domains\cookies\CookiesFeature;
use pragmatic\webtoolkit\domains\favicon\FaviconFeature;
use pragmatic\webtoolkit\domains\languageRedirect\LanguageRedirectFeature;
use pragmatic\webtoolkit\domains\mcp\McpFeature;
use pragmatic\webtoolkit\domains\plus18\Plus18Feature;
use pragmatic\webtoolkit\domains\seo\SeoFeature;
use pragmatic\webtoolkit\domains\sync\SyncFeature;
use pragmatic\webtoolkit\domains\translations\TranslationsFeature;
use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class DomainManager extends Component
{
    /**
     * @var array<string, FeatureProviderInterface>
     */
    private array $providers = [];

    public function bootstrapCoreDomains(): void
    {
        $this->register(new AnalyticsFeature());
        $this->register(new CookiesFeature());
        $this->register(new FaviconFeature());
        $this->register(new LanguageRedirectFeature());
        $this->register(new McpFeature());
        $this->register(new SeoFeature());
        $this->register(new SyncFeature());
        $this->register(new TranslationsFeature());
        $this->register(new Plus18Feature());
    }

    public function register(FeatureProviderInterface $provider): void
    {
        $this->providers[$provider::domainKey()] = $provider;
    }

    /**
     * @return array<string, FeatureProviderInterface>
     */
    public function all(): array
    {
        return $this->orderedProviders($this->providers);
    }

    /**
     * @return array<string, FeatureProviderInterface>
     */
    public function enabled(): array
    {
        $providers = $this->all();
        $config = PragmaticWebToolkit::$plugin->domainConfig->getConfiguration($providers);

        return array_filter(
            $providers,
            static function (FeatureProviderInterface $provider) use ($config): bool {
                $key = $provider::domainKey();
                return (bool)($config[$key]['enabled'] ?? false);
            }
        );
    }

    public function isEnabled(string $domainKey): bool
    {
        $providers = $this->all();
        if (!isset($providers[$domainKey])) {
            return false;
        }

        $config = PragmaticWebToolkit::$plugin->domainConfig->getConfiguration($providers);

        return (bool)($config[$domainKey]['enabled'] ?? false);
    }

    /**
     * @return array<string, array{label:string}>
     */
    public function permissionMap(): array
    {
        $permissions = [];
        foreach ($this->enabled() as $provider) {
            $permissions = array_merge($permissions, $provider->permissions());
        }

        return $permissions;
    }

    public function injectFrontendHtml(string $html): string
    {
        $result = $html;
        foreach ($this->enabled() as $provider) {
            try {
                $result = $provider->injectFrontendHtml($result);
            } catch (\Throwable $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function cpRoutes(): array
    {
        $routes = [];
        foreach ($this->enabled() as $provider) {
            $routes = array_merge($routes, $provider->cpRoutes());
        }

        return $routes;
    }

    /**
     * @return array<string, string>
     */
    public function siteRoutes(): array
    {
        $routes = [];
        foreach ($this->enabled() as $provider) {
            $routes = array_merge($routes, $provider->siteRoutes());
        }

        return $routes;
    }

    /**
     * @param array<string, FeatureProviderInterface> $providers
     * @return array<string, FeatureProviderInterface>
     */
    private function orderedProviders(array $providers): array
    {
        $config = PragmaticWebToolkit::$plugin->domainConfig->getConfiguration($providers);
        $fallback = array_flip(array_keys($providers));

        uksort(
            $providers,
            static function (string $a, string $b) use ($config, $fallback): int {
                $aRank = (int)($config[$a]['order'] ?? PHP_INT_MAX);
                $bRank = (int)($config[$b]['order'] ?? PHP_INT_MAX);
                if ($aRank !== $bRank) {
                    return $aRank <=> $bRank;
                }

                return ($fallback[$a] ?? PHP_INT_MAX) <=> ($fallback[$b] ?? PHP_INT_MAX);
            }
        );

        return $providers;
    }
}

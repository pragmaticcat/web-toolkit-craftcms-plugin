<?php

namespace pragmatic\webtoolkit\domains\translations\twig;

use Craft;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class PragmaticTranslationsTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('t', [$this, 'translate']),
        ];
    }

    /**
     * Supports usages:
     *   {{ 'key'|t }}
     *   {{ 'key'|t({ name: 'Oriol' }) }}
     *   {{ 'key'|t('app') }}
     *   {{ 'key'|t('app', { name: 'Oriol' }) }}
     *   {{ 'key'|t('app', { name: 'Oriol' }, 'es-ES') }}
     */
    public function translate(string $message, $arg1 = null, $arg2 = null, $arg3 = null): string
    {
        $category = 'site';
        $params = [];
        $language = null;

        if (is_array($arg1)) {
            $params = $arg1;
        } elseif (is_string($arg1)) {
            $category = $arg1;
            if (is_array($arg2)) {
                $params = $arg2;
            }
            if (is_string($arg3)) {
                $language = $arg3;
            }
        }

        $siteId = $this->resolveSiteId($language);
        $group = $category ?: 'site';
        if ($group === '') {
            $group = 'site';
        }

        $service = PragmaticWebToolkit::$plugin->translations;
        $service->ensureGroupExists($group);
        $value = $service->getValueWithFallback($message, $siteId, true, true, $group);
        if ($value !== null) {
            return $service->t($message, $params, $siteId, true, true, $group);
        }

        $service->ensureKeyExists($message, $group);
        return Craft::t($category, $message, $params, $language);
    }

    private function resolveSiteId(?string $language): int
    {
        $sitesService = Craft::$app->getSites();
        if ($language) {
            foreach ($sitesService->getAllSites() as $site) {
                if ($site->language === $language) {
                    return $site->id;
                }
            }
        }

        return $sitesService->getCurrentSite()->id;
    }
}

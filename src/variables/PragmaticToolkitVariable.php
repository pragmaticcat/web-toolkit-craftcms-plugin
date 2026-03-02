<?php

namespace pragmatic\webtoolkit\variables;

use Craft;
use craft\base\ElementInterface;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Twig\Markup;

class PragmaticToolkitVariable
{
    public function edition(): string
    {
        return PragmaticWebToolkit::$plugin->edition;
    }

    public function atLeast(string $edition): bool
    {
        return PragmaticWebToolkit::$plugin->atLeast($edition);
    }

    public function domain(string $key): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        return (array)($settings->{$key} ?? []);
    }

    public function hasFeature(string $domain): bool
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $flag = 'enable' . ucfirst($domain);
        return property_exists($settings, $flag) ? (bool)$settings->{$flag} : false;
    }

    public function cookiesHasConsent(string $categoryHandle): bool
    {
        if (!$this->hasFeature('cookies')) {
            return false;
        }

        return PragmaticWebToolkit::$plugin->cookiesConsent->hasConsent($categoryHandle);
    }

    public function cookiesCurrentConsent(): array
    {
        if (!$this->hasFeature('cookies')) {
            return [];
        }

        return PragmaticWebToolkit::$plugin->cookiesConsent->getCurrentConsent();
    }

    public function cookiesGroupedTable(): Markup
    {
        if (!$this->hasFeature('cookies')) {
            return new Markup('', 'UTF-8');
        }

        return new Markup(PragmaticWebToolkit::$plugin->cookiesConsent->renderCookieTable(), 'UTF-8');
    }

    public function cookiesTable(): Markup
    {
        return $this->cookiesGroupedTable();
    }

    public function cookiesPopup(): Markup
    {
        if (!$this->hasFeature('cookies')) {
            return new Markup('', 'UTF-8');
        }

        return new Markup(PragmaticWebToolkit::$plugin->cookiesConsent->renderFrontend(), 'UTF-8');
    }

    public function faviconTags(?int $siteId = null): Markup
    {
        if (!$this->hasFeature('favicon')) {
            return new Markup('', 'UTF-8');
        }

        return new Markup(PragmaticWebToolkit::$plugin->faviconTags->renderTags($siteId), 'UTF-8');
    }

    public function seoTags(?ElementInterface $element = null, string $fieldHandle = 'seo'): Markup
    {
        if (!$this->hasFeature('seo')) {
            return new Markup('', 'UTF-8');
        }

        $seo = new \pragmatic\webtoolkit\domains\seo\variables\PragmaticSeoVariable();

        return new Markup($seo->render($element, $fieldHandle), 'UTF-8');
    }

    public function analyticsScripts(): Markup
    {
        if (!$this->hasFeature('analytics')) {
            return new Markup('', 'UTF-8');
        }

        return new Markup(PragmaticWebToolkit::$plugin->analytics->renderFrontendScripts(), 'UTF-8');
    }

    public function plus18Gate(): Markup
    {
        if (!$this->hasFeature('plus18')) {
            return new Markup('', 'UTF-8');
        }

        $settings = PragmaticWebToolkit::$plugin->plus18Settings->get();
        if (!$settings->enabled) {
            return new Markup('', 'UTF-8');
        }

        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $html = $view->renderTemplate('pragmatic-web-toolkit/plus18/frontend/_age-gate', [
                'settings' => $settings,
                'language' => Craft::$app->language,
            ]);
        } finally {
            $view->setTemplateMode($oldTemplateMode);
        }

        return new Markup($html, 'UTF-8');
    }

    public function frontendFeatures(?ElementInterface $element = null, string $fieldHandle = 'seo'): Markup
    {
        $html = '';
        $html .= (string)$this->faviconTags();
        $html .= (string)$this->seoTags($element, $fieldHandle);
        $html .= (string)$this->analyticsScripts();
        $html .= (string)$this->cookiesPopup();
        $html .= (string)$this->plus18Gate();

        return new Markup($html, 'UTF-8');
    }
}

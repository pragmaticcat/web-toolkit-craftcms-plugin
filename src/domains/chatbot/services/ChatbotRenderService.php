<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ChatbotRenderService
{
    public function renderWidget(?ElementInterface $context = null, array $options = []): string
    {
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings($siteId);
        if (!$siteSettings->showLauncher || $siteSettings->displayMode === 'embed') {
            return '';
        }

        return $this->renderRoot('popup', $context, $options);
    }

    public function renderEmbed(array $options = []): string
    {
        $context = $options['context'] ?? null;
        if (!$context instanceof ElementInterface) {
            $context = null;
        }

        return $this->renderRoot('embed', $context, $options);
    }

    public function buildFrontendConfig(string $mode, ?ElementInterface $context = null, array $options = []): array
    {
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $settings = PragmaticWebToolkit::$plugin->chatbotSettings->get();
        $siteSettings = PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings($siteId);

        return [
            'mode' => $mode,
            'assistantName' => $siteSettings->assistantName,
            'welcomeMessage' => $siteSettings->welcomeMessage,
            'placeholderText' => $siteSettings->placeholderText,
            'popupTitle' => $siteSettings->popupTitle,
            'launcherLabel' => $siteSettings->launcherLabel,
            'theme' => [
                'primaryColor' => $siteSettings->themePrimaryColor,
                'backgroundColor' => $siteSettings->themeBackgroundColor,
                'textColor' => $siteSettings->themeTextColor,
                'panelPosition' => $siteSettings->panelPosition,
                'borderRadius' => $siteSettings->borderRadius,
            ],
            'displayMode' => $siteSettings->displayMode,
            'autoOpen' => $siteSettings->autoOpen,
            'disclaimerText' => $siteSettings->disclaimerText,
            'emptyStatePrompts' => $siteSettings->emptyStatePrompts ?: $settings->emptyStatePrompts,
            'fallbackContactUrl' => $siteSettings->fallbackContactUrl,
            'endpoints' => [
                'session' => UrlHelper::siteUrl('pragmatic-toolkit/chatbot/session'),
                'message' => UrlHelper::siteUrl('pragmatic-toolkit/chatbot/message'),
                'config' => UrlHelper::siteUrl('pragmatic-toolkit/chatbot/config'),
                'actionTrack' => UrlHelper::siteUrl('pragmatic-toolkit/chatbot/action-track'),
            ],
            'csrf' => [
                'param' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                'token' => Craft::$app->getRequest()->getCsrfToken(),
            ],
            'pageContext' => [
                'entryId' => $context?->id,
                'title' => $context?->title,
                'sectionHandle' => $context && method_exists($context, 'getSection') ? $context->getSection()?->handle : null,
            ],
            'options' => $options,
        ];
    }

    private function renderRoot(string $mode, ?ElementInterface $context = null, array $options = []): string
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $html = $view->renderTemplate('pragmatic-web-toolkit/chatbot/frontend/_widget', [
                'mode' => $mode,
                'config' => $this->buildFrontendConfig($mode, $context, $options),
            ]);
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return $html;
    }
}

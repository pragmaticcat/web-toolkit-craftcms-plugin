<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Response;

class ChatbotController extends Controller
{
    protected array|int|bool $allowAnonymous = ['session', 'message', 'action-track', 'config'];

    public function beforeAction($action): bool
    {
        if (in_array($action->id, ['session', 'message', 'action-track', 'config'], true)) {
            return parent::beforeAction($action);
        }

        $this->requirePermission('pragmatic-toolkit:chatbot');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/chatbot/general');
    }

    public function actionGeneral(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        return $this->renderTemplate('pragmatic-web-toolkit/chatbot/general', [
            'settings' => PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings($selectedSiteId),
            'globalSettings' => PragmaticWebToolkit::$plugin->chatbotSettings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        return $this->renderTemplate('pragmatic-web-toolkit/chatbot/options', [
            'settings' => PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings($selectedSiteId),
            'globalSettings' => PragmaticWebToolkit::$plugin->chatbotSettings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionPreview(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        return $this->renderTemplate('pragmatic-web-toolkit/chatbot/preview', [
            'settings' => PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings($selectedSiteId),
            'globalSettings' => PragmaticWebToolkit::$plugin->chatbotSettings->get(),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function actionSaveGeneral(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('site', Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);

        $global = [
            'providerMode' => (string)$request->getBodyParam('providerMode', 'mcp'),
            'aiProvider' => (string)$request->getBodyParam('aiProvider', 'openai'),
            'apiBaseUrl' => (string)$request->getBodyParam('apiBaseUrl', 'https://api.openai.com/v1'),
            'apiKey' => (string)$request->getBodyParam('apiKey', ''),
            'model' => (string)$request->getBodyParam('model', 'gpt-5-mini'),
            'requestTimeout' => (int)$request->getBodyParam('requestTimeout', 20),
            'defaultLanguageStrategy' => (string)$request->getBodyParam('defaultLanguageStrategy', 'site'),
            'maxContextItems' => (int)$request->getBodyParam('maxContextItems', 3),
            'maxSuggestions' => (int)$request->getBodyParam('maxSuggestions', 3),
            'systemPrompt' => (string)$request->getBodyParam('systemPrompt', ''),
            'logLevel' => (string)$request->getBodyParam('logLevel', 'errors'),
            'emptyStatePrompts' => preg_split('/\R+/', (string)$request->getBodyParam('globalEmptyStatePrompts', '')) ?: [],
        ];

        $site = [
            'assistantName' => (string)$request->getBodyParam('assistantName', ''),
            'welcomeMessage' => (string)$request->getBodyParam('welcomeMessage', ''),
            'placeholderText' => (string)$request->getBodyParam('placeholderText', ''),
            'popupTitle' => (string)$request->getBodyParam('popupTitle', ''),
            'launcherLabel' => (string)$request->getBodyParam('launcherLabel', ''),
            'fallbackContactUrl' => (string)$request->getBodyParam('fallbackContactUrl', ''),
            'disclaimerText' => (string)$request->getBodyParam('disclaimerText', ''),
            'allowedSections' => preg_split('/\R+/', (string)$request->getBodyParam('allowedSections', '')) ?: [],
            'excludedSections' => preg_split('/\R+/', (string)$request->getBodyParam('excludedSections', '')) ?: [],
            'emptyStatePrompts' => preg_split('/\R+/', (string)$request->getBodyParam('emptyStatePrompts', '')) ?: [],
        ];

        $ok = PragmaticWebToolkit::$plugin->chatbotSettings->saveFromArray($global)
            && PragmaticWebToolkit::$plugin->chatbotSiteSettings->saveSiteSettings($siteId, $site);

        if (!$ok) {
            Craft::$app->getSession()->setError('Could not save chatbot settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Chatbot settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveOptions(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('site', Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);

        $global = [
            'allowedActionTypes' => (array)$request->getBodyParam('allowedActionTypes', []),
            'enabled' => (bool)$request->getBodyParam('enabled', true),
        ];

        $site = [
            'themePrimaryColor' => (string)$request->getBodyParam('themePrimaryColor', '#0f766e'),
            'themeBackgroundColor' => (string)$request->getBodyParam('themeBackgroundColor', '#ffffff'),
            'themeTextColor' => (string)$request->getBodyParam('themeTextColor', '#0f172a'),
            'panelPosition' => (string)$request->getBodyParam('panelPosition', 'right'),
            'displayMode' => (string)$request->getBodyParam('displayMode', 'both'),
            'borderRadius' => (int)$request->getBodyParam('borderRadius', 18),
            'showLauncher' => (bool)$request->getBodyParam('showLauncher', false),
            'autoOpen' => (bool)$request->getBodyParam('autoOpen', false),
        ];

        $ok = PragmaticWebToolkit::$plugin->chatbotSettings->saveFromArray($global)
            && PragmaticWebToolkit::$plugin->chatbotSiteSettings->saveSiteSettings($siteId, $site);

        if (!$ok) {
            Craft::$app->getSession()->setError('Could not save chatbot options.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Chatbot options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSession(): Response
    {
        $payload = $this->requestPayload();
        $state = PragmaticWebToolkit::$plugin->chatbotConversation->startSession($payload);

        return $this->asJson([
            'conversationState' => ['id' => $state['id']],
            'message' => PragmaticWebToolkit::$plugin->chatbotSiteSettings->getSiteSettings((int)Craft::$app->getSites()->getCurrentSite()->id)->welcomeMessage,
        ]);
    }

    public function actionMessage(): Response
    {
        $this->requirePostRequest();
        $payload = $this->requestPayload();
        $message = trim((string)($payload['message'] ?? Craft::$app->getRequest()->getBodyParam('message', '')));
        $conversationId = trim((string)($payload['conversationId'] ?? Craft::$app->getRequest()->getBodyParam('conversationId', '')));

        return $this->asJson(
            PragmaticWebToolkit::$plugin->chatbotConversation->reply($conversationId, $message, $payload)
        );
    }

    public function actionConfig(): Response
    {
        return $this->asJson(
            PragmaticWebToolkit::$plugin->chatbotRender->buildFrontendConfig('popup')
        );
    }

    public function actionActionTrack(): Response
    {
        $payload = $this->requestPayload();

        return $this->asJson([
            'success' => true,
            'trackedAction' => $payload['action'] ?? null,
        ]);
    }

    private function requestPayload(): array
    {
        $raw = Craft::$app->getRequest()->getRawBody();
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return Craft::$app->getRequest()->getBodyParams();
    }
}

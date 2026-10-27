<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ExternalCodeController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('externalCode')) {
            throw new NotFoundHttpException('External Code is disabled.');
        }
        $this->requirePermission('pragmatic-toolkit:manage-external-code');
        if (in_array($action->id, ['save', 'delete', 'toggle-site', 'reorder'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $selectedSite = $this->selectedSite();
        $service = PragmaticWebToolkit::$plugin->externalCode;
        $snippets = $service->all();
        foreach ($snippets as &$snippet) {
            $snippet['enabledForSelectedSite'] = $service->isEnabledForSite($snippet, (int)$selectedSite->id);
        }
        unset($snippet);
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/index', [
            'snippets' => $snippets,
            'selectedSite' => $selectedSite,
        ]);
    }

    public function actionSetup(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/setup', [
            'selectedSite' => $this->selectedSite(),
        ]);
    }

    public function actionEdit(?string $id = null): Response
    {
        $snippet = $id === null ? null : PragmaticWebToolkit::$plugin->externalCode->find($id);
        if ($id !== null && $snippet === null) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        $sites = Craft::$app->getSites()->getAllSites();
        $states = $snippet === null
            ? array_fill_keys(array_map(static fn($site): int => (int)$site->id, $sites), true)
            : PragmaticWebToolkit::$plugin->externalCode->siteStates($snippet);
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/edit', [
            'snippet' => $snippet ?? ['position' => 'endBody', 'sortOrder' => count(PragmaticWebToolkit::$plugin->externalCode->all()) + 1],
            'siteStates' => $states,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories((int)$this->selectedSite()->id) : [],
            'selectedSite' => $this->selectedSite(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $input = (array)$this->request->getBodyParam('snippet', []);
        $id = trim((string)$this->request->getBodyParam('id', '')) ?: null;
        if ($id !== null && PragmaticWebToolkit::$plugin->externalCode->find($id) === null) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        if (!PragmaticWebToolkit::$plugin->externalCode->save($input, $id)) {
            Craft::$app->getSession()->setError('Could not save external code. Check the name, code, site and consent category.');
            Craft::$app->getUrlManager()->setRouteParams([
                'snippet' => array_merge($input, ['id' => $id]),
                'sites' => Craft::$app->getSites()->getAllSites(),
                'siteStates' => (array)($input['enabledBySite'] ?? []),
                'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories((int)$this->selectedSite()->id) : [],
                'selectedSite' => $this->selectedSite(),
            ]);
            return null;
        }
        Craft::$app->getSession()->setNotice('External code saved.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionDelete(): Response
    {
        $id = (string)$this->request->getBodyParam('id', '');
        if (!PragmaticWebToolkit::$plugin->externalCode->delete($id)) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        Craft::$app->getSession()->setNotice('External code deleted.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionToggleSite(): Response
    {
        $id = (string)$this->request->getBodyParam('id', '');
        $siteId = (int)$this->request->getBodyParam('siteId', 0);
        $enabled = (bool)$this->request->getBodyParam('enabled', false);
        if (!PragmaticWebToolkit::$plugin->externalCode->setSiteEnabled($id, $siteId, $enabled)) {
            throw new NotFoundHttpException('Snippet or site not found.');
        }
        Craft::$app->getSession()->setNotice('Site status updated.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionReorder(): Response
    {
        $ids = (array)$this->request->getBodyParam('ids', []);
        if (!PragmaticWebToolkit::$plugin->externalCode->reorder($ids)) {
            Craft::$app->getSession()->setError('Could not save snippet order.');
        } else {
            Craft::$app->getSession()->setNotice('Snippet order saved.');
        }
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    private function selectedSite(): \craft\models\Site
    {
        $handle = (string)$this->request->getParam('site', '');
        return ($handle !== '' ? Craft::$app->getSites()->getSiteByHandle($handle) : null)
            ?? Craft::$app->getSites()->getPrimarySite();
    }
}

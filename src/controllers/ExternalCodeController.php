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
        if (in_array($action->id, ['save', 'delete'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/index', [
            'snippets' => PragmaticWebToolkit::$plugin->externalCode->all(),
        ]);
    }

    public function actionEdit(?string $id = null): Response
    {
        $snippet = $id === null ? null : PragmaticWebToolkit::$plugin->externalCode->find($id);
        if ($id !== null && $snippet === null) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/edit', [
            'snippet' => $snippet ?? ['enabled' => true, 'position' => 'endBody', 'siteIds' => [], 'sortOrder' => 0],
            'sites' => Craft::$app->getSites()->getAllSites(),
            'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories() : [],
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
                'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories() : [],
            ]);
            return null;
        }
        Craft::$app->getSession()->setNotice('External code saved.');
        return $this->redirect('pragmatic-toolkit/external-code');
    }

    public function actionDelete(): Response
    {
        $id = (string)$this->request->getBodyParam('id', '');
        if (!PragmaticWebToolkit::$plugin->externalCode->delete($id)) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        Craft::$app->getSession()->setNotice('External code deleted.');
        return $this->redirect('pragmatic-toolkit/external-code');
    }
}

<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel actions for redirect rules and the 404 log.
 */
class RedirectsController extends Controller
{
    /**
     * Require the permission that matches each Redirects action.
     *
     * @param mixed $action Requested action.
     * @return bool Whether the action can run.
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('redirects')) {
            throw new NotFoundHttpException('Redirects is disabled.');
        }
        $permission = match ($action->id) {
            '404s', 'handled', 'export-404s' => 'pragmatic-toolkit:view-404-log',
            'import', 'import-preview', 'export' => 'pragmatic-toolkit:redirects-import-export',
            'edit', 'save', 'delete', 'toggle', 'test', 'save-options' => 'pragmatic-toolkit:manage-redirects',
            default => 'pragmatic-toolkit:view-redirects',
        };
        $this->requirePermission($permission);
        if (in_array($action->id, ['save', 'delete', 'toggle', 'handled', 'test', 'import-preview', 'save-options'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    /** @return Response Rules index. */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/index', [
            'rules' => PragmaticWebToolkit::$plugin->redirects->getRules(Craft::$app->getRequest()->getQueryParams()),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'testResult' => null,
        ]);
    }

    /** @return Response Rule editor. */
    public function actionEdit(?int $id = null): Response
    {
        $rule = $id ? PragmaticWebToolkit::$plugin->redirects->getRule($id) : ['enabled' => true, 'statusCode' => 301, 'matchType' => 'exact', 'preserveQueryString' => true];
        if ($id === null) {
            $rule['sourceUri'] = (string)Craft::$app->getRequest()->getQueryParam('source', '');
            $rule['siteId'] = Craft::$app->getRequest()->getQueryParam('siteId');
        }
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/edit', [
            'rule' => $rule,
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /** @return Response Save or return an error to the editor. */
    public function actionSave(): Response
    {
        $values = (array)Craft::$app->getRequest()->getBodyParam('rule', []);
        $id = !empty($values['id']) ? (int)$values['id'] : null;
        $saved = PragmaticWebToolkit::$plugin->redirects->saveRule($values, $id);
        if (!is_int($saved)) {
            Craft::$app->getSession()->setError((string)$saved);
            return $this->redirectToPostedUrl();
        }
        Craft::$app->getSession()->setNotice('Redirect saved.');
        if (PragmaticWebToolkit::$plugin->redirects->hasChain($saved)) {
            Craft::$app->getSession()->setNotice('Redirect saved. This destination continues through another redirect.');
        }
        return $this->redirect('pragmatic-toolkit/redirects');
    }

    /** @return Response Delete a redirect. */
    public function actionDelete(): Response
    {
        PragmaticWebToolkit::$plugin->redirects->deleteRule((int)Craft::$app->getRequest()->getBodyParam('id'));
        return $this->redirect('pragmatic-toolkit/redirects');
    }

    /** @return Response Toggle rule state. */
    public function actionToggle(): Response
    {
        PragmaticWebToolkit::$plugin->redirects->setEnabled((int)Craft::$app->getRequest()->getBodyParam('id'), (bool)Craft::$app->getRequest()->getBodyParam('enabled'));
        return $this->redirectToPostedUrl();
    }

    /** @return Response 404 log index. */
    public function action404s(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/404s', [
            'rows' => PragmaticWebToolkit::$plugin->redirects->get404s(Craft::$app->getRequest()->getQueryParams()),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /** @return Response Mark a logged request handled. */
    public function actionHandled(): Response
    {
        PragmaticWebToolkit::$plugin->redirects->markHandled((int)Craft::$app->getRequest()->getBodyParam('id'));
        return $this->redirectToPostedUrl();
    }

    /** @return Response Return a matcher preview on the rules index. */
    public function actionTest(): Response
    {
        $path = (string)Craft::$app->getRequest()->getBodyParam('path');
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/index', [
            'rules' => PragmaticWebToolkit::$plugin->redirects->getRules(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'testResult' => PragmaticWebToolkit::$plugin->redirects->test($path, $siteId ? (int)$siteId : null),
            'testPath' => $path,
        ]);
    }

    /** @return Response CSV import screen. */
    public function actionImport(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/import', ['results' => null]);
    }

    /** @return Response Import a submitted CSV upload. */
    public function actionImportPreview(): Response
    {
        $file = $_FILES['csvFile']['tmp_name'] ?? null;
        if (!$file || !is_uploaded_file($file)) {
            Craft::$app->getSession()->setError('Choose a CSV file to import.');
            return $this->redirect('pragmatic-toolkit/redirects/import');
        }
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle) ?: [];
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, array_pad($data, count($headers), '')) ?: [];
        }
        fclose($handle);
        $dryRun = (string)Craft::$app->getRequest()->getBodyParam('dryRun', '1') !== '0';
        $results = PragmaticWebToolkit::$plugin->redirects->importRows($rows, $dryRun);
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/import', ['results' => $results, 'dryRun' => $dryRun]);
    }

    /** @return Response Download redirect rules CSV. */
    public function actionExport(): Response
    {
        return $this->csvResponse(PragmaticWebToolkit::$plugin->redirects->exportCsv(), 'redirects.csv');
    }

    /** @return Response Download 404 log CSV. */
    public function actionExport404s(): Response
    {
        $this->requirePermission('pragmatic-toolkit:redirects-import-export');
        return $this->csvResponse(PragmaticWebToolkit::$plugin->redirects->exportCsv(true), 'redirects-404s.csv');
    }

    /** @return Response Settings screen. */
    public function actionOptions(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/redirects/options', ['settings' => PragmaticWebToolkit::$plugin->redirectSettings->get()]);
    }

    /** @return Response Save Redirects settings. */
    public function actionSaveOptions(): Response
    {
        $values = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (isset($values['ignorePatterns']) && is_string($values['ignorePatterns'])) {
            $values['ignorePatterns'] = preg_split('/\\r?\\n/', $values['ignorePatterns']) ?: [];
        }
        if (!PragmaticWebToolkit::$plugin->redirectSettings->save($values)) {
            Craft::$app->getSession()->setError('Settings could not be saved.');
            return $this->redirectToPostedUrl();
        }
        Craft::$app->getSession()->setNotice('Redirect settings saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * Return a downloadable CSV response.
     *
     * @param string $content CSV data.
     * @param string $filename Download filename.
     * @return Response Download response.
     */
    private function csvResponse(string $content, string $filename): Response
    {
        $response = Craft::$app->getResponse();
        $response->setDownloadHeaders($filename, 'text/csv');
        $response->content = $content;
        return $response;
    }
}

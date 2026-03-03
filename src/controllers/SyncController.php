<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SyncController extends Controller
{
    private const STAGED_SESSION_KEY = 'pragmatic-web-toolkit.sync.staged-package';

    protected int|bool|array $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        $permission = match ($action->id) {
            'export' => 'pragmatic-toolkit:sync-export',
            'upload-import-package', 'confirm-import' => 'pragmatic-toolkit:sync-import',
            default => 'pragmatic-toolkit:sync-manage',
        };

        $this->requirePermission($permission);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/sync/packages');
    }

    public function actionPackages(): Response
    {
        return $this->renderPackages();
    }

    public function actionExport(): Response
    {
        $this->requirePostRequest();

        try {
            $result = PragmaticWebToolkit::$plugin->syncPackageBuilder->buildPackage();
            PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'export',
                'success',
                $result['downloadName'],
                $result['summary']
            );

            return Craft::$app->getResponse()->sendFile($result['zipPath'], $result['downloadName'], [
                'mimeType' => 'application/zip',
            ]);
        } catch (Throwable $e) {
            PragmaticWebToolkit::$plugin->syncTransferLog->create('export', 'failed', 'sync-export.zip', [], $e->getMessage());
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
        }
    }

    public function actionUploadImportPackage(): Response
    {
        $this->requirePostRequest();
        $this->clearStagedSession();

        $file = \yii\web\UploadedFile::getInstanceByName('file');
        if (!$file) {
            throw new BadRequestHttpException('No ZIP package uploaded.');
        }

        $stagedLogId = null;

        try {
            $preflight = PragmaticWebToolkit::$plugin->syncPackageInspector->stageUpload($file);
            $status = empty($preflight['errors']) ? 'staged' : 'blocked';
            $stagedLogId = PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'import',
                $status,
                $preflight['packageName'],
                $preflight['summary'],
                empty($preflight['errors']) ? null : implode("\n", $preflight['errors'])
            );

            $preflight['token'] = $this->createStageToken();
            $preflight['logId'] = $stagedLogId;

            if (empty($preflight['errors'])) {
                $this->setStagedSession([
                    'token' => $preflight['token'],
                    'stagingPath' => $preflight['stagingPath'],
                    'packageName' => $preflight['packageName'],
                    'logId' => $stagedLogId,
                ]);
            } else {
                PragmaticWebToolkit::$plugin->syncPackageInspector->cleanup($preflight['stagingPath']);
            }

            return $this->renderPackages($preflight);
        } catch (Throwable $e) {
            if ($stagedLogId) {
                PragmaticWebToolkit::$plugin->syncTransferLog->update($stagedLogId, 'failed', null, $e->getMessage());
            }
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
        }
    }

    public function actionConfirmImport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $token = (string)$request->getBodyParam('token', '');
        $confirmation = trim((string)$request->getBodyParam('confirmation', ''));
        $acknowledged = (bool)$request->getBodyParam('acknowledgeImport');
        $staged = $this->stagedSession();

        if (!$staged || $token === '' || $token !== (string)($staged['token'] ?? '')) {
            throw new BadRequestHttpException('The staged import package is no longer available.');
        }

        if (!$acknowledged || $confirmation !== 'IMPORT') {
            Craft::$app->getSession()->setError('Type IMPORT and confirm the destructive import warning before continuing.');
            return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
        }

        $preflight = PragmaticWebToolkit::$plugin->syncPackageInspector->inspectStagingPath(
            (string)$staged['stagingPath'],
            (string)$staged['packageName']
        );
        $preflight['token'] = $token;
        $preflight['logId'] = $staged['logId'] ?? null;

        if (!empty($preflight['errors'])) {
            PragmaticWebToolkit::$plugin->syncTransferLog->update(
                (int)($staged['logId'] ?? 0),
                'blocked',
                $preflight['summary'],
                implode("\n", $preflight['errors'])
            );

            return $this->renderPackages($preflight, [
                'type' => 'error',
                'message' => 'Import preflight failed. No data was changed.',
            ]);
        }

        try {
            $result = PragmaticWebToolkit::$plugin->syncPackageImport->importStagedPackage((string)$staged['stagingPath']);
            $summary = array_merge($preflight['summary'], $result);
            PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'import',
                'success',
                (string)$staged['packageName'],
                $summary
            );

            PragmaticWebToolkit::$plugin->syncPackageInspector->cleanup((string)$staged['stagingPath']);
            $this->clearStagedSession();

            return $this->renderPackages(null, [
                'type' => 'success',
                'message' => sprintf(
                    'Import finished. Database restored and %d asset files merged across %d volumes.',
                    (int)$result['importedFiles'],
                    (int)$result['importedVolumes']
                ),
            ]);
        } catch (Throwable $e) {
            PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'import',
                'failed',
                (string)$staged['packageName'],
                $preflight['summary'],
                $e->getMessage()
            );

            return $this->renderPackages($preflight, [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function actionOptions(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/sync/options', [
            'settings' => PragmaticWebToolkit::$plugin->syncSettings->get(),
        ]);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

        $input = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!PragmaticWebToolkit::$plugin->syncSettings->saveFromArray($input)) {
            Craft::$app->getSession()->setError('Could not save Sync options.');

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Sync options saved.');

        return $this->redirectToPostedUrl();
    }

    private function renderPackages(?array $preflight = null, ?array $importResult = null): Response
    {
        $settings = PragmaticWebToolkit::$plugin->syncSettings->get();
        PragmaticWebToolkit::$plugin->syncPackageInspector->pruneExpiredStagingDirectories($settings->stagedUploadRetentionHours);
        PragmaticWebToolkit::$plugin->syncTransferLog->prune($settings->historyRetentionDays);

        if ($preflight === null) {
            $staged = $this->stagedSession();
            if ($staged && isset($staged['stagingPath'], $staged['packageName'])) {
                try {
                    $preflight = PragmaticWebToolkit::$plugin->syncPackageInspector->inspectStagingPath(
                        (string)$staged['stagingPath'],
                        (string)$staged['packageName']
                    );
                    $preflight['token'] = $staged['token'] ?? '';
                    $preflight['logId'] = $staged['logId'] ?? null;
                } catch (Throwable) {
                    $this->clearStagedSession();
                }
            }
        }

        return $this->renderTemplate('pragmatic-web-toolkit/sync/packages', [
            'settings' => $settings,
            'history' => PragmaticWebToolkit::$plugin->syncTransferLog->recent(),
            'preflight' => $preflight,
            'importResult' => $importResult,
        ]);
    }

    private function stagedSession(): ?array
    {
        $value = Craft::$app->getSession()->get(self::STAGED_SESSION_KEY);

        return is_array($value) ? $value : null;
    }

    private function setStagedSession(array $payload): void
    {
        Craft::$app->getSession()->set(self::STAGED_SESSION_KEY, $payload);
    }

    private function clearStagedSession(): void
    {
        Craft::$app->getSession()->remove(self::STAGED_SESSION_KEY);
    }

    private function createStageToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}

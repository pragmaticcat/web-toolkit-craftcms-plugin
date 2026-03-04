<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\sync\jobs\SyncExportJob;
use pragmatic\webtoolkit\domains\sync\jobs\SyncImportJob;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SyncController extends Controller
{
    private const STAGED_SESSION_KEY = 'pragmatic-web-toolkit.sync.staged-package';

    protected int|bool|array $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        $permission = match ($action->id) {
            'export', 'download-export' => 'pragmatic-toolkit:sync-export',
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
            $exportMode = $this->normalizeExportMode((string)Craft::$app->getRequest()->getBodyParam('exportMode', 'both'));
            PragmaticWebToolkit::$plugin->syncExportArtifacts->pruneExpiredArtifacts();
            $logId = PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'export',
                'queued',
                sprintf('pwt-sync-%s.zip', gmdate('Ymd-His')),
                ['exportMode' => $exportMode],
                null,
                ['progressLabel' => 'Queued']
            );

            if (!$logId) {
                throw new \RuntimeException('Could not create the export log row.');
            }

            $jobId = Craft::$app->getQueue()->push(new SyncExportJob([
                'logId' => $logId,
                'exportMode' => $exportMode,
            ]));
            PragmaticWebToolkit::$plugin->syncTransferLog->update($logId, ['jobId' => $jobId]);

            Craft::$app->getSession()->setNotice('Export queued. Refresh history to download the package when it finishes.');
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        }

        return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
    }

    public function actionDownloadExport(int $id): Response
    {
        $row = PragmaticWebToolkit::$plugin->syncTransferLog->getById($id);
        if (!$row || !$row->canDownload) {
            throw new NotFoundHttpException('Export artifact is not available.');
        }

        $record = Craft::$app->getDb()->createCommand(
            'SELECT artifactPath, artifactFilename FROM {{%pragmatic_toolkit_sync_transfer_logs}} WHERE id = :id',
            [':id' => $id]
        )->queryOne();

        $artifactPath = (string)($record['artifactPath'] ?? '');
        $artifactFilename = (string)($record['artifactFilename'] ?? '');

        if (!PragmaticWebToolkit::$plugin->syncExportArtifacts->artifactExists($artifactPath)) {
            throw new NotFoundHttpException('Export artifact file no longer exists.');
        }

        return Craft::$app->getResponse()->sendFile($artifactPath, $artifactFilename, ['mimeType' => 'application/zip']);
    }

    public function actionUploadImportPackage(): Response
    {
        $this->requirePostRequest();
        $this->clearStagedSession();

        $file = \yii\web\UploadedFile::getInstanceByName('file');
        if (!$file) {
            throw new BadRequestHttpException('No ZIP package uploaded.');
        }

        try {
            $preflight = PragmaticWebToolkit::$plugin->syncPackageInspector->stageUpload($file);
            $status = empty($preflight['errors']) ? 'staged' : 'blocked';
            $logId = PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'import',
                $status,
                $preflight['packageName'],
                $preflight['summary'],
                empty($preflight['errors']) ? null : implode("\n", $preflight['errors']),
                [
                    'manifest' => $preflight['manifest']->toArray(),
                    'warnings' => $preflight['warnings'],
                    'progressLabel' => $status === 'staged' ? 'Awaiting confirmation' : 'Blocked',
                ]
            );

            $preflight['token'] = $this->createStageToken();
            $preflight['logId'] = $logId;

            if (empty($preflight['errors'])) {
                $this->setStagedSession([
                    'token' => $preflight['token'],
                    'stagingPath' => $preflight['stagingPath'],
                    'packageName' => $preflight['packageName'],
                    'logId' => $logId,
                ]);
            } else {
                PragmaticWebToolkit::$plugin->syncPackageInspector->cleanup($preflight['stagingPath']);
            }

            return $this->renderPackages($preflight);
        } catch (Throwable $e) {
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

        if (!empty($preflight['errors'])) {
            PragmaticWebToolkit::$plugin->syncTransferLog->update((int)($staged['logId'] ?? 0), [
                'status' => 'blocked',
                'summary' => $preflight['summary'],
                'manifest' => $preflight['manifest']->toArray(),
                'warnings' => $preflight['warnings'],
                'errorMessage' => implode("\n", $preflight['errors']),
                'progressLabel' => 'Blocked',
            ]);

            return $this->renderPackages($preflight, [
                'type' => 'error',
                'message' => 'Import preflight failed. No data was changed.',
            ]);
        }

        try {
            $logId = PragmaticWebToolkit::$plugin->syncTransferLog->create(
                'import',
                'queued',
                (string)$staged['packageName'],
                $preflight['summary'],
                null,
                [
                    'manifest' => $preflight['manifest']->toArray(),
                    'warnings' => $preflight['warnings'],
                    'progressLabel' => 'Queued',
                ]
            );

            if (!$logId) {
                throw new \RuntimeException('Could not create the import log row.');
            }

            $jobId = Craft::$app->getQueue()->push(new SyncImportJob([
                'logId' => $logId,
                'stagingPath' => (string)$staged['stagingPath'],
                'packageName' => (string)$staged['packageName'],
            ]));

            PragmaticWebToolkit::$plugin->syncTransferLog->update($logId, ['jobId' => $jobId]);
            $this->clearStagedSession();

            Craft::$app->getSession()->setNotice('Import queued. Check history for progress and final status.');
            return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/sync/packages'));
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
        PragmaticWebToolkit::$plugin->syncExportArtifacts->pruneExpiredArtifacts();

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

    private function normalizeExportMode(string $exportMode): string
    {
        return match ($exportMode) {
            'db', 'assets', 'both' => $exportMode,
            default => throw new BadRequestHttpException('Unsupported export mode.'),
        };
    }
}

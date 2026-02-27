<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class TranslationsController extends Controller
{
    protected int|bool|array $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        if (in_array($action->id, ['export', 'export-project-php', 'import-project-php'], true)) {
            $this->requirePermission('pragmatic-toolkit:translations-export');
        } else {
            $this->requirePermission('pragmatic-toolkit:translations-manage');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/translations/static');
    }

    public function actionStaticIndex(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $group = (string)$request->getParam('group', '');
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $offset = ($page - 1) * $perPage;

        $service = PragmaticWebToolkit::$plugin->translations;
        $groups = $service->getGroupsWithState();
        $activeGroups = $service->getActiveGroups();
        if ($group !== '' && !in_array($group, $activeGroups, true)) {
            $group = '';
        }
        $total = $service->countTranslations($search, $group, $activeGroups);
        $translations = $service->getAllTranslations($search, $group, $perPage, $offset, $activeGroups);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        [$autotranslateAvailable, $autotranslateDisabledReason] = $this->getAutotranslateAvailabilityState();

        $sidebarGroups = array_values(array_filter($groups, static fn(array $g): bool => !empty($g['isActive'])));

        return $this->renderTemplate('pragmatic-web-toolkit/translations/static', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sites' => $sites,
            'languages' => $languages,
            'translations' => $translations,
            'groups' => $groups,
            'sidebarGroups' => $sidebarGroups,
            'search' => $search,
            'group' => $group,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'total' => $total,
            'autotranslateAvailable' => $autotranslateAvailable,
            'autotranslateDisabledReason' => $autotranslateDisabledReason,
            'autotranslateTextUrl' => UrlHelper::actionUrl('pragmatic-web-toolkit/translations/autotranslate-text'),
        ]);
    }

    public function actionEntries(): Response
    {
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $page = max(1, (int)$request->getParam('page', 1));
        $sectionId = (int)$request->getParam('section', 0);
        $fieldFilter = (string)$request->getParam('field', '');

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        if ($sectionId && !$this->isSectionAvailableForSite($sectionId, $selectedSiteId)) {
            $sectionId = 0;
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $languageMap = $this->getLanguageMap($sites);

        $entryQuery = Entry::find()->siteId($selectedSiteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $entries = $entryQuery->all();
        $globalSetQuery = GlobalSet::find()->siteId($selectedSiteId);
        if ($search !== '') {
            $globalSetQuery->search($search);
        }
        $globalSets = $globalSetQuery->all();

        $rows = [];
        foreach ($entries as $entry) {
            $this->appendElementRows($rows, $entry, 'entry', $fieldFilter, true);
        }

        foreach ($globalSets as $globalSet) {
            $this->appendElementRows($rows, $globalSet, 'globalSet', $fieldFilter, false);
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $entryIds = [];
        $globalSetIds = [];
        foreach ($pageRows as $row) {
            $elementType = (string)($row['elementType'] ?? 'entry');
            $elementId = (int)($row['elementId'] ?? 0);
            if ($elementId <= 0) {
                continue;
            }
            if ($elementType === 'globalSet') {
                $globalSetIds[$elementId] = true;
            } else {
                $entryIds[$elementId] = true;
            }
        }

        $entryIds = array_keys($entryIds);
        $globalSetIds = array_keys($globalSetIds);
        $siteEntries = [];
        $siteGlobalSets = [];
        if (!empty($entryIds)) {
            $allSiteIds = [];
            foreach ($languageMap as $siteIds) {
                foreach ($siteIds as $siteId) {
                    $allSiteIds[$siteId] = true;
                }
            }
            foreach (array_keys($allSiteIds) as $siteId) {
                $siteRows = Entry::find()->id($entryIds)->siteId($siteId)->status(null)->all();
                foreach ($siteRows as $siteRow) {
                    $siteEntries[$siteId][$siteRow->id] = $siteRow;
                }
                if (!empty($globalSetIds)) {
                    $globalRows = GlobalSet::find()->id($globalSetIds)->siteId($siteId)->all();
                    foreach ($globalRows as $globalRow) {
                        $siteGlobalSets[$siteId][$globalRow->id] = $globalRow;
                    }
                }
            }
        } elseif (!empty($globalSetIds)) {
            $allSiteIds = [];
            foreach ($languageMap as $siteIds) {
                foreach ($siteIds as $siteId) {
                    $allSiteIds[$siteId] = true;
                }
            }
            foreach (array_keys($allSiteIds) as $siteId) {
                $globalRows = GlobalSet::find()->id($globalSetIds)->siteId($siteId)->all();
                foreach ($globalRows as $globalRow) {
                    $siteGlobalSets[$siteId][$globalRow->id] = $globalRow;
                }
            }
        }

        foreach ($pageRows as &$row) {
            $row['values'] = [];
            $elementType = (string)($row['elementType'] ?? 'entry');
            $elementId = (int)($row['elementId'] ?? 0);
            foreach ($languageMap as $lang => $siteIds) {
                $value = '';
                foreach ($siteIds as $siteId) {
                    if ($elementType === 'globalSet') {
                        $globalSet = $siteGlobalSets[$siteId][$elementId] ?? null;
                        if ($globalSet instanceof GlobalSet) {
                            $value = $this->getElementFieldValueForHandle($globalSet, (string)$row['fieldHandle']);
                            break;
                        }
                    } elseif (isset($siteEntries[$siteId][$elementId])) {
                        $entry = $siteEntries[$siteId][$elementId];
                        if ($entry instanceof Entry) {
                            $value = $this->getElementFieldValueForHandle($entry, (string)$row['fieldHandle']);
                        }
                        break;
                    }
                }
                $row['values'][$lang] = $value;
            }
        }
        unset($row);

        $entryRowCounts = [];
        foreach ($pageRows as $row) {
            $entryKey = (string)($row['elementKey'] ?? ((string)($row['elementType'] ?? 'entry') . ':' . (int)($row['elementId'] ?? 0)));
            $entryRowCounts[$entryKey] = ($entryRowCounts[$entryKey] ?? 0) + 1;
        }

        $sections = $this->getEntrySectionsForSite($selectedSiteId, $fieldFilter);
        $fieldOptions = $this->getEntryFieldOptions();

        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        $apiKey = $this->resolveGoogleApiKey((string)$settings->googleApiKeyEnv);
        $autotranslateAvailable = $settings->enableAutotranslate && !empty($apiKey);

        return $this->renderTemplate('pragmatic-web-toolkit/translations/entries', [
            'rows' => $pageRows,
            'entryRowCounts' => $entryRowCounts,
            'languages' => $languages,
            'sections' => $sections,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sectionId' => $sectionId,
            'fieldFilter' => $fieldFilter,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'fieldOptions' => $fieldOptions,
            'autotranslateAvailable' => $autotranslateAvailable,
            'autotranslateTextUrl' => UrlHelper::actionUrl('pragmatic-web-toolkit/translations/autotranslate-text'),
        ]);
    }

    public function actionSaveEntryRow(): Response
    {
        $this->requirePostRequest();

        $saveRow = Craft::$app->getRequest()->getBodyParam('saveRow');
        $entries = Craft::$app->getRequest()->getBodyParam('entries', []);
        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $elementType = (string)($row['elementType'] ?? 'entry');
        $elementId = (int)($row['elementId'] ?? ($row['entryId'] ?? 0));
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);

        if (!$elementId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $result = $this->saveElementFieldValues($elementType, $elementId, $fieldHandle, $values);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            $ok = ((int)$result['saved'] > 0) && ((int)$result['failed'] === 0);
            $error = null;
            if (!$ok) {
                $error = isset($result['errors'][0]) && is_string($result['errors'][0])
                    ? $result['errors'][0]
                    : $this->buildNoValuesSavedMessage($result);
            }
            return $this->asJson([
                'success' => $ok,
                'result' => $result,
                'error' => $error,
            ]);
        }

        Craft::$app->getSession()->setNotice(sprintf(
            'Entry row saved. Saved %d, skipped %d, failed %d.',
            (int)$result['saved'],
            (int)$result['skipped'],
            (int)$result['failed'],
        ));
        return $this->redirectEntriesIndexWithCurrentFilters();
    }

    public function actionSaveEntryRows(): Response
    {
        $this->requirePostRequest();

        $entries = Craft::$app->getRequest()->getBodyParam('entries', []);
        if (!is_array($entries)) {
            throw new BadRequestHttpException('Invalid entries payload.');
        }

        $rowsProcessed = 0;
        $saved = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $elementType = (string)($row['elementType'] ?? 'entry');
            $elementId = (int)($row['elementId'] ?? ($row['entryId'] ?? 0));
            $fieldHandle = (string)($row['fieldHandle'] ?? '');
            $values = (array)($row['values'] ?? []);
            if (!$elementId || $fieldHandle === '') {
                continue;
            }

            $result = $this->saveElementFieldValues($elementType, $elementId, $fieldHandle, $values);
            $rowsProcessed++;
            $saved += (int)$result['saved'];
            $skipped += (int)$result['skipped'];
            $failed += (int)$result['failed'];
        }

        Craft::$app->getSession()->setNotice(sprintf(
            'Saved %d rows. Values saved: %d, skipped: %d, failed: %d.',
            $rowsProcessed,
            $saved,
            $skipped,
            $failed,
        ));
        return $this->redirectEntriesIndexWithCurrentFilters();
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $canManageOptions = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO);

        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        [$autotranslateAvailable, $autotranslateDisabledReason] = $this->getAutotranslateAvailabilityState();

        return $this->renderTemplate('pragmatic-web-toolkit/translations/options', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
            'autotranslateAvailable' => $autotranslateAvailable,
            'autotranslateDisabledReason' => $autotranslateDisabledReason,
            'canManageOptions' => $canManageOptions,
        ]);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            Craft::$app->getSession()->setError('Translation options require Pro edition.');
            return $this->redirectToPostedUrl();
        }

        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!is_array($settings)) {
            throw new BadRequestHttpException('Invalid settings payload.');
        }
        if (isset($settings['languageMapRows']) && is_array($settings['languageMapRows'])) {
            $languageMap = [];
            foreach ($settings['languageMapRows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $source = trim((string)($row['source'] ?? ''));
                $target = trim((string)($row['target'] ?? ''));
                if ($source === '' || $target === '') {
                    continue;
                }
                $languageMap[$source] = $target;
            }
            $settings['languageMap'] = $languageMap;
        }
        unset($settings['languageMapRows']);

        if (!PragmaticWebToolkit::$plugin->translationsSettings->saveFromArray($settings)) {
            Craft::$app->getSession()->setError('Could not save options.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $items = $request->getBodyParam('translations', []);
        if (!is_array($items)) {
            throw new BadRequestHttpException('Invalid translations payload.');
        }

        $deleteRow = $request->getBodyParam('deleteRow');
        if ($deleteRow !== null && isset($items[$deleteRow])) {
            $items[$deleteRow]['delete'] = 1;
        }
        $deleteRows = $request->getBodyParam('deleteRows', []);
        if (is_array($deleteRows)) {
            foreach ($deleteRows as $deleteIndex) {
                if (isset($items[$deleteIndex])) {
                    $items[$deleteIndex]['delete'] = 1;
                }
            }
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);
        $items = $this->expandLanguageValuesToSites($items, $languageMap);

        PragmaticWebToolkit::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations saved.');

        $returnGroup = trim((string)$request->getBodyParam('returnGroup', ''));
        $returnSearch = (string)$request->getBodyParam('returnQ', '');
        $returnPerPage = (int)$request->getBodyParam('returnPerPage', 50);
        if (!in_array($returnPerPage, [50, 100, 250], true)) {
            $returnPerPage = 50;
        }
        $returnPage = max(1, (int)$request->getBodyParam('returnPage', 1));
        $returnSite = trim((string)$request->getBodyParam('returnSite', ''));

        $params = [
            'q' => $returnSearch,
            'perPage' => $returnPerPage,
            'page' => $returnPage,
        ];
        if ($returnGroup !== '') {
            $params['group'] = $returnGroup;
        }
        if ($returnSite !== '') {
            $params['site'] = $returnSite;
        }

        return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/translations/static', $params));
    }

    public function actionExport(): Response
    {
        $format = strtolower((string)Craft::$app->getRequest()->getQueryParam('format', 'csv'));
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $service = PragmaticWebToolkit::$plugin->translations;

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Export requires Lite edition or higher.');
        }

        if (in_array($format, ['json', 'php'], true) && !PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('JSON and PHP export require Pro edition.');
        }

        if ($format === 'php') {
            return $this->exportPhp($sites, $service);
        }

        $translations = PragmaticWebToolkit::$plugin->translations->getAllTranslations();
        if ($format === 'json') {
            $payload = [];
            foreach ($translations as $translation) {
                $item = [
                    'translations' => [],
                ];
                foreach ($languages as $language) {
                    $item['translations'][$language] = $this->getValueForLanguage($translation, $sites, $language);
                }
                $groupName = (string)($translation['group'] ?? 'site');
                if (!isset($payload[$groupName])) {
                    $payload[$groupName] = [];
                }
                $payload[$groupName][$translation['key']] = $item;
            }

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return Craft::$app->getResponse()->sendContentAsFile($json, 'translations.json', [
                'mimeType' => 'application/json',
            ]);
        }

        $tmpFile = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations.csv';
        $handle = fopen($tmpFile, 'wb');
        if (!$handle) {
            throw new \RuntimeException('Unable to create CSV export.');
        }

        $header = ['key', 'group'];
        foreach ($languages as $language) {
            $header[] = $language;
        }
        fputcsv($handle, $header);

        foreach ($translations as $translation) {
            $row = [
                $translation['key'],
                $translation['group'],
            ];
            foreach ($languages as $language) {
                $row[] = $this->getValueForLanguage($translation, $sites, $language);
            }
            fputcsv($handle, $row);
        }

        fclose($handle);

        return Craft::$app->getResponse()->sendFile($tmpFile, 'translations.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Import requires Lite edition or higher.');
        }

        $request = Craft::$app->getRequest();
        $format = strtolower((string)$request->getBodyParam('format', 'csv'));

        if (in_array($format, ['json', 'php'], true) && !PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('JSON and PHP import require Pro edition.');
        }
        $file = \yii\web\UploadedFile::getInstanceByName('file');

        if (!$file) {
            throw new BadRequestHttpException('No file uploaded.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        $items = [];
        if ($format === 'json') {
            $raw = file_get_contents($file->tempName);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                throw new BadRequestHttpException('Invalid JSON payload.');
            }

            foreach ($data as $groupName => $groupItems) {
                if (!is_array($groupItems)) {
                    continue;
                }
                foreach ($groupItems as $key => $item) {
                    $values = [];
                    $translations = (array)($item['translations'] ?? []);
                    foreach ($translations as $language => $value) {
                        $values[$language] = (string)$value;
                    }
                    $items[] = [
                        'key' => (string)$key,
                        'group' => (string)$groupName ?: 'site',
                        'values' => $values,
                    ];
                }
            }
        } elseif ($format === 'php') {
            $zip = new \ZipArchive();
            if ($zip->open($file->tempName) !== true) {
                throw new BadRequestHttpException('Invalid ZIP file.');
            }

            $tmpDir = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations-import-' . uniqid();
            if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('Unable to create temp directory.');
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            $files = glob($tmpDir . '/translations/*/*.php');
            foreach ($files as $path) {
                $language = basename(dirname($path));
                $group = basename($path, '.php');
                PragmaticWebToolkit::$plugin->translations->ensureGroupExists($group);
                $map = include $path;
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $key => $value) {
                    $compound = $group . "\n" . (string)$key;
                    $items[$compound]['key'] = (string)$key;
                    $items[$compound]['values'][$language] = (string)$value;
                    $items[$compound]['group'] = $group;
                    $items[$compound]['preserveMeta'] = true;
                }
            }
        } else {
            $handle = fopen($file->tempName, 'rb');
            if (!$handle) {
                throw new BadRequestHttpException('Unable to read CSV file.');
            }

            $header = fgetcsv($handle);
            if (!$header) {
                throw new BadRequestHttpException('CSV file is empty.');
            }

            $columnMap = [];
            foreach ($header as $index => $column) {
                $column = trim((string)$column);
                $columnMap[$column] = $index;
            }

            while (($row = fgetcsv($handle)) !== false) {
                $key = trim((string)($row[$columnMap['key']] ?? ''));
                if ($key === '') {
                    continue;
                }

                $values = [];
                foreach ($columnMap as $column => $index) {
                    if ($column === 'key' || $column === 'group') {
                        continue;
                    }
                    $values[$column] = (string)($row[$index] ?? '');
                }

                $items[] = [
                    'key' => $key,
                    'group' => trim((string)($row[$columnMap['group']] ?? '')) ?: 'site',
                    'values' => $values,
                ];
            }

            fclose($handle);
        }

        $items = $this->expandLanguageValuesToSites($items, $languageMap);
        PragmaticWebToolkit::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations imported.');

        return $this->redirectToPostedUrl();
    }

    public function actionExportProjectPhp(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('PHP sync requires Pro edition.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $service = PragmaticWebToolkit::$plugin->translations;
        $allTranslations = $service->getAllTranslations();
        $groups = $service->getGroups();

        $rootPath = Craft::getAlias('@root', false);
        if (!is_string($rootPath) || $rootPath === '') {
            throw new \RuntimeException('Project root path is not available.');
        }
        $translationsPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'translations';
        if (!is_dir($translationsPath) && !mkdir($translationsPath, 0775, true) && !is_dir($translationsPath)) {
            throw new \RuntimeException('Unable to create project translations directory.');
        }

        $writtenFiles = 0;
        foreach ($languages as $language) {
            $languageDir = $translationsPath . DIRECTORY_SEPARATOR . $language;
            if (!is_dir($languageDir) && !mkdir($languageDir, 0775, true) && !is_dir($languageDir)) {
                throw new \RuntimeException('Unable to create language directory: ' . $language);
            }

            foreach ($groups as $group) {
                $map = [];
                foreach ($allTranslations as $translation) {
                    if (($translation['group'] ?? 'site') !== $group) {
                        continue;
                    }
                    $map[$translation['key']] = $this->getValueForLanguage($translation, $sites, $language);
                }

                $payload = "<?php\n\nreturn " . var_export($map, true) . ";\n";
                $targetFile = $languageDir . DIRECTORY_SEPARATOR . $group . '.php';
                if (file_put_contents($targetFile, $payload) === false) {
                    throw new \RuntimeException('Unable to write translation file: ' . $targetFile);
                }
                $writtenFiles++;
            }
        }

        Craft::$app->getSession()->setNotice("Project PHP translations updated ({$writtenFiles} files written).");
        return $this->redirectToPostedUrl();
    }

    public function actionImportProjectPhp(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('PHP sync requires Pro edition.');
        }

        $rootPath = Craft::getAlias('@root', false);
        if (!is_string($rootPath) || $rootPath === '') {
            throw new \RuntimeException('Project root path is not available.');
        }
        $translationsPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'translations';
        if (!is_dir($translationsPath)) {
            Craft::$app->getSession()->setNotice('Project translations folder does not exist.');
            return $this->redirectToPostedUrl();
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);
        $items = [];
        $files = glob($translationsPath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $path) {
            $language = basename(dirname($path));
            $group = basename($path, '.php');
            PragmaticWebToolkit::$plugin->translations->ensureGroupExists($group);
            $map = include $path;
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $key => $value) {
                $compound = $group . "\n" . (string)$key;
                $items[$compound]['key'] = (string)$key;
                $items[$compound]['values'][$language] = (string)$value;
                $items[$compound]['group'] = $group;
                $items[$compound]['preserveMeta'] = true;
            }
        }

        if (empty($items)) {
            Craft::$app->getSession()->setNotice('No PHP translations found in project folder.');
            return $this->redirectToPostedUrl();
        }

        $items = $this->expandLanguageValuesToSites($items, $languageMap);
        PragmaticWebToolkit::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice(sprintf('Imported project PHP translations (%d keys).', count($items)));

        return $this->redirectToPostedUrl();
    }

    public function actionScanTemplates(): Response
    {
        $this->requirePostRequest();

        $group = (string)Craft::$app->getRequest()->getBodyParam('group', 'site');
        $result = PragmaticWebToolkit::$plugin->translations->scanProjectTemplatesForTranslatableKeys($group);

        Craft::$app->getSession()->setNotice(sprintf(
            'Template scan complete. Scanned %d files, found %d keys, added %d new keys.',
            (int)$result['filesScanned'],
            (int)$result['keysFound'],
            (int)$result['keysAdded'],
        ));

        return $this->redirectToPostedUrl();
    }

    public function actionPreviewUnusedStatic(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = PragmaticWebToolkit::$plugin->translations;
        $request = Craft::$app->getRequest();
        $group = trim((string)$request->getBodyParam('group', ''));
        $activeGroups = $service->getActiveGroups();
        $allowedGroups = $group === '' ? $activeGroups : null;

        $result = $service->previewUnusedStaticTranslations($group !== '' ? $group : null, $allowedGroups);

        return $this->asJson([
            'success' => true,
            'result' => $result,
        ]);
    }

    public function actionDeleteUnusedStatic(): Response
    {
        $this->requirePostRequest();

        $service = PragmaticWebToolkit::$plugin->translations;
        $request = Craft::$app->getRequest();
        $group = trim((string)$request->getBodyParam('group', ''));
        $activeGroups = $service->getActiveGroups();
        $allowedGroups = $group === '' ? $activeGroups : null;

        $result = $service->deleteUnusedStaticTranslations($group !== '' ? $group : null, $allowedGroups);

        Craft::$app->getSession()->setNotice(sprintf(
            'Unused cleanup complete. Scanned %d files and deleted %d entries.',
            (int)$result['filesScanned'],
            (int)$result['deletedCount'],
        ));

        return $this->redirectToPostedUrl();
    }

    public function actionAutotranslateText(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->can('pragmatic-toolkit:translations-manage')) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'You do not have permission to use auto-translation.'),
            ]);
        }

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Auto-translation requires Pro edition.'),
            ]);
        }

        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        if (!$settings->enableAutotranslate) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Auto-translation is disabled in settings.'),
            ]);
        }

        $apiKey = $this->resolveGoogleApiKey((string)$settings->googleApiKeyEnv);
        if (empty($apiKey)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Google Translate API key is missing.'),
            ]);
        }

        $request = Craft::$app->getRequest();
        $texts = $request->getBodyParam('texts');
        $sourceLang = (string)$request->getBodyParam('sourceLang', '');
        $targetLang = (string)$request->getBodyParam('targetLang', '');
        $mimeType = (string)$request->getBodyParam('mimeType', 'text/plain');

        if (!is_array($texts) || $sourceLang === '' || $targetLang === '') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Missing required parameters.'),
            ]);
        }

        // Normalize site language codes (e.g. es-ES) using optional languageMap before calling provider API.
        $sourceLang = PragmaticWebToolkit::$plugin->googleTranslate->resolveLanguageCode($sourceLang);
        $targetLang = PragmaticWebToolkit::$plugin->googleTranslate->resolveLanguageCode($targetLang);

        if ($sourceLang === $targetLang) {
            return $this->asJson(['success' => true, 'translations' => $texts]);
        }

        $toTranslate = [];
        $indexMap = [];
        foreach ($texts as $index => $text) {
            if (trim((string)$text) !== '') {
                $indexMap[] = $index;
                $toTranslate[] = (string)$text;
            }
        }

        if (empty($toTranslate)) {
            return $this->asJson(['success' => true, 'translations' => $texts]);
        }

        try {
            $translated = PragmaticWebToolkit::$plugin->googleTranslate->translateBatch($toTranslate, $sourceLang, $targetLang, $mimeType);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $results = $texts;
        foreach ($indexMap as $translatedIndex => $originalIndex) {
            $results[$originalIndex] = $translated[$translatedIndex] ?? $texts[$originalIndex];
        }

        return $this->asJson(['success' => true, 'translations' => array_values($results)]);
    }

    public function actionAutotranslate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        [$autotranslateAvailable, $autotranslateDisabledReason] = $this->getAutotranslateAvailabilityState();
        if (!$autotranslateAvailable) {
            return $this->asJson([
                'success' => false,
                'error' => $autotranslateDisabledReason,
            ]);
        }

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getBodyParam('entryId');
        $fieldHandle = trim((string)$request->getBodyParam('fieldHandle', ''));
        $sourceSiteId = (int)$request->getBodyParam('sourceSiteId');
        $targetSiteId = (int)$request->getBodyParam('targetSiteId');

        if ($entryId <= 0 || $fieldHandle === '' || $sourceSiteId <= 0 || $targetSiteId <= 0) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Missing required parameters.'),
            ]);
        }

        $sourceEntry = $this->resolveEntryForSite($entryId, $sourceSiteId);
        if (!$sourceEntry instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Source entry not found for selected site.'),
            ]);
        }

        try {
            $sourceText = $fieldHandle === 'title'
                ? (string)$sourceEntry->title
                : (string)$sourceEntry->getFieldValue($fieldHandle);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }

        if (trim($sourceText) === '') {
            return $this->asJson(['success' => true, 'text' => '']);
        }

        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        $targetSite = Craft::$app->getSites()->getSiteById($targetSiteId);
        if (!$sourceSite || !$targetSite) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Invalid source or target site.'),
            ]);
        }

        $sourceLang = PragmaticWebToolkit::$plugin->googleTranslate->resolveLanguageCode((string)$sourceSite->language);
        $targetLang = PragmaticWebToolkit::$plugin->googleTranslate->resolveLanguageCode((string)$targetSite->language);

        if ($sourceLang === $targetLang) {
            return $this->asJson(['success' => true, 'text' => $sourceText]);
        }

        $mimeType = 'text/plain';
        if ($fieldHandle !== 'title') {
            $field = $sourceEntry->getFieldLayout()?->getFieldByHandle($fieldHandle);
            if ($field && get_class($field) === 'craft\\ckeditor\\Field') {
                $mimeType = 'text/html';
            }
        }

        try {
            $translated = PragmaticWebToolkit::$plugin->googleTranslate->translate($sourceText, $sourceLang, $targetLang, $mimeType);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->asJson(['success' => true, 'text' => $translated]);
    }

    public function actionAutotranslateSources(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        [$autotranslateAvailable, $autotranslateDisabledReason] = $this->getAutotranslateAvailabilityState();
        if (!$autotranslateAvailable) {
            return $this->asJson([
                'success' => false,
                'error' => $autotranslateDisabledReason,
            ]);
        }

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getBodyParam('entryId');
        $targetSiteId = (int)$request->getBodyParam('targetSiteId');
        if ($entryId <= 0 || $targetSiteId <= 0) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('pragmatic-web-toolkit', 'Missing required parameters.'),
            ]);
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $available = [];
        foreach ($sites as $site) {
            if ((int)$site->id === $targetSiteId) {
                continue;
            }
            $entry = $this->resolveEntryForSite($entryId, (int)$site->id);
            if (!$entry instanceof Entry) {
                continue;
            }
            $available[] = [
                'id' => (int)$site->id,
                'name' => (string)$site->name,
                'handle' => (string)$site->handle,
                'language' => (string)$site->language,
            ];
        }

        return $this->asJson([
            'success' => true,
            'sites' => $available,
        ]);
    }

    public function actionSaveGroups(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('Translation groups require Lite edition or higher.');
        }

        $items = Craft::$app->getRequest()->getBodyParam('groups', []);
        if (!is_array($items)) {
            throw new BadRequestHttpException('Invalid groups payload.');
        }

        PragmaticWebToolkit::$plugin->translations->saveGroups($items);
        Craft::$app->getSession()->setNotice('Groups saved.');

        return $this->redirectToPostedUrl();
    }

    private function exportPhp(array $sites, $service): Response
    {
        $zipPath = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations-php.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create PHP export.');
        }

        $languages = $this->getLanguages($sites);
        $groups = $service->getGroups();
        $allTranslations = $service->getAllTranslations();

        foreach ($languages as $language) {
            foreach ($groups as $group) {
                $map = [];
                foreach ($allTranslations as $translation) {
                    if (($translation['group'] ?? 'site') !== $group) {
                        continue;
                    }
                    $map[$translation['key']] = $this->getValueForLanguage($translation, $sites, $language);
                }

                $export = "<?php\n\nreturn " . var_export($map, true) . ";\n";
                $zip->addFromString('translations/' . $language . '/' . $group . '.php', $export);
            }
        }

        $zip->close();

        return Craft::$app->getResponse()->sendFile($zipPath, 'translations-php.zip', [
            'mimeType' => 'application/zip',
        ]);
    }

    private function getLanguages(array $sites): array
    {
        $languages = [];
        foreach ($sites as $site) {
            $languages[$site->language] = true;
        }

        $languages = array_keys($languages);
        sort($languages);

        return $languages;
    }

    private function resolveEntryForSite(int $entryId, int $siteId): ?Entry
    {
        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->revisions(null)
            ->trashed(null)
            ->unique(false)
            ->one();
        if ($entry instanceof Entry) {
            return $entry;
        }

        $baseElement = Craft::$app->getElements()->getElementById($entryId);
        if (!$baseElement instanceof Entry) {
            return null;
        }

        $canonicalId = (int)($baseElement->canonicalId ?: $baseElement->id);
        if ($canonicalId <= 0) {
            return null;
        }

        $entry = Entry::find()
            ->canonicalId($canonicalId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->revisions(null)
            ->trashed(null)
            ->unique(false)
            ->one();

        return $entry instanceof Entry ? $entry : null;
    }

    private function getAutotranslateAvailabilityState(): array
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->can('pragmatic-toolkit:translations-manage')) {
            return [false, Craft::t('pragmatic-web-toolkit', 'You do not have permission to use auto-translation.')];
        }
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return [false, Craft::t('pragmatic-web-toolkit', 'Auto-translation requires Pro edition.')];
        }

        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();
        if (!$settings->enableAutotranslate) {
            return [false, Craft::t('pragmatic-web-toolkit', 'Auto-translation is disabled in settings.')];
        }

        $apiKey = $this->resolveGoogleApiKey((string)$settings->googleApiKeyEnv);
        if (empty($apiKey)) {
            return [false, Craft::t('pragmatic-web-toolkit', 'Google Translate API key is missing.')];
        }

        return [true, ''];
    }

    private function resolveGoogleApiKey(string $envReference): string
    {
        $reference = trim($envReference);
        if ($reference === '') {
            return '';
        }

        $parsed = \craft\helpers\App::parseEnv($reference);
        if (is_string($parsed) && $parsed !== '' && $parsed !== $reference) {
            return trim($parsed);
        }

        $normalized = ltrim($reference, '$');
        $resolved = \craft\helpers\App::env($normalized);
        if (!is_string($resolved)) {
            return '';
        }

        return trim($resolved);
    }

    private function getLanguageMap(array $sites): array
    {
        $map = [];
        foreach ($sites as $site) {
            $map[$site->language][] = $site->id;
        }

        return $map;
    }

    private function getEntryFieldOptions(): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('app', 'All')],
            ['value' => 'title', 'label' => Craft::t('app', 'Title')],
        ];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($this->isMatrixField($field)) {
                foreach ($this->getEligibleMatrixSubFields($field) as $subField) {
                    $value = $this->buildMatrixFieldFilter((string)$field->handle, (string)$subField->handle);
                    $options[] = ['value' => $value, 'label' => sprintf('%s: %s', (string)$field->name, (string)$subField->name)];
                }
                continue;
            }
            if (!$this->isEligibleTranslatableField($field)) {
                continue;
            }
            if ($this->isLinkLikeField($field)) {
                $options[] = [
                    'value' => $this->buildLinkFieldHandle((string)$field->handle, 'value'),
                    'label' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'Link Value')),
                ];
                $options[] = [
                    'value' => $this->buildLinkFieldHandle((string)$field->handle, 'label'),
                    'label' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'Link Label')),
                ];
                continue;
            }
            $options[] = ['value' => $field->handle, 'label' => $field->name];
        }

        return $options;
    }

    private function saveElementFieldValues(string $elementType, int $elementId, string $fieldHandle, array $values): array
    {
        if ($elementType === 'globalSet') {
            return $this->saveGlobalSetFieldValues($elementId, $fieldHandle, $values);
        }

        return $this->saveEntryFieldValues($elementId, $fieldHandle, $values);
    }

    private function saveEntryFieldValues(int $entryId, string $fieldHandle, array $values): array
    {
        $result = [
            'saved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'skipReasons' => [],
        ];
        $linkHandleData = $this->parseLinkFieldHandle($fieldHandle);
        $matrixHandleData = $this->parseMatrixFieldHandle($fieldHandle);
        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        foreach ($values as $language => $value) {
            if (!isset($languageMap[$language])) {
                $result['skipped']++;
                $this->addSkipReason($result, sprintf('No sites mapped for language "%s".', (string)$language));
                continue;
            }
            foreach ($languageMap[$language] as $siteId) {
                $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
                if (!$entry) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Entry %d not found for site %d.', $entryId, (int)$siteId));
                    continue;
                }
                if ($linkHandleData) {
                    [$linkFieldHandle, $linkPart] = $linkHandleData;
                    $section = $entry->getSection();
                    if (!$section || !$this->isSectionActiveForSite($section, (int)$siteId)) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Entry %d section is not active for site %d.', $entryId, (int)$siteId));
                        continue;
                    }
                    try {
                        $current = $entry->getFieldValue($linkFieldHandle);
                        $field = $entry->getFieldLayout()?->getFieldByHandle($linkFieldHandle);
                        $patched = $this->patchLinkFieldValueByField($field, $current, $linkPart, (string)$value, $entry);
                        $entry->setFieldValue($linkFieldHandle, $patched);
                        $savedOk = Craft::$app->getElements()->saveElement($entry, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($entry, sprintf('field %s', $linkFieldHandle));
                            Craft::warning(
                                sprintf(
                                    'Link save returned false for entryId=%d siteId=%d field=%s part=%s',
                                    $entryId,
                                    (int)$siteId,
                                    $linkFieldHandle,
                                    $linkPart
                                ),
                                __METHOD__
                            );
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                        Craft::warning(
                            sprintf(
                                'Skipping link save for entryId=%d siteId=%d field=%s part=%s: %s',
                                $entryId,
                                (int)$siteId,
                                $linkFieldHandle,
                                $linkPart,
                                $e->getMessage()
                            ),
                            __METHOD__
                        );
                    }
                    continue;
                }
                if ($matrixHandleData) {
                    [$matrixHandle, $blockIndex, $subFieldHandle] = $matrixHandleData;
                    $blocks = $this->getMatrixBlocksForElement($entry, $matrixHandle);
                    $block = $blocks[$blockIndex] ?? null;
                    if (!$block) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix block %d not found for field "%s".', (int)$blockIndex, $matrixHandle));
                        continue;
                    }
                    if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix subfield "%s" not found in block %d.', $subFieldHandle, (int)$blockIndex));
                        continue;
                    }
                    try {
                        $block->setFieldValue($subFieldHandle, (string)$value);
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($block, sprintf('field %s', $subFieldHandle));
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                        Craft::warning(
                            sprintf(
                                'Skipping matrix block save for entryId=%d siteId=%d matrix=%s blockIndex=%d subField=%s: %s',
                                $entryId,
                                (int)$siteId,
                                $matrixHandle,
                                (int)$blockIndex,
                                $subFieldHandle,
                                $e->getMessage()
                            ),
                            __METHOD__
                        );
                    }
                    continue;
                }
                $section = $entry->getSection();
                if (!$section || !$this->isSectionActiveForSite($section, (int)$siteId)) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Entry %d section is not active for site %d.', $entryId, (int)$siteId));
                    continue;
                }
                try {
                    if ($fieldHandle === 'title') {
                        $entry->title = (string)$value;
                    } else {
                        $entry->setFieldValue($fieldHandle, (string)$value);
                    }
                    $savedOk = Craft::$app->getElements()->saveElement($entry, false, false);
                    if ($savedOk) {
                        $result['saved']++;
                    } else {
                        $result['failed']++;
                        $result['errors'][] = $this->buildElementSaveError($entry, sprintf('field %s', $fieldHandle));
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = $e->getMessage();
                    Craft::warning(
                        sprintf(
                            'Skipping entry save for entryId=%d siteId=%d field=%s: %s',
                            $entryId,
                            (int)$siteId,
                            $fieldHandle,
                            $e->getMessage()
                        ),
                        __METHOD__
                    );
                }
            }
        }

        return $result;
    }

    private function getEntryFieldValueForHandle(Entry $entry, string $fieldHandle): string
    {
        return $this->getElementFieldValueForHandle($entry, $fieldHandle);
    }

    private function isEligibleTranslatableField(mixed $field, string $fieldFilter = ''): bool
    {
        $className = get_class($field);
        $isLinkLike = $this->isLinkLikeField($field);
        $isEligibleType = ($field instanceof PlainText) || ($className === 'craft\\ckeditor\\Field') || $isLinkLike;
        if (!$isEligibleType) {
            return false;
        }

        if ($field->translationMethod === \craft\base\Field::TRANSLATION_METHOD_NONE) {
            return false;
        }

        if ($fieldFilter !== '' && $fieldFilter !== 'title') {
            if ($isLinkLike) {
                $linkData = $this->parseLinkFieldHandle($fieldFilter);
                if (!$linkData || $linkData[0] !== (string)$field->handle) {
                    return false;
                }
            } elseif ($field->handle !== $fieldFilter) {
                return false;
            }
        }

        return true;
    }

    private function isMatrixField(mixed $field): bool
    {
        return $field instanceof Matrix;
    }

    private function getEligibleMatrixSubFields(Matrix $matrixField, string $fieldFilter = ''): array
    {
        $eligible = [];
        foreach ($matrixField->getEntryTypes() as $entryType) {
            foreach ($entryType->getCustomFields() as $subField) {
                if (!$this->isEligibleTranslatableField($subField)) {
                    continue;
                }
                if ($fieldFilter !== '' && $fieldFilter !== 'title') {
                    $filterValue = $this->buildMatrixFieldFilter((string)$matrixField->handle, (string)$subField->handle);
                    if ($fieldFilter !== $filterValue) {
                        continue;
                    }
                }
                $eligible[(string)$subField->handle] = $subField;
            }
        }

        return array_values($eligible);
    }

    private function getMatrixBlocksForElement(mixed $element, string $matrixHandle): array
    {
        if (!is_object($element) || !method_exists($element, 'getFieldValue')) {
            return [];
        }

        $value = $element->getFieldValue($matrixHandle);
        if ($value instanceof \craft\elements\db\EntryQuery) {
            return $value->all();
        }
        if (is_iterable($value)) {
            return is_array($value) ? $value : iterator_to_array($value);
        }

        return [];
    }

    private function getElementFieldValueForHandle(mixed $element, string $fieldHandle): string
    {
        try {
            if ($fieldHandle === 'title' && $element instanceof Entry) {
                return (string)$element->title;
            }
            if (!is_object($element) || !method_exists($element, 'getFieldValue')) {
                return '';
            }
            $linkHandleData = $this->parseLinkFieldHandle($fieldHandle);
            if ($linkHandleData) {
                [$linkFieldHandle, $linkPart] = $linkHandleData;
                return $this->extractLinkFieldPart($element->getFieldValue($linkFieldHandle), $linkPart);
            }
            $matrixHandleData = $this->parseMatrixFieldHandle($fieldHandle);
            if (!$matrixHandleData) {
                return $this->stringifyFieldValue($element->getFieldValue($fieldHandle));
            }
            [$matrixHandle, $blockIndex, $subFieldHandle] = $matrixHandleData;
            $blocks = $this->getMatrixBlocksForElement($element, $matrixHandle);
            $block = $blocks[$blockIndex] ?? null;
            if (!$block || !method_exists($block, 'getFieldValue')) {
                return '';
            }
            if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                return '';
            }

            return $this->stringifyFieldValue($block->getFieldValue($subFieldHandle));
        } catch (\Throwable $e) {
            $elementId = is_object($element) && isset($element->id) ? (int)$element->id : 0;
            Craft::warning(
                sprintf(
                    'Unable to read element field value elementId=%d fieldHandle=%s: %s',
                    $elementId,
                    $fieldHandle,
                    $e->getMessage()
                ),
                __METHOD__
            );
            return '';
        }
    }

    private function stringifyFieldValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }
        if (is_object($value) && method_exists($value, 'getUrl')) {
            $url = $value->getUrl();
            return is_string($url) ? $url : '';
        }
        if (is_array($value)) {
            $url = $value['url'] ?? $value['value'] ?? $value['link'] ?? null;
            if (is_scalar($url)) {
                return (string)$url;
            }
        }

        return '';
    }

    private function extractLinkFieldPart(mixed $value, string $part): string
    {
        if (is_array($value)) {
            if ($part === 'label') {
                foreach (['label', 'text', 'title', 'linkText'] as $key) {
                    if (isset($value[$key]) && is_scalar($value[$key])) {
                        return (string)$value[$key];
                    }
                }
            } else {
                foreach (['value', 'url', 'href', 'link'] as $key) {
                    if (isset($value[$key]) && is_scalar($value[$key])) {
                        return (string)$value[$key];
                    }
                }
            }

            return '';
        }

        if (is_object($value)) {
            $methods = $part === 'label'
                ? ['getLabel', 'getText', 'getLinkText']
                : ['getValue', 'getUrl', 'getHref'];
            foreach ($methods as $method) {
                if (method_exists($value, $method)) {
                    $result = $value->{$method}();
                    if (is_scalar($result)) {
                        return (string)$result;
                    }
                }
            }
            $propertyCandidates = $part === 'label'
                ? ['label', 'text', 'title', 'linkText']
                : ['value', 'url', 'href', 'link'];
            foreach ($propertyCandidates as $property) {
                if (isset($value->{$property}) && is_scalar($value->{$property})) {
                    return (string)$value->{$property};
                }
            }
            if (method_exists($value, 'toArray')) {
                $asArray = $value->toArray();
                if (is_array($asArray)) {
                    return $this->extractLinkFieldPart($asArray, $part);
                }
            }
        }

        if ($part === 'value' && is_scalar($value)) {
            return (string)$value;
        }

        return '';
    }

    private function applyLinkFieldPart(mixed $currentValue, string $part, string $newValue): mixed
    {
        if (is_object($currentValue)) {
            if ($part === 'label') {
                foreach (['setLabel', 'setText', 'setLinkText'] as $setter) {
                    if (method_exists($currentValue, $setter)) {
                        $currentValue->{$setter}($newValue);
                        return $currentValue;
                    }
                }
                foreach (['label', 'text', 'title', 'linkText'] as $property) {
                    if (property_exists($currentValue, $property)) {
                        $currentValue->{$property} = $newValue;
                        return $currentValue;
                    }
                }
            } else {
                foreach (['setValue', 'setUrl', 'setHref'] as $setter) {
                    if (method_exists($currentValue, $setter)) {
                        $currentValue->{$setter}($newValue);
                        return $currentValue;
                    }
                }
                foreach (['value', 'url', 'href', 'link'] as $property) {
                    if (property_exists($currentValue, $property)) {
                        $currentValue->{$property} = $newValue;
                        return $currentValue;
                    }
                }
            }
        }

        $data = [];
        if (is_array($currentValue)) {
            $data = $currentValue;
        } elseif (is_object($currentValue) && method_exists($currentValue, 'toArray')) {
            $asArray = $currentValue->toArray();
            if (is_array($asArray)) {
                $data = $asArray;
            }
        }

        if (!empty($data)) {
            $updated = $this->mutateLinkPartInArray($data, $part, $newValue);
            if ($updated !== null) {
                return $updated;
            }
        }

        if ($part === 'label') {
            $data['type'] = $data['type'] ?? 'url';
            $data['label'] = $newValue;
            $data['text'] = $newValue;
            $data['linkText'] = $newValue;
            $data['title'] = $newValue;
            $data['linkTitle'] = $newValue;
        } else {
            $data['type'] = $data['type'] ?? 'url';
            $data['value'] = $newValue;
            $data['url'] = $newValue;
            $data['href'] = $newValue;
            $data['link'] = $newValue;
        }

        return $data;
    }

    private function patchLinkFieldValueByField(mixed $field, mixed $current, string $part, string $newValue, mixed $element): mixed
    {
        $patched = $this->applyLinkFieldPart($current, $part, $newValue);
        if (!$field) {
            return $patched;
        }

        if (method_exists($field, 'serializeValue')) {
            try {
                $serialized = $field->serializeValue($current, $element);
                $patchedSerialized = $this->applyLinkFieldPart($serialized, $part, $newValue);
                if (is_array($serialized) && is_array($patchedSerialized) && isset($serialized['type']) && !isset($patchedSerialized['type'])) {
                    $patchedSerialized['type'] = $serialized['type'];
                }
                $patched = $patchedSerialized;
            } catch (\Throwable) {
                // Fallback to current-value mutation.
            }
        }

        if (method_exists($field, 'normalizeValue')) {
            try {
                return $field->normalizeValue($patched, $element);
            } catch (\Throwable) {
                return $patched;
            }
        }

        return $patched;
    }

    private function mutateLinkPartInArray(array $data, string $part, string $newValue): ?array
    {
        $keys = $part === 'label'
            ? ['label', 'text', 'title', 'linkText']
            : ['value', 'url', 'href', 'link'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $newValue;
                return $data;
            }
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $nested = $this->mutateLinkPartInArray($v, $part, $newValue);
                if ($nested !== null) {
                    $data[$k] = $nested;
                    return $data;
                }
                continue;
            }
            if (is_object($v)) {
                $patched = $this->applyLinkFieldPart($v, $part, $newValue);
                $data[$k] = $patched;
                return $data;
            }
        }

        return null;
    }

    private function matrixBlockHasSubField(mixed $block, string $subFieldHandle): bool
    {
        if (!is_object($block) || !method_exists($block, 'getFieldLayout')) {
            return false;
        }

        try {
            $layout = $block->getFieldLayout();
            if (!$layout || !method_exists($layout, 'getFieldByHandle')) {
                return false;
            }

            return $layout->getFieldByHandle($subFieldHandle) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildMatrixFieldHandle(string $matrixHandle, int $blockIndex, string $subFieldHandle): string
    {
        return sprintf('matrix::%s::%d::%s', $matrixHandle, $blockIndex, $subFieldHandle);
    }

    private function buildMatrixFieldFilter(string $matrixHandle, string $subFieldHandle): string
    {
        return sprintf('matrix::%s::%s', $matrixHandle, $subFieldHandle);
    }

    private function parseMatrixFieldHandle(string $fieldHandle): ?array
    {
        if (!str_starts_with($fieldHandle, 'matrix::')) {
            return null;
        }
        $parts = explode('::', $fieldHandle);
        if (count($parts) !== 4) {
            return null;
        }

        return [$parts[1], (int)$parts[2], $parts[3]];
    }

    private function isLinkLikeField(mixed $field): bool
    {
        return str_contains(strtolower(get_class($field)), 'link');
    }

    private function buildLinkFieldHandle(string $fieldHandle, string $part): string
    {
        return sprintf('linkfield::%s::%s', $fieldHandle, $part);
    }

    private function parseLinkFieldHandle(string $fieldHandle): ?array
    {
        if (!str_starts_with($fieldHandle, 'linkfield::')) {
            return null;
        }
        $parts = explode('::', $fieldHandle);
        if (count($parts) !== 3) {
            return null;
        }
        if (!in_array($parts[2], ['value', 'label'], true)) {
            return null;
        }

        return [$parts[1], $parts[2]];
    }

    private function appendElementRows(array &$rows, mixed $element, string $elementType, string $fieldFilter, bool $includeTitle): void
    {
        if (!is_object($element) || !method_exists($element, 'getFieldLayout')) {
            return;
        }
        $layout = $element->getFieldLayout();
        $fields = $layout ? $layout->getCustomFields() : [];

        $eligibleFields = [];
        $matrixFields = [];
        foreach ($fields as $field) {
            if ($this->isMatrixField($field)) {
                $matrixFields[] = $field;
                continue;
            }
            if (!$this->isEligibleTranslatableField($field, $fieldFilter)) {
                continue;
            }
            $eligibleFields[] = $field;
        }

        $elementId = (int)($element->id ?? 0);
        $elementKey = $elementType . ':' . $elementId;
        if ($includeTitle && ($fieldFilter === '' || $fieldFilter === 'title')) {
            $rows[] = [
                'elementType' => $elementType,
                'elementId' => $elementId,
                'elementKey' => $elementKey,
                'element' => $element,
                'fieldHandle' => 'title',
                'fieldLabel' => Craft::t('app', 'Title'),
            ];
        }

        foreach ($eligibleFields as $field) {
            if ($this->isLinkLikeField($field)) {
                $rows[] = [
                    'elementType' => $elementType,
                    'elementId' => $elementId,
                    'elementKey' => $elementKey,
                    'element' => $element,
                    'fieldHandle' => $this->buildLinkFieldHandle((string)$field->handle, 'value'),
                    'fieldLabel' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'Link Value')),
                ];
                $rows[] = [
                    'elementType' => $elementType,
                    'elementId' => $elementId,
                    'elementKey' => $elementKey,
                    'element' => $element,
                    'fieldHandle' => $this->buildLinkFieldHandle((string)$field->handle, 'label'),
                    'fieldLabel' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'Link Label')),
                ];
                continue;
            }
            $rows[] = [
                'elementType' => $elementType,
                'elementId' => $elementId,
                'elementKey' => $elementKey,
                'element' => $element,
                'fieldHandle' => (string)$field->handle,
                'fieldLabel' => (string)$field->name,
            ];
        }

        foreach ($matrixFields as $matrixField) {
            $blocks = $this->getMatrixBlocksForElement($element, (string)$matrixField->handle);
            if (empty($blocks)) {
                continue;
            }
            $subFields = $this->getEligibleMatrixSubFields($matrixField, $fieldFilter);
            if (empty($subFields)) {
                continue;
            }

            foreach ($blocks as $blockIndex => $block) {
                foreach ($subFields as $subField) {
                    if (!$this->matrixBlockHasSubField($block, (string)$subField->handle)) {
                        continue;
                    }
                    $rows[] = [
                        'elementType' => $elementType,
                        'elementId' => $elementId,
                        'elementKey' => $elementKey,
                        'element' => $element,
                        'fieldHandle' => $this->buildMatrixFieldHandle((string)$matrixField->handle, (int)$blockIndex, (string)$subField->handle),
                        'fieldLabel' => sprintf('%s #%d: %s', (string)$matrixField->name, $blockIndex + 1, (string)$subField->name),
                    ];
                }
            }
        }
    }

    private function saveGlobalSetFieldValues(int $globalSetId, string $fieldHandle, array $values): array
    {
        $result = [
            'saved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'skipReasons' => [],
        ];
        $linkHandleData = $this->parseLinkFieldHandle($fieldHandle);
        $matrixHandleData = $this->parseMatrixFieldHandle($fieldHandle);
        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        foreach ($values as $language => $value) {
            if (!isset($languageMap[$language])) {
                $result['skipped']++;
                $this->addSkipReason($result, sprintf('No sites mapped for language "%s".', (string)$language));
                continue;
            }
            foreach ($languageMap[$language] as $siteId) {
                $globalSet = Craft::$app->getElements()->getElementById($globalSetId, GlobalSet::class, $siteId);
                if (!$globalSet instanceof GlobalSet) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Global set %d not found for site %d.', $globalSetId, (int)$siteId));
                    continue;
                }
                if ($linkHandleData) {
                    [$linkFieldHandle, $linkPart] = $linkHandleData;
                    try {
                        $current = $globalSet->getFieldValue($linkFieldHandle);
                        $field = $globalSet->getFieldLayout()?->getFieldByHandle($linkFieldHandle);
                        $patched = $this->patchLinkFieldValueByField($field, $current, $linkPart, (string)$value, $globalSet);
                        $globalSet->setFieldValue($linkFieldHandle, $patched);
                        $savedOk = Craft::$app->getElements()->saveElement($globalSet, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($globalSet, sprintf('field %s', $linkFieldHandle));
                            Craft::warning(
                                sprintf(
                                    'Link save returned false for globalSetId=%d siteId=%d field=%s part=%s',
                                    $globalSetId,
                                    (int)$siteId,
                                    $linkFieldHandle,
                                    $linkPart
                                ),
                                __METHOD__
                            );
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                    }
                    continue;
                }
                if ($matrixHandleData) {
                    [$matrixHandle, $blockIndex, $subFieldHandle] = $matrixHandleData;
                    $blocks = $this->getMatrixBlocksForElement($globalSet, $matrixHandle);
                    $block = $blocks[$blockIndex] ?? null;
                    if (!$block || !$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix subfield "%s" not found in block %d.', $subFieldHandle, (int)$blockIndex));
                        continue;
                    }
                    try {
                        $block->setFieldValue($subFieldHandle, (string)$value);
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($block, sprintf('field %s', $subFieldHandle));
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                    }
                    continue;
                }
                try {
                    $globalSet->setFieldValue($fieldHandle, (string)$value);
                    $savedOk = Craft::$app->getElements()->saveElement($globalSet, false, false);
                    if ($savedOk) {
                        $result['saved']++;
                    } else {
                        $result['failed']++;
                        $result['errors'][] = $this->buildElementSaveError($globalSet, sprintf('field %s', $fieldHandle));
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    private function redirectEntriesIndexWithCurrentFilters(): Response
    {
        $request = Craft::$app->getRequest();
        $params = [
            'q' => (string)$request->getBodyParam('q', $request->getQueryParam('q', '')),
            'perPage' => (int)$request->getBodyParam('perPage', $request->getQueryParam('perPage', 50)),
            'page' => (int)$request->getBodyParam('page', $request->getQueryParam('page', 1)),
            'section' => (int)$request->getBodyParam('section', $request->getQueryParam('section', 0)),
            'field' => (string)$request->getBodyParam('field', $request->getQueryParam('field', '')),
            'site' => (string)$request->getBodyParam('site', $request->getQueryParam('site', '')),
        ];

        if ($params['site'] === '') {
            $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
            $params['site'] = (string)$selectedSite->handle;
        }

        return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/translations/entries', $params));
    }

    private function buildElementSaveError(mixed $element, string $context): string
    {
        if (is_object($element) && method_exists($element, 'getFirstErrors')) {
            $firstErrors = $element->getFirstErrors();
            if (is_array($firstErrors) && !empty($firstErrors)) {
                $firstMessage = reset($firstErrors);
                if (is_string($firstMessage) && $firstMessage !== '') {
                    return sprintf('%s: %s', $context, $firstMessage);
                }
            }
        }

        return sprintf('%s: %s', $context, Craft::t('pragmatic-web-toolkit', 'Could not save element.'));
    }

    private function addSkipReason(array &$result, string $reason): void
    {
        if (!isset($result['skipReasons']) || !is_array($result['skipReasons'])) {
            $result['skipReasons'] = [];
        }
        if (count($result['skipReasons']) >= 5) {
            return;
        }
        if (!in_array($reason, $result['skipReasons'], true)) {
            $result['skipReasons'][] = $reason;
        }
    }

    private function buildNoValuesSavedMessage(array $result): string
    {
        $saved = (int)($result['saved'] ?? 0);
        $skipped = (int)($result['skipped'] ?? 0);
        $failed = (int)($result['failed'] ?? 0);
        $base = Craft::t('pragmatic-web-toolkit', 'No values were saved.');
        $summary = sprintf(' Saved: %d, skipped: %d, failed: %d.', $saved, $skipped, $failed);
        $firstSkip = (isset($result['skipReasons'][0]) && is_string($result['skipReasons'][0])) ? $result['skipReasons'][0] : '';
        if ($firstSkip !== '') {
            return $base . $summary . ' ' . $firstSkip;
        }

        return $base . $summary;
    }

    private function entryHasEligibleTranslatableFields(Entry $entry, string $fieldFilter = ''): bool
    {
        if ($fieldFilter === '' || $fieldFilter === 'title') {
            return true;
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isMatrixField($field)) {
                if (!empty($this->getEligibleMatrixSubFields($field, $fieldFilter))) {
                    return true;
                }
                continue;
            }
            if ($this->isEligibleTranslatableField($field, $fieldFilter)) {
                return true;
            }
        }

        return false;
    }

    private function getEntrySectionsForSite(int $siteId, string $fieldFilter = ''): array
    {
        $sectionCounts = [];
        $entries = Entry::find()->siteId($siteId)->status(null)->all();

        foreach ($entries as $entry) {
            if (!$this->entryHasEligibleTranslatableFields($entry, $fieldFilter)) {
                continue;
            }

            $section = $entry->getSection();
            if (!$section) {
                continue;
            }

            $id = (int)$section->id;
            $sectionCounts[$id] = ($sectionCounts[$id] ?? 0) + 1;
        }

        $rows = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$this->isSectionActiveForSite($section, $siteId)) {
                continue;
            }

            $id = (int)$section->id;
            $rows[$id] = ['id' => $id, 'name' => $section->name, 'count' => $sectionCounts[$id] ?? 0];
        }

        usort($rows, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return array_values($rows);
    }

    private function isSectionAvailableForSite(int $sectionId, int $siteId): bool
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        return $this->isSectionActiveForSite($section, $siteId);
    }

    private function isSectionActiveForSite(mixed $section, int $siteId): bool
    {
        if (!$section || !method_exists($section, 'getSiteSettings')) {
            return false;
        }

        $allSettings = $section->getSiteSettings();
        if (!is_array($allSettings) || empty($allSettings)) {
            return false;
        }

        if (isset($allSettings[$siteId])) {
            return true;
        }

        foreach ($allSettings as $setting) {
            if ((int)($setting->siteId ?? 0) === $siteId) {
                return true;
            }
        }

        return false;
    }

    private function expandLanguageValuesToSites(array $items, array $languageMap): array
    {
        foreach ($items as &$item) {
            if (!isset($item['values']) || !is_array($item['values'])) {
                continue;
            }

            $valuesBySite = [];
            foreach ($item['values'] as $language => $value) {
                if (!isset($languageMap[$language])) {
                    continue;
                }
                foreach ($languageMap[$language] as $siteId) {
                    $valuesBySite[$siteId] = $value;
                }
            }
            $item['values'] = $valuesBySite;
        }
        unset($item);

        return $items;
    }

    private function getValueForLanguage(array $translation, array $sites, string $language): string
    {
        foreach ($sites as $site) {
            if ($site->language !== $language) {
                continue;
            }
            if (isset($translation['values'][$site->id])) {
                return (string)$translation['values'][$site->id];
            }
        }

        return '';
    }
}

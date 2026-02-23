<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Entry;
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

        $rows = [];
        foreach ($entries as $entry) {
            $layout = $entry->getFieldLayout();
            $fields = $layout ? $layout->getCustomFields() : [];

            $eligibleFields = [];
            foreach ($fields as $field) {
                if (!$this->isEligibleTranslatableField($field, $fieldFilter)) {
                    continue;
                }
                $eligibleFields[] = $field;
            }

            if ($fieldFilter === '' || $fieldFilter === 'title') {
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => 'title',
                    'fieldLabel' => Craft::t('app', 'Title'),
                ];
            }

            foreach ($eligibleFields as $field) {
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                ];
            }
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $entryIds = array_unique(array_map(static fn(array $row): int => (int)$row['entry']->id, $pageRows));
        $siteEntries = [];
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
            }
        }

        foreach ($pageRows as &$row) {
            $row['values'] = [];
            foreach ($languageMap as $lang => $siteIds) {
                $value = '';
                foreach ($siteIds as $siteId) {
                    if (isset($siteEntries[$siteId][$row['entry']->id])) {
                        $entry = $siteEntries[$siteId][$row['entry']->id];
                        $value = $row['fieldHandle'] === 'title'
                            ? (string)$entry->title
                            : (string)$entry->getFieldValue($row['fieldHandle']);
                        break;
                    }
                }
                $row['values'][$lang] = $value;
            }
        }
        unset($row);

        $entryRowCounts = [];
        foreach ($pageRows as $row) {
            $entryId = (int)$row['entry']->id;
            $entryRowCounts[$entryId] = ($entryRowCounts[$entryId] ?? 0) + 1;
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
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);

        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        foreach ($values as $language => $value) {
            if (!isset($languageMap[$language])) {
                continue;
            }
            foreach ($languageMap[$language] as $siteId) {
                $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
                if (!$entry) {
                    continue;
                }
                if ($fieldHandle === 'title') {
                    $entry->title = (string)$value;
                } else {
                    $entry->setFieldValue($fieldHandle, (string)$value);
                }
                Craft::$app->getElements()->saveElement($entry, false, false);
            }
        }

        Craft::$app->getSession()->setNotice('Entry saved.');
        return $this->redirectToPostedUrl();
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
            if (!$this->isEligibleTranslatableField($field)) {
                continue;
            }
            $options[] = ['value' => $field->handle, 'label' => $field->name];
        }

        return $options;
    }

    private function isEligibleTranslatableField(mixed $field, string $fieldFilter = ''): bool
    {
        $isEligibleType = ($field instanceof PlainText) || (get_class($field) === 'craft\\ckeditor\\Field');
        if (!$isEligibleType) {
            return false;
        }

        if ($field->translationMethod === \craft\base\Field::TRANSLATION_METHOD_NONE) {
            return false;
        }

        if ($fieldFilter !== '' && $fieldFilter !== 'title' && $field->handle !== $fieldFilter) {
            return false;
        }

        return true;
    }

    private function entryHasEligibleTranslatableFields(Entry $entry, string $fieldFilter = ''): bool
    {
        if ($fieldFilter === '' || $fieldFilter === 'title') {
            return true;
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
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

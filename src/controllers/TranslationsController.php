<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

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
        ]);
    }

    public function actionEntries(): Response
    {
        $request = Craft::$app->getRequest();
        $searchParam = $request->getParam('q', '');
        $search = is_scalar($searchParam) ? (string)$searchParam : '';
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $page = max(1, (int)$request->getParam('page', 1));
        $scopeParam = $request->getParam('scope', 'all');
        $scope = trim(is_scalar($scopeParam) ? (string)$scopeParam : 'all');
        if (!in_array($scope, ['all', 'section', 'global', 'categoryGroup', 'entryType'], true)) {
            $scope = 'all';
        }
        $sectionIdParam = $request->getParam('sectionId', 0);
        $globalSetIdParam = $request->getParam('globalSetId', 0);
        $categoryGroupIdParam = $request->getParam('categoryGroupId', 0);
        $entryTypeIdParam = $request->getParam('entryTypeId', 0);
        $entryFilterParam = $request->getParam('entry', '');
        $sectionId = max(0, (int)(is_scalar($sectionIdParam) ? $sectionIdParam : 0));
        $globalSetId = max(0, (int)(is_scalar($globalSetIdParam) ? $globalSetIdParam : 0));
        $categoryGroupId = max(0, (int)(is_scalar($categoryGroupIdParam) ? $categoryGroupIdParam : 0));
        $entryTypeId = max(0, (int)(is_scalar($entryTypeIdParam) ? $entryTypeIdParam : 0));
        $entryFilter = is_scalar($entryFilterParam) ? (string)$entryFilterParam : '';

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $rows = $this->buildEntriesRowsForSite(
            $selectedSiteId,
            $search,
            $scope,
            $entryFilter,
            $sectionId,
            $globalSetId,
            $categoryGroupId,
            $entryTypeId
        );

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $entryRowCounts = [];
        foreach ($pageRows as $row) {
            $entryKey = (string)($row['elementKey'] ?? ((string)($row['elementType'] ?? 'entry') . ':' . (int)($row['elementId'] ?? 0)));
            $entryRowCounts[$entryKey] = ($entryRowCounts[$entryKey] ?? 0) + 1;
        }

        $entryOptions = $scope === 'all' ? $this->getEntryOptionsFromRows($rows) : [['value' => '', 'label' => Craft::t('app', 'All')]];
        $sidebarNav = $this->buildEntriesSidebar($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/translations/entries', [
            'rows' => $pageRows,
            'entryRowCounts' => $entryRowCounts,
            'languages' => $languages,
            'sidebarNav' => $sidebarNav,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'scope' => $scope,
            'sectionId' => $sectionId,
            'globalSetId' => $globalSetId,
            'categoryGroupId' => $categoryGroupId,
            'entryTypeId' => $entryTypeId,
            'entryFilter' => $entryFilter,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'entryOptions' => $entryOptions,
        ]);
    }

    public function actionSeo(): Response
    {
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $page = max(1, (int)$request->getParam('page', 1));
        $sectionFilter = trim((string)$request->getParam('section', ''));
        $sectionId = (int)$sectionFilter;

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sections = $this->getSeoSectionsForSite($selectedSiteId);
        $allowedSectionIds = array_map(static fn(array $section): int => (int)($section['id'] ?? 0), $sections);
        if ($sectionId && !in_array($sectionId, $allowedSectionIds, true)) {
            $sectionId = 0;
            $sectionFilter = '';
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $languageMap = $this->getLanguageMap($sites);

        $rows = $this->buildSeoRowsForSite($selectedSiteId, $sectionId, $search);

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $entryRowCounts = [];
        foreach ($pageRows as $row) {
            $entryKey = (string)($row['elementKey'] ?? ('entry:' . (int)($row['elementId'] ?? 0)));
            $entryRowCounts[$entryKey] = ($entryRowCounts[$entryKey] ?? 0) + 1;
        }

        return $this->renderTemplate('pragmatic-web-toolkit/translations/seo', [
            'rows' => $pageRows,
            'entryRowCounts' => $entryRowCounts,
            'languages' => $languages,
            'languageMap' => $languageMap,
            'sections' => $sections,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sectionId' => $sectionId,
            'sectionFilter' => $sectionFilter,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    public function actionAssets(): Response
    {
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $sort = strtolower(trim((string)$request->getParam('sort', 'used')));
        if (!in_array($sort, ['used', 'asset'], true)) {
            $sort = 'used';
        }
        $dir = strtolower(trim((string)$request->getParam('dir', $sort === 'used' ? 'desc' : 'asc')));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = $sort === 'used' ? 'desc' : 'asc';
        }
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $page = max(1, (int)$request->getParam('page', 1));

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $defaultSite = Craft::$app->getSites()->getPrimarySite();
        $defaultSiteId = (int)$defaultSite->id;
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $languageMap = $this->getLanguageMap($sites);

        $assetQuery = Asset::find()
            ->siteId($selectedSiteId)
            ->status(null);
        $assets = $assetQuery->all();
        $usedAssetIdLookup = array_fill_keys($this->getUsedAssetIdsForSite($selectedSiteId), true);

        usort($assets, static function(Asset $a, Asset $b) use ($sort, $dir, $usedAssetIdLookup): int {
            $direction = $dir === 'asc' ? 1 : -1;
            if ($sort === 'used') {
                $aUsed = isset($usedAssetIdLookup[(int)$a->id]) ? 1 : 0;
                $bUsed = isset($usedAssetIdLookup[(int)$b->id]) ? 1 : 0;
                if ($aUsed !== $bUsed) {
                    return ($aUsed <=> $bUsed) * $direction;
                }
            }

            $aName = mb_strtolower((string)$a->filename);
            $bName = mb_strtolower((string)$b->filename);
            return ($aName <=> $bName) * $direction;
        });

        $rows = [];
        foreach ($assets as $asset) {
            $this->appendAssetRows(
                $rows,
                $asset,
                $defaultSiteId,
                isset($usedAssetIdLookup[(int)$asset->id])
            );
        }

        [$siteAssets, $defaultAssets] = $this->getSiteAssetMapsForRows($rows, $languageMap, $defaultSiteId);
        $this->populateAssetRowsValues($rows, $languageMap, $siteAssets, $defaultAssets);

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function(array $row) use ($search): bool {
                return $this->assetRowMatchesSearch($row, $search);
            }));
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $assetRowCounts = [];
        foreach ($pageRows as $row) {
            $assetKey = (string)($row['assetKey'] ?? ('asset:' . (int)($row['assetId'] ?? 0)));
            $assetRowCounts[$assetKey] = ($assetRowCounts[$assetKey] ?? 0) + 1;
        }
        return $this->renderTemplate('pragmatic-web-toolkit/translations/assets', [
            'rows' => $pageRows,
            'assetRowCounts' => $assetRowCounts,
            'languages' => $languages,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'defaultSite' => $defaultSite,
            'defaultSiteId' => $defaultSiteId,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    public function actionDiagnoseEntrySection(): Response
    {
        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getParam('entryId', $request->getBodyParam('entryId', 0));
        $siteId = (int)$request->getParam('siteId', $request->getBodyParam('siteId', 0));
        if ($siteId <= 0) {
            $siteId = (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
        }

        if ($entryId <= 0) {
            throw new BadRequestHttpException('Missing or invalid entryId.');
        }

        $entry = $this->resolveEntryForSite($entryId, $siteId);
        if (!$entry) {
            return $this->asJson([
                'success' => false,
                'error' => sprintf('Entry %d not found for site %d.', $entryId, $siteId),
            ]);
        }

        $issues = [];
        $this->diagnoseElementSectionIntegrity($entry, sprintf('entry#%d', (int)$entry->id), $issues, 0);

        return $this->asJson([
            'success' => true,
            'entryId' => (int)$entry->id,
            'siteId' => (int)$entry->siteId,
            'issues' => $issues,
            'issueCount' => count($issues),
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

    public function actionSaveAssetRow(): Response
    {
        $this->requirePostRequest();

        $saveRow = Craft::$app->getRequest()->getBodyParam('saveRow');
        $assets = Craft::$app->getRequest()->getBodyParam('assets', []);
        if ($saveRow === null || !isset($assets[$saveRow])) {
            throw new BadRequestHttpException('Invalid asset payload.');
        }

        $row = $assets[$saveRow];
        $assetId = (int)($row['assetId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);
        if (!$assetId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing asset data.');
        }

        $result = $this->saveAssetFieldValues($assetId, $fieldHandle, $values);
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
            'Asset row saved. Saved %d, skipped %d, failed %d.',
            (int)$result['saved'],
            (int)$result['skipped'],
            (int)$result['failed'],
        ));

        return $this->redirectAssetsIndexWithCurrentFilters();
    }

    public function actionSaveAssetRows(): Response
    {
        $this->requirePostRequest();

        $assets = Craft::$app->getRequest()->getBodyParam('assets', []);
        if (!is_array($assets)) {
            throw new BadRequestHttpException('Invalid assets payload.');
        }

        $rowsProcessed = 0;
        $saved = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($assets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assetId = (int)($row['assetId'] ?? 0);
            $fieldHandle = (string)($row['fieldHandle'] ?? '');
            $values = (array)($row['values'] ?? []);
            if (!$assetId || $fieldHandle === '') {
                continue;
            }

            $result = $this->saveAssetFieldValues($assetId, $fieldHandle, $values);
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

        return $this->redirectAssetsIndexWithCurrentFilters();
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        
        $settings = PragmaticWebToolkit::$plugin->translationsSettings->get();

        return $this->renderTemplate('pragmatic-web-toolkit/translations/options', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,        ]);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

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

    public function actionGenerateStaticPromptBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $translationIds = array_values(array_unique(array_filter(array_map('intval', (array)$request->getBodyParam('translationIds', [])))));
            if (empty($translationIds)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $all = PragmaticWebToolkit::$plugin->translations->getAllTranslations();
            $selected = [];
            foreach ($all as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0 && in_array($id, $translationIds, true)) {
                    $selected[] = $row;
                }
            }
            if (empty($selected)) {
                throw new BadRequestHttpException('No matching rows found.');
            }
            $targetLanguages = array_values(array_unique(array_filter(array_map(
                static fn(mixed $language): string => trim((string)$language),
                (array)$request->getBodyParam('targetLanguages', [])
            ))));

            $bundle = $this->buildStaticTranslationBundle($selected, $sites, $siteId, $targetLanguages);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $sourceLanguage = (string)($site?->language ?? '');

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => $this->buildStaticTranslationManualPrompt($bundle, $sourceLanguage),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionExportStaticJsonBundle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $translationIds = array_values(array_unique(array_filter(array_map('intval', (array)$request->getBodyParam('translationIds', [])))));
            if (empty($translationIds)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $all = PragmaticWebToolkit::$plugin->translations->getAllTranslations();
            $selected = [];
            foreach ($all as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0 && in_array($id, $translationIds, true)) {
                    $selected[] = $row;
                }
            }
            if (empty($selected)) {
                throw new BadRequestHttpException('No matching rows found.');
            }

            $bundle = $this->buildStaticTranslationBundle($selected, $sites, $siteId);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $timestamp = (new \DateTime())->format('Ymd-His');
            $filename = 'translations-static-export-' . ($site?->handle ?? 'site') . '-' . $timestamp . '.json';

            return $this->asJson([
                'success' => true,
                'bundle' => $bundle,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportStaticJsonPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $bundle = $this->readStaticImportBundleFromRequest(Craft::$app->getRequest());
            $classification = $this->classifyStaticImportBundle($bundle);

            return $this->asJson([
                'success' => true,
                'preview' => [
                    'matchedChanged' => $classification['matchedChanged'],
                    'matchedUnchanged' => $classification['matchedUnchanged'],
                    'skippedUnmatched' => $classification['skippedUnmatched'],
                    'invalidItems' => $classification['invalidItems'],
                    'totals' => [
                        'totalItems' => $classification['totalItems'],
                        'matchedChanged' => count($classification['matchedChanged']),
                        'matchedUnchanged' => count($classification['matchedUnchanged']),
                        'skippedUnmatched' => count($classification['skippedUnmatched']),
                        'invalidItems' => count($classification['invalidItems']),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportStaticJsonApply(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $itemsJson = trim((string)$request->getBodyParam('itemsJson', ''));
            if ($itemsJson !== '') {
                try {
                    $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestHttpException('Invalid items JSON: ' . $e->getMessage());
                }
                $items = is_array($decoded) ? $decoded : [];
            } else {
                $items = (array)$request->getBodyParam('items', []);
            }

            if (empty($items)) {
                $bundle = $this->readStaticImportBundleFromRequest($request);
                $classification = $this->classifyStaticImportBundle($bundle);
                $items = $classification['matchedChanged'];
            }
            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $languageMap = $this->getLanguageMap($sites);
            $saveItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = trim((string)($item['key'] ?? ''));
                $group = trim((string)($item['group'] ?? 'site')) ?: 'site';
                $afterValues = (array)($item['afterValues'] ?? []);
                if ($key === '' || empty($afterValues)) {
                    continue;
                }

                $saveItems[] = [
                    'id' => (int)($item['id'] ?? 0) ?: null,
                    'key' => $key,
                    'group' => $group,
                    'values' => $afterValues,
                ];
            }
            if (empty($saveItems)) {
                throw new BadRequestHttpException('No valid items to apply.');
            }

            $saveItems = $this->expandLanguageValuesToSites($saveItems, $languageMap);
            PragmaticWebToolkit::$plugin->translations->saveTranslations($saveItems);

            return $this->asJson([
                'success' => true,
                'summary' => [
                    'applied' => count($saveItems),
                    'skipped' => 0,
                    'errors' => [],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionGenerateEntriesPromptBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = $this->normalizeEntriesSelectionItems((array)$request->getBodyParam('items', []));
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }
            $targetLanguages = array_values(array_unique(array_filter(array_map(
                static fn(mixed $language): string => trim((string)$language),
                (array)$request->getBodyParam('targetLanguages', [])
            ))));

            $bundle = $this->buildEntriesTranslationBundle($items, $siteId, $targetLanguages);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $sourceLanguage = (string)($site?->language ?? '');

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => $this->buildGenericTranslationManualPrompt($bundle, $sourceLanguage),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionExportEntriesJsonBundle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = $this->normalizeEntriesSelectionItems((array)$request->getBodyParam('items', []));
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $bundle = $this->buildEntriesTranslationBundle($items, $siteId);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $timestamp = (new \DateTime())->format('Ymd-His');
            $filename = 'translations-entries-export-' . ($site?->handle ?? 'site') . '-' . $timestamp . '.json';

            return $this->asJson([
                'success' => true,
                'bundle' => $bundle,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportEntriesJsonPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $bundle = $this->readDomainImportBundleFromRequest(Craft::$app->getRequest(), 'translations-entries', '1.0');
            $classification = $this->classifyEntriesImportBundle($bundle);

            return $this->asJson([
                'success' => true,
                'preview' => [
                    'matchedChanged' => $classification['matchedChanged'],
                    'matchedUnchanged' => $classification['matchedUnchanged'],
                    'skippedUnmatched' => $classification['skippedUnmatched'],
                    'invalidItems' => $classification['invalidItems'],
                    'totals' => [
                        'totalItems' => $classification['totalItems'],
                        'matchedChanged' => count($classification['matchedChanged']),
                        'matchedUnchanged' => count($classification['matchedUnchanged']),
                        'skippedUnmatched' => count($classification['skippedUnmatched']),
                        'invalidItems' => count($classification['invalidItems']),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportEntriesJsonApply(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $itemsJson = trim((string)$request->getBodyParam('itemsJson', ''));
            if ($itemsJson !== '') {
                try {
                    $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestHttpException('Invalid items JSON: ' . $e->getMessage());
                }
                $items = is_array($decoded) ? $decoded : [];
            } else {
                $items = (array)$request->getBodyParam('items', []);
            }

            if (empty($items)) {
                $bundle = $this->readDomainImportBundleFromRequest($request, 'translations-entries', '1.0');
                $classification = $this->classifyEntriesImportBundle($bundle);
                $items = $classification['matchedChanged'];
            }
            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $applied = 0;
            $skipped = 0;
            $errors = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $elementType = trim((string)($item['elementType'] ?? 'entry'));
                $elementId = (int)($item['elementId'] ?? 0);
                $fieldHandle = $this->normalizeEntryFieldHandle((string)($item['fieldHandle'] ?? ''));
                $afterValues = (array)($item['afterValues'] ?? []);
                if ($elementId <= 0 || $fieldHandle === '' || empty($afterValues)) {
                    continue;
                }
                $result = $this->saveElementFieldValues($elementType, $elementId, $fieldHandle, $afterValues);
                if ((int)$result['failed'] > 0) {
                    $errors = array_merge($errors, (array)($result['errors'] ?? []));
                }
                if ((int)$result['saved'] > 0) {
                    $applied++;
                }
                if ((int)$result['saved'] <= 0) {
                    $skipped++;
                }
                if (!empty($result['skipReasons']) && is_array($result['skipReasons'])) {
                    $errors = array_merge($errors, (array)$result['skipReasons']);
                }
            }

            return $this->asJson([
                'success' => true,
                'summary' => [
                    'applied' => $applied,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionGenerateAssetsPromptBatch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = $this->normalizeAssetsSelectionItems((array)$request->getBodyParam('items', []));
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }
            $targetLanguages = array_values(array_unique(array_filter(array_map(
                static fn(mixed $language): string => trim((string)$language),
                (array)$request->getBodyParam('targetLanguages', [])
            ))));

            $bundle = $this->buildAssetsTranslationBundle($items, $siteId, $targetLanguages);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $sourceLanguage = (string)($site?->language ?? '');

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => $this->buildGenericTranslationManualPrompt($bundle, $sourceLanguage),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionExportAssetsJsonBundle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = $this->normalizeAssetsSelectionItems((array)$request->getBodyParam('items', []));
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $bundle = $this->buildAssetsTranslationBundle($items, $siteId);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $timestamp = (new \DateTime())->format('Ymd-His');
            $filename = 'translations-assets-export-' . ($site?->handle ?? 'site') . '-' . $timestamp . '.json';

            return $this->asJson([
                'success' => true,
                'bundle' => $bundle,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportAssetsJsonPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $bundle = $this->readDomainImportBundleFromRequest(Craft::$app->getRequest(), 'translations-assets', '1.0');
            $classification = $this->classifyAssetsImportBundle($bundle);

            return $this->asJson([
                'success' => true,
                'preview' => [
                    'matchedChanged' => $classification['matchedChanged'],
                    'matchedUnchanged' => $classification['matchedUnchanged'],
                    'skippedUnmatched' => $classification['skippedUnmatched'],
                    'invalidItems' => $classification['invalidItems'],
                    'totals' => [
                        'totalItems' => $classification['totalItems'],
                        'matchedChanged' => count($classification['matchedChanged']),
                        'matchedUnchanged' => count($classification['matchedUnchanged']),
                        'skippedUnmatched' => count($classification['skippedUnmatched']),
                        'invalidItems' => count($classification['invalidItems']),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportAssetsJsonApply(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $request = Craft::$app->getRequest();
            $itemsJson = trim((string)$request->getBodyParam('itemsJson', ''));
            if ($itemsJson !== '') {
                try {
                    $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestHttpException('Invalid items JSON: ' . $e->getMessage());
                }
                $items = is_array($decoded) ? $decoded : [];
            } else {
                $items = (array)$request->getBodyParam('items', []);
            }

            if (empty($items)) {
                $bundle = $this->readDomainImportBundleFromRequest($request, 'translations-assets', '1.0');
                $classification = $this->classifyAssetsImportBundle($bundle);
                $items = $classification['matchedChanged'];
            }
            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $applied = 0;
            $errors = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $assetId = (int)($item['assetId'] ?? 0);
                $fieldHandle = $this->normalizeAssetFieldHandle((string)($item['fieldHandle'] ?? ''));
                $afterValues = (array)($item['afterValues'] ?? []);
                if ($assetId <= 0 || $fieldHandle === '' || empty($afterValues)) {
                    continue;
                }
                $result = $this->saveAssetFieldValues($assetId, $fieldHandle, $afterValues);
                if ((int)$result['failed'] > 0) {
                    $errors = array_merge($errors, (array)($result['errors'] ?? []));
                    continue;
                }
                if ((int)$result['saved'] > 0) {
                    $applied++;
                }
            }

            return $this->asJson([
                'success' => true,
                'summary' => [
                    'applied' => $applied,
                    'skipped' => 0,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $format = strtolower((string)$request->getBodyParam('format', 'csv'));
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

    public function actionSaveGroups(): Response
    {
        $this->requirePostRequest();

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

    private function buildStaticTranslationBundle(array $translations, array $sites, int $siteId, array $targetLanguages = []): array
    {
        $languages = $this->getLanguages($sites);
        $site = Craft::$app->getSites()->getSiteById($siteId) ?? Craft::$app->getSites()->getPrimarySite();
        if (!empty($targetLanguages)) {
            $allowedLanguages = array_fill_keys($targetLanguages, true);
            $allowedLanguages[(string)$site->language] = true;
            $languages = array_values(array_filter(
                $languages,
                static fn(string $language): bool => isset($allowedLanguages[$language])
            ));
        }
        $items = [];
        foreach ($translations as $translation) {
            $item = [
                'id' => (int)($translation['id'] ?? 0),
                'group' => (string)($translation['group'] ?? 'site'),
                'key' => (string)($translation['key'] ?? ''),
                'values' => [],
            ];
            foreach ($languages as $language) {
                $item['values'][$language] = $this->getValueForLanguage($translation, $sites, $language);
            }
            $items[] = $item;
        }

        return [
            'version' => '1.0',
            'domain' => 'translations-static',
            'site' => [
                'id' => (int)$site->id,
                'handle' => (string)$site->handle,
                'language' => (string)$site->language,
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    private function buildStaticTranslationManualPrompt(array $bundle, string $sourceLanguage): string
    {
        return $this->buildGenericTranslationManualPrompt($bundle, $sourceLanguage);
    }

    private function classifyStaticImportBundle(array $bundle): array
    {
        $items = (array)($bundle['items'] ?? []);
        $matchedChanged = [];
        $matchedUnchanged = [];
        $skippedUnmatched = [];
        $invalidItems = [];

        $all = PragmaticWebToolkit::$plugin->translations->getAllTranslations();
        $translationByCompoundKey = [];
        foreach ($all as $translation) {
            $group = (string)($translation['group'] ?? 'site');
            $key = (string)($translation['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $translationByCompoundKey[$group . '::' . $key] = $translation;
        }
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Item must be an object.'];
                continue;
            }
            $group = trim((string)($item['group'] ?? 'site')) ?: 'site';
            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') {
                $invalidItems[] = ['index' => $index, 'reason' => 'Missing key.'];
                continue;
            }
            $incomingValues = (array)($item['values'] ?? []);

            $compoundKey = $group . '::' . $key;
            $existing = $translationByCompoundKey[$compoundKey] ?? null;
            if (!$existing) {
                $skippedUnmatched[] = [
                    'index' => $index,
                    'group' => $group,
                    'key' => $key,
                    'reason' => 'No matching translation found by group+key.',
                ];
                continue;
            }

            $beforeValues = [];
            $afterValues = [];
            $changedLanguages = [];
            foreach ($languages as $language) {
                $before = $this->getValueForLanguage($existing, $sites, $language);
                $after = array_key_exists($language, $incomingValues) ? (string)$incomingValues[$language] : $before;
                $beforeValues[$language] = $before;
                $afterValues[$language] = $after;
                if ($before !== $after) {
                    $changedLanguages[] = $language;
                }
            }

            $previewItem = [
                'id' => (int)($existing['id'] ?? 0),
                'group' => $group,
                'key' => $key,
                'beforeValues' => $beforeValues,
                'afterValues' => $afterValues,
                'changedLanguages' => $changedLanguages,
            ];
            if (!empty($changedLanguages)) {
                $matchedChanged[] = $previewItem;
            } else {
                $matchedUnchanged[] = $previewItem;
            }
        }

        return [
            'totalItems' => count($items),
            'matchedChanged' => $matchedChanged,
            'matchedUnchanged' => $matchedUnchanged,
            'skippedUnmatched' => $skippedUnmatched,
            'invalidItems' => $invalidItems,
        ];
    }

    private function readStaticImportBundleFromRequest(\craft\web\Request $request): array
    {
        $jsonText = trim((string)$request->getBodyParam('jsonText', ''));
        if ($jsonText === '') {
            $uploaded = UploadedFile::getInstanceByName('jsonFile');
            if ($uploaded) {
                $jsonText = trim((string)file_get_contents($uploaded->tempName));
            }
        }

        if ($jsonText === '') {
            throw new BadRequestHttpException('Provide JSON text or a JSON file.');
        }

        try {
            $bundle = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($bundle)) {
            throw new BadRequestHttpException('Invalid JSON bundle.');
        }
        if (($bundle['domain'] ?? '') !== 'translations-static') {
            throw new BadRequestHttpException('Invalid bundle domain. Expected "translations-static".');
        }
        if ((string)($bundle['version'] ?? '') !== '1.0') {
            throw new BadRequestHttpException('Unsupported bundle version. Expected "1.0".');
        }
        if (!isset($bundle['items']) || !is_array($bundle['items'])) {
            throw new BadRequestHttpException('Bundle items are missing.');
        }

        return $bundle;
    }

    private function buildGenericTranslationManualPrompt(array $bundle, string $sourceLanguage): string
    {
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return implode("\n", [
            'You are an expert website localization assistant.',
            'Task: translate translation values while preserving meaning and tone.',
            'Important rules:',
            '- Return only valid JSON.',
            '- Keep EXACTLY this root structure and keys: version, domain, site, generatedAt, items.',
            '- Keep each item identity fields unchanged.',
            '- Keep values object keys (languages) unchanged.',
            '- Translate from source language "' . $sourceLanguage . '" into other language values.',
            '- Preserve placeholders and tokens exactly (examples: {name}, {count}, %s, :attribute, {{variable}}).',
            '- Do not add comments, markdown, or extra keys.',
            '',
            'Input JSON:',
            $json,
        ]);
    }

    private function readDomainImportBundleFromRequest(\craft\web\Request $request, string $expectedDomain, string $expectedVersion): array
    {
        $jsonText = trim((string)$request->getBodyParam('jsonText', ''));
        if ($jsonText === '') {
            $uploaded = UploadedFile::getInstanceByName('jsonFile');
            if ($uploaded) {
                $jsonText = trim((string)file_get_contents($uploaded->tempName));
            }
        }

        if ($jsonText === '') {
            throw new BadRequestHttpException('Provide JSON text or a JSON file.');
        }

        try {
            $bundle = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($bundle)) {
            throw new BadRequestHttpException('Invalid JSON bundle.');
        }
        if (($bundle['domain'] ?? '') !== $expectedDomain) {
            throw new BadRequestHttpException('Invalid bundle domain. Expected "' . $expectedDomain . '".');
        }
        if ((string)($bundle['version'] ?? '') !== $expectedVersion) {
            throw new BadRequestHttpException('Unsupported bundle version. Expected "' . $expectedVersion . '".');
        }
        if (!isset($bundle['items']) || !is_array($bundle['items'])) {
            throw new BadRequestHttpException('Bundle items are missing.');
        }

        return $bundle;
    }

    private function normalizeEntriesSelectionItems(array $input): array
    {
        $items = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $elementType = trim((string)($item['elementType'] ?? 'entry'));
            $elementId = (int)($item['elementId'] ?? 0);
            $fieldHandle = $this->normalizeEntryFieldHandle((string)($item['fieldHandle'] ?? ''));
            if ($elementId <= 0 || $fieldHandle === '') {
                continue;
            }
            $items[] = [
                'elementType' => $elementType !== '' ? $elementType : 'entry',
                'elementId' => $elementId,
                'fieldHandle' => $fieldHandle,
            ];
        }

        return array_values(array_unique($items, SORT_REGULAR));
    }

    private function normalizeAssetsSelectionItems(array $input): array
    {
        $items = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $assetId = (int)($item['assetId'] ?? 0);
            $fieldHandle = $this->normalizeAssetFieldHandle((string)($item['fieldHandle'] ?? ''));
            if ($assetId <= 0 || $fieldHandle === '') {
                continue;
            }
            $items[] = [
                'assetId' => $assetId,
                'fieldHandle' => $fieldHandle,
            ];
        }

        return array_values(array_unique($items, SORT_REGULAR));
    }

    private function buildEntriesTranslationBundle(array $selectionItems, int $siteId, array $targetLanguages = []): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId) ?? Craft::$app->getSites()->getPrimarySite();
        $languages = $this->getLanguages(Craft::$app->getSites()->getAllSites());
        if (!empty($targetLanguages)) {
            $allowedLanguages = array_fill_keys($targetLanguages, true);
            $allowedLanguages[(string)$site->language] = true;
            $languages = array_values(array_filter(
                $languages,
                static fn(string $language): bool => isset($allowedLanguages[$language])
            ));
        }
        $rowsByKey = [];

        $request = Craft::$app->getRequest();
        $searchParam = $request->getBodyParam('q', $request->getQueryParam('q', ''));
        $scopeParam = $request->getBodyParam('scope', $request->getQueryParam('scope', 'all'));
        $entryFilterParam = $request->getBodyParam('entry', $request->getQueryParam('entry', ''));
        $sectionIdParam = $request->getBodyParam('sectionId', $request->getQueryParam('sectionId', 0));
        $globalSetIdParam = $request->getBodyParam('globalSetId', $request->getQueryParam('globalSetId', 0));
        $categoryGroupIdParam = $request->getBodyParam('categoryGroupId', $request->getQueryParam('categoryGroupId', 0));
        $entryTypeIdParam = $request->getBodyParam('entryTypeId', $request->getQueryParam('entryTypeId', 0));

        $search = is_scalar($searchParam) ? (string)$searchParam : '';
        $scope = is_scalar($scopeParam) ? (string)$scopeParam : 'all';
        $entryFilter = is_scalar($entryFilterParam) ? (string)$entryFilterParam : '';
        $sectionId = (int)(is_scalar($sectionIdParam) ? $sectionIdParam : 0);
        $globalSetId = (int)(is_scalar($globalSetIdParam) ? $globalSetIdParam : 0);
        $categoryGroupId = (int)(is_scalar($categoryGroupIdParam) ? $categoryGroupIdParam : 0);
        $entryTypeId = (int)(is_scalar($entryTypeIdParam) ? $entryTypeIdParam : 0);

        $rows = $this->buildEntriesRowsForSite($siteId, $search, $scope, $entryFilter, $sectionId, $globalSetId, $categoryGroupId, $entryTypeId);
        foreach ($rows as $row) {
            $key = sprintf('%s:%d:%s', (string)($row['elementType'] ?? 'entry'), (int)($row['elementId'] ?? 0), (string)($row['fieldHandle'] ?? ''));
            $rowsByKey[$key] = $row;
        }
        $hasSeoSelection = false;
        foreach ($selectionItems as $selected) {
            $selectedHandle = (string)($selected['fieldHandle'] ?? '');
            if ($this->parseSeoSubFieldHandle($selectedHandle) !== null) {
                $hasSeoSelection = true;
                break;
            }
        }
        if ($hasSeoSelection) {
            $seoRows = $this->buildSeoRowsForSite($siteId, $sectionId, $search);
            foreach ($seoRows as $seoRow) {
                $seoKey = sprintf(
                    '%s:%d:%s',
                    (string)($seoRow['elementType'] ?? 'entry'),
                    (int)($seoRow['elementId'] ?? 0),
                    (string)($seoRow['fieldHandle'] ?? '')
                );
                $rowsByKey[$seoKey] = $seoRow;
            }
        }

        $items = [];
        foreach ($selectionItems as $selected) {
            $rowKey = sprintf('%s:%d:%s', (string)$selected['elementType'], (int)$selected['elementId'], (string)$selected['fieldHandle']);
            $row = $rowsByKey[$rowKey] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($languages as $language) {
                $values[$language] = (string)($row['values'][$language] ?? '');
            }
            $items[] = [
                'elementType' => (string)$selected['elementType'],
                'elementId' => (int)$selected['elementId'],
                'fieldHandle' => $this->toPortableEntryFieldHandle((string)$selected['fieldHandle']),
                'fieldLabel' => (string)($row['fieldLabel'] ?? ''),
                'values' => $values,
            ];
        }

        return [
            'version' => '1.0',
            'domain' => 'translations-entries',
            'site' => [
                'id' => (int)$site->id,
                'handle' => (string)$site->handle,
                'language' => (string)$site->language,
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    private function buildAssetsTranslationBundle(array $selectionItems, int $siteId, array $targetLanguages = []): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId) ?? Craft::$app->getSites()->getPrimarySite();
        $languages = $this->getLanguages(Craft::$app->getSites()->getAllSites());
        if (!empty($targetLanguages)) {
            $allowedLanguages = array_fill_keys($targetLanguages, true);
            $allowedLanguages[(string)$site->language] = true;
            $languages = array_values(array_filter(
                $languages,
                static fn(string $language): bool => isset($allowedLanguages[$language])
            ));
        }
        $rowsByKey = [];

        $request = Craft::$app->getRequest();
        $search = (string)$request->getBodyParam('q', $request->getQueryParam('q', ''));
        $rows = $this->buildAssetRowsForSite($siteId, $search);
        foreach ($rows as $row) {
            $key = sprintf('%d:%s', (int)($row['assetId'] ?? 0), (string)($row['fieldHandle'] ?? ''));
            $rowsByKey[$key] = $row;
        }

        $items = [];
        foreach ($selectionItems as $selected) {
            $rowKey = sprintf('%d:%s', (int)$selected['assetId'], (string)$selected['fieldHandle']);
            $row = $rowsByKey[$rowKey] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($languages as $language) {
                $values[$language] = (string)($row['values'][$language] ?? '');
            }
            $items[] = [
                'assetId' => (int)$selected['assetId'],
                'fieldHandle' => $this->toPortableAssetFieldHandle((string)$selected['fieldHandle']),
                'fieldLabel' => (string)($row['fieldLabel'] ?? ''),
                'values' => $values,
            ];
        }

        return [
            'version' => '1.0',
            'domain' => 'translations-assets',
            'site' => [
                'id' => (int)$site->id,
                'handle' => (string)$site->handle,
                'language' => (string)$site->language,
            ],
            'generatedAt' => (new \DateTime())->format(DATE_ATOM),
            'items' => $items,
        ];
    }

    private function classifyEntriesImportBundle(array $bundle): array
    {
        $items = (array)($bundle['items'] ?? []);
        $matchedChanged = [];
        $matchedUnchanged = [];
        $skippedUnmatched = [];
        $invalidItems = [];
        $languages = $this->getLanguages(Craft::$app->getSites()->getAllSites());

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Item must be an object.'];
                continue;
            }
            $elementType = trim((string)($item['elementType'] ?? 'entry'));
            $elementId = (int)($item['elementId'] ?? 0);
            $fieldHandle = $this->normalizeEntryFieldHandle((string)($item['fieldHandle'] ?? ''));
            if ($elementId <= 0 || $fieldHandle === '') {
                $invalidItems[] = ['index' => $index, 'reason' => 'Missing elementId or fieldHandle.'];
                continue;
            }

            $incoming = (array)($item['values'] ?? []);
            $allSites = Craft::$app->getSites()->getAllSites();
            $beforeValues = [];
            $afterValues = [];
            foreach ($languages as $language) {
                $value = '';
                $siteIds = [];
                foreach ($allSites as $site) {
                    if ((string)$site->language === $language) {
                        $siteIds[] = (int)$site->id;
                    }
                }
                foreach ($siteIds as $siteId) {
                    $element = $this->resolveElementByTypeForSite($elementType, $elementId, $siteId);
                    if ($element) {
                        $value = $this->getElementFieldValueForHandle($element, $fieldHandle);
                        break;
                    }
                }
                $beforeValues[$language] = $value;
                $afterValues[$language] = array_key_exists($language, $incoming) ? (string)$incoming[$language] : $value;
            }

            $changedLanguages = [];
            foreach ($languages as $language) {
                if ((string)$beforeValues[$language] !== (string)$afterValues[$language]) {
                    $changedLanguages[] = $language;
                }
            }

            $elementExists = false;
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $exists = (bool)$this->resolveElementByTypeForSite($elementType, $elementId, (int)$site->id);
                if ($exists) {
                    $elementExists = true;
                    break;
                }
            }
            if (!$elementExists) {
                $skippedUnmatched[] = [
                    'index' => $index,
                    'elementType' => $elementType,
                    'elementId' => $elementId,
                    'fieldHandle' => $fieldHandle,
                    'reason' => 'No matching element found.',
                ];
                continue;
            }

            $previewItem = [
                'elementType' => $elementType,
                'elementId' => $elementId,
                'fieldHandle' => $fieldHandle,
                'beforeValues' => $beforeValues,
                'afterValues' => $afterValues,
                'changedLanguages' => $changedLanguages,
            ];
            if (!empty($changedLanguages)) {
                $matchedChanged[] = $previewItem;
            } else {
                $matchedUnchanged[] = $previewItem;
            }
        }

        return [
            'totalItems' => count($items),
            'matchedChanged' => $matchedChanged,
            'matchedUnchanged' => $matchedUnchanged,
            'skippedUnmatched' => $skippedUnmatched,
            'invalidItems' => $invalidItems,
        ];
    }

    private function classifyAssetsImportBundle(array $bundle): array
    {
        $items = (array)($bundle['items'] ?? []);
        $matchedChanged = [];
        $matchedUnchanged = [];
        $skippedUnmatched = [];
        $invalidItems = [];
        $languages = $this->getLanguages(Craft::$app->getSites()->getAllSites());

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Item must be an object.'];
                continue;
            }
            $assetId = (int)($item['assetId'] ?? 0);
            $fieldHandle = $this->normalizeAssetFieldHandle((string)($item['fieldHandle'] ?? ''));
            if ($assetId <= 0 || $fieldHandle === '') {
                $invalidItems[] = ['index' => $index, 'reason' => 'Missing assetId or fieldHandle.'];
                continue;
            }
            $incoming = (array)($item['values'] ?? []);
            $allSites = Craft::$app->getSites()->getAllSites();
            $beforeValues = [];
            $afterValues = [];
            foreach ($languages as $language) {
                $value = '';
                foreach ($allSites as $site) {
                    if ((string)$site->language !== $language) {
                        continue;
                    }
                    $asset = $this->resolveAssetForSite($assetId, (int)$site->id);
                    if ($asset) {
                        $value = $this->getAssetFieldValueForHandle($asset, $fieldHandle);
                        break;
                    }
                }
                $beforeValues[$language] = $value;
                $afterValues[$language] = array_key_exists($language, $incoming) ? (string)$incoming[$language] : $value;
            }

            $assetExists = false;
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if ($this->resolveAssetForSite($assetId, (int)$site->id)) {
                    $assetExists = true;
                    break;
                }
            }
            if (!$assetExists) {
                $skippedUnmatched[] = [
                    'index' => $index,
                    'assetId' => $assetId,
                    'fieldHandle' => $fieldHandle,
                    'reason' => 'No matching asset found.',
                ];
                continue;
            }

            $changedLanguages = [];
            foreach ($languages as $language) {
                if ((string)$beforeValues[$language] !== (string)$afterValues[$language]) {
                    $changedLanguages[] = $language;
                }
            }

            $previewItem = [
                'assetId' => $assetId,
                'fieldHandle' => $fieldHandle,
                'beforeValues' => $beforeValues,
                'afterValues' => $afterValues,
                'changedLanguages' => $changedLanguages,
            ];
            if (!empty($changedLanguages)) {
                $matchedChanged[] = $previewItem;
            } else {
                $matchedUnchanged[] = $previewItem;
            }
        }

        return [
            'totalItems' => count($items),
            'matchedChanged' => $matchedChanged,
            'matchedUnchanged' => $matchedUnchanged,
            'skippedUnmatched' => $skippedUnmatched,
            'invalidItems' => $invalidItems,
        ];
    }

    private function buildEntriesRowsForSite(
        int $selectedSiteId,
        string $search = '',
        string $scope = 'all',
        string $entryFilter = '',
        int $sectionId = 0,
        int $globalSetId = 0,
        int $categoryGroupId = 0,
        int $entryTypeId = 0
    ): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);
        $entries = [];
        $categories = [];
        $globalSets = [];

        if ($scope === 'all') {
            $entries = Entry::find()->siteId($selectedSiteId)->status(null)->all();
            $categories = Category::find()->siteId($selectedSiteId)->status(null)->all();
            $globalSets = GlobalSet::find()->siteId($selectedSiteId)->all();
        } elseif ($scope === 'section') {
            if ($sectionId > 0 && $this->isSectionAvailableForSite($sectionId, $selectedSiteId)) {
                $entries = Entry::find()->siteId($selectedSiteId)->sectionId($sectionId)->status(null)->all();
            }
        } elseif ($scope === 'global') {
            if ($globalSetId > 0) {
                $globalSet = $this->resolveGlobalSetForSite($globalSetId, $selectedSiteId);
                if ($globalSet) {
                    $globalSets = [$globalSet];
                }
            }
        } elseif ($scope === 'categoryGroup') {
            if ($categoryGroupId > 0) {
                $categories = Category::find()
                    ->siteId($selectedSiteId)
                    ->groupId($categoryGroupId)
                    ->status(null)
                    ->all();
            }
        } elseif ($scope === 'entryType') {
            $query = Entry::find()->siteId($selectedSiteId)->status(null);
            if ($entryTypeId > 0) {
                $query->typeId($entryTypeId);
            }
            $entries = $query->all();
        }

        $rows = [];
        foreach ($entries as $entry) {
            $this->appendElementRows($rows, $entry, 'entry', '', true);
        }
        foreach ($categories as $category) {
            $this->appendElementRows($rows, $category, 'category', '', true);
        }
        foreach ($globalSets as $globalSet) {
            $this->appendElementRows($rows, $globalSet, 'globalSet', '', false);
        }

        if ($scope === 'all' && $entryFilter !== '') {
            $rows = array_values(array_filter($rows, static function(array $row) use ($entryFilter): bool {
                return (string)($row['elementKey'] ?? '') === $entryFilter;
            }));
        }

        if ($search !== '') {
            [$siteEntries, $siteGlobalSets, $siteCategories, $siteTags] = $this->getSiteElementMapsForRows($rows, $languageMap);
            $this->populateRowsValues($rows, $languageMap, $siteEntries, $siteGlobalSets, $siteCategories, $siteTags);
            $rows = array_values(array_filter($rows, function(array $row) use ($search): bool {
                return $this->rowMatchesSearch($row, $search);
            }));
        }

        [$siteEntries, $siteGlobalSets, $siteCategories, $siteTags] = $this->getSiteElementMapsForRows($rows, $languageMap);
        $this->populateRowsValues($rows, $languageMap, $siteEntries, $siteGlobalSets, $siteCategories, $siteTags);

        return $rows;
    }

    private function buildAssetRowsForSite(int $selectedSiteId, string $search = ''): array
    {
        $defaultSite = Craft::$app->getSites()->getPrimarySite();
        $defaultSiteId = (int)$defaultSite->id;
        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        $assets = Asset::find()->siteId($selectedSiteId)->status(null)->all();
        $rows = [];
        foreach ($assets as $asset) {
            $this->appendAssetRows($rows, $asset, $defaultSiteId);
        }

        [$siteAssets, $defaultAssets] = $this->getSiteAssetMapsForRows($rows, $languageMap, $defaultSiteId);
        $this->populateAssetRowsValues($rows, $languageMap, $siteAssets, $defaultAssets);

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function(array $row) use ($search): bool {
                return $this->assetRowMatchesSearch($row, $search);
            }));
        }

        return $rows;
    }

    private function buildSeoRowsForSite(int $selectedSiteId, int $sectionId = 0, string $search = ''): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $languageMap = $this->getLanguageMap($sites);

        $entryQuery = Entry::find()->siteId($selectedSiteId)->status(null);
        if ($sectionId > 0) {
            $entryQuery->sectionId($sectionId);
        }
        if ($search !== '') {
            $entryQuery->search($search);
        }
        $entries = $entryQuery->all();

        $rows = [];
        foreach ($entries as $entry) {
            $layout = $entry->getFieldLayout();
            if (!$layout) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }
                $seoHandle = (string)$field->handle;
                $seoLabel = (string)$field->name;

                foreach ([
                    'title' => Craft::t('app', 'Title'),
                    'description' => Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.description'),
                ] as $property => $propertyLabel) {
                    $row = [
                        'elementType' => 'entry',
                        'elementId' => (int)$entry->id,
                        'elementKey' => 'entry:' . (int)$entry->id,
                        'element' => $entry,
                        'fieldHandle' => $this->buildSeoSubFieldHandle($seoHandle, $property),
                        'fieldLabel' => sprintf('%s: %s', $seoLabel, $propertyLabel),
                        'values' => [],
                    ];

                    foreach ($languages as $language) {
                        $value = '';
                        foreach ((array)($languageMap[$language] ?? []) as $siteId) {
                            $localizedEntry = $this->resolveEntryForSite((int)$entry->id, (int)$siteId);
                            if (!$localizedEntry instanceof Entry) {
                                continue;
                            }
                            $value = $this->readSeoSubFieldValue($localizedEntry, $seoHandle, $property);
                            break;
                        }
                        $row['values'][$language] = $value;
                    }
                    $rows[] = $row;
                }
            }
        }

        if ($search !== '') {
            $needle = mb_strtolower(trim($search));
            $rows = array_values(array_filter($rows, static function(array $row) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }
                $entry = $row['element'] ?? null;
                $title = is_object($entry) && isset($entry->title) ? (string)$entry->title : '';
                if ($title !== '' && mb_stripos($title, $needle) !== false) {
                    return true;
                }
                $label = (string)($row['fieldLabel'] ?? '');
                if ($label !== '' && mb_stripos($label, $needle) !== false) {
                    return true;
                }
                foreach ((array)($row['values'] ?? []) as $value) {
                    $text = (string)$value;
                    if ($text !== '' && mb_stripos($text, $needle) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return $rows;
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

    private function resolveAssetForSite(int $assetId, int $siteId): ?Asset
    {
        $asset = Asset::find()
            ->id($assetId)
            ->siteId($siteId)
            ->status(null)
            ->one();
        if ($asset instanceof Asset) {
            return $asset;
        }

        $baseElement = Craft::$app->getElements()->getElementById($assetId, Asset::class);
        if (!$baseElement instanceof Asset) {
            return null;
        }

        $uid = (string)($baseElement->uid ?? '');
        if ($uid === '') {
            return null;
        }

        $asset = Asset::find()
            ->uid($uid)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $asset instanceof Asset ? $asset : null;
    }

    private function resolveGlobalSetForSite(int $globalSetId, int $siteId): ?GlobalSet
    {
        $globalSet = GlobalSet::find()
            ->id($globalSetId)
            ->siteId($siteId)
            ->status(null)
            ->one();
        if ($globalSet instanceof GlobalSet) {
            return $globalSet;
        }

        $baseElement = Craft::$app->getElements()->getElementById($globalSetId, GlobalSet::class);
        if (!$baseElement instanceof GlobalSet) {
            return null;
        }

        $uid = (string)($baseElement->uid ?? '');
        if ($uid === '') {
            return null;
        }

        $globalSet = GlobalSet::find()
            ->uid($uid)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $globalSet instanceof GlobalSet ? $globalSet : null;
    }

    private function resolveCategoryForSite(int $categoryId, int $siteId): ?Category
    {
        $category = Category::find()
            ->id($categoryId)
            ->siteId($siteId)
            ->status(null)
            ->one();
        if ($category instanceof Category) {
            return $category;
        }

        $baseElement = Craft::$app->getElements()->getElementById($categoryId, Category::class);
        if (!$baseElement instanceof Category) {
            return null;
        }
        $uid = (string)($baseElement->uid ?? '');
        if ($uid === '') {
            return null;
        }

        $category = Category::find()
            ->uid($uid)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $category instanceof Category ? $category : null;
    }

    private function resolveTagForSite(int $tagId, int $siteId): ?Tag
    {
        $tag = Tag::find()
            ->id($tagId)
            ->siteId($siteId)
            ->status(null)
            ->one();
        if ($tag instanceof Tag) {
            return $tag;
        }

        $baseElement = Craft::$app->getElements()->getElementById($tagId, Tag::class);
        if (!$baseElement instanceof Tag) {
            return null;
        }
        $uid = (string)($baseElement->uid ?? '');
        if ($uid === '') {
            return null;
        }

        $tag = Tag::find()
            ->uid($uid)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $tag instanceof Tag ? $tag : null;
    }

    private function resolveElementByTypeForSite(string $elementType, int $elementId, int $siteId): mixed
    {
        $normalizedElementType = strtolower(trim($elementType));
        if ($normalizedElementType === 'globalset' || $normalizedElementType === 'global_set') {
            return $this->resolveGlobalSetForSite($elementId, $siteId);
        }
        if ($normalizedElementType === 'category') {
            return $this->resolveCategoryForSite($elementId, $siteId);
        }
        if ($normalizedElementType === 'tag') {
            return $this->resolveTagForSite($elementId, $siteId);
        }

        return $this->resolveEntryForSite($elementId, $siteId);
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
                $options[] = [
                    'value' => $this->buildMatrixFieldFilter((string)$field->handle, 'title'),
                    'label' => sprintf('%s: %s', (string)$field->name, Craft::t('app', 'Title')),
                ];
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
                    'value' => $this->buildLinkFieldHandle((string)$field->handle, 'label'),
                    'label' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.link-label')),
                ];
                continue;
            }
            $options[] = ['value' => $field->handle, 'label' => $field->name];
        }

        return $options;
    }

    private function getEntryOptionsFromRows(array $rows): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('app', 'All')],
        ];

        $seen = [];
        foreach ($rows as $row) {
            $value = (string)($row['elementKey'] ?? '');
            $elementType = (string)($row['elementType'] ?? 'entry');
            $element = $row['element'] ?? null;
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            if ($elementType === 'globalSet') {
                $name = is_object($element) && isset($element->name) ? (string)$element->name : Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.global-set');
                $label = sprintf('%s (%s)', $name, Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.global-set'));
            } elseif ($elementType === 'category') {
                $title = is_object($element) && isset($element->title) ? (string)$element->title : '';
                $groupName = is_object($element) ? (string)($element->group->name ?? '') : '';
                $label = trim($title !== '' ? $title : ('Category #' . (int)($row['elementId'] ?? 0)));
                if ($groupName !== '') {
                    $label .= sprintf(' (%s)', $groupName);
                }
            } elseif ($elementType === 'tag') {
                $title = is_object($element) && isset($element->title) ? (string)$element->title : '';
                $groupName = is_object($element) ? (string)($element->group->name ?? '') : '';
                $label = trim($title !== '' ? $title : ('Tag #' . (int)($row['elementId'] ?? 0)));
                if ($groupName !== '') {
                    $label .= sprintf(' (%s)', $groupName);
                }
            } else {
                $title = is_object($element) && isset($element->title) ? (string)$element->title : '';
                $meta = '';
                if (is_object($element)) {
                    try {
                        $meta = (string)($element->section->name ?? $element->type->name ?? '');
                    } catch (\Throwable) {
                        $meta = '';
                    }
                }
                $label = $title !== '' ? $title : Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.entry');
                if ($meta !== '') {
                    $label = sprintf('%s (%s)', $label, $meta);
                }
            }
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        usort($options, static function(array $a, array $b): int {
            if (($a['value'] ?? '') === '') {
                return -1;
            }
            if (($b['value'] ?? '') === '') {
                return 1;
            }

            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        return $options;
    }

    private function appendAssetRows(array &$rows, Asset $asset, int $defaultSiteId, bool $isUsed = false): void
    {
        $assetId = (int)$asset->id;
        $assetKey = 'asset:' . $assetId;
        $defaultAsset = $this->resolveAssetForSite($assetId, $defaultSiteId) ?? $asset;
        $rows[] = [
            'assetId' => $assetId,
            'assetKey' => $assetKey,
            'asset' => $asset,
            'defaultAsset' => $defaultAsset,
            'isUsed' => $isUsed,
            'fieldHandle' => 'title',
            'fieldLabel' => Craft::t('app', 'Title'),
        ];

        if ($this->assetHasAltValue($asset)) {
            $rows[] = [
                'assetId' => $assetId,
                'assetKey' => $assetKey,
                'asset' => $asset,
                'defaultAsset' => $defaultAsset,
                'isUsed' => $isUsed,
                'fieldHandle' => '__native_alt__',
                'fieldLabel' => Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.alt'),
            ];
        }
    }

    /**
     * @return int[]
     */
    private function getUsedAssetIdsForSite(int $siteId): array
    {
        $entryIds = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->ids();
        $entryIds = array_values(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0));
        if (empty($entryIds)) {
            return [];
        }

        $ids = (new \craft\db\Query())
            ->select(['r.targetId'])
            ->distinct()
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[r.targetId]]')
            ->where(['r.sourceId' => $entryIds])
            ->column();

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private function getSiteAssetMapsForRows(array $rows, array $languageMap, int $defaultSiteId): array
    {
        $assetIds = [];
        foreach ($rows as $row) {
            $assetId = (int)($row['assetId'] ?? 0);
            if ($assetId > 0) {
                $assetIds[$assetId] = true;
            }
        }
        $assetIds = array_keys($assetIds);

        $siteAssets = [];
        $defaultAssets = [];
        $allSiteIds = [];
        foreach ($languageMap as $siteIds) {
            foreach ($siteIds as $siteId) {
                $allSiteIds[(int)$siteId] = true;
            }
        }
        $allSiteIds[$defaultSiteId] = true;

        foreach (array_keys($allSiteIds) as $siteId) {
            foreach ($assetIds as $assetId) {
                $asset = $this->resolveAssetForSite((int)$assetId, (int)$siteId);
                if (!$asset instanceof Asset) {
                    continue;
                }
                $siteAssets[(int)$siteId][(int)$assetId] = $asset;
                if ((int)$siteId === $defaultSiteId) {
                    $defaultAssets[(int)$assetId] = $asset;
                }
            }
        }

        return [$siteAssets, $defaultAssets];
    }

    private function populateAssetRowsValues(array &$rows, array $languageMap, array $siteAssets, array $defaultAssets): void
    {
        foreach ($rows as &$row) {
            $row['values'] = [];
            $assetId = (int)($row['assetId'] ?? 0);
            $fieldHandle = (string)($row['fieldHandle'] ?? '');
            $row['defaultAsset'] = $defaultAssets[$assetId] ?? ($row['defaultAsset'] ?? null);
            foreach ($languageMap as $language => $siteIds) {
                $value = '';
                foreach ($siteIds as $siteId) {
                    $asset = $siteAssets[(int)$siteId][$assetId] ?? null;
                    if (!$asset instanceof Asset) {
                        continue;
                    }
                    $value = $this->getAssetFieldValueForHandle($asset, $fieldHandle);
                    break;
                }
                $row['values'][$language] = $value;
            }
        }
        unset($row);
    }

    private function getAssetFieldValueForHandle(Asset $asset, string $fieldHandle): string
    {
        if ($fieldHandle === 'title') {
            return (string)$asset->title;
        }
        if ($fieldHandle === '__native_alt__') {
            return (string)($this->getAssetAltValue($asset) ?? '');
        }

        return '';
    }

    private function saveAssetFieldValues(int $assetId, string $fieldHandle, array $values): array
    {
        $result = [
            'saved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'skipReasons' => [],
        ];
        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);
        foreach ($values as $language => $value) {
            if (!isset($languageMap[$language])) {
                $result['skipped']++;
                $this->addSkipReason($result, sprintf('No sites mapped for language "%s".', (string)$language));
                continue;
            }
            foreach ($languageMap[$language] as $siteId) {
                $asset = $this->resolveAssetForSite($assetId, (int)$siteId);
                if (!$asset instanceof Asset) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Asset %d not found for site %d.', $assetId, (int)$siteId));
                    continue;
                }
                try {
                    if ($fieldHandle === 'title') {
                        $asset->title = (string)$value;
                    } elseif ($fieldHandle === '__native_alt__') {
                        $this->setAssetAltValue($asset, (string)$value);
                    } else {
                        $result['skipped']++;
                        continue;
                    }
                    $savedOk = Craft::$app->getElements()->saveElement($asset, true, false, false);
                    if ($savedOk) {
                        $result['saved']++;
                    } else {
                        $result['failed']++;
                        $result['errors'][] = $this->buildElementSaveError($asset, sprintf('field %s', $fieldHandle));
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    private function assetRowMatchesSearch(array $row, string $search): bool
    {
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $asset = $row['defaultAsset'] ?? $row['asset'] ?? null;
        $haystacks = [
            (string)($row['fieldLabel'] ?? ''),
            is_object($asset) && isset($asset->filename) ? (string)$asset->filename : '',
            is_object($asset) && isset($asset->title) ? (string)$asset->title : '',
        ];
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        foreach ((array)($row['values'] ?? []) as $value) {
            $text = (string)$value;
            if ($text !== '' && mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function saveElementFieldValues(string $elementType, int $elementId, string $fieldHandle, array $values): array
    {
        $normalizedElementType = strtolower(trim($elementType));
        if ($normalizedElementType === 'globalset' || $normalizedElementType === 'global_set') {
            return $this->saveGlobalSetFieldValues($elementId, $fieldHandle, $values);
        }
        if ($normalizedElementType === 'category') {
            return $this->saveCategoryFieldValues($elementId, $fieldHandle, $values);
        }
        if ($normalizedElementType === 'tag') {
            return $this->saveTagFieldValues($elementId, $fieldHandle, $values);
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
        $seoSubFieldData = $this->parseSeoSubFieldHandle($fieldHandle);
        $nestedMatrixHandleData = $this->parseNestedMatrixFieldHandle($fieldHandle);
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
                $entry = $this->resolveEntryForSite($entryId, (int)$siteId);
                if (!$entry) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Entry %d not found for site %d.', $entryId, (int)$siteId));
                    continue;
                }
                if ($seoSubFieldData) {
                    [$seoHandle, $seoProperty] = $seoSubFieldData;
                    try {
                        $current = $entry->getFieldValue($seoHandle);
                        $normalized = $current instanceof SeoFieldValue
                            ? $current
                            : new SeoFieldValue(is_array($current) ? $current : []);
                        $normalized->{$seoProperty} = (string)$value;
                        $entry->setFieldValue($seoHandle, $normalized->toArray());
                        $savedOk = Craft::$app->getElements()->saveElement($entry, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($entry, sprintf('field %s.%s', $seoHandle, $seoProperty));
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                    }
                    continue;
                }
                if ($nestedMatrixHandleData) {
                    [$pathSegments, $leafFieldHandle, $leafLinkPart] = $nestedMatrixHandleData;
                    $block = $this->resolveNestedMatrixBlock($entry, $pathSegments);
                    if (!$block || !method_exists($block, 'getFieldValue')) {
                        $result['skipped']++;
                        $this->addSkipReason($result, 'Nested matrix block not found.');
                        continue;
                    }
                    try {
                        if ($leafFieldHandle === 'title') {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
                        } elseif ($leafLinkPart !== null) {
                            $leafField = $this->getMatrixSubField($block, $leafFieldHandle);
                            $current = $block->getFieldValue($leafFieldHandle);
                            $patched = $this->patchLinkFieldValueByField($leafField, $current, $leafLinkPart, (string)$value, $block);
                            $block->setFieldValue($leafFieldHandle, $patched);
                        } else {
                            $block->setFieldValue($leafFieldHandle, (string)$value);
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, false);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($block, sprintf('field %s', $leafFieldHandle));
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                    }
                    continue;
                }
                if ($linkHandleData) {
                    [$linkFieldHandle, $linkPart] = $linkHandleData;
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
                    if ($subFieldHandle === 'title') {
                        try {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
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
                                    'Skipping matrix block title save for entryId=%d siteId=%d matrix=%s blockIndex=%d: %s',
                                    $entryId,
                                    (int)$siteId,
                                    $matrixHandle,
                                    (int)$blockIndex,
                                    $e->getMessage()
                                ),
                                __METHOD__
                            );
                        }
                        continue;
                    }
                    if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix subfield "%s" not found in block %d.', $subFieldHandle, (int)$blockIndex));
                        continue;
                    }
                    try {
                        $subField = $this->getMatrixSubField($block, $subFieldHandle);
                        if ($subField && $this->isLinkLikeField($subField)) {
                            $current = $block->getFieldValue($subFieldHandle);
                            $patched = $this->patchLinkFieldValueByField($subField, $current, 'label', (string)$value, $block);
                            $block->setFieldValue($subFieldHandle, $patched);
                        } else {
                            $block->setFieldValue($subFieldHandle, (string)$value);
                        }
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

    private function saveCategoryFieldValues(int $categoryId, string $fieldHandle, array $values): array
    {
        return $this->saveGenericElementFieldValues(
            fn(int $siteId): ?Category => $this->resolveCategoryForSite($categoryId, $siteId),
            $fieldHandle,
            $values,
            'Category'
        );
    }

    private function saveTagFieldValues(int $tagId, string $fieldHandle, array $values): array
    {
        return $this->saveGenericElementFieldValues(
            fn(int $siteId): ?Tag => $this->resolveTagForSite($tagId, $siteId),
            $fieldHandle,
            $values,
            'Tag'
        );
    }

    private function saveGenericElementFieldValues(callable $resolver, string $fieldHandle, array $values, string $elementLabel): array
    {
        $result = [
            'saved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'skipReasons' => [],
        ];
        $nestedMatrixHandleData = $this->parseNestedMatrixFieldHandle($fieldHandle);
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
                $element = $resolver((int)$siteId);
                if (!$element || !is_object($element) || !method_exists($element, 'setFieldValue')) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('%s not found for site %d.', $elementLabel, (int)$siteId));
                    continue;
                }

                try {
                    if ($nestedMatrixHandleData) {
                        [$pathSegments, $leafFieldHandle, $leafLinkPart] = $nestedMatrixHandleData;
                        $block = $this->resolveNestedMatrixBlock($element, $pathSegments);
                        if (!$block || !method_exists($block, 'getFieldValue')) {
                            $result['skipped']++;
                            $this->addSkipReason($result, 'Nested matrix block not found.');
                            continue;
                        }
                        if ($leafFieldHandle === 'title') {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
                        } elseif ($leafLinkPart !== null) {
                            $leafField = $this->getMatrixSubField($block, $leafFieldHandle);
                            $current = $block->getFieldValue($leafFieldHandle);
                            $patched = $this->patchLinkFieldValueByField($leafField, $current, $leafLinkPart, (string)$value, $block);
                            $block->setFieldValue($leafFieldHandle, $patched);
                        } else {
                            $block->setFieldValue($leafFieldHandle, (string)$value);
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, false);
                    } elseif ($linkHandleData) {
                        [$linkFieldHandle, $linkPart] = $linkHandleData;
                        $current = $element->getFieldValue($linkFieldHandle);
                        $field = $element->getFieldLayout()?->getFieldByHandle($linkFieldHandle);
                        $patched = $this->patchLinkFieldValueByField($field, $current, $linkPart, (string)$value, $element);
                        $element->setFieldValue($linkFieldHandle, $patched);
                        $savedOk = Craft::$app->getElements()->saveElement($element, false, false);
                    } elseif ($matrixHandleData) {
                        [$matrixHandle, $blockIndex, $subFieldHandle] = $matrixHandleData;
                        $blocks = $this->getMatrixBlocksForElement($element, $matrixHandle);
                        $block = $blocks[$blockIndex] ?? null;
                        if (!$block) {
                            $result['skipped']++;
                            $this->addSkipReason($result, sprintf('Matrix block %d not found for field "%s".', (int)$blockIndex, $matrixHandle));
                            continue;
                        }
                        if ($subFieldHandle === 'title') {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
                        } else {
                            if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                                $result['skipped']++;
                                $this->addSkipReason($result, sprintf('Matrix subfield "%s" not found in block %d.', $subFieldHandle, (int)$blockIndex));
                                continue;
                            }
                            $subField = $this->getMatrixSubField($block, $subFieldHandle);
                            if ($subField && $this->isLinkLikeField($subField)) {
                                $current = $block->getFieldValue($subFieldHandle);
                                $patched = $this->patchLinkFieldValueByField($subField, $current, 'label', (string)$value, $block);
                                $block->setFieldValue($subFieldHandle, $patched);
                            } else {
                                $block->setFieldValue($subFieldHandle, (string)$value);
                            }
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, false);
                    } else {
                        if ($fieldHandle === 'title' && property_exists($element, 'title')) {
                            $element->title = (string)$value;
                        } else {
                            $element->setFieldValue($fieldHandle, (string)$value);
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($element, false, false);
                    }

                    if ($savedOk) {
                        $result['saved']++;
                    } else {
                        $result['failed']++;
                        $result['errors'][] = $this->buildElementSaveError($element, sprintf('field %s', $fieldHandle));
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    private function isEligibleTranslatableField(mixed $field, string $fieldFilter = ''): bool
    {
        $className = get_class($field);
        $isLinkLike = $this->isLinkLikeField($field);
        $isEligibleType = ($field instanceof PlainText) || ($field instanceof Table) || ($className === 'craft\\ckeditor\\Field') || $isLinkLike;
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

    private function getEligibleMatrixSubFieldsForBlock(mixed $block, string $matrixHandle, string $fieldFilter = ''): array
    {
        if (!is_object($block) || !method_exists($block, 'getFieldLayout')) {
            return [];
        }

        if ($fieldFilter !== '' && $fieldFilter !== 'title') {
            $titleFilter = $this->buildMatrixFieldFilter($matrixHandle, 'title');
            if ($fieldFilter === $titleFilter) {
                return ['title'];
            }
        }

        try {
            $layout = $block->getFieldLayout();
            $fields = $layout ? $layout->getCustomFields() : [];
        } catch (\Throwable) {
            return [];
        }

        $eligible = [];
        foreach ($fields as $subField) {
            if (!$this->isEligibleTranslatableField($subField)) {
                continue;
            }
            if ($fieldFilter !== '' && $fieldFilter !== 'title') {
                $filterValue = $this->buildMatrixFieldFilter($matrixHandle, (string)$subField->handle);
                if ($fieldFilter !== $filterValue) {
                    continue;
                }
            }
            $eligible[(string)$subField->handle] = $subField;
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
            $value
                ->status(null)
                ->drafts(null)
                ->provisionalDrafts(null)
                ->revisions(null)
                ->trashed(null)
                ->unique(false);
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
            if ($fieldHandle === 'title' && ($element instanceof Entry || $element instanceof Category || $element instanceof Tag)) {
                return (string)$element->title;
            }
            if (!is_object($element) || !method_exists($element, 'getFieldValue')) {
                return '';
            }
            $seoSubFieldData = $this->parseSeoSubFieldHandle($fieldHandle);
            if ($seoSubFieldData && $element instanceof Entry) {
                [$seoHandle, $seoProperty] = $seoSubFieldData;
                return $this->readSeoSubFieldValue($element, $seoHandle, $seoProperty);
            }
            $nestedMatrixHandleData = $this->parseNestedMatrixFieldHandle($fieldHandle);
            if ($nestedMatrixHandleData) {
                [$pathSegments, $leafFieldHandle, $leafLinkPart] = $nestedMatrixHandleData;
                $block = $this->resolveNestedMatrixBlock($element, $pathSegments);
                if (!$block || !method_exists($block, 'getFieldValue')) {
                    return '';
                }
                if ($leafFieldHandle === 'title' && !$this->matrixBlockHasSubField($block, 'title')) {
                    return is_object($block) && isset($block->title) ? (string)$block->title : '';
                }
                $leafValue = $block->getFieldValue($leafFieldHandle);
                if ($leafLinkPart !== null) {
                    return $this->extractLinkFieldPart($leafValue, $leafLinkPart);
                }

                return $this->stringifyFieldValue($leafValue);
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
            if ($subFieldHandle === 'title' && !$this->matrixBlockHasSubField($block, 'title')) {
                return is_object($block) && isset($block->title) ? (string)$block->title : '';
            }
            if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                return '';
            }

            $subField = $this->getMatrixSubField($block, $subFieldHandle);
            $subValue = $block->getFieldValue($subFieldHandle);
            if ($subField && $this->isLinkLikeField($subField)) {
                return $this->extractLinkFieldPart($subValue, 'label');
            }

            return $this->stringifyFieldValue($subValue);
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

    private function getMatrixSubField(mixed $block, string $subFieldHandle): mixed
    {
        if (!is_object($block) || !method_exists($block, 'getFieldLayout')) {
            return null;
        }

        try {
            $layout = $block->getFieldLayout();
            if (!$layout || !method_exists($layout, 'getFieldByHandle')) {
                return null;
            }

            return $layout->getFieldByHandle($subFieldHandle);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildMatrixFieldHandle(string $matrixHandle, int $blockIndex, string $subFieldHandle): string
    {
        return sprintf('matrix::%s::%d::%s', $matrixHandle, $blockIndex, $subFieldHandle);
    }

    private function buildNestedMatrixFieldHandle(array $pathSegments, string $fieldHandle, ?string $linkPart = null): string
    {
        $parts = ['matrixpath'];
        foreach ($pathSegments as $segment) {
            $parts[] = (string)$segment[0];
            $parts[] = (string)$segment[1];
        }
        if ($linkPart !== null) {
            $parts[] = 'linkfield';
        }
        $parts[] = $fieldHandle;
        if ($linkPart !== null) {
            $parts[] = $linkPart;
        }

        return implode('::', $parts);
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

    private function parseNestedMatrixFieldHandle(string $fieldHandle): ?array
    {
        if (!str_starts_with($fieldHandle, 'matrixpath::')) {
            return null;
        }

        $parts = explode('::', $fieldHandle);
        array_shift($parts);
        if (count($parts) < 3) {
            return null;
        }

        $leafLinkPart = null;
        $leafFieldHandle = '';
        $partCount = count($parts);
        // Current format generated by buildNestedMatrixFieldHandle():
        // matrixpath::<matrix>::<index>::...::linkfield::<fieldHandle>::<part>
        if (
            $partCount >= 3
            && $parts[$partCount - 3] === 'linkfield'
        ) {
            $leafFieldHandle = (string)$parts[$partCount - 2];
            $leafLinkPart = (string)$parts[$partCount - 1];
            array_splice($parts, -3);
        } else {
            // Backward-compatible format:
            // matrixpath::<matrix>::<index>::...::<fieldHandle>::linkfield::<part>
            $leafFieldHandle = (string)array_pop($parts);
            if (!empty($parts) && end($parts) === 'linkfield') {
                array_pop($parts);
                $leafLinkPart = $leafFieldHandle;
                $leafFieldHandle = (string)array_pop($parts);
            }
        }

        if (count($parts) < 2 || count($parts) % 2 !== 0) {
            return null;
        }

        $pathSegments = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $pathSegments[] = [(string)$parts[$i], (int)$parts[$i + 1]];
        }

        return [$pathSegments, (string)$leafFieldHandle, $leafLinkPart];
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
                    'fieldHandle' => $this->buildLinkFieldHandle((string)$field->handle, 'label'),
                    'fieldLabel' => sprintf('%s: %s', (string)$field->name, Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.link-label')),
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

            foreach ($blocks as $blockIndex => $block) {
                $this->appendNestedMatrixBlockRows(
                    $rows,
                    $block,
                    $element,
                    $elementType,
                    $elementId,
                    $elementKey,
                    [[(string)$matrixField->handle, (int)$blockIndex]],
                    sprintf('%s #%d', (string)$matrixField->name, $blockIndex + 1),
                    $fieldFilter,
                );
            }
        }
    }

    private function appendNestedMatrixBlockRows(
        array &$rows,
        mixed $block,
        mixed $rootElement,
        string $elementType,
        int $elementId,
        string $elementKey,
        array $pathSegments,
        string $labelPrefix,
        string $fieldFilter,
    ): void {
        if (!is_object($block) || !method_exists($block, 'getFieldLayout')) {
            return;
        }

        $currentMatrixHandle = (string)$pathSegments[count($pathSegments) - 1][0];
        $titleFilter = $this->buildMatrixFieldFilter($currentMatrixHandle, 'title');
        if ($fieldFilter === '' || $fieldFilter === 'title' || $fieldFilter === $titleFilter) {
            $rows[] = [
                'elementType' => $elementType,
                'elementId' => $elementId,
                'elementKey' => $elementKey,
                'element' => $rootElement,
                'fieldHandle' => $this->buildNestedMatrixFieldHandle($pathSegments, 'title'),
                'fieldLabel' => sprintf('%s: %s', $labelPrefix, Craft::t('app', 'Title')),
            ];
        }

        $layout = $block->getFieldLayout();
        $fields = $layout ? $layout->getCustomFields() : [];
        foreach ($fields as $field) {
            if ($this->isMatrixField($field)) {
                $nestedBlocks = $this->getMatrixBlocksForElement($block, (string)$field->handle);
                foreach ($nestedBlocks as $nestedIndex => $nestedBlock) {
                    $nestedPath = $pathSegments;
                    $nestedPath[] = [(string)$field->handle, (int)$nestedIndex];
                    $this->appendNestedMatrixBlockRows(
                        $rows,
                        $nestedBlock,
                        $rootElement,
                        $elementType,
                        $elementId,
                        $elementKey,
                        $nestedPath,
                        sprintf('%s: %s #%d', $labelPrefix, (string)$field->name, $nestedIndex + 1),
                        $fieldFilter,
                    );
                }
                continue;
            }

            if (!$this->isEligibleTranslatableField($field)) {
                continue;
            }

            if ($fieldFilter !== '' && $fieldFilter !== 'title') {
                $filterValue = $this->buildMatrixFieldFilter($currentMatrixHandle, (string)$field->handle);
                if ($fieldFilter !== $filterValue) {
                    continue;
                }
            }

            if ($this->isLinkLikeField($field)) {
                $rows[] = [
                    'elementType' => $elementType,
                    'elementId' => $elementId,
                    'elementKey' => $elementKey,
                    'element' => $rootElement,
                    'fieldHandle' => $this->buildNestedMatrixFieldHandle($pathSegments, (string)$field->handle, 'label'),
                    'fieldLabel' => sprintf('%s: %s: %s', $labelPrefix, (string)$field->name, Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.link-label')),
                ];
                continue;
            }

            $rows[] = [
                'elementType' => $elementType,
                'elementId' => $elementId,
                'elementKey' => $elementKey,
                'element' => $rootElement,
                'fieldHandle' => $this->buildNestedMatrixFieldHandle($pathSegments, (string)$field->handle),
                'fieldLabel' => sprintf('%s: %s', $labelPrefix, (string)$field->name),
            ];
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
        $nestedMatrixHandleData = $this->parseNestedMatrixFieldHandle($fieldHandle);
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
                $globalSet = $this->resolveGlobalSetForSite($globalSetId, (int)$siteId);
                if (!$globalSet instanceof GlobalSet) {
                    $result['skipped']++;
                    $this->addSkipReason($result, sprintf('Global set %d not found for site %d.', $globalSetId, (int)$siteId));
                    continue;
                }
                if ($nestedMatrixHandleData) {
                    [$pathSegments, $leafFieldHandle, $leafLinkPart] = $nestedMatrixHandleData;
                    $block = $this->resolveNestedMatrixBlock($globalSet, $pathSegments);
                    if (!$block || !method_exists($block, 'getFieldValue')) {
                        $result['skipped']++;
                        $this->addSkipReason($result, 'Nested matrix block not found.');
                        continue;
                    }
                    try {
                        if ($leafFieldHandle === 'title') {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
                        } elseif ($leafLinkPart !== null) {
                            $leafField = $this->getMatrixSubField($block, $leafFieldHandle);
                            $current = $block->getFieldValue($leafFieldHandle);
                            $patched = $this->patchLinkFieldValueByField($leafField, $current, $leafLinkPart, (string)$value, $block);
                            $block->setFieldValue($leafFieldHandle, $patched);
                        } else {
                            $block->setFieldValue($leafFieldHandle, (string)$value);
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, true);
                        if ($savedOk) {
                            $result['saved']++;
                        } else {
                            $result['failed']++;
                            $result['errors'][] = $this->buildElementSaveError($block, sprintf('field %s', $leafFieldHandle));
                        }
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = $e->getMessage();
                    }
                    continue;
                }
                if ($linkHandleData) {
                    [$linkFieldHandle, $linkPart] = $linkHandleData;
                    try {
                        $current = $globalSet->getFieldValue($linkFieldHandle);
                        $field = $globalSet->getFieldLayout()?->getFieldByHandle($linkFieldHandle);
                        $patched = $this->patchLinkFieldValueByField($field, $current, $linkPart, (string)$value, $globalSet);
                        $globalSet->setFieldValue($linkFieldHandle, $patched);
                        $savedOk = Craft::$app->getElements()->saveElement($globalSet, false, true);
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
                    if (!$block) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix block %d not found for field "%s".', (int)$blockIndex, $matrixHandle));
                        continue;
                    }
                    if ($subFieldHandle === 'title') {
                        try {
                            $block->title = (string)$value;
                            if ($this->matrixBlockHasSubField($block, 'title')) {
                                $block->setFieldValue('title', (string)$value);
                            }
                            $savedOk = Craft::$app->getElements()->saveElement($block, false, true);
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
                    if (!$this->matrixBlockHasSubField($block, $subFieldHandle)) {
                        $result['skipped']++;
                        $this->addSkipReason($result, sprintf('Matrix subfield "%s" not found in block %d.', $subFieldHandle, (int)$blockIndex));
                        continue;
                    }
                    try {
                        $subField = $this->getMatrixSubField($block, $subFieldHandle);
                        if ($subField && $this->isLinkLikeField($subField)) {
                            $current = $block->getFieldValue($subFieldHandle);
                            $patched = $this->patchLinkFieldValueByField($subField, $current, 'label', (string)$value, $block);
                            $block->setFieldValue($subFieldHandle, $patched);
                        } else {
                            $block->setFieldValue($subFieldHandle, (string)$value);
                        }
                        $savedOk = Craft::$app->getElements()->saveElement($block, false, true);
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
                    $savedOk = Craft::$app->getElements()->saveElement($globalSet, false, true);
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
        $sectionRaw = $request->getBodyParam('section', $request->getQueryParam('section', ''));
        $section = is_string($sectionRaw) ? trim($sectionRaw) : (string)$sectionRaw;
        $params = [
            'q' => (string)$request->getBodyParam('q', $request->getQueryParam('q', '')),
            'perPage' => (int)$request->getBodyParam('perPage', $request->getQueryParam('perPage', 50)),
            'page' => (int)$request->getBodyParam('page', $request->getQueryParam('page', 1)),
            'section' => $section,
            'entry' => (string)$request->getBodyParam('entry', $request->getQueryParam('entry', '')),
            'site' => (string)$request->getBodyParam('site', $request->getQueryParam('site', '')),
        ];

        if ($params['site'] === '') {
            $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
            $params['site'] = (string)$selectedSite->handle;
        }

        return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/translations/entries', $params));
    }

    private function redirectAssetsIndexWithCurrentFilters(): Response
    {
        $request = Craft::$app->getRequest();
        $params = [
            'q' => (string)$request->getBodyParam('q', $request->getQueryParam('q', '')),
            'perPage' => (int)$request->getBodyParam('perPage', $request->getQueryParam('perPage', 50)),
            'page' => (int)$request->getBodyParam('page', $request->getQueryParam('page', 1)),
            'site' => (string)$request->getBodyParam('site', $request->getQueryParam('site', '')),
        ];

        if ($params['site'] === '') {
            $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
            $params['site'] = (string)$selectedSite->handle;
        }

        return $this->redirect(UrlHelper::cpUrl('pragmatic-toolkit/translations/assets', $params));
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

        return sprintf('%s: %s', $context, Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.could-not-save-element'));
    }

    private function diagnoseElementSectionIntegrity(mixed $element, string $path, array &$issues, int $depth): void
    {
        if ($depth > 4 || !is_object($element)) {
            return;
        }

        $elementId = isset($element->id) ? (int)$element->id : 0;
        $elementSiteId = isset($element->siteId) ? (int)$element->siteId : 0;
        $className = get_class($element);

        if ($element instanceof Entry) {
            try {
                $section = $element->getSection();
                if (!$section) {
                    $issues[] = [
                        'type' => 'missingSection',
                        'path' => $path,
                        'elementClass' => $className,
                        'elementId' => $elementId,
                        'siteId' => $elementSiteId,
                        'message' => 'Entry section is null.',
                    ];
                }
            } catch (\Throwable $e) {
                $issues[] = [
                    'type' => 'invalidSection',
                    'path' => $path,
                    'elementClass' => $className,
                    'elementId' => $elementId,
                    'siteId' => $elementSiteId,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if (!method_exists($element, 'getFieldLayout')) {
            return;
        }

        try {
            $layout = $element->getFieldLayout();
            $fields = $layout ? $layout->getCustomFields() : [];
        } catch (\Throwable $e) {
            $issues[] = [
                'type' => 'fieldLayoutError',
                'path' => $path,
                'elementClass' => $className,
                'elementId' => $elementId,
                'siteId' => $elementSiteId,
                'message' => $e->getMessage(),
            ];
            return;
        }

        foreach ($fields as $field) {
            if (!$this->isMatrixField($field)) {
                continue;
            }

            $matrixHandle = (string)$field->handle;
            try {
                $blocks = $this->getMatrixBlocksForElement($element, $matrixHandle);
            } catch (\Throwable $e) {
                $issues[] = [
                    'type' => 'matrixLoadError',
                    'path' => $path . '.' . $matrixHandle,
                    'elementClass' => $className,
                    'elementId' => $elementId,
                    'siteId' => $elementSiteId,
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            foreach ($blocks as $index => $block) {
                $childPath = sprintf('%s.%s[%d]', $path, $matrixHandle, (int)$index);
                $this->diagnoseElementSectionIntegrity($block, $childPath, $issues, $depth + 1);
            }
        }
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
        $base = Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.no-values-were-saved');
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
                $blocks = $this->getMatrixBlocksForElement($entry, (string)$field->handle);
                foreach ($blocks as $block) {
                    if (!empty($this->getEligibleMatrixSubFieldsForBlock($block, (string)$field->handle, $fieldFilter))) {
                        return true;
                    }
                }
                continue;
            }
            if ($this->isEligibleTranslatableField($field, $fieldFilter)) {
                return true;
            }
        }

        return false;
    }

    private function globalSetHasEligibleTranslatableFields(GlobalSet $globalSet, string $fieldFilter = ''): bool
    {
        if ($fieldFilter === 'title') {
            return false;
        }

        foreach ($globalSet->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isMatrixField($field)) {
                $blocks = $this->getMatrixBlocksForElement($globalSet, (string)$field->handle);
                foreach ($blocks as $block) {
                    if (!empty($this->getEligibleMatrixSubFieldsForBlock($block, (string)$field->handle, $fieldFilter))) {
                        return true;
                    }
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
            $rows[(string)$id] = ['id' => (string)$id, 'name' => (string)$section->name, 'count' => $sectionCounts[$id] ?? 0];
        }
        $rows['categories'] = [
            'id' => 'categories',
            'name' => 'Categorias',
            'count' => $this->getCategoriesAndTagsCountForSite($siteId, $fieldFilter),
        ];
        $rows['entryTypes'] = [
            'id' => 'entryTypes',
            'name' => 'Entry types',
            'count' => $this->getEntriesCountForEntryTypesFilter($siteId, $fieldFilter),
        ];
        $rows['globals'] = [
            'id' => 'globals',
            'name' => Craft::t('pragmatic-web-toolkit', 'controllers.translations-controller.globals'),
            'count' => $this->getGlobalSetsCountForSite($siteId, $fieldFilter),
        ];

        usort($rows, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return array_values($rows);
    }

    private function getSeoSectionsForSite(int $siteId): array
    {
        $sectionCounts = [];
        $entries = Entry::find()->siteId($siteId)->status(null)->all();
        foreach ($entries as $entry) {
            if (!$this->entryHasSeoFields($entry)) {
                continue;
            }

            $section = $entry->getSection();
            if (!$section) {
                continue;
            }

            $sectionId = (int)$section->id;
            $sectionCounts[$sectionId] = ($sectionCounts[$sectionId] ?? 0) + 1;
        }

        $rows = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$this->isSectionActiveForSite($section, $siteId)) {
                continue;
            }
            $sectionId = (int)$section->id;
            $count = (int)($sectionCounts[$sectionId] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $rows[] = [
                'id' => (string)$sectionId,
                'name' => (string)$section->name,
                'count' => $count,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        return $rows;
    }

    private function entryHasSeoFields(Entry $entry): bool
    {
        $layout = $entry->getFieldLayout();
        if (!$layout) {
            return false;
        }
        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof SeoField) {
                return true;
            }
        }

        return false;
    }

    private function getGlobalSetsCountForSite(int $siteId, string $fieldFilter = ''): int
    {
        $count = 0;
        $globalSets = GlobalSet::find()->siteId($siteId)->all();
        foreach ($globalSets as $globalSet) {
            if ($this->globalSetHasEligibleTranslatableFields($globalSet, $fieldFilter)) {
                $count++;
            }
        }

        return $count;
    }

    private function getCategoriesAndTagsCountForSite(int $siteId, string $fieldFilter = ''): int
    {
        $count = 0;
        $categories = Category::find()->siteId($siteId)->status(null)->all();
        foreach ($categories as $category) {
            if ($this->categoryOrTagHasEligibleTranslatableFields($category, $fieldFilter)) {
                $count++;
            }
        }
        $tags = Tag::find()->siteId($siteId)->status(null)->all();
        foreach ($tags as $tag) {
            if ($this->categoryOrTagHasEligibleTranslatableFields($tag, $fieldFilter)) {
                $count++;
            }
        }

        return $count;
    }

    private function getEntryTypeOptionsForSite(int $siteId): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('app', 'All')],
        ];
        $seen = [];
        $entries = Entry::find()->siteId($siteId)->status(null)->all();
        foreach ($entries as $entry) {
            $typeId = (int)($entry->typeId ?? 0);
            if ($typeId <= 0 || isset($seen[$typeId])) {
                continue;
            }
            if (!$this->entryHasEligibleTranslatableFields($entry)) {
                continue;
            }
            $typeName = (string)($entry->type->name ?? ('Type #' . $typeId));
            $options[] = ['value' => (string)$typeId, 'label' => $typeName];
            $seen[$typeId] = true;
        }

        usort($options, static function(array $a, array $b): int {
            if ($a['value'] === '') {
                return -1;
            }
            if ($b['value'] === '') {
                return 1;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return $options;
    }

    private function buildEntriesSidebar(int $siteId): array
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$this->isSectionActiveForSite($section, $siteId)) {
                continue;
            }
            $count = $this->getEntriesCountForSection($siteId, (int)$section->id);
            $sections[] = ['id' => (int)$section->id, 'name' => (string)$section->name, 'count' => $count];
        }

        $globals = [];
        foreach (GlobalSet::find()->siteId($siteId)->all() as $globalSet) {
            $globals[] = [
                'id' => (int)$globalSet->id,
                'name' => (string)$globalSet->name,
                'count' => $this->globalSetHasEligibleTranslatableFields($globalSet) ? 1 : 0,
            ];
        }

        $categories = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $categoryGroup) {
            $categories[] = [
                'id' => (int)$categoryGroup->id,
                'name' => (string)$categoryGroup->name,
                'count' => $this->getCategoriesCountForGroup($siteId, (int)$categoryGroup->id),
            ];
        }

        $entryTypes = [];
        foreach ($this->getEntryTypeOptionsForSite($siteId) as $option) {
            if ((string)$option['value'] === '') {
                continue;
            }
            $entryTypes[] = [
                'id' => (int)$option['value'],
                'name' => (string)$option['label'],
                'count' => $this->getEntriesCountForEntryType($siteId, (int)$option['value']),
            ];
        }

        return [
            'sections' => $sections,
            'globals' => $globals,
            'categories' => $categories,
            'entryTypes' => $entryTypes,
        ];
    }

    private function getEntriesCountForSection(int $siteId, int $sectionId): int
    {
        $count = 0;
        foreach (Entry::find()->siteId($siteId)->sectionId($sectionId)->status(null)->all() as $entry) {
            if ($this->entryHasEligibleTranslatableFields($entry)) {
                $count++;
            }
        }
        return $count;
    }

    private function getEntriesCountForEntryType(int $siteId, int $entryTypeId): int
    {
        $count = 0;
        foreach (Entry::find()->siteId($siteId)->typeId($entryTypeId)->status(null)->all() as $entry) {
            if ($this->entryHasEligibleTranslatableFields($entry)) {
                $count++;
            }
        }
        return $count;
    }

    private function getCategoriesCountForGroup(int $siteId, int $groupId): int
    {
        $count = 0;
        foreach (Category::find()->siteId($siteId)->groupId($groupId)->status(null)->all() as $category) {
            if ($this->categoryOrTagHasEligibleTranslatableFields($category)) {
                $count++;
            }
        }

        return $count;
    }

    private function getEntriesCountForEntryTypesFilter(int $siteId, string $fieldFilter = ''): int
    {
        $count = 0;
        $entries = Entry::find()->siteId($siteId)->status(null)->all();
        foreach ($entries as $entry) {
            if ($this->entryHasEligibleTranslatableFields($entry, $fieldFilter)) {
                $count++;
            }
        }

        return $count;
    }

    private function categoryOrTagHasEligibleTranslatableFields(mixed $element, string $fieldFilter = ''): bool
    {
        if ($fieldFilter === '' || $fieldFilter === 'title') {
            return true;
        }
        if (!is_object($element) || !method_exists($element, 'getFieldLayout')) {
            return false;
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isMatrixField($field)) {
                $blocks = $this->getMatrixBlocksForElement($element, (string)$field->handle);
                foreach ($blocks as $block) {
                    if (!empty($this->getEligibleMatrixSubFieldsForBlock($block, (string)$field->handle, $fieldFilter))) {
                        return true;
                    }
                }
                continue;
            }
            if ($this->isEligibleTranslatableField($field, $fieldFilter)) {
                return true;
            }
        }

        return false;
    }

    private function getSiteElementMapsForRows(array $rows, array $languageMap): array
    {
        $entryIds = [];
        $globalSetIds = [];
        $categoryIds = [];
        $tagIds = [];
        foreach ($rows as $row) {
            $elementType = (string)($row['elementType'] ?? 'entry');
            $elementId = (int)($row['elementId'] ?? 0);
            if ($elementId <= 0) {
                continue;
            }
            if ($elementType === 'globalSet') {
                $globalSetIds[$elementId] = true;
            } elseif ($elementType === 'category') {
                $categoryIds[$elementId] = true;
            } elseif ($elementType === 'tag') {
                $tagIds[$elementId] = true;
            } else {
                $entryIds[$elementId] = true;
            }
        }

        $entryIds = array_keys($entryIds);
        $globalSetIds = array_keys($globalSetIds);
        $categoryIds = array_keys($categoryIds);
        $tagIds = array_keys($tagIds);
        $siteEntries = [];
        $siteGlobalSets = [];
        $siteCategories = [];
        $siteTags = [];
        $allSiteIds = [];
        foreach ($languageMap as $siteIds) {
            foreach ($siteIds as $siteId) {
                $allSiteIds[(int)$siteId] = true;
            }
        }

        foreach (array_keys($allSiteIds) as $siteId) {
            if (!empty($entryIds)) {
                $siteRows = Entry::find()->id($entryIds)->siteId($siteId)->status(null)->all();
                foreach ($siteRows as $siteRow) {
                    $siteEntries[$siteId][$siteRow->id] = $siteRow;
                }
            }
            if (!empty($globalSetIds)) {
                $globalRows = GlobalSet::find()->id($globalSetIds)->siteId($siteId)->all();
                foreach ($globalRows as $globalRow) {
                    $siteGlobalSets[$siteId][$globalRow->id] = $globalRow;
                }
            }
            if (!empty($categoryIds)) {
                $categoryRows = Category::find()->id($categoryIds)->siteId($siteId)->status(null)->all();
                foreach ($categoryRows as $categoryRow) {
                    $siteCategories[$siteId][$categoryRow->id] = $categoryRow;
                }
            }
            if (!empty($tagIds)) {
                $tagRows = Tag::find()->id($tagIds)->siteId($siteId)->status(null)->all();
                foreach ($tagRows as $tagRow) {
                    $siteTags[$siteId][$tagRow->id] = $tagRow;
                }
            }
        }

        return [$siteEntries, $siteGlobalSets, $siteCategories, $siteTags];
    }

    private function resolveNestedMatrixBlock(mixed $element, array $pathSegments): mixed
    {
        $current = $element;
        foreach ($pathSegments as $segment) {
            [$matrixHandle, $blockIndex] = $segment;
            $blocks = $this->getMatrixBlocksForElement($current, (string)$matrixHandle);
            $current = $blocks[(int)$blockIndex] ?? null;
            if (!$current) {
                return null;
            }
        }

        return $current;
    }

    private function populateRowsValues(array &$rows, array $languageMap, array $siteEntries, array $siteGlobalSets, array $siteCategories = [], array $siteTags = []): void
    {
        foreach ($rows as &$row) {
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
                    } elseif ($elementType === 'category') {
                        $category = $siteCategories[$siteId][$elementId] ?? null;
                        if ($category instanceof Category) {
                            $value = $this->getElementFieldValueForHandle($category, (string)$row['fieldHandle']);
                            break;
                        }
                    } elseif ($elementType === 'tag') {
                        $tag = $siteTags[$siteId][$elementId] ?? null;
                        if ($tag instanceof Tag) {
                            $value = $this->getElementFieldValueForHandle($tag, (string)$row['fieldHandle']);
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
    }

    private function rowMatchesSearch(array $row, string $search): bool
    {
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            (string)($row['fieldLabel'] ?? ''),
            (string)($row['fieldHandle'] ?? ''),
        ];
        $element = $row['element'] ?? null;
        if ($element instanceof Entry) {
            $haystacks[] = (string)$element->title;
        } elseif ($element instanceof Category) {
            $haystacks[] = (string)$element->title;
            $haystacks[] = (string)($element->group->name ?? '');
        } elseif ($element instanceof Tag) {
            $haystacks[] = (string)$element->title;
            $haystacks[] = (string)($element->group->name ?? '');
        } elseif ($element instanceof GlobalSet) {
            $haystacks[] = (string)$element->name;
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        $values = $row['values'] ?? [];
        if (is_array($values)) {
            foreach ($values as $value) {
                $text = (string)$value;
                if ($text !== '' && mb_stripos($text, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assetHasAltValue(Asset $asset): bool
    {
        return method_exists($asset, 'getAltText') || $asset->canGetProperty('alt') || $asset->canSetProperty('alt');
    }

    private function getAssetAltValue(Asset $asset): ?string
    {
        if (method_exists($asset, 'getAltText')) {
            return (string)$asset->getAltText();
        }
        if ($asset->canGetProperty('alt')) {
            return (string)($asset->alt ?? '');
        }

        return null;
    }

    private function setAssetAltValue(Asset $asset, string $value): void
    {
        if (method_exists($asset, 'setAltText')) {
            $asset->setAltText($value);
            return;
        }
        if ($asset->canSetProperty('alt')) {
            $asset->alt = $value;
        }
    }

    private function normalizeAssetFieldHandle(string $fieldHandle): string
    {
        $clean = trim($fieldHandle);
        $clean = trim($clean, " \t\n\r\0\x0B`*");
        if (str_starts_with($clean, '__') && str_ends_with($clean, '__') && strlen($clean) > 4) {
            $clean = substr($clean, 2, -2);
        }

        $normalized = strtolower(trim($clean));
        if ($normalized === '') {
            return '';
        }

        if (in_array($normalized, ['__native_alt__', 'native_alt', 'alt'], true)) {
            return '__native_alt__';
        }

        if ($normalized === 'title') {
            return 'title';
        }

        return trim($clean);
    }

    private function toPortableAssetFieldHandle(string $fieldHandle): string
    {
        $normalized = $this->normalizeAssetFieldHandle($fieldHandle);
        if ($normalized === '__native_alt__') {
            return 'native_alt';
        }

        return $normalized;
    }

    private function normalizeEntryFieldHandle(string $fieldHandle): string
    {
        $clean = trim($fieldHandle);
        $clean = trim($clean, " \t\n\r\0\x0B`");
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^\*\*seo_subfield\*\*:(.+):(title|description)$/', $clean, $matches)) {
            return $this->buildSeoSubFieldHandle((string)$matches[1], (string)$matches[2]);
        }
        if (preg_match('/^pwt_seo_subfield::(.+)::(title|description)$/', $clean, $matches)) {
            return $this->buildSeoSubFieldHandle((string)$matches[1], (string)$matches[2]);
        }
        if (preg_match('/^pwt_special::(.+)$/', $clean, $matches)) {
            return '__' . trim((string)$matches[1]) . '__';
        }
        if (preg_match('/^\*\*(.+)\*\*$/', $clean, $matches)) {
            return '__' . trim((string)$matches[1]) . '__';
        }
        if (str_starts_with($clean, '__') && str_ends_with($clean, '__') && strlen($clean) > 4) {
            return '__' . trim(substr($clean, 2, -2)) . '__';
        }

        return $clean;
    }

    private function toPortableEntryFieldHandle(string $fieldHandle): string
    {
        $normalized = $this->normalizeEntryFieldHandle($fieldHandle);
        $seoSubFieldData = $this->parseSeoSubFieldHandle($normalized);
        if ($seoSubFieldData !== null) {
            return sprintf('pwt_seo_subfield::%s::%s', (string)$seoSubFieldData[0], (string)$seoSubFieldData[1]);
        }
        if (str_starts_with($normalized, '__') && str_ends_with($normalized, '__') && strlen($normalized) > 4) {
            return 'pwt_special::' . trim(substr($normalized, 2, -2));
        }

        return $normalized;
    }

    private function buildSeoSubFieldHandle(string $seoFieldHandle, string $property): string
    {
        return '__seo_subfield__:' . trim($seoFieldHandle) . ':' . trim($property);
    }

    private function parseSeoSubFieldHandle(string $fieldHandle): ?array
    {
        if (!preg_match('/^__seo_subfield__:(.+?):(title|description)$/', trim($fieldHandle), $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function readSeoSubFieldValue(Entry $entry, string $seoFieldHandle, string $property): string
    {
        $value = $entry->getFieldValue($seoFieldHandle);
        if ($value instanceof SeoFieldValue) {
            $raw = $value->{$property} ?? '';
            return is_scalar($raw) ? (string)$raw : '';
        }
        if (is_array($value)) {
            $raw = $value[$property] ?? '';
            return is_scalar($raw) ? (string)$raw : '';
        }

        return '';
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

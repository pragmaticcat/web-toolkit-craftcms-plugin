<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\fields\PlainText;
use craft\helpers\Cp;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use pragmatic\webtoolkit\jobs\SeoAssetsImportJob;
use yii\helpers\Inflector;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class SeoController extends Controller
{
    private const SITEMAP_ENTRYTYPE_TABLE = '{{%pragmatic_toolkit_seo_sitemap_entrytypes}}';

    protected array|int|bool $allowAnonymous = ['sitemap-xml'];

    public function beforeAction($action): bool
    {
        if ($action->id === 'sitemap-xml') {
            return parent::beforeAction($action);
        }

        $this->requireCpRequest();
        $this->requirePermission('pragmatic-toolkit:seo');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/seo/content');
    }

    public function actionGeneral(): Response
    {
        return $this->redirect('pragmatic-toolkit/seo/content');
    }

    public function actionOptions(): Response
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/options', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
        ]);
    }

    public function actionStrategy(): Response
    {
        $canManageStrategy = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE);
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/strategy', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
            'canManageStrategy' => $canManageStrategy,
            'gemFeatureEnabled' => !array_key_exists('enableGemFeature', $settings) || !empty($settings['enableGemFeature']),
            'gemInstructions' => PragmaticWebToolkit::$plugin->seoAi->buildGemInstructions($selectedSiteId),
        ]);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();
        $siteId = (int)Craft::$app->getRequest()->getBodyParam('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        PragmaticWebToolkit::$plugin->seoMetaSettings->saveSiteSettings($siteId, $settings);

        Craft::$app->getSession()->setNotice('SEO options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveStrategy(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('SEO strategy management requires Lite edition or higher.');
        }

        $siteId = (int)Craft::$app->getRequest()->getBodyParam('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);
        PragmaticWebToolkit::$plugin->seoMetaSettings->saveSiteSettings($siteId, $settings);

        Craft::$app->getSession()->setNotice('SEO AI strategy saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionContent(): Response
    {
        $canManageContent = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE);

        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $sectionId = (int)$request->getParam('section', 0);

        $sitesService = Craft::$app->getSites();
        $selectedSite = Cp::requestedSite() ?? $sitesService->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        if (!$canManageContent) {
            return $this->renderTemplate('pragmatic-web-toolkit/seo/content', [
                'rows' => [],
                'sections' => [],
                'sectionId' => 0,
                'selectedSite' => $selectedSite,
                'selectedSiteId' => $siteId,
                'search' => $search,
                'total' => 0,
                'canManageContent' => false,
                'gemFeatureEnabled' => false,
            ]);
        }

        $sections = $this->getSeoSectionsForSite($siteId, $sectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $rows = [];
        foreach ($entryQuery->all() as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }

                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }

                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                    'value' => $value instanceof SeoFieldValue ? $value : new SeoFieldValue(),
                ];
            }
        }

        $rowRefs = array_map(static fn(array $row): array => [
            'entryId' => (int)($row['entry']->id ?? 0),
            'fieldHandle' => (string)($row['fieldHandle'] ?? ''),
        ], $rows);
        $contentAiInstructions = PragmaticWebToolkit::$plugin->seoContentAiInstructions->getInstructionsForRows($rowRefs, $siteId);
        foreach ($rows as &$row) {
            $key = ((int)$row['entry']->id) . ':' . (string)$row['fieldHandle'];
            $row['aiInstructions'] = $contentAiInstructions[$key] ?? '';
        }
        unset($row);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/content', [
            'rows' => $rows,
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'search' => $search,
            'total' => count($rows),
            'canManageContent' => true,
            'gemFeatureEnabled' => !empty(PragmaticWebToolkit::$plugin->seoAi->getAiSettings($siteId)['gemFeatureEnabled']),
        ]);
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            throw new ForbiddenHttpException('SEO content management requires Lite edition or higher.');
        }
        $request = Craft::$app->getRequest();
        $saveRow = $request->getBodyParam('saveRow');
        $entries = (array)$request->getBodyParam('entries', []);
        $siteId = (int)$request->getBodyParam('site', 0) ?: (int)Craft::$app->getSites()->getCurrentSite()->id;

        if (empty($entries)) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        if ($saveRow !== null) {
            if (!isset($entries[$saveRow])) {
                throw new BadRequestHttpException('Invalid entry payload.');
            }
            $entries = [$saveRow => $entries[$saveRow]];
        }

        $applied = 0;
        $errors = [];
        foreach ($entries as $index => $row) {
            $entryId = (int)($row['entryId'] ?? 0);
            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            $values = (array)($row['values'] ?? []);
            if ($entryId <= 0 || $fieldHandle === '') {
                $errors[] = "Row {$index} is missing entry data.";
                continue;
            }

            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
            if (!$entry instanceof Entry) {
                $errors[] = "Entry #{$entryId} not found.";
                continue;
            }

            $entry->setFieldValue($fieldHandle, [
                'title' => trim((string)($values['title'] ?? '')),
                'description' => trim((string)($values['description'] ?? '')),
                'imageId' => $this->normalizeElementSelectValue($values['imageId'] ?? null),
            ]);
            PragmaticWebToolkit::$plugin->seoContentAiInstructions->saveInstructions(
                $entryId,
                $fieldHandle,
                $siteId,
                trim((string)($row['aiInstructions'] ?? ''))
            );

            if (!Craft::$app->getElements()->saveElement($entry, false, false)) {
                $entryErrors = $entry->getFirstErrors();
                $errors[] = !empty($entryErrors)
                    ? "Entry #{$entryId}: " . implode(' ', array_values($entryErrors))
                    : "Entry #{$entryId} could not be saved.";
                continue;
            }

            $applied++;
        }

        if (!empty($errors)) {
            Craft::$app->getSession()->setError(
                $saveRow !== null
                    ? 'Could not save SEO content.'
                    : ('Saved ' . $applied . ' rows with errors: ' . implode(' ', $errors))
            );
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice($saveRow !== null ? 'SEO content saved.' : ('SEO content saved for ' . $applied . ' rows.'));
        return $this->redirectToPostedUrl();
    }

    public function actionGenerateContentSuggestion(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return $this->asJson(['success' => false, 'error' => 'SEO AI content generation requires Lite edition or higher.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $entryId = (int)$request->getBodyParam('entryId', 0);
            $fieldHandle = trim((string)$request->getBodyParam('fieldHandle', ''));
            $aiInstructions = trim((string)$request->getBodyParam('aiInstructions', ''));
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            if (!$entryId || $fieldHandle === '') {
                throw new BadRequestHttpException('Missing entry data.');
            }

            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
            if (!$entry) {
                throw new BadRequestHttpException('Entry not found.');
            }

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => PragmaticWebToolkit::$plugin->seoAi->buildContentManualPrompt(
                    $entry,
                    $fieldHandle,
                    $siteId,
                    $aiInstructions !== '' ? $aiInstructions : PragmaticWebToolkit::$plugin->seoContentAiInstructions->getInstructions($entryId, $fieldHandle, $siteId)
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionGenerateContentSuggestionBatch(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return $this->asJson(['success' => false, 'error' => 'SEO AI content generation requires Lite edition or higher.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = (array)$request->getBodyParam('items', []);
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $rows = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $entryId = (int)($item['entryId'] ?? 0);
                $fieldHandle = trim((string)($item['fieldHandle'] ?? ''));
                if ($entryId <= 0 || $fieldHandle === '') {
                    continue;
                }

                $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
                if (!$entry instanceof Entry) {
                    continue;
                }
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $fieldHandle,
                    'aiInstructions' => trim((string)($item['aiInstructions'] ?? '')) !== ''
                        ? trim((string)($item['aiInstructions'] ?? ''))
                        : PragmaticWebToolkit::$plugin->seoContentAiInstructions->getInstructions($entryId, $fieldHandle, $siteId),
                ];
            }

            if (empty($rows)) {
                throw new BadRequestHttpException('No matching rows found.');
            }

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => PragmaticWebToolkit::$plugin->seoAi->buildContentBatchManualPrompt($rows, $siteId),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionExportContentJson(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return $this->asJson(['success' => false, 'error' => 'SEO content export requires Lite edition or higher.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = (array)$request->getBodyParam('items', []);
            if (empty($items)) {
                throw new BadRequestHttpException('No rows selected.');
            }

            $rows = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $entryId = (int)($item['entryId'] ?? 0);
                $fieldHandle = trim((string)($item['fieldHandle'] ?? ''));
                if ($entryId <= 0 || $fieldHandle === '') {
                    continue;
                }

                $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
                if (!$entry instanceof Entry) {
                    continue;
                }
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $fieldHandle,
                    'aiInstructions' => PragmaticWebToolkit::$plugin->seoContentAiInstructions->getInstructions($entryId, $fieldHandle, $siteId),
                ];
            }

            if (empty($rows)) {
                throw new BadRequestHttpException('No matching rows found.');
            }

            $bundle = PragmaticWebToolkit::$plugin->seoAi->buildContentTransferBundle($rows, $siteId);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $timestamp = (new \DateTime())->format('Ymd-His');
            $filename = 'seo-content-export-' . ($site?->handle ?? 'site') . '-' . $timestamp . '.json';

            return $this->asJson([
                'success' => true,
                'bundle' => $bundle,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportContentJsonPreview(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return $this->asJson(['success' => false, 'error' => 'SEO content import requires Lite edition or higher.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $bundle = $this->readContentImportBundleFromRequest($request);
            $classification = $this->classifyContentImportBundle($bundle, $siteId);

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

    public function actionImportContentJsonApply(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE)) {
            return $this->asJson(['success' => false, 'error' => 'SEO content import requires Lite edition or higher.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
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
                $bundle = $this->readContentImportBundleFromRequest($request);
                $classification = $this->classifyContentImportBundle($bundle, $siteId);
                $items = $classification['matchedChanged'];
            }

            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $elements = Craft::$app->getElements();
            $applied = 0;
            $errors = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $entryId = (int)($item['entryId'] ?? 0);
                $fieldHandle = trim((string)($item['fieldHandle'] ?? ''));
                $after = (array)($item['after'] ?? []);
                if ($entryId <= 0 || $fieldHandle === '') {
                    continue;
                }

                $entry = $elements->getElementById($entryId, Entry::class, $siteId);
                if (!$entry instanceof Entry) {
                    $errors[] = "Entry #{$entryId} could not be loaded.";
                    continue;
                }

                $entry->setFieldValue($fieldHandle, [
                    'title' => trim((string)($after['title'] ?? '')),
                    'description' => trim((string)($after['description'] ?? '')),
                    'imageId' => $this->normalizeElementSelectValue($after['imageId'] ?? null),
                ]);
                PragmaticWebToolkit::$plugin->seoContentAiInstructions->saveInstructions(
                    $entryId,
                    $fieldHandle,
                    $siteId,
                    trim((string)($after['aiInstructions'] ?? ''))
                );

                if (!$elements->saveElement($entry, false, false, false)) {
                    $entryErrors = $entry->getFirstErrors();
                    $errors[] = !empty($entryErrors)
                        ? "Entry #{$entryId}: " . implode(' ', array_values($entryErrors))
                        : "Entry #{$entryId} could not be saved.";
                    continue;
                }

                $applied++;
            }

            return $this->asJson([
                'success' => true,
                'summary' => [
                    'applied' => $applied,
                    'skipped' => max(0, count($items) - $applied),
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionAssets(): Response
    {
        $request = Craft::$app->getRequest();
        $rawSectionParam = $request->getQueryParam('section');
        $isNoSection = is_string($rawSectionParam) && strtolower(trim($rawSectionParam)) === 'none';
        $sectionId = $isNoSection ? 0 : (int)$rawSectionParam;
        $sort = strtolower(trim((string)$request->getQueryParam('sort', 'used')));
        if (!in_array($sort, ['used', 'asset'], true)) {
            $sort = 'used';
        }
        $dir = strtolower(trim((string)$request->getQueryParam('dir', $sort === 'used' ? 'desc' : 'asc')));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = $sort === 'used' ? 'desc' : 'asc';
        }

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sectionAssetCounts = $this->getSectionAssetCountsForSite($siteId);
        $noSectionAssetIds = $this->getUsedAssetIdsForSite($siteId, null, true);
        $noSectionCount = count($noSectionAssetIds);
        $sectionParam = $isNoSection ? 'none' : ($sectionId > 0 ? $sectionId : null);

        $assetQuery = Asset::find()
            ->kind('image')
            ->status(null)
            ->siteId($siteId);
        if ($isNoSection) {
            $assetQuery->id(!empty($noSectionAssetIds) ? $noSectionAssetIds : [0]);
        } elseif ($sectionId > 0) {
            $filteredUsedIds = $this->getUsedAssetIdsForSite($siteId, $sectionId);
            $assetQuery->id(!empty($filteredUsedIds) ? $filteredUsedIds : [0]);
        }

        $assets = (clone $assetQuery)->all();
        $total = count($assets);

        $assetIds = array_map(static fn(Asset $asset): int => (int)$asset->id, $assets);
        $usedIds = $this->getUsedAssetIdsForSite($siteId);
        $assetEntryLinks = $this->getAssetEntryLinksForSite($siteId, $assetIds, $sectionId > 0 ? $sectionId : null, $isNoSection);
        $textColumns = $this->collectAssetTextColumns($assets);
        $assetAiInstructions = PragmaticWebToolkit::$plugin->seoAssetAiInstructions->getInstructionsForAssets($assetIds, $siteId);

        $rows = [];
        foreach ($assets as $asset) {
            $isUsed = in_array((int)$asset->id, $usedIds, true);
            $fieldHandles = $this->assetTextFieldHandles($asset);
            $fieldValues = [
                '__ai_instructions__' => $assetAiInstructions[(int)$asset->id] ?? '',
            ];
            foreach ($textColumns as $handle => $meta) {
                if ($handle === '__native_alt__') {
                    $fieldValues[$handle] = $this->getAssetAltValue($asset);
                } else {
                    $fieldValues[$handle] = in_array($handle, $fieldHandles, true)
                        ? (string)$asset->getFieldValue($handle)
                        : null;
                }
            }

            $rows[] = [
                'asset' => $asset,
                'isUsed' => $isUsed,
                'entryLink' => $assetEntryLinks[(int)$asset->id] ?? null,
                'fieldValues' => $fieldValues,
            ];
        }

        usort($rows, function (array $a, array $b) use ($sort, $dir): int {
            $direction = $dir === 'asc' ? 1 : -1;
            if ($sort === 'used') {
                $aUsed = !empty($a['isUsed']) ? 1 : 0;
                $bUsed = !empty($b['isUsed']) ? 1 : 0;
                if ($aUsed !== $bUsed) {
                    return ($aUsed <=> $bUsed) * $direction;
                }
            }

            $aName = strtolower((string)($a['asset']->filename ?? ''));
            $bName = strtolower((string)($b['asset']->filename ?? ''));
            return ($aName <=> $bName) * $direction;
        });

        return $this->renderTemplate('pragmatic-web-toolkit/seo/assets', [
            'rows' => $rows,
            'sections' => $sectionAssetCounts,
            'sectionId' => $sectionId,
            'sectionParam' => $sectionParam,
            'isNoSection' => $isNoSection,
            'noSectionCount' => $noSectionCount,
            'sort' => $sort,
            'dir' => $dir,
            'textColumns' => $textColumns,
            'total' => $total,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'canManageAssets' => PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO),
            'gemFeatureEnabled' => !empty(PragmaticWebToolkit::$plugin->seoAi->getAiSettings($siteId)['gemFeatureEnabled']),
        ]);
    }

    public function actionSaveAssets(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            Craft::$app->getSession()->setError('SEO asset management requires Pro edition.');
            return $this->redirectToPostedUrl();
        }

        $assetsData = (array)Craft::$app->getRequest()->getBodyParam('assets', []);
        $saveRowId = (int)Craft::$app->getRequest()->getBodyParam('saveRowId', 0);
        if ($saveRowId > 0) {
            $assetsData = isset($assetsData[$saveRowId]) ? [$saveRowId => $assetsData[$saveRowId]] : [];
        }
        if (empty($assetsData)) {
            Craft::$app->getSession()->setError('No asset data was received.');
            return $this->redirectToPostedUrl();
        }

        $siteId = (int)Craft::$app->getRequest()->getBodyParam('site', 0);
        if (!$siteId) {
            $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
            $siteId = (int)$selectedSite->id;
        }
        $elements = Craft::$app->getElements();
        $errors = [];

        foreach ($assetsData as $assetId => $data) {
            $assetId = (int)$assetId;
            $asset = $elements->getElementById($assetId, Asset::class, $siteId);
            if (!$asset instanceof Asset) {
                $asset = $elements->getElementById($assetId, Asset::class);
            }

            if (!$asset) {
                $errors[] = "Asset #{$assetId} could not be loaded.";
                continue;
            }

            $title = trim((string)($data['title'] ?? ''));
            $titleChanged = $title !== $asset->title;
            if ($title !== $asset->title) {
                $asset->title = $title;
            }

            $fieldsData = (array)($data['fields'] ?? []);
            $assetTextHandles = $this->assetTextFieldHandles($asset);
            foreach ($fieldsData as $handle => $value) {
                if ((string)$handle === '__ai_instructions__') {
                    PragmaticWebToolkit::$plugin->seoAssetAiInstructions->saveInstructions($assetId, $siteId, trim((string)$value));
                    continue;
                }

                if ((string)$handle === '__native_alt__') {
                    $this->setAssetAltValue($asset, trim((string)$value));
                    continue;
                }

                if (!in_array((string)$handle, $assetTextHandles, true)) {
                    continue;
                }

                $asset->setFieldValue((string)$handle, trim((string)$value));
            }

            if (!$elements->saveElement($asset, true, false, false)) {
                $assetErrors = $asset->getFirstErrors();
                if (!empty($assetErrors)) {
                    $errors[] = "Asset #{$assetId}: " . implode(' ', array_values($assetErrors));
                } else {
                    $errors[] = "Asset #{$assetId} could not be saved.";
                }
                continue;
            }

            if ($titleChanged) {
                $renameError = $this->renameAssetFilenameFromTitle($asset, $title);
                if ($renameError !== null) {
                    $errors[] = "Asset #{$assetId}: {$renameError}";
                }
            }
        }

        if (!empty($errors)) {
            Craft::$app->getSession()->setError(implode(' ', $errors));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('SEO assets saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionGenerateAssetMetadata(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO AI asset generation requires Pro edition.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $assetId = (int)$request->getBodyParam('assetId', 0);
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            if (!$assetId) {
                throw new BadRequestHttpException('Missing asset.');
            }

            $asset = Asset::find()->id($assetId)->siteId($siteId)->status(null)->one();
            if (!$asset) {
                throw new BadRequestHttpException('Asset not found.');
            }

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => PragmaticWebToolkit::$plugin->seoAi->buildAssetManualPrompt($asset, $siteId),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionGenerateAssetMetadataBatch(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO AI asset generation requires Pro edition.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $assetIds = $this->extractIntIds((array)$request->getBodyParam('assetIds', []));
            if (empty($assetIds)) {
                throw new BadRequestHttpException('No assets selected.');
            }

            $assets = Asset::find()
                ->id($assetIds)
                ->siteId($siteId)
                ->status(null)
                ->all();

            if (empty($assets)) {
                throw new BadRequestHttpException('No assets matched the selection.');
            }

            return $this->asJson([
                'success' => true,
                'mode' => 'manual',
                'manualPrompt' => PragmaticWebToolkit::$plugin->seoAi->buildAssetBatchManualPrompt($assets, $siteId),
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionExportAssetsJson(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO asset export requires Pro edition.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $assetIds = $this->extractIntIds((array)$request->getBodyParam('assetIds', []));
            if (empty($assetIds)) {
                throw new BadRequestHttpException('No assets selected.');
            }

            $assets = Asset::find()
                ->id($assetIds)
                ->siteId($siteId)
                ->status(null)
                ->all();
            if (empty($assets)) {
                throw new BadRequestHttpException('No assets matched the selection.');
            }

            $bundle = PragmaticWebToolkit::$plugin->seoAi->buildAssetTransferBundle($assets, $siteId);
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $timestamp = (new \DateTime())->format('Ymd-His');
            $filename = 'seo-assets-export-' . ($site?->handle ?? 'site') . '-' . $timestamp . '.json';

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
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO asset import requires Pro edition.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $bundle = $this->readImportBundleFromRequest($request);
            $classification = $this->classifyImportBundle($bundle, $siteId);
            $matchedChanged = $classification['matchedChanged'];
            $matchedUnchanged = $classification['matchedUnchanged'];
            $skippedUnmatched = $classification['skippedUnmatched'];
            $invalidItems = $classification['invalidItems'];
            $totalItems = (int)$classification['totalItems'];

            return $this->asJson([
                'success' => true,
                'previewToken' => $this->storeAssetsImportPreview($siteId, $matchedChanged),
                'preview' => [
                    'matchedChanged' => $matchedChanged,
                    'matchedUnchanged' => $matchedUnchanged,
                    'skippedUnmatched' => $skippedUnmatched,
                    'invalidItems' => $invalidItems,
                    'totals' => [
                        'totalItems' => $totalItems,
                        'matchedChanged' => count($matchedChanged),
                        'matchedUnchanged' => count($matchedUnchanged),
                        'skippedUnmatched' => count($skippedUnmatched),
                        'invalidItems' => count($invalidItems),
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
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO asset import requires Pro edition.']);
        }

        try {
            $request = Craft::$app->getRequest();
            $siteId = (int)$request->getBodyParam('siteId', 0) ?: (int)(Cp::requestedSite()?->id ?? Craft::$app->getSites()->getPrimarySite()->id);
            $items = [];
            $itemsJson = trim((string)$request->getBodyParam('itemsJson', ''));
            $rawItems = (array)$request->getBodyParam('items', []);

            $previewToken = trim((string)$request->getBodyParam('previewToken', ''));
            if ($previewToken !== '') {
                $previewData = $this->readImportPreview($previewToken);
                if (is_array($previewData)) {
                    $previewSiteId = (int)($previewData['siteId'] ?? 0);
                    if ($previewSiteId > 0 && $previewSiteId !== $siteId) {
                        throw new BadRequestHttpException('Preview does not match the selected site.');
                    }

                    $items = is_array($previewData['items'] ?? null) ? $previewData['items'] : [];
                }
            }

            if (empty($items) && $itemsJson !== '') {
                try {
                    $decodedItems = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestHttpException('Invalid items JSON: ' . $e->getMessage());
                }
                $items = is_array($decodedItems) ? $decodedItems : [];
            }
            if (empty($items) && !empty($rawItems)) {
                $items = $rawItems;
            }
            if (empty($items)) {
                $jsonText = trim((string)$request->getBodyParam('jsonText', ''));
                if ($jsonText !== '') {
                    $bundle = $this->readImportBundleFromRequest($request);
                    $classification = $this->classifyImportBundle($bundle, $siteId);
                    $items = $classification['matchedChanged'];
                }
            }
            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $importToken = StringHelper::randomString(24);
            $now = date(DATE_ATOM);
            $this->writeImportStatus($importToken, [
                'state' => 'queued',
                'message' => 'Import queued.',
                'total' => count($items),
                'processed' => 0,
                'applied' => 0,
                'errors' => [],
                'startedAt' => null,
                'finishedAt' => null,
                'updatedAt' => $now,
            ]);

            $jobId = Craft::$app->getQueue()->push(new SeoAssetsImportJob([
                'siteId' => $siteId,
                'items' => $items,
                'statusToken' => $importToken,
            ]));

            if ($previewToken !== '') {
                $this->deleteImportPreview($previewToken);
            }

            return $this->asJson([
                'success' => true,
                'queued' => true,
                'jobId' => (int)$jobId,
                'importToken' => $importToken,
                'summary' => null,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionImportAssetsJsonStatus(): Response
    {
        $this->requirePostRequest();
        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            return $this->asJson(['success' => false, 'error' => 'SEO asset import requires Pro edition.']);
        }

        try {
            $token = trim((string)Craft::$app->getRequest()->getBodyParam('importToken', ''));
            if ($token === '') {
                throw new BadRequestHttpException('Missing import token.');
            }

            $status = $this->readImportStatus($token);
            if (!is_array($status)) {
                throw new BadRequestHttpException('Import status not found or expired.');
            }

            return $this->asJson([
                'success' => true,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionSitemap(): Response
    {
        $canManageSitemap = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO);

        $request = Craft::$app->getRequest();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sitemapUrl = UrlHelper::siteUrl('sitemap.xml', null, null, $siteId);
        $sectionId = (int)$request->getQueryParam('section', 0);
        if (!$canManageSitemap) {
            return $this->renderTemplate('pragmatic-web-toolkit/seo/sitemap', [
                'rows' => [],
                'sections' => [],
                'sectionId' => 0,
                'selectedSite' => $selectedSite,
                'sitemapUrl' => $sitemapUrl,
                'canManageSitemap' => false,
            ]);
        }

        $sections = $this->getSeoSectionsForSite($siteId, $sectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }

        $rows = [];
        foreach ($entryQuery->all() as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }
                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }
                if (!$value instanceof SeoFieldValue) {
                    $value = new SeoFieldValue();
                }
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'sitemapEnabled' => $value->sitemapEnabled ?? true,
                    'sitemapIncludeImages' => $value->sitemapIncludeImages ?? false,
                ];
                break;
            }
        }

        return $this->renderTemplate('pragmatic-web-toolkit/seo/sitemap', [
            'rows' => $rows,
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSite' => $selectedSite,
            'sitemapUrl' => $sitemapUrl,
            'canManageSitemap' => true,
        ]);
    }

    public function actionSaveSitemap(): Response
    {
        $this->requirePostRequest();

        if (!PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO)) {
            throw new ForbiddenHttpException('Sitemap configuration by entry type requires Pro edition.');
        }
        $request = Craft::$app->getRequest();
        $entries = (array)$request->getBodyParam('entries', []);
        $siteId = (int)$request->getBodyParam('site', 0) ?: (int)Craft::$app->getSites()->getCurrentSite()->id;

        foreach ($entries as $row) {
            $entryId = (int)($row['entryId'] ?? 0);
            $fieldHandle = (string)($row['fieldHandle'] ?? '');
            if (!$entryId || $fieldHandle === '') {
                continue;
            }

            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
            if (!$entry) {
                continue;
            }

            $current = $entry->getFieldValue($fieldHandle);
            if (!$current instanceof SeoFieldValue) {
                $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
                if ($field instanceof SeoField) {
                    $current = $field->normalizeValue($current, $entry);
                }
            }
            if (!$current instanceof SeoFieldValue) {
                $current = new SeoFieldValue();
            }

            $entry->setFieldValue($fieldHandle, [
                'title' => $current->title,
                'description' => $current->description,
                'imageId' => $current->imageId,
                'sitemapEnabled' => !empty($row['sitemapEnabled']),
                'sitemapIncludeImages' => !empty($row['sitemapIncludeImages']),
            ]);

            Craft::$app->getElements()->saveElement($entry, false, false);
        }

        Craft::$app->getSession()->setNotice('Sitemap settings saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSitemapXml(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteId = (int)$site->id;
        $baseUrl = rtrim((string)$site->baseUrl, '/');

        $entryTypeRows = $this->getSitemapEntryTypeRows($siteId);
        $urls = [];

        foreach ($entryTypeRows as $typeRow) {
            if (empty($typeRow['settings']['enabled'])) {
                continue;
            }

            $entries = Entry::find()
                ->typeId((int)$typeRow['entryTypeId'])
                ->siteId($siteId)
                ->status('live')
                ->limit(null)
                ->all();

            foreach ($entries as $entry) {
                $seoHandle = (string)$typeRow['seoHandle'];
                $seoValue = $entry->getFieldValue($seoHandle);
                if (!$seoValue instanceof SeoFieldValue) {
                    $field = $entry->getFieldLayout()?->getFieldByHandle($seoHandle);
                    if ($field instanceof SeoField) {
                        $seoValue = $field->normalizeValue($seoValue, $entry);
                    }
                }

                $entryEnabled = $seoValue instanceof SeoFieldValue && $seoValue->sitemapEnabled !== null
                    ? (bool)$seoValue->sitemapEnabled
                    : (bool)$typeRow['settings']['enabled'];

                if (!$entryEnabled) {
                    continue;
                }

                $entryUrl = $entry->getUrl();
                if (!$entryUrl) {
                    continue;
                }

                $urls[] = [
                    'loc' => str_starts_with($entryUrl, 'http') ? $entryUrl : $baseUrl . '/' . ltrim($entryUrl, '/'),
                    'lastmod' => $entry->dateUpdated?->format(DATE_ATOM),
                ];
            }
        }

        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $xml = $view->renderTemplate('pragmatic-web-toolkit/seo/sitemap_xml', [
                'urls' => $urls,
            ]);
        } finally {
            $view->setTemplateMode($oldTemplateMode);
        }

        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->format = Response::FORMAT_RAW;
        $response->content = $xml;

        return $response;
    }

    private function getSeoSectionsForSite(int $siteId, int $selectedSectionId = 0): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();
        $result = [];

        foreach ($sections as $section) {
            $entryQuery = Entry::find()->siteId($siteId)->sectionId($section->id)->status(null);
            $count = 0;
            foreach ($entryQuery->all() as $entry) {
                if ($this->entryHasSeoField($entry)) {
                    $count++;
                }
            }

            if ($count > 0 || $section->id === $selectedSectionId) {
                $result[] = [
                    'id' => $section->id,
                    'name' => $section->name,
                    'count' => $count,
                ];
            }
        }

        return $result;
    }

    private function entryHasSeoField(Entry $entry): bool
    {
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SeoField) {
                return true;
            }
        }

        return false;
    }

    private function getSectionAssetCountsForSite(int $siteId): array
    {
        $result = [];
        $sections = Craft::$app->getEntries()->getAllSections();
        foreach ($sections as $section) {
            $usedIds = $this->getUsedAssetIdsForSite($siteId, (int)$section->id);
            $count = count($usedIds);
            if ($count === 0) {
                continue;
            }

            $result[] = [
                'id' => (int)$section->id,
                'name' => (string)$section->name,
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @return int[]
     */
    private function getUsedAssetIdsForSite(int $siteId, ?int $sectionId = null, bool $noSectionOnly = false): array
    {
        $entryIds = $this->getEntryIdsForSite($siteId, $sectionId, $noSectionOnly);
        if (empty($entryIds)) {
            return [];
        }

        return $this->getUsedAssetIds($entryIds);
    }

    /**
     * @param int[] $sourceEntryIds
     * @return int[]
     */
    private function getUsedAssetIds(array $sourceEntryIds = []): array
    {
        $query = (new Query())
            ->select(['r.targetId'])
            ->distinct()
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[r.targetId]]');

        if (!empty($sourceEntryIds)) {
            $query->where(['r.sourceId' => $sourceEntryIds]);
        }

        return array_map('intval', $query->column());
    }

    /**
     * @return int[]
     */
    private function getEntryIdsForSite(int $siteId, ?int $sectionId = null, bool $noSectionOnly = false): array
    {
        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId !== null && $sectionId > 0) {
            $entryQuery->sectionId($sectionId);
        }

        $entryIds = array_values(array_filter(array_map('intval', $entryQuery->ids()), static fn(int $id): bool => $id > 0));
        if (!$noSectionOnly || empty($entryIds)) {
            return $entryIds;
        }

        $knownSectionIds = array_values(array_filter(array_map(
            static fn($section): int => (int)($section->id ?? 0),
            Craft::$app->getEntries()->getAllSections()
        ), static fn(int $id): bool => $id > 0));

        if (empty($knownSectionIds)) {
            return $entryIds;
        }

        $sectionBoundEntryIds = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->sectionId($knownSectionIds)
            ->ids();
        $sectionBoundEntryIds = array_values(array_filter(array_map('intval', $sectionBoundEntryIds), static fn(int $id): bool => $id > 0));
        $sectionBoundLookup = array_fill_keys($sectionBoundEntryIds, true);

        return array_values(array_filter($entryIds, static fn(int $id): bool => !isset($sectionBoundLookup[$id])));
    }

    /**
     * @param int[] $assetIds
     * @return array<int, array{entryId:int,title:string,cpEditUrl:string,extraCount:int}>
     */
    private function getAssetEntryLinksForSite(int $siteId, array $assetIds, ?int $sectionId = null, bool $noSectionOnly = false): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        if (empty($assetIds)) {
            return [];
        }

        $entryIds = $this->getEntryIdsForSite($siteId, $sectionId, $noSectionOnly);
        if (empty($entryIds)) {
            return [];
        }

        $relationRows = (new Query())
            ->select(['r.sourceId', 'r.targetId'])
            ->from(['r' => '{{%relations}}'])
            ->where(['r.sourceId' => $entryIds, 'r.targetId' => $assetIds])
            ->all();

        if (empty($relationRows)) {
            return [];
        }

        $entryByAsset = [];
        $relatedEntryIds = [];
        foreach ($relationRows as $row) {
            $entryId = (int)($row['sourceId'] ?? 0);
            $assetId = (int)($row['targetId'] ?? 0);
            if ($entryId <= 0 || $assetId <= 0) {
                continue;
            }

            $entryByAsset[$assetId][] = $entryId;
            $relatedEntryIds[$entryId] = true;
        }

        if (empty($entryByAsset)) {
            return [];
        }

        $entries = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->id(array_keys($relatedEntryIds))
            ->all();

        $entriesById = [];
        foreach ($entries as $entry) {
            $entriesById[(int)$entry->id] = $entry;
        }

        $result = [];
        foreach ($entryByAsset as $assetId => $ids) {
            $uniqueEntryIds = array_values(array_unique(array_map('intval', $ids)));
            if (empty($uniqueEntryIds)) {
                continue;
            }

            $first = null;
            foreach ($uniqueEntryIds as $entryId) {
                if (isset($entriesById[$entryId])) {
                    $first = $entriesById[$entryId];
                    break;
                }
            }
            if ($first === null) {
                continue;
            }

            $result[(int)$assetId] = [
                'entryId' => (int)$first->id,
                'title' => (string)($first->title ?: ('Entry #' . $first->id)),
                'cpEditUrl' => (string)$first->cpEditUrl,
                'extraCount' => max(0, count($uniqueEntryIds) - 1),
            ];
        }

        return $result;
    }

    /**
     * @param Asset[] $assets
     * @return array<string, array{handle:string,name:string}>
     */
    private function collectAssetTextColumns(array $assets): array
    {
        $columns = [];
        if ($this->assetsSupportNativeAlt($assets)) {
            $columns['__native_alt__'] = [
                'handle' => '__native_alt__',
                'name' => 'Alt',
            ];
        }

        foreach ($assets as $asset) {
            foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$this->isSupportedAssetTextField($field)) {
                    continue;
                }

                $columns[$field->handle] = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                ];
            }
        }

        uasort($columns, function (array $a, array $b): int {
            $aIsAlt = $this->isAltColumn($a);
            $bIsAlt = $this->isAltColumn($b);
            if ($aIsAlt !== $bIsAlt) {
                return $aIsAlt ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $columns;
    }

    /**
     * @return string[]
     */
    private function assetTextFieldHandles(Asset $asset): array
    {
        $handles = [];
        foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isSupportedAssetTextField($field)) {
                $handles[] = $field->handle;
            }
        }

        return $handles;
    }

    private function isSupportedAssetTextField(FieldInterface $field): bool
    {
        if ($field instanceof PlainText) {
            return true;
        }

        return strtolower(get_class($field)) === 'craft\\ckeditor\\field';
    }

    private function isAltColumn(array $column): bool
    {
        $handle = strtolower((string)($column['handle'] ?? ''));
        $name = strtolower((string)($column['name'] ?? ''));

        return str_contains($handle, 'alt') || str_contains($name, 'alt');
    }

    /**
     * @param Asset[] $assets
     */
    private function assetsSupportNativeAlt(array $assets): bool
    {
        foreach ($assets as $asset) {
            if ($this->hasAssetAltAttribute($asset)) {
                return true;
            }
        }

        return false;
    }

    private function hasAssetAltAttribute(Asset $asset): bool
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
        if ($asset->canSetProperty('alt')) {
            $asset->alt = $value;
        }
    }

    private function renameAssetFilenameFromTitle(Asset $asset, string $title): ?string
    {
        $targetFilename = $this->buildSeoFilenameFromTitle($asset, $title);
        if ($targetFilename === $asset->filename) {
            return null;
        }

        $folder = $asset->getFolder();
        if ($folder === null) {
            return 'The asset folder could not be resolved for filename renaming.';
        }

        try {
            Craft::$app->getAssets()->moveAsset($asset, $folder, $targetFilename);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function buildSeoFilenameFromTitle(Asset $asset, string $title): string
    {
        $baseName = trim(Inflector::slug($title));
        if ($baseName === '') {
            $currentBaseName = pathinfo($asset->filename, PATHINFO_FILENAME);
            $baseName = trim(Inflector::slug($currentBaseName));
        }
        if ($baseName === '') {
            $baseName = 'asset-' . (int)$asset->id;
        }

        $extension = strtolower((string)$asset->extension);
        if ($extension === '') {
            $extension = strtolower((string)pathinfo($asset->filename, PATHINFO_EXTENSION));
        }

        $filename = $extension !== '' ? $baseName . '.' . $extension : $baseName;
        $folder = $asset->getFolder();
        if ($folder !== null) {
            try {
                $filename = Craft::$app->getAssets()->getNameReplacementInFolder($filename, $folder->id);
            } catch (\Throwable) {
                // Fall back to the generated filename if Craft cannot resolve collisions here.
            }
        }

        return $filename;
    }

    /**
     * @param array<int,array<string,mixed>> $matchedChanged
     */
    private function storeAssetsImportPreview(int $siteId, array $matchedChanged): string
    {
        $token = StringHelper::randomString(24);
        $this->writeImportPreview($token, [
            'siteId' => $siteId,
            'items' => $matchedChanged,
            'createdAt' => date(DATE_ATOM),
        ]);

        return $token;
    }

    private function writeImportPreview(string $token, array $data): void
    {
        $path = SeoAssetsImportJob::previewFilePath($token);
        FileHelper::createDirectory(dirname($path));
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readImportPreview(string $token): ?array
    {
        $path = SeoAssetsImportJob::previewFilePath($token);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function deleteImportPreview(string $token): void
    {
        $path = SeoAssetsImportJob::previewFilePath($token);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function writeImportStatus(string $token, array $status): void
    {
        $path = SeoAssetsImportJob::statusFilePath($token);
        FileHelper::createDirectory(dirname($path));
        file_put_contents($path, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readImportStatus(string $token): ?array
    {
        $path = SeoAssetsImportJob::statusFilePath($token);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{
     *   totalItems:int,
     *   matchedChanged:array<int,array<string,mixed>>,
     *   matchedUnchanged:array<int,array<string,mixed>>,
     *   skippedUnmatched:array<int,array<string,mixed>>,
     *   invalidItems:array<int,array<string,mixed>>
     * }
     */
    private function classifyImportBundle(array $bundle, int $siteId): array
    {
        $items = (array)($bundle['items'] ?? []);
        $matchedChanged = [];
        $matchedUnchanged = [];
        $skippedUnmatched = [];
        $invalidItems = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Item must be an object.'];
                continue;
            }

            $assetId = (int)($item['assetId'] ?? 0);
            if ($assetId <= 0) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Missing or invalid assetId.'];
                continue;
            }

            $asset = Craft::$app->getElements()->getElementById($assetId, Asset::class, $siteId);
            if (!$asset) {
                $skippedUnmatched[] = [
                    'index' => $index,
                    'assetId' => $assetId,
                    'reason' => 'No matching asset found.',
                ];
                continue;
            }

            $before = [
                'aiInstructions' => PragmaticWebToolkit::$plugin->seoAssetAiInstructions->getInstructions((int)$asset->id, $siteId),
                'title' => trim((string)$asset->title),
                'alt' => trim((string)($this->getAssetAltValue($asset) ?? '')),
            ];
            $after = [
                'aiInstructions' => trim((string)($item['aiInstructions'] ?? '')),
                'title' => trim((string)($item['title'] ?? '')),
                'alt' => trim((string)($item['alt'] ?? '')),
            ];
            $changedFields = [];
            foreach (['aiInstructions', 'title', 'alt'] as $key) {
                if ($before[$key] !== $after[$key]) {
                    $changedFields[] = $key;
                }
            }

            $previewItem = [
                'assetId' => (int)$asset->id,
                'before' => $before,
                'after' => $after,
                'changedFields' => $changedFields,
            ];
            if (!empty($changedFields)) {
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

    /**
     * @return array{
     *   totalItems:int,
     *   matchedChanged:array<int,array<string,mixed>>,
     *   matchedUnchanged:array<int,array<string,mixed>>,
     *   skippedUnmatched:array<int,array<string,mixed>>,
     *   invalidItems:array<int,array<string,mixed>>
     * }
     */
    private function classifyContentImportBundle(array $bundle, int $siteId): array
    {
        $items = (array)($bundle['items'] ?? []);
        $matchedChanged = [];
        $matchedUnchanged = [];
        $skippedUnmatched = [];
        $invalidItems = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $invalidItems[] = ['index' => $index, 'reason' => 'Item must be an object.'];
                continue;
            }

            $entryId = (int)($item['entryId'] ?? 0);
            $fieldHandle = trim((string)($item['fieldHandle'] ?? ''));
            if ($entryId <= 0 || $fieldHandle === '') {
                $invalidItems[] = ['index' => $index, 'reason' => 'Missing entryId or fieldHandle.'];
                continue;
            }

            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
            if (!$entry instanceof Entry) {
                $skippedUnmatched[] = ['index' => $index, 'entryId' => $entryId, 'fieldHandle' => $fieldHandle, 'reason' => 'Entry not found.'];
                continue;
            }

            $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
            if (!$field instanceof SeoField) {
                $skippedUnmatched[] = ['index' => $index, 'entryId' => $entryId, 'fieldHandle' => $fieldHandle, 'reason' => 'SEO field not found on entry.'];
                continue;
            }

            $value = $entry->getFieldValue($fieldHandle);
            if (!$value instanceof SeoFieldValue) {
                $value = $field->normalizeValue($value, $entry);
            }
            if (!$value instanceof SeoFieldValue) {
                $value = new SeoFieldValue();
            }

            $before = [
                'aiInstructions' => PragmaticWebToolkit::$plugin->seoContentAiInstructions->getInstructions($entryId, $fieldHandle, $siteId),
                'title' => trim((string)($value->title ?? '')),
                'description' => trim((string)($value->description ?? '')),
                'imageId' => $value->imageId ? (int)$value->imageId : null,
            ];
            $after = [
                'aiInstructions' => trim((string)($item['aiInstructions'] ?? '')),
                'title' => trim((string)($item['title'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'imageId' => $this->normalizeElementSelectValue($item['imageId'] ?? null),
            ];

            $changedFields = [];
            foreach (['aiInstructions', 'title', 'description', 'imageId'] as $key) {
                if ($before[$key] !== $after[$key]) {
                    $changedFields[] = $key;
                }
            }

            $previewItem = [
                'entryId' => $entryId,
                'fieldHandle' => $fieldHandle,
                'before' => $before,
                'after' => $after,
                'changedFields' => $changedFields,
            ];
            if (!empty($changedFields)) {
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

    /**
     * @param array<int|string,mixed> $input
     * @return int[]
     */
    private function extractIntIds(array $input): array
    {
        $ids = array_values(array_filter(array_map(static fn(mixed $id): int => (int)$id, $input), static fn(int $id): bool => $id > 0));
        return array_values(array_unique($ids));
    }

    private function readImportBundleFromRequest(\craft\web\Request $request): array
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
        if (($bundle['domain'] ?? '') !== 'seo-assets') {
            throw new BadRequestHttpException('Invalid bundle domain. Expected "seo-assets".');
        }
        $version = (string)($bundle['version'] ?? '');
        if ($version !== '2.0') {
            throw new BadRequestHttpException('Unsupported bundle version. Expected "2.0".');
        }
        if (!isset($bundle['items']) || !is_array($bundle['items'])) {
            throw new BadRequestHttpException('Bundle items are missing.');
        }

        return $bundle;
    }

    private function readContentImportBundleFromRequest(\craft\web\Request $request): array
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
        if (($bundle['domain'] ?? '') !== 'seo-content') {
            throw new BadRequestHttpException('Invalid bundle domain. Expected "seo-content".');
        }
        if ((string)($bundle['version'] ?? '') !== '1.0') {
            throw new BadRequestHttpException('Unsupported bundle version. Expected "1.0".');
        }
        if (!isset($bundle['items']) || !is_array($bundle['items'])) {
            throw new BadRequestHttpException('Bundle items are missing.');
        }

        return $bundle;
    }

    private function normalizeElementSelectValue(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (int)$value;
    }

    private function ensureSitemapEntryTypeTable(): void
    {
        $db = Craft::$app->getDb();
        if ($db->tableExists(self::SITEMAP_ENTRYTYPE_TABLE)) {
            return;
        }

        $db->createCommand()->createTable(self::SITEMAP_ENTRYTYPE_TABLE, [
            'entryTypeId' => 'integer NOT NULL PRIMARY KEY',
            'enabled' => 'boolean NOT NULL DEFAULT true',
            'includeImages' => 'boolean NOT NULL DEFAULT false',
        ])->execute();

        $db->createCommand()->addForeignKey(
            null,
            self::SITEMAP_ENTRYTYPE_TABLE,
            ['entryTypeId'],
            '{{%entrytypes}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        )->execute();
    }

    private function saveEntryTypeSitemapSettings(int $entryTypeId, array $settings): void
    {
        if ($entryTypeId <= 0) {
            return;
        }

        Craft::$app->getDb()->createCommand()->upsert(
            self::SITEMAP_ENTRYTYPE_TABLE,
            [
                'entryTypeId' => $entryTypeId,
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ],
            [
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ]
        )->execute();
    }

    private function getSitemapEntryTypeRows(int $siteId): array
    {
        $this->ensureSitemapEntryTypeTable();

        $savedSettings = (new Query())
            ->select(['entryTypeId', 'enabled', 'includeImages'])
            ->from(self::SITEMAP_ENTRYTYPE_TABLE)
            ->indexBy('entryTypeId')
            ->all();

        $rows = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSetting = $section->getSiteSettings()[$siteId] ?? null;
            if ($siteSetting && !$siteSetting->hasUrls) {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $seoHandle = null;
                foreach ($entryType->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    if ($field instanceof SeoField) {
                        $seoHandle = $field->handle;
                        break;
                    }
                }

                if (!$seoHandle) {
                    continue;
                }

                $saved = $savedSettings[$entryType->id] ?? null;
                $settings = [
                    'enabled' => $saved ? (bool)$saved['enabled'] : true,
                    'includeImages' => $saved ? (bool)$saved['includeImages'] : false,
                ];

                $this->saveEntryTypeSitemapSettings((int)$entryType->id, $settings);

                $rows[] = [
                    'entryTypeId' => (int)$entryType->id,
                    'sectionId' => (int)$section->id,
                    'sectionName' => $section->name,
                    'entryTypeName' => $entryType->name,
                    'seoHandle' => $seoHandle,
                    'settings' => $settings,
                ];
            }
        }

        return $rows;
    }
}

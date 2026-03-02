<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\fields\PlainText;
use craft\helpers\Cp;
use craft\web\Controller;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class SeoController extends Controller
{
    private const SITEMAP_ENTRYTYPE_TABLE = '{{%pragmatic_toolkit_seo_sitemap_entrytypes}}';

    protected array|int|bool $allowAnonymous = ['sitemap-xml'];

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

    public function actionContent(): Response
    {
        $canManageContent = PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_LITE);

        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $sectionId = (int)$request->getParam('section', 0);
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }

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
                'perPage' => $perPage,
                'page' => 1,
                'totalPages' => 1,
                'total' => 0,
                'canManageContent' => false,
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

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/content', [
            'rows' => $pageRows,
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'canManageContent' => true,
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

        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);
        $siteId = (int)$request->getBodyParam('site', 0) ?: (int)Craft::$app->getSites()->getCurrentSite()->id;

        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
        if (!$entry) {
            throw new BadRequestHttpException('Entry not found.');
        }

        $entry->setFieldValue($fieldHandle, [
            'title' => trim((string)($values['title'] ?? '')),
            'description' => trim((string)($values['description'] ?? '')),
            'imageId' => $this->normalizeElementSelectValue($values['imageId'] ?? null),
            'imageDescription' => trim((string)($values['imageDescription'] ?? '')),
        ]);

        $saved = Craft::$app->getElements()->saveElement($entry, false, false);
        if (!$saved) {
            Craft::$app->getSession()->setError('Could not save SEO content.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('SEO content saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionAssets(): Response
    {
        $request = Craft::$app->getRequest();
        $usedOnly = $this->parseUsedFilter($request->getQueryParam('used'));
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = (int)$request->getQueryParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;

        $assetQuery = Asset::find()
            ->kind('image')
            ->status(null)
            ->siteId($siteId);

        if ($usedOnly) {
            $usedIds = $this->getUsedAssetIds();
            $assetQuery->id(!empty($usedIds) ? $usedIds : [0]);
        }

        $total = (int)(clone $assetQuery)->count();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $assets = (clone $assetQuery)
            ->offset($offset)
            ->limit($perPage)
            ->all();

        $assetIds = array_map(static fn(Asset $asset): int => (int)$asset->id, $assets);
        $usedIds = $this->getUsedAssetIds($assetIds);
        $textColumns = $this->collectAssetTextColumns($assets);

        $rows = [];
        foreach ($assets as $asset) {
            $isUsed = in_array((int)$asset->id, $usedIds, true);
            if ($usedOnly && !$isUsed) {
                continue;
            }

            $fieldHandles = $this->assetTextFieldHandles($asset);
            $fieldValues = [];
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
                'fieldValues' => $fieldValues,
            ];
        }

        return $this->renderTemplate('pragmatic-web-toolkit/seo/assets', [
            'rows' => $rows,
            'usedOnly' => $usedOnly,
            'textColumns' => $textColumns,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'selectedSite' => $selectedSite,
            'canManageAssets' => PragmaticWebToolkit::$plugin->atLeast(PragmaticWebToolkit::EDITION_PRO),
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

        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $elements = Craft::$app->getElements();

        foreach ($assetsData as $assetId => $data) {
            $asset = Asset::find()
                ->id((int)$assetId)
                ->status(null)
                ->siteId($siteId)
                ->one();

            if (!$asset) {
                continue;
            }

            $title = trim((string)($data['title'] ?? ''));
            if ($title !== '' && $title !== $asset->title) {
                $asset->title = $title;
            }

            $fieldsData = (array)($data['fields'] ?? []);
            $assetTextHandles = $this->assetTextFieldHandles($asset);
            foreach ($fieldsData as $handle => $value) {
                if ((string)$handle === '__native_alt__') {
                    $this->setAssetAltValue($asset, trim((string)$value));
                    continue;
                }

                if (!in_array((string)$handle, $assetTextHandles, true)) {
                    continue;
                }

                $asset->setFieldValue((string)$handle, trim((string)$value));
            }

            $elements->saveElement($asset, false, false, false);
        }

        Craft::$app->getSession()->setNotice('SEO assets saved.');
        return $this->redirectToPostedUrl();
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
                'imageDescription' => $current->imageDescription,
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

    /**
     * @return int[]
     */
    private function getUsedAssetIds(array $assetIds = []): array
    {
        $query = (new Query())
            ->select(['targetId'])
            ->distinct()
            ->from('{{%relations}}');

        if (!empty($assetIds)) {
            $query->where(['targetId' => $assetIds]);
        }

        return array_map('intval', $query->column());
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

    private function parseUsedFilter(mixed $rawValue): bool
    {
        if ($rawValue === null || $rawValue === '') {
            return true;
        }

        if (is_array($rawValue)) {
            $rawValue = end($rawValue);
        }

        return in_array((string)$rawValue, ['1', 'true', 'on'], true);
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

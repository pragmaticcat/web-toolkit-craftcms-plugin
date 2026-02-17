<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use yii\db\Query;
use yii\web\BadRequestHttpException;
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
        ]);
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();
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

    public function actionSitemap(): Response
    {
        $request = Craft::$app->getRequest();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sectionId = (int)$request->getQueryParam('section', 0);

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
        ]);
    }

    public function actionSaveSitemap(): Response
    {
        $this->requirePostRequest();
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

        $xml = Craft::$app->getView()->renderTemplate('pragmatic-web-toolkit/seo/sitemap_xml', [
            'urls' => $urls,
        ]);

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

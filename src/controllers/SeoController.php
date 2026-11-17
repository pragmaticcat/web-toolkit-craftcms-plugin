<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Element\Queries\EntryQuery;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Field\Assets as AssetsField;
use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Support\File;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\seo\fields\SeoField;
use pragmatic\webtoolkit\domains\seo\fields\SeoFieldValue;
use pragmatic\webtoolkit\jobs\SeoAssetsImportJob;
use pragmatic\webtoolkit\jobs\ApplySectionSeoJob;
use pragmatic\webtoolkit\jobs\SaveSeoEntriesJob;
use pragmatic\webtoolkit\jobs\RefreshSeoSlugsJob;
use pragmatic\webtoolkit\db\Query;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Assets;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Flash;
use CraftCms\Cms\View\TemplateMode;

use function CraftCms\Cms\template;

class SeoController extends Controller
{
    private const SITEMAP_MAX_IMAGES_PER_URL = 3;

    protected array|int|bool $allowAnonymous = ['robots-txt', 'sitemap-xml', 'sitemap-section-xml'];

    public function beforeAction(string $action): bool
    {
        if (in_array($action, ['robots-txt', 'sitemap-xml', 'sitemap-section-xml'], true)) {
            $this->setResponseHeader('X-Robots-Tag', 'noindex, nofollow');
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
        $sites = Sites::getAllSites()->all();
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/options', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
        ]);
    }

    public function actionSections(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sections = $this->getAllSectionsAlphabetically();
        $requestedSectionId = (int)request()->input('section', 0);
        $sectionId = $this->resolveSelectedSeoSectionId($sections, $requestedSectionId);
        $selectedSection = $sectionId > 0 ? Sections::getSectionById($sectionId) : null;
        $settings = $sectionId > 0
            ? PragmaticWebToolkit::$plugin->seoMetaSettings->getSectionSettings($selectedSiteId, $sectionId)
            : [];
        $entryRows = $sectionId > 0
            ? $this->getSeoEntryRowsForSection($selectedSiteId, $sectionId)
            : [];
        $sectionAssetFieldOptions = [['label' => 'Ninguno', 'value' => '']];
        $seenAssetHandles = [];
        foreach ($entryRows as $entryRow) {
            foreach (($entryRow['entry']->getFieldLayout()?->getCustomFields() ?? []) as $layoutField) {
                if (!$layoutField instanceof AssetsField || isset($seenAssetHandles[$layoutField->handle])) {
                    continue;
                }
                $seenAssetHandles[$layoutField->handle] = true;
                $sectionAssetFieldOptions[] = ['label' => $layoutField->name, 'value' => $layoutField->handle];
            }
        }
        $savedImageFieldHandle = trim((string)($settings['defaultSiteImageFieldHandle'] ?? ''));
        if ($savedImageFieldHandle !== '' && !isset($seenAssetHandles[$savedImageFieldHandle])) {
            $sectionAssetFieldOptions[] = ['label' => $savedImageFieldHandle, 'value' => $savedImageFieldHandle];
        }

        return $this->renderTemplate('pragmatic-web-toolkit/seo/sections', [
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSection' => $selectedSection,
            'showSectionSeoControls' => $this->sectionSupportsSectionSeo($selectedSection),
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
            'entryRows' => $entryRows,
            'sectionAssetFieldOptions' => $sectionAssetFieldOptions,
        ]);
    }

    public function actionStrategy(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-web-toolkit/seo/strategy', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
                    ]);
    }

    public function actionPrompts(): Response
    {
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $siteId = (int)$selectedSite->id;

        return $this->renderTemplate('pragmatic-web-toolkit/seo/prompts', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'settings' => PragmaticWebToolkit::$plugin->seoPrompts->get($siteId),
        ]);
    }

    public function actionSavePrompts(): Response
    {
        $this->requirePostRequest();
        $siteId = (int)request()->input('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $input = (array)request()->input('settings', []);
        if (!PragmaticWebToolkit::$plugin->seoPrompts->save($siteId, $input)) {
            Flash::error('Could not save SEO prompts.');
            return $this->redirectToPostedUrl();
        }

        Flash::notice('SEO prompts saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();
        $siteId = (int)request()->input('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $settings = (array)request()->input('settings', []);
        PragmaticWebToolkit::$plugin->seoMetaSettings->saveSiteSettings($siteId, $settings);

        Flash::notice('SEO options saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveSections(): Response
    {
        $this->requirePostRequest();

        $siteId = (int)request()->input('site', 0);
        $sectionId = (int)request()->input('sectionId', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }
        if (!$sectionId) {
            throw new BadRequestHttpException('Missing section.');
        }

        $settings = (array)request()->input('settings', []);
        PragmaticWebToolkit::$plugin->seoMetaSettings->saveSectionSettings($siteId, $sectionId, $settings);

        if (request()->input('applyToAll') !== null) {
            $sectionValues = $this->getSectionCopyValues($siteId, $sectionId);
            $entryIds = Entry::find()
                ->siteId($siteId)
                ->sectionId($sectionId)
                ->status(null)
                ->ids();
            $batches = array_chunk(array_map('intval', $entryIds), 50);
            $totalBatches = count($batches);
            foreach ($batches as $batchIndex => $batchEntryIds) {
                dispatch(new ApplySectionSeoJob([
                    'siteId' => $siteId,
                    'sectionId' => $sectionId,
                    'entryIds' => $batchEntryIds,
                    'batchNumber' => $batchIndex + 1,
                    'totalBatches' => $totalBatches,
                    'values' => $sectionValues,
                ]));
            }
            Flash::notice(
                empty($batches)
                    ? 'No hay entries para actualizar.'
                    : 'La aplicación masiva se ha dividido en ' . $totalBatches . ' lote(s) y se ha añadido a la cola. Puedes seguir el progreso desde el indicador de tareas de Craft.'
            );
            return $this->redirectToPostedUrl();
        }

        Flash::notice('SEO section defaults saved.');
        return $this->redirectToPostedUrl();
    }

    private function getSeoEntryRowsForSection(int $siteId, int $sectionId): array
    {
        $sectionValues = $this->getSectionCopyValues($siteId, $sectionId);
        $entryQuery = Entry::find()
            ->siteId($siteId)
            ->sectionId($sectionId)
            ->status(null);

        $rows = [];
        foreach ($this->getSafeEntriesFromQuery($entryQuery, $siteId) as $entry) {
            foreach ($this->getSafeSeoFields($entry) as $field) {

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
                    'matches' => [
                        'title' => trim($value->title) === $sectionValues['title'],
                        'description' => trim($value->description) === $sectionValues['description'],
                        'image' => $value->imageId === $sectionValues['imageId'],
                        'imageFieldHandle' => trim($value->imageFieldHandle) === $sectionValues['imageFieldHandle'],
                    ],
                ];
                break;
            }
        }

        return $rows;
    }

    private function getSectionCopyValues(int $siteId, int $sectionId): array
    {
        if ($sectionId <= 0) {
            return ['title' => '', 'description' => '', 'imageId' => null, 'imageFieldHandle' => ''];
        }

        $settings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSectionSettings($siteId, $sectionId);

        return [
            'title' => trim((string)($settings['titleSiteName'] ?? '')),
            'description' => trim((string)($settings['defaultSiteDescription'] ?? '')),
            'imageId' => !empty($settings['defaultSiteImageId']) ? (int)$settings['defaultSiteImageId'] : null,
            'imageFieldHandle' => trim((string)($settings['defaultSiteImageFieldHandle'] ?? '')),
        ];
    }

    public function actionSaveStrategy(): Response
    {
        $this->requirePostRequest();

        $siteId = (int)request()->input('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $settings = (array)request()->input('settings', []);
        PragmaticWebToolkit::$plugin->seoMetaSettings->saveSiteSettings($siteId, $settings);

        Flash::notice('SEO AI strategy saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionContent(): Response
    {

        $request = request();
        $search = (string)$request->input('q', '');
        $requestedSectionId = (int)$request->input('section', 0);

        $sitesService = app(\CraftCms\Cms\Site\Sites::class);
        $selectedSite = app(RequestedSite::class)->get() ?? $sitesService->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sections = $this->getSeoSectionsForSite($siteId, $requestedSectionId);
        $sectionId = $this->resolveSelectedSeoSectionId($sections, $requestedSectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null)->sectionId($sectionId ?: [0]);
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $rows = [];
        foreach ($this->getSafeEntriesFromQuery($entryQuery, $siteId) as $entry) {
            $sectionValues = $this->getSectionCopyValues($siteId, (int)($entry->sectionId ?? 0));
            $sectionImage = $sectionValues['imageId']
                ? Elements::getElementById($sectionValues['imageId'], Asset::class, $siteId)
                : null;
            foreach ($this->getSafeSeoFields($entry) as $field) {

                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }

                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                    'value' => $value instanceof SeoFieldValue ? $value : new SeoFieldValue(),
                    'sectionValues' => $sectionValues,
                    'sectionImage' => $sectionImage,
                    'assetFieldOptions' => array_merge(
                        [['label' => 'Ninguno', 'value' => '']],
                        array_values(array_map(
                            static fn(AssetsField $assetField): array => ['label' => $assetField->name, 'value' => $assetField->handle],
                            array_filter(
                                $entry->getFieldLayout()?->getCustomFields() ?? [],
                                static fn(mixed $layoutField): bool => $layoutField instanceof AssetsField
                            )
                        ))
                    ),
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
                    ]);
    }

    public function actionSlugs(): Response
    {
        $request = request();
        $search = trim((string)$request->input('q', ''));
        $requestedSectionId = (int)$request->input('section', 0);

        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sections = $this->getSeoSectionsForSite($siteId, $requestedSectionId, false);
        $sectionId = $this->resolveSelectedSeoSectionId($sections, $requestedSectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null)->sectionId($sectionId ?: [0]);
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $entries = array_values(array_filter(
            $this->getSafeEntriesFromQuery($entryQuery, $siteId),
            fn(Entry $entry): bool => $this->isSeoSlugEntry($entry)
        ));

        return $this->renderTemplate('pragmatic-web-toolkit/seo/slugs', [
            'entries' => $entries,
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'search' => $search,
            'total' => count($entries),
        ]);
    }

    public function actionRefreshSlugs(): Response
    {
        $this->requirePostRequest();

        $request = request();
        $siteId = (int)$request->input('site', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
        $refreshEntryId = (int)$request->input('refreshEntryId', 0);
        $cleanSpecialChars = (bool)$request->input('cleanSpecialChars', true);
        $entryIds = $refreshEntryId > 0
            ? [$refreshEntryId]
            : $this->extractIntIds((array)$request->input('entryIds', []));

        if (empty($entryIds)) {
            Flash::error('No entries selected.');
            return $this->redirectToPostedUrl();
        }

        if ($refreshEntryId <= 0) {
            $batches = array_chunk($entryIds, 50);
            foreach ($batches as $index => $batch) {
                dispatch(new RefreshSeoSlugsJob([
                    'siteId' => $siteId,
                    'entryIds' => $batch,
                    'cleanSpecialChars' => $cleanSpecialChars,
                    'batchNumber' => $index + 1,
                    'totalBatches' => count($batches),
                ]));
            }
            Flash::notice('La actualización de slugs se ha añadido a la cola en ' . count($batches) . ' lote(s).');
            return $this->redirectToPostedUrl();
        }

        $elements = app(\CraftCms\Cms\Element\Elements::class);
        $updated = 0;
        $errors = [];

        foreach ($entryIds as $entryId) {
            $entry = $elements->getElementById((int)$entryId, Entry::class, $siteId);
            if (!$entry instanceof Entry) {
                $errors[] = "Entry #{$entryId} not found.";
                continue;
            }

            $sourceTitle = (string)$entry->title;
            if ($cleanSpecialChars) {
                $sourceTitle = Str::ascii($sourceTitle);
            }
            $newSlug = ElementHelper::generateSlug($sourceTitle);
            $entry->slug = $newSlug;

            if (!$elements->saveElement($entry, false, false, false)) {
                $entryErrors = $entry->getFirstErrors();
                $errors[] = !empty($entryErrors)
                    ? "Entry #{$entryId}: " . implode(' ', array_values($entryErrors))
                    : "Entry #{$entryId} could not be saved.";
                continue;
            }

            $elements->updateElementSlugAndUri($entry, false, false);
            $updated++;
        }

        if (!empty($errors)) {
            Flash::error(
                'Updated ' . $updated . ' slug(s) with errors: ' . implode(' ', $errors)
            );
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Updated ' . $updated . ' slug(s).');
        return $this->redirectToPostedUrl();
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();
        $request = request();
        $saveRow = $request->input('saveRow');
        $entries = (array)$request->input('entries', []);
        $siteId = (int)$request->input('site', 0) ?: (int)Sites::getCurrentSite()->id;

        if (empty($entries)) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        if ($saveRow !== null) {
            if (!isset($entries[$saveRow])) {
                throw new BadRequestHttpException('Invalid entry payload.');
            }
            $entries = [$saveRow => $entries[$saveRow]];
        } else {
            $batches = array_chunk(array_values($entries), 50);
            foreach ($batches as $index => $batch) {
                dispatch(new SaveSeoEntriesJob([
                    'siteId' => $siteId,
                    'mode' => 'content',
                    'items' => $batch,
                    'batchNumber' => $index + 1,
                    'totalBatches' => count($batches),
                ]));
            }
            Flash::notice('El guardado de contenido SEO se ha añadido a la cola en ' . count($batches) . ' lote(s).');
            return $this->redirectToPostedUrl();
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

            $entry = Elements::getElementById($entryId, Entry::class, $siteId);
            if (!$entry instanceof Entry) {
                $errors[] = "Entry #{$entryId} not found.";
                continue;
            }

            $current = $this->resolveSeoFieldValue($entry, $fieldHandle);
            $entry->setFieldValue($fieldHandle, [
                'title' => trim((string)($values['title'] ?? '')),
                'description' => trim((string)($values['description'] ?? '')),
                'imageId' => $this->normalizeElementSelectValue($values['imageId'] ?? null),
                'imageFieldHandle' => trim((string)($values['imageFieldHandle'] ?? '')),
                'sitemapEnabled' => $current->sitemapEnabled,
                'sitemapIncludeImages' => $current->sitemapIncludeImages,
            ]);
            PragmaticWebToolkit::$plugin->seoContentAiInstructions->saveInstructions(
                $entryId,
                $fieldHandle,
                $siteId,
                trim((string)($row['aiInstructions'] ?? ''))
            );

            if (!Elements::saveElement($entry, false, false)) {
                $entryErrors = $entry->getFirstErrors();
                $errors[] = !empty($entryErrors)
                    ? "Entry #{$entryId}: " . implode(' ', array_values($entryErrors))
                    : "Entry #{$entryId} could not be saved.";
                continue;
            }

            $applied++;
        }

        if (!empty($errors)) {
            Flash::error(
                $saveRow !== null
                    ? 'Could not save SEO content.'
                    : ('Saved ' . $applied . ' rows with errors: ' . implode(' ', $errors))
            );
            return $this->redirectToPostedUrl();
        }

        Flash::notice($saveRow !== null ? 'SEO content saved.' : ('SEO content saved for ' . $applied . ' rows.'));
        return $this->redirectToPostedUrl();
    }

    public function actionGenerateContentSuggestionBatch(): Response
    {
        $this->requirePostRequest();

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $items = (array)$request->input('items', []);
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

                $entry = Elements::getElementById($entryId, Entry::class, $siteId);
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $items = (array)$request->input('items', []);
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

                $entry = Elements::getElementById($entryId, Entry::class, $siteId);
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
            $site = Sites::getSiteById($siteId);
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $itemsJson = trim((string)$request->input('itemsJson', ''));
            if ($itemsJson !== '') {
                try {
                    $decoded = json_decode($itemsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestHttpException('Invalid items JSON: ' . $e->getMessage());
                }
                $items = is_array($decoded) ? $decoded : [];
            } else {
                $items = (array)$request->input('items', []);
            }

            if (empty($items)) {
                $bundle = $this->readContentImportBundleFromRequest($request);
                $classification = $this->classifyContentImportBundle($bundle, $siteId);
                $items = $classification['matchedChanged'];
            }

            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $batches = array_chunk(array_values($items), 50);
            foreach ($batches as $index => $batch) {
                dispatch(new SaveSeoEntriesJob([
                    'siteId' => $siteId,
                    'mode' => 'content',
                    'items' => $batch,
                    'batchNumber' => $index + 1,
                    'totalBatches' => count($batches),
                ]));
            }

            return $this->asJson([
                'success' => true,
                'summary' => [
                    'applied' => 0,
                    'queued' => count($items),
                    'skipped' => 0,
                    'errors' => [],
                ],
            ]);

        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionAssets(): Response
    {
        $request = request();
        $rawSectionParam = $request->query('section');
        $isNoSection = is_string($rawSectionParam) && strtolower(trim($rawSectionParam)) === 'none';
        $sectionId = $isNoSection ? 0 : (int)$rawSectionParam;
        $sort = strtolower(trim((string)$request->query('sort', 'used')));
        if (!in_array($sort, ['used', 'asset'], true)) {
            $sort = 'used';
        }
        $dir = strtolower(trim((string)$request->query('dir', $sort === 'used' ? 'desc' : 'asc')));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = $sort === 'used' ? 'desc' : 'asc';
        }

        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sectionAssetCounts = $this->getSectionAssetCountsForSite($siteId);
        $noSectionAssetIds = $this->getUsedAssetIdsForSite($siteId, null, true);
        $noSectionCount = count($noSectionAssetIds);
        $validSectionIds = array_map(static fn(array $section): int => (int)$section['id'], $sectionAssetCounts);
        if (!$isNoSection && !in_array($sectionId, $validSectionIds, true)) {
            if (!empty($validSectionIds)) {
                $sectionId = $validSectionIds[0];
            } elseif ($noSectionCount > 0) {
                $isNoSection = true;
            }
        }
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
        $usedIds = $assetIds;
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
                    ]);
    }

    public function actionSaveAssets(): Response
    {
        $this->requirePostRequest();

        $assetsData = (array)request()->input('assets', []);
        $saveRowId = (int)request()->input('saveRowId', 0);
        if ($saveRowId > 0) {
            $assetsData = isset($assetsData[$saveRowId]) ? [$saveRowId => $assetsData[$saveRowId]] : [];
        }
        if (empty($assetsData)) {
            Flash::error('No asset data was received.');
            return $this->redirectToPostedUrl();
        }

        $siteId = (int)request()->input('site', 0);
        if (!$siteId) {
            $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
            $siteId = (int)$selectedSite->id;
        }
        if ($saveRowId <= 0) {
            $items = [];
            foreach ($assetsData as $assetId => $data) {
                $fields = (array)($data['fields'] ?? []);
                $items[] = [
                    'assetId' => (int)$assetId,
                    'after' => [
                        'title' => trim((string)($data['title'] ?? '')),
                        'alt' => trim((string)($fields['__native_alt__'] ?? '')),
                        'aiInstructions' => trim((string)($fields['__ai_instructions__'] ?? '')),
                        'fields' => array_diff_key($fields, ['__native_alt__' => true, '__ai_instructions__' => true]),
                    ],
                ];
            }
            $batches = array_chunk($items, 25);
            foreach ($batches as $batch) {
                dispatch(new SeoAssetsImportJob(['siteId' => $siteId, 'items' => $batch]));
            }
            Flash::notice('El guardado de assets SEO se ha añadido a la cola en ' . count($batches) . ' lote(s).');
            return $this->redirectToPostedUrl();
        }
        $elements = app(\CraftCms\Cms\Element\Elements::class);
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
            Flash::error(implode(' ', $errors));
            return $this->redirectToPostedUrl();
        }

        Flash::notice('SEO assets saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionGenerateAssetMetadataBatch(): Response
    {
        $this->requirePostRequest();

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $assetIds = $this->extractIntIds((array)$request->input('assetIds', []));
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $assetIds = $this->extractIntIds((array)$request->input('assetIds', []));
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
            $site = Sites::getSiteById($siteId);
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
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

        try {
            $request = request();
            $siteId = (int)$request->input('siteId', 0) ?: (int)(app(RequestedSite::class)->get()?->id ?? Sites::getPrimarySite()->id);
            $items = [];
            $itemsJson = trim((string)$request->input('itemsJson', ''));
            $rawItems = (array)$request->input('items', []);

            $previewToken = trim((string)$request->input('previewToken', ''));
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
                $jsonText = trim((string)$request->input('jsonText', ''));
                if ($jsonText !== '') {
                    $bundle = $this->readImportBundleFromRequest($request);
                    $classification = $this->classifyImportBundle($bundle, $siteId);
                    $items = $classification['matchedChanged'];
                }
            }
            if (empty($items)) {
                throw new BadRequestHttpException('No items to apply.');
            }

            $importToken = Str::random(24);
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

            $jobId = dispatch(new SeoAssetsImportJob([
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

        try {
            $token = trim((string)request()->input('importToken', ''));
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

        $request = request();
        $selectedSite = app(RequestedSite::class)->get() ?? Sites::getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sitemapUrl = Url::siteUrl('sitemap.xml', null, null, $siteId);
        $requestedSectionId = (int)$request->query('section', 0);
        $sections = $this->getSeoSectionsForSite($siteId, $requestedSectionId);
        $sectionId = $this->resolveSelectedSeoSectionId($sections, $requestedSectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null)->sectionId($sectionId ?: [0]);

        $rows = [];
        foreach ($this->getSafeEntriesFromQuery($entryQuery, $siteId) as $entry) {
            foreach ($this->getSafeSeoFields($entry) as $field) {
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
                    'sitemapIncludeImages' => $value->sitemapIncludeImages ?? true,
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
                    ]);
    }

    public function actionSaveSitemap(): Response
    {
        $this->requirePostRequest();
        $request = request();
        $entriesJson = $request->input('entriesJson');
        if (is_string($entriesJson)) {
            $entries = json_decode($entriesJson, true);
            if (!is_array($entries)) {
                throw new BadRequestHttpException('Invalid sitemap entries payload.');
            }
        } else {
            // Backwards compatibility with forms rendered by previous plugin versions.
            $entries = (array)$request->input('entries', []);
        }
        $siteId = (int)$request->input('site', 0) ?: (int)Sites::getCurrentSite()->id;

        if (!empty($entries)) {
            $batches = array_chunk(array_values($entries), 50);
            foreach ($batches as $index => $batch) {
                dispatch(new SaveSeoEntriesJob([
                    'siteId' => $siteId,
                    'mode' => 'sitemap',
                    'items' => $batch,
                    'batchNumber' => $index + 1,
                    'totalBatches' => count($batches),
                ]));
            }
            Flash::notice('El guardado del sitemap se ha añadido a la cola en ' . count($batches) . ' lote(s).');
            return $this->redirectToPostedUrl();
        }

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entryId = (int)($row['entryId'] ?? 0);
            $fieldHandle = (string)($row['fieldHandle'] ?? '');
            if (!$entryId || $fieldHandle === '') {
                continue;
            }

            $entry = Elements::getElementById($entryId, Entry::class, $siteId);
            if (!$entry) {
                continue;
            }

            $current = $this->resolveSeoFieldValue($entry, $fieldHandle);

            $entry->setFieldValue($fieldHandle, [
                'title' => $current->title,
                'description' => $current->description,
                'imageId' => $current->imageId,
                'imageFieldHandle' => $current->imageFieldHandle,
                'sitemapEnabled' => !empty($row['sitemapEnabled']),
                'sitemapIncludeImages' => !empty($row['sitemapIncludeImages']),
            ]);

            Elements::saveElement($entry, false, false);
        }

        Flash::notice('Sitemap settings saved.');
        return $this->redirectToPostedUrl();
    }

    private function resolveSeoFieldValue(Entry $entry, string $fieldHandle): SeoFieldValue
    {
        $current = $entry->getFieldValue($fieldHandle);
        if (!$current instanceof SeoFieldValue) {
            $field = $this->getSafeSeoFieldByHandle($entry, $fieldHandle);
            if ($field instanceof SeoField) {
                $current = $field->normalizeValue($current, $entry);
            }
        }

        return $current instanceof SeoFieldValue ? $current : new SeoFieldValue();
    }

    public function actionRobotsTxt(): Response
    {
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('seo')) {
            throw new NotFoundHttpException();
        }

        $site = Sites::getCurrentSite();
        $content = PragmaticWebToolkit::$plugin->seoSitemap->robotsTxt((int)$site->id, request()->getHost());

        return $this->asRaw($content, 'text/plain; charset=UTF-8');
    }

    public function actionSitemapXml(): Response
    {
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('seo')) {
            throw new NotFoundHttpException();
        }

        $site = Sites::getCurrentSite();
        $siteId = (int)$site->id;
        $entryTypeRows = $this->getSitemapEntryTypeRows($siteId);
        $sitemaps = $this->buildSitemapIndexRows($siteId, $entryTypeRows);
            $xml = template('pragmatic-web-toolkit/seo/sitemap_index_xml', [
                'sitemaps' => $sitemaps,
            ], TemplateMode::Cp);

        return $this->asRaw($xml, 'application/xml; charset=UTF-8');
    }

    public function actionSitemapSectionXml(string $sectionHandle): Response
    {
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('seo')) {
            throw new NotFoundHttpException();
        }

        $site = Sites::getCurrentSite();
        $siteId = (int)$site->id;
        $baseUrl = rtrim((string)$site->baseUrl, '/');
        $sectionHandle = trim($sectionHandle);

        $section = Sections::getSectionByHandle($sectionHandle);
        if ($section === null) {
            throw new NotFoundHttpException('Sitemap section not found.');
        }

        $entryTypeRows = $this->getSitemapEntryTypeRows($siteId);
        $hasEnabledSection = false;
        foreach ($entryTypeRows as $row) {
            if ((int)($row['sectionId'] ?? 0) === (int)$section->id && !empty($row['settings']['enabled'])) {
                $hasEnabledSection = true;
                break;
            }
        }
        if (!$hasEnabledSection) {
            throw new NotFoundHttpException('Sitemap section is not enabled.');
        }

        $urls = $this->buildSitemapUrlsForSection($siteId, (int)$section->id, $entryTypeRows, $baseUrl, !request()->isCpRequest());
            $xml = template('pragmatic-web-toolkit/seo/sitemap_xml', [
                'urls' => $urls,
            ], TemplateMode::Cp);

        return $this->asRaw($xml, 'application/xml; charset=UTF-8');
    }

    /**
     * @param array<int,array<string,mixed>> $entryTypeRows
     * @return array<int,array{loc:string,lastmod:?string}>
     */
    private function buildSitemapIndexRows(int $siteId, array $entryTypeRows): array
    {
        $sectionsByHandle = [];
        foreach ($entryTypeRows as $typeRow) {
            if (empty($typeRow['settings']['enabled'])) {
                continue;
            }

            $sectionHandle = trim((string)($typeRow['sectionHandle'] ?? ''));
            if ($sectionHandle === '') {
                continue;
            }

            $sectionsByHandle[$sectionHandle] = true;
        }

        $rows = [];
        foreach (array_keys($sectionsByHandle) as $sectionHandle) {
            $rows[] = [
                'loc' => Url::siteUrl('sitemap-' . $sectionHandle . '.xml', null, null, $siteId),
                'lastmod' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $entryTypeRows
     * @return array<int,array{loc:string,lastmod:?string,images:array<int,array{assetId:int,loc:string,title:string,caption:string}>}>
     */
    private function buildSitemapUrlsForSection(
        int $siteId,
        int $sectionId,
        array $entryTypeRows,
        string $baseUrl,
        bool $allowPrimarySiteFallback
    ): array {
        $urlsByLoc = [];
        $sitemapEntries = PragmaticWebToolkit::$plugin->seoSitemap->sectionEntries($siteId, $sectionId, $entryTypeRows, $baseUrl);

        foreach ($sitemapEntries as $loc => ['entry' => $entry, 'seoValue' => $seoValue, 'seoHandle' => $seoHandle, 'typeRow' => $typeRow]) {
            $images = [];
            $includeImages = $seoValue instanceof SeoFieldValue && $seoValue->sitemapIncludeImages !== null
                ? (bool)$seoValue->sitemapIncludeImages
                : (bool)($typeRow['settings']['includeImages'] ?? false);
            if ($includeImages) {
                $seenAssetIds = [];

                $seoImageAsset = $this->resolveSitemapImageAsset($entry, $seoValue, $seoHandle, $siteId, $allowPrimarySiteFallback);
                if ($seoImageAsset instanceof Asset) {
                    $seoImageNode = $this->buildSitemapImageNode($seoImageAsset, $baseUrl);
                    if ($seoImageNode !== null) {
                        $images[] = $seoImageNode;
                        $seenAssetIds[(int)$seoImageAsset->id] = true;
                    }
                }

                if (count($images) < self::SITEMAP_MAX_IMAGES_PER_URL) {
                    $images = [
                        ...$images,
                        ...$this->getEntrySitemapImageNodes(
                            $entry,
                            $baseUrl,
                            $seenAssetIds,
                            self::SITEMAP_MAX_IMAGES_PER_URL - count($images)
                        ),
                    ];
                }
            }

            $urlsByLoc[$loc] = [
                'loc' => $loc,
                'lastmod' => $entry->dateUpdated?->format(DATE_ATOM),
                'images' => $images,
            ];
        }

        return array_values($urlsByLoc);
    }

    private function resolveSitemapImageAsset(
        Entry $entry,
        ?SeoFieldValue $seoValue,
        string $fieldHandle,
        int $siteId,
        bool $allowPrimarySiteFallback
    ): ?Asset {
        $imageId = $seoValue?->imageId ?: null;
        if (!$imageId && $seoValue) {
            $imageId = $this->resolveEntryAssetFieldId($entry, $seoValue->imageFieldHandle);
        }
        if ((!$imageId || $imageId <= 0) && $allowPrimarySiteFallback) {
            $imageId = $this->resolvePrimarySiteEntrySeoImageId($entry, $fieldHandle);
        }
        if ((!$imageId || $imageId <= 0) && $entry->sectionId) {
            $sectionSettings = PragmaticWebToolkit::$plugin->seoMetaSettings
                ->getSectionSettings($siteId, (int)$entry->sectionId);
            $imageId = !empty($sectionSettings['defaultSiteImageId'])
                ? (int)$sectionSettings['defaultSiteImageId']
                : $this->resolveEntryAssetFieldId($entry, (string)($sectionSettings['defaultSiteImageFieldHandle'] ?? ''));
        }
        if (!$imageId || $imageId <= 0) {
            $siteSettings = PragmaticWebToolkit::$plugin->seoMetaSettings->getSiteSettings($siteId);
            $imageId = !empty($siteSettings['defaultSiteImageId']) ? (int)$siteSettings['defaultSiteImageId'] : null;
        }
        if (!$imageId || $imageId <= 0) {
            return null;
        }

        return $this->resolveAssetByIdForSitemap((int)$imageId, $siteId);
    }

    private function resolveEntryAssetFieldId(Entry $entry, string $fieldHandle): ?int
    {
        $fieldHandle = trim($fieldHandle);
        if ($fieldHandle === '' || !$entry->getFieldLayout()?->getFieldByHandle($fieldHandle)) {
            return null;
        }

        try {
            $value = $entry->getFieldValue($fieldHandle);
            if ($value instanceof Asset) {
                return (int)$value->id;
            }
            if (is_object($value) && method_exists($value, 'one')) {
                $asset = $value->one();
                return $asset instanceof Asset ? (int)$asset->id : null;
            }
            if (is_array($value)) {
                $first = reset($value);
                return $first instanceof Asset ? (int)$first->id : (is_numeric($first) ? (int)$first : null);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function resolvePrimarySiteEntrySeoImageId(Entry $entry, string $fieldHandle): ?int
    {
        $sites = app(\CraftCms\Cms\Site\Sites::class);
        $primarySite = $sites->getPrimarySite();
        if (!$primarySite) {
            return null;
        }
        if ((int)$entry->siteId === (int)$primarySite->id) {
            return null;
        }

        $canonicalId = $entry->canonicalId ?: $entry->id;
        if (!$canonicalId) {
            return null;
        }

        $primaryEntry = Entry::find()
            ->id((int)$canonicalId)
            ->siteId((int)$primarySite->id)
            ->status(null)
            ->one();
        if (!$primaryEntry instanceof Entry) {
            return null;
        }

        $primaryField = $this->getSafeSeoFieldByHandle($primaryEntry, $fieldHandle);
        if (!$primaryField instanceof SeoField) {
            return null;
        }

        $primaryValue = $primaryEntry->getFieldValue($fieldHandle);
        if (!$primaryValue instanceof SeoFieldValue) {
            $primaryValue = $primaryField->normalizeValue($primaryValue, $primaryEntry);
        }
        if (!$primaryValue instanceof SeoFieldValue) {
            return null;
        }

        $imageId = $primaryValue->imageId ?: $this->resolveEntryAssetFieldId($primaryEntry, $primaryValue->imageFieldHandle);
        if (!$imageId || $imageId <= 0) {
            return null;
        }

        return (int)$imageId;
    }

    private function resolveAssetByIdForSitemap(int $assetId, int $siteId): ?Asset
    {
        if ($assetId <= 0) {
            return null;
        }

        $asset = Elements::getElementById($assetId, Asset::class, $siteId);
        if (!$asset instanceof Asset) {
            $asset = Asset::find()->id($assetId)->status(null)->one();
        }

        return $asset instanceof Asset ? $asset : null;
    }

    /**
     * @return array{assetId:int,loc:string,title:string,caption:string}|null
     */
    private function buildSitemapImageNode(Asset $asset, string $baseUrl): ?array
    {
        $url = $asset->getUrl();
        if (!$url) {
            return null;
        }

        $loc = str_starts_with($url, 'http') ? $url : $baseUrl . '/' . ltrim($url, '/');
        $title = trim((string)$asset->title);
        if ($title === '') {
            $title = trim((string)$asset->filename);
        }
        $caption = trim((string)($this->getAssetAltValue($asset) ?? ''));
        if ($caption === '') {
            $caption = $title;
        }

        return [
            'assetId' => (int)$asset->id,
            'loc' => $loc,
            'title' => $title,
            'caption' => $caption,
        ];
    }

    /**
     * @return array<int,array{assetId:int,loc:string,title:string,caption:string}>
     */
    /**
     * Image nodes from the entry's own asset fields, in field layout order.
     *
     * @param array<int, bool> $seenAssetIds Assets already added for this URL.
     */
    private function getEntrySitemapImageNodes(Entry $entry, string $baseUrl, array $seenAssetIds, int $limit): array
    {
        $nodes = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof AssetsField) {
                continue;
            }

            try {
                $query = $entry->getFieldValue($field->handle);
                $assets = is_object($query) && method_exists($query, 'kind')
                    ? (clone $query)->kind(FileKind::Image->value)->status(null)->all()
                    : [];
            } catch (\Throwable) {
                continue;
            }

            foreach ($assets as $asset) {
                if (!$asset instanceof Asset || isset($seenAssetIds[(int)$asset->id])) {
                    continue;
                }
                $node = $this->buildSitemapImageNode($asset, $baseUrl);
                if ($node === null) {
                    continue;
                }
                $nodes[] = $node;
                $seenAssetIds[(int)$asset->id] = true;
                if (count($nodes) >= $limit) {
                    return $nodes;
                }
            }
        }

        return $nodes;
    }

    private function getSeoSectionsForSite(int $siteId, int $selectedSectionId = 0, bool $requireSeoEntryType = true): array
    {
        $sections = Sections::getAllSections()->all();
        $result = [];

        foreach ($sections as $section) {
            if (!$this->sectionSupportsSectionSeo($section)) {
                continue;
            }

            $seoEntryTypeIds = $this->getSectionSeoEntryTypeIds($section);
            if ($requireSeoEntryType && empty($seoEntryTypeIds)) {
                continue;
            }

            // Keep the navigation cheap: loading every entry merely to calculate the
            // sidebar count defeats the purpose of requiring a section filter.
            $countQuery = Entry::find()
                ->siteId($siteId)
                ->sectionId($section->id)
                ->status(null);
            if ($requireSeoEntryType) {
                $countQuery->typeId($seoEntryTypeIds);
            }
            $count = (int)$countQuery->count();

            if ($count > 0 || $section->id === $selectedSectionId) {
                $result[] = [
                    'id' => $section->id,
                    'name' => $section->name,
                    'count' => $count,
                    'type' => $this->sectionType($section),
                ];
            }
        }

        usort($result, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

        return $result;
    }

    private function resolveSelectedSeoSectionId(array $sections, int $requestedSectionId = 0): int
    {
        if ($requestedSectionId > 0) {
            foreach ($sections as $section) {
                if ((int)($section['id'] ?? 0) === $requestedSectionId) {
                    return $requestedSectionId;
                }
            }
        }

        return (int)($sections[0]['id'] ?? 0);
    }

    private function getAllSectionsAlphabetically(): array
    {
        $sections = [];
        foreach (Sections::getAllSections()->all() as $section) {
            if (
                (int)($section->id ?? 0) <= 0
                || !$this->sectionSupportsSectionSeo($section)
                || empty($this->getSectionSeoEntryTypeIds($section))
            ) {
                continue;
            }

            $sections[] = [
                'id' => (int)$section->id,
                'name' => (string)$section->name,
                'type' => (string)\CraftCms\Cms\enum_value($section->type ?? ''),
            ];
        }
        usort($sections, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

        return $sections;
    }

    /**
     * @return int[]
     */
    private function getSectionSeoEntryTypeIds(mixed $section): array
    {
        if (!is_object($section) || !method_exists($section, 'getEntryTypes')) {
            return [];
        }

        $entryTypeIds = [];
        foreach ($section->getEntryTypes() as $entryType) {
            foreach ($entryType->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if ($field instanceof SeoField) {
                    $entryTypeIds[] = (int)$entryType->id;
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($entryTypeIds)));
    }

    private function entryHasSeoField(Entry $entry): bool
    {
        foreach ($this->getSafeSeoFields($entry) as $field) {
            if ($field instanceof SeoField) {
                return true;
            }
        }

        return false;
    }

    private function sectionType(mixed $section): string
    {
        if (!is_object($section)) {
            return '';
        }

        return strtolower(trim((string)\CraftCms\Cms\enum_value($section->type ?? '')));
    }

    private function sectionSupportsSectionSeo(mixed $section): bool
    {
        return in_array($this->sectionType($section), ['channel', 'structure'], true);
    }

    /**
     * @return Entry[]
     */
    private function getSafeEntriesFromQuery(EntryQuery $entryQuery, int $siteId): array
    {
        return PragmaticWebToolkit::$plugin->seoSitemap->safeEntriesFromQuery($entryQuery, $siteId);
    }

    private function isSeoSlugEntry(Entry $entry): bool
    {
        if ((int)($entry->sectionId ?? 0) <= 0) {
            return false;
        }

        if ((int)($entry->primaryOwnerId ?? 0) > 0 || (int)($entry->ownerId ?? 0) > 0 || (int)($entry->fieldId ?? 0) > 0) {
            return false;
        }

        return true;
    }

    /**
     * @return SeoField[]
     */
    private function getSafeSeoFields(Entry $entry): array
    {
        try {
            $fields = $entry->getFieldLayout()?->getCustomFields() ?? [];
        } catch (\Throwable $e) {
            $this->logSkippedSeoEntry($entry, $e, 'field layout');
            return [];
        }

        return array_values(array_filter($fields, static fn(mixed $field): bool => $field instanceof SeoField));
    }

    private function getSafeSeoFieldByHandle(Entry $entry, string $fieldHandle): ?SeoField
    {
        try {
            $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
        } catch (\Throwable $e) {
            $this->logSkippedSeoEntry($entry, $e, sprintf('field "%s"', $fieldHandle));
            return null;
        }

        return $field instanceof SeoField ? $field : null;
    }

    private function getSafeEntryCpEditUrl(Entry $entry): string
    {
        try {
            return (string)$entry->cpEditUrl;
        } catch (\Throwable $e) {
            $this->logSkippedSeoEntry($entry, $e, 'cp edit url');
            return '';
        }
    }

    private function logSkippedSeoEntry(Entry $entry, \Throwable $e, string $context): void
    {
        Log::warning(sprintf(
            'Skipping SEO %s for entry #%d because its %s could not be resolved: %s',
            __METHOD__,
            (int)($entry->id ?? 0),
            $context,
            $e->getMessage()
        ));
    }

    private function getSectionAssetCountsForSite(int $siteId): array
    {
        $result = [];
        $sections = Sections::getAllSections()->all();
        foreach ($sections as $section) {
            $result[] = [
                'id' => (int)$section->id,
                'name' => (string)$section->name,
                'count' => null,
            ];
        }

        usort($result, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

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
            ->from(['r' => 'relations'])
            ->innerJoin(['a' => 'assets'], '[[a.id]] = [[r.targetId]]');

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
            Sections::getAllSections()->all()
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
            ->from(['r' => 'relations'])
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

        $entries = $this->getSafeEntriesFromQuery(Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->id(array_keys($relatedEntryIds)), $siteId);

        $entriesById = [];
        foreach ($entries as $entry) {
            $entriesById[(int)$entry->id] = $entry;
        }

        $result = [];
        foreach ($entryByAsset as $assetId => $ids) {
            $uniqueEntryIds = array_values(array_unique(array_map('intval', $ids)));
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
                'cpEditUrl' => $this->getSafeEntryCpEditUrl($first),
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

        return in_array(strtolower(get_class($field)), ['craftcms\\ckeditor\\field', 'craft\\ckeditor\\field'], true);
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
            Assets::moveAsset($asset, $folder, $targetFilename);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function buildSeoFilenameFromTitle(Asset $asset, string $title): string
    {
        $baseName = trim(Str::slug($title));
        if ($baseName === '') {
            $currentBaseName = pathinfo($asset->filename, PATHINFO_FILENAME);
            $baseName = trim(Str::slug($currentBaseName));
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
                $filename = Assets::getNameReplacementInFolder($filename, $folder->id);
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
        $token = Str::random(24);
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
        File::ensureDirectoryExists(dirname($path));
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
        File::ensureDirectoryExists(dirname($path));
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

            $asset = Elements::getElementById($assetId, Asset::class, $siteId);
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

            $entry = Elements::getElementById($entryId, Entry::class, $siteId);
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
                'imageFieldHandle' => trim($value->imageFieldHandle),
            ];
            $after = [
                'aiInstructions' => trim((string)($item['aiInstructions'] ?? '')),
                'title' => trim((string)($item['title'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'imageId' => $this->normalizeElementSelectValue($item['imageId'] ?? null),
                'imageFieldHandle' => array_key_exists('imageFieldHandle', $item)
                    ? trim((string)$item['imageFieldHandle'])
                    : trim($value->imageFieldHandle),
            ];

            $changedFields = [];
            foreach (['aiInstructions', 'title', 'description', 'imageId', 'imageFieldHandle'] as $key) {
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

    private function readImportBundleFromRequest(Request $request): array
    {
        $jsonText = trim((string)$request->input('jsonText', ''));
        if ($jsonText === '') {
            $uploaded = $request->file('jsonFile');
            if ($uploaded instanceof UploadedFile) {
                $jsonText = trim((string)file_get_contents($uploaded->getPathname()));
            }
        }

        if ($jsonText === '') {
            throw new BadRequestHttpException('Provide JSON text or a JSON file.');
        }

        $jsonText = $this->stripJsonMarkdownFence($jsonText);

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

    private function readContentImportBundleFromRequest(Request $request): array
    {
        $jsonText = trim((string)$request->input('jsonText', ''));
        if ($jsonText === '') {
            $uploaded = $request->file('jsonFile');
            if ($uploaded instanceof UploadedFile) {
                $jsonText = trim((string)file_get_contents($uploaded->getPathname()));
            }
        }

        if ($jsonText === '') {
            throw new BadRequestHttpException('Provide JSON text or a JSON file.');
        }

        $jsonText = $this->stripJsonMarkdownFence($jsonText);

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

    private function stripJsonMarkdownFence(string $jsonText): string
    {
        $trimmed = trim($jsonText);
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $trimmed, $matches) === 1) {
            return trim((string)$matches[1]);
        }

        return $trimmed;
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

    private function getSitemapEntryTypeRows(int $siteId): array
    {
        return PragmaticWebToolkit::$plugin->seoSitemap->entryTypeRows($siteId);
    }
}

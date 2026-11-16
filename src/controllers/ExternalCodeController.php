<?php

namespace pragmatic\webtoolkit\controllers;

use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

class ExternalCodeController extends Controller
{
    public function beforeAction(string $action): bool
    {
        $this->requireCpRequest();
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('externalCode')) {
            throw new NotFoundHttpException('External Code is disabled.');
        }
        $this->requirePermission('pragmatic-toolkit:manage-external-code');
        if (in_array($action, ['save', 'delete', 'toggle-site', 'reorder'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $selectedSite = $this->selectedSite();
        $service = PragmaticWebToolkit::$plugin->externalCode;
        $snippets = $service->all();
        foreach ($snippets as &$snippet) {
            $snippet['enabledForSelectedSite'] = $service->isEnabledForSite($snippet, (int)$selectedSite->id);
        }
        unset($snippet);
        $groups = [
            'head' => ['label' => 'Inside <head>', 'description' => 'Code placed before the closing head tag.', 'snippets' => []],
            'beginBody' => ['label' => 'After <body>', 'description' => 'Code placed immediately after the opening body tag.', 'snippets' => []],
            'endBody' => ['label' => 'Before </body>', 'description' => 'Code placed before the closing body tag.', 'snippets' => []],
        ];
        foreach ($snippets as $snippet) {
            $position = (string)($snippet['position'] ?? '');
            if (isset($groups[$position])) {
                $groups[$position]['snippets'][] = $snippet;
            }
        }
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/index', [
            'snippets' => $snippets,
            'groups' => $groups,
            'selectedSite' => $selectedSite,
        ]);
    }

    public function actionEdit(?string $id = null): Response
    {
        $snippet = $id === null ? null : PragmaticWebToolkit::$plugin->externalCode->find($id);
        if ($id !== null && $snippet === null) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        $sites = Sites::getAllSites()->all();
        $states = $snippet === null
            ? array_fill_keys(array_map(static fn($site): int => (int)$site->id, $sites), true)
            : PragmaticWebToolkit::$plugin->externalCode->siteStates($snippet);
        return $this->renderTemplate('pragmatic-web-toolkit/external-code/edit', [
            'snippet' => $snippet ?? ['position' => 'endBody', 'sortOrder' => count(PragmaticWebToolkit::$plugin->externalCode->all()) + 1],
            'siteStates' => $states,
            'sites' => Sites::getAllSites()->all(),
            'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories((int)$this->selectedSite()->id) : [],
            'selectedSite' => $this->selectedSite(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $input = (array)$this->request->input('snippet', []);
        $id = trim((string)$this->request->input('id', '')) ?: null;
        if ($id !== null && PragmaticWebToolkit::$plugin->externalCode->find($id) === null) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        if (!PragmaticWebToolkit::$plugin->externalCode->save($input, $id)) {
            Flash::error('Could not save external code. Check the name, code, site and consent category.');
            return $this->renderTemplate('pragmatic-web-toolkit/external-code/edit', [
                'snippet' => array_merge($input, ['id' => $id]),
                'sites' => Sites::getAllSites()->all(),
                'siteStates' => (array)($input['enabledBySite'] ?? []),
                'categories' => PragmaticWebToolkit::$plugin->domains->isEnabled('cookies') ? PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories((int)$this->selectedSite()->id) : [],
                'selectedSite' => $this->selectedSite(),
            ]);
        }
        Flash::notice('External code saved.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionDelete(): Response
    {
        $id = (string)$this->request->input('id', '');
        if (!PragmaticWebToolkit::$plugin->externalCode->delete($id)) {
            throw new NotFoundHttpException('Snippet not found.');
        }
        Flash::notice('External code deleted.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionToggleSite(): Response
    {
        $id = (string)$this->request->input('id', '');
        $siteId = (int)$this->request->input('siteId', 0);
        $enabled = (bool)$this->request->input('enabled', false);
        if (!PragmaticWebToolkit::$plugin->externalCode->setSiteEnabled($id, $siteId, $enabled)) {
            throw new NotFoundHttpException('Snippet or site not found.');
        }
        Flash::notice('Site status updated.');
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    public function actionReorder(): Response
    {
        $ids = (array)$this->request->input('ids', []);
        if (!PragmaticWebToolkit::$plugin->externalCode->reorder($ids)) {
            Flash::error('Could not save snippet order.');
        } else {
            Flash::notice('Snippet order saved.');
        }
        return $this->redirect('pragmatic-toolkit/external-code?site=' . $this->selectedSite()->handle);
    }

    private function selectedSite(): \CraftCms\Cms\Site\Data\Site
    {
        $handle = (string)$this->request->input('site', '');
        return ($handle !== '' ? Sites::getSiteByHandle($handle) : null)
            ?? Sites::getPrimarySite();
    }
}

<?php

namespace pragmatic\webtoolkit\controllers;

use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpFoundation\Response;
use CraftCms\Cms\Support\Flash;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/dashboard/index');
    }

    public function actionConfiguration(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/dashboard/configuration', [
            'domains' => $this->buildDomainConfigRows(),
        ]);
    }

    public function actionSaveConfiguration(): Response
    {
        $this->requirePostRequest();
        $rows = (array)request()->input('domains', []);

        $domains = PragmaticWebToolkit::$plugin->domains->all();
        $currentConfig = PragmaticWebToolkit::$plugin->domainConfig->getConfiguration($domains);
        $baseOrder = array_flip(array_values(array_keys($domains)));
        $config = [];

        foreach ($domains as $key => $_provider) {
            if (PragmaticWebToolkit::$plugin->domains->isHidden($key)) {
                $config[$key] = $currentConfig[$key];
                continue;
            }
            $enabled = !empty($rows[$key]['enabled']);

            $postedOrder = (int)($rows[$key]['order'] ?? 0);
            $fallbackOrder = isset($baseOrder[$key]) ? ((int)$baseOrder[$key] + 1) : 1;
            $config[$key] = [
                'enabled' => $enabled,
                'order' => $postedOrder > 0 ? $postedOrder : $fallbackOrder,
            ];
        }

        if (!PragmaticWebToolkit::$plugin->domainConfig->saveConfiguration($domains, $config)) {
            Flash::error('Could not save dashboard configuration.');
            return $this->redirectToPostedUrl();
        }

        Flash::notice('Dashboard configuration saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * @return array<int, array{key:string,label:string,enabled:bool,order:int}>
     */
    private function buildDomainConfigRows(): array
    {
        $providers = PragmaticWebToolkit::$plugin->domains->visible();
        $config = PragmaticWebToolkit::$plugin->domainConfig->getConfiguration($providers);
        uasort($config, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        $rows = [];
        foreach ($providers as $key => $provider) {
            $domainConfig = $config[$key] ?? ['enabled' => false, 'order' => count($rows) + 1];

            $rows[] = [
                'key' => $key,
                'label' => $provider::navLabel(),
                'enabled' => (bool)$domainConfig['enabled'],
                'order' => (int)$domainConfig['order'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return $a['order'] <=> $b['order'];
            }
        );

        return $rows;
    }
}

<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Response;

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
        $rows = (array)Craft::$app->getRequest()->getBodyParam('domains', []);

        $domains = PragmaticWebToolkit::$plugin->domains->all();
        $baseOrder = array_flip(array_values(array_keys($domains)));
        $config = [];

        foreach ($domains as $key => $_provider) {
            $enabled = !empty($rows[$key]['enabled']);

            $postedOrder = (int)($rows[$key]['order'] ?? 0);
            $fallbackOrder = isset($baseOrder[$key]) ? ((int)$baseOrder[$key] + 1) : 1;
            $config[$key] = [
                'enabled' => $enabled,
                'order' => $postedOrder > 0 ? $postedOrder : $fallbackOrder,
            ];
        }

        if (!PragmaticWebToolkit::$plugin->domainConfig->saveConfiguration($domains, $config)) {
            Craft::$app->getSession()->setError('Could not save dashboard configuration.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Dashboard configuration saved.');
        return $this->redirectToPostedUrl();
    }

    /**
     * @return array<int, array{key:string,label:string,enabled:bool,order:int}>
     */
    private function buildDomainConfigRows(): array
    {
        $providers = PragmaticWebToolkit::$plugin->domains->all();
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

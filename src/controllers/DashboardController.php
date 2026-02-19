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

        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $domains = PragmaticWebToolkit::$plugin->domains->all();
        $baseOrder = array_values(array_keys($domains));
        $resolvedOrder = [];

        foreach ($domains as $key => $_provider) {
            $flag = 'enable' . ucfirst($key);
            $enabled = !empty($rows[$key]['enabled']);
            if (property_exists($settings, $flag)) {
                $settings->{$flag} = $enabled;
            }

            $postedOrder = (int)($rows[$key]['order'] ?? 0);
            $fallbackOrder = array_search($key, $baseOrder, true);
            $resolvedOrder[$key] = $postedOrder > 0 ? $postedOrder : (($fallbackOrder === false ? 0 : $fallbackOrder) + 1);
        }

        uasort(
            $resolvedOrder,
            static function (int $a, int $b): int {
                return $a <=> $b;
            }
        );

        $settings->domainOrder = array_values(array_keys($resolvedOrder));

        if (!Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save dashboard configuration.');
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Dashboard configuration saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionPlans(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/dashboard/plans');
    }

    /**
     * @return array<int, array{key:string,label:string,enabled:bool,order:int}>
     */
    private function buildDomainConfigRows(): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $providers = PragmaticWebToolkit::$plugin->domains->all();
        $configuredOrder = array_values(array_filter(
            (array)($settings->domainOrder ?? []),
            static fn(mixed $value): bool => is_string($value) && $value !== ''
        ));

        $rows = [];
        $orderLookup = array_flip($configuredOrder);

        foreach ($providers as $key => $provider) {
            $flag = 'enable' . ucfirst($key);
            $enabled = property_exists($settings, $flag) ? (bool)$settings->{$flag} : true;
            $order = isset($orderLookup[$key]) ? ((int)$orderLookup[$key] + 1) : (count($rows) + 1);

            $rows[] = [
                'key' => $key,
                'label' => $provider::navLabel(),
                'enabled' => $enabled,
                'order' => $order,
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

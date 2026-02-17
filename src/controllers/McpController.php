<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Response;

class McpController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/mcp/sections');
    }

    public function actionSections(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/mcp/sections', [
            'settings' => PragmaticWebToolkit::$plugin->mcpSettings->get(),
        ]);
    }

    public function actionOptions(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/mcp/options', [
            'settings' => PragmaticWebToolkit::$plugin->mcpSettings->get(),
        ]);
    }

    public function actionSaveSettings(): Response
    {
        $this->requirePostRequest();

        $fields = (array)Craft::$app->getRequest()->getBodyParam('_fields', []);
        $settings = $this->normalizeSettings($fields);

        if (PragmaticWebToolkit::$plugin->mcpSettings->saveFromArray($settings)) {
            Craft::$app->getSession()->setNotice(Craft::t('app', 'Settings saved.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setError(Craft::t('app', 'Couldn\'t save settings.'));
        return $this->redirectToPostedUrl();
    }

    private function normalizeSettings(array $fields): array
    {
        $request = Craft::$app->getRequest();
        $current = PragmaticWebToolkit::$plugin->mcpSettings->get()->toArray();
        $knownFields = [
            'enableEntries',
            'enableAssets',
            'enableCategories',
            'enableUsers',
            'allowedSections',
            'enableSearchTool',
            'enableDetailsTool',
            'enableCustomQueries',
            'maxResults',
            'maxQueryComplexity',
            'exposedFields',
            'customQueries',
            'enableCache',
            'cacheDuration',
            'accessToken',
            'allowedIpAddresses',
        ];
        $targetFields = array_intersect($knownFields, $fields);

        foreach ($targetFields as $field) {
            switch ($field) {
                case 'enableEntries':
                case 'enableAssets':
                case 'enableCategories':
                case 'enableUsers':
                case 'enableSearchTool':
                case 'enableDetailsTool':
                case 'enableCustomQueries':
                case 'enableCache':
                    $current[$field] = (bool)$request->getBodyParam($field);
                    break;

                case 'maxResults':
                case 'maxQueryComplexity':
                case 'cacheDuration':
                    $current[$field] = (int)$request->getBodyParam($field, $current[$field] ?? 0);
                    break;

                case 'allowedSections':
                    $current[$field] = array_values(array_filter(
                        array_map('strval', (array)$request->getBodyParam($field, [])),
                        static fn(string $value): bool => $value !== ''
                    ));
                    break;

                case 'exposedFields':
                    $current[$field] = $this->normalizeExposedFields($request->getBodyParam($field, []));
                    break;

                case 'customQueries':
                    $current[$field] = (array)$request->getBodyParam($field, []);
                    break;

                case 'allowedIpAddresses':
                    $raw = (string)$request->getBodyParam($field, '');
                    $lines = preg_split('/\R+/', $raw) ?: [];
                    $current[$field] = array_values(array_filter(
                        array_map(static fn(string $value): string => trim($value), $lines),
                        static fn(string $value): bool => $value !== ''
                    ));
                    break;

                case 'accessToken':
                    $current[$field] = trim((string)$request->getBodyParam($field, ''));
                    break;
            }
        }

        return $current;
    }

    private function normalizeExposedFields(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        foreach ($raw as $row) {
            if (is_array($row) && isset($row['field'])) {
                $field = trim((string)$row['field']);
                if ($field !== '') {
                    $fields[] = $field;
                }
            } elseif (is_string($row)) {
                $field = trim($row);
                if ($field !== '') {
                    $fields[] = $field;
                }
            }
        }

        return array_values(array_unique($fields));
    }
}

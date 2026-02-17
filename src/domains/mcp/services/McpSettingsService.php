<?php

namespace pragmatic\webtoolkit\domains\mcp\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\mcp\models\McpSettingsModel;

class McpSettingsService
{
    public function get(): McpSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new McpSettingsModel();
        $model->setAttributes((array)($pluginSettings->mcp ?? []), false);

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $pluginSettings->mcp = $model->toArray();

        return Craft::$app->getPlugins()->savePluginSettings(PragmaticWebToolkit::$plugin, $pluginSettings->toArray());
    }
}

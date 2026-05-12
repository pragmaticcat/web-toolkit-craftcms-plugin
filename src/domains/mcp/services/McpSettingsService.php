<?php

namespace pragmatic\webtoolkit\domains\mcp\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\mcp\models\McpSettingsModel;

class McpSettingsService
{
    public function get(): McpSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new McpSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('mcp', (array)($pluginSettings->mcp ?? []));
        $model->setAttributes($stored, false);

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $model->setAttributes($input, false);

        if (!$model->validate()) {
            return false;
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('mcp', $model->toArray());
    }
}

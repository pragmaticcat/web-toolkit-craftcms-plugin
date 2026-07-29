<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\chatbot\models\ChatbotSettingsModel;

class ChatbotSettingsService
{
    public function get(): ChatbotSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new ChatbotSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('chatbot', (array)($pluginSettings->chatbot ?? []));
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

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('chatbot', $model->toArray());
    }
}

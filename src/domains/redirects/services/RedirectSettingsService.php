<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\redirects\models\RedirectSettingsModel;

/**
 * Reads and writes redirect domain settings.
 */
class RedirectSettingsService
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * Return the saved settings or defaults.
     *
     * @return RedirectSettingsModel Settings model.
     */
    public function get(): RedirectSettingsModel
    {
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('redirects', (array)(PragmaticWebToolkit::$plugin->getSettings()->redirects ?? []));
        $model = new RedirectSettingsModel();
        $model->setAttributes($stored, false);
        return $model;
    }

    /**
     * Save settings from the control panel.
     *
     * @param array<string, mixed> $values Submitted values.
     * @return bool Whether settings were saved.
     */
    public function save(array $values): bool
    {
        $model = new RedirectSettingsModel();
        $model->setAttributes($values, false);
        $model->ignorePatterns = array_values(array_filter(array_map('trim', $model->ignorePatterns), static fn(string $item): bool => $item !== ''));
        if (!$model->validate()) {
            $this->errors = $model->getFirstErrors();
            return false;
        }
        $this->errors = [];
        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('redirects', $model->toArray());
    }

    /**
     * Return the last validation errors.
     *
     * @return array<string, string> Validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

<?php

namespace pragmatic\webtoolkit\domains\analytics\models;

use CraftCms\Cms\Component\Component;

class AnalyticsSettingsModel extends Component
{
    public bool $enableTracking = true;
    public bool $requireConsent = false;
    public bool $excludeLoggedInUsers = true;
    public bool $excludeBots = true;
    public string $excludeEnvironments = 'dev,development,local,staging';

    public bool $injectGaScript = false;
    public string $gaMeasurementId = '';
    public string $gaConsentCategory = '';

    public function getRules(): array
    {
        return [
            'enableTracking' => ['boolean'],
            'requireConsent' => ['boolean'],
            'excludeLoggedInUsers' => ['boolean'],
            'excludeBots' => ['boolean'],
            'injectGaScript' => ['boolean'],
            'excludeEnvironments' => ['nullable', 'string'],
            'gaConsentCategory' => ['nullable', 'string'],
            'gaMeasurementId' => ['nullable', 'string', 'regex:/^$|^G-[A-Z0-9]+$/i'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->gaMeasurementId = trim((string)$this->gaMeasurementId);
    }
}

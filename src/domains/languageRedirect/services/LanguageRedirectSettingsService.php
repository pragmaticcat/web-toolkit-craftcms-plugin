<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\services;

use Craft;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\languageRedirect\models\LanguageRedirectSettingsModel;

class LanguageRedirectSettingsService
{
    /** @var array<string, array<int, string>> */
    private array $lastErrors = [];

    public function get(): LanguageRedirectSettingsModel
    {
        $pluginSettings = PragmaticWebToolkit::$plugin->getSettings();
        $model = new LanguageRedirectSettingsModel();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get('languageRedirect', (array)($pluginSettings->languageRedirect ?? []));
        $stored = $this->normalizeStored($stored);
        $model->setAttributes($stored, false);

        if (!$model->fallbackSiteId) {
            $model->fallbackSiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        }

        return $model;
    }

    public function saveFromArray(array $input): bool
    {
        $model = $this->get();
        $model->setAttributes($this->normalizeInput($input), false);
        $this->lastErrors = [];

        if (!$model->validate()) {
            $this->lastErrors = $model->getErrors();
            return false;
        }

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('languageRedirect', $model->toArray());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getLastErrors(): array
    {
        return $this->lastErrors;
    }

    private function normalizeStored(array $stored): array
    {
        if (isset($stored['excludePathPatterns']) && is_array($stored['excludePathPatterns'])) {
            $stored['excludePathPatterns'] = $this->normalizePatterns($stored['excludePathPatterns']);
        }

        return $stored;
    }

    private function normalizeInput(array $input): array
    {
        $input['enabled'] = !empty($input['enabled']);
        $input['cookieName'] = trim((string)($input['cookieName'] ?? 'pwt_preferred_language'));
        $input['persistQueryParam'] = trim((string)($input['persistQueryParam'] ?? 'lang'));
        $input['cookieDurationDays'] = max(1, (int)($input['cookieDurationDays'] ?? 30));
        $input['fallbackSiteId'] = $this->normalizeNullableId($input['fallbackSiteId'] ?? null);
        $input['redirectStatusCode'] = 302;
        $input['excludePathPatterns'] = $this->normalizePatterns($input['excludePathPatterns'] ?? []);

        return $input;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableId(mixed $value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    /**
     * @param mixed $patterns
     * @return array<int, string>
     */
    private function normalizePatterns(mixed $patterns): array
    {
        if (!is_array($patterns)) {
            return [];
        }

        $result = [];
        foreach ($patterns as $pattern) {
            if (is_array($pattern)) {
                $pattern = $pattern['pattern'] ?? '';
            }

            $value = trim((string)$pattern);
            if ($value === '') {
                continue;
            }

            $result[] = $value;
        }

        return array_values(array_unique($result));
    }
}

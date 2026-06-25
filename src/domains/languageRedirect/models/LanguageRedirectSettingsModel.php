<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\models;

use craft\base\Model;

class LanguageRedirectSettingsModel extends Model
{
    public bool $enabled = true;
    public string $cookieName = 'pwt_preferred_language';
    public int $cookieDurationDays = 30;
    public ?int $fallbackSiteId = null;
    public array $excludePathPatterns = ['^admin', '^actions', '^cpresources', '^api', '^feed', '^sitemap'];
    public string $persistQueryParam = 'lang';
    public int $redirectStatusCode = 302;
    public bool $debugLogging = false;

    public function rules(): array
    {
        return [
            [['enabled', 'debugLogging'], 'boolean'],
            [['cookieName', 'persistQueryParam'], 'required'],
            [['cookieName', 'persistQueryParam'], 'string', 'max' => 255],
            [['cookieDurationDays'], 'integer', 'min' => 1],
            [['fallbackSiteId'], 'integer', 'min' => 1],
            [['redirectStatusCode'], 'in', 'range' => [302]],
            [['excludePathPatterns'], 'safe'],
            [['fallbackSiteId'], 'default', 'value' => null],
        ];
    }
}

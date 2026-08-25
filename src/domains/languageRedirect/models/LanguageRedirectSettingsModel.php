<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\models;

use craft\base\Model;

class LanguageRedirectSettingsModel extends Model
{
    public bool $enabled = false;
    public string $cookieName = 'pwt_preferred_language';
    public int $cookieDurationDays = 30;
    public ?int $fallbackSiteId = null;
    public array $excludePathPatterns = ['^admin', '^actions', '^cpresources', '^api', '^feed', '^sitemap'];
    public string $persistQueryParam = 'lang';
    public int $redirectStatusCode = 302;
    public bool $debugLogging = false;
    public bool $showFloatingButton = false;
    public string $floatingButtonLabel = '';
    public string $floatingButtonPosition = 'bottom-right';
    public string $floatingButtonStyle = 'pill';
    public string $floatingButtonBackgroundColor = '#111827';
    public string $floatingButtonTextColor = '#111827';
    public string $floatingPanelBackgroundColor = '#ffffff';
    public string $floatingPanelTextColor = '#111827';
    public string $floatingAccentColor = '#2563eb';
    public string $floatingLabelDisplay = 'site-names-and-language-codes';
    public bool $floatingShowOnDesktop = true;
    public bool $floatingShowOnMobile = true;

    public function rules(): array
    {
        return [
            [['enabled', 'debugLogging', 'showFloatingButton', 'floatingShowOnDesktop', 'floatingShowOnMobile'], 'boolean'],
            [['cookieName', 'persistQueryParam'], 'required'],
            [['cookieName', 'persistQueryParam', 'floatingButtonLabel'], 'string', 'max' => 255],
            [['cookieDurationDays'], 'integer', 'min' => 1],
            [['fallbackSiteId'], 'integer', 'min' => 1],
            [['redirectStatusCode'], 'in', 'range' => [302]],
            [['excludePathPatterns'], 'safe'],
            [['floatingButtonPosition'], 'in', 'range' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']],
            [['floatingButtonStyle'], 'in', 'range' => ['icon', 'pill', 'text']],
            [['floatingLabelDisplay'], 'in', 'range' => ['site-names', 'language-codes', 'site-names-and-language-codes']],
            [['fallbackSiteId'], 'default', 'value' => null],
        ];
    }
}

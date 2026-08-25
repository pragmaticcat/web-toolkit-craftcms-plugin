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
    public string $floatingButtonLabel = 'Language';
    public string $floatingButtonPosition = 'bottom-right';
    public string $floatingButtonStyle = 'pill';
    public string $floatingButtonBackgroundColor = '#111827';
    public string $floatingButtonTextColor = '#ffffff';
    public string $floatingPanelBackgroundColor = '#ffffff';
    public string $floatingPanelTextColor = '#111827';
    public string $floatingAccentColor = '#2563eb';
    public bool $floatingShowCurrentLanguage = true;
    public bool $floatingShowLanguageCodes = true;
    public bool $floatingShowOnDesktop = true;
    public bool $floatingShowOnMobile = true;

    public function rules(): array
    {
        return [
            [['enabled', 'debugLogging', 'showFloatingButton', 'floatingShowCurrentLanguage', 'floatingShowLanguageCodes', 'floatingShowOnDesktop', 'floatingShowOnMobile'], 'boolean'],
            [['cookieName', 'persistQueryParam', 'floatingButtonLabel'], 'required'],
            [['cookieName', 'persistQueryParam', 'floatingButtonLabel'], 'string', 'max' => 255],
            [['cookieDurationDays'], 'integer', 'min' => 1],
            [['fallbackSiteId'], 'integer', 'min' => 1],
            [['redirectStatusCode'], 'in', 'range' => [302]],
            [['excludePathPatterns'], 'safe'],
            [['floatingButtonPosition'], 'in', 'range' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']],
            [['floatingButtonStyle'], 'in', 'range' => ['icon', 'pill']],
            [['fallbackSiteId'], 'default', 'value' => null],
        ];
    }
}

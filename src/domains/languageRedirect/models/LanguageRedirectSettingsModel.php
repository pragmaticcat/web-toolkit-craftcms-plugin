<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\models;

use CraftCms\Cms\Component\Component;

class LanguageRedirectSettingsModel extends Component
{
    public bool $enabled = false;
    public string $cookieName = 'pwt_preferred_language';
    public int $cookieDurationDays = 30;
    public ?int $fallbackSiteId = null;
    public array $excludePathPatterns = ['^admin', '^actions', '^cpresources', '^api', '^feed', '(^|/)sitemap', '(^|/)robots\.txt$', '(^|/)manifest\.webmanifest$', '.+'];
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
    public string $floatingSiteScope = 'all';
    public bool $floatingShowOnDesktop = true;
    public bool $floatingShowOnMobile = true;

    public function getRules(): array
    {
        return [
            'enabled' => ['boolean'],
            'debugLogging' => ['boolean'],
            'showFloatingButton' => ['boolean'],
            'floatingShowOnDesktop' => ['boolean'],
            'floatingShowOnMobile' => ['boolean'],
            'cookieName' => ['required', 'string', 'max:255'],
            'persistQueryParam' => ['required', 'string', 'max:255'],
            'floatingButtonLabel' => ['nullable', 'string', 'max:255'],
            'cookieDurationDays' => ['integer', 'min:1'],
            'fallbackSiteId' => ['nullable', 'integer', 'min:1'],
            'redirectStatusCode' => ['in:302'],
            'floatingButtonPosition' => ['in:bottom-right,bottom-left,top-right,top-left'],
            'floatingButtonStyle' => ['in:icon,pill,text'],
            'floatingLabelDisplay' => ['in:site-names,language-codes,site-names-and-language-codes'],
            'floatingSiteScope' => ['in:all,current-group'],
        ];
    }
}

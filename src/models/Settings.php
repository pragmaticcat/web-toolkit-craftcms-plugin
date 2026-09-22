<?php

namespace pragmatic\webtoolkit\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableAnalytics = false;
    public bool $enableCookies = false;
    public bool $enableFavicon = false;
    public bool $enableLanguageRedirect = false;
    public bool $enableMcp = false;
    public bool $enableSeo = false;
    public bool $enableSync = false;
    public bool $enableTranslations = false;
    public bool $enablePlus18 = false;

    public array $analytics = [];
    public array $cookies = [];
    public array $favicon = [];
    public array $languageRedirect = [];
    public array $mcp = [];
    public array $seo = [];
    public array $sync = [];
    public array $translations = [];
    public array $plus18 = [];

    /**
     * @var string[]
     */
    public array $domainOrder = [];

    public array $extensions = [];

    public function rules(): array
    {
        return [
            [['analytics', 'cookies', 'favicon', 'languageRedirect', 'mcp', 'seo', 'sync', 'translations', 'plus18', 'domainOrder', 'extensions'], 'safe'],
            [['enableAnalytics', 'enableCookies', 'enableFavicon', 'enableLanguageRedirect', 'enableMcp', 'enableSeo', 'enableSync', 'enableTranslations', 'enablePlus18'], 'boolean'],
        ];
    }
}

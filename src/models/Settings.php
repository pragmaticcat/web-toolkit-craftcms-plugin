<?php

namespace pragmatic\webtoolkit\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableAnalytics = true;
    public bool $enableCookies = true;
    public bool $enableFavicon = true;
    public bool $enableMcp = true;
    public bool $enableSeo = true;
    public bool $enableTranslations = true;
    public bool $enablePlus18 = true;

    public array $analytics = [];
    public array $cookies = [];
    public array $favicon = [];
    public array $mcp = [];
    public array $seo = [];
    public array $translations = [];
    public array $plus18 = [];

    public array $extensions = [];

    public function rules(): array
    {
        return [
            [['analytics', 'cookies', 'favicon', 'mcp', 'seo', 'translations', 'plus18', 'extensions'], 'safe'],
            [['enableAnalytics', 'enableCookies', 'enableFavicon', 'enableMcp', 'enableSeo', 'enableTranslations', 'enablePlus18'], 'boolean'],
        ];
    }
}

<?php

namespace pragmatic\webtoolkit\models;

use CraftCms\Cms\Plugin\PluginSettings;

class Settings extends PluginSettings
{
    public bool $enableAnalytics = false;
    public bool $enableCookies = false;
    public bool $enableFavicon = false;
    public bool $enableLanguageRedirect = false;
    public bool $enableSeo = false;
    public bool $enableTranslations = false;
    public bool $enablePlus18 = false;
    public bool $enableEmailTester = false;
    public bool $enableExternalCode = false;
    public bool $enableRedirects = false;

    public array $analytics = [];
    public array $cookies = [];
    public array $favicon = [];
    public array $languageRedirect = [];
    public array $seo = [];
    public array $translations = [];
    public array $plus18 = [];
    public array $emailTester = [];
    public array $externalCode = [];
    public array $redirects = [];

    /**
     * @var string[]
     */
    public array $domainOrder = ['analytics', 'cookies', 'emailTester', 'externalCode', 'favicon', 'languageRedirect', 'seo', 'translations', 'plus18', 'redirects'];

    public array $extensions = [];
}

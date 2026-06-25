<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\services;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Request;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Cookie;
use yii\web\Response;

class LanguageRedirectService
{
    public function handleCurrentRequest(): void
    {
        $request = Craft::$app->getRequest();
        if (!$this->shouldHandle($request)) {
            return;
        }

        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        if (!$settings->enabled) {
            return;
        }

        $currentSite = Craft::$app->getSites()->getCurrentSite();
        if (!$currentSite instanceof Site) {
            return;
        }

        $path = trim((string)$request->getPathInfo(), '/');
        if ($this->isExcludedPath($path, $settings->excludePathPatterns)) {
            return;
        }

        $queryParam = $settings->persistQueryParam;
        $requestedLanguage = $queryParam !== '' ? trim((string)$request->getQueryParam($queryParam, '')) : '';
        $cookieLanguage = trim((string)$request->getCookies()->getValue($settings->cookieName, ''));
        $acceptLanguage = (string)$request->getHeaders()->get('Accept-Language', '');

        $targetSite = null;
        $decisionSource = 'none';
        if ($requestedLanguage !== '') {
            $targetSite = $this->resolveSiteForLanguage($requestedLanguage);
            if ($targetSite instanceof Site) {
                $this->persistLanguagePreference($targetSite->language, $settings->cookieName, $settings->cookieDurationDays);
                $decisionSource = 'query-param';
            }
        }

        if (!$targetSite instanceof Site && $cookieLanguage !== '') {
            $targetSite = $this->resolveSiteForLanguage($cookieLanguage);
            if ($targetSite instanceof Site) {
                $decisionSource = 'cookie';
            }
        }

        if (!$targetSite instanceof Site) {
            $targetSite = $this->resolvePreferredSiteFromHeader($acceptLanguage);
            if ($targetSite instanceof Site) {
                $decisionSource = 'accept-language';
            }
        }

        if (!$targetSite instanceof Site) {
            $targetSite = $this->fallbackSite((int)$settings->fallbackSiteId);
            if ($targetSite instanceof Site) {
                $decisionSource = 'fallback';
            }
        }

        if (!$targetSite instanceof Site) {
            $this->debugLog($settings->debugLogging, [
                'stage' => 'no-target-site',
                'decisionSource' => $decisionSource,
                'requestUrl' => $request->getAbsoluteUrl(),
                'pathInfo' => $request->getPathInfo(),
                'queryParamName' => $queryParam,
                'requestedLanguage' => $requestedLanguage,
                'cookieLanguage' => $cookieLanguage,
                'acceptLanguage' => $acceptLanguage,
                'currentSite' => $currentSite->handle ?? null,
            ]);
            return;
        }

        $queryParams = $this->publicQueryParams($request->getQueryParams(), $queryParam);

        $targetUrl = null;
        if ((int)$targetSite->id !== (int)$currentSite->id) {
            $targetUrl = $this->resolveTargetUrl($request, $targetSite, $queryParams);
        } elseif ($requestedLanguage !== '') {
            $targetUrl = $this->buildCurrentSiteUrl($request, $queryParams);
        }

        $this->debugLog($settings->debugLogging, [
            'stage' => 'resolved',
            'decisionSource' => $decisionSource,
            'requestUrl' => $request->getAbsoluteUrl(),
            'pathInfo' => $request->getPathInfo(),
            'queryParamName' => $queryParam,
            'requestedLanguage' => $requestedLanguage,
            'cookieLanguage' => $cookieLanguage,
            'acceptLanguage' => $acceptLanguage,
            'currentSite' => [
                'id' => (int)$currentSite->id,
                'handle' => (string)$currentSite->handle,
                'language' => (string)$currentSite->language,
                'baseUrl' => (string)($currentSite->getBaseUrl() ?? ''),
            ],
            'targetSite' => [
                'id' => (int)$targetSite->id,
                'handle' => (string)$targetSite->handle,
                'language' => (string)$targetSite->language,
                'baseUrl' => (string)($targetSite->getBaseUrl() ?? ''),
            ],
            'publicQueryParams' => $queryParams,
            'targetUrl' => $targetUrl,
        ]);

        if (!$targetUrl || $this->sameUrl($targetUrl, $request->getAbsoluteUrl())) {
            return;
        }

        $response = Craft::$app->getResponse();
        $response->redirect($targetUrl, (int)$settings->redirectStatusCode)->send();
        Craft::$app->end();
    }

    public function persistPreferenceAndRedirect(string $language, ?string $returnUrl = null): Response
    {
        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        $site = $this->resolveSiteForLanguage($language) ?? $this->fallbackSite((int)$settings->fallbackSiteId);

        if ($site instanceof Site) {
            $this->persistLanguagePreference($site->language, $settings->cookieName, $settings->cookieDurationDays);
        }

        $sourceSite = Craft::$app->getSites()->getCurrentSite();
        $targetUrl = $this->sanitizeReturnUrl($returnUrl);
        if ($site instanceof Site) {
            $targetUrl = $this->resolveRedirectUrlForReturn(
                $targetUrl,
                $site,
                $sourceSite instanceof Site ? $sourceSite : $site,
                $settings->persistQueryParam
            );
        }

        if ($targetUrl === null || $targetUrl === '') {
            $fallbackSite = $site instanceof Site ? $site : $this->fallbackSite((int)$settings->fallbackSiteId);
            $targetUrl = $fallbackSite instanceof Site
                ? UrlHelper::siteUrl('', null, null, (int)$fallbackSite->id)
                : UrlHelper::siteUrl('');
        }

        return Craft::$app->getResponse()->redirect($targetUrl, (int)$settings->redirectStatusCode);
    }

    public function switcherUrlForSite(int|string|Site $site, ?ElementInterface $element = null): string
    {
        $targetSite = $this->resolveSite($site);
        if (!$targetSite instanceof Site) {
            return '#';
        }

        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        $targetUrl = $this->returnUrlForSite($targetSite, $element);
        $separator = str_contains($targetUrl, '?') ? '&' : '?';

        return $targetUrl . $separator . http_build_query([
            $settings->persistQueryParam => $this->normalizeLanguage((string)$targetSite->language),
        ]);
    }

    /**
     * @return array<int, array{site:Site,url:string,isCurrent:bool,language:string}>
     */
    public function switcherLinks(?ElementInterface $element = null): array
    {
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $links = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $links[] = [
                'site' => $site,
                'url' => $this->switcherUrlForSite($site, $element),
                'isCurrent' => $currentSite instanceof Site && (int)$currentSite->id === (int)$site->id,
                'language' => $this->normalizeLanguage((string)$site->language),
            ];
        }

        return $links;
    }

    private function shouldHandle(Request $request): bool
    {
        return $request->getIsSiteRequest()
            && !$request->getIsActionRequest()
            && $request->getIsGet()
            && !$request->getAcceptsJson()
            && !$request->getIsAjax()
            && !$request->getIsPreview()
            && !$request->getIsLivePreview();
    }

    /**
     * @param array<int, string> $patterns
     */
    private function isExcludedPath(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '~' . str_replace('~', '\~', $pattern) . '~i';
            $matched = @preg_match($regex, $path);
            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    private function resolvePreferredSiteFromHeader(string $header): ?Site
    {
        foreach ($this->parseAcceptLanguageHeader($header) as $language) {
            $site = $this->resolveSiteForLanguage($language);
            if ($site instanceof Site) {
                return $site;
            }
        }

        return null;
    }

    private function resolveSiteForLanguage(string $language): ?Site
    {
        $normalized = $this->normalizeLanguage($language);
        if ($normalized === '') {
            return null;
        }

        $sites = Craft::$app->getSites()->getAllSites();
        foreach ($sites as $site) {
            if ($this->normalizeLanguage((string)$site->language) === $normalized) {
                return $site;
            }
        }

        $base = $this->baseLanguage($normalized);
        foreach ($sites as $site) {
            if ($this->baseLanguage((string)$site->language) === $base) {
                return $site;
            }
        }

        return null;
    }

    private function fallbackSite(int $fallbackSiteId): ?Site
    {
        if ($fallbackSiteId > 0) {
            $fallbackSite = Craft::$app->getSites()->getSiteById($fallbackSiteId);
            if ($fallbackSite instanceof Site) {
                return $fallbackSite;
            }
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        return $primarySite instanceof Site ? $primarySite : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function resolveTargetUrl(Request $request, Site $targetSite, array $queryParams): string
    {
        $path = trim((string)$request->getPathInfo(), '/');
        if ($path === '') {
            return UrlHelper::siteUrl('', $queryParams, null, (int)$targetSite->id);
        }

        $localizedElementUrl = $this->localizedElementUrl($targetSite);
        if ($localizedElementUrl !== null) {
            return $this->appendQueryParams($localizedElementUrl, $queryParams);
        }

        return UrlHelper::siteUrl('', $queryParams, null, (int)$targetSite->id);
    }

    private function localizedElementUrl(Site $targetSite): ?string
    {
        $urlManager = Craft::$app->getUrlManager();
        if (!method_exists($urlManager, 'getMatchedElement')) {
            return null;
        }

        $matchedElement = $urlManager->getMatchedElement();
        if (!$matchedElement instanceof ElementInterface) {
            return null;
        }

        $canonicalId = (int)($matchedElement->canonicalId ?? $matchedElement->id ?? 0);
        if ($canonicalId <= 0) {
            return null;
        }

        $localized = Craft::$app->getElements()->getElementById($canonicalId, $matchedElement::class, (int)$targetSite->id);
        if (!$localized instanceof ElementInterface) {
            return null;
        }

        $url = (string)($localized->getUrl() ?? '');
        return $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function buildCurrentSiteUrl(Request $request, array $queryParams): string
    {
        $path = trim((string)$request->getPathInfo(), '/');
        return UrlHelper::siteUrl($path, $queryParams);
    }

    private function persistLanguagePreference(string $language, string $cookieName, int $days): void
    {
        Craft::$app->getResponse()->getCookies()->add(new Cookie([
            'name' => $cookieName,
            'value' => $this->normalizeLanguage($language),
            'expire' => time() + (86400 * max(1, $days)),
            'httpOnly' => false,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function parseAcceptLanguageHeader(string $header): array
    {
        $languages = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            [$code, $params] = array_pad(explode(';', $part, 2), 2, '');
            $q = 1.0;
            if ($params !== '' && preg_match('/q=([0-9.]+)/i', $params, $matches)) {
                $q = (float)$matches[1];
            }

            $normalized = $this->normalizeLanguage($code);
            if ($normalized === '') {
                continue;
            }

            $languages[] = ['code' => $normalized, 'q' => $q];
        }

        usort(
            $languages,
            static fn(array $a, array $b): int => $b['q'] <=> $a['q']
        );

        return array_values(array_unique(array_map(static fn(array $item): string => $item['code'], $languages)));
    }

    private function normalizeLanguage(string $language): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($language)));
        return preg_replace('/[^a-z0-9-]/', '', $normalized) ?? '';
    }

    private function baseLanguage(string $language): string
    {
        return explode('-', $this->normalizeLanguage($language))[0] ?? '';
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function appendQueryParams(string $url, array $queryParams): string
    {
        if ($queryParams === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($queryParams);
    }

    private function sanitizeReturnUrl(?string $returnUrl): ?string
    {
        $returnUrl = trim((string)$returnUrl);
        if ($returnUrl === '') {
            return null;
        }

        if (UrlHelper::isAbsoluteUrl($returnUrl)) {
            return $returnUrl;
        }

        return UrlHelper::siteUrl(ltrim($returnUrl, '/'));
    }

    private function resolveRedirectUrlForReturn(?string $returnUrl, Site $site, Site $sourceSite, string $queryParam): ?string
    {
        if ($returnUrl === null || $returnUrl === '') {
            return null;
        }

        $parsed = parse_url($returnUrl);
        if ($parsed === false) {
            return null;
        }

        $path = $this->normalizeReturnPathForSite((string)($parsed['path'] ?? ''), $sourceSite);
        parse_str((string)($parsed['query'] ?? ''), $queryParams);
        $queryParams = $this->publicQueryParams($queryParams, $queryParam);

        if ($path === '') {
            return UrlHelper::siteUrl('', $queryParams, null, (int)$site->id);
        }

        return UrlHelper::siteUrl($path, $queryParams, null, (int)$site->id);
    }

    private function normalizeReturnPathForSite(string $path, Site $sourceSite): string
    {
        $trimmedPath = ltrim($path, '/');
        $basePath = $this->siteBasePath($sourceSite);
        if ($basePath !== '' && str_starts_with($trimmedPath, $basePath . '/')) {
            $trimmedPath = substr($trimmedPath, strlen($basePath) + 1);
        } elseif ($trimmedPath === $basePath) {
            $trimmedPath = '';
        }

        return trim($trimmedPath, '/');
    }

    private function siteBasePath(Site $site): string
    {
        $baseUrl = (string)($site->getBaseUrl() ?? '');
        if ($baseUrl === '') {
            return '';
        }

        $path = trim((string)(parse_url($baseUrl, PHP_URL_PATH) ?? ''), '/');
        return $path;
    }

    private function resolveSite(int|string|Site $site): ?Site
    {
        if ($site instanceof Site) {
            return $site;
        }

        if (is_int($site) || ctype_digit((string)$site)) {
            $resolved = Craft::$app->getSites()->getSiteById((int)$site);
            return $resolved instanceof Site ? $resolved : null;
        }

        $resolved = Craft::$app->getSites()->getSiteByHandle((string)$site);
        return $resolved instanceof Site ? $resolved : null;
    }

    private function returnUrlForSite(Site $targetSite, ?ElementInterface $element = null): string
    {
        $request = Craft::$app->getRequest();
        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        $queryParams = $this->publicQueryParams($request->getQueryParams(), $settings->persistQueryParam);

        $referenceElement = $element ?? $this->matchedElement();
        if ($referenceElement instanceof ElementInterface) {
            $localized = $this->localizedElementForSite($referenceElement, $targetSite);
            $localizedUrl = $localized instanceof ElementInterface ? (string)($localized->getUrl() ?? '') : '';
            if ($localizedUrl !== '') {
                return $this->appendQueryParams($localizedUrl, $queryParams);
            }
        }

        $path = trim((string)$request->getPathInfo(), '/');
        return $this->sitePathUrl($targetSite, $path, $queryParams);
    }

    private function matchedElement(): ?ElementInterface
    {
        $urlManager = Craft::$app->getUrlManager();
        if (!method_exists($urlManager, 'getMatchedElement')) {
            return null;
        }

        $matchedElement = $urlManager->getMatchedElement();
        return $matchedElement instanceof ElementInterface ? $matchedElement : null;
    }

    private function localizedElementForSite(ElementInterface $element, Site $targetSite): ?ElementInterface
    {
        $canonicalId = (int)($element->canonicalId ?? $element->id ?? 0);
        if ($canonicalId <= 0) {
            return null;
        }

        $localized = Craft::$app->getElements()->getElementById($canonicalId, $element::class, (int)$targetSite->id);
        return $localized instanceof ElementInterface ? $localized : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function publicQueryParams(array $queryParams, string $languageParam): array
    {
        unset($queryParams[$languageParam], $queryParams['returnUrl']);

        $pathParam = trim((string)(Craft::$app->getConfig()->getGeneral()->pathParam ?? 'p'));
        if ($pathParam !== '') {
            unset($queryParams[$pathParam]);
        }

        return $queryParams;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function sitePathUrl(Site $site, string $path, array $queryParams = []): string
    {
        $baseUrl = trim((string)($site->getBaseUrl() ?? ''));
        if ($baseUrl === '') {
            return UrlHelper::siteUrl($path, $queryParams, null, (int)$site->id);
        }

        $url = rtrim($baseUrl, '/');
        $normalizedPath = trim($path, '/');
        if ($normalizedPath !== '') {
            $url .= '/' . $normalizedPath;
        }

        return $this->appendQueryParams($url, $queryParams);
    }

    private function sameUrl(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function debugLog(bool $enabled, array $payload): void
    {
        if (!$enabled) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Craft::info('Language redirect debug: ' . ($json ?: '[unserializable payload]'), __METHOD__);
    }
}

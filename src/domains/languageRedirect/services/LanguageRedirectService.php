<?php

namespace pragmatic\webtoolkit\domains\languageRedirect\services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Site\Data\Site;
use Illuminate\Http\Request;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use CraftCms\Cms\Route\MatchedElement;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\View\TemplateMode;

use function CraftCms\Cms\template;

class LanguageRedirectService
{
    public function renderFloatingSwitcher(?ElementInterface $element = null, mixed $siteFilter = null): string
    {
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('languageRedirect')) {
            return '';
        }

        $request = request();
        if (!$request->isSiteRequest()) {
            return '';
        }

        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        if (!$settings->showFloatingButton) {
            return '';
        }

        $path = trim((string)$request->craftPath(), '/');
        if ($this->isExcludedPath($path, $settings->excludePathPatterns)) {
            return '';
        }

        if ($siteFilter === null && $settings->floatingSiteScope === 'current-group') {
            $currentSite = Sites::getCurrentSite();
            if ($currentSite instanceof Site) {
                $siteFilter = ['siteGroup' => (int)$currentSite->groupId];
            }
        }

        $links = $this->switcherLinks($element, $siteFilter);
        if (count($links) < 2) {
            return '';
        }

        $currentLink = null;
        foreach ($links as $link) {
            if (!empty($link['isCurrent'])) {
                $currentLink = $link;
                break;
            }
        }

        return template('pragmatic-web-toolkit/language-redirect/frontend/_floating-switcher', [
            'settings' => $settings,
            'links' => $links,
            'currentLink' => $currentLink,
        ], TemplateMode::Cp);
    }

    public function injectFloatingSwitcherIntoHtml(string $html): string
    {
        $switcher = $this->renderFloatingSwitcher();
        if ($switcher === '') {
            return $html;
        }

        $bodyClosePos = strripos($html, '</body>');
        if ($bodyClosePos === false) {
            return $html . $switcher;
        }

        return substr($html, 0, $bodyClosePos) . $switcher . substr($html, $bodyClosePos);
    }

    /**
     * Retorna la redirecció que toca per a la petició actual, si n’hi ha.
     */
    public function handleCurrentRequest(): ?Response
    {
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('languageRedirect')) {
            return null;
        }

        $request = request();
        if (!$this->shouldHandle($request)) {
            return null;
        }

        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        if (!$settings->enabled) {
            return null;
        }

        $currentSite = Sites::getCurrentSite();
        if (!$currentSite instanceof Site) {
            return null;
        }

        $path = trim((string)$request->craftPath(), '/');
        if ($this->isExcludedPath($path, $settings->excludePathPatterns)) {
            return null;
        }

        $queryParam = $settings->persistQueryParam;
        $requestedLanguage = $queryParam !== '' ? trim((string)$request->query($queryParam, '')) : '';
        $cookieLanguage = trim((string)$request->cookie($settings->cookieName, ''));
        $acceptLanguage = (string)$request->header('Accept-Language', '');

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
                'requestUrl' => $request->fullUrl(),
                'pathInfo' => $request->craftPath(),
                'queryParamName' => $queryParam,
                'requestedLanguage' => $requestedLanguage,
                'cookieLanguage' => $cookieLanguage,
                'acceptLanguage' => $acceptLanguage,
                'currentSite' => $currentSite->handle ?? null,
            ]);
            return null;
        }

        $queryParams = $this->publicQueryParams($request->query(), $queryParam);

        $targetUrl = null;
        if ((int)$targetSite->id !== (int)$currentSite->id) {
            $targetUrl = $this->resolveTargetUrl($request, $targetSite, $queryParams);
        } elseif ($requestedLanguage !== '') {
            $targetUrl = $this->buildCurrentSiteUrl($request, $queryParams);
        }

        $this->debugLog($settings->debugLogging, [
            'stage' => 'resolved',
            'decisionSource' => $decisionSource,
            'requestUrl' => $request->fullUrl(),
            'pathInfo' => $request->craftPath(),
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

        if (!$targetUrl || $this->sameUrl($targetUrl, $request->fullUrl())) {
            return null;
        }

        return redirect()->to($targetUrl, (int)$settings->redirectStatusCode);
    }

    public function persistPreferenceAndRedirect(string $language, ?string $returnUrl = null): Response
    {
        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        $site = $this->resolveSiteForLanguage($language) ?? $this->fallbackSite((int)$settings->fallbackSiteId);

        if ($site instanceof Site) {
            $this->persistLanguagePreference($site->language, $settings->cookieName, $settings->cookieDurationDays);
        }

        $sourceSite = Sites::getCurrentSite();
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
                ? Url::siteUrl('', null, null, (int)$fallbackSite->id)
                : Url::siteUrl('');
        }

        return redirect()->to($targetUrl, (int)$settings->redirectStatusCode);
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
    public function switcherLinks(?ElementInterface $element = null, mixed $siteFilter = null): array
    {
        $currentSite = Sites::getCurrentSite();
        $links = [];

        foreach ($this->sitesForSwitcher($siteFilter) as $site) {
            $links[] = [
                'site' => $site,
                'url' => $this->switcherUrlForSite($site, $element),
                'isCurrent' => $currentSite instanceof Site && (int)$currentSite->id === (int)$site->id,
                'language' => $this->normalizeLanguage((string)$site->language),
            ];
        }

        return $links;
    }

    /**
     * @return array<int, Site>
     */
    private function sitesForSwitcher(mixed $siteFilter = null): array
    {
        $allSites = Sites::getAllSites()->all();

        if ($siteFilter === null || $siteFilter === '' || $siteFilter === []) {
            return $allSites;
        }

        if (is_array($siteFilter) && $this->isAssoc($siteFilter)) {
            $groupFilter = $siteFilter['siteGroup'] ?? $siteFilter['group'] ?? null;
            $sitesFilter = $siteFilter['sites'] ?? $siteFilter['siteIds'] ?? $siteFilter['siteHandles'] ?? null;

            if ($sitesFilter !== null) {
                $resolvedSites = $this->resolveSitesList(is_array($sitesFilter) ? $sitesFilter : [$sitesFilter]);
                if ($groupFilter === null || $groupFilter === '') {
                    return $resolvedSites;
                }

                return array_values(array_filter(
                    $resolvedSites,
                    fn(Site $site): bool => $this->siteMatchesGroup($site, $groupFilter)
                ));
            }

            if ($groupFilter !== null && $groupFilter !== '') {
                return array_values(array_filter(
                    $allSites,
                    fn(Site $site): bool => $this->siteMatchesGroup($site, $groupFilter)
                ));
            }
        }

        if (is_array($siteFilter)) {
            return $this->resolveSitesList($siteFilter);
        }

        return array_values(array_filter(
            $allSites,
            fn(Site $site): bool => $this->siteMatchesGroup($site, $siteFilter)
        ));
    }

    private function shouldHandle(Request $request): bool
    {
        return $request->isSiteRequest()
            && !$request->isActionRequest()
            && $request->isMethod('GET')
            && !$request->expectsJson()
            && !$request->ajax()
            && !$request->isPreview();
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

        $sites = Sites::getAllSites()->all();
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
            $fallbackSite = Sites::getSiteById($fallbackSiteId);
            if ($fallbackSite instanceof Site) {
                return $fallbackSite;
            }
        }

        $primarySite = Sites::getPrimarySite();
        return $primarySite instanceof Site ? $primarySite : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function resolveTargetUrl(Request $request, Site $targetSite, array $queryParams): string
    {
        $path = trim((string)$request->craftPath(), '/');
        if ($path === '') {
            return Url::siteUrl('', $queryParams, null, (int)$targetSite->id);
        }

        $localizedElementUrl = $this->localizedElementUrl($targetSite);
        if ($localizedElementUrl !== null) {
            return $this->appendQueryParams($localizedElementUrl, $queryParams);
        }

        return Url::siteUrl('', $queryParams, null, (int)$targetSite->id);
    }

    private function localizedElementUrl(Site $targetSite): ?string
    {
        $matchedElement = MatchedElement::get();
        if (!$matchedElement instanceof ElementInterface) {
            return null;
        }

        $canonicalId = (int)($matchedElement->canonicalId ?? $matchedElement->id ?? 0);
        if ($canonicalId <= 0) {
            return null;
        }

        $localized = Elements::getElementById($canonicalId, $matchedElement::class, (int)$targetSite->id);
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
        $path = trim((string)$request->craftPath(), '/');
        return Url::siteUrl($path, $queryParams);
    }

    private function persistLanguagePreference(string $language, string $cookieName, int $days): void
    {
        Cookie::queue(Cookie::make(
            name: $cookieName,
            value: $this->normalizeLanguage($language),
            minutes: 1440 * max(1, $days),
            httpOnly: false,
            sameSite: 'lax',
        ));
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

        if (Url::isAbsoluteUrl($returnUrl)) {
            return $returnUrl;
        }

        return Url::siteUrl(ltrim($returnUrl, '/'));
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
            return Url::siteUrl('', $queryParams, null, (int)$site->id);
        }

        return Url::siteUrl($path, $queryParams, null, (int)$site->id);
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
            $resolved = Sites::getSiteById((int)$site);
            return $resolved instanceof Site ? $resolved : null;
        }

        $resolved = Sites::getSiteByHandle((string)$site);
        return $resolved instanceof Site ? $resolved : null;
    }

    /**
     * @param array<int|string, mixed> $sites
     * @return array<int, Site>
     */
    private function resolveSitesList(array $sites): array
    {
        $resolvedSites = [];
        $seen = [];

        foreach ($sites as $siteRef) {
            $site = $this->resolveSite($siteRef);
            if (!$site instanceof Site) {
                continue;
            }

            if (isset($seen[$site->id])) {
                continue;
            }

            $seen[$site->id] = true;
            $resolvedSites[] = $site;
        }

        return $resolvedSites;
    }

    private function siteMatchesGroup(Site $site, mixed $groupFilter): bool
    {
        $groupId = (int)($site->groupId ?? 0);
        if ($groupId > 0 && (is_int($groupFilter) || ctype_digit((string)$groupFilter))) {
            return $groupId === (int)$groupFilter;
        }

        $sitesService = app(\CraftCms\Cms\Site\Sites::class);
        if (!method_exists($sitesService, 'getAllGroups')) {
            return false;
        }

        $normalizedFilter = trim(strtolower((string)$groupFilter));
        if ($normalizedFilter === '') {
            return false;
        }

        foreach ((array)$sitesService->getAllGroups() as $group) {
            $candidateId = (int)($group->id ?? 0);
            if ($candidateId !== $groupId) {
                continue;
            }

            $groupUid = trim(strtolower((string)($group->uid ?? '')));
            $groupName = trim(strtolower((string)($group->name ?? '')));
            $groupHandle = trim(strtolower((string)($group->handle ?? '')));

            return $normalizedFilter === (string)$candidateId
                || ($groupUid !== '' && $normalizedFilter === $groupUid)
                || ($groupName !== '' && $normalizedFilter === $groupName)
                || ($groupHandle !== '' && $normalizedFilter === $groupHandle);
        }

        return false;
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function returnUrlForSite(Site $targetSite, ?ElementInterface $element = null): string
    {
        $request = request();
        $settings = PragmaticWebToolkit::$plugin->languageRedirectSettings->get();
        $queryParams = $this->publicQueryParams($request->query(), $settings->persistQueryParam);

        $referenceElement = $element ?? $this->matchedElement();
        if ($referenceElement instanceof ElementInterface) {
            $localized = $this->localizedElementForSite($referenceElement, $targetSite);
            $localizedUrl = $localized instanceof ElementInterface ? (string)($localized->getUrl() ?? '') : '';
            if ($localizedUrl !== '') {
                return $this->appendQueryParams($localizedUrl, $queryParams);
            }
        }

        $path = trim((string)$request->craftPath(), '/');
        return $this->sitePathUrl($targetSite, $path, $queryParams);
    }

    private function matchedElement(): ?ElementInterface
    {
        $matchedElement = MatchedElement::get();
        return $matchedElement instanceof ElementInterface ? $matchedElement : null;
    }

    private function localizedElementForSite(ElementInterface $element, Site $targetSite): ?ElementInterface
    {
        $canonicalId = (int)($element->canonicalId ?? $element->id ?? 0);
        if ($canonicalId <= 0) {
            return null;
        }

        $localized = Elements::getElementById($canonicalId, $element::class, (int)$targetSite->id);
        return $localized instanceof ElementInterface ? $localized : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function publicQueryParams(array $queryParams, string $languageParam): array
    {
        unset($queryParams[$languageParam], $queryParams['returnUrl']);

        $pathParam = trim((string)(\CraftCms\Cms\Cms::config()->pathParam ?? 'p'));
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
            return Url::siteUrl($path, $queryParams, null, (int)$site->id);
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
        Log::info('Language redirect debug: ' . ($json ?: '[unserializable payload]'));
    }
}

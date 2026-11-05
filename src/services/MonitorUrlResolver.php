<?php

namespace pragmatic\webtoolkit\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\web\Request as YiiRequest;
use yii\web\UrlRuleInterface;

/**
 * Tells PWM whether URLs of this install work, from inside Craft and without HTTP requests,
 * so PWM does not have to request every link and trip the hosting's rate limiter.
 */
class MonitorUrlResolver
{
    public const MAX_URLS = 200;

    public const OK = 'ok';
    public const REDIRECT = 'redirect';
    public const DISABLED = 'disabled';
    public const NOT_FOUND = 'not_found';
    public const UNKNOWN = 'unknown';
    public const EXTERNAL = 'external';

    /** @var array<int,array{id:int,handle:string,base_url:string}>|null */
    private ?array $sites = null;

    /**
     * @param array<int,mixed> $urls
     * @return array<int,array<string,mixed>>
     */
    public function resolveAll(array $urls): array
    {
        $results = [];
        foreach (array_slice(array_values(array_filter($urls, 'is_string')), 0, self::MAX_URLS) as $url) {
            try {
                $results[] = ['url' => $url, ...$this->resolve($url)];
            } catch (\Throwable $exception) {
                Craft::warning("PWM could not resolve {$url}: {$exception->getMessage()}", __METHOD__);
                $results[] = ['url' => $url, 'status' => self::UNKNOWN];
            }
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $url, bool $followRedirect = true): array
    {
        $match = self::matchSite($url, $this->sites());
        if ($match === null) {
            return ['status' => self::EXTERNAL];
        }

        $site = $match['site'];
        $uri = $match['uri'];
        $element = Craft::$app->getElements()->getElementByUri($uri === '' ? Element::HOMEPAGE_URI : $uri, $site['id']);
        if ($element !== null && self::isLive($element)) {
            return ['status' => self::OK, 'kind' => 'element', 'type' => $element::lowerDisplayName(), 'id' => (int)$element->id, 'site' => $site['handle']];
        }

        // Craft only reaches templates, routes and redirects when no live element has the URI.
        if ($element === null && $this->isPublicFile($match['path'])) {
            return ['status' => self::OK, 'kind' => 'file', 'site' => $site['handle']];
        }
        if ($element === null && ($route = $this->matchRoute($uri, $url)) !== null) {
            return $route === 'templates/render'
                ? ['status' => self::OK, 'kind' => 'route', 'site' => $site['handle']]
                : ['status' => self::UNKNOWN, 'kind' => 'route', 'route' => $route, 'site' => $site['handle']];
        }
        if ($element === null && $uri !== '' && $this->isPublicTemplate($uri)) {
            return ['status' => self::OK, 'kind' => 'template', 'site' => $site['handle']];
        }

        if (($redirect = $this->redirect($match['path'], $site['id'])) !== null) {
            if ($redirect['statusCode'] === 410) {
                return ['status' => self::NOT_FOUND, 'kind' => 'gone', 'site' => $site['handle']];
            }

            $to = self::absoluteUrl($redirect['destination'], $site['base_url']);
            $result = ['status' => self::REDIRECT, 'to' => $to, 'status_code' => $redirect['statusCode'], 'site' => $site['handle']];
            if ($followRedirect) {
                $result['to_status'] = $this->resolve($to, false)['status'];
            }

            return $result;
        }

        return $element !== null
            ? ['status' => self::DISABLED, 'kind' => 'element', 'type' => $element::lowerDisplayName(), 'id' => (int)$element->id, 'site' => $site['handle']]
            : ['status' => self::NOT_FOUND, 'site' => $site['handle']];
    }

    /**
     * The site whose base URL contains the URL (the longest base path wins), and the
     * URI relative to it. Host and scheme are compared case-insensitively, `http`
     * and `https` count as the same, and the path is percent-decoded.
     *
     * @param array<int,array{id:int,handle:string,base_url:string}> $sites
     * @return array{site:array{id:int,handle:string,base_url:string},uri:string,path:string}|null
     */
    public static function matchSite(string $url, array $sites): ?array
    {
        // parse_url garbles raw non-ASCII bytes (e.g. "í"), so encode them first; the path is decoded below.
        $encoded = (string)preg_replace_callback('/[^\x00-\x7F]+/', fn (array $m): string => rawurlencode($m[0]), trim($url));
        $parts = parse_url($encoded);
        if (!is_array($parts) || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '/'.ltrim(rawurldecode($parts['path'] ?? '/'), '/');
        $best = null;

        foreach ($sites as $site) {
            $base = parse_url($site['base_url']);
            if (!is_array($base) || !isset($base['host'])) {
                continue;
            }
            $baseHost = strtolower($base['host']).(isset($base['port']) ? ':'.$base['port'] : '');
            $basePath = '/'.trim(rawurldecode($base['path'] ?? ''), '/');
            $prefix = rtrim($basePath, '/').'/';

            if ($baseHost !== $host || !($path === $basePath || str_starts_with($path, $prefix))) {
                continue;
            }
            if ($best === null || strlen($basePath) > strlen($best['base_path'])) {
                $best = ['site' => $site, 'base_path' => $basePath];
            }
        }

        if ($best === null) {
            return null;
        }

        $uri = trim(substr($path, strlen(rtrim($best['base_path'], '/'))), '/');

        return ['site' => $best['site'], 'uri' => $uri, 'path' => $path];
    }

    public static function absoluteUrl(string $destination, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $destination)) {
            return $destination;
        }

        $base = parse_url($baseUrl);
        $origin = ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');

        return $origin.'/'.ltrim($destination, '/');
    }

    private static function isLive(ElementInterface $element): bool
    {
        return in_array($element->getStatus(), [Element::STATUS_ENABLED, Entry::STATUS_LIVE], true);
    }

    /**
     * @return array<int,array{id:int,handle:string,base_url:string}>
     */
    private function sites(): array
    {
        if ($this->sites === null) {
            $this->sites = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if ($site->hasUrls && $site->getBaseUrl()) {
                    $this->sites[] = ['id' => (int)$site->id, 'handle' => (string)$site->handle, 'base_url' => (string)$site->getBaseUrl()];
                }
            }
        }

        return $this->sites;
    }

    /**
     * A file the web server serves directly, such as an asset in a local volume.
     */
    private function isPublicFile(string $path): bool
    {
        $webroot = realpath((string)Craft::getAlias('@webroot'));
        if ($webroot === false || $path === '/') {
            return false;
        }

        $file = realpath($webroot.$path);

        return $file !== false && is_file($file) && str_starts_with($file, $webroot.DIRECTORY_SEPARATOR);
    }

    /**
     * The route of the first site URL rule (config/routes.php, CP routes, plugins) that matches the URI.
     */
    private function matchRoute(string $uri, string $url): ?string
    {
        $manager = Craft::$app->getUrlManager();
        $probe = new class extends YiiRequest {
            public string $probePath = '';
            public string $probeHost = '';

            public function getPathInfo(): string
            {
                return $this->probePath;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getHostInfo(): ?string
            {
                return $this->probeHost;
            }
        };
        $probe->probePath = $uri;
        $probe->probeHost = (string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $url);

        foreach ($manager->rules as $rule) {
            if (!$rule instanceof UrlRuleInterface) {
                continue;
            }
            $result = $rule->parseRequest($manager, $probe);
            if (is_array($result) && str_starts_with((string)$result[0], 'pragmatic-web-toolkit/monitor/') === false) {
                return (string)$result[0];
            }
        }

        return null;
    }

    private function isPublicTemplate(string $uri): bool
    {
        $segments = explode('/', $uri);
        foreach ($segments as $segment) {
            // Craft never serves templates whose path has a segment starting with an underscore or dot.
            if ($segment === '' || str_starts_with($segment, '_') || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return Craft::$app->getView()->doesTemplateExist($uri, View::TEMPLATE_MODE_SITE, true);
    }

    /**
     * @return array{destination:string,statusCode:int}|null
     */
    private function redirect(string $path, int $siteId): ?array
    {
        $plugin = PragmaticWebToolkit::$plugin;
        if (!$plugin->domains->isEnabled('redirects')) {
            return null;
        }

        $result = $plugin->redirects->test($path, $siteId);
        if ($result === null) {
            return null;
        }

        return [
            'destination' => (string)($result['finalDestination'] ?? $result['destination']),
            'statusCode' => (int)$result['statusCode'],
        ];
    }
}

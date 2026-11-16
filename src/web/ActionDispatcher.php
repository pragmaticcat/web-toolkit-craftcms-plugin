<?php

namespace pragmatic\webtoolkit\web;

use CraftCms\Cms\Support\Str;
use Illuminate\Http\Request;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUser;

/**
 * Executa les accions dels controladors del plugin amb l’esquema de rutes de Craft 5
 * (`pragmatic-web-toolkit/{controlador}/{acció}`).
 *
 * Els paràmetres de l’acció s’omplen amb els paràmetres de la ruta i, si no hi són, amb els
 * de la query string, com feia Yii.
 */
class ActionDispatcher
{
    /**
     * Rutes de Craft 5 (`pragmatic-web-toolkit/seo/index`) indexades pel domini que les exposa.
     *
     * @var array<string, string>
     */
    private const CONTROLLER_DOMAINS = [
        'analytics' => 'analytics',
        'cookies' => 'cookies',
        'email-tester' => 'emailTester',
        'external-code' => 'externalCode',
        'favicon' => 'favicon',
        'language-redirect' => 'languageRedirect',
        'plus18' => 'plus18',
        'redirects' => 'redirects',
        'seo' => 'seo',
        'translations' => 'translations',
    ];

    public function __invoke(Request $request, string $controller, string $action): mixed
    {
        return $this->run($controller, $action, $request->route()?->parameters() ?? []);
    }

    /**
     * Executa una ruta de Craft 5, com `pragmatic-web-toolkit/seo/index`.
     *
     * @param array<string, mixed> $params
     */
    public function runRoute(string $route, array $params = []): mixed
    {
        $parts = explode('/', trim($route, '/'));
        if (count($parts) !== 3 || $parts[0] !== 'pragmatic-web-toolkit') {
            abort(404);
        }

        return $this->run($parts[1], $parts[2], $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function run(string $controllerId, string $actionId, array $params = []): mixed
    {
        $class = 'pragmatic\\webtoolkit\\controllers\\' . Str::studly($controllerId) . 'Controller';
        $method = 'action' . Str::studly($actionId);

        if (!preg_match('/^[a-z0-9-]+$/', $controllerId) || !preg_match('/^[a-z0-9-]+$/', $actionId) || !class_exists($class) || !method_exists($class, $method)) {
            abort(404);
        }

        $domain = self::CONTROLLER_DOMAINS[$controllerId] ?? null;
        if ($domain !== null && !PragmaticWebToolkit::$plugin->domains->isEnabled($domain)) {
            abort(404);
        }

        /** @var Controller $controller */
        $controller = app($class);
        $controller->actionId = $actionId;

        if (!$controller->allowsAnonymous($actionId) && currentUser() === null) {
            if (request()->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(\CraftCms\Cms\Support\Url::cpUrl('login'));
        }

        $response = $controller->beforeAction($actionId);
        if ($response instanceof Response) {
            return $response;
        }

        return $controller->$method(...$this->bindParams(new ReflectionMethod($controller, $method), $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function bindParams(ReflectionMethod $method, array $params): array
    {
        $args = [];
        $request = request();

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $value = $params[$name] ?? $request->query($name);

            if ($value === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $args[$name] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $args[$name] = null;
                    continue;
                }
                abort(400, sprintf('Missing required parameter: %s', $name));
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin() && !is_array($value)) {
                $value = match ($type->getName()) {
                    'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : abort(400, sprintf('Invalid data received for parameter "%s".', $name)),
                    'float' => (float)$value,
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    'string' => (string)$value,
                    default => $value,
                };
            }

            $args[$name] = $value;
        }

        return $args;
    }
}

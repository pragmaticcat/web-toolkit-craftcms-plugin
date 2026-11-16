<?php

namespace pragmatic\webtoolkit\web;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Flash;
use CraftCms\Cms\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\pageTemplate;

/**
 * Controlador base dels controladors del plugin.
 *
 * Craft 6 no té un controlador base: aquesta classe recull els ajudants que els controladors
 * del plugin feien servir a Craft 5 (`requirePostRequest()`, `renderTemplate()`, `asJson()`…),
 * implementats sobre Laravel. Les accions continuen sent mètodes `actionNomAccio()`, i
 * {@see ActionDispatcher} les resol a partir de les rutes del plugin.
 */
abstract class Controller
{
    use RespondsWithFlash;

    /**
     * Accions (en kebab-case) que no requereixen un usuari identificat, o `true` per a totes.
     *
     * @var string[]|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    public Request $request;

    /** Identificador de l’acció en curs, en kebab-case. */
    public string $actionId = '';

    /** @var array<string, string> */
    private array $responseHeaders = [];

    public function __construct()
    {
        $this->request = request();
    }

    public function allowsAnonymous(string $actionId): bool
    {
        if (is_bool($this->allowAnonymous)) {
            return $this->allowAnonymous;
        }

        return is_array($this->allowAnonymous) && in_array($actionId, $this->allowAnonymous, true);
    }

    /** Es crida abans de cada acció. */
    public function beforeAction(string $actionId): bool
    {
        return true;
    }

    protected function setResponseHeader(string $name, string $value): void
    {
        $this->responseHeaders[$name] = $value;
    }

    public function prepareResponse(mixed $result): mixed
    {
        if ($result instanceof Response) {
            foreach ($this->responseHeaders as $name => $value) {
                $result->headers->set($name, $value);
            }
        }

        return $result;
    }

    protected function requirePostRequest(): void
    {
        abort_unless($this->request->isMethod('POST'), 400, 'Post request required');
    }

    protected function requireAcceptsJson(): void
    {
        abort_unless($this->request->expectsJson() || $this->request->ajax(), 400, 'Request must accept JSON in response');
    }

    protected function requireCpRequest(): void
    {
        abort_unless($this->request->isCpRequest(), 400, 'Request must be a control panel request');
    }

    protected function requireLogin(): void
    {
        abort_unless(currentUser() !== null, 403);
    }

    protected function requireAdmin(): void
    {
        abort_unless((bool)(currentUser()?->admin ?? false), 403, 'User is not an admin');
    }

    protected function requirePermission(string $permission): void
    {
        abort_unless(Gate::check($permission), 403, 'User is not authorized to perform this action.');
    }

    /**
     * @param array<string, mixed> $variables
     */
    protected function renderTemplate(string $template, array $variables = []): Response
    {
        return response(pageTemplate($template, $variables));
    }

    protected function asJson(mixed $data): JsonResponse
    {
        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function asRaw(string $content, string $contentType = 'text/html; charset=UTF-8'): Response
    {
        return response($content, 200, ['Content-Type' => $contentType]);
    }

    protected function redirect(string $url, int $statusCode = 302): RedirectResponse
    {
        if (!preg_match('~^(https?:)?//~i', $url) && !str_starts_with($url, '/')) {
            $url = $this->request->isCpRequest() ? Url::cpUrl($url) : Url::url($url);
        }

        return redirect()->to($url, $statusCode);
    }

    public function redirectToPostedUrl(?object $object = null, ?string $redirect = null): RedirectResponse
    {
        $url = $this->getPostedRedirectUrl($object) ?? $redirect;

        if ($url === null) {
            return redirect()->to($this->request->fullUrl());
        }

        return $this->redirect($url);
    }

    protected function setSuccessFlash(string $message): void
    {
        Flash::success($message);
    }

    protected function setFailFlash(string $message): void
    {
        Flash::error($message);
    }

    protected function setNoticeFlash(string $message): void
    {
        Flash::notice($message);
    }
}

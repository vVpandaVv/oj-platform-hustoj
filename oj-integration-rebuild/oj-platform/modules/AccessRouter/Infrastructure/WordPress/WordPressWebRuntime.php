<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionDispatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteDispatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionTransport;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;

final class WordPressWebRuntime
{
    /**
     * @param DefinitionRegistry<ActionDefinition> $actions
     */
    public function __construct(
        private readonly HookRegistrar $hooks,
        private readonly RouteDispatcher $routes,
        private readonly ActionDispatcher $actionDispatcher,
        private readonly ActionHandlerRegistry $actionHandlers,
        private readonly DefinitionRegistry $actions,
        private readonly ResponseEmitter $emitter,
        private readonly WordPressRouteTransport $transport,
        private readonly WordPressRewriteManager $rewrites,
        private readonly ?\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\NotFoundResponder $notFound = null,
        private readonly ?WordPressLoginGuard $loginGuard = null
    ) {
    }

    /**
     * @param list<string>|null $allowedActionKeys
     * @param list<string>|null $allowedRoutePaths
     */
    public function registerHooks(
        ?array $allowedActionKeys = null,
        ?array $allowedRoutePaths = null,
        bool $protectNativeLogin = true
    ): void {
        $this->rewrites->registerHooks($this->hooks);

        $routeCallback = $allowedRoutePaths === null
            ? [$this, 'dispatchRoute']
            : fn (): mixed => $this->dispatchRoute($allowedRoutePaths);
        $this->hooks->addAction('template_redirect', $routeCallback, 0);

        if ($protectNativeLogin) {
            $this->loginGuard?->registerHooks($this->hooks, $allowedRoutePaths);
        }

        foreach ($this->actionHandlers->all() as $actionKey => $handler) {
            unset($handler);
            if ($allowedActionKeys !== null
                && ! in_array($actionKey, $allowedActionKeys, true)) {
                continue;
            }

            $definition = $this->actions->find($actionKey);
            if (! $definition instanceof ActionDefinition) {
                continue;
            }

            $prefix = $definition->transport() === ActionTransport::Ajax
                ? 'wp_ajax_'
                : 'admin_post_';
            $this->hooks->addAction(
                $prefix . $actionKey,
                fn (): mixed => $this->dispatchAction($actionKey)
            );

            if ($definition->authentication() === AuthenticationRequirement::Anonymous) {
                $anonymousPrefix = $definition->transport() === ActionTransport::Ajax
                    ? 'wp_ajax_nopriv_'
                    : 'admin_post_nopriv_';
                $this->hooks->addAction(
                    $anonymousPrefix . $actionKey,
                    fn (): mixed => $this->dispatchAction($actionKey)
                );
            }
        }
    }

    /** @param list<string>|null $allowedPaths */
    public function dispatchRoute(?array $allowedPaths = null): void
    {
        $request = $this->request();
        $canonicalUrl = $this->transport->canonicalRedirectUrl();
        if ($canonicalUrl !== null) {
            $this->emitter->emit(HttpResponse::redirect($canonicalUrl));

            return;
        }
        if ($allowedPaths !== null && ! in_array($request->path(), $allowedPaths, true)) {
            $this->emitter->emit(HttpResponse::redirect('/oj-console/settings/migration'));

            return;
        }

        $response = $this->routes->dispatch($request);
        if ($response === null) {
            // Keep real WordPress pages, feeds and API responses under their own handlers.
            if ($this->notFound === null
                || ! in_array($request->method(), ['GET', 'HEAD'], true)
                || ! function_exists('is_404') || ! is_404()
                || (function_exists('wp_is_json_request') && wp_is_json_request())
                || (function_exists('is_feed') && is_feed())) {
                return;
            }
            $response = $this->notFound->notFound($request);
        }

        $this->emitter->emit($response, $request->method() === 'HEAD');
    }

    public function dispatchAction(string $actionKey): never
    {
        $request = $this->request('POST');
        $response = $this->actionDispatcher->dispatch($actionKey, $request);
        $this->emitter->emit($response);

        exit;
    }

    private function request(?string $forcedMethod = null): HttpRequest
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = $this->transport->requestPath($requestUri, $_GET);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_scalar($value)) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        $headers['x-oj-client-ip'] = is_scalar($_SERVER['REMOTE_ADDR'] ?? null)
            ? (string) $_SERVER['REMOTE_ADDR']
            : '';

        return new HttpRequest(
            strtoupper($forcedMethod ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($path, '/'),
            $this->unslashArray($_GET),
            $this->unslashArray($_POST),
            $headers,
            $this->uploadedFiles($_FILES)
        );
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function unslashArray(array $values): array
    {
        return function_exists('wp_unslash') ? wp_unslash($values) : $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, UploadedFile>
     */
    private function uploadedFiles(array $values): array
    {
        $files = [];
        foreach ($values as $key => $value) {
            if (
                ! is_string($key)
                || ! is_array($value)
                || is_array($value['name'] ?? null)
                || is_array($value['tmp_name'] ?? null)
            ) {
                continue;
            }

            $files[$key] = new UploadedFile(
                is_scalar($value['name'] ?? null) ? (string) $value['name'] : '',
                is_scalar($value['tmp_name'] ?? null) ? (string) $value['tmp_name'] : '',
                is_numeric($value['size'] ?? null) ? max(0, (int) $value['size']) : 0,
                is_numeric($value['error'] ?? null)
                    ? max(0, (int) $value['error'])
                    : UPLOAD_ERR_NO_FILE
            );
        }

        return $files;
    }
}

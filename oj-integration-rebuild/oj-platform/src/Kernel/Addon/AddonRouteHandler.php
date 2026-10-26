<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

/**
 * Adapts an AddonPageHandlerV1 into the platform's RouteHandler contract.
 * Constructed once per accepted addon page by AddonRouteContributor.
 *
 * @internal
 */
final class AddonRouteHandler implements RouteHandler
{
    /**
     * @param list<\CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1> $assetBundles the addon's full declared bundle list
     * @param list<string> $pageAssetBundleKeys the specific bundle keys this route's page declared, in order
     * @param list<array{label: string, route: string, capability: string}> $localNav segments of this page's menu (Addon API 1.3)
     */
    public function __construct(
        private readonly string $routeKey,
        private readonly AddonPageHandlerV1 $handler,
        private readonly string $capabilityKey,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly RouteUrlProvider $routeUrls,
        private readonly ?ConsoleResponder $consoleResponder,
        private readonly ?PageResponder $publicResponder,
        private readonly ?string $consoleMenuKey,
        private readonly ?AddonAssetRenderer $assets = null,
        private readonly string $pluginFile = '',
        private readonly array $assetBundles = [],
        private readonly array $pageAssetBundleKeys = [],
        private readonly array $localNav = []
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        if (
            ($this->consoleResponder !== null
                && ! $this->permissions->can($identity->userId(), 'oj_access_console'))
            || ! $this->permissions->can($identity->userId(), $this->capabilityKey)
        ) {
            return $this->render('접근 제한', '이 화면에 접근할 권한이 없습니다.', 403, $request);
        }

        $response = $this->handler->handle(new AddonPageRequestV1(
            $this->routeKey,
            $identity->userId(),
            $route->parameters(),
            $this->normalizeQuery($request),
            'ko_KR',
            $this->correlations->next($this->routeKey)
        ));

        return $this->respond($response, $request);
    }

    private function respond(AddonPageResponseV1 $response, HttpRequest $request): HttpResponse
    {
        if ($response->kind() === 'redirect') {
            return HttpResponse::redirect(
                $this->routeUrls->path((string) $response->route(), $response->parameters())
            );
        }

        if ($response->kind() === 'unavailable') {
            return $this->render('사용할 수 없음', (string) $response->message(), 503, $request);
        }

        return $this->render(
            (string) $response->title(),
            (string) $response->body(),
            $response->status(),
            $request,
            $response->layout(),
            $response->supportsDarkMode()
        );
    }

    private function render(
        string $title,
        string $body,
        int $status,
        HttpRequest $request,
        string $layout = 'standard',
        bool $supportsDarkMode = false
    ): HttpResponse {
        // Empty markers the stylesheet reads with :has(): pages that did not
        // opt into dark mode stay light, and the layout widens the shell.
        $markers = ($supportsDarkMode ? '' : '<span hidden data-ojp-light-only></span>')
            . ($layout !== 'standard'
                ? '<span hidden data-ojp-layout="' . htmlspecialchars($layout, ENT_QUOTES, 'UTF-8') . '"></span>'
                : '');
        $body = $markers . $this->localNavMarkup($request) . $body;

        // Styles go before the body (avoids a flash of unstyled content).
        // Scripts go after it: a script printed before this page's own
        // markup would run while that markup is still unparsed, so any DOM
        // query it makes at load time finds nothing.
        $body = $this->styleMarkup() . $body . $this->scriptMarkup();

        if ($this->consoleResponder !== null) {
            return $this->consoleResponder->respond(
                $title,
                $body,
                $this->consoleMenuKey ?? $this->routeKey,
                $request,
                $status
            );
        }

        return $this->publicResponder->authenticated($title, $body, $request, $status);
    }

    private function localNavMarkup(HttpRequest $request): string
    {
        if ($this->consoleResponder === null || $this->localNav === []) {
            return '';
        }
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return '';
        }

        $links = '';
        $visible = 0;
        foreach ($this->localNav as $segment) {
            if ($segment['capability'] === '' || ! $this->permissions->can($identity->userId(), $segment['capability'])) {
                continue;
            }
            try {
                $url = $this->routeUrls->path($segment['route']);
            } catch (\Throwable) {
                continue;
            }
            $current = $segment['route'] === $this->routeKey;
            ++$visible;
            $links .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                . ($current ? ' class="is-current" aria-current="page"' : '') . '>'
                . htmlspecialchars($segment['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        // One reachable segment is not navigation.
        return $visible < 2 ? '' : '<nav class="ojp-local-nav" aria-label="이 기능의 화면">' . $links . '</nav>';
    }

    private function styleMarkup(): string
    {
        if ($this->assets === null || $this->pageAssetBundleKeys === []) {
            return '';
        }

        return $this->assets->renderStyles($this->pluginFile, $this->assetBundles, $this->pageAssetBundleKeys);
    }

    private function scriptMarkup(): string
    {
        if ($this->assets === null || $this->pageAssetBundleKeys === []) {
            return '';
        }

        return $this->assets->renderScripts($this->pluginFile, $this->assetBundles, $this->pageAssetBundleKeys);
    }

    /** @return array<string, string|list<string>> */
    private function normalizeQuery(HttpRequest $request): array
    {
        $normalized = [];
        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key) !== 1) {
                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = substr((string) $value, 0, 2048);
                continue;
            }

            if (is_array($value)) {
                $list = [];
                foreach (array_slice($value, 0, 100) as $item) {
                    if (is_scalar($item)) {
                        $list[] = substr((string) $item, 0, 2048);
                    }
                }
                if ($list !== []) {
                    $normalized[$key] = $list;
                }
            }
        }

        return $normalized;
    }
}

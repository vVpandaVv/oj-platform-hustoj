<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;

final class SimpleUserAdminPageHandler implements RouteHandler
{
    public function __construct(
        private readonly string $title,
        private readonly string $currentMenu,
        private readonly ConsoleResponder $pages
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">사용자</p>'
            . '<h1>' . $this->escape($this->title) . '</h1></div></header>'
            . '<div class="ojp-empty-state"><p>표시할 기록이 없습니다.</p></div>';

        return $this->pages->respond(
            $this->title,
            $content,
            $this->currentMenu,
            $request
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

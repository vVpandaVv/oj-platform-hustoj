<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\ConsolePageResponder;

final class DashboardPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsolePageResponder $pages,
        private readonly DashboardPageLoader $loader,
        private readonly DashboardPageView $view
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        return $this->pages->respond(
            '대시보드',
            $this->view->render($this->loader->load()),
            'dashboard',
            $request
        );
    }
}

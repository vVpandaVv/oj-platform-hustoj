<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\PageResponder;

final class HomePageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly HomePageLoader $loader,
        private readonly HomePageView $view
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);

        return $this->pages->authenticated(
            '홈',
            $this->view->render($this->loader->load()),
            $request
        );
    }
}

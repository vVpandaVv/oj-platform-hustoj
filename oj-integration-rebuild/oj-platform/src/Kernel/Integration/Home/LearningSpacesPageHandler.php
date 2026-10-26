<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageView;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class LearningSpacesPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly LearningSpaceHomeCatalog $spaces,
        private readonly HomePageView $view,
        private readonly Clock $clock,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $snapshot = $this->spaces->visible($identity->userId(), $this->clock->now());
        $total = count($snapshot->items());
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, (int) $request->queryString('page', '1')));
        $items = array_map(static fn (LearningSpaceSummary $space) => $space->homeItem(), array_slice($snapshot->items(), ($page - 1) * 20, 20));
        $content = '<header class="ojp-page-header"><div><h1>내 학습 공간</h1><p>현재 열 수 있는 수업·대회·그룹 ' . $total . '개</p></div></header>';
        if ($snapshot->partial()) {
            $content .= '<div class="ojp-notice ojp-notice--warning">일부 학습 공간 정보를 불러오지 못했습니다. 표시된 수는 조회된 공간만 포함합니다.</div>';
        }
        $content .= $items === [] ? '<div class="ojp-empty-state">현재 표시할 학습 공간이 없습니다.</div>' : $this->view->renderSpaceCards($items);
        $content .= $this->pagination->links($page, $pages, '/learning-spaces');
        return $this->pages->authenticated('내 학습 공간', $content, $request);
    }
}

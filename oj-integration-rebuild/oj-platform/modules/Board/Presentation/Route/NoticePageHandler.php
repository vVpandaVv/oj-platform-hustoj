<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityAccessPolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class NoticePageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly CommunityQueryService $queries,
        private readonly CommunityAccessPolicy $access,
        private readonly CommunityFeaturePolicy $features,
        private readonly CommunityHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;

        return match ($route->route()->key()) {
            'notices.index' => $this->index($userId, $request),
            'notices.show' => $this->detail(
                $userId,
                (int) ($route->parameters()['notice_id'] ?? 0),
                $request
            ),
            'notices.new' => $this->editor($userId, $request),
            default => $this->notFound($request),
        };
    }

    private function index(int $userId, HttpRequest $request): HttpResponse
    {
        $keyword = trim($request->queryString('search'));
        $listing = $this->queries->listing(
            $userId,
            new CommunityPostSearch(
                'notice',
                $keyword,
                null,
                null,
                'all',
                ['publish']
            ),
            max(1, (int) $request->queryString('page', '1')),
            20
        );
        $new = $this->access->canManageNotice($userId)
            ? '<a class="ojp-button ojp-button--primary" href="/notices/new">공지 작성</a>'
            : '';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">커뮤니티</p>'
            . '<h1>공지</h1><p>현재 게시 중인 안내를 확인하세요.</p></div>'
            . '<div class="ojp-command-group">'
            . ($this->features->boardEnabled()
                ? '<a class="ojp-button" href="/board">게시판</a>'
                : '')
            . $new . '</div></header>'
            . '<form class="ojp-filter-bar" method="get" action="/notices">'
            . '<input class="ojp-input" type="search" name="search" placeholder="공지 검색" value="'
            . $this->html->escape($keyword) . '">'
            . '<button class="ojp-button" type="submit">검색</button></form>'
            . '<section class="ojp-section">'
            . $this->html->listing(
                $listing,
                '/notices/',
                '현재 게시 중인 공지가 없습니다.'
            )
            . $this->html->pagination($listing, '/notices', ['search' => $keyword])
            . '</section>';

        return $this->pages->authenticated('공지', $content, $request);
    }

    private function detail(
        int $userId,
        int $noticeId,
        HttpRequest $request
    ): HttpResponse {
        $view = $this->queries->detail($userId, $noticeId);
        if ($view === null || $view->post()->kind() !== 'notice') {
            return $this->notFound($request);
        }
        if (! $view->bodyVisible()) {
            return $this->pages->authenticated(
                '게시가 종료된 공지',
                '<div class="ojp-empty-state"><h1>게시가 종료된 공지입니다.</h1>'
                    . '<p>현재 공지 목록에서 다른 안내를 확인해 주세요.</p>'
                    . '<a class="ojp-button" href="/notices">공지 목록</a></div>',
                $request
            );
        }
        $content = '<nav class="ojp-breadcrumb"><a href="/notices">공지</a>'
            . '<span aria-hidden="true">/</span><span>상세</span></nav>'
            . $this->html->detail(
                $view,
                '/oj-console/notices/' . $noticeId . '/edit'
            );

        return $this->pages->authenticated(
            $view->post()->title(),
            $content,
            $request
        );
    }

    private function editor(int $userId, HttpRequest $request): HttpResponse
    {
        if (! $this->access->canManageNotice($userId)) {
            return $this->notFound($request);
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">공지</p>'
            . '<h1>공지 작성</h1><p>게시 상태와 노출 기간을 확인한 뒤 등록하세요.</p>'
            . '</div></header><section class="ojp-section">'
            . $this->html->postForm(
                'oj_notice_create',
                '공지 등록',
                null,
                '',
                5,
                [],
                true
            ) . '</section>';

        return $this->pages->authenticated('공지 작성', $content, $request);
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '공지 없음',
            '<div class="ojp-empty-state"><h1>공지를 열 수 없습니다.</h1>'
                . '<p>공지 게시 상태와 접근 권한을 확인해 주세요.</p>'
                . '<a class="ojp-button" href="/notices">공지 목록</a></div>',
            $request,
            404
        );
    }
}

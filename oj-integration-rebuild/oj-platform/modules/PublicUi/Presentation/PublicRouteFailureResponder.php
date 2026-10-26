<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteFailureResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class PublicRouteFailureResponder implements RouteFailureResponder, \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\NotFoundResponder
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts
    ) {
    }

    public function notFound(HttpRequest $request): HttpResponse
    {
        $content = '<section class="ojp-error-state" aria-labelledby="ojp-not-found-title">'
            . '<div><p class="ojp-eyebrow">404</p>'
            . '<h1 id="ojp-not-found-title">페이지를 찾을 수 없습니다.</h1>'
            . '<p>주소가 올바른지 확인해 주세요. 페이지가 이동되었거나 삭제되었을 수 있습니다.</p>'
            . '<a class="ojp-button ojp-button--primary" href="/">홈으로 이동</a>'
            . '</div></section>';

        return $this->accounts->current()->isLoggedIn()
            ? $this->pages->authenticated('페이지를 찾을 수 없습니다', $content, $request, 404)
            : $this->pages->guest('페이지를 찾을 수 없습니다', $content, $request, 404);
    }

    public function unexpected(HttpRequest $request): HttpResponse
    {
        $content = '<section class="ojp-error-state" role="alert">'
            . '<div><p class="ojp-eyebrow">요청 처리 오류</p>'
            . '<h1>요청을 처리하지 못했습니다.</h1>'
            . '<p>잠시 후 다시 시도해 주세요. 같은 문제가 계속되면 '
            . '관리자가 시스템 상태와 기록을 확인해야 합니다.</p>'
            . '<a class="ojp-button ojp-button--primary" href="/">홈으로 이동</a>'
            . '</div></section>';

        if ($this->accounts->current()->isLoggedIn()) {
            return $this->pages->authenticated(
                '요청 처리 오류',
                $content,
                $request,
                500
            );
        }

        return $this->pages->guest(
            '요청 처리 오류',
            $content,
            $request,
            500
        );
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use DomainException;

final class ContestAccessPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ContestHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        $currentIp = trim((string) $request->header('x-oj-client-ip'));
        try {
            $contest = $this->contests->findVisible(
                $contestId,
                $identity?->userId() ?? 0
            );
            $allowedIp = $this->contests->allowedIp(
                $identity?->userId() ?? 0,
                $contestId
            );
            $pending = $this->contests->pendingIpChangeRequest(
                $identity?->userId() ?? 0,
                $contestId
            );
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '대회 IP 확인',
                '<div class="ojp-empty-state"><h1>IP 정보를 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                403
            );
        }
        $change = $route->route()->key() === 'contests.ip_change';
        $pendingNotice = $pending === null
            ? ''
            : '<div class="ojp-notice ojp-notice--warning"><strong>'
                . 'IP 변경 요청 처리 중</strong><p>요청 IP '
                . $this->html->escape($pending->requestedIp()) . ' · '
                . $this->html->escape($this->html->date($pending->requestedAt()))
                . '</p></div>';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '대회·평가</p><h1>' . $this->html->escape($contest->title())
            . ' IP 확인</h1><p>등록 IP: '
            . $this->html->escape($allowedIp ?? '등록 전') . ' · 현재 접속 IP: '
            . $this->html->escape($currentIp === '' ? '확인 불가' : $currentIp)
            . '</p></div></header>'
            . $this->html->tabs($contestId, 'ip', false)
            . $this->html->timeSummary($contest)
            . $pendingNotice
            . ($change
                ? ($pending !== null
                    ? '<a class="ojp-button" href="/contests/' . $contestId
                        . '/ip-check">확인 화면으로</a>'
                    : '<form class="ojp-form" method="post" action="'
                    . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                    . $this->security->hiddenFields('oj_contest_allowed_ip')
                    . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
                    . '<label class="ojp-field"><span class="ojp-label">현재 접속 IP</span>'
                    . '<input class="ojp-input" value="'
                    . $this->html->escape($currentIp === '' ? '확인 불가' : $currentIp)
                    . '" readonly></label><label class="ojp-field ojp-field--full">'
                    . '<span class="ojp-label">변경 사유</span>'
                    . '<textarea class="ojp-textarea" name="reason" rows="5"'
                    . ' maxlength="500" required></textarea></label>'
                    . '<button class="ojp-button ojp-button--primary" type="submit">'
                    . '변경 요청</button></form>')
                : '<a class="ojp-button" href="/contests/' . $contestId
                    . '/ip-change">IP 변경 요청</a>');

        return $this->pages->authenticated('대회 IP 확인', $content, $request);
    }
}

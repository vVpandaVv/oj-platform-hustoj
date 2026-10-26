<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use DomainException;

final class ContestAccessPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ContestHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        try {
            $contest = $this->contests->findManaged($contestId, $actorId);
            $participants = $this->contests->participantIdentities($contestId);
            $pendingRequests = [];
            foreach (
                $this->contests->managedIpChangeRequests(
                    $actorId,
                    $contestId
                ) as $pendingRequest
            ) {
                $pendingRequests[$pendingRequest->userId()] = $pendingRequest;
            }
        } catch (DomainException $error) {
            return $this->pages->respond(
                '대회 IP 관리',
                '<div class="ojp-empty-state"><h1>IP를 관리할 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                'contests.index',
                $request,
                403
            );
        }
        $rows = '';
        foreach ($participants as $participant) {
            $ip = $this->contests->managedAllowedIp(
                $actorId,
                $contestId,
                $participant->userId()
            );
            $pending = $pendingRequests[$participant->userId()] ?? null;
            $requestCell = $pending === null
                ? '-'
                : '<strong>' . $this->html->escape($pending->requestedIp())
                    . '</strong><span class="ojp-table-subvalue">'
                    . $this->html->escape($pending->reason()) . ' · '
                    . $this->html->escape($this->html->date($pending->requestedAt()))
                    . '</span>';
            $decisionForms = $pending === null
                ? ''
                : $this->decisionForm(
                    $contestId,
                    $pending->id(),
                    'approve',
                    '승인',
                    true
                ) . $this->decisionForm(
                    $contestId,
                    $pending->id(),
                    'reject',
                    '거절',
                    false
                );
            $rows .= '<tr><td>' . $this->html->escape($participant->displayName())
                . '</td><td>' . $this->html->escape($participant->userLogin())
                . '</td><td>' . $this->html->escape($ip ?? '미지정')
                . '</td><td>' . $requestCell . '</td><td>'
                . '<div class="ojp-command-group">' . $decisionForms
                . '<form class="ojp-inline-form" method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_contest_admin_allowed_ip')
                . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
                . '<input type="hidden" name="operation" value="save">'
                . '<input type="hidden" name="target_user_id" value="'
                . $participant->userId() . '"><input class="ojp-input"'
                . ' name="ip_address" value="' . $this->html->escape($ip ?? '')
                . '" placeholder="비우면 해제"><button class="ojp-button ojp-button--small"'
                . ' type="submit">직접 저장</button></form></div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">참여자가 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '대회 관리</p><h1>' . $this->html->escape($contest->title())
            . ' IP 관리</h1></div></header>'
            . $this->html->tabs($contestId, 'ip', true)
            . $this->html->timeSummary($contest)
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>참여자</th><th>로그인 아이디</th><th>허용 IP</th>'
            . '<th>변경 요청</th><th>처리·직접 변경</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->respond(
            '대회 IP 관리',
            $content,
            'contests.index',
            $request
        );
    }

    private function decisionForm(
        int $contestId,
        int $requestId,
        string $operation,
        string $label,
        bool $primary
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_admin_allowed_ip')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
            . '<input type="hidden" name="request_id" value="' . $requestId . '">'
            . '<input type="hidden" name="operation" value="'
            . $this->html->escape($operation) . '"><button class="ojp-button '
            . ($primary ? 'ojp-button--primary ' : '')
            . 'ojp-button--small" type="submit">'
            . $this->html->escape($label) . '</button></form>';
    }
}

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
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use DomainException;

final class ContestCatalogPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ContestHtml $html,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        if ($route->route()->key() === 'contests.index') {
            $allContests = $this->contests->visibleFor($userId);
            $page = max(1, (int) $request->queryString('page', '1'));
            $pageCount = max(1, (int) ceil(count($allContests) / self::PAGE_SIZE));
            $page = min($page, $pageCount);
            $visibleContests = array_slice(
                $allContests,
                ($page - 1) * self::PAGE_SIZE,
                self::PAGE_SIZE
            );
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습</p><h1>대회·평가</h1>'
                . '<p>진행 일정과 공개 범위를 확인하고 참여합니다.</p>'
                . '</div></header>'
                . $this->html->timeSummary()
                . $this->html->contestTable(
                    $visibleContests,
                    false,
                    $this->editableContestIds($visibleContests, $userId)
                )
                . $this->pagination->links($page, $pageCount, '/contests');

            return $this->pages->authenticated('대회·평가', $content, $request);
        }
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        try {
            $contest = $this->contests->findVisible($contestId, $userId);
            $settings = $this->contests->settings($contestId);
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '대회·평가',
                '<div class="ojp-empty-state"><h1>대회를 열 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                403
            );
        }
        $stage = $this->contests->currentStage($contest);
        $canConfigure = $this->contests->canConfigure($userId, $contestId, $settings);
        $status = $this->contests->participationStatus($contestId, $userId);
        $canViewProblems = $canConfigure
            || in_array($status, ['active', 'group'], true);
        $rows = '';
        foreach ($this->contests->problems($contestId) as $problem) {
            $navigation = ProblemNavigationContext::contest(
                $contestId,
                $problem->number()
            );
            $problemAction = $stage === 'running' && $canViewProblems
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->html->escape($navigation->submitUrl($problem->problemId()))
                    . '">풀기</a>'
                : '<span class="ojp-status-badge ojp-status-badge--neutral">'
                    . (! $canViewProblems
                        ? '참여 필요'
                        : ($stage === 'preparation' ? '시작 전' : '종료됨'))
                    . '</span>';
            $problemLabel = $canViewProblems
                ? '<a class="ojp-row-link" href="'
                    . $this->html->escape($navigation->detailUrl($problem->problemId()))
                    . '">문제 ' . $problem->problemId() . '</a>'
                : '참여 후 공개';
            $rows .= '<tr><td>' . $problem->numberLabel() . '</td><td>'
                . $problemLabel . '</td><td>' . number_format($problem->score(), 2)
                . '</td><td>' . $problemAction . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">등록된 대회 문제가 없습니다.</td></tr>';
        }
        $stageNotice = $stage === 'preparation'
            ? '<div class="ojp-notice"><strong>대회 시작 전</strong><p>'
                . '준비 단계에서는 제출할 수 없습니다. '
                . $this->html->escape($contest->startsAt())
                . '에 자동으로 진행 중으로 전환됩니다.</p></div>'
            : '';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '대회·평가</p><h1>' . $this->html->escape($contest->title())
            . '</h1><p>' . $this->html->escape($contest->description())
            . '</p></div><div class="ojp-command-group">'
            . '<span class="ojp-status-badge">'
            . $this->html->escape($this->html->stageLabel($stage)) . '</span>'
            . '<span class="ojp-status-badge ojp-status-badge--neutral">'
            . $this->html->escape($settings->scoringPolicy()) . '</span>'
            . ($canConfigure
                ? '<a class="ojp-button" href="/oj-console/contests/'
                    . $contestId . '/edit">대회 수정</a>'
                : '')
            . '</div></header>'
            . $this->html->tabs($contestId, 'show', false)
            . $this->html->timeSummary($contest)
            . $stageNotice
            . $this->participationControls(
                $contestId,
                $settings->visibility(),
                $status,
                $canConfigure,
                $settings->requiresApproval()
            )
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>번호</th><th>문제</th><th>배점</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return $this->pages->authenticated($contest->title(), $content, $request);
    }

    private function participationControls(
        int $contestId,
        string $visibility,
        ?string $status,
        bool $canConfigure,
        bool $approvalRequired = false
    ): string {
        if ($canConfigure) {
            return '';
        }
        if ($status === 'group') {
            return '<div class="ojp-notice ojp-notice--info"><strong>그룹으로 참여 중</strong>'
                . '<p>연결된 그룹의 활성 구성원으로 참여하고 있습니다. '
                . '참여 해제는 그룹 구성원 설정에서 처리합니다.</p></div>';
        }
        if ($status === 'active') {
            return '<div class="ojp-inline-actions">'
                . '<span class="ojp-status-badge">참여 중</span>'
                . $this->participationForm($contestId, 'withdraw', '참여 취소', true)
                . '</div>';
        }
        if ($status === 'removed') {
            return '<div class="ojp-notice ojp-notice--info"><p>참가가 제한된 대회입니다. 대회 담당자에게 문의해 주세요.</p></div>';
        }
        if ($status === 'pending' && $approvalRequired) {
            return '<div class="ojp-notice ojp-notice--info"><strong>승인 대기 중</strong>'
                . '<p>대회 운영자가 참여 요청을 확인하고 있습니다.</p>'
                . $this->participationForm($contestId, 'withdraw', '요청 취소')
                . '</div>';
        }
        if ($visibility !== 'public') {
            return '';
        }

        return $this->participationForm($contestId, 'request', $approvalRequired ? '참여 요청' : '대회 참가');
    }

    private function participationForm(
        int $contestId,
        string $operation,
        string $label,
        bool $danger = false
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_participation_command')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
            . '<input type="hidden" name="operation" value="'
            . $this->html->escape($operation) . '">'
            . '<button class="ojp-button '
            . ($danger ? 'ojp-button--danger' : 'ojp-button--primary')
            . '" type="submit">' . $this->html->escape($label)
            . '</button></form>';
    }

    /**
     * @param list<Contest> $contests
     * @return list<int>
     */
    private function editableContestIds(array $contests, int $userId): array
    {
        $ids = [];
        foreach ($contests as $contest) {
            try {
                $settings = $this->contests->settings($contest->id());
            } catch (DomainException) {
                continue;
            }
            if ($this->contests->canConfigure($userId, $contest->id(), $settings)) {
                $ids[] = $contest->id();
            }
        }

        return $ids;
    }
}

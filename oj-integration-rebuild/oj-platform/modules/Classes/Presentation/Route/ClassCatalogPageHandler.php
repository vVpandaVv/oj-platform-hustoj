<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use DomainException;

final class ClassCatalogPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ClassService $classes,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ClassHtml $html,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $userId = $identity?->userId() ?? 0;
        if ($route->route()->key() === 'classes.index') {
            $invitations = $this->classes->pendingInvitationsFor($userId);
            $invitationIds = array_fill_keys(array_map(
                static fn (LearningClass $class): int => $class->id(),
                $invitations
            ), true);
            $allClasses = $this->classes->visibleFor($userId);
            $allClasses = array_values(array_filter(
                $allClasses,
                static fn (LearningClass $class): bool =>
                    ! isset($invitationIds[$class->id()])
            ));
            $page = max(1, (int) $request->queryString('page', '1'));
            $pageCount = max(1, (int) ceil(count($allClasses) / self::PAGE_SIZE));
            $page = min($page, $pageCount);
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습</p><h1>수업</h1>'
                . '<p>참여 중이거나 공개된 수업에서 문제를 풀 수 있습니다.</p>'
                . '</div></header>'
                . $this->invitationList($invitations)
                . $this->html->classTable(
                    array_slice($allClasses, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
                    false
                )
                . $this->pagination->links($page, $pageCount, '/classes');

            return $this->pages->authenticated('수업', $content, $request);
        }

        $classId = $this->parameter($route, 'class_id');
        try {
            $invitation = $this->classes->pendingInvitation(
                $classId,
                $userId
            );
            if ($invitation !== null) {
                return $this->invitationPage($invitation, $request);
            }
            $class = $this->classes->findVisible($classId, $userId);
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '수업',
                '<div class="ojp-empty-state"><h1>수업을 열 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                403
            );
        }
        $status = $this->classes->participationStatus($classId, $userId);
        $canManage = $this->classes->canManage($userId, $class);
        $canViewProblems = $canManage
            || in_array($status, ['active', 'group'], true);
        $classProblems = $this->classes->classProblemsForUser($classId, $userId);
        $viewable = [];
        foreach ($classProblems as $problem) {
            $viewable[$problem->problemId()] = $this->classes->canViewProblem(
                $userId,
                $classId,
                $problem->problemId()
            );
        }
        $solveStatus = $identity !== null
            ? $this->classes->solveStatusForUser(
                $userId,
                $identity->userLogin(),
                $classId,
                array_keys(array_filter($viewable))
            )
            : [];
        $problemRows = '';
        foreach ($classProblems as $problem) {
            $navigation = ProblemNavigationContext::class($classId);
            $availability = $this->classes->problemAvailability($problem);
            $canOpen = $viewable[$problem->problemId()];
            if ($canOpen) {
                $problemTitle = '<a class="ojp-row-link" href="'
                    . $this->html->escape($navigation->detailUrl($problem->problemId()))
                    . '">문제 ' . $problem->problemId() . '</a>';
                $statusLabel = $availability === 'hidden'
                    ? '숨김 · 담당자 보기'
                    : '공개';
                $action = '<a class="ojp-button ojp-button--small" href="'
                    . $this->html->escape($navigation->submitUrl($problem->problemId()))
                    . '">풀기</a>';
                $solveBadge = $this->solveBadge(
                    $solveStatus[$problem->problemId()] ?? 'unsolved'
                );
            } else {
                $problemTitle = '<strong>다음 문제</strong>';
                $statusLabel = $availability === 'scheduled'
                    ? '공개 예정' . ($problem->availableFrom() === null
                        ? ''
                        : ' · ' . $problem->availableFrom())
                    : '공개 종료';
                $action = '<span class="ojp-status-badge">대기</span>';
                $solveBadge = '-';
            }
            $problemRows .= '<tr><td>' . ($problem->sortOrder() + 1) . '</td><td>'
                . $problemTitle . '</td><td>' . $this->html->escape($statusLabel)
                . '</td><td>' . $solveBadge . '</td><td>' . $action . '</td></tr>';
        }
        if (! $canViewProblems) {
            $problemRows = '<tr><td colspan="5">'
                . ($class->requiresApproval() ? '수업 참여가 승인되면 문제를 볼 수 있습니다.' : '수업에 참여하면 문제를 볼 수 있습니다.')
                . '</td></tr>';
        } elseif ($problemRows === '') {
            $problemRows = '<tr><td colspan="5">공개된 문제가 없습니다.</td></tr>';
        }
        $joinForm = $this->participationControls(
            $classId,
            $class->visibility(),
            $status,
            $canManage,
            $class->requiresApproval()
        );
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">수업</p><h1>'
            . $this->html->escape($class->name()) . '</h1><p>'
            . $this->html->escape($class->description()) . '</p></div></header>'
            . $this->html->tabs($classId, 'show', false)
            . $joinForm
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>순서</th><th>문제</th><th>공개 상태</th>'
            . '<th>해결 여부</th><th>작업</th></tr></thead>'
            . '<tbody>' . $problemRows . '</tbody></table></div>';

        return $this->pages->authenticated($class->name(), $content, $request);
    }

    /** @param list<LearningClass> $invitations */
    private function invitationList(array $invitations): string
    {
        if ($invitations === []) {
            return '';
        }
        $rows = '';
        foreach ($invitations as $class) {
            $rows .= '<tr><td><a class="ojp-row-link" href="/classes/'
                . $class->id() . '">' . $this->html->escape($class->name())
                . '</a></td><td><span class="ojp-status-badge">초대 수락 대기</span>'
                . '</td></tr>';
        }

        return '<section class="ojp-section"><h2>수락 대기 중인 초대</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>수업</th><th>상태</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private function invitationPage(
        LearningClass $class,
        HttpRequest $request
    ): HttpResponse {
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">수업 초대</p><h1>'
            . $this->html->escape($class->name()) . '</h1></div></header>'
            . $this->participationControls(
                $class->id(),
                $class->visibility(),
                'invited',
                false
            );

        return $this->pages->authenticated(
            $class->name(),
            $content,
            $request
        );
    }

    private function participationControls(
        int $classId,
        string $visibility,
        ?string $status,
        bool $canManage,
        bool $approvalRequired = false
    ): string {
        if ($canManage) {
            return '';
        }
        if ($status === 'group') {
            return '<div class="ojp-notice ojp-notice--info"><strong>그룹으로 참여 중</strong>'
                . '<p>연결된 그룹의 활성 구성원이므로 이 수업에 참여하고 있습니다. '
                . '참여 해제는 그룹 구성원 설정에서 처리합니다.</p></div>';
        }
        if ($status === 'active') {
            return '<div class="ojp-inline-actions">'
                . '<span class="ojp-status-badge">참여 중</span>'
                . $this->participationForm($classId, 'leave', '수업 나가기', 'danger')
                . '</div>';
        }
        if (in_array($status, ['removed', 'rejected'], true)) {
            return '<div class="ojp-notice ojp-notice--info"><p>참여가 제한된 수업입니다. 수업 담당자에게 문의해 주세요.</p></div>';
        }
        if ($status === 'pending' && $approvalRequired) {
            return '<div class="ojp-notice ojp-notice--info"><strong>승인 대기 중</strong>'
                . '<p>운영자가 참여 요청을 확인하고 있습니다.</p>'
                . $this->participationForm($classId, 'cancel', '요청 취소')
                . '</div>';
        }
        if ($status === 'invited') {
            return '<div class="ojp-notice ojp-notice--info"><strong>수업 초대</strong>'
                . '<p>수업 운영자가 참여를 요청했습니다.</p><div class="ojp-inline-actions">'
                . $this->participationForm($classId, 'accept', '초대 수락', 'primary')
                . $this->participationForm($classId, 'decline', '초대 거절', 'danger')
                . '</div></div>';
        }
        if (! in_array($visibility, ['public', 'password'], true)) {
            return '';
        }

        return $this->participationForm(
            $classId,
            'request',
            $approvalRequired ? '수업 참여 요청' : '수업 참여',
            'primary',
            $visibility === 'password'
        );
    }

    private function participationForm(
        int $classId,
        string $operation,
        string $label,
        string $tone = '',
        bool $password = false
    ): string {
        $className = $password ? 'ojp-form' : 'ojp-inline-form';
        $buttonClass = 'ojp-button'
            . ($tone === '' ? '' : ' ojp-button--' . $tone);
        $passwordField = $password
            ? '<label class="ojp-field"><span class="ojp-label">참여 비밀번호</span>'
                . '<input class="ojp-input" type="password" name="password" '
                . 'autocomplete="current-password" required></label>'
            : '';

        return '<form class="' . $className . '" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_class_membership_command')
            . '<input type="hidden" name="class_id" value="' . $classId . '">'
            . '<input type="hidden" name="operation" value="'
            . $this->html->escape($operation) . '">' . $passwordField
            . '<button class="' . $buttonClass . '" type="submit">'
            . $this->html->escape($label) . '</button></form>';
    }

    private function solveBadge(string $status): string
    {
        return match ($status) {
            'solved' => '<span class="ojp-status-badge ojp-status-badge--success">해결</span>',
            'attempted' => '<span class="ojp-status-badge ojp-status-badge--warning">오답</span>',
            default => '<span class="ojp-status-badge ojp-status-badge--neutral">미시도</span>',
        };
    }

    private function parameter(RouteMatch $route, string $key): int
    {
        return max(0, (int) ($route->parameters()[$key] ?? 0));
    }
}

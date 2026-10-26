<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Groups\Domain\GroupReport;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class GroupHtml
{
    public function __construct(
        private readonly JudgeResults $judgeResults,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter(),
        private readonly ?\CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags $features = null
    ) {
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function date(?string $value, string $format = 'Y-m-d H:i'): string
    {
        return $this->dates->format($value, $format);
    }

    /**
     * @param list<LearningGroup> $groups
     */
    public function groupTable(array $groups, bool $admin, ?callable $canArchive = null): string
    {
        $showActions = $admin && $canArchive !== null;
        $rows = '';
        foreach ($groups as $group) {
            $path = $admin
                ? '/oj-console/groups/' . $group->id() . '/edit'
                : '/groups/' . $group->id();
            $mode = $group->isPerformanceMode() ? '수행' : '일반';
            $status = $group->isDeleted()
                ? '보관'
                : ($group->isEnabled() ? '운영' : '중지');
            $rows .= '<tr><td>' . $group->id() . '</td><td>'
                . '<a class="ojp-row-link" href="' . $path . '">'
                . $this->escape($group->name()) . '</a></td><td>'
                . $this->visibilityLabel($group->visibility()) . '</td><td>'
                . '<span class="ojp-status-badge">'
                . $mode . '</span></td><td>' . $status . '</td>'
                . ($showActions ? '<td>' . ($canArchive($group) ? $this->lifecycleLink($group) : '') . '</td>' : '')
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="' . ($showActions ? 6 : 5) . '">표시할 그룹이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>번호</th><th>그룹</th><th>공개</th>'
            . '<th>운영 모드</th><th>상태</th>' . ($showActions ? '<th>관리</th>' : '')
            . '</tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    public function lifecycleLink(LearningGroup $group): string
    {
        return '<a class="ojp-button' . ($group->isDeleted() ? '' : ' ojp-button--danger')
            . '" href="/oj-console/groups/' . $group->id() . '/delete">'
            . ($group->isDeleted() ? '복원' : '보관') . '</a>';
    }

    /**
     * @param list<UserIdentity> $managers
     */
    public function infoPanel(
        LearningGroup $group,
        ?UserIdentity $owner,
        array $managers,
        int $memberCount,
        string $relation,
        bool $canManage,
        int $pendingCount
    ): string {
        $relationLabel = match ($relation) {
            'global_manager' => '전역 관리자',
            'owner' => '소유자',
            'manager' => '운영자',
            default => null,
        };
        $managerNames = array_map(
            static fn (UserIdentity $manager): string => $manager->displayName(),
            $managers
        );
        $rows = '<div><dt>공개 범위</dt><dd>'
            . $this->visibilityLabel($group->visibility()) . '</dd></div>'
            . '<div><dt>가입 방식</dt><dd>'
            . ($group->approvalMode() === 'automatic' ? '자동 승인' : '승인 필요')
            . '</dd></div><div><dt>운영 모드</dt><dd>'
            . ($group->isPerformanceMode() ? '수행' : '일반') . '</dd></div>'
            . '<div><dt>구성원 수</dt><dd>' . $memberCount . '명</dd></div>'
            . '<div><dt>소유자</dt><dd>' . ($owner !== null
                ? $this->userLink($owner->userId(), $owner->displayName())
                : '-') . '</dd></div>'
            . '<div><dt>운영자</dt><dd>' . ($managerNames === []
                ? '지정된 운영자가 없습니다.'
                : $this->escape(implode(', ', $managerNames))) . '</dd></div>';
        if ($relationLabel !== null) {
            $rows .= '<div><dt>내 역할</dt><dd><span class="ojp-badge '
                . 'ojp-badge--info">' . $relationLabel . '</span></dd></div>';
        }
        if ($canManage && $pendingCount > 0) {
            $rows .= '<div><dt>대기 중인 참여 요청</dt><dd>' . $pendingCount
                . '건 <a class="ojp-button ojp-button--small" href="'
                . '/oj-console/groups/' . $group->id() . '/approvals">'
                . '승인 처리</a></dd></div>';
        }

        return '<section class="ojp-section"><dl class="ojp-description-grid">'
            . $rows . '</dl></section>';
    }

    /**
     * @param list<UserIdentity> $members
     * @param array<int, bool> $managerIds
     * @param array<string, int> $solvedByLogin
     */
    public function memberTable(
        array $members,
        array $managerIds,
        int $ownerId,
        array $solvedByLogin,
        bool $showActivity,
        int $viewerId = 0
    ): string {
        $rows = '';
        foreach ($members as $member) {
            $isOwner = $member->userId() === $ownerId;
            $isManager = isset($managerIds[$member->userId()]);
            $roleBadge = $isOwner
                ? '<span class="ojp-status-badge">소유자</span>'
                : ($isManager
                    ? '<span class="ojp-status-badge">운영자</span>'
                    : '');
            $loginCell = $showActivity
                ? $this->escape($member->userLogin())
                : '-';
            $solvedCell = $showActivity
                ? (string) ($solvedByLogin[$member->userLogin()] ?? 0)
                : '-';
            $rows .= '<tr><td>'
                . $this->userLink($member->userId(), $member->displayName())
                . ($member->userId() !== $viewerId && ($this->features?->isEnabled('messaging') ?? false)
                    ? ' <a class="ojp-button ojp-button--small" href="/messages/new/' . $member->userId() . '">쪽지</a>' : '')
                . '</td><td>' . $loginCell . '</td><td>' . $roleBadge
                . '</td><td>' . $solvedCell . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">활성 구성원이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>표시 이름</th><th>로그인 아이디</th><th>역할</th>'
            . '<th>해결 문제 수</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>';
    }

    public function tabs(int $groupId, string $current, bool $admin): string
    {
        $base = $admin
            ? '/oj-console/groups/' . $groupId
            : '/groups/' . $groupId;
        $items = $admin
            ? [
                'edit' => [$base . '/edit', '그룹 설정'],
                'members' => [$base . '/members', '구성원'],
                'approvals' => [$base . '/approvals', '승인'],
                'managers' => [$base . '/managers', '운영자'],
                'bulk' => [$base . '/bulk-members', '일괄 등록'],
                'submissions' => [$base . '/submissions', '제출 현황'],
                'ranking' => [$base . '/ranking', '순위'],
            ]
            : [
                'show' => [$base, '그룹 정보'],
                'members' => [$base . '/members', '구성원'],
                'submissions' => [$base . '/submissions', '제출 현황'],
                'ranking' => [$base . '/ranking', '순위'],
            ];
        $html = '<nav class="ojp-tabs" aria-label="그룹 메뉴">';
        foreach ($items as $key => [$path, $label]) {
            $html .= '<a class="ojp-tab'
                . ($key === $current ? ' is-active' : '')
                . '" href="' . $path . '">' . $label . '</a>';
        }

        return $html . '</nav>';
    }

    public function periodFilter(
        string $action,
        string $fromDate,
        string $toDate
    ): string {
        return '<form class="ojp-filter-bar" method="get" action="'
            . $this->escape($action) . '"><div class="ojp-filter-bar__primary">'
            . '<label class="ojp-field"><span class="ojp-label">시작일</span>'
            . '<input class="ojp-input" type="date" name="from" value="'
            . $this->escape($fromDate) . '"></label>'
            . '<label class="ojp-field"><span class="ojp-label">종료일</span>'
            . '<input class="ojp-input" type="date" name="to" value="'
            . $this->escape($toDate) . '"></label></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">조회</button>'
            . '</form>';
    }

    public function submissionTable(
        GroupReport $report,
        bool $ownDetailsAllowed = true,
        ?string $viewerLogin = null,
        bool $canManage = true,
        bool $selectable = false
    ): string
    {
        $memberIdsByLogin = [];
        foreach ($report->members() as $member) {
            $memberIdsByLogin[$member->userLogin()] = $member->userId();
        }
        $groupId = $report->group()->id();
        $rows = '';
        foreach ($report->submissions() as $submission) {
            $id = $submission->submissionId();
            $isOwn = $viewerLogin !== null
                && $submission->userLogin() === $viewerLogin;
            $detailsAllowed = $viewerLogin === null
                || ($isOwn ? $ownDetailsAllowed : $canManage);
            $submissionCell = $detailsAllowed
                ? '<a class="ojp-row-link" href="/submissions/' . $id
                    . '?group_id=' . $groupId . '">' . $id . '</a>'
                : '<strong>' . $id . '</strong>';
            $rows .= '<tr>' . ($selectable
                ? '<td class="ojp-table-check"><input type="checkbox"'
                    . ' name="submission_ids[]" value="' . $id
                    . '" aria-label="제출 ' . $id . ' 선택"></td>'
                : '') . '<td>' . $submissionCell . '</td><td>'
                . $this->userLink($memberIdsByLogin[$submission->userLogin()] ?? null, $submission->userLogin())
                . '</td><td>'
                . '<a href="/problems/' . $submission->problemId()
                . '?group_id=' . $groupId . '">'
                . $submission->problemId() . '</a></td><td>'
                . $this->resultLabel($submission->resultCode()) . '</td><td>'
                . $this->escape($this->date($submission->submittedAt())) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="' . ($selectable ? 6 : 5)
                . '">제출 기록이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr>'
            . ($selectable ? '<th class="ojp-table-check">선택</th>' : '')
            . '<th>제출</th><th>사용자</th><th>문제</th>'
            . '<th>결과</th><th>제출 시각</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    public function rankingTable(GroupReport $report): string
    {
        $byLogin = [];
        foreach ($report->submissions() as $submission) {
            $byLogin[$submission->userLogin()][] = $submission;
        }
        $ranking = [];
        foreach ($report->members() as $member) {
            $items = $byLogin[$member->userLogin()] ?? [];
            $acceptedProblems = [];
            $lastAccepted = '';
            foreach ($items as $submission) {
                if ($submission->resultCode() === 4) {
                    $acceptedProblems[$submission->problemId()] = true;
                    if (
                        $lastAccepted === ''
                        || $submission->submittedAt() > $lastAccepted
                    ) {
                        $lastAccepted = $submission->submittedAt();
                    }
                }
            }
            $ranking[] = [
                'member' => $member,
                'solved' => count($acceptedProblems),
                'attempts' => count($items),
                'last' => $lastAccepted,
            ];
        }
        usort($ranking, static function (array $left, array $right): int {
            return ($right['solved'] <=> $left['solved'])
                ?: ($left['attempts'] <=> $right['attempts'])
                ?: strcmp(
                    $left['last'] === '' ? '9999' : $left['last'],
                    $right['last'] === '' ? '9999' : $right['last']
                )
                ?: strcmp(
                    $left['member']->userLogin(),
                    $right['member']->userLogin()
                );
        });
        $rows = '';
        foreach ($ranking as $index => $item) {
            $member = $item['member'];
            $rows .= '<tr><td>' . ($index + 1) . '</td><td><strong>'
                . $this->userLink($member->userId(), $member->displayName()) . '</strong>'
                . '<span class="ojp-table-subvalue">'
                . $this->escape($member->userLogin()) . '</span></td><td>'
                . $item['solved'] . '</td><td>' . $item['attempts']
                . '</td><td>' . $this->escape(
                    $item['last'] === '' ? '-' : $item['last']
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">순위를 계산할 구성원이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>순위</th><th>구성원</th><th>해결</th>'
            . '<th>제출</th><th>마지막 정답</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function userLink(?int $userId, string $label): string
    {
        if ($userId === null || $userId < 1) {
            return $this->escape($label);
        }

        return '<a class="ojp-row-link" href="/users/' . $userId . '">'
            . $this->escape($label) . '</a>';
    }

    private function visibilityLabel(string $visibility): string
    {
        return match ($visibility) {
            'public' => '공개',
            'code' => '참여 코드',
            default => '비공개',
        };
    }

    private function resultLabel(int $code): string
    {
        $result = $this->judgeResults->find($code);

        return '<span class="' . $this->escape($result->badgeClass()) . '">'
            . $this->escape($result->label()) . '</span>';
    }
}

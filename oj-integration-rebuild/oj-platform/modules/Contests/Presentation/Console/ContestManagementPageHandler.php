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
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use DomainException;

final class ContestManagementPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ContestHtml $html,
        private readonly ChoiceProvider $groupChoices,
        private readonly Clock $clock,
        private readonly PaginationView $pagination
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $key = $route->route()->key();
        if (in_array($key, ['console.contests.index', 'console.contests.deleted'], true)) {
            $archived = $key === 'console.contests.deleted';
            $title = $archived ? '보관된 대회' : '대회·평가 관리';
            $basePath = $archived ? '/oj-console/contests/deleted' : '/oj-console/contests';
            $addAction = ! $archived && $this->contests->canCreate($actorId)
                ? '<a class="ojp-button ojp-button--primary"'
                    . ' href="/oj-console/contests/new">대회 추가</a>'
                : '';
            $allContests = array_values(array_filter(
                $this->contests->managedBy($actorId),
                static fn (Contest $contest): bool => $contest->isArchived() === $archived
            ));
            $page = max(1, (int) $request->queryString('page', '1'));
            $pageCount = max(1, (int) ceil(count($allContests) / self::PAGE_SIZE));
            $page = min($page, $pageCount);
            $contests = array_slice($allContests, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            $archivableIds = [];
            foreach ($contests as $item) {
                try {
                    if ($this->contests->canArchive($actorId, $this->contests->settings($item->id()))) {
                        $archivableIds[] = $item->id();
                    }
                } catch (DomainException) {
                    // Legacy contests without platform settings remain visible for inspection.
                }
            }
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">학습 공간</p><h1>' . $title . '</h1>'
                . '<p>운영 단계, 문제, 채점 방식과 공개 범위를 관리합니다.</p>'
                . '</div>' . $addAction . '</header>'
                . $this->html->timeSummary()
                . $this->html->contestTable(
                    $contests,
                    true,
                    [],
                    $archivableIds
                )
                . $this->pagination->links($page, $pageCount, $basePath);

            return $this->pages->respond(
                $title,
                $content,
                $archived ? 'contests.deleted' : 'contests.index',
                $request
            );
        }
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        $contest = null;
        $settings = null;
        if ($contestId > 0) {
            try {
                $contest = $this->contests->findManaged($contestId, $actorId);
                $settings = $this->contests->settings($contestId);
            } catch (DomainException $error) {
                return $this->pages->respond(
                    '대회 관리',
                    '<div class="ojp-empty-state"><h1>대회를 관리할 수 없습니다.</h1><p>'
                        . $this->html->escape($error->getMessage()) . '</p></div>',
                    'contests.index',
                    $request,
                    403
                );
            }
        }
        if ($key === 'console.contests.delete' && $contest !== null) {
            if (! $this->contests->canArchive($actorId, $settings)) {
                return $this->pages->respond(
                    '대회 보관',
                    '<div class="ojp-empty-state">대회를 보관하거나 복원할 권한이 없습니다.</div>',
                    'contests.index',
                    $request,
                    403
                );
            }
            $restore = $contest->isArchived();
            $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
                . '대회 관리</p><h1>' . ($restore ? '대회 복원' : '대회 보관')
                . '</h1><p>채점 제출과 참가 기록은 삭제하지 않습니다.</p></div></header>'
                . '<form class="ojp-form" method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_contest_lifecycle_command')
                . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
                . '<input type="hidden" name="operation" value="'
                . ($restore ? 'restore' : 'archive') . '">'
                . '<button class="ojp-button ojp-button--primary" type="submit">'
                . ($restore ? '복원' : '보관') . '</button></form>';

            return $this->pages->respond(
                $restore ? '대회 복원' : '대회 보관',
                $content,
                $restore ? 'contests.deleted' : 'contests.index',
                $request
            );
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '학습 공간</p><h1>' . ($contest === null ? '대회 추가' : '대회 수정')
            . '</h1><p>대회 기본 정보와 문제, 담당자, 채점 정책을 함께 저장합니다.</p>'
            . '</div></header>'
            . ($contest === null ? '' : $this->html->tabs($contestId, 'edit', true))
            . $this->html->timeSummary($contest)
            . $this->form($contest, $settings, $actorId)
            . ($contest === null
                || $this->contests->currentStage($contest) === 'archived'
                    ? ''
                    : $this->participants($contestId));

        return $this->pages->respond(
            $contest === null ? '대회 추가' : '대회 수정',
            $content,
            $contest === null ? 'contests.new' : 'contests.index',
            $request
        );
    }

    private function form(
        ?Contest $contest,
        ?ContestSettings $settings,
        int $actorUserId
    ): string {
        $contestId = $contest?->id() ?? 0;
        $problems = $contestId > 0 ? $this->contests->problems($contestId) : [];
        $problemIds = implode(', ', array_map(
            static fn ($problem): int => $problem->problemId(),
            $problems
        ));
        $problemScores = implode("\n", array_map(
            static fn ($problem): string =>
                $problem->problemId() . ':' . $problem->score(),
            $problems
        ));
        $roles = $contestId > 0
            ? $this->contests->managerRoleLogins($contestId)
            : [];
        $roleLabels = [
            'manager' => '공동 운영자',
            'proctor' => '시험 감독자',
            'reviewer' => '결과 검토자',
        ];
        $managerRoles = implode("\n", array_map(
            static fn (string $login, string $role): string =>
                $login . ':' . ($roleLabels[$role] ?? $role),
            array_keys($roles),
            array_values($roles)
        ));
        $defaultStart = $this->clock->now()->modify('+1 day');
        $start = $contest?->startsAt()
            ?? $defaultStart->format('Y-m-d H:i:s');
        $end = $contest?->endsAt()
            ?? $defaultStart->modify('+2 hours')->format('Y-m-d H:i:s');
        $stage = $contest === null
            ? 'preparation'
            : $this->contests->currentStage($contest);
        $canChangeManagers = $settings === null
            || $this->contests->canChangeManagers($actorUserId, $settings);
        $canArchive = $settings !== null
            && $this->contests->canArchive($actorUserId, $settings);
        $canStart = $contest !== null
            && $settings !== null
            && $stage === 'preparation'
            && $this->contests->canConfigure($actorUserId, $contestId, $settings);
        $archiveAction = $contest === null || ! $canArchive
            ? ''
            : '<a class="ojp-button" href="/oj-console/contests/'
                . $contestId . '/delete">'
                . ($stage === 'archived' ? '대회 복원' : '대회 보관') . '</a>';
        $formStart = '<form class="ojp-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_save')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">';
        $state = [
            'title' => $contest?->title() ?? '',
            'description' => $contest?->description() ?? '',
            'starts_at' => $start,
            'ends_at' => $end,
            'visibility' => $settings?->visibility() ?? 'private',
            'approval_required' => $settings?->requiresApproval() ? 1 : 0,
            'group_id' => $settings?->groupId(),
            'result_visibility' => $settings?->resultVisibility() ?? 'participants',
            'ranking_visibility' => $settings?->rankingVisibility() ?? 'private',
            'wrong_penalty_points' => $settings?->wrongPenaltyPoints() ?? 5,
            'time_score_percent' => $settings?->timeScorePercent() ?? 10,
            'partial_score_policy' => $settings?->partialScorePolicy() ?? 'none',
            'scoring_policy' => $settings?->scoringPolicy() ?? 'standard_score_v1',
            'freeze_minutes' => $settings?->freezeMinutes() ?? 0,
            'blockly_profile_key' => $settings?->blocklyProfileKey(),
            'allow_ai_feedback' => $settings === null || $settings->allowsAiFeedback() ? 1 : 0,
            'wrong_answer_policy' => $settings?->wrongAnswerPolicy() ?? 'penalty_before_accept',
            'ip_policy' => $settings?->ipPolicy() ?? 'none',
            'problem_ids' => $problemIds,
            'problem_scores' => $problemScores,
            'manager_role_logins' => $managerRoles,
        ];

        if ($stage === 'archived') {
            return '<div class="ojp-empty-state"><h2>보관된 대회입니다.</h2>'
                . '<p>설정을 변경하려면 먼저 대회를 복원해야 합니다.</p>'
                . $archiveAction . '</div>';
        }
        if ($stage === 'running') {
            return '<div class="ojp-notice"><strong>진행 중</strong>'
                . '<p>참여자에게 이미 공개된 설정은 잠기며 종료 시각만 연장할 수 있습니다.</p></div>'
                . $formStart
                . $this->hiddenState($state, ['ends_at'])
                . '<div class="ojp-form-grid">'
                . $this->input('ends_at', '종료 시각', $end, true, 'datetime-local')
                . '</div><div class="ojp-command-group"><button '
                . 'class="ojp-button ojp-button--primary" type="submit">'
                . '종료 시각 연장</button>' . $archiveAction . '</div></form>';
        }
        if ($stage === 'ended') {
            return '<div class="ojp-notice"><strong>종료됨</strong>'
                . '<p>기록 보호를 위해 결과와 순위의 공개 범위만 변경할 수 있습니다.</p></div>'
                . $formStart
                . $this->hiddenState(
                    $state,
                    ['result_visibility', 'ranking_visibility']
                )
                . '<div class="ojp-form-grid">'
                . $this->select(
                    'result_visibility',
                    '결과 공개',
                    ['private' => '비공개', 'participants' => '참여자', 'public' => '전체 공개'],
                    (string) $state['result_visibility']
                )
                . $this->select(
                    'ranking_visibility',
                    '순위 공개',
                    ['private' => '비공개', 'live' => '공개', 'final' => '종료 후 공개'],
                    (string) $state['ranking_visibility']
                )
                . '</div><div class="ojp-command-group"><button '
                . 'class="ojp-button ojp-button--primary" type="submit">'
                . '공개 범위 저장</button>' . $archiveAction . '</div></form>';
        }

        return ($contest === null ? '' : '<div class="ojp-notice"><strong>준비</strong>'
                . '<p>준비 단계에서는 제출을 받지 않으며, 시작 시각이 되면 자동으로 진행 중으로 전환됩니다.</p>'
                . ($canStart ? $this->startAction($contestId) : '')
                . '</div>')
            . $formStart
            . '<div class="ojp-form-grid">'
            . $this->input('title', '대회 이름', (string) $state['title'], true)
            . $this->input('starts_at', '시작 시각', $start, true, 'datetime-local')
            . $this->input('ends_at', '종료 시각', $end, true, 'datetime-local')
            . $this->select(
                'visibility',
                '공개 범위',
                $this->visibilityOptions((string) $state['visibility']),
                (string) $state['visibility']
            )
            . $this->groupSelection(
                $actorUserId,
                isset($state['group_id']) ? (int) $state['group_id'] : null
            )
            . '<label class="ojp-check"><input type="checkbox" name="approval_required" value="1"'
            . ((int) $state['approval_required'] === 1 ? ' checked' : '')
            . '><span>참가 승인 필요</span></label>'
            . $this->select(
                'result_visibility',
                '결과 공개',
                ['private' => '비공개', 'participants' => '참여자', 'public' => '전체 공개'],
                (string) $state['result_visibility']
            )
            . $this->select(
                'ranking_visibility',
                '순위 공개',
                ['private' => '비공개', 'live' => '실시간 공개', 'final' => '종료 후 공개'],
                (string) $state['ranking_visibility']
            )
            . $this->select(
                'scoring_policy',
                '채점 방식',
                ['standard_score_v1' => '표준 점수', 'icpc_v1' => 'ICPC', 'oi_v1' => 'OI'],
                (string) $state['scoring_policy']
            )
            . $this->select(
                'partial_score_policy',
                '부분 점수',
                ['none' => '사용 안 함', 'pass_rate_correctness_base_v1' => '기본 점수 × 통과율'],
                (string) $state['partial_score_policy']
            )
            . $this->input(
                'wrong_penalty_points',
                '오답 감점',
                (string) $state['wrong_penalty_points'],
                true,
                'number'
            )
            . $this->input(
                'time_score_percent',
                '시간 점수 비율(%)',
                (string) $state['time_score_percent'],
                true,
                'number'
            )
            . $this->input(
                'freeze_minutes',
                '순위 동결(분)',
                (string) $state['freeze_minutes'],
                true,
                'number'
            )
            . $this->select(
                'blockly_profile_key',
                'Blockly 도구 모음',
                ['' => '사용 안 함', 'intro' => '입문', 'standard' => '표준', 'extended' => '확장'],
                (string) ($state['blockly_profile_key'] ?? '')
            )
            . $this->select(
                'ip_policy',
                'IP 정책',
                ['none' => '확인 안 함', 'first_access' => '첫 제출 IP 고정', 'managed' => '담당자 지정'],
                (string) $state['ip_policy']
            )
            . '</div>'
            . '<input type="hidden" name="wrong_answer_policy" value="'
            . $this->html->escape((string) $state['wrong_answer_policy']) . '">'
            . '<label class="ojp-field"><span class="ojp-label">설명</span>'
            . '<textarea class="ojp-textarea" name="description" rows="5">'
            . $this->html->escape((string) $state['description']) . '</textarea></label>'
            . $this->textarea('problem_ids', '대회 문제 번호', $problemIds)
            . $this->textarea('problem_scores', '문제별 배점', $problemScores)
            . $this->textarea(
                'manager_role_logins',
                '담당자 로그인 아이디와 역할',
                $managerRoles,
                ! $canChangeManagers
            )
            . (! $canChangeManagers
                ? '<p class="ojp-field-help">담당자 구성은 대회 소유자 또는 전역 관리자만 변경할 수 있습니다.</p>'
                : '')
            . '<label class="ojp-check"><input type="checkbox"'
            . ' name="allow_ai_feedback" value="1"'
            . ((int) $state['allow_ai_feedback'] === 1 ? ' checked' : '')
            . '> AI 피드백 허용</label>'
            . '<div class="ojp-command-group"><button class="ojp-button ojp-button--primary"'
            . ' type="submit">대회 저장</button>' . $archiveAction . '</div></form>';
    }

    /**
     * @param array<string, int|string|null> $state
     * @param list<string> $excluded
     */
    private function hiddenState(array $state, array $excluded): string
    {
        $html = '';
        foreach ($state as $name => $value) {
            if (in_array($name, $excluded, true)) {
                continue;
            }
            $encodedName = $this->html->escape($name);
            $encodedValue = $this->html->escape((string) ($value ?? ''));
            if (str_contains((string) ($value ?? ''), "\n")) {
                $html .= '<textarea hidden name="' . $encodedName . '">'
                    . $encodedValue . '</textarea>';
                continue;
            }
            $html .= '<input type="hidden" name="' . $encodedName
                . '" value="' . $encodedValue . '">';
        }

        return $html;
    }

    private function groupSelection(int $userId, ?int $selectedId): string
    {
        if (! $this->groupChoices->isAvailable()) {
            $hidden = $selectedId === null
                ? ''
                : '<input type="hidden" name="group_id" value="'
                    . $selectedId . '">';

            return $hidden . '<div class="ojp-notice ojp-notice--info">'
                . '<strong>그룹 기능이 꺼져 있습니다.</strong>'
                . '<p>기존 연결은 유지되며 그룹 기능을 켠 뒤 변경할 수 있습니다.</p></div>';
        }
        $included = $selectedId === null ? [] : [$selectedId];
        $choices = $this->groupChoices->choicesFor($userId, $included);
        $options = '<option value="">연결하지 않음</option>';
        foreach ($choices as $groupId => $name) {
            $options .= '<option value="' . (int) $groupId . '"'
                . ($selectedId === (int) $groupId ? ' selected' : '') . '>'
                . $this->html->escape($name) . '</option>';
        }

        return '<label class="ojp-field"><span class="ojp-label">연결 그룹</span>'
            . '<select class="ojp-select" name="group_id">'
            . $options . '</select></label>';
    }

    /** @return array<string, string> */
    private function visibilityOptions(string $current): array
    {
        $options = ['public' => '공개', 'private' => '비공개'];
        if ($this->groupChoices->isAvailable() || $current === 'group_only') {
            $options['group_only'] = '연결 그룹';
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private function select(
        string $name,
        string $label,
        array $options,
        string $current
    ): string {
        $html = '<label class="ojp-field"><span class="ojp-label">'
            . $label . '</span><select class="ojp-select" name="'
            . $this->html->escape($name) . '">';
        foreach ($options as $value => $optionLabel) {
            $html .= $this->option($value, $optionLabel, $current);
        }

        return $html . '</select></label>';
    }

    private function startAction(int $contestId): string
    {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_lifecycle_command')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
            . '<input type="hidden" name="operation" value="start">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '지금 시작</button></form>';
    }

    private function participants(int $contestId): string
    {
        $rows = '';
        foreach (['active' => '참여', 'pending' => '승인 대기'] as $status => $label) {
            foreach ($this->contests->participantIdentities($contestId, $status) as $participant) {
                $operation = $status === 'pending' ? 'approve' : 'remove';
                $rows .= '<tr><td>'
                    . $this->html->escape($participant->displayName())
                    . '</td><td>' . $this->html->escape($participant->userLogin())
                    . '</td><td>' . $label . '</td><td>'
                    . $this->participantAction(
                        $contestId,
                        $participant->userId(),
                        $operation,
                        $operation === 'approve' ? '승인' : '제외'
                    ) . '</td></tr>';
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">참여자와 승인 대기자가 없습니다.</td></tr>';
        }

        return '<section class="ojp-section"><header class="ojp-section-heading">'
            . '<div><h2>참여자 관리</h2><p>로그인 아이디로 검색해 추가하거나 '
            . '참여 요청을 승인합니다.</p></div></header>'
            . '<form class="ojp-filter-bar" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_participation_command')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
            . '<input type="hidden" name="operation" value="add">'
            . '<label class="ojp-field"><span class="ojp-label">로그인 아이디</span>'
            . '<input class="ojp-input" type="search" '
            . 'name="target_user_login" autocomplete="off" required></label>'
            . '<button class="ojp-button" type="submit">참여자 추가</button></form>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>이름</th><th>로그인 아이디</th><th>상태</th>'
            . '<th>작업</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div></section>';
    }

    private function participantAction(
        int $contestId,
        int $userId,
        string $operation,
        string $label
    ): string {
        return '<form class="ojp-inline-form" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_contest_participation_command')
            . '<input type="hidden" name="contest_id" value="' . $contestId . '">'
            . '<input type="hidden" name="target_user_id" value="' . $userId . '">'
            . '<input type="hidden" name="operation" value="' . $operation . '">'
            . '<button class="ojp-button ojp-button--small" type="submit">'
            . $label . '</button></form>';
    }
    private function input(
        string $name,
        string $label,
        string $value,
        bool $required,
        string $type = 'text'
    ): string {
        $formatted = $type === 'datetime-local'
            ? str_replace(' ', 'T', substr($value, 0, 16))
            : $value;

        return '<label class="ojp-field"><span class="ojp-label">' . $label
            . '</span><input class="ojp-input" type="' . $type . '" name="'
            . $name . '" value="' . $this->html->escape($formatted) . '"'
            . ($required ? ' required' : '') . '></label>';
    }

    private function textarea(
        string $name,
        string $label,
        string $value,
        bool $readOnly = false
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">' . $label
            . '</span><textarea class="ojp-textarea" name="' . $name
            . '" rows="3"' . ($readOnly ? ' readonly' : '') . '>'
            . $this->html->escape($value) . '</textarea></label>';
    }

    private function option(string $value, string $label, string $current): string
    {
        return '<option value="' . $value . '"'
            . ($value === $current ? ' selected' : '') . '>' . $label . '</option>';
    }
}

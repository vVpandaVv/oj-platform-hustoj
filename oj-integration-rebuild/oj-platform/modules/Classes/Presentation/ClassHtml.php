<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassReport;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Ui\Icon\IconName;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;

final class ClassHtml
{
    public function __construct(
        private readonly JudgeResults $judgeResults,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter(),
        private readonly ?IconRenderer $icons = null,
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

    private function userLink(?int $userId, string $label): string
    {
        if ($userId === null || $userId < 1) {
            return $this->escape($label);
        }

        return '<a class="ojp-row-link" href="/users/' . $userId . '">'
            . $this->escape($label) . '</a>';
    }

    public function copyLink(int $classId): string
    {
        return '<a class="ojp-button" href="/oj-console/classes/new?copy_from='
            . $classId . '">' . ($this->icons?->render(IconName::Copy) ?? '')
            . '<span>수업 복사</span></a>';
    }

    /** @param list<LearningClass> $classes */
    public function classTable(array $classes, bool $admin, bool $canCopy = false, ?callable $canArchive = null): string
    {
        $canCopy = $admin && $canCopy;
        $showActions = $admin && ($canCopy || $canArchive !== null);
        $rows = '';
        foreach ($classes as $class) {
            $path = $admin
                ? '/oj-console/classes/' . $class->id() . '/edit'
                : '/classes/' . $class->id();
            $status = $class->isDeleted()
                ? '보관'
                : ($class->isEnabled() ? '운영' : '중지');
            $rows .= '<tr><td>' . $class->id() . '</td><td><a class="ojp-row-link" href="'
                . $path . '">' . $this->escape($class->name()) . '</a></td>'
                . '<td>' . $this->visibilityLabel($class->visibility()) . '</td>'
                . '<td>' . $this->escape($class->startsAt() ?? '상시') . '</td>'
                . '<td>' . $this->escape($class->endsAt() ?? '제한 없음') . '</td>'
                . '<td><span class="ojp-status-badge">'
                . $status . '</span></td>'
                . ($showActions ? '<td><div class="ojp-command-group">'
                    . ($canCopy ? $this->copyLink($class->id()) : '')
                    . ($canArchive !== null && $canArchive($class) ? $this->lifecycleLink($class) : '')
                    . '</div></td>' : '')
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="' . ($showActions ? 7 : 6) . '">표시할 수업이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>번호</th><th>수업</th><th>공개</th>'
            . '<th>시작</th><th>종료</th><th>상태</th>'
            . ($showActions ? '<th>관리</th>' : '') . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    public function lifecycleLink(LearningClass $class): string
    {
        return '<a class="ojp-button' . ($class->isDeleted() ? '' : ' ojp-button--danger')
            . '" href="/oj-console/classes/' . $class->id() . '/delete">'
            . ($class->isDeleted() ? '복원' : '보관') . '</a>';
    }

    public function tabs(int $classId, string $current, bool $admin): string
    {
        $base = $admin
            ? '/oj-console/classes/' . $classId
            : '/classes/' . $classId;
        $items = $admin
            ? [
                'edit' => [$base . '/edit', '수업 설정'],
                'participants' => [$base . '/participants', '참여자'],
                'submissions' => [$base . '/submissions', '제출 현황'],
                'progress' => [$base . '/progress', '학습 현황'],
            ]
            : [
                'show' => [$base, '수업 문제'],
                'submissions' => [$base . '/submissions', '내 제출'],
                'progress' => [$base . '/progress', '학습 현황'],
            ];
        $html = '<nav class="ojp-tabs" aria-label="수업 메뉴">';
        foreach ($items as $key => [$path, $label]) {
            $html .= '<a class="ojp-tab'
                . ($key === $current ? ' is-active' : '')
                . '" href="' . $path . '">' . $label . '</a>';
        }

        return $html . '</nav>';
    }

    public function reportTable(
        ClassReport $report,
        string $sort = 'progress-desc',
        string $action = '',
        ?string $viewerLogin = null
    ): string {
        $sortOptions = [
            'progress-desc' => '해결 수 높은 순',
            'progress-asc' => '해결 수 낮은 순',
            'name-asc' => '이름 순',
        ];
        $sort = array_key_exists($sort, $sortOptions) ? $sort : 'progress-desc';
        $submissions = [];
        foreach ($report->submissions() as $submission) {
            $submissions[$submission->userLogin()][$submission->problemId()][]
                = $submission;
        }
        $participantNames = [];
        $approvedAtByLogin = [];
        foreach ($report->participants() as $participant) {
            $participantNames[$participant->userLogin()]
                = $participant->displayName();
            $approvedAt = $report->participantApprovedAt()[$participant->userId()] ?? null;
            if ($approvedAt !== null) {
                $approvedAtByLogin[$participant->userLogin()] = $approvedAt;
            }
        }
        $isCreditable = static function (SubmissionProjection $submission) use ($approvedAtByLogin): bool {
            $approvedAt = $approvedAtByLogin[$submission->userLogin()] ?? null;

            return $approvedAt === null || $submission->submittedAt() >= $approvedAt;
        };
        $firstAccepted = [];
        foreach ($report->problems() as $problem) {
            $accepted = array_values(array_filter(
                $report->submissions(),
                static fn (SubmissionProjection $submission): bool =>
                    $submission->problemId() === $problem->problemId()
                    && $submission->resultCode() === 4
                    && $submission->isValid()
                    && $isCreditable($submission)
            ));
            usort(
                $accepted,
                static fn (SubmissionProjection $left, SubmissionProjection $right): int =>
                    strcmp($left->submittedAt(), $right->submittedAt())
            );
            $firstAccepted[$problem->problemId()] = $accepted[0] ?? null;
        }

        $navigation = ProblemNavigationContext::class($report->class()->id());
        $head = '<th scope="col">참여자</th><th scope="col">푼 문제 수'
            . '<span class="ojp-table-subvalue">정답 / 전체</span></th>';
        foreach ($report->problems() as $problem) {
            $first = $firstAccepted[$problem->problemId()] ?? null;
            $firstLabel = '';
            if ($first instanceof SubmissionProjection) {
                $firstName = $participantNames[$first->userLogin()]
                    ?? $first->userLogin();
                $firstLabel = '<span class="ojp-table-subvalue">최초 '
                    . $this->escape($firstName) . ' · '
                    . $this->escape($this->date($first->submittedAt())) . '</span>';
            }
            $head .= '<th scope="col"><a href="'
                . $this->escape($navigation->detailUrl($problem->problemId())) . '">'
                . $problem->problemId() . '</a>' . $firstLabel . '</th>';
        }
        $reportRows = [];
        foreach ($report->participants() as $participant) {
            $login = $participant->userLogin();
            $solved = 0;
            $cells = '';
            foreach ($report->problems() as $problem) {
                $attempts = $submissions[$login][$problem->problemId()] ?? [];
                $accepted = array_values(array_filter(
                    $attempts,
                    static fn (SubmissionProjection $submission): bool =>
                        $submission->resultCode() === 4
                        && $submission->isValid()
                        && $isCreditable($submission)
                ));
                if ($accepted !== []) {
                    ++$solved;
                    $label = ($firstAccepted[$problem->problemId()] ?? null)?->userLogin() === $login
                        ? '첫 해결'
                        : '해결';
                    $tone = 'success';
                } elseif ($attempts !== []) {
                    $label = '시도 ' . count($attempts);
                    $tone = 'warning';
                } else {
                    $label = '미시도';
                    $tone = 'neutral';
                }
                $cells .= '<td><span class="ojp-status-badge ojp-status-badge--'
                    . $tone . '">' . $label . '</span></td>';
            }
            $reportRows[] = [
                'participant' => $participant,
                'login' => $login,
                'solved' => $solved,
                'cells' => $cells,
            ];
        }
        $reportRows = $this->sortReportRows($reportRows, $sort);
        $rows = '';
        foreach ($reportRows as $row) {
            $participant = $row['participant'];
            $current = $viewerLogin !== null && $row['login'] === $viewerLogin;
            $rows .= '<tr class="ojp-ranking-row'
                . ($current ? ' is-current' : '') . '"><td><strong>'
                . $this->userLink($participant->userId(), $participant->displayName())
                . ($current ? ' (나)' : '')
                . (! $current && ($this->features?->isEnabled('messaging') ?? false)
                    ? ' <a class="ojp-button ojp-button--small" href="/messages/new/' . $participant->userId() . '">쪽지</a>' : '')
                . '</strong><span class="ojp-table-subvalue">'
                . $this->escape($row['login']) . '</span></td>'
                . '<td class="ojp-class-progress-count"><strong>' . $row['solved'] . ' / '
                . count($report->problems()) . '</strong></td>' . $row['cells'] . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="' . (count($report->problems()) + 2)
                . '">참여자 또는 제출 기록이 없습니다.</td></tr>';
        }

        return ($action === '' ? '' : $this->reportToolbar(
            $action,
            $sort,
            $sortOptions
        )) . '<div class="ojp-table-wrap ojp-class-progress-scroll" role="region"'
            . ' aria-label="수업 학습 현황" tabindex="0">'
            . '<table class="ojp-table ojp-table--matrix ojp-class-progress-table">'
            . '<thead><tr>' . $head . '</tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    public function submissionTable(
        ClassReport $report,
        bool $detailsAllowed = true,
        bool $selectable = false
    ): string
    {
        $classId = $report->class()->id();
        $rows = '';
        foreach ($report->submissions() as $submission) {
            $id = $submission->submissionId();
            $submissionCell = $detailsAllowed
                ? '<a class="ojp-row-link" href="/submissions/' . $id
                    . '?class_id=' . $classId . '">' . $id . '</a>'
                : '<strong>' . $id . '</strong>';
            $rows .= '<tr>' . ($selectable
                ? '<td class="ojp-table-check"><input type="checkbox"'
                    . ' name="submission_ids[]" value="' . $id
                    . '" aria-label="제출 ' . $id . ' 선택"></td>'
                : '') . '<td>' . $submissionCell . '</td><td>'
                . $this->escape($submission->userLogin()) . '</td><td><a href="/problems/'
                . $submission->problemId() . '?class_id=' . $classId . '">'
                . $submission->problemId()
                . '</a></td><td>' . $this->resultLabel($submission->resultCode())
                . '</td><td>' . $this->escape($this->date($submission->submittedAt()))
                . '</td></tr>';
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

    public function resultLabel(int $code): string
    {
        $result = $this->judgeResults->find($code);

        return '<span class="' . $this->escape($result->badgeClass()) . '">'
            . $this->escape($result->label()) . '</span>';
    }

    /**
     * @param list<array{participant: UserIdentity, login: string, solved: int, cells: string}> $rows
     * @return list<array{participant: UserIdentity, login: string, solved: int, cells: string}>
     */
    private function sortReportRows(array $rows, string $sort): array
    {
        usort(
            $rows,
            static function (array $left, array $right) use ($sort): int {
                $compared = match ($sort) {
                    'progress-asc' => $left['solved'] <=> $right['solved'],
                    'name-asc' => strnatcasecmp(
                        $left['participant']->displayName(),
                        $right['participant']->displayName()
                    ),
                    default => $right['solved'] <=> $left['solved'],
                };

                return $compared
                    ?: strnatcasecmp(
                        $left['participant']->displayName(),
                        $right['participant']->displayName()
                    )
                    ?: strcmp($left['login'], $right['login']);
            }
        );

        return $rows;
    }

    /** @param array<string, string> $options */
    private function reportToolbar(
        string $action,
        string $sort,
        array $options
    ): string {
        $optionHtml = '';
        foreach ($options as $value => $label) {
            $optionHtml .= '<option value="' . $this->escape($value) . '"'
                . ($value === $sort ? ' selected' : '') . '>'
                . $this->escape($label) . '</option>';
        }

        return '<form class="ojp-filter-bar ojp-ranking-toolbar" method="get" action="'
            . $this->escape($action) . '"><label class="ojp-field">'
            . '<span class="ojp-label">표시 순서</span>'
            . '<select class="ojp-select" name="sort">' . $optionHtml
            . '</select></label><button class="ojp-button" type="submit">'
            . '정렬</button></form>';
    }

    private function visibilityLabel(string $visibility): string
    {
        return match ($visibility) {
            'public' => '공개',
            'password' => '비밀번호',
            'group_only' => '연결 그룹',
            default => '비공개',
        };
    }
}

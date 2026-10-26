<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation;

use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblemResult;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestRankingRow;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestReport;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use DateTimeImmutable;

final class ContestHtml
{
    public function __construct(
        private readonly JudgeResults $judgeResults,
        private readonly Clock $clock,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
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
     * @param list<Contest> $contests
     * @param list<int> $editableContestIds
     * @param list<int> $archivableContestIds
     */
    public function contestTable(
        array $contests,
        bool $admin,
        array $editableContestIds = [],
        array $archivableContestIds = []
    ): string
    {
        $rows = '';
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $editable = array_fill_keys($editableContestIds, true);
        $archivable = array_fill_keys($archivableContestIds, true);
        foreach ($contests as $contest) {
            $path = $admin
                ? '/oj-console/contests/' . $contest->id() . '/edit'
                : '/contests/' . $contest->id();
            $editAction = $admin || isset($editable[$contest->id()])
                ? '<a class="ojp-button ojp-button--small" href="/oj-console/contests/'
                    . $contest->id() . '/edit">수정</a>'
                : '<span class="ojp-muted">-</span>';
            if ($admin) {
                $editAction .= '<a class="ojp-button ojp-button--small" href="/oj-console/contests/'
                    . $contest->id() . '/submissions">제출 현황</a>';
                if (isset($archivable[$contest->id()])) {
                    $editAction .= '<a class="ojp-button ojp-button--small" href="/oj-console/contests/'
                        . $contest->id() . '/delete">'
                        . ($contest->isArchived() ? '복원' : '보관') . '</a>';
                }
                $editAction = '<div class="ojp-command-group">' . $editAction . '</div>';
            }
            $rows .= '<tr><td>' . $contest->id() . '</td><td>'
                . '<a class="ojp-row-link" href="' . $path . '">'
                . $this->escape($contest->title()) . '</a></td><td>'
                . $this->stageLabel($contest->stage($now)) . '</td><td>'
                . $this->escape($contest->startsAt()) . '</td><td>'
                . $this->escape($contest->endsAt()) . '</td><td>'
                . $editAction . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">표시할 대회가 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>번호</th><th>대회</th><th>단계</th>'
            . '<th>시작</th><th>종료</th><th>작업</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    public function timeSummary(?Contest $contest = null): string
    {
        $now = $this->clock->now();
        $transitionAt = $this->transitionAt($contest, $now);
        $html = '<section class="ojp-section" aria-label="대회 시간 기준"'
            . ' data-ojp-server-clock data-server-time="'
            . $this->escape($now->format(DATE_ATOM)) . '" data-server-timezone="'
            . $this->escape($now->getTimezone()->getName())
            . '" data-server-offset="' . $now->getOffset() . '"'
            . ($transitionAt === null
                ? ''
                : ' data-contest-transition="' . $this->escape($transitionAt) . '"')
            . '>'
            . '<dl class="ojp-description-grid">'
            . '<div><dt>현재 서버 시각</dt><dd><time'
            . ' data-ojp-server-clock-value datetime="'
            . $this->escape($now->format(DATE_ATOM)) . '">'
            . $this->escape($now->format('Y-m-d H:i:s'))
            . '</time><span class="ojp-sr-only">, 실시간 갱신</span></dd></div>'
            . '<div><dt>시간대</dt><dd>'
            . $this->escape($this->timezoneLabel($now)) . '</dd></div>';
        if ($contest !== null) {
            $stage = $contest->stage($now->format('Y-m-d H:i:s'));
            $html .= '<div><dt>시작 시각</dt><dd>'
                . $this->escape($contest->startsAt())
                . '</dd></div><div><dt>종료 시각</dt><dd>'
                . $this->escape($contest->endsAt())
                . '</dd></div><div><dt>현재 단계</dt><dd>'
                . $this->escape($this->stageLabel($stage)) . '</dd></div>';
        }

        return $html . '<div class="ojp-description-grid__wide"><dt>판정 기준</dt>'
            . '<dd>대회 단계와 제출 허용 여부는 현재 서버 시각을 기준으로 '
            . '판정합니다.</dd></div></dl></section>';
    }

    private function transitionAt(
        ?Contest $contest,
        DateTimeImmutable $now
    ): ?string {
        if ($contest === null) {
            return null;
        }
        $stage = $contest->stage($now->format('Y-m-d H:i:s'));
        $value = match ($stage) {
            'preparation' => $contest->startsAt(),
            'running' => $contest->endsAt(),
            default => '',
        };
        if ($value === '') {
            return null;
        }
        $transition = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            $now->getTimezone()
        );
        if (! $transition instanceof DateTimeImmutable) {
            return null;
        }
        if ($stage === 'running') {
            $transition = $transition->modify('+1 second');
        }

        return $transition->format(DATE_ATOM);
    }

    private function timezoneLabel(DateTimeImmutable $now): string
    {
        return $now->getTimezone()->getName()
            . ' (UTC' . $now->format('P') . ')';
    }

    public function tabs(int $contestId, string $current, bool $admin): string
    {
        $base = $admin
            ? '/oj-console/contests/' . $contestId
            : '/contests/' . $contestId;
        $items = $admin
            ? [
                'edit' => [$base . '/edit', '대회 설정'],
                'submissions' => [$base . '/submissions', '제출 현황'],
                'ranking' => [$base . '/ranking', '순위'],
                'statistics' => [$base . '/statistics', '통계'],
                'ip' => [$base . '/ip-check', 'IP 확인'],
            ]
            : [
                'show' => [$base, '대회 문제'],
                'submissions' => [$base . '/submissions', '내 제출'],
                'ranking' => [$base . '/ranking', '순위'],
                'statistics' => [$base . '/statistics', '통계'],
                'ip' => [$base . '/ip-check', 'IP 확인'],
            ];
        $html = '<nav class="ojp-tabs" aria-label="대회 메뉴">';
        foreach ($items as $key => [$path, $label]) {
            $html .= '<a class="ojp-tab'
                . ($key === $current ? ' is-active' : '')
                . '" href="' . $path . '">' . $label . '</a>';
        }

        return $html . '</nav>';
    }

    public function rankingTable(
        ContestReport $report,
        string $sort = 'rank-asc',
        string $action = '',
        ?string $viewerLogin = null
    ): string
    {
        $policy = $report->settings()->scoringPolicy();
        $sortOptions = $this->rankingSortOptions($policy);
        $sort = array_key_exists($sort, $sortOptions) ? $sort : 'rank-asc';
        $rankings = $this->sortRankings($report->rankings(), $sort);
        $toolbar = $action === '' ? '' : $this->rankingToolbar(
            $action,
            $sort,
            $sortOptions
        );
        $visibility = match ($report->settings()->rankingVisibility()) {
            'live' => '실시간 공개',
            'final' => '종료 후 공개',
            default => '비공개',
        };
        $aggregation = $report->isRankingFrozen()
            ? '순위 동결 · ' . $this->escape($this->date(
                $report->rankingFreezeCutoff()
            ))
                . '까지의 제출로 집계했습니다.'
            : '화면을 열 때 현재 유효 제출로 집계했습니다.';
        $summary = '<div class="ojp-notice ojp-notice--info">'
            . '<strong>' . $this->scoringLabel($policy) . '</strong> · '
            . $visibility . ' · ' . $aggregation
            . '</div>';
        $head = '<th>순위</th><th>참여자</th>';
        foreach ($report->problems() as $problem) {
            $navigation = ProblemNavigationContext::contest(
                $report->contest()->id(),
                $problem->number()
            );
            $head .= '<th class="ojp-ranking-problem-head"><a href="'
                . $this->escape($navigation->detailUrl($problem->problemId()))
                . '">' . $problem->numberLabel()
                . '</a><span>#' . $problem->problemId() . ' · '
                . $this->score($problem->score()) . '점</span></th>';
        }
        $head .= match ($policy) {
            ContestScoringPolicy::ICPC =>
                '<th>해결</th><th>벌점</th><th>마지막 해결</th>',
            ContestScoringPolicy::OI =>
                '<th>완전 해결</th><th>총점</th><th>벌점</th>',
            default => '<th>해결</th><th>총점</th><th>점수 완성</th>',
        };
        $rows = '';
        foreach ($rankings as $ranking) {
            $current = $viewerLogin !== null
                && $ranking->userLogin() === $viewerLogin;
            $rows .= '<tr class="ojp-ranking-row'
                . ($current ? ' is-current' : '') . '">'
                . '<td class="ojp-ranking-rank"><strong>'
                . $ranking->rank() . '</strong></td><td><strong>'
                . $this->escape($ranking->displayName())
                . ($current ? ' (나)' : '') . '</strong>'
                . '<span class="ojp-table-subvalue">'
                . $this->escape($ranking->userLogin()) . '</span>'
                . '<span class="ojp-table-subvalue">최근 제출 '
                . ($ranking->latestSubmittedAt() === null
                    ? '없음'
                    : $this->escape($this->date($ranking->latestSubmittedAt())))
                . '</span></td>';
            foreach ($report->problems() as $problem) {
                $rows .= $this->problemResultCell(
                    $policy,
                    $ranking->problemResults()[$problem->problemId()] ?? null
                );
            }
            $rows .= $this->rankingSummaryCells(
                $policy,
                $ranking,
                count($report->problems())
            ) . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td class="ojp-empty-cell" colspan="'
                . (count($report->problems()) + 5)
                . '">순위 기록이 없습니다.</td></tr>';
        }

        return $summary . $toolbar
            . '<div class="ojp-table-wrap">'
            . '<table class="ojp-table ojp-ranking-table">'
            . '<thead><tr>' . $head . '</tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    public function statisticsTable(ContestReport $report): string
    {
        $statistics = $report->statistics();
        $policy = $report->settings()->scoringPolicy();
        $scoreMetric = $policy === ContestScoringPolicy::ICPC
            ? ''
            : '<div class="ojp-metric"><dt>평균 점수</dt><dd>'
                . $this->score($statistics->averageScore()) . '</dd><span>'
                . $this->score($statistics->maximumScore())
                . '점 만점</span></div>';
        $metrics = '<dl class="ojp-metric-grid">'
            . '<div class="ojp-metric"><dt>참가자</dt><dd>'
            . $statistics->participantCount()
            . '</dd><span>승인 참가자</span></div>'
            . '<div class="ojp-metric"><dt>제출</dt><dd>'
            . $statistics->submissionCount()
            . '</dd><span>현재 유효 제출</span></div>'
            . '<div class="ojp-metric"><dt>평균 해결</dt><dd>'
            . $this->score($statistics->averageSolved())
            . '</dd><span>' . count($report->problems()) . '문제 기준</span></div>'
            . $scoreMetric
            . '<div class="ojp-metric"><dt>미제출</dt><dd>'
            . $statistics->noSubmissionCount()
            . '</dd><span>제출이 없는 참가자</span></div></dl>';
        $problemsById = [];
        foreach ($report->problems() as $problem) {
            $problemsById[$problem->problemId()] = $problem;
        }
        $rows = '';
        foreach ($statistics->problems() as $item) {
            $problem = $problemsById[$item->problemId()] ?? null;
            if ($problem === null) {
                continue;
            }
            $navigation = ProblemNavigationContext::contest(
                $report->contest()->id(),
                $problem->number()
            );
            $rows .= '<tr><td><a class="ojp-row-link" href="'
                . $this->escape($navigation->detailUrl($problem->problemId()))
                . '">' . $problem->numberLabel()
                . ' · 문제 ' . $problem->problemId() . '</a>'
                . '<span class="ojp-table-subvalue">배점 '
                . $this->score($problem->score()) . '점</span></td><td>'
                . $item->submissionCount() . '</td><td>'
                . $item->attemptedParticipantCount() . '</td><td>'
                . $item->acceptedParticipantCount() . '</td><td>'
                . number_format($item->acceptedRate(), 1) . '%</td><td>'
                . number_format($item->averageAttempts(), 1)
                . '회</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td class="ojp-empty-cell" colspan="6">'
                . '집계할 대회 문제가 없습니다.</td></tr>';
        }

        $aggregation = $report->isRankingFrozen()
            ? $this->escape($this->date(
                $report->rankingFreezeCutoff()
            )) . '까지의 제출'
            : '현재 유효 제출';

        return '<div class="ojp-notice ojp-notice--info"><strong>'
            . $this->scoringLabel($policy)
            . '</strong> · ' . $aggregation
            . '을 참가자 식별 정보 없이 문제별로 집계했습니다.</div>'
            . $metrics . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>문제</th><th>제출</th><th>도전자</th>'
            . '<th>정답자</th><th>정답률</th><th>평균 시도</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param array<string, string> $options */
    private function rankingToolbar(
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

    /** @return array<string, string> */
    private function rankingSortOptions(string $policy): array
    {
        if ($policy === ContestScoringPolicy::ICPC) {
            return [
                'rank-asc' => '공식 순위',
                'solved-desc' => '해결 많은 순',
                'penalty-asc' => '벌점 낮은 순',
                'name-asc' => '이름 순',
            ];
        }

        return [
            'rank-asc' => '공식 순위',
            'score-desc' => '총점 높은 순',
            'score-asc' => '총점 낮은 순',
            'solved-desc' => '해결 많은 순',
            ...($policy === ContestScoringPolicy::OI
                ? ['penalty-asc' => '벌점 낮은 순']
                : []),
            'name-asc' => '이름 순',
        ];
    }

    /**
     * @param list<ContestRankingRow> $rankings
     * @return list<ContestRankingRow>
     */
    private function sortRankings(array $rankings, string $sort): array
    {
        usort(
            $rankings,
            static function (
                ContestRankingRow $left,
                ContestRankingRow $right
            ) use ($sort): int {
                $compared = match ($sort) {
                    'score-desc' => $right->score() <=> $left->score(),
                    'score-asc' => $left->score() <=> $right->score(),
                    'solved-desc' => $right->solved() <=> $left->solved(),
                    'penalty-asc' => $left->penalty() <=> $right->penalty(),
                    'name-asc' => strnatcasecmp(
                        $left->displayName(),
                        $right->displayName()
                    ),
                    default => $left->rank() <=> $right->rank(),
                };

                return $compared
                    ?: $left->rank() <=> $right->rank()
                    ?: strcmp($left->userLogin(), $right->userLogin());
            }
        );

        return $rankings;
    }

    private function problemResultCell(
        string $policy,
        ?ContestProblemResult $result
    ): string {
        if ($result === null || $result->attemptCount() === 0) {
            return '<td class="ojp-ranking-cell is-empty"><strong>0</strong>'
                . '<span>미제출</span></td>';
        }
        $class = $result->isAccepted() ? ' is-solved' : ' is-attempted';
        if ($policy === ContestScoringPolicy::ICPC) {
            return '<td class="ojp-ranking-cell' . $class . '"><strong>'
                . ($result->isAccepted() ? '해결' : '미해결') . '</strong><span>'
                . ($result->isAccepted()
                    ? '첫 정답 ' . $this->duration(
                        $result->firstAcceptedElapsedSeconds() ?? 0
                    )
                    : '제출 ' . $result->attemptCount() . '회')
                . '</span><span>오답 ' . $result->penaltyAttemptCount()
                . '회 · ' . ($result->isAccepted()
                    ? '벌점 ' . $this->duration($result->penaltySeconds())
                    : '벌점 미적용') . '</span></td>';
        }
        if ($policy === ContestScoringPolicy::OI) {
            return '<td class="ojp-ranking-cell' . $class . '"><strong>'
                . $this->score($result->score()) . '점</strong><span>통과율 '
                . number_format($result->bestPassRate() * 100, 1)
                . '%</span><span>' . ($result->isAccepted()
                    ? '완전 해결 · 벌점 '
                        . $this->duration($result->penaltySeconds())
                    : '부분 점수 · 제출 ' . $result->attemptCount() . '회')
                . '</span></td>';
        }

        return '<td class="ojp-ranking-cell' . $class . '"><strong>'
            . $this->score($result->score()) . '점</strong><span>'
            . ($result->isAccepted()
                ? '풀이 시간 ' . $this->duration(
                    $result->firstAcceptedElapsedSeconds() ?? 0
                )
                : '정답 없음 · 제출 ' . $result->attemptCount() . '회')
            . '</span><span>정확성 '
            . $this->score($result->correctnessScore()) . ' + 시간 '
            . $this->score($result->timeScore()) . ' - 오답 '
            . $result->penaltyPoints() . '</span></td>';
    }

    private function rankingSummaryCells(
        string $policy,
        ContestRankingRow $ranking,
        int $problemCount
    ): string {
        if ($policy === ContestScoringPolicy::ICPC) {
            return '<td><strong>' . $ranking->solved() . ' / '
                . $problemCount . '</strong></td><td><strong>'
                . $this->duration($ranking->penalty()) . '</strong></td><td>'
                . $this->duration($ranking->completionSeconds()) . '</td>';
        }
        if ($policy === ContestScoringPolicy::OI) {
            return '<td><strong>' . $ranking->solved() . ' / '
                . $problemCount . '</strong></td><td><strong>'
                . $this->score($ranking->score()) . '점</strong></td><td>'
                . $this->duration($ranking->penalty()) . '</td>';
        }

        return '<td><strong>' . $ranking->solved() . ' / '
            . $problemCount . '</strong></td><td><strong>'
            . $this->score($ranking->score()) . '점</strong>'
            . '<span class="ojp-table-subvalue">오답 감점 '
            . $ranking->penalty() . '점</span></td><td>'
            . $this->duration($ranking->completionSeconds()) . '</td>';
    }

    private function scoringLabel(string $policy): string
    {
        return match ($policy) {
            ContestScoringPolicy::ICPC => 'ICPC',
            ContestScoringPolicy::OI => 'OI',
            default => '일반 점수형',
        };
    }

    public function submissionTable(
        ContestReport $report,
        bool $detailsAllowed = true,
        bool $selectable = false
    ): string
    {
        $contestId = $report->contest()->id();
        $rows = '';
        foreach ($report->submissions() as $submission) {
            $id = $submission->submissionId();
            $submissionCell = $detailsAllowed
                ? '<a class="ojp-row-link" href="/submissions/' . $id
                    . '?contest_id=' . $contestId . '">' . $id . '</a>'
                : '<strong>' . $id . '</strong>';
            $rows .= '<tr>' . ($selectable
                ? '<td class="ojp-table-check"><input type="checkbox"'
                    . ' name="submission_ids[]" value="' . $id
                    . '" aria-label="제출 ' . $id . ' 선택"></td>'
                : '') . '<td>' . $submissionCell . '</td><td>'
                . $this->escape($submission->userLogin()) . '</td><td>'
                . '<a href="/problems/' . $submission->problemId()
                . '?contest_id=' . $contestId . '">'
                . $submission->problemId() . '</a></td><td>'
                . $this->resultLabel($submission->resultCode()) . '</td>'
                . ($detailsAllowed
                    ? '<td>' . number_format($submission->passRate() * 100, 2)
                        . '%</td>'
                    : '') . '<td>'
                . $this->escape($this->date($submission->submittedAt())) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="' . (($detailsAllowed ? 6 : 5)
                + ($selectable ? 1 : 0))
                . '">제출 기록이 없습니다.</td></tr>';
        }

        return '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr>'
            . ($selectable ? '<th class="ojp-table-check">선택</th>' : '')
            . '<th>제출</th><th>사용자</th><th>문제</th>'
            . '<th>결과</th>'
            . ($detailsAllowed ? '<th>통과율</th>' : '')
            . '<th>시각</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    public function stageLabel(string $stage): string
    {
        return match ($stage) {
            'preparation' => '준비',
            'running' => '진행 중',
            'ended' => '종료',
            default => '보관',
        };
    }

    private function resultLabel(int $code): string
    {
        $result = $this->judgeResults->find($code);

        return '<span class="' . $this->escape($result->badgeClass()) . '">'
            . $this->escape($result->label()) . '</span>';
    }

    private function score(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
    private function duration(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }
}

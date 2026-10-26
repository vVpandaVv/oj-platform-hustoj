<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelService;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;

final class LearnerLevelPageHandler implements RouteHandler
{
    private const PAGE_SIZE = 100;

    private const STATUSES = [
        'insufficient',
        'provisional',
        'classified',
        'reliable',
    ];

    private const LEVELS = [
        'beginner',
        'intermediate',
        'advanced',
    ];

    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly LearnerLevelService $levels,
        private readonly WordPressUserGateway $users,
        private readonly AiHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $profiles = $this->levels->recent(100000);
        $users = $this->userMap($profiles);
        $keyword = trim($request->queryString('q'));
        $status = $this->allowed(
            $request->queryString('status'),
            self::STATUSES
        );
        $level = $this->allowed(
            $request->queryString('level'),
            self::LEVELS
        );
        $policies = $this->policies($profiles);
        $policy = $this->allowed(
            $request->queryString('policy'),
            $policies
        );
        $filtered = array_values(array_filter(
            $profiles,
            fn (LearnerLevelProfile $profile): bool => $this->matches(
                $profile,
                $users[$profile->userId()] ?? null,
                $keyword,
                $status,
                $level,
                $policy
            )
        ));
        $page = max(1, (int) $request->queryString('page', '1'));
        $pageCount = max(
            1,
            (int) ceil(count($filtered) / self::PAGE_SIZE)
        );
        $page = min($page, $pageCount);
        $pageProfiles = array_slice(
            $filtered,
            ($page - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE
        );

        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">AI 풀이 도움 기준</p>'
            . '<h1>학습 수준 분석</h1>'
            . '<p>학습 수준과 산정 근거를 확인합니다. 이 정보는 성적, 순위, '
            . '권한 결정에 사용하지 않습니다.</p></div></header>'
            . $this->metrics($profiles)
            . $this->filters($keyword, $status, $level, $policy, $policies)
            . '<section class="ojp-section">'
            . '<div class="ojp-notice ojp-notice--info">'
            . '<strong>분석 기준</strong><p>10회·5문제부터 임시 분석, '
            . '20회·8문제부터 수준 표시, 30회·10문제부터 신뢰 자료 충족으로 '
            . '표시합니다.</p></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>사용자</th><th>분석 상태</th>'
            . '<th>수준·점수</th><th>세부 지표</th><th>자료 품질</th>'
            . '<th>최근 분석</th></tr></thead><tbody>'
            . $this->rows($pageProfiles, $users)
            . '</tbody></table></div></section>'
            . $this->pagination(
                $page,
                $pageCount,
                $keyword,
                $status,
                $level,
                $policy
            );

        return $this->pages->respond(
            '학습 수준 분석',
            $content,
            'submissions.learner_levels',
            $request
        );
    }

    /**
     * @param list<LearnerLevelProfile> $profiles
     * @return array<int, UserIdentity>
     */
    private function userMap(array $profiles): array
    {
        $userIds = array_values(array_unique(array_map(
            static fn (LearnerLevelProfile $profile): int =>
                $profile->userId(),
            $profiles
        )));
        $users = [];
        foreach ($this->users->findMany($userIds) as $user) {
            $users[$user->userId()] = $user;
        }

        return $users;
    }

    /**
     * @param list<LearnerLevelProfile> $profiles
     * @return list<string>
     */
    private function policies(array $profiles): array
    {
        $policies = array_values(array_unique(array_map(
            static fn (LearnerLevelProfile $profile): string =>
                $profile->policyVersion(),
            $profiles
        )));
        sort($policies);

        return $policies;
    }

    /**
     * @param list<string> $allowed
     */
    private function allowed(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function matches(
        LearnerLevelProfile $profile,
        ?UserIdentity $user,
        string $keyword,
        string $status,
        string $level,
        string $policy
    ): bool {
        if ($status !== '' && $profile->analysisStatus() !== $status) {
            return false;
        }
        if ($level !== '' && $profile->level() !== $level) {
            return false;
        }
        if ($policy !== '' && $profile->policyVersion() !== $policy) {
            return false;
        }
        if ($keyword === '') {
            return true;
        }
        $haystack = implode(' ', [
            (string) $profile->userId(),
            $user?->displayName() ?? '',
            $user?->userLogin() ?? '',
        ]);

        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false
            : stripos($haystack, $keyword) !== false;
    }

    /**
     * @param list<LearnerLevelProfile> $profiles
     */
    private function metrics(array $profiles): string
    {
        $classified = 0;
        $reliable = 0;
        $insufficient = 0;
        foreach ($profiles as $profile) {
            $status = $profile->analysisStatus();
            if (in_array($status, ['classified', 'reliable'], true)) {
                ++$classified;
            }
            if ($status === 'reliable') {
                ++$reliable;
            }
            if ($status === 'insufficient') {
                ++$insufficient;
            }
        }

        return '<dl class="ojp-metric-grid ojp-metric-grid--four">'
            . $this->metric('최근 분석 기록', count($profiles), '최근 분석 순서')
            . $this->metric('수준 판정', $classified, '20회·8문제 이상')
            . $this->metric('신뢰 자료 충족', $reliable, '30회·10문제 이상')
            . $this->metric('분석 보류', $insufficient, '최소 자료 미충족')
            . '</dl>';
    }

    private function metric(string $label, int $value, string $detail): string
    {
        return '<div class="ojp-metric"><dt>' . $label . '</dt><dd>'
            . number_format($value) . '</dd><small>' . $detail . '</small></div>';
    }

    /**
     * @param list<string> $policies
     */
    private function filters(
        string $keyword,
        string $status,
        string $level,
        string $policy,
        array $policies
    ): string {
        $statusOptions = $this->option('', '모든 상태', $status)
            . $this->option('insufficient', '자료 부족', $status)
            . $this->option('provisional', '임시 분석', $status)
            . $this->option('classified', '분석 완료', $status)
            . $this->option('reliable', '신뢰 자료 충족', $status);
        $levelOptions = $this->option('', '모든 수준', $level)
            . $this->option('beginner', '초급', $level)
            . $this->option('intermediate', '중급', $level)
            . $this->option('advanced', '고급', $level);
        $policyOptions = $this->option('', '모든 정책', $policy);
        foreach ($policies as $policyKey) {
            $policyOptions .= $this->option($policyKey, $policyKey, $policy);
        }

        return '<form class="ojp-filter-bar" method="get" '
            . 'action="/oj-console/submissions/learner-levels">'
            . '<div class="ojp-filter-bar__primary"><label class="ojp-field">'
            . '<span class="ojp-label">사용자</span>'
            . '<input class="ojp-input" type="search" name="q" value="'
            . $this->html->escape($keyword)
            . '" placeholder="표시 이름 또는 로그인 아이디"></label></div>'
            . '<label class="ojp-field"><span class="ojp-label">분석 상태</span>'
            . '<select class="ojp-select" name="status">' . $statusOptions
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">학습 수준</span>'
            . '<select class="ojp-select" name="level">' . $levelOptions
            . '</select></label>'
            . '<label class="ojp-field"><span class="ojp-label">정책 버전</span>'
            . '<select class="ojp-select" name="policy">' . $policyOptions
            . '</select></label>'
            . '<button class="ojp-button ojp-button--primary" '
            . 'type="submit">조회</button></form>';
    }

    private function option(
        string $value,
        string $label,
        string $selected
    ): string {
        return '<option value="' . $this->html->escape($value) . '"'
            . ($selected === $value ? ' selected' : '') . '>'
            . $this->html->escape($label) . '</option>';
    }

    private function pagination(
        int $page,
        int $pageCount,
        string $keyword,
        string $status,
        string $level,
        string $policy
    ): string {
        if ($pageCount <= 1) {
            return '';
        }

        return '<nav class="ojp-pagination" aria-label="학습 수준 분석 페이지">'
            . ($page > 1
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->html->escape($this->pageUrl(
                        $page - 1,
                        $keyword,
                        $status,
                        $level,
                        $policy
                    )) . '">이전</a>'
                : '<span></span>')
            . '<span>' . $page . ' / ' . $pageCount . '</span>'
            . ($page < $pageCount
                ? '<a class="ojp-button ojp-button--small" href="'
                    . $this->html->escape($this->pageUrl(
                        $page + 1,
                        $keyword,
                        $status,
                        $level,
                        $policy
                    )) . '">다음</a>'
                : '<span></span>')
            . '</nav>';
    }

    private function pageUrl(
        int $page,
        string $keyword,
        string $status,
        string $level,
        string $policy
    ): string {
        $parameters = ['page' => $page];
        foreach ([
            'q' => $keyword,
            'status' => $status,
            'level' => $level,
            'policy' => $policy,
        ] as $key => $value) {
            if ($value !== '') {
                $parameters[$key] = $value;
            }
        }

        return '/oj-console/submissions/learner-levels?'
            . http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }
    /**
     * @param list<LearnerLevelProfile> $profiles
     * @param array<int, UserIdentity> $users
     */
    private function rows(array $profiles, array $users): string
    {
        if ($profiles === []) {
            return '<tr><td colspan="6">조건에 맞는 학습 수준 분석이 없습니다.'
                . '</td></tr>';
        }
        $rows = '';
        foreach ($profiles as $profile) {
            $user = $users[$profile->userId()] ?? null;
            $rows .= '<tr><td><strong>'
                . $this->html->escape($user?->displayName() ?? '삭제된 사용자')
                . '</strong><br><small>'
                . $this->html->escape(
                    $user?->userLogin() ?? '#' . $profile->userId()
                )
                . '</small></td><td>'
                . $this->statusBadge($profile->analysisStatus())
                . '<br><small>' . number_format($profile->submissionCount())
                . '회 · ' . number_format($profile->attemptedProblemCount())
                . '문제</small></td><td>'
                . $this->score($profile)
                . '</td><td><div class="ojp-ai-metric-values">'
                . $this->metricValue(
                    '난이도',
                    $profile->metric('proven_difficulty')
                )
                . $this->metricValue('범위', $profile->metric('breadth'))
                . $this->metricValue(
                    '도전',
                    $profile->metric('recent_challenge')
                )
                . $this->metricValue(
                    '안정성',
                    $profile->metric('stability')
                )
                . '</div></td><td>난이도 확정률 '
                . $this->percent($profile->difficultyCoverage())
                . '<br>주제 연결률 ' . $this->percent($profile->topicCoverage())
                . '<br><small>최근 표본 '
                . number_format($profile->recentSampleCount())
                . '문제</small></td><td>'
                . $this->html->escape($this->html->date($profile->analyzedAt()))
                . '<br><small>'
                . $this->html->escape($profile->policyVersion())
                . ' · #' . number_format($profile->lastSubmissionId())
                . '</small></td></tr>';
        }

        return $rows;
    }

    private function statusBadge(string $status): string
    {
        $class = match ($status) {
            'reliable' => 'ojp-badge--success',
            'classified' => 'ojp-badge--info',
            'provisional' => 'ojp-badge--warning',
            default => '',
        };

        return '<span class="ojp-badge ' . $class . '">'
            . $this->html->analysisStatus($status) . '</span>';
    }

    private function score(LearnerLevelProfile $profile): string
    {
        $score = $profile->metric('skill_score');
        if ($profile->analysisStatus() === 'insufficient' || $score === null) {
            return '아직 표시하지 않음';
        }
        $value = $profile->level() === null
            ? number_format($score, 1)
            : $this->html->level($profile->level())
                . ' · ' . number_format($score, 1);

        return '<strong>' . $value . '</strong><br><small>증명 난이도 '
            . ($profile->provenTier() === null
                ? '-'
                : $profile->provenTier() . '단계')
            . '</small>';
    }

    private function metricValue(string $label, ?float $value): string
    {
        return '<span>' . $label . ' <strong>'
            . ($value === null ? '-' : number_format($value, 1))
            . '</strong></span>';
    }

    private function percent(float $value): string
    {
        return number_format($value, 1) . '%';
    }
}

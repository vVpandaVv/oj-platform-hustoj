<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Application\LearningDashboardService;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\DashboardProblemItem;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\LearningDashboardSnapshot;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class LearningDashboardPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly LearningDashboardService $dashboard,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return $this->pages->authenticated('내 학습', '', $request);
        }
        $page = max(
            0,
            min(100, (int) $request->queryString('recommendation_page', '0'))
        );
        $snapshot = $this->dashboard->snapshot($identity, $page);
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">내 정보</p><h1>내 학습</h1>'
            . '<p>풀이 기록을 바탕으로 현재 학습 흐름과 다음 문제를 확인합니다.</p>'
            . '</div></header>'
            . $this->summary($snapshot)
            . $this->level($snapshot)
            . $this->problemSection(
                '이어서 풀기',
                '아직 정답을 제출하지 않은 최근 문제입니다.',
                $snapshot->continueProblems()
            )
            . $this->problemSection(
                '복습할 문제',
                '정답까지 여러 번 시도한 문제입니다.',
                $snapshot->reviewProblems()
            )
            . $this->distribution($snapshot)
            . $this->problemSection(
                '추천 문제',
                '현재 수준과 풀이 주제를 기준으로 고른 문제입니다.',
                $snapshot->recommendations(),
                '<a class="ojp-button ojp-button--small"'
                    . ' href="/dashboard?recommendation_page='
                    . ($snapshot->recommendationPage() + 1)
                    . '">다른 추천 보기</a>'
            )
            . $this->recent($snapshot->recentSubmissions());

        return $this->pages->authenticated('내 학습', $content, $request);
    }

    private function summary(LearningDashboardSnapshot $snapshot): string
    {
        return '<dl class="ojp-metrics" aria-label="학습 요약">'
            . $this->stat('해결한 문제', $snapshot->solvedProblemCount() . '개')
            . $this->stat('시도한 문제', $snapshot->attemptedProblemCount() . '개')
            . $this->stat(
                '최근 30일 해결',
                $snapshot->recentSolvedProblemCount() . '개'
            )
            . $this->stat(
                '오답 뒤 해결',
                $snapshot->recoveredProblemCount() . '개'
            )
            . '</dl>';
    }

    private function stat(string $label, string $value): string
    {
        return '<div><dt>' . $this->escape($label)
            . '</dt><dd>' . $this->escape($value) . '</dd></div>';
    }

    private function level(LearningDashboardSnapshot $snapshot): string
    {
        if (! $snapshot->levelAvailable()) {
            return '';
        }
        $level = $snapshot->level();
        if ($level === null) {
            return '<section class="ojp-section"><div class="ojp-section-heading">'
                . '<div><h2>학습 수준</h2><p>풀이 기록이 쌓이면 자동으로 분석합니다.</p></div>'
                . '</div><div class="ojp-empty-state">분석할 기록을 준비하고 있습니다.</div></section>';
        }
        $levelLabel = match ($level->level()) {
            'advanced' => '고급',
            'intermediate' => '중급',
            'beginner' => '초급',
            default => '분석 중',
        };
        $statusLabel = match ($level->analysisStatus()) {
            'reliable' => '신뢰 분석',
            'classified' => '수준 분류',
            'provisional' => '예비 분석',
            default => '자료 부족',
        };
        $score = $level->skillScore() === null
            ? '-'
            : number_format($level->skillScore(), 1);
        $tier = $level->provenTier() === null
            ? '-'
            : (string) $level->provenTier();

        return '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>학습 수준</h2><p>누적 풀이 이력에 따른 현재 분석입니다.</p></div>'
            . '<span class="ojp-badge ojp-badge--info">'
            . $this->escape($statusLabel) . '</span></div>'
            . '<dl class="ojp-metrics">'
            . $this->stat('현재 수준', $levelLabel)
            . $this->stat('숙련 점수', $score)
            . $this->stat('증명 난이도', $tier)
            . '</dl></section>';
    }

    /**
     * @param list<DashboardProblemItem> $items
     */
    private function problemSection(
        string $title,
        string $description,
        array $items,
        string $action = ''
    ): string {
        $rows = '';
        foreach ($items as $item) {
            $difficulty = $item->difficulty() === null
                ? '미분류'
                : '난이도 ' . $item->difficulty();
            $topics = $item->topics() === []
                ? '주제 미분류'
                : implode(', ', array_slice($item->topics(), 0, 3));
            $rows .= '<tr><td><a class="ojp-row-link" href="/problems/'
                . $item->problemId() . '">' . $item->problemId() . '. '
                . $this->escape($item->title()) . '</a>'
                . '<span class="ojp-table-subvalue">'
                . $this->escape($item->reason()) . '</span></td>'
                . '<td>' . $this->escape($difficulty) . '</td>'
                . '<td>' . $this->escape($topics) . '</td>'
                . '<td><a class="ojp-button ojp-button--small" href="/problems/'
                . $item->problemId() . '">풀기</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">표시할 문제가 없습니다.</td></tr>';
        }

        return '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>' . $this->escape($title) . '</h2><p>'
            . $this->escape($description) . '</p></div>' . $action
            . '</div><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>문제</th><th>난이도</th><th>주제</th><th>작업</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function distribution(LearningDashboardSnapshot $snapshot): string
    {
        $difficulty = '';
        foreach ($snapshot->difficultyDistribution() as $tier => $count) {
            $difficulty .= '<li><span>난이도 ' . $tier . '</span><strong>'
                . $count . '개</strong></li>';
        }
        $topics = '';
        foreach ($snapshot->topicDistribution() as $topic => $count) {
            $topics .= '<li><span>' . $this->escape($topic)
                . '</span><strong>' . $count . '개</strong></li>';
        }
        if ($topics === '') {
            $topics = '<li><span>분류된 주제가 없습니다.</span></li>';
        }

        return '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>학습 분포</h2><p>해결한 문제의 난이도와 주제입니다.</p></div>'
            . '</div><div class="ojp-form-grid"><div><h3>난이도</h3>'
            . '<ul class="ojp-value-list">' . $difficulty . '</ul></div>'
            . '<div><h3>주제</h3><ul class="ojp-value-list">'
            . $topics . '</ul></div></div></section>';
    }

    /**
     * @param list<SubmissionProjection> $submissions
     */
    private function recent(array $submissions): string
    {
        $rows = '';
        foreach ($submissions as $submission) {
            $label = $submission->resultLabel() !== ''
                ? $submission->resultLabel()
                : '결과 ' . $submission->resultCode();
            $rows .= '<tr><td><a class="ojp-row-link" href="/submissions/'
                . $submission->submissionId() . '">#'
                . $submission->submissionId() . '</a></td>'
                . '<td><a href="/problems/' . $submission->problemId() . '">'
                . $submission->problemId() . '</a></td>'
                . '<td>' . $this->escape($label) . '</td>'
                . '<td>' . $this->escape(
                    $this->dates->format($submission->submittedAt())
                ) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">아직 제출 기록이 없습니다.</td></tr>';
        }

        return '<section class="ojp-section"><div class="ojp-section-heading">'
            . '<div><h2>최근 제출</h2><p>최근 채점 결과 8건입니다.</p></div>'
            . '<a class="ojp-button ojp-button--small"'
            . ' href="/submissions">전체 보기</a></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>제출</th><th>문제</th><th>결과</th><th>제출 시각</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

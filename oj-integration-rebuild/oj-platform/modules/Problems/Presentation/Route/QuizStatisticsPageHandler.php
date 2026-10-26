<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use DomainException;

final class QuizStatisticsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $context = $this->quizzes->statistics($userId, $problemId);
        } catch (DomainException) {
            return $this->pages->authenticated(
                '퀴즈 통계 없음',
                '<div class="ojp-empty-state"><h1>퀴즈 통계를 열 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/quizzes/'
                    . $problemId->value() . '">퀴즈 상세</a></div>',
                $request,
                404
            );
        }
        $statistics = $context['statistics'];
        $questionRows = '';
        foreach ($statistics['question_rows'] as $index => $row) {
            $answers = (int) ($row['answer_count'] ?? 0);
            $correct = (int) ($row['correct_count'] ?? 0);
            $rate = $answers > 0 ? round(($correct / $answers) * 100, 1) : 0;
            $questionRows .= '<tr><td>' . ($index + 1) . '</td><td>'
                . $this->html->escape((string) ($row['title'] ?? ''))
                . '</td><td>' . number_format($answers) . '</td><td>'
                . $rate . '%</td></tr>';
        }
        if ($questionRows === '') {
            $questionRows = '<tr><td colspan="4">집계할 문항이 없습니다.</td></tr>';
        }
        $attemptRows = '';
        foreach ($statistics['attempt_rows'] as $row) {
            $attemptRows .= '<tr><td>'
                . $this->html->score((float) ($row['score'] ?? 0)) . ' / '
                . $this->html->score((float) ($row['max_score'] ?? 0))
                . '</td><td>' . (int) ($row['correct_count'] ?? 0) . ' / '
                . (int) ($row['question_count'] ?? 0) . '</td><td>'
                . $this->html->duration(
                    (string) ($row['started_at'] ?? ''),
                    is_string($row['submitted_at'] ?? null)
                        ? $row['submitted_at']
                        : null
                ) . '</td><td>'
                . $this->html->escape($this->html->date(
                    is_string($row['submitted_at'] ?? null)
                        ? $row['submitted_at']
                        : null
                ))
                . '</td></tr>';
        }
        if ($attemptRows === '') {
            $attemptRows = '<tr><td colspan="4">내 완료 기록이 없습니다.</td></tr>';
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '익명 집계 · 게시 버전 v' . $context['revision']->revisionNumber()
            . '</p><h1>' . $this->html->escape(
                $context['revision']->definition()->title()
            ) . ' 통계</h1><p>개인 답안이나 다른 사용자의 정보는 공개하지 않습니다.</p>'
            . '</div></header>' . $this->html->tabs($problemId->value(), 'statistics')
            . '<div class="ojp-metric-grid"><div class="ojp-metric"><span>완료 응시</span>'
            . '<strong>' . number_format($statistics['attempt_count']) . '</strong></div>'
            . '<div class="ojp-metric"><span>평균 점수</span><strong>'
            . $this->html->score($statistics['average_score']) . '</strong></div>'
            . '<div class="ojp-metric"><span>총점</span><strong>'
            . $this->html->score($context['revision']->definition()->maxScore())
            . '</strong></div></div>'
            . '<section class="ojp-content-section"><h2>문항별 정답률</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>문항</th><th>제목</th><th>답안</th><th>정답률</th></tr></thead>'
            . '<tbody>' . $questionRows . '</tbody></table></div></section>'
            . '<section class="ojp-content-section"><h2>내 응시 추이</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>점수</th><th>정답</th><th>시간</th><th>제출</th></tr></thead>'
            . '<tbody>' . $attemptRows . '</tbody></table></div></section>';

        return $this->pages->authenticated('퀴즈 통계', $content, $request);
    }
}

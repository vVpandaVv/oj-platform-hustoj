<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use DomainException;

final class QuizAdminStatisticsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly WordPressUserGateway $users,
        private readonly QuizService $quizzes,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $revisionId = max(0, (int) $request->queryString('revision_id'));
        try {
            $this->quizzes->managed($userId, $problemId);
            $context = $this->quizzes->statistics(
                $userId,
                $problemId,
                $revisionId > 0 ? $revisionId : null
            );
        } catch (DomainException) {
            return $this->pages->respond(
                '퀴즈 통계',
                '<div class="ojp-empty-state"><h1>퀴즈 통계를 열 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/quizzes">퀴즈 목록</a></div>',
                'problems.quiz',
                $request,
                404
            );
        }
        $statistics = $context['statistics'];
        $userIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $statistics['attempt_rows']
        )));
        $userMap = [];
        foreach ($this->users->findMany($userIds) as $identity) {
            $userMap[$identity->userId()] = $identity;
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '게시 버전 v' . $context['revision']->revisionNumber()
            . '</p><h1>' . $this->html->escape(
                $context['revision']->definition()->title()
            ) . ' 통계</h1><p>응시 결과를 읽기 전용으로 분석합니다.</p></div>'
            . '<a class="ojp-button" href="/oj-console/quizzes/'
            . $problemId->value() . '/edit">퀴즈 편집</a></header>'
            . '<div class="ojp-notice ojp-notice--warning"><strong>'
            . '학습자 답안과 점수는 수정하지 않습니다.</strong><p>'
            . '오류가 있으면 초안을 교정해 새 불변 버전을 게시합니다.</p></div>'
            . $this->revisionFilter($problemId, $context['history'], $context['revision'])
            . '<div class="ojp-metric-grid"><div class="ojp-metric"><span>완료 응시</span>'
            . '<strong>' . number_format($statistics['attempt_count']) . '</strong></div>'
            . '<div class="ojp-metric"><span>평균 점수</span><strong>'
            . $this->html->score($statistics['average_score']) . '</strong></div>'
            . '<div class="ojp-metric"><span>총점</span><strong>'
            . $this->html->score($context['revision']->definition()->maxScore())
            . '</strong></div></div>'
            . $this->questionTable($statistics['question_rows'])
            . $this->attemptTable(
                $statistics['attempt_rows'],
                $userMap,
                $problemId
            );

        return $this->pages->respond(
            '퀴즈 통계',
            $content,
            'problems.quiz',
            $request
        );
    }

    /**
     * @param list<QuizRevision> $history
     */
    private function revisionFilter(
        ProblemId $problemId,
        array $history,
        QuizRevision $selected
    ): string {
        $options = '';
        foreach ($history as $revision) {
            $options .= '<option value="' . $revision->id() . '"'
                . ($revision->id() === $selected->id() ? ' selected' : '')
                . '>v' . $revision->revisionNumber()
                . ($revision->isCurrentPublished() ? ' · 현재' : '')
                . '</option>';
        }

        return '<form class="ojp-filter-bar" method="get" action="/oj-console/quizzes/'
            . $problemId->value() . '/statistics"><label class="ojp-field">'
            . '<span class="ojp-label">게시 버전</span><select class="ojp-select"'
            . ' name="revision_id">' . $options . '</select></label>'
            . '<button class="ojp-button" type="submit">보기</button></form>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function questionTable(array $rows): string
    {
        $html = '';
        foreach ($rows as $index => $row) {
            $answers = (int) ($row['answer_count'] ?? 0);
            $correct = (int) ($row['correct_count'] ?? 0);
            $rate = $answers > 0 ? round(($correct / $answers) * 100, 1) : 0;
            $html .= '<tr><td>' . ($index + 1) . '</td><td>'
                . $this->html->escape((string) ($row['title'] ?? ''))
                . '</td><td>' . number_format($answers) . '</td><td>'
                . number_format($correct) . '</td><td>' . $rate . '%</td></tr>';
        }
        if ($html === '') {
            $html = '<tr><td colspan="5">집계할 문항이 없습니다.</td></tr>';
        }

        return '<section class="ojp-content-section"><h2>문항별 정답률</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>문항</th><th>제목</th><th>답안</th><th>정답</th><th>정답률</th>'
            . '</tr></thead><tbody>' . $html . '</tbody></table></div></section>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, UserIdentity> $users
     */
    private function attemptTable(
        array $rows,
        array $users,
        ProblemId $problemId
    ): string {
        $html = '';
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $identity = $users[$userId] ?? null;
            $userLabel = $identity === null
                ? '사용자 #' . $userId
                : $identity->displayName() . ' (' . $identity->userLogin() . ')';
            $html .= '<tr><td>' . $this->html->escape($userLabel) . '</td><td>'
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
                . '</td><td><a class="ojp-button ojp-button--small" href="/quizzes/'
                . $problemId->value() . '/attempts/' . (int) ($row['id'] ?? 0)
                . '">결과</a></td></tr>';
        }
        if ($html === '') {
            $html = '<tr><td colspan="6">완료된 응시가 없습니다.</td></tr>';
        }

        return '<section class="ojp-content-section"><div class="ojp-section-heading">'
            . '<div><h2>응시자 결과</h2><p>최근 500건까지 표시합니다.</p></div></div>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>사용자</th><th>점수</th><th>정답</th><th>풀이 시간</th><th>제출</th>'
            . '<th>보기</th>'
            . '</tr></thead><tbody>' . $html . '</tbody></table></div></section>';
    }
}

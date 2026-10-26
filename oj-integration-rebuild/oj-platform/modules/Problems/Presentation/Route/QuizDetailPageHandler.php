<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use DomainException;

final class QuizDetailPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly QuizKeyFactory $keys,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $problemHtml,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $context = $this->quizzes->visible($userId, $problemId);
        } catch (DomainException) {
            return $this->pages->authenticated(
                '퀴즈 없음',
                '<div class="ojp-empty-state"><h1>퀴즈를 열 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/quizzes">퀴즈 목록</a></div>',
                $request,
                404
            );
        }
        $revision = $context['revision'];
        $definition = $revision->definition();
        $attempts = $context['attempts'];
        $active = $context['active'];
        $used = count(array_filter(
            $attempts,
            static fn (QuizAttempt $attempt): bool =>
                in_array($attempt->status(), ['in_progress', 'graded'], true)
        ));
        $start = $active instanceof QuizAttempt
            ? '<a class="ojp-button ojp-button--primary" href="/quizzes/'
                . $problemId->value() . '/attempt">응시 계속하기</a>'
            : ($used >= $definition->attemptLimit()
                ? '<span class="ojp-status-badge ojp-status-badge--neutral">'
                    . '응시 횟수 사용 완료</span>'
                : $this->startForm($problemId));
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제 ' . $problemId->value() . ' · 게시 버전 v'
            . $revision->revisionNumber() . '</p><h1>'
            . $this->html->escape($definition->title()) . '</h1><p>'
            . $this->html->escape($context['problem']->title())
            . '</p></div><div class="ojp-command-group">' . $start
            . '</div></header>'
            . $this->html->tabs($problemId->value(), 'detail')
            . '<div class="ojp-metric-strip">'
            . '<span><strong>문항</strong> ' . $definition->questionCount() . '개</span>'
            . '<span><strong>총점</strong> '
            . $this->html->score($definition->maxScore()) . '점</span>'
            . '<span><strong>응시</strong> ' . $used . ' / '
            . $definition->attemptLimit() . '회</span>'
            . '<span><strong>결과</strong> '
            . $this->html->escape($this->html->policy($definition->resultPolicy()))
            . '</span></div>'
            . '<section class="ojp-content-section"><h2>퀴즈 안내</h2>'
            . ($definition->description() === ''
                ? '<p class="ojp-muted">별도의 안내가 없습니다.</p>'
                : $this->problemHtml->richText($definition->description()))
            . '</section>'
            . $this->attemptHistory($problemId, $attempts)
            . ($definition->publicStatistics()
                ? '<div class="ojp-command-bar"><a class="ojp-button" href="/quizzes/'
                    . $problemId->value() . '/statistics">익명 통계 보기</a></div>'
                : '');

        return $this->pages->authenticated(
            $definition->title(),
            $content,
            $request
        );
    }

    private function startForm(ProblemId $problemId): string
    {
        return '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_quiz_start_attempt')
            . '<input type="hidden" name="problem_id" value="'
            . $problemId->value() . '">'
            . '<input type="hidden" name="attempt_key" value="'
            . $this->keys->attemptKey() . '">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '응시 시작</button></form>';
    }

    /**
     * @param list<QuizAttempt> $attempts
     */
    private function attemptHistory(ProblemId $problemId, array $attempts): string
    {
        $rows = '';
        foreach ($attempts as $index => $attempt) {
            $link = $attempt->status() === 'in_progress'
                ? '/quizzes/' . $problemId->value() . '/attempt'
                : '/quizzes/' . $problemId->value() . '/attempts/' . $attempt->id();
            $rows .= '<tr><td>' . (count($attempts) - $index) . '</td><td>'
                . $this->html->status($attempt->status()) . '</td><td>'
                . ($attempt->status() === 'graded'
                    ? $this->html->score($attempt->score()) . ' / '
                        . $this->html->score($attempt->maxScore())
                    : '-')
                . '</td><td>' . $this->html->duration(
                    $attempt->startedAt(),
                    $attempt->submittedAt()
                )
                . '</td><td><a class="ojp-button ojp-button--small" href="'
                . $link . '">' . ($attempt->status() === 'in_progress' ? '계속' : '결과')
                . '</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">아직 응시 기록이 없습니다.</td></tr>';
        }

        return '<section class="ojp-content-section"><h2>내 응시 기록</h2>'
            . '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
            . '<th>회차</th><th>상태</th><th>점수</th><th>풀이 시간</th><th>보기</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }
}

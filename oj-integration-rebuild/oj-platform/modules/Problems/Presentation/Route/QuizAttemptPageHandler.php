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
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use DomainException;

final class QuizAttemptPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
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
            $context = $this->quizzes->attempt($userId, $problemId);
        } catch (DomainException) {
            return $this->pages->authenticated(
                '진행 중인 응시 없음',
                '<div class="ojp-empty-state"><h1>진행 중인 응시가 없습니다.</h1>'
                    . '<a class="ojp-button" href="/quizzes/'
                    . $problemId->value() . '">퀴즈 상세</a></div>',
                $request,
                404
            );
        }
        $attempt = $context['attempt'];
        if ($attempt->status() === 'graded') {
            return HttpResponse::redirect(
                '/quizzes/' . $problemId->value() . '/attempts/' . $attempt->id()
            );
        }
        $questions = '';
        foreach ($context['revision']->definition()->questions() as $question) {
            if ($question->isEnabled()) {
                $questions .= $this->question($question);
            }
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '응시 중 · 게시 버전 v'
            . $context['revision']->revisionNumber() . '</p><h1>'
            . $this->html->escape($context['revision']->definition()->title())
            . '</h1><p>답안을 확인한 뒤 한 번만 최종 제출합니다.</p></div>'
            . '<span class="ojp-status-badge ojp-status-badge--warning">진행 중</span>'
            . '</header>' . $this->html->tabs($problemId->value(), 'attempt')
            . '<form class="ojp-stack" method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl())
            . '" data-oj-quiz-attempt>'
            . $this->security->hiddenFields('oj_quiz_submit_attempt')
            . '<input type="hidden" name="problem_id" value="'
            . $problemId->value() . '"><input type="hidden" name="attempt_id" value="'
            . $attempt->id() . '">' . $questions
            . '<div class="ojp-sticky-actions"><a class="ojp-button" href="/quizzes/'
            . $problemId->value() . '">나중에 계속</a>'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '최종 제출</button></div></form>';

        return $this->pages->authenticated('퀴즈 응시', $content, $request);
    }

    private function question(QuizQuestion $question): string
    {
        $answerField = match ($question->type()) {
            'multi_choice' => $this->choiceList($question, 'checkbox', '[]'),
            'short_answer' => '<div class="ojp-field"><input class="ojp-input"'
                . ' type="text" name="answers[' . $question->id() . ']"'
                . ' maxlength="1000"></div>',
            default => $this->choiceList($question, 'radio', ''),
        };

        return '<section class="ojp-quiz-attempt-question"><div class="ojp-section-heading">'
            . '<div><p class="ojp-eyebrow">문항 ' . $question->sortOrder()
            . ' · ' . $this->html->score($question->points()) . '점</p><h2>'
            . $this->html->escape($question->title()) . '</h2></div></div>'
            . $this->problemHtml->richText($question->body())
            . $answerField . '</section>';
    }

    private function choiceList(
        QuizQuestion $question,
        string $inputType,
        string $nameSuffix
    ): string {
        $choices = '';
        foreach ($question->choices() as $choice) {
            if (! $choice->isEnabled()) {
                continue;
            }
            $choices .= '<label class="ojp-answer-choice"><input type="'
                . $inputType . '" name="answers[' . $question->id() . ']'
                . $nameSuffix . '" value="' . $choice->id()
                . '"><span class="ojp-answer-choice__label">'
                . $this->html->escape($choice->label())
                . '</span><span class="ojp-answer-choice__body">'
                . $this->html->escape($choice->body()) . '</span></label>';
        }

        return '<div class="ojp-answer-list">' . $choices . '</div>';
    }
}

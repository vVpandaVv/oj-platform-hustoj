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
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use DomainException;

final class QuizResultPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly ProblemHtml $problemHtml,
        private readonly QuizHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $attemptId = (int) ($route->parameters()['attempt_id'] ?? 0);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $navigation = QuizRequestFactory::navigation($request);
        try {
            $context = $this->quizzes->attempt($userId, $problemId, $attemptId, $navigation->accessContext());
        } catch (DomainException) {
            return $this->pages->authenticated(
                '퀴즈 결과 없음',
                '<div class="ojp-empty-state"><h1>퀴즈 결과를 열 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="'
                    . $this->html->escape($navigation->quizUrl($problemId->value()))
                    . '">퀴즈 상세</a></div>',
                $request,
                404
            );
        }
        $attempt = $context['attempt'];
        if ($attempt->status() === 'in_progress') {
            return HttpResponse::redirect(
                $navigation->quizUrl($problemId->value(), 'attempt')
            );
        }
        $revision = $context['revision'];
        $reveal = $this->quizzes->answersVisible($revision);
        $itemMap = [];
        foreach ($attempt->items() as $item) {
            $itemMap[$item->questionId()] = $item;
        }
        $details = '';
        if ($reveal) {
            foreach ($revision->definition()->questions() as $question) {
                if ($question->isEnabled()) {
                    $details .= $this->questionResult(
                        $question,
                        $itemMap[$question->id()] ?? null
                    );
                }
            }
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '응시 결과 · 게시 버전 v' . $revision->revisionNumber()
            . '</p><h1>' . $this->html->escape($revision->definition()->title())
            . '</h1><p>' . $this->html->escape($context['problem']->title())
            . '</p></div><div class="ojp-command-group"><a class="ojp-button" href="'
            . $this->html->escape($navigation->detailUrl($problemId->value()))
            . '">문제로 돌아가기</a><a class="ojp-button" href="'
            . $this->html->escape($navigation->quizUrl($problemId->value()))
            . '">퀴즈 상세</a></div></header>'
            . '<div class="ojp-result-summary"><span>점수</span><strong>'
            . $this->html->score($attempt->score()) . ' / '
            . $this->html->score($attempt->maxScore()) . '</strong><small>'
            . $this->html->duration($attempt->startedAt(), $attempt->submittedAt())
            . '</small></div>'
            . ($reveal
                ? $details
                : '<div class="ojp-notice ojp-notice--info"><strong>점수만 공개됩니다.</strong>'
                    . '<p>정오와 해설은 현재 결과 공개 정책에 따라 표시하지 않습니다.</p></div>');

        return $this->pages->authenticated('퀴즈 결과', $content, $request);
    }

    private function questionResult(
        QuizQuestion $question,
        ?QuizAttemptItem $item
    ): string {
        [$myAnswer, $correctAnswer] = match ($question->type()) {
            'multi_choice' => $this->multiChoiceAnswers($question, $item),
            'short_answer' => $this->shortAnswerAnswers($question, $item),
            default => $this->singleChoiceAnswers($question, $item),
        };
        $status = $item?->isCorrect() ?? false;

        return '<section class="ojp-quiz-result-question"><div class="ojp-section-heading">'
            . '<div><p class="ojp-eyebrow">문항 ' . $question->sortOrder()
            . '</p><h2>' . $this->html->escape($question->title()) . '</h2></div>'
            . '<span class="ojp-status-badge ojp-status-badge--'
            . ($status ? 'success">정답' : 'danger">오답') . '</span></div>'
            . '<dl class="ojp-definition-list"><div><dt>내 답안</dt><dd>'
            . $myAnswer . '</dd></div><div><dt>정답</dt><dd>'
            . $correctAnswer . '</dd></div></dl>'
            . ($question->explanation() === null
                ? ''
                : '<div class="ojp-answer-explanation"><h3>해설</h3>'
                    . $this->problemHtml->richText($question->explanation())
                    . '</div>')
            . '</section>';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function singleChoiceAnswers(
        QuizQuestion $question,
        ?QuizAttemptItem $item
    ): array {
        $selected = null;
        foreach ($question->choices() as $choice) {
            if ($choice->id() === $item?->choiceId()) {
                $selected = $choice;
                break;
            }
        }

        return [
            $selected === null
                ? '선택하지 않음'
                : $this->html->escape($selected->label() . ' · ' . $selected->body()),
            $this->html->escape(
                $question->correctChoice()->label() . ' · '
                    . $question->correctChoice()->body()
            ),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function multiChoiceAnswers(
        QuizQuestion $question,
        ?QuizAttemptItem $item
    ): array {
        $selectedIds = array_flip($item?->selectedChoiceIds() ?? []);
        $selected = array_values(array_filter(
            $question->choices(),
            static fn (QuizChoice $choice): bool => isset($selectedIds[$choice->id()])
        ));
        $labels = static fn (QuizChoice $choice): string => $choice->label();

        return [
            $selected === []
                ? '선택하지 않음'
                : $this->html->escape(implode(', ', array_map($labels, $selected))),
            $this->html->escape(implode(
                ', ',
                array_map($labels, $question->correctChoices())
            )),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function shortAnswerAnswers(
        QuizQuestion $question,
        ?QuizAttemptItem $item
    ): array {
        $answerText = $item?->answerText();

        return [
            $answerText === null || $answerText === ''
                ? '입력하지 않음'
                : $this->html->escape($answerText),
            $this->html->escape(implode(' / ', $question->acceptedAnswers())),
        ];
    }
}

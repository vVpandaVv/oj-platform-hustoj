<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class QuizEditPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly QuizBankService $bank,
        private readonly QuizEditForm $form,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        try {
            $context = $this->quizzes->managed($userId, $problemId);
        } catch (DomainException) {
            return $this->pages->respond(
                '퀴즈 편집',
                '<div class="ojp-empty-state"><h1>퀴즈를 편집할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/quizzes">퀴즈 목록</a></div>',
                'problems.quiz',
                $request,
                404
            );
        }
        $published = $context['published'];
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '문제 ' . $problemId->value() . '</p><h1>퀴즈 편집</h1><p>'
            . $this->html->escape($context['problem']->title())
            . '</p></div><div class="ojp-command-group">'
            . ($published === null
                ? '<span class="ojp-status-badge ojp-status-badge--warning">게시 전</span>'
                : '<span class="ojp-status-badge ojp-status-badge--success">'
                    . '현재 게시 v' . $published->revisionNumber() . '</span>'
                    . '<a class="ojp-button" href="/oj-console/quizzes/'
                    . $problemId->value() . '/statistics">통계</a>')
            . '</div></header>'
            . ($published === null
                ? ''
                : '<div class="ojp-notice ojp-notice--info"><strong>'
                    . '게시 버전은 직접 수정하지 않습니다.</strong><p>'
                    . '현재 초안을 저장한 뒤 새 버전으로 게시하며 기존 응시 결과는 원래 버전을 유지합니다.'
                    . '</p></div>')
            . $this->form->render(
                $context['problem'],
                $context['draft'] ?? $published,
                $published,
                $this->quizzes->canRegradeCompleted($userId),
                'oj_quiz_save',
                $this->bank->list($userId, '')
            );

        return $this->pages->respond(
            '퀴즈 편집',
            $content,
            'problems.quiz',
            $request
        );
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;

final class QuizNewPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly QuizService $quizzes,
        private readonly QuizEditForm $form,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $keyword = trim($request->queryString('q'));
        $problems = $this->quizzes->availableProblems($userId, $keyword);
        $selectedId = (int) $request->queryString('problem_id');
        $selected = null;
        foreach ($problems as $problem) {
            if ($problem->id()->value() === $selectedId) {
                $selected = $problem;
                break;
            }
        }
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '퀴즈</p><h1>퀴즈 추가</h1><p>먼저 연결할 문제를 고른 뒤 초안을 작성합니다.</p>'
            . '</div></header>'
            . $this->problemSelector($problems, $selectedId, $keyword)
            . ($selected instanceof JudgeProblem
                ? $this->form->render(
                    $selected,
                    null,
                    null,
                    false,
                    'oj_quiz_create'
                )
                : '<div class="ojp-empty-state"><h2>문제를 선택해 주세요.</h2>'
                    . '<p>퀴즈는 하나의 문제에 연결됩니다.</p></div>');

        return $this->pages->respond(
            '퀴즈 추가',
            $content,
            'problems.quiz',
            $request
        );
    }

    /**
     * @param list<JudgeProblem> $problems
     */
    private function problemSelector(
        array $problems,
        int $selectedId,
        string $keyword
    ): string {
        $options = '<option value="">문제를 선택하세요</option>';
        foreach ($problems as $problem) {
            $id = $problem->id()->value();
            $options .= '<option value="' . $id . '"'
                . ($id === $selectedId ? ' selected' : '') . '>'
                . $id . ' · ' . $this->html->escape($problem->title())
                . '</option>';
        }

        return '<form class="ojp-filter-bar" method="get"'
            . ' action="/oj-console/quizzes/new">'
            . '<label class="ojp-field"><span class="ojp-label">문제 검색</span>'
            . '<input class="ojp-input" type="search" name="q" value="'
            . $this->html->escape($keyword) . '" placeholder="번호 또는 제목"></label>'
            . '<label class="ojp-field ojp-filter-bar__primary"><span class="ojp-label">'
            . '연결 문제</span><select class="ojp-select" name="problem_id">'
            . $options . '</select></label>'
            . '<button class="ojp-button" type="submit">선택</button></form>';
    }
}

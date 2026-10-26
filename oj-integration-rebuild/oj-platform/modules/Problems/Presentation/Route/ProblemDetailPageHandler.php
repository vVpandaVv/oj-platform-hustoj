<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemDetailSectionRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSubmissionPanelRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageLoader;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageView;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

final class ProblemDetailPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemDetailPageLoader $loader,
        private readonly ProblemDetailPageView $view,
        private readonly ProblemDetailSectionRegistry $sections,
        private readonly ProblemSubmissionPanelRegistry $submissionPanel
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = $this->problemId($route);
        if ($problemId === null) {
            return $this->notFound($request);
        }

        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $navigation = ProblemNavigationContext::fromQueryValues(
            $request->queryString('contest_id'),
            $request->queryString('contest_problem_number'),
            $request->queryString('class_id')
        );
        $state = $this->loader->load(
            $userId,
            $problemId,
            $navigation->accessContext()
        );
        if ($state === null) {
            return $this->notFound($request);
        }

        $panel = $this->submissionPanel->get()?->render(
            $userId,
            $problemId->value(),
            $navigation
        );

        return $this->pages->authenticated(
            $state->problem()->title(),
            $this->view->render(
                $state,
                $this->sections->sections($problemId->value(), $userId),
                $navigation,
                $panel
            ),
            $request
        );
    }

    private function problemId(RouteMatch $route): ?ProblemId
    {
        $value = $route->parameters()['problem_id'] ?? '';
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );

        return is_int($number) ? new ProblemId($number) : null;
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '문제 없음',
            '<div class="ojp-empty-state"><h1>문제를 열 수 없습니다.</h1>'
                . '<p>문제가 없거나 현재 계정에서 볼 수 없습니다.</p>'
                . '<a class="ojp-button" href="/problems">문제 목록</a></div>',
            $request,
            404
        );
    }
}

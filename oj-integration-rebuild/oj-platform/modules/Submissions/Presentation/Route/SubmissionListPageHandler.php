<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

final class SubmissionListPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly SubmissionQueryService $submissions,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $problemId = (int) (
            $route->parameters()['problem_id']
            ?? $request->queryString('problem_id', '0')
        );
        $problemId = $problemId > 0 ? $problemId : null;
        $page = max(1, (int) $request->queryString('page', '1'));
        $result = $this->submissions->ownPage(
            $identity->userLogin(),
            $problemId,
            $page
        );
        $title = $problemId === null
            ? '내 제출'
            : '문제 ' . $problemId . ' 제출';
        $restricted = $this->performanceMode
            ->restrictsOwnSubmissionDetails($identity->userId());
        $notice = $restricted ? $this->performanceModeNotice() : '';
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출</p><h1>' . $title . '</h1>'
            . '<p>' . ($restricted
                ? '채점 결과만 확인할 수 있습니다.'
                : '채점 결과를 확인하고 허용된 제출의 상세 내용을 엽니다.')
            . '</p>'
            . '</div><div class="ojp-page-actions">'
            . '<a class="ojp-button ojp-button--primary" href="'
            . ($problemId === null ? '/submit' : '/submit/' . $problemId)
            . '">새 제출</a></div></header>' . $notice
            . $this->html->table(
                $result['rows'],
                false,
                false,
                false,
                $this->settings->display(),
                ! $restricted
            )
            . $this->html->pagination(
                $result['page'],
                $result['total'],
                $result['page_size'],
                $problemId === null
                    ? '/submissions'
                    : '/problems/' . $problemId . '/submissions'
            );

        return $this->pages->authenticated($title, $content, $request);
    }

    private function performanceModeNotice(): string
    {
        return '<div class="ojp-notice ojp-notice--warning">'
            . '<strong>수행 모드</strong>'
            . '<p>제출 상세와 코드는 표시되지 않으며 채점 결과만 확인할 수 있습니다.</p>'
            . '</div>';
    }
}

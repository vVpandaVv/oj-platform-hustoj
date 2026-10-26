<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use DomainException;

final class ContestReportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly ContestHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        $key = $route->route()->key();
        $submissions = $key === 'contests.submissions';
        $ranking = $key === 'contests.ranking';
        $statistics = $key === 'contests.statistics';
        try {
            $report = $this->contests->report(
                $identity?->userId() ?? 0,
                $identity?->userLogin() ?? '',
                $contestId,
                $submissions,
                $ranking,
                $statistics
            );
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '대회 현황',
                '<div class="ojp-empty-state"><h1>현황을 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                403
            );
        }
        $tab = $submissions
            ? 'submissions'
            : ($key === 'contests.statistics' ? 'statistics' : 'ranking');
        $sort = $request->queryString('sort', 'rank-asc');
        $restricted = $submissions && $this->performanceMode
            ->restrictsOwnSubmissionDetails($identity?->userId() ?? 0);
        $title = match ($tab) {
            'submissions' => '내 제출',
            'statistics' => '대회 통계',
            default => '대회 순위',
        };
        $reportContent = match ($tab) {
            'submissions' => $this->html->submissionTable($report, ! $restricted),
            'statistics' => $this->html->statisticsTable($report),
            default => $this->html->rankingTable(
                $report,
                $sort,
                '/contests/' . $contestId . '/ranking',
                $identity?->userLogin()
            ),
        };
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '대회·평가</p><h1>' . $this->html->escape($report->contest()->title())
            . ' ' . $title . '</h1></div></header>'
            . $this->html->tabs($contestId, $tab, false)
            . $this->html->timeSummary($report->contest())
            . ($restricted ? $this->performanceModeNotice() : '')
            . $reportContent;

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

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use DomainException;

final class ContestReportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ContestService $contests,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionRejudgeControlRenderer $rejudgeControls,
        private readonly ContestHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $actorId = $identity?->userId() ?? 0;
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);
        if ($contestId < 1) {
            $sections = '';
            foreach (array_filter(
                $this->contests->managedBy($actorId),
                static fn (Contest $contest): bool => $contest->isArchived()
            ) as $contest) {
                try {
                    $report = $this->contests->report(
                        $actorId,
                        $identity?->userLogin() ?? '',
                        $contest->id()
                    );
                    $sections .= '<section class="ojp-section"><h2>'
                        . $this->html->escape($contest->title()) . '</h2>'
                        . $this->html->submissionTable($report) . '</section>';
                } catch (DomainException) {
                }
            }
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">대회 관리</p><h1>보관 대회 제출</h1>'
                . '</div><a class="ojp-button" href="/oj-console/contests/deleted">보관된 대회</a>'
                . '</header>' . ($sections === ''
                    ? '<div class="ojp-empty-state">보관 대회 제출이 없습니다.</div>'
                    : $sections);

            return $this->pages->respond(
                '보관 대회 제출',
                $content,
                'contests.deleted_submissions',
                $request
            );
        }
        $key = $route->route()->key();
        $submissions = str_ends_with($key, '.submissions');
        $tab = $submissions
            ? 'submissions'
            : (str_ends_with($key, '.statistics') ? 'statistics' : 'ranking');
        try {
            $report = $this->contests->report(
                $actorId,
                $identity?->userLogin() ?? '',
                $contestId
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '대회 보고서',
                '<div class="ojp-empty-state"><h1>보고서를 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                'contests.index',
                $request,
                403
            );
        }
        $sort = $request->queryString('sort', 'rank-asc');
        $title = $submissions ? '제출 현황' : ($tab === 'statistics' ? '대회 통계' : '대회 순위');
        $reportContent = match ($tab) {
            'submissions' => $this->rejudgeControls->render(
                '/oj-console/contests/' . $contestId . '/submissions'
            ) . $this->html->submissionTable($report, true, true),
            'statistics' => $this->html->statisticsTable($report),
            default => $this->html->rankingTable(
                $report,
                $sort,
                '/oj-console/contests/' . $contestId . '/ranking'
            ),
        };
        $exportForm = $submissions ? ''
            : '<form method="post" action="'
                . $this->html->escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_contest_export')
                . '<input type="hidden" name="contest_id" value="'
                . $contestId . '"><input type="hidden" name="report_type" value="'
                . ($tab === 'statistics' ? 'statistics' : 'ranking') . '">'
                . '<button class="ojp-button" type="submit">'
                . 'CSV 내보내기</button></form>';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '대회 관리</p><h1>' . $this->html->escape($report->contest()->title())
            . ' ' . $title . '</h1></div>' . $exportForm
            . '</header>' . $this->html->tabs($contestId, $tab, true)
            . $this->html->timeSummary($report->contest())
            . $reportContent;

        return $this->pages->respond(
            $title,
            $content,
            'contests.index',
            $request
        );
    }
}

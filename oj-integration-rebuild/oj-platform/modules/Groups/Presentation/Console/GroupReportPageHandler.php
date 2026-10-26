<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use DomainException;

final class GroupReportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly GroupService $groups,
        private readonly SubmissionRejudgeControlRenderer $rejudgeControls,
        private readonly GroupHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $actorId = $this->accounts->current()->identity()?->userId() ?? 0;
        $groupId = (int) ($route->parameters()['group_id'] ?? 0);
        try {
            $group = $this->groups->findManaged($groupId, $actorId);
            $ranking = $route->route()->key() === 'console.groups.ranking';
            $fromDate = trim($request->queryString('from'));
            $toDate = trim($request->queryString('to'));
            $report = $this->groups->report(
                $actorId,
                $groupId,
                false,
                $fromDate,
                $toDate
            );
            $title = $ranking ? '순위' : '제출 현황';
            $content = '<header class="ojp-page-header"><div>'
                . '<p class="ojp-eyebrow">그룹 관리</p><h1>'
                . $this->html->escape($group->name()) . ' ' . $title
                . '</h1></div></header>'
                . $this->html->tabs(
                    $groupId,
                    $ranking ? 'ranking' : 'submissions',
                    true
                )
                . $this->html->periodFilter(
                    '/oj-console/groups/' . $groupId . '/'
                        . ($ranking ? 'ranking' : 'submissions'),
                    $fromDate,
                    $toDate
                )
                . ($ranking
                    ? $this->html->rankingTable($report)
                    : $this->rejudgeControls->render(
                        '/oj-console/groups/' . $groupId . '/submissions'
                    ) . $this->html->submissionTable(
                        $report,
                        true,
                        null,
                        true,
                        true
                    ));

            return $this->pages->respond(
                $title,
                $content,
                'classes.groups',
                $request
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '그룹 보고서',
                '<div class="ojp-empty-state"><h1>보고서를 볼 수 없습니다.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage())
                    . '</p></div>',
                'classes.groups',
                $request,
                403
            );
        }
    }
}

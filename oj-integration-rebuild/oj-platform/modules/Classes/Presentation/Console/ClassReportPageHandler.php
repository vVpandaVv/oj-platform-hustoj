<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use DomainException;

final class ClassReportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ClassService $classes,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly SubmissionRejudgeControlRenderer $rejudgeControls,
        private readonly ClassHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $actorId = $identity?->userId() ?? 0;
        $classId = (int) ($route->parameters()['class_id'] ?? 0);
        $submissions = str_ends_with($route->route()->key(), '.submissions');
        try {
            $report = $this->classes->report(
                $actorId,
                $identity?->userLogin() ?? '',
                $classId
            );
        } catch (DomainException $error) {
            return $this->pages->respond(
                '수업 보고서',
                '<div class="ojp-empty-state"><h1>보고서를 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                'classes.index',
                $request,
                403
            );
        }
        $title = $submissions ? '제출 현황' : '학습 현황';
        $sort = $request->queryString('sort', 'progress-desc');
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . '수업 관리</p><h1>' . $this->html->escape($report->class()->name())
            . ' ' . $title . '</h1></div><div class="ojp-command-group">'
            . '<form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_class_export_progress')
            . '<input type="hidden" name="class_id" value="' . $classId . '">'
            . '<button class="ojp-button" type="submit">CSV 내보내기</button></form>'
            . '</div></header>'
            . $this->html->tabs(
                $classId,
                $submissions ? 'submissions' : 'progress',
                true
            )
            . ($submissions
                ? $this->rejudgeControls->render(
                    '/oj-console/classes/' . $classId . '/submissions'
                ) . $this->html->submissionTable($report, true, true)
                : $this->html->reportTable(
                    $report,
                    $sort,
                    '/oj-console/classes/' . $classId . '/progress'
                ));

        return $this->pages->respond(
            $title,
            $content,
            'classes.index',
            $request
        );
    }
}

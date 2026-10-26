<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use DomainException;

final class ClassReportPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ClassService $classes,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly ClassHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        $userId = $identity?->userId() ?? 0;
        $userLogin = $identity?->userLogin() ?? '';
        $classId = (int) ($route->parameters()['class_id'] ?? 0);
        $submissions = $route->route()->key() === 'classes.submissions';
        try {
            $class = $this->classes->findVisible($classId, $userId);
            $ownOnly = $submissions || (
                ! $this->classes->canManage($userId, $class)
                && $class->progressVisibility() !== 'participants_live'
            );
            $report = $this->classes->report(
                $userId,
                $userLogin,
                $classId,
                $ownOnly
            );
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '수업 현황',
                '<div class="ojp-empty-state"><h1>현황을 볼 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                403
            );
        }
        $title = $submissions ? '내 제출' : '학습 현황';
        $restricted = $submissions && $this->performanceMode
            ->restrictsOwnSubmissionDetails($userId);
        $sort = $request->queryString('sort', 'progress-desc');
        $notice = $restricted ? $this->performanceModeNotice() : '';
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">수업</p><h1>'
            . $this->html->escape($report->class()->name()) . ' ' . $title
            . '</h1></div></header>'
            . $this->html->tabs(
                $classId,
                $submissions ? 'submissions' : 'progress',
                false
            )
            . $notice
            . ($submissions
                ? $this->html->submissionTable($report, ! $restricted)
                : $this->html->reportTable(
                    $report,
                    $sort,
                    '/classes/' . $classId . '/progress',
                    $userLogin
                ));

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

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;

final class ProblemArchivePageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemAccessPolicy $access,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $problem = $this->catalog->findVisible($userId, $problemId);
        if ($problem === null || ! $this->access->canArchive($userId, $problemId)) {
            return $this->pages->respond(
                '문제 상태 변경',
                '<div class="ojp-empty-state"><h1>문제 상태를 바꿀 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.index',
                $request,
                404
            );
        }
        $archive = $problem->visibility() === ProblemVisibility::Published;
        $verb = $archive ? '보관' : '복원';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제 '
            . $problemId->value() . '</p><h1>문제 ' . $verb . '</h1></div></header>'
            . '<section class="ojp-confirm-panel"><h2>'
            . $this->html->escape($problem->title()) . '</h2><p>'
            . ($archive
                ? '보관하면 사용자 문제 목록에서 숨겨집니다. 제출과 관련 기록은 삭제하지 않습니다.'
                : '복원하면 사용자 문제 목록에 다시 공개됩니다.')
            . '</p><form method="post" action="'
            . $this->html->escape($this->actionUrls->actionUrl()) . '">'
            . $this->security->hiddenFields('oj_problem_delete')
            . '<input type="hidden" name="problem_id" value="' . $problemId->value() . '">'
            . '<input type="hidden" name="archived" value="' . ($archive ? '1' : '0') . '">'
            . '<div class="ojp-form-actions"><a class="ojp-button" href="/oj-console/problems">취소</a>'
            . '<button class="ojp-button ' . ($archive ? 'ojp-button--danger' : 'ojp-button--primary')
            . '" type="submit">' . $verb . '</button></div></form></section>';

        return $this->pages->respond(
            '문제 ' . $verb,
            $content,
            'problems.index',
            $request
        );
    }
}

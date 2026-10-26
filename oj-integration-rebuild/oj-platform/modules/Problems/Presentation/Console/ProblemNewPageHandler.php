<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class ProblemNewPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemGateway $problems,
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemEditForm $form,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        if (! $this->access->canCreate($userId)) {
            return $this->pages->respond(
                '문제 추가',
                '<div class="ojp-empty-state"><h1>문제를 추가할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.new',
                $request,
                403
            );
        }
        $authoringDraft = null;
        $notice = '';
        $reference = trim($request->queryString('authoring_draft'));
        if ($reference !== '') {
            try {
                $authoringDraft = $this->drafts->authoring(
                    $reference,
                    $userId,
                    null
                );
            } catch (DomainException $error) {
                $notice = '<div class="ojp-notice ojp-notice--warning"><strong>'
                    . 'AI 초안을 열지 못했습니다.</strong><p>'
                    . $this->html->escape($error->getMessage()) . '</p></div>';
            }
        }

        return $this->pages->respond(
            '문제 추가',
            '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제</p>'
                . '<h1>문제 추가</h1></div></header>'
                . $notice
                . $this->form->render(
                    null,
                    null,
                    $this->problems->nextId()->value(),
                    $this->access->canRunNewAuthoringAi($userId),
                    null,
                    $authoringDraft
                ),
            'problems.new',
            $request
        );
    }
}

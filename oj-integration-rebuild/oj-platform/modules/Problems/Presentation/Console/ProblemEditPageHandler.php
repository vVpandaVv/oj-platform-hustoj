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
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAssignmentService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use DomainException;

final class ProblemEditPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly ProblemCatalogService $catalog,
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemAssignmentService $assignments,
        private readonly ProblemEditForm $form,
        private readonly ProblemAssignmentPanel $assignmentPanel,
        private readonly ProblemHtml $html
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $problemId = new ProblemId((int) ($route->parameters()['problem_id'] ?? 0));
        $userId = $this->accounts->current()->identity()?->userId() ?? 0;
        $problem = $this->catalog->findManaged($userId, $problemId);
        if ($problem === null) {
            return $this->pages->respond(
                '문제 수정',
                '<div class="ojp-empty-state"><h1>문제를 수정할 수 없습니다.</h1>'
                    . '<a class="ojp-button" href="/oj-console/problems">문제 목록</a></div>',
                'problems.index',
                $request,
                404
            );
        }
        $metadataDraft = null;
        $authoringDraft = null;
        $draftErrors = [];
        $metadataReference = trim($request->queryString('metadata_draft'));
        if ($metadataReference !== '') {
            try {
                $metadataDraft = $this->drafts->metadata(
                    $metadataReference,
                    $userId,
                    $problemId
                );
            } catch (DomainException $error) {
                $draftErrors[] = $error->getMessage();
            }
        }
        $authoringReference = trim($request->queryString('authoring_draft'));
        if ($authoringReference !== '') {
            try {
                $authoringDraft = $this->drafts->authoring(
                    $authoringReference,
                    $userId,
                    $problemId
                );
            } catch (DomainException $error) {
                $draftErrors[] = $error->getMessage();
            }
        }
        $notice = $draftErrors === []
            ? ''
            : '<div class="ojp-notice ojp-notice--warning"><strong>'
                . 'AI 초안을 열지 못했습니다.</strong><p>'
                . $this->html->escape(implode(' ', array_unique($draftErrors)))
                . '</p></div>';
        $assignmentContext = $this->assignments->view($userId, $problemId);

        return $this->pages->respond(
            '문제 수정',
            '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제 '
                . $problemId->value() . '</p><h1>문제 수정</h1></div></header>'
                . $notice
                . $this->form->render(
                    $problem,
                    $this->catalog->metadata($problemId),
                    $problemId->value(),
                    $this->access->canRunAuthoringAi($userId, $problemId),
                    $metadataDraft,
                    $authoringDraft
                )
                . $this->assignmentPanel->render(
                    $assignmentContext['assignment'],
                    $assignmentContext['users'],
                    $assignmentContext['can_manage']
                ),
            'problems.index',
            $request
        );
    }
}

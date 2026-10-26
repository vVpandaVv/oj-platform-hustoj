<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditorService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ArchiveProblemActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $actionKey,
        private readonly ProblemEditorService $editor,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor($this->actionKey, $request);
            $actorUserId = $actor->userId();
            $problemId = new ProblemId($request->bodyInt('problem_id'));
            $archived = $request->bodyBool('archived');
            $this->editor->archive($actorUserId, $problemId, $archived);

            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.visibility_changed',
                    MessageTone::Success,
                    $archived ? '문제 보관 완료' : '문제 복원 완료',
                    $archived
                        ? '문제를 사용자 목록에서 숨겼습니다.'
                        : '문제를 사용자 목록에 다시 공개했습니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $actorUserId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '문제 상태를 변경하지 못했습니다.',
                $actorUserId
            );
        }
    }

    private function failure(string $message, int $actorUserId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/problems',
            new UiMessage(
                'problems.visibility_failed',
                MessageTone::Danger,
                '문제 상태 변경 실패',
                $message
            ),
            $actorUserId
        );
    }
}

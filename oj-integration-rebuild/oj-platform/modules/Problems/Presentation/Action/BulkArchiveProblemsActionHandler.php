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

final class BulkArchiveProblemsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_bulk_visibility';

    public function __construct(
        private readonly ProblemEditorService $editor,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $succeeded = 0;
        $failed = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $problemIds = $request->bodyIntList('problem_ids');
            if ($problemIds === []) {
                throw new DomainException('보관할 문제를 선택해 주세요.');
            }
            $archived = $request->bodyBool('archived');
            foreach ($problemIds as $problemId) {
                try {
                    $this->editor->archive(
                        $actorUserId,
                        new ProblemId($problemId),
                        $archived
                    );
                    ++$succeeded;
                } catch (Throwable $error) {
                    ++$failed;
                    $this->errors->report($error);
                }
            }

            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.bulk_visibility_completed',
                    $failed === 0 ? MessageTone::Success : MessageTone::Warning,
                    '일괄 상태 변경 완료',
                    sprintf('성공 %d개, 실패 %d개입니다.', $succeeded, $failed)
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.bulk_visibility_rejected',
                    MessageTone::Danger,
                    '일괄 상태 변경 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.bulk_visibility_failed',
                    MessageTone::Danger,
                    '일괄 상태 변경 실패',
                    '문제 상태를 변경하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

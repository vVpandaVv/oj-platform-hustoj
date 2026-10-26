<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAssignmentService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditorService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemWriteConsistencyException;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SaveProblemActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_save';

    public function __construct(
        private readonly ProblemEditorService $editor,
        private readonly ProblemAssignmentService $assignments,
        private readonly ProblemAiDraftService $drafts,
        private readonly ProblemRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $problemId = $request->bodyInt('problem_id');
        $operation = $request->bodyString('operation');
        $assignmentOperation = in_array(
            $operation,
            ['assign_owner', 'grant', 'revoke'],
            true
        );
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            if ($assignmentOperation) {
                return $this->handleAssignment(
                    $actorUserId,
                    $problemId,
                    $operation,
                    $request
                );
            }
            $references = $this->draftReferences($request);
            $provenance = $this->drafts->inspectProblemSave(
                $references,
                $actorUserId,
                $problemId > 0 ? new ProblemId($problemId) : null
            );
            $command = $this->requests->editCommand(
                $request,
                $provenance['generation_method'],
                $provenance['difficulty_source']
            );
            if ($problemId > 0) {
                $id = new ProblemId($problemId);
                $this->editor->update($actorUserId, $id, $command);
            } else {
                $id = $this->editor->create($actorUserId, $command);
            }
            foreach ($provenance['references'] as $reference) {
                try {
                    $this->drafts->discard($reference, $actorUserId);
                } catch (Throwable $error) {
                    $this->errors->report($error);
                }
            }

            return $this->responses->redirect(
                '/oj-console/problems/' . $id->value() . '/edit',
                new UiMessage(
                    'problems.saved',
                    MessageTone::Success,
                    '문제 저장 완료',
                    '문제 내용과 메타데이터를 저장했습니다.'
                ),
                $actorUserId
            );
        } catch (ProblemWriteConsistencyException $error) {
            $this->errors->report($error);
            if ($error->recoveryError() !== null) {
                $this->errors->report($error->recoveryError());
            }

            return $this->responses->redirect(
                '/oj-console/problems/' . $error->problemId()->value() . '/edit',
                new UiMessage(
                    'problems.consistency_attention_required',
                    MessageTone::Danger,
                    '문제 상태 확인 필요',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            if ($error->getPrevious() !== null) {
                $this->errors->report($error->getPrevious());
            }
            return $this->responses->redirect(
                $this->failurePath($problemId, $assignmentOperation),
                new UiMessage(
                    $assignmentOperation
                        ? 'problems.assignment_failed'
                        : 'problems.save_rejected',
                    MessageTone::Danger,
                    $assignmentOperation
                        ? '담당자 변경 실패'
                        : '문제를 저장하지 못했습니다.',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $this->failurePath($problemId, $assignmentOperation),
                new UiMessage(
                    $assignmentOperation
                        ? 'problems.assignment_failed'
                        : 'problems.save_failed',
                    MessageTone::Danger,
                    $assignmentOperation
                        ? '담당자 변경 실패'
                        : '문제를 저장하지 못했습니다.',
                    $assignmentOperation
                        ? '담당자 정보를 변경하지 못했습니다.'
                        : '잠시 뒤 다시 시도하고 문제가 계속되면 시스템 상태를 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }

    private function handleAssignment(
        int $actorUserId,
        int $problemId,
        string $operation,
        HttpRequest $request
    ): HttpResponse {
        if ($problemId < 1) {
            throw new DomainException('담당자를 변경할 문제 번호가 올바르지 않습니다.');
        }
        $problem = new ProblemId($problemId);
        if ($operation === 'assign_owner') {
            $target = $this->assignments->assignOwner(
                $actorUserId,
                $problem,
                $request->bodyString('user_login'),
                $request->bodyBool('confirm_transfer')
            );
            $message = $target->displayName()
                . ' 님에게 문제 소유권을 이전했습니다.';
        } elseif ($operation === 'grant') {
            $role = CollaboratorRole::tryFrom($request->bodyString('role'));
            if ($role === null) {
                throw new DomainException('담당자 역할을 선택해 주세요.');
            }
            $target = $this->assignments->grant(
                $actorUserId,
                $problem,
                $request->bodyString('user_login'),
                $role
            );
            $message = $target->displayName()
                . ' 님의 담당 범위를 저장했습니다.';
        } else {
            $this->assignments->revoke(
                $actorUserId,
                $problem,
                $request->bodyInt('target_user_id')
            );
            $message = '공동 담당자를 해제했습니다.';
        }

        return $this->responses->redirect(
            '/oj-console/problems/' . $problemId . '/edit#problem-assignment',
            new UiMessage(
                'problems.assignment_saved',
                MessageTone::Success,
                '담당자 변경 완료',
                $message
            ),
            $actorUserId
        );
    }

    private function failurePath(
        int $problemId,
        bool $assignmentOperation
    ): string {
        if ($problemId > 0) {
            return '/oj-console/problems/' . $problemId . '/edit'
                . ($assignmentOperation ? '#problem-assignment' : '');
        }

        return $assignmentOperation
            ? '/oj-console/problems'
            : '/oj-console/problems/new';
    }

    /**
     * @return list<string>
     */
    private function draftReferences(HttpRequest $request): array
    {
        $body = $request->body();
        $values = is_array($body['applied_draft_references'] ?? null)
            ? $body['applied_draft_references']
            : [];
        $references = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $references[] = trim((string) $value);
            }
        }

        return array_values(array_unique($references));
    }
}

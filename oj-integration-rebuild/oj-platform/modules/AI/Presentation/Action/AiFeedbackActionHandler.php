<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobView;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;
use JsonException;
use Throwable;

final class AiFeedbackActionHandler implements ActionHandler
{
    private const ACTION = 'oj_ai_feedback_generate';

    public function __construct(
        private readonly SubmissionFeedbackJobService $feedback,
        private readonly JobQuery $jobs,
        private readonly JobController $jobControl,
        private readonly Authorization $permissions,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $submissionId = $request->bodyInt('submission_id');
            if ($submissionId < 1) {
                throw new DomainException('제출 번호가 올바르지 않습니다.');
            }
            $operation = $request->bodyString('operation', 'generate');
            if ($operation === 'status') {
                return $this->status(
                    $actor->userId(),
                    $actor->userLogin(),
                    $submissionId,
                    $request->bodyInt('job_id')
                );
            }
            if (in_array($operation, ['cancel', 'retry'], true)) {
                return $this->control(
                    $operation,
                    $actor->userId(),
                    $actor->userLogin(),
                    $submissionId,
                    $request->bodyInt('job_id')
                );
            }
            if ($operation !== 'generate') {
                throw new DomainException('AI 피드백 작업이 올바르지 않습니다.');
            }
            $this->guard->throttle(
                'ai_feedback_generate',
                $actor->userId() . ':' . $submissionId,
                10,
                300
            );
            $result = $this->enqueueFeedback(
                $actor->userId(),
                $actor->userLogin(),
                $submissionId
            );
            $job = $this->jobs->find($result->jobId());

            return $this->json(202, [
                'ok' => true,
                'job_id' => $result->jobId(),
                'status' => 'queued',
                'terminal' => false,
                'can_cancel' => $job !== null
                    && ! $job->isTerminal()
                    && $this->canControlJob($actor->userId(), $job),
                'message' => $result->wasCreated()
                    ? 'AI 피드백 작업을 등록했습니다.'
                    : '같은 입력으로 등록된 작업 상태를 확인합니다.',
            ]);
        } catch (DomainException $error) {
            return $this->json(403, [
                'ok' => false,
                'message' => $error->getMessage(),
            ]);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->json(500, [
                'ok' => false,
                'message' => 'AI 피드백 작업을 처리하지 못했습니다.',
            ]);
        }
    }

    private function status(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId,
        int $jobId
    ): HttpResponse {
        $job = $this->jobForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId,
            $jobId
        );
        if ($job === null) {
            return $this->json(404, [
                'ok' => false,
                'message' => 'AI 피드백 작업을 찾을 수 없습니다.',
            ]);
        }
        $canControl = $this->canControlJob($actorUserId, $job);
        $feedback = $this->feedback->findCurrentForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId
        );
        if (
            $job->status() === 'completed'
            && $job->succeededCount() > 0
            && $feedback !== null
        ) {
            return $this->json(200, [
                'ok' => true,
                'status' => $job->status(),
                'terminal' => true,
                'can_retry' => false,
                'can_cancel' => false,
                'message' => 'AI 풀이 도움을 만들었습니다.',
                'feedback' => $feedback->content(),
            ]);
        }
        if (
            $job->status() === 'completed'
            && $job->succeededCount() > 0
        ) {
            return $this->json(200, [
                'ok' => true,
                'status' => $job->status(),
                'terminal' => true,
                'can_retry' => false,
                'can_cancel' => false,
                'message' => '제출 코드, 채점 결과 또는 문제 내용이 바뀌어 '
                    . '피드백을 다시 생성해야 합니다.',
            ]);
        }
        $message = 'AI 피드백을 생성하고 있습니다.';
        if ($job->isTerminal()) {
            $message = $job->resultMessage()
                ?? 'AI 피드백을 만들지 못했습니다.';
        }

        return $this->json(200, [
            'ok' => true,
            'status' => $job->status(),
            'terminal' => $job->isTerminal(),
            'can_retry' => $canControl && in_array(
                $job->status(),
                ['failed', 'partial', 'needs_attention'],
                true
            ),
            'can_cancel' => $canControl && ! $job->isTerminal(),
            'message' => $message,
        ]);
    }

    private function control(
        string $operation,
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId,
        int $jobId
    ): HttpResponse {
        $job = $this->jobForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId,
            $jobId,
            true
        );
        if ($job === null) {
            return $this->json(404, [
                'ok' => false,
                'message' => 'AI 피드백 작업을 찾을 수 없습니다.',
            ]);
        }
        $result = $operation === 'cancel'
            ? $this->jobControl->cancel($actorUserId, $jobId)
            : $this->jobControl->retryFailed($actorUserId, $jobId);

        return $this->json(200, [
            'ok' => true,
            'job_id' => $jobId,
            'status' => $operation === 'cancel'
                ? 'cancellation_requested'
                : 'queued',
            'terminal' => false,
            'can_cancel' => $operation !== 'cancel',
            'message' => $operation === 'cancel'
                ? 'AI 피드백 작업 취소를 요청했습니다.'
                : sprintf(
                    '확인이 필요한 %d개 항목을 다시 요청했습니다.',
                    $result->affectedCount()
                ),
        ]);
    }

    private function jobForActor(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId,
        int $jobId,
        bool $requireControl = false
    ): ?JobView {
        $this->feedback->assertCanAccess(
            $actorUserId,
            $actorUserLogin,
            $submissionId
        );
        $job = $this->jobs->find($jobId);
        if (
            $job === null
            || $job->jobType() !== 'submission_ai_feedback_generate'
            || (int) ($job->targetSummary()['submission_id'] ?? 0)
                !== $submissionId
            || ($requireControl && ! $this->canControlJob($actorUserId, $job))
        ) {
            return null;
        }

        return $job;
    }

    private function enqueueFeedback(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): JobEnqueueResult {
        $restartAfterJobId = 0;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $result = $this->feedback->enqueue(
                $actorUserId,
                $actorUserLogin,
                $submissionId,
                $restartAfterJobId
            );
            $job = $this->jobs->find($result->jobId());
            if ($job === null || $job->status() !== 'cancelled') {
                return $result;
            }
            $restartAfterJobId = $job->id();
        }

        throw new DomainException(
            '취소된 AI 피드백 작업이 너무 많습니다. 관리자에게 문의해 주세요.'
        );
    }

    private function canControlJob(
        int $actorUserId,
        JobView $job
    ): bool {
        return $job->createdByUserId() === $actorUserId
            || $this->permissions->can(
                $actorUserId,
                'oj_manage_ai_feedback'
            );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        try {
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            $status = 500;
            $encoded = '{"ok":false}';
        }

        return new HttpResponse(
            $status,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ],
            $encoded
        );
    }
}

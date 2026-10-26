<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy;
use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRefresher;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use DomainException;

final class SubmissionFeedbackJobService
{
    public function __construct(
        private readonly AiFeedbackSubmissionProvider $submissions,
        private readonly LearningProblemProvider $problems,
        private readonly SubmissionFeedbackRepository $feedback,
        private readonly FeedbackGuideRepository $guides,
        private readonly AiFeedbackSettingsService $settings,
        private readonly FeedbackInputFactory $inputs,
        private readonly LearnerLevelRefresher $levels,
        private readonly FeatureFlags $features,
        private readonly JobDispatcher $jobs,
        private readonly AiTaskRouter $ai,
        private readonly ?FeedbackResearchPolicy $research = null
    ) {
    }

    public function enqueue(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId,
        int $restartAfterJobId = 0
    ): JobEnqueueResult {
        if ($restartAfterJobId < 0) {
            throw new DomainException('다시 요청할 AI 피드백 작업 번호가 올바르지 않습니다.');
        }
        $submission = $this->submissions->findForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId
        );
        if (! $submission instanceof AiFeedbackSubmission) {
            throw new DomainException('AI 피드백을 요청할 수 없는 제출입니다.');
        }
        $problem = $this->problems->findForUser(
            $actorUserId,
            $submission->problemId()
        );
        if (! $problem instanceof LearningProblem) {
            throw new DomainException('AI 피드백에 필요한 문제를 찾을 수 없습니다.');
        }
        $this->assertEligible($submission);
        $research = $this->researchContext($submission);
        $this->assertResearchProblem($research, $problem);
        $profile = $this->profileFor($submission, $research);
        $guide = $research === null ? $this->selectedGuide($profile->level()) : null;
        if ($guide === null && $research === null) {
            throw new DomainException('현재 학습 수준에 사용할 AI 답변 지침이 없습니다.');
        }
        $assignment = $this->ai->snapshot('submission_feedback', true);
        $this->assertResearchRouting($research, $assignment->toArray());
        $baseInput = $this->inputs->base($submission, $problem);
        $inputHash = $this->inputs->hash($baseInput);
        $generationContext = [
            'guide_key' => $guide?->key() ?? 'research-' . $research['study_id'],
            'guide_revision' => $guide?->revision() ?? 1,
            'analysis_status' => $profile->analysisStatus(),
            'level' => $profile->level(),
            'level_policy_version' => $profile->policyVersion(),
            'profile_last_submission_id' => $profile->lastSubmissionId(),
        ];
        if ($research !== null) { $generationContext['research_token'] = $research['token']; }
        $generationContextHash = $this->inputs->hash($generationContext);

        $targetPayload = [
            'submission_id' => $submissionId,
            'base_input' => $baseInput,
            'base_input_hash' => $inputHash,
            'source_hash' => $submission->sourceHash(),
            'problem_hash' => $problem->contentHash(),
            'generation_context_hash' => $generationContextHash,
            'assignment' => $assignment->toArray(),
            'research_token' => $research['token'] ?? null,
        ];
        $inputSummary = [
            'contract_version' => FeedbackInputFactory::CONTRACT_VERSION,
            'assignment_hash' => $assignment->hash(),
            'generation_context_hash' => $generationContextHash,
        ];
        if ($restartAfterJobId > 0) {
            $targetPayload['restart_after_job_id'] = $restartAfterJobId;
            $inputSummary['restart_after_job_id'] = $restartAfterJobId;
        }

        return $this->jobs->enqueue(
            'submission_ai_feedback_generate',
            'single',
            $actorUserId,
            [new JobTarget(
                'submission',
                (string) $submissionId,
                $targetPayload,
                $inputHash
            )],
            $inputSummary,
            ['submission_id' => $submissionId],
            'ai_feedback.submission.generate'
        );
    }

    public function assertCanAccess(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): void {
        $submission = $this->submissions->findForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId
        );
        if (! $submission instanceof AiFeedbackSubmission) {
            throw new DomainException('현재 권한이나 수행 모드에서는 AI 피드백을 볼 수 없습니다.');
        }
        $this->researchContext($submission);
    }

    public function find(int $submissionId): ?SubmissionFeedback
    {
        return $this->feedback->find($submissionId);
    }

    public function findCurrentForActor(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): ?SubmissionFeedback {
        return $this->findForActor($actorUserId, $actorUserLogin, $submissionId, true);
    }

    public function findForDisplayForActor(int $actorUserId, string $actorUserLogin, int $submissionId): ?SubmissionFeedback
    {
        return $this->findForActor($actorUserId, $actorUserLogin, $submissionId, false);
    }

    private function findForActor(int $actorUserId, string $actorUserLogin, int $submissionId, bool $currentOnly): ?SubmissionFeedback
    {
        $submission = $this->submissions->findForActor(
            $actorUserId,
            $actorUserLogin,
            $submissionId
        );
        if (! $submission instanceof AiFeedbackSubmission) {
            throw new DomainException(
                '현재 권한이나 수행 모드에서는 AI 피드백을 볼 수 없습니다.'
            );
        }
        $research = $this->researchContext($submission);
        $problem = $this->problems->findForUser(
            $actorUserId,
            $submission->problemId()
        );
        $feedback = $this->feedback->find($submissionId);
        if (
            ! $problem instanceof LearningProblem
            || ! $feedback instanceof SubmissionFeedback
            || (($currentOnly || $research !== null) && ! $this->isCurrent($feedback, $submission, $problem))
            || ($feedback->metadata()['research_token'] ?? null) !== ($research['token'] ?? null)
        ) {
            return null;
        }

        if ($research !== null) { $this->recordResearch($research, 'delivered', $feedback); }
        return $feedback;
    }

    public function isCurrent(
        SubmissionFeedback $feedback,
        AiFeedbackSubmission $submission,
        LearningProblem $problem
    ): bool {
        return hash_equals(
            $feedback->sourceHash(),
            $submission->sourceHash()
        )
            && hash_equals(
                $feedback->problemHash(),
                $problem->contentHash()
            )
            && $feedback->resultCode() === $submission->resultCode();
    }

    public function canGenerate(AiFeedbackSubmission $submission): bool
    {
        try {
            $this->assertCanGenerate($submission);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function assertCanGenerate(AiFeedbackSubmission $submission): void
    {
        $this->assertEligible($submission);
    }

    public function selectedGuide(?string $level): ?\CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide
    {
        $key = $this->settings->current()->guideKey($level);
        $guide = $this->guides->find($key);

        return $guide?->isActive() ? $guide : null;
    }

    /**
     * @return list<SubmissionFeedback>
     */
    public function recent(int $limit = 100): array
    {
        return $this->feedback->recent($limit);
    }

    private function assertEligible(AiFeedbackSubmission $submission): void
    {
        $this->researchContext($submission);
        try {
            $enabled = $this->features->isEnabled('ai_feedback');
        } catch (\Throwable) {
            $enabled = false;
        }
        if (! $enabled) {
            throw new DomainException('제출 AI 피드백 기능이 꺼져 있습니다.');
        }
        $settings = $this->settings->current();
        if (! $settings->isEnabled()) {
            throw new DomainException('제출 AI 피드백 기능이 꺼져 있습니다.');
        }
        if (
            ! $submission->isValid()
            || $submission->isProcessing()
            || trim($submission->source()) === ''
        ) {
            throw new DomainException('채점이 완료된 유효한 제출만 AI 피드백을 요청할 수 있습니다.');
        }
        if (
            $settings->resultScope() === 'incorrect'
            && $submission->resultCode() === 4
        ) {
            throw new DomainException('현재 설정에서는 오답과 오류 결과만 AI 피드백을 요청할 수 있습니다.');
        }
        if (! $this->ai->canRoute('submission_feedback', true)) {
            throw new DomainException('제출 코드 전송을 허용한 AI 연결이 준비되지 않았습니다.');
        }
    }

    /** @return array<string,mixed>|null */
    public function researchContext(AiFeedbackSubmission $submission): ?array
    {
        return $this->research?->context($submission);
    }

    public function profileFor(AiFeedbackSubmission $submission, ?array $research): LearnerLevelProfile
    {
        return $research === null ? $this->levels->refresh($submission->ownerUserId())
            : LearnerLevelProfile::fromRow($research['baseline']);
    }

    public function assertResearchRouting(?array $research, array $assignment): void
    {
        if ($research !== null && $research['routing'] !== $assignment) {
            throw new DomainException('연구 시작 이후 AI 연결이 변경되었습니다. 연구 운영자에게 알려 주세요.');
        }
    }

    public function assertResearchProblem(?array $research, LearningProblem $problem): void
    {
        if ($research !== null && ! hash_equals($research['problem_hash'], $problem->contentHash())) {
            throw new DomainException('연구 시작 이후 학습 문제 내용이 변경되었습니다. 연구 운영자에게 알려 주세요.');
        }
    }

    public function recordResearch(array $context, string $event, SubmissionFeedback $feedback): void
    {
        $this->research?->record($context, $event, [
            'submission_id' => $feedback->submissionId(), 'input_hash' => $feedback->inputHash(),
            'source_hash' => $feedback->sourceHash(), 'problem_hash' => $feedback->problemHash(),
            'result_code' => $feedback->resultCode(), 'provider' => $feedback->providerKey(),
            'model' => $feedback->modelKey(), 'profile_fingerprint' => $feedback->profileFingerprint(),
            'content' => $feedback->content(),
        ]);
    }
}

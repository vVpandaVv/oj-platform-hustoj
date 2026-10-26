<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;
use InvalidArgumentException;

final class SubmissionAiFeedbackJobHandler implements JobHandler
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly AiFeedbackSubmissionProvider $submissions,
        private readonly LearningProblemProvider $problems,
        private readonly LearnerLevelService $levels,
        private readonly FeedbackGuideRepository $guides,
        private readonly AiFeedbackSettingsService $settings,
        private readonly FeedbackInputFactory $inputs,
        private readonly FeedbackOutputValidator $outputs,
        private readonly SubmissionFeedbackRepository $feedback,
        private readonly SubmissionFeedbackReusePolicy $reuse,
        private readonly SubmissionFeedbackJobService $feedbackJobs,
        private readonly AiTaskRouter $ai,
        private readonly Clock $clock
    ) {
    }

    public function jobType(): string
    {
        return 'submission_ai_feedback_generate';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        try {
            $payload = $item->payload();
            if (! is_array($payload)) {
                throw new DomainException('AI 피드백 작업 입력이 올바르지 않습니다.');
            }
            $submissionId = $this->positiveInt(
                $payload['submission_id'] ?? null
            );
            $actor = $this->users->findById(
                $item->claim()->createdByUserId()
            );
            if ($actor === null) {
                return JobHandlerResult::needsAttention(
                    'ai_feedback.actor_missing',
                    'AI 피드백을 요청한 사용자를 찾을 수 없습니다.'
                );
            }
            $submission = $this->submissions->findForActor(
                $actor->userId(),
                $actor->userLogin(),
                $submissionId
            );
            if (! $submission instanceof AiFeedbackSubmission) {
                return JobHandlerResult::needsAttention(
                    'ai_feedback.permission_changed',
                    '현재 권한이나 수행 모드에서는 제출 상세를 볼 수 없습니다.'
                );
            }
            $this->feedbackJobs->assertCanGenerate($submission);
            $problem = $this->problems->findForUser(
                $actor->userId(),
                $submission->problemId()
            );
            if (! $problem instanceof LearningProblem) {
                return JobHandlerResult::needsAttention(
                    'ai_feedback.problem_changed',
                    '현재 권한으로 문제 원문을 읽을 수 없습니다.'
                );
            }
            $baseInput = $this->inputs->base($submission, $problem);
            $expectedHash = (string) ($payload['base_input_hash'] ?? '');
            if (
                ! hash_equals($expectedHash, $this->inputs->hash($baseInput))
                || ! hash_equals(
                    (string) ($payload['source_hash'] ?? ''),
                    $submission->sourceHash()
                )
                || ! hash_equals(
                    (string) ($payload['problem_hash'] ?? ''),
                    $problem->contentHash()
                )
            ) {
                return JobHandlerResult::needsAttention(
                    'ai_feedback.input_changed',
                    '제출 코드나 문제 원문이 작업 등록 이후 변경되었습니다.'
                );
            }
            $research = $this->feedbackJobs->researchContext($submission);
            $this->feedbackJobs->assertResearchProblem($research, $problem);
            if (($payload['research_token'] ?? null) !== ($research['token'] ?? null)) {
                throw new DomainException('연구 조건이나 단계가 작업 등록 이후 변경되었습니다.');
            }
            $profile = $this->feedbackJobs->profileFor($submission, $research);
            $guideKey = $research === null ? $this->settings->current()->guideKey($profile->level()) : 'research-' . $research['study_id'];
            $guide = $research === null ? $this->guides->find($guideKey) : null;
            if ($research === null && ($guide === null || ! $guide->isActive())) {
                return JobHandlerResult::needsAttention(
                    'ai_feedback.guide_missing',
                    '현재 수준에 사용할 AI 답변 지침을 찾을 수 없습니다.'
                );
            }
            $guideRevision = $guide?->revision() ?? 1;
            $input = $research === null ? $this->inputs->complete($baseInput, $profile, $guide)
                : $this->inputs->research($baseInput, $research);
            $assignmentValue = $payload['assignment'] ?? null;
            if (! is_array($assignmentValue)) {
                throw new DomainException('AI 작업 배정 정보가 없습니다.');
            }
            $assignment = AiTaskAssignmentSnapshot::fromArray(
                $assignmentValue
            );
            $this->feedbackJobs->assertResearchRouting($research, $assignment->toArray());
            $this->feedbackJobs->assertResearchRouting($research, $this->ai->snapshot('submission_feedback', true)->toArray());
            $inputHash = $this->inputs->hash($input);
            $existing = $this->feedback->find($submissionId);
            if (($existing?->metadata()['research_token'] ?? null) === ($research['token'] ?? null) && $this->reuse->matches(
                $existing,
                $submission,
                $problem,
                $profile,
                $guideKey,
                $guideRevision,
                $assignment,
                $inputHash
            )) {
                return JobHandlerResult::succeeded(
                    'ai_feedback.reused',
                    '같은 입력으로 만든 기존 AI 피드백을 사용합니다.',
                    [
                        'submission_id' => $submissionId,
                        'reused' => true,
                    ]
                );
            }
            $response = $this->ai->execute(
                $assignment,
                new AiTaskRequest(
                    'submission_feedback',
                    FeedbackInputFactory::CONTRACT_VERSION,
                    $input
                )
            );
            $content = $this->outputs->validate($response->output());
            if ($research !== null && strlen(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 8000) {
                throw new DomainException('연구 피드백의 공통 응답 길이 상한을 넘었습니다.');
            }
            $currentResearch = $this->feedbackJobs->researchContext($submission);
            if (($currentResearch['token'] ?? null) !== ($research['token'] ?? null)) {
                throw new DomainException('연구 단계가 바뀌어 생성한 피드백을 공개하지 않습니다.');
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $savedFeedback = new SubmissionFeedback(
                $submissionId,
                $content,
                $inputHash,
                $submission->sourceHash(),
                $problem->contentHash(),
                $submission->resultCode(),
                $guideKey,
                $guideRevision,
                $profile->analysisStatus(),
                $profile->level(),
                $profile->policyVersion(),
                $profile->lastSubmissionId(),
                $assignment->routingRevision(),
                $response->profileKey(),
                $response->providerKey(),
                $response->profileFingerprint(),
                $response->modelKey(),
                [
                    'contract_version' => FeedbackInputFactory::CONTRACT_VERSION,
                    'difficulty_source' => $problem->difficultySource(),
                    'research_token' => $research['token'] ?? null,
                ],
                $existing?->createdAt() ?? $now,
                $now
            );
            if ($research !== null) { $this->feedbackJobs->recordResearch($research, 'generated', $savedFeedback); }
            $this->feedback->save($savedFeedback);

            return JobHandlerResult::succeeded(
                'ai_feedback.ready',
                '제출 AI 피드백을 만들었습니다.',
                [
                    'submission_id' => $submissionId,
                ]
            );
        } catch (AiProviderException $error) {
            return $error->isTransient()
                ? JobHandlerResult::retry(
                    'ai_feedback.provider_transient',
                    $error->getMessage(),
                    60
                )
                : JobHandlerResult::needsAttention(
                    'ai_feedback.provider_failed',
                    $error->getMessage()
                );
        } catch (DomainException | InvalidArgumentException $error) {
            return JobHandlerResult::needsAttention(
                'ai_feedback.contract_failed',
                $error->getMessage()
            );
        }
    }

    private function positiveInt(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw new DomainException('AI 피드백 제출 번호가 올바르지 않습니다.');
        }

        return $value;
    }
}

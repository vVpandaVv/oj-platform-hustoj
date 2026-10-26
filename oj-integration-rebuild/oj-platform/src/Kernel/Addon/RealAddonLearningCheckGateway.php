<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use Throwable;

/** @internal No test files, judge wrapper source, errors, or other learners are projected. */
final class RealAddonLearningCheckGateway implements AddonLearningCheckGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly ProblemCatalogService $problems,
        private readonly SubmissionQueryService $submissions,
        private readonly AiFeedbackSubmissionProvider $feedbackSubmissions,
        private readonly WordPressUserGateway $users,
        private readonly AddonCorrelationIdFactory $correlations,
        private readonly ?ContestSubmissionAccess $contests = null,
        private readonly ?GroupContextAccess $groups = null
    ) {
    }

    public function context(AddonLearningCheckContextQueryV1 $query): AddonLearningCheckContextResultV1
    {
        try {
            $actor = $this->users->currentUser();
            if ($actor === null || $actor->userId() !== $query->actorUserId()) {
                return $this->denied();
            }
            if ($query->contextType() === 'contest'
                && ($this->contests === null || ! $this->contests->allowsAiFeedback($query->contextId() ?? 0))
            ) {
                return $this->denied();
            }
            if ($query->contextType() === 'group'
                && ($this->groups === null || ! $this->groups->isActiveMember($query->contextId() ?? 0, $actor->userId()))
            ) {
                return $this->denied();
            }
            $access = match ($query->contextType()) {
                'class' => ProblemAccessContext::class($query->contextId() ?? 0),
                'contest' => ProblemAccessContext::contest($query->contextId() ?? 0),
                default => ProblemAccessContext::general(),
            };
            $problem = $this->problems->findVisible($actor->userId(), new ProblemId($query->problemId()), $access);
            if ($problem === null) {
                return $this->denied();
            }
            $submission = null;
            $latestOwnSubmissionId = null;
            if ($query->projection() === 'problem') {
                // Public problem validation needs no submission list, count or source.
            } elseif ($query->submissionId() !== null) {
                $submission = $this->feedbackSubmissions->findForActor(
                    $actor->userId(), $actor->userLogin(), $query->submissionId()
                );
                if (! $this->isOwnValid($submission, $query) || $submission->submissionId() !== $query->submissionId()) {
                    return $this->denied();
                }
            } elseif ($query->projection() === 'latest') {
                $summary = $this->submissions->latestOwnForProblem($actor->userLogin(), $query->problemId());
                $latestOwnSubmissionId = 0;
                if ($summary !== null && $summary->problemId() === $query->problemId()
                    && $summary->belongsTo($actor->userLogin()) && $summary->isValid()) {
                    $latestOwnSubmissionId = $summary->submissionId()->value();
                    $candidate = $this->feedbackSubmissions->findForActor(
                        $actor->userId(), $actor->userLogin(), $latestOwnSubmissionId
                    );
                    if ($this->isOwnValid($candidate, $query) && $candidate->submissionId() === $latestOwnSubmissionId) {
                        $submission = $candidate;
                    }
                }
            } else {
                // One page, at most six source reads, scoped to the current problem and learner.
                // The provider rechecks full source visibility and contest feedback policy.
                $page = $this->submissions->ownPage($actor->userLogin(), $query->problemId(), 1);
                // A source-hidden old submission must still be part of a new-submission baseline.
                // This only projects an ID already present in the current learner's own summary list.
                $latestOwnSubmissionId = 0;
                foreach ($page['rows'] as $summary) {
                    if ($summary->problemId() === $query->problemId() && $summary->belongsTo($actor->userLogin()) && $summary->isValid()) {
                        $latestOwnSubmissionId = max($latestOwnSubmissionId, $summary->submissionId()->value());
                    }
                }
                foreach (array_slice($page['rows'], 0, 6) as $summary) {
                    if ($summary->problemId() !== $query->problemId() || ! $summary->belongsTo($actor->userLogin())) {
                        continue;
                    }
                    $candidate = $this->feedbackSubmissions->findForActor(
                        $actor->userId(), $actor->userLogin(), $summary->submissionId()->value()
                    );
                    if ($this->isOwnValid($candidate, $query) && $candidate->submissionId() === $summary->submissionId()->value()) {
                        $submission = $candidate;
                        break;
                    }
                }
            }
            $publicProblem = [
                'id' => $problem->id()->value(),
                'title' => $problem->title(),
                'description' => $problem->description(),
                'input' => $problem->input(),
                'output' => $problem->output(),
                'sample_input' => $problem->sampleInput(),
                'sample_output' => $problem->sampleOutput(),
            ];
            $publicProblem['content_hash'] = hash('sha256', json_encode($publicProblem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return new AddonLearningCheckContextResultV1(true, [
                'problem' => $publicProblem,
                'latest_own_submission_id' => $latestOwnSubmissionId,
                'submission' => $submission === null ? null : [
                    'id' => $submission->submissionId(),
                    'problem_id' => $submission->problemId(),
                    'source' => $submission->source(),
                    'language_id' => $submission->languageId(),
                    'language' => $this->language($submission->languageId()),
                    'result_code' => $submission->resultCode(),
                    'submitted_at' => $submission->submittedAt(),
                    'processing' => $submission->isProcessing(),
                ],
            ], null);
        } catch (Throwable) {
            return new AddonLearningCheckContextResultV1(false, [], new AddonApiProblemV1(
                'addon_runtime_unavailable',
                '학습 확인에 필요한 자료를 사용할 수 없습니다.',
                $this->correlations->next($this->addonId . ':learning-check'),
                true
            ));
        }
    }

    private function isOwnValid(?AiFeedbackSubmission $submission, AddonLearningCheckContextQueryV1 $query): bool
    {
        return $submission !== null
            && $submission->ownerUserId() === $query->actorUserId()
            && $submission->problemId() === $query->problemId()
            && $submission->isValid()
            && strlen($submission->source()) <= 65536;
    }

    private function language(int $languageId): string
    {
        $catalog = new JudgeLanguageCatalog();
        $canonicalId = match ($languageId) {
            13 => $catalog->idForKey('c'),
            14 => $catalog->idForKey('cpp'),
            default => $languageId,
        };
        foreach (['python', 'c', 'cpp', 'java'] as $key) {
            if ($catalog->idForKey($key) === $canonicalId) {
                return $key;
            }
        }
        return 'unknown';
    }

    private function denied(): AddonLearningCheckContextResultV1
    {
        return new AddonLearningCheckContextResultV1(false, [], new AddonApiProblemV1(
            'addon_access_denied',
            '이 풀이의 학습 확인 자료에 접근할 수 없습니다.',
            $this->correlations->next($this->addonId . ':learning-check'),
            false
        ));
    }
}

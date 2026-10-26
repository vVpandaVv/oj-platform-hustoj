<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;

final class AiFeedbackSubmissionService implements AiFeedbackSubmissionProvider
{
    public function __construct(
        private readonly SubmissionQueryService $submissions,
        private readonly WordPressUserGateway $users,
        private readonly SubmissionAiFeedbackPolicyRegistry $policies
    ) {
    }

    public function findForActor(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): ?AiFeedbackSubmission {
        if ($actorUserId < 1 || $submissionId < 1) {
            return null;
        }
        $view = $this->submissions->view(
            $actorUserId,
            $actorUserLogin,
            new SubmissionId($submissionId)
        );
        if (
            $view === null
            || $view->access() !== SubmissionAccessLevel::Full
            || $view->detail() === null
        ) {
            return null;
        }
        $summary = $view->summary();
        $detail = $view->detail();
        if (! $this->policies->allows($summary)) {
            return null;
        }
        $owner = $this->users->findByLogin($summary->userLogin());
        if ($owner === null) {
            return null;
        }

        return new AiFeedbackSubmission(
            $summary->submissionId()->value(),
            $summary->problemId(),
            $owner->userId(),
            $summary->languageId(),
            $summary->resultCode(),
            $summary->timeMilliseconds(),
            $summary->memoryKilobytes(),
            $summary->submittedAt(),
            $detail->userSource(),
            $detail->compileError(),
            $detail->runtimeError(),
            $summary->isValid(),
            $this->submissions->result($summary->resultCode())->isProcessing()
        );
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

interface SubmissionGateway
{
    /**
     * @return list<SubmissionSummary>
     */
    public function search(SubmissionSearch $search): array;

    public function count(SubmissionSearch $search): int;

    public function findSummary(SubmissionId $submissionId): ?SubmissionSummary;

    /**
     * @param list<SubmissionId> $submissionIds
     * @return array<int, SubmissionSummary>
     */
    public function findSummaries(array $submissionIds): array;

    public function findDetail(SubmissionId $submissionId): ?SubmissionDetail;

    public function createPrepared(PreparedSubmission $submission): SubmissionId;

    public function storeSources(
        SubmissionId $submissionId,
        string $userSource,
        string $judgeSource
    ): void;

    public function sourcesMatch(
        SubmissionId $submissionId,
        string $userSha256,
        string $judgeSha256,
        int $userBytes,
        int $judgeBytes
    ): bool;

    public function publish(SubmissionId $submissionId): void;

    public function discardPrepared(SubmissionId $submissionId): void;

    public function markRecoveryRequired(SubmissionId $submissionId): void;

    public function latestSubmissionTimestamp(string $userLogin): ?int;

    public function hasAccepted(
        string $userLogin,
        int $problemId,
        SubmissionContext $context
    ): bool;

    public function rejudge(SubmissionId $submissionId): int;

    public function setValid(SubmissionId $submissionId, bool $valid): bool;
}

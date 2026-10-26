<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class SubmissionProjectionService implements SubmissionProjectionProvider
{
    private const CONTEXT_BATCH_SIZE = 500;
    private const JUDGE_BATCH_SIZE = 100;

    public function __construct(
        private readonly SubmissionGateway $submissions,
        private readonly SubmissionContextStore $contexts,
        private readonly SubmissionVisibilityPolicy $visibility,
        private readonly JudgeResults $results
    ) {
    }

    public function byIds(
        int $actorUserId,
        string $actorUserLogin,
        array $submissionIds
    ): array {
        $ids = [];
        foreach ($submissionIds as $value) {
            if (is_int($value) && $value > 0) {
                $ids[$value] = new SubmissionId($value);
            }
        }
        if ($ids === []) {
            return [];
        }
        $summaries = $this->withContexts(array_values(
            $this->submissions->findSummaries(array_values($ids))
        ));

        return $this->project(
            $actorUserId,
            $actorUserLogin,
            $summaries
        );
    }

    public function search(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return $this->project(
            $actorUserId,
            $actorUserLogin,
            $this->summaries($query)
        );
    }

    public function report(SubmissionProjectionQuery $query): array
    {
        return array_map(
            fn (SubmissionSummary $summary): SubmissionProjection =>
                $this->projection($summary),
            $this->summaries($query)
        );
    }

    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return $this->project(
            $actorUserId,
            $actorUserLogin,
            $this->allSummaries($query)
        );
    }

    public function reportAll(SubmissionProjectionQuery $query): array
    {
        return array_map(
            fn (SubmissionSummary $summary): SubmissionProjection =>
                $this->projection($summary),
            $this->allSummaries($query)
        );
    }

    /** @return list<SubmissionSummary> */
    private function allSummaries(SubmissionProjectionQuery $query): array
    {
        $summaries = [];
        if ($query->classId() !== null || $query->groupId() !== null) {
            $offset = 0;
            $cursor = null;
            do {
                $ids = $this->contextIds($query, $offset, $cursor);
                $summaryMap = $this->filteredSummaries($ids, $query);
                foreach ($ids as $id) {
                    $summary = $summaryMap[$id] ?? null;
                    if (
                        $summary instanceof SubmissionSummary
                        && $this->matches($summary, $query)
                    ) {
                        $summaries[] = $summary;
                    }
                }
                $offset += count($ids);
                if ($ids !== []) { $cursor = min($ids); }
            } while (count($ids) === self::CONTEXT_BATCH_SIZE);

            return $this->withContexts($summaries);
        }

        $offset = 0;
        do {
            $batch = $this->submissions->search(new SubmissionSearch(
                null,
                null,
                $query->resultCode(),
                null,
                $query->valid(),
                '',
                self::JUDGE_BATCH_SIZE,
                $offset,
                $query->userLogins(),
                $query->problemIds(),
                $query->contestId(),
                $query->submittedFrom(),
                $query->submittedUntil()
            ));
            array_push($summaries, ...$batch);
            $offset += count($batch);
        } while (count($batch) === self::JUDGE_BATCH_SIZE);

        return $this->withContexts($summaries);
    }
    /**
     * @return list<SubmissionSummary>
     */
    private function summaries(SubmissionProjectionQuery $query): array
    {
        if ($query->classId() !== null || $query->groupId() !== null) {
            return $this->contextSummaries($query);
        }

        $remaining = $query->limit();
        $offset = $query->offset();
        $summaries = [];
        while ($remaining > 0) {
            $batchSize = min(100, $remaining);
            $batch = $this->submissions->search(new SubmissionSearch(
                null,
                null,
                $query->resultCode(),
                null,
                $query->valid(),
                '',
                $batchSize,
                $offset,
                $query->userLogins(),
                $query->problemIds(),
                $query->contestId(),
                $query->submittedFrom(),
                $query->submittedUntil()
            ));
            array_push($summaries, ...$batch);
            $count = count($batch);
            if ($count < $batchSize) {
                break;
            }
            $remaining -= $count;
            $offset += $count;
        }

        return $this->withContexts($summaries);
    }

    /**
     * @return list<SubmissionSummary>
     */
    private function contextSummaries(
        SubmissionProjectionQuery $query
    ): array {
        $needed = $query->offset() + $query->limit();
        $matched = [];
        $contextOffset = 0;
        $cursor = null;

        while (count($matched) < $needed) {
            $ids = $this->contextIds($query, $contextOffset, $cursor);
            if ($ids === []) {
                break;
            }
            $summaryMap = $this->filteredSummaries($ids, $query);
            foreach ($ids as $id) {
                $summary = $summaryMap[$id] ?? null;
                if (
                    $summary instanceof SubmissionSummary
                    && $this->matches($summary, $query)
                ) {
                    $matched[] = $summary;
                }
            }
            $contextOffset += count($ids);
            $cursor = min($ids);
            if (count($ids) < self::CONTEXT_BATCH_SIZE) {
                break;
            }
        }

        return $this->withContexts(array_slice(
            $matched,
            $query->offset(),
            $query->limit()
        ));
    }

    /** @return list<int> */
    private function contextIds(SubmissionProjectionQuery $query, int $offset, ?int $cursor): array
    {
        return $this->contexts instanceof \CodingPanda\OjPlatform\Modules\Submissions\Contract\CursorSubmissionContextStore
            ? $this->contexts->submissionIdsBefore($query->classId(), $query->groupId(), self::CONTEXT_BATCH_SIZE, $cursor)
            : $this->contexts->submissionIds($query->classId(), $query->groupId(), self::CONTEXT_BATCH_SIZE, $offset);
    }

    /** @param list<int> $ids @return array<int, SubmissionSummary> */
    private function filteredSummaries(array $ids, SubmissionProjectionQuery $query): array
    {
        $keys = array_map(static fn (int $id): SubmissionId => new SubmissionId($id), $ids);
        return $this->submissions instanceof \CodingPanda\OjPlatform\Modules\Submissions\Contract\FilteredSubmissionGateway
            ? $this->submissions->findFilteredSummaries($keys, $query) : $this->submissions->findSummaries($keys);
    }

    private function matches(
        SubmissionSummary $summary,
        SubmissionProjectionQuery $query
    ): bool {
        if (
            $query->userLogins() !== []
            && ! in_array($summary->userLogin(), $query->userLogins(), true)
        ) {
            return false;
        }
        if (
            $query->problemIds() !== []
            && ! in_array($summary->problemId(), $query->problemIds(), true)
        ) {
            return false;
        }
        if (
            $query->contestId() !== null
            && $summary->contestId() !== $query->contestId()
        ) {
            return false;
        }
        if (
            $query->valid() !== null
            && $summary->isValid() !== $query->valid()
        ) {
            return false;
        }
        if (
            $query->submittedFrom() !== null
            && $summary->submittedAt() < $query->submittedFrom()
        ) {
            return false;
        }
        if (
            $query->submittedUntil() !== null
            && $summary->submittedAt() > $query->submittedUntil()
        ) {
            return false;
        }

        return $query->resultCode() === null
            || $summary->resultCode() === $query->resultCode();
    }

    /**
     * @param list<SubmissionSummary> $summaries
     * @return list<SubmissionSummary>
     */
    private function withContexts(array $summaries): array
    {
        $stored = $this->contexts->findMany(array_map(
            static fn (SubmissionSummary $summary): SubmissionId =>
                $summary->submissionId(),
            $summaries
        ));

        return array_map(
            static function (SubmissionSummary $summary) use ($stored): SubmissionSummary {
                $context = $stored[$summary->submissionId()->value()] ?? null;

                return $context === null
                    ? $summary
                    : $summary->withContext(
                        $context->classId(),
                        $context->groupId()
                    );
            },
            $summaries
        );
    }

    /**
     * @param list<SubmissionSummary> $summaries
     * @return list<SubmissionProjection>
     */
    private function project(
        int $actorUserId,
        string $actorUserLogin,
        array $summaries
    ): array {
        $projections = [];
        foreach ($summaries as $summary) {
            $access = $this->visibility->decide(
                $actorUserId,
                $actorUserLogin,
                $summary
            );
            if ($access === SubmissionAccessLevel::Denied) {
                continue;
            }
            $projections[] = $this->projection($summary);
        }

        return $projections;
    }

    private function projection(SubmissionSummary $summary): SubmissionProjection
    {
        $result = $this->results->find($summary->resultCode());

        return new SubmissionProjection(
            $summary->submissionId()->value(),
            $summary->problemId(),
            $summary->userLogin(),
            $summary->resultCode(),
            $summary->languageId(),
            $summary->timeMilliseconds(),
            $summary->memoryKilobytes(),
            $summary->submittedAt(),
            $summary->isValid(),
            $summary->passRate(),
            $summary->contestId(),
            $result->label(),
            $result->isProcessing()
        );
    }
}

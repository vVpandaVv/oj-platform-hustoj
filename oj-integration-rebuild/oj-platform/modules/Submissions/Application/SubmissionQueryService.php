<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionManagedScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionPollResult;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionView;
use RuntimeException;

final class SubmissionQueryService
{
    private const SCOPE_CONTEXT_BATCH_SIZE = 500;
    private const SCOPE_JUDGE_BATCH_SIZE = 100;

    /**
     * @var array<int, true>
     */
    private array $unknownResults = [];

    public function __construct(
        private readonly SubmissionGateway $submissions,
        private readonly SubmissionContextStore $contexts,
        private readonly SubmissionEventStore $events,
        private readonly SubmissionVisibilityPolicy $visibility,
        private readonly SubmissionSettingsProvider $settings,
        private readonly JudgeResults $results,
        private readonly ErrorReporter $errors
    ) {
    }

    /**
     * @return array{
     *   rows: list<SubmissionSummary>,
     *   total: int,
     *   page: int,
     *   page_size: int
     * }
     */
    public function ownPage(
        string $userLogin,
        ?int $problemId,
        int $page
    ): array {
        $pageSize = $this->settings->current()->pageSize();
        $search = new SubmissionSearch(
            $userLogin,
            $problemId,
            null,
            null,
            true,
            '',
            $pageSize,
            (max(1, $page) - 1) * $pageSize
        );

        return $this->page($search, $page, $pageSize);
    }

    public function latestOwnForProblem(string $userLogin, int $problemId): ?SubmissionSummary
    {
        if (trim($userLogin) === '' || $problemId < 1) { return null; }
        $rows = $this->contextualize($this->submissions->search(new SubmissionSearch(
            userLogin: $userLogin, problemId: $problemId, valid: true, limit: 1
        )));
        return $rows[0] ?? null;
    }

    public function hasAcceptedOwn(string $userLogin, int $problemId): bool
    {
        if (trim($userLogin) === '') {
            return false;
        }

        return $this->submissions->search(new SubmissionSearch(
            userLogin: $userLogin,
            problemId: $problemId,
            resultCode: 4,
            valid: true,
            limit: 1
        )) !== [];
    }

    /** @return list<SubmissionSummary> */
    public function recentOwn(string $userLogin, int $limit = 6): array
    {
        $limit = max(1, min(6, $limit));

        return $this->contextualize($this->submissions->search(
            new SubmissionSearch(
                $userLogin,
                null,
                null,
                null,
                true,
                '',
                $limit,
                0
            )
        ));
    }

    /**
     * Bounded internal projection used by the Addon API after its Context
     * gateway has authorized every learner ID in the request.
     *
     * @param list<string> $userLogins
     * @param list<int> $problemIds
     * @return list<array{user_login:string,problem_id:int,accepted_at:string}>
     */
    public function acceptedTimesForUsers(array $userLogins, array $problemIds, ?string $submittedFrom, ?string $submittedUntil): array
    {
        $userLogins = array_values(array_unique(array_filter(array_map('trim', $userLogins), static fn (string $login): bool => $login !== '')));
        $problemIds = array_values(array_unique(array_filter($problemIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($userLogins === [] || $problemIds === []) { return []; }
        if ($this->submissions instanceof \CodingPanda\OjPlatform\Modules\Submissions\Contract\AcceptedSubmissionTimeGateway) {
            return $this->submissions->earliestAcceptedTimes($userLogins, $problemIds, $submittedFrom, $submittedUntil, 5000);
        }
        $times = [];
        foreach ($this->acceptedForUsers($userLogins, $problemIds, $submittedFrom, $submittedUntil) as $submission) {
            $key = $submission->userLogin() . ':' . $submission->problemId();
            if (!isset($times[$key]) || $submission->submittedAt() < $times[$key]['accepted_at']) {
                $times[$key] = ['user_login' => $submission->userLogin(), 'problem_id' => $submission->problemId(), 'accepted_at' => $submission->submittedAt()];
            }
        }
        return array_values($times);
    }

    /** @param list<string> $userLogins @param list<int> $problemIds @return list<SubmissionSummary> */
    public function acceptedForUsers(
        array $userLogins,
        array $problemIds,
        ?string $submittedFrom,
        ?string $submittedUntil,
        int $maximum = 5000
    ): array {
        $userLogins = array_values(array_unique(array_filter(
            array_map('trim', $userLogins),
            static fn (string $value): bool => $value !== ''
        )));
        $problemIds = array_values(array_unique(array_filter(
            $problemIds,
            static fn (mixed $value): bool => is_int($value) && $value > 0
        )));
        if ($userLogins === [] || $problemIds === []) {
            return [];
        }
        $maximum = max(1, min(5000, $maximum));
        $rows = [];
        for ($offset = 0; count($rows) < $maximum; $offset += 100) {
            $batch = $this->submissions->search(new SubmissionSearch(
                resultCode: 4,
                valid: true,
                limit: min(100, $maximum - count($rows)),
                offset: $offset,
                userLogins: $userLogins,
                problemIds: $problemIds,
                submittedFrom: $submittedFrom,
                submittedUntil: $submittedUntil
            ));
            foreach ($batch as $row) {
                if ($row instanceof SubmissionSummary) {
                    $rows[] = $row;
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array{
     *   rows: list<SubmissionSummary>,
     *   total: int,
     *   page: int,
     *   page_size: int
     * }
     */
    public function managedPage(
        int $actorUserId,
        string $actorUserLogin,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId,
        ?bool $valid,
        string $keyword,
        int $page,
        ?SubmissionManagedScope $managedScope = null
    ): array {
        $pageSize = $this->settings->current()->pageSize();
        $page = max(1, $page);

        if ($this->visibility->canListAll($actorUserId)) {
            $search = new SubmissionSearch(
                null,
                $problemId,
                $resultCode,
                $languageId,
                $valid,
                $keyword,
                $pageSize,
                ($page - 1) * $pageSize
            );

            return $this->page($search, $page, $pageSize);
        }

        if ($managedScope === null || $managedScope->isEmpty()) {
            $search = new SubmissionSearch(
                $actorUserLogin,
                $problemId,
                $resultCode,
                $languageId,
                $valid,
                $keyword,
                $pageSize,
                ($page - 1) * $pageSize
            );

            return $this->page($search, $page, $pageSize);
        }

        return $this->scopedManagedPage(
            $actorUserLogin,
            $managedScope,
            $problemId,
            $resultCode,
            $languageId,
            $valid,
            trim($keyword),
            $page,
            $pageSize
        );
    }

    /**
     * @return array{
     *   rows: list<SubmissionSummary>,
     *   total: int,
     *   page: int,
     *   page_size: int
     * }
     */
    private function scopedManagedPage(
        string $actorUserLogin,
        SubmissionManagedScope $scope,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId,
        ?bool $valid,
        string $keyword,
        int $page,
        int $pageSize
    ): array {
        $contextIds = [];
        foreach ($scope->classIds() as $classId) {
            $this->collectContextSubmissionIds($classId, null, $contextIds);
        }
        foreach ($scope->groupIds() as $groupId) {
            $this->collectContextSubmissionIds(null, $groupId, $contextIds);
        }
        $matchedIds = [];
        $this->collectContextMatches(
            array_keys($contextIds),
            $problemId,
            $resultCode,
            $languageId,
            $valid,
            $keyword,
            $matchedIds
        );
        $this->collectJudgeMatches(
            $actorUserLogin,
            null,
            $problemId,
            $resultCode,
            $languageId,
            $valid,
            $keyword,
            $matchedIds
        );
        foreach ($scope->contestIds() as $contestId) {
            $this->collectJudgeMatches(
                null,
                $contestId,
                $problemId,
                $resultCode,
                $languageId,
                $valid,
                $keyword,
                $matchedIds
            );
        }
        krsort($matchedIds, SORT_NUMERIC);
        $pageIds = array_slice(
            array_keys($matchedIds),
            ($page - 1) * $pageSize,
            $pageSize
        );
        $summaryMap = $this->submissions->findSummaries(array_map(
            static fn (int $id): SubmissionId => new SubmissionId($id),
            $pageIds
        ));
        $rows = [];
        foreach ($pageIds as $id) {
            $summary = $summaryMap[$id] ?? null;
            if ($summary instanceof SubmissionSummary) {
                $rows[] = $summary;
            }
        }

        return [
            'rows' => $this->contextualize($rows),
            'total' => count($matchedIds),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array<int, true> $submissionIds
     */
    private function collectContextSubmissionIds(
        ?int $classId,
        ?int $groupId,
        array &$submissionIds
    ): void {
        $offset = 0;
        while (true) {
            $ids = $this->contexts->submissionIds(
                $classId,
                $groupId,
                self::SCOPE_CONTEXT_BATCH_SIZE,
                $offset
            );
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                if (is_int($id) && $id > 0) {
                    $submissionIds[$id] = true;
                }
            }
            $offset += count($ids);
            if (count($ids) < self::SCOPE_CONTEXT_BATCH_SIZE) {
                break;
            }
        }
    }

    /**
     * @param list<int> $submissionIds
     * @param array<int, true> $matchedIds keyed by submission ID
     */
    private function collectContextMatches(
        array $submissionIds,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId,
        ?bool $valid,
        string $keyword,
        array &$matchedIds
    ): void {
        foreach (array_chunk($submissionIds, self::SCOPE_CONTEXT_BATCH_SIZE) as $ids) {
            $summaryMap = $this->submissions->findSummaries(array_map(
                static fn (int $id): SubmissionId => new SubmissionId($id),
                $ids
            ));
            foreach ($summaryMap as $summary) {
                if ($this->scopedSummaryMatches(
                    $summary,
                    $problemId,
                    $resultCode,
                    $languageId,
                    $valid,
                    $keyword
                )) {
                    $matchedIds[$summary->submissionId()->value()] = true;
                }
            }
        }
    }

    /** @param array<int, true> $matchedIds keyed by submission ID */
    private function collectJudgeMatches(
        ?string $userLogin,
        ?int $contestId,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId,
        ?bool $valid,
        string $keyword,
        array &$matchedIds
    ): void {
        $offset = 0;
        while (true) {
            $batch = $this->submissions->search(new SubmissionSearch(
                $userLogin,
                $problemId,
                $resultCode,
                $languageId,
                $valid,
                $keyword,
                self::SCOPE_JUDGE_BATCH_SIZE,
                $offset,
                [],
                [],
                $contestId
            ));
            foreach ($batch as $summary) {
                if ($this->scopedSummaryMatches(
                    $summary,
                    $problemId,
                    $resultCode,
                    $languageId,
                    $valid,
                    $keyword
                )) {
                    $matchedIds[$summary->submissionId()->value()] = true;
                }
            }
            $offset += count($batch);
            if (count($batch) < self::SCOPE_JUDGE_BATCH_SIZE) {
                break;
            }
        }
    }

    private function scopedSummaryMatches(
        SubmissionSummary $summary,
        ?int $problemId,
        ?int $resultCode,
        ?int $languageId,
        ?bool $valid,
        string $keyword
    ): bool {
        if ($problemId !== null && $summary->problemId() !== $problemId) {
            return false;
        }
        if ($resultCode !== null && $summary->resultCode() !== $resultCode) {
            return false;
        }
        if ($languageId !== null && $summary->languageId() !== $languageId) {
            return false;
        }
        if ($valid !== null && $summary->isValid() !== $valid) {
            return false;
        }
        if ($keyword === '') {
            return true;
        }

        return str_contains((string) $summary->submissionId()->value(), $keyword)
            || str_contains((string) $summary->problemId(), $keyword)
            || $this->containsCaseInsensitive($summary->userLogin(), $keyword)
            || $this->containsCaseInsensitive($summary->nickname(), $keyword);
    }

    private function containsCaseInsensitive(string $value, string $keyword): bool
    {
        return function_exists('mb_stripos')
            ? mb_stripos($value, $keyword) !== false
            : stripos($value, $keyword) !== false;
    }

    public function reviewSummary(int $actorUserId, string $actorUserLogin, SubmissionId $submissionId): ?SubmissionSummary
    {
        $summary = $this->contextualizeOne($this->submissions->findSummary($submissionId));
        return $summary !== null
            && $this->visibility->decide($actorUserId, $actorUserLogin, $summary) === SubmissionAccessLevel::Full
            ? $summary : null;
    }

    /** @param list<SubmissionId> $ids
     *  @return list<SubmissionSummary> */
    public function reviewSummaries(int $actorUserId, string $actorUserLogin, array $ids): array
    {
        $rows = $this->contextualize(array_values($this->submissions->findSummaries($ids)));
        return array_values(array_filter($rows, fn (SubmissionSummary $row): bool =>
            $this->visibility->decide($actorUserId, $actorUserLogin, $row) === SubmissionAccessLevel::Full
        ));
    }

    public function view(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionId $submissionId
    ): ?SubmissionView {
        $summary = $this->contextualizeOne(
            $this->submissions->findSummary($submissionId)
        );
        if (! $summary instanceof SubmissionSummary) {
            return null;
        }
        $access = $this->visibility->decide(
            $actorUserId,
            $actorUserLogin,
            $summary
        );
        if ($access === SubmissionAccessLevel::Denied) {
            return null;
        }
        if ($access === SubmissionAccessLevel::ResultOnly) {
            return new SubmissionView($summary, $access, null);
        }
        $detail = $this->submissions->findDetail($submissionId);
        if ($detail === null) {
            return null;
        }

        return new SubmissionView($summary, $access, $detail);
    }

    public function poll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionId $submissionId
    ): ?SubmissionPollResult {
        $summary = $this->contextualizeOne(
            $this->submissions->findSummary($submissionId)
        );
        if ($summary === null) {
            return null;
        }
        $access = $this->visibility->decide(
            $actorUserId,
            $actorUserLogin,
            $summary
        );
        if ($access === SubmissionAccessLevel::Denied) {
            return null;
        }

        return new SubmissionPollResult($summary, $access);
    }

    public function result(int $code): JudgeResult
    {
        if (($code < 0 || $code > 17) && ! isset($this->unknownResults[$code])) {
            $this->unknownResults[$code] = true;
            $this->errors->report(new RuntimeException(
                '정의되지 않은 Judge 결과 코드를 읽었습니다: ' . $code
            ));
        }

        return $this->results->find($code);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentEvents(int $limit = 100): array
    {
        return $this->events->recent($limit);
    }

    /**
     * @return array{
     *   rows: list<SubmissionSummary>,
     *   total: int,
     *   page: int,
     *   page_size: int
     * }
     */
    private function contextualizeOne(
        ?SubmissionSummary $summary
    ): ?SubmissionSummary {
        if ($summary === null) {
            return null;
        }

        return $this->contextualize([$summary])[0] ?? $summary;
    }

    /**
     * @param list<SubmissionSummary> $summaries
     * @return list<SubmissionSummary>
     */
    private function contextualize(array $summaries): array
    {
        $contexts = $this->contexts->findMany(array_map(
            static fn (SubmissionSummary $summary): SubmissionId =>
                $summary->submissionId(),
            $summaries
        ));

        return array_map(
            static function (SubmissionSummary $summary) use ($contexts): SubmissionSummary {
                $context = $contexts[$summary->submissionId()->value()] ?? null;

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

    private function page(
        SubmissionSearch $search,
        int $page,
        int $pageSize
    ): array {
        return [
            'rows' => $this->contextualize(
                $this->submissions->search($search)
            ),
            'total' => $this->submissions->count($search),
            'page' => max(1, $page),
            'page_size' => $pageSize,
        ];
    }
}

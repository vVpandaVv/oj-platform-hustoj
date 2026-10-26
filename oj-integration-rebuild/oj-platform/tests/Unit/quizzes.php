<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\IdempotencyKeyFactory;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobExecutionPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemPlan;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizGrader;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizReapplyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemBookRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizBankRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizReapplyRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PersonalProblemBookItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemRankingEntry;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemStatistics;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizBankQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttemptItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizChoice;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizOverview;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizQuestion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizReapplyMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$quizAssertions = 0;

function quizAssert(bool $condition, string $message): void
{
    global $quizAssertions;
    ++$quizAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function quizSame(mixed $expected, mixed $actual, string $message): void
{
    quizAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

final class QuizCapabilityChecker implements CapabilityChecker
{
    /**
     * @param array<int, list<string>> $grants
     */
    public function __construct(private readonly array $grants)
    {
    }

    public function userCan(int $userId, string $capability): bool
    {
        return in_array($capability, $this->grants[$userId] ?? [], true);
    }

    public function isSuperAdmin(int $userId): bool
    {
        return false;
    }
}

final class QuizJobVault implements FormDraftStore
{
    /**
     * @var array<string, FormDraft>
     */
    private array $values = [];

    private int $sequence = 1;

    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference {
        $reference = new FormDraftReference(sprintf(
            'draft:v1:00000000-0000-4000-8000-%012x',
            $this->sequence++
        ));
        $createdAt = time();
        $this->values[$reference->value()] = new FormDraft(
            $reference,
            $draftType,
            $ownerUserId,
            $targetType,
            $targetKey,
            $payload,
            $createdAt,
            $createdAt + $ttlSeconds
        );

        return $reference;
    }

    public function find(
        FormDraftReference $reference,
        int $ownerUserId
    ): ?FormDraft
    {
        $draft = $this->values[$reference->value()] ?? null;

        return $draft?->ownerUserId() === $ownerUserId ? $draft : null;
    }

    public function discard(
        FormDraftReference $reference,
        int $ownerUserId
    ): void {
        if ($this->find($reference, $ownerUserId) !== null) {
            unset($this->values[$reference->value()]);
        }
    }

    public function purgeExpired(int $limit = 100): int
    {
        unset($limit);

        return 0;
    }
}

final class QuizJobQueue implements JobQueue
{
    public ?JobExecutionPlan $plan = null;

    /**
     * @var list<JobItemPlan>
     */
    public array $items = [];

    public function enqueue(
        JobExecutionPlan $plan,
        array $items
    ): JobEnqueueResult {
        $this->plan = $plan;
        $this->items = $items;

        return new JobEnqueueResult(77, true);
    }

    public function claim(
        string $workerId,
        int $leaseSeconds
    ): ?ClaimedJobItem {
        unset($workerId, $leaseSeconds);

        return null;
    }

    public function finish(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void {
        unset($claim, $result);
    }
}

final class QuizAssignments implements ProblemAssignmentRepository
{
    public function __construct(private readonly ProblemAssignment $assignment)
    {
    }

    public function find(ProblemId $problemId): ProblemAssignment
    {
        return $problemId->equals($this->assignment->problemId())
            ? $this->assignment
            : new ProblemAssignment($problemId, null, []);
    }

    public function assignOwner(
        ProblemId $problemId,
        int $ownerUserId,
        int $actorUserId
    ): void {
        unset($problemId, $ownerUserId, $actorUserId);
    }

    public function grant(
        ProblemId $problemId,
        int $userId,
        CollaboratorRole $role,
        int $actorUserId
    ): void {
        unset($problemId, $userId, $role, $actorUserId);
    }

    public function revoke(ProblemId $problemId, int $userId): void
    {
        unset($problemId, $userId);
    }

    public function problemIdsForUser(int $userId): array
    {
        return $this->assignment->isOwner($userId)
            || $this->assignment->collaboratorRole($userId) !== null
            ? [$this->assignment->problemId()->value()]
            : [];
    }
}

final class QuizProblemGateway implements ProblemGateway
{
    public function __construct(private readonly JudgeProblem $problem)
    {
    }

    public function search(ProblemSearch $search): array
    {
        unset($search);

        return [$this->problem];
    }

    public function count(ProblemSearch $search): int
    {
        unset($search);

        return 1;
    }

    public function find(ProblemId $problemId): ?JudgeProblem
    {
        return $problemId->equals($this->problem->id()) ? $this->problem : null;
    }

    public function findMany(array $problemIds): array
    {
        return array_values(array_filter(array_map(
            fn (ProblemId $problemId): ?JudgeProblem =>
                $this->find($problemId),
            $problemIds
        )));
    }

    public function nextId(): ProblemId
    {
        return new ProblemId($this->problem->id()->value() + 1);
    }

    public function createNext(ProblemDraft $draft): ProblemId
    {
        unset($draft);

        return $this->nextId();
    }

    public function create(ProblemId $problemId, ProblemDraft $draft): void
    {
        unset($problemId, $draft);
    }

    public function update(ProblemId $problemId, ProblemDraft $draft): void
    {
        unset($problemId, $draft);
    }

    public function archive(ProblemId $problemId, bool $archived): void
    {
        unset($problemId, $archived);
    }

    public function statistics(ProblemId $problemId): ProblemStatistics
    {
        return new ProblemStatistics($problemId, 0, 0, []);
    }

    /**
     * @return list<ProblemRankingEntry>
     */
    public function ranking(ProblemId $problemId, int $limit = 100): array
    {
        unset($problemId, $limit);

        return [];
    }

    public function availableColumns(): array
    {
        return [];
    }
}

final class QuizProblemAiAvailability implements ProblemAiAvailability
{
    public function isAvailable(string $taskType): bool
    {
        unset($taskType);

        return true;
    }
}

final class QuizMetadata implements ProblemMetadataRepository
{
    public function find(ProblemId $problemId): ?ProblemMetadata
    {
        unset($problemId);

        return null;
    }

    public function findMany(array $problemIds): array
    {
        unset($problemIds);

        return [];
    }

    public function save(ProblemMetadata $metadata): void
    {
        unset($metadata);
    }

    public function move(ProblemId $from, ProblemId $to): void
    {
        unset($from, $to);
    }
}

final class MemoryQuizRepository implements QuizRepository, QuizReapplyRepository
{
    /**
     * @var array<int, QuizRevision>
     */
    private array $drafts = [];

    /**
     * @var array<int, QuizRevision>
     */
    private array $published = [];

    /**
     * @var array<int, list<QuizRevision>>
     */
    private array $history = [];

    /**
     * @var array<int, QuizAttempt>
     */
    private array $attempts = [];

    private int $revisionSequence = 1;
    private int $questionSequence = 1;
    private int $choiceSequence = 1;
    private int $attemptSequence = 1;

    public function overviews(): array
    {
        $problemIds = array_values(array_unique(array_merge(
            array_keys($this->drafts),
            array_keys($this->published)
        )));
        sort($problemIds);
        $overviews = [];
        foreach ($problemIds as $problemId) {
            $graded = array_values(array_filter(
                $this->attempts,
                static fn (QuizAttempt $attempt): bool =>
                    $attempt->problemId()->value() === $problemId
                    && $attempt->status() === 'graded'
            ));
            $average = $graded === []
                ? 0.0
                : array_sum(array_map(
                    static fn (QuizAttempt $attempt): float => $attempt->score(),
                    $graded
                )) / count($graded);
            $overviews[] = new QuizOverview(
                new ProblemId($problemId),
                $this->drafts[$problemId] ?? null,
                $this->published[$problemId] ?? null,
                count($graded),
                $average
            );
        }

        return $overviews;
    }

    public function draft(ProblemId $problemId): ?QuizRevision
    {
        return $this->drafts[$problemId->value()] ?? null;
    }

    public function published(ProblemId $problemId): ?QuizRevision
    {
        return $this->published[$problemId->value()] ?? null;
    }

    public function revision(int $revisionId): ?QuizRevision
    {
        foreach (array_merge($this->drafts, $this->published) as $revision) {
            if ($revision->id() === $revisionId) {
                return $revision;
            }
        }
        foreach ($this->history as $history) {
            foreach ($history as $revision) {
                if ($revision->id() === $revisionId) {
                    return $revision;
                }
            }
        }

        return null;
    }

    public function history(ProblemId $problemId): array
    {
        return array_reverse($this->history[$problemId->value()] ?? []);
    }

    public function saveDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision {
        $current = $this->drafts[$problemId->value()] ?? null;
        $persisted = $this->persist($definition);
        $revision = new QuizRevision(
            $current?->id() ?? $this->revisionSequence++,
            $problemId,
            0,
            'draft',
            false,
            $persisted,
            $persisted->contentHash(),
            $current?->createdByUserId() ?? $actorUserId,
            null,
            null
        );
        $this->drafts[$problemId->value()] = $revision;

        return $revision;
    }

    public function publishDraft(
        ProblemId $problemId,
        QuizDefinition $definition,
        int $actorUserId
    ): QuizRevision {
        $this->saveDraft($problemId, $definition, $actorUserId);
        $history = $this->history[$problemId->value()] ?? [];
        if ($history !== []) {
            $last = array_pop($history);
            $history[] = new QuizRevision(
                $last->id(),
                $last->problemId(),
                $last->revisionNumber(),
                'published',
                false,
                $last->definition(),
                $last->contentHash(),
                $last->createdByUserId(),
                $last->publishedByUserId(),
                $last->publishedAt()
            );
        }
        $persisted = $this->persist($definition);
        $revision = new QuizRevision(
            $this->revisionSequence++,
            $problemId,
            count($history) + 1,
            'published',
            true,
            $persisted,
            $persisted->contentHash(),
            $actorUserId,
            $actorUserId,
            '2026-07-26 00:00:00'
        );
        $history[] = $revision;
        $this->history[$problemId->value()] = $history;
        $this->published[$problemId->value()] = $revision;

        return $revision;
    }

    public function startAttempt(
        QuizRevision $revision,
        int $userId,
        string $attemptKey
    ): QuizAttempt {
        foreach ($this->attempts as $attempt) {
            if ($attempt->attemptKey() === $attemptKey) {
                return $attempt;
            }
            if (
                $attempt->problemId()->equals($revision->problemId())
                && $attempt->userId() === $userId
                && $attempt->status() === 'in_progress'
            ) {
                return $attempt;
            }
        }
        $used = count(array_filter(
            $this->attempts,
            static fn (QuizAttempt $attempt): bool =>
                $attempt->problemId()->equals($revision->problemId())
                && $attempt->userId() === $userId
                && in_array($attempt->status(), ['in_progress', 'graded'], true)
        ));
        if ($used >= $revision->definition()->attemptLimit()) {
            throw new DomainException('응시 횟수를 모두 사용했습니다.');
        }
        $attempt = new QuizAttempt(
            $this->attemptSequence++,
            $attemptKey,
            $revision->problemId(),
            $revision->id(),
            $userId,
            'in_progress',
            0,
            $revision->definition()->maxScore(),
            '2026-07-26 01:00:00',
            null
        );
        $this->attempts[$attempt->id()] = $attempt;

        return $attempt;
    }

    public function activeAttempt(
        ProblemId $problemId,
        int $userId
    ): ?QuizAttempt {
        foreach ($this->attempts as $attempt) {
            if (
                $attempt->problemId()->equals($problemId)
                && $attempt->userId() === $userId
                && $attempt->status() === 'in_progress'
            ) {
                return $attempt;
            }
        }

        return null;
    }

    public function attempt(int $attemptId): ?QuizAttempt
    {
        return $this->attempts[$attemptId] ?? null;
    }

    public function attemptsForUser(ProblemId $problemId, int $userId): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (QuizAttempt $attempt): bool =>
                $attempt->problemId()->equals($problemId)
                && $attempt->userId() === $userId
        ));
    }

    public function finalizeAttempt(
        int $attemptId,
        int $userId,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt {
        $current = $this->attempts[$attemptId] ?? null;
        if ($current === null || $current->userId() !== $userId) {
            throw new DomainException('응시를 찾을 수 없습니다.');
        }
        if ($current->status() === 'graded') {
            return $current;
        }
        $graded = new QuizAttempt(
            $current->id(),
            $current->attemptKey(),
            $current->problemId(),
            $current->revisionId(),
            $current->userId(),
            'graded',
            $score,
            $maxScore,
            $current->startedAt(),
            '2026-07-26 01:05:00',
            $items
        );
        $this->attempts[$attemptId] = $graded;

        return $graded;
    }

    public function candidates(
        ProblemId $problemId,
        int $sourceRevisionId,
        QuizReapplyMode $mode
    ): array {
        $status = $mode === QuizReapplyMode::InProgress
            ? 'in_progress'
            : 'graded';

        return array_values(array_filter(
            $this->attempts,
            static fn (QuizAttempt $attempt): bool =>
                $attempt->problemId()->equals($problemId)
                && $attempt->revisionId() === $sourceRevisionId
                && $attempt->status() === $status
        ));
    }

    public function replaceInProgress(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey
    ): QuizAttempt {
        $existing = $this->reapplyChild(
            $original->id(),
            $target->id(),
            'replacement'
        );
        if ($existing !== null) {
            return $existing;
        }
        $current = $this->attempts[$original->id()] ?? null;
        if ($current === null || $current->status() !== 'in_progress') {
            throw new DomainException('진행 중 재적용 상태가 변경되었습니다.');
        }
        $this->attempts[$current->id()] = new QuizAttempt(
            $current->id(),
            $current->attemptKey(),
            $current->problemId(),
            $current->revisionId(),
            $current->userId(),
            'superseded',
            $current->score(),
            $current->maxScore(),
            $current->startedAt(),
            $current->submittedAt(),
            $current->items(),
            $current->attemptKind(),
            $current->parentAttemptId()
        );
        $replacement = new QuizAttempt(
            $this->attemptSequence++,
            $attemptKey,
            $target->problemId(),
            $target->id(),
            $current->userId(),
            'in_progress',
            0,
            $target->definition()->maxScore(),
            '2026-07-26 03:00:00',
            null,
            [],
            'replacement',
            $current->id()
        );
        $this->attempts[$replacement->id()] = $replacement;

        return $replacement;
    }

    public function createRegrade(
        QuizAttempt $original,
        QuizRevision $target,
        string $attemptKey,
        array $items,
        float $score,
        float $maxScore
    ): QuizAttempt {
        $existing = $this->reapplyChild(
            $original->id(),
            $target->id(),
            'regrade'
        );
        if ($existing !== null) {
            return $existing;
        }
        $current = $this->attempts[$original->id()] ?? null;
        if ($current === null || $current->status() !== 'graded') {
            throw new DomainException('재채점 상태가 변경되었습니다.');
        }
        $regrade = new QuizAttempt(
            $this->attemptSequence++,
            $attemptKey,
            $target->problemId(),
            $target->id(),
            $current->userId(),
            'graded',
            $score,
            $maxScore,
            $current->startedAt(),
            $current->submittedAt(),
            $items,
            'regrade',
            $current->id()
        );
        $this->attempts[$regrade->id()] = $regrade;

        return $regrade;
    }

    public function statistics(
        ProblemId $problemId,
        int $revisionId
    ): array {
        $revision = $this->revision($revisionId);
        $attempts = array_values(array_filter(
            $this->attempts,
            static fn (QuizAttempt $attempt): bool =>
                $attempt->problemId()->equals($problemId)
                && $attempt->revisionId() === $revisionId
                && $attempt->status() === 'graded'
        ));
        $questionRows = [];
        foreach ($revision?->definition()->questions() ?? [] as $question) {
            $items = [];
            foreach ($attempts as $attempt) {
                foreach ($attempt->items() as $item) {
                    if ($item->questionId() === $question->id()) {
                        $items[] = $item;
                    }
                }
            }
            $questionRows[] = [
                'question_id' => $question->id(),
                'title' => $question->title(),
                'answer_count' => count($items),
                'correct_count' => count(array_filter(
                    $items,
                    static fn (QuizAttemptItem $item): bool => $item->isCorrect()
                )),
            ];
        }
        $attemptRows = array_map(
            static fn (QuizAttempt $attempt): array => [
                'id' => $attempt->id(),
                'user_id' => $attempt->userId(),
                'score' => $attempt->score(),
                'max_score' => $attempt->maxScore(),
                'started_at' => $attempt->startedAt(),
                'submitted_at' => $attempt->submittedAt(),
                'correct_count' => count(array_filter(
                    $attempt->items(),
                    static fn (QuizAttemptItem $item): bool => $item->isCorrect()
                )),
                'question_count' => count($attempt->items()),
            ],
            $attempts
        );

        return [
            'attempt_count' => count($attempts),
            'average_score' => $attempts === []
                ? 0.0
                : array_sum(array_map(
                    static fn (QuizAttempt $attempt): float => $attempt->score(),
                    $attempts
                )) / count($attempts),
            'question_rows' => $questionRows,
            'attempt_rows' => $attemptRows,
        ];
    }

    private function persist(QuizDefinition $definition): QuizDefinition
    {
        $questions = [];
        foreach ($definition->questions() as $question) {
            $choices = [];
            foreach ($question->choices() as $choice) {
                $choices[] = new QuizChoice(
                    $this->choiceSequence++,
                    $choice->key(),
                    $choice->label(),
                    $choice->body(),
                    $choice->isCorrect(),
                    $choice->sortOrder(),
                    $choice->isEnabled()
                );
            }
            $questions[] = new QuizQuestion(
                $this->questionSequence++,
                $question->key(),
                $question->title(),
                $question->body(),
                $question->type(),
                $question->explanation(),
                $question->points(),
                $question->sortOrder(),
                $question->isEnabled(),
                $choices
            );
        }

        return new QuizDefinition(
            $definition->title(),
            $definition->description(),
            $definition->attemptLimit(),
            $definition->resultPolicy(),
            $definition->resultsAvailableAt(),
            $definition->publicStatistics(),
            $definition->protectPublished(),
            $definition->isEnabled(),
            $questions
        );
    }

    private function reapplyChild(
        int $parentAttemptId,
        int $targetRevisionId,
        string $kind
    ): ?QuizAttempt {
        foreach ($this->attempts as $attempt) {
            if (
                $attempt->parentAttemptId() === $parentAttemptId
                && $attempt->revisionId() === $targetRevisionId
                && $attempt->attemptKind() === $kind
            ) {
                return $attempt;
            }
        }

        return null;
    }
}

final class MemoryProblemBookRepository implements ProblemBookRepository
{
    /**
     * @var array<int, ProblemBook>
     */
    private array $books = [];

    /**
     * @var array<int, array<int, PersonalProblemBookItem>>
     */
    private array $personal = [];

    private int $bookSequence = 1;
    private int $personalSequence = 1;

    public function publicBooks(): array
    {
        return array_values(array_filter(
            $this->books,
            static fn (ProblemBook $book): bool =>
                $book->visibility() === ProblemBookVisibility::Members
        ));
    }

    public function managedBooks(): array
    {
        return array_values($this->books);
    }

    public function find(int $bookId): ?ProblemBook
    {
        return $this->books[$bookId] ?? null;
    }

    public function save(
        ?int $bookId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
        ProblemBookVisibility $visibility,
        int $sortOrder,
        array $problemIds,
        int $actorUserId
    ): ProblemBook {
        $id = $bookId ?? $this->bookSequence++;
        $book = new ProblemBook(
            $id,
            $parentId,
            $name,
            $slug,
            $description,
            $visibility,
            $sortOrder,
            $actorUserId,
            '2026-07-26 02:00:00',
            '2026-07-26 02:00:00',
            $problemIds
        );
        $this->books[$id] = $book;

        return $book;
    }

    public function delete(int $bookId): void
    {
        unset($this->books[$bookId]);
    }

    public function personalItems(int $userId): array
    {
        return array_values($this->personal[$userId] ?? []);
    }

    public function hasPersonalItem(int $userId, ProblemId $problemId): bool
    {
        return isset($this->personal[$userId][$problemId->value()]);
    }

    public function addPersonalItem(
        int $userId,
        ProblemId $problemId,
        string $note
    ): void {
        $existing = $this->personal[$userId][$problemId->value()] ?? null;
        $this->personal[$userId][$problemId->value()] = new PersonalProblemBookItem(
            $existing?->id() ?? $this->personalSequence++,
            $userId,
            $problemId,
            $note,
            $existing?->sortOrder() ?? count($this->personal[$userId] ?? []) + 1,
            $existing?->createdAt() ?? '2026-07-26 02:00:00',
            '2026-07-26 02:00:00'
        );
    }

    public function removePersonalItem(
        int $userId,
        ProblemId $problemId
    ): void {
        unset($this->personal[$userId][$problemId->value()]);
    }
}

final class MemoryQuizBankRepository implements QuizBankRepository
{
    /** @var array<int, QuizBankQuestion> */
    private array $items = [];

    private int $sequence = 1;

    public function find(int $id): ?QuizBankQuestion
    {
        return $this->items[$id] ?? null;
    }

    public function search(string $keyword): array
    {
        if ($keyword === '') {
            return array_values($this->items);
        }

        return array_values(array_filter(
            $this->items,
            static fn (QuizBankQuestion $item): bool =>
                str_contains($item->question()->title(), $keyword)
        ));
    }

    public function save(?int $id, QuizQuestion $question, int $actorUserId): QuizBankQuestion
    {
        $bankId = $id ?? $this->sequence++;
        $item = new QuizBankQuestion(
            $bankId,
            $question,
            $id === null ? $actorUserId : $this->items[$id]->createdByUserId(),
            $id === null ? '2026-07-26 03:00:00' : $this->items[$id]->createdAt()
        );
        $this->items[$bankId] = $item;

        return $item;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
    }

    public function usageCount(int $id): int
    {
        return 0;
    }
}

function quizDefinition(
    string $title,
    int $attemptLimit = 1,
    QuizResultPolicy $policy = QuizResultPolicy::Full,
    ?string $availableAt = null,
    bool $publicStatistics = true,
    bool $enabled = true,
    bool $protectPublished = true,
    string $questionKey = '11111111-1111-4111-8111-111111111111'
): QuizDefinition {
    return new QuizDefinition(
        $title,
        '퀴즈 설명',
        $attemptLimit,
        $policy,
        $availableAt,
        $publicStatistics,
        $protectPublished,
        $enabled,
        [
            new QuizQuestion(
                0,
                $questionKey,
                '문항 1',
                '2 + 2의 값은?',
                'single_choice',
                '2와 2를 더하면 4입니다.',
                2,
                1,
                true,
                [
                    new QuizChoice(
                        0,
                        '22222222-2222-4222-8222-222222222222',
                        'A',
                        '4',
                        true,
                        1
                    ),
                    new QuizChoice(
                        0,
                        '33333333-3333-4333-8333-333333333333',
                        'B',
                        '5',
                        false,
                        2
                    ),
                ]
            ),
        ]
    );
}

$problemId = new ProblemId(1000);
$problem = new JudgeProblem(
    $problemId,
    '덧셈 문제',
    '설명',
    '입력',
    '출력',
    '2 2',
    '4',
    '',
    '',
    1,
    128,
    false,
    ProblemVisibility::Published,
    0,
    0,
    '',
    '',
    '',
    '',
    null
);
$capabilityConfig = require $pluginRoot . '/config/capabilities.php';
$capabilities = new DefinitionRegistry(
    'quiz-test-capabilities',
    CapabilityDefinition::class
);
foreach ($capabilityConfig['capabilities'] as $definition) {
    $capabilities->register(CapabilityDefinition::fromArray($definition));
}
$permissions = new PermissionService(
    $capabilities,
    new QuizCapabilityChecker([
        1 => [
            'oj_manage_quizzes',
            'oj_manage_problems',
            'oj_edit_problems',
            'oj_manage_problem_books',
        ],
        3 => ['oj_manage_quizzes'],
    ])
);
$assignments = new QuizAssignments(new ProblemAssignment(
    $problemId,
    3,
    []
));
$problemAccess = new ProblemAccessPolicy(
    $permissions,
    $assignments,
    new QuizProblemAiAvailability(),
    new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry()
);
$catalog = new ProblemCatalogService(
    new QuizProblemGateway($problem),
    new QuizMetadata(),
    $assignments,
    $problemAccess,
    $permissions
);
$repository = new MemoryQuizRepository();
$quizAccess = new QuizAccessPolicy($permissions, $problemAccess);
$service = new QuizService(
    $repository,
    $catalog,
    $quizAccess,
    new QuizGrader()
);

$keyFactory = new QuizKeyFactory();
quizAssert(
    preg_match('/^[a-f0-9-]{36}$/', $keyFactory->uuid()) === 1,
    '문항 키는 UUID 형식이어야 합니다.'
);
quizAssert(
    preg_match('/^[a-f0-9]{64}$/', $keyFactory->attemptKey()) === 1,
    '응시 시작 키는 64자리 SHA-256 호환 형식이어야 합니다.'
);

$invalidQuestionRejected = false;
try {
    new QuizQuestion(
        0,
        '44444444-4444-4444-8444-444444444444',
        '잘못된 문항',
        '정답이 두 개인 문항',
        'single_choice',
        null,
        1,
        1,
        true,
        [
            new QuizChoice(
                0,
                '55555555-5555-4555-8555-555555555555',
                'A',
                '1',
                true,
                1
            ),
            new QuizChoice(
                0,
                '66666666-6666-4666-8666-666666666666',
                'B',
                '2',
                true,
                2
            ),
        ]
    );
} catch (InvalidArgumentException) {
    $invalidQuestionRejected = true;
}
quizAssert($invalidQuestionRejected, '단일 선택 문항은 정답 1개만 허용해야 합니다.');

$multiChoiceQuestion = new QuizQuestion(
    0,
    '77777777-7777-4777-8777-777777777777',
    '다중 답 문항',
    '2 + 2와 같은 값을 모두 고르세요.',
    'multi_choice',
    null,
    2,
    1,
    true,
    [
        new QuizChoice(1, '88888888-8888-4888-8888-888888888888', 'A', '4', true, 1),
        new QuizChoice(2, '99999999-9999-4999-8999-999999999999', 'B', '2+2', true, 2),
        new QuizChoice(3, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'C', '5', false, 3),
    ]
);
quizSame(
    2,
    count($multiChoiceQuestion->correctChoices()),
    '다중 답 문항은 정답 선택지를 모두 반환해야 합니다.'
);

$multiChoiceNoCorrectRejected = false;
try {
    new QuizQuestion(
        0,
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        '정답 없는 다중 답',
        '아무 정답도 없는 문항',
        'multi_choice',
        null,
        1,
        1,
        true,
        [
            new QuizChoice(1, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'A', '1', false, 1),
            new QuizChoice(2, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'B', '2', false, 2),
        ]
    );
} catch (InvalidArgumentException) {
    $multiChoiceNoCorrectRejected = true;
}
quizAssert($multiChoiceNoCorrectRejected, '다중 답 문항에는 정답이 1개 이상 필요합니다.');

$shortAnswerQuestion = new QuizQuestion(
    0,
    'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    '단답형 문항',
    '2 + 2는?',
    'short_answer',
    null,
    1,
    1,
    true,
    [],
    ['4', 'Four']
);
quizSame(
    ['4', 'Four'],
    $shortAnswerQuestion->acceptedAnswers(),
    '단답형 문항은 등록한 정답을 그대로 반환해야 합니다.'
);

$shortAnswerWithChoicesRejected = false;
try {
    new QuizQuestion(
        0,
        'ffffffff-ffff-4fff-8fff-ffffffffffff',
        '잘못된 단답형',
        '선택지가 있는 단답형',
        'short_answer',
        null,
        1,
        1,
        true,
        [
            new QuizChoice(1, '10101010-1010-4101-8101-101010101010', 'A', '4', true, 1),
        ],
        ['4']
    );
} catch (InvalidArgumentException) {
    $shortAnswerWithChoicesRejected = true;
}
quizAssert($shortAnswerWithChoicesRejected, '단답형 문항에는 선택지를 등록할 수 없습니다.');

$shortAnswerNoAnswersRejected = false;
try {
    new QuizQuestion(
        0,
        '20202020-2020-4202-8202-202020202020',
        '정답 없는 단답형',
        '정답 목록이 비어 있는 문항',
        'short_answer',
        null,
        1,
        1,
        true,
        [],
        []
    );
} catch (InvalidArgumentException) {
    $shortAnswerNoAnswersRejected = true;
}
quizAssert($shortAnswerNoAnswersRejected, '단답형 문항에는 정답을 1개 이상 등록해야 합니다.');

$mixedTypeGrader = new QuizGrader();
$mixedDefinition = new QuizDefinition(
    '혼합 유형 퀴즈',
    '설명',
    1,
    QuizResultPolicy::Full,
    null,
    true,
    true,
    true,
    [
        new QuizQuestion(
            21,
            '30303030-3030-4303-8303-303030303030',
            '다중 답',
            '정답을 모두 고르세요.',
            'multi_choice',
            null,
            2,
            1,
            true,
            [
                new QuizChoice(211, '40404040-4040-4404-8404-404040404040', 'A', '4', true, 1),
                new QuizChoice(212, '50505050-5050-4505-8505-505050505050', 'B', '2+2', true, 2),
                new QuizChoice(213, '60606060-6060-4606-8606-606060606060', 'C', '5', false, 3),
            ]
        ),
        new QuizQuestion(
            22,
            '70707070-7070-4707-8707-707070707070',
            '단답형',
            '2 + 2는?',
            'short_answer',
            null,
            1,
            2,
            true,
            [],
            ['4', 'Four']
        ),
    ]
);
$mixedRevision = new QuizRevision(
    1,
    $problemId,
    1,
    'published',
    true,
    $mixedDefinition,
    $mixedDefinition->contentHash(),
    1,
    1,
    '2026-07-26 00:00:00'
);

$fullCreditResult = $mixedTypeGrader->grade($mixedRevision, [21 => [212, 211], 22 => '4']);
quizSame(3.0, $fullCreditResult->score(), '다중 답을 정확히 모두 고르고 단답형을 맞히면 만점이어야 합니다.');

$missingChoiceResult = $mixedTypeGrader->grade($mixedRevision, [21 => [211], 22 => '4']);
quizSame(1.0, $missingChoiceResult->score(), '다중 답은 정답을 일부만 고르면 0점 처리해야 합니다.');

$extraChoiceResult = $mixedTypeGrader->grade($mixedRevision, [21 => [211, 212, 213], 22 => '4']);
quizSame(1.0, $extraChoiceResult->score(), '다중 답은 오답을 하나라도 고르면 0점 처리해야 합니다.');

$caseInsensitiveResult = $mixedTypeGrader->grade($mixedRevision, [21 => [211, 212], 22 => '  FOUR  ']);
quizSame(3.0, $caseInsensitiveResult->score(), '단답형은 대소문자와 앞뒤 공백을 무시하고 비교해야 합니다.');

$wrongAnswerResult = $mixedTypeGrader->grade($mixedRevision, [21 => [211, 212], 22 => 'five']);
quizSame(2.0, $wrongAnswerResult->score(), '단답형은 등록된 정답과 일치하지 않으면 0점 처리해야 합니다.');

$draft = $service->createDraft(1, $problemId, quizDefinition('초안 v1'));
quizSame('draft', $draft->status(), '퀴즈 생성은 게시가 아닌 초안이어야 합니다.');
quizAssert($quizAccess->canManage(3, $problemId), '퀴즈 권한이 있는 문제 소유자는 관리할 수 있어야 합니다.');
quizAssert(! $quizAccess->canManage(2, $problemId), '일반 학습자는 퀴즈를 관리할 수 없어야 합니다.');

$publishedV1 = $service->publish(1, $problemId, quizDefinition('게시 v1'));
quizSame(1, $publishedV1->revisionNumber(), '첫 게시 버전은 v1이어야 합니다.');
$service->saveDraft(1, $problemId, quizDefinition('수정 초안'));
quizSame(
    '게시 v1',
    $repository->revision($publishedV1->id())?->definition()->title(),
    '초안 수정은 이미 게시된 버전을 바꾸면 안 됩니다.'
);
$publishedV2 = $service->publish(1, $problemId, quizDefinition('게시 v2'));
quizSame(2, $publishedV2->revisionNumber(), '다음 게시는 새 버전을 만들어야 합니다.');
quizSame(
    '게시 v1',
    $repository->revision($publishedV1->id())?->definition()->title(),
    '새 게시 뒤에도 이전 버전 원문을 보존해야 합니다.'
);
quizSame(1, count($service->visibleOverviews(2)), '학습자는 활성 게시 퀴즈만 볼 수 있어야 합니다.');

$attemptKey = str_repeat('a', 64);
$attempt = $service->start(2, $problemId, $attemptKey);
$sameAttempt = $service->start(2, $problemId, $attemptKey);
quizSame($attempt->id(), $sameAttempt->id(), '같은 시작 요청은 같은 응시를 반환해야 합니다.');
$activeAttempt = $service->start(2, $problemId, str_repeat('b', 64));
quizSame($attempt->id(), $activeAttempt->id(), '문제별 진행 응시는 한 건만 존재해야 합니다.');

$attemptContext = $service->attempt(2, $problemId);
$question = $attemptContext['revision']->definition()->questions()[0];
$correctChoice = $question->correctChoice();
$graded = $service->submit(
    2,
    $problemId,
    $attempt->id(),
    [$question->id() => $correctChoice->id()]
);
quizSame(2.0, $graded->score(), '정답에는 문항 배점을 모두 부여해야 합니다.');
quizSame('graded', $graded->status(), '최종 제출은 응시를 제출 완료로 확정해야 합니다.');
$repeated = $service->submit(2, $problemId, $attempt->id(), []);
quizSame($graded->score(), $repeated->score(), '중복 제출은 최초 점수를 그대로 반환해야 합니다.');
quizSame(
    $graded->items()[0]->choiceId(),
    $repeated->items()[0]->choiceId(),
    '중복 제출은 최초 답안을 바꾸면 안 됩니다.'
);

$limitRejected = false;
try {
    $service->start(2, $problemId, str_repeat('c', 64));
} catch (DomainException) {
    $limitRejected = true;
}
quizAssert($limitRejected, '완료 뒤 응시 제한을 넘는 새 응시를 거부해야 합니다.');

$scoreOnly = new QuizRevision(
    900,
    $problemId,
    9,
    'published',
    true,
    quizDefinition(
        '점수만',
        1,
        QuizResultPolicy::ScoreOnly
    ),
    quizDefinition(
        '점수만',
        1,
        QuizResultPolicy::ScoreOnly
    )->contentHash(),
    1,
    1,
    '2026-07-26 00:00:00'
);
quizAssert(! $service->answersVisible($scoreOnly), '점수만 정책은 정오와 해설을 숨겨야 합니다.');
$afterEndDefinition = quizDefinition(
    '종료 후',
    1,
    QuizResultPolicy::AfterEnd,
    '2026-07-27 00:00:00'
);
$afterEnd = new QuizRevision(
    901,
    $problemId,
    10,
    'published',
    true,
    $afterEndDefinition,
    $afterEndDefinition->contentHash(),
    1,
    1,
    '2026-07-26 00:00:00'
);
quizAssert(
    ! $service->answersVisible(
        $afterEnd,
        new DateTimeImmutable('2026-07-26 23:59:59')
    ),
    '예약 시각 전에는 정오와 해설을 숨겨야 합니다.'
);
quizAssert(
    $service->answersVisible(
        $afterEnd,
        new DateTimeImmutable('2026-07-27 00:00:00')
    ),
    '예약 시각부터 정오와 해설을 공개해야 합니다.'
);
$siteClock = new class implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-07-27 00:00:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
$siteTimedQuizService = new QuizService(
    $repository,
    $catalog,
    $quizAccess,
    new QuizGrader(),
    $siteClock
);
quizAssert(
    $siteTimedQuizService->answersVisible($afterEnd),
    '퀴즈 결과 공개 시각은 WordPress 사이트 시계를 기준으로 판정해야 합니다.'
);

$statistics = $service->statistics(2, $problemId);
quizSame(1, $statistics['statistics']['attempt_count'], '완료 응시를 통계에 집계해야 합니다.');
quizSame(1, count($statistics['statistics']['attempt_rows']), '일반 사용자는 본인 응시 행만 받아야 합니다.');
$managedStatistics = $service->statistics(1, $problemId);
quizAssert($managedStatistics['managed'], '퀴즈 관리자는 관리 통계를 열 수 있어야 합니다.');

$inProgressV2 = $service->start(4, $problemId, str_repeat('d', 64));
$sourceV2 = $repository->published($problemId);
if ($sourceV2 === null) {
    throw new RuntimeException('재적용 원본 퀴즈 버전이 없습니다.');
}
$unansweredAttempt = $service->start(
    5,
    $problemId,
    str_repeat('e', 64)
);
$unansweredGraded = $service->submit(
    5,
    $problemId,
    $unansweredAttempt->id(),
    []
);
quizSame(
    null,
    $unansweredGraded->items()[0]->choiceId(),
    '미응답 문항은 선택지 없이 완료 결과에 보존해야 합니다.'
);
$targetV3 = $service->publish(
    1,
    $problemId,
    quizDefinition(
        '게시 v3',
        1,
        QuizResultPolicy::Full,
        null,
        true,
        true,
        false
    )
);
$jobDefinitions = new DefinitionRegistry(
    'quiz-reapply-jobs',
    JobDefinition::class
);
$jobDefinitions->register(JobDefinition::fromArray([
    'key' => 'quiz_revision_reapply',
    'owner_module' => 'problems',
    'definition_version' => '1',
    'max_attempts' => 1,
    'uses_steps' => true,
]));
$jobVault = new QuizJobVault();
$jobQueue = new QuizJobQueue();
$reapplyService = new QuizReapplyService(
    $repository,
    $repository,
    $quizAccess,
    new QuizGrader(),
    new JobManager(
        $jobDefinitions,
        new IdempotencyKeyFactory(),
        $jobVault,
        $jobQueue
    )
);
$scopedRegradeRejected = false;
try {
    $reapplyService->assertCanRequest(
        3,
        $problemId,
        $targetV3->definition(),
        false,
        true
    );
} catch (DomainException) {
    $scopedRegradeRejected = true;
}
quizAssert(
    $scopedRegradeRejected,
    '문제 담당 범위만 가진 관리자는 완료 결과를 일괄 재채점하면 안 됩니다.'
);
$reapplyJob = $reapplyService->enqueue(
    1,
    $sourceV2,
    $targetV3,
    true,
    true
);
quizSame(77, $reapplyJob?->jobId(), '퀴즈 재적용 Jobs를 등록해야 합니다.');
quizSame(3, count($jobQueue->items), '진행 응시와 완료 결과를 각각 고정해야 합니다.');

$reapplyResults = [];
$inProgressPayload = null;
foreach ($jobQueue->items as $item) {
    $payload = $jobVault->find(
        $item->payloadReference(),
        1
    )?->payload();
    if (is_array($payload) && ($payload['mode'] ?? '') === 'in_progress') {
        $inProgressPayload = $payload;
    }
    $reapplyResults[] = $reapplyService->execute(1, $payload);
}
$superseded = $repository->attempt($inProgressV2->id());
quizSame(
    'superseded',
    $superseded?->status(),
    '진행 중 원본 응시는 교체됨 상태로 보존해야 합니다.'
);
$replacementResult = current(array_filter(
    $reapplyResults,
    static fn (array $result): bool => $result['mode'] === 'in_progress'
));
$replacement = is_array($replacementResult)
    ? $repository->attempt($replacementResult['created_attempt_id'])
    : null;
quizSame(
    'replacement',
    $replacement?->attemptKind(),
    '진행 중 재적용은 횟수 차감 없는 대체 시도를 만들어야 합니다.'
);
quizSame(
    $inProgressV2->id(),
    $replacement?->parentAttemptId(),
    '대체 시도는 원본 응시와 연결되어야 합니다.'
);
$regradeResult = current(array_filter(
    $reapplyResults,
    static fn (array $result): bool => $result['mode'] === 'graded'
));
$regrade = is_array($regradeResult)
    ? $repository->attempt($regradeResult['created_attempt_id'])
    : null;
quizSame('regrade', $regrade?->attemptKind(), '완료 결과는 별도 재채점 기록으로 저장해야 합니다.');
quizSame($graded->id(), $regrade?->parentAttemptId(), '재채점 결과는 원본 결과와 연결해야 합니다.');
quizSame(
    'graded',
    $repository->attempt($graded->id())?->status(),
    '재채점 뒤에도 원래 완료 결과를 변경하면 안 됩니다.'
);
$unansweredResult = current(array_filter(
    $reapplyResults,
    static fn (array $result): bool =>
        $result['original_attempt_id'] === $unansweredGraded->id()
));
$unansweredRegrade = is_array($unansweredResult)
    ? $repository->attempt($unansweredResult['created_attempt_id'])
    : null;
quizSame(
    null,
    $unansweredRegrade?->items()[0]->choiceId(),
    '미응답 문항도 오류 없이 새 버전의 미응답 결과로 재채점해야 합니다.'
);
if (! is_array($inProgressPayload)) {
    throw new RuntimeException('진행 중 재적용 스냅샷이 없습니다.');
}
$repeatedReplacement = $reapplyService->execute(1, $inProgressPayload);
quizSame(
    $replacement?->id(),
    $repeatedReplacement['created_attempt_id'],
    '재적용 Worker 재시도는 대체 시도를 중복 생성하면 안 됩니다.'
);

$targetV4 = $service->publish(
    1,
    $problemId,
    quizDefinition(
        '게시 v4',
        1,
        QuizResultPolicy::Full,
        null,
        true,
        true,
        false,
        '77777777-7777-4777-8777-777777777777'
    )
);
$mappingRejected = false;
try {
    $reapplyService->execute(1, [
        'problem_id' => $problemId->value(),
        'source_revision_id' => $targetV3->id(),
        'source_content_hash' => $targetV3->contentHash(),
        'target_revision_id' => $targetV4->id(),
        'target_content_hash' => $targetV4->contentHash(),
        'original_attempt_id' => $regrade?->id(),
        'original_attempt_key' => $regrade?->attemptKey(),
        'mode' => 'graded',
    ]);
} catch (DomainException) {
    $mappingRejected = true;
}
quizAssert(
    $mappingRejected,
    '안정 문항 키가 대응하지 않으면 완료 결과 재채점을 중단해야 합니다.'
);

$bookRepository = new MemoryProblemBookRepository();
$bookService = new ProblemBookService(
    $bookRepository,
    $catalog,
    $permissions
);
$book = $bookService->save(
    1,
    null,
    null,
    '덧셈 기초',
    'addition-basics',
    '덧셈 문제를 순서대로 풉니다.',
    ProblemBookVisibility::Members,
    10,
    [$problemId->value()]
);
quizSame(1, $book->id(), '공용 문제집을 새 번호로 저장해야 합니다.');
$bookView = $bookService->publicView(2);
quizSame(1, count($bookView['public']), '로그인 사용자는 공개 문제집을 볼 수 있어야 합니다.');
quizSame(
    $problemId->value(),
    $bookView['public'][0]['problems'][0]->id()->value(),
    '공용 문제집은 접근 가능한 문제만 제공해야 합니다.'
);
$bookService->addPersonal(2, $problemId, '다시 풀기');
quizAssert(
    $bookService->hasPersonal(2, $problemId),
    '사용자는 접근 가능한 문제를 개인 문제집에 추가할 수 있어야 합니다.'
);
quizSame(
    1,
    count($bookService->publicView(2)['personal']),
    '개인 문제집에는 현재 사용자 항목만 표시해야 합니다.'
);
quizSame(
    0,
    count($bookService->publicView(1)['personal']),
    '관리자라도 다른 사용자의 개인 문제집을 볼 수 없어야 합니다.'
);
$bookService->removePersonal(2, $problemId);
quizAssert(
    ! $bookService->hasPersonal(2, $problemId),
    '개인 문제집 제거는 현재 사용자 항목만 삭제해야 합니다.'
);
$bookService->delete(1, $book->id());
quizSame(0, count($bookService->publicView(2)['public']), '삭제한 공용 문제집은 목록에서 제외해야 합니다.');

$invalidSlugRejected = false;
try {
    $bookService->save(
        1,
        null,
        null,
        '잘못된 주소',
        '한글 주소',
        '',
        ProblemBookVisibility::Members,
        0,
        []
    );
} catch (InvalidArgumentException) {
    $invalidSlugRejected = true;
}
quizAssert($invalidSlugRejected, '공용 문제집 주소 키는 영문 소문자 형식이어야 합니다.');

$freshQuizRepository = new MemoryQuizRepository();
$freshBankRepository = new MemoryQuizBankRepository();
$freshQuizService = new QuizService(
    $freshQuizRepository,
    $catalog,
    $quizAccess,
    new QuizGrader()
);
$bankService = new QuizBankService(
    $freshBankRepository,
    $permissions,
    $freshQuizService,
    $keyFactory
);

$bankQuestion = new QuizQuestion(
    0,
    '80808080-8080-4808-8808-808080808080',
    '은행 문항',
    '2 + 2는?',
    'single_choice',
    null,
    3,
    1,
    true,
    [
        new QuizChoice(0, '90909090-9090-4909-8909-909090909090', 'A', '4', true, 1),
        new QuizChoice(0, 'a0a0a0a0-a0a0-4a0a-8a0a-a0a0a0a0a0a0', 'B', '5', false, 2),
    ]
);
$savedBankItem = $bankService->save(1, null, $bankQuestion);
quizAssert($savedBankItem->id() > 0, '문항 은행 저장은 새 id를 발급해야 합니다.');

$bankNoPermissionRejected = false;
try {
    $bankService->save(2, null, $bankQuestion);
} catch (DomainException) {
    $bankNoPermissionRejected = true;
}
quizAssert($bankNoPermissionRejected, '문항 은행 관리 권한이 없는 사용자는 저장할 수 없어야 합니다.');

$importRejectedNoDraft = false;
try {
    $bankService->importIntoDraft(1, $problemId, $savedBankItem->id());
} catch (DomainException) {
    $importRejectedNoDraft = true;
}
quizAssert($importRejectedNoDraft, '퀴즈 초안이 없으면 문항을 가져올 수 없어야 합니다.');

$freshDraft = $freshQuizService->createDraft(1, $problemId, quizDefinition('은행 가져오기 테스트'));
quizSame(1, $freshDraft->definition()->questionCount(), '새 초안은 문항 1개로 시작해야 합니다.');

$afterImport = $bankService->importIntoDraft(1, $problemId, $savedBankItem->id());
quizSame(2, $afterImport->definition()->questionCount(), '문항 은행에서 가져오면 초안 문항 수가 늘어야 합니다.');
$importedQuestion = $afterImport->definition()->questions()[1];
quizSame('은행 문항', $importedQuestion->title(), '가져온 문항은 은행 원본의 제목을 그대로 가져야 합니다.');
quizAssert(
    $importedQuestion->key() !== $bankQuestion->key(),
    '가져온 문항은 원본과 다른 새 문항 키를 가져야 합니다(복사본).'
);

$editedBankQuestion = new QuizQuestion(
    0,
    $savedBankItem->question()->key(),
    '수정된 은행 문항 제목',
    '수정된 본문',
    'single_choice',
    null,
    5,
    1,
    true,
    [
        new QuizChoice(0, 'b0b0b0b0-b0b0-4b0b-8b0b-b0b0b0b0b0b0', 'A', '4', true, 1),
        new QuizChoice(0, 'c0c0c0c0-c0c0-4c0c-8c0c-c0c0c0c0c0c0', 'B', '6', false, 2),
    ]
);
$bankService->save(1, $savedBankItem->id(), $editedBankQuestion);
$reloadedDraft = $freshQuizService->managed(1, $problemId)['draft'];
$stillOldTitleQuestion = $reloadedDraft->definition()->questions()[1];
quizSame(
    '은행 문항',
    $stillOldTitleQuestion->title(),
    '은행 원본을 수정해도 이미 가져온 퀴즈 문항은 그대로 유지해야 합니다(스냅샷 복사).'
);

$exportedBankItem = $bankService->exportToBank(1, $problemId, $stillOldTitleQuestion->key());
quizSame(
    '은행 문항',
    $exportedBankItem->question()->title(),
    '초안 문항을 내보내면 그 시점 내용 그대로 새 은행 항목이 만들어져야 합니다.'
);
quizAssert(
    $exportedBankItem->id() !== $savedBankItem->id(),
    '내보내기는 기존 은행 항목이 아닌 새 은행 항목을 만들어야 합니다.'
);

$bankService->delete(1, $savedBankItem->id());
quizAssert(
    $freshBankRepository->find($savedBankItem->id()) === null,
    '삭제한 문항 은행 항목은 더 이상 조회되지 않아야 합니다.'
);
quizSame(
    1,
    count($bankService->list(1, '')),
    '삭제 뒤 문항 은행에는 내보내기로 만든 항목 1개만 남아야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-05 퀴즈·문제집 단위 검사 통과: assertion %d개\n", $quizAssertions)
);

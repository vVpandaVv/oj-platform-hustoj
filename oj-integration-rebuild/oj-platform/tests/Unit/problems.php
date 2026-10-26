<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeOrderingAuthoringPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAssignmentService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiInputFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiOutputValidator;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobSupport;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAuthoringGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCodePolicyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditCommand;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditorService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemJobAccessRule;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataBulkApplyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeCoordinator;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSpjCompileJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestcaseApplyJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestcaseGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataSettingsService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlCodec;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlExportService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlImportService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlMaterializer;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlPreviewService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlWriter;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\BoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSpjCompiler;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTransaction;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\BoundedProcessResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberChangePlan;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemStatistics;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopicDictionaryVersion;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataConfiguration;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemWriteConsistencyException;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlApplyRow;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompileResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjSandboxProfile;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\RootOnlyProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ProblemDataWorkspace;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge\MysqliProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\ProblemPlatformDatabase;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process\BubblewrapBoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemEditForm;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemRequestFactory;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
require_once $pluginRoot . '/tests/Support/wordpress-stubs.php';

$assertions = 0;

function problemsAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function problemsSame(mixed $expected, mixed $actual, string $message): void
{
    problemsAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

final class ProblemsActionUrls implements ActionUrlProvider
{
    public function actionUrl(): string
    {
        return '/oj-action';
    }

    public function ajaxUrl(): string
    {
        return '/oj-ajax';
    }
}

final class ProblemsActionSecurity implements ActionSecurity
{
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="' . $actionKey . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        unset($actionKey, $request);

        return true;
    }
}

final class FailingProblemPlatformConnection
{
    public string $last_error = "Data too long for column 'topic_dictionary_version'";

    /**
     * @param array<string, mixed> $data
     * @param list<string>|null $formats
     */
    public function insert(
        string $table,
        array $data,
        ?array $formats = null
    ): false {
        unset($table, $data, $formats);

        return false;
    }
}

final class ProblemsSettingsManager implements SettingsManager
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int> */
    public array $revisions = [];

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(
            $this->revisions[$key] ?? 0,
            $this->values[$key] ?? $default
        );
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        $revision = $this->revisions[$key] ?? 0;
        if ($revision !== $expectedRevision) {
            throw new RuntimeException('설정 revision 충돌');
        }

        $revision++;
        $this->revisions[$key] = $revision;
        $this->values[$key] = $value;

        return new SettingsSnapshot($revision, $value);
    }
}
final class ProblemsCapabilityChecker implements CapabilityChecker
{
    /**
     * @param array<int, list<string>> $grants
     * @param list<int> $superAdmins
     */
    public function __construct(
        private readonly array $grants = [],
        private readonly array $superAdmins = []
    ) {
    }

    public function userCan(int $userId, string $capability): bool
    {
        return in_array($capability, $this->grants[$userId] ?? [], true);
    }

    public function isSuperAdmin(int $userId): bool
    {
        return in_array($userId, $this->superAdmins, true);
    }
}

final class MemoryAssignments implements ProblemAssignmentRepository
{
    /**
     * @var array<int, ProblemAssignment>
     */
    public array $items = [];

    public function find(ProblemId $problemId): ProblemAssignment
    {
        return $this->items[$problemId->value()]
            ?? new ProblemAssignment($problemId, null, []);
    }

    public function assignOwner(
        ProblemId $problemId,
        int $ownerUserId,
        int $actorUserId
    ): void {
        unset($actorUserId);
        $current = $this->find($problemId);
        $collaborators = $current->collaborators();
        unset($collaborators[$ownerUserId]);
        $this->items[$problemId->value()] = new ProblemAssignment(
            $problemId,
            $ownerUserId,
            $collaborators
        );
    }

    public function grant(
        ProblemId $problemId,
        int $userId,
        CollaboratorRole $role,
        int $actorUserId
    ): void {
        unset($actorUserId);
        $current = $this->find($problemId);
        $collaborators = $current->collaborators();
        $collaborators[$userId] = $role;
        $this->items[$problemId->value()] = new ProblemAssignment(
            $problemId,
            $current->ownerUserId(),
            $collaborators
        );
    }

    public function revoke(ProblemId $problemId, int $userId): void
    {
        $current = $this->find($problemId);
        $collaborators = $current->collaborators();
        unset($collaborators[$userId]);
        $this->items[$problemId->value()] = new ProblemAssignment(
            $problemId,
            $current->ownerUserId(),
            $collaborators
        );
    }

    public function problemIdsForUser(int $userId): array
    {
        $ids = [];
        foreach ($this->items as $problemId => $assignment) {
            if (
                $assignment->isOwner($userId)
                || $assignment->collaboratorRole($userId) !== null
            ) {
                $ids[] = $problemId;
            }
        }

        return $ids;
    }
}

final class ProblemsUsers implements WordPressUserGateway
{
    /**
     * @param array<int, UserIdentity> $users
     */
    public function __construct(private array $users)
    {
    }

    public function currentUser(): ?UserIdentity
    {
        return null;
    }

    public function findById(int $userId): ?UserIdentity
    {
        return $this->users[$userId] ?? null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        foreach ($this->users as $user) {
            if ($user->userLogin() === $userLogin) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        foreach ($this->users as $user) {
            if ($user->email() === $email) {
                return $user;
            }
        }

        return null;
    }

    public function verifyCredentials(
        string $userLogin,
        string $password
    ): ?UserIdentity {
        unset($password);

        return $this->findByLogin($userLogin);
    }

    public function establishSession(int $userId, bool $remember): void
    {
        unset($userId, $remember);
    }

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        unset($password);
        $userId = count($this->users) + 100;
        $identity = new UserIdentity(
            $userId,
            $userLogin,
            $email,
            $displayName,
            [$role]
        );
        $this->users[$userId] = $identity;

        return $identity;
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        $current = $this->findById($userId);
        if ($current === null) {
            throw new RuntimeException('사용자가 없습니다.');
        }
        $identity = new UserIdentity(
            $userId,
            $current->userLogin(),
            $email,
            $displayName,
            $current->roles()
        );
        $this->users[$userId] = $identity;

        return $identity;
    }

    public function setRoles(int $userId, array $roles): void
    {
        unset($userId, $roles);
    }

    public function setPassword(int $userId, string $password): void
    {
        unset($userId, $password);
    }

    public function invalidateSessions(int $userId): void
    {
        unset($userId);
    }

    public function requestPasswordReset(string $userLogin): void
    {
        unset($userLogin);
    }

    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {
        unset($userLogin, $resetKey, $newPassword);
    }

    public function deleteUser(int $userId): void
    {
        unset($this->users[$userId]);
    }

    public function findMany(array $userIds): array
    {
        $found = [];
        foreach ($userIds as $userId) {
            $user = $this->findById((int) $userId);
            if ($user !== null) {
                $found[] = $user;
            }
        }

        return $found;
    }
}

final class MemoryProblemGateway implements ProblemGateway
{
    /**
     * @var array<int, JudgeProblem>
     */
    public array $items = [];

    /**
     * @var list<string>
     */
    public array $events = [];

    public ?int $failUpdateOnCall = null;

    private int $updateCalls = 0;

    public function search(ProblemSearch $search): array
    {
        unset($search);

        return array_values($this->items);
    }

    public function count(ProblemSearch $search): int
    {
        unset($search);

        return count($this->items);
    }

    public function find(ProblemId $problemId): ?JudgeProblem
    {
        return $this->items[$problemId->value()] ?? null;
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
        return new ProblemId(
            $this->items === [] ? 1000 : max(array_keys($this->items)) + 1
        );
    }

    public function createNext(ProblemDraft $draft): ProblemId
    {
        $problemId = $this->nextId();
        $this->create($problemId, $draft);

        return $problemId;
    }

    public function create(ProblemId $problemId, ProblemDraft $draft): void
    {
        $this->events[] = 'create:' . $draft->visibility()->value;
        $this->items[$problemId->value()] = problemFromDraft($problemId, $draft);
    }

    public function update(ProblemId $problemId, ProblemDraft $draft): void
    {
        $this->updateCalls++;
        if ($this->failUpdateOnCall === $this->updateCalls) {
            throw new RuntimeException('Judge 문제 복구 실패');
        }
        $this->events[] = 'update:' . $draft->title();
        $this->items[$problemId->value()] = problemFromDraft($problemId, $draft);
    }

    public function nextUpdateCall(): int
    {
        return $this->updateCalls + 1;
    }

    public function archive(ProblemId $problemId, bool $archived): void
    {
        $existing = $this->items[$problemId->value()];
        $draft = ProblemDraft::fromProblem($existing)->withVisibility(
            $archived ? ProblemVisibility::Archived : ProblemVisibility::Published
        );
        $this->events[] = 'archive:' . ($archived ? 'yes' : 'no');
        $this->items[$problemId->value()] = problemFromDraft($problemId, $draft);
    }

    public function statistics(ProblemId $problemId): ProblemStatistics
    {
        return new ProblemStatistics($problemId, 0, 0, []);
    }

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

final class MemoryMetadata implements ProblemMetadataRepository
{
    /**
     * @var array<int, ProblemMetadata>
     */
    public array $items = [];

    public bool $failSave = false;

    public function find(ProblemId $problemId): ?ProblemMetadata
    {
        return $this->items[$problemId->value()] ?? null;
    }

    public function findMany(array $problemIds): array
    {
        $found = [];
        foreach ($problemIds as $problemId) {
            $metadata = $this->find($problemId);
            if ($metadata !== null) {
                $found[$problemId->value()] = $metadata;
            }
        }

        return $found;
    }

    public function save(ProblemMetadata $metadata): void
    {
        if ($this->failSave) {
            throw new RuntimeException('forced metadata failure');
        }
        $this->items[$metadata->problemId()->value()] = $metadata;
    }

    public function move(ProblemId $from, ProblemId $to): void
    {
        $this->items[$to->value()] = $this->items[$from->value()];
        unset($this->items[$from->value()]);
    }
}

final class MemoryTopicLinks implements ProblemTopicLinkRepository
{
    /**
     * @var array<int, list<string>>
     */
    public array $items = [];

    public function keysFor(ProblemId $problemId): array
    {
        return $this->items[$problemId->value()] ?? [];
    }

    public function keysForMany(array $problemIds): array
    {
        $found = [];
        foreach ($problemIds as $problemId) {
            $found[$problemId->value()] = $this->keysFor($problemId);
        }

        return $found;
    }

    public function replace(
        ProblemId $problemId,
        array $topicKeys,
        string $dictionaryVersion
    ): void {
        unset($dictionaryVersion);
        $this->items[$problemId->value()] = $topicKeys;
    }

    public function move(ProblemId $from, ProblemId $to): void
    {
        $this->items[$to->value()] = $this->items[$from->value()] ?? [];
        unset($this->items[$from->value()]);
    }
}

final class MemoryTopics implements ProblemTopicDictionary
{
    /**
     * @var array<string, ProblemTopic>
     */
    public array $topics;

    public function __construct()
    {
        $this->topics = [
            'implementation' => new ProblemTopic('implementation', '구현', null, []),
            'simulation' => new ProblemTopic('simulation', '시뮬레이션', 'implementation', []),
        ];
    }

    public function all(): array
    {
        return array_values($this->topics);
    }

    public function find(string $key): ?ProblemTopic
    {
        return $this->topics[$key] ?? null;
    }

    public function assertSelectableDetails(array $detailKeys): void
    {
        if (! in_array($detailKeys, [[], ['simulation']], true)) {
            throw new DomainException('invalid topics');
        }
    }

    public function version(): string
    {
        return 'td-v1:' . str_repeat('A', 43);
    }

    public function createCategory(
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        unset($aliases, $actorUserId);
        $this->topics[$key] = new ProblemTopic($key, $label, null, []);
    }

    public function createDetail(
        string $categoryKey,
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        unset($aliases, $actorUserId);
        $this->topics[$key] = new ProblemTopic($key, $label, $categoryKey, []);
    }
}

final class MemoryProblemFormDrafts implements FormDraftStore
{
    /**
     * @var array<string, FormDraft>
     */
    public array $items = [];

    private int $sequence = 1;

    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference {
        $suffix = str_pad(dechex($this->sequence++), 12, '0', STR_PAD_LEFT);
        $reference = new FormDraftReference(
            'draft:v1:00000000-0000-4000-8000-' . $suffix
        );
        $now = time();
        $this->items[$reference->value()] = new FormDraft(
            $reference,
            $draftType,
            $ownerUserId,
            $targetType,
            $targetKey,
            $payload,
            $now,
            $now + $ttlSeconds
        );

        return $reference;
    }

    public function find(
        FormDraftReference $reference,
        int $ownerUserId
    ): ?FormDraft {
        $draft = $this->items[$reference->value()] ?? null;

        return $draft instanceof FormDraft
            && $draft->ownerUserId() === $ownerUserId
                ? $draft
                : null;
    }

    public function discard(
        FormDraftReference $reference,
        int $ownerUserId
    ): void {
        if ($this->find($reference, $ownerUserId) !== null) {
            unset($this->items[$reference->value()]);
        }
    }

    public function purgeExpired(int $limit = 100): int
    {
        unset($limit);

        return 0;
    }
}

final class ProblemsAiRouter implements AiTaskRouter
{
    private const FINGERPRINT =
        '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $outputs = [];

    /**
     * @var list<AiTaskRequest>
     */
    public array $requests = [];

    public function canRoute(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): bool {
        unset($taskType, $requiresSubmissionSource);

        return true;
    }

    public function snapshot(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): AiTaskAssignmentSnapshot {
        unset($requiresSubmissionSource);

        return new AiTaskAssignmentSnapshot(
            $taskType,
            1,
            'problems-test',
            null,
            self::FINGERPRINT
        );
    }

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        AiTaskRequest $request
    ): AiTaskResponse {
        $this->requests[] = $request;

        return new AiTaskResponse(
            $this->outputs[$assignment->taskType()] ?? [],
            'problems-test',
            'test_provider',
            'test-model',
            self::FINGERPRINT
        );
    }
}

final class MemoryProblemAiAvailability implements ProblemAiAvailability
{
    /**
     * @param array<string, bool> $tasks
     */
    public function __construct(public array $tasks)
    {
    }

    public function isAvailable(string $taskType): bool
    {
        return $this->tasks[$taskType] ?? false;
    }
}

final class DirectProblemTransaction implements ProblemTransaction
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}

final class MemoryProblemNumberChangeLock implements ProblemNumberChangeLock
{
    public bool $active = false;

    /**
     * @var list<int>
     */
    public array $lockedIds = [];

    public function withExclusiveLock(array $problemIds, callable $operation): mixed
    {
        $this->active = true;
        $this->lockedIds = $problemIds;
        try {
            return $operation();
        } finally {
            $this->active = false;
            $this->lockedIds = [];
        }
    }

    public function hasActiveChange(): bool
    {
        return $this->active;
    }

    public function isLocked(ProblemId $problemId): bool
    {
        return $this->active
            && in_array($problemId->value(), $this->lockedIds, true);
    }
}

final class MemoryProblemNumberChangeParticipant implements ProblemNumberChangeParticipant
{
    /**
     * @param list<int> $problemIds
     * @param list<int> $failOnSourceIds
     */
    public function __construct(
        private readonly string $participantKey,
        array $problemIds,
        private readonly array $failOnSourceIds = []
    ) {
        $this->problemIds = array_fill_keys($problemIds, true);
    }

    /**
     * @var array<int, true>
     */
    private array $problemIds;

    private bool $failurePending = true;

    public function key(): string
    {
        return $this->participantKey;
    }

    public function inspect(ProblemNumberMap $mapping): array
    {
        foreach ($mapping->sourceIds() as $sourceId) {
            if (! isset($this->problemIds[$sourceId])) {
                throw new DomainException('변경할 문제 번호가 없습니다.');
            }
        }
        $sources = array_fill_keys($mapping->sourceIds(), true);
        foreach ($mapping->targetIds() as $targetId) {
            if (isset($this->problemIds[$targetId]) && ! isset($sources[$targetId])) {
                throw new DomainException('변경할 번호가 이미 사용 중입니다.');
            }
        }

        return ['problem_rows' => $mapping->count()];
    }

    public function move(ProblemNumberMap $mapping): void
    {
        if (
            $this->failurePending
            && $mapping->sourceIds() === $this->failOnSourceIds
        ) {
            $this->failurePending = false;
            throw new RuntimeException('forced participant failure');
        }
        $this->inspect($mapping);
        foreach ($mapping->sourceIds() as $sourceId) {
            unset($this->problemIds[$sourceId]);
        }
        foreach ($mapping->targetIds() as $targetId) {
            $this->problemIds[$targetId] = true;
        }
    }

    public function verifyMoved(ProblemNumberMap $mapping): void
    {
        foreach ($mapping->sourceIds() as $sourceId) {
            if (isset($this->problemIds[$sourceId])) {
                throw new RuntimeException('이전 문제 번호가 남아 있습니다.');
            }
        }
        foreach ($mapping->targetIds() as $targetId) {
            if (! isset($this->problemIds[$targetId])) {
                throw new RuntimeException('새 문제 번호가 없습니다.');
            }
        }
    }

    /**
     * @return list<int>
     */
    public function problemIds(): array
    {
        $ids = array_keys($this->problemIds);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}

final class MemoryJobStepJournal implements JobStepJournal
{
    /**
     * @var array<string, string>
     */
    public array $statuses = [];

    public function start(
        ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        string $inputHash,
        array $checkpoint = []
    ): void {
        unset($claim, $stepOrder, $inputHash, $checkpoint);
        $this->statuses[$stepKey] = 'running';
    }

    public function complete(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        unset($claim, $validationResult);
        $this->statuses[$stepKey] = 'completed';
    }

    public function fail(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        unset($claim, $validationResult);
        $this->statuses[$stepKey] = 'failed';
    }

    public function compensate(
        ClaimedJobItem $claim,
        string $stepKey,
        bool $succeeded,
        array $compensationResult = []
    ): void {
        unset($claim, $compensationResult);
        $this->statuses[$stepKey] = $succeeded
            ? 'compensated'
            : 'compensation_failed';
    }
}

final class MemoryProblemArchiveManager implements ProblemArchiveManager
{
    public string $lastOperation = '';

    /**
     * @var array<string, string>
     */
    public array $lastHashes = [];

    public function inspect(
        ProblemId $problemId,
        string $archiveName,
        ?string $expectedArchiveSha256 = null
    ): ProblemArchiveInspection {
        unset($problemId, $archiveName, $expectedArchiveSha256);

        throw new RuntimeException('not used');
    }

    public function create(
        ProblemId $problemId,
        string $archiveName,
        array $expectedHashesByFile
    ): ProblemArchiveResult {
        unset($problemId, $archiveName);
        $this->lastOperation = 'create';
        $this->lastHashes = $expectedHashesByFile;

        return new ProblemArchiveResult(
            count($expectedHashesByFile),
            128,
            str_repeat('c', 64)
        );
    }

    public function extract(
        ProblemId $problemId,
        string $archiveName,
        string $expectedArchiveSha256,
        array $overwriteHashesByFile
    ): ProblemArchiveResult {
        unset($problemId, $archiveName, $expectedArchiveSha256);
        $this->lastOperation = 'extract';
        $this->lastHashes = $overwriteHashesByFile;

        return new ProblemArchiveResult(2, 256, str_repeat('d', 64));
    }
}

final class MemoryProblemSpjCompiler implements ProblemSpjCompiler
{
    /**
     * @var list<mixed>
     */
    public array $arguments = [];

    public function compile(
        ProblemId $problemId,
        string $sourceName,
        string $expectedSourceSha256,
        ?string $expectedBinarySha256,
        ?string $expectedLogSha256
    ): SpjCompileResult {
        $this->arguments = [
            $problemId->value(),
            $sourceName,
            $expectedSourceSha256,
            $expectedBinarySha256,
            $expectedLogSha256,
        ];

        return new SpjCompileResult(
            true,
            'cpp17',
            str_repeat('e', 64),
            4096,
            str_repeat('f', 64),
            'problem.spj_compiled'
        );
    }
}

final class MemorySpjSandboxProfiles implements SpjSandboxProfileProvider
{
    public function current(): SpjSandboxProfile
    {
        return new SpjSandboxProfile('/usr/bin/bwrap');
    }
}

final class CapturingBoundedProcessRunner implements BoundedProcessRunner
{
    /**
     * @var list<string>
     */
    public array $command = [];

    public string $workingDirectory = '';

    public function run(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        int $maximumOutputBytes
    ): BoundedProcessResult {
        unset($timeoutSeconds, $maximumOutputBytes);
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;

        return new BoundedProcessResult(0, '', '', false, false);
    }
}

final class FixedProblemDataRoot implements ProblemDataRootProvider
{
    public function __construct(private readonly string $path)
    {
    }

    public function root(): string
    {
        return $this->path;
    }

    public function problemDirectory(ProblemId $problemId): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $problemId->value();
    }
}

function problemFromDraft(ProblemId $id, ProblemDraft $draft): JudgeProblem
{
    return new JudgeProblem(
        $id,
        $draft->title(),
        $draft->description(),
        $draft->input(),
        $draft->output(),
        $draft->sampleInput(),
        $draft->sampleOutput(),
        $draft->hint(),
        $draft->source(),
        $draft->timeLimitSeconds(),
        $draft->memoryLimitMegabytes(),
        $draft->specialJudge(),
        $draft->visibility(),
        0,
        0,
        $draft->forbiddenCode(),
        $draft->prefixCode(),
        $draft->starterCode(),
        $draft->suffixCode(),
        $draft->languageMask()
    );
}

function problemDraft(
    string $title = '두 수의 합',
    bool $specialJudge = false,
    string $forbiddenCode = "eval\nexec",
    ?int $languageMask = 1
): ProblemDraft
{
    return new ProblemDraft(
        $title,
        '두 정수를 더합니다.',
        '두 정수 A와 B',
        'A+B',
        '1 2',
        '3',
        '',
        '기초',
        1,
        128,
        $specialJudge,
        ProblemVisibility::Published,
        $forbiddenCode,
        '',
        'print({{OJ_BLANK:answer}})',
        '',
        $languageMask
    );
}

function problemAiClaim(
    string $taskType,
    string $targetKey,
    string $inputHash,
    int $attempt = 1,
    int $maxAttempts = 1
): ClaimedJobItem {
    return new ClaimedJobItem(
        10,
        10,
        $taskType,
        'problem',
        $targetKey,
        $inputHash,
        null,
        $inputHash,
        new FormDraftReference(
            'draft:v1:00000000-0000-4000-8000-000000000010'
        ),
        $attempt,
        $maxAttempts,
        1,
        str_repeat('c', 64)
    );
}

$judgeLanguages = new JudgeLanguageCatalog();
$onlyCAndPythonMask = $judgeLanguages->disabledMaskForEnabled([0, 6]);
problemsSame(
    [0, 6],
    $judgeLanguages->enabledIds($onlyCAndPythonMask),
    '허용 언어 체크 상태를 HUSTOJ 비트 마스크에서 복원해야 합니다.'
);
$requestBody = [
    'title' => '언어 선택 문제',
    'description' => '설명',
    'input' => '입력',
    'output' => '출력',
    'sample_input' => '1',
    'sample_output' => '1',
    'hint' => '',
    'source' => '',
    'time_limit' => '1',
    'memory_limit' => '128',
    'visibility' => 'published',
    'code_entry_mode' => 'full_code',
    'forbidden_code' => '',
    'prefix_code' => '',
    'starter_code' => '',
    'suffix_code' => '',
    'enabled_language_ids' => ['0', '6'],
    'admin_notes' => '',
];
$languageCommand = (new ProblemRequestFactory($judgeLanguages))->editCommand(
    new HttpRequest('POST', '/oj-action', [], $requestBody)
);
$escapedWordBody = $requestBody;
$escapedWordBody['forbidden_code'] = 'eval/exec/\/';
$escapedWordBody['prefix_code'] = "import sys\nhidden_prefix_fixture = 42 #hidden";
$escapedWordBody['suffix_code'] = 'hidden_suffix_fixture() #hidden';
$escapedWordCommand = (new ProblemRequestFactory($judgeLanguages))->editCommand(
    new HttpRequest('POST', '/oj-action', [], $escapedWordBody)
);
problemsSame(
    'eval/exec/\/',
    $escapedWordCommand->problem()->forbiddenCode(),
    '저장 요청은 금지어의 슬래시 이스케이프를 제거하면 안 됩니다.'
);
problemsSame(
    ['eval', 'exec', '/'],
    problemFromDraft(new ProblemId(9999), $escapedWordCommand->problem())->forbiddenWords(),
    '저장한 금지어의 슬래시와 구분자를 구별해야 합니다.'
);
problemsSame($escapedWordBody['prefix_code'], $escapedWordCommand->problem()->prefixCode(), '저장 요청은 숨김 표식이 있는 앞 코드 원문을 유지해야 합니다.');
problemsSame($escapedWordBody['suffix_code'], $escapedWordCommand->problem()->suffixCode(), '저장 요청은 숨김 표식이 있는 뒤 코드 원문을 유지해야 합니다.');
problemsSame(
    $onlyCAndPythonMask,
    $languageCommand->problem()->languageMask(),
    '문제 저장 요청은 선택한 언어를 HUSTOJ 비트 마스크로 변환해야 합니다.'
);
$richTextBody = $requestBody;
$richTextBody['description'] = '<p><strong>설명</strong> $x^2$</p>';
$richTextCommand = (new ProblemRequestFactory($judgeLanguages))->editCommand(
    new HttpRequest('POST', '/oj-action', [], $richTextBody)
);
problemsSame(
    '<p><strong>설명</strong> $x^2$</p>',
    $richTextCommand->problem()->description(),
    '문제 저장 요청은 편집기 서식과 LaTeX 원문을 함께 전달해야 합니다.'
);
$xssBody = $requestBody;
$xssBody['description'] = '<p>설명</p><script>alert(1)</script>';
$xssBody['hint'] = '<img src="x" onerror="alert(1)">힌트';
$xssCommand = (new ProblemRequestFactory($judgeLanguages))->editCommand(
    new HttpRequest('POST', '/oj-action', [], $xssBody)
);
problemsAssert(
    str_contains($xssCommand->problem()->description(), '<p>설명</p>')
        && ! str_contains($xssCommand->problem()->description(), '<script')
        && ! str_contains($xssCommand->problem()->description(), 'alert(1)'),
    '문제 저장 요청은 허용하지 않는 태그·스크립트를 제거해야 합니다.'
);
problemsAssert(
    ! str_contains($xssCommand->problem()->hint(), 'onerror'),
    '문제 저장 요청은 허용하지 않는 이벤트 속성을 제거해야 합니다.'
);
$renderedRichText = (new ProblemHtml(new MemoryTopics()))->richText(
    '<p>설명</p><script>alert(1)</script>'
);
problemsAssert(
    str_contains($renderedRichText, '<p>설명</p>')
        && ! str_contains($renderedRichText, '<script')
        && ! str_contains($renderedRichText, 'alert(1)'),
    '문제 본문 렌더링은 저장된 값에 남아 있을 수 있는 허용하지 않는 태그도 제거해야 합니다.'
);
$problemHtml = new ProblemHtml(new MemoryTopics());
problemsSame(
    '',
    $problemHtml->specialJudgeBadge(false),
    '일반 채점 문제에는 특수 채점 배지를 표시하면 안 됩니다.'
);
$specialJudgeBadge = $problemHtml->specialJudgeBadge(true);
problemsAssert(
    str_contains($specialJudgeBadge, 'ojp-status-badge--info')
        && str_contains($specialJudgeBadge, '>특수 채점</span>')
        && str_contains($specialJudgeBadge, '전용 채점 프로그램으로 정답을 검사합니다.'),
    '특수 채점 문제에는 설명이 포함된 정보 배지를 표시해야 합니다.'
);
$noLanguageRejected = false;
try {
    $noLanguageBody = $requestBody;
    unset($noLanguageBody['enabled_language_ids']);
    (new ProblemRequestFactory($judgeLanguages))->editCommand(
        new HttpRequest('POST', '/oj-action', [], $noLanguageBody)
    );
} catch (DomainException) {
    $noLanguageRejected = true;
}
problemsAssert(
    $noLanguageRejected,
    '문제에는 허용 언어를 하나 이상 선택해야 합니다.'
);

$formTopics = new MemoryTopics();
$problemEditForm = new ProblemEditForm(
    $formTopics,
    new ProblemsActionUrls(),
    new ProblemsActionSecurity(),
    new ProblemHtml($formTopics),
    $judgeLanguages
);
$problemEditHtml = $problemEditForm->render(
    problemFromDraft(
        new ProblemId(1001),
        problemDraft(languageMask: $onlyCAndPythonMask)
    ),
    null,
    1001
);
$hiddenEditHtml = $problemEditForm->render(
    problemFromDraft(new ProblemId(1001), $escapedWordCommand->problem()), null, 1001
);
problemsAssert(
    str_contains($hiddenEditHtml, $escapedWordBody['prefix_code'])
        && str_contains($hiddenEditHtml, $escapedWordBody['suffix_code']),
    '관리자 문제 수정에서는 숨긴 코드와 표식을 그대로 편집할 수 있어야 합니다.'
);
problemsSame(
    count($judgeLanguages->all()),
    substr_count($problemEditHtml, 'name="enabled_language_ids[]"'),
    '문제 수정 화면은 제출 설정과 같은 전체 언어 체크박스를 보여야 합니다.'
);
problemsAssert(
    ! str_contains($problemEditHtml, 'name="language_mask"')
        && str_contains(
            $problemEditHtml,
            'name="enabled_language_ids[]" value="0" checked'
        )
        && ! str_contains(
            $problemEditHtml,
            'name="enabled_language_ids[]" value="1" checked'
        )
        && str_contains(
            $problemEditHtml,
            'name="enabled_language_ids[]" value="6" checked'
        ),
    '문제 수정 화면은 숫자 마스크를 숨기고 저장된 허용 언어만 선택해야 합니다.'
);
problemsSame(
    4,
    substr_count($problemEditHtml, 'data-ojp-rich-text-editor'),
    '문제 설명·입력·출력·힌트는 같은 공통 편집기를 사용해야 합니다.'
);
problemsSame(
    4,
    substr_count($problemEditHtml, 'data-ojp-rich-text-source'),
    '공통 편집기는 네 필드의 저장용 원문 입력을 각각 유지해야 합니다.'
);
problemsSame(
    4,
    substr_count($problemEditHtml, 'data-editor-tables="true"'),
    '각 공통 편집기는 동일한 서식 도구 모음을 제공해야 합니다.'
);
problemsAssert(
    str_contains($problemEditHtml, 'name="description"')
        && str_contains($problemEditHtml, 'name="input"')
        && str_contains($problemEditHtml, 'name="output"')
        && str_contains($problemEditHtml, 'name="hint"')
        && str_contains($problemEditHtml, 'name="sample_input"')
        && str_contains($problemEditHtml, 'name="sample_output"'),
    '편집기 적용 후에도 문제 본문과 예시 입출력 저장 필드를 모두 유지해야 합니다.'
);

$testDataDefaults = ProblemTestDataConfiguration::from([]);
problemsSame(
    '/home/judge/data',
    $testDataDefaults->root(),
    '테스트 데이터 기본 루트는 HUSTOJ 데이터 경로여야 합니다.'
);
problemsSame(
    '/usr/bin/bwrap',
    $testDataDefaults->sandboxExecutable(),
    'SPJ 기본 격리 실행기는 bwrap이어야 합니다.'
);
$testDataSettingsStore = new ProblemsSettingsManager();
$testDataSettings = new ProblemTestDataSettingsService($testDataSettingsStore);
$testDataSettingsRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-settings-' . bin2hex(random_bytes(6));
if (! mkdir($testDataSettingsRoot, 0700) && ! is_dir($testDataSettingsRoot)) {
    throw new RuntimeException('테스트 환경 설정용 임시 폴더를 만들지 못했습니다.');
}
try {
    $testDataSettings->saveRoot($testDataSettingsRoot, 0);
    $savedTestDataSettings = ProblemTestDataConfiguration::from(
        $testDataSettingsStore->values['problem_test_data'] ?? []
    );
    problemsSame(
        realpath($testDataSettingsRoot),
        $savedTestDataSettings->root(),
        '검증한 테스트 데이터 절대 경로를 저장해야 합니다.'
    );

    $relativeRootRejected = false;
    try {
        $testDataSettings->saveRoot('relative/test-data', 1);
    } catch (DomainException) {
        $relativeRootRejected = true;
    }
    problemsAssert($relativeRootRejected, '상대 테스트 데이터 경로를 거부해야 합니다.');

    $unapprovedSpjRejected = false;
    try {
        $testDataSettings->saveSpj(
            $testDataSettingsRoot . DIRECTORY_SEPARATOR . 'gcc',
            '/usr/bin/g++',
            '/usr/bin/prlimit',
            '/usr/bin/bwrap',
            1
        );
    } catch (DomainException) {
        $unapprovedSpjRejected = true;
    }
    problemsAssert($unapprovedSpjRejected, '승인되지 않은 SPJ 실행 경로를 거부해야 합니다.');
} finally {
    rmdir($testDataSettingsRoot);
}
$schemaConfig = require $pluginRoot . '/config/problems-schema.php';
problemsSame(15, count($schemaConfig), 'Problems 스키마는 15개여야 합니다.');
$schemaNames = [];
foreach ($schemaConfig as $definition) {
    $schema = SchemaTableDefinition::fromArray($definition);
    $schemaNames[] = $schema->key();
    problemsSame(
        'problems',
        $schema->owner()->value(),
        'Problems 스키마 소유자가 올바르지 않습니다.'
    );
    problemsAssert(
        ! str_starts_with($schema->key(), 'oj_'),
        'Problems 테이블에 접두어를 사용할 수 없습니다.'
    );
}
sort($schemaNames);
problemsSame(
    [
        'personal_problem_book_items',
        'problem_book_categories',
        'problem_book_category_items',
        'problem_collaborators',
        'problem_metadata',
        'problem_ownerships',
        'problem_topic_links',
        'quiz_attempt_item_choices',
        'quiz_attempt_items',
        'quiz_attempt_summaries',
        'quiz_choices',
        'quiz_question_bank',
        'quiz_question_bank_choices',
        'quiz_questions',
        'quiz_revisions',
    ],
    $schemaNames,
    'Problems 스키마 이름이 동결 설계와 다릅니다.'
);

$judgeGatewayReflection = new ReflectionClass(MysqliProblemGateway::class);
$judgeGateway = $judgeGatewayReflection->newInstanceWithoutConstructor();
$counterSql = $judgeGatewayReflection->getMethod('selectColumns')->invoke($judgeGateway, []);
problemsAssert(
    str_contains($counterSql, '0 AS `accepted`')
        && str_contains($counterSql, '0 AS `submit`')
        && ! str_contains($counterSql, 'SELECT COUNT'),
    '문제 목록의 통계는 행별 상관 쿼리 대신 페이지 단위로 집계해야 합니다.'
);
problemsAssert(
    ! str_contains($counterSql, 'contest_id') && ! str_contains($counterSql, 'class_id')
        && ! str_contains($counterSql, 'DISTINCT'),
    '문제 통계는 수업·대회 제출을 누락하거나 정답자 수와 제출 횟수를 혼용하면 안 됩니다.'
);
$whereMethod = $judgeGatewayReflection->getMethod('where');
[$allJudgeWhere] = $whereMethod->invoke(
    $judgeGateway,
    new ProblemSearch('', null, 50, 0, null, null)
);
[$specialJudgeWhere] = $whereMethod->invoke(
    $judgeGateway,
    new ProblemSearch('', null, 50, 0, null, true)
);
[$standardJudgeWhere] = $whereMethod->invoke(
    $judgeGateway,
    new ProblemSearch('', null, 50, 0, null, false)
);
problemsAssert(
    ! str_contains($allJudgeWhere, '`spj`'),
    '채점 방식 전체 검색은 SPJ 조건을 추가하면 안 됩니다.'
);
problemsAssert(
    str_contains($specialJudgeWhere, "IN ('1', 'Y')")
        && ! str_contains($specialJudgeWhere, 'NOT ('),
    '특수 채점 검색은 HUSTOJ의 1·Y 값을 모두 포함해야 합니다.'
);
problemsAssert(
    str_contains($standardJudgeWhere, "NOT (")
        && str_contains($standardJudgeWhere, "IN ('1', 'Y')"),
    '일반 채점 검색은 HUSTOJ의 특수 채점 값을 제외해야 합니다.'
);
$writeDataMethod = $judgeGatewayReflection->getMethod('writeData');
$judgeWriteData = $writeDataMethod->invoke($judgeGateway, problemDraft(), []);
problemsSame("eval\nexec", $judgeWriteData['bann'] ?? null, 'Judge bann 쓰기 매핑이 올바르지 않습니다.');
problemsSame('', $judgeWriteData['front'] ?? null, 'Judge front 쓰기 매핑이 올바르지 않습니다.');
problemsSame('print({{OJ_BLANK:answer}})', $judgeWriteData['body'] ?? null, 'Judge body 쓰기 매핑이 올바르지 않습니다.');
problemsSame('', $judgeWriteData['rear'] ?? null, 'Judge rear 쓰기 매핑이 올바르지 않습니다.');
problemsSame(1, $judgeWriteData['langmask'] ?? null, 'Judge langmask 쓰기 매핑이 올바르지 않습니다.');
$defaultMaskWriteData = $writeDataMethod->invoke(
    $judgeGateway,
    problemDraft('기본 언어', false, "eval\nexec", null),
    []
);
problemsSame(
    ProblemDraft::DEFAULT_LANGUAGE_MASK,
    $defaultMaskWriteData['langmask'] ?? null,
    '빈 허용 언어 값은 HUSTOJ 기본 마스크를 사용해야 합니다.'
);
$longForbiddenRejected = false;
try {
    problemDraft('긴 금지 코드', false, str_repeat('x', 101));
} catch (InvalidArgumentException) {
    $longForbiddenRejected = true;
}
problemsAssert($longForbiddenRejected, 'HUSTOJ bann 100자 한도를 초과하면 안 됩니다.');
$largeMaskRejected = false;
try {
    problemDraft('큰 언어 값', false, '', ProblemDraft::MAX_LANGUAGE_MASK + 1);
} catch (InvalidArgumentException) {
    $largeMaskRejected = true;
}
problemsAssert($largeMaskRejected, 'HUSTOJ langmask INT 범위를 초과하면 안 됩니다.');

$hydrateMethod = $judgeGatewayReflection->getMethod('hydrate');
$mappedProblem = $hydrateMethod->invoke($judgeGateway, [
    'problem_id' => 1234,
    'title' => 'Judge 정책 매핑',
    'description' => '설명',
    'input' => '입력',
    'output' => '출력',
    'sample_input' => '1',
    'sample_output' => '1',
    'hint' => '',
    'source' => '검사',
    'time_limit' => 1,
    'memory_limit' => 128,
    'spj' => '0',
    'defunct' => 'N',
    'accepted' => 2,
    'submit' => 3,
    'bann' => "eval\nexec",
    'front' => 'prefix',
    'body' => 'starter',
    'rear' => 'suffix',
    'langmask' => 4194303,
]);
problemsSame("eval\nexec", $mappedProblem->forbiddenCode(), 'Judge bann 읽기 매핑이 올바르지 않습니다.');
problemsSame('prefix', $mappedProblem->prefixCode(), 'Judge front 읽기 매핑이 올바르지 않습니다.');
problemsSame('starter', $mappedProblem->starterCode(), 'Judge body 읽기 매핑이 올바르지 않습니다.');
problemsSame('suffix', $mappedProblem->suffixCode(), 'Judge rear 읽기 매핑이 올바르지 않습니다.');
problemsSame(4194303, $mappedProblem->languageMask(), 'Judge langmask 읽기 매핑이 올바르지 않습니다.');

$judgeConfig = require $pluginRoot . '/config/judge.php';
foreach (['bann', 'front', 'body', 'rear', 'langmask'] as $requiredPolicyColumn) {
    problemsAssert(
        isset($judgeConfig['required_columns']['problem'][$requiredPolicyColumn]),
        'Judge problem 필수 코드 정책 열이 누락되었습니다: ' . $requiredPolicyColumn
    );
}
$topicSeed = require $pluginRoot . '/config/problem-topics.php';
problemsSame(93, count($topicSeed), '초기 문제 주제 사전은 93개여야 합니다.');
$seedKeys = [];
foreach ($topicSeed as $topic) {
    $seedKeys[(string) $topic['key']] = $topic;
}
foreach ($topicSeed as $topic) {
    $category = $topic['category'] ?? null;
    if ($category !== null) {
        problemsAssert(
            isset($seedKeys[$category])
                && ($seedKeys[$category]['category'] ?? null) === null,
            '초기 문제 주제는 정확히 분야와 세부 주제의 2단계여야 합니다.'
        );
    }
}
$versionTopics = array_map(
    static fn (array $topic): ProblemTopic => new ProblemTopic(
        (string) $topic['key'],
        (string) $topic['label'],
        is_string($topic['category'] ?? null) ? $topic['category'] : null,
        is_array($topic['aliases'] ?? null) ? $topic['aliases'] : [],
        true
    ),
    $topicSeed
);
$dictionaryVersion = ProblemTopicDictionaryVersion::fromTopics($versionTopics);
problemsSame(
    49,
    strlen($dictionaryVersion),
    '문제 주제 사전 버전은 VARCHAR(50)에 저장할 수 있어야 합니다.'
);
problemsAssert(
    preg_match('/^td-v1:[A-Za-z0-9_-]{43}$/', $dictionaryVersion) === 1,
    '문제 주제 사전 버전은 URL-safe SHA-256 형식이어야 합니다.'
);
problemsSame(
    $dictionaryVersion,
    ProblemTopicDictionaryVersion::fromTopics(array_reverse($versionTopics)),
    '주제 조회 순서가 달라도 사전 버전은 같아야 합니다.'
);
$changedVersionTopics = $versionTopics;
$changedVersionTopics[] = new ProblemTopic(
    'version-test',
    '버전 변경 확인',
    null,
    [],
    true
);
problemsAssert(
    $dictionaryVersion
        !== ProblemTopicDictionaryVersion::fromTopics($changedVersionTopics),
    '주제 사전 내용이 바뀌면 버전도 바뀌어야 합니다.'
);

$databaseErrorExposed = false;
try {
    (new ProblemPlatformDatabase(
        new FailingProblemPlatformConnection()
    ))->insert('problem_metadata', ['problem_id' => 1000]);
} catch (RuntimeException $error) {
    $databaseErrorExposed = str_contains(
        $error->getMessage(),
        "Data too long for column 'topic_dictionary_version'"
    );
}
problemsAssert(
    $databaseErrorExposed,
    '플랫폼 문제 저장 실패의 DB 원인을 시스템 기록용 예외에 포함해야 합니다.'
);

problemsSame(1, (new DifficultyScore(1))->value(), '최소 난이도가 올바르지 않습니다.');
problemsSame(5, (new DifficultyScore(5))->value(), '최대 난이도가 올바르지 않습니다.');
foreach ([0, 6] as $invalidDifficulty) {
    $rejected = false;
    try {
        new DifficultyScore($invalidDifficulty);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    problemsAssert($rejected, '1~5 밖의 난이도를 거부해야 합니다.');
}

$numberRange = ProblemNumberMap::range(1000, 1002, 2000);
problemsSame(
    [1000 => 2000, 1001 => 2001, 1002 => 2002],
    $numberRange->pairs(),
    '연속 문제 번호 매핑이 올바르지 않습니다.'
);
problemsSame(
    [2000 => 1000, 2001 => 1001, 2002 => 1002],
    $numberRange->inverse()->pairs(),
    '문제 번호 역매핑이 올바르지 않습니다.'
);
$duplicateTargetRejected = false;
try {
    new ProblemNumberMap([1000 => 2000, 1001 => 2000]);
} catch (InvalidArgumentException) {
    $duplicateTargetRejected = true;
}
problemsAssert($duplicateTargetRejected, '중복 대상 문제 번호를 거부해야 합니다.');
$swapNumbers = new ProblemNumberMap([1000 => 1001, 1001 => 1000]);
problemsSame(
    [1000 => 1001, 1001 => 1000],
    $swapNumbers->pairs(),
    '서로 번호를 교환하는 문제 집합도 임시 번호 단계로 변경할 수 있어야 합니다.'
);
$judgeNumberParticipantSource = (string) file_get_contents(
    $pluginRoot
        . '/modules/Problems/Infrastructure/Judge/'
        . 'MysqliProblemNumberChangeParticipant.php'
);
problemsAssert(
    str_contains($judgeNumberParticipantSource, 'LOCK TABLES')
        && str_contains($judgeNumberParticipantSource, 'temporaryMapping')
        && str_contains($judgeNumberParticipantSource, 'revertOperations'),
    'Judge 번호 변경은 테이블 잠금·임시 번호·명시적 역순 복구를 함께 사용해야 합니다.'
);
problemsAssert(
    ! str_contains($judgeNumberParticipantSource, 'begin_transaction')
        && ! str_contains($judgeNumberParticipantSource, 'assertTransactional'),
    'Judge 번호 변경은 MyISAM에서 무효인 트랜잭션 롤백에 의존하면 안 됩니다.'
);

$platformNumberParticipantSource = (string) file_get_contents(
    $pluginRoot
        . '/modules/Problems/Infrastructure/WordPress/'
        . 'WordPressPlatformProblemNumberChangeParticipant.php'
);
problemsAssert(
    str_contains($platformNumberParticipantSource, 'contest_problem_settings'),
    '문제 번호 변경은 대회별 문제 배점 참조도 함께 변경해야 합니다.'
);

$numberClaim = new ClaimedJobItem(
    1,
    1,
    'problem_number_change',
    'problem_number_mapping',
    'mapping:test',
    null,
    null,
    str_repeat('a', 64),
    new FormDraftReference('draft:v1:00000000-0000-4000-8000-000000000000'),
    1,
    1,
    1,
    str_repeat('b', 64)
);
$requestedNumbers = new ProblemNumberMap([1000 => 2000, 1001 => 2001]);
$temporaryNumbers = new ProblemNumberMap([1000 => 9000, 1001 => 9001]);
$numberImpact = [
    'judge' => ['problem_rows' => 2],
    'platform' => ['problem_rows' => 2],
];
$numberPlan = new ProblemNumberChangePlan(
    $requestedNumbers,
    $temporaryNumbers,
    $numberImpact
);
$judgeParticipant = new MemoryProblemNumberChangeParticipant(
    'judge',
    [1000, 1001]
);
$platformParticipant = new MemoryProblemNumberChangeParticipant(
    'platform',
    [1000, 1001]
);
$numberJournal = new MemoryJobStepJournal();
$numberCoordinator = new ProblemNumberChangeCoordinator(
    [$judgeParticipant, $platformParticipant],
    new MemoryProblemNumberChangeLock(),
    $numberJournal
);
$numberResult = $numberCoordinator->execute($numberClaim, $numberPlan);
problemsSame('succeeded', $numberResult->outcome(), '문제 번호 변경을 완료해야 합니다.');
problemsSame([2000, 2001], $judgeParticipant->problemIds(), 'Judge 문제 번호를 변경해야 합니다.');
problemsSame([2000, 2001], $platformParticipant->problemIds(), '플랫폼 문제 번호를 변경해야 합니다.');
problemsSame('completed', $numberJournal->statuses['verify.judge'] ?? null, 'Judge 변경 검증 단계를 기록해야 합니다.');
problemsSame('completed', $numberJournal->statuses['verify.platform'] ?? null, '플랫폼 변경 검증 단계를 기록해야 합니다.');

$compensatedJudge = new MemoryProblemNumberChangeParticipant(
    'judge',
    [1000, 1001]
);
$compensatedPlatform = new MemoryProblemNumberChangeParticipant(
    'platform',
    [1000, 1001],
    [9000, 9001]
);
$compensationJournal = new MemoryJobStepJournal();
$compensationCoordinator = new ProblemNumberChangeCoordinator(
    [$compensatedJudge, $compensatedPlatform],
    new MemoryProblemNumberChangeLock(),
    $compensationJournal
);
$compensationResult = $compensationCoordinator->execute(
    $numberClaim,
    $numberPlan
);
problemsSame('compensated', $compensationResult->outcome(), '중간 실패 시 완료 단계를 복구해야 합니다.');
problemsSame([1000, 1001], $compensatedJudge->problemIds(), 'Judge 번호를 원래 값으로 복구해야 합니다.');
problemsSame([1000, 1001], $compensatedPlatform->problemIds(), '플랫폼 번호를 원래 값으로 복구해야 합니다.');
problemsSame('compensated', $compensationJournal->statuses['finalize.judge'] ?? null, '완료된 최종 변경 단계를 보상 처리해야 합니다.');
problemsSame('compensated', $compensationJournal->statuses['stage.platform'] ?? null, '임시 변경 단계도 보상 처리해야 합니다.');

$completion = new CodeCompletionTemplatePolicy();
$prepared = $completion->prepare(
    "print({{OJ_BLANK:answer}})\r\n",
    '{"language_key":"python","blanks":[{"id":"answer","label":"정답 식","kind":"inline","max_bytes":100}]}',
    1
);
problemsAssert(
    preg_match('/^[a-f0-9]{64}$/', $prepared['hash']) === 1,
    '빈칸 완성 해시는 SHA-256이어야 합니다.'
);
$invalidCompletionRejected = false;
try {
    $completion->prepare(
        'print({{OJ_BLANK:missing}})',
        '{"language_key":"python","blanks":[{"id":"answer","label":"정답","kind":"inline","max_bytes":100}]}',
        1
    );
} catch (DomainException) {
    $invalidCompletionRejected = true;
}
problemsAssert($invalidCompletionRejected, '템플릿과 스키마가 다른 빈칸을 거부해야 합니다.');

$capabilityConfig = require $pluginRoot . '/config/capabilities.php';
$capabilities = new DefinitionRegistry('problem-test-capabilities', CapabilityDefinition::class);
foreach ($capabilityConfig['capabilities'] as $definition) {
    $capabilities->register(CapabilityDefinition::fromArray($definition));
}
$permissions = new PermissionService(
    $capabilities,
    new ProblemsCapabilityChecker(
        [
            1 => [
                'oj_create_problems',
                'oj_edit_problems',
                'oj_delete_problems',
                'oj_manage_problem_ai_assist',
                'oj_manage_problem_xml',
            ],
            20 => ['oj_manage_testcases', 'oj_manage_problem_ai_assist'],
            21 => ['oj_manage_problem_ai_assist'],
        ],
        [99]
    )
);
$assignments = new MemoryAssignments();
$problemId = new ProblemId(1000);
$assignments->items[1000] = new ProblemAssignment(
    $problemId,
    10,
    [11 => CollaboratorRole::Editor, 12 => CollaboratorRole::TestcaseManager]
);
$aiAvailability = new MemoryProblemAiAvailability([
    'problem_metadata_generate' => true,
    'problem_authoring_generate' => true,
    'problem_testcase_generate' => true,
]);
$access = new ProblemAccessPolicy(
    $permissions,
    $assignments,
    $aiAvailability,
    new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry()
);
$jobAccess = new ProblemJobAccessRule(
    $permissions,
    $access,
    new QuizAccessPolicy($permissions, $access)
);
$metadataJob = new JobDetails(
    1,
    'problem_metadata_generate',
    'problems',
    'problems.metadata.generate',
    '1',
    1,
    'failed',
    1,
    0,
    1,
    0,
    '2026-07-27 00:00:00',
    '2026-07-27 00:01:00',
    ['problem_ids' => [1000]],
    [],
    [],
    []
);
$invalidAuthoringJob = new JobDetails(
    2,
    'problem_authoring_generate',
    'problems',
    'problems.authoring.generate',
    '1',
    1,
    'failed',
    1,
    0,
    1,
    0,
    '2026-07-27 00:00:00',
    '2026-07-27 00:01:00',
    [],
    [],
    [],
    []
);
problemsAssert($access->canEdit(10, $problemId), '문제 소유자는 문제를 수정할 수 있어야 합니다.');
problemsAssert($access->canEdit(11, $problemId), '공동 편집자는 문제를 수정할 수 있어야 합니다.');
problemsAssert(! $access->canEdit(12, $problemId), '테스트 담당자는 본문을 수정할 수 없어야 합니다.');
problemsAssert($access->canManageTestData(12, $problemId), '테스트 담당자는 테스트 데이터를 관리할 수 있어야 합니다.');
problemsAssert($access->canEdit(1, $problemId), '전역 문제 편집 권한을 적용해야 합니다.');
problemsAssert($access->canRunAuthoringAi(1, $problemId), 'AI 문제 작성은 AI와 편집 권한을 모두 요구해야 합니다.');
problemsAssert($access->canRunTestcaseAi(20, $problemId), 'AI 테스트 생성은 AI와 테스트데이터 권한을 모두 요구해야 합니다.');
problemsAssert(! $access->canRunAi(21, $problemId), 'AI 권한만으로 대상 문제를 변경할 수 없어야 합니다.');
problemsAssert(
    $jobAccess->canControl(1, $metadataJob),
    '문제 AI 작업 제어 시 현재 AI 권한과 문제 범위를 다시 확인해야 합니다.'
);
problemsAssert(
    ! $jobAccess->canControl(21, $metadataJob),
    '대상 문제 범위가 없는 사용자는 자기가 만든 작업이어도 제어할 수 없어야 합니다.'
);
problemsAssert(
    ! $jobAccess->canControl(1, $invalidAuthoringJob),
    '대상 요약이 손상된 새 문제 AI 작업은 생성 권한으로 오인하면 안 됩니다.'
);
problemsAssert(! $access->canRunAuthoringAi(12, $problemId), 'AI 권한 없는 테스트 담당자는 AI 문제 작성을 실행할 수 없어야 합니다.');
$aiAvailability->tasks['problem_authoring_generate'] = false;
problemsAssert(
    ! $access->canRunAuthoringAi(1, $problemId),
    'AI 작업 배정이 없으면 권한이 있어도 문제 작성 AI를 숨겨야 합니다.'
);
$aiAvailability->tasks['problem_authoring_generate'] = true;
problemsAssert($access->canManageTopics(99), '주제 추가는 최고 관리자에게만 허용해야 합니다.');
problemsAssert(! $access->canManageTopics(1), '일반 문제 관리자는 주제를 추가할 수 없어야 합니다.');

$gateway = new MemoryProblemGateway();
$metadata = new MemoryMetadata();
$links = new MemoryTopicLinks();
$topics = new MemoryTopics();
$numberChangeLock = new MemoryProblemNumberChangeLock();
$formDrafts = new MemoryProblemFormDrafts();
$aiInputs = new ProblemAiInputFactory($topics);
$aiOutputs = new ProblemAiOutputValidator($topics);
$aiDrafts = new ProblemAiDraftService(
    $formDrafts,
    $gateway,
    $metadata,
    $topics,
    $aiInputs,
    $aiOutputs
);
$problemChanges = new class($gateway, $metadata) implements \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemChangePublisher {
    public array $events = [];
    public function __construct(private MemoryProblemGateway $gateway, private MemoryMetadata $metadata) {}
    public function saved(int $problemId, int $actorUserId, string $change): void
    {
        $id = new ProblemId($problemId);
        $this->events[] = [$problemId, $actorUserId, $change, $this->metadata->find($id) !== null, $this->gateway->find($id)?->visibility()];
    }
};
$editor = new ProblemEditorService(
    $gateway,
    $metadata,
    $links,
    $topics,
    $assignments,
    new DirectProblemTransaction(),
    $access,
    $completion,
    $numberChangeLock,
    $judgeLanguages,
    null,
    $problemChanges
);
$command = new ProblemEditCommand(
    problemDraft(languageMask: $judgeLanguages->disabledMaskForEnabled([6])),
    new DifficultyScore(3),
    ['simulation'],
    CodeEntryMode::CodeCompletion,
    '{"language_key":"python","blanks":[{"id":"answer","label":"정답 식","kind":"inline","max_bytes":100}]}',
    '검토 완료'
);
$createdId = $editor->create(1, $command);
problemsSame(1000, $createdId->value(), '새 문제 번호가 올바르지 않습니다.');
problemsSame(
    ['create:archived', 'archive:no'],
    $gateway->events,
    '새 문제는 플랫폼 정보 저장 전 보관 상태여야 합니다.'
);
problemsSame(1, $assignments->find($createdId)->ownerUserId(), '생성자를 문제 소유자로 기록해야 합니다.');
problemsSame(3, $metadata->find($createdId)?->difficulty()?->value(), '난이도 1~5를 저장해야 합니다.');
problemsSame(['simulation'], $links->keysFor($createdId), '문제 세부 주제를 순서대로 저장해야 합니다.');
problemsSame([[1000, 1, 'created', true, ProblemVisibility::Published]], $problemChanges->events, '공개 변경 이벤트는 플랫폼 정보 저장과 게시 전환 성공 뒤에 발행해야 합니다.');
$multiLanguageCompletionRejected = false;
try {
    $editor->create(
        1,
        new ProblemEditCommand(
            problemDraft(languageMask: $onlyCAndPythonMask),
            new DifficultyScore(3),
            ['simulation'],
            CodeEntryMode::CodeCompletion,
            '{"language_key":"python","blanks":[{"id":"answer","label":"정답 식","kind":"inline","max_bytes":100}]}',
            '검토 완료'
        )
    );
} catch (DomainException $error) {
    $multiLanguageCompletionRejected = str_contains($error->getMessage(), '정확히 1개');
}
problemsAssert(
    $multiLanguageCompletionRejected,
    '빈칸 완성 방식은 허용 언어가 2개 이상이면 저장을 거부해야 합니다.'
);
$multiLanguageWrapperRejected = false;
try {
    $editor->create(
        1,
        new ProblemEditCommand(
            new ProblemDraft(
                '두 수의 합',
                '두 정수를 더합니다.',
                '두 정수 A와 B',
                'A+B',
                '1 2',
                '3',
                '',
                '기초',
                1,
                128,
                false,
                ProblemVisibility::Published,
                "eval\nexec",
                'front(',
                '',
                ')rear',
                $onlyCAndPythonMask
            ),
            new DifficultyScore(3),
            ['simulation'],
            CodeEntryMode::FullCode,
            null,
            '검토 완료'
        )
    );
} catch (DomainException $error) {
    $multiLanguageWrapperRejected = str_contains($error->getMessage(), '정확히 1개');
}
problemsAssert(
    $multiLanguageWrapperRejected,
    '앞·뒤 코드가 있는 문제는 허용 언어가 2개 이상이면 저장을 거부해야 합니다.'
);
problemsSame(1, count($problemChanges->events), '검증에 실패한 문제는 변경 이벤트를 발행하지 않아야 합니다.');

// Exercise the checkbox POST contract, not a hand-written enabled-bit mask.
(function () use ($permissions, $aiAvailability, $judgeLanguages, $requestBody): void {
    $gateway = new MemoryProblemGateway();
    $metadata = new MemoryMetadata();
    $assignments = new MemoryAssignments();
    $editor = new ProblemEditorService(
        $gateway,
        $metadata,
        new MemoryTopicLinks(),
        new MemoryTopics(),
        $assignments,
        new DirectProblemTransaction(),
        new ProblemAccessPolicy(
            $permissions,
            $assignments,
            $aiAvailability,
            new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry()
        ),
        new CodeCompletionTemplatePolicy(),
        new MemoryProblemNumberChangeLock(),
        $judgeLanguages
    );
    $requests = new ProblemRequestFactory($judgeLanguages);
    $cases = [
        'prefix' => ['prefix_code' => 'front'],
        'suffix' => ['suffix_code' => 'rear'],
        'both' => ['prefix_code' => 'front', 'suffix_code' => 'rear'],
        'completion' => [
            'code_entry_mode' => 'code_completion',
            'starter_code' => 'print({{OJ_BLANK:answer}})',
            'code_completion_schema' => '{"language_key":"python","blanks":[{"id":"answer","label":"정답 식","kind":"inline","max_bytes":100}]}',
        ],
    ];
    foreach ($cases as $case => $fields) {
        $languageIds = $case === 'completion' ? [6] : array_keys($judgeLanguages->all());
        foreach ($languageIds as $languageId) {
            $body = array_replace($requestBody, $fields, [
                'title' => $case . '-' . $languageId,
                'enabled_language_ids' => [(string) $languageId],
            ]);
            $command = $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $body));
            $id = $editor->create(1, $command);
            problemsSame(
                [$languageId],
                $judgeLanguages->enabledIds($gateway->find($id)?->languageMask()),
                $case . ': 언어 하나를 선택한 신규 문제를 저장해야 합니다.'
            );
            $body['title'] .= '-updated';
            $editor->update(
                1,
                $id,
                $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $body))
            );
            $saved = $gateway->find($id);
            problemsAssert(
                $saved?->title() === $body['title']
                    && $judgeLanguages->enabledIds($saved?->languageMask()) === [$languageId]
                    && $saved?->prefixCode() === $body['prefix_code']
                    && $saved?->suffixCode() === $body['suffix_code']
                    && $saved?->starterCode() === $body['starter_code']
                    && $metadata->find($id)?->codeEntryMode() === $command->entryMode(),
                $case . ': 수정 저장에도 선택 언어와 코드 작성 설정을 보존해야 합니다.'
            );
        }
        $invalidMasks = [
            null,
            0,
            1,
            ProblemDraft::MAX_LANGUAGE_MASK,
            $judgeLanguages->disabledMaskForEnabled([0, 6]),
        ];
        foreach ($invalidMasks as $mask) {
            $draft = $command->problem();
            $invalid = new ProblemEditCommand(
                new ProblemDraft(
                    $draft->title(), $draft->description(), $draft->input(), $draft->output(),
                    $draft->sampleInput(), $draft->sampleOutput(), $draft->hint(), $draft->source(),
                    $draft->timeLimitSeconds(), $draft->memoryLimitMegabytes(), $draft->specialJudge(),
                    $draft->visibility(), $draft->forbiddenCode(), $draft->prefixCode(),
                    $draft->starterCode(), $draft->suffixCode(), $mask
                ),
                null, [], $command->entryMode(), $command->completionSchema(), ''
            );
            foreach (['create', 'update'] as $operation) {
                $before = [$gateway->items, $gateway->events, $metadata->items];
                $rejected = false;
                try {
                    if ($operation === 'create') {
                        $editor->create(1, $invalid);
                    } else {
                        $editor->update(1, $id, $invalid);
                    }
                } catch (DomainException $error) {
                    $rejected = str_contains($error->getMessage(), '정확히 1개');
                }
                problemsAssert(
                    $rejected,
                    $case . ': 허용 언어가 하나가 아니면 ' . $operation . '을 거부해야 합니다.'
                );
                problemsSame(
                    $before,
                    [$gateway->items, $gateway->events, $metadata->items],
                    '언어 검증 실패 시 원문과 메타데이터를 변경하면 안 됩니다.'
                );
            }
        }
    }
    $unrestricted = $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $requestBody));
    $id = $editor->create(1, $unrestricted);
    $editor->update(1, $id, $unrestricted);
    problemsSame(
        [0, 6],
        $judgeLanguages->enabledIds($gateway->find($id)?->languageMask()),
        '앞·뒤 코드 없는 일반 문제는 여러 언어를 허용해야 합니다.'
    );
})();

$metadataBeforeArchive = $metadata->find($createdId);
$assignmentBeforeArchive = $assignments->find($createdId);
$topicsBeforeArchive = $links->keysFor($createdId);
$editor->archive(1, $createdId, true);
$editor->archive(1, $createdId, false);
problemsAssert(
    $gateway->find($createdId)?->visibility() === ProblemVisibility::Published
        && $metadata->find($createdId) === $metadataBeforeArchive
        && $assignments->find($createdId) === $assignmentBeforeArchive
        && $links->keysFor($createdId) === $topicsBeforeArchive,
    '문제 보관·복원은 메타데이터, 담당자와 주제 관계를 지우지 않아야 합니다.'
);

$assignmentUsers = new ProblemsUsers([
    1 => new UserIdentity(1, 'creator', '', '생성자'),
    10 => new UserIdentity(10, 'owner10', '', '새 소유자'),
    11 => new UserIdentity(11, 'editor11', '', '공동 편집자'),
    12 => new UserIdentity(12, 'testcase12', '', '테스트 담당자'),
    13 => new UserIdentity(13, 'editor13', '', '추가 편집자'),
]);
$assignmentService = new ProblemAssignmentService(
    $gateway,
    $assignments,
    $access,
    $assignmentUsers
);
$assignmentView = $assignmentService->view(1, $createdId);
problemsAssert(
    $assignmentView['can_manage'],
    '문제 소유자는 담당자 변경 명령을 사용할 수 있어야 합니다.'
);
$assignmentService->grant(
    1,
    $createdId,
    'editor13',
    CollaboratorRole::Editor
);
problemsSame(
    CollaboratorRole::Editor,
    $assignments->find($createdId)->collaboratorRole(13),
    '공동 편집자 역할을 문제 담당 범위에 저장해야 합니다.'
);
$ownerAsCollaboratorRejected = false;
try {
    $assignmentService->grant(
        1,
        $createdId,
        'creator',
        CollaboratorRole::TestcaseManager
    );
} catch (DomainException) {
    $ownerAsCollaboratorRejected = true;
}
problemsAssert(
    $ownerAsCollaboratorRejected,
    '문제 소유자를 공동 담당자로 중복 등록하면 안 됩니다.'
);
$unconfirmedTransferRejected = false;
try {
    $assignmentService->assignOwner(
        1,
        $createdId,
        'owner10',
        false
    );
} catch (DomainException) {
    $unconfirmedTransferRejected = true;
}
problemsAssert(
    $unconfirmedTransferRejected,
    '확인하지 않은 소유권 이전을 거부해야 합니다.'
);
$assignmentService->assignOwner(1, $createdId, 'owner10', true);
problemsSame(
    10,
    $assignments->find($createdId)->ownerUserId(),
    '확인한 사용자에게 문제 소유권을 이전해야 합니다.'
);
problemsAssert(
    ! $access->canManageAssignments(1, $createdId),
    '이전 소유자는 전역 권한이 없으면 담당자를 계속 변경할 수 없어야 합니다.'
);
$assignmentService->revoke(10, $createdId, 13);
problemsSame(
    null,
    $assignments->find($createdId)->collaboratorRole(13),
    '현재 소유자는 공동 담당자를 해제할 수 있어야 합니다.'
);
$assignmentService->assignOwner(10, $createdId, 'creator', true);

$validatedAuthoring = $aiOutputs->authoring(
    [
        'title' => '  공백을 보존하는 문제  ',
        'description' => "설명\n",
        'input' => "입력\n",
        'output' => "출력\n",
        'sample_input' => "  1 2\n",
        'sample_output' => "3\n\n",
        'hint' => '',
    ],
    'full_draft'
);
problemsSame(
    '공백을 보존하는 문제',
    $validatedAuthoring['title'],
    'AI 문제 제목의 바깥 공백은 제거해야 합니다.'
);
problemsSame(
    "  1 2\n",
    $validatedAuthoring['sample_input'],
    'AI 예시 입력은 사용자가 확인할 원문 공백을 보존해야 합니다.'
);
problemsSame('', $validatedAuthoring['hint'], 'AI 문제 초안은 빈 힌트를 허용해야 합니다.');
$invalidAiDifficultyRejected = false;
try {
    $aiOutputs->metadata([
        'difficulty_score' => 6,
        'topic_keys' => ['simulation'],
        'confidence' => 0.8,
        'rationale' => '범위를 벗어난 난이도',
    ]);
} catch (DomainException) {
    $invalidAiDifficultyRejected = true;
}
problemsAssert(
    $invalidAiDifficultyRejected,
    'AI가 생성한 난이도도 1부터 5까지로 제한해야 합니다.'
);
$stringDifficultyMetadata = $aiOutputs->metadata([
    'difficulty_score' => '3',
    'topic_keys' => ['simulation'],
    'confidence' => 0.8,
    'rationale' => '문자열로 보고된 난이도입니다.',
]);
problemsSame(
    3,
    $stringDifficultyMetadata['difficulty_score'],
    '숫자 형태의 문자열 난이도는 거부 대신 정수로 보정해야 합니다.'
);
$nonNumericDifficultyRejected = false;
try {
    $aiOutputs->metadata([
        'difficulty_score' => 'three',
        'topic_keys' => ['simulation'],
        'confidence' => 0.8,
        'rationale' => '숫자가 아닌 난이도',
    ]);
} catch (DomainException) {
    $nonNumericDifficultyRejected = true;
}
problemsAssert(
    $nonNumericDifficultyRejected,
    '숫자가 아닌 문자열 난이도는 여전히 거부해야 합니다.'
);
$topiclessAiMetadata = $aiOutputs->metadata([
    'difficulty_score' => 1,
    'topic_keys' => [],
    'confidence' => 0.8,
    'rationale' => '현재 사전에 정확히 맞는 세부 주제가 없습니다.',
]);
problemsSame(
    [],
    $topiclessAiMetadata['topic_keys'],
    'AI도 맞는 세부 주제가 없으면 빈 주제 목록을 반환할 수 있어야 합니다.'
);
foreach ([85, -3] as $invalidConfidence) {
    $rejected = false;
    try {
        $aiOutputs->metadata([
            'difficulty_score' => 2,
            'topic_keys' => ['simulation'],
            'confidence' => $invalidConfidence,
            'rationale' => '신뢰도를 잘못된 척도로 보고했습니다.',
        ]);
    } catch (DomainException) {
        $rejected = true;
    }
    problemsAssert($rejected, '잘못된 신뢰도를 100% 또는 0%로 만들어 저장하면 안 됩니다.');
}
$problemSnapshotHash = $aiInputs->hash($aiInputs->problem(
    $gateway->find($createdId),
    $metadata->find($createdId)
));
$metadataDraftReference = $formDrafts->store(
    'problem_metadata',
    1,
    'problem',
    (string) $createdId->value(),
    [
        'difficulty_score' => 4,
        'topic_keys' => ['simulation'],
        'confidence' => 0.9,
        'rationale' => '반복과 상태 전이가 필요합니다.',
        'problem_snapshot_hash' => $problemSnapshotHash,
        'dictionary_version' => $topics->version(),
    ]
);
$reviewedMetadata = $aiDrafts->metadata(
    $metadataDraftReference->value(),
    1,
    $createdId
);
problemsSame(
    4,
    $reviewedMetadata['difficulty_score'] ?? null,
    '현재 문제와 일치하는 AI 메타데이터 초안을 검토할 수 있어야 합니다.'
);
$provenance = $aiDrafts->inspectProblemSave(
    [$metadataDraftReference->value()],
    1,
    $createdId
);
problemsSame(
    ['ai_reviewed', 'ai'],
    [$provenance['generation_method'], $provenance['difficulty_source']],
    '검토 후 적용한 AI 메타데이터의 출처를 저장해야 합니다.'
);
$foreignDraftRejected = false;
try {
    $aiDrafts->metadata($metadataDraftReference->value(), 2, $createdId);
} catch (DomainException) {
    $foreignDraftRejected = true;
}
problemsAssert($foreignDraftRejected, '다른 사용자의 AI 초안을 열 수 없어야 합니다.');

$aiRouter = new ProblemsAiRouter();
$aiSupport = new ProblemAiJobSupport($aiInputs, $aiRouter);
$metadataGenerateHandler = new ProblemMetadataGenerateJobHandler(
    $gateway,
    $metadata,
    $access,
    $aiInputs,
    $aiOutputs,
    $aiSupport,
    $formDrafts
);
$metadataInput = [
    'contract_version' => ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
    'problem' => $aiInputs->problem(
        $gateway->find($createdId),
        $metadata->find($createdId)
    ),
    'topic_dictionary' => $aiInputs->topicDictionary(),
];
$metadataInputHash = $aiInputs->hash($metadataInput);
$metadataAssignment = $aiRouter->snapshot('problem_metadata_generate');
$aiRouter->outputs['problem_metadata_generate'] = [
    'difficulty_score' => 5,
    'topic_keys' => ['simulation'],
    'confidence' => 0.85,
    'rationale' => '여러 상태를 정확히 구현해야 합니다.',
];
$metadataGenerateResult = $metadataGenerateHandler->handle(new JobWorkItem(
    problemAiClaim(
        'problem_metadata_generate',
        (string) $createdId->value(),
        $metadataInputHash
    ),
    [
        'problem_id' => $createdId->value(),
        'problem_input_hash' => $metadataInputHash,
        'input' => $metadataInput,
        'assignment' => $metadataAssignment->toArray(),
    ]
));
problemsSame(
    'succeeded',
    $metadataGenerateResult->outcome(),
    '메타데이터 AI Handler가 검증된 초안을 생성해야 합니다.'
);
$generatedMetadataReference = (string) (
    $metadataGenerateResult->safeResult()['draft_reference'] ?? ''
);
problemsSame(
    5,
    $aiDrafts->metadata(
        $generatedMetadataReference,
        1,
        $createdId
    )['difficulty_score'] ?? null,
    'AI Handler가 생성한 난이도 5 초안을 다시 검증할 수 있어야 합니다.'
);
$draftCountBeforeInvalid = count($formDrafts->items);
$aiRouter->outputs['problem_metadata_generate']['difficulty_score'] = 6;
$invalidMetadataResult = $metadataGenerateHandler->handle(new JobWorkItem(
    problemAiClaim(
        'problem_metadata_generate',
        (string) $createdId->value(),
        $metadataInputHash
    ),
    [
        'problem_id' => $createdId->value(),
        'problem_input_hash' => $metadataInputHash,
        'input' => $metadataInput,
        'assignment' => $metadataAssignment->toArray(),
    ]
));
problemsSame(
    'needs_attention',
    $invalidMetadataResult->outcome(),
    'AI가 난이도 6을 반환하면 작업을 확인 필요 상태로 보내야 합니다.'
);
problemsSame(
    $draftCountBeforeInvalid,
    count($formDrafts->items),
    '계약을 위반한 AI 메타데이터를 초안에 저장할 수 없습니다.'
);

$authoringHandler = new ProblemAuthoringGenerateJobHandler(
    $gateway,
    $metadata,
    $access,
    $aiInputs,
    $aiOutputs,
    $aiSupport,
    $formDrafts
);
$authoringInput = [
    'contract_version' => ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION,
    'mode' => 'full_draft',
    'brief' => '반복문을 학습하는 덧셈 문제',
    'requested_difficulty_score' => 2,
    'requested_topic_keys' => ['simulation'],
    'topic_dictionary' => $aiInputs->topicDictionary(),
    'existing_problem' => $aiInputs->problem(
        $gateway->find($createdId),
        $metadata->find($createdId)
    ),
];
$authoringInputHash = $aiInputs->hash($authoringInput);
$authoringAssignment = $aiRouter->snapshot('problem_authoring_generate');
$aiRouter->outputs['problem_authoring_generate'] = [
    'title' => '반복 덧셈',
    'description' => '주어진 수를 반복해서 더합니다.',
    'input' => '정수 N과 X가 주어집니다.',
    'output' => 'X를 N번 더한 값을 출력합니다.',
    'sample_input' => "3 4\n",
    'sample_output' => "12\n",
    'hint' => '',
];
$authoringResult = $authoringHandler->handle(new JobWorkItem(
    problemAiClaim(
        'problem_authoring_generate',
        (string) $createdId->value(),
        $authoringInputHash
    ),
    [
        'problem_id' => $createdId->value(),
        'problem_input_hash' => $authoringInputHash,
        'input' => $authoringInput,
        'assignment' => $authoringAssignment->toArray(),
    ]
));
problemsSame(
    'succeeded',
    $authoringResult->outcome(),
    '문제 작성 AI Handler가 편집 전 초안을 생성해야 합니다.'
);
$generatedAuthoring = $aiDrafts->authoring(
    (string) ($authoringResult->safeResult()['draft_reference'] ?? ''),
    1,
    $createdId
);
problemsSame(
    "3 4\n",
    $generatedAuthoring['fields']['sample_input'] ?? null,
    '문제 작성 AI Handler가 예시 원문을 그대로 보존해야 합니다.'
);

$testcaseGenerateHandler = new ProblemTestcaseGenerateJobHandler(
    $gateway,
    $metadata,
    $access,
    $aiInputs,
    $aiOutputs,
    $aiSupport,
    $formDrafts
);
$testcaseInput = [
    'contract_version' => ProblemAiInputFactory::TESTCASE_CONTRACT_VERSION,
    'problem' => $aiInputs->problem(
        $gateway->find($createdId),
        $metadata->find($createdId)
    ),
    'requested_pair_count' => 1,
    'guidance' => '가장 작은 정상 입력',
];
$testcaseInputHash = $aiInputs->hash($testcaseInput);
$testcaseAssignment = $aiRouter->snapshot('problem_testcase_generate');
$aiRouter->outputs['problem_testcase_generate'] = [
    'files' => [
        ['name' => '1.in', 'contents' => "1 2\n"],
        ['name' => '1.out', 'contents' => "3\n"],
    ],
    'rationale' => '최소 크기 정상 사례입니다.',
];
$testcaseGenerateResult = $testcaseGenerateHandler->handle(new JobWorkItem(
    problemAiClaim(
        'problem_testcase_generate',
        (string) $createdId->value(),
        $testcaseInputHash
    ),
    [
        'problem_id' => $createdId->value(),
        'problem_input_hash' => $testcaseInputHash,
        'input' => $testcaseInput,
        'assignment' => $testcaseAssignment->toArray(),
    ]
));
problemsSame(
    'succeeded',
    $testcaseGenerateResult->outcome(),
    '테스트케이스 AI Handler가 파일 초안만 생성해야 합니다.'
);
$generatedTestcases = $aiDrafts->testcases(
    (string) ($testcaseGenerateResult->safeResult()['draft_reference'] ?? ''),
    1,
    $createdId
);
problemsSame(
    ['1.in', '1.out'],
    array_column($generatedTestcases['files'] ?? [], 'name'),
    '테스트케이스 AI Handler가 검증된 입출력 쌍을 저장해야 합니다.'
);
problemsSame(
    4,
    count($aiRouter->requests),
    'AI 계약 위반 응답도 포함해 모든 생성은 공통 Router를 통해야 합니다.'
);

$codePolicy = (new ProblemCodePolicyService($gateway, $metadata))->forProblem($createdId);
problemsSame(['eval', 'exec'], $codePolicy->forbiddenFragments(), '금지 코드 목록을 구조화해야 합니다.');
$wordGateway = new MemoryProblemGateway();
$wordPolicyService = new ProblemCodePolicyService($wordGateway, $metadata);
foreach ([
    ['eval/exec', ['eval', 'exec']],
    [" / eval // exec /\r\n eval / ", ['eval', 'exec']],
    ["/ \n /", []],
    ['0/printf/std::', ['0', 'printf', 'std::']],
    ['eval/exec/\/', ['eval', 'exec', '/']],
    ['\/\/ / a\/b / \\\\ / \d+', ['//', 'a/b', '\\', '\d+']],
    ["한글/단어\u{2028}printf", ['한글', '단어', 'printf']],
] as [$rawWords, $expectedWords]) {
    $wordProblem = problemFromDraft(new ProblemId(9999), problemDraft(forbiddenCode: $rawWords));
    $wordGateway->items[9999] = $wordProblem;
    problemsSame($expectedWords, $wordProblem->forbiddenWords(), '금지어 구분자·이스케이프를 해석해야 합니다.');
    problemsSame(
        $expectedWords,
        $wordPolicyService->forProblem($wordProblem->id())->forbiddenFragments(),
        '문제 표시와 제출 정책은 같은 금지어 목록을 사용해야 합니다.'
    );
    problemsSame($rawWords, $wordProblem->forbiddenCode(), '편집용 금지어 원문은 보존해야 합니다.');
}
$wordGateway->items[9999] = problemFromDraft(new ProblemId(9999), problemDraft(forbiddenCode: 'eval/exec'));
$slashPolicy = $wordPolicyService->forProblem(new ProblemId(9999));
$wordGateway->items[9999] = problemFromDraft(new ProblemId(9999), problemDraft(forbiddenCode: "eval\nexec"));
problemsSame(
    $slashPolicy->policyVersion(),
    $wordPolicyService->forProblem(new ProblemId(9999))->policyVersion(),
    '같은 금지어 목록의 구분자 변경은 정책 의미를 바꾸지 않아야 합니다.'
);
problemsSame(
    CodeEntryMode::CodeCompletion,
    $codePolicy->entryMode(),
    '빈칸 완성 작성 방식을 제공해야 합니다.'
);

$editor->update(
    1,
    $createdId,
    new ProblemEditCommand(
        problemDraft('주제 없는 문제', languageMask: $judgeLanguages->disabledMaskForEnabled([6])),
        null,
        [],
        CodeEntryMode::CodeCompletion,
        '{"language_key":"python","blanks":[{"id":"answer","label":"정답 식","kind":"inline","max_bytes":100}]}',
        ''
    )
);
problemsSame(
    [],
    $links->keysFor($createdId),
    '해당하는 세부 주제가 없으면 문제를 빈 주제로 저장할 수 있어야 합니다.'
);
problemsSame(
    null,
    $metadata->find($createdId)?->difficulty(),
    '난이도 미분석과 주제 미지정 상태를 함께 저장할 수 있어야 합니다.'
);

$oldTitle = $gateway->find($createdId)?->title();
$eventsBeforeFailure = count($problemChanges->events);
$metadata->failSave = true;
$rollbackReported = false;
try {
    $editor->update(
        1,
        $createdId,
        new ProblemEditCommand(
            problemDraft('변경된 제목'),
            new DifficultyScore(4),
            ['simulation'],
            CodeEntryMode::FullCode,
            null,
            ''
        )
    );
} catch (DomainException) {
    $rollbackReported = true;
}
problemsAssert($rollbackReported, '플랫폼 저장 실패를 호출자에게 알려야 합니다.');
problemsSame(
    $oldTitle,
    $gateway->find($createdId)?->title(),
    '플랫폼 저장 실패 시 Judge 문제 원문을 복구해야 합니다.'
);
$metadata->failSave = false;

$metadata->failSave = true;
$gateway->failUpdateOnCall = $gateway->nextUpdateCall() + 1;
$consistencyFailure = null;
try {
    $editor->update(
        1,
        $createdId,
        new ProblemEditCommand(
            problemDraft('복구에도 실패하는 제목'),
            new DifficultyScore(4),
            ['simulation'],
            CodeEntryMode::FullCode,
            null,
            ''
        )
    );
} catch (ProblemWriteConsistencyException $error) {
    $consistencyFailure = $error;
}
problemsAssert(
    $consistencyFailure instanceof ProblemWriteConsistencyException,
    'Judge 원문 자동 복구 실패를 별도 일관성 예외로 알려야 합니다.'
);
problemsSame(
    $createdId->value(),
    $consistencyFailure?->problemId()->value(),
    '일관성 확인이 필요한 문제 번호를 보존해야 합니다.'
);
problemsAssert(
    $consistencyFailure?->recoveryError() instanceof Throwable,
    '자동 복구 실패 원인을 운영 로그에 전달할 수 있어야 합니다.'
);
$metadata->failSave = false;
$gateway->failUpdateOnCall = null;
problemsSame($eventsBeforeFailure, count($problemChanges->events), '저장 또는 복구에 실패한 수정은 완료 이벤트를 발행하지 않아야 합니다.');

$archiveManager = new MemoryProblemArchiveManager();
$archiveDependencyRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-archive-handler-' . bin2hex(random_bytes(6));
problemsAssert(
    mkdir($archiveDependencyRoot, 0750),
    'ZIP Handler 테스트 루트를 만들 수 있어야 합니다.'
);
$archiveFiles = new RootOnlyProblemFileManager(
    new ProblemDataWorkspace(new FixedProblemDataRoot($archiveDependencyRoot))
);
$archiveHandler = new ProblemTestcaseApplyJobHandler(
    $gateway,
    $archiveManager,
    $archiveFiles,
    $aiDrafts,
    $access
);
$archiveHash = str_repeat('1', 64);
$archiveResult = $archiveHandler->handle(new JobWorkItem(
    $numberClaim,
    [
        'operation' => 'archive_create',
        'problem_id' => $createdId->value(),
        'archive_name' => 'cases.zip',
        'file_hashes' => ['1.in' => $archiveHash],
    ]
));
problemsSame('succeeded', $archiveResult->outcome(), 'ZIP 생성 작업을 Handler에서 처리해야 합니다.');
problemsSame('create', $archiveManager->lastOperation, 'ZIP 생성 Adapter를 호출해야 합니다.');
problemsSame(['1.in' => $archiveHash], $archiveManager->lastHashes, 'ZIP 대상 해시를 그대로 고정해야 합니다.');
rmdir($archiveDependencyRoot);

$spjProblemId = new ProblemId(1001);
$gateway->items[$spjProblemId->value()] = problemFromDraft(
    $spjProblemId,
    problemDraft('특수 채점 문제', true)
);
$sandboxDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-spj-sandbox-test-' . bin2hex(random_bytes(6));
mkdir($sandboxDirectory, 0700);
$hostRunner = new CapturingBoundedProcessRunner();
$sandboxRunner = new BubblewrapBoundedProcessRunner(
    new MemorySpjSandboxProfiles(),
    $hostRunner
);
$sandboxRunner->run(
    [
        '/usr/bin/prlimit',
        '--',
        '/usr/bin/g++',
        'spj.cpp',
        '-o',
        'spj',
    ],
    $sandboxDirectory,
    20,
    262144
);
problemsAssert(
    in_array('--unshare-all', $hostRunner->command, true),
    'SPJ 컴파일은 모든 네임스페이스를 분리해야 합니다.'
);
problemsAssert(
    in_array('--cap-drop', $hostRunner->command, true)
        && in_array('ALL', $hostRunner->command, true),
    'SPJ 격리 프로세스의 capability를 제거해야 합니다.'
);
$buildBindIndex = array_search('/build', $hostRunner->command, true);
problemsAssert(
    is_int($buildBindIndex)
        && ($hostRunner->command[$buildBindIndex - 1] ?? null)
            === realpath($sandboxDirectory),
    'SPJ 임시 폴더만 /build 쓰기 경로로 연결해야 합니다.'
);
problemsAssert(
    ! in_array($pluginRoot, $hostRunner->command, true),
    'SPJ 격리 프로세스에 플러그인 루트를 노출하면 안 됩니다.'
);
rmdir($sandboxDirectory);

$assignments->items[$spjProblemId->value()] = new ProblemAssignment(
    $spjProblemId,
    1,
    []
);
$spjCompiler = new MemoryProblemSpjCompiler();
$spjHandler = new ProblemSpjCompileJobHandler(
    $gateway,
    $spjCompiler,
    $access
);
$spjSourceHash = str_repeat('2', 64);
$spjResult = $spjHandler->handle(new JobWorkItem(
    $numberClaim,
    [
        'problem_id' => $spjProblemId->value(),
        'source_name' => 'spj.cpp',
        'source_sha256' => $spjSourceHash,
        'binary_sha256' => null,
        'log_sha256' => null,
    ]
));
problemsSame('succeeded', $spjResult->outcome(), 'SPJ 컴파일 작업을 Handler에서 처리해야 합니다.');
problemsSame(
    [1001, 'spj.cpp', $spjSourceHash, null, null],
    $spjCompiler->arguments,
    'SPJ 소스와 충돌 해시를 Compiler에 전달해야 합니다.'
);

$testDataRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-problems-' . bin2hex(random_bytes(6));
problemsAssert(mkdir($testDataRoot, 0750), '테스트 데이터 임시 루트를 만들 수 있어야 합니다.');
$fileManager = new RootOnlyProblemFileManager(
    new ProblemDataWorkspace(new FixedProblemDataRoot($testDataRoot))
);
$fileProblemId = new ProblemId(2400);
$fileManager->write($fileProblemId, '1.in', "2 3\n", null);
$firstFiles = $fileManager->files($fileProblemId);
problemsSame(1, count($firstFiles), '새 테스트 데이터 파일을 생성해야 합니다.');
$firstHash = $firstFiles[0]->sha256();
$duplicateRejected = false;
try {
    $fileManager->write($fileProblemId, '1.in', "9 9\n", null);
} catch (DomainException) {
    $duplicateRejected = true;
}
problemsAssert($duplicateRejected, '같은 이름의 새 파일 덮어쓰기를 거부해야 합니다.');
$fileManager->write($fileProblemId, '1.in', "4 5\n", $firstHash);
problemsSame(
    "4 5\n",
    $fileManager->read($fileProblemId, '1.in'),
    '원본 해시가 일치하면 UTF-8 파일을 갱신해야 합니다.'
);
$staleWriteRejected = false;
try {
    $fileManager->write($fileProblemId, '1.in', "7 8\n", $firstHash);
} catch (DomainException) {
    $staleWriteRejected = true;
}
problemsAssert($staleWriteRejected, '오래된 원본 해시로 파일을 덮어쓸 수 없어야 합니다.');
$fileManager->write($fileProblemId, '1.out', "9\n", null);
$inspection = ProblemTestDataInspection::inspect($fileManager->files($fileProblemId));
problemsAssert($inspection->isValid(), '입력과 출력 파일 쌍을 정상으로 판정해야 합니다.');
problemsSame(1, $inspection->pairCount(), '입출력 쌍 수가 올바르지 않습니다.');
$gateway->items[$fileProblemId->value()] = problemFromDraft(
    $fileProblemId,
    problemDraft('AI 테스트데이터 적용 문제')
);
$assignments->items[$fileProblemId->value()] = new ProblemAssignment(
    $fileProblemId,
    1,
    []
);
$testcaseSnapshotHash = $aiInputs->hash($aiInputs->problem(
    $gateway->find($fileProblemId),
    null
));
$testcaseDraftReference = $formDrafts->store(
    'problem_testcase',
    1,
    'problem',
    (string) $fileProblemId->value(),
    [
        'files' => [
            ['name' => '2.in', 'contents' => "10 20\n"],
            ['name' => '2.out', 'contents' => "30\n"],
        ],
        'rationale' => '기본 덧셈 사례를 추가합니다.',
        'problem_snapshot_hash' => $testcaseSnapshotHash,
    ]
);
$testcaseApplyHandler = new ProblemTestcaseApplyJobHandler(
    $gateway,
    $archiveManager,
    $fileManager,
    $aiDrafts,
    $access
);
$testcaseApplyResult = $testcaseApplyHandler->handle(new JobWorkItem(
    $numberClaim,
    [
        'operation' => 'ai_draft_apply',
        'problem_id' => $fileProblemId->value(),
        'draft_reference' => $testcaseDraftReference->value(),
        'selected_files' => ['2.in', '2.out'],
        'expected_hashes' => ['2.in' => null, '2.out' => null],
    ]
));
problemsSame(
    'succeeded',
    $testcaseApplyResult->outcome(),
    '검토한 AI 테스트케이스 초안을 Worker에서 적용해야 합니다.'
);
problemsSame(
    "10 20\n",
    $fileManager->read($fileProblemId, '2.in'),
    'AI 입력 파일 내용을 공백과 줄바꿈 그대로 적용해야 합니다.'
);
problemsAssert(
    $formDrafts->find($testcaseDraftReference, 1) === null,
    '성공적으로 적용한 AI 테스트케이스 초안은 폐기해야 합니다.'
);
$output = array_values(array_filter(
    $fileManager->files($fileProblemId),
    static fn ($file): bool => $file->name() === '1.out'
))[0];
$fileManager->rename(
    $fileProblemId,
    '1.out',
    '1.ans',
    $output->sha256()
);
problemsSame(
    ['1.ans', '1.in', '2.in', '2.out'],
    array_map(
        static fn ($file): string => $file->name(),
        $fileManager->files($fileProblemId)
    ),
    '파일 이름을 바꾸고 자연 정렬해야 합니다.'
);
$deleteMap = [];
foreach ($fileManager->files($fileProblemId) as $file) {
    $deleteMap[$file->name()] = $file->sha256();
}
$fileManager->deleteMany($fileProblemId, $deleteMap);
problemsSame([], $fileManager->files($fileProblemId), '선택한 테스트 파일을 함께 삭제해야 합니다.');

$xmlCodec = new ProblemXmlCodec();
$xmlDocument = $xmlCodec->decode(
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<CSLfps version="1.2"><item><problem_id>2500</problem_id>'
    . '<title>XML 합계</title><time_limit unit="s">2</time_limit>'
    . '<description>A와 B를 더합니다.</description><input>A B</input>'
    . '<output>A+B</output><sample_input>1 2</sample_input>'
    . '<sample_output>3</sample_output><defunct>N</defunct>'
    . '<oj_metadata difficulty="4" code_entry_mode="full_code">'
    . '<topic key="simulation"/></oj_metadata>'
    . '<test_input name="1">1 2&#10;</test_input>'
    . '<test_output name="1">3&#10;</test_output></item></CSLfps>'
);
problemsSame(1, count($xmlDocument->items()), '유효한 CSLfps 문제를 분석해야 합니다.');
problemsSame(
    128,
    $xmlDocument->items()[0]->draft()->memoryLimitMegabytes(),
    '메모리 제한이 없으면 128MB를 사용해야 합니다.'
);
problemsSame(
    4,
    $xmlDocument->items()[0]->difficulty()?->value(),
    'XML 난이도는 1부터 5 사이 값으로 분석해야 합니다.'
);
$unsafeXmlRejected = false;
try {
    $xmlCodec->decode(
        '<?xml version="1.0"?><!DOCTYPE item [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        . '<CSLfps><item><title>&xxe;</title></item></CSLfps>'
    );
} catch (DomainException) {
    $unsafeXmlRejected = true;
}
problemsAssert($unsafeXmlRejected, 'DOCTYPE과 외부 엔터티 XML을 거부해야 합니다.');
foreach (['UTF-16LE', 'UTF-16BE'] as $xmlEncoding) {
    $dtdXml = '<?xml version="1.0" encoding="' . $xmlEncoding . '"?>'
        . '<!DOCTYPE item [<!ENTITY title "synthetic-entity">]>'
        . '<item><title>&title;</title></item>';
    $encodedDtdXml = '';
    foreach (str_split($dtdXml) as $character) {
        $encodedDtdXml .= $xmlEncoding === 'UTF-16LE' ? $character . "\0" : "\0" . $character;
    }
    $encodedDtdRejected = false;
    try { $xmlCodec->decode($encodedDtdXml); }
    catch (DomainException) { $encodedDtdRejected = true; }
    problemsAssert($encodedDtdRejected, 'BOM 없는 다른 인코딩으로 UTF-8·DOCTYPE 검사를 우회할 수 없어야 합니다: ' . $xmlEncoding);
}
$utf7EntityBody = '<!DOCTYPE item [<!ENTITY title "synthetic-entity">]><item><title>&title;</title></item>';
$utf7ShiftBytes = '';
foreach (str_split($utf7EntityBody) as $character) { $utf7ShiftBytes .= "\0" . $character; }
$utf7EntityXml = '<?xml version="1.0" encoding="UTF-7"?>+'
    . rtrim(base64_encode($utf7ShiftBytes), '=') . '-';
$utf7EntityRejected = false;
try { $xmlCodec->decode($utf7EntityXml); }
catch (DomainException) { $utf7EntityRejected = true; }
problemsAssert($utf7EntityRejected, 'NUL 없는 UTF-7 shift 인코딩으로 DOCTYPE과 내부 엔터티를 숨겨도 거부해야 합니다.');
foreach (['<?xml version="1.0" encoding="UTF-8"?>', "<?xml version='1.0' encoding = 'utf-8'?>", ''] as $utf8Declaration) {
    problemsSame(1, count($xmlCodec->decode($utf8Declaration . '<item><title>UTF-8 합성 문제</title></item>')->items()), '정상 UTF-8 선언과 선언 없는 UTF-8 문제는 계속 허용해야 합니다.');
}

$xmlMaterializer = new ProblemXmlMaterializer(
    $gateway,
    $metadata,
    $links,
    $topics,
    $fileManager,
    $completion
);
$xmlPreviews = new ProblemXmlPreviewService(
    $gateway,
    $topics,
    $numberChangeLock,
    $access,
    $xmlCodec,
    $xmlMaterializer,
    $formDrafts
);
$xmlWriter = new ProblemXmlWriter(
    $gateway,
    $metadata,
    $links,
    $assignments,
    new DirectProblemTransaction(),
    $fileManager,
    $xmlMaterializer
);
$xmlImports = new ProblemXmlImportService(
    $gateway,
    $numberChangeLock,
    $xmlMaterializer,
    $xmlPreviews,
    $xmlWriter
);
$xmlExports = new ProblemXmlExportService(
    $access,
    $numberChangeLock,
    $xmlMaterializer,
    $xmlCodec
);
$newXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<CSLfps version="1.2"><item><problem_id>2500</problem_id>'
    . '<title>XML 합계</title><time_limit unit="s">2</time_limit>'
    . '<memory_limit unit="mb">256</memory_limit>'
    . '<description>A와 B를 더합니다.</description><input>A B</input>'
    . '<output>A+B</output><sample_input>1 2</sample_input>'
    . '<sample_output>3</sample_output><defunct>N</defunct>'
    . '<oj_metadata difficulty="4" code_entry_mode="full_code">'
    . '<topic key="simulation"/></oj_metadata>'
    . '<test_input name="1">1 2&#10;</test_input>'
    . '<test_output name="1">3&#10;</test_output></item></CSLfps>';
$newPreview = $xmlPreviews->createPreview(
    1,
    $newXml,
    'simulation',
    false,
    true,
    true
);
problemsSame(1, $newPreview->selectableCount(), '새 XML 문제를 적용 가능으로 표시해야 합니다.');
$newResult = $xmlImports->apply(1, $newPreview->reference(), [0]);
problemsSame(
    1,
    $newResult->createdCount(),
    '선택한 XML 문제를 새로 만들어야 합니다: '
        . ($newResult->rows()[0]->message() ?? '')
);
problemsSame(
    ProblemXmlApplyRow::CREATED,
    $newResult->rows()[0]->status(),
    '새 문제 적용 결과를 생성 완료로 기록해야 합니다.'
);
problemsSame(
    ProblemVisibility::Published,
    $gateway->find(new ProblemId(2500))?->visibility(),
    '플랫폼 정보와 테스트 파일 저장 뒤 새 문제를 공개해야 합니다.'
);
problemsSame(
    4,
    $metadata->find(new ProblemId(2500))?->difficulty()?->value(),
    'XML 메타데이터 난이도를 저장해야 합니다.'
);
problemsSame(
    ['simulation'],
    $links->keysFor(new ProblemId(2500)),
    'XML의 세부 주제를 연결해야 합니다.'
);
problemsSame(
    "1 2\n",
    $fileManager->read(new ProblemId(2500), '1.in'),
    'XML 테스트 입력의 줄바꿈과 공백을 보존해야 합니다.'
);
problemsAssert(
    $assignments->find(new ProblemId(2500))->isOwner(1),
    'XML을 가져온 관리자를 새 문제 소유자로 지정해야 합니다.'
);

$topiclessXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<CSLfps version="1.2"><item><problem_id>2501</problem_id>'
    . '<title>주제 없는 XML 문제</title><time_limit unit="s">1</time_limit>'
    . '<memory_limit unit="mb">128</memory_limit>'
    . '<description>입력값을 그대로 출력합니다.</description>'
    . '<input>정수 하나</input><output>입력한 정수</output>'
    . '<sample_input>1</sample_input><sample_output>1</sample_output>'
    . '<defunct>N</defunct></item></CSLfps>';
$topiclessPreview = $xmlPreviews->createPreview(
    1,
    $topiclessXml,
    '',
    false,
    true,
    false
);
problemsSame(
    1,
    $topiclessPreview->selectableCount(),
    '대체 주제를 선택하지 않은 XML 문제도 적용 가능해야 합니다.'
);
$topiclessResult = $xmlImports->apply(
    1,
    $topiclessPreview->reference(),
    [0]
);
problemsSame(1, $topiclessResult->createdCount(), '주제 없는 XML 문제를 생성해야 합니다.');
problemsSame(
    [],
    $links->keysFor(new ProblemId(2501)),
    'XML 가져오기도 정확한 주제가 없으면 관계 행을 만들지 않아야 합니다.'
);

$exportedXml = $xmlExports->export(1, '2500', true, true);
$exportedDocument = $xmlCodec->decode($exportedXml);
problemsSame(
    'XML 합계',
    $exportedDocument->items()[0]->draft()->title(),
    '내보낸 XML을 다시 분석해 문제 제목을 복원해야 합니다.'
);
problemsSame(
    "3\n",
    $exportedDocument->items()[0]->testFiles()['1.out'] ?? null,
    '내보낸 XML에 테스트 출력을 포함해야 합니다.'
);
problemsSame(
    [2500, 2501, 2505],
    array_map(
        static fn (ProblemId $id): int => $id->value(),
        $xmlExports->parseSelection('2500-2501, 2505')
    ),
    '문제 번호 범위와 불연속 번호를 함께 해석해야 합니다.'
);

$existingXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<CSLfps><item><problem_id>2400</problem_id>'
    . '<title>XML 수정 제목</title><time_limit>1</time_limit>'
    . '<memory_limit>128</memory_limit><defunct>Y</defunct>'
    . '<test_input name="1">10 20&#10;</test_input>'
    . '<test_output name="1">30&#10;</test_output></item></CSLfps>';
$stalePreview = $xmlPreviews->createPreview(
    1,
    $existingXml,
    'simulation',
    true,
    false,
    true
);
$gateway->update($fileProblemId, problemDraft('미리보기 뒤 변경된 제목'));
$staleResult = $xmlImports->apply(1, $stalePreview->reference(), [0]);
problemsSame(1, $staleResult->failedCount(), '미리보기 뒤 변경된 문제를 덮어쓰지 않아야 합니다.');
problemsSame(
    '미리보기 뒤 변경된 제목',
    $gateway->find($fileProblemId)?->title(),
    '상태 충돌이 난 문제의 현재 원문을 보존해야 합니다.'
);

$gateway->update($fileProblemId, problemDraft('복구할 원래 제목'));
$fileManager->write($fileProblemId, '1.in', "4 5\n", null);
$fileManager->write($fileProblemId, '1.out', "9\n", null);
$recoveryPreview = $xmlPreviews->createPreview(
    1,
    $existingXml,
    'simulation',
    true,
    false,
    true
);
$metadata->failSave = true;
$recoveryResult = $xmlImports->apply(1, $recoveryPreview->reference(), [0]);
$metadata->failSave = false;
problemsSame(1, $recoveryResult->failedCount(), '플랫폼 저장 실패를 항목 실패로 기록해야 합니다.');
problemsSame(
    '복구할 원래 제목',
    $gateway->find($fileProblemId)?->title(),
    '플랫폼 저장 실패 시 Judge 문제 원문을 복구해야 합니다.'
);
problemsSame(
    "4 5\n",
    $fileManager->read($fileProblemId, '1.in'),
    '플랫폼 저장 실패 시 기존 테스트 입력을 복구해야 합니다.'
);

$bulkApplyProblemId = $editor->create(1, new ProblemEditCommand(
    problemDraft('일괄 승인 대상 문제'),
    null,
    [],
    CodeEntryMode::FullCode,
    null,
    ''
));
$bulkApplySnapshotHash = $aiInputs->hash($aiInputs->problem(
    $gateway->find($bulkApplyProblemId),
    $metadata->find($bulkApplyProblemId)
));
$bulkApplyDraftReference = $formDrafts->store(
    'problem_metadata',
    1,
    'problem',
    (string) $bulkApplyProblemId->value(),
    [
        'difficulty_score' => 2,
        'topic_keys' => ['simulation'],
        'confidence' => 0.7,
        'rationale' => '일괄 승인 테스트용 초안입니다.',
        'problem_snapshot_hash' => $bulkApplySnapshotHash,
        'dictionary_version' => $topics->version(),
    ]
);
$bulkApplyErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
};
$bulkApplyService = new ProblemMetadataBulkApplyService(
    $aiDrafts,
    $gateway,
    $metadata,
    $editor,
    $access,
    $bulkApplyErrors
);
$bulkApplyResult = $bulkApplyService->applyMany(1, [
    [
        'problem_id' => $bulkApplyProblemId->value(),
        'reference' => $bulkApplyDraftReference->value(),
    ],
    [
        'problem_id' => 999999,
        'reference' => 'draft:v1:00000000-0000-4000-8000-000000000000',
    ],
]);
problemsSame(
    [$bulkApplyProblemId->value()],
    $bulkApplyResult['applied'],
    '유효한 AI 메타데이터 초안은 일괄 승인으로 적용해야 합니다.'
);
problemsAssert(
    isset($bulkApplyResult['failed'][999999]),
    '존재하지 않는 문제 대상은 실패 목록에 남아야 합니다.'
);
problemsSame(
    2,
    $metadata->find($bulkApplyProblemId)?->difficulty()?->value(),
    '일괄 승인한 난이도를 문제 메타데이터에 반영해야 합니다.'
);
problemsSame(
    ['simulation'],
    $metadata->find($bulkApplyProblemId)?->topicKeys(),
    '일괄 승인한 주제를 문제 메타데이터에 반영해야 합니다.'
);
problemsSame(
    'ai_reviewed',
    $metadata->find($bulkApplyProblemId)?->generationMethod(),
    '일괄 승인은 생성 방식을 ai_reviewed로 기록해야 합니다.'
);
problemsAssert(
    $formDrafts->find($bulkApplyDraftReference, 1) === null,
    '일괄 승인 후 적용한 AI 초안은 폐기해야 합니다.'
);
$reappliedResult = $bulkApplyService->applyMany(1, [
    [
        'problem_id' => $bulkApplyProblemId->value(),
        'reference' => $bulkApplyDraftReference->value(),
    ],
]);
problemsSame(
    [],
    $reappliedResult['applied'],
    '이미 폐기한 AI 초안은 다시 적용할 수 없어야 합니다.'
);

// ---- 이미지 채점 (Matplotlib/turtle) ----
$visualTemplates = new \CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\PluginVisualGradingTemplates(
    $pluginRoot . '/resources/features'
);
$visualBody = $requestBody;
$visualBody['code_entry_mode'] = 'full_code';
$visualBody['enabled_language_ids'] = ['6'];
$visualBody['visual_grading_mode'] = 'turtle';
$visualCommand = (new ProblemRequestFactory($judgeLanguages))->editCommand(
    new HttpRequest('POST', '/oj-action', [], $visualBody)
);
problemsSame(
    \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::Turtle,
    $visualCommand->visualGradingMode(),
    '이미지 채점 방식을 요청에서 읽어야 합니다.'
);
problemsAssert($visualCommand->problem()->specialJudge(), '이미지 채점은 특수 채점기를 강제로 켜야 합니다.');
problemsSame(
    \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::None,
    (new ProblemRequestFactory($judgeLanguages))->editCommand(
        new HttpRequest('POST', '/oj-action', [], $requestBody)
    )->visualGradingMode(),
    '선택하지 않으면 이미지 채점을 사용하지 않아야 합니다.'
);
foreach ([
    ['visual_grading_mode' => 'bogus'],
    ['code_entry_mode' => 'code_completion', 'code_completion_schema' => '{}'],
] as $invalidVisualChange) {
    $rejectedVisual = false;
    try {
        (new ProblemRequestFactory($judgeLanguages))->editCommand(
            new HttpRequest('POST', '/oj-action', [], $invalidVisualChange + $visualBody)
        );
    } catch (DomainException) {
        $rejectedVisual = true;
    }
    problemsAssert($rejectedVisual, '잘못된 이미지 채점 설정은 거부해야 합니다.');
}
$visualTwoLanguages = $visualBody;
$visualTwoLanguages['enabled_language_ids'] = ['0', '6'];
$rejectedTwoLanguages = false;
try {
    $editor->create(1, (new ProblemRequestFactory($judgeLanguages))->editCommand(
        new HttpRequest('POST', '/oj-action', [], $visualTwoLanguages)
    ));
} catch (DomainException $error) {
    $rejectedTwoLanguages = str_contains($error->getMessage(), 'Python 3');
}
problemsAssert($rejectedTwoLanguages, '이미지 채점 문제는 Python 3 하나만 허용해야 합니다.');
$rejectedNoSpj = false;
try {
    $editor->create(1, new ProblemEditCommand(
        problemDraft('특수 채점 없음', false, '', $judgeLanguages->disabledMaskForEnabled([6])),
        null,
        [],
        CodeEntryMode::FullCode,
        null,
        '',
        'manual',
        'manual',
        \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::Matplotlib
    ));
} catch (DomainException $error) {
    $rejectedNoSpj = str_contains($error->getMessage(), '특수 채점기');
}
problemsAssert($rejectedNoSpj, '서비스도 특수 채점기 없는 이미지 채점 문제를 거부해야 합니다.');

$visualId = $editor->create(1, $visualCommand);
problemsSame(
    \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::Turtle,
    $metadata->find($visualId)?->visualGradingMode(),
    '이미지 채점 방식을 플랫폼 메타데이터로 저장해야 합니다.'
);
$visualPolicy = (new ProblemCodePolicyService($gateway, $metadata, $visualTemplates))->forProblem($visualId);
problemsAssert(
    str_contains($visualPolicy->prefixCode(), '_oj_stdout')
        && str_contains($visualPolicy->suffixCode(), '_oj_emit_turtle_png')
        && str_ends_with(trim(explode("\n", $visualPolicy->suffixCode())[0]), '#hidden'),
    '제출 정책은 비공개 표식이 붙은 이미지 출력 래퍼를 포함해야 합니다.'
);
$visualUnwrapped = false;
try {
    (new ProblemCodePolicyService($gateway, $metadata))->forProblem($visualId);
} catch (DomainException) {
    $visualUnwrapped = true;
}
problemsAssert($visualUnwrapped, '래퍼를 만들 수 없으면 이미지 채점 문제의 제출 정책을 만들면 안 됩니다.');
$plainPolicy = (new ProblemCodePolicyService($gateway, $metadata, $visualTemplates))->forProblem($createdId);
problemsAssert(
    ! str_contains($plainPolicy->prefixCode(), '_oj_stdout'),
    '이미지 채점이 아닌 문제에는 래퍼를 붙이면 안 됩니다.'
);

$visualJobs = new class () implements \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher {
    /** @var list<array{string, string}> */
    public array $queued = [];

    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult {
        $this->queued[] = [$jobType, $inputSummary['source_name'] ?? ''];

        return new \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult(77, true);
    }
};
$visualCompilerProfiles = new class () implements \CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider {
    public function forSource(string $sourceName): \CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompilerProfile
    {
        return new \CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompilerProfile(
            'cpp17',
            'C++17',
            '/usr/bin/prlimit',
            '/usr/bin/g++',
            ['-std=c++17']
        );
    }
};
$visualTestData = new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataService(
    $gateway,
    $fileManager,
    new FixedProblemDataRoot($testDataRoot),
    $access,
    $archiveManager,
    $numberChangeLock
);
$visualService = new \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService(
    $gateway,
    $metadata,
    $visualTestData,
    new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataJobService(
        $gateway,
        $fileManager,
        $archiveManager,
        $access,
        $aiDrafts,
        $visualJobs,
        $numberChangeLock,
        $visualCompilerProfiles,
        new MemorySpjSandboxProfiles()
    ),
    $visualTemplates,
    new \CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\FilesystemVisualCaptureStore(
        new FixedProblemDataRoot($testDataRoot)
    )
);
$captureStoreDirectory = $testDataRoot . DIRECTORY_SEPARATOR . '_capture';
$captureCandidatePath = $captureStoreDirectory . DIRECTORY_SEPARATOR . $visualId->value() . '.txt';
$installed = $visualService->installGrader(1, $visualId, false);
problemsAssert($installed->sourceWritten && $installed->compileJobId === 77, '이미지 채점기 소스를 저장하고 컴파일을 등록해야 합니다.');
problemsSame(
    $visualTemplates->spjSource(),
    $fileManager->read($visualId, 'spj.cc'),
    '저장한 spj.cc는 이미지 채점기 단일 소스와 같아야 합니다.'
);
problemsSame([['problem_spj_compile', 'spj.cc']], $visualJobs->queued, 'spj.cc 컴파일 작업을 등록해야 합니다.');
$reinstalled = $visualService->installGrader(1, $visualId, false);
problemsAssert(! $reinstalled->sourceWritten && $reinstalled->compileJobId === 77, '최신 소스는 다시 쓰지 않고 컴파일만 다시 등록해야 합니다.');
$spjHash = $fileManager->files($visualId)[0]->sha256();
foreach ($fileManager->files($visualId) as $visualFile) {
    if ($visualFile->name() === 'spj.cc') {
        $spjHash = $visualFile->sha256();
    }
}
$fileManager->write($visualId, 'spj.cc', "// custom\n", $spjHash);
$overwriteBlocked = false;
try {
    $visualService->installGrader(1, $visualId, false);
} catch (DomainException $error) {
    $overwriteBlocked = str_contains($error->getMessage(), '덮어');
}
problemsAssert($overwriteBlocked, '다른 내용의 spj.cc는 확인 없이 덮어쓰면 안 됩니다.');
problemsAssert($visualService->installGrader(1, $visualId, true)->sourceWritten, '확인하면 spj.cc를 최신 소스로 덮어써야 합니다.');
$fileManager->write($visualId, 'spj.cpp', "// other\n", null);
$otherSourceBlocked = false;
try {
    $visualService->installGrader(1, $visualId, true);
} catch (DomainException) {
    $otherSourceBlocked = true;
}
problemsAssert($otherSourceBlocked, 'spj.cpp가 있으면 이미지 채점기를 설치하면 안 됩니다.');
$spjOtherHash = '';
foreach ($fileManager->files($visualId) as $visualFile) {
    if ($visualFile->name() === 'spj.cpp') {
        $spjOtherHash = $visualFile->sha256();
    }
}
$fileManager->delete($visualId, 'spj.cpp', $spjOtherHash);

$xmlVisualItem = $xmlMaterializer->exportItem($visualId, false);
problemsSame(
    \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::Turtle,
    $xmlMaterializer->metadataFor($visualId, 1, $xmlVisualItem, '', true)->visualGradingMode(),
    'XML을 다시 가져와도 조건이 맞으면 이미지 채점 설정을 유지해야 합니다.'
);
$xmlNonPython = new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem(
    $visualId->value(),
    $visualId,
    problemDraft('언어 변경', true, '', 1),
    true,
    null,
    [],
    CodeEntryMode::FullCode,
    null,
    []
);
problemsSame(
    \CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode::None,
    $xmlMaterializer->metadataFor($visualId, 1, $xmlNonPython, '', true)->visualGradingMode(),
    '가져온 XML이 Python 3 단일 언어가 아니면 이미지 채점 설정을 해제해야 합니다.'
);

$visualPng = static function (int $width, int $height): string {
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data
        . pack('N', crc32($type . $data) & 0xFFFFFFFF);

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\0\0\0")
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xff", $width * 3), $height)))
        . $chunk('IEND', '');
};
$savedCase = $visualService->saveReferenceImage(1, $visualId, '01', '', $visualPng(2, 2), false);
problemsSame('01', $savedCase, '정답 이미지 테스트 이름을 반환해야 합니다.');
problemsSame("\n", $fileManager->read($visualId, '01.in'), '입력이 비어 있으면 줄바꿈만 있는 입력 파일을 만들어야 합니다.');
problemsAssert(
    str_starts_with($fileManager->read($visualId, '01.out'), "OJ_IMAGE_PNG_BASE64_V1\n"),
    '정답 이미지는 Base64 포장 텍스트 .out으로 저장해야 합니다.'
);
$previews = $visualService->referencePreviews(1, $visualId);
problemsSame(1, count($previews), '저장한 정답 이미지를 미리보기로 보여줘야 합니다.');
problemsAssert(
    $previews[0]['width'] === 2 && str_starts_with($previews[0]['source'], 'data:image/png;base64,'),
    '미리보기는 PNG 데이터 주소와 크기를 제공해야 합니다.'
);
$panelHandlerReflection = new ReflectionClass(
    \CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemTestDataPageHandler::class
);
$panelHandler = $panelHandlerReflection->newInstanceWithoutConstructor();
$panelHandlerReflection->getProperty('html')->setValue($panelHandler, new ProblemHtml(new MemoryTopics()));
$panelHandlerReflection->getProperty('actionUrls')->setValue($panelHandler, new ProblemsActionUrls());
$panelHandlerReflection->getProperty('security')->setValue($panelHandler, new ProblemsActionSecurity());
$panelHandlerReflection->getProperty('visualGrading')->setValue($panelHandler, $visualService);
$visualPanelHtml = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($visualPanelHtml, 'value="install_visual_grader"')
        && str_contains($visualPanelHtml, 'value="upload_reference_image"')
        && str_contains($visualPanelHtml, 'enctype="multipart/form-data"')
        && str_contains($visualPanelHtml, 'name="reference_image"')
        && str_contains($visualPanelHtml, 'data:image/png;base64,')
        && str_contains($visualPanelHtml, 'name="return_tab" value="visual"')
        && str_contains($visualPanelHtml, 'ojp-visual-steps')
        && str_contains($visualPanelHtml, '방법 A')
        && str_contains($visualPanelHtml, 'sudo mkdir')
        && str_contains($visualPanelHtml, 'turtle 그림 이미지 채점')
        && str_contains($visualPanelHtml, '_oj_emit_turtle_png'),
    '테스트데이터 화면의 이미지 채점 패널은 설치·업로드 폼과 정답 이미지 미리보기를 제공해야 합니다.'
);
$visualPanelOutput = getenv('OJ_VISUAL_PANEL_OUTPUT');
if (is_string($visualPanelOutput) && $visualPanelOutput !== '') {
    $visualService->saveReferenceImage(1, $visualId, '02', "7\n", $visualPng(160, 100), false);
    file_put_contents($visualPanelOutput, $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
        $panelHandler,
        $visualId,
        1,
        $fileManager->files($visualId)
    ));
}
$panelWithoutService = $panelHandlerReflection->newInstanceWithoutConstructor();
$panelHandlerReflection->getProperty('visualGrading')->setValue($panelWithoutService, null);
problemsSame(
    '',
    $panelHandlerReflection->getMethod('visualGradingPanel')->invoke($panelWithoutService, $visualId, 1, []),
    '이미지 채점 서비스가 없으면 패널을 보여주면 안 됩니다.'
);
$inspectedVisual = ProblemTestDataInspection::inspect($fileManager->files($visualId));
problemsAssert($inspectedVisual->missingInputs() === [] && $inspectedVisual->missingOutputs() === [], '정답 이미지는 입출력 쌍으로 인식되어야 합니다.');
foreach ([
    [static fn () => $visualService->saveReferenceImage(1, $visualId, '01', '', $visualPng(2, 2), false), '이미 있습니다'],
    [static fn () => $visualService->saveReferenceImage(1, $visualId, '../x', '', $visualPng(2, 2), true), '테스트 이름'],
    [static fn () => $visualService->saveReferenceImage(1, $visualId, '02', '', 'not a png at all, just text', true), 'PNG'],
    [static fn () => $visualService->saveReferenceImage(1, $visualId, '02', "\xff\xfe", $visualPng(2, 2), true), 'UTF-8'],
    [static fn () => $visualService->saveReferenceImage(1, $fileProblemId, '01', '', $visualPng(2, 2), true), '이미지 채점'],
] as [$visualAction, $visualNeedle]) {
    $visualRejected = false;
    try {
        $visualAction();
    } catch (DomainException $error) {
        $visualRejected = str_contains($error->getMessage(), $visualNeedle);
    }
    problemsAssert($visualRejected, '잘못된 정답 이미지 요청은 거부해야 합니다: ' . $visualNeedle);
}
problemsSame(
    "3 4\n",
    (function () use ($visualService, $visualId, $visualPng, $fileManager): string {
        $visualService->saveReferenceImage(1, $visualId, '01', '3 4', $visualPng(3, 3), true);

        return $fileManager->read($visualId, '01.in');
    })(),
    '같은 이름 덮어쓰기를 선택하면 입력과 정답 이미지를 함께 갱신해야 합니다.'
);
$captureHash = static function (string $name) use ($fileManager, $visualId): ?string {
    foreach ($fileManager->files($visualId) as $file) {
        if ($file->name() === $name) {
            return $file->sha256();
        }
    }

    return null;
};
$captureDirectory = $testDataRoot . DIRECTORY_SEPARATOR . $visualId->value();
$noFolderRejected = false;
try {
    $visualService->armCapture(1, $visualId);
} catch (DomainException $error) {
    $noFolderRejected = str_contains($error->getMessage(), '_capture');
}
problemsAssert($noFolderRejected, '캡처 폴더가 없으면 캡처를 시작하면 안 됩니다.');
problemsAssert(! $visualService->captureState(1, $visualId)->directoryReady, '캡처 폴더가 없으면 상태에 표시해야 합니다.');
mkdir($captureStoreDirectory);
$visualService->armCapture(1, $visualId);
$armedState = $visualService->captureState(1, $visualId);
problemsAssert(
    $armedState->armed && ! $armedState->expired && $armedState->candidate === null
        && $armedState->remainingSeconds > 590
        && $captureHash('capture-tmp.in') === null,
    '정답 캡처를 시작하면 대기 상태가 되고, 기존 테스트가 있으면 임시 테스트를 만들지 않아야 합니다.'
);
$staleTime = time() - 700;
touch($captureDirectory . DIRECTORY_SEPARATOR . 'capture-armed.txt', $staleTime);
$expiredState = $visualService->captureState(1, $visualId);
problemsAssert(
    ! $expiredState->armed && $expiredState->expired,
    '10분이 지난 캡처 표식은 만료로 표시해야 합니다.'
);
$visualService->armCapture(1, $visualId);
problemsAssert($visualService->captureState(1, $visualId)->armed, '다시 시작하면 새 대기 상태가 되어야 합니다.');
$armedPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($armedPanel, '캡처 대기 중')
        && str_contains($armedPanel, '/submit/' . $visualId->value())
        && str_contains($armedPanel, 'value="cancel_capture"')
        && ! str_contains($armedPanel, 'sudo mkdir'),
    '캡처 대기 중에는 제출 링크와 취소만 보여주고 폴더 준비 안내는 숨겨야 합니다.'
);
$verdictPath = $captureStoreDirectory . DIRECTORY_SEPARATOR . $visualId->value() . '.last.txt';
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=dimensions_mismatch expected=1200x800 actual=1005x701 total_ms=3.1\n");
$verdictPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($verdictPanel, '채점기 마지막 판정')
        && str_contains($verdictPanel, '정답 1200x800')
        && str_contains($verdictPanel, '제출 1005x701')
        && str_contains($verdictPanel, 'ojp-notice--warning'),
    '채점기가 남긴 크기 불일치 판정을 패널에 설명해 보여줘야 합니다.'
);
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=pixels_differ width=1200 height=800 unmatched_expected=42 unmatched_actual=40 color_tolerance=16\n");
$pixelPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($pixelPanel, '정답에만 있는 픽셀 42개') && str_contains($pixelPanel, '제출에만 있는 픽셀 40개'),
    '픽셀 불일치 판정은 개수를 보여줘야 합니다.'
);
$actualPath = $captureStoreDirectory . DIRECTORY_SEPARATOR . $visualId->value() . '.actual.png';
file_put_contents($actualPath, $visualPng(2, 2));
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=pixels_differ case=01 width=2 height=2 unmatched_expected=3 unmatched_actual=3 diff_bbox=0,1,1,1 max_delta=99 color_tolerance=16
");
$comparePanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($comparePanel, 'ojp-diff-overlay')
        && str_contains($comparePanel, '마지막 제출 2×2')
        && str_contains($comparePanel, '차이 범위(x,y 시작~끝) 0,1,1,1')
        && str_contains($comparePanel, '최대 색 차이 99'),
    '정답과 마지막 제출 이미지를 겹쳐 보여주고 차이 범위와 최대 색 차이를 설명해야 합니다.'
);
file_put_contents($actualPath, 'not a png');
$brokenActualPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    ! str_contains($brokenActualPanel, 'ojp-diff-overlay'),
    '올바른 PNG가 아닌 보관 이미지는 비교 화면에 쓰면 안 됩니다.'
);
unlink($actualPath);

problemsSame(64, \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService::suggestedTolerance(49), '최대 색 차이 49는 8 단위 여유를 두어 64를 제안해야 합니다.');
problemsSame(32, \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService::suggestedTolerance(17), '최대 색 차이 17은 32를 제안해야 합니다.');
problemsSame(96, \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService::suggestedTolerance(88), '상한 안에서는 96까지 제안할 수 있습니다.');
problemsSame(null, \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService::suggestedTolerance(89), '상한을 넘는 차이는 제안하지 않아야 합니다.');
problemsSame(null, \CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService::suggestedTolerance(0), '차이가 없으면 제안하지 않아야 합니다.');
$toleranceState = $visualService->toleranceState(1, $visualId);
problemsAssert(
    $toleranceState['value'] === 16 && ! $toleranceState['custom'] && $toleranceState['problem'] === null,
    '설정 파일이 없으면 기본 엄격 값 16을 보여줘야 합니다.'
);
$visualService->saveTolerance(1, $visualId, 64);
problemsSame("color_tolerance=64\n", $fileManager->read($visualId, 'image-spj-settings.txt'), '색 허용치는 채점기가 읽는 설정 파일로 저장해야 합니다.');
$toleranceState = $visualService->toleranceState(1, $visualId);
problemsAssert($toleranceState['value'] === 64 && $toleranceState['custom'], '저장한 색 허용치를 다시 읽어야 합니다.');
$visualService->saveTolerance(1, $visualId, 40);
problemsSame("color_tolerance=40\n", $fileManager->read($visualId, 'image-spj-settings.txt'), '이미 있는 설정 파일을 갱신해야 합니다.');
if (DIRECTORY_SEPARATOR === '/') {
    problemsSame(
        0644,
        fileperms($testDataRoot . '/' . $visualId->value() . '/image-spj-settings.txt') & 0777,
        '채점기 설정 파일은 채점기 계정이 읽을 수 있게 저장해야 합니다.'
    );
}
foreach ([-1, 97, 1000] as $badTolerance) {
    $toleranceRejected = false;
    try {
        $visualService->saveTolerance(1, $visualId, $badTolerance);
    } catch (DomainException $error) {
        $toleranceRejected = str_contains($error->getMessage(), '96');
    }
    problemsAssert($toleranceRejected, '허용 범위를 벗어난 색 허용치는 거부해야 합니다: ' . $badTolerance);
}
$toleranceHash = $captureHash('image-spj-settings.txt');
$fileManager->write($visualId, 'image-spj-settings.txt', "color_tolerance=120\n", $toleranceHash);
$brokenTolerance = $visualService->toleranceState(1, $visualId);
problemsAssert(
    $brokenTolerance['problem'] !== null && $brokenTolerance['value'] === 16,
    '채점기가 거부할 설정 파일은 오류로 표시해야 합니다.'
);
$brokenPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($brokenPanel, '내용이 올바르지 않아') && str_contains($brokenPanel, '기본값(엄격)으로 되돌리기'),
    '손상된 설정 파일은 패널에서 안내하고 되돌리기를 제공해야 합니다.'
);
$visualService->saveTolerance(1, $visualId, 16);
problemsAssert($captureHash('image-spj-settings.txt') === null, '기본값으로 저장하면 설정 파일을 지워야 합니다.');
$defaultPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($defaultPanel, '비교 기준 (선택)')
        && str_contains($defaultPanel, '기본 · 엄격')
        && str_contains($defaultPanel, 'name="tolerance"')
        && ! str_contains($defaultPanel, '기본값(엄격)으로 되돌리기'),
    '비교 기준 단계는 기본 상태에서 입력 폼만 보여줘야 합니다.'
);
problemsAssert(
    ! str_contains($defaultPanel, '새 버전 있음') && ! str_contains($defaultPanel, 'value="1" checked'),
    '최신 채점기가 설치돼 있으면 업데이트 안내를 보여주면 안 됩니다.'
);
$currentSpjHash = $captureHash('spj.cc');
$fileManager->write($visualId, 'spj.cc', "// old grader\n", $currentSpjHash);
$outdatedPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($outdatedPanel, '새 버전 있음')
        && str_contains($outdatedPanel, 'name="replace_existing" value="1" checked'),
    '옛 채점기 소스가 있으면 새 버전 안내와 함께 덮어쓰기를 미리 체크해야 합니다.'
);
problemsAssert($visualService->installGrader(1, $visualId, true)->sourceWritten, '덮어쓰기로 최신 채점기 소스를 다시 설치해야 합니다.');
$visualService->saveTolerance(1, $visualId, 48);
$customPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($customPanel, '색 허용치 48') && str_contains($customPanel, '기본값(엄격)으로 되돌리기'),
    '설정한 색 허용치는 배지로 보여주고 되돌리기를 제공해야 합니다.'
);
$visualService->saveTolerance(1, $visualId, 16);
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=pixels_differ case=01 width=640 height=480 unmatched_expected=49 unmatched_actual=49 diff_bbox=352,175,441,220 max_delta=49 color_tolerance=16 spatial_radius=0 max_unmatched=0\n");
$suggestPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($suggestPanel, 'ojp-tolerance-suggestion')
        && str_contains($suggestPanel, '최대 색 차이는 49')
        && str_contains($suggestPanel, '<input type="hidden" name="tolerance" value="64">')
        && str_contains($suggestPanel, 'value="set_tolerance"'),
    '색 차이로 거절된 판정에는 허용치 64 제안 버튼을 보여줘야 합니다.'
);
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=pixels_differ case=01 width=640 height=480 unmatched_expected=49 unmatched_actual=49 diff_bbox=1,2,3,4 max_delta=49 color_tolerance=16 settings=unreadable\n");
$unreadablePanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($unreadablePanel, '읽지 못해 기본 엄격 기준') && str_contains($unreadablePanel, 'chmod 644'),
    '채점기가 설정 파일을 읽지 못했다면 패널이 권한 안내를 보여줘야 합니다.'
);
file_put_contents($verdictPath, time() . " OJ_IMAGE_SPJ/1 WA reason=pixels_differ case=01 width=2 height=2 unmatched_expected=9 unmatched_actual=9 diff_bbox=0,0,1,1 max_delta=200 color_tolerance=16\n");
$noSuggestPanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    ! str_contains($noSuggestPanel, 'ojp-tolerance-suggestion'),
    '허용 상한을 넘는 색 차이에는 제안하지 않아야 합니다.'
);
unlink($verdictPath);
file_put_contents($verdictPath, "garbage <script>alert(1)</script>\n");
$garbagePanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    ! str_contains($garbagePanel, '채점기 마지막 판정') && ! str_contains($garbagePanel, '<script>'),
    '형식이 맞지 않는 판정 기록은 무시해야 합니다.'
);
unlink($verdictPath);
$tabNavigation = $panelHandlerReflection->getMethod('tabNavigation')->invoke(
    $panelHandler,
    $visualId,
    ['files' => '파일 3', 'visual' => '이미지 채점', 'spj' => '특수 채점기'],
    'visual'
);
problemsAssert(
    str_contains($tabNavigation, 'class="ojp-tab is-active" href="/oj-console/problems/' . $visualId->value() . '/test-data?tab=visual" aria-current="page"')
        && str_contains($tabNavigation, '?tab=spj')
        && substr_count($tabNavigation, 'is-active') === 1,
    '테스트데이터 탭 메뉴는 현재 탭만 활성으로 표시해야 합니다.'
);
$fileManager->delete($visualId, 'capture-armed.txt', (string) $captureHash('capture-armed.txt'));
file_put_contents(
    $captureCandidatePath,
    "OJ_IMAGE_CAPTURE_V1 01\n"
        . \CodingPanda\OjPlatform\Modules\Problems\Domain\PngReferenceImage::fromBytes($visualPng(4, 3))->wrappedText()
);
$candidateState = $visualService->captureState(1, $visualId);
problemsAssert(
    ! $candidateState->armed
        && $candidateState->candidate !== null
        && $candidateState->candidate['case'] === '01'
        && $candidateState->candidate['width'] === 4
        && $candidateState->candidate['height'] === 3
        && str_starts_with($candidateState->candidate['source'], 'data:image/png;base64,'),
    'SPJ가 남긴 캡처 후보를 해석해 크기와 미리보기를 보여줘야 합니다.'
);
$capturePanel = $panelHandlerReflection->getMethod('visualGradingPanel')->invoke(
    $panelHandler,
    $visualId,
    1,
    $fileManager->files($visualId)
);
problemsAssert(
    str_contains($capturePanel, 'value="apply_capture"')
        && str_contains($capturePanel, 'value="cancel_capture"')
        && str_contains($capturePanel, '정답 이미지로 적용'),
    '캡처 후보가 있으면 패널에 적용·취소 폼이 보여야 합니다.'
);
$captureRejected = false;
try {
    $visualService->applyCapture(1, $visualId, '01', '', false);
} catch (DomainException $error) {
    $captureRejected = str_contains($error->getMessage(), '이미 있습니다');
}
problemsAssert($captureRejected, '이미 있는 이름에는 확인 없이 캡처 결과를 덮어쓰면 안 됩니다.');
problemsSame('01', $visualService->applyCapture(1, $visualId, '', "5\n", true), '이름을 비우면 캡처한 테스트 이름으로 저장해야 합니다.');
problemsSame("5\n", $fileManager->read($visualId, '01.in'), '캡처 적용 시 입력을 함께 저장해야 합니다.');
problemsAssert(
    ! is_file($captureCandidatePath) && $captureHash('capture-armed.txt') === null,
    '적용한 뒤에는 캡처 후보와 표식을 지워야 합니다.'
);
file_put_contents($captureCandidatePath, "OJ_IMAGE_CAPTURE_V1 01\nnot base64 at all\n");
$brokenState = $visualService->captureState(1, $visualId);
problemsAssert(
    $brokenState->candidate === null && $brokenState->candidateProblem !== null,
    '손상된 캡처 후보는 오류 문구로만 보여주고 적용하지 않아야 합니다.'
);
$visualService->cancelCapture(1, $visualId);
problemsAssert(! is_file($captureCandidatePath), '취소하면 캡처 후보를 지워야 합니다.');
foreach (['01.in', '01.out', '02.in', '02.out'] as $dropName) {
    $dropHash = $captureHash($dropName);
    if ($dropHash !== null) {
        $fileManager->delete($visualId, $dropName, $dropHash);
    }
}
file_put_contents($captureCandidatePath, "stale\n");
$visualService->armCapture(1, $visualId);
problemsAssert(! is_file($captureCandidatePath), '캡처를 다시 시작하면 이전 후보를 지워야 합니다.');
problemsAssert(
    $captureHash('capture-tmp.in') !== null
        && $captureHash('capture-tmp.out') !== null
        && $captureHash('capture-armed.txt') !== null,
    '테스트가 하나도 없으면 임시 입출력 쌍을 만들어 SPJ가 호출되도록 해야 합니다.'
);
$visualService->cancelCapture(1, $visualId);
problemsAssert(
    $captureHash('capture-tmp.in') === null
        && $captureHash('capture-tmp.out') === null
        && $captureHash('capture-armed.txt') === null,
    '취소하면 임시 입출력 쌍과 표식을 모두 정리해야 합니다.'
);
$reservedRejected = false;
try {
    $visualService->saveReferenceImage(1, $visualId, 'capture-tmp', '', $visualPng(2, 2), true);
} catch (DomainException $error) {
    $reservedRejected = str_contains($error->getMessage(), '임시 이름');
}
problemsAssert($reservedRejected, '캡처용 임시 이름은 일반 정답 이름으로 쓸 수 없어야 합니다.');
if (is_dir($captureStoreDirectory)) {
    rmdir($captureStoreDirectory);
}
$visualCleanup = [];
foreach ($fileManager->files($visualId) as $visualFile) {
    $visualCleanup[$visualFile->name()] = $visualFile->sha256();
}
$fileManager->deleteMany($visualId, $visualCleanup);
$visualDirectory = $testDataRoot . DIRECTORY_SEPARATOR . $visualId->value();
$visualLock = $testDataRoot . DIRECTORY_SEPARATOR . '.oj-platform-problem-' . $visualId->value() . '.lock';
if (is_file($visualLock)) {
    unlink($visualLock);
}
if (is_dir($visualDirectory)) {
    rmdir($visualDirectory);
}


$imageGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$imageJpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$imageWebp = base64_decode('UklGRhoAAABXRUJQVlA4TA4AAAAvAAAAAAfQ//73v/+BiOh/AAA=');
foreach ([
    ['png', $visualPng(3, 2), 3, 2],
    ['jpg', $imageJpeg, 1, 1],
    ['gif', $imageGif, 1, 1],
    ['webp', $imageWebp, 1, 1],
] as [$expectedExtension, $imageBytes, $expectedWidth, $expectedHeight]) {
    $accepted = \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage::fromBytes($imageBytes);
    problemsAssert(
        $accepted->extension() === $expectedExtension
            && $accepted->width() === $expectedWidth
            && $accepted->height() === $expectedHeight
            && $accepted->bytes() === $imageBytes,
        '문제 본문 이미지는 내용으로 형식과 크기를 판별해야 합니다: ' . $expectedExtension
    );
}
$oversizedPng = $visualPng(2, 2);
$hugeHeader = substr($oversizedPng, 0, 16) . pack('NN', 7000, 7000) . substr($oversizedPng, 24);
foreach ([
    ['', '비어'],
    ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'PNG, JPEG'],
    ['<html><body>x</body></html>', 'PNG, JPEG'],
    ["GIF89a" . str_repeat("\0", 20), '읽을 수 없습니다'],
    [substr($visualPng(2, 2), 0, 20), '읽을 수 없습니다'],
    [$visualPng(2, 2) . str_repeat('x', \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage::MAX_BYTES), '5MB'],
    [$hugeHeader, '6000'],
] as [$badBytes, $needle]) {
    $imageRejected = false;
    try {
        \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage::fromBytes($badBytes);
    } catch (DomainException $error) {
        $imageRejected = str_contains($error->getMessage(), $needle);
    }
    problemsAssert($imageRejected, '허용하지 않는 문제 본문 이미지는 거부해야 합니다: ' . $needle);
}

$imageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-problem-images-' . bin2hex(random_bytes(4));
mkdir($imageRoot);
$wpImageStore = new \CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemImageStore(
    static fn (): array => ['basedir' => $imageRoot, 'baseurl' => 'https://example.test/wp-content/uploads/']
);
$storedUrl = $wpImageStore->store(\CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage::fromBytes($visualPng(2, 2)));
$secondUrl = $wpImageStore->store(\CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage::fromBytes($visualPng(2, 2)));
problemsAssert(
    preg_match('#^https://example\.test/wp-content/uploads/oj-platform/problem-images/\d{4}/\d{2}/[0-9a-f]{24}\.png$#', $storedUrl) === 1
        && $storedUrl !== $secondUrl,
    '문제 본문 이미지는 업로드 폴더 아래 무작위 이름의 공개 주소로 저장해야 합니다.'
);
$storedPath = $imageRoot . substr($storedUrl, strlen('https://example.test/wp-content/uploads'));
problemsAssert(
    is_file($storedPath) && file_get_contents($storedPath) === $visualPng(2, 2),
    '저장한 이미지 파일은 올린 내용과 같아야 합니다.'
);
$leftovers = glob(dirname($storedPath) . '/.*.tmp') ?: [];
problemsSame([], $leftovers, '이미지 저장 뒤 임시 파일이 남으면 안 됩니다.');
$imageProblemId = new ProblemId(4242);
$assignments->items[4242] = new ProblemAssignment(
    $imageProblemId,
    10,
    [11 => CollaboratorRole::Editor, 12 => CollaboratorRole::TestcaseManager]
);
$imageService = new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemImageService($access, $wpImageStore);
$uploaded = $imageService->upload(1, null, $visualPng(2, 2));
problemsAssert(
    str_starts_with($uploaded['url'], 'https://example.test/') && $uploaded['width'] === 2 && $uploaded['height'] === 2,
    '문제 생성 권한이 있으면 새 문제 편집 중에도 이미지를 올릴 수 있어야 합니다.'
);
problemsAssert(
    str_starts_with($imageService->upload(10, $imageProblemId, $visualPng(2, 2))['url'], 'https://'),
    '문제 소유자는 자기 문제에 이미지를 올릴 수 있어야 합니다.'
);
problemsAssert(
    str_starts_with($imageService->upload(11, $imageProblemId, $visualPng(2, 2))['url'], 'https://'),
    '공동 편집자는 이미지를 올릴 수 있어야 합니다.'
);
foreach ([[21, null], [12, $imageProblemId], [21, $imageProblemId]] as [$deniedUser, $deniedProblem]) {
    $imageDenied = false;
    try {
        $imageService->upload($deniedUser, $deniedProblem, $visualPng(2, 2));
    } catch (DomainException $error) {
        $imageDenied = str_contains($error->getMessage(), '권한');
    }
    problemsAssert($imageDenied, '편집 권한이 없는 사용자는 이미지를 올릴 수 없어야 합니다: ' . $deniedUser);
}
$cleanupImages = static function (string $directory) use (&$cleanupImages): void {
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? $cleanupImages($path) : unlink($path);
    }
    rmdir($directory);
};
$cleanupImages($imageRoot);

$plainEditor = (new \CodingPanda\OjPlatform\Ui\Editor\RichTextEditor())->render('hint', '힌트', '', 6);
problemsAssert(
    ! str_contains($plainEditor, 'data-ojp-rich-text-upload-fields'),
    '이미지 업로드를 설정하지 않은 편집기에는 이미지 도구가 없어야 합니다.'
);
$uploadEditor = (new \CodingPanda\OjPlatform\Ui\Editor\RichTextEditor())->render(
    'hint',
    '힌트',
    '',
    6,
    false,
    true,
    new \CodingPanda\OjPlatform\Ui\Editor\RichTextImageUpload('/oj-ajax', '<input type="hidden" name="action" value="oj_problem_upload_image">')
);
problemsAssert(
    str_contains($uploadEditor, '<template data-ojp-rich-text-upload-fields data-upload-url="/oj-ajax">')
        && str_contains($uploadEditor, 'value="oj_problem_upload_image"></template>')
        && str_contains($uploadEditor, 'data-editor-latex="true"'),
    '이미지 업로드를 설정한 편집기는 이미지 버튼과 업로드 필드를 제공해야 합니다.'
);
problemsSame(
    4,
    substr_count($problemEditHtml, 'data-ojp-rich-text-upload-fields'),
    '문제 설명·입력·출력·힌트 편집기에 모두 이미지 버튼이 있어야 합니다.'
);
problemsAssert(
    substr_count($problemEditHtml, '<template data-ojp-rich-text-upload-fields data-upload-url="/oj-ajax">') === 4
        && substr_count($problemEditHtml, 'value="oj_problem_upload_image"') === 4,
    '문제 편집기의 이미지 업로드 필드는 form과 함께 제출되지 않는 template 안에 있어야 합니다.'
);

foreach ([2400, 2500, 2501] as $cleanupProblemId) {
    $cleanupId = new ProblemId($cleanupProblemId);
    $cleanupFiles = [];
    foreach ($fileManager->files($cleanupId) as $file) {
        $cleanupFiles[$file->name()] = $file->sha256();
    }
    if ($cleanupFiles !== []) {
        $fileManager->deleteMany($cleanupId, $cleanupFiles);
    }
    $problemDirectory = $testDataRoot . DIRECTORY_SEPARATOR . $cleanupProblemId;
    $lockFile = $testDataRoot . DIRECTORY_SEPARATOR . '.oj-platform-problem-'
        . $cleanupProblemId . '.lock';
    if (is_file($lockFile)) {
        unlink($lockFile);
    }
    if (is_dir($problemDirectory)) {
        rmdir($problemDirectory);
    }
}
rmdir($testDataRoot);

// Parsons puzzles preserve logical source, opaque identities and server-owned reconstruction.
$orderingLimitSettings = new ProblemsSettingsManager();
$orderingLimitSettings->values['submission_rules'] = ['max_source_kb' => 1];
$orderingLimitPolicy = new CodeOrderingAuthoringPolicy($orderingLimitSettings);
$orderingAtLimit = "print(1)\n#" . str_repeat('x', 1013) . "\n";
$orderingLimitPolicy->assertFits($orderingAtLimit);
problemsAssert(true, '현재 제출 크기 제한과 같은 배열 원문은 허용해야 합니다.');
$orderingHiddenSource = "x=1\n#" . str_repeat('x', 1014) . "\n";
$orderingLimitPolicy->assertFits($orderingHiddenSource, "x=1 #hidden\r\n");
problemsAssert(true, '비공개 표식 제거와 줄바꿈 정규화 후 실제 채점 코드 크기로 판단해야 합니다.');
foreach ([[$orderingAtLimit . ' ', '', ''], [$orderingAtLimit, 'a=1', ''],
    [substr($orderingAtLimit, 0, 1000), str_repeat('a', 13), str_repeat('b', 13)]] as [$source, $prefix, $suffix]) {
    $rejected = false;
    try { $orderingLimitPolicy->assertFits($source, $prefix, $suffix); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, '원문 또는 앞·뒤 코드 합계가 사이트 제출 상한을 넘으면 저장을 거부해야 합니다.');
}
$rejected = false;
try { (new CodeOrderingAuthoringPolicy())->assertFits(str_repeat('a', 61 * 1024)); } catch (DomainException) { $rejected = true; }
problemsAssert($rejected, '설정 주입이 없어도 60KB 제출 상한을 적용해야 합니다.');
$orderingSource = "a = int(input())\nb = int(input())\nprint(a + b)\n";
$orderingSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create($orderingSource, 'python'));
$orderingBlocks = json_decode($orderingSchema, true, 16, JSON_THROW_ON_ERROR)['blocks'];
$reorderedSchema = json_decode($orderingSchema, true, 16, JSON_THROW_ON_ERROR);
$reorderedSchema['blocks'] = array_reverse($reorderedSchema['blocks']);
problemsSame(CodeOrderingPuzzle::hash($orderingSchema), CodeOrderingPuzzle::hash(CodeOrderingPuzzle::encode($reorderedSchema)),
    '공개 퍼즐 해시에는 기준 순서가 들어가면 안 됩니다.');
$orderingIds = array_column($orderingBlocks, 'id');
problemsSame($orderingSource, CodeOrderingPuzzle::source($orderingSchema), '교사용 원문을 손실 없이 복원해야 합니다.');
problemsSame($orderingSource, CodeOrderingPuzzle::assemble($orderingSchema, $orderingIds), '조각 원문을 서버에서 복원해야 합니다.');
$orderingPublic = CodeOrderingPuzzle::publicBlocks($orderingSchema);
$publicById = array_column($orderingPublic, 'code', 'id');
$originalById = array_column($orderingBlocks, 'code', 'id');
ksort($publicById);
ksort($originalById);
problemsSame($originalById, $publicById, '셔플은 조각의 ID와 원문 집합을 그대로 보존해야 합니다.');
foreach ($orderingPublic as $block) {
    problemsSame(['id', 'code'], array_keys($block), '공개 조각에는 원래 위치나 정답 필드가 없어야 합니다.');
}
foreach ([[$orderingIds[0], $orderingIds[0], $orderingIds[2]], array_slice($orderingIds, 1),
    [$orderingIds[0], str_repeat('0', 32), $orderingIds[2]], ['first' => $orderingIds[0]]] as $invalidIds) {
    $rejected = false;
    try { CodeOrderingPuzzle::assemble($orderingSchema, $invalidIds); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, '중복·누락·알 수 없는 조각·잘못된 배열은 거부해야 합니다.');
}
$duplicateSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create("print(1)\nprint(1)\n", 'python'));
$duplicateBlocks = json_decode($duplicateSchema, true, 16, JSON_THROW_ON_ERROR)['blocks'];
problemsSame(2, count(array_unique(array_column($duplicateBlocks, 'id'))), '같은 코드 두 줄도 서로 다른 조각 ID를 가져야 합니다.');
$multilineSource = <<<'PYTHON'
values = [
    "a)",
    "b",
]
message = """first
second"""
if values:
    print(message)
PYTHON;
$multilineSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create($multilineSource, 'python'));
$multilineBlocks = json_decode($multilineSchema, true, 16, JSON_THROW_ON_ERROR)['blocks'];
problemsSame(4, count($multilineBlocks), '괄호와 여러 줄 문자열 안에서는 조각을 나누면 안 됩니다.');
problemsSame($multilineSource . "\n", CodeOrderingPuzzle::source($multilineSchema), '여러 줄 문자열과 고정 들여쓰기를 보존해야 합니다.');
$continued = "total = 1 + \\\n    2\nprint(total)\n";
problemsSame(2, count(CodeOrderingPuzzle::create($continued, 'python')['blocks']), '명시적 줄 연속을 하나의 조각으로 묶어야 합니다.');
$commentSlash = "print(1) # comment \\\nprint(2)\n";
problemsSame(2, count(CodeOrderingPuzzle::create($commentSlash, 'python')['blocks']),
    'Python 주석 끝의 역슬래시는 다음 코드 줄과 연결하면 안 됩니다.');
foreach (['c', 'cpp', 'java'] as $key) {
    $source = "/* comment\ncontinued */\nint value = call(\n    1, 2);\nprint(value);\n";
    $schema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create($source, $key));
    problemsSame($source, CodeOrderingPuzzle::source($schema), '여러 줄 주석과 함수 인자를 보존해야 합니다.');
    problemsSame(3, count(json_decode($schema, true, 16, JSON_THROW_ON_ERROR)['blocks']), '여러 줄 주석과 괄호를 나누면 안 됩니다.');
}
$rawCpp = <<<'CPP'
auto text = R"tag(first "quote"
second)tag";
print(text);
CPP;
$rawSchema = CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create($rawCpp, 'cpp'));
problemsSame($rawCpp . "\n", CodeOrderingPuzzle::source($rawSchema), 'C++ 원시 문자열을 보존해야 합니다.');
problemsSame(2, count(json_decode($rawSchema, true, 16, JSON_THROW_ON_ERROR)['blocks']), 'C++ 원시 문자열 내부를 나누면 안 됩니다.');
foreach ([['print(1)', 'python'], ["a = [1\nprint(a)\n", 'python'], ["a = 1\nprint(a)\n", 'unknown']] as [$source, $key]) {
    $rejected = false;
    try { CodeOrderingPuzzle::create($source, $key); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, '조각 부족·미닫힌 구문·미지원 언어를 거부해야 합니다.');
}
(function () use ($requestBody, $judgeLanguages, $permissions, $aiAvailability, $orderingSource, $fileManager): void {
    $gateway = new MemoryProblemGateway();
    $metadata = new MemoryMetadata();
    $links = new MemoryTopicLinks();
    $topics = new MemoryTopics();
    $assignments = new MemoryAssignments();
    $access = new ProblemAccessPolicy($permissions, $assignments, $aiAvailability,
        new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry());
    $limits = new ProblemsSettingsManager();
    $limits->values['submission_rules'] = ['max_source_kb' => 1];
    $authoring = new CodeOrderingAuthoringPolicy($limits);
    $editor = new ProblemEditorService($gateway, $metadata, $links, $topics, $assignments,
        new DirectProblemTransaction(), $access, new CodeCompletionTemplatePolicy(),
        new MemoryProblemNumberChangeLock(), $judgeLanguages, $authoring);
    $requests = new ProblemRequestFactory($judgeLanguages);
    $body = ['code_entry_mode' => 'code_ordering', 'ordering_source' => $orderingSource,
        'ordering_language_key' => 'python', 'starter_code' => 'teacher answer must never leak',
        'enabled_language_ids' => []] + $requestBody;
    $command = $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $body));
    problemsSame('', $command->problem()->starterCode(), '완성 정답은 공개 시작 코드에 저장하면 안 됩니다.');
    problemsSame([6], $judgeLanguages->enabledIds($command->problem()->languageMask()), '배열 언어 하나로 제출 언어를 고정해야 합니다.');
    $id = $editor->create(1, $command);
    $first = $metadata->find($id);
    problemsSame(CodeEntryMode::CodeOrdering, $first?->codeEntryMode(), '코드 배열 방식을 저장해야 합니다.');
    $tooLarge = ['ordering_source' => "x='" . str_repeat('a', 1024) . "'\nprint(x)\n"] + $body;
    $rejected = false;
    try { $editor->create(1, $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $tooLarge))); }
    catch (DomainException) { $rejected = true; }
    problemsAssert($rejected && count($gateway->items) === 1, '사이트 상한 초과 배열 문제는 Judge 원문 생성 전 거부해야 합니다.');
    $editor->update(1, $id, $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $body)));
    problemsSame($first?->orderingHash(), $metadata->find($id)?->orderingHash(), '코드가 그대로이면 조각 ID와 해시를 유지해야 합니다.');
    $body['ordering_source'] = str_replace('a + b', 'b + a', $orderingSource);
    $editor->update(1, $id, $requests->editCommand(new HttpRequest('POST', '/oj-action', [], $body)));
    problemsAssert($first?->orderingHash() !== $metadata->find($id)?->orderingHash(), '코드를 바꾸면 퍼즐 버전이 달라져야 합니다.');
    $policy = (new ProblemCodePolicyService($gateway, $metadata))->forProblem($id);
    problemsSame($metadata->find($id)?->orderingHash(), $policy->orderingHash(), '제출 정책에 배열 해시를 전달해야 합니다.');
    $drafts = new MemoryProblemFormDrafts();
    $inputs = new ProblemAiInputFactory($topics);
    $aiDrafts = new ProblemAiDraftService($drafts, $gateway, $metadata, $topics, $inputs, new ProblemAiOutputValidator($topics));
    $reference = $drafts->store('problem_metadata', 1, 'problem', (string) $id->value(), [
        'difficulty_score' => 2, 'topic_keys' => ['simulation'], 'confidence' => 0.8,
        'rationale' => '코드 배열 메타데이터 보존 검사',
        'problem_snapshot_hash' => $inputs->hash($inputs->problem($gateway->find($id), $metadata->find($id))),
        'dictionary_version' => $topics->version(),
    ]);
    $errors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
        public function report(Throwable $error): void { throw $error; }
    };
    $result = (new ProblemMetadataBulkApplyService($aiDrafts, $gateway, $metadata, $editor, $access, $errors))
        ->applyMany(1, [['problem_id' => $id->value(), 'reference' => $reference->value()]]);
    problemsSame([$id->value()], $result['applied'], '배열 문제에도 AI 메타데이터 일괄 승인을 적용해야 합니다.');
    problemsSame($policy->orderingSchema(), $metadata->find($id)?->orderingSchema(), '메타데이터 일괄 변경에서 배열 조각을 보존해야 합니다.');
    problemsSame($policy->orderingHash(), $metadata->find($id)?->orderingHash(), '메타데이터 일괄 변경에서 배열 버전을 보존해야 합니다.');
    $item = new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem(0, $id,
        ProblemDraft::fromProblem($gateway->find($id)), true, null, [], CodeEntryMode::CodeOrdering,
        null, [], $policy->orderingSchema());
    $codec = new ProblemXmlCodec();
    $xml = $codec->encode([$item], true, false);
    $decoded = $codec->decode($xml);
    problemsSame([], $decoded->issues(), '코드 배열 XML을 오류 없이 읽어야 합니다.');
    problemsSame($policy->orderingSchema(), $decoded->items()[0]->orderingSchema(), 'XML 왕복에서 조각과 원문 순서를 보존해야 합니다.');
    $materializer = new ProblemXmlMaterializer($gateway, $metadata, $links, $topics, $fileManager, new CodeCompletionTemplatePolicy(), $authoring);
    $materializer->validateImport($decoded->items()[0], '', true);
    $imported = $materializer->metadataFor($id, 1, $decoded->items()[0], '', true);
    problemsSame($policy->orderingHash(), $imported->orderingHash(), 'XML 가져오기에서 배열 버전을 보존해야 합니다.');
    $tooLargeItem = new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem(0, $id,
        $command->problem(), true, null, [], CodeEntryMode::CodeOrdering, null, [],
        CodeOrderingPuzzle::encode(CodeOrderingPuzzle::create($tooLarge['ordering_source'], 'python')));
    $rejected = false;
    try { $materializer->validateImport($tooLargeItem, '', true); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, 'XML 미리보기에서도 현재 사이트 제출 상한을 검사해야 합니다.');
    $rejected = false;
    try { $materializer->metadataFor($id, 1, $decoded->items()[0], '', false); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, '배열 설정이 빠지는 XML 가져오기는 거부해야 합니다.');
    $rejected = false;
    try { $codec->encode([$item], false, false); } catch (DomainException) { $rejected = true; }
    problemsAssert($rejected, '배열 설정이 빠지는 XML 내보내기는 거부해야 합니다.');
})();

fwrite(
    STDOUT,
    sprintf("R-05 문제 도메인 단위 검사 통과: assertion %d개\n", $assertions)
);

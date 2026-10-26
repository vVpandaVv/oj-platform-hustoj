<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Application\AdminUserCreationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserBulkImportService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserCsvImportJobHandler;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserImportCsvParser;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserImportDraftService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
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
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Domain\CapabilityScope;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$importAssertions = 0;
$importTemporaryFiles = [];

register_shutdown_function(static function () use (&$importTemporaryFiles): void {
    foreach ($importTemporaryFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function importAssert(bool $condition, string $message): void
{
    global $importAssertions;
    ++$importAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function importSame(mixed $expected, mixed $actual, string $message): void
{
    importAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

function importThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        importAssert(true, $message);

        return;
    }

    importAssert(false, $message);
}

function importCsv(string $contents): UploadedFile
{
    global $importTemporaryFiles;
    $path = tempnam(sys_get_temp_dir(), 'oj-import-');
    if ($path === false || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('테스트 CSV 파일을 만들지 못했습니다.');
    }
    $importTemporaryFiles[] = $path;

    return new UploadedFile(
        'users.csv',
        $path,
        (int) filesize($path),
        UPLOAD_ERR_OK
    );
}

final class ImportMemoryUsers implements WordPressUserGateway
{
    /** @var array<int, UserIdentity> */
    public array $users = [];
    /** @var array<int, string> */
    public array $passwords = [];
    private int $nextId = 100;

    public function seed(string $login, string $email): UserIdentity
    {
        return $this->createUser($login, $email, 'seed-password', $login, 'subscriber');
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
            if (strcasecmp($user->userLogin(), $userLogin) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->email(), $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        $user = $this->findByLogin($userLogin);

        return $user !== null
            && ($this->passwords[$user->userId()] ?? '') === $password
            ? $user
            : null;
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
        if ($this->findByLogin($userLogin) !== null || $this->findByEmail($email) !== null) {
            throw new RuntimeException('duplicate');
        }
        $user = new UserIdentity(
            $this->nextId++,
            $userLogin,
            $email,
            $displayName,
            [$role]
        );
        $this->users[$user->userId()] = $user;
        $this->passwords[$user->userId()] = $password;

        return $user;
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        $before = $this->users[$userId];
        $after = new UserIdentity(
            $userId,
            $before->userLogin(),
            $email,
            $displayName,
            $before->roles()
        );
        $this->users[$userId] = $after;

        return $after;
    }

    public function setRoles(int $userId, array $roles): void
    {
        $before = $this->users[$userId];
        $this->users[$userId] = new UserIdentity(
            $userId,
            $before->userLogin(),
            $before->email(),
            $before->displayName(),
            $roles
        );
    }

    public function setPassword(int $userId, string $password): void
    {
        $this->passwords[$userId] = $password;
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
        unset($this->users[$userId], $this->passwords[$userId]);
    }

    public function findMany(array $userIds): array
    {
        return array_values(array_filter(
            array_map(fn (int $id): ?UserIdentity => $this->findById($id), $userIds)
        ));
    }
}

final class ImportMemoryProfiles implements UserProfileRepository
{
    /** @var array<int, UserProfile> */
    public array $profiles = [];

    public function find(int $userId): ?UserProfile
    {
        return $this->profiles[$userId] ?? null;
    }

    public function create(UserProfile $profile): void
    {
        $this->profiles[$profile->userId()] = $profile;
    }

    public function save(UserProfile $profile, ?int $changedByUserId = null): void
    {
        unset($changedByUserId);
        $this->profiles[$profile->userId()] = $profile;
    }

    public function recordLogin(int $userId): void
    {
        unset($userId);
    }

    public function saveRegistrationRequirements(
        int $userId,
        RegistrationRequirements $requirements
    ): void {
        unset($userId, $requirements);
    }

    public function registrationRequirements(int $userId): ?RegistrationRequirements
    {
        unset($userId);

        return null;
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        return array_slice(array_values($this->profiles), $offset, $limit);
    }

    public function findByStatus(
        AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array {
        return array_slice(
            array_values(array_filter(
                $this->profiles,
                static fn (UserProfile $profile): bool => $profile->accessStatus() === $status
            )),
            $offset,
            $limit
        );
    }

    public function findFiltered(
        ?array $userIds,
        ?AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array {
        if ($userIds === []) {
            return [];
        }

        return array_slice(array_values(array_filter(
            $this->profiles,
            static function (UserProfile $profile) use ($userIds, $status): bool {
                if ($userIds !== null && ! in_array($profile->userId(), $userIds, true)) {
                    return false;
                }

                return $status === null || $profile->accessStatus() === $status;
            }
        )), $offset, $limit);
    }

    public function countFiltered(?array $userIds, ?AccessStatus $status): int
    {
        if ($userIds === []) {
            return 0;
        }

        return count(array_filter(
            $this->profiles,
            static function (UserProfile $profile) use ($userIds, $status): bool {
                if ($userIds !== null && ! in_array($profile->userId(), $userIds, true)) {
                    return false;
                }

                return $status === null || $profile->accessStatus() === $status;
            }
        ));
    }
}

final class ImportMemoryPreferences implements UserPreferenceRepository
{
    /** @var array<int, UserPreferences> */
    public array $preferences = [];

    public function find(int $userId): ?UserPreferences
    {
        return $this->preferences[$userId] ?? null;
    }

    public function save(UserPreferences $preferences): void
    {
        $this->preferences[$preferences->userId()] = $preferences;
    }
}

final class ImportMemoryTransaction implements AuthTransaction
{
    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }
}

final class ImportMemoryVault implements FormDraftStore
{
    /** @var array<string, FormDraft> */
    public array $values = [];
    private int $nextId = 1;

    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference {
        $reference = new FormDraftReference(sprintf(
            'draft:v1:%08x-0000-4000-8000-%012x',
            $this->nextId,
            $this->nextId
        ));
        ++$this->nextId;
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

final class ImportMemoryJobs implements JobQueue
{
    public ?JobExecutionPlan $plan = null;
    /** @var list<JobItemPlan> */
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

final class ImportCapabilityChecker implements CapabilityChecker
{
    public function userCan(int $userId, string $capability): bool
    {
        return $userId === 7
            && in_array($capability, ['oj_create_users', 'oj_manage_roles'], true);
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
    }
}

final class ImportErrorReporter implements ErrorReporter
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

$users = new ImportMemoryUsers();
$users->seed('existing', 'existing@example.com');
$roles = new DefinitionRegistry('import-roles', RoleDefinition::class);
$roles->register(new RoleDefinition(
    'oj_group_manager',
    '그룹 위임 운영자',
    ['oj_create_users']
));
$capabilities = new DefinitionRegistry('import-capabilities', CapabilityDefinition::class);
$capabilities->register(new CapabilityDefinition(
    'oj_create_users',
    new ModuleId('auth'),
    'accounts',
    CapabilityScope::Global,
    '사용자 생성',
    '사용자 계정을 생성합니다.'
));
$capabilities->register(new CapabilityDefinition(
    'oj_manage_roles',
    new ModuleId('auth'),
    'accounts',
    CapabilityScope::Global,
    '역할 관리',
    '사용자 역할을 관리합니다.'
));
$permissions = new PermissionService($capabilities, new ImportCapabilityChecker());
$inputs = new AccountInputPolicy();
$parser = new UserImportCsvParser($inputs, $users, $roles);

$previewFile = importCsv(
    "\xEF\xBB\xBFuser_login,email,password,display_name,affiliation,birth_date,role\n"
    . "learner01,learner01@example.com,change-this-password,학습자 01,1반,2012-03-04,subscriber\n"
    . "manager01,manager01@example.com,another-password-12,운영자 01,,,oj_group_manager\n"
    . "learner01,duplicate@example.com,duplicate-pass-12,중복 아이디,,,subscriber\n"
    . "existing,new@example.com,existing-pass-12,기존 아이디,,,subscriber\n"
    . "badbirth,badbirth@example.com,badbirth-pass-12,잘못된 생년,,2025-02-30,subscriber\n"
);
$preview = $parser->parse($previewFile, true);
importSame(5, count($preview->candidates()), 'CSV의 비어 있지 않은 모든 행을 미리보기에 표시해야 합니다.');
importSame(2, $preview->validCount(), '유효한 사용자 두 명만 실행 후보여야 합니다.');
importSame(3, $preview->invalidCount(), '중복과 잘못된 날짜 행은 제외되어야 합니다.');
importAssert(
    in_array('duplicate_login', $preview->candidates()[2]->errors(), true),
    'CSV 내부 로그인 아이디 중복을 표시해야 합니다.'
);
importAssert(
    in_array('existing_login', $preview->candidates()[3]->errors(), true),
    'WordPress 기존 로그인 아이디를 표시해야 합니다.'
);
importAssert(
    in_array('invalid_birth_date', $preview->candidates()[4]->errors(), true),
    '실재하지 않는 생년월일을 표시해야 합니다.'
);

$roleDenied = $parser->parse(importCsv(
    "user_login,email,password,display_name,role\n"
    . "manager02,manager02@example.com,manager-password-12,운영자 02,oj_group_manager\n"
), false);
importAssert(
    in_array('role_not_allowed', $roleDenied->candidates()[0]->errors(), true),
    '역할 관리 권한이 없으면 운영 역할 배정을 거부해야 합니다.'
);

$vault = new ImportMemoryVault();
$drafts = new UserImportDraftService($parser, $vault);
$draft = $drafts->create($previewFile, 7, true);
importAssert($draft->reference() !== null, '유효한 행이 있으면 암호화 초안 참조를 발급해야 합니다.');
importSame(1, count($vault->values), '비밀번호가 포함된 후보는 단일 초안 비밀값에 저장해야 합니다.');

$profiles = new ImportMemoryProfiles();
$preferences = new ImportMemoryPreferences();
$creation = new AdminUserCreationService(
    $users,
    $profiles,
    $preferences,
    $inputs,
    new ImportMemoryTransaction()
);
$jobs = new ImportMemoryJobs();
$errors = new ImportErrorReporter();
$jobDefinitions = new DefinitionRegistry(
    'import-job-definitions',
    JobDefinition::class
);
$jobDefinitions->register(JobDefinition::fromArray([
    'key' => 'user_csv_import',
    'owner_module' => 'auth',
    'definition_version' => '1',
    'max_attempts' => 3,
    'uses_steps' => false,
]));
$jobManager = new JobManager(
    $jobDefinitions,
    new IdempotencyKeyFactory(),
    $vault,
    $jobs
);
$bulkImport = new UserBulkImportService(
    $drafts,
    $jobManager,
    $permissions,
    $errors
);
$draftReference = $draft->reference()?->value() ?? '';
$enqueueResult = $bulkImport->enqueue($draftReference, 7);

importSame(77, $enqueueResult->jobId(), '일괄 등록은 Jobs 작업 번호를 반환해야 합니다.');
importSame(2, count($jobs->items), '사용자별 Jobs 항목을 하나씩 등록해야 합니다.');
importSame(0, count($profiles->profiles), '웹 요청에서는 사용자 계정을 생성하면 안 됩니다.');
importSame(2, count($vault->values), '작업 항목 비밀번호는 Worker 실행 전까지 암호화 저장해야 합니다.');
importSame([], $errors->errors, '정상 실행은 오류 보고를 만들지 않아야 합니다.');

$importHandler = new UserCsvImportJobHandler(
    $creation,
    $permissions,
    $roles
);
$handlerResults = [];
foreach ($jobs->items as $index => $itemPlan) {
    $claim = new ClaimedJobItem(
        77,
        $index + 1,
        'user_csv_import',
        $itemPlan->targetType(),
        $itemPlan->targetKey(),
        null,
        null,
        $itemPlan->inputHash(),
        $itemPlan->payloadReference(),
        1,
        $itemPlan->maxAttempts(),
        7,
        hash('sha256', 'import-' . $index)
    );
    $itemPayload = $vault->find(
        $itemPlan->payloadReference(),
        7
    )?->payload();
    $handlerResult = $importHandler->handle(new JobWorkItem(
        $claim,
        $itemPayload
    ));
    $handlerResults[] = $handlerResult;
    importSame('succeeded', $handlerResult->outcome(), 'Worker가 사용자 후보를 생성해야 합니다.');
    if ($index === 0) {
        $repeatedResult = $importHandler->handle(new JobWorkItem(
            $claim,
            $itemPayload
        ));
        importSame(
            'succeeded',
            $repeatedResult->outcome(),
            '상태 저장 전 중단 뒤 같은 사용자 생성 항목을 재실행해도 성공해야 합니다.'
        );
        importSame(
            2,
            count($users->users),
            '사용자 생성 항목 재실행은 같은 계정을 중복 생성하면 안 됩니다.'
        );
    }
    $vault->discard($itemPlan->payloadReference(), 7);
}
importSame(2, count($profiles->profiles), 'Worker가 생성한 사용자마다 플랫폼 프로필을 만들어야 합니다.');
importSame(0, count($vault->values), '성공한 항목의 암호화 입력은 폐기해야 합니다.');

$safeJobData = var_export([
    $jobs->plan?->inputSummary(),
    $jobs->plan?->targetSummary(),
    array_map(
        static fn (JobHandlerResult $result): array => $result->safeResult(),
        $handlerResults
    ),
], true);
importAssert(
    ! str_contains($safeJobData, 'change-this-password')
    && ! str_contains($safeJobData, 'another-password-12'),
    '비밀번호는 Jobs 요약이나 결과에 기록되어서는 안 됩니다.'
);
importThrows(
    static fn () => $bulkImport->enqueue($draftReference, 7),
    '소비한 미리보기 초안은 다시 실행할 수 없어야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-04 사용자 일괄 등록 단위 검사 통과: assertion %d개\n", $importAssertions)
);

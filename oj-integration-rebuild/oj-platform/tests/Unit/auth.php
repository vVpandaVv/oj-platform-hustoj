<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentVerifier;
use CodingPanda\OjPlatform\Modules\Auth\Application\LegacyAccountTransitionService;
use CodingPanda\OjPlatform\Modules\Auth\Application\LegacyJudgePasswordVerifier;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginOutcome;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginService;
use CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationInput;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserSettingsInput;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserRoleService;
use CodingPanda\OjPlatform\Modules\Auth\Application\VerifiedLegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AdminActionAuditLog;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserRecordSource;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyTransitionChallengeStore;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserProfileWriter;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSearchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;
use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeUserRecord;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserLoginRecord;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserPreferenceRepository;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\LoginActionHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$authAssertions = 0;

function authAssert(bool $condition, string $message): void
{
    global $authAssertions;
    ++$authAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function authSame(mixed $expected, mixed $actual, string $message): void
{
    authAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class AuthMemoryUsers implements WordPressUserGateway, WordPressUserProfileWriter
{
    /** @var array<int, UserIdentity> */
    public array $identities = [];
    /** @var array<int, string> */
    public array $passwords = [];
    /** @var list<int> */
    public array $sessions = [];
    /** @var list<int> */
    public array $invalidatedSessions = [];
    public ?int $currentUserId = null;
    private int $nextId = 100;

    public function seed(
        string $login,
        string $password,
        array $roles = ['subscriber'],
        string $email = ''
    ): UserIdentity {
        $identity = new UserIdentity(
            $this->nextId++,
            $login,
            $email !== '' ? $email : $login . '@example.test',
            strtoupper($login),
            $roles
        );
        $this->identities[$identity->userId()] = $identity;
        $this->passwords[$identity->userId()] = $password;

        return $identity;
    }

    public function currentUser(): ?UserIdentity
    {
        return $this->currentUserId === null
            ? null
            : ($this->identities[$this->currentUserId] ?? null);
    }

    public function findById(int $userId): ?UserIdentity
    {
        return $this->identities[$userId] ?? null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->userLogin() === $userLogin) {
                return $identity;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        foreach ($this->identities as $identity) {
            if (strcasecmp($identity->email(), $email) === 0) {
                return $identity;
            }
        }

        return null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        $identity = $this->findByLogin($userLogin);

        return $identity !== null
            && hash_equals($this->passwords[$identity->userId()] ?? '', $password)
            ? $identity
            : null;
    }

    public function establishSession(int $userId, bool $remember): void
    {
        unset($remember);
        $this->currentUserId = $userId;
        $this->sessions[] = $userId;
    }

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        if ($this->findByLogin($userLogin) !== null) {
            throw new RuntimeException('duplicate');
        }

        $identity = new UserIdentity(
            $this->nextId++,
            $userLogin,
            $email,
            $displayName,
            [$role],
            $displayName
        );
        $this->identities[$identity->userId()] = $identity;
        $this->passwords[$identity->userId()] = $password;

        return $identity;
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        $before = $this->identities[$userId] ?? null;
        if ($before === null) {
            throw new RuntimeException('missing');
        }
        $after = new UserIdentity(
            $userId,
            $before->userLogin(),
            $email,
            $displayName,
            $before->roles(),
            $before->nickname(),
            $before->registeredAt()
        );
        $this->identities[$userId] = $after;

        return $after;
    }

    public function updateProfileIdentity(
        int $userId,
        string $email,
        string $displayName,
        string $nickname
    ): UserIdentity {
        $before = $this->identities[$userId] ?? null;
        if ($before === null) {
            throw new RuntimeException('missing');
        }
        $after = new UserIdentity(
            $userId,
            $before->userLogin(),
            $email,
            $displayName,
            $before->roles(),
            $nickname,
            $before->registeredAt()
        );
        $this->identities[$userId] = $after;

        return $after;
    }

    public function setRoles(int $userId, array $roles): void
    {
        $before = $this->identities[$userId];
        $this->identities[$userId] = new UserIdentity(
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
        $this->invalidatedSessions[] = $userId;
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
        unset($resetKey);
        $identity = $this->findByLogin($userLogin);
        if ($identity === null) {
            throw new RuntimeException('missing');
        }
        $this->setPassword($identity->userId(), $newPassword);
    }

    public function deleteUser(int $userId): void
    {
        unset($this->identities[$userId], $this->passwords[$userId]);
    }

    public function findMany(array $userIds): array
    {
        $found = [];
        foreach ($userIds as $userId) {
            if (isset($this->identities[$userId])) {
                $found[] = $this->identities[$userId];
            }
        }

        return $found;
    }
}

final class AuthMemoryProfiles implements UserProfileRepository
{
    /** @var array<int, UserProfile> */
    public array $profiles = [];
    /** @var array<int, RegistrationRequirements> */
    public array $requirements = [];

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
        authAssert(isset($this->profiles[$userId]), '로그인 기록 대상이 없습니다.');
    }

    public function saveRegistrationRequirements(
        int $userId,
        RegistrationRequirements $requirements
    ): void {
        $this->requirements[$userId] = $requirements;
    }

    public function registrationRequirements(int $userId): ?RegistrationRequirements
    {
        return $this->requirements[$userId] ?? null;
    }

    public function findByStatus(AccessStatus $status, int $limit, int $offset = 0): array
    {
        unset($offset);

        return array_slice(array_values(array_filter(
            $this->profiles,
            static fn (UserProfile $profile): bool => $profile->accessStatus() === $status
        )), 0, $limit);
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        return array_slice(array_values($this->profiles), $offset, $limit);
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

        return array_slice($this->matching($userIds, $status), $offset, $limit);
    }

    public function countFiltered(?array $userIds, ?AccessStatus $status): int
    {
        if ($userIds === []) {
            return 0;
        }

        return count($this->matching($userIds, $status));
    }

    /**
     * @param list<int>|null $userIds
     * @return list<UserProfile>
     */
    private function matching(?array $userIds, ?AccessStatus $status): array
    {
        return array_values(array_filter(
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

final class AuthMemoryLoginHistory implements UserLoginHistoryRepository
{
    /** @var list<array{user_id: int, ip_address: ?string, user_agent: ?string, logged_in_at: string}> */
    public array $records = [];

    public function record(int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        $this->records[] = [
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'logged_in_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function findForUser(int $userId, int $limit, int $offset = 0): array
    {
        $matching = array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['user_id'] === $userId
        ));

        return array_map(
            static fn (array $record): UserLoginRecord => UserLoginRecord::fromRow(array_merge(
                ['id' => 0],
                $record
            )),
            array_slice(array_reverse($matching), $offset, $limit)
        );
    }

    public function countForUser(int $userId): int
    {
        return count(array_filter(
            $this->records,
            static fn (array $record): bool => $record['user_id'] === $userId
        ));
    }
}

final class AuthMemoryPreferences implements UserPreferenceRepository
{
    /** @var array<int, UserPreferences> */
    public array $items = [];

    public function find(int $userId): ?UserPreferences
    {
        return $this->items[$userId] ?? null;
    }

    public function save(UserPreferences $preferences): void
    {
        $this->items[$preferences->userId()] = $preferences;
    }
}

final class AuthMemoryAgreements implements AgreementRepository
{
    /** @var array<string, AgreementDocument> */
    public array $documents = [];
    /** @var array<int, list<int>> */
    public array $agreed = [];

    public function activeDocuments(): array
    {
        return array_values($this->documents);
    }

    public function activeDocumentByType(string $typeSlug): ?AgreementDocument
    {
        return $this->documents[$typeSlug] ?? null;
    }

    public function recordAgreement(
        int $userId,
        array $documentIds,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        unset($ipAddress, $userAgent);
        $this->agreed[$userId] = array_values(array_unique($documentIds));
    }

    public function agreedDocumentIds(int $userId): array
    {
        return $this->agreed[$userId] ?? [];
    }

    public function publish(
        string $typeSlug,
        string $typeLabel,
        string $version,
        string $title,
        string $content,
        bool $required,
        int $createdByUserId
    ): AgreementDocument {
        unset($typeLabel, $createdByUserId);
        $document = new AgreementDocument(
            count($this->documents) + 1,
            $typeSlug,
            $version,
            $title,
            $content,
            $required
        );
        $this->documents[$typeSlug] = $document;

        return $document;
    }
}

final class AuthMemoryGuardians implements GuardianVerificationRepository
{
    /** @var array<string, int> */
    public array $verified = [];
    /** @var array<int, bool> */
    public array $consumed = [];
    private int $nextId = 1;

    public function issue(
        string $childEmail,
        string $guardianName,
        string $guardianEmail,
        int $expiresHours,
        AgreementDocument $document
    ): GuardianVerificationChallenge {
        unset($childEmail, $guardianName, $guardianEmail, $expiresHours);

        return new GuardianVerificationChallenge(
            $this->nextId++,
            str_repeat('a', 64),
            '12345678',
            new DateTimeImmutable('+1 hour')
        );
    }

    public function cancel(int $verificationId): void
    {
        unset($verificationId);
    }

    public function pendingReview(string $token): ?\CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview
    {
        return $token === str_repeat('a', 64) ? new \CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview(
            'child@example.test', '보호자', 'guardian@example.test',
            new AgreementDocument(3, 'guardian-consent', '1', '법정대리인 동의서', '동의 내용', false)
        ) : null;
    }

    public function confirm(string $token, int $documentId, \CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship $relationship): bool
    {
        return $this->pendingReview($token)?->document->documentId() === $documentId;
    }

    public function isVerified(string $childEmail, string $code): bool
    {
        return isset($this->verified[strtolower($childEmail) . '|' . $code]);
    }

    public function claimVerified(string $childEmail, string $code): ?int
    {
        $key = strtolower($childEmail) . '|' . $code;
        if (! isset($this->verified[$key])) {
            return null;
        }

        $id = $this->verified[$key];
        unset($this->verified[$key]);

        return $id;
    }

    public function bindClaim(int $verificationId, int $userId): void
    {
        unset($verificationId);
        $this->consumed[$userId] = true;
    }

    public function releaseClaim(int $verificationId): void
    {
        unset($verificationId);
    }

    public function hasConsumedVerification(int $userId): bool
    {
        return $this->consumed[$userId] ?? false;
    }
}

final class AuthMemoryTransaction implements AuthTransaction
{
    public int $begun = 0;
    public int $committed = 0;
    public int $rolledBack = 0;

    public function begin(): void
    {
        ++$this->begun;
    }

    public function commit(): void
    {
        ++$this->committed;
    }

    public function rollBack(): void
    {
        ++$this->rolledBack;
    }
}

final class AuthPolicyProvider implements RegistrationPolicyProvider
{
    public function __construct(public RegistrationPolicy $policy)
    {
    }

    public function current(): RegistrationPolicy
    {
        return $this->policy;
    }
}

final class AuthLegacyReader implements LegacyJudgeUserReader
{
    public int $calls = 0;

    public function verify(string $userLogin, string $password): ?LegacyJudgeCandidate
    {
        ++$this->calls;
        if ($userLogin !== 'legacy' || $password !== 'legacy-password') {
            return null;
        }

        return new LegacyJudgeCandidate(
            'legacy',
            'legacy@example.test',
            '기존 사용자',
            '학습 모임',
            '2005-05-05'
        );
    }
}

final class AuthChallengeStore implements LegacyTransitionChallengeStore
{
    public bool $valid = false;
    public string $candidateFingerprint = '';
    public string $contextFingerprint = '';

    public function issue(
        string $candidateFingerprint,
        string $contextFingerprint
    ): string {
        $this->valid = true;
        $this->candidateFingerprint = $candidateFingerprint;
        $this->contextFingerprint = $contextFingerprint;

        return str_repeat('b', 64);
    }

    public function consume(
        string $token,
        string $candidateFingerprint,
        string $contextFingerprint
    ): bool {
        if (
            ! $this->valid
            || $token !== str_repeat('b', 64)
            || $candidateFingerprint !== $this->candidateFingerprint
            || $contextFingerprint !== $this->contextFingerprint
        ) {
            return false;
        }
        $this->valid = false;

        return true;
    }
}

final class AuthLegacyRecordSource implements LegacyJudgeUserRecordSource
{
    public function __construct(private readonly LegacyJudgeUserRecord $record)
    {
    }

    public function findByLogin(string $userLogin): ?LegacyJudgeUserRecord
    {
        return $userLogin === $this->record->userLogin() ? $this->record : null;
    }
}

final class AuthResetPolicy implements PasswordResetTargetPolicy
{
    public function allows(
        int $actorUserId,
        UserIdentity $target,
        UserProfile $profile
    ): bool {
        unset($actorUserId, $profile);

        return ! $this->protectsTarget($target);
    }

    public function protectsTarget(UserIdentity $target): bool
    {
        foreach ($target->roles() as $role) {
            if ($role === 'administrator' || str_starts_with($role, 'oj_')) {
                return true;
            }
        }

        return false;
    }
}

final class AuthAuditLog implements AdminActionAuditLog
{
    /** @var list<array{actor_user_id: int, scope: string, target_user_id: int, action: string, result: string, recorded_at: string}> */
    public array $entries = [];

    public function record(
        int $actorUserId,
        string $scope,
        int $targetUserId,
        string $action,
        string $result
    ): void {
        $this->entries[] = [
            'actor_user_id' => $actorUserId,
            'scope' => $scope,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'result' => $result,
            'recorded_at' => '2026-01-01 00:00:00',
        ];
    }

    public function recent(int $limit = 100): array
    {
        return array_slice(array_reverse($this->entries), 0, max(0, $limit));
    }
}

final class AuthRoleAssignmentPolicy implements UserRoleAssignmentPolicy
{
    public function allows(int $actorUserId, UserIdentity $target): bool
    {
        unset($target);

        return $actorUserId === 900;
    }
}

$inputPolicy = new AccountInputPolicy();
authSame('1234', $inputPolicy->userLogin('1234'), '숫자 로그인 아이디도 문자열로 허용해야 합니다.');
$longLoginRejected = false;
try {
    $inputPolicy->userLogin(str_repeat('a', 49));
} catch (DomainException) {
    $longLoginRejected = true;
}
authAssert($longLoginRejected, 'Judge 호환 길이를 넘는 새 로그인 아이디를 거부해야 합니다.');
$invalidEmailRejected = false;
try {
    $inputPolicy->email('not-an-email');
} catch (DomainException) {
    $invalidEmailRejected = true;
}
authAssert($invalidEmailRejected, '올바르지 않은 이메일을 거부해야 합니다.');
$siteBoundaryClock = new class implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-09-12 00:30:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
authSame(
    '2026-09-12',
    (new AccountInputPolicy($siteBoundaryClock))->birthDate('2026-09-12'),
    '사이트 지역시의 오늘은 UTC 날짜가 전날이어도 유효해야 합니다.'
);
$passwordVerifier = new LegacyJudgePasswordVerifier();
$legacySalt = 'a1b2';
$legacySaltedHash = base64_encode(
    sha1(md5('legacy-password') . $legacySalt, true) . $legacySalt
);
authAssert(
    $passwordVerifier->verify('legacy-password', md5('legacy-password')),
    'HUSTOJ 기존 MD5 비밀번호를 검증해야 합니다.'
);
authAssert(
    $passwordVerifier->verify('legacy-password', $legacySaltedHash),
    'HUSTOJ salt 비밀번호를 검증해야 합니다.'
);
authAssert(
    ! $passwordVerifier->verify('wrong-password', $legacySaltedHash),
    '잘못된 HUSTOJ 비밀번호를 거부해야 합니다.'
);
$verifiedLegacyReader = new VerifiedLegacyJudgeUserReader(
    new AuthLegacyRecordSource(new LegacyJudgeUserRecord(
        'verified-legacy',
        'verified-legacy@example.test',
        $legacySaltedHash,
        '검증된 기존 사용자',
        '학습 모임',
        true
    )),
    $passwordVerifier
);
authAssert(
    $verifiedLegacyReader->verify(
        'verified-legacy',
        'legacy-password'
    ) instanceof LegacyJudgeCandidate,
    '활성 HUSTOJ 원본과 비밀번호가 모두 맞을 때만 전환 후보를 반환해야 합니다.'
);
authSame(
    null,
    $verifiedLegacyReader->verify('verified-legacy', 'wrong-password'),
    'HUSTOJ 원본의 잘못된 비밀번호는 후보가 아니어야 합니다.'
);

$users = new AuthMemoryUsers();
$profiles = new AuthMemoryProfiles();
$preferences = new AuthMemoryPreferences();
$agreements = new AuthMemoryAgreements();
$guardians = new AuthMemoryGuardians();
$boundaryConsent = (new GuardianConsentVerifier(
    $guardians,
    $siteBoundaryClock
))->claim(
    new RegistrationPolicy(
        true,
        ApprovalMode::Automatic,
        'subscriber',
        ['terms'],
        true,
        14,
        24
    ),
    'boundary@example.test',
    '2012-09-12',
    null
);
authAssert(
    ! $boundaryConsent->isRequired(),
    '보호자 동의 나이는 WordPress 사이트 날짜를 기준으로 판정해야 합니다.'
);
$transaction = new AuthMemoryTransaction();
$legacy = new AuthLegacyReader();
$challenges = new AuthChallengeStore();
$agreements->documents = [
    'terms' => new AgreementDocument(1, 'terms', '1.0', '이용약관', '내용', true),
    'privacy' => new AgreementDocument(2, 'privacy', '1.0', '개인정보', '내용', true),
];

$known = $users->seed('known', 'known-password');
$loginHistory = new AuthMemoryLoginHistory();
$login = new LoginService($users, $profiles, $legacy, $loginHistory);
authSame(
    LoginOutcome::ProfileCompletionRequired,
    $login->authenticate('known', 'known-password', false)->outcome(),
    '프로필 없는 WordPress 사용자는 보완 화면으로 가야 합니다.'
);
authSame(0, $legacy->calls, 'WordPress 사용자가 있으면 Judge를 조회하면 안 됩니다.');
authSame(
    LoginOutcome::InvalidCredentials,
    $login->authenticate('known', 'wrong-password', false)->outcome(),
    '기존 WordPress 계정은 WordPress 비밀번호만 검증해야 합니다.'
);
authSame(0, $legacy->calls, 'WordPress 인증 실패 뒤 Judge fallback을 하면 안 됩니다.');

$profiles->create(new UserProfile(
    $known->userId(),
    null,
    null,
    AccessStatus::Active,
    false
));
authSame(
    LoginOutcome::Success,
    $login->authenticate('known', 'known-password', true, '203.0.113.10', 'TestAgent/1.0')->outcome(),
    '활성 WordPress 사용자는 로그인되어야 합니다.'
);
authSame($known->userId(), $users->currentUserId, '로그인 세션 사용자 번호가 다릅니다.');
authSame(1, $loginHistory->countForUser($known->userId()), '성공한 로그인은 누적 기록에 남아야 합니다.');
$loginRecords = $loginHistory->findForUser($known->userId(), 10);
authSame('203.0.113.10', $loginRecords[0]->ipAddress(), '로그인 기록에 IP 주소가 저장되어야 합니다.');
authSame('TestAgent/1.0', $loginRecords[0]->userAgent(), '로그인 기록에 브라우저 정보가 저장되어야 합니다.');

$pending = $users->seed('pending', 'pending-password');
$profiles->create(new UserProfile(
    $pending->userId(),
    null,
    null,
    AccessStatus::Pending,
    false
));
$sessionsBefore = count($users->sessions);
authSame(
    LoginOutcome::PendingApproval,
    $login->authenticate('pending', 'pending-password', false)->outcome(),
    '승인 대기 계정은 로그인되면 안 됩니다.'
);
authSame($sessionsBefore, count($users->sessions), '승인 대기 세션을 만들면 안 됩니다.');
authSame(0, $loginHistory->countForUser($pending->userId()), '승인 대기 계정은 로그인 기록이 남으면 안 됩니다.');

authSame(
    LoginOutcome::LegacyTransitionRequired,
    $login->authenticate('legacy', 'legacy-password', false)->outcome(),
    'WordPress 계정이 없을 때만 기존 Judge 전환을 제안해야 합니다.'
);
authSame(1, $legacy->calls, '기존 Judge 조회 횟수가 올바르지 않습니다.');

$manualPolicy = new AuthPolicyProvider(new RegistrationPolicy(
    true,
    ApprovalMode::Manual,
    'subscriber',
    ['terms', 'privacy'],
    false,
    14,
    24
));
$guardianConsent = new GuardianConsentVerifier($guardians);
$registration = new RegistrationService(
    $users,
    $profiles,
    $preferences,
    $agreements,
    $guardianConsent,
    $manualPolicy,
    new AccountInputPolicy(),
    $transaction
);
$manualResult = $registration->register(new RegistrationInput(
    'manual',
    'manual@example.test',
    'long-password',
    '수동 가입자',
    null,
    '2000-01-01',
    null,
    [1, 2],
    '127.0.0.1',
    'test'
));
authSame(AccessStatus::Pending, $manualResult->accessStatus(), '수동 가입은 승인 대기여야 합니다.');
authAssert($users->currentUserId !== $manualResult->userId(), '수동 가입 세션을 만들면 안 됩니다.');
authSame(
    [1, 2],
    $profiles->registrationRequirements($manualResult->userId())?->agreementDocumentIds(),
    '가입 당시 필수 문서 번호를 고정해야 합니다.'
);

$administrationTargets = new class implements UserManagementTargetPolicy {
    public bool $allowed = true;
    public function allows(int $actorUserId, UserIdentity $target, string $capability = 'oj_edit_users'): bool
    {
        return $this->allowed;
    }
};
$administrator = new UserAdministrationService($profiles, $agreements, $guardians, $users, $administrationTargets);
foreach ([AccessStatus::Active, AccessStatus::Suspended] as $unauthorizedStatus) {
    $approvalBypassBlocked = false;
    try {
        $administrator->changeAccessStatus($manualResult->userId(), $unauthorizedStatus, $known->userId());
    } catch (DomainException) {
        $approvalBypassBlocked = true;
    }
    authAssert($approvalBypassBlocked, '승인 대기 계정은 이용 상태 변경으로 승인 절차를 우회할 수 없어야 합니다.');
    authSame(AccessStatus::Pending, $profiles->find($manualResult->userId())?->accessStatus(), '승인 전에는 이용 중지를 경유해도 승인 대기 상태를 유지해야 합니다.');
}
$administrator->approve($manualResult->userId(), $known->userId());
authSame(
    AccessStatus::Active,
    $profiles->find($manualResult->userId())?->accessStatus(),
    '동의가 완료된 승인 대기 사용자를 활성화해야 합니다.'
);

$guardianPolicy = new AuthPolicyProvider(new RegistrationPolicy(
    true,
    ApprovalMode::Automatic,
    'subscriber',
    ['terms', 'privacy'],
    true,
    14,
    24
));
$guardianRegistration = new RegistrationService(
    $users,
    $profiles,
    $preferences,
    $agreements,
    $guardianConsent,
    $guardianPolicy,
    new AccountInputPolicy(),
    $transaction
);
$guardianBlocked = false;
try {
    $guardianRegistration->register(new RegistrationInput(
        'child-blocked',
        'child-blocked@example.test',
        'long-password',
        '미확인 가입자',
        null,
        '2015-03-20',
        null,
        [1, 2],
        null,
        null
    ));
} catch (DomainException) {
    $guardianBlocked = true;
}
authAssert($guardianBlocked, '기준 나이 미만은 법정대리인 확인 없이 가입할 수 없어야 합니다.');

$guardians->verified['child@example.test|12345678'] = 77;
$guardianResult = $guardianRegistration->register(new RegistrationInput(
    'child',
    'child@example.test',
    'long-password',
    '확인 가입자',
    null,
    '2015-03-20',
    '12345678',
    [1, 2],
    null,
    null
));
authSame(AccessStatus::Active, $guardianResult->accessStatus(), '자동 가입은 활성 상태여야 합니다.');
authAssert(
    $guardians->hasConsumedVerification($guardianResult->userId()),
    '법정대리인 확인을 생성한 사용자 번호에 연결해야 합니다.'
);
authAssert(
    $profiles->registrationRequirements($guardianResult->userId())
        ?->guardianConsentRequired() === true,
    '가입 당시 법정대리인 요구 여부를 고정해야 합니다.'
);

$transition = new LegacyAccountTransitionService(
    $legacy,
    $challenges,
    $users,
    $profiles,
    $preferences,
    $inputPolicy,
    $transaction
);
$legacyCandidate = $legacy->verify('legacy', 'legacy-password');
authAssert($legacyCandidate instanceof LegacyJudgeCandidate, '전환 후보가 필요합니다.');
$transitionContext = "127.0.0.1\0unit-test";
$transitionToken = $transition->begin($legacyCandidate, $transitionContext);
$transitionedUserId = $transition->complete(
    $transitionToken,
    'legacy',
    'legacy-password',
    $transitionContext,
    '',
    '',
    null,
    true
);
authAssert($users->findById($transitionedUserId) !== null, '전환된 WordPress 사용자가 없습니다.');
authSame(
    AccessStatus::Active,
    $profiles->find($transitionedUserId)?->accessStatus(),
    '전환 계정은 활성 상태여야 합니다.'
);
authAssert(
    $profiles->find($transitionedUserId)?->mustChangePassword() === true,
    '전환 계정은 새 WordPress 비밀번호를 설정해야 합니다.'
);
authAssert(
    ! $challenges->consume(
        $transitionToken,
        $legacyCandidate->fingerprint(),
        hash('sha256', $transitionContext)
    ),
    '전환 토큰은 재사용할 수 없어야 합니다.'
);

$roleDefinitions = new DefinitionRegistry('auth-test-roles', RoleDefinition::class);
$roleDefinitions->register(new RoleDefinition(
    'oj_user_manager',
    '사용자 관리자',
    ['oj_manage_users']
));
$roleDefinitions->register(new RoleDefinition(
    'oj_role_manager',
    '역할 관리자',
    ['oj_manage_roles']
));
$roleTarget = $users->seed(
    'role-target',
    'role-target-password',
    ['subscriber', 'oj_user_manager']
);
$roleService = new UserRoleService(
    $users,
    $roleDefinitions,
    new AuthRoleAssignmentPolicy()
);
$roleService->assign(900, $roleTarget->userId(), ['oj_role_manager']);
authSame(
    ['subscriber', 'oj_role_manager'],
    $users->findById($roleTarget->userId())?->roles(),
    'OJ 역할을 바꿀 때 WordPress 기본 역할을 보존해야 합니다.'
);
$roleAssignmentBlocked = false;
try {
    $roleService->assign(901, $roleTarget->userId(), ['oj_user_manager']);
} catch (DomainException) {
    $roleAssignmentBlocked = true;
}
authAssert($roleAssignmentBlocked, '대상 역할 정책을 통과하지 못하면 역할 변경을 거부해야 합니다.');

$actor = $users->seed('operator', 'operator-password');
$profiles->create(new UserProfile(
    $actor->userId(),
    null,
    null,
    AccessStatus::Active,
    false
));
$target = $users->seed('target', 'target-password');
$profiles->create(new UserProfile(
    $target->userId(),
    null,
    null,
    AccessStatus::Active,
    false
));
$protected = $users->seed('protected', 'protected-password', ['administrator']);
$profiles->create(new UserProfile(
    $protected->userId(),
    null,
    null,
    AccessStatus::Active,
    false
));
$auditLog = new AuthAuditLog();
$passwords = new PasswordResetService(
    $users,
    $profiles,
    new AuthResetPolicy(),
    $auditLog
);
$resetResults = $passwords->reset(
    $actor->userId(),
    [$actor->userId(), $target->userId(), $protected->userId()]
);
authSame('self_reset_forbidden', $resetResults[0]->resultCode(), '본인 초기화를 제외해야 합니다.');
authAssert($resetResults[1]->succeeded(), '허용된 사용자 비밀번호를 초기화해야 합니다.');
authSame(18, strlen((string) $resetResults[1]->temporaryPassword()), '임시 비밀번호 길이가 다릅니다.');
authAssert(
    $profiles->find($target->userId())?->mustChangePassword() === true,
    '초기화 사용자는 다음 로그인에서 비밀번호를 바꿔야 합니다.'
);
authSame('target_not_allowed', $resetResults[2]->resultCode(), '보호 역할을 제외해야 합니다.');
authAssert(
    in_array($target->userId(), $users->invalidatedSessions, true),
    '비밀번호 초기화 뒤 기존 세션을 폐기해야 합니다.'
);

$shortPasswordResult = $passwords->reset(
    $actor->userId(),
    [$target->userId()],
    null,
    8
);
authSame(
    8,
    strlen((string) $shortPasswordResult[0]->temporaryPassword()),
    '지정한 길이로 임시 비밀번호를 생성해야 합니다.'
);

$customPasswordResult = $passwords->reset(
    $actor->userId(),
    [$target->userId()],
    'my-custom-pass-1'
);
authSame(
    'my-custom-pass-1',
    $customPasswordResult[0]->temporaryPassword(),
    '직접 입력한 비밀번호를 그대로 사용해야 합니다.'
);

$emptyCustomPasswordRejected = false;
try {
    $passwords->reset($actor->userId(), [$target->userId()], '');
} catch (InvalidArgumentException) {
    $emptyCustomPasswordRejected = true;
}
authAssert($emptyCustomPasswordRejected, '빈 문자열을 직접 입력 비밀번호로 허용하면 안 됩니다.');

$weakPasswordRejected = false;
try {
    $passwords->reset($actor->userId(), [$target->userId()], 'lowercaseonly');
} catch (InvalidArgumentException) {
    $weakPasswordRejected = true;
}
authAssert(
    $weakPasswordRejected,
    '확인 체크 없이는 약한 비밀번호(문자 종류 1가지)로 초기화하면 안 됩니다.'
);
$shortWeakPasswordRejected = false;
try {
    $passwords->reset($actor->userId(), [$target->userId()], 'Ab1!');
} catch (InvalidArgumentException) {
    $shortWeakPasswordRejected = true;
}
authAssert(
    $shortWeakPasswordRejected,
    '확인 체크 없이는 약한 비밀번호(8자 미만)로 초기화하면 안 됩니다.'
);
$weakPasswordConfirmedResult = $passwords->reset(
    $actor->userId(),
    [$target->userId()],
    'lowercaseonly',
    18,
    true
);
authSame(
    'lowercaseonly',
    $weakPasswordConfirmedResult[0]->temporaryPassword(),
    '약한 비밀번호 확인 체크를 하면 그대로 사용할 수 있어야 합니다.'
);

$clampedShortLengthResult = $passwords->reset(
    $actor->userId(),
    [$target->userId()],
    null,
    1
);
authSame(
    6,
    strlen((string) $clampedShortLengthResult[0]->temporaryPassword()),
    '너무 짧은 길이는 최소 6자로 보정해야 합니다.'
);

$ojManager = $users->seed('oj-manager', 'manager-password', ['oj_contest_manager']);
$profiles->create(new UserProfile(
    $ojManager->userId(),
    null,
    null,
    AccessStatus::Active,
    false
));
$authorizedResults = $passwords->resetAuthorized(
    $actor->userId(),
    [$target->userId(), $protected->userId(), $ojManager->userId()]
);
authAssert($authorizedResults[0]['success'], '문맥에서 승인한 일반 계정은 초기화해야 합니다.');
authSame(
    'target_not_allowed',
    $authorizedResults[1]['reason'],
    '문맥 초기화에서도 WordPress 관리자 계정을 보호해야 합니다.'
);
authSame(
    'target_not_allowed',
    $authorizedResults[2]['reason'],
    '문맥 초기화에서도 OJ 운영 역할 계정을 보호해야 합니다.'
);
authSame(
    10,
    count($auditLog->entries),
    '비밀번호 초기화마다(전역 7건, 문맥 3건) 감사 기록을 남겨야 합니다.'
);
authAssert(
    $auditLog->entries[0]['action'] === 'password_reset'
        && $auditLog->entries[0]['scope'] === 'global'
        && $auditLog->entries[0]['result'] === 'self_reset_forbidden'
        && $auditLog->entries[0]['target_user_id'] === $actor->userId(),
    '감사 기록에는 행위·범위·결과·대상 user_id만 남아야 합니다.'
);
authAssert(
    ! str_contains(
        (string) json_encode($auditLog->entries),
        (string) $resetResults[1]->temporaryPassword()
    ),
    '감사 기록에는 임시 비밀번호 원문이 남으면 안 됩니다.'
);

$weakAuthorizedPasswordRejected = false;
try {
    $passwords->resetAuthorized(
        $actor->userId(),
        [$target->userId()],
        'authorized',
        'lowercaseonly'
    );
} catch (InvalidArgumentException) {
    $weakAuthorizedPasswordRejected = true;
}
authAssert(
    $weakAuthorizedPasswordRejected,
    '문맥 초기화도 확인 체크 없이는 약한 직접 입력 비밀번호를 거부해야 합니다.'
);
$confirmedAuthorizedResult = $passwords->resetAuthorized(
    $actor->userId(),
    [$target->userId()],
    'authorized',
    'lowercaseonly',
    true
);
authSame(
    'lowercaseonly',
    $confirmedAuthorizedResult[0]['temporary_password'],
    '문맥 초기화도 확인 체크를 하면 직접 입력한 비밀번호를 그대로 사용해야 합니다.'
);
$customAuthorizedResult = $passwords->resetAuthorized(
    $actor->userId(),
    [$target->userId()],
    'authorized',
    'Str0ng!Pass_word'
);
authSame(
    'Str0ng!Pass_word',
    $customAuthorizedResult[0]['temporary_password'],
    '문맥 초기화는 강한 직접 입력 비밀번호를 확인 체크 없이도 사용해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-04 Auth 단위 검사 통과: assertion %d개\n", $authAssertions)
);

final class AuthErasureDispatcher implements \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

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
        $this->calls[] = get_defined_vars();

        return new \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult(
            701,
            true
        );
    }
}

final class AuthErasureParticipant implements \CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant
{
    /** @var list<\CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker> */
    public array $blockers = [];
    public bool $fail = false;
    /** @var list<array{0: int, 1: string, 2: string}> */
    public array $calls = [];

    public function __construct(private readonly string $key)
    {
    }

    public function moduleKey(): string
    {
        return $this->key;
    }

    public function blockers(int $userId): array
    {
        unset($userId);

        return $this->blockers;
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        $this->calls[] = [$userId, $currentUserLogin, $anonymousUserLogin];
        if ($this->fail) {
            throw new RuntimeException('participant failed');
        }
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($userId, $anonymousUserLogin);

        return ! $this->fail;
    }
}

final class AuthErasureStore implements \CodingPanda\OjPlatform\Modules\Auth\Contract\AuthAccountErasureStore
{
    public bool $locked = false;
    public bool $erased = false;

    public function lock(int $userId): void
    {
        unset($userId);
        $this->locked = true;
    }

    public function erasePrivateData(int $userId): void
    {
        unset($userId);
        $this->erased = true;
    }

    public function verify(int $userId): bool
    {
        unset($userId);

        return $this->erased;
    }
}

final class AuthErasureIdentity implements \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentityAnonymizer
{
    public bool $anonymized = false;

    public function anonymousLogin(int $userId): string
    {
        return 'deleted_user_' . $userId;
    }

    public function anonymize(int $userId): void
    {
        unset($userId);
        $this->anonymized = true;
    }

    public function isAnonymized(int $userId): bool
    {
        unset($userId);

        return $this->anonymized;
    }
}

final class AuthErasureDeletedUsers implements \CodingPanda\OjPlatform\Modules\Auth\Contract\DeletedUserRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $completed = [];

    public function findPage(int $limit, int $offset = 0): array
    {
        unset($limit, $offset);

        return [];
    }

    public function hasCompleted(int $userId): bool
    {
        return isset($this->completed[$userId]);
    }

    public function recordCompleted(
        int $userId,
        int $actorUserId,
        int $jobId,
        string $reason
    ): void {
        $this->completed[$userId] = compact(
            'actorUserId',
            'jobId',
            'reason'
        );
    }
}

final class AuthErasureSteps implements \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function start(
        \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        string $inputHash,
        array $checkpoint = []
    ): void {
        $this->events[] = [
            'event' => 'start',
            'step_key' => $stepKey,
            'step_order' => $stepOrder,
            'input_hash' => $inputHash,
            'value' => $checkpoint,
        ];
    }

    public function complete(
        \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        $this->events[] = [
            'event' => 'complete',
            'step_key' => $stepKey,
            'value' => $validationResult,
        ];
    }

    public function fail(
        \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        $this->events[] = [
            'event' => 'fail',
            'step_key' => $stepKey,
            'value' => $validationResult,
        ];
    }

    public function compensate(
        \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem $claim,
        string $stepKey,
        bool $succeeded,
        array $compensationResult = []
    ): void {
        unset($claim, $stepKey, $succeeded, $compensationResult);
    }
}

final class AuthErasureErrorReporter implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

/**
 * @return array{
 *   0: \CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureParticipantRegistry,
 *   1: array<string, AuthErasureParticipant>
 * }
 */
function authErasureRegistry(): array
{
    $registry = new \CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureParticipantRegistry();
    $participants = [];
    foreach ([
        'problems',
        'submissions',
        'classes',
        'contests',
        'groups',
        'ai_feedback',
    ] as $key) {
        $participant = new AuthErasureParticipant($key);
        $registry->register($participant);
        $participants[$key] = $participant;
    }

    return [$registry, $participants];
}

function authErasureWorkItem(int $userId): \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem
{
    return new \CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem(
        new \CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem(
            701,
            801,
            'user_account_erasure',
            'user',
            (string) $userId,
            null,
            null,
            hash('sha256', 'account-erasure-test'),
            new \CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference(
                'draft:v1:12345678-1234-4123-8123-123456789abc'
            ),
            1,
            1,
            $userId,
            str_repeat('a', 64)
        ),
        ['user_id' => $userId]
    );
}

function authErasureFails(callable $operation): bool
{
    try {
        $operation();
    } catch (DomainException) {
        return true;
    }

    return false;
}

$erasureUser = $users->seed(
    'erasure-student',
    'correct-password',
    ['subscriber'],
    'erasure-student@example.test'
);
$erasureUserId = $erasureUser->userId();
$erasureJobs = new AuthErasureDispatcher();
$incompleteErasure = new \CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureService(
    new \CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureParticipantRegistry(),
    $users,
    $erasureJobs
);
authAssert(
    ! $incompleteErasure->impact($erasureUserId)->canRequest(),
    '필수 참여 모듈이 빠지면 계정 삭제를 차단해야 합니다.'
);

[$erasureRegistry, $erasureParticipants] = authErasureRegistry();
$erasureService = new \CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureService(
    $erasureRegistry,
    $users,
    $erasureJobs
);
authAssert(
    authErasureFails(static fn () => $erasureService->request(
        $erasureUserId,
        'correct-password',
        true,
        '삭제'
    )),
    '정확한 확인 문구가 없으면 계정 삭제를 거절해야 합니다.'
);
authAssert(
    authErasureFails(static fn () => $erasureService->request(
        $erasureUserId,
        'wrong-password',
        true,
        '계정 삭제'
    )),
    '현재 비밀번호가 틀리면 계정 삭제를 거절해야 합니다.'
);
$erasureParticipants['problems']->blockers = [
    new \CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker(
        'problems',
        'owned_problems',
        '소유 문제',
        2
    ),
];
authAssert(
    authErasureFails(static fn () => $erasureService->request(
        $erasureUserId,
        'correct-password',
        true,
        '계정 삭제'
    )),
    '소유 관계가 남아 있으면 계정 삭제를 거절해야 합니다.'
);
$erasureParticipants['problems']->blockers = [];

$erasureQueued = $erasureService->request(
    $erasureUserId,
    'correct-password',
    true,
    '계정 삭제'
);
authSame(701, $erasureQueued->jobId(), '계정 삭제 작업 번호가 반환되어야 합니다.');
authSame(1, count($erasureJobs->calls), '계정 삭제 작업을 한 번만 등록해야 합니다.');
$erasureCall = $erasureJobs->calls[0];
authSame(
    ['user_id' => $erasureUserId],
    $erasureCall['targets'][0]->payload(),
    '작업 대상에는 숫자 user_id만 저장해야 합니다.'
);
$erasureQueueEvidence = json_encode(
    $erasureCall,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
foreach ([
    'erasure-student',
    'erasure-student@example.test',
    'correct-password',
] as $privateValue) {
    authAssert(
        ! str_contains($erasureQueueEvidence, $privateValue),
        '작업 입력과 요약에 개인정보를 남기면 안 됩니다.'
    );
}

$erasureStore = new AuthErasureStore();
$erasureIdentity = new AuthErasureIdentity();
$erasureDeleted = new AuthErasureDeletedUsers();
$erasureSteps = new AuthErasureSteps();
$erasureErrors = new AuthErasureErrorReporter();
$erasureHandler = new \CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountErasureJobHandler(
    $erasureRegistry,
    $users,
    $erasureStore,
    $erasureIdentity,
    $erasureDeleted,
    $erasureSteps,
    $erasureErrors
);
$erasureHandled = $erasureHandler->handle(
    authErasureWorkItem($erasureUserId)
);
authSame('succeeded', $erasureHandled->outcome(), '계정 익명화 작업이 완료되어야 합니다.');
authAssert($erasureStore->locked, '계정을 먼저 잠가야 합니다.');
authAssert($erasureStore->erased, 'Auth 개인정보를 정리해야 합니다.');
authAssert($erasureIdentity->anonymized, 'WordPress 식별자를 익명화해야 합니다.');
authAssert(
    isset($erasureDeleted->completed[$erasureUserId]),
    '최소 완료 기록을 남겨야 합니다.'
);
authAssert(
    in_array($erasureUserId, $users->invalidatedSessions, true),
    '계정 잠금과 함께 로그인 세션을 무효화해야 합니다.'
);
$erasureStartedSteps = array_values(array_map(
    static fn (array $event): string => (string) $event['step_key'],
    array_filter(
        $erasureSteps->events,
        static fn (array $event): bool => $event['event'] === 'start'
    )
));
authSame(
    [
        'lock_account',
        'module_ai_feedback',
        'module_classes',
        'module_contests',
        'module_groups',
        'module_problems',
        'module_submissions',
        'auth_private_data',
        'wordpress_identity',
        'final_verification',
        'completion_record',
    ],
    $erasureStartedSteps,
    '계정 삭제 단계 순서를 고정해야 합니다.'
);
$erasureEvidence = json_encode(
    [$erasureHandled->toArray(), $erasureSteps->events],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
foreach ([
    'erasure-student',
    'erasure-student@example.test',
    'correct-password',
] as $privateValue) {
    authAssert(
        ! str_contains($erasureEvidence, $privateValue),
        '작업 결과와 단계 증적에 개인정보를 남기면 안 됩니다.'
    );
}

$erasureStepCount = count($erasureSteps->events);
$erasureRepeated = $erasureHandler->handle(
    authErasureWorkItem($erasureUserId)
);
authSame(
    'auth.erasure_already_completed',
    $erasureRepeated->resultCode(),
    '완료된 요청을 다시 실행해도 중복 정리하지 않아야 합니다.'
);
authSame(
    $erasureStepCount,
    count($erasureSteps->events),
    '완료된 요청은 단계 작업을 다시 시작하지 않아야 합니다.'
);
authSame([], $erasureErrors->errors, '정상 계정 익명화는 오류를 보고하면 안 됩니다.');

[$failureRegistry, $failureParticipants] = authErasureRegistry();
$failureParticipants['classes']->fail = true;
$failureStore = new AuthErasureStore();
$failureSteps = new AuthErasureSteps();
$failureErrors = new AuthErasureErrorReporter();
$failureHandler = new \CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountErasureJobHandler(
    $failureRegistry,
    $users,
    $failureStore,
    new AuthErasureIdentity(),
    new AuthErasureDeletedUsers(),
    $failureSteps,
    $failureErrors
);
$failureResult = $failureHandler->handle(
    authErasureWorkItem($erasureUserId)
);
authSame(
    'needs_attention',
    $failureResult->outcome(),
    '계정 잠금 뒤 실패하면 운영자 확인 상태로 전환해야 합니다.'
);
authSame(
    'auth.erasure_forward_attention',
    $failureResult->resultCode(),
    '잠금 뒤에는 되돌리지 않고 남은 단계를 재개하도록 표시해야 합니다.'
);
authAssert($failureStore->locked, '오류가 나도 이미 수행한 계정 잠금을 유지해야 합니다.');
authAssert(
    in_array(
        [
            'event' => 'fail',
            'step_key' => 'module_classes',
            'value' => ['result_code' => 'step_failed'],
        ],
        $failureSteps->events,
        true
    ),
    '실패 단계에는 개인정보 없는 결과 코드만 기록해야 합니다.'
);
authSame(1, count($failureErrors->errors), '예기치 않은 계정 익명화 실패를 중앙 오류 기록에 남겨야 합니다.');

$logoutSessions = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentSessionTerminator {
    public bool $terminated = false;

    public function terminate(): void
    {
        $this->terminated = true;
    }
};
$logoutGuard = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard {
    public bool $valid = true;
    /** @var list<string> */
    public array $verifiedActions = [];

    public function verifyNonce(
        string $actionKey,
        \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request
    ): void {
        unset($request);
        $this->verifiedActions[] = $actionKey;
        if (! $this->valid) {
            throw new DomainException('요청 확인 정보가 만료되었습니다.');
        }
    }
    public function actor(
        string $actionKey,
        \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,
        string $capability
    ): UserIdentity {
        unset($actionKey, $request, $capability);
        throw new RuntimeException('사용하지 않는 테스트 경로입니다.');
    }
    public function authenticatedActor(
        string $actionKey,
        \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request
    ): UserIdentity {
        unset($actionKey, $request);
        throw new RuntimeException('사용하지 않는 테스트 경로입니다.');
    }
    public function throttle(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): void {
        unset($scope, $identifier, $limit, $windowSeconds);
    }
};
$logoutResponses = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse {
    public ?\CodingPanda\OjPlatform\Ui\Message\UiMessage $message = null;

    public function redirect(
        string $path,
        \CodingPanda\OjPlatform\Ui\Message\UiMessage $message,
        int $userId = 0
    ): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse {
        unset($userId);
        $this->message = $message;

        return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::redirect($path);
    }
};
$logoutErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
};
$logoutHandler = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\LogoutActionHandler(
    $logoutSessions,
    $logoutGuard,
    $logoutResponses,
    $logoutErrors
);
$logoutResponse = $logoutHandler->handle(
    new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
        'POST',
        '/wp-admin/admin-post.php',
        [],
        ['action' => 'oj_logout', '_oj_nonce' => 'valid']
    )
);
authAssert($logoutSessions->terminated, '플러그인 로그아웃은 현재 세션을 종료해야 합니다.');
authSame(['oj_logout'], $logoutGuard->verifiedActions, '로그아웃 전용 nonce를 검증해야 합니다.');
authSame('/login', $logoutResponse->headers()['Location'] ?? null, '로그아웃 후 플러그인 로그인 화면으로 이동해야 합니다.');
authSame('auth.logout_complete', $logoutResponses->message?->code(), '로그아웃 완료 안내를 제공해야 합니다.');

$logoutSessions->terminated = false;
$logoutGuard->valid = false;
$invalidLogout = $logoutHandler->handle(
    new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
        'POST',
        '/wp-admin/admin-post.php',
        [],
        ['action' => 'oj_logout', '_oj_nonce' => 'expired']
    )
);
authAssert(! $logoutSessions->terminated, 'nonce 검증 실패 시 세션을 종료하면 안 됩니다.');
authSame('/', $invalidLogout->headers()['Location'] ?? null, '만료된 로그아웃 요청은 사용자 화면으로 돌아가야 합니다.');
authSame('auth.logout_validation_failed', $logoutResponses->message?->code(), '만료된 로그아웃 요청을 안내해야 합니다.');
authSame([], $logoutErrors->errors, '예상 가능한 nonce 실패를 시스템 오류로 기록하면 안 됩니다.');

$GLOBALS['auth_wp_logout_calls'] = 0;
if (! function_exists('wp_logout')) {
    function wp_logout(): void
    {
        ++$GLOBALS['auth_wp_logout_calls'];
    }
}
(new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressCurrentSessionTerminator())->terminate();
authSame(1, $GLOBALS['auth_wp_logout_calls'], 'WordPress 현재 세션 종료 API를 정확히 한 번 호출해야 합니다.');

$profileUser = $users->seed(
    'profile-user',
    'profile-password',
    ['subscriber'],
    'profile-user@example.test'
);
$profileUserId = $profileUser->userId();
$profiles->create(new UserProfile(
    $profileUserId,
    '기존 소속',
    '2000-01-01',
    AccessStatus::Active,
    false,
    '기존 소개'
));
$profiles->saveRegistrationRequirements(
    $profileUserId,
    new RegistrationRequirements([1, 2], false)
);
$profileGuardians = new AuthMemoryGuardians();
$profileTransaction = new AuthMemoryTransaction();
$profileEditPolicy = new class implements UserManagementTargetPolicy {
    public bool $allowed = true;
    /** @var list<array{actor:int,target:int}> */
    public array $calls = [];

    public function allows(int $actorUserId, UserIdentity $target, string $capability = 'oj_edit_users'): bool
    {
        $this->calls[] = ['actor' => $actorUserId, 'target' => $target->userId()];

        return $this->allowed;
    }
};
$profileService = new UserAccountService(
    $users,
    $users,
    $profiles,
    $preferences,
    new AuthPolicyProvider(new RegistrationPolicy(
        true,
        ApprovalMode::Automatic,
        'subscriber',
        ['terms', 'privacy'],
        true,
        14,
        24
    )),
    new GuardianConsentVerifier($profileGuardians),
    new AccountInputPolicy(),
    $profileTransaction,
    $profileEditPolicy
);
$minorBirthDate = (new DateTimeImmutable(
    'today',
    new DateTimeZone('UTC')
))->modify('-10 years')->format('Y-m-d');
$profileInput = static fn (?string $guardianCode): UserSettingsInput => new UserSettingsInput(
    'profile-new@example.test',
    '프로필 사용자',
    '알고리즘반',
    $minorBirthDate,
    '매일 한 문제씩 풉니다.',
    $guardianCode,
    'hidden',
    'members',
    6,
    'dracula',
    'blockly',
    18,
    "print('start')\n",
    true
);
$minorUpdateBlocked = false;
try {
    $profileService->updateProfile($profileUserId, $profileInput(null));
} catch (DomainException) {
    $minorUpdateBlocked = true;
}
authAssert($minorUpdateBlocked, '성인 생년을 미성년 생년으로 바꿀 때 동의 코드를 요구해야 합니다.');
authSame(0, $profileTransaction->begun, '동의 검증 실패 전에 저장 트랜잭션을 시작하면 안 됩니다.');
authSame(
    '2000-01-01',
    $profiles->find($profileUserId)?->birthDate(),
    '동의 검증 실패 시 기존 생년을 유지해야 합니다.'
);

$profileGuardians->verified['profile-new@example.test|87654321'] = 91;
$updatedProfile = $profileService->updateProfile(
    $profileUserId,
    $profileInput('87654321')
);
$updatedIdentity = $users->findById($profileUserId);
$updatedPreference = $preferences->find($profileUserId);
authSame('profile-new@example.test', $updatedIdentity?->email(), '프로필 이메일을 WordPress 계정에 저장해야 합니다.');
authSame('프로필 사용자', $updatedIdentity?->displayName(), '표시 이름을 WordPress 계정에 저장해야 합니다.');
authSame($profileUser->nickname(), $updatedIdentity?->nickname(), '본인 프로필 수정은 관리용 학생 이름을 유지해야 합니다.');
authSame($minorBirthDate, $updatedProfile->birthDate(), '검증된 미성년 생년을 저장해야 합니다.');
authSame('알고리즘반', $updatedProfile->affiliation(), '소속을 프로필 저장소에 저장해야 합니다.');
authSame('매일 한 문제씩 풉니다.', $updatedProfile->bio(), '소개를 프로필 저장소에 저장해야 합니다.');
authSame('hidden', $updatedPreference?->profileVisibility(), '프로필 공개 범위를 저장해야 합니다.');
authSame('members', $updatedPreference?->activityVisibility(), '학습 활동 공개 범위를 저장해야 합니다.');
authSame(6, $updatedPreference?->defaultLanguage(), '기본 제출 언어를 저장해야 합니다.');
authSame('dracula', $updatedPreference?->editorTheme(), 'Ace 편집기 테마를 저장해야 합니다.');
authSame('blockly', $updatedPreference?->defaultEditor(), '기본 편집기를 저장해야 합니다.');
authSame(18, $updatedPreference?->editorFontSize(), '편집기 글자 크기를 저장해야 합니다.');
authSame(true, $updatedPreference?->inlineSubmissionPanel(), '문제 화면 제출 패널 사용 여부를 저장해야 합니다.');
authSame("print('start')\n", $updatedPreference?->codeTemplate(), '기본 코드 템플릿을 저장해야 합니다.');
authSame(
    [1, 2],
    $profiles->registrationRequirements($profileUserId)?->agreementDocumentIds(),
    '생년 변경 시 가입 당시 동의 문서 번호를 보존해야 합니다.'
);
authAssert(
    $profiles->registrationRequirements($profileUserId)?->guardianConsentRequired() === true,
    '미성년 생년으로 바꾼 뒤 동의 요구 상태를 기록해야 합니다.'
);
authAssert(
    $profileGuardians->hasConsumedVerification($profileUserId),
    '확인 코드를 사용자 번호에 한 번 연결해야 합니다.'
);
authSame(1, $profileTransaction->begun, '프로필 저장 트랜잭션을 한 번 시작해야 합니다.');
authSame(1, $profileTransaction->committed, '프로필 저장 트랜잭션을 완료해야 합니다.');
authSame(0, $profileTransaction->rolledBack, '정상 저장에서 롤백하면 안 됩니다.');

$preferencesBeforeManagedUpdate = $preferences->find($profileUserId);
$managedProfile = $profileService->updateManagedProfile(
    $profileUserId,
    'managed-profile@example.test',
    '관리 수정 사용자',
    '김학습',
    '관리 수정 소속',
    $minorBirthDate,
    '관리자가 수정한 소개',
    $known->userId()
);
$managedIdentity = $users->findById($profileUserId);
authSame('managed-profile@example.test', $managedIdentity?->email(), '관리자 수정은 WordPress 이메일을 저장해야 합니다.');
authSame('관리 수정 사용자', $managedIdentity?->displayName(), '관리자 수정은 표시 이름을 저장해야 합니다.');
authSame('김학습', $managedIdentity?->nickname(), '관리자 수정은 학생 이름을 저장해야 합니다.');
authSame('관리 수정 소속', $managedProfile->affiliation(), '관리자 수정은 소속을 저장해야 합니다.');
authSame('관리자가 수정한 소개', $managedProfile->bio(), '관리자 수정은 소개를 저장해야 합니다.');
authSame(
    $preferencesBeforeManagedUpdate,
    $preferences->find($profileUserId),
    '관리자 프로필 수정은 본인의 공개·편집기 환경설정을 변경하면 안 됩니다.'
);
authSame(2, $profileTransaction->begun, '관리자 프로필 수정도 트랜잭션에서 실행해야 합니다.');
authSame(2, $profileTransaction->committed, '관리자 프로필 수정 트랜잭션을 완료해야 합니다.');
authSame(0, $profileTransaction->rolledBack, '정상 관리자 수정에서 롤백하면 안 됩니다.');

$protectedProfileUser = $users->seed('protected-profile-admin', 'protected-password', ['administrator']);
$profiles->create(new UserProfile($protectedProfileUser->userId(), null, '2000-01-01', AccessStatus::Active, false));
$profileEditPolicy->allowed = false;
$protectedProfileBlocked = false;
try {
    $profileService->updateManagedProfile(
        $protectedProfileUser->userId(),
        'attacker-inbox@example.test',
        '보호된 관리자',
        '보호된 이름',
        null,
        '2000-01-01',
        null,
        $known->userId()
    );
} catch (DomainException) {
    $protectedProfileBlocked = true;
}
authAssert($protectedProfileBlocked, '대상 관리 권한이 없으면 관리자 복구 이메일 변경을 거부해야 합니다.');
authSame($protectedProfileUser->email(), $users->findById($protectedProfileUser->userId())?->email(), '거부된 관리자의 복구 이메일을 유지해야 합니다.');
authSame(2, $profileTransaction->begun, '대상 권한 검사 실패 전에 WordPress 저장을 시작하면 안 됩니다.');
$profileEditPolicy->allowed = true;

$adminEditSource = file_get_contents(
    dirname(__DIR__, 2) . '/modules/Auth/Presentation/Console/UserEditPageHandler.php'
);
authAssert(is_string($adminEditSource), '관리자 사용자 수정 화면 원본을 읽어야 합니다.');
authAssert(str_contains($adminEditSource, "'학생 이름 (관리용)', 'nickname'"), '관리자 사용자 수정 화면에 학생 이름 입력이 있어야 합니다.');
authAssert(str_contains($adminEditSource, "textarea('소개', 'bio'"), '관리자 사용자 수정 화면에 소개 입력이 있어야 합니다.');
$adminUpdateSource = file_get_contents(
    dirname(__DIR__, 2) . '/modules/Auth/Presentation/Action/UpdateUserActionHandler.php'
);
authAssert(
    is_string($adminUpdateSource) && str_contains($adminUpdateSource, 'updateManagedProfile('),
    '관리자 사용자 수정 액션은 환경설정과 분리된 관리자 프로필 서비스를 사용해야 합니다.'
);
$submissionPreference = (new \CodingPanda\OjPlatform\Modules\Auth\Application\UserSubmissionPreferenceService(
    $preferences
))->forUser($profileUserId);
authSame(6, $submissionPreference->defaultLanguage(), '제출 공개 계약에 기본 언어를 제공해야 합니다.');
authSame('dracula', $submissionPreference->editorTheme(), '제출 공개 계약에 Ace 테마를 제공해야 합니다.');
authSame("print('start')\n", $submissionPreference->codeTemplate(), '제출 공개 계약에 코드 템플릿을 제공해야 합니다.');
authSame('blockly', $submissionPreference->defaultEditor(), '제출 공개 계약에 기본 편집기를 제공해야 합니다.');
authSame(18, $submissionPreference->editorFontSize(), '제출 공개 계약에 편집기 글자 크기를 제공해야 합니다.');
authSame(true, $submissionPreference->inlineSubmissionPanel(), '제출 공개 계약에 문제 화면 제출 패널 사용 여부를 제공해야 합니다.');
$defaultSubmissionPreference = (new \CodingPanda\OjPlatform\Modules\Auth\Application\UserSubmissionPreferenceService(
    $preferences
))->forUser(9999);
authSame(null, $defaultSubmissionPreference->defaultLanguage(), '환경설정이 없는 사용자는 문제의 기본 언어를 사용해야 합니다.');
authSame('code', $defaultSubmissionPreference->defaultEditor(), '환경설정이 없는 사용자는 코드 편집기를 사용해야 합니다.');
authSame('chrome', $defaultSubmissionPreference->editorTheme(), '환경설정이 없는 사용자는 Chrome 테마를 사용해야 합니다.');
authSame(false, $defaultSubmissionPreference->inlineSubmissionPanel(), '환경설정이 없는 사용자는 문제 화면 제출 패널을 기본으로 끄고 있어야 합니다.');

$adminDetailSource = file_get_contents(
    dirname(__DIR__, 2) . '/modules/Auth/Presentation/Console/UserDetailPageHandler.php'
);
authAssert(
    is_string($adminDetailSource)
        && str_contains($adminDetailSource, "AccessStatus::Active => '이용 가능'")
        && str_contains($adminDetailSource, '$role->label()')
        && ! str_contains($adminDetailSource, 'accessStatus()->value'),
    '관리자 사용자 상세는 상태와 역할의 내부 키 대신 한글 표시명을 사용해야 합니다.'
);
authAssert(
    is_string($adminDetailSource) && str_contains($adminDetailSource, '/login-history">로그인 기록</a>'),
    '관리자 사용자 상세에는 로그인 기록으로 이동하는 링크가 있어야 합니다.'
);

$loginHistoryPageSource = file_get_contents(
    dirname(__DIR__, 2) . '/modules/Auth/Presentation/Console/UserLoginHistoryPageHandler.php'
);
authAssert(
    is_string($loginHistoryPageSource)
        && str_contains($loginHistoryPageSource, 'ipAddress()')
        && str_contains($loginHistoryPageSource, 'this->dates->format(')
        && str_contains($loginHistoryPageSource, 'this->pagination->links('),
    '로그인 기록 화면은 IP 주소·현지 시간대 변환·페이지 번호 이동을 모두 제공해야 합니다.'
);
$failingPreferences = new class ($updatedPreference) implements UserPreferenceRepository {
    public function __construct(private readonly ?UserPreferences $current)
    {
    }

    public function find(int $userId): ?UserPreferences
    {
        unset($userId);

        return $this->current;
    }

    public function save(UserPreferences $preferences): void
    {
        unset($preferences);
        throw new RuntimeException('preference write failed');
    }
};
$failureTransaction = new AuthMemoryTransaction();
$failingProfileService = new UserAccountService(
    $users,
    $users,
    $profiles,
    $failingPreferences,
    new AuthPolicyProvider(new RegistrationPolicy(
        true,
        ApprovalMode::Automatic,
        'subscriber',
        [],
        true,
        14,
        24
    )),
    new GuardianConsentVerifier($profileGuardians),
    new AccountInputPolicy(),
    $failureTransaction,
    $profileEditPolicy
);
$profileWriteFailed = false;
try {
    $failingProfileService->updateProfile($profileUserId, $profileInput(null));
} catch (RuntimeException) {
    $profileWriteFailed = true;
}
authAssert($profileWriteFailed, '프로필 일부 저장 실패를 성공으로 처리하면 안 됩니다.');
authSame(1, $failureTransaction->begun, '실패한 프로필 저장도 트랜잭션 안에서 수행해야 합니다.');
authSame(0, $failureTransaction->committed, '일부 저장 실패 시 커밋하면 안 됩니다.');
authSame(1, $failureTransaction->rolledBack, '일부 저장 실패 시 전체 저장을 롤백해야 합니다.');

$profileSectionErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
};
$profileSections = new \CodingPanda\OjPlatform\Modules\Auth\Application\PublicProfileSectionRegistry(
    $profileSectionErrors
);
$profileSections->register(new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\PublicProfileSectionProvider {
    public function key(): string { return 'broken_activity'; }
    public function priority(): int { return 10; }

    public function provide(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit
    ): ?\CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection {
        unset($viewerUserId, $targetUserId, $targetUserLogin, $limit);
        throw new RuntimeException('profile provider failed');
    }
});
$profileSections->register(new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\PublicProfileSectionProvider {
    public function key(): string { return 'learning_activity'; }
    public function priority(): int { return 20; }

    public function provide(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit
    ): ?\CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection {
        unset($viewerUserId, $targetUserId, $targetUserLogin, $limit);

        return new \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection(
            $this->key(),
            '학습 활동',
            '제출 결과 기준',
            [new \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileMetric(
                'solved',
                '해결한 문제',
                '7개'
            )],
            [new \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileActivity(
                '문제 1001 · 출력',
                '난이도 1',
                '2026-08-10 12:00:00',
                '/problems/1001'
            )]
        );
    }
});
$loadedProfileSections = $profileSections->sections(100, 101, 'profile-user', 5);
authSame(1, count($loadedProfileSections), '실패한 프로필 Provider를 제외하고 정상 섹션을 표시해야 합니다.');
authSame('learning_activity', $loadedProfileSections[0]->key(), '프로필 섹션 키를 보존해야 합니다.');
authSame('7개', $loadedProfileSections[0]->metrics()[0]->value(), '프로필 지표 값을 보존해야 합니다.');
authSame(1, count($profileSectionErrors->errors), '프로필 Provider 실패를 중앙 오류 기록에 남겨야 합니다.');

$legacyPreferenceDatabase = new class {
    public function prepare(string $query, mixed ...$values): string
    {
        unset($values);

        return $query;
    }

    public function get_row(string $query, string $format): array
    {
        unset($query, $format);

        return [
            'user_id' => 77,
            'profile_visibility' => 'public',
            'ranking_visibility' => 'invalid',
            'activity_visibility' => 'hidden',
            'default_language' => 6,
            'editor_theme' => 'ace/theme/cobalt',
            'code_template' => "print('legacy')",
            'default_editor' => 'unknown',
            'editor_font_size' => 99,
        ];
    }
};
$recoveredPreferences = (new WordPressUserPreferenceRepository(
    $legacyPreferenceDatabase
))->find(77);
authSame('members', $recoveredPreferences?->profileVisibility(), '이전 public 공개값은 로그인 사용자 공개로 복구해야 합니다.');
authSame('members', $recoveredPreferences?->rankingVisibility(), '알 수 없는 랭킹 공개값은 기본 공개 범위로 복구해야 합니다.');
authSame('hidden', $recoveredPreferences?->activityVisibility(), '유효한 비공개 값은 보존해야 합니다.');
authSame('cobalt', $recoveredPreferences?->editorTheme(), '이전 Ace 테마 식별자를 보존해야 합니다.');
authSame('code', $recoveredPreferences?->defaultEditor(), '알 수 없는 편집기는 코드 편집기로 복구해야 합니다.');
authSame(16, $recoveredPreferences?->editorFontSize(), '알 수 없는 글자 크기는 16px로 복구해야 합니다.');

$siteLanguages = new class implements \CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageAvailability {
    public function allowedForSite(): array
    {
        return [0 => 'C', 6 => 'Python 3'];
    }

    public function isAllowedForSite(int $languageId): bool
    {
        return isset($this->allowedForSite()[$languageId]);
    }
};
$settingsActionReflection = new ReflectionClass(
    \CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveUserSettingsActionHandler::class
);
$settingsAction = $settingsActionReflection->newInstanceWithoutConstructor();
$settingsActionReflection->getProperty('languages')->setValue(
    $settingsAction,
    $siteLanguages
);
$forgedNameInput = $settingsActionReflection->getMethod('settingsInput')->invoke(
    $settingsAction,
    new HttpRequest('POST', '/user-settings', [], [
        'email' => 'managed-profile@example.test',
        'display_name' => '새 공개 이름',
        'nickname' => '위조한 학생 이름',
        'student_name' => '다른 위조 이름',
        'birth_date' => $minorBirthDate,
    ])
);
authAssert(! property_exists($forgedNameInput, 'nickname'), '본인 설정 입력 계약에는 학생 이름이 없어야 합니다.');
$profileService->updateProfile($profileUserId, $forgedNameInput);
authSame('새 공개 이름', $users->findById($profileUserId)?->displayName(), '본인은 표시 이름을 변경할 수 있어야 합니다.');
authSame('김학습', $users->findById($profileUserId)?->nickname(), '위조한 본인 설정 요청도 관리자 학생 이름을 바꾸면 안 됩니다.');
$completionUser = $users->createUser('complete-names', 'complete@example.test', 'password', '초기 이름', 'subscriber');
$users->updateProfileIdentity($completionUser->userId(), $completionUser->email(), '초기 이름', '관리용 이름');
$completionInput = $settingsActionReflection->getMethod('settingsInput')->invoke(
    $settingsAction,
    new HttpRequest('POST', '/user-settings', [], [
        'email' => 'complete@example.test',
        'display_name' => '보완한 표시 이름',
        'nickname' => '위조 이름',
        'birth_date' => '2000-01-01',
    ])
);
$profileService->completeProfile($completionUser->userId(), $completionInput);
authSame('관리용 이름', $users->findById($completionUser->userId())?->nickname(), '프로필 보완 경로도 관리용 학생 이름을 유지해야 합니다.');
$defaultLanguageMethod = $settingsActionReflection->getMethod('defaultLanguage');
authSame(
    0,
    $defaultLanguageMethod->invoke(
        $settingsAction,
        new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
            'POST',
            '/user-settings',
            [],
            ['default_language' => '0']
        )
    ),
    '관리자가 허용한 C 언어 번호 0을 기본 제출 언어로 저장할 수 있어야 합니다.'
);
authSame(
    null,
    $defaultLanguageMethod->invoke(
        $settingsAction,
        new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
            'POST',
            '/user-settings',
            [],
            ['default_language' => '']
        )
    ),
    '빈 기본 제출 언어는 문제 설정 사용으로 저장해야 합니다.'
);
$blockedLanguage = false;
try {
    $defaultLanguageMethod->invoke(
        $settingsAction,
        new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
            'POST',
            '/user-settings',
            [],
            ['default_language' => '1']
        )
    );
} catch (DomainException) {
    $blockedLanguage = true;
}
authAssert($blockedLanguage, '관리자가 허용하지 않은 언어를 기본값으로 저장하면 안 됩니다.');

$settingsPageReflection = new ReflectionClass(
    \CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserSettingsPageHandler::class
);
$settingsPage = $settingsPageReflection->newInstanceWithoutConstructor();
$settingsPageReflection->getProperty('languages')->setValue(
    $settingsPage,
    $siteLanguages
);
$languageOptions = $settingsPageReflection->getMethod('languageSelect')->invoke($settingsPage, null);
authAssert(str_contains($languageOptions, '<option value="" selected>문제 설정 사용</option>'), '문제 설정 사용은 빈 값이어야 합니다.');
authAssert(str_contains($languageOptions, '<option value="0">C</option>'), '허용된 C 언어는 별도 값 0으로 표시해야 합니다.');
authAssert(! str_contains($languageOptions, '<option value="1">'), '관리자가 막은 C++ 언어는 계정 설정에 표시하면 안 됩니다.');
$blockedSelection = $settingsPageReflection->getMethod('languageSelect')->invoke($settingsPage, 1);
authAssert(str_contains($blockedSelection, '<option value="" selected>'), '저장된 기본 언어가 차단되면 문제 설정 사용으로 되돌려야 합니다.');
$themeOptions = $settingsPageReflection->getMethod('themeSelect')->invoke($settingsPage, 'dracula');
authAssert(str_contains($themeOptions, '<optgroup label="밝은 테마">'), 'Ace 밝은 테마 그룹을 표시해야 합니다.');
authAssert(str_contains($themeOptions, '<optgroup label="어두운 테마">'), 'Ace 어두운 테마 그룹을 표시해야 합니다.');
authAssert(str_contains($themeOptions, '<option value="dracula" selected>Dracula</option>'), '저장된 Ace 테마를 선택해야 합니다.');
authAssert(
    str_contains($themeOptions, 'data-ojp-theme-select'),
    '테마 선택 상자에 실시간 미리보기 스크립트가 찾을 수 있는 표식이 있어야 합니다.'
);

$settingsPageReflection->getProperty('aceAssets')->setValue(
    $settingsPage,
    new class implements \CodingPanda\OjPlatform\Ui\Editor\AceCoreAssetLocator {
        public function urls(): array
        {
            return ['core' => 'https://example.test/ace/ace.js', 'base' => 'https://example.test/ace/'];
        }
    }
);
$codeTemplateField = $settingsPageReflection->getMethod('codeTemplateField')->invoke(
    $settingsPage,
    new \CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences(
        1,
        'members',
        'members',
        'members',
        null,
        'dracula',
        "print('hi')\n",
        'code',
        16
    )
);
authAssert(
    str_contains($codeTemplateField, 'data-oj-ace-editor'),
    '기본 코드 템플릿은 실시간 테마 미리보기를 위해 Ace 편집기로 감싸야 합니다.'
);
authAssert(
    str_contains($codeTemplateField, 'data-oj-ace-theme="dracula"'),
    '기본 코드 템플릿의 초기 테마는 저장된 편집기 테마와 같아야 합니다.'
);
authAssert(
    str_contains($codeTemplateField, 'https://example.test/ace/ace.js'),
    '기본 코드 템플릿은 실제 Ace 코어 스크립트 주소를 전달해야 합니다.'
);
authAssert(
    str_contains($codeTemplateField, "print(&#039;hi&#039;)"),
    '기본 코드 템플릿의 실제 내용을 편집기 안 textarea에 그대로 유지해야 합니다.'
);

$settingsFormSource = file_get_contents(
    dirname(__DIR__, 2) . '/modules/Auth/Presentation/Route/UserSettingsPageHandler.php'
);
authAssert(! str_contains($settingsFormSource, 'nickname'), '사용자 설정에는 학생 이름 조회나 입력이 없어야 합니다.');
authAssert(
    is_string($settingsFormSource) && str_contains($settingsFormSource, 'data-ojp-theme-preview'),
    '계정 설정 폼에 실시간 테마 미리보기 스크립트가 찾을 수 있는 표식이 있어야 합니다.'
);
authSame(
    'monokai',
    \CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog::normalize('dark'),
    '기존 dark 설정은 Monokai 테마로 호환해야 합니다.'
);
// PHP casts numeric-string array keys ('14', '16', '18') to int at array
// creation time, so select()'s $options iteration must not assume string
// keys stay strings — regression for a live TypeError in escape(string).
$fontSizeSelect = $settingsPageReflection->getMethod('select')->invoke(
    $settingsPage,
    '편집기 글자 크기',
    'editor_font_size',
    ['14' => '14px', '16' => '16px', '18' => '18px'],
    '16'
);
authAssert(str_contains($fontSizeSelect, '<option value="14">14px</option>'), '숫자 문자열 key 옵션이 올바르게 렌더링돼야 합니다.');
authAssert(str_contains($fontSizeSelect, '<option value="16" selected>16px</option>'), '선택된 숫자 문자열 key 옵션이 selected로 표시돼야 합니다.');

$profileUsers = new AuthMemoryUsers();
$profileOwner = $profileUsers->seed('profile-owner', 'password-for-profile');
$profileViewer = $profileUsers->seed('profile-viewer', 'password-for-viewer');
$profileUsers->identities[$profileOwner->userId()] = new UserIdentity(
    $profileOwner->userId(),
    $profileOwner->userLogin(),
    $profileOwner->email(),
    '프로필 사용자',
    ['subscriber'],
    '학습자',
    '2026-01-02 03:04:05'
);
$profileStore = new AuthMemoryProfiles();
$profileStore->profiles[$profileOwner->userId()] = new UserProfile(
    $profileOwner->userId(),
    '알고리즘반',
    null,
    AccessStatus::Active,
    false,
    '매일 한 문제씩 풉니다.'
);
$profileStore->profiles[$profileViewer->userId()] = new UserProfile(
    $profileViewer->userId(),
    null,
    null,
    AccessStatus::Active,
    false
);
$profilePreferences = new AuthMemoryPreferences();
$profilePreferences->items[$profileOwner->userId()] = new UserPreferences(
    $profileOwner->userId(),
    'members',
    'members',
    'hidden'
);
$profilePageResponder = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder {
    public function guest(
        string $title,
        string $content,
        \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,
        int $status = 200
    ): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse {
        unset($title, $request);

        return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($content, $status);
    }

    public function authenticated(
        string $title,
        string $content,
        \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,
        int $status = 200
    ): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse {
        unset($title, $request);

        return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($content, $status);
    }
};
$profileSectionErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public function report(Throwable $error): void { unset($error); }
};
$profileSectionRegistry = new \CodingPanda\OjPlatform\Modules\Auth\Application\PublicProfileSectionRegistry(
    $profileSectionErrors
);
$profileSectionProvider = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\PublicProfileSectionProvider {
    public int $calls = 0;
    public function key(): string { return 'learning_activity'; }
    public function priority(): int { return 100; }
    public function provide(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit
    ): \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection {
        unset($viewerUserId, $targetUserId, $targetUserLogin, $limit);
        ++$this->calls;

        return new \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection(
            $this->key(),
            '학습 활동',
            '최근 제출 결과 기준',
            [new \CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileMetric(
                'solved',
                '해결한 문제',
                '12개'
            )],
            []
        );
    }
};
$profileSectionRegistry->register($profileSectionProvider);
$profileRoutes = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider {
    public function path(string $routeName, array $parameters = []): string
    {
        return $routeName === 'auth.settings'
            ? '/user-settings'
            : '/users/' . (string) ($parameters['user_id'] ?? '');
    }
};
$profileAuthorization = new class implements \CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization {
    public function can(
        int $userId,
        string $capability,
        ?\CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext $context = null
    ): bool {
        unset($userId, $capability, $context);
        return false;
    }
    public function canAny(int $userId, array $capabilities): bool
    {
        unset($userId, $capabilities);
        return false;
    }
    public function isSuperAdmin(int $userId): bool { unset($userId); return false; }
};
$profileFeatures = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags {
    public function isEnabled(string $featureKey): bool
    {
        unset($featureKey);
        return false;
    }
};
$profileHandler = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserProfilePageHandler(
    $profilePageResponder,
    new \CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService($profileUsers, $profileStore),
    new \CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService(
        $profileStore,
        $profileUsers,
        new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSearchProvider {
            public function searchUserIds(string $keyword, int $limit = 500): array
            {
                return [];
            }
        }
    ),
    $profilePreferences,
    $profileSectionRegistry,
    $profileRoutes,
    $profileAuthorization,
    $profileFeatures,
    new \CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter()
);
$profileRouteDefinition = \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray([
    'name' => 'auth.profile',
    'owner_module' => 'auth',
    'feature_key' => 'auth',
    'path' => '/users/{user_id}',
    'area' => 'public',
    'methods' => ['GET'],
    'authentication' => 'authenticated',
    'response_type' => 'html',
]);
$profileRouteMatch = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(
    $profileRouteDefinition,
    ['user_id' => (string) $profileOwner->userId()]
);
$profileRequest = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest(
    'GET',
    '/users/' . $profileOwner->userId()
);
$profileUsers->currentUserId = $profileOwner->userId();
$ownerProfileResponse = $profileHandler->handle($profileRouteMatch, $profileRequest);
authSame(200, $ownerProfileResponse->status(), '본인은 자신의 비공개 활동이 있는 프로필을 열 수 있어야 합니다.');
authAssert(! str_contains($ownerProfileResponse->body(), '<dt>별명</dt>'), '공개 프로필에는 별명 항목이 없어야 합니다.');
authAssert(! str_contains($ownerProfileResponse->body(), '학습자'), '관리용 학생 이름은 본인 공개 프로필에서도 제외해야 합니다.');
authAssert(str_contains($ownerProfileResponse->body(), '학습 활동 비공개'), '본인에게 활동 비공개 상태를 안내해야 합니다.');
authAssert(str_contains($ownerProfileResponse->body(), '해결한 문제'), '본인은 비공개 활동 통계를 계속 확인할 수 있어야 합니다.');
authSame(1, $profileSectionProvider->calls, '본인 프로필은 학습 활동 Provider를 한 번 호출해야 합니다.');

$profileUsers->currentUserId = $profileViewer->userId();
$viewerProfileResponse = $profileHandler->handle($profileRouteMatch, $profileRequest);
authSame(200, $viewerProfileResponse->status(), '공개 기본 프로필은 다른 로그인 사용자도 열 수 있어야 합니다.');
authAssert(! str_contains($viewerProfileResponse->body(), '학습자'), '다른 사용자의 공개 프로필에 학생 이름을 노출하면 안 됩니다.');
authAssert(! str_contains($viewerProfileResponse->body(), '해결한 문제'), '활동 비공개 시 타인에게 학습 통계를 노출하면 안 됩니다.');
authSame(1, $profileSectionProvider->calls, '활동 비공개 시 타인 요청에서 Provider를 호출하면 안 됩니다.');

$profilePreferences->items[$profileOwner->userId()] = new UserPreferences(
    $profileOwner->userId(),
    'hidden',
    'members',
    'hidden'
);
$hiddenProfileResponse = $profileHandler->handle($profileRouteMatch, $profileRequest);
authSame(403, $hiddenProfileResponse->status(), '전체 프로필 비공개 시 타인 접근을 거부해야 합니다.');
authAssert(! str_contains($hiddenProfileResponse->body(), '알고리즘반'), '비공개 프로필의 기본 정보를 응답에 포함하면 안 됩니다.');

$identityNames = new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserIdentityAnonymizer(new stdClass());
authSame('deleted_41', $identityNames->anonymousLogin(41), '일반 익명 로그인은 읽을 수 있어야 합니다.');
authSame(
    20,
    strlen($identityNames->anonymousLogin(PHP_INT_MAX)),
    '익명 로그인은 HUSTOJ solution.user_id의 20자 한도를 넘으면 안 됩니다.'
);

$searchProfiles = new AuthMemoryProfiles();
$searchUsers = new AuthMemoryUsers();
$searchAlice = $searchUsers->seed('alice', 'pw', ['subscriber'], 'alice@example.com');
$searchBob = $searchUsers->seed('bob', 'pw', ['subscriber'], 'bob@example.com');
$searchCarol = $searchUsers->seed('carol', 'pw', ['subscriber'], 'carol@example.com');
$searchProfiles->profiles[$searchAlice->userId()] = new UserProfile(
    $searchAlice->userId(),
    null,
    null,
    AccessStatus::Active,
    false,
    null
);
$searchProfiles->profiles[$searchBob->userId()] = new UserProfile(
    $searchBob->userId(),
    null,
    null,
    AccessStatus::Pending,
    false,
    null
);
$searchProfiles->profiles[$searchCarol->userId()] = new UserProfile(
    $searchCarol->userId(),
    null,
    null,
    AccessStatus::Active,
    false,
    null
);
$matchingSearch = new class ($searchAlice->userId(), $searchCarol->userId()) implements UserSearchProvider {
    /** @var list<int> */
    private array $matches;

    public function __construct(int $first, int $second)
    {
        $this->matches = [$first, $second];
    }

    public function searchUserIds(string $keyword, int $limit = 500): array
    {
        return $this->matches;
    }
};
$searchDirectory = new UserDirectoryService($searchProfiles, $searchUsers, $matchingSearch);

authSame(
    3,
    count($searchDirectory->page(null, 50, 0)),
    '검색어가 없으면 전체 사용자를 반환해야 합니다.'
);
authSame(
    2,
    count($searchDirectory->page(null, 50, 0, 'a')),
    '검색어가 있으면 검색 결과에 해당하는 사용자만 반환해야 합니다.'
);
authSame(
    2,
    $searchDirectory->count(AccessStatus::Active, 'a'),
    '검색어와 이용 상태 필터를 함께 적용한 개수가 일치해야 합니다.'
);
authSame(
    0,
    $searchDirectory->count(AccessStatus::Pending, 'a'),
    '검색 결과 중 조건에 맞는 상태가 없으면 개수는 0이어야 합니다.'
);

$emptySearch = new class implements UserSearchProvider {
    public function searchUserIds(string $keyword, int $limit = 500): array
    {
        return [];
    }
};
$emptyDirectory = new UserDirectoryService($searchProfiles, $searchUsers, $emptySearch);
authSame(
    [],
    $emptyDirectory->page(null, 50, 0, '없음'),
    '검색 결과가 0건이면 빈 배열을 반환해야 하고 프로필 조회를 시도하면 안 됩니다.'
);
authSame(
    0,
    $emptyDirectory->count(null, '없음'),
    '검색 결과가 0건이면 전체 개수도 0이어야 합니다.'
);

$natLimiter = new class implements AttemptLimiter {
    public array $counts = [];
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        $key = $scope . ':' . $identifier;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        return $this->counts[$key] <= $limit;
    }
    public function resetAll(): int { $count = count($this->counts); $this->counts = []; return $count; }
};
$natSecurity = new class implements ActionSecurity {
    public bool $valid = true;
    public function hiddenFields(string $actionKey): string { return ''; }
    public function verify(string $actionKey, HttpRequest $request): bool { return $this->valid; }
};
$natAccounts = new class implements CurrentAccountProvider {
    public function current(): CurrentAccount { return new CurrentAccount(null, null); }
};
$natPermissions = new class implements Authorization {
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return false; }
    public function canAny(int $userId, array $capabilities): bool { return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$natRedirects = new class implements SafeRedirect {
    public function afterLogin(string $candidate): string { return '/'; }
};
$natFlash = new class implements FlashStore {
    public ?UiMessage $message = null;
    public function issue(UiMessage $message, int $userId = 0): string { $this->message = $message; return 'test'; }
    public function consume(string $token, int $userId = 0): ?UiMessage { return $this->message; }
};
$natHandler = new LoginActionHandler(
    $login, $transition, $natRedirects,
    new ActionGuard($natSecurity, $natAccounts, $natPermissions, $natLimiter),
    new ActionResponder($natFlash), $logoutErrors
);
foreach (['nat-one', 'nat-two'] as $userLogin) {
    $identity = $users->seed($userLogin, 'correct-password');
    $profiles->create(new UserProfile($identity->userId(), null, null, AccessStatus::Active, false));
}
$natRequest = static fn (string $name, string $password): HttpRequest => new HttpRequest(
    'POST', '/oj-action', [], ['user_login' => $name, 'password' => $password], ['x-oj-client-ip' => '203.0.113.10']
);
for ($attempt = 0; $attempt < 10; ++$attempt) {
    $natHandler->handle($natRequest('nat-one', 'incorrect'));
}
$natHandler->handle($natRequest('nat-one', 'correct-password'));
authAssert(str_contains($natFlash->message?->body() ?? '', '요청이 너무 많습니다'), '같은 아이디의 한도 초과를 차단해야 합니다.');
$otherStudent = $natHandler->handle($natRequest('nat-two', 'correct-password'));
authSame('/', $otherStudent->headers()['Location'] ?? null, '동일 IP에서 다른 학생이 실패해도 첫 로그인은 성공해야 합니다.');
$natHandler->handle($natRequest(' NAT-ONE ', 'incorrect'));
authAssert(! isset($natLimiter->counts['login:203.0.113.10| nat-one ']), '아이디 앞뒤 공백으로 제한을 우회하면 안 됩니다.');
$beforeInvalidNonce = $natLimiter->counts;
$natSecurity->valid = false;
$natHandler->handle($natRequest('nat-two', 'correct-password'));
authSame($beforeInvalidNonce, $natLimiter->counts, '만료된 nonce 요청이 로그인 횟수를 차감하면 안 됩니다.');

fwrite(
    STDOUT,
    sprintf("R-11 계정 삭제 단위 검사 통과: assertion %d개\n", $authAssertions)
);

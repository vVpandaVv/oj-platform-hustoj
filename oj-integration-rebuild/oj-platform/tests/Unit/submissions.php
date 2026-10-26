<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Application\AiFeedbackSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeCompletionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeOrderingSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\FullCodeSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\PerformanceModePolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionAiFeedbackPolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionContextProviderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\RuntimeErrorParser;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SettingsSubmissionLanguageAvailability;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionOperationService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionProjectionService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceComposer;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceModeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourcePolicyValidator;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionVisibilityPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmitSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStaffScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionMode;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionPollResult;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionReservation;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliSubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\SubmissionPlatformDatabase;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressSubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressSubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionEventText;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$submissionAssertions = 0;

function submissionAssert(bool $condition, string $message): void
{
    global $submissionAssertions;
    ++$submissionAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class SubmissionTestStaffScope implements SubmissionStaffScope
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly int $classId
    ) {
    }

    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool {
        return $actorUserId === $this->actorUserId
            && $submission->classId() === $this->classId;
    }
}

function submissionSame(mixed $expected, mixed $actual, string $message): void
{
    submissionAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function submissionThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (DomainException $error) {
        submissionAssert(
            str_contains($error->getMessage(), $messagePart),
            '예외 문구가 다릅니다: ' . $error->getMessage()
        );

        return;
    }
    throw new RuntimeException('예상한 DomainException이 발생하지 않았습니다.');
}

function submissionRuntimeThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        submissionAssert(
            str_contains($error->getMessage(), $messagePart),
            '예외 문구가 다릅니다: ' . $error->getMessage()
        );

        return;
    }
    throw new RuntimeException('예상한 RuntimeException이 발생하지 않았습니다.');
}
final class SubmissionTestSettings implements SettingsManager
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(1, $this->values[$key] ?? $default);
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($expectedRevision);
        $this->values[$key] = $value;

        return new SettingsSnapshot(2, $value);
    }
}

final class SubmissionTestProblems implements SubmissionProblemProvider
{
    public function __construct(private readonly SubmissionCodePolicy $policy)
    {
    }

    public function findVisible(
        int $userId,
        int $problemId,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): ?SubmissionProblem
    {
        unset($userId);

        return $problemId === $this->policy->problemId()
            ? new SubmissionProblem($problemId, $this->policy->title())
            : null;
    }

    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): array
    {
        $visible = [];
        foreach ($problemIds as $problemId) {
            $problem = $this->findVisible($userId, (int) $problemId);
            if ($problem !== null) {
                $visible[$problem->problemId()] = $problem;
            }
        }

        return $visible;
    }
    public function listVisible(string $keyword = '', int $limit = 100): array
    {
        unset($keyword, $limit);

        return [new SubmissionProblem(
            $this->policy->problemId(),
            $this->policy->title()
        )];
    }

    public function codePolicy(
        int $userId,
        int $problemId,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): SubmissionCodePolicy {
        unset($userId);
        if ($problemId !== $this->policy->problemId()) {
            throw new DomainException('문제를 찾을 수 없습니다.');
        }

        return $this->policy;
    }
}

final class SubmissionTestGateway implements SubmissionGateway
{
    /** @var list<string> */
    public array $calls = [];
    public bool $sourceMatch = true;
    public bool $publishFails = false;
    public bool $accepted = false;
    public ?int $latestTimestamp = null;
    public string $userSource = '';
    public string $judgeSource = '';
    /** @var array<int, SubmissionSummary> */
    public array $summaries = [];
    public ?SubmissionDetail $detail = null;

    public function search(SubmissionSearch $search): array
    {
        $items = array_values(array_filter(
            $this->summaries,
            static function (SubmissionSummary $summary) use ($search): bool {
                return ($search->userLogin() === null
                        || $summary->userLogin() === $search->userLogin())
                    && ($search->problemId() === null
                        || $summary->problemId() === $search->problemId())
                    && ($search->resultCode() === null
                        || $summary->resultCode() === $search->resultCode())
                    && ($search->valid() === null
                        || $summary->isValid() === $search->valid())
                    && ($search->userLogins() === []
                        || in_array(
                            $summary->userLogin(),
                            $search->userLogins(),
                            true
                        ))
                    && ($search->problemIds() === []
                        || in_array(
                            $summary->problemId(),
                            $search->problemIds(),
                            true
                        ))
                    && ($search->submittedFrom() === null
                        || $summary->submittedAt() >= $search->submittedFrom())
                    && ($search->submittedUntil() === null
                        || $summary->submittedAt() <= $search->submittedUntil())
                    && ($search->contestId() === null
                        || $summary->contestId() === $search->contestId());
            }
        ));
        usort(
            $items,
            static fn (SubmissionSummary $left, SubmissionSummary $right): int =>
                $right->submissionId()->value()
                    <=> $left->submissionId()->value()
        );

        return array_slice($items, $search->offset(), $search->limit());
    }

    public function count(SubmissionSearch $search): int
    {
        unset($search);

        return 0;
    }

    public function findSummary(SubmissionId $submissionId): ?SubmissionSummary
    {
        return $this->summaries[$submissionId->value()] ?? null;
    }

    public function findSummaries(array $submissionIds): array
    {
        $found = [];
        foreach ($submissionIds as $submissionId) {
            $summary = $this->findSummary($submissionId);
            if ($summary !== null) {
                $found[$submissionId->value()] = $summary;
            }
        }

        return $found;
    }

    public function findDetail(SubmissionId $submissionId): ?SubmissionDetail
    {
        unset($submissionId);

        return $this->detail;
    }

    public function createPrepared(PreparedSubmission $submission): SubmissionId
    {
        $this->calls[] = 'create:' . $submission->userLogin();

        return new SubmissionId(501);
    }

    public function storeSources(
        SubmissionId $submissionId,
        string $userSource,
        string $judgeSource
    ): void {
        $this->calls[] = 'store:' . $submissionId->value();
        $this->userSource = $userSource;
        $this->judgeSource = $judgeSource;
    }

    public function sourcesMatch(
        SubmissionId $submissionId,
        string $userSha256,
        string $judgeSha256,
        int $userBytes,
        int $judgeBytes
    ): bool {
        $this->calls[] = 'verify:' . $submissionId->value();

        return $this->sourceMatch
            && hash_equals($userSha256, hash('sha256', $this->userSource))
            && hash_equals($judgeSha256, hash('sha256', $this->judgeSource))
            && $userBytes === strlen($this->userSource)
            && $judgeBytes === strlen($this->judgeSource);
    }

    public function publish(SubmissionId $submissionId): void
    {
        $this->calls[] = 'publish:' . $submissionId->value();
        if ($this->publishFails) {
            throw new RuntimeException('publish failed');
        }
    }

    public function discardPrepared(SubmissionId $submissionId): void
    {
        $this->calls[] = 'discard:' . $submissionId->value();
    }

    public function markRecoveryRequired(SubmissionId $submissionId): void
    {
        $this->calls[] = 'recovery:' . $submissionId->value();
    }

    public function latestSubmissionTimestamp(string $userLogin): ?int
    {
        unset($userLogin);

        return $this->latestTimestamp;
    }

    public function hasAccepted(
        string $userLogin,
        int $problemId,
        SubmissionContext $context
    ): bool {
        unset($userLogin, $problemId, $context);

        return $this->accepted;
    }

    public function rejudge(SubmissionId $submissionId): int
    {
        $this->calls[] = 'rejudge:' . $submissionId->value();

        return 4;
    }

    public function setValid(SubmissionId $submissionId, bool $valid): bool
    {
        $this->calls[] = ($valid ? 'restore:' : 'invalidate:')
            . $submissionId->value();

        return true;
    }
}

final class SubmissionTestContexts implements SubmissionContextStore
{
    /** @var array<int, SubmissionContext> */
    public array $contexts = [];

    public function save(
        SubmissionId $submissionId,
        SubmissionContext $context
    ): void {
        if ($context->classId() === null && $context->groupId() === null) {
            $this->remove($submissionId);

            return;
        }
        $this->contexts[$submissionId->value()] = $context;
    }

    public function remove(SubmissionId $submissionId): void
    {
        unset($this->contexts[$submissionId->value()]);
    }

    public function findMany(array $submissionIds): array
    {
        $found = [];
        foreach ($submissionIds as $submissionId) {
            if (isset($this->contexts[$submissionId->value()])) {
                $found[$submissionId->value()] =
                    $this->contexts[$submissionId->value()];
            }
        }

        return $found;
    }

    public function submissionIds(
        ?int $classId,
        ?int $groupId,
        int $limit,
        int $offset
    ): array {
        $ids = [];
        foreach ($this->contexts as $submissionId => $context) {
            if (
                ($classId !== null && $context->classId() === $classId)
                || ($groupId !== null && $context->groupId() === $groupId)
            ) {
                $ids[] = $submissionId;
            }
        }
        rsort($ids, SORT_NUMERIC);

        return array_slice($ids, $offset, $limit);
    }
}
final class SubmissionFailingContexts implements SubmissionContextStore
{
    public int $removals = 0;

    public function save(
        SubmissionId $submissionId,
        SubmissionContext $context
    ): void {
        unset($submissionId, $context);
        throw new RuntimeException('제출 문맥 저장 충돌');
    }

    public function remove(SubmissionId $submissionId): void
    {
        unset($submissionId);
        ++$this->removals;
    }

    public function findMany(array $submissionIds): array
    {
        unset($submissionIds);

        return [];
    }

    public function submissionIds(
        ?int $classId,
        ?int $groupId,
        int $limit,
        int $offset
    ): array {
        unset($classId, $groupId, $limit, $offset);

        return [];
    }
}
final class SubmissionContextWpdb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $insert_id = 0;
    public int $updates = 0;

    public function prepare(string $sql, mixed ...$values): string
    {
        foreach ($values as $value) {
            $replacement = is_int($value)
                ? (string) $value
                : "'" . addslashes((string) $value) . "'";
            $sql = (string) preg_replace('/%[dfs]/', $replacement, $sql, 1);
        }

        return $sql;
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $format): ?array
    {
        unset($format);
        if (preg_match('/submission_id = (\d+)/', $sql, $matches) !== 1) {
            return null;
        }

        return $this->rows[(int) $matches[1]] ?? null;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data, mixed $formats = null): int|false
    {
        unset($formats);
        if ($table !== 'submission_contexts') {
            return false;
        }
        $submissionId = (int) ($data['submission_id'] ?? 0);
        if ($submissionId < 1 || isset($this->rows[$submissionId])) {
            return false;
        }
        $this->rows[$submissionId] = $data;
        $this->insert_id = $submissionId;

        return 1;
    }

    public function update(mixed ...$arguments): int
    {
        unset($arguments);
        ++$this->updates;

        return 1;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int|false
    {
        if ($table !== 'submission_contexts') {
            return false;
        }
        $submissionId = (int) ($where['submission_id'] ?? 0);
        if (! isset($this->rows[$submissionId])) {
            return 0;
        }
        unset($this->rows[$submissionId]);

        return 1;
    }
}
final class SubmissionTestEvents implements SubmissionEventStore
{
    /** @var list<string> */
    public array $calls = [];
    public ?SubmissionId $existing = null;
    public bool $completeFails = false;

    public function reserve(
        string $requestKey,
        int $actorUserId,
        array $metadata
    ): SubmissionReservation {
        unset($requestKey, $actorUserId, $metadata);
        $this->calls[] = 'reserve';

        return new SubmissionReservation(
            91,
            $this->existing === null,
            $this->existing,
            false
        );
    }

    public function complete(
        int $eventId,
        SubmissionId $submissionId,
        array $metadata
    ): void {
        unset($metadata);
        $this->calls[] = 'complete:' . $eventId . ':' . $submissionId->value();
        if ($this->completeFails) {
            throw new RuntimeException('complete failed');
        }
    }

    public function fail(
        int $eventId,
        string $eventType,
        string $message,
        array $metadata
    ): void {
        unset($message, $metadata);
        $this->calls[] = 'fail:' . $eventId . ':' . $eventType;
    }

    public function record(
        string $eventType,
        int $actorUserId,
        ?SubmissionId $submissionId,
        ?string $requestKey,
        string $message,
        array $metadata = []
    ): void {
        unset($actorUserId, $requestKey, $message, $metadata);
        $this->calls[] = 'record:' . $eventType . ':'
            . ($submissionId?->value() ?? 0);
    }

    public function recent(int $limit = 100): array
    {
        unset($limit);

        return [];
    }
}

final class SubmissionTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-28 12:00:00 UTC');
    }
}

final class SubmissionTestErrors implements ErrorReporter
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

final class SubmissionTestAuthorization implements Authorization
{
    /** @param list<string> $allowed */
    public function __construct(private readonly array $allowed)
    {
    }

    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($userId, $context);

        return in_array($capability, $this->allowed, true);
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
    }
}

final class SubmissionTestPerformanceMode implements PerformanceModePolicy
{
    public function __construct(private readonly bool $restricted)
    {
    }

    public function restrictsOwnSubmissionDetails(int $userId): bool
    {
        unset($userId);

        return $this->restricted;
    }
}

final class SubmissionTestUsers implements WordPressUserGateway
{
    /** @var array<string, UserIdentity> */
    public array $byLogin = [];

    public function currentUser(): ?UserIdentity
    {
        return null;
    }

    public function findById(int $userId): ?UserIdentity
    {
        foreach ($this->byLogin as $user) {
            if ($user->userId() === $userId) {
                return $user;
            }
        }

        return null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        return $this->byLogin[$userLogin] ?? null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        unset($email);

        return null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        unset($userLogin, $password);

        return null;
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
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }

    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
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
        unset($userId);
    }

    /**
     * @param list<int> $userIds
     * @return list<UserIdentity>
     */
    public function findMany(array $userIds): array
    {
        unset($userIds);

        return [];
    }
}

function submissionPolicy(
    string $entryMode = 'full_code',
    array $forbidden = ['eval('],
    string $starter = '',
    ?string $completionSchema = null,
    ?string $completionHash = null
): SubmissionCodePolicy {
    return new SubmissionCodePolicy(
        1000,
        '합계 구하기',
        $entryMode,
        $forbidden,
        "import sys\r\n",
        $starter,
        "\nprint('done')\r\n",
        null,
        $completionSchema,
        $completionHash,
        'problem-code-policy-v1:' . str_repeat('a', 64)
    );
}

$eventText = new SubmissionEventText(new JudgeResultCatalog());
submissionSame(
    '채점 대기열 등록',
    $eventText->eventLabel('submission_published'),
    '내부 제출 이벤트 키는 한글 표시명으로 변환해야 합니다.'
);
submissionSame(
    '기타 제출 처리',
    $eventText->eventLabel('future_internal_event'),
    '알 수 없는 이벤트 키를 화면에 그대로 노출하면 안 됩니다.'
);
submissionSame(
    '제출을 채점 대기열에 등록했습니다.',
    $eventText->eventDescription('submission_published'),
    '기존에 저장된 내부 처리 문구 대신 사용자 설명을 표시해야 합니다.'
);
submissionSame(
    '제출 처리 내용을 확인했습니다.',
    $eventText->eventDescription('future_internal_event'),
    '알 수 없는 이벤트의 저장 문구를 그대로 노출하면 안 됩니다.'
);
submissionSame(
    '선택한 제출의 재채점을 요청했습니다. 사유: 채점기 변경 반영',
    $eventText->eventDescription(
        'submission_rejudge_requested',
        '채점기 변경 반영'
    ),
    '관리자가 입력한 제출 처리 사유는 감사 화면에 유지해야 합니다.'
);
submissionSame(
    '제출을 채점 대기열에 등록했습니다.',
    $eventText->eventDescription(
        'submission_published',
        '내부 저장 처리 문구'
    ),
    '시스템 이벤트의 저장 문구는 화면에 그대로 노출하면 안 됩니다.'
);
submissionSame(
    '작성 방식',
    $eventText->metadataLabel('submission_mode'),
    '감사 메타데이터 키는 한글 표시명으로 변환해야 합니다.'
);
submissionSame(
    null,
    $eventText->metadataLabel('internal_secret_key'),
    '허용하지 않은 메타데이터 키는 화면에 노출하면 안 됩니다.'
);
submissionSame(
    '처리 결과',
    $eventText->metadataLabel('changed'),
    '제출 상태 변경 결과는 한글 표시명으로 변환해야 합니다.'
);
submissionSame(
    '변경 없음',
    $eventText->metadataValue('changed', false),
    '제출 상태 변경 여부는 사용자 문구로 변환해야 합니다.'
);
submissionSame(
    '빈칸 완성',
    $eventText->metadataValue('submission_mode', 'code_completion'),
    '제출 작성 방식 값도 사용자 문구로 변환해야 합니다.'
);
submissionSame(
    '맞았습니다',
    $eventText->metadataValue('previous_result', 4),
    '이전 결과 코드는 HUSTOJ 결과 문구로 변환해야 합니다.'
);
submissionSame(
    '없음',
    $eventText->metadataValue('contest_id', 0),
    '문맥이 없는 번호는 0 대신 없음으로 표시해야 합니다.'
);
$languages = new SubmissionLanguageCatalog();
submissionSame('c_cpp', $languages->editorMode(0), 'C는 Ace C/C++ 문법 모드를 사용해야 합니다.');
submissionSame('python', $languages->editorMode(6), 'Python 3은 Ace Python 문법 모드를 사용해야 합니다.');
submissionSame('text', $languages->editorMode(24), 'Cangjie는 지원 모드가 생기기 전까지 일반 텍스트로 편집해야 합니다.');
$siteLanguageAvailability = new SettingsSubmissionLanguageAvailability(
    new SubmissionSettingsProvider(new SubmissionTestSettings([
        'submission' => ['disabled_language_mask' => (1 << 1) | (1 << 3)],
    ])),
    $languages
);
submissionAssert($siteLanguageAvailability->isAllowedForSite(0), '관리자가 허용한 C는 기본 언어 후보여야 합니다.');
submissionAssert(! $siteLanguageAvailability->isAllowedForSite(1), '관리자가 막은 C++는 기본 언어 후보에서 제외해야 합니다.');
submissionAssert(! isset($siteLanguageAvailability->allowedForSite()[3]), '관리자가 막은 Java를 허용 언어 목록에 표시하면 안 됩니다.');
$runtimeErrors = new RuntimeErrorParser();
$pythonReport = $runtimeErrors->parse(<<<'ERROR'
Traceback (most recent call last):
  File "/srv/judge/Main.py", line 3, in <module>
    print(1 / 0)
ZeroDivisionError: division by zero
ERROR);
submissionAssert($pythonReport->recognized(), 'Python 실행 오류를 인식해야 합니다.');
submissionSame(
    'Python 호출',
    $pythonReport->diagnostics()[0]->type(),
    'Python traceback 호출 위치를 구분해야 합니다.'
);
submissionSame(
    ['/srv/judge/Main.py', 3, '<module>', 'print(1 / 0)'],
    [
        $pythonReport->diagnostics()[0]->file(),
        $pythonReport->diagnostics()[0]->line(),
        $pythonReport->diagnostics()[0]->function(),
        $pythonReport->diagnostics()[0]->message(),
    ],
    'Python 파일·줄·함수·소스 문맥을 구조화해야 합니다.'
);
submissionSame(
    ['ZeroDivisionError', 'division by zero'],
    [
        $pythonReport->diagnostics()[1]->type(),
        $pythonReport->diagnostics()[1]->message(),
    ],
    'Python 예외 유형과 메시지를 분리해야 합니다.'
);
$javaReport = $runtimeErrors->parse(
    "Exception in thread \"main\" java.lang.ArithmeticException: / by zero\n"
        . "    at Main.main(Main.java:5)"
);
submissionSame(
    ['java.lang.ArithmeticException', 'Java 호출', 'Main.java', 5],
    [
        $javaReport->diagnostics()[0]->type(),
        $javaReport->diagnostics()[1]->type(),
        $javaReport->diagnostics()[1]->file(),
        $javaReport->diagnostics()[1]->line(),
    ],
    'Java 예외와 호출 위치를 구조화해야 합니다.'
);
$sanitizerReport = $runtimeErrors->parse(
    "==7==ERROR: AddressSanitizer: heap-buffer-overflow on address 0x123\n"
        . "    #0 0x123 in main /judge/Main.cpp:8:15\n"
        . "SUMMARY: AddressSanitizer: heap-buffer-overflow /judge/Main.cpp:8"
);
submissionSame(
    ['AddressSanitizer: heap-buffer-overflow', '/judge/Main.cpp', 8],
    [
        $sanitizerReport->diagnostics()[0]->type(),
        $sanitizerReport->diagnostics()[1]->file(),
        $sanitizerReport->diagnostics()[1]->line(),
    ],
    'AddressSanitizer 오류 유형과 호출 위치를 구조화해야 합니다.'
);
$fallbackReport = $runtimeErrors->parse(
    "알 수 없는 실행기 메시지\n추가 정보가 없습니다"
);
submissionAssert(
    ! $fallbackReport->recognized()
        && count($fallbackReport->diagnostics()) === 2
        && $fallbackReport->diagnostics()[0]->kind() === '진단',
    '알 수 없는 형식도 원문 덩어리 대신 행 단위 진단으로 정리해야 합니다.'
);
$sharedMask = $languages->disabledMaskForEnabled([0, 6, 6, 999]);
submissionSame(
    [0, 6],
    $languages->enabledIds($sharedMask),
    '제출 설정과 문제 설정은 동일한 언어 마스크 변환을 사용해야 합니다.'
);
$emptyLanguageSelectionRejected = false;
try {
    $languages->disabledMaskForEnabled([]);
} catch (DomainException) {
    $emptyLanguageSelectionRejected = true;
}
submissionAssert(
    $emptyLanguageSelectionRejected,
    '허용 언어를 하나도 선택하지 않은 설정은 거부해야 합니다.'
);
$eventOwnerWpdb = new class {
    public int $insert_id = 91;
    public bool $hideExistingOnce = false;
    public ?array $existing = null;
    public array $writes = [];
    public function prepare(string $sql, mixed ...$values): string
    {
        unset($values);
        return $sql;
    }
    public function get_row(string $sql, string $format): ?array
    {
        unset($format);
        if ($this->hideExistingOnce) {
            $this->hideExistingOnce = false;
            return null;
        }
        $row = $this->existing;
        if ($row !== null && ! str_contains($sql, '`actor_user_id`')) {
            unset($row['actor_user_id']);
        }
        return $row;
    }
    public function insert(string $table, array $data, mixed $formats = null): int|false
    {
        unset($table, $formats);
        if ($this->existing !== null) { return false; }
        $this->writes[] = $data;
        $this->existing = ['id' => $this->insert_id] + $data;
        return 1;
    }
};
$ownedEvents = new WordPressSubmissionEventStore(new SubmissionPlatformDatabase($eventOwnerWpdb));
$eventRequestKey = str_repeat('a', 64);
$firstReservation = $ownedEvents->reserve($eventRequestKey, 17, ['problem_id' => 1024]);
submissionAssert($firstReservation->isNew(), '본인의 새 제출 요청 키는 정상 예약해야 합니다.');
$eventOwnerWpdb->existing = [
    'id' => 91,
    'submission_id' => 777,
    'event_type' => 'submission_published',
    'actor_user_id' => 17,
];
$ownReservation = $ownedEvents->reserve($eventRequestKey, 17, ['problem_id' => 1024]);
submissionSame(777, $ownReservation->submissionId()?->value(), '본인의 중복 제출 요청은 기존 제출을 재사용해야 합니다.');
submissionThrows(
    static fn (): mixed => $ownedEvents->reserve($eventRequestKey, 23, ['problem_id' => 1024]),
    '다른 사용자의 제출 요청'
);
submissionSame(1, count($eventOwnerWpdb->writes), '타인의 제출 키 재사용은 새 이벤트를 쓰거나 기존 이벤트를 변경하면 안 됩니다.');
$eventOwnerWpdb->hideExistingOnce = true;
$ownRacedReservation = $ownedEvents->reserve($eventRequestKey, 17, ['problem_id' => 1024]);
submissionSame(777, $ownRacedReservation->submissionId()?->value(), '동시 등록으로 중복 키가 발생해도 본인의 기존 제출은 재사용해야 합니다.');
$eventOwnerWpdb->hideExistingOnce = true;
submissionThrows(
    static fn (): mixed => $ownedEvents->reserve($eventRequestKey, 23, ['problem_id' => 1024]),
    '다른 사용자의 제출 요청'
);

$contextWpdb = new SubmissionContextWpdb();
$immutableContexts = new WordPressSubmissionContextStore(
    new SubmissionPlatformDatabase($contextWpdb)
);
$directContextId = new SubmissionId(7001);
$immutableContexts->save(
    $directContextId,
    new SubmissionContext(0, -1, null, null)
);
submissionSame([], $contextWpdb->rows, '직접 제출은 문맥 행을 만들면 안 됩니다.');
$classContextId = new SubmissionId(7002);
$immutableContexts->save(
    $classContextId,
    new SubmissionContext(0, -1, 81, null)
);
submissionSame(
    81,
    $contextWpdb->rows[7002]['class_id'] ?? null,
    '수업 제출 문맥은 제출 번호에 한 번만 저장해야 합니다.'
);
submissionRuntimeThrows(
    static fn (): mixed => $immutableContexts->save(
        $classContextId,
        new SubmissionContext(0, -1, 82, null)
    ),
    '생성 후 변경할 수 없습니다'
);
submissionSame(0, $contextWpdb->updates, '제출 문맥 저장소는 기존 행을 갱신하면 안 됩니다.');
submissionSame(
    81,
    $contextWpdb->rows[7002]['class_id'] ?? null,
    '거부된 문맥 변경은 최초 수업 번호를 보존해야 합니다.'
);
$immutableContexts->remove($classContextId);
submissionSame([], $contextWpdb->rows, '보상 정리는 새로 쓴 제출 문맥만 제거해야 합니다.');
$settings = new CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSettings(
    0,
    61440,
    0,
    true,
    50,
    180
);
$composer = new SubmissionSourceComposer();
foreach ([
    ['', '', '', false],
    ["front\nrear", "front\nrear", "front\nrear", false],
    ["show\r\nsecret #hidden\r\nend", "show\nend", "show\nsecret\nend", true],
    ["\tsecret();#hidden \t\rend", 'end', "\tsecret();\nend", true],
    ["secret #hidden\n", '', "secret\n", true],
    ["#hidden\n#hidden", '', "\n", true],
    ['print("#hidden")', 'print("#hidden")', 'print("#hidden")', false],
    ["x #Hidden\ny #hidden_other\nz #hidden not-at-end", "x #Hidden\ny #hidden_other\nz #hidden not-at-end", "x #Hidden\ny #hidden_other\nz #hidden not-at-end", false],
] as [$raw, $visible, $judge, $hidden]) {
    $wrapper = new SubmissionWrapperCode($raw);
    submissionSame($visible, $wrapper->visibleCode(), '앞·뒤 코드의 줄 끝 표식이 있는 줄만 사용자에게 숨겨야 합니다.');
    submissionSame($judge, $wrapper->judgeCode(), '숨긴 코드는 줄 수를 유지하고 표식만 제거해 채점해야 합니다.');
    submissionSame($hidden, $wrapper->hasHiddenLines(), '실제로 숨긴 줄이 있는지를 판정해야 합니다.');
}
submissionSame(
    "front();\nprivate_front();\nstudent(); #hidden\nprivate_rear();",
    $composer->compose("front();\nprivate_front(); #hidden", 'student(); #hidden', 'private_rear(); #hidden'),
    '앞·뒤 표식만 제거하고 사용자 코드의 #hidden은 변경하면 안 됩니다.'
);
$hiddenPolicy = new SubmissionCodePolicy(
    1000, '숨김 코드 문제', 'full_code', ['eval'],
    "visible_front();\neval('private_front'); #hidden", '',
    'private_rear(); #hidden', null, null, null,
    'problem-code-policy-v1:' . str_repeat('b', 64)
);
$hiddenPayload = (new FullCodeSourceMode())->build(new SubmissionSourceRequest(
    $hiddenPolicy, 6, 'print(42)', '', [], '', 'standard'
));
$hiddenSource = (new SubmissionSourcePolicyValidator($languages, $composer))->validate($hiddenPayload, $hiddenPolicy, $settings);
submissionSame('print(42)', $hiddenSource->userSource(), '사용자 제출 코드에는 관리자 앞·뒤 코드가 섞이면 안 됩니다.');
submissionSame('print(42)', $hiddenSource->storedUserSource(), '사용자 코드 조회용 소스에는 숨긴 관리자 코드가 저장되면 안 됩니다.');
submissionSame(
    "visible_front();\neval('private_front');\nprint(42)\nprivate_rear();",
    $hiddenSource->judgeSource(),
    '숨긴 관리자 코드도 금지어 검사 후 채점 코드에 한 번만 결합해야 합니다.'
);
$formReflection = new ReflectionClass(CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer::class);
$policyForm = $formReflection->newInstanceWithoutConstructor();
$formReflection->getProperty('settings')->setValue($policyForm, new SubmissionSettingsProvider(new SubmissionTestSettings()));
$formReflection->getProperty('html')->setValue(
    $policyForm,
    (new ReflectionClass(CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml::class))->newInstanceWithoutConstructor()
);
$hiddenPolicyHtml = $formReflection->getMethod('policySummary')->invoke($policyForm, $hiddenPolicy);
submissionAssert(
    str_contains($hiddenPolicyHtml, 'visible_front();')
        && ! str_contains($hiddenPolicyHtml, 'private_front')
        && ! str_contains($hiddenPolicyHtml, 'private_rear')
        && ! str_contains($hiddenPolicyHtml, '#hidden')
        && str_contains($hiddenPolicyHtml, '비공개 코드'),
    '사용자 제출 화면의 HTML에도 숨긴 코드나 표식을 전달하면 안 됩니다.'
);
$visualOutput = getenv('OJ_SUBMISSION_POLICY_VISUAL_OUTPUT');
if (is_string($visualOutput) && $visualOutput !== '') {
    file_put_contents($visualOutput, $hiddenPolicyHtml);
}
submissionSame(
    "import sys\nprint(1)\nprint('done')\n",
    $composer->compose("import sys\r\n", "\nprint(1)\r\n", "\nprint('done')\n"),
    '앞·사용자·뒤 코드는 경계마다 줄바꿈 하나로 결합해야 합니다.'
);

$fullPolicy = submissionPolicy();
$fullPayload = (new FullCodeSourceMode())->build(new SubmissionSourceRequest(
    $fullPolicy,
    6,
    "print(42)\r\n",
    '',
    [],
    '',
    'standard'
));
$validated = (new SubmissionSourcePolicyValidator(
    $languages,
    $composer
))->validate($fullPayload, $fullPolicy, $settings);
submissionSame(
    "import sys\nprint(42)\nprint('done')\n",
    $validated->judgeSource(),
    'Judge 소스는 서버 정책의 앞·뒤 코드를 사용해야 합니다.'
);
submissionThrows(
    fn () => (new SubmissionSourcePolicyValidator(
        $languages,
        $composer
    ))->validate(
        (new FullCodeSourceMode())->build(new SubmissionSourceRequest(
            $fullPolicy,
            6,
            'print(eval("1"))',
            '',
            [],
            '',
            'standard'
        )),
        $fullPolicy,
        $settings
    ),
    '금지어가 포함'
);

$template = "answer = {{OJ_BLANK:value}}\nprint(answer)";
$completionSchema = json_encode([
    'language_key' => 'python3',
    'blanks' => [[
        'id' => 'value',
        'label' => '계산식',
        'kind' => 'inline',
        'max_bytes' => 100,
    ]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$completionHash = hash('sha256', $template . $completionSchema);
$completionPolicy = submissionPolicy(
    'code_completion',
    ['eval('],
    $template,
    $completionSchema,
    $completionHash
);
$completionMode = new CodeCompletionSourceMode($languages);
$completionPayload = $completionMode->build(new SubmissionSourceRequest(
    $completionPolicy,
    0,
    '',
    $completionHash,
    ['value' => '10 + 20'],
    '',
    'standard'
));
submissionSame(
    "answer = 10 + 20\nprint(answer)",
    $completionPayload->userSource(),
    '빈칸 답은 서버 템플릿에 재구성되어야 합니다.'
);
submissionSame(
    6,
    $completionPayload->languageId(),
    '빈칸 완성 언어는 서버 스키마에서 결정해야 합니다.'
);
submissionThrows(
    fn () => $completionMode->build(new SubmissionSourceRequest(
        $completionPolicy,
        0,
        '',
        str_repeat('0', 64),
        ['value' => '10 + 20'],
        '',
        'standard'
    )),
    '화면을 새로 고친'
);
submissionThrows(
    fn () => (new SubmissionSourcePolicyValidator(
        $languages,
        $composer
    ))->validate(
        $completionMode->build(new SubmissionSourceRequest(
            $completionPolicy,
            0,
            '',
            $completionHash,
            ['value' => 'eval("20")'],
            '',
            'standard'
        )),
        $completionPolicy,
        $settings
    ),
    '금지어가 포함'
);

$wordValidator = new SubmissionSourcePolicyValidator($languages, $composer);
$wordPolicy = submissionPolicy(forbidden: ['eval', 'exec', '/']);
foreach (['print(eval("1"))', 'execute(1)', 'print(10 / 2)', 'print("//")', '# exec' . "\nprint(1)"] as $source) {
    submissionThrows(
        fn () => $wordValidator->validate(
            (new FullCodeSourceMode())->build(new SubmissionSourceRequest(
                $wordPolicy, 6, $source, '', [], '', 'standard'
            )),
            $wordPolicy,
            $settings
        ),
        '금지어가 포함'
    );
}
$allowedSource = 'print(EVAL(10))';
submissionSame(
    $allowedSource,
    $wordValidator->validate(
        (new FullCodeSourceMode())->build(new SubmissionSourceRequest(
            $wordPolicy, 6, $allowedSource, '', [], '', 'standard'
        )),
        $wordPolicy,
        $settings
    )->userSource(),
    '금지어는 정규식이 아닌 대소문자를 구분하는 부분 문자열이어야 합니다.'
);
$overlapTemplate = "aaa{{OJ_BLANK:value}}\nprint(1)";
$overlapHash = hash('sha256', $overlapTemplate . $completionSchema);
$overlapPolicy = submissionPolicy('code_completion', ['aaa'], $overlapTemplate, $completionSchema, $overlapHash);
submissionThrows(
    fn () => $wordValidator->validate(
        $completionMode->build(new SubmissionSourceRequest(
            $overlapPolicy, 6, '', $overlapHash, ['value' => 'a'], '', 'standard'
        )),
        $overlapPolicy,
        $settings
    ),
    '금지어가 포함'
);
submissionSame(
    "aaab\nprint(1)",
    $wordValidator->validate(
        $completionMode->build(new SubmissionSourceRequest(
            $overlapPolicy, 6, '', $overlapHash, ['value' => 'b'], '', 'standard'
        )),
        $overlapPolicy,
        $settings
    )->userSource(),
    '관리자 고정 코드만의 금지어는 기존 신뢰 영역 정책을 유지해야 합니다.'
);

$summary = new SubmissionSummary(
    new SubmissionId(77),
    1000,
    'student01',
    '학생 1',
    6,
    4,
    12,
    2048,
    '2026-07-28 11:00:00',
    '2026-07-28 11:00:01',
    0,
    -1,
    null,
    null,
    true
);
$performance = new PerformanceModePolicyRegistry();
$visibility = new SubmissionVisibilityPolicy(
    new SubmissionTestAuthorization([]),
    $performance,
    new SubmissionStaffScopeRegistry()
);
submissionSame(
    SubmissionAccessLevel::Full,
    $visibility->decide(10, 'student01', $summary),
    '일반 모드에서 본인 제출 상세를 볼 수 있어야 합니다.'
);
$performance->register('groups', new SubmissionTestPerformanceMode(true));
submissionSame(
    SubmissionAccessLevel::ResultOnly,
    $visibility->decide(10, 'student01', $summary),
    '수행 모드에서는 그룹 밖 제출을 포함한 본인 상세를 제한해야 합니다.'
);
$pollGateway = new SubmissionTestGateway();
// Acceptance history must not depend on the six recent submissions or learning context.
$historyGateway = new SubmissionTestGateway();
$historyGateway->summaries[1] = new SubmissionSummary(new SubmissionId(1), 9036, 'student01', '', 6, 4, 1, 1, '2026-01-01 00:00:00', '', 9, 0, 15, null, true);
$historyQuery = (new ReflectionClass(SubmissionQueryService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SubmissionQueryService::class, 'submissions'))->setValue($historyQuery, $historyGateway);
submissionAssert($historyQuery->hasAcceptedOwn('student01', 9036), '수업·대회 정답 이력도 홈 추천 제외에 포함해야 합니다.');
submissionAssert(! $historyQuery->hasAcceptedOwn('other', 9036), '다른 사용자의 정답은 제외 근거가 아닙니다.');
submissionAssert(! $historyQuery->hasAcceptedOwn('student01', 9037), '다른 문제의 정답은 제외 근거가 아닙니다.');
$historyGateway->summaries[1] = new SubmissionSummary(new SubmissionId(1), 9036, 'student01', '', 6, 4, 1, 1, '2026-01-01 00:00:00', '', 9, 0, 15, null, false);
submissionAssert(! $historyQuery->hasAcceptedOwn('student01', 9036), '무효 정답 제출은 홈 추천을 막지 않아야 합니다.');
$homeFeedClass = \CodingPanda\OjPlatform\Kernel\Integration\Home\HomeSubmissionFeed::class;
$homeFeed = (new ReflectionClass($homeFeedClass))->newInstanceWithoutConstructor();
(new ReflectionProperty($homeFeedClass, 'submissions'))->setValue($homeFeed, $historyQuery);
$homeEntry = static fn (int $id, int $problem, int $result) => new \CodingPanda\OjPlatform\Kernel\Integration\Home\HomeSubmissionEntry($id, $problem, '문제', '틀렸습니다', 'danger', 'Python', '', $result);
(new ReflectionProperty($homeFeedClass, 'entries'))->setValue($homeFeed, [10 => [$homeEntry(3, 9036, 4), $homeEntry(2, 9036, 6), $homeEntry(1, 9037, 6)]]);
$homeProvider = new \CodingPanda\OjPlatform\Kernel\Integration\Home\ContinueLearningHomeBlockProvider($homeFeed);
$homeContext = new \CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext(new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(10, 'student01', 'test@example.test', '학습자'), 'ko_KR', new DateTimeImmutable());
$continueBlock = $homeProvider->load($homeContext, 1);
submissionAssert(str_contains($continueBlock->items()[0]->title(), '9037'), '최신 정답 다음에 있는 동일 문제의 과거 오답을 추천하면 안 됩니다.');
$pollGateway->summaries[77] = $summary;
$pollQuery = new SubmissionQueryService(
    $pollGateway,
    new SubmissionTestContexts(),
    new SubmissionTestEvents(),
    $visibility,
    new SubmissionSettingsProvider(new SubmissionTestSettings()),
    new JudgeResultCatalog(),
    new SubmissionTestErrors()
);
$pollGateway->summaries[80] = new SubmissionSummary(new SubmissionId(80), 1000, 'student01', '학생 1', 6, 4, 1, 1, '2026-07-28 10:00:00', '', 0, 0, null, null, true);
$pollGateway->summaries[81] = new SubmissionSummary(new SubmissionId(81), 1000, 'student01', '학생 1', 6, 6, 1, 1, '2026-07-28 10:01:00', '', 0, 0, null, null, true);
$pollGateway->summaries[82] = new SubmissionSummary(new SubmissionId(82), 1000, 'student02', '학생 2', 6, 4, 1, 1, '2026-07-28 10:02:00', '', 0, 0, null, null, true);
$pollGateway->summaries[83] = new SubmissionSummary(new SubmissionId(83), 1001, 'student02', '학생 2', 6, 4, 1, 1, '2026-07-28 10:03:00', '', 0, 0, null, null, true);
$acceptedProjection = $pollQuery->acceptedForUsers(
    ['student01', 'student02'],
    [1000],
    '2026-07-28 09:59:00',
    '2026-07-28 10:59:00'
);
submissionSame([82, 80], array_map(
    static fn (SubmissionSummary $row): int => $row->submissionId()->value(),
    $acceptedProjection
), '애드온 정답 조회는 요청한 사용자·문제의 유효 정답만 반환해야 합니다.');
$restrictedPoll = $pollQuery->poll(
    10,
    'student01',
    new SubmissionId(77)
);
$reviewAccess = new \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionReviewService($pollQuery);
submissionAssert($reviewAccess->findForReview(10, 'student01', 77) === null, '결과 전용 권한에서는 저장된 AI 피드백 검토도 차단해야 합니다.');
submissionAssert($reviewAccess->findManyForReview(10, 'student01', [77]) === [], '일괄 조회에서도 결과 전용 제출의 피드백을 노출하면 안 됩니다.');
submissionAssert(
    $restrictedPoll instanceof SubmissionPollResult,
    '수행 모드에서도 본인 제출의 채점 상태는 조회할 수 있어야 합니다.'
);
submissionSame(
    SubmissionAccessLevel::ResultOnly,
    $restrictedPoll?->access(),
    '수행 모드 채점 상태 응답은 결과 전용 접근 수준을 유지해야 합니다.'
);
submissionSame(
    null,
    $pollQuery->poll(11, 'student02', new SubmissionId(77)),
    '다른 사용자의 제출 채점 상태는 조회할 수 없어야 합니다.'
);
submissionSame(
    SubmissionAccessLevel::Denied,
    $visibility->decide(11, 'student02', $summary),
    '권한이 없는 사용자는 다른 사용자의 제출을 볼 수 없어야 합니다.'
);
$staffVisibility = new SubmissionVisibilityPolicy(
    new SubmissionTestAuthorization([
        'oj_manage_submissions',
        'oj_view_submission_detail',
        'oj_view_submission_code',
    ]),
    new PerformanceModePolicyRegistry(),
    new SubmissionStaffScopeRegistry()
);
submissionSame(
    SubmissionAccessLevel::Full,
    $staffVisibility->decide(1, 'manager', $summary),
    '제출 상세·코드 권한이 있는 운영자는 전체 상세를 볼 수 있어야 합니다.'
);
$scopedStaffRegistry = new SubmissionStaffScopeRegistry();
$scopedStaffRegistry->register(new SubmissionTestStaffScope(2, 1000));
$scopedSummary = new SubmissionSummary(
    new SubmissionId(78),
    1000,
    'student01',
    '학생 1',
    6,
    4,
    12,
    2048,
    '2026-07-28 11:00:00',
    '2026-07-28 11:00:01',
    0,
    -1,
    1000,
    null,
    true
);
$scopedVisibility = new SubmissionVisibilityPolicy(
    new SubmissionTestAuthorization([]),
    new PerformanceModePolicyRegistry(),
    $scopedStaffRegistry
);
submissionSame(
    SubmissionAccessLevel::Full,
    $scopedVisibility->decide(2, 'teacher', $scopedSummary),
    '담당 범위에 속한 운영자는 전체 상세를 볼 수 있어야 합니다.'
);

$aiFeedbackSummary = new SubmissionSummary(
    new SubmissionId(88),
    1000,
    'student02',
    '학생 2',
    6,
    4,
    12,
    2048,
    '2026-07-28 11:00:00',
    '2026-07-28 11:00:01',
    0,
    -1,
    null,
    null,
    true
);
$aiFeedbackGateway = new SubmissionTestGateway();
$aiFeedbackGateway->summaries[88] = $aiFeedbackSummary;
$aiFeedbackGateway->detail = new SubmissionDetail(
    $aiFeedbackSummary,
    'print(1)',
    'print(1)',
    '',
    ''
);
$aiFeedbackUsers = new SubmissionTestUsers();
$aiFeedbackUsers->byLogin['student02'] = new UserIdentity(
    22,
    'student02',
    'student02@example.test',
    '학생 2'
);
$aiFeedbackQuery = new SubmissionQueryService(
    $aiFeedbackGateway,
    new SubmissionTestContexts(),
    new SubmissionTestEvents(),
    new SubmissionVisibilityPolicy(
        new SubmissionTestAuthorization([]),
        new PerformanceModePolicyRegistry(),
        new SubmissionStaffScopeRegistry()
    ),
    new SubmissionSettingsProvider(new SubmissionTestSettings()),
    new JudgeResultCatalog(),
    new SubmissionTestErrors()
);
submissionSame(
    null,
    (new AiFeedbackSubmissionService(
        $aiFeedbackQuery,
        $aiFeedbackUsers,
        new SubmissionAiFeedbackPolicyRegistry()
    ))->findForActor(999, 'someone-else', 88),
    '접근 권한이 없는 열람자는 AI 피드백 조회에서 예외 없이 null을 반환해야 합니다.'
);
$denyingPolicies = new SubmissionAiFeedbackPolicyRegistry();
$denyingPolicies->register(new class implements SubmissionAiFeedbackPolicy {
    public function supports(SubmissionSummary $submission): bool
    {
        unset($submission);

        return true;
    }

    public function allows(SubmissionSummary $submission): bool
    {
        unset($submission);

        return false;
    }
});
submissionSame(
    null,
    (new AiFeedbackSubmissionService(
        $aiFeedbackQuery,
        $aiFeedbackUsers,
        $denyingPolicies
    ))->findForActor(22, 'student02', 88),
    'AI 피드백 정책이 금지하면 본인 제출이어도 조회할 수 없어야 합니다(정책이 실제로 호출돼야 합니다).'
);
$allowedFeedback = (new AiFeedbackSubmissionService(
    $aiFeedbackQuery,
    $aiFeedbackUsers,
    new SubmissionAiFeedbackPolicyRegistry()
))->findForActor(22, 'student02', 88);
submissionAssert(
    $allowedFeedback instanceof AiFeedbackSubmission,
    '정책이 허용하고 본인 제출이면 AI 피드백 조회 결과를 반환해야 합니다.'
);

$settingManager = new SubmissionTestSettings([
    'submission' => [
        'disabled_language_mask' => 0,
        'page_size' => 50,
        'maximum_poll_seconds' => 180,
    ],
    'submission_rules' => [
        'max_source_kb' => 60,
        'cooldown_seconds' => 0,
        'allow_resubmit_after_accept' => true,
    ],
]);
$gateway = new SubmissionTestGateway();
$contextStore = new SubmissionTestContexts();
$events = new SubmissionTestEvents();
$errors = new SubmissionTestErrors();
$service = new SubmitSubmissionService(
    new SubmissionTestProblems($fullPolicy),
    $gateway,
    $contextStore,
    $events,
    new SubmissionSettingsProvider($settingManager),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
    new SubmissionSourcePolicyValidator($languages, $composer),
    new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(),
    $errors
);
$command = new SubmitSubmissionCommand(
    10,
    'student01',
    '학생 1',
    1000,
    SubmissionMode::Code,
    6,
    'print(42)',
    '',
    [],
    '',
    'standard',
    str_repeat('b', 64),
    '127.0.0.1'
);
$eventOwnerGateway = new SubmissionTestGateway();
$eventOwnerService = new SubmitSubmissionService(
    new SubmissionTestProblems($fullPolicy),
    $eventOwnerGateway,
    new SubmissionTestContexts(),
    $ownedEvents,
    new SubmissionSettingsProvider($settingManager),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
    new SubmissionSourcePolicyValidator($languages, $composer),
    new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(),
    new SubmissionTestErrors()
);
$eventOwnerCommand = static fn (int $actor): SubmitSubmissionCommand => new SubmitSubmissionCommand(
    $actor, 'student' . $actor, '학생', 1024, SubmissionMode::Code, 6,
    'print(42)', '', [], '', 'standard', $eventRequestKey, '127.0.0.1'
);
submissionSame(777, $eventOwnerService->submit($eventOwnerCommand(17))->submissionId()->value(), '제출 서비스도 본인의 기존 요청만 재사용해야 합니다.');
submissionThrows(static fn (): mixed => $eventOwnerService->submit($eventOwnerCommand(23)), '다른 사용자의 제출 요청');
submissionSame([], $eventOwnerGateway->calls, '타인의 요청 키 재사용은 소스 조회·저장·채점을 시작하면 안 됩니다.');
$requestLimitCalls = [];
$created = $service->submit($command, function (int $count, int $seconds) use (&$requestLimitCalls): void {
    $requestLimitCalls[] = [$count, $seconds];
});
submissionSame([[30, 60]], $requestLimitCalls, '기본 요청 제한을 제출 검증 후 적용해야 합니다.');
submissionSame(501, $created->submissionId()->value(), '생성된 제출 번호가 다릅니다.');
submissionSame([], $contextStore->contexts, '직접 제출은 별도 문맥 행을 만들면 안 됩니다.');
submissionAssert(! $created->wasReused(), '첫 제출은 재사용으로 표시하면 안 됩니다.');
submissionSame(
    [
        'create:student01',
        'store:501',
        'verify:501',
        'publish:501',
    ],
    $gateway->calls,
    '제출은 준비·소스 저장·재검증·공개 순서를 지켜야 합니다.'
);
submissionSame(
    [
        'reserve',
        'record:submission_prepared:501',
        'record:submission_source_verified:501',
        'complete:91:501',
    ],
    $events->calls,
    '제출 준비·소스 검증·공개 사건을 순서대로 기록해야 합니다.'
);
$events->existing = new SubmissionId(501);
$reused = $service->submit($command);
submissionAssert($reused->wasReused(), '같은 요청 키는 기존 제출을 재사용해야 합니다.');
submissionSame(
    4,
    count($gateway->calls),
    '중복 요청은 Judge DB에 다시 쓰면 안 됩니다.'
);

foreach ([true, false] as $enabled) {
    $testSettings = new SubmissionTestSettings(['submission_rules' => [
        'cooldown_seconds' => 3, 'request_limit_enabled' => $enabled,
        'request_limit_count' => 7, 'request_limit_seconds' => 120,
    ]]);
    $testGateway = new SubmissionTestGateway();
    $testGateway->latestTimestamp = (new SubmissionTestClock())->now()->getTimestamp();
    $testService = new SubmitSubmissionService(
        new SubmissionTestProblems($fullPolicy), $testGateway,
        new SubmissionTestContexts(), new SubmissionTestEvents(),
        new SubmissionSettingsProvider($testSettings),
        new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
        new SubmissionSourcePolicyValidator($languages, $composer),
        new SubmissionContextProviderRegistry(), new SubmissionTestClock(), $errors
    );
    $calls = [];
    $consume = function (int $count, int $seconds) use (&$calls): void { $calls[] = [$count, $seconds]; };
    try {
        $testService->submit($command, $consume);
        throw new LogicException('제출 간격 제한이 누락되었습니다.');
    } catch (DomainException $error) {
        submissionSame('3초 뒤에 다시 제출할 수 있습니다.', $error->getMessage(), '간격 제한 메시지');
    }
    submissionSame([], $calls, '최소 간격 거절은 요청 횟수를 소비하면 안 됩니다.');
    $testGateway->latestTimestamp = null;
    $testService->submit($command, $consume);
    submissionSame($enabled ? [[7, 120]] : [], $calls, '사용 여부와 사용자 지정 횟수·시간을 적용해야 합니다.');
}

$contextFailureGateway = new SubmissionTestGateway();
$contextFailureStore = new SubmissionFailingContexts();
$contextFailureService = new SubmitSubmissionService(
    new SubmissionTestProblems($fullPolicy),
    $contextFailureGateway,
    $contextFailureStore,
    new SubmissionTestEvents(),
    new SubmissionSettingsProvider($settingManager),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
    new SubmissionSourcePolicyValidator($languages, $composer),
    new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(),
    new SubmissionTestErrors()
);
submissionRuntimeThrows(
    static fn (): mixed => $contextFailureService->submit($command),
    '제출 문맥 저장 충돌'
);
submissionAssert(
    in_array('discard:501', $contextFailureGateway->calls, true),
    '문맥 저장 실패는 새로 준비한 Judge 제출을 보상 삭제해야 합니다.'
);
submissionSame(
    0,
    $contextFailureStore->removals,
    '문맥 저장 전 실패는 기존 플랫폼 문맥을 삭제하면 안 됩니다.'
);
$failedGateway = new SubmissionTestGateway();
$failedGateway->sourceMatch = false;
$failedEvents = new SubmissionTestEvents();
$failedContexts = new SubmissionTestContexts();
$failedService = new SubmitSubmissionService(
    new SubmissionTestProblems($fullPolicy),
    $failedGateway,
    $failedContexts,
    $failedEvents,
    new SubmissionSettingsProvider($settingManager),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
    new SubmissionSourcePolicyValidator($languages, $composer),
    new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(),
    new SubmissionTestErrors()
);
submissionThrows(
    fn () => $failedService->submit(new SubmitSubmissionCommand(
        10,
        'student01',
        '학생 1',
        1000,
        SubmissionMode::Code,
        6,
        'print(99)',
        '',
        [],
        '',
        'standard',
        str_repeat('c', 64),
        '127.0.0.1'
    )),
    '저장된 제출 코드'
);
submissionAssert(
    in_array('discard:501', $failedGateway->calls, true),
    '공개 전 저장 검증 실패는 준비된 제출을 보상 삭제해야 합니다.'
);
submissionSame([], $failedContexts->contexts, '실패한 제출의 플랫폼 문맥도 보상 삭제해야 합니다.');
submissionAssert(
    in_array('fail:91:submission_rejected', $failedEvents->calls, true),
    '거절된 제출은 실패 이벤트로 남겨야 합니다.'
);

$auditGateway = new SubmissionTestGateway();
$auditEvents = new SubmissionTestEvents();
$auditEvents->completeFails = true;
$auditErrors = new SubmissionTestErrors();
$auditContexts = new SubmissionTestContexts();
$auditService = new SubmitSubmissionService(
    new SubmissionTestProblems($fullPolicy),
    $auditGateway,
    $auditContexts,
    $auditEvents,
    new SubmissionSettingsProvider($settingManager),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode()]),
    new SubmissionSourcePolicyValidator($languages, $composer),
    new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(),
    $auditErrors
);
$auditResult = $auditService->submit(new SubmitSubmissionCommand(
    10,
    'student01',
    '학생 1',
    1000,
    SubmissionMode::Code,
    6,
    'print(100)',
    '',
    [],
    '',
    'standard',
    str_repeat('d', 64),
    '127.0.0.1'
));
submissionAssert(
    $auditResult->hasAuditWarning(),
    'Judge 공개 뒤 감사 갱신 실패는 성공 결과에 경고로 표시해야 합니다.'
);
submissionAssert(
    in_array('publish:501', $auditGateway->calls, true)
        && ! in_array('discard:501', $auditGateway->calls, true)
        && ! in_array('recovery:501', $auditGateway->calls, true),
    '이미 공개된 제출은 감사 갱신 실패로 삭제하거나 복구 상태로 바꾸면 안 됩니다.'
);
submissionAssert(
    count(array_filter(
        $auditEvents->calls,
        static fn (string $call): bool => str_starts_with($call, 'fail:')
    )) === 0
        && count($auditErrors->errors) === 1,
    '공개 뒤 감사 실패는 거절 사건으로 덮지 않고 오류 보고만 남겨야 합니다.'
);

$projectionGateway = new SubmissionTestGateway();
$projectionContexts = new SubmissionTestContexts();
for ($index = 1; $index <= 1205; ++$index) {
    $submissionId = new SubmissionId(10000 + $index);
    $projectionGateway->summaries[$submissionId->value()] =
        new SubmissionSummary(
            $submissionId,
            1000,
            'student01',
            '학생 1',
            6,
            4,
            10,
            1024,
            '2026-07-28 11:00:00',
            '2026-07-28 11:00:01',
            0,
            -1,
            null,
            null,
            true,
            1.0
        );
    $projectionContexts->save(
        $submissionId,
        new SubmissionContext(0, -1, 7, null)
    );
}
$projectionService = new SubmissionProjectionService(
    $projectionGateway,
    $projectionContexts,
    $visibility,
    new JudgeResultCatalog()
);
$projectionQuery = new SubmissionProjectionQuery(
    [],
    [1000],
    null,
    7,
    null,
    true,
    50
);
submissionSame(
    50,
    count($projectionService->report($projectionQuery)),
    '일반 제출 투영 조회는 요청한 페이지 크기를 유지해야 합니다.'
);
submissionSame(
    1205,
    count($projectionService->reportAll($projectionQuery)),
    '수업 전체 보고서는 1,000건을 넘어도 모든 문맥 제출을 조회해야 합니다.'
);
$insidePeriod = new SubmissionProjectionQuery(
    [],
    [1000],
    null,
    7,
    null,
    true,
    50,
    0,
    null,
    '2026-07-28 00:00:00',
    '2026-07-28 23:59:59'
);
submissionSame(
    1205,
    count($projectionService->reportAll($insidePeriod)),
    '문맥 기반 전체 보고서도 제출 기간 안의 기록을 모두 포함해야 합니다.'
);
$outsidePeriod = new SubmissionProjectionQuery(
    [],
    [1000],
    null,
    7,
    null,
    true,
    50,
    0,
    null,
    '2026-07-29 00:00:00',
    '2026-07-29 23:59:59'
);
submissionSame(
    0,
    count($projectionService->reportAll($outsidePeriod)),
    '문맥 기반 전체 보고서는 제출 기간 밖의 기록을 제외해야 합니다.'
);
$invalidProjectionPeriodRejected = false;
try {
    new SubmissionProjectionQuery(
        [],
        [],
        null,
        null,
        null,
        true,
        50,
        0,
        null,
        '2026-07-30 00:00:00',
        '2026-07-29 23:59:59'
    );
} catch (InvalidArgumentException) {
    $invalidProjectionPeriodRejected = true;
}
submissionAssert(
    $invalidProjectionPeriodRejected,
    '제출 투영 조회는 역전된 기간을 거부해야 합니다.'
);
$profileToday = new DateTimeImmutable('2026-08-10');
$submissionProfile = new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile(
    24,
    10,
    7,
    8,
    5,
    $profileToday->format('Y-m-d'),
    [
        new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSolvedProblem(
            1001,
            '2026-08-10 09:30:00'
        ),
    ],
    ['2026-08-10', '2026-08-09', '2026-08-08', '2026-08-06']
);
submissionSame(63, $submissionProfile->recentAccuracyPercent(), '최근 30일 정답률을 반올림해야 합니다.');
submissionSame(3, $submissionProfile->currentStreak(), '오늘부터 이어진 연속 해결 일수를 계산해야 합니다.');
submissionSame(
    1001,
    $submissionProfile->recentSolvedProblems()[0]->problemId(),
    '최근 해결 문제 번호를 보존해야 합니다.'
);
$yesterdayStreak = new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile(
    1,
    1,
    1,
    1,
    1,
    '2026-08-10',
    [],
    ['2026-08-09', '2026-08-08']
);
submissionSame(2, $yesterdayStreak->currentStreak(), '오늘 기록이 없으면 어제부터 연속 일수를 계산해야 합니다.');

$invalidProfileAggregateRejected = false;
try {
    new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile(
        1,
        1,
        1,
        2,
        3,
        '2026-08-10',
        [],
        []
    );
} catch (InvalidArgumentException) {
    $invalidProfileAggregateRejected = true;
}
submissionAssert(
    $invalidProfileAggregateRejected,
    '최근 정답 수가 완료 제출 수보다 큰 프로필 집계를 거부해야 합니다.'
);
$profileProviderSource = file_get_contents(
    dirname(__DIR__, 2)
        . '/modules/Submissions/Infrastructure/Judge/MysqliUserSubmissionProfileProvider.php'
);
submissionAssert(
    is_string($profileProviderSource)
        && str_contains(
            $profileProviderSource,
            '`result` BETWEEN 4 AND 11'
        )
        && ! str_contains($profileProviderSource, 'CURRENT_TIMESTAMP')
        && ! str_contains($profileProviderSource, 'CURRENT_DATE()'),
    '최근 정답률과 연속 학습은 완료 결과와 사이트 Clock을 기준으로 집계해야 합니다.'
);

$publicProfileSubmissions = new class implements \CodingPanda\OjPlatform\Modules\Submissions\Contract\UserSubmissionProfileProvider {
    public function find(string $userLogin, int $recentLimit = 5): \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile
    {
        unset($userLogin, $recentLimit);

        return new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile(
            12,
            5,
            4,
            3,
            2,
            '2026-08-10',
            [new \CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSolvedProblem(
                1001,
                '2026-08-10 09:30:00'
            )],
            ['2026-08-10']
        );
    }
};
$publicProfileProblems = new class implements \CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider {
    public function findForUser(int $userId, int $problemId): ?\CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem
    {
        return $this->findManyForUser($userId, [$problemId])[$problemId] ?? null;
    }

    public function findManyForUser(int $userId, array $problemIds): array
    {
        unset($userId);
        if (! in_array(1001, $problemIds, true)) {
            return [];
        }

        return [1001 => new \CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem(
            1001,
            '출력 연습',
            '',
            '',
            '',
            '',
            1,
            ['basics.output'],
            30,
            40,
            true,
            str_repeat('a', 64)
        )];
    }

    public function catalogForUser(int $userId, int $limit = 500): array
    {
        unset($userId, $limit);

        return [];
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        unset($userId, $limit);

        return [];
    }

    public function publicCatalog(int $limit = 500): array
    {
        unset($limit);

        return [];
    }

    public function catalogRevision(): string
    {
        return 'profile-test';
    }
};
$publicProfileTopics = new class implements \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary {
    public function all(): array { return []; }

    public function find(string $key): ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord
    {
        if ($key !== 'basics.output') {
            return null;
        }

        return new class implements \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord {
            public function key(): string { return 'basics.output'; }
            public function label(): string { return '기초 출력'; }
            public function categoryKey(): ?string { return 'basics'; }
            public function aliases(): array { return []; }
            public function isCategory(): bool { return false; }
            public function isActive(): bool { return true; }
        };
    }

    public function assertSelectableDetails(array $detailKeys): void { unset($detailKeys); }
    public function version(): string { return 'topics-v1'; }
    public function createCategory(string $key, string $label, array $aliases, int $actorUserId): void
    {
        unset($key, $label, $aliases, $actorUserId);
    }
    public function createDetail(string $categoryKey, string $key, string $label, array $aliases, int $actorUserId): void
    {
        unset($categoryKey, $key, $label, $aliases, $actorUserId);
    }
};
$publicProfileRoutes = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider {
    public function path(string $routeName, array $parameters = []): string
    {
        return '/' . str_replace('.', '/', $routeName) . '/'
            . (string) ($parameters['problem_id'] ?? '');
    }
};
$publicProfileSection = (new \CodingPanda\OjPlatform\Kernel\Integration\Profile\SubmissionPublicProfileSectionProvider(
    $publicProfileSubmissions,
    $publicProfileProblems,
    $publicProfileTopics,
    $publicProfileRoutes
))->provide(21, 22, 'learner22', 5);
submissionSame(4, count($publicProfileSection->metrics()), '공개 프로필에 네 가지 학습 지표를 제공해야 합니다.');
submissionSame(1, count($publicProfileSection->activities()), '수행 모드와 무관하게 공개 가능한 최근 해결 문제를 제공해야 합니다.');
submissionSame(
    '난이도 1 · 기초 출력',
    $publicProfileSection->activities()[0]->description(),
    '최근 해결 문제에 난이도와 표준 주제를 표시해야 합니다.'
);
submissionSame(
    ['기초 출력'],
    $publicProfileSection->highlights(),
    '공개 프로필에 최근 해결 문제에서 집계한 표준 주제를 제공해야 합니다.'
);
submissionSame(
    '/problems/show/1001',
    $publicProfileSection->activities()[0]->url(),
    '최근 해결 링크는 문제 상세로 연결해야 합니다.'
);
$operationSummary = static fn (int $id): SubmissionSummary =>
    new SubmissionSummary(
        new SubmissionId($id),
        1000,
        'student01',
        '학생 1',
        6,
        4,
        12,
        2048,
        '2026-07-28 11:00:00',
        '2026-07-28 11:00:01',
        0,
        -1,
        null,
        null,
        true
    );
$operationGateway = new SubmissionTestGateway();
$operationGateway->summaries[201] = $operationSummary(201);
$operationGateway->summaries[202] = $operationSummary(202);
$operationGateway->summaries[203] = $operationSummary(203);
$operationContexts = new SubmissionTestContexts();
$operationContexts->contexts[201] = new SubmissionContext(0, -1, 7, null);
$operationContexts->contexts[202] = new SubmissionContext(0, -1, 7, null);
$operationContexts->contexts[203] = new SubmissionContext(0, -1, 8, null);
$operationScopes = new SubmissionStaffScopeRegistry();
$operationScopes->register(new SubmissionTestStaffScope(55, 7));
$operationEvents = new SubmissionTestEvents();
$scopedOperations = new SubmissionOperationService(
    $operationGateway,
    $operationEvents,
    $operationContexts,
    $operationScopes,
    new SubmissionTestAuthorization([])
);
submissionSame(
    2,
    $scopedOperations->rejudge(
        55,
        [new SubmissionId(201), new SubmissionId(201), new SubmissionId(202)],
        '수업 제출 재채점',
        str_repeat('a', 64)
    ),
    '수업 담당자는 담당 범위 제출만 중복 없이 재채점할 수 있어야 합니다.'
);
submissionSame(
    ['rejudge:201', 'rejudge:202'],
    array_values(array_filter(
        $operationGateway->calls,
        static fn (string $call): bool => str_starts_with($call, 'rejudge:')
    )),
    '담당 범위 재채점은 선택 제출마다 한 번만 실행해야 합니다.'
);
$operationCallCount = count($operationGateway->calls);
submissionThrows(
    fn () => $scopedOperations->rejudge(
        55,
        [new SubmissionId(201), new SubmissionId(203)],
        '범위 혼합 재채점'
    ),
    '담당 범위를 벗어난'
);
submissionSame(
    $operationCallCount,
    count($operationGateway->calls),
    '범위 밖 제출이 섞인 요청은 어떤 제출도 먼저 변경하면 안 됩니다.'
);
submissionThrows(
    fn () => $scopedOperations->rejudge(
        55,
        [new SubmissionId(201), new SubmissionId(999)],
        '없는 제출 혼합'
    ),
    '찾을 수 없는'
);
submissionSame(
    $operationCallCount,
    count($operationGateway->calls),
    '없는 제출이 섞인 요청도 사전 검증에서 모두 중단해야 합니다.'
);
submissionThrows(
    fn () => $scopedOperations->rejudge(
        55,
        [new SubmissionId(201)],
        '요청 키 검증',
        'invalid-key'
    ),
    '요청 식별자'
);
submissionSame(
    $operationCallCount,
    count($operationGateway->calls),
    '잘못된 요청 키는 Judge DB 변경 전에 거절해야 합니다.'
);
submissionThrows(
    fn () => $scopedOperations->setValidity(
        55,
        [new SubmissionId(201)],
        false,
        '무효 처리 시도'
    ),
    '관리 권한'
);
submissionSame(
    $operationCallCount,
    count($operationGateway->calls),
    '담당 범위 권한만으로 제출을 무효 처리할 수 없어야 합니다.'
);
$globalOperations = new SubmissionOperationService(
    $operationGateway,
    $operationEvents,
    $operationContexts,
    new SubmissionStaffScopeRegistry(),
    new SubmissionTestAuthorization([
        'oj_rejudge_submissions',
        'oj_delete_submissions',
    ])
);
submissionSame(
    1,
    $globalOperations->setValidity(
        91,
        [new SubmissionId(203)],
        false,
        '관리자 무효 처리'
    ),
    '전역 제출 관리자는 담당 문맥과 무관하게 무효 처리할 수 있어야 합니다.'
);

// A single legacy `solution` row with an out-of-range language/problem_id
// (direct HUSTOJ import, manual DB edit) must not take down an entire
// ranking/dashboard/list request — MysqliSubmissionGateway::hydrateSummary()
// still enforces SubmissionSummary's strict invariants, but bulk/lookup
// callers go through tryHydrateSummary() and tolerate a bad row instead of
// letting the exception propagate.
$gatewayReflection = new ReflectionClass(MysqliSubmissionGateway::class);
$gateway = $gatewayReflection->newInstanceWithoutConstructor();
$hydrateMethod = $gatewayReflection->getMethod('hydrateSummary');
$tryHydrateMethod = $gatewayReflection->getMethod('tryHydrateSummary');
$baseRow = [
    'solution_id' => 501,
    'problem_id' => 10,
    'user_id' => 'legacy',
    'nick' => 'legacy',
    'language' => -1,
    'result' => 4,
    'time' => 10,
    'memory' => 1024,
    'in_date' => '2026-01-01 00:00:00',
    'judgetime' => '2026-01-01 00:00:01',
    'contest_id' => 0,
    'num' => 1,
    'valid' => 1,
    'pass_rate' => 1.0,
];
$negativeLanguageSummary = $tryHydrateMethod->invoke($gateway, $baseRow);
submissionAssert(
    $negativeLanguageSummary instanceof SubmissionSummary,
    '음수 언어 번호를 가진 레거시 제출 행도 0으로 보정해 복원해야 합니다.'
);
submissionSame(
    0,
    $negativeLanguageSummary->languageId(),
    '음수 언어 번호는 0으로 보정되어야 합니다.'
);
$orphanRow = $baseRow;
$orphanRow['problem_id'] = 0;
submissionSame(
    null,
    $tryHydrateMethod->invoke($gateway, $orphanRow),
    '문제 번호가 없는 고아 제출 행은 복원하지 않고 건너뛰어야 합니다.'
);
$orphanRejected = false;
try {
    $hydrateMethod->invoke($gateway, $orphanRow);
} catch (InvalidArgumentException) {
    $orphanRejected = true;
}
submissionAssert(
    $orphanRejected,
    '엄격한 hydrateSummary()는 여전히 잘못된 행을 거부해야 합니다.'
);

$panelPolicy = new SubmissionCodePolicy(
    2048, '패널 테스트 문제', 'full_code', [],
    '', 'print(1)', '', null, null, null,
    'problem-code-policy-v1:' . str_repeat('c', 64)
);
$panelProblems = new SubmissionTestProblems($panelPolicy);
$panelContexts = new SubmissionContextProviderRegistry();
$panelRendererStub = (new ReflectionClass(
    CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer::class
))->newInstanceWithoutConstructor();
$panelPreferenceOn = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider {
    public function forUser(int $userId): \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences
    {
        return new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences(
            null, 'chrome', null, 'code', 16, true
        );
    }
};
$panelPreferenceOff = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider {
    public function forUser(int $userId): \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences
    {
        return new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences(
            null, 'chrome', null, 'code', 16, false
        );
    }
};
$panelNavigation = \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext::general();
submissionAssert(
    (new \CodingPanda\OjPlatform\Modules\Submissions\Integration\Problems\InlineSubmissionPanelProvider(
        $panelPreferenceOff,
        $panelProblems,
        $panelRendererStub,
        $panelContexts
    ))->render(10, 2048, $panelNavigation) === null,
    '제출 패널 설정을 끈 사용자에게는 패널을 보여주면 안 됩니다.'
);
submissionAssert(
    (new \CodingPanda\OjPlatform\Modules\Submissions\Integration\Problems\InlineSubmissionPanelProvider(
        $panelPreferenceOn,
        $panelProblems,
        $panelRendererStub,
        $panelContexts
    ))->render(0, 2048, $panelNavigation) === null,
    '사용자 번호가 없으면 패널을 보여주면 안 됩니다.'
);
submissionAssert(
    (new \CodingPanda\OjPlatform\Modules\Submissions\Integration\Problems\InlineSubmissionPanelProvider(
        $panelPreferenceOn,
        $panelProblems,
        $panelRendererStub,
        $panelContexts
    ))->render(10, 9999, $panelNavigation) === null,
    '제출 정책을 확인할 수 없는 문제는 패널을 보여주면 안 됩니다.'
);

$orderingDefinition = CodeOrderingPuzzle::create("a = 1\nb = 2\nprint(a + b)\n", 'python');
$orderingSchema = CodeOrderingPuzzle::encode($orderingDefinition);
$orderingHash = CodeOrderingPuzzle::hash($orderingSchema);
$orderingIds = array_column($orderingDefinition['blocks'], 'id');
$orderingPolicy = new SubmissionCodePolicy(
    1000, '코드 순서 배열', 'code_ordering', [], '', '', '', null, null, null,
    'problem-code-policy-v1:' . str_repeat('e', 64), $orderingSchema, $orderingHash
);
$orderingMode = new CodeOrderingSourceMode($languages);
$orderingRequest = new SubmissionSourceRequest(
    $orderingPolicy, 0, 'print("client source must not be used")', $orderingHash,
    [], '', 'standard', $orderingIds
);
$orderingPayload = $orderingMode->build($orderingRequest);
submissionSame("a = 1\nb = 2\nprint(a + b)\n", $orderingPayload->userSource(), '배열 제출은 클라이언트 소스 대신 등록된 조각으로 복원해야 합니다.');
submissionSame(6, $orderingPayload->languageId(), '배열 문제의 언어는 서버 스키마로 고정해야 합니다.');
$orderingValidated = (new SubmissionSourcePolicyValidator($languages, $composer))->validate(
    $orderingPayload, $orderingPolicy, $settings
);
submissionSame(SubmissionMode::CodeOrdering, $orderingValidated->mode(), '배열 제출 모드를 보존해야 합니다.');
submissionSame($orderingPayload->userSource(), $orderingValidated->judgeSource(), '복원한 코드를 기존 채점 경로에 전달해야 합니다.');

$equivalentOrder = [$orderingIds[1], $orderingIds[0], $orderingIds[2]];
$equivalentPayload = $orderingMode->build(new SubmissionSourceRequest(
    $orderingPolicy, 6, '', $orderingHash, [], '', 'standard', $equivalentOrder
));
submissionSame("b = 2\na = 1\nprint(a + b)\n", $equivalentPayload->userSource(), '독립 문장 순서가 다른 배열도 코드로 복원해 채점기에 보내야 합니다.');
foreach ([[], [$orderingIds[0]], [$orderingIds[0], $orderingIds[0], $orderingIds[2]], [str_repeat('f', 32), $orderingIds[1], $orderingIds[2]]] as $invalidOrder) {
    try {
        $orderingMode->build(new SubmissionSourceRequest($orderingPolicy, 6, '', $orderingHash, [], '', 'standard', $invalidOrder));
        throw new LogicException('누락·중복·외부 코드 조각을 허용했습니다.');
    } catch (DomainException) {
        submissionAssert(true, '잘못된 배열을 거부해야 합니다.');
    }
}
submissionThrows(
    fn () => $orderingMode->build(new SubmissionSourceRequest($orderingPolicy, 6, '', str_repeat('0', 64), [], '', 'standard', $orderingIds)),
    '변경되었습니다'
);
submissionThrows(
    fn () => (new SubmissionSourcePolicyValidator($languages, $composer))->validate($fullPayload, $orderingPolicy, $settings),
    '코드 순서 배열 방식으로만'
);
submissionThrows(
    fn () => (new SubmissionSourcePolicyValidator($languages, $composer))->validate($orderingPayload, $fullPolicy, $settings),
    '설정되어 있지'
);
$orderingDisabledSettings = (new SubmissionSettingsProvider(new SubmissionTestSettings([
    'submission' => ['disabled_language_mask' => 1 << 6],
])))->current();
submissionThrows(
    fn () => (new SubmissionSourcePolicyValidator($languages, $composer))->validate($orderingPayload, $orderingPolicy, $orderingDisabledSettings),
    '사용할 수 없는'
);

$orderingGateway = new SubmissionTestGateway();
$orderingEvents = new SubmissionTestEvents();
$orderingService = new SubmitSubmissionService(
    new SubmissionTestProblems($orderingPolicy), $orderingGateway, new SubmissionTestContexts(),
    $orderingEvents, new SubmissionSettingsProvider(new SubmissionTestSettings()),
    new SubmissionSourceModeRegistry([new FullCodeSourceMode(), $orderingMode]),
    new SubmissionSourcePolicyValidator($languages, $composer), new SubmissionContextProviderRegistry(),
    new SubmissionTestClock(), new SubmissionTestErrors()
);
$orderingCommand = new SubmitSubmissionCommand(
    10, 'student01', '학생 1', 1000, SubmissionMode::CodeOrdering, 6, 'print("ignored")',
    $orderingHash, [], '', 'standard', str_repeat('e', 64), '127.0.0.1',
    orderingBlockIds: $equivalentOrder
);
$orderingResult = $orderingService->submit($orderingCommand);
submissionSame(501, $orderingResult->submissionId()->value(), '배열 문제도 정식 제출을 생성해야 합니다.');
submissionSame($equivalentPayload->userSource(), $orderingGateway->userSource, '저장 소스는 학생이 배열한 등록 조각이어야 합니다.');
submissionSame($equivalentPayload->userSource(), $orderingGateway->judgeSource, '기존 채점기에는 배열 결과를 전달해야 합니다.');
submissionAssert(in_array('publish:501', $orderingGateway->calls, true), '배열 제출도 기존 채점 대기열에 등록되어야 합니다.');
$orderingEvents->existing = $orderingResult->submissionId();
submissionAssert($orderingService->submit($orderingCommand)->wasReused(), '배열 제출도 같은 요청 키는 중복 생성하지 않아야 합니다.');

$orderingActor = new UserIdentity(10, 'student01', 'student01@example.test', '학생 1');
$orderingRequestFactory = new \CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionRequestFactory();
$orderingHttpBody = [
    'problem_id' => '1000', 'submission_mode' => 'code_ordering', 'language_id' => '6',
    'template_hash' => $orderingHash, 'request_key' => str_repeat('f', 64),
    'ordering_block_ids' => $orderingIds,
];
$parsedOrdering = $orderingRequestFactory->command($orderingActor, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('POST', '/submit', [], $orderingHttpBody));
submissionSame($orderingIds, $parsedOrdering->orderingBlockIds(), '요청에서 코드 조각 순서와 중복 여부를 손실 없이 전달해야 합니다.');
foreach (['not-an-array', ['named' => $orderingIds[0]], [[$orderingIds[0]]], array_fill(0, 201, $orderingIds[0])] as $malformedOrder) {
    submissionThrows(
        fn () => $orderingRequestFactory->command($orderingActor, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('POST', '/submit', [], array_replace($orderingHttpBody, ['ordering_block_ids' => $malformedOrder]))),
        '코드 조각의 순서'
    );
}

fwrite(
    STDOUT,
    sprintf(
        "R-06 제출 단위 검사 통과: assertion %d개\n",
        $submissionAssertions
    )
);

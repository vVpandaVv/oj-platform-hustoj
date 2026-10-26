<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Application\PerformanceModePolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionVisibilityPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionManagedScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionReservation;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$scopeAssertions = 0;

function scopeAssert(bool $condition, string $message): void
{
    global $scopeAssertions;
    ++$scopeAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function scopeSame(mixed $expected, mixed $actual, string $message): void
{
    global $scopeAssertions;
    ++$scopeAssertions;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' expected=' . var_export($expected, true)
                . ' actual=' . var_export($actual, true)
        );
    }
}

/** @param list<SubmissionSummary> $rows */
function scopeIds(array $rows): array
{
    return array_map(
        static fn (SubmissionSummary $summary): int =>
            $summary->submissionId()->value(),
        $rows
    );
}

function scopeSummary(
    int $id,
    string $userLogin,
    int $problemId = 1000,
    int $languageId = 6,
    int $resultCode = 4,
    int $contestId = 0,
    bool $valid = true
): SubmissionSummary {
    return new SubmissionSummary(
        new SubmissionId($id),
        $problemId,
        $userLogin,
        strtoupper($userLogin),
        $languageId,
        $resultCode,
        12,
        2048,
        '2026-08-13 12:00:00',
        '2026-08-13 12:00:01',
        $contestId,
        -1,
        null,
        null,
        $valid
    );
}

final class ScopeTestSettings implements SettingsManager
{
    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(
            1,
            $key === 'submission' ? ['page_size' => 10] : $default
        );
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key, $expectedRevision);

        return new SettingsSnapshot(2, $value);
    }
}

final class ScopeTestAuthorization implements Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($userId, $capability, $context);

        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        unset($userId, $capabilities);

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
    }
}

final class ScopeTestErrors implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        throw new RuntimeException('예상하지 않은 오류가 보고됐습니다.', 0, $error);
    }
}

final class ScopeTestEvents implements SubmissionEventStore
{
    public function reserve(
        string $requestKey,
        int $actorUserId,
        array $metadata
    ): SubmissionReservation {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }

    public function complete(
        int $eventId,
        SubmissionId $submissionId,
        array $metadata
    ): void {
        unset($eventId, $submissionId, $metadata);
    }

    public function fail(
        int $eventId,
        string $eventType,
        string $message,
        array $metadata
    ): void {
        unset($eventId, $eventType, $message, $metadata);
    }

    public function record(
        string $eventType,
        int $actorUserId,
        ?SubmissionId $submissionId,
        ?string $requestKey,
        string $message,
        array $metadata = []
    ): void {
        unset(
            $eventType,
            $actorUserId,
            $submissionId,
            $requestKey,
            $message,
            $metadata
        );
    }

    public function recent(int $limit = 100): array
    {
        unset($limit);

        return [];
    }
}

final class ScopeTestContexts implements SubmissionContextStore
{
    /** @var array<int, list<int>> */
    public array $classIds = [];
    /** @var array<int, list<int>> */
    public array $groupIds = [];
    /** @var array<int, SubmissionContext> */
    public array $contexts = [];

    public function save(
        SubmissionId $submissionId,
        SubmissionContext $context
    ): void {
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
            $id = $submissionId->value();
            if (isset($this->contexts[$id])) {
                $found[$id] = $this->contexts[$id];
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
        $ids = $classId !== null
            ? ($this->classIds[$classId] ?? [])
            : ($this->groupIds[$groupId ?? 0] ?? []);
        rsort($ids, SORT_NUMERIC);

        return array_slice($ids, $offset, $limit);
    }
}

final class ScopeTestGateway implements SubmissionGateway
{
    /** @var array<int, SubmissionSummary> */
    public array $summaries = [];
    /** @var list<int> */
    public array $searchLimits = [];
    /** @var list<int> */
    public array $findBatchSizes = [];

    public function search(SubmissionSearch $search): array
    {
        $this->searchLimits[] = $search->limit();
        $items = $this->matching($search);

        return array_slice($items, $search->offset(), $search->limit());
    }

    public function count(SubmissionSearch $search): int
    {
        return count($this->matching($search));
    }

    public function findSummary(SubmissionId $submissionId): ?SubmissionSummary
    {
        return $this->summaries[$submissionId->value()] ?? null;
    }

    public function findSummaries(array $submissionIds): array
    {
        $this->findBatchSizes[] = count($submissionIds);
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

        return null;
    }

    public function createPrepared(PreparedSubmission $submission): SubmissionId
    {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }

    public function storeSources(
        SubmissionId $submissionId,
        string $userSource,
        string $judgeSource
    ): void {
        unset($submissionId, $userSource, $judgeSource);
    }

    public function sourcesMatch(
        SubmissionId $submissionId,
        string $userSha256,
        string $judgeSha256,
        int $userBytes,
        int $judgeBytes
    ): bool {
        unset(
            $submissionId,
            $userSha256,
            $judgeSha256,
            $userBytes,
            $judgeBytes
        );

        return false;
    }

    public function publish(SubmissionId $submissionId): void
    {
        unset($submissionId);
    }

    public function discardPrepared(SubmissionId $submissionId): void
    {
        unset($submissionId);
    }

    public function markRecoveryRequired(SubmissionId $submissionId): void
    {
        unset($submissionId);
    }

    public function latestSubmissionTimestamp(string $userLogin): ?int
    {
        unset($userLogin);

        return null;
    }

    public function hasAccepted(
        string $userLogin,
        int $problemId,
        SubmissionContext $context
    ): bool {
        unset($userLogin, $problemId, $context);

        return false;
    }

    public function rejudge(SubmissionId $submissionId): int
    {
        unset($submissionId);

        return 0;
    }

    public function setValid(SubmissionId $submissionId, bool $valid): bool
    {
        unset($submissionId, $valid);

        return false;
    }

    /** @return list<SubmissionSummary> */
    private function matching(SubmissionSearch $search): array
    {
        $items = array_values(array_filter(
            $this->summaries,
            static function (SubmissionSummary $summary) use ($search): bool {
                if (
                    $search->userLogin() !== null
                    && $summary->userLogin() !== $search->userLogin()
                ) {
                    return false;
                }
                if (
                    $search->problemId() !== null
                    && $summary->problemId() !== $search->problemId()
                ) {
                    return false;
                }
                if (
                    $search->resultCode() !== null
                    && $summary->resultCode() !== $search->resultCode()
                ) {
                    return false;
                }
                if (
                    $search->languageId() !== null
                    && $summary->languageId() !== $search->languageId()
                ) {
                    return false;
                }
                if (
                    $search->valid() !== null
                    && $summary->isValid() !== $search->valid()
                ) {
                    return false;
                }
                if (
                    $search->contestId() !== null
                    && $summary->contestId() !== $search->contestId()
                ) {
                    return false;
                }
                $keyword = $search->keyword();
                if (
                    $keyword !== ''
                    && ! str_contains(
                        (string) $summary->submissionId()->value(),
                        $keyword
                    )
                    && ! str_contains((string) $summary->problemId(), $keyword)
                    && stripos($summary->userLogin(), $keyword) === false
                    && stripos($summary->nickname(), $keyword) === false
                ) {
                    return false;
                }

                return true;
            }
        ));
        usort(
            $items,
            static fn (SubmissionSummary $left, SubmissionSummary $right): int =>
                $right->submissionId()->value()
                    <=> $left->submissionId()->value()
        );

        return $items;
    }
}

function scopeQuery(
    ScopeTestGateway $gateway,
    ScopeTestContexts $contexts
): SubmissionQueryService {
    return new SubmissionQueryService(
        $gateway,
        $contexts,
        new ScopeTestEvents(),
        new SubmissionVisibilityPolicy(
            new ScopeTestAuthorization(),
            new PerformanceModePolicyRegistry(),
            new SubmissionStaffScopeRegistry()
        ),
        new SubmissionSettingsProvider(new ScopeTestSettings()),
        new JudgeResultCatalog(),
        new ScopeTestErrors()
    );
}

$scope = new SubmissionManagedScope([7, 7, 0], [8, -1], [9, 9]);
scopeSame([7], $scope->classIds(), '수업 범위 ID는 양수 중복 제거 목록이어야 합니다.');
scopeSame([8], $scope->groupIds(), '그룹 범위 ID는 양수 목록이어야 합니다.');
scopeSame([9], $scope->contestIds(), '대회 범위 ID는 양수 중복 제거 목록이어야 합니다.');

$gateway = new ScopeTestGateway();
$contexts = new ScopeTestContexts();
$gateway->summaries = [
    201 => scopeSummary(201, 'teacher'),
    202 => scopeSummary(202, 'LearnerClass'),
    203 => scopeSummary(203, 'learnerGroup'),
    204 => scopeSummary(204, 'learnerContest', contestId: 9),
    205 => scopeSummary(205, 'learnerDuplicate', contestId: 9),
    206 => scopeSummary(206, 'outside'),
    207 => scopeSummary(207, 'invalidClass', valid: false),
    208 => scopeSummary(208, 'otherLanguage', languageId: 3),
];
$contexts->classIds[7] = [202, 205, 207, 208];
$contexts->groupIds[8] = [203];
$contexts->contexts[202] = new SubmissionContext(0, -1, 7, null);
$contexts->contexts[203] = new SubmissionContext(0, -1, null, 8);
$contexts->contexts[205] = new SubmissionContext(9, -1, 7, null);
$contexts->contexts[207] = new SubmissionContext(0, -1, 7, null);
$contexts->contexts[208] = new SubmissionContext(0, -1, 7, null);
$query = scopeQuery($gateway, $contexts);
$page = $query->managedPage(
    50,
    'teacher',
    null,
    null,
    null,
    true,
    '',
    1,
    $scope
);
scopeSame(6, $page['total'], '본인과 담당 문맥 제출의 합집합 건수가 맞아야 합니다.');
scopeSame(
    [208, 205, 204, 203, 202, 201],
    scopeIds($page['rows']),
    '다중 문맥 결과는 중복 없이 최신 제출순이어야 합니다.'
);
scopeSame(7, $page['rows'][4]->classId(), '수업 제출 문맥이 목록 결과에 유지돼야 합니다.');
scopeSame(8, $page['rows'][3]->groupId(), '그룹 제출 문맥이 목록 결과에 유지돼야 합니다.');

$filtered = $query->managedPage(
    50,
    'teacher',
    null,
    4,
    6,
    true,
    'LEARNER',
    1,
    $scope
);
scopeSame(
    [205, 204, 203, 202],
    scopeIds($filtered['rows']),
    '결과·언어·키워드 필터가 모든 문맥에 동일하게 적용돼야 합니다.'
);

$contestGateway = new ScopeTestGateway();
$contestContexts = new ScopeTestContexts();
for ($id = 1001; $id <= 1205; ++$id) {
    $contestGateway->summaries[$id] = scopeSummary(
        $id,
        'contest-' . $id,
        contestId: 55
    );
}
$contestPage = scopeQuery($contestGateway, $contestContexts)->managedPage(
    60,
    'manager',
    null,
    null,
    null,
    true,
    '',
    21,
    new SubmissionManagedScope(contestIds: [55])
);
scopeSame(205, $contestPage['total'], '100건 초과 대회의 전체 제출 수가 유지돼야 합니다.');
scopeSame(
    [1005, 1004, 1003, 1002, 1001],
    scopeIds($contestPage['rows']),
    '100건 초과 대회의 마지막 페이지가 누락되지 않아야 합니다.'
);
scopeAssert(
    max($contestGateway->searchLimits) <= 100,
    'Judge DB 검색 배치는 SubmissionSearch 상한 100을 넘으면 안 됩니다.'
);

$largeGateway = new ScopeTestGateway();
$largeContexts = new ScopeTestContexts();
$largeContexts->classIds[77] = range(2001, 7105);
foreach ($largeContexts->classIds[77] as $id) {
    $largeGateway->summaries[$id] = scopeSummary($id, 'class-' . $id);
    $largeContexts->contexts[$id] = new SubmissionContext(0, -1, 77, null);
}
$largePage = scopeQuery($largeGateway, $largeContexts)->managedPage(
    70,
    'manager',
    null,
    null,
    null,
    true,
    '',
    511,
    new SubmissionManagedScope(classIds: [77])
);
scopeSame(5105, $largePage['total'], '5,000건 이후의 담당 수업 제출도 집계돼야 합니다.');
scopeSame(
    [2005, 2004, 2003, 2002, 2001],
    scopeIds($largePage['rows']),
    '5,000건 이후 마지막 페이지까지 조회돼야 합니다.'
);
scopeAssert(
    max($largeGateway->findBatchSizes) <= 500,
    '컨텍스트 제출 상세 조회는 500건 배치를 넘으면 안 됩니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "Task #24 다중 문맥 제출 조회 검사 통과: assertion %d개\n",
        $scopeAssertions
    )
);

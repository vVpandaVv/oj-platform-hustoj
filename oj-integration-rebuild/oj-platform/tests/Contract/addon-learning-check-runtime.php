<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeContext;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonLearningCheckGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestSubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemStatistics;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Application\AiFeedbackSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionAiFeedbackPolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionVisibilityPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionReservation;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$throws = static function (callable $call) use ($check): void {
    try { $call(); } catch (InvalidArgumentException) { $check(true, 'invalid'); return; }
    $check(false, 'Invalid public DTO input must be rejected.');
};

$users = new class implements WordPressUserGateway {
    public ?int $current = 7;
    public bool $throws = false;
    public function currentUser(): ?UserIdentity {
        if ($this->throws) { throw new RuntimeException('private account failure'); }
        return $this->current === null ? null : $this->findById($this->current);
    }
    public function findById(int $userId): ?UserIdentity { return new UserIdentity($userId, 'learner' . $userId, 'private@example.test', '학생'); }
    public function findByLogin(string $userLogin): ?UserIdentity { return $this->findById((int) substr($userLogin, 7)); }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity { return null; }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(string $userLogin, string $email, string $password, string $displayName, string $role): UserIdentity { throw new RuntimeException('unused'); }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity { throw new RuntimeException('unused'); }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(string $userLogin, string $resetKey, string $newPassword): void {}
    public function deleteUser(int $userId): void {}
    public function findMany(array $userIds): array { return []; }
};
$permissions = new class implements Authorization {
    public bool $staff = false;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $this->staff; }
    public function canAny(int $userId, array $capabilities): bool { return $this->staff; }
    public function isSuperAdmin(int $userId): bool { return $this->staff; }
};
$problemRows = new class implements ProblemGateway {
    public array $rows = [];
    public int $reads = 0;
    public function search(ProblemSearch $search): array { return []; }
    public function count(ProblemSearch $search): int { return 0; }
    public function find(ProblemId $problemId): ?JudgeProblem { ++$this->reads; return $this->rows[$problemId->value()] ?? null; }
    public function findMany(array $problemIds): array { return []; }
    public function nextId(): ProblemId { throw new RuntimeException('unused'); }
    public function createNext(ProblemDraft $draft): ProblemId { throw new RuntimeException('unused'); }
    public function create(ProblemId $problemId, ProblemDraft $draft): void {}
    public function update(ProblemId $problemId, ProblemDraft $draft): void {}
    public function archive(ProblemId $problemId, bool $archived): void {}
    public function statistics(ProblemId $problemId): ProblemStatistics { throw new RuntimeException('unused'); }
    public function ranking(ProblemId $problemId, int $limit = 100): array { return []; }
    public function availableColumns(): array { return []; }
};
$assignments = new class implements ProblemAssignmentRepository {
    public function find(ProblemId $problemId): ProblemAssignment { return new ProblemAssignment($problemId, null, []); }
    public function assignOwner(ProblemId $problemId, int $ownerUserId, int $actorUserId): void {}
    public function grant(ProblemId $problemId, int $userId, CollaboratorRole $role, int $actorUserId): void {}
    public function revoke(ProblemId $problemId, int $userId): void {}
    public function problemIdsForUser(int $userId): array { return []; }
};
$metadata = new class implements ProblemMetadataRepository {
    public function find(ProblemId $problemId): ?ProblemMetadata { return null; }
    public function findMany(array $problemIds): array { return []; }
    public function save(ProblemMetadata $metadata): void {}
    public function move(ProblemId $from, ProblemId $to): void {}
};
$ai = new class implements ProblemAiAvailability {
    public function isAvailable(string $taskType): bool { return false; }
};
$contexts = new ProblemContextAccessRegistry();
$classAccess = new class implements ProblemContextAccessProvider {
    public function scope(): string { return 'class'; }
    public function canView(int $userId, int $problemId, ProblemAccessContext $context): bool {
        return $userId === 7 && $problemId === 102 && $context->classId() === 5;
    }
};
$contexts->register($classAccess);
$contexts->register(new class implements ProblemContextAccessProvider {
    public function scope(): string { return 'contest'; }
    public function canView(int $userId, int $problemId, ProblemAccessContext $context): bool {
        return $userId === 7 && $problemId === 101 && $context->contestId() === 8;
    }
});
$problems = new ProblemCatalogService($problemRows, $metadata, $assignments,
    new ProblemAccessPolicy($permissions, $assignments, $ai, $contexts), $permissions);
$makeProblem = static fn (int $id, ProblemVisibility $visibility = ProblemVisibility::Published, string $sample = '3') => new JudgeProblem(
    new ProblemId($id), '덧셈', '<p>두 정수를 더하세요.</p>', '두 정수', '합계', "1 2\n", $sample,
    'public hint', 'source attribution', 1, 128, false, $visibility, 0, 0,
    'SECRET forbidden', 'SECRET prefix', 'public starter', 'SECRET suffix', null
);
$problemRows->rows = [101 => $makeProblem(101), 102 => $makeProblem(102, ProblemVisibility::Archived)];
$rows = new class implements SubmissionGateway {
    public array $rows = [];
    public array $details = [];
    public array $searches = [];
    public array $sourceReads = [];
    public int $countReads = 0;
    public function search(SubmissionSearch $search): array {
        $this->searches[] = $search;
        $matches = array_filter($this->rows, static fn (SubmissionSummary $row): bool =>
            $row->belongsTo($search->userLogin() ?? '') && $row->problemId() === $search->problemId() && $row->isValid());
        krsort($matches);
        return array_slice(array_values($matches), $search->offset(), $search->limit());
    }
    public function count(SubmissionSearch $search): int { ++$this->countReads; return count($this->rows); }
    public function findSummary(SubmissionId $submissionId): ?SubmissionSummary { return $this->rows[$submissionId->value()] ?? null; }
    public function findSummaries(array $submissionIds): array { return []; }
    public function findDetail(SubmissionId $submissionId): ?SubmissionDetail {
        $this->sourceReads[] = $submissionId->value(); return $this->details[$submissionId->value()] ?? null;
    }
    public function createPrepared(PreparedSubmission $submission): SubmissionId { throw new RuntimeException('unused'); }
    public function storeSources(SubmissionId $submissionId, string $userSource, string $judgeSource): void {}
    public function sourcesMatch(SubmissionId $submissionId, string $userSha256, string $judgeSha256, int $userBytes, int $judgeBytes): bool { return false; }
    public function publish(SubmissionId $submissionId): void {}
    public function discardPrepared(SubmissionId $submissionId): void {}
    public function markRecoveryRequired(SubmissionId $submissionId): void {}
    public function latestSubmissionTimestamp(string $userLogin): ?int { return null; }
    public function hasAccepted(string $userLogin, int $problemId, SubmissionContext $context): bool { return false; }
    public function rejudge(SubmissionId $submissionId): int { return 0; }
    public function setValid(SubmissionId $submissionId, bool $valid): bool { return false; }
};
$submissionContexts = new class implements SubmissionContextStore {
    public function save(SubmissionId $submissionId, SubmissionContext $context): void {}
    public function remove(SubmissionId $submissionId): void {}
    public function findMany(array $submissionIds): array { return []; }
    public function submissionIds(?int $classId, ?int $groupId, int $limit, int $offset): array { return []; }
};
$events = new class implements SubmissionEventStore {
    public function reserve(string $requestKey, int $actorUserId, array $metadata): SubmissionReservation { throw new RuntimeException('unused'); }
    public function complete(int $eventId, SubmissionId $submissionId, array $metadata): void {}
    public function fail(int $eventId, string $eventType, string $message, array $metadata): void {}
    public function record(string $eventType, int $actorUserId, ?SubmissionId $submissionId, ?string $requestKey, string $message, array $metadata = []): void {}
    public function recent(int $limit = 100): array { return []; }
};
$settings = new class implements SettingsManager {
    public function read(string $key, mixed $default = null): SettingsSnapshot { return new SettingsSnapshot(1, []); }
    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot { throw new RuntimeException('unused'); }
};
$performance = new class implements PerformanceModePolicy {
    public bool $restricted = false;
    public function restrictsOwnSubmissionDetails(int $userId): bool { return $this->restricted; }
};
$results = new class implements JudgeResults {
    public function find(int $code): JudgeResult { return new JudgeResult($code, 'OJ_AC', '결과', 'success', $code < 4); }
    public function all(): array { return []; }
};
$errors = new class implements ErrorReporter { public function report(Throwable $error): void { throw $error; } };
$queries = new SubmissionQueryService($rows, $submissionContexts, $events,
    new SubmissionVisibilityPolicy($permissions, $performance, new SubmissionStaffScopeRegistry()),
    new SubmissionSettingsProvider($settings), $results, $errors);
$contests = new class implements ContestSubmissionAccess {
    public bool $allowed = false;
    public function canViewProblem(int $userId, int $contestId, int $problemId, ?int $problemNumber): bool { return true; }
    public function validateSubmission(int $userId, int $contestId, int $problemId, int $problemNumber, string $ipAddress): int { return 0; }
    public function allowsAiFeedback(int $contestId): bool { return $this->allowed; }
    public function submissionBlocklyProfile(int $contestId): ?string { return null; }
    public function canViewSubmission(int $actorUserId, int $contestId): bool { return true; }
};
$policies = new SubmissionAiFeedbackPolicyRegistry();
$policies->register(new ContestSubmissionAiFeedbackPolicy($contests));
$feedback = new AiFeedbackSubmissionService($queries, $users, $policies);
$correlations = new AddonCorrelationIdFactory();
$gateway = new RealAddonLearningCheckGateway('focus-tracker', $problems, $queries, $feedback, $users, $correlations, $contests);
$makeSubmission = static fn (int $id, int $problem = 101, int $user = 7, int $contest = 0, bool $valid = true, int $language = 6) => new SubmissionSummary(
    new SubmissionId($id), $problem, 'learner' . $user, 'private nickname', $language, 4, 1, 100,
    '2026-10-23 10:00:00', '', $contest, 0, null, null, $valid
);
$addSubmission = static function (int $id, int $problem = 101, int $user = 7, int $contest = 0, bool $valid = true, int $language = 6) use ($rows, $makeSubmission): void {
    $summary = $makeSubmission($id, $problem, $user, $contest, $valid, $language);
    $rows->rows[$id] = $summary;
    $rows->details[$id] = new SubmissionDetail($summary, 'print(' . $id . ')', 'SECRET judge wrapper', 'SECRET compile diagnostic', 'SECRET hidden testcase');
};

$publicOnly = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'general', null, 'problem'));
$check($publicOnly->isSuccessful() && $publicOnly->payload()['submission'] === null
    && $publicOnly->payload()['latest_own_submission_id'] === null && $rows->searches === [] && $rows->countReads === 0,
    'Public-only checks must authorize the problem without reading submissions or inventing a baseline.');
$empty = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check($empty->isSuccessful() && $empty->payload()['submission'] === null, 'A learner without any submission must receive the public problem context.');
$check($empty->payload()['latest_own_submission_id'] === 0, 'A confirmed empty own summary page must provide the zero baseline for a first submission.');
$check($empty->payload()['problem']['sample_input'] === "1 2\n" && $empty->payload()['problem']['sample_output'] === '3', 'Only explicit public sample fields may be projected.');
$check(! str_contains(json_encode($empty->payload()), 'SECRET'), 'Problem internal code must never be projected.');
$reads = $problemRows->reads;
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(8, 101))->isSuccessful() && $problemRows->reads === $reads, 'Mismatched actors must be rejected before problem or source reads.');
$users->current = null;
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101))->isSuccessful(), 'Logged out access must fail.');
$users->current = 7;
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 999))->isSuccessful(), 'Missing problems must fail closed.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 102))->isSuccessful(), 'Hidden problems must not be exposed by general access.');
$check($gateway->context(new AddonLearningCheckContextQueryV1(7, 102, null, 'class', 5))->isSuccessful(), 'Authorized hidden classroom problems must be supported.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 102, null, 'class', 6))->isSuccessful(), 'Unrelated classroom access must fail.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'contest', 8))->isSuccessful(), 'Active contests must respect the existing feedback restriction before draft/sample checks.');
$contests->allowed = true;
$check($gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'contest', 8))->isSuccessful(), 'Contest checks may resume only when the contest policy permits.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'contest', 9))->isSuccessful(), 'Contest problem access must still be authorized.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'group', 5))->isSuccessful(), 'Missing optional group membership services must deny group requests.');
$contests->allowed = false;
$addSubmission(11);
$addSubmission(12, 102);
$addSubmission(13, 101, 8);
$explicit = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 11));
$check($explicit->isSuccessful() && $explicit->payload()['submission']['source'] === 'print(11)', 'The learner must receive their source without judge wrappers.');
$check($explicit->payload()['latest_own_submission_id'] === null, 'An explicit submission query must not claim to have read a latest-submission baseline.');
$check($explicit->payload()['submission']['language'] === 'python' && $explicit->payload()['submission']['language_id'] === 6, 'The public projection must use the shared language catalog.');
foreach ([13 => 'c', 14 => 'cpp'] as $languageId => $languageKey) {
    $addSubmission(11, language: $languageId);
    $alias = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 11));
    $check($alias->isSuccessful() && $alias->payload()['submission']['language'] === $languageKey
        && $alias->payload()['submission']['language_id'] === $languageId,
        'Clang aliases must preserve the original compiler ID and expose the canonical source language.');
}
$addSubmission(11);
$check(! str_contains(json_encode($explicit->payload()), 'SECRET') && ! str_contains(json_encode($explicit->payload()), 'private@'), 'Diagnostics, hidden tests and private identities must never be projected.');
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 12))->isSuccessful(), 'Cross-problem submission references must fail.');
$permissions->staff = true;
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 13))->isSuccessful(), 'Staff source permissions must not allow other learner source in self checks.');
$permissions->staff = false;
$performance->restricted = true;
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 11))->isSuccessful(), 'Result-only performance mode must not expose source.');
$restrictedLatest = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check($restrictedLatest->isSuccessful() && $restrictedLatest->payload()['submission'] === null
    && $restrictedLatest->payload()['latest_own_submission_id'] === 11,
    'Source restrictions must remain intact while retaining only the current learner own summary baseline.');
$performance->restricted = false;
$addSubmission(14, 101, 7, 8);
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 14))->isSuccessful(), 'A general context must not bypass a submission contest policy.');
$addSubmission(15, 101, 7, 0, false);
$check(! $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, 15))->isSuccessful(), 'Invalidated submissions cannot be used as learning check evidence.');
$latest = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check($latest->isSuccessful() && $latest->payload()['submission']['id'] === 11, 'Latest lookup must skip restricted source, other learners, other problems and invalid submissions.');
$check($latest->payload()['latest_own_submission_id'] === 14, 'The baseline must include a source-hidden own valid submission but exclude foreign, cross-problem and invalid summaries.');
$search = $rows->searches[array_key_last($rows->searches)];
$check($search->userLogin() === 'learner7' && $search->problemId() === 101 && $search->offset() === 0 && $search->limit() <= 100, 'Latest lookup must use the bounded own-page service with current problem filtering.');
foreach (range(20, 27) as $id) { $addSubmission($id, 101, 7, 8); }
$rows->sourceReads = [];
$latest = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check($latest->isSuccessful() && $latest->payload()['submission'] === null && count($rows->sourceReads) === 6, 'Source lookup must stop after six candidates without unbounded history scans.');
$check($latest->payload()['latest_own_submission_id'] === 27, 'A hidden newest own summary must not disappear from the baseline when no source can be projected.');
$beforeCounts = $rows->countReads; $rows->sourceReads = [];
$narrow = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'general', null, 'latest'));
$lastSearch = $rows->searches[array_key_last($rows->searches)];
$check($narrow->isSuccessful() && $narrow->payload()['submission'] === null && $narrow->payload()['latest_own_submission_id'] === 27
    && $lastSearch->limit() === 1 && $rows->countReads === $beforeCounts && count($rows->sourceReads) <= 1,
    'Narrow latest reads retain hidden-source baselines without COUNT or scanning older sources.');
$addSubmission(28); $rows->sourceReads = [];
$narrow = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'general', null, 'latest'));
$check($narrow->isSuccessful() && $narrow->payload()['submission']['id'] === 28 && $rows->sourceReads === [28]
    && $rows->countReads === $beforeCounts, 'Narrow latest returns one authorized own source and performs no total count.');
$performance->restricted = true;
$check($gateway->context(new AddonLearningCheckContextQueryV1(7, 101, null, 'general', null, 'latest'))->payload()['submission'] === null,
    'Narrow latest must retain full source visibility restrictions.');
$performance->restricted = false;
$problemRows->rows[101] = $makeProblem(101, ProblemVisibility::Published, '4');
$updated = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check($updated->payload()['problem']['content_hash'] !== $empty->payload()['problem']['content_hash'], 'Changing public samples must invalidate the content hash.');
$users->throws = true;
$failed = $gateway->context(new AddonLearningCheckContextQueryV1(7, 101));
$check(! $failed->isSuccessful() && $failed->payload() === [] && ! str_contains($failed->problem()?->message() ?? '', 'private'), 'Provider errors must return empty safe failures.');
$users->throws = false;
$factory = new UnavailableAddonRuntimeServiceFactory($correlations);
$runtime = new AddonRuntimeContext('focus-tracker', null, '2.3.20', '1.4.0', '', $factory->contexts('focus-tracker', null), $factory->aiTasks('focus-tracker', null), $correlations);
$check($runtime instanceof AddonLearningCheckRuntimeContextV1 && ! $runtime->learningChecks()->context(new AddonLearningCheckContextQueryV1(7, 101))->isSuccessful(), 'Existing runtime construction must retain compatibility and provide an unavailable optional gateway.');
$runtime = new AddonRuntimeContext('focus-tracker', null, '2.3.20', '1.4.0', '', $factory->contexts('focus-tracker', null), $factory->aiTasks('focus-tracker', null), $correlations, null, null, $gateway);
$check($runtime->learningChecks()->context(new AddonLearningCheckContextQueryV1(7, 101))->isSuccessful(), 'The optional runtime extension must expose the real current learner gateway.');

foreach ([
    fn () => new AddonLearningCheckContextQueryV1(0, 101),
    fn () => new AddonLearningCheckContextQueryV1(7, 0),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, 0),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, null, 'class'),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, null, 'general', 5),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, null, 'invalid', 5),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, null, 'general', null, 'invalid'),
    fn () => new AddonLearningCheckContextQueryV1(7, 101, 11, 'general', null, 'problem'),
    fn () => new AddonLearningCheckContextResultV1(true, [], null),
    fn () => new AddonLearningCheckContextResultV1(false, $empty->payload(), new AddonApiProblemV1('addon_access_denied', '거부', 'test:deny', false)),
] as $invalid) { $throws($invalid); }
$bad = $explicit->payload();
$bad['problem']['private_test'] = 'hidden';
$throws(fn () => new AddonLearningCheckContextResultV1(true, $bad, null));
$bad = $explicit->payload();
$bad['submission']['problem_id'] = 102;
$throws(fn () => new AddonLearningCheckContextResultV1(true, $bad, null));
$bad = $explicit->payload();
$bad['submission']['source'] = str_repeat('x', 65537);
$throws(fn () => new AddonLearningCheckContextResultV1(true, $bad, null));
$legacy = $explicit->payload();
unset($legacy['latest_own_submission_id']);
$check((new AddonLearningCheckContextResultV1(true, $legacy, null))->isSuccessful(), 'Existing DTO callers without the optional baseline field must remain compatible.');
foreach ([-1, '11', 1.5, 10] as $invalidBaseline) {
    $bad = $explicit->payload();
    $bad['latest_own_submission_id'] = $invalidBaseline;
    $throws(fn () => new AddonLearningCheckContextResultV1(true, $bad, null));
}

fwrite(STDOUT, "Addon learning check runtime: {$checks} checks passed\n");

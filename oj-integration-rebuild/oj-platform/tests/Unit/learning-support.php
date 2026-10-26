<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackOutputValidator;
use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelCalculator;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceBatchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Application\LearningDashboardService;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummary;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummaryProvider;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearningDashboardCache;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\LearningDashboardSnapshot;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Ranking\Application\RankingService;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\JudgeUserActivity;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\JudgeUserActivityProvider;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingSnapshotCache;
use CodingPanda\OjPlatform\Modules\Ranking\Infrastructure\WordPress\WordPressRankingSnapshotCache;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$learningSupportAssertions = 0;

function learningSupportAssert(bool $condition, string $message): void
{
    global $learningSupportAssertions;
    ++$learningSupportAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function learningSupportSame(
    mixed $expected,
    mixed $actual,
    string $message
): void {
    learningSupportAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function learningSupportThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        learningSupportAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class LearningSupportProblems implements LearningProblemProvider
{
    /**
     * @var list<int>
     */
    public array $catalogLimits = [];

    public int $findManyCalls = 0;

    /**
     * @param array<int, LearningProblem> $items
     */
    public function __construct(public array $items)
    {
    }

    public function findForUser(
        int $userId,
        int $problemId
    ): ?LearningProblem {
        unset($userId);

        return $this->items[$problemId] ?? null;
    }

    public function findManyForUser(int $userId, array $problemIds): array
    {
        unset($userId);
        ++$this->findManyCalls;
        $index = array_fill_keys($problemIds, true);

        return array_intersect_key($this->items, $index);
    }
    public function catalogForUser(int $userId, int $limit = 500): array
    {
        unset($userId);
        $this->catalogLimits[] = $limit;

        return array_slice(array_values($this->items), 0, $limit);
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        unset($userId);

        return array_slice(array_values($this->items), 0, $limit);
    }

    public function publicCatalog(int $limit = 500): array
    {
        return array_slice(array_values(array_filter(
            $this->items,
            static fn (LearningProblem $problem): bool =>
                $problem->isPublished()
        )), 0, $limit);
    }

    public function catalogRevision(): string
    {
        return hash('sha256', implode(',', array_keys($this->items)));
    }
}

final class LearningSupportSubmissions implements SubmissionProjectionProvider
{
    public int $reportCalls = 0;

    /**
     * @param list<SubmissionProjection> $items
     */
    public function __construct(public array $items)
    {
    }

    public function byIds(
        int $actorUserId,
        string $actorUserLogin,
        array $submissionIds
    ): array {
        unset($actorUserId, $actorUserLogin);
        $index = array_fill_keys($submissionIds, true);

        return array_values(array_filter(
            $this->items,
            static fn (SubmissionProjection $item): bool =>
                isset($index[$item->submissionId()])
        ));
    }

    public function search(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        unset($actorUserId);

        return $this->filtered($query, [$actorUserLogin]);
    }

    public function report(SubmissionProjectionQuery $query): array
    {
        ++$this->reportCalls;

        return $this->filtered($query, $query->userLogins());
    }

    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        unset($actorUserId);

        return $this->filtered($query, [$actorUserLogin], false);
    }

    public function reportAll(SubmissionProjectionQuery $query): array
    {
        ++$this->reportCalls;

        return $this->filtered($query, $query->userLogins(), false);
    }

    /**
     * @param list<string> $userLogins
     * @return list<SubmissionProjection>
     */
    private function filtered(
        SubmissionProjectionQuery $query,
        array $userLogins,
        bool $paginate = true
    ): array {
        $items = array_values(array_filter(
            $this->items,
            static function (
                SubmissionProjection $item
            ) use ($userLogins, $query): bool {
                return (
                    $userLogins === []
                    || in_array($item->userLogin(), $userLogins, true)
                )
                    && (
                        $query->contestId() === null
                        || $item->contestId() === $query->contestId()
                    )
                    && (
                        $query->resultCode() === null
                        || $item->resultCode() === $query->resultCode()
                    );
            }
        ));

        return $paginate
            ? array_slice($items, $query->offset(), $query->limit())
            : $items;
    }
}

final class LearningSupportHistoryEligibility implements LearningHistoryEligibility
{
    public function includes(
        int $userId,
        SubmissionProjection $submission
    ): bool {
        unset($userId);

        return $submission->contestId() === 0;
    }
}

final class LearningSupportLevels implements LearnerLevelSummaryProvider
{
    public function __construct(private readonly bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function summaryFor(
        int $userId,
        int $lastSubmissionId
    ): ?LearnerLevelSummary {
        unset($userId, $lastSubmissionId);

        return new LearnerLevelSummary(
            'classified',
            'intermediate',
            2,
            55.0,
            '2026-07-29 00:00:00'
        );
    }
}

final class LearningSupportClock implements Clock
{
    private readonly DateTimeImmutable $date;

    public function __construct(string $date)
    {
        $this->date = new DateTimeImmutable(
            $date,
            new DateTimeZone('Asia/Seoul')
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->date;
    }
}

final class LearningSupportNoCache implements LearningDashboardCache
{
    public function get(string $key): ?LearningDashboardSnapshot
    {
        unset($key);

        return null;
    }

    public function put(
        string $key,
        LearningDashboardSnapshot $snapshot,
        int $ttlSeconds
    ): void {
        unset($key, $snapshot, $ttlSeconds);
    }
}

final class LearningSupportUsers implements WordPressUserGateway
{
    /**
     * @param array<string, UserIdentity> $users
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
        foreach ($this->users as $user) {
            if ($user->userId() === $userId) {
                return $user;
            }
        }

        return null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        return $this->users[$userLogin] ?? null;
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
        $user = new UserIdentity(
            count($this->users) + 1,
            $userLogin,
            $email,
            $displayName,
            [$role]
        );
        $this->users[$userLogin] = $user;

        return $user;
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        $user = $this->findById($userId);
        if ($user === null) {
            throw new RuntimeException('사용자를 찾을 수 없습니다.');
        }

        return new UserIdentity(
            $userId,
            $user->userLogin(),
            $email,
            $displayName,
            $user->roles()
        );
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

    public function findMany(array $userIds): array
    {
        return array_values(array_filter(
            $this->users,
            static fn (UserIdentity $user): bool =>
                in_array($user->userId(), $userIds, true)
        ));
    }
}

final class LearningSupportPreferences implements
    UserPreferenceRepository,
    UserPreferenceBatchProvider
{
    public int $findCalls = 0;
    public int $findManyCalls = 0;

    /**
     * @param array<int, UserPreferences> $preferences
     */
    public function __construct(private array $preferences)
    {
    }

    public function find(int $userId): ?UserPreferences
    {
        ++$this->findCalls;

        return $this->preferences[$userId] ?? null;
    }

    public function findMany(array $userIds): array
    {
        ++$this->findManyCalls;
        $index = array_fill_keys($userIds, true);

        return array_intersect_key($this->preferences, $index);
    }

    public function save(UserPreferences $preferences): void
    {
        $this->preferences[$preferences->userId()] = $preferences;
    }
}

final class LearningSupportRankingCache implements RankingSnapshotCache
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    public array $rows = [];

    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function put(string $key, array $rows, int $ttlSeconds): void
    {
        learningSupportAssert($ttlSeconds === 60, '공개 순위 캐시는 60초여야 합니다.');
        $this->rows[$key] = $rows;
    }
}

final class LearningSupportJudgeActivity implements JudgeUserActivityProvider
{
    public int $activeUsersCalls = 0;

    /**
     * @param list<JudgeUserActivity> $activity
     */
    public function __construct(private readonly array $activity)
    {
    }

    public function activeUsers(): array
    {
        ++$this->activeUsersCalls;

        return $this->activity;
    }
}

final class LearningSupportPrivateRoot implements PrivateDataRootProvider
{
    public function __construct(private readonly string $path)
    {
    }

    public function root(): string
    {
        return $this->path;
    }
}

/**
 * @param list<string> $topics
 */
function learningSupportProblem(
    int $id,
    ?int $difficulty,
    array $topics,
    bool $published = true,
    int $accepted = 10,
    int $submitted = 20
): LearningProblem {
    return new LearningProblem(
        $id,
        '문제 ' . $id,
        '설명',
        '입력',
        '출력',
        '힌트',
        $difficulty,
        $topics,
        $accepted,
        $submitted,
        $published,
        hash('sha256', 'problem-' . $id)
    );
}

function learningSupportSubmission(
    int $id,
    int $problemId,
    string $login,
    int $result = 4,
    int $contestId = 0,
    ?string $submittedAt = null,
    bool $processing = false
): SubmissionProjection {
    return new SubmissionProjection(
        $id,
        $problemId,
        $login,
        $result,
        6,
        10,
        1024,
        $submittedAt ?? sprintf('2026-07-29 00:%02d:00', $id % 60),
        true,
        $result === 4 ? 100.0 : 0.0,
        $contestId,
        $result === 4 ? '정답입니다' : '틀렸습니다',
        $processing
    );
}

$estimated = learningSupportProblem(900, null, ['math'], true, 70, 100);
learningSupportSame(1, $estimated->effectiveDifficulty(), '정답률 70%는 난이도 1이어야 합니다.');
learningSupportSame('accepted_rate', $estimated->difficultySource(), '추정 난이도 출처를 구분해야 합니다.');
learningSupportSame(
    null,
    learningSupportProblem(901, null, [], true, 2, 9)->effectiveDifficulty(),
    '제출 10건 미만 문제는 난이도를 추정하면 안 됩니다.'
);

$levelProblems = [];
foreach (range(1, 10) as $id) {
    $levelProblems[$id] = learningSupportProblem(
        $id,
        1,
        ['topic-' . (($id % 4) + 1)]
    );
}
foreach (range(11, 18) as $id) {
    $levelProblems[$id] = learningSupportProblem(
        $id,
        2,
        ['topic-' . (($id % 4) + 1)]
    );
}
$levelSubmissions = [];
foreach (range(1, 18) as $id) {
    $levelSubmissions[] = learningSupportSubmission($id, $id, 'learner');
}
$levelSubmissions[] = learningSupportSubmission(19, 1, 'learner', 6);
$levelSubmissions[] = learningSupportSubmission(20, 2, 'learner', 6);
$levelSubmissions[] = learningSupportSubmission(
    21,
    3,
    'learner',
    0,
    0,
    null,
    true
);
$calculator = new LearnerLevelCalculator();
$profile = $calculator->calculate(
    10,
    $levelSubmissions,
    $levelProblems,
    '2026-07-29 00:00:00'
);
learningSupportSame('classified', $profile->analysisStatus(), '20회·8문제 이상은 수준 분류 상태여야 합니다.');
learningSupportSame(2, $profile->provenTier(), '충족 점수가 가장 높은 난이도 2를 선택해야 합니다.');
learningSupportSame('intermediate', $profile->level(), '분류 가능한 중간 점수는 중급이어야 합니다.');
learningSupportSame(20, $profile->submissionCount(), '채점 중 제출을 제외한 유효 완료 제출 수를 계산해야 합니다.');
learningSupportSame(18, $profile->attemptedProblemCount(), '도전 문제 수를 중복 없이 계산해야 합니다.');

$higherTierProblems = [];
foreach ([3, 3, 4, 4, 5, 5, 1, 1] as $offset => $difficulty) {
    $topic = match ($difficulty) {
        3 => 'tier-three',
        4 => 'tier-four',
        5 => 'tier-five',
        default => 'foundation',
    };
    $higherTierProblems[100 + $offset] = learningSupportProblem(
        100 + $offset,
        $difficulty,
        [$topic]
    );
}
$higherTierSubmissions = [];
foreach (range(100, 105) as $problemId) {
    $higherTierSubmissions[] = learningSupportSubmission(
        count($higherTierSubmissions) + 1,
        $problemId,
        'learner'
    );
}
foreach (range(1, 14) as $attempt) {
    $higherTierSubmissions[] = learningSupportSubmission(
        count($higherTierSubmissions) + 1,
        106 + ($attempt % 2),
        'learner',
        6
    );
}
$higherTierProfile = $calculator->calculate(
    10,
    $higherTierSubmissions,
    $higherTierProblems,
    '2026-07-29 00:00:00'
);
learningSupportSame(
    4,
    $higherTierProfile->provenTier(),
    '증명 난이도는 해당 단계보다 높은 난이도의 해결 문제와 주제도 함께 계산해야 합니다.'
);

$insufficient = $calculator->calculate(
    10,
    array_slice($levelSubmissions, 0, 9),
    $levelProblems,
    '2026-07-29 00:00:00'
);
learningSupportSame('insufficient', $insufficient->analysisStatus(), '10회 미만은 자료 부족이어야 합니다.');
learningSupportSame(null, $insufficient->level(), '자료 부족 상태에는 수준을 표시하면 안 됩니다.');
learningSupportSame(null, $insufficient->metric('skill_score'), '자료 부족 상태에는 점수를 표시하면 안 됩니다.');

$dashboardProblems = [];
foreach (range(1, 14) as $id) {
    $dashboardProblems[$id] = learningSupportProblem(
        $id,
        (($id - 1) % 5) + 1,
        ['topic-' . (($id % 6) + 1)]
    );
}
$dashboardSubmissions = new LearningSupportSubmissions([
    learningSupportSubmission(1, 1, 'learner', 6),
    learningSupportSubmission(2, 1, 'learner'),
    learningSupportSubmission(3, 2, 'learner', 6),
    learningSupportSubmission(4, 3, 'learner', 4, 55),
    learningSupportSubmission(5, 4, 'learner', 0, 0, null, true),
]);
$dashboardProblemProvider = new LearningSupportProblems($dashboardProblems);
$dashboard = new LearningDashboardService(
    $dashboardSubmissions,
    $dashboardProblemProvider,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
);
$learner = new UserIdentity(
    10,
    'learner',
    'learner@example.com',
    '학습자'
);
$identityStableDashboard = new LearningDashboardService(
    new LearningSupportSubmissions([]),
    $dashboardProblemProvider,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
);
$beforeLoginChange = $identityStableDashboard->snapshot(
    new UserIdentity(10, 'old-login', 'a@example.com', '학습자'),
    0
);
$afterLoginChange = $identityStableDashboard->snapshot(
    new UserIdentity(10, 'new-login', 'a@example.com', '학습자'),
    0
);
learningSupportSame(
    array_map(
        static fn ($item): int => $item->problemId(),
        $beforeLoginChange->recommendations()
    ),
    array_map(
        static fn ($item): int => $item->problemId(),
        $afterLoginChange->recommendations()
    ),
    '추천 회전은 로그인 아이디가 아닌 WordPress user_id와 정책 버전을 기준으로 해야 합니다.'
);

$firstDashboard = $dashboard->snapshot($learner, 0);
$nextDashboard = $dashboard->snapshot($learner, 1);
learningSupportSame(3, $firstDashboard->submissionCount(), '채점 중 제출과 진행 중 대회 제출을 제외하고 본인의 유효 학습 이력만 집계해야 합니다.');
learningSupportSame(1, $firstDashboard->solvedProblemCount(), '정답 문제를 중복 없이 집계해야 합니다.');
learningSupportSame(1, $firstDashboard->recentSolvedProblemCount(), '최근 30일에 해결한 고유 문제를 집계해야 합니다.');
learningSupportSame(1, $firstDashboard->recoveredProblemCount(), '오답 뒤 정답을 받은 고유 문제를 집계해야 합니다.');
$siteBoundaryDashboard = (new LearningDashboardService(
    new LearningSupportSubmissions([
        learningSupportSubmission(
            200,
            1,
            'learner',
            4,
            0,
            '2026-06-29 03:30:00'
        ),
    ]),
    $dashboardProblemProvider,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
))->snapshot($learner);
learningSupportSame(
    1,
    $siteBoundaryDashboard->recentSolvedProblemCount(),
    '최근 30일 경계는 WordPress 지역시를 Judge UTC 시각으로 변환해 비교해야 합니다.'
);
learningSupportSame(2, $firstDashboard->continueProblems()[0]->problemId(), '미해결 최근 문제를 이어서 풀기에 표시해야 합니다.');
learningSupportSame(6, count($firstDashboard->recommendations()), '추천 문제는 최대 6개여야 합니다.');
learningSupportSame(
    '최근 미해결 문제 다시 도전',
    $firstDashboard->recommendations()[0]->reason(),
    '아직 해결하지 못한 최근 문제를 추천 후보에서 제외하면 안 됩니다.'
);
learningSupportSame(
    [100000],
    array_values(array_unique($dashboardProblemProvider->catalogLimits)),
    '내 학습과 수준 분석은 500개 임의 제한 없이 접근 가능한 전체 문제를 읽어야 합니다.'
);learningSupportSame(
    array_map(
        static fn ($item): int => $item->problemId(),
        array_slice($firstDashboard->recommendations(), 0, 2)
    ),
    array_map(
        static fn ($item): int => $item->problemId(),
        array_slice($nextDashboard->recommendations(), 0, 2)
    ),
    '추천 상위 2개는 다른 추천 보기에서도 고정되어야 합니다.'
);
learningSupportAssert(
    array_map(
        static fn ($item): int => $item->problemId(),
        array_slice($firstDashboard->recommendations(), 2)
    ) !== array_map(
        static fn ($item): int => $item->problemId(),
        array_slice($nextDashboard->recommendations(), 2)
    ),
    '추천 묶음 번호를 바꾸면 회전 추천 4개가 달라져야 합니다.'
);
$tomorrowDashboard = (new LearningDashboardService(
    $dashboardSubmissions,
    $dashboardProblemProvider,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-30 12:00:00'),
    new LearningSupportNoCache()
))->snapshot($learner, 0);
$todayRotating = array_map(
    static fn ($item): int => $item->problemId(),
    array_slice($firstDashboard->recommendations(), 2)
);
$tomorrowRotating = array_map(
    static fn ($item): int => $item->problemId(),
    array_slice($tomorrowDashboard->recommendations(), 2)
);
learningSupportSame(
    [],
    array_values(array_intersect($todayRotating, $tomorrowRotating)),
    '회전 후보가 8개 이상이면 다음 날 추천 4개는 전날 추천을 우선 제외해야 합니다.'
);

$targetDashboard = (new LearningDashboardService(
    new LearningSupportSubmissions([
        learningSupportSubmission(100, 40, 'learner'),
    ]),
    new LearningSupportProblems([
        30 => learningSupportProblem(30, 3, ['shared']),
        40 => learningSupportProblem(40, 4, ['shared']),
        50 => learningSupportProblem(50, 5, ['shared']),
    ]),
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
))->snapshot($learner);
learningSupportSame(
    50,
    $targetDashboard->recommendations()[0]->problemId(),
    '추천 목표 난이도는 수준 이름보다 가장 최근 해결 문제의 다음 단계를 우선해야 합니다.'
);
learningSupportSame(
    '최근 해결 난이도보다 한 단계 도전',
    $targetDashboard->recommendations()[0]->reason(),
    '최근 해결 난이도 다음 단계라는 추천 근거를 표시해야 합니다.'
);

$privateHistoryProblems = new LearningSupportProblems([
    99 => learningSupportProblem(99, 2, ['private'], false),
]);
$privateHistoryDashboard = (new LearningDashboardService(
    new LearningSupportSubmissions([
        learningSupportSubmission(200, 99, 'learner', 6),
    ]),
    $privateHistoryProblems,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
))->snapshot($learner);
learningSupportSame(
    1,
    $privateHistoryProblems->findManyCalls,
    '공개 목록에 없는 이력 문제는 한 번의 접근 적용 일괄 조회로 보완해야 합니다.'
);
learningSupportSame(
    99,
    $privateHistoryDashboard->continueProblems()[0]->problemId(),
    '현재 접근 가능한 비공개 이력 문제는 이어서 풀기에 유지해야 합니다.'
);
learningSupportSame(
    [],
    $privateHistoryDashboard->recommendations(),
    '비공개 문제는 접근 가능하더라도 일반 추천 후보에는 포함하면 안 됩니다.'
);
$levelDisabledDashboard = (new LearningDashboardService(
    $dashboardSubmissions,
    $dashboardProblemProvider,
    new LearningSupportHistoryEligibility(),
    new LearningSupportLevels(false),
    new LearningSupportClock('2026-07-29 12:00:00'),
    new LearningSupportNoCache()
))->snapshot($learner, 0);
learningSupportSame(
    false,
    $levelDisabledDashboard->levelAvailable(),
    'AI 피드백 기능이 꺼지면 내 학습의 수준 영역을 숨겨야 합니다.'
);
learningSupportSame(
    null,
    $levelDisabledDashboard->level(),
    'AI 피드백 기능이 꺼진 상태에서는 수준 프로필을 조회하지 않아야 합니다.'
);

$rankingActivity = new LearningSupportJudgeActivity([
    new JudgeUserActivity('alice', 4, 3, 2),
    new JudgeUserActivity('bob', 2, 2, 2),
    new JudgeUserActivity('charlie', 2, 1, 1),
    new JudgeUserActivity('dana', 1, 1, 1),
    new JudgeUserActivity('eve', 1, 1, 1),
]);
$rankingUsers = new LearningSupportUsers([
    'alice' => new UserIdentity(1, 'alice', 'a@example.com', '가람'),
    'bob' => new UserIdentity(2, 'bob', 'b@example.com', '나래'),
    'charlie' => new UserIdentity(3, 'charlie', 'c@example.com', '다온'),
    'dana' => new UserIdentity(4, 'dana', 'd@example.com', '라온'),
    'eve' => new UserIdentity(5, 'eve', 'e@example.com', '마루'),
]);
$rankingPreferences = new LearningSupportPreferences([
    1 => new UserPreferences(1, 'members', 'members', 'members'),
    2 => new UserPreferences(2, 'members', 'members', 'members'),
    3 => new UserPreferences(3, 'hidden', 'members', 'members'),
    5 => new UserPreferences(5, 'members', 'hidden', 'members'),
]);
$rankingCache = new LearningSupportRankingCache();
$ranking = new RankingService(
    $rankingActivity,
    $rankingUsers,
    $rankingPreferences,
    $rankingCache
);
$entries = $ranking->entries();
learningSupportSame(
    1,
    $rankingPreferences->findManyCalls,
    '랭킹 공개 설정은 참가자 전체를 한 번에 조회해야 합니다.'
);
learningSupportSame(
    0,
    $rankingPreferences->findCalls,
    '랭킹 생성 중 참가자별 환경설정을 반복 조회하면 안 됩니다.'
);
learningSupportSame(4, count($entries), '랭킹 공개 설정을 숨김으로 바꾼 사용자는 순위에서 제외해야 합니다.');
learningSupportSame(['가람', '나래', '다온', '라온'], array_map(
    static fn ($entry): string => $entry->displayName(),
    $entries
), '공개 상시 문제의 해결 수로 정렬하고 정답률은 순위에 영향을 주지 않아야 합니다.');
learningSupportSame([1, 1, 3, 3], array_map(
    static fn ($entry): int => $entry->rank(),
    $entries
), '해결 수가 같으면 정답률과 관계없이 공동 순위를 사용해야 합니다.');
learningSupportSame(2, $entries[0]->solvedProblemCount(), '해결한 공개 상시 문제 수를 중복 없이 사용해야 합니다.');
learningSupportSame(4, $entries[0]->submissionCount(), '정답률 분모는 완료된 유효 상시 제출 수여야 합니다.');
learningSupportSame(3, $entries[0]->acceptedSubmissionCount(), '정답률 분자는 정답 처리된 제출 수여야 합니다.');
learningSupportAssert(
    abs($entries[0]->accuracyRate() - 0.75) < 0.0001,
    '정답률은 정답 제출 수를 완료된 유효 제출 수로 나누어야 합니다.'
);
learningSupportAssert(
    abs($entries[1]->accuracyRate() - 1.0) < 0.0001,
    '모든 완료 제출이 정답이면 정답률이 100%여야 합니다.'
);
learningSupportAssert(
    $entries[0]->isProfileVisible(),
    '프로필 공개 설정이 members이면 랭킹에서 공개 프로필 링크를 허용해야 합니다.'
);
learningSupportAssert(
    ! $entries[2]->isProfileVisible(),
    '프로필 공개 설정이 hidden이면 랭킹의 프로필 링크를 숨겨야 합니다.'
);
learningSupportAssert(
    $entries[3]->isProfileVisible(),
    '환경설정을 저장한 적 없는 참가자는 기본값(공개)으로 취급해야 합니다.'
);
$cachedActivity = $rankingCache->rows['activity:v6'][0] ?? [];
learningSupportAssert(
    isset($cachedActivity['user_id'])
        && ! isset($cachedActivity['user_login']),
    '랭킹 캐시에는 숫자 user_id만 저장하고 Judge 로그인 문자열을 남기면 안 됩니다.'
);
$callsBeforeCache = $rankingActivity->activeUsersCalls;
$ranking->entries();
learningSupportSame($callsBeforeCache, $rankingActivity->activeUsersCalls, '60초 캐시가 같은 집계를 다시 읽지 않아야 합니다.');
$busyCache = new class($rankingCache) implements RankingSnapshotCache, \CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingRefreshCache {
    public bool $hasStale = true;
    public function __construct(private RankingSnapshotCache $source) {}
    public function get(string $key): ?array { return null; }
    public function put(string $key, array $rows, int $ttlSeconds): void { throw new RuntimeException('동시 갱신 금지'); }
    public function acquireRefresh(string $key): bool { return false; }
    public function releaseRefresh(string $key): void { throw new RuntimeException('다른 요청의 잠금 해제 금지'); }
    public function stale(string $key): ?array { return $this->hasStale ? $this->source->get($key) : null; }
};
$busyRanking = new RankingService($rankingActivity, $rankingUsers, $rankingPreferences, $busyCache);
for ($request = 0; $request < 36; ++$request) {
    learningSupportSame(count($entries), count($busyRanking->entries()), '갱신 중인 요청은 이전 랭킹을 재사용해야 합니다.');
}
learningSupportSame($callsBeforeCache, $rankingActivity->activeUsersCalls, '갱신 대기 요청 36개가 제출 전체 집계를 반복하면 안 됩니다.');
$busyCache->hasStale = false;
try {
    $busyRanking->entries();
    throw new RuntimeException('초기 랭킹 갱신 중임을 알려야 합니다.');
} catch (DomainException $exception) {
    learningSupportAssert(str_contains($exception->getMessage(), '갱신'), '초기 갱신 대기 안내가 필요합니다.');
}
$rankingPreferences->save(new UserPreferences(
    2,
    'members',
    'hidden',
    'members'
));
$privacyUpdatedEntries = $ranking->entries();
learningSupportSame(
    3,
    count($privacyUpdatedEntries),
    '랭킹 공개 설정 변경은 집계 캐시 만료를 기다리지 않고 적용되어야 합니다.'
);
learningSupportSame(
    $callsBeforeCache,
    $rankingActivity->activeUsersCalls,
    '공개 설정 변경은 고비용 제출 집계를 다시 실행하지 않아야 합니다.'
);
$hiddenProfile = (new UserPreferences(
    1,
    'hidden',
    'members',
    'members'
))->withRankingVisibility('hidden');
learningSupportSame(
    'hidden',
    $hiddenProfile->rankingVisibility(),
    '랭킹 공개 설정 변경 모델이 새 값을 보존해야 합니다.'
);
learningSupportSame(
    'hidden',
    $hiddenProfile->profileVisibility(),
    '랭킹 공개 설정 변경이 프로필 공개 설정을 덮어쓰면 안 됩니다.'
);

$corruptRanking = new RankingService(
    new LearningSupportJudgeActivity([
        new JudgeUserActivity('frank', 1, 3, 3),
    ]),
    new LearningSupportUsers([
        'frank' => new UserIdentity(6, 'frank', 'f@example.com', '바다'),
    ]),
    new LearningSupportPreferences([]),
    new LearningSupportRankingCache()
);
$corruptEntries = $corruptRanking->entries();
learningSupportSame(
    1,
    count($corruptEntries),
    '정답·해결 수가 제출 수보다 큰 손상된 집계도 예외 없이 처리해야 합니다.'
);
learningSupportAssert(
    abs($corruptEntries[0]->accuracyRate() - 1.0) < 0.0001,
    '정답·해결 수가 제출 수보다 크면 정답률을 100%로 보정해야 합니다.'
);

$rankingProviderSource = (string) file_get_contents(
    $pluginRoot
        . '/modules/Ranking/Infrastructure/Judge/MysqliJudgeUserActivityProvider.php'
);
learningSupportAssert(
    str_contains($rankingProviderSource, "`s`.`valid` = 1")
        && str_contains($rankingProviderSource, "`s`.`contest_id` = 0")
        && str_contains($rankingProviderSource, "`p`.`defunct` = 'N'")
        && str_contains($rankingProviderSource, "`s`.`result` BETWEEN")
        && str_contains($rankingProviderSource, 'accepted_submission_count')
        && str_contains($rankingProviderSource, 'solved_problem_count'),
    '랭킹 SQL은 공개 상시 문제의 완료된 유효 제출에서 정답 제출과 고유 해결 문제를 따로 집계해야 합니다.'
);

$longDiagnostic = str_repeat('가', 3000);
$feedbackInput = (new FeedbackInputFactory())->base(
    new AiFeedbackSubmission(
        500,
        900,
        10,
        6,
        11,
        10,
        1024,
        '2026-07-29 12:00:00',
        'print(1)',
        $longDiagnostic,
        '',
        true,
        false
    ),
    $estimated
);
$truncatedDiagnostic = (string) $feedbackInput['submission']['compile_error'];
learningSupportAssert(
    strlen($truncatedDiagnostic) <= 8000
        && preg_match('//u', $truncatedDiagnostic) === 1,
    '긴 한글 진단 메시지는 UTF-8 문자 경계를 보존하며 제한해야 합니다.'
);

$validator = new FeedbackOutputValidator();
$validated = $validator->validate([
    'result_understanding' => '경계 조건에서 결과가 달라집니다.',
    'step_hints' => ['입력 범위를 다시 확인하세요.', '반복문의 끝 조건을 추적하세요.'],
    'review_question' => '최솟값을 직접 대입하면 어떤 값이 나오나요?',
]);
learningSupportSame(2, count($validated['step_hints']), 'AI 피드백은 1~5개의 단계별 힌트를 허용해야 합니다.');
learningSupportThrows(
    static fn (): array => $validator->validate([
        'result_understanding' => '전체 코드입니다.',
        'step_hints' => ["```python\nprint(1)\n```"],
        'review_question' => '확인했나요?',
    ]),
    '복사 가능한 전체 코드 블록은 거부해야 합니다.'
);
learningSupportThrows(
    static fn (): array => $validator->validate([
        'result_understanding' => '설명',
        'step_hints' => [],
        'review_question' => '질문',
    ]),
    '빈 힌트 목록은 거부해야 합니다.'
);

$canStubWordPressCache = ! function_exists('wp_cache_get')
    && ! function_exists('wp_cache_set')
    && ! function_exists('get_transient')
    && ! function_exists('set_transient');
if ($canStubWordPressCache) {
    $GLOBALS['learningSupportObjectCache'] = [];
    $GLOBALS['learningSupportTransients'] = [];
    $GLOBALS['learningSupportTransientTtl'] = [];

    function wp_cache_get(
        mixed $key,
        string $group = '',
        bool $force = false,
        ?bool &$found = null
    ): mixed {
        unset($force);
        $cacheKey = $group . ':' . (string) $key;
        $found = array_key_exists(
            $cacheKey,
            $GLOBALS['learningSupportObjectCache']
        );

        return $GLOBALS['learningSupportObjectCache'][$cacheKey] ?? false;
    }

    function wp_cache_set(
        mixed $key,
        mixed $value,
        string $group = '',
        int $expire = 0
    ): bool {
        unset($expire);
        $GLOBALS['learningSupportObjectCache'][
            $group . ':' . (string) $key
        ] = $value;

        return true;
    }

    function get_transient(string $transient): mixed
    {
        return $GLOBALS['learningSupportTransients'][$transient] ?? false;
    }

    function set_transient(
        string $transient,
        mixed $value,
        int $expiration = 0
    ): bool {
        $GLOBALS['learningSupportTransients'][$transient] = $value;
        $GLOBALS['learningSupportTransientTtl'][$transient] = $expiration;

        return true;
    }

    $persistentCache = new WordPressRankingSnapshotCache();
    $persistentCache->put(
        'activity:persistent-test',
        [['user_id' => 1, 'solved' => 2]],
        60
    );
    $GLOBALS['learningSupportObjectCache'] = [];
    $restoredRows = (new WordPressRankingSnapshotCache())->get(
        'activity:persistent-test'
    );
    learningSupportSame(
        2,
        $restoredRows[0]['solved'] ?? null,
        '기본 WordPress 환경에서도 랭킹 집계를 transient에서 복원해야 합니다.'
    );
    learningSupportSame(
        [300, 60],
        array_values($GLOBALS['learningSupportTransientTtl']),
        '랭킹은 60초 최신 캐시와 동시 갱신 대기용 300초 이전 집계를 저장해야 합니다.'
    );
    learningSupportSame(
        $restoredRows,
        $persistentCache->stale('activity:persistent-test'),
        '다른 요청이 갱신 중일 때 이전 집계를 재사용할 수 있어야 합니다.'
    );
}
$guideRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-r08-' . bin2hex(random_bytes(6));
$guides = new PrivateFeedbackGuideRepository(
    new LearningSupportPrivateRoot($guideRoot)
);
$builtInGuides = $guides->all();
learningSupportSame(8, count($builtInGuides), '신규 설치에는 수준별 기본 AI 지침 8개가 즉시 제공되어야 합니다.');
learningSupportAssert(
    $guides->find('foundation-learning')?->isBuiltIn() === true,
    '기본 자료 부족 지침은 파일 생성 없이 조회되어야 합니다.'
);
learningSupportAssert(
    ! is_dir($guideRoot),
    '기본 AI 지침 조회는 비공개 저장소에 부팅 시 쓰기를 만들면 안 됩니다.'
);
learningSupportThrows(
    static fn (): mixed => $guides->setArchived(
        'foundation-learning',
        true,
        1
    ),
    '배포 기본 AI 지침은 보관 처리할 수 없어야 합니다.'
);
$firstGuide = $guides->save(
    null,
    '초급 경계 확인',
    'beginner',
    '경계값을 스스로 확인합니다.',
    '정답 코드를 제시하지 말고 입력 범위부터 질문합니다.',
    1
);
$secondGuide = $guides->save(
    $firstGuide->key(),
    '초급 경계 확인',
    'beginner',
    '경계값을 스스로 확인합니다.',
    '입력 범위와 반복문의 끝 조건을 차례로 질문합니다.',
    1
);
learningSupportSame(2, $secondGuide->revision(), '지침 수정은 새 불변 개정으로 저장해야 합니다.');
learningSupportSame(2, count($guides->revisions($firstGuide->key())), '지침 변경 기록을 모두 보존해야 합니다.');
$guides->setArchived($firstGuide->key(), false, 1);
learningSupportAssert(
    $guides->find($firstGuide->key())?->isActive() === true,
    '사용할 사용자 지침은 명시적으로 활성화할 수 있어야 합니다.'
);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $guideRoot,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($guideRoot);

echo sprintf(
    "R-08 학습 지원 단위 검사 통과: assertion %d개\n",
    $learningSupportAssertions
);

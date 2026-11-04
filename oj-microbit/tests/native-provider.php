<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use OjMicrobit\Addon\NativeLearningProvider;
use OjMicrobit\Addon\PageHandler;
use OjMicrobit\Application\Access;
use OjMicrobit\Application\LessonAccess;
use OjMicrobit\Application\LessonService;
use OjMicrobit\Application\PracticeService;
use OjMicrobit\Application\Runner;
use OjMicrobit\Storage\WpdbLessonRepository;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\OperationError;

final class NativeActor implements Access, LessonAccess
{
    public array $classes = [7]; public array $managed = []; public int $learningCalls = 0; public int $classChecks = 0;
    public function __construct(public int $id = 20, public bool $teacher = false) {}
    public function actorId(): int { return $this->id; }
    public function requireTeacher(): void { if (!$this->teacher) { throw new OperationError('access_denied', 403, '교사 권한이 필요합니다.'); } }
    public function requireLearner(): void { if ($this->id < 1) { throw new OperationError('unauthorized', 401, '로그인하세요.'); } }
    public function isAdmin(): bool { return false; }
    public function teachingGroups(string $cursor, int $limit): array { return ['available' => false, 'items' => [], 'nextCursor' => null]; }
    public function learnerGroupIds(): ?array { return null; }
    public function requireGroupManagement(array $groupIds): void { throw new OperationError('access_denied', 403, '접근할 수 없습니다.'); }
    public function requireGroupParticipation(array $groupIds): void { throw new OperationError('access_denied', 403, '접근할 수 없습니다.'); }
    public function teachingClasses(string $cursor, int $limit): array { return ['available' => true, 'items' => [], 'nextCursor' => null]; }
    public function learnerClassIds(): ?array { $this->learningCalls++; return null; }
    public function requireClassManagement(array $classIds): void { foreach ($classIds as $id) { if (!in_array($id, $this->managed, true)) { throw new OperationError('access_denied', 403, '수업 관리 권한이 없습니다.'); } } }
    public function requireClassParticipation(array $classIds): void { foreach ($classIds as $id) { if (!in_array($id, $this->classes, true)) { throw new OperationError('access_denied', 403, '수업 참여 권한이 없습니다.'); } } }
    public function learningLessons(): ?array { $this->learningCalls++; return null; }
    public function lessonTitle(int $classId, bool $teacher): string { $this->classChecks++; $teacher ? $this->requireClassManagement([$classId]) : $this->requireClassParticipation([$classId]); return '실습 수업 ' . $classId; }
    public function lessonRoster(int $classId, string $cursor, int $limit, ?string $contextRevision = null, ?string $rosterRevision = null): array { throw new LogicException('Native lists never request a full student roster.'); }
}
final class NativeRunner implements Runner
{
    public int $calls = 0;
    public function status(): array { return ['ready' => false]; }
    public function run(array $project, array $exercise): array { $this->calls++; throw new LogicException('Metadata lists never execute code.'); }
}

$db = new SqliteWpdb(); $records = new WpdbRepository($db); $lessonRecords = new WpdbLessonRepository($db); $actor = new NativeActor(); $runner = new NativeRunner();
$lessons = new LessonService($lessonRecords, $records, $actor, $actor, $runner);
$practice = new PracticeService($records, $actor, $runner); $provider = new NativeLearningProvider($lessons, $practice, $actor);
$insert = $db->pdo->prepare('INSERT INTO wp_oj_microbit_exercises(id,owner_id,title,status,revision,archived,audience_mode,data_json,created_at,updated_at) VALUES (?,?,?,?,1,0,?,?,?,?)');
foreach ([[1,10,'공개 문제 <b>하나</b>','published','site'],[2,10,'작성 중 문제','draft','site'],[3,10,'기존 제한 문제','published','groups'],[4,11,'다른 교사의 공개 문제','published','site']] as [$id,$owner,$title,$status,$scope]) { $insert->execute([$id,$owner,$title,$status,$scope,str_repeat('HIDDEN_901_INVALID_JSON ',1000),'2026-01-01','2026-11-03']); }
$db->pdo->exec("INSERT INTO wp_oj_microbit_exercise_groups(exercise_id,group_id,status,archived) VALUES (3,9,'published',0)");
$assign = $db->pdo->prepare('INSERT INTO wp_oj_microbit_assignments(id,class_id,exercise_id,exercise_revision,title,owner_id,revision,archived,created_at,updated_at) VALUES (?,?,?,1,?,10,1,?,?,?)');
foreach ([[1,7,1,'수업의 공개 문제',0],[2,7,2,'공개 중단된 수업 문제',0],[3,7,4,'적용 해제된 수업 문제',1],[4,8,1,'다른 수업 문제',0]] as [$id,$class,$exercise,$title,$archived]) { $assign->execute([$id,$class,$exercise,$title,$archived,'2026-01-01','2026-11-03']); }

$db->queries = [];
$page = $provider->query(new AddonNativeLearningQueryV1(20, 'problems.public', 0, 0, 1));
verify($page->isAvailable() && count($page->items()) === 1 && $page->items()[0]->externalId() === 'oj_microbit:exercise:4' && $page->nextBeforeId() === 4, 'Native problem menu renders a bounded actual microbit row with an independent cursor');
verify(count($db->queries) === 1 && !preg_match('/json|COUNT\(|OFFSET/i', $db->queries[0]), 'Native public problem rows read one summary query without payload JSON or full counts');
verify($actor->learningCalls === 0 && $actor->classChecks === 0 && $runner->calls === 0, 'Public problem discovery never expands memberships, student rosters or executable code');
verify($page->items()[0]->primaryLink()->routeKey() === 'addon.oj_microbit.learn' && $page->items()[0]->primaryLink()->query() === ['exerciseId' => '4', 'mbView' => 'detail'], 'Public native row links to the registered problem page, not a judge ID');
verify($page->items()[0]->secondaryLinks() === [], 'Native public learner catalog offers only problem details, without direct general-history shortcuts');
$next = $provider->query(new AddonNativeLearningQueryV1(20, 'problems.public', 0, $page->nextBeforeId(), 1));
verify(count($next->items()) === 1 && $next->items()[0]->externalId() === 'oj_microbit:exercise:1' && $next->nextBeforeId() === null, 'Native public pagination excludes drafts and old restricted scope without duplicates');
verify($next->items()[0]->title() === '공개 문제 <b>하나</b>' && $next->items()[0]->statusLabel() === '공개', 'Provider passes plain untrusted title metadata for host escaping');
$db->queries = [];
$denied = $provider->query(new AddonNativeLearningQueryV1(10, 'problems.public'));
verify(!$denied->isAvailable() && $denied->items() === [] && $db->queries === [], 'A host query cannot impersonate another actor');
$denied = $provider->query(new AddonNativeLearningQueryV1(20, 'problems.teacher'));
verify(!$denied->isAvailable() && $denied->actions() === [], 'Students cannot get teacher problem rows or create actions');
$db->queries = [];
$class = $provider->query(new AddonNativeLearningQueryV1(20, 'class.student', 7));
verify($class->isAvailable() && count($class->items()) === 3 && count($db->queries) === 1, 'Exact native class page reads one bounded metadata query for only that class');
verify($actor->learningCalls === 0 && $actor->classChecks === 1, 'Native class requests use fresh single-class participation authority without all-membership fanout');
verify(array_map(static fn ($item) => $item->externalId(), $class->items()) === ['oj_microbit:assignment:3','oj_microbit:assignment:2','oj_microbit:assignment:1'], 'Native class rows use namespaced assignment identities separate from public exercise identities');
$archived = $class->items()[0]; $withdrawn = $class->items()[1]; $active = $class->items()[2];
verify($archived->primaryLink()->routeKey() === 'addon.oj_microbit.lessons' && $archived->primaryLink()->query()['assignmentId'] === '3' && $archived->primaryLink()->query()['mbView'] === 'history', 'Unassigned course records link to scoped history on the lesson page');
verify($withdrawn->primaryLink()->query()['mbView'] === 'history' && str_contains($withdrawn->statusLabel(), '공개 중단'), 'A withdrawn source remains discoverable as read-only scoped history');
verify($active->primaryLink()->query() === ['assignmentId' => '1', 'exerciseId' => '1', 'mbView' => 'detail'], 'Active native class row retains both the exact assignment and source identity');
$denied = $provider->query(new AddonNativeLearningQueryV1(20, 'class.student', 8));
verify(!$denied->isAvailable() && $denied->items() === [], 'Native host authorization never substitutes for the provider own class participation check');
$actor->classes = [];
verify(!$provider->query(new AddonNativeLearningQueryV1(20, 'class.student', 7))->isAvailable(), 'A revoked participant loses native class discovery immediately');
$actor->id = 10; $actor->teacher = true; $actor->managed = [7];
$teacher = $provider->query(new AddonNativeLearningQueryV1(10, 'problems.teacher'));
verify(array_map(static fn ($item) => $item->externalId(), $teacher->items()) === ['oj_microbit:exercise:3','oj_microbit:exercise:2','oj_microbit:exercise:1'], 'Teacher native problems include own drafts and preserved restricted records but not another author management');
verify($teacher->actions()[0]->routeKey() === 'addon.oj_microbit.teacher' && $teacher->actions()[0]->query() === ['mbView' => 'new'], 'Native problem management exposes the registered create-problem action');
verify($teacher->items()[0]->secondaryLinks()[0]->query()['mbView'] === 'history', 'Teacher-owned native problem records retain their authorized general submission link');
verify($teacher->items()[0]->statusLabel() === '기존 제한 배포' && $teacher->items()[1]->statusLabel() === '초안', 'Native problem status distinguishes old restricted records and drafts from global publication');
$teacherClass = $provider->query(new AddonNativeLearningQueryV1(10, 'class.teacher', 7));
verify($teacherClass->isAvailable() && $teacherClass->actions()[0]->routeKey() === 'addon.oj_microbit.teacher_lessons' && $teacherClass->actions()[0]->query() === ['classId'=>'7','mbView'=>'lesson'], 'Native teacher class page exposes only scoped application/result management');
verify($teacherClass->items()[0]->primaryLink()->query()['mbView'] === 'results' && $teacherClass->items()[0]->primaryLink()->routeKey() === 'addon.oj_microbit.teacher_lessons', 'Archived assignment teacher results remain on the class navigation area');
$actor->managed = [];
verify(!$provider->query(new AddonNativeLearningQueryV1(10, 'class.teacher', 7))->isAvailable(), 'Source ownership does not grant native class results or management actions');
$db->pdo->exec('DROP TABLE wp_oj_microbit_assignments'); $actor->managed = [7];
$failure = $provider->query(new AddonNativeLearningQueryV1(10, 'class.teacher', 7));
verify(!$failure->isAvailable() && $failure->items() === [] && !str_contains($failure->message(), 'SQL') && !str_contains($failure->message(), 'HIDDEN'), 'Storage failure is contained with no raw SQL, secret payload or fallback global rows');

// Exercise the production factory, not only injected service instances.
$wpdb = $db; $testUserId = 20;
$testCapabilities = [\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('oj_microbit_learn') => true];
\OjMicrobit\Core\Plugin::connect(new class implements \CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1 {
    public function snapshot(): \CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1 { return new \CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1('oj-microbit', OJ_MICROBIT_VERSION, '>=2.5.9 <3.0.0', '2.5.9', '^1.9', '1.9.0', 'accepted', '', 'native-catalog', [], 'native-factory'); }
    public function contexts(): \CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1 { throw new LogicException('Public native discovery does not query memberships.'); }
    public function aiTasks(): \CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1 { throw new LogicException('Native discovery does not run AI.'); }
});
$db->queries = [];
$production = (new NativeLearningProvider())->query(new AddonNativeLearningQueryV1(20, 'problems.public'));
verify($production->isAvailable() && count($production->items()) === 2 && count($db->queries) === 1, 'The actual registered provider factory returns real SQLite public metadata with the accepted platform runtime');
$testCapabilities = []; $db->queries = [];
$production = (new NativeLearningProvider())->query(new AddonNativeLearningQueryV1(20, 'problems.public'));
verify(!$production->isAvailable() && $production->items() === [] && $db->queries === [], 'Production native provider honors the real platform capability boundary before reading metadata');

$navigation = ['problems.index'=>'/subdir/problems','classes.index'=>'/subdir/classes','addon.oj_microbit.learn'=>'/subdir/oj-addons/oj-microbit/learn','addon.oj_microbit.lessons'=>'/subdir/oj-addons/oj-microbit/lessons'];
$testUserId = 20;
$request = new AddonPageRequestV1('addon.oj_microbit.lessons',20,[],[],'ko-KR','native-fixture',$navigation);
$page = (new PageHandler('student','lessons'))->handle($request);
preg_match('/data-config="([^"]+)"/', $page->body(), $configMatch); $config = json_decode(html_entity_decode($configMatch[1], ENT_QUOTES, 'UTF-8'), true, 64, JSON_THROW_ON_ERROR);
verify($config['nativeNavigation'] === true && $config['initialSection'] === 'lessons', 'Lesson route bootstraps its explicit native section without query heuristics');
verify($config['problemMenuUrl'] === $navigation['addon.oj_microbit.learn'] && $config['lessonMenuUrl'] === $navigation['addon.oj_microbit.lessons'], 'Independent problem and lesson menu URLs preserve host registry subdirectories without linking core lists');
verify($config['problemPageUrl'] === $navigation['addon.oj_microbit.learn'] && $config['lessonPageUrl'] === $navigation['addon.oj_microbit.lessons'], 'Distinct registered problem and lesson pages are supplied to client navigation');
verify($page->title() === '마이크로비트 수업' && !str_contains($page->body(), '127.0.0.1'), 'Course page heading matches its section and exposes no private runner address');
$fallback = (new PageHandler('student'))->handle(new AddonPageRequestV1('addon.oj_microbit.learn',20,[],[],'ko-KR','native-fixture'));
preg_match('/data-config="([^"]+)"/', $fallback->body(), $match); $fallbackConfig = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),true,64,JSON_THROW_ON_ERROR);
verify($fallbackConfig['nativeNavigation'] === false && $fallbackConfig['initialSection'] === 'problems', 'Missing old-host URL context retains a clear fallback instead of claiming native navigation');
throws(fn () => new PageHandler('student','arbitrary'), InvalidArgumentException::class, 'Unknown navigation section is rejected');
echo '[native-provider] ' . $testChecks . " checks passed (real native DTOs, SQLite metadata, service authority and page context; live WordPress remains separate)\n";

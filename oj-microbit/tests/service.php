<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Application\Access;
use OjMicrobit\Application\PracticeService;
use OjMicrobit\Application\Repository;
use OjMicrobit\Application\Runner;

final class MemoryRecords implements Repository
{
    public array $records = []; public array $projects = []; public array $results = []; public int $leases = 0; public bool $locked = false;
    public array $reads = [];
    public function exercises(?int $ownerId, bool $published, int $beforeId = 0, int $limit = 21): array {
        $this->reads[] = ['exercises', $ownerId, $published, $beforeId, $limit];
        $rows = array_values(array_filter($this->records, static fn ($row) => ($ownerId === null || $row['ownerId'] === $ownerId) && (!$published || $row['status'] === 'published') && (!$beforeId || $row['id'] < $beforeId)));
        usort($rows, static fn ($a, $b) => $b['id'] <=> $a['id']);
        return array_map(static fn ($row) => array_intersect_key($row, array_flip(['id', 'ownerId', 'title', 'status', 'revision', 'updatedAt', 'audience'])), array_slice($rows, 0, $limit));
    }
    public function learnerExercises(array $classIds, int $beforeId = 0, int $limit = 21, array $groupIds = []): array {
        $this->reads[] = ['learnerExercises', $classIds, $beforeId, $limit];
        $rows = array_values(array_filter($this->records, static fn ($row) => $row['status'] === 'published' && (!$beforeId || $row['id'] < $beforeId)
            && (($row['audience']['mode'] ?? 'site') === 'site' || (($row['audience']['mode'] ?? '') === 'classes' && array_intersect($row['audience']['classIds'], $classIds) !== []) || (($row['audience']['mode'] ?? '') === 'groups' && array_intersect($row['audience']['groupIds'], $groupIds) !== []))));
        usort($rows, static fn ($a, $b) => $b['id'] <=> $a['id']);
        return array_map(static fn ($row) => array_intersect_key($row, array_flip(['id', 'ownerId', 'title', 'status', 'revision', 'updatedAt', 'audience'])), array_slice($rows, 0, $limit));
    }
    public function exercise(int $id): ?array { $this->reads[] = ['exercise', $id]; return $this->records[$id] ?? null; }
    public function exerciseSummary(int $id): ?array { $this->reads[] = ['exerciseSummary', $id]; return isset($this->records[$id]) ? array_intersect_key($this->records[$id], array_flip(['id', 'ownerId', 'title', 'status', 'revision', 'updatedAt', 'audience'])) : null; }
    public function saveExercise(array $data, int $ownerId, ?int $id, int $revision): array {
        if ($id !== null && ($this->records[$id]['revision'] ?? -1) !== $revision) { throw new DomainException('과제가 변경되었습니다.'); }
        $id ??= count($this->records) + 1;
        return $this->records[$id] = $data + ['id' => $id, 'ownerId' => $ownerId, 'revision' => $revision + 1];
    }
    public function archiveExercise(int $id, int $revision): void { if (($this->records[$id]['revision'] ?? null) !== $revision) { throw new DomainException('변경되었습니다.'); } unset($this->records[$id]); }
    public function project(int $exerciseId, int $userId): ?array { return $this->projects[$exerciseId . ':' . $userId] ?? null; }
    public function saveProject(int $exerciseId, int $userId, array $project, int $revision): array {
        $key = $exerciseId . ':' . $userId;
        if (($this->projects[$key]['revision'] ?? 0) !== $revision) { throw new DomainException('프로젝트가 변경되었습니다.'); }
        return $this->projects[$key] = ['project' => $project, 'revision' => $revision + 1];
    }
    public function saveAttempt(int $exerciseId, int $revision, int $userId, array $project, array $result): int {
        $id = count($this->results) + 1;
        $this->results[] = ['id' => $id, 'exerciseId' => $exerciseId, 'exerciseRevision' => $revision, 'userId' => $userId, 'result' => $result]; return $id;
    }
    public function attempts(int $exerciseId, ?int $userId, int $beforeId = 0, int $limit = 21): array {
        $this->reads[] = ['attempts', $exerciseId, $userId, $beforeId, $limit];
        $rows = array_values(array_filter($this->results, static fn ($row) => $row['exerciseId'] === $exerciseId && ($userId === null || $row['userId'] === $userId) && (!$beforeId || $row['id'] < $beforeId)));
        usort($rows, static fn ($a, $b) => $b['id'] <=> $a['id']);
        return array_map(static fn ($row) => array_replace($row, ['result' => array_intersect_key($row['result'], array_flip(['status', 'score', 'passed']))]), array_slice($rows, 0, $limit));
    }
    public function attempt(int $exerciseId, int $attemptId, ?int $userId): ?array { foreach ($this->results as $row) { if ($row['id'] === $attemptId && $row['exerciseId'] === $exerciseId && ($userId === null || $row['userId'] === $userId)) { return $row; } } return null; }
    public function withRunLease(int $userId, callable $operation): mixed { if ($this->locked) { throw new DomainException('실행 중입니다.'); } $this->leases++; $this->locked = true; try { return $operation(); } finally { $this->locked = false; } }
}
final class Actor implements Access
{
    public ?array $classes = []; public array $managedClasses = [];
    public ?array $groups = []; public array $managedGroups = [];
    public function __construct(public int $id, public bool $teacher = false, public bool $admin = false, public bool $learner = true) {}
    public function actorId(): int { if ($this->id < 1) { throw new DomainException('로그인 필요'); } return $this->id; }
    public function requireTeacher(): void { $this->actorId(); if (!$this->teacher && !$this->admin) { throw new DomainException('권한 없음'); } }
    public function requireLearner(): void { $this->actorId(); if (!$this->learner && !$this->teacher && !$this->admin) { throw new DomainException('권한 없음'); } }
    public function isAdmin(): bool { return $this->admin; }
    public function teachingGroups(string $cursor, int $limit): array { return ['available' => $this->groups !== null, 'items' => [], 'nextCursor' => null]; }
    public function learnerGroupIds(): ?array { return $this->groups; }
    public function requireGroupManagement(array $groupIds): void { if (array_diff($groupIds, $this->managedGroups) !== []) { throw new \OjMicrobit\Support\OperationError('access_denied', 403, '그룹 관리 권한 없음'); } }
    public function requireGroupParticipation(array $groupIds): void { if ($this->groups === null || array_intersect($groupIds, $this->groups) === []) { throw new \OjMicrobit\Support\OperationError('access_denied', 403, '그룹 참여 권한 없음'); } }
    public function teachingClasses(string $cursor, int $limit): array { return ['available' => $this->classes !== null, 'items' => [], 'nextCursor' => null]; }
    public function learnerClassIds(): ?array { return $this->classes; }
    public function requireClassManagement(array $classIds): void { if (array_diff($classIds, $this->managedClasses) !== []) { throw new \OjMicrobit\Support\OperationError('access_denied', 403, '학급 관리 권한 없음'); } }
    public function requireClassParticipation(array $classIds): void { if ($this->classes === null || array_intersect($classIds, $this->classes) === []) { throw new \OjMicrobit\Support\OperationError('access_denied', 403, '학급 참여 권한 없음'); } }
}
final class InspectRunner implements Runner
{
    public array $calls = []; public mixed $after = null; public mixed $decorate = null;
    public function status(): array { return ['ready' => true]; }
    public function run(array $project, array $exercise): array {
        $this->calls[] = ['project' => $project, 'exercise' => $exercise];
        $result = fixtureResult($project, $exercise);
        if (is_callable($this->decorate)) { $result = ($this->decorate)($result); }
        if (is_callable($this->after)) { ($this->after)(); }
        return $result;
    }
}

$records = new MemoryRecords(); $teacher = new Actor(10, true); $student = new Actor(20); $runner = new InspectRunner();
$teacherService = new PracticeService($records, $teacher, $runner); $service = new PracticeService($records, $student, $runner);
$saved = $teacherService->saveExercise(reviewedExercise(fixtureExercise()));
verify($saved['id'] === 1 && $saved['revision'] === 1 && $saved['ownerId'] === 10, 'Teacher creates an owned validated exercise');
throws(fn () => $service->saveExercise(fixtureExercise()), DomainException::class, 'Learner cannot create exercises');
$other = new PracticeService($records, new Actor(11, true), $runner);
throws(fn () => $other->teacherExercise(1), DomainException::class, 'Other teacher cannot inspect exercise');
throws(fn () => $other->saveExercise($saved), DomainException::class, 'Other teacher cannot edit exercise');
throws(fn () => $other->deleteExercise(1, ['revision' => 1]), DomainException::class, 'Other teacher cannot archive exercise');
throws(fn () => $other->attempts(1, true), DomainException::class, 'Other teacher cannot read learner attempts');
$admin = new PracticeService($records, new Actor(1, false, true), $runner);
verify($admin->teacherExercise(1)['ownerId'] === 10, 'Administrator can inspect exercise');
$forged = fixtureExercise() + ['ownerId' => 999, 'id' => 1, 'revision' => 1];
verify($teacherService->saveExercise(reviewedExercise($forged))['ownerId'] === 10, 'Client cannot transfer ownership');
throws(fn () => $teacherService->saveExercise(reviewedExercise($forged)), DomainException::class, 'Stale exercise edit rejected');
throws(fn () => $teacherService->deleteExercise(1, ['revision' => 1]), DomainException::class, 'Stale archive rejected');
verify(count($service->exercise(1)['scenarios']) === 1, 'Student only receives visible scenario');
verify(!str_contains(json_encode($service->exercise(1)), '901'), 'Hidden input absent from exercise response');
verify($service->project(1)['revision'] === 0, 'Unsaved learner starts with starter project');
$project = fixtureProject();
verify($service->saveProject(1, ['project' => $project, 'revision' => 0, 'userId' => 10])['revision'] === 1, 'Project save uses logged-in owner');
verify(isset($records->projects['1:20']) && !isset($records->projects['1:10']), 'Forged learner ID ignored');
throws(fn () => $service->saveProject(1, ['project' => $project, 'revision' => 0]), DomainException::class, 'Project CAS rejects stale write');
verify($service->saveProject(1, ['project' => $project, 'revision' => 1])['revision'] === 2, 'Current project revision advances');

$runner->decorate = static function (array $result): array { $result['diagnostics'] = [['message' => 'secret=901']]; $result['error'] = 'secret=901'; return $result; };
$result = $service->run(1, ['project' => $project, 'passed' => true, 'score' => 100, 'scenarios' => [], 'assertions' => [], 'userId' => 10]);
verify(count($runner->calls[0]['exercise']['scenarios']) === 2 && $runner->calls[0]['exercise']['scenarios'][1]['inputs'][0]['value'] === 901, 'Server sends original hidden tests, ignoring client verdicts and assertions');
verify($result['id'] === 1 && $records->results[0]['userId'] === 20, 'Run records logged-in owner');
verify(!isset($result['scenarios'][1]['assertions']) && $result['scenarios'][1]['name'] === '비공개 테스트', 'Hidden expected outputs and details redacted');
verify($result['diagnostics'] === [] && !str_contains($result['error'], '901'), 'Hidden values cannot leak through errors or diagnostics');
verify(!isset($result['_visibility']) && $records->results[0]['result']['_visibility']['secret'] === false, 'Private original visibility persisted but not returned');
verify($result['traceScenarioId'] === 'public' && count($result['trace']) === 1, 'Visible trace remains scoped to public scenario');
verify(count($teacherService->attempt(1, 1, true)['result']['scenarios'][1]['assertions']) === 1, 'Owner teacher can inspect one full result');
verify(!isset($teacherService->attempts(1, true)['items'][0]['result']['scenarios']), 'Teacher history list contains only result summary');
verify((new PracticeService($records, new Actor(21), $runner))->attempts(1)['items'] === [], 'Learners cannot read other learner attempts');

// Reusing a scenario ID in a later public revision must not reveal an old hidden case.
$records->records[1]['scenarios'][1]['visible'] = true;
verify(!isset($service->attempt(1, 1)['result']['scenarios'][1]['assertions']), 'Old revision detail uses original hidden visibility');
unset($records->results[0]['result']['_visibility']);
$legacy = $service->attempt(1, 1)['result'];
verify(!isset($legacy['scenarios'][0]['assertions']) && $legacy['trace'] === [], 'Legacy result without original visibility fails closed');

$runner->decorate = null;
$preview = $service->preview(1, ['project' => $project, 'durationMs' => 500, 'inputs' => [['atMs' => 0, 'pin' => 0, 'signal' => 'analog', 'value' => 600]]]);
verify($preview['status'] === 'preview' && $preview['passed'] === null && $preview['score'] === null && $preview['preview'], 'Preview returns non-grade null verdict');
verify(count($records->results) === 1, 'Preview does not create a graded attempt');
throws(fn () => $service->preview(1, ['project' => $project, 'inputs' => [['atMs' => 0, 'pin' => 1, 'signal' => 'digital', 'value' => 1]]]), InvalidArgumentException::class, 'Preview cannot inject output pins');
$runner->after = static function () use ($records): void { $records->records[1]['status'] = 'draft'; };
throws(fn () => $service->run(1, ['project' => $project]), DomainException::class, 'Publication rechecked after runner call');
verify(count($records->results) === 1 && !$records->locked, 'Revoked run leaves no attempt and releases lease');
throws(fn () => $service->exercise(1), DomainException::class, 'Draft unavailable to learner');
verify($teacherService->teacherExercise(1)['status'] === 'draft', 'Owner still manages draft');
$records->records[1]['status'] = 'published';
throws(fn () => $service->preview(1, ['project' => $project, 'durationMs' => 500, 'inputs' => []]), DomainException::class, 'Preview also rechecks publication after runner call');
$runner->after = null;
$records->records[1]['status'] = 'published';
$records->locked = true;
throws(fn () => $service->run(1, ['project' => $project]), DomainException::class, 'Concurrent learner run rejected before execution');
$records->locked = false;
$teacherService->deleteExercise(1, ['revision' => 2]);
throws(fn () => $service->project(1), DomainException::class, 'Archived exercise cannot be used through project endpoint');
throws(fn () => $service->run(1, ['project' => $project]), DomainException::class, 'Archived exercise cannot be graded');
$compileRecords = new MemoryRecords(); $compileRunner = new InspectRunner();
$compileTeacher = new PracticeService($compileRecords, $teacher, $compileRunner);
$compileTeacher->saveExercise(reviewedExercise(fixtureExercise()));
$compileRunner->decorate = static function (array $result): array {
    $result['status'] = 'compile_error'; $result['passed'] = false; $result['score'] = 0; $result['scenarios'] = []; $result['trace'] = []; $result['traceScenarioId'] = null;
    $result['diagnostics'] = [['code' => 1005, 'file' => 'main.ts', 'line' => 1, 'message' => 'Expected token.']]; return $result;
};
$compileResult = (new PracticeService($compileRecords, $student, $compileRunner))->run(1, ['project' => $project]);
verify($compileResult['passed'] === false && $compileResult['score'] === 0 && count($compileRecords->results) === 1, 'Compile failure is recorded without granting a pass');
verify($compileResult['diagnostics'][0]['message'] === 'Expected token.', 'Own source compile diagnostics remain useful even when exercise contains hidden tests');

// Simulate an untouched 0.1.0 database row, not a freshly normalized 0.2 contract.
$legacyRecords = new MemoryRecords(); $legacyRunner = new InspectRunner();
$legacyTeacher = new PracticeService($legacyRecords, $teacher, $legacyRunner);
$legacyTeacher->saveExercise(reviewedExercise(fixtureExercise()));
unset($legacyRecords->records[1]['i2cDevices']);
foreach ($legacyRecords->records[1]['scenarios'] as &$scenario) { unset($scenario['i2cInputs'], $scenario['i2cAssertions']); }
unset($scenario);
$legacyLearner = new PracticeService($legacyRecords, $student, $legacyRunner);
verify($legacyTeacher->teacherExercise(1)['i2cDevices'] === [] && $legacyLearner->exercise(1)['scenarios'][0]['i2cAssertions'] === [], 'Raw 0.1 database rows hydrate protocol defaults for teacher and learner GET');
verify($legacyLearner->run(1, ['project' => $project])['passed'] === true && $legacyRunner->calls[0]['exercise']['i2cDevices'] === [], 'Raw 0.1 exercise remains gradable with normalized runner payload');
verify($legacyLearner->preview(1, ['project' => $project, 'inputs' => []])['preview'] === true && $legacyRunner->calls[1]['exercise']['scenarios'][0]['i2cInputs'] === [], 'Raw 0.1 exercise preview receives protocol defaults');
unset($legacyRecords->results[0]['result']['i2cTrace']);
$legacyRecords->results[0]['result']['runtimeVersion'] = 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/1';
foreach ($legacyRecords->results[0]['result']['scenarios'] as &$scenario) { unset($scenario['i2cAssertions']); }
unset($scenario);
$oldHistory = $legacyLearner->attempt(1, 1)['result'];
verify($oldHistory['i2cTrace'] === [] && $oldHistory['scenarios'][0]['i2cAssertions'] === [] && !isset($oldHistory['scenarios'][1]['assertions']), 'Raw 0.1 history remains readable without protocol fields or hidden leakage');

$busRecords = new MemoryRecords(); $busRunner = new InspectRunner();
$busExercise = fixtureProtocolExercise(); $busExercise['connections'] = [];
foreach ($busExercise['scenarios'] as &$scenario) { $scenario['inputs'] = []; $scenario['assertions'] = []; }
unset($scenario);
(new PracticeService($busRecords, $teacher, $busRunner))->saveExercise(reviewedExercise($busExercise));
$busLearner = new PracticeService($busRecords, $student, $busRunner);
$busPreview = $busLearner->preview(1, ['project' => $project, 'inputs' => [], 'durationMs' => 500, 'i2cInputs' => [['atMs' => 0, 'address' => 64, 'register' => 0, 'value' => 222]], 'i2cAssertions' => [['passed' => true]]]);
verify($busPreview['passed'] === null && $busRunner->calls[0]['exercise']['scenarios'][0]['i2cInputs'][0]['value'] === 222 && $busRunner->calls[0]['exercise']['scenarios'][0]['i2cAssertions'] === [], 'I2C-only preview uses validated environment updates and ignores forged assertions');
throws(fn () => $busLearner->preview(1, ['project' => $project, 'inputs' => [], 'i2cInputs' => [['atMs' => 0, 'address' => 65, 'register' => 0, 'value' => 222]], 'i2cDevices' => [['address' => 65]]]), InvalidArgumentException::class, 'Preview cannot forge additional bus devices');
throws(fn () => $busLearner->preview(1, ['project' => $project, 'inputs' => [], 'i2cInputs' => null]), InvalidArgumentException::class, 'Malformed protocol preview input rejected');
$busGrade = $busLearner->run(1, ['project' => $project, 'i2cInputs' => [], 'i2cAssertions' => [], 'passed' => true, 'score' => 100]);
verify(count($busRunner->calls[1]['exercise']['scenarios'][0]['i2cAssertions']) === 3 && !isset($busGrade['scenarios'][1]['i2cAssertions']), 'Official bus grade uses saved teacher conditions and redacts hidden bus results');

$metadataRecords = new MemoryRecords(); $metadataRunner = new InspectRunner();
$metadataTeacher = new PracticeService($metadataRecords, $teacher, $metadataRunner);
$metadataTeacher->saveExercise(reviewedExercise(fixtureExercise()));
$oldProject = fixtureProject(); $oldProject['files']['.simstate.json'] = '{}'; $oldProject['files']['.git.json'] = '{}'; $oldProject['files']['_history'] = '{}';
$metadataRecords->records[1]['starterProject'] = $oldProject;
$metadataRecords->projects['1:20'] = ['project' => $oldProject, 'revision' => 7];
$metadataLearner = new PracticeService($metadataRecords, $student, $metadataRunner);
verify(!isset($metadataTeacher->teacherExercise(1)['starterProject']['files']['.simstate.json']), 'Existing teacher exercise metadata is normalized on edit load');
$loadedProject = $metadataLearner->project(1);
verify($loadedProject['revision'] === 7 && !isset($loadedProject['project']['files']['.simstate.json']), 'Existing learner draft metadata is normalized without losing revision');
verify($metadataLearner->saveProject(1, ['project' => $oldProject, 'revision' => 7])['revision'] === 8, 'Existing draft can be saved after metadata normalization');
verify($metadataLearner->run(1, ['project' => $oldProject])['passed'] === true && !isset($metadataRunner->calls[0]['project']['files']['.git.json']), 'Grading ignores workspace metadata while preserving source');
$editedExercise = $metadataTeacher->teacherExercise(1); $editedExercise['starterProject'] = $oldProject;
verify($metadataTeacher->saveExercise(reviewedExercise($editedExercise))['revision'] === 2, 'Existing exercise with metadata can be edited and saved');

$sequenceRecords = new MemoryRecords(); $sequenceRunner = new InspectRunner();
$sequenceTeacher = new PracticeService($sequenceRecords, $teacher, $sequenceRunner);
$sequenceExercise = $sequenceTeacher->saveExercise(reviewedExercise(fixtureSequenceExercise()));
$sequenceLearner = new PracticeService($sequenceRecords, $student, $sequenceRunner);
verify(count($sequenceLearner->exercise(1)['scenarios'][0]['outputSequences'][0]['steps']) === 2 && count($sequenceLearner->exercise(1)['scenarios']) === 1, 'Learner receives public sequence conditions without hidden case');
$sequenceGrade = $sequenceLearner->run(1, ['project' => $sequenceExercise['starterProject'], 'outputSequences' => [['passed' => true, 'steps' => []]], 'score' => 100]);
verify($sequenceRunner->calls[0]['exercise']['scenarios'][0]['outputSequences'][0]['steps'][0]['expected'] === 1 && $sequenceGrade['score'] === 100, 'Official sequence grade ignores client conditions and uses saved teacher order');
verify(isset($sequenceGrade['scenarios'][0]['outputSequences']) && !isset($sequenceGrade['scenarios'][1]['outputSequences']), 'Hidden sequence results omitted from learner response');
verify(count($sequenceTeacher->attempt(1, 1, true)['result']['scenarios'][1]['outputSequences'][0]['steps']) === 2, 'Owning teacher retains full private sequence feedback in requested detail');
$sequencePreview = $sequenceLearner->preview(1, ['project' => $sequenceExercise['starterProject'], 'inputs' => [], 'outputSequences' => $sequenceExercise['scenarios'][0]['outputSequences']]);
verify($sequencePreview['passed'] === null && $sequenceRunner->calls[1]['exercise']['scenarios'][0]['outputSequences'] === [], 'Interactive preview cannot create a sequence grade from client conditions');
unset($sequenceRecords->records[1]['scenarios'][0]['outputSequences'], $sequenceRecords->records[1]['scenarios'][1]['outputSequences']);
// A historical 0.2 exercise retains its ordinary assertions and no sequence field.
$sequenceRecords->records[1] = fixtureExercise() + ['id' => 1, 'ownerId' => 10, 'revision' => 1];
foreach ($sequenceRecords->records[1]['scenarios'] as &$scenario) { unset($scenario['outputSequences']); }
unset($scenario);
verify($sequenceTeacher->teacherExercise(1)['scenarios'][0]['outputSequences'] === [], 'Old stored teacher exercise loads with empty sequence default');
$sequenceRecords->results[0]['result']['runtimeVersion'] = 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/2';
foreach ($sequenceRecords->results[0]['result']['scenarios'] as &$scenario) { unset($scenario['outputSequences']); }
unset($scenario);
$historicalSequenceResult = $sequenceLearner->attempt(1, 1)['result'];
verify($historicalSequenceResult['runtimeVersion'] === 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/2' && $historicalSequenceResult['score'] === 100 && $historicalSequenceResult['scenarios'][0]['outputSequences'] === [], 'Old runtime result remains unchanged and readable after sequence runtime upgrade');
verify($sequenceLearner->run(1, ['project' => fixtureProject()])['passed'] === true && $sequenceRunner->calls[2]['exercise']['scenarios'][0]['outputSequences'] === [], 'Old stored exercise grades through new runtime without sequence conditions');

$pageRecords = new MemoryRecords(); $pageRunner = new InspectRunner();
for ($index = 1; $index <= 67; $index++) { $pageRecords->records[$index] = fixtureExercise() + ['id' => $index, 'ownerId' => $index === 66 ? 11 : 10, 'revision' => 1]; }
$pageTeacher = new PracticeService($pageRecords, $teacher, $pageRunner); $pageLearner = new PracticeService($pageRecords, $student, $pageRunner);
$firstPage = $pageTeacher->teacherExercises();
verify(count($firstPage['items']) === 20 && $firstPage['items'][0]['id'] === 67 && $firstPage['nextBeforeId'] === 47, 'Teacher keyset page selects twenty owned newest summaries and a cursor');
verify(!isset($firstPage['items'][0]['starterProject'], $firstPage['items'][0]['scenarios']), 'Exercise list never exposes project or scenarios');
$secondPage = $pageTeacher->teacherExercises($firstPage['nextBeforeId']);
verify($secondPage['items'][0]['id'] === 46 && count(array_intersect(array_column($firstPage['items'], 'id'), array_column($secondPage['items'], 'id'))) === 0, 'Cursor page contains older nonoverlapping exercises');
verify($pageRecords->reads[0] === ['exercises', 10, false, 0, 21], 'Service requests page size plus one without a count query');
verify(count($pageLearner->exercises(0, 50)['items']) === 50 && $pageLearner->exercises(3, 20)['nextBeforeId'] === null, 'Published exercise page obeys custom bounded size and end cursor');
throws(fn () => $pageLearner->exercises(-1), InvalidArgumentException::class, 'Negative exercise cursor rejected');
throws(fn () => $pageLearner->exercises(0, 51), InvalidArgumentException::class, 'Oversized exercise page rejected');
for ($index = 1; $index <= 62; $index++) { $pageRecords->results[] = ['id' => $index, 'exerciseId' => 1, 'exerciseRevision' => 1, 'userId' => $index === 61 ? 21 : 20, 'createdAt' => '2026-10-28 00:00:00', 'result' => fixtureResult(fixtureProject(), fixtureExercise())]; }
$historyPage = $pageLearner->attempts(1);
verify(count($historyPage['items']) === 20 && $historyPage['items'][0]['id'] === 62 && $historyPage['nextBeforeId'] === 42, 'History page is keyset bounded and scoped to the current learner');
verify(array_keys($historyPage['items'][0]['result']) === ['status', 'passed', 'score'], 'History list excludes traces, assertions and diagnostics');
verify($pageRecords->reads[array_key_last($pageRecords->reads) - 1] === ['exerciseSummary', 1], 'History authorization reads only the exercise summary');
verify($pageLearner->attempts(1, false, 3)['nextBeforeId'] === null && count($pageLearner->attempts(1, false, 3)['items']) === 2, 'History final page terminates instead of repeating a cursor');
throws(fn () => $pageLearner->attempt(1, 61), DomainException::class, 'Learner cannot load another learner detail by ID');
throws(fn () => $pageLearner->attempt(2, 62), DomainException::class, 'Attempt detail cannot be used through a different exercise');
throws(fn () => (new PracticeService($pageRecords, new Actor(11, true), $pageRunner))->attempt(1, 62, true), DomainException::class, 'Other teacher cannot load owner-only attempt detail');
verify(isset($pageTeacher->attempt(1, 61, true)['result']['trace']), 'Owner can explicitly load one learner full result');
$pageRecords->reads = [];
$pageLearner->saveProject(1, ['project' => fixtureProject(), 'revision' => 0]);
verify($pageRecords->reads === [['exerciseSummary', 1]], 'Project save authorizes with summary instead of loading unused starter JSON');
$pageRecords->reads = [];
$pageLearner->run(1, ['project' => fixtureProject()]);
verify($pageRecords->reads === [['exercise', 1], ['exerciseSummary', 1]], 'Run reads full scenarios once and rechecks access using metadata');
$pageRecords->reads = [];
$restored = $pageLearner->project(1);
verify($restored['revision'] === 1 && $restored['exerciseRevision'] === 1 && $pageRecords->reads === [['exerciseSummary', 1]], 'Saved project restore reads exercise metadata without unused starter JSON');
$pageRecords->reads = [];
$initialProject = $pageLearner->project(2);
verify($initialProject['revision'] === 0 && $initialProject['project'] === fixtureProject() && $pageRecords->reads === [['exerciseSummary', 2], ['exercise', 2]], 'First practice reads full starter project only when no learner draft exists');

$classRecords = new MemoryRecords(); $classRunner = new InspectRunner(); $classTeacher = new Actor(10, true); $classTeacher->managedClasses = [7, 8];
$classStudent = new Actor(20); $classStudent->classes = [7]; $classOutsider = new Actor(21); $classOutsider->classes = [9];
$classTeacherService = new PracticeService($classRecords, $classTeacher, $classRunner);
$classLearner = new PracticeService($classRecords, $classStudent, $classRunner); $outside = new PracticeService($classRecords, $classOutsider, $classRunner);
$classInput = fixtureExercise(); $classInput['audience'] = ['mode' => 'classes', 'classIds' => [8, 7]];
$classSaved = $classRecords->saveExercise(\OjMicrobit\Domain\ExerciseSchema::normalize($classInput), 10, null, 0);
$classInput += ['id' => $classSaved['id'], 'revision' => $classSaved['revision']];
$classTeacherService->saveExercise(reviewedExercise(fixtureExercise()));
verify($classSaved['audience'] === ['mode' => 'classes', 'classIds' => [7, 8]], 'Saved class audience is canonical and explicit');
verify(array_column($classLearner->exercises()['items'], 'id') === [2, 1] && array_column($outside->exercises()['items'], 'id') === [2], 'Class membership filters the list before pagination without widening the site scope');
$classGrade = $classLearner->run(1, ['project' => fixtureProject(), 'audience' => ['mode' => 'site', 'classIds' => []]]);
verify($classGrade['passed'] && $classRecords->results[0]['userId'] === 20, 'Authorized class grade uses the saved scope despite forged request audience');
$blocked = [
    fn () => $outside->exercise(1), fn () => $outside->project(1),
    fn () => $outside->saveProject(1, ['project' => fixtureProject(), 'revision' => 0]),
    fn () => $outside->preview(1, ['project' => fixtureProject(), 'inputs' => []]),
    fn () => $outside->run(1, ['project' => fixtureProject()]), fn () => $outside->attempts(1), fn () => $outside->attempt(1, 1),
];
foreach ($blocked as $operation) { throws($operation, \OjMicrobit\Support\OperationError::class, 'Every direct class practice/history entry denies an outside learner'); }
verify(count($classRunner->calls) === 1 && count($classRecords->results) === 1 && $classRecords->projects === [], 'Denied class requests never invoke a worker, persist code or read another history detail');
$classStudent->classes = [];
throws(fn () => $classLearner->project(1), \OjMicrobit\Support\OperationError::class, 'Leaving or removal immediately revokes saved-project restore');
throws(fn () => $classLearner->attempts(1), \OjMicrobit\Support\OperationError::class, 'Leaving a class immediately revokes ordinary history');
$classStudent->classes = null;
$fallback = $classLearner->exercises();
verify(array_column($fallback['items'], 'id') === [2] && !$fallback['classAccessAvailable'] && is_string($fallback['audienceWarning']), 'Unavailable or incomplete provider preserves site exercises with an explicit safe warning');
verify($classLearner->exercise(2)['audience']['mode'] === 'site', 'Site practice continues without a class provider');
throws(fn () => $classLearner->exercise(1), \OjMicrobit\Support\OperationError::class, 'Provider failure never falls back a restricted exercise to site scope');
$classStudent->classes = [7]; $classRunner->after = static function () use ($classStudent): void { $classStudent->classes = []; };
throws(fn () => $classLearner->run(1, ['project' => fixtureProject()]), \OjMicrobit\Support\OperationError::class, 'Membership is freshly rechecked after a grade finishes');
verify(count($classRecords->results) === 1 && !$classRecords->locked, 'A revoked grade is not persisted or returned and releases its lease');
$classStudent->classes = [7];
throws(fn () => $classLearner->preview(1, ['project' => fixtureProject(), 'inputs' => []]), \OjMicrobit\Support\OperationError::class, 'Membership is freshly rechecked after a preview finishes');
$classRunner->after = null; $classStudent->classes = [7];
$classRunner->decorate = static function (): array { throw new \OjMicrobit\Support\OperationError('runner_unavailable', 503, '실행 서비스를 확인해 주세요.'); };
throws(fn () => $classLearner->run(1, ['project' => fixtureProject()]), \OjMicrobit\Support\OperationError::class, 'Infrastructure failure remains a typed non-grade error');
verify(count($classRecords->results) === 1 && !$classRecords->locked, 'Infrastructure failure creates no attempt and recovers the lease');
$classRunner->decorate = null;
verify($classLearner->run(1, ['project' => fixtureProject()])['passed'], 'A later authorized run succeeds after infrastructure recovery');
$unmanaged = $classInput; $unmanaged['audience']['classIds'] = [99];
throws(fn () => $classTeacherService->reviewExercise(reviewedExercise($unmanaged)), InvalidArgumentException::class, 'Prepublication review denies an unmanaged target class');
throws(fn () => $classTeacherService->saveExercise(reviewedExercise($unmanaged)), InvalidArgumentException::class, 'Teacher cannot forge an unmanaged class assignment');
verify(count($classRecords->records) === 2, 'Rejected class assignments create no exercise');
throws(fn () => $classTeacherService->saveExercise(fixtureExercise()), InvalidArgumentException::class, 'Published save requires an explicit server-bound review');
$reviewInput = reviewedExercise($classInput, 'blank');
$review = $classTeacherService->reviewExercise($reviewInput);
verify($review['summary']['audience']['classIds'] === [7, 8] && $review['studentPreview']['starterProject']['files']['main.ts'] === '' && count($review['studentPreview']['scenarios']) === 1, 'Server review shows the exact class audience, blank starter and public student examples');
$stale = reviewedExercise($classInput); $stale['scenarios'][1]['inputs'][0]['value'] = 900;
throws(fn () => $classTeacherService->saveExercise($stale), InvalidArgumentException::class, 'Changing a hidden condition invalidates the reviewed publication payload');
$classTeacher->managedClasses = [];
throws(fn () => $classTeacherService->saveExercise(reviewedExercise($classInput)), \OjMicrobit\Support\OperationError::class, 'Class management is rechecked at save time after a prior review');
$groupRecords = new MemoryRecords(); $groupRunner = new InspectRunner(); $groupTeacher = new Actor(10, true); $groupTeacher->managedGroups = [7, 8];
$groupStudent = new Actor(20); $groupStudent->groups = [7]; $groupOutsider = new Actor(21); $groupOutsider->groups = [9];
$groupTeacherService = new PracticeService($groupRecords, $groupTeacher, $groupRunner);
$groupLearner = new PracticeService($groupRecords, $groupStudent, $groupRunner); $outside = new PracticeService($groupRecords, $groupOutsider, $groupRunner);
$groupInput = fixtureExercise(); $groupInput['audience'] = ['mode' => 'groups', 'groupIds' => [8, 7]];
$groupSaved = $groupRecords->saveExercise(\OjMicrobit\Domain\ExerciseSchema::normalize($groupInput), 10, null, 0);
$groupInput += ['id' => $groupSaved['id'], 'revision' => $groupSaved['revision']];
$groupTeacherService->saveExercise(reviewedExercise(fixtureExercise()));
verify($groupSaved['audience'] === ['mode' => 'groups', 'groupIds' => [7, 8]], 'Saved class audience is canonical and explicit');
verify(array_column($groupLearner->exercises()['items'], 'id') === [2, 1] && array_column($outside->exercises()['items'], 'id') === [2], 'Class membership filters the list before pagination without widening the site scope');
$groupGrade = $groupLearner->run(1, ['project' => fixtureProject(), 'audience' => ['mode' => 'site', 'groupIds' => []]]);
verify($groupGrade['passed'] && $groupRecords->results[0]['userId'] === 20, 'Authorized class grade uses the saved scope despite forged request audience');
$blocked = [
    fn () => $outside->exercise(1), fn () => $outside->project(1),
    fn () => $outside->saveProject(1, ['project' => fixtureProject(), 'revision' => 0]),
    fn () => $outside->preview(1, ['project' => fixtureProject(), 'inputs' => []]),
    fn () => $outside->run(1, ['project' => fixtureProject()]), fn () => $outside->attempts(1), fn () => $outside->attempt(1, 1),
];
foreach ($blocked as $operation) { throws($operation, \OjMicrobit\Support\OperationError::class, 'Every direct class practice/history entry denies an outside learner'); }
verify(count($groupRunner->calls) === 1 && count($groupRecords->results) === 1 && $groupRecords->projects === [], 'Denied class requests never invoke a worker, persist code or read another history detail');
$groupStudent->groups = [];
throws(fn () => $groupLearner->project(1), \OjMicrobit\Support\OperationError::class, 'Leaving or removal immediately revokes saved-project restore');
throws(fn () => $groupLearner->attempts(1), \OjMicrobit\Support\OperationError::class, 'Leaving a class immediately revokes ordinary history');
$groupStudent->groups = null;
$fallback = $groupLearner->exercises();
verify(array_column($fallback['items'], 'id') === [2] && !$fallback['groupAccessAvailable'] && is_string($fallback['audienceWarning']), 'Unavailable or incomplete provider preserves site exercises with an explicit safe warning');
verify($groupLearner->exercise(2)['audience']['mode'] === 'site', 'Site practice continues without a class provider');
throws(fn () => $groupLearner->exercise(1), \OjMicrobit\Support\OperationError::class, 'Provider failure never falls back a restricted exercise to site scope');
$groupStudent->groups = [7]; $groupRunner->after = static function () use ($groupStudent): void { $groupStudent->groups = []; };
throws(fn () => $groupLearner->run(1, ['project' => fixtureProject()]), \OjMicrobit\Support\OperationError::class, 'Membership is freshly rechecked after a grade finishes');
verify(count($groupRecords->results) === 1 && !$groupRecords->locked, 'A revoked grade is not persisted or returned and releases its lease');
$groupStudent->groups = [7];
throws(fn () => $groupLearner->preview(1, ['project' => fixtureProject(), 'inputs' => []]), \OjMicrobit\Support\OperationError::class, 'Membership is freshly rechecked after a preview finishes');
$groupRunner->after = null; $groupStudent->groups = [7];
$groupRunner->decorate = static function (): array { throw new \OjMicrobit\Support\OperationError('runner_unavailable', 503, '실행 서비스를 확인해 주세요.'); };
throws(fn () => $groupLearner->run(1, ['project' => fixtureProject()]), \OjMicrobit\Support\OperationError::class, 'Infrastructure failure remains a typed non-grade error');
verify(count($groupRecords->results) === 1 && !$groupRecords->locked, 'Infrastructure failure creates no attempt and recovers the lease');
$groupRunner->decorate = null;
verify($groupLearner->run(1, ['project' => fixtureProject()])['passed'], 'A later authorized run succeeds after infrastructure recovery');
$unmanaged = $groupInput; $unmanaged['audience']['groupIds'] = [99];
throws(fn () => $groupTeacherService->reviewExercise(reviewedExercise($unmanaged)), InvalidArgumentException::class, 'Prepublication review denies an unmanaged target class');
throws(fn () => $groupTeacherService->saveExercise(reviewedExercise($unmanaged)), InvalidArgumentException::class, 'Teacher cannot forge an unmanaged class assignment');
verify(count($groupRecords->records) === 2, 'Rejected class assignments create no exercise');
throws(fn () => $groupTeacherService->saveExercise(fixtureExercise()), InvalidArgumentException::class, 'Published save requires an explicit server-bound review');
$reviewInput = reviewedExercise($groupInput, 'blank');
$review = $groupTeacherService->reviewExercise($reviewInput);
verify($review['summary']['audience']['groupIds'] === [7, 8] && $review['studentPreview']['starterProject']['files']['main.ts'] === '' && count($review['studentPreview']['scenarios']) === 1, 'Server review shows the exact class audience, blank starter and public student examples');
$stale = reviewedExercise($groupInput); $stale['scenarios'][1]['inputs'][0]['value'] = 900;
throws(fn () => $groupTeacherService->saveExercise($stale), InvalidArgumentException::class, 'Changing a hidden condition invalidates the reviewed publication payload');
$groupTeacher->managedGroups = [];
throws(fn () => $groupTeacherService->saveExercise(reviewedExercise($groupInput)), \OjMicrobit\Support\OperationError::class, 'Class management is rechecked at save time after a prior review');
$classTeacher->managedClasses = [7, 8];
$missingAudience = $classInput; unset($missingAudience['audience']); $missingAudience['publicationReview'] = ['starterMode' => 'current'];
$legacyReview = $classTeacherService->reviewExercise($missingAudience);
verify($legacyReview['summary']['audience'] === ['mode' => 'classes', 'classIds' => [7, 8]], 'Older clients omitting audience never silently widen an existing restricted problem');
$missingAudience['publicationReview'] += ['confirmed' => true, 'fingerprint' => $legacyReview['fingerprint']];
verify($classTeacherService->saveExercise($missingAudience)['audience']['mode'] === 'classes', 'Reviewed legacy edits preserve their original restricted scope without requiring a new audience field');
$restrictPublic = fixtureExercise(); $restrictPublic += ['id' => 2, 'revision' => 1]; $restrictPublic['audience'] = ['mode' => 'classes', 'classIds' => [7]];
throws(fn () => $classTeacherService->saveExercise(reviewedExercise($restrictPublic)), InvalidArgumentException::class, 'An existing public problem cannot be turned into a restricted group or lesson problem');
$explicitPublic = $groupInput; $explicitPublic['audience'] = ['mode' => 'site', 'classIds' => []];
verify($groupTeacherService->saveExercise(reviewedExercise($explicitPublic))['audience']['mode'] === 'site', 'A source owner can explicitly publish an old restricted problem after a new exact review');
$newRestricted = fixtureExercise(); $newRestricted['audience'] = ['mode' => 'groups', 'groupIds' => [7]];
throws(fn () => $classTeacherService->saveExercise(reviewedExercise($newRestricted)), InvalidArgumentException::class, 'New public problem API cannot create a restricted audience');
throws(fn () => $classTeacherService->reviewExercise(reviewedExercise($newRestricted)), InvalidArgumentException::class, 'Review also rejects a new restricted problem before saving');
echo '[service] ' . $testChecks . " checks passed (in-memory repository and runner boundary fixtures)\n";

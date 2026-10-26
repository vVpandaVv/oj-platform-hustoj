<?php

declare(strict_types=1);

// Exercise the real ML application services without loading an AI plugin.
// Only persistence, public Context authority, upload storage and the inspection worker are test doubles.
namespace OJML\Playgrounds\Application {
    final class ContextService
    {
        public array $requests = [];
        public bool $allowed = true;
        public function creationGroups(int $actor, string $search, string $cursor, int $limit): array
        {
            return ['items' => [['type' => 'group', 'id' => 'g-1']], 'nextCursor' => ''];
        }
        public function assignmentSnapshot(int $actor, string $type, string $id): array
        {
            $this->assertAssignmentAccess($actor, ['context_type' => $type, 'context_id' => $id], 'assign');
            return ['context_revision' => 'ctx-1', 'roster_revision' => 'roster-1', 'learners' => [22]];
        }
        public function assertAssignmentAccess(int $actor, array $assignment, string $operation, ?int $learner = null): void
        {
            $this->requests[] = [$actor, $assignment['context_type'], $assignment['context_id'], $operation, $learner];
            if (!$this->allowed || !in_array($actor, [7, 22], true)) {
                throw new \OJML\Playgrounds\Domain\Problem('OJ_ML_CONTEXT_ACCESS_DENIED', '권한 없음', 403);
            }
        }
    }
}

namespace OJML\Playgrounds\Database {
    final class Repository
    {
        public array $assignments = [];
        public ?array $attempt = null;
        public array $events = [];
        public int $writes = 0;
        public function createAssignment(array $data): array
        {
            $id = count($this->assignments) + 1;
            $this->writes++;
            return $this->assignments[$id] = $data + ['id' => $id, 'status' => 'draft', 'revision' => 1];
        }
        public function getAssignment(int $id): ?array { return $this->assignments[$id] ?? null; }
        public function updateAssignment(int $id, int $revision, array $changes, int $actor): array
        {
            $this->writes++;
            return $this->assignments[$id] = array_replace($this->assignments[$id], $changes, ['revision' => $revision + 1]);
        }
        public function publishAssignment(int $id, int $revision, int $actor): array
        {
            return $this->updateAssignment($id, $revision, ['status' => 'published'], $actor);
        }
        public function isActiveLearner(int $id, int $user): bool { return $user === 22; }
        public function assignmentForLearner(array $assignment, int $user): array { return $assignment; }
        public function attemptsUsed(int $id, int $user): int { return 0; }
        public function activeAttempt(int $id, int $user): ?array { return $this->attempt; }
        public function latestSubmittedAttempt(int $id, int $user): ?array { return null; }
        public function hasSubmissionHistory(int $id, int $user): bool { return ($this->attempt['received_at'] ?? null) !== null; }
        public function createAttempt(int $id, int $user, ?string $key, ?string $client, ?int $format): array
        {
            $this->attempt = ['id' => 11, 'uuid' => '11111111-1111-4111-8111-111111111111',
                'assignment_id' => $id, 'user_id' => $user, 'attempt_no' => 1, 'artifact_id' => null,
                'artifact' => null, 'status' => 'in_progress', 'received_at' => null, 'review' => null];
            return ['attempt' => $this->attempt, 'created' => true];
        }
        public function getOwnedAttempt(string $uuid, int $user): ?array
        {
            return $this->getAttemptResult($uuid, $user);
        }
        public function getAttemptResult(string $uuid, ?int $user = null): ?array
        {
            if ($this->attempt === null || $this->attempt['uuid'] !== $uuid
                || ($user !== null && $this->attempt['user_id'] !== $user)) { return null; }
            $result = $this->attempt;
            if ($user !== null && ($result['review']['status'] ?? '') !== 'released') { $result['review'] = null; }
            return $result;
        }
        public function storageBytes(): int { return 0; }
        public function attachArtifact(string $uuid, int $user, array $artifact): array
        {
            $this->attempt = array_replace($this->attempt, ['artifact_id' => 4, 'artifact' => $artifact,
                'status' => 'quarantined', 'received_at' => gmdate('Y-m-d H:i:s')]);
            return $this->attempt;
        }
        public function appendReview(int $attempt, int $actor, int $version, array $review, string $event, string $correlation): array
        {
            $this->events[] = $event;
            return $this->attempt['review'] = $review + ['version' => $version + 1];
        }
        public function releaseReview(int $attempt, int $actor, int $version, string $correlation): array
        {
            $this->events[] = 'review.released';
            $this->attempt['review']['status'] = 'released';
            return $this->attempt['review'];
        }
    }
}

namespace OJML\Playgrounds\Storage {
    final class PrivateArtifactStore
    {
        public int $writes = 0;
        public function root(): string { return __DIR__; }
        public function putUploadedFile(string $path, int $maximum): StoredArtifact
        {
            $this->writes++;
            return new StoredArtifact('22222222-2222-4222-8222-222222222222', 'private/artifact.mlpx', str_repeat('a', 64), 1024, 'application/zip');
        }
        public function deleteOrphan(string $key): void { throw new \RuntimeException('Unexpected orphan deletion.'); }
    }
}

namespace OJML\Playgrounds\Ops {
    final class Lease
    {
        public static function acquire(string $key, int $slots = 1, int $seconds = 0): self { return new self(); }
        public function release(): void {}
    }
    final class InspectionWorker
    {
        public function __construct(private readonly \OJML\Playgrounds\Database\Repository $repository, mixed $storage, mixed $inspector) {}
        public function process(string $uuid, int $user, string $correlation): void { $this->repository->attempt['status'] = 'accepted'; }
    }
}

namespace OJML\Playgrounds\Security {
    final class Addon_Capabilities
    {
        public const REVIEW = 'review';
        public const MANAGE = 'manage';
        public static function wordpress(string $key): string { return 'ml_' . $key; }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';
    use OJML\Playgrounds\Application\AiEvidenceSource;
    use OJML\Playgrounds\Application\AiReviewService;
    use OJML\Playgrounds\Application\ArtifactPreviewService;
    use OJML\Playgrounds\Application\AssignmentService;
    use OJML\Playgrounds\Application\ContextService;
    use OJML\Playgrounds\Application\SubmissionService;
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    use OJML\Playgrounds\Storage\PrivateArtifactStore;

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
    function sanitize_key(string $text): string { return strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $text)); }
    function sanitize_file_name(string $text): string { return basename($text); }
    function wp_kses_post(string $text): string { return strip_tags($text); }
    function wp_json_encode(mixed $value): string|false { return json_encode($value); }
    function get_option(string $key, mixed $default = false): mixed
    {
        return $key === 'oj_ml_playgrounds_settings' ? ['minimumFreeBytes' => 0, 'maxArtifactBytes' => 1048576] : $default;
    }
    function user_can(int $actor, string $capability): bool { return $actor === 7 && in_array($capability, ['ml_review', 'ml_manage'], true); }
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if ($hook === 'oj_ml_playgrounds_is_uploaded_file') { return true; }
        return $value; // No AI plugin gateway is registered.
    }
    function standaloneAssert(bool $value, string $message): void
    {
        if (!$value) { throw new \RuntimeException($message); }
    }
    function standaloneReject(callable $callback, string $code): void
    {
        try { $callback(); } catch (Problem $problem) {
            standaloneAssert($problem->problemCode() === $code, 'Expected ' . $code . ', got ' . $problem->problemCode());
            return;
        }
        throw new \RuntimeException('Expected rejection: ' . $code);
    }

    $metadata = require dirname(__DIR__) . '/plugin-package.php';
    standaloneAssert(count($metadata['requires']) === 1 && str_starts_with($metadata['requires'][0], 'oj-platform:'),
        'Only OJ Platform may be a mandatory ML dependency.');
    $repository = new Repository();
    $contexts = new ContextService();
    $service = new AssignmentService($repository, $contexts);
    $input = ['contextType' => 'group', 'contextId' => 'g-1', 'title' => '독립 ML 과제',
        'description' => 'AI 없이 평가', 'taskType' => 'classification', 'maxAttempts' => 3,
        'policy' => [], 'rubric' => ['version' => 1, 'criteria' => [
            ['id' => 'analysis', 'title' => '실험 분석', 'description' => '실험 근거를 설명', 'maxScore' => 60],
            ['id' => 'reflection', 'title' => '성찰', 'description' => '한계와 개선', 'maxScore' => 40],
        ]]];
    $groups = $service->teachingContexts(7, '', '', 30);
    standaloneAssert(array_column($groups['items'], 'type') === ['group'], 'The creation selector must expose groups only.');
    standaloneReject(fn () => $service->createAssignment(array_replace($input, ['contextType' => 'class']), 7),
        'OJ_ML_ASSIGNMENT_GROUP_REQUIRED');
    standaloneAssert($repository->writes === 0 && $contexts->requests === [], 'New classes must fail before snapshot or persistence.');
    $contexts->allowed = false;
    standaloneReject(fn () => $service->createAssignment($input, 7), 'OJ_ML_CONTEXT_ACCESS_DENIED');
    standaloneAssert($repository->writes === 0, 'Group creation must still fail closed without Context authority.');
    $contexts->allowed = true;
    $assignment = $service->createAssignment($input, 7);
    standaloneAssert($assignment['status'] === 'draft' && $assignment['learners'] === [22], 'Group assignments must use the authorized roster snapshot.');
    $groupCopy = $service->duplicateAssignment($assignment['id'], 7);
    standaloneAssert($groupCopy['context_type'] === 'group' && $groupCopy['status'] === 'draft', 'Existing group duplication must continue working.');
    $legacyId = 3;
    $repository->assignments[$legacyId] = array_replace($assignment, ['id' => $legacyId, 'context_type' => 'class', 'context_id' => 'old-class']);
    $beforeCopy = $repository->writes;
    $contexts->allowed = false;
    standaloneReject(fn () => $service->duplicateAssignment($legacyId, 7), 'OJ_ML_CONTEXT_ACCESS_DENIED');
    $contexts->allowed = true;
    standaloneReject(fn () => $service->duplicateAssignment($legacyId, 7), 'OJ_ML_ASSIGNMENT_GROUP_REQUIRED');
    standaloneAssert($repository->writes === $beforeCopy && end($contexts->requests)[3] === 'manage',
        'Legacy class duplication must require an explicit group without bypassing source authorization.');
    $edited = $service->updateAssignment($legacyId, ['expectedRevision' => 1, 'title' => '기존 수업 편집'], 7);
    standaloneAssert($edited['context_type'] === 'class' && $edited['context_id'] === 'old-class', 'Unchanged historical class scope must remain editable.');
    standaloneReject(fn () => $service->updateAssignment($legacyId, ['expectedRevision' => 2, 'contextId' => 'another-class'], 7),
        'OJ_ML_ASSIGNMENT_GROUP_REQUIRED');
    standaloneReject(fn () => $service->updateAssignment($assignment['id'], ['expectedRevision' => 1, 'contextType' => 'class'], 7),
        'OJ_ML_ASSIGNMENT_GROUP_REQUIRED');
    $migrated = $service->updateAssignment($legacyId, ['expectedRevision' => 2, 'contextType' => 'group', 'contextId' => 'g-2'], 7);
    standaloneAssert($migrated['context_type'] === 'group' && $migrated['context_id'] === 'g-2', 'A draft class assignment may switch to an authorized group.');
    $repository->assignments[4] = array_replace($assignment, ['id' => 4, 'context_type' => 'class', 'context_id' => 'old-class']);
    $publishedClass = $service->publishAssignment(4, 1, 7);
    standaloneAssert($publishedClass['status'] === 'published' && $publishedClass['context_type'] === 'class'
        && $service->teacherAssignment(4, 7, 'evaluate')['id'] === 4,
        'Historical class assignments must remain publishable and accessible for evaluation.');

    $service->publishAssignment($assignment['id'], 1, 7);
    $created = $service->createAttempt($assignment['id'], 22, ['clientAppVersion' => '0.12.1', 'formatVersion' => 2]);
    $uuid = $created['attempt']['uuid'];
    standaloneReject(fn () => $service->createAttempt($assignment['id'], 23, []), 'OJ_ML_ASSIGNMENT_NOT_FOUND');
    $storage = new PrivateArtifactStore();
    $submission = new SubmissionService($repository, $service, $storage, new \OJML\Playgrounds\Inspection\MlpxInspector());
    $receipt = $submission->submit($uuid, 22, ['name' => 'project.mlpx', 'tmp_name' => 'test-upload', 'size' => 1024, 'error' => UPLOAD_ERR_OK], 'standalone-test');
    standaloneAssert($receipt['httpStatus'] === 201 && $receipt['attempt']['status'] === 'accepted' && $storage->writes === 1,
        'The ML upload/receipt path must work without an AI gateway.');
    $retry = $submission->submit($uuid, 22, [], 'standalone-retry');
    standaloneAssert($retry['httpStatus'] === 200 && $storage->writes === 1, 'Receipt retries must not upload a second artifact.');

    $preview = (new \ReflectionClass(ArtifactPreviewService::class))->newInstanceWithoutConstructor();
    $ai = new AiReviewService(new AiEvidenceSource($service, $preview), $repository);
    foreach (['status', 'enqueue'] as $action) {
        $absent = $ai->request($uuid, 7, ['action' => $action], 'standalone-test');
        standaloneAssert($absent['available'] === false && $absent['result'] === null,
            'An absent AI plugin must report unavailability, not prevent manual evaluation.');
    }
    standaloneReject(fn () => $ai->request($uuid, 7, ['action' => 'apply'], 'standalone-test'), 'OJ_ML_AI_UNAVAILABLE');
    standaloneAssert($repository->attempt['review'] === null, 'Unavailable AI must not write an evaluation.');
    $review = $service->review($uuid, 7, ['expectedVersion' => 0, 'criteriaScores' => [
        ['id' => 'analysis', 'score' => 54, 'feedback' => '실험 비교가 명확함'],
        ['id' => 'reflection', 'score' => 35, 'feedback' => '한계를 더 구체적으로'],
    ], 'feedback' => '수동 평가 완료'], 'standalone-review');
    standaloneAssert((float) $review['score'] === 89.0 && (float) $review['max_score'] === 100.0
        && count($review['details']['criteriaScores']) === 2, 'Manual rubric scores and criterion feedback must persist independently of AI.');
    standaloneAssert($service->ownResult($uuid, 22)['review'] === null, 'A draft evaluation must stay private to teachers.');
    $service->releaseReview($uuid, 7, 1, 'standalone-release');
    standaloneAssert($service->ownResult($uuid, 22)['review']['status'] === 'released'
        && $repository->events === ['review.saved', 'review.released'], 'Manual evaluation publication and audit events must work without AI.');
    standaloneReject(fn () => $service->ownResult($uuid, 23), 'OJ_ML_ATTEMPT_NOT_FOUND');
    $contexts->allowed = false;
    standaloneReject(fn () => $service->review($uuid, 7, ['expectedVersion' => 1], 'denied'), 'OJ_ML_CONTEXT_ACCESS_DENIED');
    standaloneAssert($repository->events === ['review.saved', 'review.released'], 'Revoked teacher authority must not change manual evaluation.');

    // Version 2 uses the same independent workflow, but only a defined grade may determine each score.
    $gradedInput = $input;
    $gradedInput['rubric']['version'] = 2;
    foreach ($gradedInput['rubric']['criteria'] as &$criterion) {
        $criterion['levels'] = [
            ['id' => 'met', 'label' => '충족', 'description' => '제출 근거가 해당 기준을 충족한다.', 'score' => $criterion['maxScore']],
            ['id' => 'not_met', 'label' => '미충족', 'description' => '기준을 충족하는 제출 근거가 없다.', 'score' => 0],
        ];
    }
    unset($criterion);
    $gradeRepository = new Repository();
    $gradeContexts = new ContextService();
    $gradeService = new AssignmentService($gradeRepository, $gradeContexts);
    $gradeAssignment = $gradeService->createAssignment($gradedInput, 7);
    $gradeService->publishAssignment($gradeAssignment['id'], 1, 7);
    $gradeAttempt = $gradeService->createAttempt($gradeAssignment['id'], 22, []);
    $gradeUuid = $gradeAttempt['attempt']['uuid'];
    $gradeSubmission = new SubmissionService($gradeRepository, $gradeService, new PrivateArtifactStore(), new \OJML\Playgrounds\Inspection\MlpxInspector());
    $gradeSubmission->submit($gradeUuid, 22, ['name' => 'levels.mlpx', 'tmp_name' => 'test-upload', 'size' => 1024, 'error' => UPLOAD_ERR_OK], 'grade-submit');
    $gradeScores = [
        ['id' => 'analysis', 'levelId' => 'met', 'score' => 60, 'feedback' => '조건과 결과 차이를 근거로 설명함'],
        ['id' => 'reflection', 'levelId' => 'not_met', 'score' => 0, 'feedback' => '개선 제안을 보완하세요'],
    ];
    $invalidGradeScores = $gradeScores; $invalidGradeScores[0]['score'] = 59;
    standaloneReject(fn () => $gradeService->review($gradeUuid, 7, ['expectedVersion' => 0, 'criteriaScores' => $invalidGradeScores], 'grade-invalid'),
        'OJ_ML_RUBRIC_LEVEL_SCORE_MISMATCH');
    standaloneAssert($gradeRepository->events === [], 'Arbitrary grade points must be rejected before review persistence.');
    $gradeReview = $gradeService->review($gradeUuid, 7, ['expectedVersion' => 0, 'criteriaScores' => $gradeScores], 'grade-review');
    standaloneAssert($gradeReview['score'] === 60 && $gradeReview['max_score'] === 100 && $gradeReview['details']['version'] === 2,
        'Defined grades determine the same independent manual review and exact total.');
    $gradeAi = new AiReviewService(new AiEvidenceSource($gradeService, $preview), $gradeRepository);
    standaloneAssert($gradeAi->request($gradeUuid, 7, ['action' => 'status'], 'grade-ai-absent')['available'] === false
        && $gradeRepository->attempt['review'] === $gradeReview, 'Missing AI must also leave version 2 grade decisions untouched.');
    standaloneAssert($gradeService->ownResult($gradeUuid, 22)['review'] === null, 'Unreleased grade selections must stay private.');
    $gradeService->releaseReview($gradeUuid, 7, 1, 'grade-release');
    $releasedGrades = $gradeService->ownResult($gradeUuid, 22)['review'];
    standaloneAssert($releasedGrades['details']['rubricSnapshot'] === $gradedInput['rubric']
        && array_column($releasedGrades['details']['criteriaScores'], 'levelId') === ['met', 'not_met'],
        'Independent publication preserves both selected grade IDs and the complete teacher grade descriptions.');
    standaloneAssert(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'OJAI\\')) === [],
        'This regression must not load any AI plugin classes.');
    echo "OJ ML standalone group assignment, upload and manual review regression passed.\n";
}

<?php

declare(strict_types=1);

// Isolated framework/repository doubles. The source, evidence projection, AI
// gateway/profile, ML draft validation and application service are real code.
namespace OJML\Playgrounds\Ops {
    final class Options
    {
        public static bool $maintenance = false;
        public static function all(): array { return ['maintenanceMode' => self::$maintenance]; }
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

namespace OJML\Playgrounds\Application {
    use OJML\Playgrounds\Domain\Problem;

    final class AssignmentService
    {
        public function __construct(public object $fixture) {}
        public function teacherResult(string $id, int $actor, string $operation): array
        {
            $this->assertAccess($actor, $operation);
            if ($id !== $this->fixture->attempt['uuid']) { throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', 'Not found.', 404); }
            return $this->fixture->attempt + ['rubric' => $this->fixture->assignment['rubric']];
        }
        public function teacherAssignment(int $id, int $actor, string $operation): array
        {
            $this->assertAccess($actor, $operation);
            if ($id !== $this->fixture->attempt['assignment_id']) { throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', 'Not found.', 404); }
            return $this->fixture->assignment;
        }
        private function assertAccess(int $actor, string $operation): void
        {
            if ($actor !== 7 || !$this->fixture->contextAllowed || $operation !== 'evaluate') {
                throw new Problem('OJ_ML_CONTEXT_FORBIDDEN', 'Not allowed.', 403);
            }
        }
    }

    final class ArtifactPreviewService
    {
        public function __construct(public object $fixture) {}
        public static function canPreviewStatus(string $status): bool { return in_array($status, ['accepted', 'under_review', 'graded'], true); }
        public function preview(string $id, int $actor): array
        {
            if (!$this->fixture->integrity) { throw new Problem('OJ_ML_ARTIFACT_INTEGRITY', 'Checksum mismatch.', 409); }
            if ($actor !== 7 || $id !== $this->fixture->attempt['uuid']) { throw new Problem('OJ_ML_CONTEXT_FORBIDDEN', 'Not allowed.', 403); }
            return $this->fixture->preview;
        }
    }
}

namespace OJML\Playgrounds\Database {
    use OJML\Playgrounds\Domain\Problem;

    final class Repository
    {
        public array $writes = [];
        public function __construct(public object $fixture) {}
        public function appendReview(int $attempt, int $actor, int $expected, array $draft, ?string $event = null, string $correlation = ''): array
        {
            if ($attempt !== $this->fixture->attempt['id'] || $actor !== 7) { throw new \RuntimeException('Wrong source or actor.'); }
            $current = (int) ($this->fixture->attempt['review']['version'] ?? 0);
            if ($current !== $expected) { throw new Problem('OJ_ML_REVIEW_VERSION_CONFLICT', 'Conflict.', 409); }
            $review = $draft + ['version' => $current + 1, 'reviewer_id' => $actor];
            $this->writes[] = compact('attempt', 'actor', 'expected', 'draft', 'event', 'correlation');
            $this->fixture->attempt['review'] = $review;
            return $review;
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    use OJML\Playgrounds\Application\AiEvidenceSource;
    use OJML\Playgrounds\Application\AiReviewService;
    use OJML\Playgrounds\Application\ArtifactPreviewService;
    use OJML\Playgrounds\Application\AssignmentService;
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    use OJML\Playgrounds\Ops\Options;

    $GLOBALS['aiReviewFilters'] = [];
    $GLOBALS['aiReviewActors'] = [7 => true, 8 => true];
    function user_can(int $actor, string $capability): bool
    {
        return !empty($GLOBALS['aiReviewActors'][$actor]) && in_array($capability, ['ml_review', 'ml_manage'], true);
    }
    function add_filter(string $name, callable $callback): void { $GLOBALS['aiReviewFilters'][$name][] = $callback; }
    function apply_filters(string $name, mixed $value): mixed
    {
        foreach ($GLOBALS['aiReviewFilters'][$name] ?? [] as $callback) { $value = $callback($value); }
        return $value;
    }
    function aiFlowAssert(bool $value, string $message): void
    {
        if (!$value) { throw new \RuntimeException($message); }
    }
    function aiFlowReject(callable $callback, string $code): void
    {
        try { $callback(); } catch (Problem $problem) {
            aiFlowAssert($problem->problemCode() === $code, 'Expected ' . $code . ', got ' . $problem->problemCode());
            return;
        }
        throw new \RuntimeException('Expected rejection: ' . $code);
    }
    function aiFlowFixture(): object
    {
        $GLOBALS['aiReviewFilters'] = [];
        $GLOBALS['aiReviewActors'] = [7 => true, 8 => true];
        Options::$maintenance = false;
        $fixture = (object) [
            'contextAllowed' => true, 'integrity' => true,
            'assignment' => ['title' => '실험 평가', 'description' => '실험 결과와 한계를 설명하세요.',
                'context_type' => 'class', 'context_id' => 'private-context', 'policy' => [],
                'rubric' => ['version' => 1, 'criteria' => [
                    ['id' => 'analysis', 'title' => '분석', 'description' => '설명 내용 검토', 'maxScore' => 100, 'evidenceRequirements' => []],
                ]]],
            'attempt' => ['id' => 11, 'uuid' => '11111111-1111-4111-8111-111111111111', 'assignment_id' => 22,
                'status' => 'accepted', 'user_id' => 239, 'artifact' => ['sha256' => str_repeat('a', 64)], 'review' => null],
            'preview' => ['source' => 'verified-read-only', 'content' => [
                'project' => ['taskType' => 'classification', 'dataType' => 'tabular', 'name' => 'private student name'],
                'settings' => ['sampleCount' => 100], 'experiments' => [],
                'portfolio' => ['sections' => [['id' => 'analysis', 'answered' => true, 'answer' => '표본이 적어 일반화에 한계가 있습니다.', 'attachmentCount' => 0]]],
                'notices' => [],
            ]],
        ];
        $fixture->repository = new Repository($fixture);
        $fixture->source = new AiEvidenceSource(new AssignmentService($fixture), new ArtifactPreviewService($fixture));
        $fixture->source->register();
        $fixture->service = new AiReviewService($fixture->source, $fixture->repository);
        return $fixture;
    }
    function aiFlowRequest(object $fixture, array $input, int $actor = 7): array
    {
        return $fixture->service->request($fixture->attempt['uuid'], $actor, $input, 'test-correlation');
    }

    $base = aiFlowFixture();
    $missing = aiFlowRequest($base, ['action' => 'status']);
    aiFlowAssert(!$missing['available'] && $missing['result'] === null && $base->repository->writes === [], 'Missing AI must not alter manual review.');
    aiFlowReject(fn () => aiFlowRequest($base, ['action' => 'status'], 239), 'OJ_ML_CAPABILITY_REQUIRED');
    aiFlowReject(fn () => aiFlowRequest($base, ['action' => 'status'], 8), 'OJ_ML_CONTEXT_FORBIDDEN');
    aiFlowReject(fn () => aiFlowRequest($base, ['action' => 'apply']), 'OJ_ML_AI_UNAVAILABLE');
    Options::$maintenance = true;
    aiFlowReject(fn () => aiFlowRequest($base, ['action' => 'status']), 'OJ_ML_MAINTENANCE');
    Options::$maintenance = false;
    $base->attempt['status'] = 'quarantined';
    aiFlowReject(fn () => aiFlowRequest($base, ['action' => 'status']), 'OJ_ML_AI_SUBMISSION_NOT_READY');

    $aiRoot = dirname(__DIR__, 2) . '/oj-ai-dashboard/src/ExternalAssessment/';
    if (!is_file($aiRoot . 'Assessment_Gateway_V1.php')) {
        fwrite(STDOUT, "OJ ML AI review local authorization guards passed; optional cross-plugin source unavailable.\n");
        exit(0);
    }
    foreach (['Assessment_Store.php', 'Assessment_Profile.php', 'Assessment_Gateway_V1.php'] as $file) { require_once $aiRoot . $file; }

    final class AiFlowStore implements \OJAI\Dashboard\ExternalAssessment\Assessment_Store
    {
        public array $runs = [];
        public array $results = [];
        public function latest(string $source, string $source_id): ?array
        {
            foreach (array_reverse($this->runs, true) as $run) { if ($run['source'] === $source && $run['source_id'] === $source_id) { return $run; } }
            return null;
        }
        public function enqueue(array $metadata): array
        {
            $prior = $this->latest($metadata['source'], $metadata['source_id']);
            if ($prior !== null && $prior['input_hash'] === $metadata['input_hash'] && in_array($prior['state'], ['queued', 'running', 'ready', 'needs_review'], true)) { return $prior; }
            $id = 'run-' . (count($this->runs) + 1);
            return $this->runs[$id] = $metadata + ['run_id' => $id, 'state' => 'queued', 'created_at' => '2026-10-14 00:00:00'];
        }
        public function claim(): ?array
        {
            foreach ($this->runs as $id => $run) {
                if ($run['state'] === 'queued') { $this->runs[$id]['state'] = 'running'; return $this->runs[$id]; }
            }
            return null;
        }
        public function finish(array $run, string $state, ?array $result, string $error_code = ''): void
        {
            $id = $run['run_id'];
            $this->runs[$id]['state'] = $state;
            $this->runs[$id]['error_code'] = $error_code;
            if ($result !== null) { $this->results[$id] = $result; }
        }
        public function result(array $run): ?array { return $this->results[$run['run_id']] ?? null; }
        public function release(): void {}
    }
    function aiFlowConnected(): object
    {
        $fixture = aiFlowFixture();
        $fixture->store = new AiFlowStore();
        $fixture->routing = ['fingerprint' => 'route-1'];
        $fixture->calls = [];
        $fixture->duringExecution = null;
        $fixture->output = ['schema' => 'ml_submission_assessment.v1', 'needs_review' => false,
            'criteria' => [['id' => 'analysis', 'score' => 80, 'feedback' => '표본의 한계를 설명했습니다.', 'evidence' => 'portfolio.answers[analysis]']],
            'summary' => '근거에 맞게 해석했습니다.', 'limitations' => []];
        $fixture->gateway = new \OJAI\Dashboard\ExternalAssessment\Assessment_Gateway_V1($fixture->store,
            static fn (): array => apply_filters('oj_ai_external_assessment_sources_v1', []),
            static fn (): ?array => $fixture->routing,
            static function (array $run, array $input) use ($fixture): array {
                $fixture->calls[] = compact('run', 'input');
                if (is_callable($fixture->duringExecution)) { ($fixture->duringExecution)(); }
                return ['success' => true, 'output' => $fixture->output, 'routing_fingerprint' => 'route-1'];
            }, static function (): void {});
        add_filter('oj_ai_external_assessment_gateway_v1', static fn (): object => $fixture->gateway);
        return $fixture;
    }
    function aiFlowEnqueue(object $fixture): array
    {
        return aiFlowRequest($fixture, ['action' => 'enqueue', 'actorId' => 239, 'consent' => ['approved' => true, 'routingFingerprint' => 'route-1']]);
    }
    function aiFlowApply(object $fixture, array $result, int $version = 0): array
    {
        return aiFlowRequest($fixture, ['action' => 'apply', 'evaluationId' => $result['id'], 'inputHash' => $result['inputHash'],
            'expectedVersion' => $version, 'score' => 100, 'feedback' => 'browser forged feedback', 'status' => 'released']);
    }

    $good = aiFlowConnected();
    $badIdentity = $good->source->snapshot($good->attempt['uuid'], 7);
    $badIdentity['source_id'] = '22222222-2222-4222-8222-222222222222';
    try {
        \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::snapshot($badIdentity, $good->attempt['uuid']);
        throw new \RuntimeException('AI profile accepted a mismatched source identity.');
    } catch (\InvalidArgumentException) {
        // Null-coalescing comparisons must not short-circuit the later guards.
    }
    $missingConsent = aiFlowRequest($good, ['action' => 'enqueue']);
    aiFlowAssert($missingConsent['code'] === 'ml_ai_consent_required' && $good->store->runs === [], 'Absent consent must fail without PHP warnings or side effects.');
    $denied = aiFlowRequest($good, ['action' => 'enqueue', 'consent' => ['approved' => false, 'routingFingerprint' => 'route-1']]);
    aiFlowAssert(!$denied['available'] && $good->store->runs === [], 'Consent must precede queue insertion.');
    $queued = aiFlowEnqueue($good);
    aiFlowAssert($queued['result']['state'] === 'queued', 'Real gateway must enqueue the authorized source.');
    aiFlowEnqueue($good);
    aiFlowAssert(count($good->store->runs) === 1, 'Repeated enqueue must not duplicate the same frozen source.');
    $good->gateway->run_once();
    $ready = aiFlowRequest($good, ['action' => 'status']);
    aiFlowAssert($ready['result']['state'] === 'ready' && $ready['result']['score'] === 80.0, 'Real output must pass through the ML status projection.');
    aiFlowAssert($good->calls[0]['run']['actor_id'] === 7, 'Request data cannot override the server actor.');
    $externalJson = json_encode($good->calls[0]['input'], JSON_UNESCAPED_UNICODE);
    foreach (['private student name', 'private-context', $good->attempt['uuid'], str_repeat('a', 64)] as $private) {
        aiFlowAssert(!str_contains($externalJson, $private), 'Provider received a private identity/reference.');
    }
    aiFlowReject(fn () => aiFlowApply($good, $ready['result'], 4), 'OJ_ML_REVIEW_VERSION_CONFLICT');
    $draft = aiFlowApply($good, $ready['result']);
    aiFlowAssert($draft['review']['score'] === 80 && $draft['review']['status'] === 'draft', 'Browser fields must never override AI scores or publish a draft.');
    aiFlowAssert($good->repository->writes[0]['event'] === 'review.ai_applied', 'Applying must record its audit event.');
    aiFlowApply($good, $ready['result']);
    aiFlowAssert(count($good->repository->writes) === 1, 'Lost-response retry must not append a second draft.');
    $good->attempt['review']['status'] = 'released';
    aiFlowReject(fn () => aiFlowApply($good, $ready['result'], 1), 'OJ_ML_AI_ALREADY_APPLIED');
    $good->assignment['description'] .= ' 새로운 기준';
    aiFlowAssert(aiFlowRequest($good, ['action' => 'status'])['result']['state'] === 'stale', 'Changed teacher criteria must invalidate cached output.');
    aiFlowReject(fn () => aiFlowApply($good, $ready['result'], 1), 'OJ_ML_AI_RESULT_STALE');

    $student = $good->gateway->status('oj-ml-playgrounds', $good->attempt['uuid'], 239);
    aiFlowAssert(!$student['available'] && $student['result'] === null, 'Direct gateway calls must also recheck learner access.');
    $other = $good->gateway->status('oj-ml-playgrounds', '22222222-2222-4222-8222-222222222222', 7);
    aiFlowAssert(!$other['available'] && $other['result'] === null, 'Another source cannot read the first source result.');

    foreach (['permission', 'context', 'routing', 'integrity'] as $mode) {
        $blocked = aiFlowConnected();
        aiFlowEnqueue($blocked);
        if ($mode === 'permission') { $GLOBALS['aiReviewActors'][7] = false; }
        if ($mode === 'context') { $blocked->contextAllowed = false; }
        if ($mode === 'routing') { $blocked->routing = ['fingerprint' => 'route-2']; }
        if ($mode === 'integrity') { $blocked->integrity = false; }
        $blocked->gateway->run_once();
        aiFlowAssert($blocked->calls === [] && $blocked->store->results === [], 'Worker must stop before external execution after ' . $mode . ' changes.');
    }
    $changed = aiFlowConnected();
    aiFlowEnqueue($changed);
    $changed->duringExecution = static function () use ($changed): void { $changed->assignment['rubric']['criteria'][0]['description'] .= ' changed'; };
    $changed->gateway->run_once();
    aiFlowAssert($changed->store->runs['run-1']['state'] === 'stale' && $changed->store->results === [], 'Source changes during execution cannot commit output.');
    $revoked = aiFlowConnected();
    aiFlowEnqueue($revoked);
    $revoked->duringExecution = static function (): void { $GLOBALS['aiReviewActors'][7] = false; };
    $revoked->gateway->run_once();
    aiFlowAssert($revoked->store->runs['run-1']['state'] === 'failed' && $revoked->store->results === [], 'Access revocation during execution cannot commit output.');

    $uncertain = aiFlowConnected();
    $uncertain->output['criteria'][0]['score'] = null;
    aiFlowEnqueue($uncertain);
    $uncertain->gateway->run_once();
    $needsReview = aiFlowRequest($uncertain, ['action' => 'status']);
    aiFlowAssert($needsReview['result']['state'] === 'needs_review' && $needsReview['result']['score'] === null, 'Insufficient evidence is not a zero score.');
    aiFlowReject(fn () => aiFlowApply($uncertain, $needsReview['result']), 'OJ_ML_AI_RESULT_STALE');
    $partial = aiFlowConnected();
    $partial->preview['content']['notices'] = ['clipped'];
    aiFlowReject(fn () => aiFlowEnqueue($partial), 'OJ_ML_AI_EVIDENCE_INCOMPLETE');
    aiFlowAssert($partial->store->runs === [], 'Truncated evidence cannot be queued.');

    foreach ([80, 81] as $proposedScore) {
        $levels = aiFlowConnected();
        $levels->assignment['rubric'] = ['version' => 2, 'criteria' => [[
            'id' => 'analysis', 'title' => '실험 분석', 'description' => '실험 비교의 근거를 확인합니다.', 'maxScore' => 100, 'evidenceRequirements' => [],
            'levels' => [
                ['id' => 'excellent', 'label' => '우수', 'description' => '같은 조건의 실험 지표와 한계를 모두 비교함', 'score' => 100],
                ['id' => 'developing', 'label' => '보통', 'description' => '실험 비교는 있으나 조건이나 한계의 설명이 일부 부족함', 'score' => 80],
                ['id' => 'insufficient', 'label' => '미흡', 'description' => '실험 비교의 근거를 제시하지 못함', 'score' => 0],
            ],
        ]]];
        $levels->output['criteria'][0]['score'] = $proposedScore;
        aiFlowEnqueue($levels);
        $levels->gateway->run_once();
        $levelStatus = aiFlowRequest($levels, ['action' => 'status']);
        aiFlowAssert(str_contains($levels->calls[0]['input']['rubric'][0]['description'], '실험 비교는 있으나'),
            'The existing AI gateway must receive the exact teacher level descriptions.');
        if ($proposedScore === 80) {
            aiFlowAssert($levelStatus['result']['state'] === 'ready'
                && $levelStatus['result']['criteriaScores'][0]['levelId'] === 'developing'
                && $levelStatus['result']['criteriaScores'][0]['levelLabel'] === '보통'
                && str_contains($levelStatus['result']['criteriaScores'][0]['levelDescription'], '조건이나 한계'),
                'Applicable AI scores must show the canonical level and criterion, not provider-invented labels.');
            $levelDraft = aiFlowApply($levels, $levelStatus['result']);
            aiFlowAssert($levelDraft['review']['details']['version'] === 2
                && $levelDraft['review']['details']['criteriaScores'][0]['levelId'] === 'developing'
                && $levelDraft['review']['status'] === 'draft', 'The selected level must survive draft application.');
            $levels->assignment['rubric']['criteria'][0]['levels'][1]['description'] .= ' 변경된 판단 기준';
            aiFlowAssert(aiFlowRequest($levels, ['action' => 'status'])['result']['state'] === 'stale',
                'Changing only a level descriptor must invalidate the previous AI result.');
        } else {
            aiFlowAssert($levelStatus['result']['state'] === 'needs_review' && $levelStatus['result']['score'] === null
                && $levelStatus['result']['errorCode'] === 'OJ_ML_AI_RUBRIC_LEVEL_MISMATCH',
                'An in-range but off-level score must not be presented as an applicable AI draft.');
            aiFlowReject(fn () => aiFlowApply($levels, $levelStatus['result']), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
            aiFlowAssert($levels->repository->writes === [], 'Direct apply must never bypass the level rubric.');
        }
    }

    // A confident provider must not bypass absent server-owned data evidence.
    $noData = aiFlowConnected();
    unset($noData->assignment['rubric']['criteria'][0]['evidenceRequirements']);
    $precheck = aiFlowRequest($noData, ['action' => 'status']);
    aiFlowAssert($precheck['evidenceReadiness']['status'] === 'needs_review', 'Legacy criteria conservatively require description and data statistics.');
    aiFlowEnqueue($noData);
    $noData->gateway->run_once();
    $guarded = aiFlowRequest($noData, ['action' => 'status']);
    aiFlowAssert($guarded['result']['state'] === 'needs_review' && $guarded['result']['score'] === null
        && $guarded['result']['criteriaScores'][0]['score'] === null
        && $guarded['result']['criteriaScores'][0]['reviewStatus'] === 'needs_review'
        && count($guarded['result']['criteriaScores'][0]['reviewReasons']) === 2,
        'A provider numeric score must become an explicit unscored teacher-check item.');
    aiFlowReject(fn () => aiFlowApply($noData, $guarded['result']), 'OJ_ML_AI_REVIEW_REQUIRED');
    aiFlowAssert($noData->repository->writes === [], 'Missing-data result must not create a score or a draft.');
    aiFlowAssert(str_contains($noData->calls[0]['input']['rubric'][0]['description'], '점수를 null'), 'The provider receives the insufficient-evidence instruction.');
    $noData->assignment['policy']['datasetDescription'] = '교사가 추가한 변수와 데이터 출처 설명';
    aiFlowAssert(aiFlowRequest($noData, ['action' => 'status'])['result']['state'] === 'stale', 'Adding dataset context invalidates the old AI digest.');

    restore_error_handler();
    fwrite(STDOUT, "OJ ML source / real AI gateway / draft application authorization and lifecycle regression passed.\n");
}

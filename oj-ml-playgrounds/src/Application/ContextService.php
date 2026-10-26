<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;
use Throwable;

/** Public Addon API adapter. All missing or inconsistent Runtime state fails closed. */
final class ContextService
{
    private const TYPES = ['class', 'group'];
    private const OPERATIONS = ['view', 'assign', 'evaluate', 'report', 'manage', 'participate'];
    private const MAX_ROSTER_PAGES = 50;
    private const MAX_ROSTER_LEARNERS = 5000;
    private const MAX_CONTEXT_PAGES = 1000;
    private const MAX_CONTEXTS = 10000;

    public function __construct(private readonly Addon_Runtime_Bridge $runtime)
    {
    }

    /** @return array{items:list<array<string,mixed>>,nextCursor:string} */
    public function teachingContexts(
        int $actorId,
        string $search = '',
        string $cursor = '',
        int $limit = 30
    ): array {
        return $this->listTeachingContexts($actorId, self::TYPES, $search, $cursor, $limit);
    }

    /** New assignments use groups; historical class assignments retain their original access scope. */
    public function creationGroups(int $actorId, string $search = '', string $cursor = '', int $limit = 30): array
    {
        return $this->listTeachingContexts($actorId, ['group'], $search, $cursor, $limit);
    }

    /** @param list<string> $types @return array{items:list<array<string,mixed>>,nextCursor:string} */
    private function listTeachingContexts(int $actorId, array $types, string $search, string $cursor, int $limit): array
    {
        try {
            $page = $this->gateway()->listTeachingContexts(new AddonTeachingContextQueryV1(
                $actorId,
                $types,
                substr($search, 0, 200),
                substr($cursor, 0, 512),
                max(1, min(100, $limit))
            ));
        } catch (Problem $problem) {
            throw $problem;
        } catch (Throwable) {
            throw new Problem('OJ_ML_CONTEXT_GATEWAY_FAILED', '수업 Context 목록을 가져오지 못했습니다.', 503);
        }
        if (!$page->isSuccessful()) {
            throw $this->gatewayProblem($page->problem(), 'OJ_ML_CONTEXT_LIST_FAILED');
        }
        $items = [];
        foreach ($page->items() as $item) {
            if (in_array($item->type(), $types, true)) {
                $items[] = [
                    'type' => $item->type(),
                    'id' => $item->id(),
                    'label' => $item->label(),
                    'lifecycleState' => $item->lifecycleState(),
                    'revision' => $item->revision(),
                    'attributes' => $item->attributes(),
                ];
            }
        }
        return ['items' => $items, 'nextCursor' => $page->nextCursor()];
    }

    /** @return list<array{type:string,id:string}> */
    public function allTeachingScopes(int $actorId): array
    {
        $cursor = '';
        $seenCursors = [];
        $scopes = [];
        for ($pageNumber = 0; $pageNumber < self::MAX_CONTEXT_PAGES; $pageNumber++) {
            $page = $this->teachingContexts($actorId, '', $cursor, 100);
            foreach ($page['items'] as $item) {
                $identity = $item['type'] . "\0" . $item['id'];
                $scopes[$identity] = ['type' => (string) $item['type'], 'id' => (string) $item['id']];
                if (count($scopes) > self::MAX_CONTEXTS) {
                    throw new Problem(
                        'OJ_ML_CONTEXT_SCOPE_LIMIT',
                        '관리 가능한 수업 Context가 10,000개를 초과합니다.',
                        503
                    );
                }
            }
            $next = $page['nextCursor'];
            if ($next === '') {
                return array_values($scopes);
            }
            if (isset($seenCursors[$next])) {
                throw new Problem('OJ_ML_CONTEXT_CURSOR_LOOP', '수업 Context 페이지 상태가 올바르지 않습니다.', 503);
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }
        throw new Problem('OJ_ML_CONTEXT_PAGE_LIMIT', '수업 Context 페이지가 허용 범위를 초과합니다.', 503);
    }

    /** @return array{context_revision:string,roster_revision:string,learners:list<int>} */
    public function assignmentSnapshot(int $actorId, string $contextType, string $contextId): array
    {
        $snapshot = $this->authorizeContext($actorId, $contextType, $contextId, 'assign', null, null);
        if ($snapshot['lifecycleState'] !== 'active') {
            throw new Problem(
                'OJ_ML_CONTEXT_INACTIVE',
                '활성 상태의 수업 Context만 과제로 지정할 수 있습니다.',
                409,
                ['lifecycleState' => $snapshot['lifecycleState']]
            );
        }
        $cursor = '';
        $rosterRevision = null;
        $learners = [];
        $seenCursors = [];
        for ($pageNumber = 0; $pageNumber < self::MAX_ROSTER_PAGES; $pageNumber++) {
            try {
                $page = $this->gateway()->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
                    $actorId,
                    $contextType,
                    $contextId,
                    $snapshot['revision'],
                    $rosterRevision,
                    $cursor,
                    100
                ));
            } catch (Problem $problem) {
                throw $problem;
            } catch (Throwable) {
                throw new Problem('OJ_ML_ROSTER_GATEWAY_FAILED', '과제 학습자 명단을 가져오지 못했습니다.', 503);
            }
            if (!$page->isSuccessful()) {
                throw $this->gatewayProblem($page->problem(), 'OJ_ML_ROSTER_SNAPSHOT_FAILED');
            }
            $pageRevision = $page->rosterRevision();
            if (!is_string($pageRevision) || $pageRevision === '' || ($rosterRevision !== null && $pageRevision !== $rosterRevision)) {
                throw new Problem('OJ_ML_ROSTER_REVISION_CHANGED', '학습자 명단이 조회 중 변경되었습니다. 다시 시도하세요.', 409);
            }
            $rosterRevision = $pageRevision;
            foreach ($page->items() as $learner) {
                $learners[$learner->learnerUserId()] = $learner->learnerUserId();
                if (count($learners) > self::MAX_ROSTER_LEARNERS) {
                    throw new Problem('OJ_ML_ROSTER_TOO_LARGE', '과제 학습자 명단이 5,000명을 초과합니다.', 413);
                }
            }
            $next = $page->nextCursor();
            if ($next === '') {
                return [
                    'context_revision' => $snapshot['revision'],
                    'roster_revision' => $rosterRevision,
                    'learners' => array_values($learners),
                ];
            }
            if (isset($seenCursors[$next])) {
                throw new Problem('OJ_ML_ROSTER_CURSOR_LOOP', '학습자 명단 페이지 상태가 올바르지 않습니다.', 503);
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }
        throw new Problem('OJ_ML_ROSTER_PAGE_LIMIT', '학습자 명단 페이지가 허용 범위를 초과합니다.', 503);
    }

    /** @param array<string,mixed> $assignment */
    public function assertAssignmentAccess(
        int $actorId,
        array $assignment,
        string $operation,
        ?int $learnerId = null
    ): void {
        $type = is_string($assignment['context_type'] ?? null) ? $assignment['context_type'] : '';
        $id = is_string($assignment['context_id'] ?? null) ? $assignment['context_id'] : '';
        $revision = is_string($assignment['context_revision'] ?? null) ? $assignment['context_revision'] : '';
        if ($revision === '') {
            throw new Problem('OJ_ML_CONTEXT_SNAPSHOT_MISSING', '과제 Context 스냅샷이 없어 접근을 확인할 수 없습니다.', 503);
        }
        // Snapshot revisions remain audit evidence. Current access always uses current authority.
        // Teacher access is to immutable assignment records, independent of today's learner roster.
        $snapshot = $this->authorizeContext($actorId, $type, $id, $operation, $operation === 'participate' ? $learnerId : null, null);
        if (in_array($operation, ['participate', 'assign'], true) && $snapshot['lifecycleState'] !== 'active') {
            throw new Problem('OJ_ML_CONTEXT_INACTIVE', '비활성 수업에서는 새 실습이나 제출을 할 수 없습니다.', 409);
        }
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        return $this->runtime->diagnostics();
    }

    /** @return array{type:string,id:string,revision:string,lifecycleState:string,actorRelation:string} */
    private function authorizeContext(
        int $actorId,
        string $contextType,
        string $contextId,
        string $operation,
        ?int $learnerId,
        ?string $expectedRevision
    ): array {
        if (!in_array($contextType, self::TYPES, true) || $contextId === '' || !in_array($operation, self::OPERATIONS, true)) {
            throw new Problem('OJ_ML_CONTEXT_INVALID', '지원하지 않는 과제 Context입니다.', 400);
        }
        try {
            $decision = $this->gateway()->authorize(new AddonContextAccessRequestV1(
                $actorId,
                $contextType,
                $contextId,
                $operation,
                $learnerId,
                $expectedRevision
            ));
        } catch (Problem $problem) {
            throw $problem;
        } catch (Throwable) {
            throw new Problem('OJ_ML_CONTEXT_GATEWAY_FAILED', 'Context 접근 권한을 확인하지 못했습니다.', 503);
        }
        if (!$decision->isAllowed()) {
            $code = strtolower($decision->code());
            $status = $this->decisionStatus($code);
            $message = match ($status) {
                409 => '수업 Context가 변경되었습니다. 과제를 다시 동기화하세요.',
                503 => 'Context 접근 권한을 현재 확인할 수 없습니다.',
                default => '이 수업 Context에 접근할 권한이 없습니다.',
            };
            throw new Problem('OJ_ML_CONTEXT_ACCESS_DENIED', $message, $status, ['gatewayCode' => $code]);
        }
        $snapshot = $decision->snapshot();
        if ($snapshot === null || $snapshot->type() !== $contextType || $snapshot->id() !== $contextId) {
            throw new Problem('OJ_ML_CONTEXT_SNAPSHOT_INVALID', 'Context 권한 응답이 올바르지 않습니다.', 503);
        }
        if ($expectedRevision !== null && !hash_equals($expectedRevision, $snapshot->revision())) {
            throw new Problem('OJ_ML_CONTEXT_REVISION_CHANGED', '수업 Context가 변경되었습니다. 과제를 다시 동기화하세요.', 409);
        }
        return [
            'type' => $snapshot->type(),
            'id' => $snapshot->id(),
            'revision' => $snapshot->revision(),
            'lifecycleState' => $snapshot->lifecycleState(),
            'actorRelation' => $snapshot->actorRelation(),
        ];
    }

    private function gateway(): object
    {
        if (!$this->runtime->is_ready()) {
            throw new Problem('OJ_ML_RUNTIME_NOT_READY', 'OJ Platform Runtime 연결이 준비되지 않았습니다.', 503, [
                'state' => $this->runtime->state(),
                'code' => $this->runtime->code(),
            ]);
        }
        $gateway = $this->runtime->contexts();
        if ($gateway === null || $this->runtime->assignment_rosters() === null) {
            throw new Problem('OJ_ML_CONTEXT_GATEWAY_UNAVAILABLE', 'OJ Context/명단 API를 사용할 수 없습니다.', 503);
        }
        return $gateway;
    }

    private function decisionStatus(string $code): int
    {
        foreach (['revision', 'stale', 'conflict', 'changed'] as $needle) {
            if (str_contains($code, $needle)) {
                return 409;
            }
        }
        foreach (['unavailable', 'timeout', 'failed', 'error', 'not_ready', 'retry'] as $needle) {
            if (str_contains($code, $needle)) {
                return 503;
            }
        }
        return 403;
    }

    private function gatewayProblem(?object $problem, string $fallbackCode): Problem
    {
        if ($problem === null) {
            return new Problem($fallbackCode, 'OJ Context 요청을 완료하지 못했습니다.', 503);
        }
        $code = method_exists($problem, 'code') ? strtolower((string) $problem->code()) : 'unknown';
        $retryable = method_exists($problem, 'retryable') && $problem->retryable();
        $status = $retryable ? 503 : $this->decisionStatus($code);
        return new Problem($fallbackCode, 'OJ Context 요청을 완료하지 못했습니다.', $status, ['gatewayCode' => $code]);
    }
}

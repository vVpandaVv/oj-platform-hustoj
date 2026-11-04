<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use OjMicrobit\Core\Plugin;

/** Names and current lesson participation are provided only by the public platform contract. */
final class ClassLessonAccess
{
    public function __construct(private readonly ?AddonContextGatewayV1 $gateway) {}
    public static function current(): self
    {
        try { return new self(Plugin::runtime()?->contexts()); }
        catch (\Throwable) { return new self(null); }
    }
    public function learning(int $actor): ?array
    {
        if (!$this->gateway instanceof AddonLearningContextGatewayV1) { return null; }
        try {
            $page = $this->gateway->listLearningContexts(new AddonLearningContextQueryV1($actor, ['class'], '', '', 100));
            if (!$page->isSuccessful() || $page->nextCursor() !== '') { return null; }
            $items = []; $ids = [];
            foreach ($page->items() as $item) {
                if ($item->type() !== 'class' || preg_match('/\Aclass:([1-9][0-9]*)\z/D', $item->id(), $match) !== 1 || $item->lifecycleState() !== 'active' || ($item->attributes()['enabled'] ?? null) !== true) { return null; }
                $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || isset($ids[$id])) { return null; } $ids[$id] = true;
                $items[] = ['id' => $id, 'title' => $item->label()];
            }
            return $items;
        } catch (\Throwable) { return null; }
    }
    public function title(int $actor, int $classId, bool $teacher): string
    {
        try {
            $decision = $this->gateway?->authorize(new AddonContextAccessRequestV1($actor, 'class', 'class:' . $classId, $teacher ? 'manage' : 'participate')); $snapshot = $decision?->snapshot();
            if ($decision?->isAllowed() === true && $snapshot?->type() === 'class' && $snapshot->id() === 'class:' . $classId && ($teacher || ($snapshot->lifecycleState() === 'active' && ($snapshot->attributes()['enabled'] ?? null) === true))) { return $snapshot->label(); }
        } catch (\Throwable) { /* A source author is not a lesson access principal. */ }
        throw new OperationError('access_denied', 403, '선택한 수업에 접근할 권한이 없습니다.');
    }
    public function roster(int $actor, int $classId, string $cursor, int $limit, ?string $contextRevision, ?string $rosterRevision): array
    {
        if ($classId < 1 || $limit < 1 || $limit > 50 || strlen($cursor) > 512 || preg_match('/[\x00-\x1f\x7f]/', $cursor)
            || (($cursor === '') !== ($rosterRevision === null))) { throw new \InvalidArgumentException('수업 명단의 페이지 상태를 확인해 주세요.'); }
        foreach ([$contextRevision, $rosterRevision] as $revision) {
            if ($revision !== null && (strlen($revision) > 160 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $revision) !== 1)) { throw new \InvalidArgumentException('수업 명단의 저장 버전을 확인해 주세요.'); }
        }
        if (!$this->gateway instanceof AddonAssignmentRosterGatewayV1) { throw new OperationError('unavailable', 503, '수업 학생 명단에 연결하지 못했습니다. 잠시 후 다시 확인해 주세요.', 5); }
        try {
            $decision = $this->gateway->authorize(new AddonContextAccessRequestV1($actor, 'class', 'class:' . $classId, 'report'));
            $snapshot = $decision->snapshot();
            if (!$decision->isAllowed() || $snapshot?->type() !== 'class' || $snapshot->id() !== 'class:' . $classId) {
                throw new OperationError('access_denied', 403, '이 수업의 결과를 확인할 권한이 없습니다.');
            }
            if ($snapshot->lifecycleState() !== 'active' || ($snapshot->attributes()['enabled'] ?? null) !== true) { throw new OperationError('access_denied', 403, '비활성 수업의 현재 학생 명단은 확인할 수 없습니다. 보관된 제출 내역에서 결과를 확인해 주세요.'); }
            if ($contextRevision !== null && !hash_equals($snapshot->revision(), $contextRevision)) { throw new OperationError('conflict', 409, '수업 구성이 변경됐습니다. 첫 페이지부터 다시 확인해 주세요.'); }
            $page = $this->gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1($actor, 'class', 'class:' . $classId, $snapshot->revision(), $rosterRevision, $cursor, $limit));
            if (!$page->isSuccessful()) {
                if (in_array($page->problem()?->code(), ['addon_context_denied', 'addon_context_revision_mismatch', 'addon_roster_revision_mismatch'], true)) {
                    throw new OperationError('conflict', 409, '수업 권한이나 학생 명단이 변경됐습니다. 첫 페이지부터 다시 확인해 주세요.');
                }
                throw new OperationError('unavailable', 503, '수업 학생 명단을 확인하지 못했습니다. 잠시 후 다시 확인해 주세요.', 5);
            }
            $items = [];
            foreach ($page->items() as $learner) { $items[] = ['userId' => $learner->learnerUserId(), 'displayName' => $learner->displayName(), 'managementName' => $learner->managementName()]; }
            if (count($items) > $limit || $page->rosterRevision() === null) { throw new \RuntimeException('Invalid roster projection.'); }
            return ['items' => $items, 'nextCursor' => $page->nextCursor() === '' ? null : $page->nextCursor(), 'contextRevision' => $snapshot->revision(), 'rosterRevision' => $page->rosterRevision(), 'classTitle' => $snapshot->label()];
        } catch (OperationError $error) { throw $error; }
        catch (\Throwable) { throw new OperationError('unavailable', 503, '수업 학생 명단을 확인하지 못했습니다. 잠시 후 다시 확인해 주세요.', 5); }
    }
}

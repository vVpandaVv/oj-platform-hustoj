<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use OjMicrobit\Core\Plugin;

/** Uses only the public addon context contract; never reads another module's tables. */
final class GroupAudienceAccess
{
    public function __construct(private readonly ?AddonContextGatewayV1 $gateway) {}
    public static function current(): self
    {
        try { return new self(Plugin::runtime()?->contexts()); }
        catch (\Throwable) { return new self(null); }
    }
    public function teaching(int $actor, string $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > 50 || strlen($cursor) > 512 || preg_match('/[\x00-\x1f\x7f]/', $cursor)) {
            throw new \InvalidArgumentException('그룹(학급) 목록 기준과 페이지 크기를 확인해 주세요.');
        }
        $empty = ['available' => false, 'items' => [], 'nextCursor' => null];
        if ($this->gateway === null) { return $empty; }
        try {
            $page = $this->gateway->listTeachingContexts(new AddonTeachingContextQueryV1($actor, ['group'], '', $cursor, $limit));
            if (!$page->isSuccessful()) { return $empty; }
            $items = [];
            foreach ($page->items() as $item) {
                $id = self::groupId($item->type(), $item->id());
                if ($id === null) { return $empty; }
                $items[] = ['id' => $id, 'contextId' => $item->id(), 'title' => $item->label(), 'revision' => $item->revision(), 'lifecycleState' => $item->lifecycleState()];
            }
            return ['available' => true, 'items' => $items, 'nextCursor' => $page->nextCursor() === '' ? null : $page->nextCursor()];
        } catch (\Throwable) { return $empty; }
    }
    /** One complete bounded projection, with no per-exercise membership lookup. */
    public function learningIds(int $actor): ?array
    {
        if (!$this->gateway instanceof AddonLearningContextGatewayV1) { return null; }
        try {
            $page = $this->gateway->listLearningContexts(new AddonLearningContextQueryV1($actor, ['group'], '', '', 100));
            if (!$page->isSuccessful() || $page->nextCursor() !== '') { return null; }
            $ids = [];
            foreach ($page->items() as $item) {
                $id = self::groupId($item->type(), $item->id());
                if ($id === null || isset($ids[$id]) || $item->lifecycleState() !== 'active' || ($item->attributes()['enabled'] ?? null) !== true) { return null; }
                $ids[$id] = $id;
            }
            sort($ids, SORT_NUMERIC);
            return array_values($ids);
        } catch (\Throwable) { return null; }
    }
    public function requireManaged(int $actor, array $ids): void
    {
        foreach ($ids as $id) { $this->requireDecision($actor, $id, 'manage'); }
    }
    public function requireParticipant(int $actor, array $ids): void
    {
        $learning = $this->learningIds($actor);
        $eligible = $learning === null ? [] : array_values(array_intersect($ids, $learning));
        if ($eligible === []) { $this->denied(); }
        // Recheck one currently eligible group using the live ledger. This is
        // deliberately uncached, including after a long-running grade/preview.
        $this->requireDecision($actor, $eligible[0], 'participate');
    }
    private function requireDecision(int $actor, int $id, string $operation): void
    {
        try {
            $decision = $this->gateway?->authorize(new AddonContextAccessRequestV1($actor, 'group', 'group:' . $id, $operation));
            $snapshot = $decision?->snapshot();
            if ($decision?->isAllowed() === true && $snapshot?->type() === 'group' && $snapshot->id() === 'group:' . $id
                && $snapshot->lifecycleState() === 'active' && ($snapshot->attributes()['enabled'] ?? null) === true) { return; }
        } catch (\Throwable) { /* Public providers fail closed. */ }
        $this->denied();
    }
    private function denied(): never
    {
        throw new OperationError('access_denied', 403, '선택한 그룹(학급)에 접근할 권한이 없거나 그룹(학급) 연결을 확인할 수 없습니다.', null, null, 'exercise.audience.groupIds');
    }
    private static function groupId(string $type, string $id): ?int
    {
        if ($type !== 'group' || preg_match('/\Agroup:([1-9][0-9]*)\z/D', $id, $match) !== 1) { return null; }
        $number = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $number === false ? null : $number;
    }
}

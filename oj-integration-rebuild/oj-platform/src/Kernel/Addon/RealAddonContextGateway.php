<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassContextAccess;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use Throwable;

/**
 * Fail-closed adapter over the platform class/group ledger. Teaching
 * operations remain staff-gated; participate is limited to the actor's
 * own active relation. Learning lists and assignment rosters expose only
 * the bounded projections defined by Addon API 1.1.0.
 *
 * @internal
 */
final class RealAddonContextGateway implements
    AddonLearningContextGatewayV1,
    AddonAssignmentRosterGatewayV1
{
    private const MAX_ASSIGNMENT_LEARNERS = 5000;
    public function __construct(
        private readonly string $addonId,
        private readonly ClassContextAccess $classes,
        private readonly GroupContextAccess $groups,
        private readonly WordPressUserGateway $users,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1
    {
        try {
            $entries = [];
            foreach ($query->types() as $type) {
                if ($type === 'class') {
                    foreach ($this->classes->managedBy($query->actorUserId()) as $class) {
                        if ($class->isDeleted()) {
                            continue;
                        }
                        if ($query->search() !== '' && ! self::containsCi($class->name(), $query->search())) {
                            continue;
                        }
                        try {
                            $entries[] = [
                                'class',
                                $class->id(),
                                $this->safeLabel($class->name(), 'class:' . $class->id()),
                                self::lifecycleState($class->isEnabled()),
                                $this->classRevision($class),
                                $class->isEnabled(),
                            ];
                        } catch (Throwable) {
                            continue; // one malformed entry must not discard the rest of the page
                        }
                    }
                } elseif ($type === 'group') {
                    foreach ($this->groups->managedBy($query->actorUserId()) as $group) {
                        if ($group->isDeleted()) {
                            continue;
                        }
                        if ($query->search() !== '' && ! self::containsCi($group->name(), $query->search())) {
                            continue;
                        }
                        try {
                            $entries[] = [
                                'group',
                                $group->id(),
                                $this->safeLabel($group->name(), 'group:' . $group->id()),
                                self::lifecycleState($group->isEnabled()),
                                $this->groupRevision($group),
                                $group->isEnabled(),
                            ];
                        } catch (Throwable) {
                            continue; // one malformed entry must not discard the rest of the page
                        }
                    }
                }
                // 'site'/'user' have no backing mechanism yet — contribute nothing.
            }
            usort(
                $entries,
                static fn (array $left, array $right): int =>
                    [$left[0], $left[1]] <=> [$right[0], $right[1]]
            );

            $start = 0;
            if ($query->cursor() !== '') {
                foreach ($entries as $index => $entry) {
                    if ($entry[0] . ':' . $entry[1] === $query->cursor()) {
                        $start = $index + 1;
                        break;
                    }
                }
            }
            $slice = array_slice($entries, $start, $query->limit());
            $nextCursor = '';
            if ($start + count($slice) < count($entries)) {
                $last = $slice[count($slice) - 1];
                $nextCursor = $last[0] . ':' . $last[1];
            }

            $items = array_map(
                static fn (array $entry): AddonContextSummaryV1 => new AddonContextSummaryV1(
                    $entry[0],
                    $entry[0] . ':' . $entry[1],
                    $entry[2],
                    $entry[3],
                    $entry[4],
                    ['enabled' => $entry[5]]
                ),
                $slice
            );

            return new AddonContextPageV1(true, $items, $nextCursor, null);
        } catch (Throwable) {
            return new AddonContextPageV1(
                false,
                [],
                '',
                $this->unavailableProblem('context-list')
            );
        }
    }

    public function listLearningContexts(AddonLearningContextQueryV1 $query): AddonContextPageV1
    {
        try {
            $entries = [];
            foreach ($query->types() as $type) {
                $contexts = $type === 'class'
                    ? $this->classes->learningBy($query->actorUserId())
                    : $this->groups->learningBy($query->actorUserId());
                foreach ($contexts as $context) {
                    if ($context->isDeleted() || ! $context->isEnabled()) {
                        continue;
                    }
                    if ($query->search() !== '' && ! self::containsCi($context->name(), $query->search())) {
                        continue;
                    }
                    try {
                        $entries[] = [
                            $type,
                            $context->id(),
                            $this->safeLabel($context->name(), $type . ':' . $context->id()),
                            $type === 'class'
                                ? $this->classRevision($context)
                                : $this->groupRevision($context),
                        ];
                    } catch (Throwable) {
                        continue;
                    }
                }
            }
            usort(
                $entries,
                static fn (array $left, array $right): int =>
                    [$left[0], $left[1]] <=> [$right[0], $right[1]]
            );

            $start = $this->contextCursorStart($entries, $query->cursor());
            if ($start === null) {
                return new AddonContextPageV1(
                    false,
                    [],
                    '',
                    $this->unavailableProblem('learning-context-list')
                );
            }
            $slice = array_slice($entries, $start, $query->limit());
            $nextCursor = '';
            if ($start + count($slice) < count($entries)) {
                $last = $slice[count($slice) - 1];
                $nextCursor = $last[0] . ':' . $last[1];
            }
            $items = array_map(
                static fn (array $entry): AddonContextSummaryV1 => new AddonContextSummaryV1(
                    $entry[0],
                    $entry[0] . ':' . $entry[1],
                    $entry[2],
                    'active',
                    $entry[3],
                    ['enabled' => true]
                ),
                $slice
            );

            return new AddonContextPageV1(true, $items, $nextCursor, null);
        } catch (Throwable) {
            return new AddonContextPageV1(
                false,
                [],
                '',
                $this->unavailableProblem('learning-context-list')
            );
        }
    }

    public function listAssignmentLearners(
        AddonAssignmentRosterQueryV1 $query
    ): AddonAssignmentLearnerPageV1 {
        try {
            return match ($query->contextType()) {
                'class' => $this->classRoster($query),
                'group' => $this->groupRoster($query),
            };
        } catch (Throwable) {
            return $this->rosterUnavailable();
        }
    }

    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1
    {
        $correlationId = $this->correlations->next($this->addonId . ':context-authorize');
        try {
            return match ($request->contextType()) {
                'class' => $this->authorizeClass($request, $correlationId),
                'group' => $this->authorizeGroup($request, $correlationId),
                default => $this->deny($correlationId),
            };
        } catch (Throwable) {
            return $this->unavailable($correlationId);
        }
    }

    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        try {
            return match ($request->contextType()) {
                'class' => $this->snapshotClass($request),
                'group' => $this->snapshotGroup($request),
                default => new AddonContextSnapshotResultV1(false, null, $this->deniedProblem()),
            };
        } catch (Throwable) {
            return new AddonContextSnapshotResultV1(false, null, $this->unavailableProblem('context-snapshot'));
        }
    }

    private function authorizeClass(
        AddonContextAccessRequestV1 $request,
        string $correlationId
    ): AddonContextAccessDecisionV1 {
        $classId = self::parseId('class', $request->contextId());
        $class = $classId !== null ? $this->classes->findById($classId) : null;
        if ($class === null || $class->isDeleted()) {
            return $this->deny($correlationId);
        }
        if ($request->operation() === 'participate') {
            return $this->authorizeClassParticipation($request, $class, $correlationId);
        }
        if (! $this->classes->canManage($request->actorUserId(), $class)) {
            return $this->deny($correlationId);
        }
        $learnerUserId = $request->learnerUserId();
        if ($learnerUserId !== null) {
            if (
                $this->users->findById($learnerUserId) === null
                || ! $this->classes->isActiveLearner($classId, $learnerUserId)
            ) {
                return $this->deny($correlationId);
            }
        }
        $revision = $this->classRevision($class);
        if ($request->expectedRevision() !== null && ! hash_equals($revision, $request->expectedRevision())) {
            return $this->unavailable($correlationId);
        }

        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                'class',
                $request->contextId(),
                $this->safeLabel($class->name(), $request->contextId()),
                self::lifecycleState($class->isEnabled()),
                $revision,
                $this->classes->relationOf($request->actorUserId(), $class),
                ['enabled' => $class->isEnabled()]
            ),
            $correlationId
        );
    }

    private function authorizeGroup(
        AddonContextAccessRequestV1 $request,
        string $correlationId
    ): AddonContextAccessDecisionV1 {
        $groupId = self::parseId('group', $request->contextId());
        $group = $groupId !== null ? $this->groups->findById($groupId) : null;
        if ($group === null || $group->isDeleted()) {
            return $this->deny($correlationId);
        }
        if ($request->operation() === 'participate') {
            return $this->authorizeGroupParticipation($request, $group, $correlationId);
        }
        if (! $this->groups->canManage($request->actorUserId(), $group)) {
            return $this->deny($correlationId);
        }
        $learnerUserId = $request->learnerUserId();
        if ($learnerUserId !== null) {
            if (
                $this->users->findById($learnerUserId) === null
                || ! $this->groups->isActiveMember($groupId, $learnerUserId)
            ) {
                return $this->deny($correlationId);
            }
        }
        $revision = $this->groupRevision($group);
        if ($request->expectedRevision() !== null && ! hash_equals($revision, $request->expectedRevision())) {
            return $this->unavailable($correlationId);
        }

        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                'group',
                $request->contextId(),
                $this->safeLabel($group->name(), $request->contextId()),
                self::lifecycleState($group->isEnabled()),
                $revision,
                $this->groups->relationOf($request->actorUserId(), $group),
                ['enabled' => $group->isEnabled()]
            ),
            $correlationId
        );
    }

    private function authorizeClassParticipation(
        AddonContextAccessRequestV1 $request,
        LearningClass $class,
        string $correlationId
    ): AddonContextAccessDecisionV1 {
        $actorUserId = $request->actorUserId();
        if (
            ! $class->isEnabled()
            || ($request->learnerUserId() !== null && $request->learnerUserId() !== $actorUserId)
            || $this->users->findById($actorUserId) === null
            || ! $this->classes->isActiveLearner($class->id(), $actorUserId)
        ) {
            return $this->deny($correlationId);
        }
        $revision = $this->classRevision($class);
        if ($request->expectedRevision() !== null && ! hash_equals($revision, $request->expectedRevision())) {
            return $this->unavailable($correlationId);
        }

        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                'class',
                $request->contextId(),
                $this->safeLabel($class->name(), $request->contextId()),
                'active',
                $revision,
                'participant',
                ['enabled' => true]
            ),
            $correlationId
        );
    }

    private function authorizeGroupParticipation(
        AddonContextAccessRequestV1 $request,
        LearningGroup $group,
        string $correlationId
    ): AddonContextAccessDecisionV1 {
        $actorUserId = $request->actorUserId();
        if (
            ! $group->isEnabled()
            || ($request->learnerUserId() !== null && $request->learnerUserId() !== $actorUserId)
            || $this->users->findById($actorUserId) === null
            || ! $this->groups->isActiveMember($group->id(), $actorUserId)
        ) {
            return $this->deny($correlationId);
        }
        $revision = $this->groupRevision($group);
        if ($request->expectedRevision() !== null && ! hash_equals($revision, $request->expectedRevision())) {
            return $this->unavailable($correlationId);
        }

        return new AddonContextAccessDecisionV1(
            true,
            '',
            new AddonContextSnapshotV1(
                'group',
                $request->contextId(),
                $this->safeLabel($group->name(), $request->contextId()),
                'active',
                $revision,
                'member',
                ['enabled' => true]
            ),
            $correlationId
        );
    }

    private function classRoster(
        AddonAssignmentRosterQueryV1 $query
    ): AddonAssignmentLearnerPageV1 {
        $classId = self::parseId('class', $query->contextId());
        $class = $classId !== null ? $this->classes->findById($classId) : null;
        if (
            $class === null
            || $class->isDeleted()
            || ! $class->isEnabled()
            || ! $this->classes->canManage($query->actorUserId(), $class)
        ) {
            return $this->rosterDenied();
        }
        $contextRevision = $this->classRevision($class);
        if (! hash_equals($contextRevision, $query->expectedContextRevision())) {
            return $this->rosterUnavailable();
        }

        return $this->rosterPage(
            'class',
            $classId,
            $contextRevision,
            $this->classes->activeLearnerUserIds($classId),
            $query
        );
    }

    private function groupRoster(
        AddonAssignmentRosterQueryV1 $query
    ): AddonAssignmentLearnerPageV1 {
        $groupId = self::parseId('group', $query->contextId());
        $group = $groupId !== null ? $this->groups->findById($groupId) : null;
        if (
            $group === null
            || $group->isDeleted()
            || ! $group->isEnabled()
            || ! $this->groups->canManage($query->actorUserId(), $group)
        ) {
            return $this->rosterDenied();
        }
        $contextRevision = $this->groupRevision($group);
        if (! hash_equals($contextRevision, $query->expectedContextRevision())) {
            return $this->rosterUnavailable();
        }

        return $this->rosterPage(
            'group',
            $groupId,
            $contextRevision,
            $this->groups->activeLearnerUserIds($groupId),
            $query
        );
    }

    /** @param list<int> $candidateUserIds */
    private function rosterPage(
        string $contextType,
        int $contextId,
        string $contextRevision,
        array $candidateUserIds,
        AddonAssignmentRosterQueryV1 $query
    ): AddonAssignmentLearnerPageV1 {
        $candidateUserIds = array_values(array_unique(array_filter(
            $candidateUserIds,
            static fn (mixed $userId): bool => is_int($userId) && $userId > 0
        )));
        sort($candidateUserIds, SORT_NUMERIC);
        if (count($candidateUserIds) > self::MAX_ASSIGNMENT_LEARNERS) {
            return $this->rosterUnavailable();
        }

        $candidateMap = array_fill_keys($candidateUserIds, true);
        $learnerIdentities = [];
        foreach ($this->users->findMany($candidateUserIds) as $identity) {
            $userId = $identity->userId();
            if (isset($candidateMap[$userId])) {
                $learnerIdentities[$userId] = $identity;
            }
        }
        $learnerUserIds = array_keys($learnerIdentities);
        sort($learnerUserIds, SORT_NUMERIC);
        $rosterRevisionSource = json_encode([
            'contract' => 'roster-v2',
            'context_type' => $contextType,
            'context_id' => $contextId,
            'context_revision' => $contextRevision,
            'learners' => array_map(
                static fn (int $userId): array => [
                    'id' => $userId,
                    'display_name' => $learnerIdentities[$userId]->displayName(),
                    'management_name' => $learnerIdentities[$userId]->nickname(),
                ],
                $learnerUserIds
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($rosterRevisionSource)) {
            return $this->rosterUnavailable();
        }
        $rosterRevision = hash('sha256', $rosterRevisionSource);
        if (
            $query->expectedRosterRevision() !== null
            && ! hash_equals($rosterRevision, $query->expectedRosterRevision())
        ) {
            return $this->rosterUnavailable();
        }

        $start = 0;
        if ($query->cursor() !== '') {
            if (preg_match('/^r1\.([a-f0-9]{64})\.([1-9][0-9]{0,18})$/D', $query->cursor(), $matches) !== 1
                || ! hash_equals($rosterRevision, $matches[1])) {
                return $this->rosterUnavailable();
            }
            $lastUserId = (int) $matches[2];
            $lastIndex = array_search($lastUserId, $learnerUserIds, true);
            if ($lastIndex === false || $lastIndex + 1 >= count($learnerUserIds)) {
                return $this->rosterUnavailable();
            }
            $start = $lastIndex + 1;
        }

        $slice = array_slice($learnerUserIds, $start, $query->limit());
        $items = array_map(
            static fn (int $userId): AddonAssignmentLearnerV1 =>
                new AddonAssignmentLearnerV1(
                    $userId,
                    hash('sha256', implode('|', [
                        'membership-v1',
                        $contextType,
                        (string) $contextId,
                        (string) $userId,
                    ])),
                    $learnerIdentities[$userId]->displayName(),
                    $learnerIdentities[$userId]->nickname()
                ),
            $slice
        );
        $nextCursor = '';
        if ($start + count($slice) < count($learnerUserIds)) {
            $last = $slice[count($slice) - 1];
            $nextCursor = 'r1.' . $rosterRevision . '.' . $last;
        }

        return new AddonAssignmentLearnerPageV1(
            true,
            $items,
            $nextCursor,
            $rosterRevision,
            null
        );
    }

    private function rosterDenied(): AddonAssignmentLearnerPageV1
    {
        return new AddonAssignmentLearnerPageV1(
            false,
            [],
            '',
            null,
            $this->deniedProblem()
        );
    }

    private function rosterUnavailable(): AddonAssignmentLearnerPageV1
    {
        return new AddonAssignmentLearnerPageV1(
            false,
            [],
            '',
            null,
            $this->unavailableProblem('assignment-roster')
        );
    }

    /** @param list<array{0:string,1:int,2:string,3:string}> $entries */
    private function contextCursorStart(array $entries, string $cursor): ?int
    {
        if ($cursor === '') {
            return 0;
        }
        foreach ($entries as $index => $entry) {
            if ($entry[0] . ':' . $entry[1] === $cursor) {
                return $index + 1;
            }
        }

        return null;
    }

    private function snapshotClass(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        $classId = self::parseId('class', $request->contextId());
        $class = $classId !== null ? $this->classes->findById($classId) : null;
        if (
            $class === null
            || $class->isDeleted()
            || ! $this->classes->canManage($request->actorUserId(), $class)
        ) {
            return new AddonContextSnapshotResultV1(false, null, $this->deniedProblem());
        }
        $revision = $this->classRevision($class);
        if (! hash_equals($revision, $request->expectedRevision())) {
            return new AddonContextSnapshotResultV1(false, null, $this->unavailableProblem('context-snapshot'));
        }

        return new AddonContextSnapshotResultV1(
            true,
            new AddonContextSnapshotV1(
                'class',
                $request->contextId(),
                $this->safeLabel($class->name(), $request->contextId()),
                self::lifecycleState($class->isEnabled()),
                $revision,
                $this->classes->relationOf($request->actorUserId(), $class),
                ['enabled' => $class->isEnabled()]
            ),
            null
        );
    }

    private function snapshotGroup(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        $groupId = self::parseId('group', $request->contextId());
        $group = $groupId !== null ? $this->groups->findById($groupId) : null;
        if (
            $group === null
            || $group->isDeleted()
            || ! $this->groups->canManage($request->actorUserId(), $group)
        ) {
            return new AddonContextSnapshotResultV1(false, null, $this->deniedProblem());
        }
        $revision = $this->groupRevision($group);
        if (! hash_equals($revision, $request->expectedRevision())) {
            return new AddonContextSnapshotResultV1(false, null, $this->unavailableProblem('context-snapshot'));
        }

        return new AddonContextSnapshotResultV1(
            true,
            new AddonContextSnapshotV1(
                'group',
                $request->contextId(),
                $this->safeLabel($group->name(), $request->contextId()),
                self::lifecycleState($group->isEnabled()),
                $revision,
                $this->groups->relationOf($request->actorUserId(), $group),
                ['enabled' => $group->isEnabled()]
            ),
            null
        );
    }

    private function deny(string $correlationId): AddonContextAccessDecisionV1
    {
        return new AddonContextAccessDecisionV1(false, 'addon_context_denied', null, $correlationId);
    }

    private function unavailable(string $correlationId): AddonContextAccessDecisionV1
    {
        return new AddonContextAccessDecisionV1(false, 'addon_context_unavailable', null, $correlationId);
    }

    private function deniedProblem(): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_context_denied',
            '이 context에 접근할 권한이 없습니다.',
            $this->correlations->next($this->addonId . ':context'),
            false
        );
    }

    private function unavailableProblem(string $scope): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_context_unavailable',
            '학습 컨텍스트를 확인할 수 없습니다.',
            $this->correlations->next($this->addonId . ':' . $scope),
            true
        );
    }

    /**
     * Deliberately excludes the instructor roster — this is an
     * authorization-*validity* signal (did enable/delete/ownership change),
     * not a content diff. authorize()/snapshot() always re-run canManage()
     * fresh regardless of whether the caller's revision matches, so a
     * stale-but-matching revision never masks a roster change today; it
     * must never be treated as a substitute for that fresh check.
     */
    private function classRevision(LearningClass $class): string
    {
        return hash('sha256', implode('|', [
            'class',
            (string) $class->id(),
            $class->isEnabled() ? '1' : '0',
            $class->isDeleted() ? '1' : '0',
            (string) $class->ownerUserId(),
        ]));
    }

    private function groupRevision(LearningGroup $group): string
    {
        return hash('sha256', implode('|', [
            'group',
            (string) $group->id(),
            $group->isEnabled() ? '1' : '0',
            $group->isDeleted() ? '1' : '0',
            (string) $group->ownerUserId(),
        ]));
    }

    private static function lifecycleState(bool $enabled): string
    {
        return $enabled ? 'active' : 'inactive';
    }

    private function safeLabel(string $raw, string $fallback): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', ' ', strip_tags($raw)) ?? '';
        $stripped = trim($stripped);
        $label = function_exists('mb_substr') ? mb_substr($stripped, 0, 80) : substr($stripped, 0, 80);
        $label = trim($label);

        return $label !== '' ? $label : $fallback;
    }

    private static function containsCi(string $haystack, string $needle): bool
    {
        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $needle) !== false
            : stripos($haystack, $needle) !== false;
    }

    private static function parseId(string $type, string $contextId): ?int
    {
        $prefix = $type . ':';
        if (! str_starts_with($contextId, $prefix)) {
            return null;
        }
        $suffix = substr($contextId, strlen($prefix));
        if ($suffix === '' || strlen($suffix) > 18 || ! ctype_digit($suffix)) {
            return null;
        }
        $id = (int) $suffix;

        return $id > 0 ? $id : null;
    }
}

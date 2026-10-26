<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;

/**
 * Class/group access through OJ Platform's Context Gateway — the only
 * sanctioned way for an addon to read classes, groups and rosters.
 */
final class Context_Resolver {
    public const OPERATION_MANAGE = 'manage';
    public const OPERATION_PARTICIPATE = 'participate';

    /** @return list<array{type: string, id: string, label: string}> */
    public static function teaching_contexts(int $actor_user_id): array {
        $gateway = Runtime_Bridge::contexts();
        if (null === $gateway) {
            return array();
        }
        $out = array();
        $cursor = '';
        do {
            $page = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1($actor_user_id, array('class', 'group'), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                break;
            }
            foreach ($page->items() as $context) {
                if ('inactive' === $context->lifecycleState()) {
                    continue;
                }
                $out[] = array('type' => $context->type(), 'id' => $context->id(), 'label' => $context->label());
            }
            $cursor = $page->nextCursor();
        } while ('' !== $cursor && count($out) < 500);
        return $out;
    }

    /** @return list<string> "type|id" keys of the learner's own classes/groups */
    public static function learning_context_keys(int $actor_user_id): array {
        $gateway = Runtime_Bridge::learning_gateway();
        if (null === $gateway) {
            return array();
        }
        $out = array();
        $cursor = '';
        do {
            $page = $gateway->listLearningContexts(new AddonLearningContextQueryV1($actor_user_id, array('class', 'group'), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                break;
            }
            foreach ($page->items() as $context) {
                $out[] = $context->type() . '|' . $context->id();
            }
            $cursor = $page->nextCursor();
        } while ('' !== $cursor && count($out) < 500);
        return $out;
    }

    /**
     * Returns the context revision when allowed, null otherwise. The label
     * is written to $label when given.
     */
    public static function authorize(int $actor_user_id, string $context_type, string $context_id, string $operation, ?string &$label = null): ?string {
        $gateway = Runtime_Bridge::contexts();
        if (null === $gateway || ! in_array($context_type, array('class', 'group'), true)) {
            return null;
        }
        $decision = $gateway->authorize(new AddonContextAccessRequestV1($actor_user_id, $context_type, $context_id, $operation));
        if (! $decision->isAllowed()) {
            return null;
        }
        $snapshot = $decision->snapshot();
        $label = $snapshot ? $snapshot->label() : '';
        return $snapshot ? $snapshot->revision() : '';
    }

    /** @return list<int> learner user ids of a class/group */
    public static function list_all_learner_ids(int $actor_user_id, string $context_type, string $context_id, string $context_revision): array {
        $gateway = Runtime_Bridge::roster_gateway();
        if (null === $gateway) {
            return array();
        }
        $ids = array();
        $cursor = '';
        $roster_revision = null;
        do {
            $page = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
                $actor_user_id,
                $context_type,
                $context_id,
                $context_revision,
                $roster_revision,
                $cursor,
                100
            ));
            if (! $page->isSuccessful()) {
                break;
            }
            foreach ($page->items() as $learner) {
                $ids[] = $learner->learnerUserId();
            }
            $cursor = $page->nextCursor();
            $roster_revision = $page->rosterRevision();
        } while ('' !== $cursor);
        return array_values(array_unique($ids));
    }
}

<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;

/**
 * Thin, addon-facing wrapper around the Context Gateway (see
 * docs/DESIGN.md §7): resolves a teacher's/learner's class-group contexts,
 * authorizes a specific operation against one context, and pages through a
 * class/group roster. All three REST controllers go through this rather
 * than touching Runtime_Bridge's gateway objects directly.
 */
final class Context_Resolver {
    public const OPERATION_VIEW = 'view';
    public const OPERATION_MANAGE = 'manage';
    public const OPERATION_REPORT = 'report';

    /**
     * @return list<array{type: string, id: string, label: string, revision: string}>
     */
    public static function list_teaching_contexts(int $actor_user_id): array {
        $gateway = Runtime_Bridge::contexts();
        if (null === $gateway) {
            return array();
        }

        $contexts = array();
        $cursor = '';
        $seen_cursors = array();
        do {
            $page = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1($actor_user_id, array('class', 'group'), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                return array();
            }
            foreach ($page->items() as $context) {
                $contexts[$context->type() . ':' . $context->id()] = array(
                    'type' => $context->type(),
                    'id' => $context->id(),
                    'label' => $context->label(),
                    'revision' => $context->revision(),
                );
            }
            $cursor = $page->nextCursor();
            if ('' !== $cursor && isset($seen_cursors[$cursor])) {
                return array();
            }
            $seen_cursors[$cursor] = true;
        } while ('' !== $cursor);

        return array_values($contexts);
    }

    /**
     * @return list<array{type: string, id: string, label: string}>
     */
    public static function list_learning_contexts(int $actor_user_id, ?bool &$successful = null): array {
        $successful = false;
        $gateway = Runtime_Bridge::learning_gateway();
        if (null === $gateway) {
            return array();
        }

        $contexts = array();
        $cursor = '';
        $seen_cursors = array();
        do {
            $page = $gateway->listLearningContexts(new AddonLearningContextQueryV1($actor_user_id, array('class', 'group'), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                return array();
            }
            foreach ($page->items() as $context) {
                $contexts[$context->type() . ':' . $context->id()] = array(
                    'type' => $context->type(),
                    'id' => $context->id(),
                    'label' => $context->label(),
                );
            }
            $cursor = $page->nextCursor();
            if ('' !== $cursor && isset($seen_cursors[$cursor])) {
                return array();
            }
            $seen_cursors[$cursor] = true;
        } while ('' !== $cursor);

        $successful = true;
        return array_values($contexts);
    }

    /**
     * Authorizes $actor_user_id for $operation on the given context and, if
     * allowed, returns its current revision (needed to page the roster).
     * Returns null when the gateway is unavailable or access is denied.
     */
    public static function authorize(int $actor_user_id, string $context_type, string $context_id, string $operation, ?int $learner_user_id = null): ?string {
        $gateway = Runtime_Bridge::contexts();
        if (null === $gateway) {
            return null;
        }

        $decision = $gateway->authorize(new AddonContextAccessRequestV1($actor_user_id, $context_type, $context_id, $operation, $learner_user_id));
        if (! $decision->isAllowed()) {
            return null;
        }

        return $decision->snapshot()?->revision();
    }

    /**
     * Pages through the full roster of a class/group. $context_revision
     * must come from a prior authorize() call against the same context.
     *
     * @return list<int> learner user ids
     */
    public static function list_all_learner_ids(int $actor_user_id, string $context_type, string $context_id, string $context_revision): array {
        $gateway = Runtime_Bridge::roster_gateway();
        if (null === $gateway) {
            return array();
        }

        $learner_ids = array();
        $cursor = '';
        $roster_revision = null;
        $seen_cursors = array();

        do {
            $query = new AddonAssignmentRosterQueryV1(
                $actor_user_id,
                $context_type,
                $context_id,
                $context_revision,
                $roster_revision,
                $cursor,
                100
            );

            $page = $gateway->listAssignmentLearners($query);
            if (! $page->isSuccessful()) {
                return array();
            }

            foreach ($page->items() as $learner) {
                $learner_ids[] = $learner->learnerUserId();
            }

            $cursor = $page->nextCursor();
            if ('' !== $cursor && isset($seen_cursors[$cursor])) {
                return array();
            }
            $seen_cursors[$cursor] = true;
            $roster_revision = $page->rosterRevision();
        } while ('' !== $cursor);

        return array_values(array_unique($learner_ids));
    }
}

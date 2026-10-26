<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;

/**
 * Thin, addon-facing wrapper around the Context Gateway, mirroring
 * focus-tracker's Addon\Context_Resolver: resolves a teacher's class/group
 * contexts, authorizes a read-only "report" operation against one context
 * (and optionally one specific learner in it), and pages through a roster.
 */
final class Context_Resolver {
    public const OPERATION_REPORT = 'report';

    /**
     * @return list<array{type: string, id: string, label: string}>
     */
    public static function list_teaching_contexts(int $actor_user_id): array {
        $gateway = Runtime_Bridge::contexts();
        if (null === $gateway) {
            return array();
        }

        $page = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1($actor_user_id, array('class', 'group')));
        if (! $page->isSuccessful()) {
            return array();
        }

        return array_map(static function ($context): array {
            return array(
                'type' => $context->type(),
                'id' => $context->id(),
                'label' => $context->label(),
            );
        }, $page->items());
    }

    /**
     * Authorizes $actor_user_id for $operation on the given context and, if
     * allowed, returns its current revision (needed to page the roster).
     * When $learner_user_id is given, also confirms that learner is an
     * active member of this exact context. Returns null on denial or when
     * the gateway is unavailable.
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

    public static function learner_page(int $actor, string $type, string $id, string $revision, string $cursor = '', ?string $roster_revision = null): array|\WP_Error {
        $gateway = Runtime_Bridge::roster_gateway();
        if (null === $gateway) { return new \WP_Error('oj_code_lab_roster_unavailable', '학급 명단을 불러올 수 없습니다.', array('status' => 503)); }
        $page = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1($actor, $type, $id, $revision, $roster_revision, $cursor, 50));
        if (! $page->isSuccessful()) { return new \WP_Error('oj_code_lab_roster_changed', '학급 명단이 변경되었거나 조회에 실패했습니다. 목록을 다시 불러와 주세요.', array('status' => 409)); }
        return array('ids' => array_values(array_unique(array_map(static fn ($item): int => $item->learnerUserId(), $page->items()))),
            'nextCursor' => $page->nextCursor(), 'rosterRevision' => $page->rosterRevision());
    }
}

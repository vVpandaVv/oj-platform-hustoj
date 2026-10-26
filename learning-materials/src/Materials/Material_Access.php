<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use LearningMaterials\Addon\Runtime_Bridge;
use LearningMaterials\Support\Capabilities;
use Throwable;

/**
 * Group-based visibility rule for a material: no groups assigned means the
 * material is public to every logged-in member; otherwise the viewer must
 * be an active member of at least one assigned group, or hold the manage
 * capability. Membership is checked via authorize() with operation
 * 'participate' — the platform's Real Gateway treats view/assign/evaluate/
 * report/manage as staff-only (they all require the actor to already
 * manage the group), so 'participate' is the only operation that performs
 * the self-membership check an ordinary viewer needs.
 *
 * If the platform never granted this addon a Context gateway (declined at
 * registration, or the runtime bridge never connected), group-restricted
 * materials fail CLOSED — nobody but managers can see them — rather than
 * silently becoming public, since we can no longer verify membership.
 */
final class Material_Access {
    /** @param list<string> $group_ids */
    public function can_view(int $viewer_user_id, array $group_ids): bool {
        if (array() === $group_ids) {
            return true;
        }
        if (Capabilities::can_manage()) {
            return true;
        }

        $gateway = Runtime_Bridge::context_gateway();
        if (null === $gateway) {
            return false;
        }

        foreach ($group_ids as $group_id) {
            try {
                $decision = $gateway->authorize(new AddonContextAccessRequestV1(
                    $viewer_user_id,
                    'group',
                    $group_id,
                    'participate'
                ));
            } catch (Throwable $error) {
                continue;
            }
            if ($decision->isAllowed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Labels of the groups a material is restricted to, resolved from the
     * viewer's own authorize() snapshots (only ever returns groups the
     * viewer can actually see). Returns '' both when the material has no
     * group restriction and when none of its groups resolved for this
     * viewer. Managers resolve staff-authorized snapshots first because
     * managing a group does not imply student membership. Callers should
     * treat an empty result plus a non-empty
     * $group_ids as "restricted, but no readable label".
     *
     * @param list<string> $group_ids
     */
    public function scope_label(int $viewer_user_id, array $group_ids): string {
        if (array() === $group_ids) {
            return '';
        }
        $gateway = Runtime_Bridge::context_gateway();
        if (null === $gateway) {
            return '';
        }

        $labels = array();
        $operations = Capabilities::can_manage() ? array('view', 'participate') : array('participate');
        foreach ($group_ids as $group_id) {
            foreach ($operations as $operation) {
                try {
                    $decision = $gateway->authorize(new AddonContextAccessRequestV1(
                        $viewer_user_id,
                        'group',
                        $group_id,
                        $operation
                    ));
                } catch (Throwable $error) {
                    continue;
                }
                if ($decision->isAllowed() && null !== $decision->snapshot()) {
                    $label = $decision->snapshot()->label();
                    if ('' !== trim($label)) {
                        $labels[] = $label;
                        break;
                    }
                }
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Groups the given user manages, for the console form's checkbox list —
     * this is deliberately listTeachingContexts() (staff-side: "groups I
     * manage"), not listLearningContexts() (learner-side: "groups I'm
     * enrolled in"). A manager uploading materials is almost never also an
     * enrolled member of their own group, so the learner-side call would
     * come back empty for exactly the accounts that need this picker.
     *
     * @return array<string, string> group_id => label
     */
    public function group_choices(int $user_id): array {
        $gateway = Runtime_Bridge::context_gateway();
        if (null === $gateway) {
            return array();
        }

        $choices = array();
        $cursor = '';
        do {
            $page = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1($user_id, array('group'), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                break;
            }
            foreach ($page->items() as $summary) {
                $choices[$summary->id()] = $summary->label();
            }
            $cursor = $page->nextCursor();
        } while ('' !== $cursor && count($choices) < 500);

        return $choices;
    }
}

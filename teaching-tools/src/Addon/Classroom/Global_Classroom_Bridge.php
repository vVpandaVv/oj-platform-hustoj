<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Classroom;

use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetConfigProviderV1;
use TeachingTools\Support\Capabilities;

/** Configuration provider for the platform-owned authenticated shell slot. */
final class Global_Classroom_Bridge implements AddonSiteAssetConfigProviderV1 {
    /** @return array<string, mixed> */
    public function config(int $actorUserId): array {
        if (
            $actorUserId < 1
            || get_current_user_id() !== $actorUserId
            || (! Capabilities::can_join() && ! Capabilities::can_host())
        ) {
            return array();
        }

        return array(
            'restUrl' => esc_url_raw(rest_url('teaching-tools/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentUserId' => $actorUserId,
            'canJoin' => Capabilities::can_join(),
            'canHost' => Capabilities::can_host(),
            'classroomUrl' => home_url('/oj-console/addons/teaching-tools/classroom'),
            'joinUrl' => home_url('/oj-addons/teaching-tools/join'),
        );
    }
}

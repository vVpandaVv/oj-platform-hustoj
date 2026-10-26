<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use InvalidArgumentException;
use TeachingTools\Addon\Seating\Runtime_Bridge;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** The sole Teaching Tools boundary for platform and addon discovery. */
final class Integration_Controller {
    public static function register_routes(): void {
        register_rest_route('teaching-tools/v1', '/integrations/activities', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'activities'),
            'permission_callback' => array(Capabilities::class, 'can_host'),
        ));
    }

    public static function activities(WP_REST_Request $request) {
        $gateway = Runtime_Bridge::integrations();
        if (null === $gateway) {
            return new WP_Error(
                'teaching_tools_platform_integration_unavailable',
                __('OJ Platform을 업데이트한 뒤 연동 활동을 사용할 수 있습니다.', 'teaching-tools'),
                array('status' => 503)
            );
        }

        $kinds = array_values(array_filter(array_map(
            'sanitize_key',
            explode(',', (string) $request->get_param('kinds'))
        )));
        try {
            $page = $gateway->searchActivities(new AddonActivityQueryV1(
                get_current_user_id(),
                sanitize_text_field((string) $request->get_param('search')),
                $kinds,
                100
            ));
        } catch (InvalidArgumentException) {
            return new WP_Error(
                'teaching_tools_invalid_activity_search',
                __('활동 검색 조건을 확인해 주세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }
        if (! $page->isSuccessful()) {
            $problem = $page->problem();

            return new WP_Error(
                'teaching_tools_activity_search_failed',
                $problem instanceof AddonApiProblemV1 ? $problem->message() : __('활동을 불러오지 못했습니다.', 'teaching-tools'),
                array(
                    'status' => 502,
                    'platformCode' => $problem instanceof AddonApiProblemV1 ? $problem->code() : '',
                )
            );
        }

        return rest_ensure_response(array('items' => array_map(
            static fn ($activity): array => array(
                'key' => $activity->key(),
                'title' => $activity->title(),
                'description' => $activity->description(),
                'url' => $activity->url(),
                'kind' => $activity->kind(),
                'source' => $activity->sourceLabel(),
                'contentType' => $activity->contentType(),
            ),
            $page->items()
        )));
    }
}

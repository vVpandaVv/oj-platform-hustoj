<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use TeachingTools\Addon\Seating\Runtime_Bridge;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Read-only bridge from the seating (and future roster-driven) tools to the
 * platform's own class/group roster, via the Addon API context gateway
 * handed to Runtime_Bridge at boot. Never used for anything but listing —
 * writes to class membership stay the platform's job.
 */
final class Roster_Controller {
    private const MAX_ROSTER_PAGES = 20;

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/contexts',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_contexts'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            // id charset must match AddonContextSummaryV1's own validation
            // (ValueValidator::safeToken: [A-Za-z0-9][A-Za-z0-9_.:@-]*) — a
            // narrower route regex here silently 404s ("no route found")
            // for any real context id containing '.', ':' or '@'.
            //
            // The '@' MUST be escaped as '\@' here: WP_REST_Server::dispatch()
            // wraps every registered route in '@^' . $route . '$@i' before
            // calling preg_match(). A raw, unescaped '@' inside our own
            // pattern gets read as THAT wrapper's closing delimiter, which
            // truncates the whole match silently (no PHP error — it just
            // never matches) and makes every id containing '@' 404 as "no
            // route found", no matter how correct the rest of the pattern
            // looks. Confirmed by reproducing WP's own preg_match() call
            // directly: unescaped '@' fails to match; '\@' matches exactly
            // ids like "group:8" that don't even contain '@'.
            '/contexts/(?P<type>class|group)/(?P<id>[A-Za-z0-9][A-Za-z0-9_.:\@-]{0,159})/roster',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_roster'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
                'args' => array(
                    'revision' => array('required' => true),
                ),
            )
        );
    }

    public static function list_contexts(WP_REST_Request $request) {
        $contexts = Runtime_Bridge::contexts();
        if (null === $contexts) {
            return self::runtime_unavailable_error();
        }

        $query = new AddonTeachingContextQueryV1(get_current_user_id(), array('class', 'group'));
        $page = $contexts->listTeachingContexts($query);

        if (! $page->isSuccessful()) {
            return self::problem_error('teaching_tools_context_list_failed', $page->problem(), __('학급 목록을 불러오지 못했습니다.', 'teaching-tools'));
        }

        $items = array_map(
            static function ($summary): array {
                return array(
                    'type' => $summary->type(),
                    'id' => $summary->id(),
                    'label' => $summary->label(),
                    'revision' => $summary->revision(),
                );
            },
            $page->items()
        );

        return rest_ensure_response(array('items' => $items));
    }

    public static function get_roster(WP_REST_Request $request) {
        $students = self::students_for_context(
            (string) $request['type'],
            (string) $request['id'],
            sanitize_text_field((string) $request->get_param('revision')),
            get_current_user_id()
        );
        if (is_wp_error($students)) {
            return $students;
        }

        return rest_ensure_response(array('students' => $students));
    }

    /** @return array{type:string,id:string,label:string,revision:string}|WP_Error */
    public static function latest_context(string $type, string $id, int $user_id) {
        $contexts = Runtime_Bridge::contexts();
        if (null === $contexts) {
            return self::runtime_unavailable_error();
        }
        $cursor = '';
        for ($page_index = 0; $page_index < 20; $page_index++) {
            $page = $contexts->listTeachingContexts(new AddonTeachingContextQueryV1($user_id, array($type), '', $cursor, 100));
            if (! $page->isSuccessful()) {
                return self::problem_error('teaching_tools_context_list_failed', $page->problem(), __('학급 목록을 불러오지 못했습니다.', 'teaching-tools'));
            }
            foreach ($page->items() as $summary) {
                if ($summary->type() === $type && $summary->id() === $id) {
                    return array(
                        'type' => $summary->type(),
                        'id' => $summary->id(),
                        'label' => $summary->label(),
                        'revision' => $summary->revision(),
                    );
                }
            }
            $cursor = $page->nextCursor();
            if ('' === $cursor) {
                break;
            }
        }

        return new WP_Error('teaching_tools_context_not_found', __('학급 또는 그룹을 찾지 못했습니다.', 'teaching-tools'), array('status' => 404));
    }

    /**
     * Resolve a teacher-authorized roster on the server. Classroom sessions
     * use this method instead of trusting a browser-supplied list of users.
     *
     * @return list<array{id: int, displayName: string, managementName: string}>|WP_Error
     */
    public static function students_for_context(string $type, string $id, string $revision, int $user_id) {
        $roster_gateway = Runtime_Bridge::roster_gateway();
        if (null === $roster_gateway) {
            return self::runtime_unavailable_error();
        }

        if (
            ! in_array($type, array('class', 'group'), true)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:@-]{0,159}\z/', $id)
        ) {
            return new WP_Error(
                'teaching_tools_invalid_context',
                __('학급 또는 그룹을 다시 선택해 주세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        if ('' === $revision) {
            return new WP_Error(
                'teaching_tools_context_revision_required',
                __('학급 정보가 오래되었습니다. 목록을 다시 불러와 주세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $students = array();
        $cursor = '';
        $roster_revision = null;

        for ($page = 0; $page < self::MAX_ROSTER_PAGES; $page++) {
            $query = new AddonAssignmentRosterQueryV1($user_id, $type, $id, $revision, $roster_revision, $cursor, 100);
            $result = $roster_gateway->listAssignmentLearners($query);

            if (! $result->isSuccessful()) {
                return self::problem_error('teaching_tools_roster_fetch_failed', $result->problem(), __('명단을 불러오지 못했습니다.', 'teaching-tools'));
            }

            foreach ($result->items() as $learner) {
                $learner_user_id = $learner->learnerUserId();
                $display_name = trim($learner->displayName());
                $management_name = trim($learner->managementName());
                if ('' === $display_name) {
                    $display_name = sprintf(__('학생 #%d', 'teaching-tools'), $learner_user_id);
                }
                if ('' === $management_name) {
                    $management_name = $display_name;
                }

                $students[$learner_user_id] = array(
                    'id' => $learner_user_id,
                    'displayName' => $display_name,
                    'managementName' => $management_name,
                );
            }

            $roster_revision = $result->rosterRevision();
            $cursor = $result->nextCursor();
            if ('' === $cursor) {
                break;
            }
        }

        if ('' !== $cursor) {
            return new WP_Error(
                'teaching_tools_roster_too_large',
                __('학급 또는 그룹 인원이 너무 많아 한 번에 불러올 수 없습니다.', 'teaching-tools'),
                array('status' => 413)
            );
        }

        return array_values($students);
    }

    /**
     * Surfaces the platform's own problem message/code (e.g. "this account
     * isn't recognized as a teacher of any class") instead of masking every
     * failure behind one generic string — that message is often the only
     * clue that what's actually missing is the platform's own teaching
     * permission, not this addon's "학습지원도구 진행" capability.
     */
    private static function problem_error(string $errorCode, ?AddonApiProblemV1 $problem, string $fallbackMessage): WP_Error {
        return new WP_Error(
            $errorCode,
            $problem instanceof AddonApiProblemV1 ? $problem->message() : $fallbackMessage,
            array(
                'status' => 502,
                'platformCode' => $problem instanceof AddonApiProblemV1 ? $problem->code() : '',
            )
        );
    }

    private static function runtime_unavailable_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_runtime_unavailable',
            __('학급 정보를 불러올 수 없습니다. 잠시 후 다시 시도해 주세요.', 'teaching-tools'),
            array('status' => 503)
        );
    }
}

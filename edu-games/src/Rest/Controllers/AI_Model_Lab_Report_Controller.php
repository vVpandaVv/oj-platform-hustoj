<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use EduGames\Addon\AiModelLab\Runtime_Bridge;
use EduGames\Games\Modules\AI_Model_Lab\Session_Service;
use EduGames\Support\Capabilities;

/**
 * Read-only teacher report: per-student AI Model Lab scores for a class or
 * group, sourced from the platform's own roster via the Addon API context
 * gateway. Mirrors teaching-tools' Rest\Controllers\Roster_Controller
 * pattern closely — see the route regex comment below before touching it.
 */
final class AI_Model_Lab_Report_Controller {
    private const MAX_ROSTER_PAGES = 20;
    private const ACTIVITY_KEYS = array('digit_recognition', 'titanic_survival');

    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/games/ai-model-lab/report/contexts',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_contexts'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
            )
        );

        register_rest_route(
            'edu-games/v1',
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
            // looks. See teaching-tools' Roster_Controller for the original
            // reproduction of this bug.
            '/games/ai-model-lab/report/contexts/(?P<type>class|group)/(?P<id>[A-Za-z0-9][A-Za-z0-9_.:\@-]{0,159})/scores',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_scores'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
                'args' => array(
                    'revision' => array('required' => true),
                ),
            )
        );
    }

    public static function list_contexts(\WP_REST_Request $request) {
        $contexts = Runtime_Bridge::contexts();
        if (null === $contexts) {
            return self::runtime_unavailable_error();
        }

        $query = new AddonTeachingContextQueryV1(get_current_user_id(), array('class', 'group'));
        $page = $contexts->listTeachingContexts($query);

        if (! $page->isSuccessful()) {
            return self::problem_error(
                'edu_games_ai_model_lab_context_list_failed',
                $page->problem(),
                __('학급 목록을 불러오지 못했습니다.', 'edu-games')
            );
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

    public static function get_scores(\WP_REST_Request $request) {
        $roster_gateway = Runtime_Bridge::roster_gateway();
        if (null === $roster_gateway) {
            return self::runtime_unavailable_error();
        }

        $type = (string) $request['type'];
        $id = (string) $request['id'];
        $revision = sanitize_text_field((string) $request->get_param('revision'));

        if ('' === $revision) {
            return new \WP_Error(
                'edu_games_ai_model_lab_revision_required',
                __('학급 정보가 오래되었습니다. 목록을 다시 불러와 주세요.', 'edu-games'),
                array('status' => 400)
            );
        }

        $actor_id = get_current_user_id();
        $learner_ids = array();
        $cursor = '';
        $roster_revision = null;

        for ($page = 0; $page < self::MAX_ROSTER_PAGES; $page++) {
            $query = new AddonAssignmentRosterQueryV1($actor_id, $type, $id, $revision, $roster_revision, $cursor, 100);
            $result = $roster_gateway->listAssignmentLearners($query);

            if (! $result->isSuccessful()) {
                return self::problem_error(
                    'edu_games_ai_model_lab_roster_fetch_failed',
                    $result->problem(),
                    __('명단을 불러오지 못했습니다.', 'edu-games')
                );
            }

            foreach ($result->items() as $learner) {
                $learner_ids[$learner->learnerUserId()] = true;
            }

            $roster_revision = $result->rosterRevision();
            $cursor = $result->nextCursor();
            if ('' === $cursor) {
                break;
            }
        }

        $user_ids = array_keys($learner_ids);

        /** @var array<int, array<string, mixed>> $rows keyed by userId */
        $rows = array();
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            $rows[$user_id] = array(
                'userId' => $user_id,
                'displayName' => $user ? $user->display_name : ('학생 #' . $user_id),
                'activities' => array(),
            );
        }

        // attempts_for_users() never resolves an Activity_Definition, so no
        // Activity_Catalog is needed here.
        $service = new Session_Service();

        foreach (self::ACTIVITY_KEYS as $activity_key) {
            $attempts = $service->attempts_for_users($activity_key, $user_ids);

            foreach ($attempts as $attempt) {
                if (! isset($rows[$attempt->userId()])) {
                    continue;
                }

                $rows[$attempt->userId()]['activities'][$activity_key] = array(
                    'status' => $attempt->status(),
                    'phase1Score' => $attempt->phase1Score(),
                    'phase2Score' => $attempt->phase2Score(),
                    'totalScore' => $attempt->totalScore(),
                );
            }
        }

        return rest_ensure_response(array('students' => array_values($rows)));
    }

    /**
     * Surfaces the platform's own problem message/code instead of masking
     * every failure behind one generic string.
     */
    private static function problem_error(string $errorCode, ?AddonApiProblemV1 $problem, string $fallbackMessage): \WP_Error {
        return new \WP_Error(
            $errorCode,
            $problem instanceof AddonApiProblemV1 ? $problem->message() : $fallbackMessage,
            array(
                'status' => 502,
                'platformCode' => $problem instanceof AddonApiProblemV1 ? $problem->code() : '',
            )
        );
    }

    private static function runtime_unavailable_error(): \WP_Error {
        return new \WP_Error(
            'edu_games_ai_model_lab_runtime_unavailable',
            __('학급 정보를 불러올 수 없습니다. 잠시 후 다시 시도해 주세요.', 'edu-games'),
            array('status' => 503)
        );
    }
}

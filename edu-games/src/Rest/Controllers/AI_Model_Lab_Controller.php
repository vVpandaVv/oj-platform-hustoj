<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Games\Modules\AI_Model_Lab\Activity_Catalog;
use EduGames\Games\Modules\AI_Model_Lab\Session_Service;
use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

/**
 * Gameplay REST endpoints for AI Model Lab. The client sends only
 * attemptId / modelKey / labels (its own hands-on classifications) —
 * correctness is always checked server-side against the real ground-truth
 * labels, and every resulting accuracy/confusion matrix/score is looked up
 * or computed server-side from the precomputed activity lookup tables;
 * nothing client-supplied is ever trusted as a result or score.
 */
final class AI_Model_Lab_Controller {
    private const ACTIVITY_KEYS = array('digit_recognition', 'titanic_survival');

    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/games/ai-model-lab/activities/(?P<activity>[a-z0-9_]+)/start',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'start'),
                'permission_callback' => array(__CLASS__, 'can_play'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/games/ai-model-lab/attempts/(?P<attemptId>\d+)/phase1',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'phase1'),
                'permission_callback' => array(__CLASS__, 'can_play'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/games/ai-model-lab/attempts/(?P<attemptId>\d+)/phase2',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'phase2'),
                'permission_callback' => array(__CLASS__, 'can_play'),
            )
        );
    }

    public static function can_play(): bool {
        return Capabilities::can_play() && Game_Visibility::is_open('ai-model-lab');
    }

    public static function start(\WP_REST_Request $request) {
        $activity_key = sanitize_key((string) $request['activity']);
        $catalog = self::catalog();
        $activity = $catalog->find($activity_key);

        if (null === $activity) {
            return new \WP_Error(
                'edu_games_ai_model_lab_unknown_activity',
                __('알 수 없는 활동입니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        $user_id = get_current_user_id();
        $service = new Session_Service($catalog);

        try {
            $attempt = $service->start_or_resume($user_id, $activity_key);
        } catch (\Throwable $e) {
            return self::error_from_exception($e);
        }

        return rest_ensure_response(array(
            'attemptId' => $attempt->id(),
            'activity' => $activity->publicView(),
            'resume' => array(
                'status' => $attempt->status(),
                'modelKey' => $attempt->modelKey(),
                'correctCount' => $attempt->correctCount(),
                'totalCount' => $attempt->totalCount(),
                'phase1Score' => $attempt->phase1Score(),
                'phase2Accuracy' => $attempt->phase2Accuracy(),
                'phase2Score' => $attempt->phase2Score(),
                'totalScore' => $attempt->totalScore(),
            ),
        ));
    }

    public static function phase1(\WP_REST_Request $request) {
        $attempt_id = (int) $request['attemptId'];
        $params = $request->get_json_params();
        $data = is_array($params) ? $params : array();

        $model_key = sanitize_key((string) ($data['modelKey'] ?? ''));
        $raw_labels = is_array($data['labels'] ?? null) ? $data['labels'] : array();

        $submitted_labels = array();
        foreach ($raw_labels as $sample_id => $label_key) {
            $submitted_labels[(int) $sample_id] = sanitize_key((string) $label_key);
        }

        $user_id = get_current_user_id();
        $service = new Session_Service(self::catalog());

        try {
            $outcome = $service->submit_phase1($attempt_id, $user_id, $model_key, $submitted_labels);
        } catch (\Throwable $e) {
            return self::error_from_exception($e);
        }

        return rest_ensure_response(array(
            'correctCount' => $outcome['correctCount'],
            'totalCount' => $outcome['totalCount'],
            'perSampleCorrect' => $outcome['perSampleCorrect'],
            'score' => $outcome['score'],
            'attemptId' => $outcome['attempt']->id(),
        ));
    }

    public static function phase2(\WP_REST_Request $request) {
        $attempt_id = (int) $request['attemptId'];
        $user_id = get_current_user_id();
        $service = new Session_Service(self::catalog());

        try {
            $outcome = $service->submit_phase2($attempt_id, $user_id);
        } catch (\Throwable $e) {
            return self::error_from_exception($e);
        }

        $performance = $outcome['performance'];
        $attempt = $outcome['attempt'];

        return rest_ensure_response(array_merge(
            $performance,
            array(
                'score' => $outcome['score'],
                'phase1Score' => $attempt->phase1Score(),
                'phase2Score' => $attempt->phase2Score(),
                'totalScore' => $attempt->totalScore(),
                'attemptId' => $attempt->id(),
            )
        ));
    }

    /**
     * Builds a fresh Activity_Catalog per request from the two
     * activities/*.php files — there is no persistent registry singleton
     * for this game, matching the task's design (avoids holding the
     * (large) lookup tables in memory across requests).
     */
    private static function catalog(): Activity_Catalog {
        $configs = array();

        foreach (self::ACTIVITY_KEYS as $key) {
            $path = __DIR__ . '/../../Games/Modules/AI_Model_Lab/activities/' . $key . '.php';
            if (is_readable($path)) {
                $configs[$key] = require $path;
            }
        }

        return new Activity_Catalog($configs);
    }

    private static function error_from_exception(\Throwable $e): \WP_Error {
        if ($e instanceof \InvalidArgumentException) {
            return new \WP_Error(
                'edu_games_ai_model_lab_invalid',
                $e->getMessage(),
                array('status' => 422)
            );
        }

        if ($e instanceof \RuntimeException) {
            // Covers both "attempt not found" and ownership/phase-ordering
            // violations — distinguish by message content since this addon
            // has no dedicated exception hierarchy for that split.
            $status = str_contains($e->getMessage(), '찾을 수 없') ? 404 : 403;

            return new \WP_Error(
                'edu_games_ai_model_lab_denied',
                $e->getMessage(),
                array('status' => $status)
            );
        }

        return new \WP_Error(
            'edu_games_ai_model_lab_error',
            __('요청을 처리하지 못했습니다.', 'edu-games'),
            array('status' => 500)
        );
    }
}

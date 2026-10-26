<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest\Controllers;

use OjCodeLab\Addon\Context_Resolver;
use OjCodeLab\Database\Notebook_Repository;
use OjCodeLab\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Teacher-only, read-only view onto a class/group roster's notebooks
 * (Addon\Console_Dashboard_Page_Handler, resources/js/dashboard.js). Every
 * request re-authorizes the acting teacher against the specific context via
 * Context_Resolver ("report" operation) before touching any student's row —
 * nothing here trusts a context id passed straight from the client.
 */
final class Roster_Controller {
    public static function register_routes(): void {
        register_rest_route('oj-code-lab/v1', '/contexts/(?P<type>class|group)/(?P<id>[^/]+)/notebooks', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'index'),
            'permission_callback' => array(__CLASS__, 'can_review'),
            'args' => array(
                'type' => array('required' => true),
                'id' => array(
                    'required' => true, 'type' => 'string', 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:@-]*$',
                    'validate_callback' => array(__CLASS__, 'validate_context_id'),
                    'sanitize_callback' => array(__CLASS__, 'sanitize_context_id'),
                ),
                'cursor' => array('type' => 'string', 'maxLength' => 512),
                'rosterRevision' => array('type' => 'string', 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:@-]*$'),
            ),
        ));

        register_rest_route('oj-code-lab/v1', '/contexts/(?P<type>class|group)/(?P<id>[^/]+)/notebooks/(?P<user_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'show'),
            'permission_callback' => array(__CLASS__, 'can_review'),
            'args' => array(
                'type' => array('required' => true),
                'id' => array(
                    'required' => true, 'type' => 'string', 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:@-]*$',
                    'validate_callback' => array(__CLASS__, 'validate_context_id'),
                    'sanitize_callback' => array(__CLASS__, 'sanitize_context_id'),
                ),
                'cursor' => array('type' => 'string', 'maxLength' => 512),
                'rosterRevision' => array('type' => 'string', 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:@-]*$'),
                'user_id' => array('required' => true),
            ),
        ));
    }

    public static function validate_context_id(mixed $value): bool {
        // WordPress retains percent escapes in route captures (class%3A1).
        // Decode once before applying the platform's opaque-token constraints.
        if (! is_string($value) || strlen($value) > 480) { return false; }
        $decoded = rawurldecode($value);
        return strlen($decoded) <= 160 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $decoded) === 1;
    }

    public static function sanitize_context_id(mixed $value): string|WP_Error {
        // WordPress sanitizes every parameter layer, including query overrides.
        if (! self::validate_context_id($value)) {
            return new WP_Error('oj_code_lab_invalid_context_id', __('학급/모둠 ID가 올바르지 않습니다.', 'oj-code-lab'), array('status' => 400));
        }
        return rawurldecode($value);
    }

    public static function can_review(): bool|WP_Error {
        return Capabilities::can_review() ?: new WP_Error('oj_code_lab_forbidden', __('열람 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function index(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $actor_id = get_current_user_id();
        $context_type = (string) $request->get_param('type');
        $context_id = (string) $request->get_param('id');

        $revision = Context_Resolver::authorize($actor_id, $context_type, $context_id, Context_Resolver::OPERATION_REPORT);
        if (null === $revision) {
            return new WP_Error('oj_code_lab_context_denied', __('이 학급/모둠에 접근할 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
        }

        $cursor = $request->get_param('cursor') ?? '';
        $roster_revision = $request->get_param('rosterRevision');
        if (('' === $cursor) !== (null === $roster_revision)) {
            return new WP_Error('oj_code_lab_invalid_page', '페이지 커서와 명단 버전을 함께 전달해 주세요.', array('status' => 400));
        }
        $page = Context_Resolver::learner_page($actor_id, $context_type, $context_id, $revision,
            $cursor, $roster_revision);
        if (is_wp_error($page)) { return $page; }
        $learner_ids = $page['ids'];
        $notebooks = Notebook_Repository::summaries_for_users($learner_ids);
        if (is_wp_error($notebooks)) { return $notebooks; }

        $rows = array();
        foreach ($learner_ids as $learner_id) {
            $notebook = $notebooks[$learner_id] ?? array('cellCount' => 0, 'hasError' => false, 'updatedAt' => null);
            $user = get_userdata($learner_id);
            $rows[] = array(
                'userId' => $learner_id,
                'displayName' => $user ? $user->display_name : ('#' . $learner_id),
                'cellCount' => $notebook['cellCount'],
                'updatedAt' => $notebook['updatedAt'],
                'hasError' => $notebook['hasError'],
            );
        }

        return rest_ensure_response(array('rows' => $rows, 'nextCursor' => $page['nextCursor'], 'rosterRevision' => $page['rosterRevision']));
    }

    public static function show(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $actor_id = get_current_user_id();
        $context_type = (string) $request->get_param('type');
        $context_id = (string) $request->get_param('id');
        $learner_id = (int) $request->get_param('user_id');

        $revision = Context_Resolver::authorize($actor_id, $context_type, $context_id, Context_Resolver::OPERATION_REPORT, $learner_id);
        if (null === $revision) {
            return new WP_Error('oj_code_lab_context_denied', __('이 학생의 노트북에 접근할 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
        }

        $notebook = Notebook_Repository::find_for_user($learner_id);
        if (is_wp_error($notebook)) {
            return $notebook;
        }
        $user = get_userdata($learner_id);

        return rest_ensure_response(array(
            'userId' => $learner_id,
            'displayName' => $user ? $user->display_name : ('#' . $learner_id),
            'cells' => $notebook['cells'],
            'updatedAt' => $notebook['updatedAt'],
        ));
    }
}

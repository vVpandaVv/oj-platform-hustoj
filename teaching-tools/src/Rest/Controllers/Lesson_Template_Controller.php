<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Lesson_Template_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Lesson_Template_Controller {
    public static function register_routes(): void {
        register_rest_route('teaching-tools/v1', '/lesson-templates', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'index'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'create'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
        ));
        register_rest_route('teaching-tools/v1', '/lesson-templates/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'show'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array(__CLASS__, 'delete'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
        ));
    }

    public static function index() {
        return rest_ensure_response(array('items' => array_map(
            static fn (array $row): array => self::present($row, false),
            Lesson_Template_Repository::all(get_current_user_id())
        )));
    }

    public static function create(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $name = self::limit(sanitize_text_field((string) ($params['name'] ?? '')), 120);
        $steps = self::steps($params['steps'] ?? null);
        if ('' === $name || null === $steps) {
            return new WP_Error('teaching_tools_invalid_lesson_template', __('수업안 이름과 단계를 확인해 주세요.', 'teaching-tools'), array('status' => 400));
        }
        $saved = Lesson_Template_Repository::save(get_current_user_id(), $name, $steps);
        if (null === $saved) {
            return new WP_Error('teaching_tools_lesson_template_save_failed', __('수업안을 저장하지 못했습니다.', 'teaching-tools'), array('status' => 500));
        }

        return rest_ensure_response(self::present($saved, true));
    }

    public static function show(WP_REST_Request $request) {
        $row = Lesson_Template_Repository::find((int) $request['id'], get_current_user_id());

        return null === $row ? self::not_found() : rest_ensure_response(self::present($row, true));
    }

    public static function delete(WP_REST_Request $request) {
        if (! Lesson_Template_Repository::delete((int) $request['id'], get_current_user_id())) {
            return self::not_found();
        }

        return rest_ensure_response(array('deleted' => true));
    }

    /** @return list<array<string, mixed>>|null */
    private static function steps($value): ?array {
        if (! is_array($value) || count($value) > 30) {
            return null;
        }
        $steps = array();
        foreach ($value as $step) {
            if (! is_array($step)) {
                return null;
            }
            $text = self::limit(sanitize_text_field((string) ($step['text'] ?? '')), 120);
            if ('' === $text) {
                continue;
            }
            $steps[] = array(
                'text' => $text,
                'done' => false,
                'mode' => in_array(($step['mode'] ?? ''), array('explain', 'solo', 'pair', 'group', 'wrap'), true) ? $step['mode'] : 'explain',
                'minutes' => max(1, min(180, (int) ($step['minutes'] ?? 5))),
            );
        }

        return $steps;
    }

    /** @param array<string, mixed> $row */
    private static function present(array $row, bool $with_steps): array {
        $result = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'updatedAt' => (string) $row['updated_at'],
        );
        if ($with_steps) {
            $steps = json_decode((string) $row['steps'], true);
            $result['steps'] = is_array($steps) ? $steps : array();
        }

        return $result;
    }

    private static function limit(string $value, int $length): string {
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function not_found(): WP_Error {
        return new WP_Error('teaching_tools_lesson_template_not_found', __('수업안을 찾지 못했습니다.', 'teaching-tools'), array('status' => 404));
    }
}

<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Database\Installer;
use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;

/** The shared problem bank. */
final class Bank_Controller {
    private const MAX_IMPORT = 3000;

    public static function register(): void {
        $can = array(Capabilities::class, 'can_teach');
        register_rest_route(Api::NS, '/bank', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'index'), 'permission_callback' => $can),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create'), 'permission_callback' => $can),
        ));
        register_rest_route(Api::NS, '/bank/(?P<id>\d+)', array(
            array('methods' => 'PUT', 'callback' => array(__CLASS__, 'update'), 'permission_callback' => $can),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'delete'), 'permission_callback' => $can),
        ));
        register_rest_route(Api::NS, '/bank/import', array('methods' => 'POST', 'callback' => array(__CLASS__, 'import'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/bank/defaults', array('methods' => 'POST', 'callback' => array(__CLASS__, 'defaults'), 'permission_callback' => $can));
    }

    public static function index(): \WP_REST_Response {
        $repo = new Problem_Repository();
        $items = array_map(array(__CLASS__, 'out'), $repo->all());
        return new \WP_REST_Response(array('items' => $items, 'tags' => $repo->tags(), 'operator' => Capabilities::is_operator()));
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function out(array $p): array {
        $p['editable'] = self::may_edit($p);
        unset($p['created_by']);
        return $p;
    }

    /**
     * Teachers change only the problems they added; the bundled bank and
     * everybody else's problems are the operators' (it is shared by every
     * class game on the site).
     *
     * @param array<string,mixed> $p
     */
    private static function may_edit(array $p): bool {
        return Capabilities::is_operator() || ((int) $p['created_by'] > 0 && (int) $p['created_by'] === get_current_user_id());
    }

    /** @return true|\WP_Error */
    private static function check_owner(int $id) {
        $p = (new Problem_Repository())->find($id);
        if (! $p) {
            return Api::error('not_found', '문제가 없어요.', 404);
        }
        return self::may_edit($p) ? true : Api::error('forbidden', '다른 선생님이 만든 문제나 기본 문제는 운영자만 고칠 수 있어요.', 403);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function create(\WP_REST_Request $request) {
        $repo = new Problem_Repository();
        try {
            $id = $repo->insert((array) $request->get_json_params(), get_current_user_id());
        } catch (\InvalidArgumentException $e) {
            return Api::error('invalid', $e->getMessage(), 400);
        }
        if (! $id) {
            return Api::error('duplicate', '같은 문제가 이미 있어요.', 409);
        }
        return new \WP_REST_Response(array('item' => self::out((array) $repo->find($id))), 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function update(\WP_REST_Request $request) {
        $owner = self::check_owner((int) $request['id']);
        if (is_wp_error($owner)) {
            return $owner;
        }
        try {
            $item = (new Problem_Repository())->update((int) $request['id'], (array) $request->get_json_params());
        } catch (\InvalidArgumentException $e) {
            return Api::error('invalid', $e->getMessage(), 400);
        }
        return $item ? new \WP_REST_Response(array('item' => self::out($item))) : Api::error('not_found', '문제가 없어요.', 404);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function delete(\WP_REST_Request $request) {
        $owner = self::check_owner((int) $request['id']);
        if (is_wp_error($owner)) {
            return $owner;
        }
        (new Problem_Repository())->delete((int) $request['id']);
        return new \WP_REST_Response(array('ok' => true));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function import(\WP_REST_Request $request) {
        $items = $request->get_param('items');
        if (! is_array($items) || ! $items) {
            return Api::error('empty', '추가할 문제가 없어요.', 400);
        }
        if (count($items) > self::MAX_IMPORT) {
            return Api::error('too_many', '한 번에 ' . self::MAX_IMPORT . '개까지 추가할 수 있어요.', 400);
        }
        $repo = new Problem_Repository();
        if ($request->get_param('replace')) {
            if (! Capabilities::is_operator()) {
                return Api::error('forbidden', '문제은행 전체를 바꾸는 것은 운영자만 할 수 있어요.', 403);
            }
            $repo->delete_all();
        }
        $added = 0;
        $skipped = 0;
        $errors = array();
        foreach ($items as $k => $item) {
            try {
                if ($repo->insert((array) $item, get_current_user_id())) {
                    $added++;
                } else {
                    $skipped++;
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = ($k + 1) . ': ' . $e->getMessage();
            }
        }
        return new \WP_REST_Response(array('added' => $added, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function defaults() {
        if (! Capabilities::is_operator()) {
            return Api::error('forbidden', '운영자만 할 수 있어요.', 403);
        }
        return new \WP_REST_Response(array('added' => Installer::load_default_bank(new Problem_Repository())));
    }
}

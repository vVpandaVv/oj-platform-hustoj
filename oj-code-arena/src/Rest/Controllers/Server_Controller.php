<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Database\Session_Repository;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Settings;
use OjCodeArena\Support\Ticket;

/**
 * Called by the game server, never by browsers: authenticated only by the
 * HMAC signature over the raw body (no WordPress login).
 */
final class Server_Controller {
    public static function register(): void {
        register_rest_route(Api::NS, '/server/results', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'results'),
            'permission_callback' => array(__CLASS__, 'signed'),
        ));
        register_rest_route(Api::NS, '/server/bank', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'bank'),
            'permission_callback' => array(__CLASS__, 'signed'),
        ));
    }

    public static function signed(\WP_REST_Request $request): bool {
        // Route relative to the API base, e.g. "/server/results".
        $route = (string) preg_replace('#^/' . preg_quote(Api::NS, '#') . '#', '', (string) $request->get_route());
        return Ticket::verify(
            $request->get_method(),
            $route,
            (string) $request->get_header('x-arena-time'),
            (string) $request->get_header('x-arena-signature'),
            (string) $request->get_body(),
            Settings::secret()
        );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function results(\WP_REST_Request $request) {
        $data = json_decode((string) $request->get_body(), true);
        $result = is_array($data) ? self::clean($data) : null;
        if (null === $result) {
            return Api::error('bad_result', 'Malformed round result.', 400);
        }
        $title = $result['title'];
        if ($result['sessionId'] > 0) {
            $session = (new Session_Repository())->find($result['sessionId']);
            if (! $session) {
                return Api::error('no_session', 'Unknown session.', 404);
            }
            $title = $session['title'];
        }
        $id = (new Round_Repository())->save($result, $title);
        return $id ? new \WP_REST_Response(array('ok' => true, 'id' => $id)) : Api::error('db', 'Could not save.', 500);
    }

    public static function bank(): \WP_REST_Response {
        return new \WP_REST_Response((new Problem_Repository())->for_game());
    }

    /**
     * Keeps only the fields we store, with types fixed.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>|null
     */
    private static function clean(array $in): ?array {
        if (! isset($in['teams']) || ! is_array($in['teams']) || count($in['teams']) > 8) {
            return null;
        }
        $mode = 'free' === ($in['mode'] ?? '') ? 'free' : 'class';
        $teams = array();
        foreach ($in['teams'] as $t) {
            if (! is_array($t) || ! isset($t['players']) || ! is_array($t['players'])) {
                return null;
            }
            $players = array();
            foreach (array_slice($t['players'], 0, 60) as $p) {
                if (! is_array($p)) {
                    return null;
                }
                $players[] = array(
                    'uid' => max(0, (int) ($p['uid'] ?? 0)),
                    'name' => mb_substr(sanitize_text_field((string) ($p['name'] ?? '')), 0, 40),
                    'bot' => ! empty($p['bot']),
                    'solved' => max(0, min(999, (int) ($p['solved'] ?? 0))),
                    'bosses' => max(0, min(999, (int) ($p['bosses'] ?? 0))),
                );
            }
            $attacks = is_array($t['attacks'] ?? null) ? $t['attacks'] : array();
            $teams[] = array(
                'team' => max(1, min(8, (int) ($t['team'] ?? 1))),
                'rank' => max(1, min(8, (int) ($t['rank'] ?? 1))),
                'pct' => max(0, min(100, (int) ($t['pct'] ?? 0))),
                'solved' => max(0, (int) ($t['solved'] ?? 0)),
                'total' => max(0, (int) ($t['total'] ?? 0)),
                'cleared' => ! empty($t['cleared']),
                'ms' => max(0, (int) ($t['ms'] ?? 0)),
                'bosses' => max(0, (int) ($t['bosses'] ?? 0)),
                'attacks' => array(
                    'sent' => max(0, (int) ($attacks['sent'] ?? 0)),
                    'got' => max(0, (int) ($attacks['got'] ?? 0)),
                    'blocked' => max(0, (int) ($attacks['blocked'] ?? 0)),
                ),
                'players' => $players,
            );
        }
        $started = (int) ($in['startedAt'] ?? 0);
        $ended = (int) ($in['endedAt'] ?? 0);
        if ($started <= 0 || $ended <= 0) {
            return null;
        }
        return array(
            'sessionId' => 'class' === $mode ? max(0, (int) ($in['sessionId'] ?? 0)) : 0,
            'mode' => $mode,
            'round' => max(1, (int) ($in['round'] ?? 1)),
            'title' => mb_substr(sanitize_text_field((string) ($in['title'] ?? '')), 0, 120),
            'reason' => sanitize_key((string) ($in['reason'] ?? '')),
            'startedAt' => $started,
            'endedAt' => $ended,
            'limitMs' => max(0, (int) ($in['limitMs'] ?? 0)),
            'blocks' => max(0, (int) ($in['blocks'] ?? 0)),
            'attacks' => ! empty($in['attacks']),
            'teams' => $teams,
        );
    }
}

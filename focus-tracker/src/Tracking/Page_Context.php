<?php
declare(strict_types=1);

namespace FocusTracker\Tracking;

use FocusTracker\Support\Clock;
use Throwable;
use WP_Error;

/** Canonical learning-page classification and actor-bound, expiring claims. */
final class Page_Context {
    public const VERSION = 1;
    public const TOKEN_LIFETIME_MS = 86400000;
    public const QUEUE_LIFETIME_MS = 86400000;

    public static function labels(): array {
        return array('problem' => '문제 풀이', 'submission' => '제출·결과', 'material' => '학습자료',
            'practice' => '실습·학습도구', 'game' => '교육게임', 'discussion' => '질문·토론',
            'other' => '기타 내부 학습', 'unclassified' => '이전 기록·미분류');
    }

    public static function label(string $category): string { return self::labels()[$category] ?? self::labels()['unclassified']; }

    /** No full URL, query string, user-supplied label or private route is saved. */
    public static function classify(string $path, array $query = array()): ?array {
        $path = self::normalize_path($path);
        if ($path === '') { return null; }
        $category = null; $subtype = null; $key = ''; $problem = null;
        if (preg_match('#^/problems/([1-9][0-9]*)(?:/(statistics|ranking|submissions))?$#D', $path, $m)) {
            $problem = self::positive_id($m[1]);
            if ($problem === null) { return null; }
            $category = isset($m[2]) ? 'submission' : 'problem';
            $subtype = isset($m[2]) ? 'result' : null;
            $key = 'problem:' . $m[1] . (isset($m[2]) ? ':' . $m[2] : '');
        } elseif (preg_match('#^/submit(?:/([1-9][0-9]*))?$#D', $path, $m)) {
            $problem = self::positive_id($m[1] ?? ($query['problem_id'] ?? null));
            $category = $problem === null ? 'other' : 'submission';
            $subtype = $problem === null ? null : 'form';
            $key = 'submit:' . ($problem ?? 'index');
        } elseif (preg_match('#^/submissions(?:/([1-9][0-9]*))?$#D', $path, $m)) {
            $category = 'submission'; $subtype = 'result'; $key = 'submission:' . ($m[1] ?? 'index');
        } elseif (preg_match('#^/quizzes(?:/([1-9][0-9]*)(?:/(attempt|statistics|attempts/[1-9][0-9]*))?)?$#D', $path, $m)) {
            // Quiz identifiers are not coding-problem identifiers.
            $category = 'practice'; $subtype = 'quiz';
            $key = 'quiz:' . ($m[1] ?? 'index') . (isset($m[2]) ? ':' . str_replace('/', ':', $m[2]) : '');
        } elseif (preg_match('#^/oj-addons/learning-materials(?:/([1-9][0-9]*))?$#D', $path, $m)) {
            $category = 'material'; $key = 'learning-materials:' . ($m[1] ?? 'index');
        } elseif (preg_match('#^/oj-addons/readcontents-library(?:/([A-Za-z0-9_-]{1,96})|/topic/([A-Za-z0-9_-]{1,96}))?$#D', $path, $m)) {
            $category = 'material'; $key = 'readcontents:' . (! empty($m[2]) ? 'topic:' . $m[2] : ($m[1] ?? 'index'));
        } elseif ($path === '/oj-addons/oj-code-lab/lab' || $path === '/oj-addons/oj-primm/learn') {
            $category = 'practice'; $key = $path === '/oj-addons/oj-code-lab/lab' ? 'code-lab' : 'primm';
        } elseif (preg_match('#^/oj-addons/edu-games/games/(ai-model-lab|ai-classifier-lab)$#D', $path, $m)) {
            $category = 'practice'; $subtype = 'ml'; $key = 'edu-games:' . $m[1];
        } elseif (preg_match('#^/oj-addons/oj-ml-playgrounds/(assignments|lab/[1-9][0-9]*|result/[1-9][0-9]*)$#D', $path, $m)) {
            $category = 'practice'; $subtype = 'ml'; $key = 'ml:' . str_replace('/', ':', $m[1]);
        } elseif (preg_match('#^/oj-addons/edu-games/(games(?:/[a-z0-9][a-z0-9-]{0,79})?|ranking)$#D', $path, $m)) {
            $category = 'game'; $key = 'edu-games:' . str_replace('/', ':', $m[1]);
        } elseif ($path === '/oj-addons/teaching-tools/join') {
            $category = 'practice'; $key = 'teaching-tools:join';
        } elseif (preg_match('#^/(board(?:/new|/thread/[1-9][0-9]*(?:/edit)?)?|notices(?:/new|/[1-9][0-9]*)?)$#D', $path, $m)) {
            $category = 'discussion'; $key = str_replace('/', ':', $m[1]);
        } elseif (preg_match('#^/(?:classes|groups)(?:/[1-9][0-9]*(?:/(?:members|submissions|progress|ranking))?)?$#D', $path)
            || in_array($path, array('/', '/problems', '/problem-books', '/dashboard', '/learning-spaces', '/ranking', '/contests'), true)
            || preg_match('#^/contests/[1-9][0-9]*(?:/(?:submissions|ranking|statistics|ip-check|ip-change))?$#D', $path)) {
            $category = 'other'; $key = $path === '/' ? 'home' : str_replace('/', ':', ltrim($path, '/'));
        }
        if ($category === null || strlen($key) > 160) { return null; }
        $type = 'general'; $id = null;
        foreach (array('contest', 'class', 'group') as $candidate) {
            $number = self::positive_id($query[$candidate . '_id'] ?? null);
            if ($number !== null) { $type = $candidate; $id = $candidate . ':' . $number; break; }
        }
        if (preg_match('#^/(classes|groups|contests)/([1-9][0-9]*)(?:/|$)#D', $path, $m)) {
            $type = array('classes' => 'class', 'groups' => 'group', 'contests' => 'contest')[$m[1]]; $id = $type . ':' . $m[2];
        }
        return array('category' => $category, 'subtype' => $subtype, 'label' => self::label($category), 'key' => $key,
            'problemId' => $problem, 'contextType' => $type, 'contextId' => $id, 'version' => self::VERSION);
    }

    public static function current(int $user_id): ?array { return self::issue($user_id, self::current_path(), $_GET); }

    public static function issue(int $user_id, string $path, array $query = array(), ?int $now_ms = null): ?array {
        $page = self::classify($path, $query);
        if ($user_id < 1 || $page === null || ! function_exists('wp_salt')) { return null; }
        try { return self::sign($page, $user_id, $now_ms ?? Clock::now_ms()); }
        catch (Throwable $error) { return null; }
    }

    public static function from_payload(array $body, int $user_id, int $now_ms, bool $offline = false): array|WP_Error {
        if (array_key_exists('page_context', $body)) {
            $page = self::verify($body['page_context'], $user_id, $now_ms, $offline);
            if ($page instanceof WP_Error) { return $page; }
            if (array_key_exists('problem_id', $body)) {
                $problem = $body['problem_id'] === null ? null : self::positive_id($body['problem_id']);
                if ($problem !== $page['problemId'] || ($body['problem_id'] !== null && $problem === null)) {
                    return self::error('focus_tracker_page_mismatch', '페이지와 문제 정보가 일치하지 않습니다.');
                }
            }
            return $page;
        }
        $problem = self::positive_id($body['problem_id'] ?? null);
        if ($problem === null) { return self::error('focus_tracker_page_required', '페이지 확인 정보가 필요합니다.', 400); }
        // Old problem-page clients and their 24-hour outbox keep their original
        // scoring policy. They cannot claim a neutral new page category.
        return array('category' => 'unclassified', 'subtype' => null, 'key' => null, 'problemId' => $problem,
            'version' => 0, 'contextType' => 'general', 'contextId' => null, 'issuedAtMs' => 0, 'expiresAtMs' => PHP_INT_MAX, 'token' => null);
    }

    public static function verify(mixed $token, int $user_id, int $now_ms, bool $offline = false): array|WP_Error {
        try {
            if (! is_string($token) || strlen($token) > 2048 || substr_count($token, '.') !== 1 || ! function_exists('wp_salt')) { return self::error(); }
            [$encoded, $signature] = explode('.', $token);
            if (! preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) || ! preg_match('/^[a-f0-9]{64}$/D', $signature)
                || ! hash_equals(hash_hmac('sha256', 'focus-tracker/page/v1|' . $encoded, self::secret()), $signature)) { return self::error(); }
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $value = json_decode($json === false ? '' : $json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($value) || ($value['version'] ?? null) !== self::VERSION || ($value['userId'] ?? null) !== $user_id || $user_id < 1
                || ! is_string($value['category'] ?? null) || ! isset(self::labels()[$value['category']]) || $value['category'] === 'unclassified'
                || ! array_key_exists('subtype', $value) || ($value['subtype'] !== null && (! is_string($value['subtype']) || ! preg_match('/^[a-z0-9_-]{1,32}$/D', $value['subtype'])))
                || ! is_string($value['key'] ?? null) || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9_:/.-]{0,159}$#D', $value['key'])
                || ! array_key_exists('problemId', $value) || ($value['problemId'] !== null && (! is_int($value['problemId']) || $value['problemId'] < 1))
                || ! in_array($value['contextType'] ?? null, array('general', 'class', 'group', 'contest'), true)
                || ! array_key_exists('contextId', $value) || ($value['contextType'] === 'general' ? $value['contextId'] !== null : ! is_string($value['contextId']))
                || ($value['contextType'] !== 'general' && ! preg_match('/^' . $value['contextType'] . ':[1-9][0-9]{0,18}$/D', $value['contextId']))
                || ! is_int($value['issuedAtMs'] ?? null) || ! is_int($value['expiresAtMs'] ?? null)
                || ! is_int($value['renewedAtMs'] ?? null) || $value['issuedAtMs'] < 1 || $value['issuedAtMs'] > $value['renewedAtMs']
                || $value['renewedAtMs'] > $now_ms || $value['expiresAtMs'] - $value['renewedAtMs'] !== self::TOKEN_LIFETIME_MS) { return self::error(); }
            if ($now_ms >= $value['expiresAtMs'] + ($offline ? self::QUEUE_LIFETIME_MS : 0)) { return self::error('focus_tracker_page_expired', '페이지 확인 정보가 만료되었습니다. 페이지를 새로 열어 주세요.'); }
            $value['token'] = $token; $value['label'] = self::label($value['category']);
            return $value;
        } catch (Throwable $error) { return self::error(); }
    }

    /** A signed group/class page cannot be relabeled as a different context. */
    public static function context(array $page, string $type, string|int|null $id): array|WP_Error {
        if ($page['version'] === 0 || $page['contextType'] === 'general') { return array('type' => $type, 'id' => $id); }
        if ($type === 'general' && ($id === null || $id === '')) { return array('type' => $page['contextType'], 'id' => $page['contextId']); }
        if ($type !== $page['contextType'] || ! Activity_Window_Gate::same_context($type, (string) $id, (string) $page['contextId'])) {
            return self::error('focus_tracker_page_context_mismatch', '페이지와 학습 문맥이 일치하지 않습니다.');
        }
        return array('type' => $page['contextType'], 'id' => $page['contextId']);
    }

    public static function renew(array $page, int $user_id, int $now_ms): array {
        return $page['version'] > 0 && $page['expiresAtMs'] - $now_ms < intdiv(self::TOKEN_LIFETIME_MS, 2)
            ? self::sign($page, $user_id, $now_ms) : $page;
    }

    public static function columns(array $page): array {
        return array('page_category' => $page['category'], 'page_subtype' => $page['subtype'], 'page_key' => $page['key'], 'page_context_version' => $page['version']);
    }

    public static function current_path(): string {
        $route = $_GET['oj_route'] ?? null;
        if (! is_scalar($route) && function_exists('get_query_var')) { $route = get_query_var('oj_route', null); }
        if (is_scalar($route) && (string) $route !== '') { return self::normalize_path((string) wp_unslash($route)); }
        $path = (string) wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && ($path === $home || str_starts_with($path, $home . '/'))) { $path = substr($path, strlen($home)) ?: '/'; }
        return self::normalize_path($path);
    }

    private static function sign(array $page, int $user_id, int $now_ms): array {
        $value = array_intersect_key($page, array_flip(array('category', 'subtype', 'key', 'problemId', 'contextType', 'contextId', 'version')));
        $value['userId'] = $user_id; $value['issuedAtMs'] = $page['issuedAtMs'] ?? $now_ms;
        $value['renewedAtMs'] = $now_ms; $value['expiresAtMs'] = $now_ms + self::TOKEN_LIFETIME_MS;
        $encoded = rtrim(strtr(base64_encode((string) wp_json_encode($value)), '+/', '-_'), '=');
        $value['token'] = $encoded . '.' . hash_hmac('sha256', 'focus-tracker/page/v1|' . $encoded, self::secret());
        $value['label'] = self::label($value['category']);
        return $value;
    }

    private static function positive_id(mixed $value): ?int {
        if (! is_string($value) && ! is_int($value)) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return $id === false ? null : $id;
    }

    private static function secret(): string {
        $secret = wp_salt('auth');
        if (! is_string($secret) || strlen($secret) < 32) { throw new \RuntimeException('Page signing is unavailable.'); }
        return $secret;
    }

    private static function normalize_path(string $path): string {
        if ($path === '' || strlen($path) > 2048 || preg_match('/[\x00-\x20\x7f]/', $path) || str_contains($path, '\\')
            || str_contains($path, '?') || str_contains($path, '#') || str_contains($path, '//')
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|20|23|2f|3f|5c|7f)/i', $path)) { return '/_oj_invalid_route_'; }
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
    private static function error(string $code = 'focus_tracker_invalid_page', string $message = '페이지 확인 정보를 검증하지 못했습니다.', int $status = 403): WP_Error { return new WP_Error($code, $message, array('status' => $status)); }
}

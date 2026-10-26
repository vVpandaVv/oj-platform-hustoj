<?php
declare(strict_types=1);

// Isolated WordPress doubles. No site, database, or user records are modified.
namespace {
    if (PHP_SAPI !== 'cli') { exit; }
}

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    final class AddonCapabilityKeyV1 {
        public static function wordpress(string $key): string { return $key; }
    }
}

namespace {
    define('EDU_GAMES_PATH', dirname(__DIR__) . '/');
    define('EDU_GAMES_FILE', EDU_GAMES_PATH . 'edu-games.php');
    define('ARRAY_A', 'ARRAY_A');
    define('EMPTY_TRASH_DAYS', getenv('EDU_TEST_TRASH_DAYS') === '0' ? 0 : 30);
    require EDU_GAMES_PATH . 'src/Core/Autoloader.php';
    EduGames\Core\Autoloader::register();

    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
    }
    class WP_Post {
        public string $post_type = 'page';
        public string $post_status = 'publish';
        public string $post_content = '';
    }
    class WP_REST_Request implements ArrayAccess {
        public function __construct(private string $game, private array $data = array()) {}
        public function get_json_params(): array { return $this->data; }
        public function offsetGet(mixed $offset): mixed { return $offset === 'game' ? $this->game : null; }
        public function offsetExists(mixed $offset): bool { return $offset === 'game'; }
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetUnset(mixed $offset): void {}
    }
    function __($text, $domain = '') { return $text; }
    function esc_html__($text, $domain = '') { return $text; }
    function plugins_url($path = '', $file = '') { return '/plugins/edu-games/' . $path; }
    function home_url($path = '') { return $path; }
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)); }
    function sanitize_text_field($text) { return strip_tags($text); }
    function absint($value) { return abs((int) $value); }
    function wp_json_encode($value) { return json_encode($value); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function rest_ensure_response($value) { return $value; }
    function get_current_user_id() { return 7; }
    function get_userdata($id) { return (object) array('display_name' => 'Test student'); }
    function is_user_logged_in() { return true; }
    function current_user_can($cap) { return in_array($cap, $GLOBALS['caps'] ?? array('edu_games_play'), true); }
    function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
    function wp_generate_password($length, $special = true, $extra = false) { return str_repeat('a', $length); }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function get_post($id) { return $GLOBALS['post'] ?? null; }
    function parse_blocks($content) { return $GLOBALS['parsed_blocks']; }
    function wp_trash_post($id) { $GLOBALS['trashed'][] = $id; return $GLOBALS['trash_succeeds'] ?? true; }
    function wp_delete_post($id, $force = false): never { throw new RuntimeException('Permanent deletion must never be called.'); }
    function edu_games_registry() {
        static $registry;
        return $registry ??= new EduGames\Games\Game_Module_Registry();
    }

    final class Fake_DB {
        public string $prefix = 'wp_';
        public int $insert_id = 1;
        public bool $consumed = false;
        public bool $fail_insert = false;
        public bool $stale_read = false;
        public int $score_inserts = 0;
        public int $session_age = 600; // seconds since the play session started
        public $on_insert = null;
        public array $last_score = array();
        public function prepare($sql, ...$args): array { return array($sql, $args); }
        public function get_row($query, $format) {
            if (! str_contains($query[0], 'edu_games_sessions')) { return null; }
            [$token, $user, $game] = $query[1];
            if ($token !== 'valid-token' || $user !== 7 || ($this->consumed && ! $this->stale_read)) { return null; }
            return array('id' => 1, 'started_at' => gmdate('Y-m-d H:i:s', time() - $this->session_age));
        }
        public function get_results($query, $format) { return array(); }
        public function get_var($query) { return null; }
        public function query($query) {
            if (str_contains($query[0], 'SET consumed_at = NULL')) { $this->consumed = false; return 1; }
            if ($this->consumed) { return 0; }
            $this->consumed = true;
            return 1;
        }
        public function insert($table, $data, $format) {
            if ($this->fail_insert) { return false; }
            if (str_ends_with($table, 'scores')) {
                $this->score_inserts++;
                $this->last_score = $data;
                if ($this->on_insert) { ($this->on_insert)(); }
            }
            return 1;
        }
    }
}

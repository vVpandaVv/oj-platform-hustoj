<?php
declare(strict_types=1);

// Fixtures are never an alternate unauthenticated web entry point.
if (! in_array(PHP_SAPI, array('cli', 'cli-server'), true)) {
    http_response_code(404);
    exit;
}

// Integration boundary: production controllers/repositories and real SQL,
// with WordPress services and the MySQL dialect adapted to a local SQLite DB.
// This is intentionally not a substitute for WordPress cookie-auth testing.
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('FOCUS_TRACKER_VERSION', '1.0.1');
define('FOCUS_TRACKER_FILE', dirname(__DIR__) . '/focus-tracker.php');
define('FOCUS_TRACKER_PATH', dirname(__DIR__) . '/');
define('FOCUS_TRACKER_URL', '/__focus/');
define('OJ_PLATFORM_VERSION', '2.3.22');
define('OJ_PLATFORM_ADDON_API_VERSION', '1.4.0');
$testRoot = dirname(__DIR__, 2) . '/_notes/tmp/focus-tracker-tests';
if (! is_dir($testRoot . '/wp-admin/includes')) {
    mkdir($testRoot . '/wp-admin/includes', 0777, true);
}
file_put_contents($testRoot . '/wp-admin/includes/upgrade.php', "<?php\n");
define('ABSPATH', $testRoot . '/');
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\FocusTracker\Core\Autoloader::register();
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'FocusTracker\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) { require_once $path; }
        return;
    }
    $prefix = 'CodingPanda\\OjPlatform\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $base = dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform/';
        $path = $base . (str_starts_with($relative, 'Modules\\') ? lcfirst(str_replace('\\', '/', $relative)) : 'src/' . str_replace('\\', '/', $relative)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

final class Focus_Test_Wpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public string $last_error = '';
    public ?string $fail_pattern = null;
    public array $queries = array();
    public ?Closure $before_results = null;
    public PDO $pdo;

    public function __construct(string $file = ':memory:') {
        $this->pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no, 3);
    }

    public function get_charset_collate(): string { return ''; }

    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return preg_replace_callback('/%[%dfs]/', function (array $match) use ($args, &$index): string {
            if ($match[0] === '%%') { return '%'; }
            $value = $args[$index++];
            return match ($match[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => $this->pdo->quote((string) $value),
            };
        }, $sql);
    }

    private function dialect(string $sql): string {
        $sql = str_replace('START TRANSACTION', 'BEGIN', $sql);
        $sql = preg_replace('/\s+FOR UPDATE\b/i', '', $sql);
        $sql = preg_replace('/\s+LOCK IN SHARE MODE\b/i', '', $sql);
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            [$insert, $update] = explode('ON DUPLICATE KEY UPDATE', $sql, 2);
            if (preg_match('/^\s*(id\s*=\s*id|activity_id\s*=\s*activity_id|user_id\s*=\s*user_id)\s*$/', $update)) {
                return $insert . ' ON CONFLICT DO NOTHING';
            }
            $update = preg_replace('/VALUES\((\w+)\)/i', 'excluded.$1', $update);
            $sql = $insert . ' ON CONFLICT DO UPDATE SET ' . $update;
        }
        return $sql;
    }

    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if (null !== $this->fail_pattern && str_contains($sql, $this->fail_pattern)) {
            $this->fail_pattern = null;
            $this->last_error = 'Injected storage failure';
            return false;
        }
        try {
            $count = $this->pdo->exec($this->dialect($sql));
            $this->insert_id = (int) $this->pdo->lastInsertId();
            $this->last_error = '';
            return $count;
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_results(string $sql, $mode = ARRAY_A): array {
        $this->queries[] = $sql;
        if ($this->before_results !== null) { ($this->before_results)($sql); }
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', $sql, $match)) {
            return array_map(static fn (array $row): array => array('Field' => $row['name']), $this->pdo->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC));
        }
        if (preg_match('/^SHOW INDEX FROM (\w+)$/', $sql, $match)) {
            $rows = array();
            $columns = $this->pdo->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['pk']) { $rows[] = array('Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => $column['pk'], 'Column_name' => $column['name']); }
            }
            foreach ($this->pdo->query('PRAGMA index_list(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $rows[] = array('Key_name' => $index['name'], 'Non_unique' => $index['unique'] ? 0 : 1, 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name']);
                }
            }
            return $rows;
        }
        return $this->pdo->query($this->dialect($sql))->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get_row(string $sql, $mode = ARRAY_A): ?array {
        $this->queries[] = $sql;
        if (preg_match("/^SHOW TABLE STATUS WHERE Name = '([^']+)'$/", $sql, $match)) {
            $exists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote($match[1]))->fetchColumn();
            return $exists ? array('Name' => $match[1], 'Engine' => $GLOBALS['focus_test_storage_engine'] ?? 'InnoDB') : null;
        }
        $row = $this->pdo->query($this->dialect($sql))->fetch(PDO::FETCH_ASSOC);
        return false === $row ? null : $row;
    }
    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $sql, $match)) {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote($match[1]);
        }
        $value = $this->pdo->query($this->dialect($sql))->fetchColumn();
        return false === $value ? null : $value;
    }
    public function insert(string $table, array $data, ?array $formats = null): int|false {
        $values = array_map(fn ($v): string => null === $v ? 'NULL' : $this->pdo->quote((string) $v), array_values($data));
        return $this->query('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $values) . ')');
    }
    public function update(string $table, array $data, array $where, ?array $formats = null, ?array $where_formats = null): int|false {
        $sets = array();
        foreach ($data as $key => $value) {
            $sets[] = $key . ' = ' . (null === $value ? 'NULL' : $this->pdo->quote((string) $value));
        }
        return $this->query('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $this->conditions($where));
    }
    public function delete(string $table, array $where, ?array $formats = null): int|false {
        return $this->query('DELETE FROM ' . $table . ' WHERE ' . $this->conditions($where));
    }
    private function conditions(array $where): string {
        $clauses = array();
        foreach ($where as $key => $value) {
            $clauses[] = $key . (null === $value ? ' IS NULL' : ' = ' . $this->pdo->quote((string) $value));
        }
        return implode(' AND ', $clauses);
    }
}

function dbDelta(string $sql): void {
    global $wpdb;
    $sql = preg_replace('/id bigint\(20\) unsigned NOT NULL AUTO_INCREMENT,/', 'id INTEGER PRIMARY KEY AUTOINCREMENT,', $sql);
    $sql = preg_replace('/^\s*PRIMARY KEY\s+\(id\),?\s*$/m', '', $sql);
    $sql = preg_replace('/^\s*UNIQUE KEY \w+ \(([^)]+)\),?\s*$/m', 'UNIQUE ($1),', $sql);
    $sql = preg_replace('/^\s*KEY \w+ \([^)]+\),?\s*$/m', '', $sql);
    $sql = str_replace(' unsigned', '', $sql);
    $sql = preg_replace('/\s+ENGINE=InnoDB\b/i', '', $sql);
    $sql = preg_replace('/,\s*\)/', "\n)", $sql);
    $sql = str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql);
    if (false === $wpdb->query($sql)) {
        throw new RuntimeException($wpdb->last_error . "\n" . $sql);
    }
}

final class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
final class WP_REST_Response {
    public array $headers = array();
    public function __construct(public array $data, public int $status = 200) {}
    public function get_data(): array { return $this->data; }
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
    public function get_headers(): array { return $this->headers; }
}
final class WP_REST_Request {
    public function __construct(public array $data = array(), public array $params = array()) {}
    public function get_json_params(): array { return $this->data; }
    public function get_param(string $key) { return $this->params[$key] ?? $this->data[$key] ?? null; }
    public function get_header(string $key): ?string { return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] ?? null; }
    public function get_route(): string { return $this->params['_route'] ?? '/focus-tracker/v1/test'; }
}
final class WP_REST_Server { public const CREATABLE = 'POST'; public const READABLE = 'GET'; public const EDITABLE = 'PATCH'; public const DELETABLE = 'DELETE'; }
function rest_ensure_response(array $data): WP_REST_Response { return new WP_REST_Response($data); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function __($text, $domain = ''): string { return $text; }
function esc_html__($text, $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($text, $domain = ''): void { echo esc_html__($text, $domain); }
function esc_attr_e($text, $domain = ''): void { echo esc_attr($text); }
function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text): string { return esc_attr($text); }
function esc_url_raw($text): string { return (string) $text; }
function admin_url(string $path = ''): string { return '/wp-admin/' . ltrim($path, '/'); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function esc_textarea(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function checked($checked, $current = true, bool $display = true): string { $result = $checked == $current ? ' checked="checked"' : ''; if ($display) { echo $result; } return $result; }
function wp_nonce_field(string $action, string $name): void { echo '<input type="hidden" name="' . esc_attr($name) . '" value="focus-test-nonce">'; }
function wp_json_encode($data, int $flags = 0): string { return json_encode($data, $flags | JSON_THROW_ON_ERROR); }
function wp_unslash($value) { return $value; }
function wp_parse_url($url, int $component = -1) { return parse_url($url, $component); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function maybe_serialize($value) { return is_array($value) || is_object($value) ? serialize($value) : (is_bool($value) ? ($value ? '1' : '') : (string) $value); }
function maybe_unserialize($value) {
    if (is_string($value) && preg_match('/^(?:a|O|s|i|b|d|N)[:;]/', $value)) {
        $decoded = @unserialize($value, array('allowed_classes' => false));
        if ($decoded !== false || $value === 'b:0;') { return $decoded; }
    }
    return $value;
}
function get_option($key, $default = false) {
    global $wpdb;
    if (array_key_exists('focus_test_option_cache', $GLOBALS) && array_key_exists($key, $GLOBALS['focus_test_option_cache'])) {
        $cached = $GLOBALS['focus_test_option_cache'][$key];
        return $cached['exists'] ? $cached['value'] : $default;
    }
    if (isset($wpdb->options, $wpdb->pdo)) {
        $statement = $wpdb->pdo->prepare('SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = ?');
        $statement->execute(array($key));
        $value = $statement->fetchColumn();
        if (array_key_exists('focus_test_option_cache', $GLOBALS)) {
            $GLOBALS['focus_test_option_cache'][$key] = array('exists' => is_string($value), 'value' => is_string($value) ? maybe_unserialize($value) : null);
        }
        return is_string($value) ? maybe_unserialize($value) : $default;
    }
    return $GLOBALS['focus_test_options'][$key] ?? $default;
}
function update_option($key, $value, $autoload = null): bool {
    global $wpdb;
    $GLOBALS['focus_test_options'][$key] = $value;
    wp_cache_delete($key, 'options');
    if (isset($wpdb->options, $wpdb->pdo)) {
        return false !== $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value), autoload='no'", $key, maybe_serialize($value)));
    }
    return true;
}
function add_option($key, $value = '', $deprecated = '', $autoload = true): bool {
    global $wpdb;
    if (isset($wpdb->options, $wpdb->pdo)) {
        $statement = $wpdb->pdo->prepare('INSERT INTO ' . $wpdb->options . ' (option_name, option_value, autoload) VALUES (?, ?, ?) ON CONFLICT(option_name) DO NOTHING');
        $statement->execute(array($key, maybe_serialize($value), 'no'));
        wp_cache_delete($key, 'options');
        return $statement->rowCount() > 0;
    }
    if (array_key_exists($key, $GLOBALS['focus_test_options'] ?? array())) { return false; }
    $GLOBALS['focus_test_options'][$key] = $value;
    return true;
}
function delete_option($key): bool {
    global $wpdb;
    unset($GLOBALS['focus_test_options'][$key]);
    wp_cache_delete($key, 'options');
    if (isset($wpdb->options, $wpdb->pdo)) { return $wpdb->delete($wpdb->options, array('option_name' => $key)) !== false; }
    return true;
}
function wp_cache_delete($key, $group = ''): bool {
    if ($group === 'options' && array_key_exists('focus_test_option_cache', $GLOBALS)) { unset($GLOBALS['focus_test_option_cache'][$key]); }
    return true;
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1): void { $GLOBALS['focus_test_hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void { $GLOBALS['focus_test_filters'][$hook][] = $callback; }
function wp_next_scheduled($hook, $args = array()) { return $GLOBALS['focus_test_cron'][$hook] ?? false; }
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false): bool { $GLOBALS['focus_test_cron'][$hook] = $timestamp; return true; }
function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false): bool { $GLOBALS['focus_test_cron'][$hook] = $timestamp; return true; }
function wp_unschedule_event($timestamp, $hook, $args = array(), $wp_error = false): bool { unset($GLOBALS['focus_test_cron'][$hook]); return true; }
function wp_clear_scheduled_hook($hook, $args = array(), $wp_error = false): int { unset($GLOBALS['focus_test_cron'][$hook]); return 1; }
function current_time($format, $gmt = false): string { return gmdate($format === 'mysql' ? 'Y-m-d H:i:s' : $format); }
function wp_timezone(): DateTimeZone { return new DateTimeZone($GLOBALS['focus_test_timezone'] ?? 'Asia/Seoul'); }
function get_current_user_id(): int { return $GLOBALS['focus_test_user_id'] ?? 1; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can($cap): bool {
    if (isset($GLOBALS['focus_test_allowed']) && ! $GLOBALS['focus_test_allowed']) { return false; }
    return function_exists('student_role_fixture_enabled') && student_role_fixture_enabled()
        ? student_role_fixture_can((string) $cap) : ($GLOBALS['focus_test_allowed'] ?? true);
}
function is_super_admin(): bool { return ! function_exists('student_role_fixture_enabled') || ! student_role_fixture_enabled(); }
function wp_create_nonce($action): string { return 'focus-test-nonce'; }
function wp_verify_nonce($nonce, $action): int|false { return $nonce === 'focus-test-nonce' ? ($GLOBALS['focus_test_nonce_age'] ?? 1) : false; }
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'focus_tracker_checks_client_config') { return array_replace($value, $GLOBALS['focus_test_checks_client_config'] ?? array()); }
    if ($hook === 'focus_tracker_activity_gate_client_config') { return array_replace($value, $GLOBALS['focus_test_activity_gate_client_config'] ?? array()); }
    return $value;
}
function get_userdata(int $id): object { return function_exists('student_role_fixture_enabled') && student_role_fixture_enabled() ? student_role_fixture_user($id) : (object) array('display_name' => '학생 ' . $id); }
function rest_url(string $path): string { return '/wp-json/' . $path; }
function home_url(string $path = ''): string { return 'http://localhost' . $path; }

$wpdb = new Focus_Test_Wpdb(getenv('FOCUS_TRACKER_TEST_DB') ?: ':memory:');
$wpdb->pdo->exec('CREATE TABLE IF NOT EXISTS wp_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT \'no\')');
$wpdb->pdo->exec('CREATE TABLE IF NOT EXISTS focus_tracker_fixture_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL)');
\FocusTracker\Database\Installer::install();

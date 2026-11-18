<?php
declare(strict_types=1);

namespace TeachingTools\Support {
    final class Capabilities {
        public static function can_host(): bool { return in_array($GLOBALS['actor'], array(1, 3), true); }
    }
}
namespace {
    define('ARRAY_A', 'ARRAY_A');
    define('ABSPATH', __DIR__ . '/fixtures/');
    $actor = 1;
    function __(string $text, string $domain = ''): string { return $text; }
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function current_time(string $format, bool $gmt = false): string { return '2026-11-16 00:00:00'; }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    function rest_ensure_response(mixed $value): mixed { return $value; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function get_option(string $key): mixed { return $GLOBALS['schema'] ?? ''; }
    function update_option(string $key, mixed $value): void { $GLOBALS['schema'] = $value; }
    function is_admin(): bool { return false; }
    function get_user_by(string $field, string $value): object { return (object) array('ID' => (int) $value); }
    function ensure(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
    final class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
    }
    final class WP_REST_Server { public const CREATABLE = 'POST'; }
    function register_rest_route(string $namespace, string $route, array $definition): void { $GLOBALS['routes'][$route] = $definition; }
    final class WP_REST_Request {
        public function __construct(private mixed $params) {}
        public function get_json_params(): mixed { return $this->params; }
        public function get_body(): string { return wp_json_encode($this->params); }
    }
    final class History_Db {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public array $rows = array();
        public int $sequence = 0;
        public bool $fail = false;
        public function prepare(string $sql, mixed ...$args): array { return array($sql, $args); }
        public function esc_like(string $value): string { return $value; }
        public function get_charset_collate(): string { return ''; }
        private function matching(int $owner, string $scope): array {
            $rows = array_values(array_filter($this->rows, static fn ($row): bool => $row['owner_user_id'] === $owner && $row['scope_key'] === $scope));
            usort($rows, static fn ($a, $b): int => $b['id'] <=> $a['id']);
            return $rows;
        }
        public function get_results(array $query, string $output): ?array {
            [$sql, $args] = $query;
            $this->last_error = $this->fail ? 'offline' : '';
            if ($this->fail) { return null; }
            if (!str_contains($sql, 'teaching_tools_seating_history')) { return array(); }
            if (str_contains($sql, 'positions LIKE')) {
                return array_values(array_filter($this->rows, static fn ($row): bool => $row['owner_user_id'] === $args[0]
                    || str_contains($row['positions'], trim($args[1], '%'))));
            }
            return array_slice($this->matching($args[0], $args[1]), 0, $args[2]);
        }
        public function get_var(array $query): mixed {
            [$sql, $args] = $query;
            if (str_contains($sql, 'SELECT scope_key')) {
                foreach ($this->rows as $row) {
                    if ($row['owner_user_id'] === $args[0] && $row['confirmation_key'] === $args[1]) { return $row['scope_key']; }
                }
                return null;
            }
            return $this->matching($args[0], $args[1])[$args[2]]['id'] ?? null;
        }
        public function query(array|string $query): int|false {
            if ($this->fail) { $this->last_error = 'offline'; return false; }
            $this->last_error = '';
            if (is_string($query)) { return 0; }
            [$sql, $args] = $query;
            if (str_starts_with($sql, 'INSERT INTO')) {
                [$owner, $scope, $key, $rows, $cols, $positions, $created] = $args;
                foreach ($this->rows as &$row) {
                    if ($row['owner_user_id'] === $owner && $row['confirmation_key'] === $key) {
                        if ($row['scope_key'] === $scope) { $row['rows_count'] = $rows; $row['cols_count'] = $cols; $row['positions'] = $positions; }
                        return 1;
                    }
                }
                unset($row);
                $this->rows[] = array('id' => ++$this->sequence, 'owner_user_id' => $owner, 'scope_key' => $scope, 'confirmation_key' => $key,
                    'rows_count' => $rows, 'cols_count' => $cols, 'positions' => $positions, 'created_at' => $created);
                return 1;
            }
            if (str_starts_with($sql, 'DELETE FROM')) {
                $before = count($this->rows);
                $this->rows = array_values(array_filter($this->rows, static fn ($row): bool => !($row['owner_user_id'] === $args[0] && $row['scope_key'] === $args[1] && $row['id'] < $args[2])));
                return $before - count($this->rows);
            }
            throw new \RuntimeException('Unexpected query: ' . $sql);
        }
        public function delete(string $table, array $where, array $formats): int {
            if (!str_ends_with($table, 'seating_history')) { return 0; }
            $before = count($this->rows);
            $this->rows = array_values(array_filter($this->rows, static fn ($row): bool => $row['owner_user_id'] !== $where['owner_user_id']));
            return $before - count($this->rows);
        }
        public function update(string $table, array $data, array $where, array $formats, array $where_formats): int {
            foreach ($this->rows as &$row) if ($row['id'] === $where['id']) { $row = array_merge($row, $data); return 1; }
            return 0;
        }
        public function get_col(array $query): array { return array(); }
    }
    require dirname(__DIR__) . '/src/Database/Installer.php';
    require dirname(__DIR__) . '/src/Addon/Seating/Seating_History_Repository.php';
    require dirname(__DIR__) . '/src/Rest/Controllers/Seating_History_Controller.php';
    require dirname(__DIR__) . '/src/Admin/Privacy_Tools.php';
    use TeachingTools\Rest\Controllers\Seating_History_Controller as Controller;
    use TeachingTools\Addon\Seating\Seating_History_Repository as Repository;
    $wpdb = new History_Db();
    if (($argv[1] ?? '') === '--request') {
        $input = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
        $actor = $input['actor'] ?? 1;
        $wpdb->rows = $input['db']['rows'] ?? array(); $wpdb->sequence = $input['db']['sequence'] ?? 0;
        $wpdb->fail = $input['fail'] ?? false;
        $result = Controller::{$input['method']}(new WP_REST_Request($input['params']));
        echo wp_json_encode(array('status' => is_wp_error($result) ? $result->data['status'] : 200,
            'result' => is_wp_error($result) ? array('message' => $result->message) : $result,
            'db' => array('rows' => $wpdb->rows, 'sequence' => $wpdb->sequence)));
        exit;
    }
    Controller::register_routes();
    ensure(count($routes) === 2 && $routes['/seating-history']['permission_callback'][1] === 'can_host', 'Routes need host permission');
    TeachingTools\Database\Installer::maybe_install();
    ensure($schema === TeachingTools\Database\Installer::SCHEMA_VERSION && str_contains(implode('\n', $ddl), 'teaching_tools_seating_history'), 'History schema migration');
    $count = count($ddl); TeachingTools\Database\Installer::maybe_install(); ensure(count($ddl) === $count, 'Normal request must not migrate again');
    $payload = array('roster' => array(array('id' => 10, 'name' => 'A'), array('id' => 20, 'name' => 'B')));
    $query = static fn (array $p) => Controller::query(new WP_REST_Request($p));
    $confirm = static fn (array $p) => Controller::confirm(new WP_REST_Request($p));
    $initial = $query($payload); ensure($initial['items'] === array() && $initial['studentKeys'][10] === 'u:10', 'First run');
    $draft = $payload + array('confirmationKey' => '12345678-1234-4234-8234-123456789001', 'rows' => 2, 'cols' => 2,
        'positions' => array(array('id' => 10, 'row' => 0, 'col' => 0), array('id' => 20, 'row' => 0, 'col' => 1)));
    ensure($confirm($draft)['confirmed'] && $confirm($draft)['confirmed'] && count($wpdb->rows) === 1, 'Idempotent retry');
    $draft['positions'][0]['row'] = 1;
    ensure($confirm($draft)['confirmed'] && count($wpdb->rows) === 1, 'Manual edit updates same round');
    $renamed = $payload; $renamed['roster'][0]['name'] = 'Management A'; $renamed['roster'] = array_reverse($renamed['roster']);
    ensure(count($query($renamed)['items']) === 1, 'ID identity and roster order');
    $actor = 3;
    ensure($query($payload + array('owner' => 1, 'scopeKey' => $wpdb->rows[0]['scope_key']))['items'] === array(), 'Owner isolation');
    ensure($confirm($draft)['confirmed'] && count($wpdb->rows) === 2, 'Independent owner confirmation');
    $actor = 2; ensure($query($payload)->data['status'] === 403 && $confirm($draft)->data['status'] === 403, 'Student denied');
    $actor = 1;
    for ($i = 2; $i <= 8; $i++) {
        $draft['confirmationKey'] = '12345678-1234-4234-8234-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT);
        ensure($confirm($draft)['confirmed'], 'Confirm round');
    }
    ensure(count($query($payload)['items']) === 5 && count($wpdb->rows) === 6, 'Retention and owner separation');
    ensure(is_wp_error($confirm($draft + array('historyGroup' => 'Other'))), 'Key cannot change group');
    $duplicate = $draft; $duplicate['positions'][1]['row'] = 1; $duplicate['positions'][1]['col'] = 0;
    ensure(is_wp_error($confirm($duplicate)), 'Duplicate coordinates');
    $invalid = $draft; $invalid['positions'][1]['id'] = 999;
    ensure(is_wp_error($confirm($invalid)), 'Student outside submitted roster');
    $invalid = $draft; $invalid['positions'][0]['row'] = 9;
    ensure(is_wp_error($confirm($invalid)), 'Out of bounds');
    ensure(is_wp_error($query(array('roster' => array(array('id' => array()))))), 'Malformed identity');
    ensure(is_wp_error($query(array('roster' => array_fill(0, 401, array('id' => 1))))), 'Roster bound');
    ensure(is_wp_error($query(array('roster' => array(array('id' => 1), array('id' => 1))))), 'Duplicate roster');
    $manual = array('roster' => array(array('id' => 'manual:old:1', 'displayName' => 'Student A'), array('id' => 'manual:old:2', 'displayName' => 'Student B')));
    $before = $query($manual)['studentKeys'];
    $manual['roster'][0]['id'] = 'manual:new:1'; $manual['roster'][1]['id'] = 'manual:new:2';
    ensure(array_values($before) === array_values($query($manual)['studentKeys']), 'Manual identity across reload');
    $grouped = $draft; $grouped['historyGroup'] = '3반'; $grouped['confirmationKey'] = '12345678-1234-4234-8234-123456789999';
    ensure($confirm($grouped)['confirmed'], 'Named history group');
    $grouped['roster'][] = array('id' => 30);
    ensure(count($query($grouped)['items']) === 1, 'Named group survives roster changes');
    $wpdb->fail = true; ensure($query($payload)->data['status'] === 500 && $confirm($draft)->data['status'] === 500, 'Database errors must surface'); $wpdb->fail = false;
    $export = TeachingTools\Admin\Privacy_Tools::export_personal_data('10');
    ensure(count($export['data']) === 7, 'Student export history');
    foreach ($export['data'] as $item) { ensure(!str_contains(wp_json_encode($item), 'u:20'), 'Export must not expose classmates'); }
    ensure(Repository::erase_user(10) === 7, 'Erase student positions in all teachers histories');
    ensure(count(Repository::for_user(10)) === 0 && count(Repository::for_user(20)) === 7, 'Erasure preserves classmates');
    ensure(Repository::erase_user(1) === 6 && count($wpdb->rows) === 1, 'Owner deletion preserves other teacher');
    echo "Seating history: schema, permissions, scope, identity, retention, retries, validation, failures and privacy passed.\n";
}

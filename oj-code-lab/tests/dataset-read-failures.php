<?php
declare(strict_types=1);
// Isolated fault injection: a failed query must not look like an empty dataset.
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('OJ_CODE_LAB_PATH', dirname(__DIR__) . '/');
class WP_Error {
    public function __construct(public string $code, public string $message = '', public array $data = array()) {}
    public function get_error_data(): array { return $this->data; }
}
class WP_REST_Request {
    public function get_param(string $name): mixed { return 'id' === $name ? 123 : null; }
}
class WP_REST_Response {
    public function __construct(public mixed $data) {}
}
function __(string $value, string $domain = ''): string { return $value; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function is_user_logged_in(): bool { return false; }
function rest_ensure_response(mixed $value): WP_REST_Response|WP_Error { return is_wp_error($value) ? $value : new WP_REST_Response($value); }
class FaultDatabase {
    public string $prefix = 'isolated_';
    public string $last_error = '';
    public bool $fail = true;
    public int $writes = 0;
    public function prepare(string $query, mixed ...$values): string { return $query; }
    private function fault(): void { $this->last_error = $this->fail ? 'Injected database read failure' : ''; }
    public function get_results(string $query, string $format): array { $this->fault(); return array(); }
    public function get_row(string $query, string $format): ?array { $this->fault(); return null; }
    public function get_col(string $query): array { $this->fault(); return array(); }
    public function get_var(string $query): ?string { $this->fault(); return null; }
    public function query(string $query): int { $this->writes++; return 1; }
}
$wpdb = new FaultDatabase();
require OJ_CODE_LAB_PATH . 'src/Core/Autoloader.php';
OjCodeLab\Core\Autoloader::register();
use OjCodeLab\Database\Dataset_Repository as Repository;
use OjCodeLab\Rest\Controllers\Dataset_Controller as Controller;
use OjCodeLab\Datasets\Sample_Dataset_Seeder as Seeder;
use OjCodeLab\Datasets\Upload_Store;
$checks = 0;
function check(bool $result, string $label): void {
    global $checks;
    if (! $result) { fwrite(STDERR, 'FAIL ' . $label . PHP_EOL); exit(1); }
    $checks++;
    echo 'PASS ' . $label . PHP_EOL;
}
function failed(mixed $result): bool { return is_wp_error($result) && 500 === ($result->get_error_data()['status'] ?? null); }
check(failed(Repository::all()), 'list read failure is an error, not an empty list');
check(failed(Repository::find(123)), 'lookup read failure is an error, not missing');
check(failed(Repository::existing_sample_keys()), 'sample key lookup reports failure');
check(failed(Repository::upload_key(123)), 'upload key lookup reports failure');
check(failed(Upload_Store::read('fixture')), 'blob read failure is an error, not missing');
check(failed(Controller::list_recommended(new WP_REST_Request())), 'REST recommended list propagates failure');
check(failed(Controller::import_recommended(new WP_REST_Request())), 'REST import reports database failure, not 404');
check(failed(Seeder::restore_missing()) && 0 === $wpdb->writes, 'restore does not insert when existing keys cannot be read');
$wpdb->fail = false;
check(array() === Repository::all(), 'genuinely empty list remains valid');
check(null === Repository::find(123) && null === Repository::upload_key(123) && null === Upload_Store::read('fixture'), 'missing records remain distinguishable from failures');
check(array() === Repository::existing_sample_keys(), 'empty sample keys remain valid');
$result = Controller::import_recommended(new WP_REST_Request());
check(is_wp_error($result) && 404 === $result->get_error_data()['status'], 'genuinely missing import retains 404');
echo 'Passed: ' . $checks . PHP_EOL;

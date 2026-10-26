<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    final class AddonCapabilityKeyV1 {
        public static function wordpress(string $key): string { return $key; }
    }
}
namespace {
    // Unit tests use deliberate WordPress/DB/HTTP doubles, no live site.
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
    define('ARRAY_A', 'ARRAY_A');
    define('HOUR_IN_SECONDS', 3600);
    define('OJ_CODE_LAB_PATH', dirname(__DIR__) . '/');
    define('OJ_CODE_LAB_VERSION', (require dirname(__DIR__) . '/plugin-package.php')['version']);
    class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_messages(): array { return array($this->message); }
    }
    class WP_REST_Server { public const READABLE = 'GET'; public const EDITABLE = 'POST, PUT, PATCH'; }
    function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['registered_routes'][$route] = $args; }
    class WP_REST_Request {
        public function __construct(private array $params = array()) { $this->params += array('revision' => 0); }
        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    }
    class WP_REST_Response {
        public function __construct(private mixed $data) {}
        public function get_data(): mixed { return $this->data; }
    }
    final class FakeDB {
        public string $prefix = 'test_';
        public string $last_error = '';
        public mixed $packet_bytes = 67108864;
        public int|false $query_result = 1;
        public int|false $insert_result = 1;
        public int $insert_id = 42;
        public ?array $row = null;
        public array $rows = array();
        public array $queries = array();
        public array $insertions = array();
        public array $deleted = array();
        public array $prepared_args = array();
        public function prepare(string $sql, mixed ...$args): string { $this->prepared_args = $args; return $sql; }
        public function get_row(string $sql, string $format): ?array { return $this->row; }
        public function get_results(string $sql, string $format): array { return $this->rows; }
        public function get_var(string $sql): mixed {
            if (str_contains($sql, '@@SESSION.max_allowed_packet')) { return $this->packet_bytes; }
            if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) { return '1'; }
            if (str_contains($sql, 'SHOW TABLES')) { return str_replace('\\', '', $this->prepared_args[0]); }
            return null;
        }
        public function esc_like(string $s): string { return $s; }
        public function get_col(string $sql): array { return str_contains($sql,'SHOW COLUMNS') ? array('revision','cell_count','has_error') : array(); }
        public function query(string $sql): int|false { $this->queries[] = $sql; return $this->query_result; }
        public function insert(string $table, array $fields, array $format): int|false { $this->insertions[] = $fields; return $this->insert_result; }
        public function delete(string $table, array $fields, array $format): int { $this->deleted[] = $fields; return 1; }
        public function get_charset_collate(): string { return ''; }
    }
    function __(string $s, string $domain = ''): string { return $s; }
    function esc_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function rest_ensure_response(mixed $data): WP_REST_Response|WP_Error { return $data instanceof WP_Error ? $data : new WP_REST_Response($data); }
    function get_current_user_id(): int { return 7; }
    function is_user_logged_in(): bool { return $GLOBALS['logged_in']; }
    function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS['caps'], true); }
    function is_super_admin(): bool { return $GLOBALS['super_admin']; }
    function current_time(string $type): string { return '2026-09-07 20:00:00'; }
    function wp_json_encode(mixed $v): string|false { return json_encode($v); }
    function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
    function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['options'][$key] ?? $default; }
    function update_option(string $key, mixed $value): bool { $GLOBALS['options'][$key] = $value; return true; }
    function apply_filters(string $hook, mixed $value): mixed { return $hook === 'oj_code_lab_max_dataset_bytes' ? ($GLOBALS['max_bytes'] ?? $value) : $value; }
    function delete_option(string $key): bool { unset($GLOBALS['options'][$key]); return true; }
    function wp_next_scheduled(string $hook): bool { return false; }
    function wp_schedule_single_event(int $time, string $hook): bool { return true; }
    function get_transient(string $key): mixed { return false; }
    function set_transient(string $key, mixed $value, int $ttl): bool { return true; }
    function wp_remote_get(string $url, array $args): array|WP_Error { $GLOBALS['http_call'] = array('safe' => false, 'url' => $url, 'args' => $args); return $GLOBALS['http_response']; }
    function wp_safe_remote_get(string $url, array $args): array|WP_Error { wp_remote_get($url, $args); $GLOBALS['http_call']['safe'] = true; return $GLOBALS['http_response']; }
    function wp_remote_retrieve_body(array $r): string { return $r['body']; }
    function wp_remote_retrieve_response_code(array $r): int { return $r['status']; }
    require OJ_CODE_LAB_PATH . 'src/Core/Autoloader.php';
    \OjCodeLab\Core\Autoloader::register();
    use OjCodeLab\Database\Notebook_Repository as Notebooks;
    use OjCodeLab\Rest\Controllers\Notebook_Controller as NotebookApi;
    use OjCodeLab\Rest\Controllers\Dataset_Controller as DatasetApi;
    use OjCodeLab\Rest\Controllers\Roster_Controller as RosterApi;
    use OjCodeLab\Rest\Controllers\Settings_Controller as SettingsApi;
    use OjCodeLab\Database\Dataset_Repository as Datasets;
    use OjCodeLab\Datasets\Url_Source;
    use OjCodeLab\Datasets\Kaggle_Client;
    use OjCodeLab\Datasets\Upload_Store;
    use OjCodeLab\Datasets\Sample_Dataset_Seeder;
    use OjCodeLab\Database\Installer;
    function check(bool $ok, string $message): void { if (!$ok) { throw new \RuntimeException($message); } }
    function run_test(string $name, callable $test): void {
        $GLOBALS['wpdb'] = new FakeDB();
        $GLOBALS['logged_in'] = true;
        $GLOBALS['caps'] = array('oj_code_lab_use');
        $GLOBALS['super_admin'] = false;
        $GLOBALS['options'] = array();
        $GLOBALS['max_bytes'] = 16_000_000;
        $GLOBALS['http_response'] = array('status' => 200, 'body' => 'name,value');
        $GLOBALS['http_call'] = null;
        $GLOBALS['schema_calls'] = array();
        $test();
        echo "PASS $name\n";
    }
    run_test('notebook DB failure becomes HTTP 500', function () {
        $GLOBALS['wpdb']->query_result = false;
        $r = NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'cells' => array())));
        check(is_wp_error($r) && $r->data['status'] === 500, 'False success on failed write');
    });
    run_test('zero affected CAS rows return a conflict', function () {
        $GLOBALS['wpdb']->query_result = 0;
        check(is_wp_error(Notebooks::save_for_user(7, array(), 1)), 'Stale revision must fail');
    });
    run_test('caller cannot save another learner notebook', function () {
        NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'user_id' => 900, 'cells' => array())));
        check($GLOBALS['wpdb']->prepared_args[0] === 7, 'User id must come from session');
    });
    run_test('DB read failure never becomes an empty notebook', function () {
        $GLOBALS['wpdb']->last_error = 'simulated db failure';
        check(is_wp_error(NotebookApi::get(new WP_REST_Request())), 'Read failed silently');
    });
    run_test('damaged notebook JSON is not silently replaced', function () {
        $GLOBALS['wpdb']->row = array('cells' => '{bad', 'updated_at' => 'today');
        check(is_wp_error(Notebooks::find_for_user(7)), 'Damaged JSON became empty cells');
    });
    run_test('first notebook returns a valid empty list', function () {
        check(Notebooks::find_for_user(7)['cells'] === array(), 'New notebook should be empty');
    });
    run_test('format version is advertised for empty and legacy notebooks', function () {
        check(Notebooks::find_for_user(7)['formatVersion'] === 2, 'Empty notebook format missing');
        $legacy = array(array('id' => 'old', 'code' => "print('한글')", 'outputs' => array()));
        $GLOBALS['wpdb']->row = array('cells' => wp_json_encode($legacy), 'updated_at' => 'today', 'revision' => 4);
        $read = Notebooks::find_for_user(7);
        check($read['formatVersion'] === 2 && $read['cells'][0]['type'] === 'code' && $read['cells'][0]['code'] === $legacy[0]['code'], 'Legacy code cell did not remain readable');
        check($GLOBALS['wpdb']->queries === array(), 'Reading legacy notebook rewrote data');
    });
    run_test('old and unsupported clients cannot write mixed notebook data', function () {
        foreach (array(null, 1, '2', 3) as $format) {
            $params = array('revision' => 4, 'cells' => array());
            if (null !== $format) { $params['formatVersion'] = $format; }
            $r = NotebookApi::save(new WP_REST_Request($params));
            check(is_wp_error($r) && $r->data['status'] === 409 && $r->code === 'oj_code_lab_notebook_format_changed', 'Old client did not receive refresh conflict');
        }
        check($GLOBALS['wpdb']->queries === array(), 'Old client issued a mutation');
    });
    run_test('mixed cell storage preserves literal text and excludes text outputs', function () {
        $text = "  학습 기록\n<script>alert('기록')</script> & **정리**\n다음 줄  ";
        $cells = array(
            array('id' => 'note', 'type' => 'text', 'code' => $text, 'outputs' => array(array('type' => 'error', 'text' => 'not executable'))),
            array('id' => 'program', 'code' => 'print(1)', 'outputs' => array(array('type' => 'stream', 'text' => '1')))
        );
        $r = NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'revision' => 0, 'cells' => $cells)));
        check($r instanceof WP_REST_Response && $r->get_data()['revision'] === 1 && $r->get_data()['formatVersion'] === 2, 'Mixed save failed');
        $stored = json_decode($GLOBALS['wpdb']->prepared_args[1], true);
        check($stored[0]['type'] === 'text' && $stored[0]['code'] === $text && $stored[0]['outputs'] === array(), 'Text or its empty outputs changed');
        check($stored[1]['type'] === 'code' && $stored[1]['outputs'][0]['text'] === '1', 'Legacy code normalization lost output');
        check($GLOBALS['wpdb']->prepared_args[3] === 2 && $GLOBALS['wpdb']->prepared_args[4] === 0, 'Text affected error summary or total count');
        $GLOBALS['wpdb']->row = array('cells' => wp_json_encode($stored), 'updated_at' => 'today', 'revision' => 1);
        check(Notebooks::find_for_user(7)['cells'] === $stored, 'Mixed cell read order or body changed');
    });
    run_test('code errors remain visible in mixed notebook summary', function () {
        Notebooks::save_for_user(7, array(array('type' => 'text', 'code' => '기록'), array('type' => 'code', 'code' => 'bad', 'outputs' => array(array('type' => 'error', 'text' => 'trace')))), 0);
        check($GLOBALS['wpdb']->prepared_args[3] === 2 && $GLOBALS['wpdb']->prepared_args[4] === 1, 'Code error missing from mixed summary');
    });
    run_test('unsupported explicit cell types fail without writing', function () {
        foreach (array('markdown', null, array('text'), 3) as $type) {
            $r = NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'cells' => array(array('type' => $type, 'code' => 'keep')))));
            check(is_wp_error($r) && $r->data['status'] === 400, 'Unknown type was silently executed as code');
        }
        check($GLOBALS['wpdb']->queries === array(), 'Unknown type issued a mutation');
        $GLOBALS['wpdb']->row = array('cells' => '[{"id":"future","type":"future","code":"keep"}]', 'updated_at' => 'today', 'revision' => 1);
        check(is_wp_error(Notebooks::find_for_user(7)), 'Future stored format was silently reinterpreted');
    });
    run_test('text notebooks retain CAS and shared size limits', function () {
        $GLOBALS['wpdb']->query_result = 0;
        $r = NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'revision' => 4, 'cells' => array(array('type' => 'text', 'code' => '보존')))));
        check(is_wp_error($r) && $r->data['status'] === 409, 'Text bypassed CAS');
        $GLOBALS['wpdb']->queries = array();
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        $r = NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'cells' => array(array('type' => 'text', 'code' => str_repeat('x', 8355841))))));
        check(is_wp_error($r) && $r->data['status'] === 413 && $GLOBALS['wpdb']->queries === array(), 'Text bypassed packet byte cap');
    });
    run_test('cell cap enforced', function () {
        check(is_wp_error(NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'cells' => array_fill(0,301,array()))))), '301 cells allowed');
    });
    run_test('notebook byte cap enforced', function () {
        check(is_wp_error(NotebookApi::save(new WP_REST_Request(array('formatVersion' => 2, 'cells' => array(array('code' => str_repeat('x',16_000_001))))))), 'Oversized notebook allowed');
    });
    run_test('16 MiB packet safely reduces effective dataset limit', function () {
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        check(\OjCodeLab\Support\Dataset_Limits::max_bytes() === 8355840, 'SQL escaping margin was not applied');
        $GLOBALS['max_bytes'] = 10;
        check(\OjCodeLab\Support\Dataset_Limits::max_bytes() === 10, 'Configured lower cap was ignored');
    });
    run_test('oversized notebook is rejected before SQL and retains revision', function () {
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        $r = Notebooks::save_for_user(7, array(array('code' => str_repeat('x', 8355841))), 9);
        check(is_wp_error($r) && $r->data['status'] === 413 && $r->data['maxBytes'] === 8355840, 'Packet-bound notebook error is not explicit');
        check($GLOBALS['wpdb']->queries === array(), 'Rejected notebook issued a write');
    });
    run_test('repository applies absolute cap even without REST controller', function () {
        $r = Notebooks::save_for_user(7, array(array('code' => str_repeat('x', 16_000_001))), 1);
        check(is_wp_error($r) && $r->data['status'] === 413 && $GLOBALS['wpdb']->queries === array(), 'Direct repository call bypasses absolute cap');
    });
    run_test('unknown packet limit rejects writes and preserves cached data', function () {
        $GLOBALS['wpdb']->packet_bytes = null;
        $book = Notebooks::save_for_user(7, array(), 1);
        $upload = Upload_Store::store_bytes('small,csv');
        \OjCodeLab\Datasets\Dataset_Cache::put('existing', 'replacement');
        check(is_wp_error($book) && $book->data['status'] === 503 && is_wp_error($upload) && $upload->data['status'] === 503, 'Unknown packet limit must fail closed');
        check($GLOBALS['wpdb']->queries === array(), 'Unknown limit triggered a mutation');
    });
    run_test('oversized upload and cache never delete or write stored rows', function () {
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        $large = str_repeat("'", 8355841);
        $upload = Upload_Store::store_bytes($large);
        \OjCodeLab\Datasets\Dataset_Cache::put('existing', $large);
        check(is_wp_error($upload) && $upload->data['status'] === 413, 'Oversized blob was not rejected');
        check($GLOBALS['wpdb']->queries === array() && $GLOBALS['wpdb']->deleted === array(), 'Rejected blob altered existing records');
    });
    run_test('packet limit is read again when the connection setting changes', function () {
        check(\OjCodeLab\Support\Dataset_Limits::max_bytes() === 16000000, 'Full limit unavailable on large packet');
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        check(\OjCodeLab\Support\Dataset_Limits::max_bytes() === 8355840, 'Stale packet limit reused');
    });
    run_test('site health reports reduced and unavailable packet limits', function () {
        $GLOBALS['options']['oj_code_lab_db_version'] = '2';
        $GLOBALS['wpdb']->packet_bytes = 16777216;
        $health = \OjCodeLab\Support\Operations::health();
        check($health['status'] === 'recommended' && str_contains($health['description'], '8,355,840'), 'Reduced but safe limit missing from health');
        $GLOBALS['wpdb']->packet_bytes = null;
        check(\OjCodeLab\Support\Operations::health()['status'] === 'critical', 'Unknown storage safety cannot be healthy');
    });
    run_test('anonymous learner rejected', function () {
        $GLOBALS['logged_in'] = false;
        check(is_wp_error(NotebookApi::can_use()), 'Anonymous access allowed');
    });
    run_test('unknown output fields removed but text preserved', function () {
        $r=NotebookApi::sanitize_cells(array(array('code'=>'print("한글")','outputs'=>array(array('type'=>'html','text'=>'<script>'),array('type'=>'input','text'=>'이름')))));
        check(count($r[0]['outputs'])===1 && $r[0]['outputs'][0]['text']==='이름','Unexpected sanitization');
    });
    function dataset_row(): array { return array('id'=>1,'label'=>'Data','icon'=>'','source'=>'url','kaggle_owner'=>null,'kaggle_slug'=>null,'source_url'=>'https://example.org/data.csv?serviceKey=TEST_ONLY','file_name'=>'data.csv','sample_key'=>null,'created_by'=>2,'created_at'=>'today'); }
    run_test('learner does not receive source URL secrets', function () {
        $GLOBALS['wpdb']->rows = array(dataset_row());
        $r=DatasetApi::list_recommended(new WP_REST_Request())->get_data();
        check($r[0]['sourceUrl']===null && !str_contains(json_encode($r),'TEST_ONLY'),'URL leaked');
    });
    run_test('curator retains original URL and list access', function () {
        $GLOBALS['caps']=array('oj_code_lab_manage_datasets');
        $GLOBALS['wpdb']->rows=array(dataset_row());
        check(DatasetApi::can_search()===true,'Curator list denied');
        check(str_contains(DatasetApi::list_recommended(new WP_REST_Request())->get_data()[0]['sourceUrl'],'TEST_ONLY'),'Curator URL missing');
    });
    run_test('URL fetch delegates redirects to WordPress safe HTTP API', function () {
        Url_Source::fetch('https://example.org/data.csv');
        check($GLOBALS['http_call']['safe']===true,'Unsafe HTTP API used');
        check($GLOBALS['http_call']['args']['limit_response_size']===16_000_001,'Sentinel byte missing');
    });
    run_test('local and non-HTTP URLs rejected before network', function () {
        foreach(array('http://127.0.0.1/x','http://192.168.1.1/x','file:///etc/passwd') as $url) {
            check(is_wp_error(Url_Source::fetch($url)),'Unsafe URL allowed');
        }
        check($GLOBALS['http_call']===null,'Network was contacted');
    });
    run_test('safe transport rejects redirect target', function () {
        $GLOBALS['http_response']=new WP_Error('http_request_failed','Unsafe redirect');
        check(is_wp_error(Url_Source::fetch('https://example.org/redirect')),'Transport error swallowed');
    });
    run_test('HTTP 200 truncated URL response is rejected', function () {
        $GLOBALS['max_bytes']=10;
        $GLOBALS['http_response']=array('status'=>200,'body'=>str_repeat('a',11));
        check(is_wp_error(Url_Source::fetch('https://example.org/data.csv')),'Truncated response accepted');
    });
    run_test('exact-limit URL file is preserved', function () {
        $GLOBALS['max_bytes']=10;
        check(Url_Source::fetch('https://example.org/data.csv')['content']==='name,value','Boundary file changed');
    });
    run_test('Kaggle truncated response rejected', function () {
        $GLOBALS['max_bytes']=10;
        $GLOBALS['http_response']=array('status'=>200,'body'=>str_repeat('a',11));
        check(is_wp_error((new Kaggle_Client('test','test'))->download('owner','slug','data.csv')),'Oversized Kaggle response accepted');
    });
    run_test('Malformed Kaggle ZIP is rejected before text conversion', function () {
        $GLOBALS['http_response']=array('status'=>200,'body'=>"PK\x03\x04binary");
        $r=(new Kaggle_Client('test','test'))->download('owner','slug','data.csv');
        check(is_wp_error($r) && ($r->data['status'] ?? 0)>=400,'Broken ZIP corrupted into text');
    });
    run_test('upload storage failure is reported', function () {
        $GLOBALS['wpdb']->query_result=false;
        check(is_wp_error(Upload_Store::store_bytes('csv')),'Upload write failed silently');
    });
    run_test('normalized upload byte cap enforced', function () {
        $GLOBALS['max_bytes']=4;
        check(is_wp_error(Upload_Store::store_bytes('가나다')),'UTF-8 expansion bypassed cap');
        check($GLOBALS['wpdb']->queries===array(),'Oversized upload reached DB');
    });
    run_test('dataset metadata failure is reported', function () {
        $GLOBALS['wpdb']->insert_result=false;
        check(is_wp_error(DatasetApi::create_recommended(new WP_REST_Request(array('label'=>'Data','source'=>'url','sourceUrl'=>'https://example.org/data.csv')))),'Metadata failure returned success');
    });
    run_test('sample restore failure cleans up uploaded blob', function () {
        $GLOBALS['wpdb']->insert_result=false;
        check(is_wp_error(Sample_Dataset_Seeder::restore_missing()),'Restore failure ignored');
        check(count($GLOBALS['wpdb']->deleted)===1,'Orphan blob was not removed');
    });
    run_test('upgrade does not resurrect deleted sample datasets', function () {
        $GLOBALS['options']['oj_code_lab_db_version']='0.7.0-dev';
        Installer::install();
        check($GLOBALS['wpdb']->insertions===array(),'Upgrade restored removed samples');
        check(count($GLOBALS['schema_calls'])===4,'Table definitions missing');
    });
    run_test('fresh install seeds 29 datasets', function () {
        Installer::install();
        check(count($GLOBALS['wpdb']->insertions)===29,'Fresh sample set incomplete');
    });
    run_test('roster routes decode opaque context IDs before gateway use', function () {
        $GLOBALS['registered_routes'] = array();
        RosterApi::register_routes();
        check(count($GLOBALS['registered_routes']) === 2, 'Roster routes missing');
        foreach ($GLOBALS['registered_routes'] as $route) {
            $arg = $route['args']['id'];
            check(isset($arg['validate_callback'], $arg['sanitize_callback']), 'Encoded path ID callbacks missing');
            foreach (array('class%3A1' => 'class:1', 'group%3a99' => 'group:99', 'class:1' => 'class:1', '1' => '1', '%63lass%3A1' => 'class:1') as $raw => $decoded) {
                $raw = (string) $raw;
                check(call_user_func($arg['validate_callback'], $raw) === true, 'Valid opaque context ID rejected');
                check(call_user_func($arg['sanitize_callback'], $raw) === $decoded, 'Opaque context ID was not decoded exactly once');
            }
        }
    });
    run_test('roster ID validator rejects unsafe and double-encoded path values', function () {
        foreach (array('', '%', 'class%2F1', 'class%5C1', 'class%001', 'class%253A1', 'class%0A1', 'class:1%0A', 'class%201', 'class+1', '%ED%95%99%EA%B8%89', array('class:1'), 1, null) as $raw) {
            check(RosterApi::validate_context_id($raw) === false, 'Unsafe context ID accepted');
        }
    });
    run_test('roster ID limit applies to decoded tokens with bounded encoded input', function () {
        $token = 'c' . str_repeat(':', 159);
        check(RosterApi::validate_context_id($token) && RosterApi::validate_context_id(rawurlencode($token)), 'Valid 160-byte ID rejected');
        check(RosterApi::validate_context_id(str_repeat('%41', 160)), 'Valid fully encoded ID rejected');
        check(!RosterApi::validate_context_id($token . 'x') && !RosterApi::validate_context_id(rawurlencode($token . 'x')), '161-byte ID accepted');
        check(!RosterApi::validate_context_id(str_repeat('%41', 161)), 'Oversized encoded input accepted');
    });
    run_test('roster ID sanitizer fails closed for invalid parameter layers', function () {
        check(is_wp_error(RosterApi::sanitize_context_id(array('class:1'))), 'Array ID did not fail safely');
        check(is_wp_error(RosterApi::sanitize_context_id('class%253A1')), 'Double-encoded ID passed sanitization');
    });
    run_test('settings REST needs site admin or the 코드 실습실 설정 capability', function () {
        $routes = array();
        $GLOBALS['registered_routes'] = array();
        SettingsApi::register_routes();
        foreach ($GLOBALS['registered_routes']['/settings/kaggle'] as $route) { $routes[] = $route['permission_callback']; }
        check(count($routes) === 2 && $routes[0] === $routes[1] && $routes[0] === array(SettingsApi::class, 'can_manage_settings'), 'Settings read/save must share the settings permission check');
        foreach (array(array('oj_code_lab_use'), array('oj_code_lab_review'), array('oj_code_lab_manage_datasets'), array('manage_options')) as $caps) {
            $GLOBALS['caps'] = $caps;
            $denied = SettingsApi::can_manage_settings();
            check(is_wp_error($denied) && $denied->data['status'] === 403, 'Settings opened without the settings capability: ' . $caps[0]);
        }
        $GLOBALS['caps'] = array('oj_code_lab_settings');
        check(SettingsApi::can_manage_settings() === true, 'Granted teacher denied settings');
        $GLOBALS['caps'] = array();
        $GLOBALS['super_admin'] = true;
        check(SettingsApi::can_manage_settings() === true, 'Site administrator denied settings');
        $GLOBALS['logged_in'] = false;
        check(is_wp_error(SettingsApi::can_manage_settings()), 'Logged-out request passed settings check');
    });
    run_test('dataset curation keeps its own capability and admits site administrators', function () {
        $GLOBALS['caps'] = array('oj_code_lab_settings');
        check(is_wp_error(DatasetApi::can_manage_datasets()), 'Settings capability opened dataset curation');
        $GLOBALS['caps'] = array();
        check(is_wp_error(DatasetApi::can_search()), 'Search open without use or curation capability');
        $GLOBALS['super_admin'] = true;
        check(DatasetApi::can_manage_datasets() === true && DatasetApi::can_search() === true, 'Site administrator denied dataset curation or Kaggle search');
        $GLOBALS['super_admin'] = false;
        $GLOBALS['caps'] = array('oj_code_lab_manage_datasets');
        check(DatasetApi::can_manage_datasets() === true && DatasetApi::can_search() === true, 'Curator denied');
    });
    echo "44 regression scenarios passed (WordPress/HTTP/DB doubles).\n";
}

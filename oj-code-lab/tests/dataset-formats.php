<?php
declare(strict_types=1);
namespace OjCodeLab\Datasets {
    final class Kaggle_Settings {
        public static function is_configured(): bool { return true; }
        public static function username(): string { return 'fixture'; }
        public static function key(): string { return 'fixture'; }
    }
    function is_uploaded_file(string $path): bool { return $path === ($GLOBALS['fixture_upload'] ?? null); }
}
namespace {
    define('ABSPATH', __DIR__ . '/');
    define('ARRAY_A', 'ARRAY_A');
    define('OJ_CODE_LAB_PATH', dirname(__DIR__) . '/');
    class WP_Error {
        public function __construct(public string $code, public string $message = '', public array $data = array()) {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_messages(): array { return array($this->message); }
    }
    class WP_REST_Request {
        public function __construct(private array $params) {}
        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    }
    class WP_REST_Response {
        public function __construct(private mixed $data) {}
        public function get_data(): mixed { return $this->data; }
    }
    function __(string $text, string $domain = ''): string { return $text; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function rest_ensure_response(mixed $value): WP_REST_Response|WP_Error { return is_wp_error($value) ? $value : new WP_REST_Response($value); }
    function apply_filters(string $hook, mixed $default): mixed { return $hook === 'oj_code_lab_max_dataset_bytes' ? ($GLOBALS['file_limit'] ?? $default) : $default; }
    function wp_json_encode(mixed $value): string|false { return json_encode($value); }
    function wp_generate_uuid4(): string { return 'fixture-upload'; }
    function sanitize_file_name(string $value): string { return $value; }
    function wp_safe_remote_get(string $url, array $args): array { $GLOBALS['http_calls']++; $GLOBALS['http_args'] = $args; return array('status' => 200, 'body' => $GLOBALS['response_body']); }
    function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
    function wp_remote_retrieve_body(array $response): string { return $response['body']; }
    final class FormatDB {
        public string $prefix = 'fixture_';
        public string $last_error = '';
        public array $args = array();
        public array $cache = array();
        public ?array $dataset = null;
        public string $uploaded = '';
        public function prepare(string $query, mixed ...$args): string { $this->args = $args; return $query; }
        public function get_var(string $query): mixed {
            if (str_contains($query, '@@SESSION')) { return 67108864; }
            if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) { return 1; }
            if (str_contains($query, 'SELECT content FROM') && str_contains($query, 'dataset_cache')) { return $this->cache[$this->args[0]] ?? null; }
            if (str_contains($query, 'SELECT content FROM') && str_contains($query, 'uploads')) { return $this->uploaded; }
            if (str_contains($query, 'SELECT upload_key')) { return 'fixture-upload'; }
            return 0;
        }
        public function query(string $query): int {
            if (str_contains($query, 'INSERT INTO') && str_contains($query, 'dataset_cache')) { $this->cache[$this->args[0]] = $this->args[1]; }
            if (str_contains($query, 'INSERT INTO') && str_contains($query, 'uploads')) { $this->uploaded = $this->args[1]; }
            return 1;
        }
        public function get_row(string $query, string $format): ?array { return $this->dataset; }
    }
    require OJ_CODE_LAB_PATH . 'src/Core/Autoloader.php';
    \OjCodeLab\Core\Autoloader::register();
    use OjCodeLab\Support\Dataset_File as File;
    use OjCodeLab\Rest\Controllers\Dataset_Controller as Api;
    use OjCodeLab\Datasets\Kaggle_Client;
    use OjCodeLab\Datasets\Upload_Store;
    use OjCodeLab\Datasets\Url_Source;
    $wpdb = new FormatDB();
    $checks = 0;
    $http_calls = 0;
    function check(bool $ok, string $label): void {
        global $checks;
        if (! $ok) { throw new \RuntimeException('FAIL ' . $label); }
        $checks++; echo 'PASS ' . $label . PHP_EOL;
    }
    function zip_file(array $files): string {
        $path = tempnam(sys_get_temp_dir(), 'ocl-format-');
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $body) { $zip->addFromString($name, $body); }
        $zip->close(); $bytes = file_get_contents($path); unlink($path); return $bytes;
    }
    $jsonl = "{\"text\":\"한글\",\"nested\":{\"ok\":true}}\n{\"text\":\"둘째\",\"items\":[1,2]}\n";
    check(File::normalize($jsonl, 'records.JSONL') === array('content'=>$jsonl,'fileName'=>'records.JSONL','format'=>'jsonl'), 'JSONL retains Unicode nested records and format');
    check(File::normalize($jsonl, 'records.ndjson')['format'] === 'jsonl', 'NDJSON alias uses JSONL');
    check(File::normalize("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $jsonl), 'records.jsonl')['content'] === str_replace("\n", "\r\n", $jsonl), 'BOM and CRLF normalize without changing records');
    check(File::normalize("\n" . $jsonl . "\n", 'data.jsonl')['format'] === 'jsonl', 'Blank separators are accepted');
    check(File::normalize("[1,\"한글\"]\n[2,\"둘\"]\n", 'data.jsonl')['format'] === 'jsonl', 'Array records are supported');
    $bad = File::normalize("{}\n{secret broken}\n", 'data.jsonl');
    check(is_wp_error($bad) && str_contains($bad->message, '2번째') && !str_contains($bad->message, 'secret'), 'Invalid JSONL reports line number without content');
    check(is_wp_error(File::normalize(" \r\n", 'empty.jsonl')), 'Empty JSONL is rejected');
    check(is_wp_error(File::normalize("true\n", 'scalar.jsonl')), 'Non-tabular scalar records get a clear rejection');
    check(is_wp_error(File::normalize('{"x":"' . str_repeat('a', 1048576) . '"}', 'large.jsonl')), 'One JSONL record cannot allocate beyond its line cap');
    check(File::normalize("x,y\n1,2", 'data.csv')['format'] === 'csv', 'CSV remains CSV');
    check(File::normalize("PK,id\n1,2\n", 'data.csv')['content'] === "PK,id\n1,2\n", 'CSV beginning with PK is not mistaken for ZIP');
    check(File::normalize($jsonl, 'download')['fileName'] === 'download.jsonl', 'Extensionless JSONL gets a usable filename');
    $file_limit = 12;
    check(is_wp_error(File::normalize($jsonl, 'large.jsonl')), 'Total normalized file cap applies');
    unset($file_limit);
    check(File::supported_name('data.NDJSON') && ! File::supported_name('script.py'), 'Supported file choice excludes unrelated formats');
    check(is_wp_error(File::normalize('<html>login</html>', 'data.jsonl')), 'Login HTML is never imported as data');
    $response_body = $jsonl;
    $direct = (new Kaggle_Client('fixture','fixture'))->download('owner','slug','records.jsonl');
    check($direct['format'] === 'jsonl' && $direct['content'] === $jsonl, 'Kaggle plain JSONL returns typed content');
    check(Url_Source::fetch('https://example.org/download')['format'] === 'jsonl', 'URL JSONL uses the shared format path');
    if (! class_exists('ZipArchive')) { throw new \RuntimeException('ZIP extension is required for this acceptance suite'); }
    $response_body = zip_file(array('data/records.jsonl'=>$jsonl, 'README.txt'=>'dataset'));
    $zip = (new Kaggle_Client('fixture','fixture'))->download('owner','slug','records.jsonl');
    check($zip['content'] === $jsonl && $zip['fileName'] === 'records.jsonl' && $zip['format'] === 'jsonl', 'Kaggle ZIP wrapper resolves its actual JSONL member');
    $response_body = zip_file(array('data.csv'=>"x,y\n1,2"));
    check((new Kaggle_Client('fixture','fixture'))->download('owner','slug','data.csv')['format'] === 'csv', 'Kaggle zipped CSV remains compatible');
    $response_body = zip_file(array('one.csv'=>"x\n1", 'two.jsonl'=>$jsonl));
    check(is_wp_error(Url_Source::fetch('https://example.org/data.zip')), 'Ambiguous URL archive cannot silently pick a file');
    $response_body = zip_file(array('nested/records.jsonl'=>$jsonl));
    $wpdb->cache[sha1('owner/slug/data.zip')] = 'legacy raw CSV cache';
    $request = new WP_REST_Request(array('owner'=>'owner','slug'=>'slug','file'=>'data.zip'));
    $before = $http_calls;
    $first = Api::import($request)->get_data();
    $second = Api::import($request)->get_data();
    check($first === $second && $first['fileName'] === 'records.jsonl' && $first['format'] === 'jsonl' && $http_calls === $before + 1, 'Kaggle archive cache hit preserves filename format and bytes');
    check($wpdb->cache[sha1('owner/slug/data.zip')] === 'legacy raw CSV cache', 'Versioned cache does not overwrite legacy raw entries');
    $file_limit=1024;$response_body="value\n" . str_repeat("1\n", 300);$before=$http_calls;
    $largeRequest=new WP_REST_Request(array('owner'=>'owner','slug'=>'slug','file'=>'large.csv'));
    $largeFirst=Api::import($largeRequest)->get_data();$largeAgain=Api::import($largeRequest)->get_data();
    check($largeFirst===$largeAgain && $largeFirst['content']===$response_body && $http_calls===$before+2, 'Large import succeeds while avoiding cache envelope memory overhead');
    unset($file_limit);$response_body=zip_file(array('nested/records.jsonl'=>$jsonl));
    $url_import = new \ReflectionMethod(Api::class, 'import_recommended_url');
    $dataset = array('sourceUrl'=>'https://example.org/data.zip','fileName'=>null);
    $before = $http_calls;
    $first = $url_import->invoke(null, $dataset)->get_data();
    $second = $url_import->invoke(null, $dataset)->get_data();
    check($first === $second && $first['fileName'] === 'records.jsonl' && $http_calls === $before + 1, 'URL archive cache hit preserves actual inner filename');
    $fixture_upload = tempnam(sys_get_temp_dir(), 'ocl-upload-');
    try {
        file_put_contents($fixture_upload, $response_body);
        $upload = Upload_Store::store(array('name'=>'data.zip','tmp_name'=>$fixture_upload,'error'=>UPLOAD_ERR_OK,'size'=>strlen($response_body)));
        check(!is_wp_error($upload) && $upload['fileName']==='records.jsonl' && $wpdb->uploaded === $jsonl, 'Teacher ZIP upload stores extracted JSONL and its filename');
        $upload_import = new \ReflectionMethod(Api::class, 'import_recommended_upload');
        $result = $upload_import->invoke(null, array('id'=>1,'fileName'=>'records.jsonl'))->get_data();
        check($result['content']===$jsonl && $result['format']==='jsonl', 'Stored upload imports with JSONL reader metadata');
        file_put_contents($fixture_upload, '{broken}');
        $bad = Upload_Store::store(array('name'=>'data.jsonl','tmp_name'=>$fixture_upload,'error'=>UPLOAD_ERR_OK,'size'=>8));
        check(is_wp_error($bad) && $wpdb->uploaded === $jsonl, 'Invalid upload preserves existing stored bytes');
        $file_limit = 10; file_put_contents($fixture_upload, str_repeat('a', 11));
        $bad = Upload_Store::store(array('name'=>'data.csv','tmp_name'=>$fixture_upload,'error'=>UPLOAD_ERR_OK,'size'=>1));
        check(is_wp_error($bad), 'Upload actual bounded read defeats a forged size field');
    } finally { unlink($fixture_upload); unset($fixture_upload, $file_limit); }
    echo json_encode(array('suite'=>'dataset-formats','checks'=>$checks,'passed'=>true,'scope'=>'real ZIP/text parsers with HTTP/DB doubles'), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

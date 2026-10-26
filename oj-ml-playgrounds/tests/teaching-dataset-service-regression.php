<?php
declare(strict_types=1);

namespace OJML\Playgrounds\Security {
    final class Addon_Capabilities {
        public const MANAGE = 'manage'; public const USE = 'use'; public const SUBMIT = 'submit';
        public static function wordpress(string $key): string { return $key; }
    }
}
namespace OJML\Playgrounds\Ops {
    final class Options {
        public static bool $maintenance = false;
        public static function all(): array { return ['maintenanceMode' => self::$maintenance]; }
    }
}
namespace OJML\Playgrounds\Database {
    final class Repository {
        public array $assignments = []; public array $events = []; public bool $active = true; public string $failEvent = '';
        public function getAssignment(int $id): ?array { return $this->assignments[$id] ?? null; }
        public function isActiveLearner(int $assignmentId, int $actorId): bool { return $this->active; }
        public function insertEvent(?int $assignment, ?int $attempt, ?int $actor, string $event, array $payload, string $correlation): void {
            if ($event === $this->failEvent) { throw new \RuntimeException('sensitive audit connection error'); }
            $this->events[] = [$actor, $event, $payload, $correlation];
        }
    }
}
namespace OJML\Playgrounds\Application {
    final class ContextService {
        public bool $allowed = true; public array $requests = [];
        public function assertAssignmentAccess(int $actor, array $assignment, string $operation, ?int $learner = null): void {
            $this->requests[] = [$actor, $assignment['id'], $operation, $learner];
            if (!$this->allowed) { throw new \OJML\Playgrounds\Domain\Problem('CONTEXT_DENIED', '권한 없음', 403); }
        }
    }
}
namespace OJML\Playgrounds\Rest { final class Routes { public const NAMESPACE = 'oj-ml/v1'; } }
namespace {
    use OJML\Playgrounds\Application\ContextService;
    use OJML\Playgrounds\Application\TeachingDatasetService;
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    use OJML\Playgrounds\Domain\TeachingDataset;
    use OJML\Playgrounds\Ops\Options;
    use OJML\Playgrounds\Rest\Permissions;
    use OJML\Playgrounds\Rest\TeachingDatasetRoute;
    use OJML\Playgrounds\Storage\PrivateArtifactStore;
    use OJML\Playgrounds\Storage\TeachingAssetStore;

    final class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
    final class WP_REST_Response {
        public array $headers = [];
        public function __construct(public mixed $data = null, public int $status = 200) {}
        public function header(string $key, string $value): void { $this->headers[$key] = $value; }
    }
    final class WP_REST_Server { public const READABLE = 'GET'; public const CREATABLE = 'POST'; public const DELETABLE = 'DELETE'; }
    final class WP_REST_Request implements \ArrayAccess {
        public function __construct(public array $params = [], public array $files = [], public string $method = 'GET', public string $nonce = 'valid') {}
        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
        public function get_file_params(): array { return $this->files; }
        public function get_method(): string { return $this->method; }
        public function get_header(string $key): string { return strtolower($key) === 'x-wp-nonce' ? $this->nonce : ''; }
        public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
        public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
        public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
        public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
    }
    $wpdb = new class {
        public string $dbname = 'isolated_csv_fixture'; public string $last_error = ''; public bool $busy = false; public int $locks = 0;
        public function prepare(string $sql, mixed ...$params): string { return $sql; }
        public function get_var(string $sql): mixed {
            if (str_contains($sql, 'GET_LOCK')) { if ($this->busy) { return '0'; } $this->locks++; return '1'; }
            if (str_contains($sql, 'RELEASE_LOCK')) { $this->locks--; return '1'; }
            throw new \RuntimeException('Unexpected SQL');
        }
    };
    $actor = 7; $permission = true; $uploaded = true; $saveFails = false; $optionData = []; $registered = []; $filters = []; $autoloads = []; $diagnostics = 0;
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function user_can(int $id, string $cap): bool { return $GLOBALS['permission'] && $id > 0 && in_array($cap, ['manage', 'use', 'submit'], true); }
    function current_user_can(string $cap): bool { return user_can(get_current_user_id(), $cap); }
    function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'valid' ? 1 : false; }
    function wp_generate_uuid4(): string { return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; }
    function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0750, true); }
    function sanitize_file_name(string $name): string { return preg_replace('/[<>:"?*]/', '', $name); }
    function apply_filters(string $name, mixed $value, mixed ...$args): mixed { return $name === 'oj_ml_playgrounds_is_uploaded_file' ? $GLOBALS['uploaded'] : $value; }
    function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['optionData'][$name] ?? $default; }
    function update_option(string $name, mixed $value, mixed $autoload = null): bool {
        $GLOBALS['autoloads'][] = $autoload;
        if ($GLOBALS['saveFails']) { return false; }
        $GLOBALS['optionData'][$name] = $value; return true;
    }
    function wp_cache_delete(string $name, string $group): bool { return true; }
    function do_action(string $name, mixed ...$args): void { $GLOBALS['diagnostics']++; }
    function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['registered'][$route] = $args; }
    function add_filter(string $name, callable $callback, int $priority, int $args): void { $GLOBALS['filters'][$name] = [$callback, $priority, $args]; }
    require_once __DIR__ . '/bootstrap.php';

    $checks = 0;
    function csvAssert(bool $ok, string $label): void { if (!$ok) { throw new \RuntimeException($label); } $GLOBALS['checks']++; }
    function csvReject(callable $call, string $code, int $status): void {
        try { $call(); } catch (Problem $error) { csvAssert($error->problemCode() === $code && $error->httpStatus() === $status, $code . ': ' . $error->problemCode()); return; }
        throw new \RuntimeException('Expected ' . $code);
    }
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ojml-csv-test-' . bin2hex(random_bytes(10));
    mkdir($fixtureRoot); mkdir($fixtureRoot . '/wordpress');
    define('ABSPATH', $fixtureRoot . '/wordpress/');
    unset($_SERVER['DOCUMENT_ROOT']);
    $private = new PrivateArtifactStore($fixtureRoot . '/private');
    $store = new TeachingAssetStore($private);
    $repo = new Repository(); $contexts = new ContextService();
    $service = new TeachingDatasetService($repo, $store, $contexts);
    $inputPath = $fixtureRoot . '/input.csv';
    $input = "이름,결과\r\n가,1\r\n나,0\r\n";
    file_put_contents($inputPath, $input);
    $upload = ['error' => UPLOAD_ERR_OK, 'name' => '수업.csv', 'tmp_name' => $inputPath, 'size' => strlen($input)];
    try {
        $canonical = TeachingDataset::normalize($input);
        csvAssert($canonical['content'] === "\xEF\xBB\xBF이름,결과\n가,1\n나,0", 'BOM + LF + no trailing LF canonical bytes');
        csvAssert($canonical === TeachingDataset::normalize($canonical['content']), 'Idempotent normalization');
        foreach ([
            ['', 'OJ_ML_DATASET_SIZE_INVALID', 413], [str_repeat('x', TeachingDataset::MAX_BYTES + 1), 'OJ_ML_DATASET_SIZE_INVALID', 413],
            ["x,y\n\xFF,1", 'OJ_ML_DATASET_ENCODING_INVALID', 400], ["x;y\n1;2", 'OJ_ML_DATASET_DELIMITER_INVALID', 400],
            ["x,y\n1\t2,3", 'OJ_ML_DATASET_DELIMITER_INVALID', 400], ["x,y\n1|2,3", 'OJ_ML_DATASET_DELIMITER_INVALID', 400],
            ["x,y\n1\0,2", 'OJ_ML_DATASET_DELIMITER_INVALID', 400], ["x,y\n\"no,1", 'OJ_ML_DATASET_CSV_INVALID', 400],
            ["x,y\nx\"q,1", 'OJ_ML_DATASET_CSV_INVALID', 400], ["x,y\n\"a\"tail,1", 'OJ_ML_DATASET_CSV_INVALID', 400],
            ["x,y\n1,2,3", 'OJ_ML_DATASET_TABLE_INVALID', 400], ["x,y", 'OJ_ML_DATASET_TABLE_INVALID', 400],
            ["one\n1", 'OJ_ML_DATASET_TABLE_INVALID', 400], ["x,x\n1,2", 'OJ_ML_DATASET_HEADER_INVALID', 400],
            [",y\n1,2", 'OJ_ML_DATASET_HEADER_INVALID', 400], ["x,y\n" . str_repeat('x', 65537) . ",2", 'OJ_ML_DATASET_COMPLEXITY_LIMIT', 413],
            ["\xC2\xA0,y\n1,2", 'OJ_ML_DATASET_HEADER_INVALID', 400],
            [implode(',', range(1, 101)) . "\n1,2", 'OJ_ML_DATASET_COMPLEXITY_LIMIT', 413],
            ["x,y\n" . str_repeat("1,2\n", 20000), 'OJ_ML_DATASET_COMPLEXITY_LIMIT', 413],
            [implode(',', range(1, 100)) . "\n" . str_repeat(implode(',', array_fill(0, 100, '1')) . "\n", 2000), 'OJ_ML_DATASET_COMPLEXITY_LIMIT', 413],
        ] as [$bad, $code, $status]) { csvReject(fn () => TeachingDataset::normalize($bad), $code, $status); }
        foreach (['../file.csv', 'a\\b.csv', "x\n.csv", 'file.exe', "\xFF.csv", str_repeat('x', 121) . '.csv'] as $name) {
            csvReject(fn () => TeachingDataset::filename($name), 'OJ_ML_DATASET_FILENAME_INVALID', 400);
        }
        csvAssert(TeachingDataset::filename('한국어.CSV') === '한국어.CSV', 'Unicode filenames supported');
        foreach (['=1+1', '+SUM(A1)', '@cmd', '-1+cmd', '-apple', "  =HYPERLINK(\"\"https://example.invalid\"\")", "\xC2\xA0=1", "\xEF\xBB\xBF@cmd", "\r\n+cmd"] as $formula) {
            $csv = "value,label\n\"" . str_replace('"', '""', $formula) . "\",one";
            csvReject(fn () => TeachingDataset::normalize($csv), 'OJ_ML_DATASET_FORMULA_UNSAFE', 400);
        }
        foreach (['-1', '+1', '-0.5', '+.5', '-1.5e-3', '+1E6', '  -7', 'ordinary text', "'=1+1"] as $value) {
            csvAssert(TeachingDataset::normalize("value,label\n" . $value . ",one")['rowCount'] === 1, 'Safe signed number or literal is preserved: ' . $value);
        }
        csvAssert($service->listForTeacher(7)['items'] === [], 'Initial library empty');
        csvReject(fn () => $service->upload(0, $upload, 'test'), 'OJ_ML_AUTHENTICATION_REQUIRED', 401);
        $permission = false; csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_CAPABILITY_REQUIRED', 403); $permission = true;
        Options::$maintenance = true; csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_MAINTENANCE', 503); Options::$maintenance = false;
        $uploaded = false; csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_UPLOAD_INVALID', 400); $uploaded = true;
        csvReject(fn () => $service->upload(7, array_replace($upload, ['size' => 1]), 'test'), 'OJ_ML_DATASET_UPLOAD_INVALID', 400);
        $wpdb->busy = true; csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_SERVER_BUSY', 429); $wpdb->busy = false;
        $record = $service->upload(7, $upload, 'upload-correlation');
        $id = $record['id'];
        csvAssert($record['sha256'] === $canonical['sha256'] && $record['bytes'] === $canonical['bytes'], 'Server authoritative canonical hash');
        csvAssert(!isset($record['ownerId'], $record['storageKey'], $record['audit']), 'Private metadata never returned');
        csvAssert($record['path'] === 'dataset/data.csv' && $record['rowCount'] === 2 && $record['columnCount'] === 2, 'Dataset policy metadata');
        csvAssert($autoloads === [false], 'Metadata never autoloads');
        csvAssert(count($repo->events) === 2 && $repo->events[1][1] === 'teaching_dataset.uploaded', 'Intent and completed audit');
        csvAssert($wpdb->locks === 0, 'Lease released after upload and failures');
        csvAssert(count($service->listForTeacher(7)['items']) === 1 && $service->listForTeacher(8)['items'] === [], 'Owner isolated library');
        csvAssert($service->resolveReference(['id' => $id, 'sha256' => 'forged'], 7)['sha256'] === $canonical['sha256'], 'Client hashes ignored');
        csvReject(fn () => $service->resolveReference(['id' => $id], 8), 'OJ_ML_DATASET_NOT_FOUND', 404);
        $assignment = ['id' => 42, 'status' => 'published', 'opens_at' => null, 'policy' => ['datasetResource' => $record]];
        $repo->assignments[42] = $assignment;
        csvAssert($service->resolveReference(['id' => $id], 8, $assignment)['id'] === $id, 'Authorized co-teacher can preserve and duplicate source reference');
        csvAssert(end($contexts->requests)[2] === 'manage', 'Current manage permission checked for shared resource');
        $contexts->allowed = false; csvReject(fn () => $service->resolveReference(['id' => $id], 8, $assignment), 'CONTEXT_DENIED', 403); $contexts->allowed = true;
        csvReject(fn () => $service->resolveReference(['id' => $id], 8, array_replace($assignment, ['id' => 99])), 'OJ_ML_DATASET_NOT_FOUND', 404);
        csvAssert($service->downloadForLearner(42, 9)['content'] === $canonical['content'], 'Student receives canonical exact bytes');
        csvAssert(end($contexts->requests) === [9, 42, 'participate', 9], 'Current student context checked');
        $repo->active = false; csvReject(fn () => $service->downloadForLearner(42, 9), 'OJ_ML_DATASET_NOT_FOUND', 404); $repo->active = true;
        $contexts->allowed = false; csvReject(fn () => $service->downloadForLearner(42, 9), 'CONTEXT_DENIED', 403); $contexts->allowed = true;
        $repo->assignments[42]['status'] = 'draft'; csvReject(fn () => $service->downloadForLearner(42, 9), 'OJ_ML_DATASET_NOT_FOUND', 404);
        $repo->assignments[42] = $assignment; $repo->assignments[42]['opens_at'] = '2999-01-01 00:00:00';
        csvReject(fn () => $service->downloadForLearner(42, 9), 'OJ_ML_ASSIGNMENT_NOT_OPEN', 409); $repo->assignments[42] = $assignment;
        $repo->assignments[42]['policy']['datasetResource']['sha256'] = str_repeat('0', 64);
        csvReject(fn () => $service->downloadForLearner(42, 9), 'OJ_ML_TEACHING_ASSET_INTEGRITY', 503); $repo->assignments[42] = $assignment;
        csvReject(fn () => $service->hide(8, $id, 'test'), 'OJ_ML_DATASET_NOT_FOUND', 404);
        csvAssert($service->hide(7, $id, 'test')['retained'] === true, 'Hide explicitly retains bytes');
        csvAssert($service->listForTeacher(7)['items'] === [] && $service->listForTeacher(7)['usage']['retainedCount'] === 1, 'Hidden omitted but counted in quota');
        csvReject(fn () => $service->resolveReference(['id' => $id], 7), 'OJ_ML_DATASET_NOT_FOUND', 404);
        csvAssert($service->resolveReference(['id' => $id], 8, $assignment)['id'] === $id, 'Hidden reference preserved on authorized source');
        csvAssert($service->downloadForLearner(42, 9)['content'] === $canonical['content'], 'Hiding cannot break an assigned student download');
        csvAssert(TeachingDatasetService::privacyForUser(7)['retained'] && count(TeachingDatasetService::privacyForUser(7)['items']) === 1 && !TeachingDatasetService::privacyForUser(8)['retained'], 'Privacy lists only teacher-owned retained metadata without constructing runtime');
        $hiddenAuditCount = count($repo->events); $service->hide(7, $id, 'repeat'); csvAssert(count($repo->events) === $hiddenAuditCount, 'Hide idempotent');
        $savedOptions = $optionData;
        $beforeFiles = glob($fixtureRoot . '/private/teaching-assets/*.csv');
        $saveFails = true; csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_LIBRARY_SAVE_FAILED', 503); $saveFails = false;
        csvAssert(glob($fixtureRoot . '/private/teaching-assets/*.csv') === $beforeFiles, 'Known option failure cleans only newly created orphan');
        $repo->failEvent = 'teaching_dataset.upload_requested';
        try { $service->upload(7, $upload, 'test'); throw new \RuntimeException('Expected audit failure'); } catch (\RuntimeException $error) { csvAssert($error->getMessage() === 'sensitive audit connection error', 'Audit intent failure prevents writes'); }
        $repo->failEvent = '';
        csvAssert(glob($fixtureRoot . '/private/teaching-assets/*.csv') === $beforeFiles, 'Failed audit did not write a file');
        $template = reset($optionData[TeachingDatasetService::OPTION]);
        for ($i = 0; $i < 20; $i++) { $fake = $template; $fake['id'] = str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT); $fake['hiddenAt'] = null; $optionData[TeachingDatasetService::OPTION][$fake['id']] = $fake; }
        csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_QUOTA_EXCEEDED', 413);
        $optionData = $savedOptions;
        $quotaBase = reset($optionData[TeachingDatasetService::OPTION]);
        for ($i = 0; $i < 10; $i++) {
            $fake = $quotaBase; $fake['id'] = str_pad(dechex($i + 300), 32, '0', STR_PAD_LEFT); $fake['bytes'] = TeachingDataset::MAX_BYTES;
            $optionData[TeachingDatasetService::OPTION][$fake['id']] = $fake;
        }
        csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_QUOTA_EXCEEDED', 413);
        $optionData = $savedOptions;
        for ($i = 0; $i < 40; $i++) {
            $fake = $quotaBase; $fake['id'] = str_pad(dechex($i + 500), 32, '0', STR_PAD_LEFT); $fake['ownerId'] = 99; $fake['bytes'] = TeachingDataset::MAX_BYTES;
            $optionData[TeachingDatasetService::OPTION][$fake['id']] = $fake;
        }
        csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_QUOTA_EXCEEDED', 413);
        $optionData = $savedOptions;
        for ($i = 0; $i < 199; $i++) {
            $fake = $quotaBase; $fake['id'] = str_pad(dechex($i + 800), 32, '0', STR_PAD_LEFT); $fake['ownerId'] = 99;
            $optionData[TeachingDatasetService::OPTION][$fake['id']] = $fake;
        }
        csvReject(fn () => $service->upload(7, $upload, 'test'), 'OJ_ML_DATASET_QUOTA_EXCEEDED', 413);
        $optionData = $savedOptions;
        $orphan = $fixtureRoot . '/private/teaching-assets/orphan.part';
        file_put_contents($orphan, 'retained');
        csvAssert($store->usage()['count'] === 2 && $store->usage()['bytes'] === $canonical['bytes'] + 8, 'Physical quota counts unregistered crash leftovers');
        unlink($orphan);
        $privateRecord = $optionData[TeachingDatasetService::OPTION][$id];
        csvReject(fn () => $store->read(array_replace($privateRecord, ['storageKey' => '../input.csv'])), 'OJ_ML_TEACHING_ASSET_UNSAFE', 503);
        csvReject(fn () => $store->read(array_replace($privateRecord, ['bytes' => $privateRecord['bytes'] + 1])), 'OJ_ML_TEACHING_ASSET_INTEGRITY', 503);
        csvReject(fn () => $store->read(array_replace($privateRecord, ['sha256' => str_repeat('0', 64)])), 'OJ_ML_TEACHING_ASSET_INTEGRITY', 503);
        csvReject(fn () => $store->discardNew('artifacts/a.csv'), 'OJ_ML_TEACHING_ASSET_UNSAFE', 503);
        csvAssert(!is_dir($fixtureRoot . '/private/artifacts'), 'Teaching assets isolated from submission reconciliation');
        $route = new TeachingDatasetRoute($service); $route->register();
        csvAssert(count($registered) === 3 && isset($filters['rest_pre_serve_request']), 'Complete routes and binary serving filter');
        csvAssert($registered['/console/datasets'][1]['permission_callback'] === [Permissions::class, 'manageMutation'], 'Upload requires management and nonce');
        csvAssert($registered['/console/datasets/(?P<id>[a-f0-9]{32})']['permission_callback'] === [Permissions::class, 'manageMutation'], 'Hide requires management and nonce');
        csvAssert(Permissions::manageMutation(new WP_REST_Request([], [], 'POST', 'bad')) instanceof WP_Error, 'Mutation bad nonce denied');
        $request = new WP_REST_Request(['id' => 42]);
        $response = $route->download($request);
        csvAssert($response instanceof WP_REST_Response && $response->data === null, 'Binary file never embedded in JSON data');
        csvAssert($response->headers['Cache-Control'] === 'no-store, private' && $response->headers['X-Content-Type-Options'] === 'nosniff', 'Private no-cache headers');
        csvAssert(str_starts_with($response->headers['Content-Disposition'], 'attachment;') && str_contains($response->headers['Content-Disposition'], 'filename*=UTF-8'), 'Encoded attachment filename');
        ob_start(); $served = $route->serveBinary(false, $response, $request, null); $content = ob_get_clean();
        csvAssert($served && $content === $canonical['content'], 'Exact raw CSV served without JSON quotes');
        csvAssert(!$route->serveBinary(false, $response, $request, null), 'Body sent at most once');
        $head = new WP_REST_Request(['id' => 42], [], 'HEAD'); $headResponse = $route->download($head);
        ob_start(); $headServed = $route->serveBinary(false, $headResponse, $head, null); $headContent = ob_get_clean();
        csvAssert($headServed && $headContent === '', 'HEAD sends no body');
        csvAssert($route->download(new WP_REST_Request(['id' => 42, '_envelope' => '1'])) instanceof WP_Error, 'Envelope option denied');
        $uploadResponse = $route->upload(new WP_REST_Request([], ['dataset' => $upload], 'POST'));
        csvAssert($uploadResponse instanceof WP_REST_Response && $uploadResponse->status === 201 && isset($uploadResponse->data['data']['dataset']['id']), 'Upload matches UI dataset wrapper');
        $repo->failEvent = 'teaching_dataset.uploaded'; $beforeDiagnostic = $diagnostics;
        $postCommit = $service->upload(7, $upload, 'completion-failure');
        csvAssert(isset($optionData[TeachingDatasetService::OPTION][$postCommit['id']]) && $diagnostics > $beforeDiagnostic, 'Completion audit failure preserves committed file and metadata audit');
        $repo->failEvent = '';
        $repo->failEvent = 'teaching_dataset.upload_requested';
        $errorResponse = $route->upload(new WP_REST_Request([], ['dataset' => $upload], 'POST'));
        csvAssert($errorResponse instanceof WP_Error && $errorResponse->code === 'OJ_ML_INTERNAL_ERROR' && !str_contains($errorResponse->message, 'sensitive'), 'REST hides internal audit diagnostics');
        $repo->failEvent = '';
        $optionData[TeachingDatasetService::OPTION]['invalid'] = [];
        csvReject(fn () => $service->listForTeacher(7), 'OJ_ML_DATASET_LIBRARY_INVALID', 503);
        unset($optionData[TeachingDatasetService::OPTION]['invalid']);
        csvAssert($wpdb->locks === 0, 'No leaked connection lease');
        echo "Teaching dataset service/storage/REST: {$checks} checks passed.\n";
    } finally {
        // Only this test's freshly created, explicit random subtree is eligible for cleanup.
        $actual = realpath($fixtureRoot);
        if ($actual !== false && str_starts_with(basename($actual), 'ojml-csv-test-')) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($actual, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($walk as $item) { if ($item->isDir() && !$item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); } }
            rmdir($actual);
        }
    }
}

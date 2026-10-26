<?php
declare(strict_types=1);

namespace OjCodeLab\Datasets {
    // Advance a deterministic clock in HTTP fixtures; no slow sleeps are needed.
    function microtime(bool $as_float = false): float { return $GLOBALS['ocl_clock']; }
}

namespace {
    final class WP_Error {
        public function __construct(private string $code, private string $message, private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed { return $this->data; }
    }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function __(string $value, string $domain = ''): string { return $value; }
    function wp_remote_retrieve_response_code(array $response): int { return $response['response']['code']; }
    function wp_remote_retrieve_body(array $response): string { return $response['body']; }
    function wp_remote_retrieve_header(array $response, string $name): string { return $response['headers'][$name] ?? ''; }
    final class WP_Http {
        public static function make_absolute_url(string $target, string $base): string {
            return str_starts_with($target, '/') ? 'https://' . parse_url($base, PHP_URL_HOST) . $target : $target;
        }
    }
    function wp_safe_remote_get(string $url, array $args): array|WP_Error {
        $GLOBALS['ocl_requests'][] = array('url' => $url, 'args' => $args);
        if (!$GLOBALS['ocl_responses']) { throw new \RuntimeException('Unexpected extra HTTP request'); }
        $next = array_shift($GLOBALS['ocl_responses']);
        return is_callable($next) ? $next($url, $args) : $next;
    }
    require dirname(__DIR__) . '/src/Datasets/Kaggle_Client.php';
    use OjCodeLab\Datasets\Kaggle_Client;

    function payload(array $data, int $code = 200): array {
        return array('response' => array('code' => $code), 'headers' => array(), 'body' => json_encode($data, JSON_THROW_ON_ERROR));
    }
    function page(array $names, ?string $next = null): array {
        $data = array('datasetFiles' => array_map(static fn($name) => array('name' => $name, 'totalBytes' => 10), $names));
        if (null !== $next) { $data['nextPageToken'] = $next; }
        return payload($data);
    }
    function check(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function error_code(mixed $value, string $code): void {
        check($value instanceof WP_Error && $value->get_error_code() === $code, 'Expected complete failure: ' . $code);
    }
    function run(string $name, callable $test): void {
        $GLOBALS['ocl_clock'] = 1000.0;
        $GLOBALS['ocl_requests'] = array();
        $GLOBALS['ocl_responses'] = array();
        $test(new Kaggle_Client('fixture-user', 'fixture-key'));
        $GLOBALS['ocl_passed']++;
        echo 'PASS ' . $name . PHP_EOL;
    }
    $GLOBALS['ocl_passed'] = 0;

    run('two pages are combined and opaque token is encoded', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'cursor+/='), page(array('second.csv')));
        $result = $client->files('owner', 'slug');
        check(array_column($result, 'name') === array('first.csv', 'second.csv'), 'Later file omitted');
        check($result[0]['size'] === '10', 'Size shape changed');
        check(count($GLOBALS['ocl_requests']) === 2, 'Wrong page count');
        parse_str(parse_url($GLOBALS['ocl_requests'][1]['url'], PHP_URL_QUERY), $query);
        check($query['pageToken'] === 'cursor+/=' && $query['pageSize'] === '100', 'Wrong page query');
    });
    run('empty canonical list is valid', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array()));
        check($client->files('owner', 'slug') === array(), 'Empty list rejected');
    });
    run('legacy list and files envelope remain supported', function ($client) {
        foreach (array(array(array('name' => 'old.csv')), array('files' => array(array('nameNullable' => 'old.csv')))) as $data) {
            $GLOBALS['ocl_responses'] = array(payload($data));
            check($client->files('owner', 'slug')[0]['name'] === 'old.csv', 'Legacy response changed');
        }
    });
    run('second-page network failure discards earlier files', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'next'), new WP_Error('transport', 'Fixture'));
        error_code($client->files('owner', 'slug'), 'oj_code_lab_remote_failed');
    });
    run('second-page HTTP failure discards earlier files', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'next'), payload(array('error' => 'Fixture'), 503));
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_http_error');
    });
    run('second-page malformed JSON discards earlier files', function ($client) {
        $broken = payload(array()); $broken['body'] = 'not JSON';
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'next'), $broken);
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_bad_response');
    });
    run('unknown and empty objects cannot complete a partial list', function ($client) {
        foreach (array('{"unexpected":true}', '{}') as $body) {
            $broken = payload(array()); $broken['body'] = $body;
            $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'next'), $broken);
            check(is_wp_error($client->files('owner', 'slug')), 'Bad envelope became a successful partial list');
        }
    });
    run('malformed rows and non-list files are rejected', function ($client) {
        foreach (array(array('datasetFiles' => 'bad'), array('datasetFiles' => array('key' => array('name' => 'a.csv'))),
                       array('datasetFiles' => array('bad')), array('datasetFiles' => array(array('size' => 1)))) as $data) {
            $GLOBALS['ocl_responses'] = array(payload($data));
            error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_bad_file_list');
        }
    });
    run('upstream errorMessage is not an empty success or echoed', function ($client) {
        $GLOBALS['ocl_responses'] = array(payload(array('datasetFiles' => array(), 'errorMessage' => 'PRIVATE_UPSTREAM_DETAIL')));
        $result = $client->files('owner', 'slug');
        error_code($result, 'oj_code_lab_kaggle_file_list_error');
        check(!str_contains($result->get_error_message(), 'PRIVATE_UPSTREAM_DETAIL'), 'Upstream detail leaked');
    });
    run('invalid token types lengths and control characters are rejected', function ($client) {
        foreach (array(123, false, array('token'), "bad\nvalue", ' ', str_repeat('a', 4097)) as $token) {
            $GLOBALS['ocl_responses'] = array(payload(array('datasetFiles' => array(), 'nextPageToken' => $token)));
            error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_page_token');
        }
    });
    run('null token denotes a completed page', function ($client) {
        $GLOBALS['ocl_responses'] = array(payload(array('datasetFiles' => array(), 'nextPageToken' => null)));
        check($client->files('owner', 'slug') === array(), 'Optional null token rejected');
    });
    run('repeated token stops without returning partial files', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'same'), page(array('second.csv'), 'same'));
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_page_loop');
        check(count($GLOBALS['ocl_requests']) === 2, 'Repeated token was followed');
    });
    run('nonconsecutive token cycle is rejected', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('a.csv'), 'a'), page(array('b.csv'), 'b'), page(array('c.csv'), 'a'));
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_page_loop');
        check(count($GLOBALS['ocl_requests']) === 3, 'Token cycle was followed');
    });
    run('page cap discards all results', function ($client) {
        for ($i = 1; $i <= 10; $i++) { $GLOBALS['ocl_responses'][] = page(array('file' . $i . '.csv'), 'token' . $i); }
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_file_list_limit');
        check(count($GLOBALS['ocl_requests']) === 10, 'More than ten pages requested');
    });
    run('1001 files exceed total cap', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array_fill(0, 600, 'a.csv'), 'next'), page(array_fill(0, 401, 'b.csv')));
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_file_list_limit');
    });
    run('exact 1000 files and ten completed pages are allowed', function ($client) {
        for ($i = 1; $i <= 10; $i++) { $GLOBALS['ocl_responses'][] = page(array_fill(0, 100, 'file' . $i . '.csv'), $i < 10 ? 'token' . $i : null); }
        check(count($client->files('owner', 'slug')) === 1000, 'Exact documented limit rejected');
    });
    run('deadline expires without requesting a later page', function ($client) {
        $GLOBALS['ocl_responses'] = array(function () { $GLOBALS['ocl_clock'] += 20.0; return page(array('first.csv'), 'next'); });
        $result = $client->files('owner', 'slug');
        error_code($result, 'oj_code_lab_kaggle_file_list_timeout');
        check($result->get_error_data()['status'] === 504 && count($GLOBALS['ocl_requests']) === 1, 'Deadline not enforced');
    });
    run('fractional remaining budget is not rounded up', function ($client) {
        $GLOBALS['ocl_responses'] = array(function () { $GLOBALS['ocl_clock'] += 19.75; return page(array('first.csv'), 'next'); }, page(array('second.csv')));
        check(count($client->files('owner', 'slug')) === 2, 'Fast second page failed');
        check($GLOBALS['ocl_requests'][1]['args']['timeout'] === 0.25, 'Remaining budget was expanded');
    });
    run('redirects consume the same overall deadline', function ($client) {
        $GLOBALS['ocl_responses'] = array(
            function () { $GLOBALS['ocl_clock'] += 15.0; return array('response' => array('code' => 302), 'headers' => array('location' => 'https://www.kaggle.com/next'), 'body' => ''); },
            function () { $GLOBALS['ocl_clock'] += 5.0; return page(array('first.csv')); }
        );
        error_code($client->files('owner', 'slug'), 'oj_code_lab_kaggle_file_list_timeout');
        check($GLOBALS['ocl_requests'][1]['args']['timeout'] === 5.0, 'Redirect reset timeout');
    });
    run('cross-origin redirects still strip credentials', function ($client) {
        $GLOBALS['ocl_responses'] = array(
            array('response' => array('code' => 302), 'headers' => array('location' => 'https://storage.googleapis.com/fixture'), 'body' => ''),
            page(array('first.csv'))
        );
        check(count($client->files('owner', 'slug')) === 1, 'Redirect failed');
        check(isset($GLOBALS['ocl_requests'][0]['args']['headers']['Authorization'])
              && !isset($GLOBALS['ocl_requests'][1]['args']['headers']['Authorization']), 'Authorization forwarded cross-origin');
    });
    run('same-origin paging preserves authentication and body cap', function ($client) {
        $GLOBALS['ocl_responses'] = array(page(array('first.csv'), 'next'), page(array('second.csv')));
        $client->files('owner', 'slug');
        foreach ($GLOBALS['ocl_requests'] as $request) {
            check($request['args']['headers']['Authorization'] === 'Basic ' . base64_encode('fixture-user:fixture-key'), 'Authentication changed');
            check($request['args']['limit_response_size'] === 2_000_001 && $request['args']['redirection'] === 0, 'Safe response bounds changed');
            check($request['args']['timeout'] <= 20.0, 'Request exceeded overall deadline');
        }
    });
    echo $GLOBALS['ocl_passed'] . ' Kaggle pagination scenarios passed.' . PHP_EOL;
}

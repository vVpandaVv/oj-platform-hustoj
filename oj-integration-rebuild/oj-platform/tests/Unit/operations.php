<?php

declare(strict_types=1);

namespace {

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\SystemStatusRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console\SystemStatusPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\SeparatedAiEndpointPolicy;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeReadinessReport;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\SmtpSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProof;
use CodingPanda\OjPlatform\Modules\Settings\Contract\HostAddressResolver;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SmtpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SmtpSettings;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressAiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressCandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSmtpConfigurator;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);
require_once $pluginRoot . '/tests/Support/wordpress-stubs.php';

$operationsTransients = [];
$operationsScheduledEvents = [];
$operationsRemoteRequests = [];
$operationsRemoteFailure = false;
$operationsPinnedOptions = [];
$operationsSkipPin = false;
$operationsProxy = null;
$operationsPinFailure = false;

if (! function_exists('remove_action')) {
    function remove_action(string $hook, callable $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['oj_platform_test_actions'][$hook] ?? [] as $key => $entry) {
            if ($entry['callback'] === $callback && $entry['priority'] === $priority) {
                unset($GLOBALS['oj_platform_test_actions'][$hook][$key]);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['oj_platform_test_filters'][$hook] ?? [] as $key => $entry) {
            if ($entry['callback'] === $callback && $entry['priority'] === $priority) {
                unset($GLOBALS['oj_platform_test_filters'][$hook][$key]);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(
        string $key,
        mixed $value,
        int $expiration
    ): bool {
        global $operationsTransients;
        $operationsTransients[$key] = [
            'value' => $value,
            'expiration' => $expiration,
        ];

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        global $operationsTransients;

        return $operationsTransients[$key]['value'] ?? false;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        global $operationsTransients;
        $existed = array_key_exists($key, $operationsTransients);
        unset($operationsTransients[$key]);

        return $existed;
    }
}

if (! function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(
        int $timestamp,
        string $hook,
        array $args = []
    ): bool {
        global $operationsScheduledEvents;
        $operationsScheduledEvents[] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        global $operationsScheduledEvents;
        unset($args);
        $timestamps = [];
        foreach ($operationsScheduledEvents as $event) {
            if ($event['hook'] === $hook) {
                $timestamps[] = $event['timestamp'];
            }
        }

        return $timestamps === [] ? false : min($timestamps);
    }
}

if (! function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): array
    {
        global $operationsRemoteRequests, $operationsRemoteFailure, $operationsSkipPin, $operationsProxy;
        // Model WP_Http's argument mapping, then Requests' default options and hook.
        $options = [
            'connect_timeout' => 10,
            'timeout' => $args['timeout'] ?? 5,
            'useragent' => $args['user-agent'] ?? 'WordPress',
            'hooks' => new class {
                public array $callbacks = [];
                public function register(string $name, callable $callback): void { $this->callbacks[$name][] = $callback; }
            },
            'proxy' => $operationsProxy,
        ];
        $headers = $args['headers'] ?? [];
        $body = $args['body'] ?? null;
        $method = $args['method'] ?? 'GET';
        $unrelated = [];
        foreach ($GLOBALS['oj_platform_test_actions']['requests-requests.before_request'] ?? [] as $entry) {
            foreach (['url', 'method', 'useragent', 'request_id'] as $difference) {
                $otherOptions = $options;
                $otherUrl = $difference === 'url' ? 'https://other.example.com/' : $url;
                $otherMethod = $difference === 'method' ? 'DELETE' : $method;
                $otherHeaders = $headers;
                if ($difference === 'useragent') { $otherOptions['useragent'] = 'Another-Plugin'; }
                if ($difference === 'request_id') { $otherHeaders['X-OJ-Request-Id'] = 'another-request'; }
                ($entry['callback'])($otherUrl, $otherHeaders, $body, $otherMethod, $otherOptions);
                $unrelated[$difference] = $otherOptions['connect_timeout'];
            }
            ($entry['callback'])($url, $headers, $body, $method, $options);
        }
        $operationsRemoteRequests[] = compact('url', 'args', 'options', 'unrelated');
        if ($operationsRemoteFailure) {
            throw new RuntimeException('Simulated HTTP transport failure.');
        }
        if (! $operationsSkipPin) {
            foreach ($options['hooks']->callbacks['curl.before_send'] ?? [] as $callback) {
                $callback(new stdClass());
            }
        }

        return [
            'response' => ['code' => 200],
            'headers' => ['content-type' => 'application/json'],
            'body' => '{}',
        ];
    }
}

$operationsAssertions = 0;

function operationsAssert(bool $condition, string $message): void
{
    global $operationsAssertions;
    ++$operationsAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function operationsSame(mixed $expected, mixed $actual, string $message): void
{
    operationsAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function operationsThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        operationsAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class OperationsMemorySettings implements SettingsManager
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var array<string, int>
     */
    private array $revisions = [];

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(
            $this->revisions[$key] ?? 0,
            array_key_exists($key, $this->values)
                ? $this->values[$key]
                : $default
        );
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        $currentRevision = $this->revisions[$key] ?? 0;
        if ($currentRevision !== $expectedRevision) {
            throw new DomainException('설정 revision이 일치하지 않습니다.');
        }
        $this->revisions[$key] = $currentRevision + 1;
        $this->values[$key] = $value;

        return new SettingsSnapshot($this->revisions[$key], $value);
    }

    public function seed(string $key, mixed $value, int $revision = 1): void
    {
        $this->values[$key] = $value;
        $this->revisions[$key] = $revision;
    }
}

final class OperationsMemoryVault implements SecretVault
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    private int $sequence = 0;

    public function store(mixed $value): SecretReference
    {
        ++$this->sequence;
        $reference = new SecretReference(sprintf(
            'secret:v2:00000000-0000-4000-8000-%012d',
            $this->sequence
        ));
        $this->values[$reference->value()] = $value;

        return $reference;
    }

    public function reveal(SecretReference $reference): mixed
    {
        if (! array_key_exists($reference->value(), $this->values)) {
            throw new RuntimeException('비밀값이 없습니다.');
        }

        return $this->values[$reference->value()];
    }

    public function discard(SecretReference $reference): void
    {
        unset($this->values[$reference->value()]);
    }

    public function has(SecretReference $reference): bool
    {
        return array_key_exists($reference->value(), $this->values);
    }
}

final class OperationsHostResolver implements HostAddressResolver
{
    /**
     * @param array<string, list<string>> $addresses
     */
    public function __construct(public array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        return $this->addresses[strtolower($host)] ?? [];
    }
}

final class OperationsJudgeSettings implements JudgeConnectionSettingsProvider
{
    public function current(): ?JudgeConnectionSettings
    {
        return null;
    }
}

final class OperationsJudgeProbe implements JudgeConnectionProbe
{
    public function __construct(
        private int $extensionReports = 0
    ) {
    }

    public function inspect(
        JudgeConnectionSettings $settings
    ): JudgeReadinessReport {
        unset($settings);
        if ($this->extensionReports > 0) {
            --$this->extensionReports;

            return new JudgeReadinessReport(
                [],
                [],
                ['problem' => ['bann', 'body', 'front', 'langmask', 'rear']],
                [],
                [],
                [],
                ['problem' => 'InnoDB'],
                []
            );
        }

        return new JudgeReadinessReport([], [], [], [], [], [], [], []);
    }
}

final class OperationsJudgeExtensions implements JudgeSchemaExtensionInstaller
{
    public int $provisionCalls = 0;

    public function canProvision(JudgeReadinessReport $report): bool
    {
        return array_keys($report->missingRequiredColumns()) === ['problem'];
    }

    public function pendingColumns(JudgeReadinessReport $report): array
    {
        return $report->missingRequiredColumns()['problem'] ?? [];
    }

    public function provision(JudgeConnectionSettings $settings): array
    {
        unset($settings);
        ++$this->provisionCalls;

        return ['bann', 'body', 'front', 'langmask', 'rear'];
    }
}

final class OperationsUnusedJudgeConnector implements JudgeConnector
{
    public function read(JudgeConnectionSettings $settings): \mysqli
    {
        unset($settings);
        throw new RuntimeException('이 테스트에서는 연결하지 않습니다.');
    }

    public function write(JudgeConnectionSettings $settings): \mysqli
    {
        unset($settings);
        throw new RuntimeException('이 테스트에서는 연결하지 않습니다.');
    }
}

final class OperationsSmtpTransport implements SmtpTransport
{
    public function sendTest(
        SmtpSettings $settings,
        string $recipient,
        string $subject,
        string $message
    ): bool {
        unset($settings, $recipient, $subject, $message);

        return true;
    }
}

final class OperationsMailer
{
    public bool $smtp = false;
    public string $Host = '';
    public int $Port = 0;
    public bool $SMTPAuth = false;
    public string $Username = '';
    public string $Password = '';
    public string $SMTPSecure = '';
    public bool $SMTPAutoTLS = false;
    public int $Timeout = 0;
    public array $SMTPOptions = [];
    public string $fromAddress = '';
    public string $fromName = '';

    public function isSMTP(): void
    {
        $this->smtp = true;
    }

    public function setFrom(
        string $address,
        string $name = '',
        bool $auto = true
    ): bool {
        unset($auto);
        $this->fromAddress = $address;
        $this->fromName = $name;

        return true;
    }
}

$catalog = new AiProviderCatalog();
operationsSame(7, count($catalog->all()), 'AI Provider는 확정된 7종이어야 합니다.');
operationsSame(
    'gemma4:latest',
    $catalog->get('ollama')->defaultModel(),
    'Ollama 기본 모델 예시는 gemma4:latest여야 합니다.'
);
operationsSame(
    ['x_api_key'],
    $catalog->get('gemini')->authenticationMethods(),
    'Gemini 인증 방식은 X-API-Key로 제한해야 합니다.'
);
operationsSame(
    5,
    count(AiProviderCatalog::TASK_TYPES),
    '합성 프롬프트 검증을 포함한 AI 작업 유형은 5종이어야 합니다.'
);

$resolver = new OperationsHostResolver([
    'ai.example.com' => ['203.0.113.10'],
    'judge.example.com' => ['203.0.113.20'],
    'api.openai.com' => ['203.0.113.30'],
]);
$endpointPolicy = new SeparatedAiEndpointPolicy(
    $resolver,
    new OperationsJudgeSettings(),
    ['judge.example.com']
);
$validated = $endpointPolicy->validate(
    $catalog->get('ollama'),
    'https://ai.example.com/'
);
operationsSame(
    'https://ai.example.com',
    $validated->normalizedBaseUrl(),
    '자체 AI 서버 주소를 정규화해야 합니다.'
);
operationsSame(
    ['203.0.113.10'],
    $validated->resolvedIps(),
    '검사한 AI 서버 IP를 fingerprint 입력으로 유지해야 합니다.'
);
operationsThrows(
    static fn () => $endpointPolicy->validate(
        $catalog->get('openai'),
        'https://example.com'
    ),
    'OpenAI는 공식 Base URL 이외의 주소를 거부해야 합니다.'
);
operationsThrows(
    static fn () => $endpointPolicy->validate(
        $catalog->get('ollama'),
        'http://ai.example.com'
    ),
    'AI 연결은 HTTPS가 아니면 거부해야 합니다.'
);
$resolver->addresses['judge.example.com'] = ['203.0.113.10'];
operationsThrows(
    static fn () => $endpointPolicy->validate(
        $catalog->get('ollama'),
        'https://ai.example.com'
    ),
    'AI 서버와 Judge 서버가 같은 IP이면 거부해야 합니다.'
);

foreach (['::1', '0:0:0:0:0:0:0:1', '0:0:0:0:0:0:0:0', '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:169.254.169.254', '::ffff:a9fe:a9fe', '0.1.2.3'] as $forbidden) {
    $resolver->addresses['ai.example.com'] = [$forbidden];
    operationsThrows(
        static fn () => $endpointPolicy->validate($catalog->get('ollama'), 'https://ai.example.com'),
        '축약·확장·IPv4 매핑 주소로 금지된 AI 네트워크 검사를 우회하면 안 됩니다: ' . $forbidden
    );
}
$resolver->addresses['ai.example.com'] = ['2001:db8::1'];
$resolver->addresses['judge.example.com'] = ['2001:0db8:0:0:0:0:0:1'];
operationsThrows(static fn () => $endpointPolicy->validate($catalog->get('ollama'), 'https://ai.example.com'), '같은 IPv6의 다른 표기로 Judge 주소 분리를 우회하면 안 됩니다.');
$resolver->addresses['ai.example.com'] = ['::ffff:203.0.113.20'];
$resolver->addresses['judge.example.com'] = ['203.0.113.20'];
operationsThrows(static fn () => $endpointPolicy->validate($catalog->get('ollama'), 'https://ai.example.com'), 'IPv4 매핑 IPv6도 Judge IPv4와 같은 주소여야 합니다.');
$resolver->addresses['ai.example.com'] = ['203.0.113.10'];
$resolver->addresses['judge.example.com'] = ['203.0.113.20'];

$aiTransport = new WordPressAiHttpTransport($resolver);
$response = $aiTransport->request(
    'POST',
    'https://ai.example.com/api/chat',
    ['Accept' => 'application/json'],
    '{}',
    3,
    30,
    ['203.0.113.10']
);
operationsSame(200, $response->statusCode(), '검증된 AI 주소 요청을 전송해야 합니다.');
operationsSame('\\WpOrg\\Requests\\Transport\\Curl', $operationsRemoteRequests[0]['options']['transport'], 'DNS를 고정할 수 있는 AI 전송만 선택해야 합니다.');
operationsSame(['ai.example.com:443:203.0.113.10'], $operationsPinnedOptions[0][CURLOPT_RESOLVE], '연결 시 DNS를 다시 조회하지 않고 검사한 주소에 고정해야 합니다.');
operationsSame('', $operationsPinnedOptions[0][CURLOPT_PROXY], '프록시의 DNS 재조회로 주소 고정을 우회하면 안 됩니다.');
operationsSame(true, $operationsPinnedOptions[0][CURLOPT_SSL_VERIFYPEER], '주소를 고정해도 TLS 인증서 검증을 유지해야 합니다.');
operationsSame(2, $operationsPinnedOptions[0][CURLOPT_SSL_VERIFYHOST], '주소를 고정해도 TLS 호스트 검증을 유지해야 합니다.');
operationsSame('https://ai.example.com/api/chat', $operationsRemoteRequests[0]['url'], 'TLS 검증·SNI에 사용할 원래 호스트를 보존해야 합니다.');
operationsSame(
    3,
    $operationsRemoteRequests[0]['options']['connect_timeout'] ?? null,
    'AI 연결 제한은 WordPress 인수가 아닌 실제 Requests 옵션에 적용되어야 합니다.'
);
operationsSame(30, $operationsRemoteRequests[0]['options']['timeout'], 'AI 생성 응답 제한은 변경하면 안 됩니다.');
operationsSame(
    ['url' => 10, 'method' => 10, 'useragent' => 10, 'request_id' => 10],
    $operationsRemoteRequests[0]['unrelated'],
    '다른 HTTP 요청의 연결 제한은 변경하면 안 됩니다.'
);
operationsSame([], array_values($GLOBALS['oj_platform_test_actions']['requests-requests.before_request'] ?? []), '완료 후 연결 제한 Hook을 해제해야 합니다.');
operationsSame([], array_values($GLOBALS['oj_platform_test_filters']['http_request_host_is_external'] ?? []), '완료 후 호스트 승인 Hook을 해제해야 합니다.');
operationsAssert(
    ($operationsRemoteRequests[0]['args']['redirection'] ?? null) === 0
        && ($operationsRemoteRequests[0]['args']['sslverify'] ?? null) === true
        && ($operationsRemoteRequests[0]['args']['reject_unsafe_urls'] ?? null) === true,
    'AI 전송은 리디렉션을 막고 TLS와 WordPress URL 검증을 유지해야 합니다.'
);
$requestCount = count($operationsRemoteRequests);
$resolver->addresses['ai.example.com'] = ['203.0.113.11'];
operationsThrows(
    static fn () => $aiTransport->request(
        'GET',
        'https://ai.example.com/api/tags',
        [],
        null,
        3,
        30,
        ['203.0.113.10']
    ),
    '요청 직전 DNS가 후보 검사 IP와 달라지면 AI 전송을 거부해야 합니다.'
);
operationsSame(
    $requestCount,
    count($operationsRemoteRequests),
    'DNS가 바뀐 AI 요청은 WordPress HTTP 계층에 전달하면 안 됩니다.'
);
$resolver->addresses['ai.example.com'] = ['203.0.113.10'];

$operationsRemoteFailure = true;
operationsThrows(
    static fn () => $aiTransport->request('POST', 'https://ai.example.com/api/chat', [], '{}', 3, 60, ['203.0.113.10']),
    '전송 예외를 숨기면 안 됩니다.'
);
$operationsRemoteFailure = false;
operationsSame([], array_values($GLOBALS['oj_platform_test_actions']['requests-requests.before_request'] ?? []), '실패 후에도 연결 제한 Hook을 해제해야 합니다.');
operationsSame([], array_values($GLOBALS['oj_platform_test_filters']['http_request_host_is_external'] ?? []), '실패 후에도 호스트 승인 Hook을 해제해야 합니다.');
$aiTransport->request('GET', 'https://ai.example.com/api/tags', [], null, 3, 30, ['203.0.113.10']);
operationsSame(3, $operationsRemoteRequests[array_key_last($operationsRemoteRequests)]['options']['connect_timeout'], '모델 목록 조회에도 연결 제한을 적용해야 합니다.');

$operationsSkipPin = true;
operationsThrows(static fn () => $aiTransport->request('GET', 'https://ai.example.com/api/tags', [], null, 3, 30, ['203.0.113.10']), '주소 고정을 수행하지 않은 HTTP 응답은 사용하면 안 됩니다.');
$operationsSkipPin = false;
$operationsPinFailure = true;
operationsThrows(static fn () => $aiTransport->request('GET', 'https://ai.example.com/api/tags', [], null, 3, 30, ['203.0.113.10']), 'cURL 주소 고정 설정이 실패하면 요청을 계속하면 안 됩니다.');
$operationsPinFailure = false;
operationsSame([], array_values($GLOBALS['oj_platform_test_actions']['requests-requests.before_request'] ?? []), '주소 고정 실패 후에도 HTTP Hook을 해제해야 합니다.');
$operationsProxy = new stdClass();
operationsThrows(static fn () => $aiTransport->request('GET', 'https://ai.example.com/api/tags', [], null, 3, 30, ['203.0.113.10']), '주소 고정을 보장할 수 없는 프록시 연결은 거부해야 합니다.');
$operationsProxy = null;
$resolver->addresses['ai.example.com'] = ['0:0:0:0:0:ffff:7f00:1'];
operationsThrows(static fn () => $aiTransport->request('GET', 'https://ai.example.com/api/tags', [], null, 3, 30, ['::ffff:127.0.0.1']), '저장된 프로필도 전송 시 금지 주소를 다시 확인해야 합니다.');
$resolver->addresses['ai.example.com'] = ['2001:db8::2', '2001:db8::3'];
$aiTransport->request('GET', 'https://ai.example.com:8443/api/tags', [], null, 3, 30, ['2001:0db8:0:0:0:0:0:2', '2001:db8::3']);
operationsSame(['ai.example.com:8443:[2001:db8::2],[2001:db8::3]'], $operationsPinnedOptions[array_key_last($operationsPinnedOptions)][CURLOPT_RESOLVE], 'IPv6와 명시적 포트도 확인한 주소 집합에 고정해야 합니다.');
$resolver->addresses['2001:db8::4'] = ['2001:db8::4'];
$aiTransport->request('GET', 'https://[2001:db8::4]/api/tags', [], null, 3, 30, ['2001:db8::4']);
operationsSame([], $operationsPinnedOptions[array_key_last($operationsPinnedOptions)][CURLOPT_RESOLVE], 'IP 리터럴 URL은 DNS 조회가 없으며 잘못된 cURL 호스트 문자열을 만들면 안 됩니다.');
$resolver->addresses['ai.example.com'] = ['203.0.113.10'];

$settings = new OperationsMemorySettings();
$system = new SystemSettingsService($settings);
$savedSystem = $system->save(
    true,
    '점검 중입니다.',
    '2026-08-01T09:00',
    ['problems'],
    0
);

operationsSame(1, $savedSystem->revision(), '운영 설정 revision을 증가시켜야 합니다.');
operationsAssert($system->maintenanceEnabled(), '점검 모드를 저장해야 합니다.');
operationsAssert(
    ! $system->allowsAction('problems', 'oj_problem_save'),
    '동결된 Problems 쓰기 명령을 차단해야 합니다.'
);
operationsAssert(
    $system->allowsAction('settings', 'oj_save_system_settings'),
    '안전 복구용 시스템 설정 명령은 동결 중에도 허용해야 합니다.'
);
operationsAssert(
    ! $system->allowsJob('problems', 'problem_metadata_generate'),
    '동결된 Problems Job을 차단해야 합니다.'
);
operationsAssert(
    $system->allowsJob('migration', 'platform_migration'),
    '승인된 Migration Job은 동결 제어를 수행할 수 있어야 합니다.'
);
$approval = $system->issueUninstallApproval(
    '학습용 채점 서비스',
    '학습용 채점 서비스',
    'backup-proof',
    'restore-proof',
    7,
    1
);
operationsAssert(
    $system->current()->verifiesUninstallApproval(
        $approval['token'],
        7,
        time()
    ),
    '제거 승인은 사용자와 30분 만료를 묶은 일회성 토큰이어야 합니다.'
);
operationsThrows(
    static fn () => $system->issueUninstallApproval(
        '다른 서비스',
        '학습용 채점 서비스',
        'backup-proof',
        'restore-proof',
        7,
        2
    ),
    '서비스 이름이 다르면 제거 승인을 발급하지 않아야 합니다.'
);

$vault = new OperationsMemoryVault();
$proofStore = new WordPressCandidateProofStore($vault);
$expiredReference = $vault->store(['candidate' => 'expired']);
$proofStore->remember(
    'ai_provider.test',
    new CandidateProof(
        str_repeat('a', 64),
        $expiredReference,
        7,
        time() - 1,
        ['result_code' => 'ok']
    )
);
$proofStore->cleanupExpired();
operationsAssert(
    ! $vault->has($expiredReference),
    '만료된 후보 비밀값을 Vault에서 삭제해야 합니다.'
);
$activeReference = $vault->store(['candidate' => 'active']);
$activeProof = new CandidateProof(
    str_repeat('b', 64),
    $activeReference,
    7,
    time() + 900,
    ['result_code' => 'ok']
);
$proofStore->remember('smtp', $activeProof);
operationsSame(
    $activeProof->fingerprint(),
    $proofStore->find('smtp', 7)?->fingerprint(),
    '유효한 후보 증명을 같은 사용자에게만 반환해야 합니다.'
);
$proofStore->forget('smtp', 7);
operationsAssert(
    ! $vault->has($activeReference),
    '후보 증명을 폐기하면 연결 비밀값도 함께 삭제해야 합니다.'
);
operationsAssert(
    $operationsScheduledEvents !== [],
    '후보 비밀값 만료 정리용 단일 이벤트를 예약해야 합니다.'
);

$futureExpiresAt = time() + 900;
$futureReference = $vault->store(['candidate' => 'future']);
$proofStore->remember(
    'ai_provider.future',
    new CandidateProof(
        str_repeat('c', 64),
        $futureReference,
        7,
        $futureExpiresAt,
        ['result_code' => 'ok']
    )
);
$orphanReference = $vault->store(['candidate' => 'expired-orphan']);
$ledger = get_option('oj_platform_candidate_proof_ledger', []);
$ledger['ojp_candidate_expired_test'] = [
    'payload_reference' => $orphanReference->value(),
    'expires_at' => time() - 1,
];
update_option('oj_platform_candidate_proof_ledger', $ledger, false);
$operationsScheduledEvents = [];
$proofStore->cleanupExpired();
operationsAssert(
    ! $vault->has($orphanReference) && $vault->has($futureReference),
    '만료 후보만 폐기하고 다음 후보 비밀값은 유지해야 합니다.'
);
operationsSame(
    $futureExpiresAt + 1,
    $operationsScheduledEvents[0]['timestamp'] ?? null,
    '만료 이벤트 실행 뒤 다음 후보 만료 정리를 다시 예약해야 합니다.'
);
$proofStore->forget('ai_provider.future', 7);
$extensionPolicy = new MysqliJudgeSchemaExtensionInstaller(
    new OperationsUnusedJudgeConnector()
);
$extensionOnlyReport = new JudgeReadinessReport(
    [],
    [],
    ['problem' => ['bann', 'body', 'front', 'langmask', 'rear']],
    [],
    [],
    [],
    ['problem' => 'InnoDB'],
    []
);
operationsAssert(
    $extensionPolicy->canProvision($extensionOnlyReport),
    '승인된 problem 확장 필드 누락만 설치 가능한 상태로 분류해야 합니다.'
);
operationsSame(
    ['bann', 'body', 'front', 'langmask', 'rear'],
    $extensionPolicy->pendingColumns($extensionOnlyReport),
    '확장 필드 설치 대상을 정렬된 목록으로 반환해야 합니다.'
);
operationsAssert(
    ! $extensionPolicy->canProvision(new JudgeReadinessReport(
        [],
        [],
        ['problem' => ['title']],
        [],
        [],
        [],
        ['problem' => 'InnoDB'],
        []
    )),
    'HUSTOJ 핵심 열 누락을 확장 필드 설치로 우회하면 안 됩니다.'
);

$judgeCandidate = new JudgeConnectionSettings(
    '127.0.0.1',
    3306,
    'jol',
    new JudgeCredentials('judge', 'judge-secret'),
    new JudgeCredentials('judge', 'judge-secret'),
    false,
    null,
    true
);
$judgeCandidateSettings = new OperationsMemorySettings();
$judgeExtensions = new OperationsJudgeExtensions();
$judgeCommands = new JudgeConnectionCommandService(
    $judgeCandidateSettings,
    $vault,
    $proofStore,
    new OperationsJudgeProbe(2),
    new OperationsJudgeSettings(),
    $judgeExtensions
);
$judgeProof = $judgeCommands->testCandidate($judgeCandidate, 7, 0);
operationsSame('utc-v1', $judgeProof->safeResult()['timestamp_contract'] ?? null, '후보 증명에 UTC 저장 계약을 기록해야 합니다.');
operationsSame(7, $judgeProof->safeResult()['timestamp_confirmed_by'] ?? null, '시간 기준 확인자를 기록해야 합니다.');
operationsSame(
    ['bann', 'body', 'front', 'langmask', 'rear'],
    $judgeProof->safeResult()['pending_extension_columns'] ?? null,
    '표준 HUSTOJ에 없는 problem 확장 필드를 적용 대기로 기록해야 합니다.'
);
$judgeCandidateReference = $judgeProof->payloadReference();
$judgeCommands->applyTestedCandidate(7, 0);
operationsSame(
    1,
    $judgeExtensions->provisionCalls,
    '검사한 Judge 연결을 적용할 때 확장 필드 설치를 한 번 실행해야 합니다.'
);
operationsAssert(
    $proofStore->find('judge_database', 7) === null
        && $vault->has($judgeCandidateReference),
    'Judge DB 후보 증명만 소비하고 활성 연결 비밀값은 유지해야 합니다.'
);
$activeJudgeReference = $judgeCandidateSettings->read('judge_database')->value();
operationsAssert(
    $activeJudgeReference instanceof SecretReference,
    '검사한 Judge DB 후보 참조를 활성 설정으로 이전해야 합니다.'
);
$activeJudgePayload = $activeJudgeReference instanceof SecretReference
    ? $vault->reveal($activeJudgeReference)
    : null;
operationsSame(
    'jol',
    is_array($activeJudgePayload) ? ($activeJudgePayload['database'] ?? null) : null,
    '후보 증명을 소비한 뒤에도 활성 Judge DB 설정을 읽을 수 있어야 합니다.'
);

$smtpSettings = new SmtpSettings(
    true,
    'smtp.example.com',
    587,
    'starttls',
    true,
    'mailer',
    'secret',
    'noreply@example.com',
    '학습용 채점 서비스'
);
$smtpService = new SmtpSettingsService(
    $settings,
    $vault,
    $proofStore,
    new OperationsSmtpTransport()
);
$smtpProof = $smtpService->testCandidate(
    $smtpSettings,
    'teacher@example.com',
    7
);
$smtpCandidateReference = $smtpProof->payloadReference();
$smtpService->applyTestedCandidate(7, 0);
operationsAssert(
    $proofStore->find('smtp', 7) === null
        && $vault->has($smtpCandidateReference),
    'SMTP 후보 증명만 소비하고 활성 설정이 인수한 비밀값은 유지해야 합니다.'
);
operationsSame(
    $smtpSettings->fingerprint(),
    $smtpService->current()?->fingerprint(),
    '후보 증명을 소비해도 활성 SMTP 비밀값은 계속 읽을 수 있어야 합니다.'
);
$mailer = new OperationsMailer();
(new WordPressSmtpConfigurator($smtpService))->configure($mailer);
operationsAssert($mailer->smtp, '저장된 SMTP 설정이 활성 상태면 SMTP를 사용해야 합니다.');
operationsSame('smtp.example.com', $mailer->Host, 'SMTP 호스트를 적용해야 합니다.');
operationsSame('tls', $mailer->SMTPSecure, 'STARTTLS를 PHPMailer tls 값으로 변환해야 합니다.');
operationsSame(
    'noreply@example.com',
    $mailer->fromAddress,
    '발신 주소를 운영 설정에서 적용해야 합니다.'
);
operationsAssert(
    ($mailer->SMTPOptions['ssl']['verify_peer'] ?? false) === true,
    'SMTP TLS 인증서 검증을 끌 수 없어야 합니다.'
);

$failingStatusSettings = new class () implements SettingsManager {
    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($key, $default);
        throw new RuntimeException('상태 설정을 읽을 수 없습니다.');
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key, $value, $expectedRevision);
        throw new RuntimeException('상태 설정을 저장할 수 없습니다.');
    }
};
$statusRegistry = new SystemStatusRegistry([
    new class () implements SystemStatusProvider {
        public function key(): string
        {
            return 'last_fatal';
        }

        public function current(): SystemStatusItem
        {
            throw new RuntimeException('마지막 오류 상태 수집 실패');
        }
    },
]);
$statusPages = new class () implements ConsoleResponder {
    public function respond(
        string $title,
        string $content,
        string $currentMenu,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        unset($title, $currentMenu, $request);

        return HttpResponse::html($content, $status);
    }
};
$statusAccounts = new class () implements CurrentAccountProvider {
    public function current(): CurrentAccount
    {
        return new CurrentAccount(
            new UserIdentity(1, 'admin', '', '관리자', ['administrator']),
            null
        );
    }
};
$statusPermissions = new class () implements Authorization {
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($context);

        return $userId === 1 && $capability === 'oj_manage_system';
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId === 1;
    }
};
$statusActionUrls = new class () implements ActionUrlProvider {
    public function actionUrl(): string
    {
        return '/oj-action';
    }

    public function ajaxUrl(): string
    {
        return '/oj-ajax';
    }
};
$statusSecurity = new class () implements ActionSecurity {
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="' . $actionKey . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        unset($actionKey, $request);

        return true;
    }
};
$statusHandler = new SystemStatusPageHandler(
    $statusPages,
    $statusRegistry,
    $failingStatusSettings,
    $statusAccounts,
    $statusPermissions,
    $statusActionUrls,
    $statusSecurity
);
$statusRoute = new RouteMatch(new RouteDefinition(
    'console.system_status',
    new ModuleId('settings'),
    'settings',
    '/oj-console/system-status',
    RouteArea::Console,
    ['GET'],
    AuthenticationRequirement::Authenticated,
    'html'
), []);
$statusResponse = $statusHandler->handle(
    $statusRoute,
    new HttpRequest('GET', '/oj-console/system-status')
);
operationsSame(200, $statusResponse->status(), '상태 수집 실패가 페이지 전체를 중단하면 안 됩니다.');
operationsAssert(
    str_contains($statusResponse->body(), '상태 정보를 안전하게 수집하지 못했습니다.')
        && ! str_contains($statusResponse->body(), '마지막 오류 표시 지우기'),
    '읽을 수 없는 마지막 오류는 안전한 상태 카드로 표시하고 지우기 버튼을 생략해야 합니다.'
);

$lastFatalConfigLoader = new \CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader(
    dirname(__DIR__, 2) . '/config'
);
$lastFatalSettingRow = null;
foreach ($lastFatalConfigLoader->load('settings') as $row) {
    if (($row['key'] ?? '') === 'last_fatal') {
        $lastFatalSettingRow = $row;
        break;
    }
}
operationsAssert(
    $lastFatalSettingRow !== null,
    'last_fatal 설정 정의를 config/settings.php에서 찾아야 합니다.'
);
operationsAssert(
    ($lastFatalSettingRow['sensitive'] ?? true) === false,
    'last_fatal은 추적 ID·오류 클래스 같은 진단 정보일 뿐이라 민감 설정으로 표시하면 안 됩니다 — '
        . '민감 처리하면 배열 값을 SecretReference로 감싸다 형식 검증에서 항상 예외가 나서 '
        . '상태 화면이 실제 오류 대신 안전 실패 카드로 대체되고 지우기 버튼도 영원히 사라집니다.'
);

$lastFatalSettingDefinitions = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry(
    'last-fatal-test-settings',
    \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition::class
);
$lastFatalSettingDefinitions->register(
    \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition::fromArray($lastFatalSettingRow)
);
$lastFatalStore = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore {
    /** @var array<string, mixed> */
    public array $values = [];

    public function read(string $optionName, mixed $default = null): mixed
    {
        return $this->values[$optionName] ?? $default;
    }

    public function write(string $optionName, mixed $value): bool
    {
        $this->values[$optionName] = $value;

        return true;
    }
};
$lastFatalSettingsService = new \CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService(
    $lastFatalSettingDefinitions,
    $lastFatalStore
);
$lastFatalDelegateErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public function report(\Throwable $error): void
    {
        unset($error);
    }
};
$lastFatalTrackingLogger = new class implements \CodingPanda\OjPlatform\Kernel\Error\TrackingLogger {
    public function log(string $trackingId, string $errorClass): void
    {
        unset($trackingId, $errorClass);
    }
};
(new \CodingPanda\OjPlatform\Kernel\Integration\Operations\RecordingErrorReporter(
    $lastFatalDelegateErrors,
    $lastFatalStore,
    $lastFatalTrackingLogger
))->report(new RuntimeException('실제 운영 오류 재현'));

$lastFatalProvider = new \CodingPanda\OjPlatform\Kernel\Integration\Operations\LastFatalSystemStatusProvider(
    $lastFatalSettingsService
);
$lastFatalItem = $lastFatalProvider->current();
operationsAssert(
    str_contains($lastFatalItem->summary(), '현재 요청의 재발 여부와는 별개'),
    '저장된 과거 오류와 현재 요청의 재발 여부를 구분해 안내해야 합니다.'
);
operationsAssert(
    str_contains($lastFatalItem->nextAction(), '오류 원인이 해결되지는 않습니다'),
    '오류 표시 초기화를 원인 해결로 안내해서는 안 됩니다.'
);
operationsSame('last_fatal', $lastFatalItem->key(), '마지막 오류 Provider 키가 달라졌습니다.');
operationsSame(
    'danger',
    $lastFatalItem->tone(),
    'sensitive 설정이 false일 때는 실제로 기록된 오류를 예외 없이 조치 필요 상태로 표시해야 합니다.'
);
operationsAssert(
    ($lastFatalItem->technicalDetails()['추적 ID'] ?? '') !== '',
    'sensitive 설정이 false일 때는 예외 없이 실제 추적 ID를 읽어야 합니다.'
);

$lastFatalRegistry = new SystemStatusRegistry([$lastFatalProvider]);
$lastFatalAccounts = new class () implements CurrentAccountProvider {
    public function current(): CurrentAccount
    {
        return new CurrentAccount(
            new UserIdentity(1, 'admin', '', '관리자', ['administrator']),
            null
        );
    }
};
$lastFatalPermissions = new class () implements Authorization {
    public bool $technical = true;

    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($context);

        return $this->technical && $userId === 1 && $capability === 'oj_manage_system';
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
    }
};
$lastFatalHandler = new SystemStatusPageHandler(
    $statusPages,
    $lastFatalRegistry,
    $lastFatalSettingsService,
    $lastFatalAccounts,
    $lastFatalPermissions,
    $statusActionUrls,
    $statusSecurity
);
$lastFatalResponse = $lastFatalHandler->handle(
    $statusRoute,
    new HttpRequest('GET', '/oj-console/system-status')
);
operationsAssert(
    str_contains($lastFatalResponse->body(), '마지막 오류 표시 지우기'),
    'sensitive 설정이 false이고 oj_manage_system 권한이 있으면 마지막 오류 지우기 버튼이 보여야 합니다.'
);
operationsAssert(
    ! str_contains($lastFatalResponse->body(), '상태 정보를 안전하게 수집하지 못했습니다.'),
    'sensitive 설정이 false일 때는 안전 실패 카드 대신 실제 마지막 오류 카드를 보여야 합니다.'
);
operationsAssert(
    str_contains($lastFatalResponse->body(), '직접 원인을 확정할 수 없습니다.')
        && str_contains($lastFatalResponse->body(), '<details open>'),
    '알 수 없는 원인은 단정하지 않고 마지막 오류의 기술 정보는 바로 펼쳐야 합니다.'
);

$diagnosticPost = $_POST;
$_POST = ['action' => 'oj_message_send'];
(new \CodingPanda\OjPlatform\Kernel\Integration\Operations\RecordingErrorReporter(
    $lastFatalDelegateErrors,
    $lastFatalStore,
    $lastFatalTrackingLogger
))->report(new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.'));
$_POST = $diagnosticPost;
$storageItem = $lastFatalProvider->current();
operationsAssert(
    str_contains($storageItem->summary(), '쪽지 발송: 요청 제한 카운터를 저장하지 못했습니다.')
        && str_contains($storageItem->nextAction(), '오브젝트 캐시'),
    '실패한 작업과 저장 장애 점검 대상을 오류 카드에서 바로 확인해야 합니다.'
);
operationsSame('rate_limit_storage_failed', $storageItem->technicalDetails()['오류 코드'] ?? '', '안전한 오류 코드를 표시해야 합니다.');
operationsSame('오류 유형 확인됨', $storageItem->technicalDetails()['원인 분류'] ?? '', '분류된 오류와 미확인 오류를 구분해야 합니다.');
operationsAssert(
    str_contains($storageItem->technicalDetails()['발생 위치'] ?? '', 'operations.php:'),
    '추적 ID 외에 발생 파일과 줄 번호를 표시해야 합니다.'
);
operationsAssert(
    ! isset($storageItem->technicalDetails()['원인 예외 위치'])
        && ! isset($storageItem->technicalDetails()['원인 예외']),
    '동일한 예외와 위치를 기술 정보에 중복 표시하지 않아야 합니다.'
);
$storageResponse = $lastFatalHandler->handle($statusRoute, new HttpRequest('GET', '/oj-console/system-status'));
operationsAssert(
    str_contains($storageResponse->body(), 'rate_limit_storage_failed')
        && str_contains($storageResponse->body(), '발생 위치'),
    '시스템 관리자는 오류 코드와 발생 위치를 화면에서 확인할 수 있어야 합니다.'
);
$lastFatalPermissions->technical = false;
$limitedResponse = $lastFatalHandler->handle($statusRoute, new HttpRequest('GET', '/oj-console/system-status'));
operationsAssert(
    str_contains($limitedResponse->body(), '요청 제한 카운터를 저장하지 못했습니다.')
        && ! str_contains($limitedResponse->body(), 'operations.php:')
        && ! str_contains($limitedResponse->body(), 'rate_limit_storage_failed')
        && ! str_contains($limitedResponse->body(), '마지막 오류 표시 지우기'),
    '일반 상태 조회자는 안전한 설명만 보며 기술 정보와 초기화 기능은 시스템 관리 권한으로 제한해야 합니다.'
);
$lastFatalPermissions->technical = true;
$storedFatal = $lastFatalStore->values['oj_platform_last_fatal'];
$lastFatalStore->values['oj_platform_last_fatal']['value']['diagnostic']['root_cause_class'] = PDOException::class;
$lastFatalStore->values['oj_platform_last_fatal']['value']['diagnostic']['cause_source'] = 'oj-platform/modules/JudgeDb/connection.php:42';
operationsAssert(
    ($lastFatalProvider->current()->technicalDetails()['원인 예외'] ?? '') === PDOException::class
        && ($lastFatalProvider->current()->technicalDetails()['원인 예외 위치'] ?? '') === 'oj-platform/modules/JudgeDb/connection.php:42',
    '중첩된 원인 예외가 다르면 원인 종류와 위치를 별도로 표시해야 합니다.'
);
$lastFatalStore->values['oj_platform_last_fatal'] = $storedFatal;
$lastFatalStore->values['oj_platform_last_fatal']['value']['diagnostic']['source'] = '<img src=x onerror=alert(1)>:12';
$escapedResponse = $lastFatalHandler->handle($statusRoute, new HttpRequest('GET', '/oj-console/system-status'));
operationsAssert(
    ! str_contains($escapedResponse->body(), '<img src=x')
        && str_contains($escapedResponse->body(), '&lt;img src=x'),
    '진단 문자열도 HTML로 실행되지 않도록 이스케이프해야 합니다.'
);
$lastFatalStore->values['oj_platform_last_fatal'] = $storedFatal;
unset($lastFatalStore->values['oj_platform_last_fatal']['value']['diagnostic']);
$legacyItem = $lastFatalProvider->current();
operationsAssert(
    str_contains($legacyItem->summary(), '현재 요청의 재발 여부와는 별개')
        && str_contains($legacyItem->nextAction(), '서버 로그에서 추적 ID를 확인하세요.')
        && ! isset($legacyItem->technicalDetails()['오류 코드']),
    '이전 버전의 기록은 없는 원인을 만들어 내지 않고 기존 추적 안내를 유지해야 합니다.'
);
$lastFatalStore->values['oj_platform_last_fatal']['value']['tracking_id'] = '';
operationsAssert(
    $lastFatalProvider->current()->tone() === 'success'
        && $lastFatalProvider->current()->technicalDetails() === [],
    '오류 기록이 없으면 진단 정보도 노출하지 않아야 합니다.'
);
$lastFatalStore->values['oj_platform_last_fatal'] = $storedFatal;

fwrite(
    STDOUT,
    sprintf(
        "R-10 운영 기반 단위 검사 통과: assertion %d개\n",
        $operationsAssertions
    )
);

}

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress {
    function curl_setopt_array(mixed $handle, array $options): bool
    {
        unset($handle);
        $GLOBALS['operationsPinnedOptions'][] = $options;

        return ! $GLOBALS['operationsPinFailure'];
    }
}

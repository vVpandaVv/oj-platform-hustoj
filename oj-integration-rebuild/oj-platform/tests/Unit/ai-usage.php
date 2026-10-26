<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\PlatformAiUsageStore;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract as Http;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Settings\Application as AI;
use CodingPanda\OjPlatform\Modules\Settings\Contract as Settings;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTokenUsage;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiUsageFilter;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai as Clients;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\AiUsagePageHandler;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

require __DIR__ . '/ai-routing.php';

$assertions = 0;
function usageAssert(bool $ok, string $message): void
{
    ++$GLOBALS['assertions'];
    if (! $ok) { throw new RuntimeException($message); }
}
function usageThrows(callable $work, string $code = ''): void
{
    try { $work(); }
    catch (Throwable $error) {
        usageAssert($code === '' || ($error instanceof Settings\AiProviderException && $error->safeCode() === $code), 'Unexpected exception: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection.');
}
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Seoul'); }

final class UsageClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-09-14 10:00:00', wp_timezone()); }
}
final class UsageStore implements Settings\AiUsageStore
{
    public array $records = [];
    public array $result = [];
    public bool $fail = false;
    public function append(array $record): void
    {
        if ($this->fail) { throw new RuntimeException('private database error'); }
        $this->records[] = $record;
    }
    public function report(AiUsageFilter $filter): array
    {
        if ($this->fail) { throw new RuntimeException('private database error'); }
        return $this->result;
    }
}
final class UsageErrors implements ErrorReporter
{
    public array $messages = [];
    public bool $fail = false;
    public function report(Throwable $error): void
    {
        $this->messages[] = $error->getMessage();
        if ($this->fail) { throw new RuntimeException('diagnostic error'); }
    }
}
final class UsageTransport implements Settings\AiHttpTransport
{
    public array $responses = [];
    public array $requests = [];
    public function request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutSeconds, int $timeoutSeconds, array $expectedResolvedIps): Settings\AiHttpResponse
    {
        $this->requests[] = [$method, $headers];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        return $response;
    }
    public function queue(array $body, int $status = 200): void
    {
        $this->responses[] = new Settings\AiHttpResponse($status, [], json_encode($body, JSON_THROW_ON_ERROR), 24);
    }
}

$openUsage = ['prompt_tokens' => 100, 'completion_tokens' => 40, 'total_tokens' => 140,
    'prompt_tokens_details' => ['cached_tokens' => 30], 'completion_tokens_details' => ['reasoning_tokens' => 10]];
foreach (['openai', 'mlx_lm', 'vllm', 'sglang', 'openai_compatible_gateway'] as $provider) {
    $usage = AiTokenUsage::parse($provider, ['usage' => $openUsage]);
    usageAssert($usage['input_tokens'] === 100 && $usage['output_tokens'] === 40 && $usage['total_tokens'] === 140, 'OpenAI-compatible totals must not add cached/reasoning subsets.');
    usageAssert($usage['cached_input_tokens'] === 30 && $usage['reasoning_tokens'] === 10 && $usage['measurement'] === 'complete', 'Detailed usage retained.');
}
$geminiUsage = ['promptTokenCount' => 100, 'candidatesTokenCount' => 40, 'thoughtsTokenCount' => 10, 'cachedContentTokenCount' => 30, 'totalTokenCount' => 150];
$usage = AiTokenUsage::parse('gemini', ['usageMetadata' => $geminiUsage]);
usageAssert($usage['total_tokens'] === 150 && $usage['output_tokens'] === 40 && $usage['reasoning_tokens'] === 10, 'Gemini total includes thoughts, output is candidate tokens.');
unset($geminiUsage['totalTokenCount']);
usageAssert(AiTokenUsage::parse('gemini', ['usageMetadata' => $geminiUsage])['total_tokens'] === null, 'Missing Gemini total must not be guessed.');
$usage = AiTokenUsage::parse('ollama', ['prompt_eval_count' => 10, 'eval_count' => 4, 'prompt_eval_cached_count' => 3]);
usageAssert($usage['total_tokens'] === 14 && $usage['total_source'] === 'sum' && $usage['cached_input_tokens'] === 3, 'Ollama measured counts sum once.');
foreach ([null, false, -1, '15', 1.5, [], 1000000000001] as $invalid) {
    usageAssert(AiTokenUsage::parse('openai', ['usage' => ['prompt_tokens' => $invalid]])['measurement'] === 'unavailable', 'Invalid token count cannot become zero.');
}
usageAssert(AiTokenUsage::parse('openai', ['usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]])['total_tokens'] === 0, 'Reported zero differs from missing.');
usageAssert(AiTokenUsage::parse('openai', ['usage' => ['prompt_tokens_details' => 'invalid']])['cached_input_tokens'] === null, 'Malformed detail object is ignored.');
usageAssert(AiTokenUsage::parse('openai', ['usage' => ['prompt_tokens' => 15]])['measurement'] === 'partial', 'Partial usage retained.');

$store = new UsageStore(); $errors = new UsageErrors(); $clock = new UsageClock();
$recorder = new AI\AiUsageRecorder($store, $clock, $errors);
$transport = new UsageTransport(); $http = new Clients\AiHttpJson($transport, $recorder);
$open = new Clients\MeteredAiProviderClient(new Clients\OpenAiCompatibleClient($http), $recorder);
$messages = [['role' => 'user', 'content' => 'private source and prompt']];
$body = ['choices' => [['message' => ['content' => '{"difficulty_score":3,"topic_keys":["arithmetic"],"confidence":0.8,"rationale":"Basic arithmetic."}']]], 'usage' => $openUsage];
$transport->queue($body);
$output = $recorder->withTask('problem_metadata_generate', false, fn () => $open->generate($primary, $messages, 1200));
usageAssert($output['difficulty_score'] === 3 && ! isset($output['usage']), 'Public generation payload unchanged.');
$first = $store->records[0];
usageAssert($first['total_tokens'] === 140 && $first['purpose'] === 'core' && $first['occurred_at'] === '2026-09-14 01:00:00', 'Metadata collected in UTC.');
usageAssert(! str_contains(json_encode($first), 'private') && ! str_contains(json_encode($first), 'secret') && ! str_contains(json_encode($first), 'https:'), 'No prompt, source, credential or endpoint in usage record.');
$transport->queue(array_replace($body, ['choices' => [['message' => ['content' => 'not JSON']]]]));
usageThrows(fn () => $recorder->withTask('addon.reader.review', false, fn () => $open->generate($primary, $messages, 1200)), 'invalid_response');
usageAssert($store->records[1]['total_tokens'] === 140 && $store->records[1]['outcome'] === 'failed' && $store->records[1]['addon_id'] === 'reader', 'Parse failure preserves addon usage.');
$transport->queue(['usage' => $openUsage], 429);
usageThrows(fn () => $recorder->withTask('problem_metadata_generate', true, fn () => $open->generate($primary, $messages, 1200)), 'rate_limited');
usageAssert($store->records[2]['http_status'] === 429 && $store->records[2]['purpose'] === 'probe', 'Probe HTTP failures identified separately.');
$transport->responses[] = new Settings\AiProviderException('timeout', 'private endpoint error', true);
usageThrows(fn () => $open->generate($primary, $messages, 1200), 'timeout');
usageAssert($store->records[3]['total_tokens'] === null && $store->records[3]['measurement'] === 'unavailable' && $store->records[3]['http_status'] === null, 'Timeout is unknown usage, never zero.');
usageThrows(fn () => $recorder->measure($primary, static fn () => throw new RuntimeException('preflight')));
usageAssert(count($store->records) === 4, 'No HTTP attempt means no usage row.');
$transport->queue(['data' => [['id' => 'model-a']]]);
$open->models($primary);
usageAssert(count($store->records) === 4, 'Model listing excluded.');

foreach (['ollama', 'gemini'] as $provider) {
    $profile = AiProviderProfile::tested($provider, $provider, $provider, 'https://ai.example.com', 'bearer', 'secret', 'model-a', 'v1', ['problem_metadata_generate'], false, 30, ['203.0.113.10']);
    $client = new Clients\MeteredAiProviderClient($provider === 'ollama' ? new Clients\OllamaClient($http) : new Clients\GeminiClient($http, $transport), $recorder);
    $transport->queue($provider === 'ollama'
        ? ['message' => ['content' => '{"ok":true}'], 'prompt_eval_count' => 10, 'eval_count' => 4]
        : ['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]], 'usageMetadata' => $geminiUsage + ['totalTokenCount' => 150]]);
    usageAssert($client->generate($profile, $messages, 1000) === ['ok' => true], 'Real adapter output preserved.');
    usageAssert(end($store->records)['total_tokens'] === ($provider === 'ollama' ? 14 : 150), 'Real adapter tracks correct usage.');
}

// Use the actual router and its transient fallback, not an emulated retry loop.
$settings = new AiRoutingSettings(); $vault = new AiRoutingVault();
$settings->put('ai_provider', 1, $vault->store(['profiles' => [$primary->toArray(), $secondary->toArray()]]));
$settings->put('ai_task_routing', 1, ['assignments' => [$assignment->toArray()]]);
$providers = new AI\AiProviderSettingsService($settings, $vault);
$routing = new AI\AiTaskRoutingService($settings, $providers, $catalog);
$router = new AI\StoredAiTaskRouter($catalog, $providers, $routing, new AI\AiProviderClientRegistry([$open]), new AiRoutingEndpointPolicy(), new AI\AiTaskPromptFactory(), $recorder);
$snapshot = $router->snapshot('problem_metadata_generate');
$request = new \CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest('problem_metadata_generate', 'metadata-v1', ['problem' => ['title' => 'Test', 'description' => 'Print Hello.']]);
$transport->responses[] = new Settings\AiProviderException('timeout', 'timeout', true);
$transport->queue($body);
$router->execute($snapshot, $request);
$last = array_slice($store->records, -2);
usageAssert($last[0]['outcome'] === 'failed' && $last[1]['outcome'] === 'succeeded' && $last[0]['profile_key'] === 'primary' && $last[1]['profile_key'] === 'secondary', 'Fallback attempts independently recorded with task context.');
usageAssert($last[0]['task_type'] === 'problem_metadata_generate' && $last[0]['request_id'] !== $last[1]['request_id'], 'Attempts have unique request IDs.');

$addonTasks = new \CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions();
$addonTasks->add(new \CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1('addon.reader.review', '읽기 평가', '1', 'addon_payload'));
$addonCatalog = new AI\AiProviderCatalog($addonTasks);
$addonProfile = AiProviderProfile::tested('reader', 'Reader', 'openai_compatible_gateway', 'https://ai.example.com', 'bearer', 'secret', 'model-a', 'v1', ['addon.reader.review'], false, 30, ['203.0.113.10']);
$settings->put('ai_provider', 2, $vault->store(['profiles' => [$addonProfile->toArray()]]));
$settings->put('ai_task_routing', 2, ['assignments' => [(new \CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment('addon.reader.review', 'reader', null, 1200, 'none'))->toArray()]]);
$addonRouter = new AI\StoredAiTaskRouter($addonCatalog, $providers, new AI\AiTaskRoutingService($settings, $providers, $addonCatalog), new AI\AiProviderClientRegistry([$open]), new AiRoutingEndpointPolicy(), new AI\AiTaskPromptFactory($addonTasks), $recorder);
$transport->queue($body);
$addonRouter->execute($addonRouter->snapshot('addon.reader.review'), new \CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest('addon.reader.review', '1', ['text' => 'synthetic']));
usageAssert(end($store->records)['purpose'] === 'addon' && end($store->records)['addon_id'] === 'reader', 'Real shared router classifies addon calls without changing public result contracts.');
$probe = new AI\AiProviderProbeService($catalog, new AI\AiProviderClientRegistry([$open]), new AiRoutingEndpointPolicy(), new AI\AiTaskPromptFactory(), $recorder);
$beforeProbe = count($store->records);
$transport->queue(['data' => [['id' => 'model-a']]]); $transport->queue($body);
$probe->inspect('probe', 'Probe', 'openai_compatible_gateway', 'https://ai.example.com', 'bearer', 'secret', 'model-a', ['problem_metadata_generate'], false, 30);
usageAssert(count($store->records) === $beforeProbe + 1 && end($store->records)['purpose'] === 'probe', 'Real connection probe records generation once, excludes model listing.');
$store->fail = true; $errors->fail = true; $transport->queue($body);
usageAssert($open->generate($primary, $messages, 1200) === $output, 'Telemetry and diagnostic failures cannot discard output or retry generation.');
$store->fail = false; $errors->fail = false;

$filter = new AiUsageFilter(['from' => '2026-09-01', 'to' => '2026-09-14', 'purpose' => 'addon', 'task_type' => "x' OR 1=1", 'page' => 2], $clock);
usageAssert($filter->startUtc === '2026-08-31 15:00:00' && $filter->endUtc === '2026-09-14 14:59:59', 'Local date boundaries converted to UTC.');
foreach ([['from' => '2026-02-30'], ['from' => '2025-01-01'], ['from' => '2026-09-15'], ['group_by' => 'id; DROP TABLE'], ['purpose' => 'unknown'], ['model_key' => "bad\0"]] as $bad) {
    usageThrows(fn () => new AiUsageFilter($bad, $clock));
}

final class UsageDatabase implements ApplicationDatabase
{
    public array $queries = [];
    public array $inserted = [];
    public bool $fail = false;
    public function row(string $sql, array $values = []): ?array { $this->queries[] = [$sql, $values]; return $this->fail ? null : ['request_count' => '51']; }
    public function rows(string $sql, array $values = []): array { $this->queries[] = [$sql, $values]; return []; }
    public function value(string $sql, array $values = []): mixed { return null; }
    public function insert(string $table, array $data, array $formats = []): int { $this->inserted = [$table, $data]; return $this->fail ? 0 : 1; }
    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int { return 0; }
    public function delete(string $table, array $where, array $whereFormats = []): int { return 0; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
}
$db = new UsageDatabase(); $repository = new PlatformAiUsageStore($db);
$repository->append($first + ['prompt' => 'secret', 'source' => 'secret']);
usageAssert($db->inserted[0] === 'ai_usage_logs' && count($db->inserted[1]) === 19 && ! isset($db->inserted[1]['prompt']), 'Prefix-free metadata whitelist persisted.');
$report = $repository->report($filter);
usageAssert($report['page'] === 2 && $report['pages'] === 2 && end($db->queries)[1][4] === 50, 'Detail queries paginate 50 records.');
usageAssert(! str_contains($db->queries[0][0], "x' OR") && in_array("x' OR 1=1", $db->queries[0][1], true), 'Filter values parameterized.');
$db->fail = true;
usageThrows(fn () => $repository->report($filter));
usageThrows(fn () => $repository->append($first));

final class UsagePages implements ConsoleResponder
{
    public string $content = '';
    public function respond(string $title, string $content, string $currentMenu, Http\HttpRequest $request, int $status = 200): Http\HttpResponse
    {
        usageAssert($currentMenu === 'operations.ai_usage', 'Usage page selects its registered menu.');
        $this->content = $content;
        return Http\HttpResponse::html($content, $status);
    }
}
$pages = new UsagePages();
$urls = new class implements Http\RouteUrlProvider {
    public function path(string $routeName, array $parameters = []): string { usageAssert($routeName === 'console.settings.ai_usage', 'Registered route used.'); return '/oj-console/settings/ai-usage'; }
};
$store->result = ['summary' => ['request_count' => 51, 'success_count' => 49, 'failure_count' => 2, 'input_tokens' => 18420, 'output_tokens' => 6840, 'total_tokens' => 25260, 'total_measured_count' => 50, 'partial_count' => 0, 'unavailable_count' => 1, 'average_ms' => 12450],
    'groups' => [['group_key' => 'problem_metadata_generate', 'request_count' => 35, 'failure_count' => 1, 'input_tokens' => 12000, 'output_tokens' => 4000, 'total_tokens' => 16000, 'total_measured_count' => 34],
        ['group_key' => 'addon.reader.review', 'request_count' => 16, 'failure_count' => 1, 'input_tokens' => 6420, 'output_tokens' => 2840, 'total_tokens' => 9260, 'total_measured_count' => 16]],
    'items' => array_slice($store->records, 0, 4), 'page' => 1, 'pages' => 2];
$handler = new AiUsagePageHandler($pages, $store, $catalog, $clock, $urls, $errors);
$definition = current(array_filter(require $pluginRoot . '/config/routes.php', static fn ($row) => $row['name'] === 'console.settings.ai_usage'));
$route = new Http\RouteMatch(Http\RouteDefinition::fromArray($definition), []);
$handler->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-usage'));
$html = $pages->content;
foreach (['25,260', '측정 불가', '2026-09-14 10:00:00', '애드온', '연결 검사', 'name="from"', 'name="group_by"', 'page=2', '캐시·추론'] as $text) {
    usageAssert(str_contains($html, $text), 'Usage page missing: ' . $text);
}
usageAssert(! str_contains($html, 'style=') && ! str_contains($html, '<style'), 'Uses shared site CSS only.');
$store->result['items'][0]['model_key'] = '<img src=x onerror=alert(1)>';
$handler->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-usage'));
usageAssert(! str_contains($pages->content, '<img') && str_contains($pages->content, '&lt;img'), 'Provider metadata HTML escaped.');
$store->result['summary'] = ['request_count' => 0]; $store->result['items'] = []; $store->result['groups'] = [];
$handler->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-usage'));
usageAssert(str_contains($pages->content, '<dd>0</dd>') && str_contains($pages->content, '기록된 호출이 없습니다'), 'Empty report is distinct from missing measurements.');
$response = $handler->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-usage', ['group_by' => 'bad']));
usageAssert($response->status() === 400, 'Invalid filters do not run a broad query.');
$store->fail = true;
$response = $handler->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-usage'));
usageAssert($response->status() === 503 && ! str_contains($pages->content, 'private database'), 'Unavailable store has a safe error, not false zero totals.');

if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    file_put_contents($argv[2], '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style><title>AI 사용량</title></head><body class="ojp-app"><div class="ojp-console-shell"><div class="ojp-console-layout"><aside class="ojp-console-sidebar">관리자 메뉴</aside><main class="ojp-console-main"><div class="ojp-shell">' . $html . '</div></main></div></div></body></html>');
}
echo "AI usage: {$assertions} assertions passed.\n";

<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Settings\Application as AI;
use CodingPanda\OjPlatform\Modules\Settings\Contract as Contract;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai as Clients;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;

require __DIR__ . '/../Contract/addon-i06-ai-instruction.php';
require __DIR__ . '/ai-usage.php';

final class StructuredTransport implements Contract\AiHttpTransport
{
    public array $bodies = [];
    public string $doneReason = 'stop';
    public mixed $content = '{"ok":true}';
    public bool $done = true;
    public string $thinking = '';
    public ?string $httpError = null;
    public int $httpStatus = 400;
    public function request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutSeconds, int $timeoutSeconds, array $expectedResolvedIps): Contract\AiHttpResponse
    {
        if ($method === 'GET' && str_ends_with($url, '/api/tags')) {
            return new Contract\AiHttpResponse(200, [], '{"models":[{"name":"metadata-model","digest":"v1"}]}', 1);
        }
        $this->bodies[] = json_decode($body ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        if ($this->httpError !== null) {
            return new Contract\AiHttpResponse($this->httpStatus, [], json_encode(['error' => $this->httpError], JSON_THROW_ON_ERROR), 1);
        }
        return new Contract\AiHttpResponse(200, [], json_encode(['message' => ['content' => $this->content, 'thinking' => $this->thinking], 'done_reason' => $this->doneReason, 'done' => $this->done], JSON_THROW_ON_ERROR), 1);
    }
}

$schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false];
$generationInput = ['operation_payload' => ['output_contract' => ['json_schema' => $schema]]];
i06AiSame($schema, AiStructuredOutput::fromInput($generationInput), 'Schema extraction must not depend on a model or addon name.');
i06AiSame(null, AiStructuredOutput::fromInput(['answer' => ['json_schema' => $schema]]), 'Student text must not become generation settings.');
foreach ([['$ref' => 'https://example.test/schema'], ['$ref' => '#/$defs/missing'], ['$dynamicRef' => 'remote']] as $invalid) {
    i06AiThrows(fn () => AiStructuredOutput::fromInput(['output_contract' => ['json_schema' => array_merge($schema, $invalid)]]), 'Unsafe schema rejected.');
}
$cycle = $schema + ['$defs' => ['loop' => ['$ref' => '#/$defs/loop']]];
i06AiThrows(fn () => AiStructuredOutput::fromInput(['output_contract' => ['json_schema' => $cycle]]), 'Recursive schema rejected.');
foreach ([['temperature' => -0.1], ['temperature' => 3], ['temperature' => INF], ['temperature' => true], ['num_ctx' => 100], ['num_ctx' => '4096.5'], ['num_ctx' => 2000000], ['model' => 'override']] as $invalid) {
    i06AiThrows(fn () => AiTaskAssignment::parseOllamaOptions($invalid), 'Invalid generation options rejected.');
}
$configured = AiTaskAssignment::fromArray(['task_type' => I06_TASK_A, 'primary_profile_key' => 'ollama', 'max_output_tokens' => 1200, 'ollama_options' => ['temperature' => '0', 'num_ctx' => '16384']]);
i06AiSame(['num_ctx' => 16384, 'temperature' => 0.0], $configured->ollamaOptions(), 'Zero temperature must survive form parsing.');
i06AiSame($configured->toArray(), AiTaskAssignment::fromArray($configured->toArray())->toArray(), 'Options round trip through persisted settings.');
i06AiThrows(fn () => AiTaskAssignment::fromArray(['task_type' => I06_TASK_A, 'primary_profile_key' => 'ollama', 'max_output_tokens' => 4096, 'ollama_options' => ['num_ctx' => 4096]]), 'Invalid input/output budget must be rejected while saving settings.');

$http = new StructuredTransport();
$ollama = new Clients\OllamaClient(new Clients\AiHttpJson($http));
$ollama->generate($profilesByAdapter['ollama'], $adapterMessages, 1200);
i06AiSame('json', $http->bodies[0]['format'], 'Legacy requests retain JSON mode.');
i06AiSame($adapterMessages, $http->bodies[0]['messages'], 'Legacy messages remain unchanged.');
i06AiSame(1.0, (float) $http->bodies[0]['options']['temperature'], 'Legacy temperature preserved.');
i06AiAssert(! array_key_exists('truncate', $http->bodies[0]) && ! array_key_exists('shift', $http->bodies[0]), 'Legacy requests retain their existing context policy.');
$ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []);
i06AiSame($schema, $http->bodies[1]['format'], 'Schema reaches the actual Ollama HTTP body.');
i06AiSame('system', $http->bodies[1]['messages'][0]['role'], 'Native schema is also grounded outside untrusted task text.');
i06AiAssert(str_contains($http->bodies[1]['messages'][0]['content'], json_encode($schema, JSON_THROW_ON_ERROR)), 'Ground the same schema, not a second independent prompt contract.');
i06AiAssert(str_starts_with($http->bodies[1]['messages'][0]['content'], $adapterMessages[0]['content'] . "\n\n"), 'Keep the platform system instruction first.');
i06AiSame(array_slice($adapterMessages, 1), array_slice($http->bodies[1]['messages'], 1), 'Preserve addon instructions, user data and their order.');
i06AiSame(0.2, $http->bodies[1]['options']['temperature'], 'Structured requests default to lower temperature.');
i06AiAssert(! isset($http->bodies[1]['options']['num_ctx']), 'No hardware capacity is assumed.');
i06AiSame(false, $http->bodies[1]['truncate'], 'Structured evidence cannot be silently truncated.');
i06AiSame(false, $http->bodies[1]['shift'], 'Do not evict source evidence while generating a structured judgment.');
$metered = new Clients\MeteredAiProviderClient($ollama, new AI\AiUsageRecorder(new UsageStore(), new UsageClock(), new UsageErrors()));
$metered->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, $configured->ollamaOptions());
i06AiSame(16384, $http->bodies[2]['options']['num_ctx'], 'Metering must forward context and schema.');
i06AiSame(0.0, (float) $http->bodies[2]['options']['temperature'], 'Configured zero must not fall back to a default.');

$routingSettings2 = new I06AiSettings([
    'ai_provider' => new SettingsSnapshot(1, $secretReference),
    'ai_task_routing' => new SettingsSnapshot(1, ['assignments' => [$configured->toArray()]]),
]);
$vault2 = new I06AiVault($secretReference, ['profiles' => [$profilesByAdapter['ollama']->toArray()]]);
$profiles2 = new AI\AiProviderSettingsService($routingSettings2, $vault2);
$routing2 = new AI\AiTaskRoutingService($routingSettings2, $profiles2, $providerCatalogA);
$router2 = new AI\StoredAiTaskRouter($providerCatalogA, $profiles2, $routing2, new AI\AiProviderClientRegistry([$metered]), new I06AiEndpointPolicy(), $prompts);
$snapshot2 = $router2->snapshot(I06_TASK_A);
$router2->execute($snapshot2, new AiTaskRequest(I06_TASK_A, 'worksheet.v1', $generationInput));
i06AiSame($schema, $http->bodies[3]['format'], 'Routing must carry server schema to the provider.');
i06AiSame(16384, $http->bodies[3]['options']['num_ctx'], 'Routing must carry persisted task options.');
$changed = $configured->toArray();
$changed['ollama_options']['temperature'] = 0.15;
$settings3 = new I06AiSettings([
    'ai_provider' => new SettingsSnapshot(1, $secretReference),
    'ai_task_routing' => new SettingsSnapshot(1, ['assignments' => [$changed]]),
]);
$profiles3 = new AI\AiProviderSettingsService($settings3, $vault2);
$routing3 = new AI\AiTaskRoutingService($settings3, $profiles3, $providerCatalogA);
$router3 = new AI\StoredAiTaskRouter($providerCatalogA, $profiles3, $routing3, new AI\AiProviderClientRegistry([$metered]), new I06AiEndpointPolicy(), $prompts);
i06AiAssert($snapshot2->profileFingerprint() !== $router3->snapshot(I06_TASK_A)->profileFingerprint(), 'Generation settings participate in immutable routing identity.');
$beforeStale = count($http->bodies);
i06AiThrows(fn () => $router3->execute($snapshot2, new AiTaskRequest(I06_TASK_A, 'worksheet.v1', $generationInput)), 'Stale generation settings fail before execution.');
i06AiSame($beforeStale, count($http->bodies), 'Stale settings do not send another request.');

$http->doneReason = 'length';
usageThrows(fn () => $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []), 'output_truncated');
$calls = count($http->bodies);
usageThrows(fn () => $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 4096, $schema, ['num_ctx' => 4096]), 'context_too_small');
i06AiSame($calls, count($http->bodies), 'Invalid context must fail before HTTP transmission.');
$http->httpError = 'the input length exceeds the context length; private-source-do-not-log';
try {
    $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []);
    throw new RuntimeException('A context limit error must not produce a draft.');
} catch (Contract\AiProviderException $error) {
    i06AiSame('context_too_small', $error->safeCode(), 'Recognize context exhaustion instead of a generic request failure.');
    i06AiAssert(str_contains($error->getMessage(), 'num_ctx') && strlen($error->getMessage()) <= 191, 'Give actionable, bounded context diagnostics.');
    i06AiAssert(! str_contains($error->getMessage(), 'private-source-do-not-log') && ! $error->isTransient(), 'Do not expose source, retry blindly or route a context error to a fallback.');
}
$http->httpError = 'unrelated bad schema';
usageThrows(fn () => $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []), 'invalid_request');
$http->httpError = 'the input prompt (9000 tokens) exceeds the context length (4096 tokens)';
foreach ([400, 413, 500] as $contextStatus) {
    $http->httpStatus = $contextStatus;
    usageThrows(fn () => $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []), 'context_too_small');
}
$http->httpStatus = 400;
usageThrows(fn () => $ollama->generate($profilesByAdapter['ollama'], $adapterMessages, 1200), 'invalid_request');
usageThrows(fn () => (new Clients\AiHttpJson($http))->post($profilesByAdapter['ollama'], '/v1/chat/completions', ['truncate' => false]), 'invalid_request');
$http->httpError = null;

$http->doneReason = 'stop';
foreach (["\xEF\xBB\xBF{\"ok\":true}", "\xEF\xBB\xBF```json\n{\"ok\":true}\n```", "```json\n\xEF\xBB\xBF{\"ok\":true}\n```"] as $body) {
    $http->content = $body;
    i06AiSame(['ok' => true], $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []), 'Normalize BOM/fences without changing the JSON data.');
}
foreach ([
    [null, 'message.content'], [[], 'message.content'], ['', '빈 답변'], ['   ', '빈 답변'],
    ['{"ok":', 'JSON 문법 오류'], ['앞 설명 {"ok":true}', 'JSON 문법 오류'],
    ['{"ok":true}{"ok":false}', 'JSON 문법 오류'], ['{"ok":true,}', 'JSON 문법 오류'],
    ['[]', '배열'], ['true', 'bool'], ['"answer"', 'string'],
] as [$body, $reason]) {
    $http->content = $body;
    try {
        $ollama->generateStructured($profilesByAdapter['ollama'], $adapterMessages, 1200, $schema, []);
        throw new RuntimeException('Invalid provider content must fail.');
    } catch (Contract\AiProviderException $error) {
        i06AiSame('invalid_response', $error->safeCode(), 'Preserve the provider exception contract.');
        i06AiAssert(str_contains($error->getMessage(), $reason), 'Return a specific safe response diagnosis.');
        i06AiAssert(strlen($error->getMessage()) <= 191, 'Response diagnostics fit Jobs status messages.');
        i06AiAssert(! $error->isTransient(), 'Malformed data must not silently switch to a fallback provider.');
    }
}
$http->content = '{"ok":true}';
$http->done = false;
try {
    $ollama->generate($profilesByAdapter['ollama'], $adapterMessages, 1200);
    throw new RuntimeException('An incomplete envelope must fail even with valid JSON.');
} catch (Contract\AiProviderException $error) {
    i06AiAssert(str_contains($error->getMessage(), '완료되지 않은'), 'Do not persist an unfinished response.');
}
$http->done = true;
$http->content = '';
$http->thinking = 'Private model reasoning must not be exposed.';
try {
    $ollama->generate($profilesByAdapter['ollama'], $adapterMessages, 1200);
    throw new RuntimeException('Thinking without final output must fail.');
} catch (Contract\AiProviderException $error) {
    i06AiAssert(str_contains($error->getMessage(), '생각 과정만'), 'Distinguish reasoning-only from an empty final response.');
    i06AiAssert(! str_contains($error->getMessage(), $http->thinking), 'Never leak model reasoning in diagnostics.');
}
$http->doneReason = 'length';
usageThrows(fn () => $ollama->generate($profilesByAdapter['ollama'], $adapterMessages, 1200), 'output_truncated');

$geminiHttp = new I06AiTransport();
$gemini = new Clients\MeteredAiProviderClient(new Clients\GeminiClient(new Clients\AiHttpJson($geminiHttp), $geminiHttp), new AI\AiUsageRecorder(new UsageStore(), new UsageClock(), new UsageErrors()));
$gemini->generateStructured($profilesByAdapter['gemini'], $adapterMessages, 1200, $schema, ['temperature' => 0, 'num_ctx' => 16384]);
i06AiSame(0.2, $geminiHttp->requests[0]['body']['generationConfig']['temperature'], 'Ollama task overrides must not affect Gemini.');
i06AiAssert(! isset($geminiHttp->requests[0]['body']['options']), 'Gemini request format is unchanged.');

$authoringHttp = new StructuredTransport();
$authoringClient = new Clients\OllamaClient(new Clients\AiHttpJson($authoringHttp));
$authoringPrompts = new AI\AiTaskPromptFactory();
foreach (['title_only', 'full_draft'] as $mode) {
    $authoringContract = \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAuthoringOutputContract::forMode($mode);
    $authoringOutput = $mode === 'title_only' ? ['title' => 'Hello 출력'] : [
        'title' => 'Hello 출력', 'description' => 'Hello를 출력하세요.', 'input' => '',
        'output' => 'Hello를 출력합니다.', 'sample_input' => '', 'sample_output' => "Hello\n", 'hint' => '',
    ];
    $authoringHttp->content = json_encode($authoringOutput, JSON_THROW_ON_ERROR);
    $authoringInput = ['mode' => $mode, 'brief' => 'Hello 출력', 'output_contract' => $authoringContract];
    $authoringMessages = $authoringPrompts->messages(new AiTaskRequest('problem_authoring_generate', 'problem-authoring-v1', $authoringInput));
    i06AiSame($authoringOutput, $authoringClient->generateStructured($profilesByAdapter['ollama'], $authoringMessages, 1200, AiStructuredOutput::fromInput($authoringInput), []), 'Native Ollama preserves valid no-input authoring output.');
    i06AiSame($authoringContract['json_schema'], $authoringHttp->bodies[array_key_last($authoringHttp->bodies)]['format'], 'Authoring mode schema reaches the actual Ollama HTTP request.');
}

$metadataHttp = new StructuredTransport();
$metadataOutput = ['difficulty_score' => 2, 'topic_keys' => ['arithmetic'], 'confidence' => 0.8, 'rationale' => 'Basic arithmetic.'];
$metadataHttp->content = json_encode($metadataOutput, JSON_THROW_ON_ERROR);
$metadataStore = new UsageStore();
$metadataUsage = new AI\AiUsageRecorder($metadataStore, new UsageClock(), new UsageErrors());
$metadataClient = new Clients\MeteredAiProviderClient(new Clients\OllamaClient(new Clients\AiHttpJson($metadataHttp, $metadataUsage)), $metadataUsage);
$metadataClients = new AI\AiProviderClientRegistry([$metadataClient]);
$metadataPrompts = new AI\AiTaskPromptFactory();
$metadataProbe = new AI\AiProviderProbeService($providerCatalogA, $metadataClients, new I06AiEndpointPolicy(), $metadataPrompts, $metadataUsage);
$inspectMetadata = fn () => $metadataProbe->inspect('metadata', 'Metadata', 'ollama', 'https://ai.example.test', 'bearer', 'test-secret', 'metadata-model', ['problem_metadata_generate'], false, 60);
$metadataProfile = $inspectMetadata();
$probeInput = $metadataPrompts->probeInput('problem_metadata_generate');
$metadataSchema = AiStructuredOutput::fromInput($probeInput);
i06AiSame($metadataSchema, $metadataHttp->bodies[0]['format'], 'Candidate inspection sends native metadata schema instead of plain JSON mode.');
i06AiSame(['difficulty_score', 'topic_keys', 'confidence', 'rationale'], $metadataSchema['required'], 'Probe and job require all four fields.');
i06AiSame('probe', $metadataStore->records[0]['purpose'], 'Structured probes remain metered as probes.');

$metadataSettings = new I06AiSettings([
    'ai_provider' => new SettingsSnapshot(1, $secretReference),
    'ai_task_routing' => new SettingsSnapshot(1, ['assignments' => [[
        'task_type' => 'problem_metadata_generate', 'primary_profile_key' => 'metadata', 'max_output_tokens' => 1200,
    ]]]),
]);
$metadataProfiles = new AI\AiProviderSettingsService($metadataSettings, new I06AiVault($secretReference, ['profiles' => [$metadataProfile->toArray()]]));
$metadataRouting = new AI\AiTaskRoutingService($metadataSettings, $metadataProfiles, $providerCatalogA);
$metadataRouter = new AI\StoredAiTaskRouter($providerCatalogA, $metadataProfiles, $metadataRouting, $metadataClients, new I06AiEndpointPolicy(), $metadataPrompts, $metadataUsage);
$metadataAssignment = $metadataRouter->snapshot('problem_metadata_generate');
$metadataRequest = new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $probeInput);
i06AiSame($metadataOutput, $metadataRouter->execute($metadataAssignment, $metadataRequest)->output(), 'Real metadata routing, metering and Ollama parsing preserve complete output.');
i06AiSame($metadataHttp->bodies[0]['format'], $metadataHttp->bodies[1]['format'], 'Candidate inspection and live metadata execution use the same native schema.');
$helloWireInput = $probeInput;
$helloWireInput['problem'] = [
    'problem_id' => 1001,
    'title' => 'Hello 출력',
    'description' => '<p>printf( ) 함수를 사용해서 Hello 를 출력해 보자.</p>',
    'input' => '', 'output' => 'Hello를 출력한다.', 'sample_input' => '', 'sample_output' => 'Hello',
    'hint' => '#include <stdio.h>' . "\n" . 'int main() { printf("Hello"); return 0; }',
];
$metadataRouter->execute($metadataAssignment, new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $helloWireInput));
$helloBody = $metadataHttp->bodies[array_key_last($metadataHttp->bodies)];
$helloSentInput = json_decode($helloBody['messages'][1]['content'], true, 64, JSON_THROW_ON_ERROR)['input'];
i06AiSame($helloWireInput['problem'], $helloSentInput['problem'], 'Native Ollama receives the current statement and literal samples without substitutions.');
i06AiSame(['arithmetic' => '산술 연산'], $helloSentInput['topic_dictionary'], 'Native Ollama receives only the allowed key/label map, not category metadata.');
i06AiAssert(! isset($helloSentInput['output_contract']), 'Send the schema natively and in the system message, not a third copy after the source.');
i06AiSame(false, $helloBody['truncate'], 'Whole-context policy survives routing and metering.');
i06AiSame(['system', 'user'], array_column($helloBody['messages'], 'role'), 'A generation after a probe must not carry probe conversation history.');
i06AiAssert(str_contains($helloBody['messages'][0]['content'], '문제를 새로 만들거나 다른 문제를 설명하는 작업이 아닙니다'), 'Source-grounding rules survive routing, metering and native schema grounding.');
i06AiAssert(! str_contains($helloBody['messages'][1]['content'], '두 수의 합'), 'Do not substitute the candidate-inspection sample for the real target.');
$beforeMissingSource = count($metadataHttp->bodies);
i06AiThrows(fn () => $metadataRouter->execute($metadataAssignment, new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', ['topic_dictionary' => $probeInput['topic_dictionary']])), 'Reject a dictionary without a problem before HTTP.');
i06AiSame($beforeMissingSource, count($metadataHttp->bodies), 'No provider call is allowed when source text is missing.');
unset($metadataOutput['confidence']);
$metadataOutput['private_key_do_not_log'] = 'private-value-do-not-log';
$metadataHttp->content = json_encode($metadataOutput, JSON_THROW_ON_ERROR);
foreach ([$inspectMetadata, fn () => $metadataRouter->execute($metadataAssignment, $metadataRequest)] as $operation) {
    $before = count($metadataHttp->bodies);
    try {
        $operation();
        throw new RuntimeException('Ignored required schema must not succeed.');
    } catch (Contract\AiProviderException $error) {
        i06AiSame('schema_required_missing', $error->safeCode(), 'Distinguish ignored native schema from JSON parsing or connection failures.');
        i06AiAssert(str_contains($error->getMessage(), 'confidence'), 'Name the missing required key from our schema.');
        i06AiAssert(str_contains($error->getMessage(), 'format'), 'Explain which server/proxy capability to check.');
        i06AiAssert(! str_contains($error->getMessage(), 'private'), 'Do not expose unknown output keys or values.');
        i06AiAssert(! $error->isTransient(), 'An ignored schema must not trigger retries or fallback-provider transmission.');
        i06AiAssert(strlen($error->getMessage()) <= 191, 'Diagnosis fits existing Jobs storage.');
    }
    i06AiSame($before + 1, count($metadataHttp->bodies), 'One generation attempt per operation without hidden repair requests.');
}
i06AiSame('failed', end($metadataStore->records)['outcome'], 'Ignored native schema is recorded as failed, not successful AI usage.');

foreach (['x', str_repeat('x', 32), '<script>private-value</script>'] as $key) {
    $requiredSchema = ['type' => 'object', 'required' => [$key], 'properties' => [$key => ['type' => 'number']]];
    try {
        $metadataClient->generateStructured($metadataProfile, $adapterMessages, 1200, $requiredSchema, []);
        throw new RuntimeException('Missing required property must fail.');
    } catch (Contract\AiProviderException $error) {
        i06AiAssert(strlen($error->getMessage()) <= 191, 'Long required keys remain within the status message byte limit.');
        i06AiAssert(! str_contains($error->getMessage(), '<script>'), 'Unsafe schema keys are not echoed into diagnostics.');
    }
}

echo "Structured generation passed: schema/options through routing and metering, legacy/Gemini isolation, invalid references/settings and truncated output.\n";

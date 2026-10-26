<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiInputFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiOutputValidator;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobSupport;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiTopicValidationException;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Presentation\Console\JobDetailsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;

require __DIR__ . '/problems.php';
$assertions = 0;
$dictionary = new MemoryTopics();
$dictionary->topics['retired'] = new ProblemTopic('retired', '사용 중지 주제', 'implementation', [], false);
$dictionary->topics['orphan'] = new ProblemTopic('orphan', '상위 분야 누락', 'missing', []);
$inputs = new ProblemAiInputFactory($dictionary);
$outputs = new ProblemAiOutputValidator($dictionary);
$contract = $inputs->metadataOutputContract($inputs->topicDictionary());
$schema = AiStructuredOutput::fromInput(['output_contract' => $contract]);
problemsSame(['simulation'], $schema['properties']['topic_keys']['items']['enum'], 'Only active selectable detail keys belong to the enum.');
problemsSame(0, $schema['properties']['topic_keys']['minItems'], 'No matching topic must allow an empty list.');
problemsSame(3, $inputs->metadataOutputContract(['version' => 'test', 'topics' => array_map(
    static fn (string $key): array => ['key' => $key, 'selectable' => true], ['a', 'b', 'c', 'd']
)])['json_schema']['properties']['topic_keys']['maxItems'], 'Never select more than three topics.');
$emptyContract = $inputs->metadataOutputContract(['version' => 'empty', 'topics' => []]);
problemsSame(0, $emptyContract['json_schema']['properties']['topic_keys']['maxItems'], 'An empty dictionary only permits no topics.');
problemsAssert(! isset($emptyContract['json_schema']['properties']['topic_keys']['items']['enum']), 'Do not produce an invalid empty enum.');
$output = ['difficulty_score' => 2, 'topic_keys' => ['simulation'], 'confidence' => 0.8, 'rationale' => '상태를 차례대로 계산합니다.'];
problemsSame(['simulation'], $outputs->metadata($output)['topic_keys'], 'Valid keys remain unchanged.');
problemsSame([], $outputs->metadata(array_replace($output, ['topic_keys' => []]))['topic_keys'], 'Empty keys remain empty.');
foreach ([[0, 0.0], [1, 1.0], ['0.8', 0.8], [' 0.8 ', 0.8], ['8e-1', 0.8], ['0', 0.0], ['1', 1.0]] as [$value, $expected]) {
    problemsSame($expected, $outputs->metadata(array_replace($output, ['confidence' => $value]))['confidence'], 'Normalize unambiguous JSON numbers only.');
}
foreach ([
    [null, 'null'], [true, 'bool'], [[], 'array'], ['높음', 'string'], ['80%', '백분율'],
    ['0.8점', 'string'], ['"0.8"', 'string'], ['null', 'string'], [85, '범위'], [-3, '범위'],
    [1.01, '범위'], [INF, '범위'], [NAN, '범위'], ['1e999', '범위'],
] as [$value, $reason]) {
    try {
        $outputs->metadata(array_replace($output, ['confidence' => $value]));
        throw new RuntimeException('Invalid confidence must fail.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), $reason), 'Explain confidence rejection without guessing a value.');
        problemsAssert(strlen($error->getMessage()) <= 191, 'Confidence diagnostics fit the job message limit.');
    }
}
$missingConfidence = $output;
unset($missingConfidence['confidence']);
try {
    $outputs->metadata($missingConfidence);
    throw new RuntimeException('Missing confidence must fail.');
} catch (DomainException $error) {
    problemsAssert(str_contains($error->getMessage(), '누락'), 'Distinguish a missing field from a wrong type.');
}
foreach ([
    'implementation' => '상위 분야',
    'unknown-key' => '등록되지 않은',
    '시뮬레이션' => '등록되지 않은',
    'retired' => '비활성화',
    'orphan' => '상위 분야 연결',
] as $key => $reason) {
    try {
        $outputs->metadata(array_replace($output, ['topic_keys' => ['simulation', $key]]));
        throw new RuntimeException('Invalid topics must fail.');
    } catch (ProblemAiTopicValidationException $error) {
        problemsSame($key, $error->issues()[0]['key'], 'Keep the actual rejected key.');
        problemsAssert(str_contains($error->issues()[0]['reason'], $reason), 'Explain the exact rejection reason.');
        problemsSame(1, count($error->issues()), 'Do not report valid keys as invalid.');
        problemsAssert(strlen($error->getMessage()) <= 191, 'Job status messages must fit their byte limit.');
    }
}
try {
    $outputs->metadata(array_replace($output, ['topic_keys' => [str_repeat('가', 200)]]));
} catch (ProblemAiTopicValidationException $error) {
    problemsAssert(strlen($error->issues()[0]['key']) <= 160, 'Bound rejected values in safe results.');
    problemsSame(1, preg_match('//u', $error->issues()[0]['key']), 'Truncation must preserve UTF-8.');
}
$formTopics->topics['retired'] = $dictionary->topics['retired'];
$formHtml = $problemEditForm->render(null, null, 1000);
problemsAssert(! str_contains($formHtml, '<option value="retired"'), 'Inactive details cannot be offered by the editor.');
problemsAssert(str_contains($formHtml, '<option value="simulation"'), 'Active details stay selectable.');
$input = [
    'contract_version' => ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
    'problem' => $inputs->problem($gateway->find($bulkApplyProblemId), $metadata->find($bulkApplyProblemId)),
    'topic_dictionary' => $inputs->topicDictionary(),
];
$router = new ProblemsAiRouter();
$assignment = $router->snapshot('problem_metadata_generate');
$inputHash = $inputs->hash($input);
$item = new JobWorkItem(problemAiClaim('problem_metadata_generate', (string) $bulkApplyProblemId->value(), $inputHash), [
    'problem_id' => $bulkApplyProblemId->value(), 'problem_input_hash' => $inputHash,
    'input' => $input, 'assignment' => $assignment->toArray(),
]);
$handler = new ProblemMetadataGenerateJobHandler($gateway, $metadata, $access, $inputs, $outputs, new ProblemAiJobSupport($inputs, $router), $formDrafts);
$router->outputs['problem_metadata_generate'] = array_replace($output, ['topic_keys' => ['implementation', '<script>alert(1)</script>', 'retired']]);
$before = count($formDrafts->items);
$failed = $handler->handle($item);
problemsSame('needs_attention', $failed->outcome(), 'Invalid AI topics must require review.');
problemsSame('problem.ai_topics_invalid', $failed->resultCode(), 'Use the diagnostic result code.');
problemsSame(3, count($failed->safeResult()['topic_validation']), 'Preserve every rejected value for inspection.');
problemsSame($before, count($formDrafts->items), 'Do not store an invalid or silently corrected draft.');
problemsAssert(! isset($failed->safeResult()['rationale']), 'Do not expose the entire AI response in job status.');
problemsSame($contract, $router->requests[0]->input()['output_contract'], 'The provider receives the enum schema derived from the verified snapshot.');
problemsSame(array_diff_key($input['problem'], array_flip(['current_metadata', 'accepted_count', 'submitted_count'])), $router->requests[0]->input()['problem'], 'Preserve saved source fields while excluding previous classifications and submission statistics.');
problemsSame($inputHash, $inputs->hash($item->payload()['input']), 'Existing queued hashes remain unchanged.');
$router->outputs['problem_metadata_generate'] = $output;
problemsSame('succeeded', $handler->handle($item)->outcome(), 'A corrected response can create a valid draft.');
$before = count($formDrafts->items);
$router->outputs['problem_metadata_generate'] = array_replace($output, ['confidence' => null]);
$invalidConfidenceResult = $handler->handle($item);
problemsSame('problem.ai_contract_failed', $invalidConfidenceResult->resultCode(), 'Keep the existing job result contract.');
problemsAssert(str_contains($invalidConfidenceResult->message(), 'null'), 'Keep the field type diagnosis in the job result.');
problemsSame($before, count($formDrafts->items), 'Do not persist invalid confidence.');
$router->outputs['problem_metadata_generate'] = array_replace($output, ['confidence' => '0.8']);
problemsSame('succeeded', $handler->handle($item)->outcome(), 'Quoted numeric confidence can finish the job.');
$retryItem = static fn (int $attempt): JobWorkItem => new JobWorkItem(
    problemAiClaim('problem_metadata_generate', (string) $bulkApplyProblemId->value(), $inputHash, $attempt, 3),
    $item->payload()
);
$before = count($formDrafts->items);
$requestCount = count($router->requests);
$router->outputs['problem_metadata_generate'] = $missingConfidence;
foreach ([1, 2, 3] as $attempt) {
    $result = $handler->handle($retryItem($attempt));
    problemsSame($attempt < 3 ? 'retry' : 'needs_attention', $result->outcome(), 'Missing confidence uses the queue attempt budget before requiring attention.');
    problemsSame($attempt < 3 ? 5 : 0, $result->retryDelaySeconds(), 'Use a short queue delay, not an unbounded in-request retry loop.');
    problemsSame('problem.ai_contract_failed', $result->resultCode(), 'Preserve the public result code across retries.');
    problemsAssert(str_contains($result->message(), 'confidence') && str_contains($result->message(), '누락'), 'Retain the exact missing-field diagnosis.');
    problemsSame([], $result->safeResult(), 'Do not persist unvalidated model output.');
    problemsSame($before, count($formDrafts->items), 'Do not invent confidence or persist a partial draft.');
    $request = $router->requests[array_key_last($router->requests)];
    problemsSame($contract['json_schema']['required'], $request->input()['output_contract']['json_schema']['required'], 'Retries must retain all required fields.');
    problemsSame($attempt > 1, str_contains($request->input()['output_contract']['json_schema']['description'], '재생성'), 'Repeated attempts receive explicit complete-object instructions.');
    problemsSame($inputHash, $inputs->hash($retryItem($attempt)->payload()['input']), 'Regeneration must not modify the queued snapshot hash.');
}
problemsSame(3, count($router->requests) - $requestCount, 'Exactly one provider call per queue attempt.');
$router->outputs['problem_metadata_generate'] = $output;
problemsSame('succeeded', $handler->handle($retryItem(2))->outcome(), 'A complete regenerated response creates a valid draft.');
problemsSame($before + 1, count($formDrafts->items), 'Only the successful response creates a draft.');
foreach ([
    array_replace($output, ['difficulty_score' => null]),
    array_replace($output, ['topic_keys' => 'simulation']),
    array_replace($output, ['confidence' => '80%']),
    array_replace($output, ['rationale' => '']),
] as $invalidOutput) {
    $router->outputs['problem_metadata_generate'] = $invalidOutput;
    problemsSame('retry', $handler->handle($retryItem(1))->outcome(), 'Malformed metadata fields share the bounded retry path.');
}
$router->outputs['problem_metadata_generate'] = array_replace($output, ['topic_keys' => ['implementation']]);
problemsSame('needs_attention', $handler->handle($retryItem(1))->outcome(), 'A rejected topic remains a reviewable semantic failure even with attempts remaining.');
$tamperedPayload = $item->payload();
$tamperedPayload['input']['problem']['title'] = 'changed';
$requestCount = count($router->requests);
problemsSame('needs_attention', $handler->handle(new JobWorkItem($retryItem(1)->claim(), $tamperedPayload))->outcome(), 'Input contract failures must not be retried as model-output failures.');
problemsSame($requestCount, count($router->requests), 'Invalid snapshots must not call the provider.');
$prompt = new AiTaskPromptFactory();
$messages = $prompt->messages(new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $input));
problemsAssert(str_contains($messages[0]['content'], '사전의 key 문자열'), 'All providers get the same exact-key selection rule.');
problemsAssert(str_contains($messages[0]['content'], '분석 대상 본문이나 문제의 입력 데이터가 아닙니다'), 'Do not mistake the topic dictionary for the problem statement.');
problemsAssert(str_contains($messages[0]['content'], 'label, 상위 분야, 별칭'), 'Disallow labels, categories and aliases explicitly.');
foreach (['input.problem', 'description, input, output, sample_input, sample_output, hint',
    '유사 문제를 대신 분석하지', 'current_metadata', '본문에 없는', '역할 변경',
    '이미지·외부 링크', '부족하거나 충돌하는 근거', '실제 요구사항을 먼저', '근거 없는 주장은 제거'] as $rule) {
    problemsAssert(str_contains($messages[0]['content'], $rule), 'Keep the shared metadata grounding rule: ' . $rule);
}
problemsAssert(str_contains($schema['properties']['rationale']['description'], '현재 input.problem'), 'Native schemas ground the rationale in the current problem too.');
$groundedProblem = new JudgeProblem(
    new ProblemId(42001), '최단 경로',
    '<p>문자열을 그대로 출력한다. 그래프 탐색은 요구하지 않는다.</p>' . str_repeat('<p>한글 본문</p>', 1000),
    '<p>한 줄에 문자열 S가 주어진다. \\(1 \\le |S| \\le 100\\)</p>',
    '<p>S의 공백과 기호를 변경하지 않고 출력한다.</p>', "a < b\t& c\n", "a < b\t& c\n",
    '<pre>print(input())</pre><img src="https://example.test/unseen.png">', '제목으로 추측하면 안 되는 문제',
    1, 128, false, ProblemVisibility::Published, 0, 0, '', '', '', '', null
);
$groundedInput = [
    'problem' => $inputs->problem($groundedProblem),
    'topic_dictionary' => $inputs->topicDictionary(),
    'output_contract' => $contract,
];
$groundedMessages = $prompt->messages(new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $groundedInput));
$decodedInput = json_decode($groundedMessages[1]['content'], true, 64, JSON_THROW_ON_ERROR);
problemsSame($groundedInput['problem'], $decodedInput['input']['problem'], 'Preserve the full problem through JSON serialization.');
problemsSame(['simulation' => '시뮬레이션'], $decodedInput['input']['topic_dictionary'], 'Only selectable key/label choices reach the model, without category metadata.');
problemsSame(['topic_dictionary', 'problem'], array_keys($decodedInput['input']), 'Keep source evidence last and omit the repeated output schema.');
foreach (['description' => $groundedProblem->description(), 'input' => $groundedProblem->input(),
    'output' => $groundedProblem->output(), 'sample_input' => $groundedProblem->sampleInput(),
    'sample_output' => $groundedProblem->sampleOutput(), 'hint' => $groundedProblem->hint()] as $field => $expected) {
    problemsSame($expected, $decodedInput['input']['problem'][$field], 'Do not truncate or rewrite source facts: ' . $field);
}
problemsSame(42001, $decodedInput['input']['problem']['problem_id'], 'Preserve the target problem identifier.');
problemsSame(['system', 'user'], array_column($groundedMessages, 'role'), 'No prior assistant responses or unrelated conversation may enter metadata requests.');
$helloProblem = new JudgeProblem(
    new ProblemId(42002), 'Hello 출력',
    '<p>printf( ) 함수를 사용해서 Hello 를 출력해 보자.</p><pre>#include &lt;stdio.h&gt;\nint main() { printf("Hello"); return 0; }</pre>',
    '', '<p>Hello를 출력한다.</p>', '', 'Hello', '<p>대소문자를 정확하게 구분한다.</p>', '',
    1, 128, false, ProblemVisibility::Published, 12, 20, '', '', '', '', null
);
$gateway->items[42002] = $helloProblem;
$wrongMetadata = new ProblemMetadata($helloProblem->id(), new DifficultyScore(3), ['dynamic-programming', 'memoization', 'knapsack'], CodeEntryMode::FullCode, null, null, 'ai', '', 1);
$metadata->save($wrongMetadata);
$helloInput = [
    'contract_version' => ProblemAiInputFactory::METADATA_CONTRACT_VERSION,
    'problem' => $inputs->problem($helloProblem, $wrongMetadata),
    'topic_dictionary' => $inputs->topicDictionary(),
];
$helloHash = $inputs->hash($helloInput);
$helloItem = new JobWorkItem(problemAiClaim('problem_metadata_generate', '42002', $helloHash), [
    'problem_id' => 42002, 'problem_input_hash' => $helloHash, 'input' => $helloInput, 'assignment' => $assignment->toArray(),
]);
$router->outputs['problem_metadata_generate'] = $output;
problemsSame('succeeded', $handler->handle($helloItem)->outcome(), 'Incorrect prior metadata does not prevent regeneration from saved source.');
$helloRequest = $router->requests[array_key_last($router->requests)];
$helloMessages = $prompt->messages($helloRequest);
$helloWire = json_decode($helloMessages[1]['content'], true, 64, JSON_THROW_ON_ERROR)['input'];
foreach (['current_metadata', 'accepted_count', 'submitted_count'] as $excluded) {
    problemsAssert(! array_key_exists($excluded, $helloWire['problem']), 'Do not anchor regenerated metadata to existing values: ' . $excluded);
}
problemsSame($helloProblem->description(), $helloWire['problem']['description'], 'The actual Hello statement, not a generation example, reaches the model.');
problemsSame('Hello', $helloWire['problem']['sample_output'], 'Keep literal expected output for source-grounded analysis.');
problemsSame($helloHash, $inputs->hash($helloItem->payload()['input']), 'Removing model hints must not change the original queued snapshot.');
problemsSame($wrongMetadata, $metadata->find($helloProblem->id()), 'Generation must not automatically overwrite stored metadata.');
$fullDictionary = ['version' => 'seed', 'topics' => array_map(
    static fn (array $topic): array => [
        'key' => $topic['key'], 'label' => $topic['label'], 'category_key' => $topic['category'],
        'selectable' => $topic['category'] !== null,
    ], require $pluginRoot . '/config/problem-topics.php'
)];
$fullInput = $helloRequest->input();
$fullInput['topic_dictionary'] = $fullDictionary;
$fullInput['output_contract'] = $inputs->metadataOutputContract($fullDictionary);
$fullRequest = new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $fullInput);
$fullMessages = $prompt->messages($fullRequest);
$fullWire = json_decode($fullMessages[1]['content'], true, 64, JSON_THROW_ON_ERROR)['input'];
problemsSame($helloProblem->description(), $fullWire['problem']['description'], 'The full installed topic dictionary must not displace source content.');
problemsSame('출력', $fullWire['topic_dictionary']['output'], 'Keep the actual basic-output choice, not a shortened or invented taxonomy.');
problemsSame(count(array_filter($fullDictionary['topics'], static fn (array $topic): bool => $topic['selectable'])), count($fullWire['topic_dictionary']), 'Preserve every allowed choice when compacting the dictionary.');
$beforeBytes = strlen(json_encode(['contract_version' => $fullRequest->contractVersion(), 'input' => $fullInput], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$afterBytes = strlen($fullMessages[1]['content']);
problemsAssert($afterBytes < $beforeBytes / 2, 'Remove redundant schema/category tokens without cutting the problem or selection list.');
problemsSame($fullInput, $fullRequest->input(), 'Prompt projection must not mutate queue contracts or native response schemas.');
foreach ([[], ['problem' => []], ['problem' => ['title' => 'Only title', 'sample_output' => 'Hello']],
    ['problem' => ['description' => '<p>&nbsp;<br></p>']], ['problem' => ['description' => '<img src="missing.png">']],
    ['problem' => ['description' => []]]] as $emptyInput) {
    try {
        $prompt->messages(new AiTaskRequest('problem_metadata_generate', 'problem-metadata-v1', $emptyInput));
        throw new RuntimeException('A taxonomy or title alone must not be classified as a problem.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), '문제 본문'), 'Explain missing source before a provider call.');
    }
}
echo "Metadata user message: $beforeBytes -> $afterBytes bytes with full topic dictionary (not a token count).\n";
problemsSame(true, $prompt->probeInput('problem_metadata_generate')['topic_dictionary']['topics'][1]['selectable'], 'Probe dictionaries use the same input shape.');
$probeInput = $prompt->probeInput('problem_metadata_generate');
problemsSame($inputs->metadataOutputContract($probeInput['topic_dictionary']), $probeInput['output_contract'], 'Probe and generation share one metadata output contract definition.');
foreach ([0, 1, 0.8, '0.8'] as $confidence) {
    $prompt->assertProbeOutput('problem_metadata_generate', array_replace($output, ['confidence' => $confidence]));
    problemsAssert(true, 'Complete metadata passes the connection probe.');
}
foreach ([$missingConfidence, array_diff_key($output, ['rationale' => true]),
    array_replace($output, ['confidence' => null]), array_replace($output, ['confidence' => '80%']),
    array_replace($output, ['confidence' => true]), array_replace($output, ['confidence' => 1.01]),
    array_replace($output, ['confidence' => INF]), array_replace($output, ['confidence' => '1e999']),
    array_replace($output, ['rationale' => ' ']), array_replace($output, ['rationale' => []]),
] as $invalidProbe) {
    try {
        $prompt->assertProbeOutput('problem_metadata_generate', $invalidProbe);
        throw new RuntimeException('Incomplete or invalid metadata must not pass the connection probe.');
    } catch (DomainException) {
        problemsAssert(true, 'Connection probes enforce confidence and rationale, too.');
    }
}
$jobItem = new JobItemDetails(1, 'problem', (string) $bulkApplyProblemId->value(), 'needs_attention', 1, 3, $failed->resultCode(), $failed->message(), $failed->safeResult());
$confidenceItem = new JobItemDetails(2, 'problem', '1002', 'needs_attention', 1, 3, $invalidConfidenceResult->resultCode(), $invalidConfidenceResult->message(), []);
$providerItem = new JobItemDetails(3, 'problem', '1003', 'needs_attention', 1, 3, 'problem.ai_provider_failed', 'Ollama 응답: JSON 문법 오류 (본문 320바이트). 구조화 출력 지원을 확인해 주세요.', []);
$job = new JobDetails(1, 'problem_metadata_generate', 'problems', 'problems.metadata.generate', '1', 1, 'needs_attention', 3, 0, 3, 0, '2026-09-17 00:00:00', null, [], [], [$jobItem, $confidenceItem, $providerItem], []);
$reflection = new ReflectionClass(JobDetailsPageHandler::class);
$page = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('access')->setValue($page, new JobAccessPolicy(new JobAccessRuleRegistry([])));
$html = $reflection->getMethod('items')->invoke($page, $job, 1);
foreach (['주제 검증 상세', 'AI 반환 키', '등록 주제명', '거부 사유', 'implementation', 'retired', '&lt;script&gt;'] as $text) {
    problemsAssert(str_contains($html, $text), 'Render diagnosis: ' . $text);
}
problemsAssert(! str_contains($html, '<script>'), 'Escape AI output instead of rendering executable HTML.');
problemsAssert(! str_contains($html, 'draft_reference'), 'Invalid results must not have an applicable draft.');
problemsAssert(str_contains($html, '반환 형식: null'), 'Render the confidence diagnosis in the existing result table.');
problemsAssert(str_contains($html, 'JSON 문법 오류'), 'Render the provider response diagnosis without raw output.');
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $directory = $argv[2];
    if (! is_dir($directory)) mkdir($directory, 0777, true);
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    file_put_contents($directory . '/topic-validation.html', '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>주제 검증 상세</title><style>' . $css . '</style></head><body class="ojp-app"><main class="ojp-main"><h1>문제 메타데이터 생성</h1>' . $html . '</main></body></html>');
}
echo "Problem AI topics: $assertions checks passed.\n";

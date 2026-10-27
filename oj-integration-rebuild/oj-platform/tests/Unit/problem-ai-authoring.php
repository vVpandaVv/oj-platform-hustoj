<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiInputFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobSupport;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAuthoringGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAuthoringOutputContract;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiStructuredOutput;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;

require __DIR__ . '/problems.php';
$assertions = 0;
$hello = [
    'title' => ' Hello 출력 ', 'description' => 'Hello를 출력하세요.',
    'input' => '', 'output' => 'Hello를 출력합니다.',
    'sample_input' => '', 'sample_output' => "Hello\n", 'hint' => '',
];
$validated = $aiOutputs->authoring($hello, 'full_draft');
problemsSame('', $validated['input'], 'A no-input problem may have an empty input description.');
problemsSame('', $validated['sample_input'], 'A no-input problem must allow empty sample input.');
problemsSame("Hello\n", $validated['sample_output'], 'Preserve output whitespace.');
problemsSame('Hello 출력', $validated['title'], 'Only trim the title.');
problemsSame("  1\n\n", $aiOutputs->authoring(array_replace($hello, ['sample_input' => "  1\n\n"]), 'full_draft')['sample_input'], 'Never trim sample input.');
problemsSame(['title' => '제목'], $aiOutputs->authoring(['title' => '제목'], 'title_only'), 'Title-only does not require full-draft fields.');

foreach (array_keys($hello) as $key) {
    $missing = $hello;
    unset($missing[$key]);
    try {
        $aiOutputs->authoring($missing, 'full_draft');
        throw new RuntimeException('A required field must not be synthesized.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), '(' . $key . ')') && str_contains($error->getMessage(), '누락'), 'Name the missing field.');
    }
}
foreach ([null, [], ['PRIVATE_RESPONSE'], ['description' => 'PRIVATE_RESPONSE'], (object) ['secret' => 'PRIVATE_RESPONSE'], true, 3, 0.5] as $value) {
    try {
        $aiOutputs->authoring(array_replace($hello, ['input' => $value]), 'full_draft');
        throw new RuntimeException('Non-string descriptions must fail without coercion.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), '입력 설명(input)') && str_contains($error->getMessage(), '반환 형식:'), 'Name the invalid field and its safe type.');
        problemsAssert(! str_contains($error->getMessage(), 'PRIVATE_RESPONSE') && strlen($error->getMessage()) <= 191, 'Do not expose AI response contents in status.');
    }
}
foreach (['title', 'description', 'output', 'sample_output'] as $key) {
    try {
        $aiOutputs->authoring(array_replace($hello, [$key => " \n"]), 'full_draft');
        throw new RuntimeException('Required content must not be empty.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), '비어'), 'Explain empty required content.');
    }
}
foreach ([["\xFF", 'UTF-8'], [str_repeat('x', 262145), '262144바이트']] as [$value, $reason]) {
    try {
        $aiOutputs->authoring(array_replace($hello, ['input' => $value]), 'full_draft');
        throw new RuntimeException('Invalid encoding or size must fail.');
    } catch (DomainException $error) {
        problemsAssert(str_contains($error->getMessage(), $reason), 'Explain encoding and size failures separately.');
    }
}

$schema = AiStructuredOutput::fromInput(['output_contract' => ProblemAuthoringOutputContract::forMode('full_draft')]);
problemsSame(array_keys($hello), $schema['required'], 'The full draft must require all seven keys.');
problemsSame(false, $schema['additionalProperties'], 'The model cannot invent additional fields.');
foreach (array_keys($hello) as $key) {
    problemsSame('string', $schema['properties'][$key]['type'], 'Every authoring field is a string.');
}
problemsAssert(! isset($schema['properties']['description']['maxLength']), 'Large byte limits belong to server validation, not native schema character repetitions.');
problemsSame(['title'], ProblemAuthoringOutputContract::forMode('title_only')['json_schema']['required'], 'Title-only uses a smaller schema.');
$prompts = new AiTaskPromptFactory();
$probe = $prompts->probeInput('problem_authoring_generate');
problemsSame(ProblemAuthoringOutputContract::forMode('title_only'), $probe['output_contract'], 'Candidate probes use the shared authoring contract.');
$messages = $prompts->messages(new AiTaskRequest('problem_authoring_generate', ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION, ['mode' => 'full_draft', 'brief' => 'Hello 출력']));
problemsAssert(str_contains($messages[0]['content'], '배열·객체·숫자·null') && str_contains($messages[0]['content'], '입력이 없는 문제'), 'The prompt must describe string types and no-input semantics.');

$router = new ProblemsAiRouter();
$drafts = new MemoryProblemFormDrafts();
$handler = new ProblemAuthoringGenerateJobHandler($gateway, $metadata, $access, $aiInputs, $aiOutputs, new ProblemAiJobSupport($aiInputs, $router), $drafts);
$beforeProblems = count($gateway->items);
foreach (['full_draft', 'title_only'] as $mode) {
    $input = [
        'contract_version' => ProblemAiInputFactory::AUTHORING_CONTRACT_VERSION,
        'mode' => $mode, 'brief' => 'Hello를 출력하는 입문 문제',
        'requested_difficulty_score' => 1, 'requested_topic_keys' => [],
        'topic_dictionary' => $aiInputs->topicDictionary(), 'existing_problem' => null,
    ];
    $hash = $aiInputs->hash($input);
    $item = new JobWorkItem(problemAiClaim('problem_authoring_generate', 'new-' . substr($hash, 0, 32), $hash), [
        'problem_id' => null, 'problem_input_hash' => $hash, 'input' => $input,
        'assignment' => $router->snapshot('problem_authoring_generate')->toArray(),
    ]);
    $router->outputs['problem_authoring_generate'] = $mode === 'full_draft' ? $hello : ['title' => 'Hello'];
    problemsSame('succeeded', $handler->handle($item)->outcome(), 'The job must accept a valid no-input or title-only draft.');
    problemsSame(ProblemAuthoringOutputContract::forMode($mode), $router->requests[array_key_last($router->requests)]->input()['output_contract'], 'The real job must pass the mode-specific native schema.');
    problemsSame($hash, $aiInputs->hash($item->payload()['input']), 'Do not change queued input hashes.');
    problemsAssert(! isset($item->payload()['input']['output_contract']), 'Derive the schema only for the provider request.');
    $beforeDrafts = count($drafts->items);
    if ($mode === 'full_draft') {
        $router->outputs['problem_authoring_generate'] = array_replace($hello, ['input' => ['PRIVATE_RESPONSE']]);
        $beforeCalls = count($router->requests);
        $failed = $handler->handle($item);
        problemsSame('needs_attention', $failed->outcome(), 'Malformed output requires explicit review, not silent coercion.');
        problemsSame('problem.ai_contract_failed', $failed->resultCode(), 'Keep the existing job error contract.');
        problemsSame($beforeDrafts, count($drafts->items), 'Never store a malformed draft.');
        problemsSame($beforeCalls + 1, count($router->requests), 'No hidden extra provider calls.');
        problemsAssert(! str_contains(json_encode($failed->safeResult()), 'PRIVATE_RESPONSE'), 'Do not expose raw output in job results.');
    }
}
problemsSame($beforeProblems, count($gateway->items), 'Generating drafts must not create or overwrite judge problems.');
fwrite(STDOUT, "Problem AI authoring: $assertions checks passed.\n");

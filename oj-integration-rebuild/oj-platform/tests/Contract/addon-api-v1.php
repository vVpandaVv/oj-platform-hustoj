<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskInstructionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionAvailabilityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeMaterialV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionTemplateResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAssemblyResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingPuzzleResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonExtensionRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchExtensionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetConfigProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function addonApiAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function addonApiSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' 예상=' . var_export($expected, true)
            . ' 실제=' . var_export($actual, true)
        );
    }
}

function addonApiThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function addonApiType(?ReflectionType $type): string
{
    if ($type === null) {
        return '';
    }
    if (! $type instanceof ReflectionNamedType) {
        return (string) $type;
    }

    $name = $type->getName();

    return $type->allowsNull() && $name !== 'mixed' && $name !== 'null'
        ? '?' . $name
        : $name;
}

/**
 * @param list<array{0:string,1:string,2?:mixed}> $parameters
 */
function addonApiAssertMethod(
    ReflectionClass $class,
    string $methodName,
    string $returnType,
    array $parameters,
    bool $static = false,
    bool $public = true
): void {
    addonApiAssert($class->hasMethod($methodName), $class->getName() . '::' . $methodName . '가 없습니다.');
    $method = $class->getMethod($methodName);
    addonApiSame($public, $method->isPublic(), $class->getName() . '::' . $methodName . ' 공개 범위가 다릅니다.');
    addonApiSame($static, $method->isStatic(), $class->getName() . '::' . $methodName . ' static 계약이 다릅니다.');
    addonApiSame($returnType, addonApiType($method->getReturnType()), $class->getName() . '::' . $methodName . ' 반환형이 다릅니다.');
    $actualParameters = $method->getParameters();
    addonApiSame(count($parameters), count($actualParameters), $class->getName() . '::' . $methodName . ' 인자 수가 다릅니다.');
    foreach ($parameters as $index => $expected) {
        $actual = $actualParameters[$index];
        addonApiSame($expected[0], $actual->getName(), $class->getName() . '::' . $methodName . ' 인자 이름이 다릅니다.');
        addonApiSame($expected[1], addonApiType($actual->getType()), $class->getName() . '::' . $methodName . ' 인자형이 다릅니다.');
        $hasDefault = array_key_exists(2, $expected);
        addonApiSame($hasDefault, $actual->isDefaultValueAvailable(), $class->getName() . '::' . $methodName . ' 기본값 계약이 다릅니다.');
        if ($hasDefault) {
            addonApiSame($expected[2], $actual->getDefaultValue(), $class->getName() . '::' . $methodName . ' 기본값이 다릅니다.');
        }
    }
}

$namespace = 'CodingPanda\\OjPlatform\\AddonApi\\V1\\';
$interfaces = [
    AddonExtensionRegistrationGatewayV1::class => [
        'apiVersion' => ['string', []],
        'registerResearch' => ['void', [['extension', ResearchExtensionV1::class]]],
    ],
    AddonRegistrationGatewayV1::class => [
        'apiVersion' => ['string', []],
        'register' => ['void', [['addonId', 'string'], ['build', 'callable']]],
    ],
    AddonManifestBuilderV1::class => [
        'onReady' => ['void', [['callback', 'callable']]],
        'manifest' => ['void', [
            ['namespace', 'string'], ['version', 'string'], ['pluginFile', 'string'],
            ['requiresPlatform', 'string'], ['requiresAddonApi', 'string'],
            ['diagnosticLabel', 'string'], ['supportReference', 'string'],
        ]],
        'capability' => ['void', [['definition', AddonCapabilityDefinitionV1::class]]],
        'consolePage' => ['void', [['definition', AddonConsolePageDefinitionV1::class]]],
        'publicPage' => ['void', [['definition', AddonPublicPageDefinitionV1::class]]],
        'consoleMenu' => ['void', [['definition', AddonConsoleMenuDefinitionV1::class]]],
        'publicMenu' => ['void', [['definition', AddonPublicMenuDefinitionV1::class]]],
        'assetBundle' => ['void', [['definition', AddonAssetBundleDefinitionV1::class]]],
        'aiTask' => ['void', [['definition', AddonAiTaskDefinitionV1::class]]],
    ],
    AddonIntegrationManifestBuilderV1::class => [
        'onReady' => ['void', [['callback', 'callable']]],
        'manifest' => ['void', [
            ['namespace', 'string'], ['version', 'string'], ['pluginFile', 'string'],
            ['requiresPlatform', 'string'], ['requiresAddonApi', 'string'],
            ['diagnosticLabel', 'string'], ['supportReference', 'string'],
        ]],
        'capability' => ['void', [['definition', AddonCapabilityDefinitionV1::class]]],
        'consolePage' => ['void', [['definition', AddonConsolePageDefinitionV1::class]]],
        'publicPage' => ['void', [['definition', AddonPublicPageDefinitionV1::class]]],
        'consoleMenu' => ['void', [['definition', AddonConsoleMenuDefinitionV1::class]]],
        'publicMenu' => ['void', [['definition', AddonPublicMenuDefinitionV1::class]]],
        'assetBundle' => ['void', [['definition', AddonAssetBundleDefinitionV1::class]]],
        'aiTask' => ['void', [['definition', AddonAiTaskDefinitionV1::class]]],
        'activityProvider' => ['void', [['definition', AddonActivityProviderDefinitionV1::class]]],
        'siteAsset' => ['void', [['definition', AddonSiteAssetDefinitionV1::class]]],
        'privacyData' => ['void', [['definition', AddonPrivacyDataDefinitionV1::class]]],
    ],
    AddonConsoleLocalNavBuilderV1::class => [
        'consoleLocalNav' => ['void', [['definition', AddonConsoleLocalNavItemV1::class]]],
    ],
    AddonPageHandlerV1::class => [
        'handle' => [AddonPageResponseV1::class, [['request', AddonPageRequestV1::class]]],
    ],
    AddonRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
    ],
    AddonIntegrationRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'integrations' => [AddonIntegrationGatewayV1::class, []],
    ],
    AddonLearningCheckRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'learningChecks' => [AddonLearningCheckGatewayV1::class, []],
    ],
    AddonLearningCheckGatewayV1::class => [
        'context' => [AddonLearningCheckContextResultV1::class, [['query', AddonLearningCheckContextQueryV1::class]]],
    ],
    AddonSubmissionDraftRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'submissionDrafts' => [AddonSubmissionDraftGatewayV1::class, []],
    ],
    AddonSubmissionDraftGatewayV1::class => [
        'prepare' => [AddonSubmissionDraftResultV1::class, [['request', AddonSubmissionDraftRequestV1::class]]],
    ],
    AddonCodeOrderingRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'codeOrdering' => [AddonCodeOrderingGatewayV1::class, []],
    ],
    AddonCodeOrderingGatewayV1::class => [
        'puzzle' => [AddonCodeOrderingPuzzleResultV1::class, [['query', AddonCodeOrderingQueryV1::class]]],
        'assemble' => [AddonCodeOrderingAssemblyResultV1::class, [['answer', AddonCodeOrderingAnswerV1::class]]],
    ],
    AddonCodeCompletionRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'codeCompletion' => [AddonCodeCompletionGatewayV1::class, []],
    ],
    AddonCodeCompletionGatewayV1::class => [
        'template' => [AddonCodeCompletionTemplateResultV1::class, [['query', AddonCodeCompletionQueryV1::class]]],
        'assemble' => [AddonCodeCompletionAssemblyResultV1::class, [['answer', AddonCodeCompletionAnswerV1::class]]],
    ],
    AddonCodeExecutionRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'codeExecution' => [AddonCodeExecutionGatewayV1::class, []],
    ],
    AddonCodeExecutionGatewayV1::class => [
        'availability' => [AddonCodeExecutionAvailabilityV1::class, []],
        'submit' => [AddonCodeExecutionResultV1::class, [['request', AddonCodeExecutionRequestV1::class]]],
        'poll' => [AddonCodeExecutionResultV1::class, [['query', AddonCodeExecutionQueryV1::class]]],
    ],
    AddonPracticeRuntimeContextV1::class => [
        'snapshot'=>[AddonRuntimeSnapshotV1::class,[]],'contexts'=>[AddonContextGatewayV1::class,[]],
        'aiTasks'=>[AddonAiTaskGatewayV1::class,[]],'practice'=>[AddonPracticeGatewayV1::class,[]],
    ],
    AddonPracticeGatewayV1::class => [
        'availability'=>[AddonCodeExecutionAvailabilityV1::class,[['material',AddonPracticeMaterialV1::class]]],
        'submit'=>[AddonCodeExecutionResultV1::class,[['request',AddonPracticeRequestV1::class]]],
        'poll'=>[AddonCodeExecutionResultV1::class,[['query',AddonPracticeQueryV1::class]]],
    ],
    AddonActivityProviderV1::class => [
        'search' => [AddonActivityPageV1::class, [['query', AddonActivityQueryV1::class]]],
    ],
    AddonSiteAssetConfigProviderV1::class => [
        'config' => ['array', [['actorUserId', 'int']]],
    ],
    AddonIntegrationGatewayV1::class => [
        'searchActivities' => [AddonActivityPageV1::class, [['query', AddonActivityQueryV1::class]]],
        'searchProblems' => [AddonProblemPageV1::class, [['query', AddonProblemQueryV1::class]]],
        'acceptedSubmissions' => [AddonAcceptedSubmissionPageV1::class, [['query', AddonAcceptedSubmissionQueryV1::class]]],
        'privacyManagementPath' => ['string', []],
    ],
    AddonEditorRuntimeContextV1::class => [
        'snapshot' => [AddonRuntimeSnapshotV1::class, []],
        'contexts' => [AddonContextGatewayV1::class, []],
        'aiTasks' => [AddonAiTaskGatewayV1::class, []],
        'editor' => [AddonEditorGatewayV1::class, []],
    ],
    AddonEditorGatewayV1::class => [
        'current' => [AddonEditorSnapshotV1::class, []],
    ],
    AddonContextGatewayV1::class => [
        'listTeachingContexts' => [AddonContextPageV1::class, [['query', AddonTeachingContextQueryV1::class]]],
        'authorize' => [AddonContextAccessDecisionV1::class, [['request', AddonContextAccessRequestV1::class]]],
        'snapshot' => [AddonContextSnapshotResultV1::class, [['request', AddonContextSnapshotRequestV1::class]]],
    ],
    AddonLearningContextGatewayV1::class => [
        'listTeachingContexts' => [AddonContextPageV1::class, [['query', AddonTeachingContextQueryV1::class]]],
        'authorize' => [AddonContextAccessDecisionV1::class, [['request', AddonContextAccessRequestV1::class]]],
        'snapshot' => [AddonContextSnapshotResultV1::class, [['request', AddonContextSnapshotRequestV1::class]]],
        'listLearningContexts' => [AddonContextPageV1::class, [['query', AddonLearningContextQueryV1::class]]],
    ],
    AddonAssignmentRosterGatewayV1::class => [
        'listTeachingContexts' => [AddonContextPageV1::class, [['query', AddonTeachingContextQueryV1::class]]],
        'authorize' => [AddonContextAccessDecisionV1::class, [['request', AddonContextAccessRequestV1::class]]],
        'snapshot' => [AddonContextSnapshotResultV1::class, [['request', AddonContextSnapshotRequestV1::class]]],
        'listAssignmentLearners' => [AddonAssignmentLearnerPageV1::class, [['query', AddonAssignmentRosterQueryV1::class]]],
    ],
    AddonAiTaskGatewayV1::class => [
        'canRoute' => [AddonAiTaskRouteDecisionV1::class, [['taskKey', 'string']]],
        'snapshot' => [AddonAiTaskRoutingSnapshotV1::class, [['taskKey', 'string']]],
        'execute' => [AddonAiTaskResultV1::class, [['request', AddonAiTaskRequestV1::class]]],
    ],
];

foreach ($interfaces as $interfaceName => $methods) {
    $reflection = new ReflectionClass($interfaceName);
    addonApiAssert($reflection->isInterface(), $interfaceName . '는 interface여야 합니다.');
    $actual = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
    );
    sort($actual);
    $expected = array_keys($methods);
    sort($expected);
    addonApiSame($expected, $actual, $interfaceName . ' 공개 메서드 allowlist가 다릅니다.');
    foreach ($methods as $method => [$returnType, $parameters]) {
        addonApiAssertMethod($reflection, $method, $returnType, $parameters);
    }
}

$classes = [
    AddonSubmissionDraftRequestV1::class => [
        [['actorUserId','int'],['problemId','int'],['contextType','string'],['contextId','int'],['language','string'],['source','string']],
        ['actorUserId'=>'int','problemId'=>'int','contextType'=>'string','contextId'=>'int','language'=>'string','source'=>'string'],
    ],
    AddonSubmissionDraftResultV1::class => [
        [['successful','bool'],['payload','array'],['problem','?' . AddonApiProblemV1::class]],
        ['isSuccessful'=>'bool','payload'=>'array','problem'=>'?' . AddonApiProblemV1::class],
    ],
    AddonPracticeMaterialV1::class => [
        [['activityKey','string'],['revision','string'],['contextType','string'],['contextId','string'],['language','string'],['tests','array']],
        ['activityKey'=>'string','revision'=>'string','contextType'=>'string','contextId'=>'string','language'=>'string','tests'=>'array'],
    ],
    AddonPracticeRequestV1::class => [
        [['actorUserId','int'],['material',AddonPracticeMaterialV1::class],['source','string'],['testIndex','int'],['idempotencyKey','string']],
        ['actorUserId'=>'int','material'=>AddonPracticeMaterialV1::class,'source'=>'string','testIndex'=>'int','idempotencyKey'=>'string'],
    ],
    AddonPracticeQueryV1::class => [
        [['actorUserId','int'],['material',AddonPracticeMaterialV1::class],['ticket','string']],
        ['actorUserId'=>'int','material'=>AddonPracticeMaterialV1::class,'ticket'=>'string'],
    ],
    AddonCodeExecutionAvailabilityV1::class => [
        [['available', 'bool'], ['languages', 'array'], ['problem', '?' . AddonApiProblemV1::class, null]],
        ['isAvailable' => 'bool', 'languages' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonCodeExecutionRequestV1::class => [
        [['actorUserId', 'int'], ['language', 'string'], ['source', 'string'], ['input', 'string'], ['idempotencyKey', 'string']],
        ['actorUserId' => 'int', 'language' => 'string', 'source' => 'string', 'input' => 'string', 'idempotencyKey' => 'string'],
    ],
    AddonCodeExecutionQueryV1::class => [
        [['actorUserId', 'int'], ['ticket', 'string']], ['actorUserId' => 'int', 'ticket' => 'string'],
    ],
    AddonCodeExecutionResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonEditorSnapshotV1::class => [
        [['theme', 'string'], ['fontSize', 'int'], ['available', 'bool'], ['coreUrl', 'string'], ['baseUrl', 'string']],
        ['theme' => 'string', 'fontSize' => 'int', 'isAvailable' => 'bool', 'coreUrl' => 'string', 'baseUrl' => 'string'],
    ],
    AddonCapabilityDefinitionV1::class => [
        [['key', 'string'], ['label', 'string'], ['audience', 'string']],
        ['key' => 'string', 'label' => 'string', 'audience' => 'string'],
    ],
    AddonConsolePageDefinitionV1::class => [
        [['key', 'string'], ['path', 'string'], ['handler', AddonPageHandlerV1::class], ['capability', 'string'], ['assetBundles', 'array', []]],
        ['key' => 'string', 'path' => 'string', 'handler' => AddonPageHandlerV1::class, 'capability' => 'string', 'assetBundles' => 'array'],
    ],
    AddonPublicPageDefinitionV1::class => [
        [['key', 'string'], ['path', 'string'], ['handler', AddonPageHandlerV1::class], ['capability', 'string'], ['assetBundles', 'array', []]],
        ['key' => 'string', 'path' => 'string', 'handler' => AddonPageHandlerV1::class, 'capability' => 'string', 'assetBundles' => 'array'],
    ],
    AddonConsoleMenuDefinitionV1::class => [
        [['key', 'string'], ['label', 'string'], ['anchor', 'string'], ['route', 'string'], ['capability', 'string'], ['order', 'int'], ['icon', 'string', '']],
        ['key' => 'string', 'label' => 'string', 'anchor' => 'string', 'route' => 'string', 'capability' => 'string', 'order' => 'int', 'icon' => 'string'],
    ],
    AddonConsoleLocalNavItemV1::class => [
        [['key', 'string'], ['label', 'string'], ['menu', 'string'], ['route', 'string'], ['order', 'int']],
        ['key' => 'string', 'label' => 'string', 'menu' => 'string', 'route' => 'string', 'order' => 'int'],
    ],
    AddonPublicMenuDefinitionV1::class => [
        [['key', 'string'], ['label', 'string'], ['parent', 'string'], ['route', 'string'], ['capability', 'string'], ['order', 'int'], ['scope', 'string'], ['mobile', 'bool', true], ['defaultEnabled', 'bool', true]],
        ['key' => 'string', 'label' => 'string', 'parent' => 'string', 'route' => 'string', 'capability' => 'string', 'order' => 'int', 'scope' => 'string', 'mobile' => 'bool', 'defaultEnabled' => 'bool'],
    ],
    AddonAssetBundleDefinitionV1::class => [
        [['key', 'string'], ['handle', 'string'], ['styles', 'array'], ['scripts', 'array'], ['dependencies', 'array'], ['version', 'string']],
        ['key' => 'string', 'handle' => 'string', 'styles' => 'array', 'scripts' => 'array', 'dependencies' => 'array', 'version' => 'string'],
    ],
    AddonAiTaskDefinitionV1::class => [
        [['key', 'string'], ['label', 'string'], ['inputContractVersion', 'string'], ['sourceKind', 'string'], ['instruction', '?' . AddonAiTaskInstructionV1::class, null]],
        ['key' => 'string', 'label' => 'string', 'inputContractVersion' => 'string', 'sourceKind' => 'string', 'instruction' => '?' . AddonAiTaskInstructionV1::class],
    ],
    AddonAiTaskInstructionV1::class => [
        [['contractVersion', 'string'], ['content', 'string']],
        ['contractVersion' => 'string', 'content' => 'string', 'contentHash' => 'string'],
    ],
    AddonPageRequestV1::class => [
        [['routeKey', 'string'], ['actorUserId', 'int'], ['parameters', 'array'], ['query', 'array'], ['locale', 'string'], ['correlationId', 'string']],
        ['routeKey' => 'string', 'actorUserId' => 'int', 'parameters' => 'array', 'query' => 'array', 'locale' => 'string', 'correlationId' => 'string'],
    ],
    AddonRuntimeSnapshotV1::class => [
        [['addonId', 'string'], ['addonVersion', 'string'], ['requiredPlatform', 'string'], ['actualPlatform', 'string'], ['requiredAddonApi', 'string'], ['actualAddonApi', 'string'], ['status', 'string'], ['code', 'string'], ['catalogRevision', 'string'], ['contributionCounts', 'array'], ['correlationId', 'string']],
        ['addonId' => 'string', 'addonVersion' => 'string', 'requiredPlatform' => 'string', 'actualPlatform' => 'string', 'requiredAddonApi' => 'string', 'actualAddonApi' => 'string', 'status' => 'string', 'code' => 'string', 'catalogRevision' => 'string', 'contributionCounts' => 'array', 'correlationId' => 'string'],
    ],
    AddonApiProblemV1::class => [
        [['code', 'string'], ['message', 'string'], ['correlationId', 'string'], ['retryable', 'bool'], ['details', 'array', []]],
        ['code' => 'string', 'message' => 'string', 'correlationId' => 'string', 'retryable' => 'bool', 'details' => 'array'],
    ],
    AddonTeachingContextQueryV1::class => [
        [['actorUserId', 'int'], ['types', 'array'], ['search', 'string', ''], ['cursor', 'string', ''], ['limit', 'int', 30]],
        ['actorUserId' => 'int', 'types' => 'array', 'search' => 'string', 'cursor' => 'string', 'limit' => 'int'],
    ],
    AddonLearningContextQueryV1::class => [
        [['actorUserId', 'int'], ['types', 'array'], ['search', 'string', ''], ['cursor', 'string', ''], ['limit', 'int', 30]],
        ['actorUserId' => 'int', 'types' => 'array', 'search' => 'string', 'cursor' => 'string', 'limit' => 'int'],
    ],
    AddonLearningCheckContextQueryV1::class => [
        [['actorUserId', 'int'], ['problemId', 'int'], ['submissionId', '?int', null], ['contextType', 'string', 'general'], ['contextId', '?int', null], ['projection', 'string', 'full']],
        ['actorUserId' => 'int', 'problemId' => 'int', 'submissionId' => '?int', 'contextType' => 'string', 'contextId' => '?int', 'projection' => 'string'],
    ],
    AddonLearningCheckContextResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonCodeOrderingQueryV1::class => [
        [['actorUserId', 'int'], ['problemId', 'int'], ['contextType', 'string', 'general'], ['contextId', '?int', null]],
        ['actorUserId' => 'int', 'problemId' => 'int', 'contextType' => 'string', 'contextId' => '?int'],
    ],
    AddonCodeOrderingAnswerV1::class => [
        [['actorUserId', 'int'], ['problemId', 'int'], ['templateHash', 'string'], ['blockIds', 'array'], ['contextType', 'string', 'general'], ['contextId', '?int', null]],
        ['actorUserId' => 'int', 'problemId' => 'int', 'templateHash' => 'string', 'blockIds' => 'array', 'contextType' => 'string', 'contextId' => '?int'],
    ],
    AddonCodeOrderingPuzzleResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonCodeOrderingAssemblyResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonCodeCompletionQueryV1::class => [
        [['actorUserId', 'int'], ['problemId', 'int'], ['contextType', 'string', 'general'], ['contextId', '?int', null]],
        ['actorUserId' => 'int', 'problemId' => 'int', 'contextType' => 'string', 'contextId' => '?int'],
    ],
    AddonCodeCompletionAnswerV1::class => [
        [['actorUserId', 'int'], ['problemId', 'int'], ['templateHash', 'string'], ['answers', 'array'], ['contextType', 'string', 'general'], ['contextId', '?int', null]],
        ['actorUserId' => 'int', 'problemId' => 'int', 'templateHash' => 'string', 'answers' => 'array', 'contextType' => 'string', 'contextId' => '?int'],
    ],
    AddonCodeCompletionTemplateResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonCodeCompletionAssemblyResultV1::class => [
        [['successful', 'bool'], ['payload', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'payload' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonAssignmentRosterQueryV1::class => [
        [['actorUserId', 'int'], ['contextType', 'string'], ['contextId', 'string'], ['expectedContextRevision', 'string'], ['expectedRosterRevision', '?string', null], ['cursor', 'string', ''], ['limit', 'int', 100]],
        ['actorUserId' => 'int', 'contextType' => 'string', 'contextId' => 'string', 'expectedContextRevision' => 'string', 'expectedRosterRevision' => '?string', 'cursor' => 'string', 'limit' => 'int'],
    ],
    AddonAssignmentLearnerV1::class => [
        [['learnerUserId', 'int'], ['membershipRevision', 'string'], ['displayName', 'string', ''], ['managementName', 'string', '']],
        ['learnerUserId' => 'int', 'membershipRevision' => 'string', 'displayName' => 'string', 'managementName' => 'string'],
    ],
    AddonActivityProviderDefinitionV1::class => [
        [['key', 'string'], ['provider', AddonActivityProviderV1::class]],
        ['key' => 'string', 'provider' => AddonActivityProviderV1::class],
    ],
    AddonActivityQueryV1::class => [
        [['actorUserId', 'int'], ['search', 'string', ''], ['kinds', 'array', []], ['limit', 'int', 50]],
        ['actorUserId' => 'int', 'search' => 'string', 'kinds' => 'array', 'limit' => 'int'],
    ],
    AddonActivityPageV1::class => [
        [['successful', 'bool'], ['items', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'items' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonActivityV1::class => [
        [['key', 'string'], ['title', 'string'], ['description', 'string'], ['url', 'string'], ['kind', 'string'], ['sourceLabel', 'string'], ['contentType', 'string', '']],
        ['key' => 'string', 'title' => 'string', 'description' => 'string', 'url' => 'string', 'kind' => 'string', 'sourceLabel' => 'string', 'contentType' => 'string'],
    ],
    AddonProblemQueryV1::class => [
        [['actorUserId', 'int'], ['search', 'string', ''], ['limit', 'int', 20]],
        ['actorUserId' => 'int', 'search' => 'string', 'limit' => 'int'],
    ],
    AddonProblemPageV1::class => [
        [['successful', 'bool'], ['items', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'items' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonProblemV1::class => [
        [['problemId', 'int'], ['title', 'string'], ['submitted', 'int'], ['accepted', 'int']],
        ['problemId' => 'int', 'title' => 'string', 'submitted' => 'int', 'accepted' => 'int'],
    ],
    AddonAcceptedSubmissionQueryV1::class => [
        [['actorUserId', 'int'], ['contextType', 'string'], ['contextId', 'string'], ['contextRevision', 'string'], ['learnerUserIds', 'array'], ['problemIds', 'array'], ['submittedFrom', '?string', null], ['submittedUntil', '?string', null]],
        ['actorUserId' => 'int', 'contextType' => 'string', 'contextId' => 'string', 'contextRevision' => 'string', 'learnerUserIds' => 'array', 'problemIds' => 'array', 'submittedFrom' => '?string', 'submittedUntil' => '?string'],
    ],
    AddonAcceptedSubmissionPageV1::class => [
        [['successful', 'bool'], ['items', 'array'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'items' => 'array', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonAcceptedSubmissionV1::class => [
        [['learnerUserId', 'int'], ['problemId', 'int'], ['acceptedAt', 'string']],
        ['learnerUserId' => 'int', 'problemId' => 'int', 'acceptedAt' => 'string'],
    ],
    AddonSiteAssetDefinitionV1::class => [
        [['key', 'string'], ['assetBundle', 'string'], ['configElementId', 'string'], ['configProvider', AddonSiteAssetConfigProviderV1::class]],
        ['key' => 'string', 'assetBundle' => 'string', 'configElementId' => 'string', 'configProvider' => AddonSiteAssetConfigProviderV1::class],
    ],
    AddonPrivacyDataDefinitionV1::class => [
        [['key', 'string'], ['label', 'string'], ['dataCategories', 'array'], ['retention', 'string'], ['administrationNote', 'string']],
        ['key' => 'string', 'label' => 'string', 'dataCategories' => 'array', 'retention' => 'string', 'administrationNote' => 'string'],
    ],
    AddonAssignmentLearnerPageV1::class => [
        [['successful', 'bool'], ['items', 'array'], ['nextCursor', 'string'], ['rosterRevision', '?string'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'items' => 'array', 'nextCursor' => 'string', 'rosterRevision' => '?string', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonContextPageV1::class => [
        [['successful', 'bool'], ['items', 'array'], ['nextCursor', 'string'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'items' => 'array', 'nextCursor' => 'string', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonContextSummaryV1::class => [
        [['type', 'string'], ['id', 'string'], ['label', 'string'], ['lifecycleState', 'string'], ['revision', 'string'], ['attributes', 'array', []]],
        ['type' => 'string', 'id' => 'string', 'label' => 'string', 'lifecycleState' => 'string', 'revision' => 'string', 'attributes' => 'array'],
    ],
    AddonContextAccessRequestV1::class => [
        [['actorUserId', 'int'], ['contextType', 'string'], ['contextId', 'string'], ['operation', 'string'], ['learnerUserId', '?int', null], ['expectedRevision', '?string', null]],
        ['actorUserId' => 'int', 'contextType' => 'string', 'contextId' => 'string', 'operation' => 'string', 'learnerUserId' => '?int', 'expectedRevision' => '?string'],
    ],
    AddonContextAccessDecisionV1::class => [
        [['allowed', 'bool'], ['code', 'string'], ['snapshot', '?' . AddonContextSnapshotV1::class], ['correlationId', 'string']],
        ['isAllowed' => 'bool', 'code' => 'string', 'snapshot' => '?' . AddonContextSnapshotV1::class, 'correlationId' => 'string'],
    ],
    AddonContextSnapshotRequestV1::class => [
        [['actorUserId', 'int'], ['contextType', 'string'], ['contextId', 'string'], ['expectedRevision', 'string']],
        ['actorUserId' => 'int', 'contextType' => 'string', 'contextId' => 'string', 'expectedRevision' => 'string'],
    ],
    AddonContextSnapshotResultV1::class => [
        [['successful', 'bool'], ['snapshot', '?' . AddonContextSnapshotV1::class], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'snapshot' => '?' . AddonContextSnapshotV1::class, 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonContextSnapshotV1::class => [
        [['type', 'string'], ['id', 'string'], ['label', 'string'], ['lifecycleState', 'string'], ['revision', 'string'], ['actorRelation', 'string'], ['attributes', 'array', []]],
        ['type' => 'string', 'id' => 'string', 'label' => 'string', 'lifecycleState' => 'string', 'revision' => 'string', 'actorRelation' => 'string', 'attributes' => 'array'],
    ],
    AddonAiTaskRouteDecisionV1::class => [
        [['canRoute', 'bool'], ['code', 'string'], ['routingRevision', 'string'], ['correlationId', 'string']],
        ['canRoute' => 'bool', 'code' => 'string', 'routingRevision' => 'string', 'correlationId' => 'string'],
    ],
    AddonAiTaskRoutingSnapshotV1::class => [
        [['available', 'bool'], ['taskKey', 'string'], ['providerKey', 'string'], ['modelKey', 'string'], ['profileFingerprint', 'string'], ['routingRevision', 'string'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isAvailable' => 'bool', 'taskKey' => 'string', 'providerKey' => 'string', 'modelKey' => 'string', 'profileFingerprint' => 'string', 'routingRevision' => 'string', 'problem' => '?' . AddonApiProblemV1::class],
    ],
    AddonAiTaskRequestV1::class => [
        [['taskKey', 'string'], ['contractVersion', 'string'], ['actorUserId', 'int'], ['idempotencyKey', 'string'], ['input', 'array']],
        ['taskKey' => 'string', 'contractVersion' => 'string', 'actorUserId' => 'int', 'idempotencyKey' => 'string', 'input' => 'array'],
    ],
    AddonAiTaskResultV1::class => [
        [['successful', 'bool'], ['output', 'array'], ['routingSnapshot', AddonAiTaskRoutingSnapshotV1::class], ['correlationId', 'string'], ['problem', '?' . AddonApiProblemV1::class]],
        ['isSuccessful' => 'bool', 'output' => 'array', 'routingSnapshot' => AddonAiTaskRoutingSnapshotV1::class, 'correlationId' => 'string', 'problem' => '?' . AddonApiProblemV1::class],
    ],
];

foreach ($classes as $className => [$constructor, $getters]) {
    $reflection = new ReflectionClass($className);
    addonApiAssert($reflection->isFinal(), $className . '는 final이어야 합니다.');
    addonApiAssert(! $reflection->isInterface(), $className . '는 class여야 합니다.');
    addonApiAssertMethod($reflection, '__construct', '', $constructor);
    foreach ($reflection->getProperties() as $property) {
        addonApiAssert($property->isPrivate(), $className . ' 속성은 private이어야 합니다.');
        addonApiAssert($property->isReadOnly(), $className . ' 속성은 readonly여야 합니다.');
        addonApiAssert(! $property->isStatic(), $className . ' 속성은 static일 수 없습니다.');
    }
    $publicMethods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getName() !== '__construct') {
            $publicMethods[] = $method->getName();
        }
    }
    sort($publicMethods);
    $expectedMethods = array_keys($getters);
    sort($expectedMethods);
    addonApiSame($expectedMethods, $publicMethods, $className . ' 공개 getter allowlist가 다릅니다.');
    foreach ($getters as $getter => $returnType) {
        addonApiAssertMethod($reflection, $getter, $returnType, []);
    }
}

$pageResponse = new ReflectionClass(AddonPageResponseV1::class);
addonApiAssert($pageResponse->isFinal(), 'AddonPageResponseV1은 final이어야 합니다.');
addonApiAssert($pageResponse->getConstructor()?->isPrivate() === true, 'AddonPageResponseV1 생성자는 private이어야 합니다.');
foreach ($pageResponse->getProperties() as $property) {
    addonApiAssert($property->isPrivate() && $property->isReadOnly(), 'AddonPageResponseV1 속성 계약이 다릅니다.');
}
$responseMethods = [
    'html' => [AddonPageResponseV1::class, [['title', 'string'], ['body', 'string'], ['status', 'int', 200]], true],
    'page' => [AddonPageResponseV1::class, [['title', 'string'], ['body', 'string'], ['layout', 'string', 'standard'], ['supportsDarkMode', 'bool', false], ['status', 'int', 200]], true],
    'layout' => ['string', [], false],
    'supportsDarkMode' => ['bool', [], false],
    'redirect' => [AddonPageResponseV1::class, [['route', 'string'], ['parameters', 'array', []]], true],
    'unavailable' => [AddonPageResponseV1::class, [['message', 'string'], ['code', 'string']], true],
    'kind' => ['string', [], false],
    'title' => ['?string', [], false],
    'body' => ['?string', [], false],
    'status' => ['int', [], false],
    'route' => ['?string', [], false],
    'parameters' => ['array', [], false],
    'message' => ['?string', [], false],
    'code' => ['?string', [], false],
];
$actualResponseMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    $pageResponse->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($actualResponseMethods);
$expectedResponseMethods = array_keys($responseMethods);
sort($expectedResponseMethods);
addonApiSame($expectedResponseMethods, $actualResponseMethods, 'AddonPageResponseV1 공개 메서드 allowlist가 다릅니다.');
foreach ($responseMethods as $method => [$returnType, $parameters, $static]) {
    addonApiAssertMethod($pageResponse, $method, $returnType, $parameters, $static);
}

$capabilityKey = new ReflectionClass(AddonCapabilityKeyV1::class);
addonApiAssert($capabilityKey->isFinal(), 'AddonCapabilityKeyV1은 final이어야 합니다.');
addonApiAssert($capabilityKey->getConstructor()?->isPrivate() === true, 'AddonCapabilityKeyV1 생성자는 private이어야 합니다.');
addonApiSame(
    ['wordpress'],
    array_map(static fn (ReflectionMethod $method): string => $method->getName(), $capabilityKey->getMethods(ReflectionMethod::IS_PUBLIC)),
    'AddonCapabilityKeyV1 공개 메서드 allowlist가 다릅니다.'
);
addonApiAssertMethod($capabilityKey, 'wordpress', 'string', [['declaredKey', 'string']], true);

$expectedFiles = [];
foreach ([...array_keys($interfaces), ...array_keys($classes), AddonPageResponseV1::class, AddonCapabilityKeyV1::class] as $symbol) {
    addonApiAssert(str_starts_with($symbol, $namespace), '공개 namespace가 올바르지 않습니다: ' . $symbol);
    $expectedFiles[] = substr($symbol, strlen($namespace)) . '.php';
}
sort($expectedFiles);
$actualFiles = array_map('basename', glob($pluginRoot . '/src/AddonApi/V1/*.php') ?: []);
sort($actualFiles);
addonApiSame($expectedFiles, $actualFiles, 'Addon API v1 공개 파일 allowlist가 다릅니다.');

$pluginSource = (string) file_get_contents($pluginRoot . '/oj-platform.php');
addonApiAssert(
    str_contains($pluginSource, "define('OJ_PLATFORM_ADDON_API_VERSION', '1.8.0');"),
    'Addon API 1.8.0 공개 상수가 없습니다.'
);

$baseline = json_decode(
    (string) file_get_contents($pluginRoot . '/docs/artifacts/c-28/baseline.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
addonApiSame(152, $baseline['core_contract']['routes'] ?? null, 'C28-I01 Route 기준선이 다릅니다.');
addonApiSame(0, $baseline['core_contract']['public_addon_api_symbols'] ?? null, 'C28-I01 공개 API 기준선이 다릅니다.');
addonApiSame(40, $baseline['verification']['passed'] ?? null, 'C28-I01 fast test 기준선이 다릅니다.');

$handler = new class implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        return AddonPageResponseV1::html('활동지', '<p>본문</p>');
    }
};
$asset = new AddonAssetBundleDefinitionV1(
    'addon.oj_ai_dashboard.assets',
    'oj-ai-dashboard',
    ['resources/css/addon.css'],
    ['resources/js/addon.js'],
    ['wp-api-fetch'],
    '1.0.0-beta.1'
);
$page = new AddonPublicPageDefinitionV1(
    'addon.oj_ai_dashboard.assignments',
    '/oj-addons/oj-ai-dashboard/assignments',
    $handler,
    'oj_ai_use_assignments',
    [$asset->key()]
);
addonApiSame('1.0.0-beta.1', $asset->version(), 'SemVer prerelease를 보존해야 합니다.');
addonApiSame($handler, $page->handler(), '페이지 handler를 그대로 보존해야 합니다.');
addonApiSame(
    'oj_addon_hustoj_edu_games_play',
    AddonCapabilityKeyV1::wordpress('hustoj_edu_games_play'),
    '선언 권한을 WordPress 저장 권한으로 변환해야 합니다.'
);
addonApiSame(
    'oj_addon_readcontents_library_view',
    AddonCapabilityKeyV1::wordpress('readcontents_library_view'),
    '서로 다른 애드온 권한 키를 독립적으로 변환해야 합니다.'
);
addonApiThrows(
    static fn () => AddonCapabilityKeyV1::wordpress('bad-key'),
    '잘못된 선언 권한 키 변환을 거부해야 합니다.'
);

$problem = new AddonApiProblemV1(
    'addon_context_denied',
    '이 Context에 접근할 수 없습니다.',
    'corr-0001',
    false,
    ['context_type' => 'class']
);
$snapshot = new AddonContextSnapshotV1(
    'class',
    'class:10',
    '알고리즘 수업',
    'active',
    'revision-1',
    'teacher',
    ['academic_year' => 2026]
);
$decision = new AddonContextAccessDecisionV1(true, '', $snapshot, 'corr-0002');
addonApiAssert($decision->isAllowed(), '허용 Context 결정을 만들 수 있어야 합니다.');

$routing = new AddonAiTaskRoutingSnapshotV1(
    true,
    'addon.oj_ai_dashboard.worksheet_quality_assessment',
    'ollama',
    'gemma4',
    'profile-1',
    'routing-1',
    null
);

// Gemini stores its model id as "models/gemini-2.5-flash" (a real '/' in the
// value, per its own API convention) — modelKey must accept that shape while
// every other safeToken()-validated field (providerKey, revisions, ...)
// keeps rejecting '/'.
$geminiRouting = new AddonAiTaskRoutingSnapshotV1(
    true,
    'addon.oj_ai_dashboard.worksheet_quality_assessment',
    'gemini',
    'models/gemini-2.5-flash',
    'profile-2',
    'routing-2',
    null
);
addonApiAssert(
    $geminiRouting->modelKey() === 'models/gemini-2.5-flash',
    'Gemini 모델 id(models/gemini-2.5-flash)를 modelKey로 받아들여야 합니다.'
);
$rejectedSlashInProviderKey = false;
try {
    new AddonAiTaskRoutingSnapshotV1(
        true,
        'addon.oj_ai_dashboard.worksheet_quality_assessment',
        'models/gemini',
        'gemma4',
        'profile-1',
        'routing-1',
        null
    );
} catch (InvalidArgumentException) {
    $rejectedSlashInProviderKey = true;
}
addonApiAssert(
    $rejectedSlashInProviderKey,
    'providerKey처럼 modelKey가 아닌 다른 safeToken 필드는 여전히 \'/\'를 거부해야 합니다.'
);
$request = new AddonAiTaskRequestV1(
    'addon.oj_ai_dashboard.worksheet_quality_assessment',
    '1.0',
    10,
    'worksheet-10-revision-2',
    ['worksheet_id' => 10, 'goals' => ['반복문', '조건문']]
);
$result = new AddonAiTaskResultV1(
    true,
    ['score' => 4, 'feedback' => '목표와 일치합니다.'],
    $routing,
    'corr-0003',
    null
);
addonApiAssert($result->isSuccessful() && $request->actorUserId() === 10, 'AI 작업 DTO가 계약대로 동작해야 합니다.');

$instruction = new AddonAiTaskInstructionV1(
    'worksheet_quality_prompt.v1',
    "첫 줄\r\n둘째 줄\r셋째 줄"
);
addonApiSame("첫 줄\n둘째 줄\n셋째 줄", $instruction->content(), 'AI 작업 지시문은 LF로 정규화해야 합니다.');
addonApiSame(hash('sha256', $instruction->content()), $instruction->contentHash(), 'AI 작업 지시문 hash가 결정적이어야 합니다.');
$legacyTask = new AddonAiTaskDefinitionV1(
    'addon.oj_ai_dashboard.legacy_assessment',
    '기존 평가',
    '1.0',
    'none'
);
addonApiSame(null, $legacyTask->instruction(), '기존 4인자 AI 작업 정의가 유지되어야 합니다.');
$instructedTask = new AddonAiTaskDefinitionV1(
    'addon.oj_ai_dashboard.instructed_assessment',
    '지시문 평가',
    '1.0',
    'addon_payload',
    $instruction
);
addonApiSame($instruction, $instructedTask->instruction(), 'AI 작업 지시문을 그대로 보존해야 합니다.');

$learningQuery = new AddonLearningContextQueryV1(10, ['class', 'group']);
addonApiSame(['class', 'group'], $learningQuery->types(), '학습 Context 종류를 보존해야 합니다.');
$learner = new AddonAssignmentLearnerV1(20, 'membership-20-r1', '김학생', '2학년 3반 20번');
$rosterPage = new AddonAssignmentLearnerPageV1(
    true,
    [$learner],
    '',
    'roster-r1',
    null
);
addonApiSame('roster-r1', $rosterPage->rosterRevision(), '명단 revision을 보존해야 합니다.');
addonApiSame('김학생', $learner->displayName(), '학생 표시 이름을 보존해야 합니다.');
addonApiSame('2학년 3반 20번', $learner->managementName(), '학생 관리용 이름을 보존해야 합니다.');
addonApiSame(
    '표시 이름',
    (new AddonAssignmentLearnerV1(21, 'membership-21-r1', '표시 이름'))->managementName(),
    '관리용 이름이 없으면 표시 이름으로 안전하게 대체해야 합니다.'
);

$activity = new AddonActivityV1(
    'learning-materials:1',
    '수업 자료',
    'PDF 활동 자료',
    '/oj-addons/learning-materials/1',
    'material',
    '학습자료',
    'application/pdf'
);
$activityPage = new AddonActivityPageV1(true, [$activity], null);
addonApiSame('application/pdf', $activityPage->items()[0]->contentType(), '활동 MIME 형식을 보존해야 합니다.');

$acceptedQuery = new AddonAcceptedSubmissionQueryV1(
    10,
    'class',
    'class:42',
    'context-r1',
    [20, 20, 21],
    [1001, 1001],
    '2026-10-01 00:00:00',
    '2026-10-31 23:59:59'
);
addonApiSame([20, 21], $acceptedQuery->learnerUserIds(), '제출 조회 학생 ID를 중복 없이 보존해야 합니다.');
addonApiSame([1001], $acceptedQuery->problemIds(), '제출 조회 문제 ID를 중복 없이 보존해야 합니다.');

$privacy = new AddonPrivacyDataDefinitionV1(
    'addon.teaching_tools.privacy_sessions',
    '교실 도구',
    ['학생 사용자 ID', '수업 응답'],
    '관리자가 정한 보존기간',
    '플랫폼 통합 문서 화면에서 검토'
);
addonApiSame(
    ['학생 사용자 ID', '수업 응답'],
    $privacy->dataCategories(),
    '애드온 개인정보 인벤토리는 관리용 데이터 항목만 보존해야 합니다.'
);

addonApiThrows(
    static fn () => new AddonCapabilityDefinitionV1('bad-key', '권한', 'operator'),
    '잘못된 capability key를 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonCapabilityDefinitionV1('oj_ai_manage', '<b>권한</b>', 'operator'),
    'HTML label을 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAssetBundleDefinitionV1(
        'addon.oj_ai_dashboard.assets',
        'oj-ai-dashboard',
        ['https://example.test/addon.css'],
        [],
        [],
        '1.0.0'
    ),
    '외부 asset URL을 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAiTaskDefinitionV1(
        'addon.oj_ai_dashboard.assessment',
        '평가',
        '1.0',
        'raw_database'
    ),
    '지원하지 않는 AI sourceKind를 거부해야 합니다.'
);
addonApiThrows(
    static fn () => AddonPageResponseV1::html('제목', '본문', 201),
    '허용되지 않은 페이지 상태를 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonContextSnapshotResultV1(true, null, $problem),
    '성공·실패 snapshot 불변식을 강제해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAiTaskRequestV1(
        'addon.oj_ai_dashboard.assessment',
        '1.0',
        1,
        'request-1',
        [0 => 'object root가 아님']
    ),
    'AI 입력은 string key를 가진 JSON object root여야 합니다.'
);
addonApiThrows(
    static fn () => new AddonLearningContextQueryV1(1, ['site']),
    '학습 Context는 class와 group만 허용해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAssignmentRosterQueryV1(1, 'class', 'class:1', 'context-r1', null, 'cursor-1'),
    '후속 명단 page는 expected roster revision을 요구해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAssignmentLearnerPageV1(
        true,
        [new AddonAssignmentLearnerV1(2, 'member-r1'), new AddonAssignmentLearnerV1(2, 'member-r1')],
        '',
        'roster-r1',
        null
    ),
    '명단 page의 중복 학습자를 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAcceptedSubmissionQueryV1(
        10,
        'class',
        'class:42',
        'context-r1',
        [20],
        [1001],
        '2026-11-01 00:00:00',
        '2026-10-01 00:00:00'
    ),
    '제출 조회의 역전된 기간을 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAiTaskInstructionV1('prompt.v1', " \t\n "),
    '공백뿐인 AI 작업 지시문을 거부해야 합니다.'
);
addonApiThrows(
    static fn () => new AddonAiTaskInstructionV1('prompt.v1', str_repeat('a', 16385)),
    '16KiB를 넘는 AI 작업 지시문을 거부해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "C28-I06 Addon API 1.8.0 계약 검사 통과: interface %d개, DTO %d개, 권한 utility 1개, 기본 공개 symbol %d개 (연구 하위 계약 별도 검사)\n",
        count($interfaces),
        count($classes) + 1,
        count($expectedFiles)
    )
);

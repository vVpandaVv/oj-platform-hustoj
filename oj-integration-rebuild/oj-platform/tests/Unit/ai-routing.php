<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderClientRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService;
use CodingPanda\OjPlatform\Modules\Settings\Application\StoredAiTaskRouter;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointValidation;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$aiRoutingAssertions = 0;

function aiRoutingAssert(bool $condition, string $message): void
{
    global $aiRoutingAssertions;
    ++$aiRoutingAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function aiRoutingThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        aiRoutingAssert(true, $message);
        return;
    }

    aiRoutingAssert(false, $message);
}

final class AiRoutingSettings implements SettingsManager
{
    /** @var array<string, SettingsSnapshot> */
    private array $values = [];

    public function put(string $key, int $revision, mixed $value): void
    {
        $this->values[$key] = new SettingsSnapshot($revision, $value);
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return $this->values[$key] ?? new SettingsSnapshot(0, $default);
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        $current = $this->read($key);
        if ($current->revision() !== $expectedRevision) {
            throw new DomainException('설정 revision이 일치하지 않습니다.');
        }
        $saved = new SettingsSnapshot($expectedRevision + 1, $value);
        $this->values[$key] = $saved;

        return $saved;
    }
}

final class AiRoutingVault implements SecretVault
{
    /** @var array<string, mixed> */
    private array $values = [];
    private int $sequence = 1;

    public function store(mixed $value): SecretReference
    {
        $tail = str_pad(dechex($this->sequence++), 12, '0', STR_PAD_LEFT);
        $reference = new SecretReference(
            'secret:v2:00000000-0000-4000-8000-' . $tail
        );
        $this->values[$reference->value()] = $value;

        return $reference;
    }

    public function reveal(SecretReference $reference): mixed
    {
        if (! array_key_exists($reference->value(), $this->values)) {
            throw new DomainException('테스트 비밀값을 찾을 수 없습니다.');
        }

        return $this->values[$reference->value()];
    }

    public function discard(SecretReference $reference): void
    {
        unset($this->values[$reference->value()]);
    }
}

final class AiRoutingEndpointPolicy implements AiEndpointPolicy
{
    public function validate(
        AiProviderDefinition $definition,
        string $baseUrl
    ): AiEndpointValidation {
        unset($definition);

        return new AiEndpointValidation($baseUrl, ['203.0.113.10']);
    }
}

final class AiRoutingClient implements AiProviderClient
{
    public int $calls = 0;

    public function adapterKey(): string
    {
        return 'openai_compatible';
    }

    public function models(AiProviderProfile $profile): array
    {
        return [$profile->model() => $profile->modelRevision()];
    }

    public function generate(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens
    ): array {
        unset($profile, $messages, $maxOutputTokens);
        ++$this->calls;

        return ['difficulty_score' => 3, 'topic_keys' => ['arithmetic'], 'confidence' => 0.8, 'rationale' => '산술 연산 문제입니다.'];
    }
}

$settings = new AiRoutingSettings();
$vault = new AiRoutingVault();
$catalog = new AiProviderCatalog();
$tasks = ['problem_metadata_generate'];
$primary = AiProviderProfile::tested(
    'primary',
    '주 프로필',
    'openai_compatible_gateway',
    'https://ai.example.com',
    'bearer',
    'primary-secret',
    'model-a',
    'revision-a',
    $tasks,
    false,
    30,
    ['203.0.113.10']
);
$secondary = AiProviderProfile::tested(
    'secondary',
    '대체 프로필',
    'openai_compatible_gateway',
    'https://ai.example.com',
    'bearer',
    'secondary-secret',
    'model-b',
    'revision-b',
    $tasks,
    false,
    30,
    ['203.0.113.10']
);
$providerReference = $vault->store([
    'profiles' => [$primary->toArray(), $secondary->toArray()],
]);
$settings->put('ai_provider', 1, $providerReference);
$assignment = new AiTaskAssignment(
    'problem_metadata_generate',
    'primary',
    'secondary',
    1200,
    'transient_only'
);
$settings->put('ai_task_routing', 1, [
    'assignments' => [$assignment->toArray()],
]);
$providers = new AiProviderSettingsService($settings, $vault);
$routing = new AiTaskRoutingService($settings, $providers, $catalog);
$client = new AiRoutingClient();
$router = new StoredAiTaskRouter(
    $catalog,
    $providers,
    $routing,
    new AiProviderClientRegistry([$client]),
    new AiRoutingEndpointPolicy(),
    new AiTaskPromptFactory()
);

aiRoutingThrows(
    static fn (): AiTaskAssignment => new AiTaskAssignment(
        'problem_metadata_generate',
        'primary',
        null,
        1200,
        'transient_only'
    ),
    '대체 정책은 실제 대체 프로필 없이 저장할 수 없어야 합니다.'
);
aiRoutingThrows(
    static fn () => $routing->save([
        new AiTaskAssignment(
            'problem_metadata_generate',
            'primary',
            'missing',
            1200,
            'transient_only'
        ),
    ], 1),
    '등록되지 않은 대체 프로필은 작업 배정에 저장할 수 없어야 합니다.'
);
aiRoutingAssert(
    $router->canRoute('problem_metadata_generate'),
    '검사를 통과한 주·대체 프로필 배정은 사용 가능해야 합니다.'
);
$snapshot = $router->snapshot('problem_metadata_generate');
$response = $router->execute(
    $snapshot,
    new AiTaskRequest(
        'problem_metadata_generate',
        'metadata-v1',
        ['problem' => ['title' => '두 수의 합', 'description' => '두 정수의 합을 출력한다.']]
    )
);
aiRoutingAssert(
    $response->profileKey() === 'primary' && $client->calls === 1,
    '변경되지 않은 작업 배정은 주 프로필로 정확히 실행되어야 합니다.'
);

$settings->put('ai_task_routing', 2, [
    'assignments' => [$assignment->toArray()],
]);
aiRoutingThrows(
    static fn () => $router->execute(
        $snapshot,
        new AiTaskRequest(
            'problem_metadata_generate',
            'metadata-v1',
            ['problem' => ['title' => '두 수의 합', 'description' => '두 정수의 합을 출력한다.']]
        )
    ),
    '작업 등록 뒤 라우팅 revision이 바뀌면 이전 스냅샷을 실행할 수 없어야 합니다.'
);
aiRoutingAssert(
    $client->calls === 1,
    '변경된 작업 배정은 Provider 호출 전에 차단해야 합니다.'
);

$currentSnapshot = $router->snapshot('problem_metadata_generate');
$changedAssignment = new AiTaskAssignment(
    'problem_metadata_generate',
    'secondary',
    null,
    1200,
    'none'
);
$settings->put('ai_task_routing', 2, [
    'assignments' => [$changedAssignment->toArray()],
]);
aiRoutingThrows(
    static fn () => $router->execute(
        $currentSnapshot,
        new AiTaskRequest(
            'problem_metadata_generate',
            'metadata-v1',
            ['problem' => ['title' => '두 수의 합', 'description' => '두 정수의 합을 출력한다.']]
        )
    ),
    '같은 revision 안에서도 주·대체 프로필 키가 달라지면 실행을 거부해야 합니다.'
);

$assertStaleGuidance = static function ($staleSnapshot) use ($router, $client): void {
    $beforeCalls = $client->calls;
    try {
        $router->execute($staleSnapshot, new AiTaskRequest(
            'problem_metadata_generate', 'metadata-v1', ['problem' => ['title' => '두 수의 합', 'description' => '두 정수의 합을 출력한다.']]
        ));
        throw new RuntimeException('Stale assignments must not execute.');
    } catch (DomainException $error) {
        aiRoutingAssert(str_contains($error->getMessage(), '새 작업을 등록'), '설정 변경 오류는 기존 재시도가 아닌 새 작업 등록을 안내해야 합니다.');
        aiRoutingAssert(strlen($error->getMessage()) <= 191, '오류 안내는 Jobs 메시지 제한 안에 들어가야 합니다.');
    }
    aiRoutingAssert($beforeCalls === $client->calls, '이전 배정으로 재시도해도 Provider를 호출하면 안 됩니다.');
};
$assertStaleGuidance($snapshot);
$assertStaleGuidance($currentSnapshot);
$beforeProfileChange = $router->snapshot('problem_metadata_generate');
$updatedSecondary = AiProviderProfile::tested(
    'secondary', '대체 프로필', 'openai_compatible_gateway', 'https://ai.example.com',
    'bearer', 'secondary-secret', 'model-b', 'revision-b-updated', $tasks, false, 30,
    ['203.0.113.10']
);
$settings->put('ai_provider', 2, $vault->store([
    'profiles' => [$primary->toArray(), $updatedSecondary->toArray()],
]));
$assertStaleGuidance($beforeProfileChange);
$beforeCalls = $client->calls;
$freshResponse = $router->execute(
    $router->snapshot('problem_metadata_generate'),
    new AiTaskRequest('problem_metadata_generate', 'metadata-v1', ['problem' => ['title' => '두 수의 합', 'description' => '두 정수의 합을 출력한다.']])
);
aiRoutingAssert($freshResponse->profileKey() === 'secondary' && $client->calls === $beforeCalls + 1, '새 작업의 현재 배정 스냅샷은 정상적으로 실행되어야 합니다.');
$settings->put('ai_provider', 1, $providerReference);

$brokenFallback = new AiTaskAssignment(
    'problem_metadata_generate',
    'primary',
    'missing',
    1200,
    'transient_only'
);
$settings->put('ai_task_routing', 3, [
    'assignments' => [$brokenFallback->toArray()],
]);
aiRoutingAssert(
    ! $router->canRoute('problem_metadata_generate'),
    '유실된 대체 프로필이 있는 작업을 사용 가능으로 표시하면 안 됩니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "AI 작업 라우팅 단위 검사 통과: assertion %d개\n",
        $aiRoutingAssertions
    )
);

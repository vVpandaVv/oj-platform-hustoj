<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract as Http;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract as Auth;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Settings\Application as AI;
use CodingPanda\OjPlatform\Modules\Settings\Contract as Settings;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressAiProbeRunGuard;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveAiProviderSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\AiProviderSettingsPageHandler;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require __DIR__ . '/ai-routing.php';

$controlAssertions = 0;
function aiControlAssert(bool $condition, string $message): void
{
    ++$GLOBALS['controlAssertions'];
    if (! $condition) { throw new RuntimeException($message); }
}
function aiControlThrows(callable $operation, string $message): void
{
    try { $operation(); }
    catch (DomainException | RuntimeException $error) {
        aiControlAssert(str_contains($error->getMessage(), $message), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

final class AiControlClock implements Clock
{
    public int $timestamp = 1000;
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
}

// Model affected-row semantics and race the exact SQL parameter snapshots.
final class AiControlDatabase
{
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $rows = [];
    public bool $failReads = false;
    public bool $failWrites = false;
    public ?Closure $beforeWrite = null;
    public array $queries = [];
    public function prepare(string $sql, mixed ...$args): array { return [$sql, $args]; }
    public function get_var(array $query): ?string
    {
        $this->last_error = $this->failReads ? 'Read failed' : '';
        return $this->rows[$query[1][0]] ?? null;
    }
    public function query(array $query): int|false
    {
        $this->queries[] = $query;
        if ($this->failWrites) { return false; }
        if ($this->beforeWrite !== null) {
            $callback = $this->beforeWrite;
            $this->beforeWrite = null;
            $callback($query);
        }
        [$sql, $args] = $query;
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            [$key, $after] = $args;
            if (isset($this->rows[$key])) { return 0; }
        } else {
            [$after, $key, $before] = $args;
            aiControlAssert(str_contains($sql, 'BINARY option_value = BINARY %s'), 'Lease comparison must be case sensitive.');
            if (($this->rows[$key] ?? null) !== $before) { return 0; }
        }
        $this->rows[$key] = $after;
        return 1;
    }
}

$clock = new AiControlClock();
$db = new AiControlDatabase();
$runs = new WordPressAiProbeRunGuard($clock, $db);
$token = $runs->acquire('primary', 1);
aiControlAssert($runs->status('primary', 1) === ['running' => true, 'retry_after' => 90], 'A probe must own a bounded lease.');
aiControlThrows(fn () => $runs->acquire('primary', 1), '이미 진행 중');
aiControlThrows(fn () => $runs->reset('primary', 1), '진행 중인');
$otherProfile = $runs->acquire('secondary', 1);
$otherAdmin = $runs->acquire('primary', 2);
aiControlAssert($otherProfile !== $token && $otherAdmin !== $token, 'Profiles and administrators must be isolated.');
$runs->release('primary', 1, $token);
aiControlAssert($runs->status('primary', 1) === ['running' => false, 'retry_after' => 10], 'Completion starts a 10 second cooldown.');
aiControlThrows(fn () => $runs->acquire('primary', 1), '10초');
$clock->timestamp += 9;
aiControlThrows(fn () => $runs->acquire('primary', 1), '1초');
++ $clock->timestamp;
$token = $runs->acquire('primary', 1);
$runs->release('primary', 1, $token);
$runs->reset('primary', 1);
aiControlAssert($runs->status('primary', 1)['retry_after'] === 0, 'Reset removes only the cooldown.');
aiControlAssert($runs->status('secondary', 1)['running'] && $runs->status('primary', 2)['running'], 'Reset must not touch other profiles or users.');
$token = $runs->acquire('primary', 1);
$clock->timestamp += 90;
$newToken = $runs->acquire('primary', 1);
$runs->release('primary', 1, $token);
aiControlAssert($runs->status('primary', 1)['running'], 'Late release must not clear a replacement lease.');
$runs->release('primary', 1, $newToken);
$runs->reset('primary', 1);

$raceToken = '';
$db->beforeWrite = function () use ($runs, &$raceToken): void { $raceToken = $runs->acquire('insert-race', 1); };
aiControlThrows(fn () => $runs->acquire('insert-race', 1), '이미 진행 중');
aiControlAssert($runs->status('insert-race', 1)['running'], 'Only the winning insert owns the lease.');
$db->beforeWrite = function () use ($runs, &$raceToken): void { $raceToken = $runs->acquire('primary', 1); };
aiControlThrows(fn () => $runs->acquire('primary', 1), '이미 진행 중');
$clock->timestamp += 90;
$db->beforeWrite = function () use ($runs, &$raceToken): void { $raceToken = $runs->acquire('primary', 1); };
aiControlThrows(fn () => $runs->reset('primary', 1), '진행 중인');
aiControlAssert($runs->status('primary', 1)['running'], 'Reset CAS must not overwrite a concurrently started test.');
$db->beforeWrite = function () use ($runs, &$raceToken, $clock): void {
    $clock->timestamp += 90;
    $raceToken = $runs->acquire('primary', 1);
};
$runs->release('primary', 1, $raceToken);
aiControlAssert($runs->status('primary', 1)['running'], 'Release CAS must not overwrite the new owner.');
$db->failWrites = true;
aiControlThrows(fn () => $runs->acquire('write-failure', 1), '저장하지 못했습니다');
$db->failWrites = false;
$db->failReads = true;
aiControlThrows(fn () => $runs->acquire('read-failure', 1), '읽지 못했습니다');
$db->failReads = false;
aiControlThrows(fn () => $runs->acquire('', 1), '프로필 키');
aiControlThrows(fn () => $runs->acquire('primary', 0), '프로필 키');

final class AiControlClient implements Settings\AiProviderClient
{
    public int $calls = 0;
    public int $generationCalls = 0;
    public ?Settings\AiProviderException $failure = null;
    public ?AiProviderProfile $lastProfile = null;
    public ?Closure $onModels = null;
    public function __construct(private readonly string $adapter) {}
    public function adapterKey(): string { return $this->adapter; }
    public function models(AiProviderProfile $profile): array
    {
        ++$this->calls;
        $this->lastProfile = $profile;
        if ($this->onModels !== null) { ($this->onModels)(); }
        if ($this->failure !== null) { throw $this->failure; }
        return [$profile->model() => 'revision-1'];
    }
    public function generate(AiProviderProfile $profile, array $messages, int $maxOutputTokens): array
    {
        ++$this->generationCalls;
        return ['result_understanding' => '결과 확인', 'step_hints' => ['입력 확인'], 'review_question' => '자료형은 무엇인가요?', 'difficulty_score' => 1, 'topic_keys' => ['arithmetic'], 'confidence' => 0.8, 'rationale' => '산술 연산 문제입니다.'];
    }
}

$contributions = new AddonAiTaskContributions();
$contributions->add(new AddonAiTaskDefinitionV1('addon.alpha.review', '제출 평가', '1', 'platform_submission'));
$contributions->add(new AddonAiTaskDefinitionV1('addon.alpha.payload', '자체 입력', '1', 'addon_payload'));
$catalog = new AI\AiProviderCatalog($contributions);
$prompts = new AI\AiTaskPromptFactory($contributions);
$client = new AiControlClient('openai_compatible');
$gemini = new AiControlClient('gemini');
$ollama = new AiControlClient('ollama');
$clients = new AI\AiProviderClientRegistry([$client, $gemini, $ollama]);
$probes = new AI\AiProviderProbeService($catalog, $clients, new AiRoutingEndpointPolicy(), $prompts);
$inspect = static fn (string $provider, bool $approved, array $tasks = ['submission_feedback']): AiProviderProfile => $probes->inspect(
    'test-' . $provider, '검사 프로필', $provider, $catalog->get($provider)->defaultBaseUrl(),
    $catalog->get($provider)->authenticationMethods()[0], 'test-secret', 'model-a', $tasks, $approved, 60
);
foreach ($catalog->all() as $definition) {
    aiControlAssert($inspect($definition->key(), true)->allowsSubmissionSource(), $definition->key() . ' must support explicit approval.');
    aiControlAssert(! $inspect($definition->key(), false)->allowsSubmissionSource(), $definition->key() . ' must not implicitly approve source.');
}
aiControlAssert($inspect('gemini', true, ['addon.alpha.review'])->allowsSubmissionSource(), 'Addon-only platform_submission must allow approval.');
aiControlAssert(! $inspect('gemini', false, ['addon.alpha.payload'])->allowsSubmissionSource(), 'addon_payload must keep its existing contract.');
aiControlThrows(fn () => $inspect('gemini', true, ['addon.alpha.payload']), '제출 코드를 사용하는 애드온');
aiControlThrows(fn () => $inspect('openai', true, ['problem_metadata_generate']), '제출 코드를 사용하는 애드온');

$settings = new AiRoutingSettings();
$vault = new AiRoutingVault();
$providers = new AI\AiProviderSettingsService($settings, $vault);
$proofs = new class implements Settings\CandidateProofStore {
    public array $items = [];
    public function remember(string $scope, Settings\CandidateProof $proof): void { $this->items[$scope . ':' . $proof->actorUserId()] = $proof; }
    public function find(string $scope, int $actorUserId): ?Settings\CandidateProof { return $this->items[$scope . ':' . $actorUserId] ?? null; }
    public function consume(string $scope, int $actorUserId): ?Settings\CandidateProof
    {
        $proof = $this->find($scope, $actorUserId);
        $this->forget($scope, $actorUserId);
        return $proof;
    }
    public function forget(string $scope, int $actorUserId): void { unset($this->items[$scope . ':' . $actorUserId]); }
};
$candidates = new AI\AiProviderCandidateService($probes, $providers, $proofs, $vault);
$candidate = static fn (string $url, string $secret = ''): Settings\CandidateProof => $candidates->test(
    'external', '외부 피드백', 'openai_compatible_gateway', $url, 'bearer', $secret, 'model-a', ['submission_feedback'], true, 60, 1
);
$candidate('https://ai.example.com', 'saved-secret');
$candidates->saveTested('external', 1, 0);
aiControlAssert($providers->find('external')?->allowsSubmissionSource() === true, 'Test/save must persist external approval.');
$candidate('https://ai.example.com/');
aiControlAssert($client->lastProfile?->secret() === 'saved-secret', 'Same endpoint may reuse its saved credential.');
$before = $client->calls;
aiControlThrows(fn () => $candidate('https://different.example.com'), '비밀값을 다시 입력');
aiControlAssert($client->calls === $before, 'Changed host must be rejected before sending an old credential.');
$candidate('https://different.example.com', 'new-secret');
aiControlAssert($client->lastProfile?->secret() === 'new-secret', 'Changed host may use explicitly supplied credentials.');
$routing = new AI\AiTaskRoutingService($settings, $providers, $catalog);
$assignment = new AiTaskAssignment('submission_feedback', 'external', null, 1200, 'none');
$routing->save([$assignment], 0);
$router = new AI\StoredAiTaskRouter($catalog, $providers, $routing, $clients, new AiRoutingEndpointPolicy(), $prompts);
aiControlAssert($router->canRoute('submission_feedback'), 'Approved external profile must be usable by existing routing.');
$unapproved = $probes->inspect('external', '외부 피드백', 'openai_compatible_gateway', 'https://ai.example.com', 'bearer', 'saved-secret', 'model-a', ['submission_feedback'], false, 60);
$providers->saveProfile($unapproved, $providers->snapshot()->revision());
aiControlAssert(! $router->canRoute('submission_feedback'), 'Revoking approval must block live source routing.');
aiControlThrows(fn () => $router->snapshot('submission_feedback'), '허용하지 않습니다');
aiControlThrows(fn () => $routing->save([$assignment], 1), '제출 코드');

$security = new class implements Http\ActionSecurity {
    public bool $valid = true;
    public function verify(string $actionKey, Http\HttpRequest $request): bool { return $this->valid; }
    public function hiddenFields(string $actionKey): string { return '<input type="hidden" name="action" value="' . $actionKey . '"><input type="hidden" name="_nonce" value="test">'; }
};
$accounts = new class implements Auth\CurrentAccountProvider {
    public function current(): Auth\CurrentAccount
    {
        return new Auth\CurrentAccount(new Auth\UserIdentity(1, 'admin', '', '관리자', ['administrator']), new UserProfile(1, null, null, Auth\AccessStatus::Active, false));
    }
};
$permissions = new class implements Authorization {
    public bool $allowed = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $this->allowed && $capability === 'oj_manage_settings'; }
    public function canAny(int $userId, array $capabilities): bool { return $this->allowed; }
    public function isSuperAdmin(int $userId): bool { return $this->allowed; }
};
$attempts = new class implements Auth\AttemptLimiter {
    public int $calls = 0;
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { ++$this->calls; return false; }
    public function resetAll(): int { throw new LogicException('Global limiter must not be reset.'); }
};
$responses = new class implements Auth\ActionResponse {
    public ?UiMessage $message = null;
    public function redirect(string $path, UiMessage $message, int $userId = 0): Http\HttpResponse { $this->message = $message; return Http\HttpResponse::redirect($path); }
};
$errors = new class implements ErrorReporter {
    public ?Throwable $last = null;
    public function report(Throwable $error): void { $this->last = $error; }
};
$action = new SaveAiProviderSettingsActionHandler($candidates, new ActionGuard($security, $accounts, $permissions, $attempts), $responses, $errors, $runs);
$request = static fn (string $operation): Http\HttpRequest => new Http\HttpRequest('POST', '/oj-action', [], [
    'operation' => $operation, 'profile_key' => 'action-test', 'profile_name' => '연결 검사',
    'provider_key' => 'openai_compatible_gateway', 'base_url' => 'https://ai.example.com',
    'authentication_method' => 'bearer', 'secret' => 'test-secret', 'model' => 'model-a',
    'task_types' => ['submission_feedback'], 'allow_submission_source' => '1', 'timeout_seconds' => 60,
]);
foreach (['test', 'reset_probe'] as $operation) {
    $before = $db->rows;
    $permissions->allowed = false;
    $action->handle($request($operation));
    aiControlAssert($db->rows === $before && $responses->message?->code() === 'ai_provider.failed', 'Unauthorized request must not mutate probe state.');
    $permissions->allowed = true;
    $security->valid = false;
    $action->handle($request($operation));
    aiControlAssert($db->rows === $before && $responses->message?->code() === 'ai_provider.failed', 'Invalid nonce must not mutate probe state.');
    $security->valid = true;
}
$client->failure = new Settings\AiProviderException('ai_provider_transient', '연결 시간 초과', true);
for ($i = 0; $i < 8; ++$i) {
    $before = $client->calls;
    $action->handle($request('test'));
    aiControlAssert($client->calls === $before + 1 && $errors->last === $client->failure, 'Every spaced retry must reach the provider, even after five failures.');
    aiControlAssert($runs->status('action-test', 1) === ['running' => false, 'retry_after' => 10], 'Failed tests must release their lease.');
    $action->handle($request('test'));
    aiControlAssert($client->calls === $before + 1, 'Immediate duplicate must not reach the provider.');
    $clock->timestamp += 10;
}
aiControlAssert($attempts->calls === 0, 'The old admin-wide attempt counter must not be used.');
$client->failure = null;
$client->onModels = function () use ($runs): void { aiControlThrows(fn () => $runs->reset('action-test', 1), '진행 중인'); };
$action->handle($request('test'));
aiControlAssert($responses->message?->code() === 'ai_provider.test_succeeded', 'Successful tests must still issue candidate proofs.');
$client->onModels = null;
$before = [$providers->snapshot(), $proofs->items, $settings->read('ai_task_routing')];
$action->handle($request('reset_probe'));
aiControlAssert($responses->message?->code() === 'ai_provider.probe_reset', 'Administrator must be able to clear completed cooldown.');
aiControlAssert($before == [$providers->snapshot(), $proofs->items, $settings->read('ai_task_routing')], 'Reset must preserve profiles, proofs and task assignments.');
$client->failure = new Settings\AiProviderException('ai_provider_rate_limited', 'AI Provider 요청 한도에 도달했습니다.', true);
$action->handle($request('test'));
aiControlAssert($errors->last === $client->failure, 'Provider quota errors must retain their identity.');
$action->handle($request('reset_probe'));
$action->handle($request('test'));
aiControlAssert($errors->last === $client->failure, 'Reset must not bypass provider-side quota.');
$client->failure = null;

$pages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, Http\HttpRequest $request, int $status = 200): Http\HttpResponse { return Http\HttpResponse::html($content, $status); }
};
$urls = new class implements Http\ActionUrlProvider {
    public function actionUrl(): string { return '/oj-action'; }
    public function ajaxUrl(): string { return '/oj-ajax'; }
};
$page = new AiProviderSettingsPageHandler($pages, $catalog, $providers, $candidates, $accounts, $urls, $security);
$route = new Http\RouteMatch(new Http\RouteDefinition('console.settings.ai_provider', new ModuleId('settings'), 'settings', '/oj-console/settings/ai-provider', Http\RouteArea::Console, ['GET'], Http\AuthenticationRequirement::Authenticated, 'html'), []);
$html = $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider'))->body();
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
aiControlAssert($xpath->query('//input[@name="allow_submission_source"][@checked]')->length === 0, 'Approval must default off.');
aiControlAssert($xpath->query('//form[@method="post"]//button[@name="operation"][@value="reset_probe"][@formnovalidate]')->length === 1, 'Reset must be a nonce-protected POST, independent of candidate input validation.');
aiControlAssert($xpath->query('//input[@name="operation"][@value="test"]')->length === 0, 'Test and reset must not submit conflicting operation fields.');
aiControlAssert(str_contains($html, '자체 서버·외부 AI 모두 기본 차단') && str_contains($html, '10초'), 'UI must describe approval and retry rules consistently.');
aiControlAssert(! str_contains($html, '<style') && ! str_contains($html, 'style="'), 'Use shared UI styling.');

// Cloning a connection must never replace its already-tested task set or routing.
$oldTasks = $catalog->allTaskKeys();
$source = AiProviderProfile::tested('clone-source', '기존 운영 연결', 'openai_compatible_gateway',
    'https://ai.example.com', 'bearer', 'clone-server-only-secret', 'model-a', 'revision-1',
    $oldTasks, true, 60, ['203.0.113.10']);
$providers->saveProfile($source, $providers->snapshot()->revision());
$oldAssignments = array_map(static fn (string $task): AiTaskAssignment => new AiTaskAssignment($task, 'clone-source', null, 1200, 'none'), $oldTasks);
$routing->save($oldAssignments, $routing->snapshot()->revision());
$beforeProfiles = array_map(static fn (AiProviderProfile $item): array => $item->toArray(), $providers->profiles());
$beforeRouting = $routing->snapshot();
$beforeFingerprints = array_map(static fn (string $task): string => $router->snapshot($task)->profileFingerprint(), $oldTasks);
$primmTask = 'addon.oj_primm.generate_lesson';
$contributions->add(new AddonAiTaskDefinitionV1($primmTask, 'PRIMM 수업 생성', 'primm_lesson.v1', 'addon_payload'));
$copyCandidate = static fn (string $target, ?string $from = 'clone-source', string $url = 'https://ai.example.com',
    string $secret = '', string $provider = 'openai_compatible_gateway', string $auth = 'bearer', array $tasks = ['addon.oj_primm.generate_lesson']): Settings\CandidateProof => $candidates->test(
        $target, 'PRIMM 전용 연결', $provider, $url, $auth, $secret, 'model-a', $tasks, false, 60, 1, $from
    );
$beforeCalls = [$client->calls, $client->generationCalls];
$cloneProof = $copyCandidate('primm-only');
aiControlAssert([$client->calls, $client->generationCalls] === [$beforeCalls[0] + 1, $beforeCalls[1] + 1], 'A clone must inspect the model and selected PRIMM task exactly once, not re-probe the seven old tasks.');
aiControlAssert($client->lastProfile?->secret() === 'clone-server-only-secret', 'Same-endpoint clones may reuse the source credential only on the server.');
aiControlAssert(! str_contains(json_encode($cloneProof->safeResult(), JSON_THROW_ON_ERROR), 'clone-server-only-secret'), 'Safe proof metadata must never expose the cloned credential.');
aiControlAssert($cloneProof->safeResult()['allowed_task_types'] === [$primmTask], 'Only explicitly selected tasks enter the clone proof.');
aiControlAssert($beforeProfiles === array_map(static fn (AiProviderProfile $item): array => $item->toArray(), $providers->profiles()) && $routing->snapshot() == $beforeRouting, 'Testing a clone must leave all stored profiles and routing untouched.');
aiControlThrows(fn () => $candidates->saveTested('primm-only', 2, $providers->snapshot()->revision()), '검사 결과가 없습니다');
$candidates->saveTested('primm-only', 1, $providers->snapshot()->revision());
$cloned = $providers->find('primm-only');
aiControlAssert($cloned?->allowedTaskTypes() === [$primmTask] && ! $cloned->allowsSubmissionSource(), 'The saved clone has independent tasks and no inherited submission-source approval.');
aiControlAssert($cloned->fingerprint() !== $source->fingerprint(), 'The new key and task set receive their own fingerprint.');
aiControlAssert($providers->find('clone-source')?->toArray() === $source->toArray(), 'The original source profile, task set, secret and fingerprint are unchanged.');
foreach ($beforeProfiles as $beforeProfile) {
    aiControlAssert($providers->find($beforeProfile['key'])?->toArray() === $beforeProfile, 'Saving a clone preserves every existing profile.');
}
aiControlAssert($routing->snapshot() == $beforeRouting && array_map(static fn (string $task): string => $router->snapshot($task)->profileFingerprint(), $oldTasks) === $beforeFingerprints, 'Saving a clone preserves routing revision and all existing assignment fingerprints.');
aiControlAssert(array_filter($oldTasks, static fn (string $task): bool => ! $router->canRoute($task)) === [], 'All seven old tasks remain routable after clone save.');

$beforeCalls = [$client->calls, $client->generationCalls];
foreach ([
    static fn () => $copyCandidate('clone-source'),
    static fn () => $copyCandidate('external'),
] as $operation) { aiControlThrows($operation, '새 프로필 키'); }
aiControlThrows(fn () => $copyCandidate('missing-source-copy', 'missing-source'), '원본 AI 연결 프로필');
aiControlThrows(fn () => $copyCandidate('blank-source-copy', ' '), '프로필 키');
aiControlThrows(fn () => $copyCandidate('different-host-copy', 'clone-source', 'https://different.example.com'), '비밀값을 다시 입력');
aiControlThrows(fn () => $copyCandidate('different-path-copy', 'clone-source', 'https://ai.example.com/other'), '비밀값을 다시 입력');
aiControlThrows(fn () => $copyCandidate('different-provider-copy', 'clone-source', 'https://ai.example.com', '', 'openai'), '비밀값을 다시 입력');
aiControlThrows(fn () => $copyCandidate('different-auth-copy', 'clone-source', 'https://ai.example.com', '', 'openai_compatible_gateway', 'x_api_key'), '비밀값을 다시 입력');
aiControlThrows(fn () => $copyCandidate('empty-tasks-copy', tasks: []), '작업을 하나 이상');
aiControlAssert([$client->calls, $client->generationCalls] === $beforeCalls, 'Invalid clone source, collisions, changed credential destinations and empty task sets fail before any provider call.');
$copyCandidate('trailing-slash-copy', url: 'https://ai.example.com/');
aiControlAssert($client->lastProfile?->secret() === 'clone-server-only-secret', 'A harmless trailing slash keeps the existing credential reuse rule.');
$copyCandidate('explicit-secret-copy', url: 'https://different.example.com', secret: 'explicit-new-secret');
aiControlAssert($client->lastProfile?->secret() === 'explicit-new-secret', 'Changed endpoints may use an explicitly supplied new credential.');

$lateProof = $copyCandidate('late-copy');
$staleRevision = $providers->snapshot()->revision();
$occupant = AiProviderProfile::tested('late-copy', '다른 관리자가 만든 연결', 'openai_compatible_gateway',
    'https://ai.example.com', 'bearer', 'other-admin-secret', 'model-a', 'revision-1', [$primmTask], false, 60, ['203.0.113.10']);
$providers->saveProfile($occupant, $staleRevision);
aiControlThrows(fn () => $candidates->saveTested('late-copy', 1, $staleRevision), '다른 요청에서 변경');
aiControlAssert($candidates->proofFor('late-copy', 1) === $lateProof, 'Revision conflicts leave the proof available for a deliberate retry.');
aiControlThrows(fn () => $candidates->saveTested('late-copy', 1, $providers->snapshot()->revision()), '이미 사용 중');
aiControlAssert($providers->find('late-copy')?->toArray() === $occupant->toArray(), 'A key created after the clone test cannot be overwritten even with the latest global revision.');
aiControlAssert($candidates->proofFor('late-copy', 1) === null, 'A consumed collision proof cannot be replayed.');
aiControlThrows(fn () => $vault->reveal($lateProof->payloadReference()), '비밀값을 찾을 수 없습니다');

$cloneBody = [
    'operation' => 'test', 'profile_key' => 'clone-http', 'profile_name' => '복제 HTTP 연결', 'copy_from' => 'clone-source',
    'provider_key' => 'openai_compatible_gateway', 'base_url' => 'https://ai.example.com',
    'authentication_method' => 'bearer', 'secret' => '', 'model' => 'model-a', 'task_types' => [$primmTask], 'timeout_seconds' => 60,
];
$cloneRequest = static fn (): Http\HttpRequest => new Http\HttpRequest('POST', '/oj-action', [], $cloneBody);
$beforeClone = [$providers->snapshot(), $routing->snapshot(), $proofs->items, $db->rows, $client->calls];
$permissions->allowed = false;
$action->handle($cloneRequest());
aiControlAssert($beforeClone == [$providers->snapshot(), $routing->snapshot(), $proofs->items, $db->rows, $client->calls], 'Unauthorized clone requests cannot read through provider calls or mutate stored state.');
$permissions->allowed = true; $security->valid = false;
$action->handle($cloneRequest());
aiControlAssert($beforeClone == [$providers->snapshot(), $routing->snapshot(), $proofs->items, $db->rows, $client->calls], 'Clone requests with invalid nonces cannot invoke providers or mutate state.');
$security->valid = true;
$cloneResponse = $action->handle($cloneRequest());
aiControlAssert($responses->message?->code() === 'ai_provider.test_succeeded' && $candidates->proofFor('clone-http', 1)?->safeResult()['copy_source_key'] === 'clone-source', 'The guarded action forwards clone intent into its server-owned proof.');
aiControlAssert($cloneResponse->headers()['Location'] === '/oj-console/settings/ai-provider?profile_key=clone-http&copy_from=clone-source', 'Successful clone tests return to their independent candidate form.');

$cloneHtml = $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider', ['copy_from' => 'clone-source']))->body();
$cloneDom = new DOMDocument(); @$cloneDom->loadHTML('<?xml encoding="UTF-8">' . $cloneHtml); $cloneXpath = new DOMXPath($cloneDom);
aiControlAssert($cloneXpath->query('//a[contains(@href,"?copy_from=clone-source")]')->length === 1, 'Every existing profile has a non-mutating clone link.');
aiControlAssert($cloneXpath->query('//input[@name="copy_from"][@value="clone-source"][@type="hidden"]')->length === 1, 'Only the public source key is carried in the clone form.');
aiControlAssert($cloneXpath->query('//input[@name="profile_key"][@value=""]')->length === 1 && $cloneXpath->query('//input[@name="profile_key"][@readonly]')->length === 0, 'The clone starts with an editable empty new profile key.');
aiControlAssert($cloneXpath->query('//input[@name="profile_name"][@value=""]')->length === 1, 'The clone asks for its own name.');
aiControlAssert($cloneXpath->query('//input[@name="task_types[]"][@checked]')->length === 0 && $cloneXpath->query('//input[@name="allow_submission_source"][@checked]')->length === 0, 'Clone task selection and source approval default off, independently from the source.');
aiControlAssert($cloneXpath->query('//input[@name="base_url"][@value="https://ai.example.com"]')->length === 1 && $cloneXpath->query('//input[@name="model"][@value="model-a"]')->length === 1, 'The clone pre-fills connection and model.');
aiControlAssert($cloneXpath->query('//input[@name="secret"][@value=""][@type="password"]')->length === 1, 'The clone credential field is always empty.');
aiControlAssert(! str_contains($cloneHtml, 'clone-server-only-secret') && ! str_contains($cloneHtml, 'saved-secret') && ! str_contains($cloneHtml, 'other-admin-secret'), 'No stored credential is rendered anywhere in the clone HTML.');
aiControlAssert(str_contains($cloneHtml, '기존 프로필과 작업 배정은 유지') && str_contains($cloneHtml, '필요한 작업만 선택'), 'The clone form explains independent task testing and preservation.');
aiControlThrows(fn () => $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider', ['copy_from' => ''])), '원본 AI 연결 프로필');
aiControlThrows(fn () => $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider', ['copy_from' => 'missing'])), '원본 AI 연결 프로필');
aiControlThrows(fn () => $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider', ['copy_from' => 'clone-source', 'profile_key' => 'external'])), '새 프로필 키');
$cloneProofHtml = $page->handle($route, new Http\HttpRequest('GET', '/oj-console/settings/ai-provider', ['copy_from' => 'clone-source', 'profile_key' => 'clone-http']))->body();
aiControlAssert(str_contains($cloneProofHtml, '검사한 프로필 저장') && ! str_contains($cloneProofHtml, 'clone-server-only-secret'), 'The tested clone keeps the existing proof-save workflow without exposing its secret.');

if (in_array(($argv[1] ?? ''), ['--render', '--render-copy'], true) && isset($argv[2])) {
    if ($argv[1] === '--render-copy') { $html = $cloneHtml; }
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    $js = file_get_contents($pluginRoot . '/resources/ui/js/oj-ui.js');
    file_put_contents($argv[2], '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI 연결 프로필</title><style>' . $css . '</style></head><body class="ojp-app"><div class="ojp-console-shell"><div class="ojp-console-layout"><aside class="ojp-console-sidebar">관리자 메뉴</aside><main class="ojp-console-main"><div class="ojp-shell">' . $html . '</div></main></div></div><script>' . $js . '</script></body></html>');
}
fwrite(STDOUT, "AI provider controls: {$controlAssertions} assertions passed.\n");

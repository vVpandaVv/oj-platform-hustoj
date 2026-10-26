<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonSubmissionDraftGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonSubmissionDraftGateway;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract as Http;
use CodingPanda\OjPlatform\Modules\Auth\Contract as Auth;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Jobs\Contract as Jobs;
use CodingPanda\OjPlatform\Modules\Problems\Contract as Problems;
use CodingPanda\OjPlatform\Modules\Settings\Contract as Settings;
use CodingPanda\OjPlatform\Modules\Submissions\Application as Submissions;
use CodingPanda\OjPlatform\Modules\Submissions\Contract as SubmissionContracts;
use CodingPanda\OjPlatform\Modules\Submissions\Domain as SubmissionDomain;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route\SubmissionFormPageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (! $condition) { throw new RuntimeException($message); } };
$rejects = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (DomainException|InvalidArgumentException) { $check(true, $message); return; }
    $check(false, $message);
};
$accounts = new class implements Auth\CurrentAccountProvider {
    public ?int $actor = 7; public bool $active = true;
    public function current(): Auth\CurrentAccount {
        return new Auth\CurrentAccount($this->actor === null ? null : new Auth\UserIdentity($this->actor, 'student' . $this->actor, 'private@example.test', 'private learner'),
            $this->actor === null ? null : new UserProfile($this->actor, null, null, $this->active ? Auth\AccessStatus::Active : Auth\AccessStatus::Suspended, false));
    }
};
$store = new class implements Jobs\FormDraftStore {
    public array $rows = []; public int $writes = 0; public bool $fail = false; public ?Closure $afterStore = null;
    public function store(string $draftType, int $ownerUserId, string $targetType, string $targetKey, array $payload, int $ttlSeconds = 86400): Jobs\FormDraftReference {
        if ($this->fail) { throw new RuntimeException('SECRET storage path /private/server'); }
        ++$this->writes; $ref = new Jobs\FormDraftReference(sprintf('draft:v1:%08x-0000-4000-8000-%012x', $this->writes, $this->writes));
        $this->rows[$ref->value()] = new Jobs\FormDraft($ref, $draftType, $ownerUserId, $targetType, $targetKey, $payload, time(), time() + $ttlSeconds);
        if ($this->afterStore !== null) { ($this->afterStore)(); }
        return $ref;
    }
    public function find(Jobs\FormDraftReference $reference, int $ownerUserId): ?Jobs\FormDraft {
        $row = $this->rows[$reference->value()] ?? null;
        return $row !== null && $row->ownerUserId() === $ownerUserId && $row->expiresAt() > time() ? $row : null;
    }
    public function discard(Jobs\FormDraftReference $reference, int $ownerUserId): void { if (($this->rows[$reference->value()] ?? null)?->ownerUserId() === $ownerUserId) { unset($this->rows[$reference->value()]); } }
    public function purgeExpired(int $limit = 100): int { return 0; }
};
$problems = new class implements Problems\SubmissionProblemProvider {
    public bool $visible = true; public bool $completion = false; public int $mask = 0; public string $version = 'a';
    public function findVisible(int $userId, int $problemId, ?Problems\ProblemAccessContext $context = null): ?Problems\SubmissionProblem { return $this->visible && $problemId === 100 ? new Problems\SubmissionProblem(100, '전송 시험') : null; }
    public function findVisibleMany(int $userId, array $problemIds, ?Problems\ProblemAccessContext $context = null): array { return []; }
    public function listVisible(string $keyword = '', int $limit = 100): array { return []; }
    public function codePolicy(int $userId, int $problemId, ?Problems\ProblemAccessContext $context = null): Problems\SubmissionCodePolicy {
        return new Problems\SubmissionCodePolicy(100, '전송 시험', $this->completion ? 'code_completion' : 'full_code', [], '', 'SECRET_STARTER', '', $this->mask,
            $this->completion ? '{}' : null, $this->completion ? str_repeat('a', 64) : null, 'problem-code-policy-v1:' . str_repeat($this->version, 64));
    }
};
$contextProvider = new class implements SubmissionContracts\SubmissionContextProvider, SubmissionContracts\SubmissionContextProfileProvider {
    public bool $allowed = true; public array $commands = [];
    public function supports(SubmissionDomain\SubmitSubmissionCommand $command): bool { return $this->supportsContext($command->contestId(), $command->classId(), $command->groupId()); }
    public function supportsContext(int $contestId, ?int $classId, ?int $groupId): bool { return $contestId === 0 && (($classId === 5 && $groupId === null) || ($classId === null && $groupId === 6)); }
    public function blocklyProfile(int $contestId, ?int $classId, ?int $groupId): ?string { return null; }
    public function validate(SubmissionDomain\SubmitSubmissionCommand $command): SubmissionDomain\SubmissionContext {
        $this->commands[] = $command;
        if (! $this->allowed) { throw new DomainException('수업 소속이 없습니다.'); }
        return new SubmissionDomain\SubmissionContext(0, -1, $command->classId(), $command->groupId());
    }
};
$contexts = new Submissions\SubmissionContextProviderRegistry(); $contexts->register($contextProvider);
$settings = new class implements Settings\SettingsManager {
    public array $values = [];
    public function read(string $key, mixed $default = null): Settings\SettingsSnapshot { return new Settings\SettingsSnapshot(1, $this->values[$key] ?? $default); }
    public function save(string $key, mixed $value, int $expectedRevision): Settings\SettingsSnapshot { throw new LogicException('No setting changes belong in editor preparation.'); }
};
$guard = new class implements Auth\ActionAccessGuard {
    public array $limits = []; public bool $deny = false;
    public function verifyNonce(string $actionKey, Http\HttpRequest $request): void { throw new LogicException('The native form still owns its submission nonce.'); }
    public function actor(string $actionKey, Http\HttpRequest $request, string $capability): Auth\UserIdentity { throw new LogicException('Unused'); }
    public function authenticatedActor(string $actionKey, Http\HttpRequest $request): Auth\UserIdentity { throw new LogicException('Unused'); }
    public function throttle(string $scope, string $identifier, int $limit, int $windowSeconds): void { $this->limits[] = [$scope, $identifier, $limit, $windowSeconds]; if ($this->deny) { throw new DomainException('발급 한도를 넘었습니다.'); } }
};
$languages = new Submissions\SubmissionLanguageCatalog(); $provider = new Submissions\SubmissionSettingsProvider($settings);
$service = new Submissions\SubmissionDraftService($store, $accounts, $problems, $contexts, $provider, $languages, $guard);
$gateway = new RealAddonSubmissionDraftGateway('oj-primm', $service, new AddonCorrelationIdFactory());
$source = "\n\tprint(\"한글 </textarea></script>\")\r\n\n";
$query = new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'python', $source);
$result = $gateway->prepare($query); $payload = $result->payload();
$check($result->isSuccessful() && array_keys($payload) === ['url', 'language', 'expires_at'], 'Public preparation returns only an opaque URL, language and expiry.');
$check(! str_contains(json_encode($payload), '한글') && ! str_contains(json_encode($payload), 'source') && ! str_contains(json_encode($payload), 'private@'), 'The receipt contains no source, private identity or server diagnostics.');
parse_str((string) parse_url($payload['url'], PHP_URL_QUERY), $urlQuery); $ref = $urlQuery['draft'];
$formContext = ['contest_id' => 0, 'contest_problem_number' => -1, 'class_id' => 5, 'group_id' => null];
$loaded = $service->load($ref, 7, 100, $formContext);
$check($loaded['source'] === $source && $loaded['language_id'] === $languages->idForKey('python'), 'Preparation preserves raw Unicode, CRLF, tabs, leading/trailing blank lines and the requested language.');
$check($payload['expires_at'] >= time() + 295 && $payload['expires_at'] <= time() + 300, 'Prepared forms have a five-minute bounded lifetime.');
$check($guard->limits === [['submission_draft_prepare', '7', 12, 60]], 'Only preparation is rate-limited by current actor at twelve requests per minute.');
$check($service->load($ref, 7, 100, $formContext) === $loaded && count($store->rows) === 1 && $store->writes === 1, 'Refresh reloads the same private draft without consuming or reissuing it.');
$check(count($guard->limits) === 1, 'Reloading a prepared form does not consume another issuance allowance.');

$html = (new ReflectionClass(SubmissionHtml::class))->newInstanceWithoutConstructor();
$assets = new class implements SubmissionContracts\SubmissionCodeEditorAssets { public function isReady(): bool { return false; } public function urls(): array { return []; } };
$urls = new class implements Http\ActionUrlProvider { public function actionUrl(): string { return '/oj-action'; } public function ajaxUrl(): string { return '/oj-ajax'; } };
$security = new class implements Http\ActionSecurity { public function hiddenFields(string $actionKey): string { return '<input type="hidden" name="_oj_nonce" value="native-form-nonce"><input type="hidden" name="action" value="' . $actionKey . '">'; } public function verify(string $actionKey, Http\HttpRequest $request): bool { return true; } };
$renderer = new Submissions\SubmissionFormRenderer($provider, $languages, $assets, new Submissions\SubmissionEditorExtensionRegistry(), $urls, $security, $html);
$preferences = new Auth\UserSubmissionPreferences(0, 'chrome', 'OLD_PREFERENCE_TEMPLATE', 'blockly');
$rendered = $renderer->render(100, $problems->codePolicy(7, 100), null, $preferences, $formContext, 7, $loaded);
if (in_array('--render', $argv, true)) { fwrite(STDOUT, json_encode(['html' => $rendered, 'source' => $source, 'storage_key' => $loaded['storage_key']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); exit(0); }
$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $rendered); $xpath = new DOMXPath($dom);
$check($xpath->query('//textarea[@name="source"]')->length === 1 && $xpath->query('//script')->length === 0, 'HTML-like student code cannot close the textarea or execute as markup.');
$check($xpath->query('//option[@selected and @value="6"]')->length === 1 && $xpath->query('//input[@name="submission_mode" and @value="code" and @checked]')->length === 1, 'Prefill selects its explicit source language and direct-code mode instead of an old preference.');
$check(! str_contains($rendered, 'OLD_PREFERENCE_TEMPLATE') && ! str_contains($rendered, 'SECRET_STARTER') && str_contains($rendered, 'native-form-nonce'), 'The prepared source wins over templates and retains the normal final-submission nonce.');
$check($xpath->query('//button[@type="submit"]')->length === 1 && ! str_contains($rendered, '.submit('), 'Rendering provides a manual final button and never auto-submits.');
$check($xpath->query('//form[@data-oj-handoff-storage]')->item(0)?->getAttribute('data-oj-handoff-storage') === $loaded['storage_key'], 'Editor restoration is scoped to the opaque handoff storage key.');
$pages = new class implements PageResponder {
    public function guest(string $title, string $content, Http\HttpRequest $request, int $status = 200): Http\HttpResponse { return Http\HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, Http\HttpRequest $request, int $status = 200): Http\HttpResponse { return Http\HttpResponse::html($content, $status); }
};
$preferenceProvider = new class($preferences) implements Auth\UserSubmissionPreferenceProvider { public function __construct(private Auth\UserSubmissionPreferences $value) {} public function forUser(int $userId): Auth\UserSubmissionPreferences { return $this->value; } };
$page = new SubmissionFormPageHandler($pages, $accounts, $preferenceProvider, $problems, $renderer, $contexts, $html, $service);
$route = new Http\RouteMatch((new ReflectionClass(Http\RouteDefinition::class))->newInstanceWithoutConstructor(), ['problem_id' => '100']);
$response = $page->handle($route, new Http\HttpRequest('GET', '/submit/100', ['class_id' => '5', 'draft' => $ref]));
$check($response->status() === 200 && ($response->headers()['Cache-Control'] ?? '') === 'private, no-store' && ($response->headers()['Referrer-Policy'] ?? '') === 'no-referrer', 'A loaded native editor prevents caching and referrer leakage of its short-lived reference.');
$check($store->writes === 1 && ! str_contains($response->body(), 'name="submission_id"'), 'Preparing and opening the form creates no judge submission receipt.');
$badContext = $page->handle($route, new Http\HttpRequest('GET', '/submit/100', ['class_id' => '5', 'group_id' => '6', 'draft' => $ref]));
$missingRoute = new Http\RouteMatch((new ReflectionClass(Http\RouteDefinition::class))->newInstanceWithoutConstructor(), ['problem_id' => '999']);
$missingPage = $page->handle($missingRoute, new Http\HttpRequest('GET', '/submit/999', ['class_id' => '5', 'draft' => $ref]));
$accounts->actor = null;
$loginRedirect = $page->handle($route, new Http\HttpRequest('GET', '/submit/100', ['class_id' => '5', 'draft' => $ref]));
$accounts->actor = 7;
foreach ([[$badContext, 400], [$missingPage, 404], [$loginRedirect, null]] as [$earlyResponse, $status]) {
    $check(($status === null ? $earlyResponse->status() >= 300 && $earlyResponse->status() < 400 : $earlyResponse->status() === $status)
        && ($earlyResponse->headers()['Cache-Control'] ?? '') === 'private, no-store'
        && ($earlyResponse->headers()['Referrer-Policy'] ?? '') === 'no-referrer'
        && ! str_contains($earlyResponse->body(), '한글'), 'Context errors, missing problems and login redirects protect the opaque reference without exposing its source.');
}

$before = $store->writes; $guard->deny = true;
$check(! $gateway->prepare($query)->isSuccessful() && $store->writes === $before, 'Issuance throttling rejects before private draft storage.'); $guard->deny = false;
foreach ([0, 8] as $actor) { $rejects(fn () => $service->load($ref, $actor, 100, $formContext), 'Foreign owners cannot reload a prepared form.'); }
$rejects(fn () => $service->load($ref, 7, 101, $formContext), 'A prepared source cannot migrate to another problem.');
foreach ([['class_id' => 9], ['class_id' => 5, 'group_id' => 6], ['contest_id' => 8], ['class_id' => null, 'group_id' => 6]] as $change) {
    $rejects(fn () => $service->load($ref, 7, 100, array_replace($formContext, $change)), 'A changed learning or contest context cannot reuse the source reference.');
}
$accounts->active = false; $before = $store->writes;
$check(! $gateway->prepare($query)->isSuccessful() && $store->writes === $before, 'Suspended accounts cannot prepare source through the public gateway.');
$rejects(fn () => $service->load($ref, 7, 100, $formContext), 'An inactive profile cannot consume a previously issued reference.'); $accounts->active = true;
$accounts->actor = null;
$check(! $gateway->prepare($query)->isSuccessful(), 'Logged-out requests fail closed.'); $accounts->actor = 7;
$contextProvider->allowed = false;
$check(! $gateway->prepare($query)->isSuccessful(), 'Current membership is required during issuance.');
$rejects(fn () => $service->load($ref, 7, 100, $formContext), 'Revoked membership blocks a previously issued reference.'); $contextProvider->allowed = true;
$problems->visible = false;
$check(! $gateway->prepare($query)->isSuccessful(), 'Problem visibility is checked before storing a draft.'); $problems->visible = true;
$problems->mask = 1 << 6;
$check(! $gateway->prepare($query)->isSuccessful(), 'The problem language mask still applies to transferred code.');
$rejects(fn () => $service->load($ref, 7, 100, $formContext), 'A language disabled after issuance blocks reloading.'); $problems->mask = 0;
$problems->completion = true;
$check(! $gateway->prepare($query)->isSuccessful(), 'Full-source prefill cannot bypass a completion-only exercise.'); $problems->completion = false;
$problems->version = 'b';
$rejects(fn () => $service->load($ref, 7, 100, $formContext), 'A changed code policy invalidates an old prepared editor.'); $problems->version = 'a';
$beforeRows = count($store->rows);
$store->afterStore = static function () use ($store, $contextProvider): void { $store->afterStore = null; $contextProvider->allowed = false; };
$check(! $gateway->prepare($query)->isSuccessful() && count($store->rows) === $beforeRows, 'Authorization revoked after storage discards the undelivered private reference.'); $contextProvider->allowed = true;
$store->fail = true; $failed = $gateway->prepare($query);
$check(! $failed->isSuccessful() && $failed->payload() === [] && $failed->problem()?->retryable()
    && ! str_contains($failed->problem()?->message() ?? '', 'SECRET'), 'Storage failures yield an empty safe retryable result without source or server paths.'); $store->fail = false;
$group = $gateway->prepare(new Api\AddonSubmissionDraftRequestV1(7, 100, 'group', 6, 'cpp', 'int main() {}'));
$check($group->isSuccessful() && str_starts_with($group->payload()['url'], '/submit/100?group_id=6&draft=') && $group->payload()['language'] === 'cpp', 'Group context and C++ language survive the public preparation contract.');
$old = $store->rows[$ref];
$store->rows[$ref] = new Jobs\FormDraft($old->reference(), $old->draftType(), 7, $old->targetType(), $old->targetKey(), $old->payload(), time() - 400, time() - 100);
$rejects(fn () => $service->load($ref, 7, 100, $formContext), 'Expired prepared forms cannot be reused.');
$unavailable = (new UnavailableAddonSubmissionDraftGateway('oj-primm', new AddonCorrelationIdFactory()))->prepare($query);
$check(! $unavailable->isSuccessful() && $unavailable->payload() === [], 'Optional unavailable runtimes fail with no prepared URL or source.');
foreach ([fn () => new Api\AddonSubmissionDraftRequestV1(0, 100, 'class', 5, 'python', 'x'),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 0, 'class', 5, 'python', 'x'),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'contest', 5, 'python', 'x'),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 0, 'python', 'x'),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'ruby', 'x'),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'python', ''),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'python', str_repeat('x', 65537)),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'python', "x\0y"),
    fn () => new Api\AddonSubmissionDraftRequestV1(7, 100, 'class', 5, 'python', "\xff"),
    fn () => new Api\AddonSubmissionDraftResultV1(true, $payload + ['source' => 'private'], null),
    fn () => new Api\AddonSubmissionDraftResultV1(false, $payload, new Api\AddonApiProblemV1('addon_access_denied', 'Denied', 'draft:test', false)),
] as $invalid) { $rejects($invalid, 'Malformed or source-bearing public contracts fail closed.'); }
fwrite(STDOUT, "Addon submission draft runtime: {$checks} checks passed\n");

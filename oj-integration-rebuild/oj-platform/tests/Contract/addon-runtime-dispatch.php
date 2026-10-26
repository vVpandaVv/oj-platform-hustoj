<?php

declare(strict_types=1);

/**
 * Regression coverage for the C28-API-CHANGE onReady redesign: proves that
 * each accepted addon's runtime context is delivered ONLY to the callback
 * that addon itself supplied at registration — never to any other addon,
 * never for a rejected addon, and never via a shared/broadcast mechanism
 * another addon could intercept. This is the fix for the cross-addon
 * impersonation finding (AddonRuntimeGateway::forAddon() had no caller
 * identity check; the fix removes that method and the shared
 * oj_platform_loaded hook entirely).
 */

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCapabilityContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCodeCompletionRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCodeOrderingRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonSubmissionDraftRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonSubmissionDraftGateway;
use CodingPanda\OjPlatform\Kernel\Addon\AddonContributionComposer;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonHookPublisher;
use CodingPanda\OjPlatform\Kernel\Addon\AddonLifecycleManager;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeReadiness;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\AddonMenuContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRouteContributor;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonCodeOrderingGateway;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonCodeCompletionGateway;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function dispatchAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function dispatchSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' 예상=' . var_export($expected, true)
            . ' 실제=' . var_export($actual, true)
        );
    }
}

final class RecordingErrorReporterStub implements ErrorReporter
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $error): void
    {
        $this->reported[] = $error;
    }
}

final class MultiAddonHookPublisher implements AddonHookPublisher
{
    /** @var list<callable(AddonRegistrationGatewayV1): void> */
    private array $registrars = [];

    public function addRegistrar(callable $registrar): void
    {
        $this->registrars[] = $registrar;
    }

    public function publishRegistration(AddonRegistrationGatewayV1 $gateway): void
    {
        foreach ($this->registrars as $registrar) {
            $registrar($gateway);
        }
    }
}

final class NullCoreSnapshotProvider implements CoreContributionSnapshotProvider
{
    public function snapshot(): CoreContributionSnapshot
    {
        return new CoreContributionSnapshot([], [], [], [], [], [], [], []);
    }
}

final class InMemoryAddonNamespaceLedger implements AddonNamespaceLedger
{
    /** @var array<string, string> */
    private array $owners = [];

    public function ownerOf(string $namespace): string
    {
        return $this->owners[$namespace] ?? '';
    }

    public function bind(string $namespace, string $addonId): void
    {
        $this->owners[$namespace] = $addonId;
    }
}

final class StubAuthorization implements Authorization
{
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return false;
    }
}

final class StubCurrentAccountProvider implements CurrentAccountProvider
{
    public function current(): CurrentAccount
    {
        return new CurrentAccount(new UserIdentity(1, 'stub', 'stub@example.test', '스텁'), null);
    }
}

final class StubRouteUrlProvider implements RouteUrlProvider
{
    public function path(string $routeName, array $parameters = []): string
    {
        return '/' . $routeName;
    }
}

final class StubConsoleResponder implements ConsoleResponder
{
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse
    {
        return HttpResponse::html($content, $status);
    }
}

final class StubPageResponder implements PageResponder
{
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
    {
        return HttpResponse::html($content, $status);
    }

    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
    {
        return HttpResponse::html($content, $status);
    }
}

function dispatchBuildLifecycle(
    MultiAddonHookPublisher $hooks,
    RecordingErrorReporterStub $errors,
    AddonNamespaceLedger $namespaceLedger,
    ?AddonRuntimeReadiness $readiness = null,
    ?AddonRuntimeServiceFactory $services = null
): AddonLifecycleManager {
    $routes = new DefinitionRegistry('routes', RouteDefinition::class);
    $menus = new DefinitionRegistry('menus', MenuDefinition::class);
    $capabilities = new DefinitionRegistry('capabilities', CapabilityDefinition::class);
    $features = new DefinitionRegistry('features', FeatureDefinition::class);
    $correlations = new AddonCorrelationIdFactory();

    $composer = new AddonContributionComposer(
        $features,
        new AddonCapabilityContributor($capabilities),
        new AddonRouteContributor(
            $routes,
            new RouteHandlerRegistry(),
            new StubCurrentAccountProvider(),
            new StubAuthorization(),
            $correlations,
            new StubRouteUrlProvider(),
            new StubConsoleResponder(),
            new StubPageResponder()
        ),
        new AddonMenuContributor($menus, new AddonPublicMenuContributions()),
        new AddonAiTaskContributor(new AddonAiTaskContributions()),
        new ConsoleRouteAccessRuleRegistry(),
        new StubAuthorization()
    );

    $catalog = new AcceptedAddonCatalog();
    $validator = new AddonManifestValidator('2.0.0', '1.2.0', new NullCoreSnapshotProvider(), $namespaceLedger);
    $services ??= new UnavailableAddonRuntimeServiceFactory($correlations);
    $readiness ??= new AddonRuntimeReadiness();

    return new AddonLifecycleManager(
        $hooks,
        $validator,
        $catalog,
        $services,
        '2.0.0',
        '1.2.0',
        $correlations,
        $errors,
        $composer,
        $readiness
    );
}

$fixtures = dirname(__DIR__) . '/Fixtures/addons';

// --- Two legitimate addons, each supplying their own onReady callback ---

$receivedByA = [];
$receivedByB = [];
$receivedByC = []; // addon-c will be deliberately rejected — must never receive anything

$hooks = new MultiAddonHookPublisher();
$hooks->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway) use (&$receivedByA): void {
    $gateway->register('addon-a', function (AddonManifestBuilderV1 $builder) use (&$receivedByA): void {
        $builder->manifest(
            'addonns_a',
            '1.0.0',
            dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
            '^2.0.0',
            '^1.1.0',
            '애드온 A',
            'example.test/support-a'
        );
        $builder->aiTask(new AddonAiTaskDefinitionV1('addon.addonns_a.summarize', '요약', '1.0', 'none'));
        $builder->onReady(function (AddonRuntimeContextV1 $context) use (&$receivedByA): void {
            $receivedByA[] = $context;
        });
    });
});
$hooks->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway) use (&$receivedByB): void {
    $gateway->register('addon-b', function (AddonManifestBuilderV1 $builder) use (&$receivedByB): void {
        $builder->manifest(
            'addonns_b',
            '1.0.0',
            dirname(__DIR__) . '/Fixtures/addons/addon-b/plugin.php',
            '^2.0.0',
            '^1.0.0',
            '애드온 B',
            'example.test/support-b'
        );
        $builder->aiTask(new AddonAiTaskDefinitionV1('addon.addonns_b.review', '검토', '1.0', 'none'));
        $builder->onReady(function (AddonRuntimeContextV1 $context) use (&$receivedByB): void {
            $receivedByB[] = $context;
        });
    });
});
// addon-c: no manifest() call at all -> registration fails validation -> must never reach onReady.
$hooks->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway) use (&$receivedByC): void {
    $gateway->register('addon-c', function (AddonManifestBuilderV1 $builder) use (&$receivedByC): void {
        $builder->onReady(function (AddonRuntimeContextV1 $context) use (&$receivedByC): void {
            $receivedByC[] = $context;
        });
        // Deliberately never call $builder->manifest(...).
    });
});

$namespaceLedger = new InMemoryAddonNamespaceLedger();

$errors = new RecordingErrorReporterStub();
$runtimeReadiness = new AddonRuntimeReadiness();
$lifecycle = dispatchBuildLifecycle($hooks, $errors, $namespaceLedger, $runtimeReadiness);
$lifecycle->collectAndFreeze();
$lifecycle->publishRuntimeReady();

// addon-c's own registration closure deliberately never calls manifest() —
// AddonRegistrationGateway isolates that failure per-addon (C28-D03), which
// surfaces here as exactly one reported error. That is expected, not a bug.
dispatchSame(1, count($errors->reported), 'addon-c의 등록 실패만 격리되어 보고돼야 합니다.');
dispatchAssert(
    str_contains($errors->reported[0]->getMessage(), 'manifest'),
    '보고된 오류가 addon-c의 manifest 누락과 관련 없습니다: ' . $errors->reported[0]->getMessage()
);

// Each accepted addon's callback fires EXACTLY ONCE, with ITS OWN context.
dispatchSame(1, count($receivedByA), 'addon-a의 onReady는 정확히 한 번 호출돼야 합니다.');
dispatchSame('^1.1.0', $receivedByA[0]->snapshot()->requiredAddonApi(), '기존 1.1.0 애드온의 요구 버전을 보존해야 합니다.');
dispatchSame('1.2.0', $receivedByA[0]->snapshot()->actualAddonApi(), '기존 호출 방식으로 공통 API 1.2.0에서 실행되어야 합니다.');
dispatchSame(1, count($receivedByB), 'addon-b의 onReady는 정확히 한 번 호출돼야 합니다.');
dispatchSame('addon-a', $receivedByA[0]->snapshot()->addonId(), 'addon-a는 자기 자신의 context를 받아야 합니다.');
dispatchSame('addon-b', $receivedByB[0]->snapshot()->addonId(), 'addon-b는 자기 자신의 context를 받아야 합니다.');
dispatchAssert(
    $receivedByA[0]->snapshot()->addonId() !== $receivedByB[0]->snapshot()->addonId(),
    'addon-a와 addon-b는 서로 다른 context를 받아야 합니다.'
);
dispatchAssert(
    $receivedByA[0] !== $receivedByB[0],
    'addon-a와 addon-b는 완전히 다른 context 객체를 받아야 합니다 (공유 gateway가 없어야 함).'
);

// A rejected addon (invalid manifest) never has its onReady invoked — no impersonation surface at all.
dispatchSame([], $receivedByC, '등록에 실패한 addon-c의 onReady는 절대 호출되면 안 됩니다.');
dispatchSame(
    AddonRuntimeReadiness::READY,
    $runtimeReadiness->state('addon-a')['status'] ?? null,
    'addon-a는 manifest 승인과 별도로 런타임 준비 완료 상태여야 합니다.'
);
dispatchSame(
    AddonRuntimeReadiness::READY,
    $runtimeReadiness->state('addon-b')['status'] ?? null,
    'addon-b는 manifest 승인과 별도로 런타임 준비 완료 상태여야 합니다.'
);
dispatchSame(null, $runtimeReadiness->state('addon-c'), '거부된 addon-c는 런타임 상태를 만들면 안 됩니다.');

// There is no forAddon()-style capability anywhere in the public contract any more.
dispatchAssert(
    ! interface_exists('CodingPanda\\OjPlatform\\AddonApi\\V1\\AddonRuntimeGatewayV1'),
    'AddonRuntimeGatewayV1은 더 이상 존재하면 안 됩니다 (forAddon() 교차 애드온 위장 경로 제거).'
);

// --- An addon that supplies no onReady callback at all is simply skipped, not an error ---

$hooksNoCallback = new MultiAddonHookPublisher();
$hooksNoCallback->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway): void {
    $gateway->register('addon-a', function (AddonManifestBuilderV1 $builder): void {
        $builder->manifest(
            'addonns_a',
            '1.0.0',
            dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
            '^2.0.0',
            '^1.0.0',
            '애드온 A',
            'example.test/support-a'
        );
        // No onReady() call at all.
    });
});
$errorsNoCallback = new RecordingErrorReporterStub();
$noCallbackReadiness = new AddonRuntimeReadiness();
$lifecycleNoCallback = dispatchBuildLifecycle(
    $hooksNoCallback,
    $errorsNoCallback,
    $namespaceLedger,
    $noCallbackReadiness
);
$lifecycleNoCallback->collectAndFreeze();
$lifecycleNoCallback->publishRuntimeReady();
dispatchAssert(
    $errorsNoCallback->reported === [],
    'onReady를 등록하지 않은 애드온은 조용히 건너뛰어야 하며 오류가 아니어야 합니다.'
);
dispatchSame(
    AddonRuntimeReadiness::NOT_REQUIRED,
    $noCallbackReadiness->state('addon-a')['status'] ?? null,
    'onReady가 없는 애드온은 런타임 준비 불필요 상태여야 합니다.'
);

// --- A failing onReady callback keeps catalog acceptance but records fail-closed runtime readiness ---

$hooksFailure = new MultiAddonHookPublisher();
$hooksFailure->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway): void {
    $gateway->register('addon-a', static function (AddonManifestBuilderV1 $builder): void {
        $builder->manifest(
            'addonns_a',
            '1.0.0',
            dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
            '^2.0.0',
            '^1.0.0',
            '애드온 A',
            'example.test/support-a'
        );
        $builder->onReady(static function (AddonRuntimeContextV1 $context): void {
            unset($context);
            throw new RuntimeException('sensitive callback detail');
        });
    });
});
$failureErrors = new RecordingErrorReporterStub();
$failureReadiness = new AddonRuntimeReadiness();
$failureLifecycle = dispatchBuildLifecycle(
    $hooksFailure,
    $failureErrors,
    $namespaceLedger,
    $failureReadiness
);
$failureLifecycle->collectAndFreeze();
$failureLifecycle->publishRuntimeReady();
$failureState = $failureReadiness->state('addon-a');
dispatchSame(AddonRuntimeReadiness::FAILED, $failureState['status'] ?? null, 'onReady 실패를 기록해야 합니다.');
dispatchSame(
    'addon_runtime_callback_failed',
    $failureState['code'] ?? null,
    '운영 상태에는 안정적인 실패 코드만 기록해야 합니다.'
);
dispatchAssert(
    ! str_contains($failureState['code'] ?? '', 'sensitive'),
    '런타임 상태 코드에 예외 원문이 노출되면 안 됩니다.'
);
dispatchSame(1, count($failureErrors->reported), 'onReady 예외는 기존 오류 보고 경로에도 한 번 전달돼야 합니다.');

// --- Cross-boot namespace reuse: a DIFFERENT addonId must never be able to
// claim a namespace ('addonns_a') that a different addon already owns from
// a prior boot — it must not silently inherit that namespace's AI task
// routing assignment history. The SAME addonId ('addon-a', both scenarios
// above) reusing its own namespace across separate lifecycle instances is
// exactly what already happened above without rejection — this is the
// negative case, a different addon trying to take it over.

$receivedByD = [];
$hooksHijack = new MultiAddonHookPublisher();
$hooksHijack->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway) use (&$receivedByD): void {
    $gateway->register('addon-d', function (AddonManifestBuilderV1 $builder) use (&$receivedByD): void {
        $builder->manifest(
            'addonns_a',
            '1.0.0',
            dirname(__DIR__) . '/Fixtures/addons/addon-d/plugin.php',
            '^2.0.0',
            '^1.0.0',
            '애드온 D',
            'example.test/support-d'
        );
        $builder->onReady(function (AddonRuntimeContextV1 $context) use (&$receivedByD): void {
            $receivedByD[] = $context;
        });
    });
});
$errorsHijack = new RecordingErrorReporterStub();
$lifecycleHijack = dispatchBuildLifecycle($hooksHijack, $errorsHijack, $namespaceLedger);
$lifecycleHijack->collectAndFreeze();
$lifecycleHijack->publishRuntimeReady();
dispatchSame(
    [],
    $receivedByD,
    'addon-d는 다른 애드온(addon-a)의 namespace를 재사용하려 했으므로 onReady가 호출되면 안 됩니다.'
);
dispatchAssert(
    $namespaceLedger->ownerOf('addonns_a') === 'addon-a',
    'namespace addonns_a의 소유자는 여전히 addon-a여야 합니다 (addon-d의 탈취 시도가 소유권을 바꾸면 안 됨).'
);

// Optional code ordering factories are wired through onReady without changing
// the base factory interface or the legacy lifecycle construction above.
$extensionCorrelations = new AddonCorrelationIdFactory();
$extensionGateway = new UnavailableAddonCodeOrderingGateway('addon-a', $extensionCorrelations);
$completionGateway = new UnavailableAddonCodeCompletionGateway('addon-a', $extensionCorrelations);
$draftGateway = new UnavailableAddonSubmissionDraftGateway('addon-a', $extensionCorrelations);
$extensionFactory = new class(new UnavailableAddonRuntimeServiceFactory($extensionCorrelations), $extensionGateway, $completionGateway, $draftGateway) implements AddonCodeOrderingRuntimeServiceFactory, AddonCodeCompletionRuntimeServiceFactory, AddonSubmissionDraftRuntimeServiceFactory {
    public array $requested = [];
    public array $requestedCompletion = [];
    public array $requestedDrafts = [];
    public function __construct(private AddonRuntimeServiceFactory $base, private AddonCodeOrderingGatewayV1 $gateway, private AddonCodeCompletionGatewayV1 $completion, private AddonSubmissionDraftGatewayV1 $drafts) {}
    public function contexts(string $addonId, ?AddonCatalogEntry $entry): AddonContextGatewayV1 { return $this->base->contexts($addonId, $entry); }
    public function aiTasks(string $addonId, ?AddonCatalogEntry $entry): AddonAiTaskGatewayV1 { return $this->base->aiTasks($addonId, $entry); }
    public function codeOrdering(string $addonId, ?AddonCatalogEntry $entry): AddonCodeOrderingGatewayV1 {
        $this->requested[] = [$addonId, $entry?->addonId()];
        return $this->gateway;
    }
    public function codeCompletion(string $addonId, ?AddonCatalogEntry $entry): AddonCodeCompletionGatewayV1 {
        $this->requestedCompletion[] = [$addonId, $entry?->addonId()];
        return $this->completion;
    }
    public function submissionDrafts(string $addonId, ?AddonCatalogEntry $entry): AddonSubmissionDraftGatewayV1 {
        $this->requestedDrafts[] = [$addonId, $entry?->addonId()];
        return $this->drafts;
    }
};
$receivedExtension = null;
$extensionHooks = new MultiAddonHookPublisher();
$extensionHooks->addRegistrar(static function (AddonRegistrationGatewayV1 $gateway) use (&$receivedExtension): void {
    $gateway->register('addon-a', static function (AddonManifestBuilderV1 $builder) use (&$receivedExtension): void {
        $builder->manifest('addonns_a', '1.0.0', dirname(__DIR__) . '/Fixtures/addons/addon-a/plugin.php',
            '^2.0.0', '^1.0.0', '애드온 A', 'example.test/support-a');
        $builder->onReady(static function (AddonRuntimeContextV1 $runtime) use (&$receivedExtension): void { $receivedExtension = $runtime; });
    });
});
$extensionErrors = new RecordingErrorReporterStub();
$extensionLifecycle = dispatchBuildLifecycle($extensionHooks, $extensionErrors, $namespaceLedger, null, $extensionFactory);
$extensionLifecycle->collectAndFreeze();
$extensionLifecycle->publishRuntimeReady();
dispatchSame([], $extensionErrors->reported, '선택형 코드 배열 factory 전달에 오류가 없어야 합니다.');
dispatchSame([['addon-a', 'addon-a']], $extensionFactory->requested, '선택형 factory에는 해당 애드온 entry만 전달해야 합니다.');
dispatchAssert($receivedExtension instanceof AddonCodeOrderingRuntimeContextV1
    && $receivedExtension->codeOrdering() === $extensionGateway, 'onReady에서 factory의 코드 배열 gateway를 그대로 사용해야 합니다.');
dispatchSame([['addon-a', 'addon-a']], $extensionFactory->requestedCompletion, '빈칸 채우기 factory에는 해당 애드온 entry만 전달해야 합니다.');
dispatchAssert($receivedExtension instanceof AddonCodeCompletionRuntimeContextV1
    && $receivedExtension->codeCompletion() === $completionGateway, 'onReady에서 factory의 빈칸 채우기 gateway를 그대로 사용해야 합니다.');
dispatchSame([['addon-a', 'addon-a']], $extensionFactory->requestedDrafts, '제출 초안 factory는 해당 애드온의 수락된 entry만 받아야 합니다.');
dispatchAssert($receivedExtension instanceof AddonSubmissionDraftRuntimeContextV1
    && $receivedExtension->submissionDrafts() === $draftGateway, 'onReady는 선택형 제출 초안 gateway를 해당 애드온에 전달해야 합니다.');

fwrite(
    STDOUT,
    "C28-API-CHANGE 런타임 전달 계약 검사 통과: 애드온별 격리된 context, 거부된 애드온 미호출, forAddon 제거 확인\n"
);

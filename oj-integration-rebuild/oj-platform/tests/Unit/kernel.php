<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Boot\BootPhase;
use CodingPanda\OjPlatform\Kernel\Boot\BootTrace;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\BuildContainer;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Environment\RequirementChecker;
use CodingPanda\OjPlatform\Kernel\Error\ErrorBoundary;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Error\FailurePresenter;
use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Module\Module;
use CodingPanda\OjPlatform\Kernel\Module\ModuleCatalog;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleGraph;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Kernel\Registry\RegistrySet;
use CodingPanda\OjPlatform\Kernel\Registry\RegistrySetValidator;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Pagination\Page;
use CodingPanda\OjPlatform\Shared\Pagination\PageRequest;
use CodingPanda\OjPlatform\Shared\Result\Result;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressUiAssetEnqueuer;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressUiAssetLocator;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;

function testAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function testSame(mixed $expected, mixed $actual, string $message): void
{
    testAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

/**
 * @param class-string<Throwable> $expectedClass
 */
function testThrows(callable $operation, string $expectedClass, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        testAssert(
            $error instanceof $expectedClass,
            $message . ' 다른 예외가 발생했습니다: ' . $error::class
        );

        return;
    }

    testAssert(false, $message . ' 예외가 발생하지 않았습니다.');
}

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.test/wp-content/plugins/oj-platform/'
            . ltrim($path, '/');
    }
}

$GLOBALS['oj_platform_test_enqueued_styles'] = [];
$GLOBALS['oj_platform_test_enqueued_scripts'] = [];

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(
        string $handle,
        string $src,
        array $dependencies = [],
        string|bool|null $version = false
    ): void {
        $GLOBALS['oj_platform_test_enqueued_styles'][$handle] = compact(
            'src',
            'dependencies',
            'version'
        );
    }
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(
        string $handle,
        string $src,
        array $dependencies = [],
        string|bool|null $version = false,
        array|bool $arguments = false
    ): void {
        $GLOBALS['oj_platform_test_enqueued_scripts'][$handle] = compact(
            'src',
            'dependencies',
            'version',
            'arguments'
        );
    }
}

final class TestClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class TestModule implements Module
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @param list<string> $events
     */
    public function __construct(
        private readonly ModuleDefinition $moduleDefinition,
        array &$events
    ) {
        $this->events =& $events;
    }

    public function definition(): ModuleDefinition
    {
        return $this->moduleDefinition;
    }

    public function register(): void
    {
        $this->events[] = 'register:' . $this->moduleDefinition->id()->value();
    }

    public function connect(): void
    {
        $this->events[] = 'connect:' . $this->moduleDefinition->id()->value();
    }
}

final class FailingRegisterModule implements Module
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @param list<string> $events
     */
    public function __construct(
        private readonly ModuleDefinition $moduleDefinition,
        array &$events
    ) {
        $this->events =& $events;
    }

    public function definition(): ModuleDefinition
    {
        return $this->moduleDefinition;
    }

    public function register(): void
    {
        $this->events[] = 'register:' . $this->moduleDefinition->id()->value();

        throw new RuntimeException('registration failed');
    }

    public function connect(): void
    {
        $this->events[] = 'connect:' . $this->moduleDefinition->id()->value();
    }
}

final class TestRegistryDefinition implements RegistryDefinition
{
    public function __construct(
        private readonly string $definitionKey,
        private readonly ModuleId $moduleOwner
    ) {
    }

    public function key(): string
    {
        return $this->definitionKey;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }
}

final class OtherRegistryDefinition implements RegistryDefinition
{
    public function key(): string
    {
        return 'other';
    }

    public function owner(): ModuleId
    {
        return new ModuleId('permission');
    }
}

final class TestRegistryValidator implements RegistrySetValidator
{
    public bool $called = false;

    public function validate(): void
    {
        $this->called = true;
    }
}

final class FailingRegistryValidator implements RegistrySetValidator
{
    public function validate(): void
    {
        throw KernelException::invalidRegistry('cross-registry validation failed');
    }
}

final class TestErrorReporter implements ErrorReporter
{
    public ?Throwable $reported = null;

    public function report(Throwable $error): void
    {
        $this->reported = $error;
    }
}

final class TestFailurePresenter implements FailurePresenter
{
    /**
     * @var list<string>
     */
    public array $messages = [];

    public function present(array $messages): void
    {
        $this->messages = array_values($messages);
    }
}

final class TestHookRegistrar implements HookRegistrar
{
    /**
     * @var list<string>
     */
    public array $hooks = [];

    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
        $this->hooks[] = $hook;
    }
}

$success = Result::success('완료');
testAssert($success->isSuccess(), '성공 Result 상태가 올바르지 않습니다.');
testSame('완료', $success->value(), '성공 Result 값이 올바르지 않습니다.');
testSame([], $success->errors(), '성공 Result에 오류가 들어 있습니다.');

$failure = Result::failure('첫 오류', '', '두 번째 오류');
testAssert($failure->isFailure(), '실패 Result 상태가 올바르지 않습니다.');
testSame(['첫 오류', '두 번째 오류'], $failure->errors(), '실패 오류 정규화가 올바르지 않습니다.');
testSame('대체값', $failure->valueOr('대체값'), '실패 Result 대체값이 올바르지 않습니다.');
testThrows(
    static fn () => $failure->value(),
    LogicException::class,
    '실패 Result의 value 접근을 차단해야 합니다.'
);

$pageRequest = new PageRequest(2, 10);
$page = new Page(range(11, 20), 25, $pageRequest);
testSame(10, $pageRequest->offset(), '페이지 offset이 올바르지 않습니다.');
testSame(3, $page->totalPages(), '전체 페이지 수가 올바르지 않습니다.');
testAssert($page->hasPreviousPage(), '이전 페이지 판정이 올바르지 않습니다.');
testAssert($page->hasNextPage(), '다음 페이지 판정이 올바르지 않습니다.');
testThrows(
    static fn () => new PageRequest(1, 101),
    InvalidArgumentException::class,
    '페이지 크기 상한을 검사해야 합니다.'
);

testThrows(
    static fn () => new ModuleId('Bad-Module'),
    KernelException::class,
    '모듈 ID 형식을 검사해야 합니다.'
);
testThrows(
    static fn () => new ModuleDefinition(
        new ModuleId('permission'),
        ModuleType::Required,
        ['permission']
    ),
    KernelException::class,
    '자기 의존을 차단해야 합니다.'
);

$events = [];
$permission = new TestModule(
    new ModuleDefinition(new ModuleId('permission'), ModuleType::Required),
    $events
);
$settings = new TestModule(
    new ModuleDefinition(new ModuleId('settings'), ModuleType::Required, ['permission']),
    $events
);
$platformDb = new TestModule(
    new ModuleDefinition(new ModuleId('platform_db'), ModuleType::Required, ['settings']),
    $events
);
$catalog = new ModuleCatalog([$platformDb, $settings, $permission]);
$ordered = (new ModuleGraph())->sort($catalog);
testSame(
    ['permission', 'settings', 'platform_db'],
    array_map(
        static fn (Module $module): string => $module->definition()->id()->value(),
        $ordered
    ),
    '필수 의존 위상 정렬이 올바르지 않습니다.'
);

$optionalEvents = [];
$classes = new TestModule(
    new ModuleDefinition(
        new ModuleId('classes'),
        ModuleType::Optional,
        ['permission'],
        ['groups']
    ),
    $optionalEvents
);
$groups = new TestModule(
    new ModuleDefinition(new ModuleId('groups'), ModuleType::Optional, ['permission']),
    $optionalEvents
);
$optionalPermission = new TestModule(
    new ModuleDefinition(new ModuleId('permission'), ModuleType::Required),
    $optionalEvents
);
testSame(
    ['permission', 'groups', 'classes'],
    array_map(
        static fn (Module $module): string => $module->definition()->id()->value(),
        (new ModuleGraph())->sort(new ModuleCatalog([$classes, $groups, $optionalPermission]))
    ),
    '존재하는 선택 의존의 위상 정렬이 올바르지 않습니다.'
);
testSame(
    ['permission', 'classes'],
    array_map(
        static fn (Module $module): string => $module->definition()->id()->value(),
        (new ModuleGraph())->sort(new ModuleCatalog([$classes, $optionalPermission]))
    ),
    '없는 선택 의존은 모듈 적재를 막지 않아야 합니다.'
);

$missingEvents = [];
$missing = new TestModule(
    new ModuleDefinition(new ModuleId('settings'), ModuleType::Required, ['permission']),
    $missingEvents
);
testThrows(
    static fn () => (new ModuleGraph())->sort(new ModuleCatalog([$missing])),
    KernelException::class,
    '필수 의존 누락을 차단해야 합니다.'
);

$cycleEvents = [];
$cycleA = new TestModule(
    new ModuleDefinition(new ModuleId('cycle_a'), ModuleType::Required, ['cycle_b']),
    $cycleEvents
);
$cycleB = new TestModule(
    new ModuleDefinition(new ModuleId('cycle_b'), ModuleType::Required, ['cycle_a']),
    $cycleEvents
);
testThrows(
    static fn () => (new ModuleGraph())->sort(new ModuleCatalog([$cycleA, $cycleB])),
    KernelException::class,
    '순환 의존을 차단해야 합니다.'
);
testThrows(
    static fn () => new ModuleCatalog([$permission, $permission]),
    KernelException::class,
    '중복 모듈 ID를 차단해야 합니다.'
);

$routeRegistry = new DefinitionRegistry('routes', TestRegistryDefinition::class);
$route = new TestRegistryDefinition('problems.index', new ModuleId('permission'));
$routeRegistry->register($route);
testAssert($routeRegistry->find('problems.index') === $route, 'Registry 조회가 올바르지 않습니다.');
testThrows(
    static fn () => $routeRegistry->register($route),
    KernelException::class,
    'Registry 중복 키를 차단해야 합니다.'
);
testThrows(
    static fn () => $routeRegistry->register(new OtherRegistryDefinition()),
    KernelException::class,
    'Registry 타입 불일치를 차단해야 합니다.'
);

$validator = new TestRegistryValidator();
$registrySet = new RegistrySet([$routeRegistry], [$validator]);
$registrySet->validateAndFreeze();
testAssert($validator->called, 'Registry 교차 검증기가 실행되지 않았습니다.');
testAssert($registrySet->isFrozen(), 'Registry Set이 동결되지 않았습니다.');
testAssert($routeRegistry->isFrozen(), '개별 Registry가 동결되지 않았습니다.');
testThrows(
    static fn () => $routeRegistry->register(
        new TestRegistryDefinition('problems.show', new ModuleId('permission'))
    ),
    KernelException::class,
    '동결 뒤 Registry 등록을 차단해야 합니다.'
);

$unfrozenRegistry = new DefinitionRegistry('actions', TestRegistryDefinition::class);
$failingSet = new RegistrySet([$unfrozenRegistry], [new FailingRegistryValidator()]);
testThrows(
    static fn () => $failingSet->validateAndFreeze(),
    KernelException::class,
    '교차 검증 실패를 전달해야 합니다.'
);
testAssert(! $unfrozenRegistry->isFrozen(), '검증 실패한 Registry를 동결하면 안 됩니다.');

$container = new BuildContainer();
$service = new stdClass();
$container->bind('service', $service);
testAssert($container->get('service') === $service, 'BuildContainer 조회가 올바르지 않습니다.');
testThrows(
    static fn () => $container->bind('service', new stdClass()),
    KernelException::class,
    'BuildContainer 중복 등록을 차단해야 합니다.'
);
testThrows(
    static fn () => $container->get('missing'),
    KernelException::class,
    'BuildContainer의 미등록 조회를 차단해야 합니다.'
);
$container->freeze();
testThrows(
    static fn () => $container->bind('later', new stdClass()),
    KernelException::class,
    '동결된 BuildContainer 등록을 차단해야 합니다.'
);

$kernelEvents = [];
$kernelPermission = new TestModule(
    new ModuleDefinition(new ModuleId('permission'), ModuleType::Required),
    $kernelEvents
);
$kernelSettings = new TestModule(
    new ModuleDefinition(new ModuleId('settings'), ModuleType::Required, ['permission']),
    $kernelEvents
);
$kernelRegistries = new RegistrySet([
    new DefinitionRegistry('kernel.routes', TestRegistryDefinition::class),
]);
$fixedTime = new DateTimeImmutable('2026-07-26T00:00:00+00:00');
$kernel = new Kernel(
    new ModuleCatalog([$kernelSettings, $kernelPermission]),
    new ModuleGraph(),
    $kernelRegistries,
    new TestClock($fixedTime)
);
$bootReport = $kernel->boot();
testSame(
    [
        'register:permission',
        'register:settings',
        'connect:permission',
        'connect:settings',
    ],
    $kernelEvents,
    '모듈 등록과 Adapter 연결 순서가 올바르지 않습니다.'
);
testSame(
    array_map(
        static fn (BootPhase $phase): string => $phase->value,
        BootPhase::cases()
    ),
    array_map(
        static fn (BootPhase $phase): string => $phase->value,
        $bootReport->completedPhases()
    ),
    '6단계 부팅 순서가 올바르지 않습니다.'
);
testSame(['permission', 'settings'], $bootReport->moduleIds(), '부팅 모듈 순서가 올바르지 않습니다.');
testSame($fixedTime, $bootReport->completedAt(), '부팅 완료 시각이 올바르지 않습니다.');
testThrows(
    static fn () => $kernel->boot(),
    KernelException::class,
    'Kernel 재부팅을 차단해야 합니다.'
);

$failedEvents = [];
$failingModule = new FailingRegisterModule(
    new ModuleDefinition(new ModuleId('permission'), ModuleType::Required),
    $failedEvents
);
$failingKernel = new Kernel(
    new ModuleCatalog([$failingModule]),
    new ModuleGraph(),
    new RegistrySet([]),
    new TestClock($fixedTime)
);
testThrows(
    static fn () => $failingKernel->boot(),
    RuntimeException::class,
    '모듈 등록 실패를 전달해야 합니다.'
);
testSame(
    ['register:permission'],
    $failedEvents,
    '계약 등록 실패 뒤 Adapter를 연결하면 안 됩니다.'
);

$trace = new BootTrace();
testThrows(
    static fn () => $trace->advance(BootPhase::ModuleGraph),
    KernelException::class,
    '부팅 단계 건너뛰기를 차단해야 합니다.'
);

$reporter = new TestErrorReporter();
$presenter = new TestFailurePresenter();
$boundary = new ErrorBoundary($reporter, $presenter);
$boundarySuccess = $boundary->execute(static fn (): string => '정상');
testSame('정상', $boundarySuccess->value(), 'Error Boundary 성공 결과가 올바르지 않습니다.');
$boundaryFailure = $boundary->execute(static function (): void {
    throw new RuntimeException('boot failed');
});
testAssert($boundaryFailure->isFailure(), 'Error Boundary가 실패를 반환하지 않았습니다.');
testAssert($reporter->reported instanceof RuntimeException, '부팅 예외가 기록되지 않았습니다.');
testSame(
    ['OJ Platform을 시작하지 못했습니다. 시스템 기록을 확인해 주세요.'],
    $presenter->messages,
    '사용자 부팅 실패 문구가 올바르지 않습니다.'
);

$environment = PluginEnvironment::fromPluginFile(
    $pluginRoot . '/oj-platform.php',
    '2.0.0-dev.102',
    '6.6',
    '8.1'
);
$assetLocator = new WordPressUiAssetLocator($environment);
testSame(
    'https://example.test/wp-content/plugins/oj-platform/resources/ui/css/oj-ui-system.css?ver=2.0.0-dev.102',
    $assetLocator->stylesheetUrl(),
    '사용자 화면 CSS 주소에 플러그인 버전이 포함되어야 합니다.'
);
testSame(
    'https://example.test/wp-content/plugins/oj-platform/resources/ui/js/oj-ui.js?ver=2.0.0-dev.102',
    $assetLocator->scriptUrl(),
    '사용자 화면 스크립트 주소에 플러그인 버전이 포함되어야 합니다.'
);
testSame(
    'https://example.test/wp-content/plugins/oj-platform/resources/features/mathjax/js/loader.js?ver=2.0.0-dev.102',
    $assetLocator->mathJaxLoaderUrl(),
    'MathJax 로더 주소에 플러그인 버전이 포함되어야 합니다.'
);
testSame(
    'https://example.test/wp-content/plugins/oj-platform/resources/ui/icons/lucide.svg?ver=2.0.0-dev.102',
    $assetLocator->iconSpriteUrl(),
    '사용자 화면 아이콘 주소에 플러그인 버전이 포함되어야 합니다.'
);
$assetEnqueuer = new WordPressUiAssetEnqueuer($environment);
$assetEnqueuer->enqueue();
testSame(
    $assetLocator->stylesheetUrl(),
    $GLOBALS['oj_platform_test_enqueued_styles']['oj-platform-ui-system']['src'] ?? null,
    '관리 화면과 사용자 화면은 같은 CSS 주소 생성기를 사용해야 합니다.'
);
testSame(
    null,
    $GLOBALS['oj_platform_test_enqueued_styles']['oj-platform-ui-system']['version'],
    'CSS 주소에 이미 포함된 버전을 WordPress가 중복해서 추가하면 안 됩니다.'
);
testSame(
    $assetLocator->scriptUrl(),
    $GLOBALS['oj_platform_test_enqueued_scripts']['oj-platform-ui']['src'] ?? null,
    '관리 화면과 사용자 화면은 같은 스크립트 주소 생성기를 사용해야 합니다.'
);
testSame(
    $assetLocator->mathJaxLoaderUrl(),
    $GLOBALS['oj_platform_test_enqueued_scripts']['oj-platform-mathjax-loader']['src'] ?? null,
    '문제 본문용 MathJax 로더를 등록해야 합니다.'
);
testSame(
    ['oj-platform-ui'],
    $GLOBALS['oj_platform_test_enqueued_scripts']['oj-platform-mathjax-loader']['dependencies'] ?? null,
    'MathJax 로더는 공통 UI 초기화 뒤 실행되어야 합니다.'
);
$requirementChecker = new RequirementChecker();
testAssert(
    $requirementChecker->check($environment, '6.6', '8.1')->isSuccess(),
    '최소 요구 버전을 통과해야 합니다.'
);
testSame(
    2,
    count($requirementChecker->check($environment, '6.5', '8.0')->errors()),
    'WordPress와 PHP 요구 버전 오류를 함께 반환해야 합니다.'
);
testSame(
    ['접두어 없는 플랫폼 테이블을 사용하므로 WordPress Multisite에는 설치할 수 없습니다.'],
    $requirementChecker->check($environment, '6.6', '8.1', true)->errors(),
    'Multisite 설치를 요구조건 단계에서 차단해야 합니다.'
);

$foundationHooks = new TestHookRegistrar();
$foundationBoot = (new CompositionRoot(
    $environment,
    $foundationHooks,
    new TestErrorReporter()
))->boot();
testSame(
    [
        'permission',
        'settings',
        'platform_db',
        'judge_db',
        'jobs',
        'access_router',
        'auth',
        'public_ui',
        'problems',
        'submissions',
        'classes',
        'contests',
        'groups',
        'ai_feedback',
        'learning_dashboard',
        'ranking',
        'blockly',
        'board',
        'messaging',
        'admin_console',
        'migration',
    ],
    $foundationBoot->moduleIds(),
    'R-10 Composition Root의 운영 모듈 포함 순서가 올바르지 않습니다.'
);
testSame(6, count($foundationBoot->completedPhases()), '공통 기반도 6단계 부팅을 완료해야 합니다.');
testSame(
    ['admin_menu', 'admin_notices', 'admin_post_oj_platform_setup', 'admin_enqueue_scripts'],
    $foundationHooks->hooks,
    '신규 설치 상태의 임시 Hook 구성이 올바르지 않습니다.'
);

$recordingReporterStore = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore {
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
$recordingReporterLogger = new class implements \CodingPanda\OjPlatform\Kernel\Error\TrackingLogger {
    /** @var list<array{0: string, 1: string}> */
    public array $logged = [];

    public function log(string $trackingId, string $errorClass): void
    {
        $this->logged[] = [$trackingId, $errorClass];
    }
};
$recordingReporterDelegate = new TestErrorReporter();
$recordingReporter = new \CodingPanda\OjPlatform\Kernel\Integration\Operations\RecordingErrorReporter(
    $recordingReporterDelegate,
    $recordingReporterStore,
    $recordingReporterLogger
);
$recordingReporterError = new RuntimeException('추적 ID 회귀 테스트');
$recordingReporter->report($recordingReporterError);

$recordingReporterStoredTrackingId = (string) (
    $recordingReporterStore->values['oj_platform_last_fatal']['value']['tracking_id'] ?? ''
);
testAssert(
    $recordingReporterStoredTrackingId !== '',
    '오류 기록 시 추적 ID를 설정 저장소에 남겨야 합니다.'
);
testAssert(
    $recordingReporterStoredTrackingId !== ''
        && $recordingReporterLogger->logged === [[
            $recordingReporterStoredTrackingId,
            RuntimeException::class,
        ]],
    '시스템 상태 화면이 안내하는 추적 ID는 실제 서버 로그에도 기록되어야 합니다.'
);
testAssert(
    $recordingReporterDelegate->reported === $recordingReporterError,
    '추적 ID를 기록한 뒤에도 실제 오류 보고 위임은 계속 수행해야 합니다.'
);

fwrite(STDOUT, sprintf("R-10 Kernel 단위 검사 통과: assertion %d개\n", $assertions));

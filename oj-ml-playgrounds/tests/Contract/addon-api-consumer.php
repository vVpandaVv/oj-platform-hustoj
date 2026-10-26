<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRouteDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRoutingSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCandidate;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;
use OJML\Playgrounds\Application\ArtifactPreviewService;
use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Application\ReinspectionService;
use OJML\Playgrounds\Application\ContextService;
use OJML\Playgrounds\Application\SubmissionService;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Platform\Addon_Integration;
use OJML\Playgrounds\Platform\Addon_Manifest;
use OJML\Playgrounds\Platform\Addon_Page_Handler;
use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;
use OJML\Playgrounds\Rest\ArtifactPreviewRoute;
use OJML\Playgrounds\Rest\Presenter;
use OJML\Playgrounds\Rest\ReinspectionRoute;
use OJML\Playgrounds\Rest\Routes;
use OJML\Playgrounds\Security\Addon_Capabilities;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;

$pluginRoot = dirname(__DIR__, 2);
$workspaceRoot = dirname($pluginRoot);
$configuredPlatform = getenv('OJ_PLATFORM_TEST_ROOT');
$platformCandidates = array_filter([
    is_string($configuredPlatform) ? $configuredPlatform : '',
    $workspaceRoot . '/oj-integration-rebuild/oj-platform',
    $workspaceRoot . '/oj-platform',
]);
$platformRoot = '';
foreach ($platformCandidates as $candidate) {
    if (is_file($candidate . '/src/Kernel/Autoloader.php')) {
        $platformRoot = $candidate;
        break;
    }
}
if ($platformRoot === '') {
    fwrite(STDOUT, "SKIP: OJ Platform test source is unavailable.\n");
    exit(0);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}
if (!defined('OJ_ML_PLAYGROUNDS_VERSION')) {
    define('OJ_ML_PLAYGROUNDS_VERSION', (require $pluginRoot . '/plugin-package.php')['version']);
}
if (!defined('OJ_ML_PLAYGROUNDS_FILE')) {
    define('OJ_ML_PLAYGROUNDS_FILE', $pluginRoot . '/oj-ml-playgrounds.php');
}
if (!defined('OJ_ML_PLAYGROUNDS_DIR')) {
    define('OJ_ML_PLAYGROUNDS_DIR', $pluginRoot . '/');
}
if (!defined('OJ_ML_PLAYGROUNDS_URL')) {
    define('OJ_ML_PLAYGROUNDS_URL', 'https://example.test/wp-content/plugins/oj-ml-playgrounds/');
}

if (!function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        return $userId === 7 && $capability === 'oj_addon_oj_ml_playgrounds_submit';
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return $action === 'wp_rest' ? 'nonce-value' : '';
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        return $value;
    }
}

require_once $platformRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
require_once $pluginRoot . '/src/Core/Autoloader.php';
(new \OJML\Playgrounds\Core\Autoloader($pluginRoot . '/src'))->register();

/** @param mixed $actual */
function ojMlSmokeAssert(bool $condition, string $message, $actual = null): void
{
    if ($condition) {
        return;
    }
    $suffix = $actual === null ? '' : ' actual=' . var_export($actual, true);
    throw new RuntimeException($message . $suffix);
}

/** @param list<class-string> $expected */
function ojMlSmokeConstructor(string $className, array $expected): void
{
    $constructor = (new ReflectionClass($className))->getConstructor();
    $actual = [];
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $actual[] = $type instanceof ReflectionNamedType ? $type->getName() : '';
    }
    ojMlSmokeAssert($actual === $expected, $className . ' constructor contract mismatch.', $actual);
}

$runtime = new Addon_Runtime_Bridge();
$builder = new AddonManifestBuilder();
Addon_Manifest::build($builder, $runtime);
$manifest = $builder->close();

$core = new class implements CoreContributionSnapshotProvider {
    public function snapshot(): CoreContributionSnapshot
    {
        return new CoreContributionSnapshot(
            [],
            [],
            [],
            ['learning_support', 'operations'],
            ['learning_support'],
            [],
            [],
            []
        );
    }
};
$ledger = new class implements AddonNamespaceLedger {
    /** @var array<string,string> */
    private array $owners = [];

    public function ownerOf(string $namespace): string
    {
        return $this->owners[$namespace] ?? '';
    }

    public function bind(string $namespace, string $addonId): void
    {
        $this->owners[$namespace] = $addonId;
    }
};
$validator = new AddonManifestValidator('2.0.0-dev.88', '1.1.0', $core, $ledger);
$entries = $validator->validate([
    AddonCandidate::manifest(
        Addon_Integration::ADDON_ID,
        $manifest,
        $builder->onReadyCallback()
    ),
]);
$entry = $entries[0] ?? null;
ojMlSmokeAssert($entry !== null && $entry->isAccepted(), 'Manifest must be accepted.', $entry?->code());

$expectedCounts = [
    'capability' => 6,
    'console_page' => 3,
    'public_page' => 3,
    'console_menu' => 1,
    'public_menu' => 1,
    'asset_bundle' => 1,
    'ai_task' => 0,
    'console_local_nav' => 3,
];
ojMlSmokeAssert($manifest->contributionCounts() === $expectedCounts, 'Manifest contribution count mismatch.', $manifest->contributionCounts());

ojMlSmokeConstructor(AssignmentService::class, [Repository::class, ContextService::class, \OJML\Playgrounds\Application\TeachingDatasetService::class]);
ojMlSmokeConstructor(SubmissionService::class, [Repository::class, AssignmentService::class, PrivateArtifactStore::class, MlpxInspector::class]);
ojMlSmokeConstructor(ReinspectionService::class, [Repository::class, AssignmentService::class, PrivateArtifactStore::class, MlpxInspector::class]);
ojMlSmokeConstructor(ArtifactPreviewService::class, [Repository::class, AssignmentService::class, PrivateArtifactStore::class, ArtifactIntegrityVerifier::class, MlpxInspector::class]);
ojMlSmokeConstructor(Routes::class, [AssignmentService::class, SubmissionService::class, ContextService::class, Presenter::class]);
ojMlSmokeConstructor(ReinspectionRoute::class, [ReinspectionService::class, Presenter::class]);
ojMlSmokeConstructor(ArtifactPreviewRoute::class, [ArtifactPreviewService::class]);

$before = new Addon_Page_Handler(
    $runtime,
    Addon_Manifest::PUBLIC_LAB,
    'lab',
    'ML 실습',
    Addon_Capabilities::SUBMIT,
    'assignment_id',
    true
);
$pageRequest = new AddonPageRequestV1(
    Addon_Manifest::PUBLIC_LAB,
    7,
    ['assignment_id' => '42'],
    [],
    'ko_KR',
    'corr-page'
);
ojMlSmokeAssert($before->handle($pageRequest)->status() === 503, 'Page must fail closed before onReady.');

$snapshot = new AddonRuntimeSnapshotV1(
    Addon_Integration::ADDON_ID,
    OJ_ML_PLAYGROUNDS_VERSION,
    Addon_Integration::PLATFORM_RANGE,
    '2.0.0-dev.88',
    Addon_Integration::ADDON_API_RANGE,
    '1.1.0',
    'accepted',
    '',
    'catalog-revision',
    $expectedCounts,
    'corr-runtime'
);
$contexts = new class implements AddonLearningContextGatewayV1, AddonAssignmentRosterGatewayV1 {
    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function listLearningContexts(AddonLearningContextQueryV1 $query): AddonContextPageV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function listAssignmentLearners(AddonAssignmentRosterQueryV1 $query): AddonAssignmentLearnerPageV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }
};
$aiTasks = new class implements AddonAiTaskGatewayV1 {
    public function canRoute(string $taskKey): AddonAiTaskRouteDecisionV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }

    public function execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1
    {
        throw new RuntimeException('Not used by this smoke test.');
    }
};
$runtimeContext = new class($snapshot, $contexts, $aiTasks) implements AddonRuntimeContextV1 {
    public function __construct(
        private readonly AddonRuntimeSnapshotV1 $runtimeSnapshot,
        private readonly AddonContextGatewayV1 $contextGateway,
        private readonly AddonAiTaskGatewayV1 $aiTaskGateway
    ) {
    }

    public function snapshot(): AddonRuntimeSnapshotV1
    {
        return $this->runtimeSnapshot;
    }

    public function contexts(): AddonContextGatewayV1
    {
        return $this->contextGateway;
    }

    public function aiTasks(): AddonAiTaskGatewayV1
    {
        return $this->aiTaskGateway;
    }
};

$runtime->connect($runtimeContext);
ojMlSmokeAssert($runtime->is_ready(), 'Runtime must become ready only after onReady connection.');
ojMlSmokeAssert($before->handle($pageRequest)->status() === 200, 'Page must become available after onReady.');

$secondConnectionRejected = false;
try {
    $runtime->connect($runtimeContext);
} catch (LogicException) {
    $secondConnectionRejected = true;
}
ojMlSmokeAssert($secondConnectionRejected, 'Runtime bridge must reject a second connection.');

fwrite(STDOUT, "OJ ML Playgrounds addon API consumer smoke passed.\n");

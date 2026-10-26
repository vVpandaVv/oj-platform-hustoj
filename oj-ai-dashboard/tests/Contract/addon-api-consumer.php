<?php
/**
 * G1 Addon API 1.1 consumer contract.
 *
 * This test uses the adjacent platform source only as a development fixture.
 * Product Runtime code remains limited to the public AddonApi\V1 namespace.
 *
 * @package OJAI\Dashboard
 */

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
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCandidate;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Core\Activation_Preflight;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Platform\Addon_Integration;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use OJAI\Dashboard\Platform\Assignment_Workbench_Page_Handler;
use OJAI\Dashboard\Platform\External_Ai_Consent_Settings_Page_Handler;
use OJAI\Dashboard\Platform\Report_Dashboard_Page_Handler;
use OJAI\Dashboard\Platform\Storage_Operations_Detail_Page_Handler;
use OJAI\Dashboard\Platform\Addon_Runtime_Readiness_Gate;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Platform\Worksheet_Evaluation_Workbench_Page_Handler;
use OJAI\Dashboard\Platform\Worksheet_Workbench_Page_Handler;
use OJAI\Dashboard\Security\Policy\Access_Policy;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Actor_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;

$sourceRoot   = dirname( __DIR__, 2 );
$addonRoot    = isset( $argv[1] ) ? realpath( $argv[1] ) : $sourceRoot;
if ( ! is_string( $addonRoot ) || ! is_dir( $addonRoot ) ) {
	throw new RuntimeException( 'The add-on source or staged package directory was not found.' );
}
$platformRoot = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platformRoot ) || '' === ( $platformRoot ) ) {
	$platformRoot = dirname( $sourceRoot ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platformRoot = realpath( $platformRoot );

if ( ! is_string( $platformRoot ) || ! is_file(
	$platformRoot . DIRECTORY_SEPARATOR . 'src'
	. DIRECTORY_SEPARATOR . 'AddonApi' . DIRECTORY_SEPARATOR . 'V1'
	. DIRECTORY_SEPARATOR . 'AddonManifestBuilderV1.php'
) ) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}

$pluginSource = (string) file_get_contents( $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
if ( 1 !== ( preg_match( "/define\\(\\s*'OJ_AI_DASHBOARD_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $pluginSource, $addonVersionMatch ) ) ) {
	throw new RuntimeException( 'The add-on version constant was not found.' );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addonRoot . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_VERSION' ) || define( 'OJ_AI_DASHBOARD_VERSION', $addonVersionMatch[1] );
defined( 'OJ_AI_DASHBOARD_FILE' ) || define( 'OJ_AI_DASHBOARD_FILE', $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $addonRoot . DIRECTORY_SEPARATOR );

require_once $platformRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platformRoot );

require_once $addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addonRoot . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G1 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g1_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G1 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g1_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true )
			. '\nActual: ' . var_export( $actual, true )
		);
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1RegistrationGateway implements AddonRegistrationGatewayV1 {

	/**
	 * Addon id.
	 *
	 * @var string
	 */
	public string $addonId = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	/**
	 * Manifest.
	 *
	 * @var AddonManifest|null
	 */
	public ?AddonManifest $manifest = null;
	/**
	 * On ready.
	 *
	 * @var mixed
	 */
	public mixed $onReady = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	/** @var string Registration failure retained for diagnostics. */
	public string $failure = '';

	/**
	 * Api version.
	 *
	 * @return string
	 */
	public function apiVersion(): string {
		return '1.1.0';
	}

	/**
	 * Register.
	 *
	 * @param string   $addonId Addon id.
	 * @param callable $build Build.
	 * @return void
	 */
	public function register( string $addonId, callable $build ): void {
		$builder = new AddonManifestBuilder();
		try {
			$build( $builder );
		} catch ( Throwable $error ) {
			$this->failure = $error->getMessage();
			throw $error;
		}

		$this->addonId  = $addonId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
		$this->manifest = $builder->close();
		$this->onReady  = $builder->onReadyCallback(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1CoreContributionSnapshotProvider implements CoreContributionSnapshotProvider {

	/**
	 * Snapshot.
	 *
	 * @return CoreContributionSnapshot
	 */
	public function snapshot(): CoreContributionSnapshot {
		return new CoreContributionSnapshot( array(), array(), array(), array( 'classes', 'operations' ), array( 'classes' ), array(), array(), array() );
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1NamespaceLedger implements AddonNamespaceLedger {

	/**
	 * Owners.
	 *
	 * @var array<string,string>
	 */
	private array $owners = array();

	/**
	 * Owner of.
	 *
	 * @param string $namespace Namespace.
	 * @return string
	 */
	public function ownerOf( string $namespace ): string {
		return $this->owners[ $namespace ] ?? '';
	}

	/**
	 * Bind.
	 *
	 * @param string $namespace Namespace.
	 * @param string $addonId Addon id.
	 * @return void
	 */
	public function bind( string $namespace, string $addonId ): void {
		$this->owners[ $namespace ] = $addonId;
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1ContextGateway implements AddonLearningContextGatewayV1, AddonAssignmentRosterGatewayV1 {

	/**
	 * List teaching contexts.
	 *
	 * @param AddonTeachingContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listTeachingContexts( AddonTeachingContextQueryV1 $query ): AddonContextPageV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * Authorize.
	 *
	 * @param AddonContextAccessRequestV1 $request Request.
	 * @return AddonContextAccessDecisionV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( AddonContextAccessRequestV1 $request ): AddonContextAccessDecisionV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * Snapshot.
	 *
	 * @param AddonContextSnapshotRequestV1 $request Request.
	 * @return AddonContextSnapshotResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( AddonContextSnapshotRequestV1 $request ): AddonContextSnapshotResultV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * List learning contexts.
	 *
	 * @param AddonLearningContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listLearningContexts( AddonLearningContextQueryV1 $query ): AddonContextPageV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * List assignment learners.
	 *
	 * @param AddonAssignmentRosterQueryV1 $query Query.
	 * @return AddonAssignmentLearnerPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listAssignmentLearners(
		AddonAssignmentRosterQueryV1 $query
	): AddonAssignmentLearnerPageV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1AiTaskGateway implements AddonAiTaskGatewayV1 {

	/**
	 * Can route.
	 *
	 * @param string $taskKey Task key.
	 * @return AddonAiTaskRouteDecisionV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function canRoute( string $taskKey ): AddonAiTaskRouteDecisionV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * Snapshot.
	 *
	 * @param string $taskKey Task key.
	 * @return AddonAiTaskRoutingSnapshotV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( string $taskKey ): AddonAiTaskRoutingSnapshotV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}

	/**
	 * Execute g1 ai task gateway.
	 *
	 * @param AddonAiTaskRequestV1 $request Request.
	 * @return AddonAiTaskResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( AddonAiTaskRequestV1 $request ): AddonAiTaskResultV1 {
		throw new RuntimeException( 'Not called by the G1 contract test.' );
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1RuntimeContext implements AddonRuntimeContextV1 {

	/**
	 * Initialize g1 runtime context dependencies and state.
	 *
	 * @param AddonRuntimeSnapshotV1 $runtimeSnapshot Runtime snapshot.
	 * @param G1ContextGateway       $contextGateway Context gateway.
	 * @param G1AiTaskGateway        $aiTaskGateway Ai task gateway.
	 */
	public function __construct(
		private readonly AddonRuntimeSnapshotV1 $runtimeSnapshot,
		private readonly G1ContextGateway $contextGateway,
		private readonly G1AiTaskGateway $aiTaskGateway
	) {
	}

	/**
	 * Snapshot.
	 *
	 * @return AddonRuntimeSnapshotV1
	 */
	public function snapshot(): AddonRuntimeSnapshotV1 {
		return $this->runtimeSnapshot; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	}

	/**
	 * Contexts.
	 *
	 * @return G1ContextGateway
	 */
	public function contexts(): G1ContextGateway {
		return $this->contextGateway; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	}

	/**
	 * Ai tasks.
	 *
	 * @return G1AiTaskGateway
	 */
	public function aiTasks(): G1AiTaskGateway {
		return $this->aiTaskGateway; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1SchemaReadiness implements Schema_Runtime_Readiness_Port_Interface {

	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Initialize g1 schema readiness dependencies and state.
	 *
	 * @param Schema_Runtime_Readiness_Result $result Result.
	 */
	public function __construct( private readonly Schema_Runtime_Readiness_Result $result ) {
	}

	/**
	 * Verify g1 schema readiness.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		++$this->calls;

		return $this->result;
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1ActorAuthorization implements Actor_Authorization_Port_Interface {

	/**
	 * Is active.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return bool
	 */
	public function is_active( int $actor_user_id ): bool {
		return true;
	}

	/**
	 * Has capability.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $wordpress_capability WordPress capability.
	 * @return bool
	 */
	public function has_capability( int $actor_user_id, string $wordpress_capability ): bool {
		return true;
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1ContextAuthorization implements Context_Authorization_Port_Interface {

	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result {
		throw new RuntimeException( 'Static G1 pages must not authorize a Context.' );
	}
}

/**
 * G1 Addon API 1.1 consumer contract.
 */
final class G1ObjectAuthorization implements Object_Authorization_Port_Interface {

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object
	): Object_Authorization_Result {
		return Object_Authorization_Result::unavailable();
	}
}

/**
 * G1 access policy.
 *
 * @param Addon_Runtime_Bridge $bridge Bridge.
 * @return Access_Policy
 */
function g1_access_policy( Addon_Runtime_Bridge $bridge ): Access_Policy {
	return new Access_Policy(
		$bridge,
		new G1ActorAuthorization(),
		new G1ContextAuthorization(),
		new G1ObjectAuthorization()
	);
}

$bridge          = new Addon_Runtime_Bridge();
$schemaReadiness = new G1SchemaReadiness(
	new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::READY, 'schema_runtime_ready' )
);
$readinessGate   = new Addon_Runtime_Readiness_Gate( $bridge, $schemaReadiness );
$integration     = new Addon_Integration( $bridge, $readinessGate, g1_access_policy( $bridge ) );
$gateway         = new G1RegistrationGateway();
$integration->register( $gateway );

g1_same( '', $gateway->failure, 'The platform must accept every manifest definition.' );
g1_same( Addon_Integration::ADDON_ID, $gateway->addonId, 'The registration must use the frozen add-on ID.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
g1_assert( $gateway->manifest instanceof AddonManifest, 'The complete Manifest must be built.' );
g1_assert( is_callable( $gateway->onReady ), 'The Manifest must include one onReady callback.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.

$manifest = $gateway->manifest;
g1_same( 'oj_ai_dashboard', $manifest->namespace(), 'Manifest namespace drifted.' );
g1_same( OJ_AI_DASHBOARD_VERSION, $manifest->version(), 'Manifest version drifted.' );
g1_same( '>=2.0.0-dev.33 <3.0.0', $manifest->requiresPlatform(), 'Platform range drifted.' );
g1_same( '>=1.1.0 <2.0.0', $manifest->requiresAddonApi(), 'Addon API range drifted.' );
g1_same( 'OJAI-A-DS-02', $manifest->supportReference(), 'Support reference drifted.' );
g1_same(
	array(
		'capability'   => 15,
		'console_page' => 9,
		'public_page'  => 3,
		'console_menu' => 1,
		'public_menu'  => 1,
		'asset_bundle' => 9,
		'ai_task'      => 4,
	),
	array_diff_key( $manifest->contributionCounts(), array( 'console_local_nav' => true ) ),
	'Manifest contribution counts drifted.'
);
// API-AUD-2026-10-13-R165: the platform reports console_local_nav only when used; the Runtime bridge expects the same map.
$g1ExpectedRuntimeCounts = Addon_Manifest::contribution_counts( array_key_exists( 'console_local_nav', $manifest->contributionCounts() ) );
if ( array_key_exists( 'console_local_nav', $manifest->contributionCounts() ) ) {
	$g1ExpectedRuntimeCounts['console_local_nav'] = Addon_Manifest::local_nav_count();
}
$g1ActualRuntimeCounts = $manifest->contributionCounts();
ksort( $g1ExpectedRuntimeCounts );
ksort( $g1ActualRuntimeCounts );
g1_same( $g1ExpectedRuntimeCounts, $g1ActualRuntimeCounts, 'The Runtime bridge count map must match the platform Manifest counts.' );

$platformPluginSource = (string) file_get_contents( $platformRoot . DIRECTORY_SEPARATOR . 'oj-platform.php' );
if (
	1 !== ( preg_match( "/define\\(\\s*'OJ_PLATFORM_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $platformPluginSource, $platformVersionMatch ) )
	|| 1 !== ( preg_match( "/define\\(\\s*'OJ_PLATFORM_ADDON_API_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $platformPluginSource, $apiVersionMatch ) )
) {
	throw new RuntimeException( 'The adjacent platform version constants were not found.' );
}
$validator        = new AddonManifestValidator(
	$platformVersionMatch[1],
	$apiVersionMatch[1],
	new G1CoreContributionSnapshotProvider(),
	new G1NamespaceLedger()
);
$validatedEntries = $validator->validate(
	array(
		AddonCandidate::manifest( Addon_Integration::ADDON_ID, $manifest, $gateway->onReady ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
	)
);
g1_same( 1, count( $validatedEntries ), 'The platform validator must return one add-on catalog entry.' );
g1_same( 'accepted', $validatedEntries[0]->status(), 'The current platform validator must accept the complete add-on Manifest.' );

$capabilityKeys = array_map(
	static fn ( $definition ): string => $definition->key(),
	$manifest->capabilities()
);
g1_same( Addon_Capabilities::keys(), $capabilityKeys, 'The exact 15 capabilities must be declared in order.' );
g1_same(
	'oj_addon_' . Addon_Capabilities::VIEW_WORKSPACE,
	Addon_Capabilities::wordpress( Addon_Capabilities::VIEW_WORKSPACE ),
	'The public platform capability mapper must be used.'
);

$expectedConsoleRoutes = array(
	Addon_Manifest::CONSOLE_PROJECTS       => '/oj-console/addons/oj-ai-dashboard/projects',
	Addon_Manifest::CONSOLE_WORKSHEETS     => '/oj-console/addons/oj-ai-dashboard/worksheets',
	Addon_Manifest::CONSOLE_WORKSHEET_EDIT => '/oj-console/addons/oj-ai-dashboard/worksheets/{worksheet_id}',
	Addon_Manifest::CONSOLE_ASSIGNMENTS    => '/oj-console/addons/oj-ai-dashboard/assignments',
	Addon_Manifest::CONSOLE_ASSESSMENTS    => '/oj-console/addons/oj-ai-dashboard/assessments',
	Addon_Manifest::CONSOLE_REPORTS        => '/oj-console/addons/oj-ai-dashboard/reports',
	Addon_Manifest::CONSOLE_SETTINGS       => '/oj-console/addons/oj-ai-dashboard/settings',
	Addon_Manifest::CONSOLE_CRITERIA       => '/oj-console/addons/oj-ai-dashboard/criteria',
	Addon_Manifest::CONSOLE_STORAGE_DETAIL => '/oj-console/addons/oj-ai-dashboard/settings/storage-detail',
);
$actualConsoleRoutes   = array();
foreach ( $manifest->consolePages() as $page ) {
	$actualConsoleRoutes[ $page->key() ] = $page->path();
	$expectedCapability                  = match ( $page->key() ) {
		Addon_Manifest::CONSOLE_REPORTS => Addon_Capabilities::VIEW_REPORTS,
		Addon_Manifest::CONSOLE_SETTINGS => Addon_Capabilities::MANAGE_POLICIES,
		Addon_Manifest::CONSOLE_CRITERIA => Addon_Capabilities::MANAGE_POLICIES,
		Addon_Manifest::CONSOLE_STORAGE_DETAIL => Addon_Capabilities::MANAGE_POLICIES,
		Addon_Manifest::CONSOLE_ASSIGNMENTS => Addon_Capabilities::ASSIGN_WORKSHEETS,
		default => Addon_Capabilities::VIEW_WORKSPACE,
	};
	g1_same( $expectedCapability, $page->capability(), 'Console route capability drifted.' );
}
g1_same( $expectedConsoleRoutes, $actualConsoleRoutes, 'Console Page catalog drifted.' );

$expectedPublicRoutes = array(
	Addon_Manifest::PUBLIC_WORKSHEETS => '/oj-addons/oj-ai-dashboard/worksheets',
	Addon_Manifest::PUBLIC_ASSIGNMENT => '/oj-addons/oj-ai-dashboard/assignments/{assignment_id}',
	Addon_Manifest::PUBLIC_RESULT     => '/oj-addons/oj-ai-dashboard/results/{release_id}',
);
$actualPublicRoutes   = array();
foreach ( $manifest->publicPages() as $page ) {
	$actualPublicRoutes[ $page->key() ] = $page->path();
	g1_same( Addon_Capabilities::PARTICIPATE, $page->capability(), 'Public route capability drifted.' );
}
g1_same( $expectedPublicRoutes, $actualPublicRoutes, 'Public Page catalog drifted.' );

$expectedConsoleMenus = array(
	Addon_Manifest::CONSOLE_PROJECTS => array( '활동지 평가', 'classes', Addon_Capabilities::VIEW_WORKSPACE, 40 ),
);
$actualConsoleMenus   = array();
foreach ( $manifest->consoleMenus() as $consoleMenu ) {
	$actualConsoleMenus[ $consoleMenu->route() ] = array(
		$consoleMenu->label(),
		$consoleMenu->anchor(),
		$consoleMenu->capability(),
		$consoleMenu->order(),
	);
}
g1_same(
	$expectedConsoleMenus,
	$actualConsoleMenus,
	'Console menus must keep only the teacher menu under classes; settings live in its local navigation.'
);
foreach ( $manifest->consoleMenus() as $consoleMenu ) {
	g1_assert( 'operations' !== $consoleMenu->anchor(), 'No console menu may be anchored at operations.' );
}
$fallbackMenus = ( new ReflectionMethod( Addon_Manifest::class, 'console_menus' ) )->invoke( null, false );
$fallbackShape = array();
foreach ( $fallbackMenus as $consoleMenu ) {
	$fallbackShape[ $consoleMenu->route() ] = array(
		$consoleMenu->label(),
		$consoleMenu->anchor(),
		$consoleMenu->capability(),
		$consoleMenu->order(),
	);
}
g1_same(
	array(
		Addon_Manifest::CONSOLE_PROJECTS => array( '활동지 평가', 'classes', Addon_Capabilities::VIEW_WORKSPACE, 40 ),
		Addon_Manifest::CONSOLE_SETTINGS => array( '활동지 평가 설정', 'classes', Addon_Capabilities::MANAGE_POLICIES, 42 ),
	),
	$fallbackShape,
	'Platforms without local navigation must keep a settings menu under the teacher anchor.'
);
g1_same( 2, Addon_Manifest::contribution_counts( false )['console_menu'], 'Fallback menu count drifted.' );
$capabilityDefinitions = array_column( Addon_Capabilities::definitions(), null, 'key' );
g1_same(
	array(
		'key'      => Addon_Capabilities::MANAGE_POLICIES,
		'label'    => '활동지 평가 설정',
		'audience' => 'educator',
	),
	$capabilityDefinitions[ Addon_Capabilities::MANAGE_POLICIES ],
	'The settings capability must be grantable to teachers.'
);
if ( method_exists( $manifest, 'consoleLocalNav' ) ) {
	$actualLocalNav = array();
	foreach ( $manifest->consoleLocalNav() as $item ) {
		g1_same( Addon_Manifest::MENU_WORKSPACE, $item->menu(), 'Local navigation must hang under the teacher menu.' );
		$actualLocalNav[ $item->route() ] = $item->label();
	}
	g1_same(
		array(
			Addon_Manifest::CONSOLE_PROJECTS    => '평가 프로젝트',
			Addon_Manifest::CONSOLE_WORKSHEETS  => '활동지 보관함',
			Addon_Manifest::CONSOLE_ASSIGNMENTS => '배포·제출',
			Addon_Manifest::CONSOLE_ASSESSMENTS => '평가',
			Addon_Manifest::CONSOLE_CRITERIA    => '평가 기준',
			Addon_Manifest::CONSOLE_REPORTS     => '보고서',
			Addon_Manifest::CONSOLE_SETTINGS    => '설정',
		),
		$actualLocalNav,
		'Local navigation sections drifted.'
	);
}
$publicMenu = $manifest->publicMenus()[0];
g1_same( 'classes', $publicMenu->parent(), 'Public menu parent drifted.' );
g1_same( 'learner', $publicMenu->scope(), 'Public menu scope drifted.' );
g1_same( true, $publicMenu->defaultEnabled(), 'Public menu must be enabled by default.' );

$assetBundles = array();
foreach ( $manifest->assetBundles() as $bundle ) {
	$assetBundles[ $bundle->key() ] = $bundle;
	g1_assert( count( $bundle->styles() ) <= 8 && count( $bundle->scripts() ) <= 8, 'Every asset bundle must stay within the platform per-type limit of eight files.' );
	foreach ( array_merge( $bundle->styles(), $bundle->scripts() ) as $relativePath ) {
		g1_assert(
			is_file( $addonRoot . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relativePath ) ),
			'Manifest asset is missing: ' . $relativePath
		);
	}
}

g1_same( array( Addon_Manifest::ASSET_PROJECT_EDITOR, Addon_Manifest::ASSET_LEARNER_LABELS, Addon_Manifest::ASSET_SUBMISSION_DOCUMENT, Addon_Manifest::ASSET_CONSOLE ), $manifest->consolePages()[0]->assetBundles(), 'Project workspace must load Plate and shared helpers before its controllers.' );
g1_same( array( 'resources/js/learner-labels.js' ), $assetBundles[ Addon_Manifest::ASSET_LEARNER_LABELS ]->scripts(), 'Learner labels must have a separate bundle within the provider asset limit.' );
g1_same( array( 'resources/css/submission-document.css' ), $assetBundles[ Addon_Manifest::ASSET_SUBMISSION_DOCUMENT ]->styles(), 'Document styles must have a separate bundle within the provider asset limit.' );
g1_same( array( 'resources/js/submission-document.js' ), $assetBundles[ Addon_Manifest::ASSET_SUBMISSION_DOCUMENT ]->scripts(), 'Document helper must accompany its styles.' );
foreach ( $manifest->consolePages() as $page ) {
	$page_assets = $page->assetBundles();
	if ( in_array( Addon_Manifest::ASSET_CONSOLE, $page_assets, true ) ) {
		g1_assert( in_array( Addon_Manifest::ASSET_LEARNER_LABELS, $page_assets, true ) && array_search( Addon_Manifest::ASSET_LEARNER_LABELS, $page_assets, true ) < array_search( Addon_Manifest::ASSET_CONSOLE, $page_assets, true ), 'Every console workbench must load learner labels before its controllers.' );
		g1_assert( in_array( Addon_Manifest::ASSET_SUBMISSION_DOCUMENT, $page_assets, true ) && array_search( Addon_Manifest::ASSET_SUBMISSION_DOCUMENT, $page_assets, true ) < array_search( Addon_Manifest::ASSET_CONSOLE, $page_assets, true ), 'Every console workbench must load the document helper before its controllers.' );
	}
}
g1_same( array( 'resources/assets/builder/plate-template-builder.js' ), $assetBundles[ Addon_Manifest::ASSET_PROJECT_EDITOR ]->scripts(), 'Project Plate bundle must not duplicate workbench controllers.' );

g1_same(
	array(
		'resources/css/admin-shell.css',
		'resources/css/assignment-workbench.css',
		'resources/css/worksheet-authoring-workbench.css',
		'resources/css/worksheet-evaluation-workbench.css',
		'resources/css/submission-evaluation-review-workbench.css',
		'resources/css/report-dashboard.css',
		'resources/css/worksheet-workflow.css',
		'resources/css/assessment-projects.css',
	),
	$assetBundles[ Addon_Manifest::ASSET_CONSOLE ]->styles(),
	'Console workbench styles drifted.'
);
g1_same(
	array(
		'resources/js/worksheet-workflow.js',
		'resources/js/assessment-projects.js',
		'resources/js/assignment-workbench.js',
		'resources/js/worksheet-authoring-workbench.js',
		'resources/js/worksheet-evaluation-workbench.js',
		'resources/js/submission-evaluation-review-workbench.js',
		'resources/js/report-dashboard.js',
	),
	$assetBundles[ Addon_Manifest::ASSET_CONSOLE ]->scripts(),
	'Console workbench scripts drifted.'
);
g1_same(
	array(
		'resources/css/admin-shell.css',
		'resources/css/external-ai-consent-settings.css',
		'resources/css/assessment-configuration-settings.css',
		'resources/css/curriculum-catalog-settings.css',
		'resources/css/operations-diagnostics.css',
		'resources/css/data-management-settings.css',
		'resources/css/worksheet-workflow.css',
	),
	$assetBundles[ Addon_Manifest::ASSET_SETTINGS ]->styles(),
	'Console settings styles drifted.'
);
g1_same(
	array(
		'resources/js/external-ai-consent-settings.js',
		'resources/js/worksheet-workflow.js',
		'resources/js/assessment-configuration-settings.js',
		'resources/js/curriculum-catalog-settings.js',
		'resources/js/operations-diagnostics.js',
		'resources/js/data-management-settings.js',
	),
	$assetBundles[ Addon_Manifest::ASSET_SETTINGS ]->scripts(),
	'Console settings scripts drifted.'
);
g1_same(
	array( 'resources/css/admin-shell.css', 'resources/assets/builder/plate-template-builder.css', 'resources/css/worksheet-authoring-workbench.css', 'resources/css/worksheet-evaluation-workbench.css', 'resources/css/worksheet-workflow.css' ),
	$assetBundles[ Addon_Manifest::ASSET_WORKSHEET_EDITOR ]->styles(),
	'Plate editor styles drifted.'
);
g1_same(
	array( 'resources/assets/builder/plate-template-builder.js', 'resources/js/worksheet-workflow.js', 'resources/js/worksheet-authoring-workbench.js', 'resources/js/worksheet-evaluation-workbench.js' ),
	$assetBundles[ Addon_Manifest::ASSET_WORKSHEET_EDITOR ]->scripts(),
	'Plate editor script order drifted.'
);
g1_same(
	array( 'resources/css/admin-shell.css', 'resources/css/operations-diagnostics.css', 'resources/css/worksheet-workflow.css' ),
	$assetBundles[ Addon_Manifest::ASSET_STORAGE_DETAIL ]->styles(),
	'Storage detail styles drifted.'
);
g1_same(
	array( 'resources/js/operations-storage-detail.js', 'resources/js/worksheet-workflow.js' ),
	$assetBundles[ Addon_Manifest::ASSET_STORAGE_DETAIL ]->scripts(),
	'Storage detail scripts drifted.'
);
g1_assert(
	$manifest->consolePages()[1]->handler() instanceof Worksheet_Workbench_Page_Handler,
	'The Worksheet list must use the specialized workbench handler.'
);
g1_assert(
	$manifest->consolePages()[2]->handler() instanceof Worksheet_Workbench_Page_Handler,
	'The Worksheet editor must use the specialized workbench handler.'
);
g1_assert(
	$manifest->consolePages()[4]->handler() instanceof Worksheet_Evaluation_Workbench_Page_Handler,
	'The assessment Page must use the specialized evaluation workbench handler.'
);
g1_assert(
	$manifest->consolePages()[5]->handler() instanceof Report_Dashboard_Page_Handler,
	'The report Page must use the specialized report dashboard handler.'
);
g1_assert(
	$manifest->consolePages()[6]->handler() instanceof External_Ai_Consent_Settings_Page_Handler,
	'The settings Page must use the external AI consent handler.'
);
g1_same(
	array( Addon_Manifest::ASSET_SETTINGS ),
	$manifest->consolePages()[6]->assetBundles(),
	'The settings Page must use the settings-specific asset bundle.'
);
g1_assert(
	$manifest->consolePages()[3]->handler() instanceof Assignment_Workbench_Page_Handler,
	'The assignment Page must use the specialized workbench handler.'
);
g1_assert(
	$manifest->consolePages()[8]->handler() instanceof Storage_Operations_Detail_Page_Handler,
	'The storage detail Page must use the specialized handler.'
);
g1_same(
	array( Addon_Manifest::ASSET_STORAGE_DETAIL ),
	$manifest->consolePages()[8]->assetBundles(),
	'The storage detail Page must use its own asset bundle.'
);

$tasks = array();
foreach ( $manifest->aiTasks() as $task ) {
	$tasks[ $task->key() ] = $task;
}
g1_same( 'external_submission_assessment.v1', $tasks[ Addon_Manifest::TASK_EXTERNAL_ASSESSMENT ]->inputContractVersion(), 'Common assessment input contract drifted.' );
g1_same( 'addon_payload', $tasks[ Addon_Manifest::TASK_EXTERNAL_ASSESSMENT ]->sourceKind(), 'Common assessment source kind drifted.' );
g1_same( 'external_submission_assessment_prompt.v1', $tasks[ Addon_Manifest::TASK_EXTERNAL_ASSESSMENT ]->instruction()?->contractVersion(), 'Common assessment instruction drifted.' );
g1_same( 'ml_submission_assessment.v1', $tasks[ Addon_Manifest::TASK_ML_ASSESSMENT ]->inputContractVersion(), 'ML assessment contract drifted.' );
g1_same( 'addon_payload', $tasks[ Addon_Manifest::TASK_ML_ASSESSMENT ]->sourceKind(), 'ML source kind drifted.' );
g1_same( 'ml_submission_assessment_prompt.v1', $tasks[ Addon_Manifest::TASK_ML_ASSESSMENT ]->instruction()?->contractVersion(), 'ML instruction version drifted.' );
g1_same(
	'worksheet_quality.v1',
	$tasks[ Addon_Manifest::TASK_WORKSHEET_QUALITY ]->inputContractVersion(),
	'Worksheet quality input contract drifted.'
);
g1_same( 'none', $tasks[ Addon_Manifest::TASK_WORKSHEET_QUALITY ]->sourceKind(), 'Worksheet source kind drifted.' );
g1_same(
	'student_submission_assessment.v1',
	$tasks[ Addon_Manifest::TASK_STUDENT_ASSESSMENT ]->inputContractVersion(),
	'Submission outer contract drifted.'
);
g1_same( 'addon_payload', $tasks[ Addon_Manifest::TASK_STUDENT_ASSESSMENT ]->sourceKind(), 'Submission source kind drifted.' );
$worksheetInstruction  = $tasks[ Addon_Manifest::TASK_WORKSHEET_QUALITY ]->instruction();
$submissionInstruction = $tasks[ Addon_Manifest::TASK_STUDENT_ASSESSMENT ]->instruction();
g1_same( 'worksheet_quality_prompt.v6', $worksheetInstruction?->contractVersion(), 'Worksheet instruction contract drifted.' );
g1_same(
	'student_submission_assessment_prompt.v10',
	$submissionInstruction?->contractVersion(),
	'Submission instruction contract drifted.'
);
g1_assert(
	$worksheetInstruction?->contentHash() !== $submissionInstruction?->contentHash(),
	'The two AI tasks must have isolated instruction hashes.'
);

$notReadyRequest     = new AddonPageRequestV1(
	Addon_Manifest::CONSOLE_WORKSHEETS,
	1,
	array(),
	array(),
	'ko_KR',
	'g1-page-before-ready'
);
$firstConsoleHandler = $manifest->consolePages()[1]->handler();
g1_same( 'unavailable', $firstConsoleHandler->handle( $notReadyRequest )->kind(), 'Page must fail closed before onReady.' );

$snapshot       = new AddonRuntimeSnapshotV1(
	'oj-ai-dashboard',
	OJ_AI_DASHBOARD_VERSION,
	'>=2.0.0-dev.33 <3.0.0',
	'2.0.0-dev.33',
	'>=1.1.0 <2.0.0',
	'1.1.0',
	'accepted',
	'',
	str_repeat( 'a', 64 ),
	$manifest->contributionCounts(),
	'g1-runtime-ready'
);
$runtimeContext = new G1RuntimeContext( $snapshot, new G1ContextGateway(), new G1AiTaskGateway() );
( $gateway->onReady )( $runtimeContext ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.

g1_same( 1, $schemaReadiness->calls, 'Accepted onReady must verify the local schema exactly once.' );
g1_same( true, $bridge->is_ready(), 'Bridge must become ready after one valid callback.' );
g1_same( 'html', $firstConsoleHandler->handle( $notReadyRequest )->kind(), 'Static Page must render after Runtime ready.' );
$objectRequest = new AddonPageRequestV1(
	Addon_Manifest::CONSOLE_WORKSHEET_EDIT,
	1,
	array( 'worksheet_id' => '01HZY3M4N5P6Q7R8S9T0V1W2X3' ),
	array(),
	'ko_KR',
	'g1-object-policy'
);
g1_same(
	'unavailable',
	$manifest->consolePages()[2]->handler()->handle( $objectRequest )->kind(),
	'Object-scoped routes must stay closed until their policy is implemented.'
);

$duplicateRejected = false;
try {
	( $gateway->onReady )( $runtimeContext ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
} catch ( LogicException ) {
	$duplicateRejected = true;
}
g1_same( true, $duplicateRejected, 'The onReady gate must reject a second connection attempt.' );
g1_same( 1, $schemaReadiness->calls, 'A duplicate callback must not repeat schema verification.' );

$blockedBridge          = new Addon_Runtime_Bridge();
$blockedSchemaReadiness = new G1SchemaReadiness(
	new Schema_Runtime_Readiness_Result(
		Schema_Runtime_Readiness_Result::UNAVAILABLE,
		'schema_catalog_drift',
		array( 'table_engine_mismatch' )
	)
);
$blockedGate            = new Addon_Runtime_Readiness_Gate( $blockedBridge, $blockedSchemaReadiness );
$blockedIntegration     = new Addon_Integration( $blockedBridge, $blockedGate, g1_access_policy( $blockedBridge ) );
$blockedGateway         = new G1RegistrationGateway();
$blockedIntegration->register( $blockedGateway );
$blockedCode = '';
try {
	( $blockedGateway->onReady )( $runtimeContext ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
} catch ( RuntimeException $error ) {
	$blockedCode = $error->getMessage();
}
g1_same( 'schema_catalog_drift', $blockedCode, 'Schema rejection must throw only its stable code.' );
g1_same( false, $blockedBridge->is_ready(), 'Schema rejection must keep Platform Runtime gateways closed.' );
g1_same( 'schema_catalog_drift', $blockedBridge->code(), 'Bridge diagnostics must retain the schema rejection code.' );
g1_same( 1, $blockedSchemaReadiness->calls, 'A rejected schema must be inspected once.' );

$blockedDuplicateRejected = false;
try {
	( $blockedGateway->onReady )( $runtimeContext ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
} catch ( LogicException ) {
	$blockedDuplicateRejected = true;
}
g1_same( true, $blockedDuplicateRejected, 'A failed callback must not retry during the same boot.' );
g1_same( 1, $blockedSchemaReadiness->calls, 'A failed callback retry must not touch the database again.' );

$extensions     = array( 'dom', 'fileinfo', 'intl', 'mbstring', 'mysqli', 'openssl', 'xmlwriter', 'zip' );
$validPreflight = Activation_Preflight::inspect(
	array(
		'wordpress_version'      => '6.6',
		'php_version'            => '8.1.0',
		'is_multisite'           => false,
		'platform_version'       => '2.0.0-dev.33',
		'addon_api_version'      => '1.1.0',
		'api_contract_available' => true,
		'extensions'             => $extensions,
		'private_storage_ready'  => true,
	)
);
g1_same( null, $validPreflight, 'Valid activation preflight must pass before local writes.' );
$missingIntlPreflight = Activation_Preflight::inspect(
	array(
		'wordpress_version'      => '6.6',
		'php_version'            => '8.1.0',
		'is_multisite'           => false,
		'platform_version'       => '2.0.0-dev.33',
		'addon_api_version'      => '1.1.0',
		'api_contract_available' => true,
		'extensions'             => array_values( array_diff( $extensions, array( 'intl' ) ) ),
		'private_storage_ready'  => true,
	)
);
g1_same( 'addon_php_extension_missing', $missingIntlPreflight['code'] ?? '', 'Missing intl must fail before migration.' );
$missingMbstringPreflight = Activation_Preflight::inspect(
	array(
		'wordpress_version'      => '6.6',
		'php_version'            => '8.1.0',
		'is_multisite'           => false,
		'platform_version'       => '2.0.0-dev.33',
		'addon_api_version'      => '1.1.0',
		'api_contract_available' => true,
		'extensions'             => array_values( array_diff( $extensions, array( 'mbstring' ) ) ),
		'private_storage_ready'  => true,
	)
);
g1_same( 'addon_php_extension_missing', $missingMbstringPreflight['code'] ?? '', 'Missing mbstring must fail before migration.' );
$invalidPreflight = Activation_Preflight::inspect(
	array(
		'wordpress_version'      => '6.6',
		'php_version'            => '8.1.0',
		'is_multisite'           => false,
		'platform_version'       => '2.0.0-dev.33',
		'addon_api_version'      => '1.0.0',
		'api_contract_available' => true,
		'extensions'             => $extensions,
		'private_storage_ready'  => true,
	)
);
g1_same( 'addon_api_incompatible', $invalidPreflight['code'] ?? '', 'Old Addon API must fail before migration.' );

$pluginSource = (string) file_get_contents( $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
g1_assert(
	str_contains( $pluginSource, 'Requires Plugins: oj-platform' ),
	'The WordPress plugin header must declare the OJ Platform dependency.'
);

$runtimeSources = array(
	$addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Plugin.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Integration.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Manifest.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Runtime_Bridge.php',
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Runtime_Readiness_Gate.php',
);
$activeRuntime  = '';
foreach ( $runtimeSources as $source ) {
	$activeRuntime .= (string) file_get_contents( $source );
}
foreach ( array( 'oj_platform_loaded', 'route_registry', 'console_menu_registry' ) as $forbidden ) {
	g1_assert( ! str_contains( $activeRuntime, $forbidden ), 'Active G1 Runtime contains legacy boundary: ' . $forbidden );
}
g1_assert(
	! str_contains(
		(string) file_get_contents(
			$addonRoot . DIRECTORY_SEPARATOR . 'src'
			. DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Plugin.php'
		),
		'new Security_Module'
	),
	'Legacy role synchronization must not boot in G1.'
);

echo "G1 Addon API consumer contract passed: manifest catalog, Runtime counts, assets, AI tasks, schema-gated Runtime fail-closed.\n";

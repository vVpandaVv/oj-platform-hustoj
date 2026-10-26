<?php
/**
 * G1 common access policy contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Infrastructure\Platform_Context_Authorization;
use OJAI\Dashboard\Security\Policy\Infrastructure\WordPress_Actor_Authorization;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Actor_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;

$addon_root    = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $addon_root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );

if (
	! is_string( $platform_root )
	|| ! is_file(
		$platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AddonApi'
		. DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'AddonContextAccessRequestV1.php'
	)
) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platform_root );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Policy assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function policy_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Policy same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function policy_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G1 common access policy contract.
 */
final class Policy_Runtime_Fixture implements Platform_Context_Runtime_Port_Interface {
	/**
	 * Initialize policy runtime fixture dependencies and state.
	 *
	 * @param bool                       $ready Ready.
	 * @param AddonContextGatewayV1|null $gateway Gateway.
	 */
	public function __construct(
		public bool $ready = true,
		public ?AddonContextGatewayV1 $gateway = null
	) {
	}

	/**
	 * Is ready.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->ready;
	}

	/**
	 * Contexts.
	 *
	 * @return AddonContextGatewayV1|null
	 */
	public function contexts(): ?AddonContextGatewayV1 {
		return $this->gateway;
	}
}

/**
 * G1 common access policy contract.
 */
final class Policy_Actor_Fixture implements Actor_Authorization_Port_Interface {
	/**
	 * Active.
	 *
	 * @var bool
	 */
	public bool $active = true;
	/**
	 * Throw on active.
	 *
	 * @var bool
	 */
	public bool $throw_on_active = false;
	/**
	 * Throw on capability.
	 *
	 * @var bool
	 */
	public bool $throw_on_capability = false;
	/**
	 * Active calls.
	 *
	 * @var int
	 */
	public int $active_calls = 0;

	/**
	 * Capability calls.
	 *
	 * @var array<int,string>
	 */
	public array $capability_calls = array();

	/**
	 * Grants.
	 *
	 * @var array<string,bool>
	 */
	public array $grants = array();

	/**
	 * Is active.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function is_active( int $actor_user_id ): bool {
		++$this->active_calls;
		if ( $this->throw_on_active ) {
			throw new RuntimeException( 'raw_actor_error' );
		}

		return $this->active;
	}

	/**
	 * Has capability.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $wordpress_capability WordPress capability.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function has_capability( int $actor_user_id, string $wordpress_capability ): bool {
		$this->capability_calls[] = $wordpress_capability;
		if ( $this->throw_on_capability ) {
			throw new RuntimeException( 'raw_capability_error' );
		}

		return true === ( $this->grants[ $wordpress_capability ] ?? false );
	}
}

/**
 * G1 common access policy contract.
 */
final class Policy_Context_Fixture implements Context_Authorization_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Throw.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Initialize policy context fixture dependencies and state.
	 *
	 * @param Context_Authorization_Result $result Result.
	 */
	public function __construct( public Context_Authorization_Result $result ) {
	}

	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result {
		++$this->calls;
		if ( $this->throw ) {
			throw new RuntimeException( 'raw_context_error' );
		}

		return $this->result;
	}
}

/**
 * G1 common access policy contract.
 */
final class Policy_Object_Fixture implements Object_Authorization_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Throw.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Initialize policy object fixture dependencies and state.
	 *
	 * @param Object_Authorization_Result $result Result.
	 */
	public function __construct( public Object_Authorization_Result $result ) {
	}

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object
	): Object_Authorization_Result {
		++$this->calls;
		if ( $this->throw ) {
			throw new RuntimeException( 'raw_object_error' );
		}

		return $this->result;
	}
}

/**
 * G1 common access policy contract.
 */
final class Policy_Platform_Context_Gateway implements AddonContextGatewayV1 {
	/**
	 * Last request.
	 *
	 * @var AddonContextAccessRequestV1|null
	 */
	public ?AddonContextAccessRequestV1 $last_request = null;
	/**
	 * Throw.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Initialize policy platform context gateway dependencies and state.
	 *
	 * @param AddonContextAccessDecisionV1 $decision Decision.
	 */
	public function __construct( public AddonContextAccessDecisionV1 $decision ) {
	}

	/**
	 * List teaching contexts.
	 *
	 * @param AddonTeachingContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listTeachingContexts( AddonTeachingContextQueryV1 $query ): AddonContextPageV1 {
		throw new RuntimeException( 'Not used by the policy contract.' );
	}

	/**
	 * Authorize.
	 *
	 * @param AddonContextAccessRequestV1 $request Request.
	 * @return AddonContextAccessDecisionV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( AddonContextAccessRequestV1 $request ): AddonContextAccessDecisionV1 {
		$this->last_request = $request;
		if ( $this->throw ) {
			throw new RuntimeException( 'raw_platform_context_error' );
		}

		return $this->decision;
	}

	/**
	 * Snapshot.
	 *
	 * @param AddonContextSnapshotRequestV1 $request Request.
	 * @return AddonContextSnapshotResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( AddonContextSnapshotRequestV1 $request ): AddonContextSnapshotResultV1 {
		throw new RuntimeException( 'Not used by the policy contract.' );
	}
}

/**
 * Policy fixture.
 *
 * @param bool $runtime_ready Runtime ready.
 * @return array{0:Access_Policy,1:Policy_Runtime_Fixture,2:Policy_Actor_Fixture,3:Policy_Context_Fixture,4:Policy_Object_Fixture}
 */
function policy_fixture( bool $runtime_ready = true ): array {
	$runtime  = new Policy_Runtime_Fixture( $runtime_ready );
	$actors   = new Policy_Actor_Fixture();
	$contexts = new Policy_Context_Fixture(
		Context_Authorization_Result::allowed( 'class', 'class-1', 'active', 'ctx-rev-1', 'teacher' )
	);
	$objects  = new Policy_Object_Fixture(
		Object_Authorization_Result::allowed( 'obj-rev-1', 'draft', 'owner' )
	);

	return array( new Access_Policy( $runtime, $actors, $contexts, $objects ), $runtime, $actors, $contexts, $objects );
}

/**
 * Policy request.
 *
 * @param array<int,string>  $required_capabilities Required capabilities.
 * @param Context_Scope|null $context Access or execution context for this operation.
 * @param Object_Scope|null  $object Object.
 * @param string             $shell Shell.
 * @param string             $surface Surface.
 * @param bool               $integrity Integrity.
 * @param array<int,string>  $required_core_capabilities Required core capabilities.
 * @param int                $actor_user_id Authenticated actor from the access context.
 * @return Access_Policy_Request
 */
function policy_request(
	array $required_capabilities = array(),
	?Context_Scope $context = null,
	?Object_Scope $object = null,
	string $shell = Access_Policy_Request::SHELL_CONSOLE,
	string $surface = Access_Policy_Request::SURFACE_PAGE,
	bool $integrity = true,
	array $required_core_capabilities = array(),
	int $actor_user_id = 7
): Access_Policy_Request {
	return new Access_Policy_Request(
		$actor_user_id,
		$shell,
		$surface,
		'worksheet.view',
		$required_capabilities,
		$context,
		$object,
		$integrity,
		'policy-contract-1',
		$required_core_capabilities
	);
}

/**
 * Grant console.
 *
 * @param Policy_Actor_Fixture $actors Actors.
 * @param array                $additional Additional.
 * @return void
 */
function grant_console( Policy_Actor_Fixture $actors, array $additional = array() ): void {
	$actors->grants['oj_access_console'] = true;
	$actors->grants[ Addon_Capabilities::wordpress( Addon_Capabilities::VIEW_WORKSPACE ) ] = true;
	foreach ( $additional as $capability ) {
		$actors->grants[ Addon_Capabilities::wordpress( $capability ) ] = true;
	}
}

/**
 * Grant public.
 *
 * @param Policy_Actor_Fixture $actors Actors.
 * @return void
 */
function grant_public( Policy_Actor_Fixture $actors ): void {
	$actors->grants[ Addon_Capabilities::wordpress( Addon_Capabilities::PARTICIPATE ) ] = true;
}

policy_same( 15, count( Addon_Capabilities::keys() ), 'The policy must consume the exact 15-capability catalog.' );

$invalid_capability_rejected = false;
try {
	policy_request( array( 'oj_ai_dashboard_unknown' ) );
} catch ( InvalidArgumentException ) {
	$invalid_capability_rejected = true;
}
policy_assert( $invalid_capability_rejected, 'Undeclared add-on capabilities must be rejected before authorization.' );

$invalid_core_rejected = false;
try {
	policy_request( array(), null, null, Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_PAGE, true, array( 'manage_options' ) );
} catch ( InvalidArgumentException ) {
	$invalid_core_rejected = true;
}
policy_assert( $invalid_core_rejected, 'Only the frozen delete_plugins core capability may be requested.' );

list( $policy, , $actors ) = policy_fixture( false );
$decision                  = $policy->authorize( policy_request() );
policy_same( 503, $decision->status(), 'Runtime unavailability must fail before actor authorization.' );
policy_same( Access_Policy_Decision::CODE_RUNTIME_UNAVAILABLE, $decision->code(), 'Runtime public code drifted.' );
policy_same( 0, $actors->active_calls, 'A closed Runtime must not query actor state.' );

list( $policy, , $actors ) = policy_fixture();
$decision                  = $policy->authorize( policy_request( actor_user_id: 0 ) );
policy_same( 401, $decision->status(), 'Anonymous REST actors must receive the common authentication decision.' );
policy_same( 'authentication_required', $decision->reason_code(), 'Anonymous actor reason drifted.' );
policy_same( 0, $actors->active_calls, 'Anonymous actors must stop before actor storage.' );

list( $policy, , $actors ) = policy_fixture();
$actors->active            = false;
$decision                  = $policy->authorize( policy_request() );
policy_same( 401, $decision->status(), 'Inactive actors must receive the authentication decision.' );
policy_same( array(), $actors->capability_calls, 'Inactive actors must not reach capability checks.' );

list( $policy, , $actors ) = policy_fixture();
$decision                  = $policy->authorize( policy_request() );
policy_same( 403, $decision->status(), 'Console access must require oj_access_console.' );
policy_same( 'console_access_denied', $decision->reason_code(), 'Console core denial reason drifted.' );

list( $policy, , $actors )           = policy_fixture();
$actors->grants['oj_access_console'] = true;
$decision                            = $policy->authorize( policy_request() );
policy_same( 403, $decision->status(), 'Console access must also require view_workspace.' );

list( $policy, , $actors ) = policy_fixture();
$decision                  = $policy->authorize(
	policy_request( array(), null, null, Access_Policy_Request::SHELL_PUBLIC )
);
policy_same( 403, $decision->status(), 'Public access must require participate.' );

$isolated_capabilities = array_values(
	array_diff(
		Addon_Capabilities::keys(),
		array( Addon_Capabilities::VIEW_WORKSPACE, Addon_Capabilities::PARTICIPATE )
	)
);
policy_same( 13, count( $isolated_capabilities ), 'The action capability isolation fixture drifted.' );
foreach ( $isolated_capabilities as $capability ) {
	list( $policy, , $actors ) = policy_fixture();
	grant_console( $actors, array( $capability ) );
	policy_assert(
		$policy->authorize( policy_request( array( $capability ) ) )->is_allowed(),
		'An explicitly assigned action capability must be accepted: ' . $capability
	);
	unset( $actors->grants[ Addon_Capabilities::wordpress( $capability ) ] );
	policy_same(
		403,
		$policy->authorize( policy_request( array( $capability ) ) )->status(),
		'One action capability must not be inherited from another: ' . $capability
	);
}

list( $policy, , $actors, $contexts ) = policy_fixture();
grant_console( $actors );
$decision = $policy->authorize( policy_request( array(), null, null, Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_REST, false ) );
policy_same( 403, $decision->status(), 'Failed request integrity must deny before Context access.' );
policy_same( 'request_integrity_invalid', $decision->reason_code(), 'Request integrity reason drifted.' );
policy_same( 0, $contexts->calls, 'Invalid requests must not query Platform Context.' );

$context_scope                        = new Context_Scope( 'class', 'class-1', 'view', null, 'ctx-rev-1' );
list( $policy, , $actors, $contexts ) = policy_fixture();
grant_console( $actors );
$decision = $policy->authorize( policy_request( array(), $context_scope ) );
policy_assert( $decision->is_allowed(), 'An active exact class Context must be accepted.' );
policy_same( 'ctx-rev-1', $decision->context()?->revision(), 'Authorized Context evidence must be retained.' );

$contexts->result = Context_Authorization_Result::allowed( 'class', 'class-1', 'inactive', 'ctx-rev-1', 'teacher' );
policy_same( 404, $policy->authorize( policy_request( array(), $context_scope ) )->status(), 'Inactive Contexts must be hidden.' );
$contexts->result = Context_Authorization_Result::denied();
policy_same( 404, $policy->authorize( policy_request( array(), $context_scope ) )->status(), 'Out-of-scope Contexts must be hidden.' );
$contexts->result = Context_Authorization_Result::unavailable();
policy_same( 503, $policy->authorize( policy_request( array(), $context_scope ) )->status(), 'Context outages must fail closed.' );
$contexts->result = Context_Authorization_Result::allowed( 'class', 'class-1', 'active', 'ctx-rev-2', 'teacher' );
policy_same( 409, $policy->authorize( policy_request( array(), $context_scope ) )->status(), 'A stale Context revision must conflict.' );

list( $policy, , $actors, $contexts ) = policy_fixture();
grant_public( $actors );
$participation    = new Context_Scope( 'group', 'group-1', 'participate', 7, 'ctx-rev-1' );
$contexts->result = Context_Authorization_Result::allowed( 'group', 'group-1', 'active', 'ctx-rev-1', 'learner' );
policy_assert(
	$policy->authorize( policy_request( array(), $participation, null, Access_Policy_Request::SHELL_PUBLIC ) )->is_allowed(),
	'Public participation must allow only the active learner relation.'
);
$context_calls = $contexts->calls;
$other_learner = new Context_Scope( 'group', 'group-1', 'participate', 8, 'ctx-rev-1' );
policy_same(
	404,
	$policy->authorize( policy_request( array(), $other_learner, null, Access_Policy_Request::SHELL_PUBLIC ) )->status(),
	'Public participation must not authorize another learner.'
);
policy_same( $context_calls, $contexts->calls, 'Another learner must be rejected before the Platform call.' );
$public_staff_operation = new Context_Scope( 'group', 'group-1', 'view' );
policy_same(
	404,
	$policy->authorize( policy_request( array(), $public_staff_operation, null, Access_Policy_Request::SHELL_PUBLIC ) )->status(),
	'Public surfaces must not invoke staff Context operations.'
);

$invalid_context_type = false;
try {
	new Context_Scope( 'site', 'site-1', 'view' );
} catch ( InvalidArgumentException ) {
	$invalid_context_type = true;
}
policy_assert( $invalid_context_type, 'The add-on v1 policy must accept only class and group Context types.' );

$platform_snapshot = new AddonContextSnapshotV1(
	'class',
	'class-1',
	'Class One',
	'active',
	'ctx-rev-1',
	'teacher'
);
$platform_gateway  = new Policy_Platform_Context_Gateway(
	new AddonContextAccessDecisionV1( true, '', $platform_snapshot, 'ctx-correlation-1' )
);
$platform_runtime  = new Policy_Runtime_Fixture( true, $platform_gateway );
$platform_adapter  = new Platform_Context_Authorization( $platform_runtime );
$adapter_result    = $platform_adapter->authorize( 7, $context_scope );
policy_assert( $adapter_result->is_allowed(), 'The public Addon API Context adapter must normalize an allowed decision.' );
policy_same( 7, $platform_gateway->last_request?->actorUserId(), 'Context adapter actor forwarding drifted.' );
policy_same( 'class', $platform_gateway->last_request?->contextType(), 'Context adapter type forwarding drifted.' );
policy_same( 'ctx-rev-1', $platform_gateway->last_request?->expectedRevision(), 'Context adapter revision forwarding drifted.' );

$platform_gateway->decision = new AddonContextAccessDecisionV1(
	false,
	'addon_context_denied',
	null,
	'ctx-denied-1'
);
policy_assert( $platform_adapter->authorize( 7, $context_scope )->is_denied(), 'Platform Context denial must remain a denial.' );
$platform_gateway->decision = new AddonContextAccessDecisionV1(
	false,
	'addon_context_unavailable',
	null,
	'ctx-unavailable-1'
);
policy_assert( $platform_adapter->authorize( 7, $context_scope )->is_unavailable(), 'Platform Context outage must stay unavailable.' );
$platform_gateway->throw = true;
policy_assert( $platform_adapter->authorize( 7, $context_scope )->is_unavailable(), 'Raw Platform errors must collapse to unavailable.' );

$object_scope = new Object_Scope( 'worksheet', 'worksheet-1', 'view', 'obj-rev-1', 'draft' );
policy_assert( ! $object_scope->requires_context(), 'Worksheet owner objects must not invent a class or group Context.' );
list( $policy, , $actors, $contexts, $objects ) = policy_fixture();
grant_console( $actors, array( Addon_Capabilities::VIEW_WORKSHEETS ) );
$decision = $policy->authorize(
	policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), $context_scope, $object_scope )
);
policy_assert( $decision->is_allowed(), 'Matching optional Context, object relation, revision, and state must allow.' );
policy_same( 'owner', $decision->object()?->actor_relation(), 'Authorized object evidence must be retained.' );

$objects->result = Object_Authorization_Result::denied();
policy_same( 404, $policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), $context_scope, $object_scope ) )->status(), 'Object scope denial must hide existence.' );
$objects->result = Object_Authorization_Result::conflict();
policy_same( 409, $policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), $context_scope, $object_scope ) )->status(), 'Object state conflicts must return 409.' );
$objects->result = Object_Authorization_Result::unavailable();
policy_same( 503, $policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), $context_scope, $object_scope ) )->status(), 'Missing object owner policy must fail closed.' );
$objects->result = Object_Authorization_Result::allowed( 'obj-rev-2', 'draft', 'owner' );
policy_same( 409, $policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), $context_scope, $object_scope ) )->status(), 'Object revision drift must return 409.' );

list( $policy, , $actors, , $objects ) = policy_fixture();
grant_console( $actors, array( Addon_Capabilities::VIEW_WORKSHEETS ) );
policy_assert(
	$policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), null, $object_scope ) )->is_allowed(),
	'Worksheet owner objects must authorize without an unrelated Platform Context.'
);
policy_same( 1, $objects->calls, 'Worksheet owner authorization must reach its storage adapter.' );

$assignment_scope = new Object_Scope( 'assignment', 'assignment-1', 'view', 'obj-rev-1', 'draft' );
policy_assert( $assignment_scope->requires_context(), 'Assignment objects must remain Context-bound.' );
list( $policy, , $actors, , $objects ) = policy_fixture();
grant_console( $actors, array( Addon_Capabilities::VIEW_WORKSHEETS ) );
policy_same(
	404,
	$policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), null, $assignment_scope ) )->status(),
	'Context-bound assignment objects must not authorize without Platform Context.'
);
policy_same( 0, $objects->calls, 'A missing required assignment Context must stop before object storage.' );

$global_policy_object = new Object_Scope( 'policy', 'policy-1', 'view', 'obj-rev-1', 'draft' );
policy_assert(
	$policy->authorize( policy_request( array( Addon_Capabilities::VIEW_WORKSHEETS ), null, $global_policy_object ) )->is_allowed(),
	'Frozen global operator objects may use their object relation without a class or group Context.'
);

list( $policy, , $actors ) = policy_fixture();
grant_console( $actors, array( Addon_Capabilities::MANAGE_MIGRATIONS ) );
$purge_request = policy_request(
	array( Addon_Capabilities::MANAGE_MIGRATIONS ),
	null,
	null,
	Access_Policy_Request::SHELL_CONSOLE,
	Access_Policy_Request::SURFACE_REST,
	true,
	array( Access_Policy_Request::CORE_DELETE_PLUGINS )
);
policy_same( 403, $policy->authorize( $purge_request )->status(), 'Irreversible operations must additionally require delete_plugins.' );
$actors->grants[ Access_Policy_Request::CORE_DELETE_PLUGINS ] = true;
policy_assert( $policy->authorize( $purge_request )->is_allowed(), 'The explicit delete_plugins AND gate must be satisfiable.' );

list( $policy, , $actors, $contexts, $objects ) = policy_fixture();
grant_console( $actors, array( Addon_Capabilities::VIEW_WORKSHEETS ) );
$parity = array();
foreach (
	array(
		Access_Policy_Request::SURFACE_PAGE,
		Access_Policy_Request::SURFACE_REST,
		Access_Policy_Request::SURFACE_JOB,
		Access_Policy_Request::SURFACE_ARTIFACT,
	) as $surface
) {
	$surface_decision = $policy->authorize(
		policy_request(
			array( Addon_Capabilities::VIEW_WORKSHEETS ),
			$context_scope,
			$object_scope,
			Access_Policy_Request::SHELL_CONSOLE,
			$surface
		)
	);
	$parity[]         = array( $surface_decision->status(), $surface_decision->code(), $surface_decision->reason_code() );
}
policy_same( array_fill( 0, 4, array( 200, 'oj_ai_access_allowed', 'access_policy_allowed' ) ), $parity, 'Every surface must receive the same policy decision.' );

$actor_checked_id = 0;
$actor_adapter    = new WordPress_Actor_Authorization(
	static function ( int $actor_user_id ): object {
		return new class() {
			/**
			 * User status.
			 *
			 * @var int
			 */
			public int $user_status = 0;

			/**
			 * Exists.
			 *
			 * @return bool
			 */
			public function exists(): bool {
				return true;
			}
		};
	},
	static function ( int $actor_user_id, string $capability ) use ( &$actor_checked_id ): bool {
		$actor_checked_id = $actor_user_id;

		return 'oj_access_console' === $capability;
	}
);
policy_assert( $actor_adapter->is_active( 7 ), 'The WordPress actor adapter must accept an existing active user.' );
policy_assert( $actor_adapter->has_capability( 7, 'oj_access_console' ), 'The WordPress actor adapter must use the injected actor check.' );
policy_same( 7, $actor_checked_id, 'Capability checks must preserve the responsible actor ID.' );
policy_assert( ! $actor_adapter->has_capability( 7, 'oj_addon_oj_ai_dashboard_manage_policies' ), 'A user without the settings capability must stay denied.' );
$site_admin_adapter = new WordPress_Actor_Authorization(
	null,
	static fn ( int $actor_user_id, string $capability ): bool => false,
	static fn ( int $actor_user_id ): bool => 9 === $actor_user_id
);
policy_assert( $site_admin_adapter->has_capability( 9, 'oj_addon_oj_ai_dashboard_manage_policies' ), 'A site administrator must pass the settings capability without a granted role.' );
policy_assert( ! $site_admin_adapter->has_capability( 7, 'oj_addon_oj_ai_dashboard_manage_policies' ), 'Only the site administrator may skip the capability check.' );

$policy_sources   = '';
$policy_directory = $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security'
	. DIRECTORY_SEPARATOR . 'Policy';
$iterator         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $policy_directory ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$policy_sources .= (string) file_get_contents( $file->getPathname() );
	}
}
foreach (
	array(
		'current_user_can(',
		'manage_options',
		'add_role(',
		'add_cap(',
		'CodingPanda\\OjPlatform\\Kernel',
		'$wpdb',
		'oj_addon_',
	) as $forbidden
) {
	policy_assert( ! str_contains( $policy_sources, $forbidden ), 'Common Policy contains a forbidden boundary: ' . $forbidden );
}
$active_sources = (string) file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php' )
	. (string) file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Plugin.php' );
foreach ( array( 'new Permission_Guard(', 'new Scope_Checker(', 'new Rest_Permission(', 'new Security_Module(' ) as $legacy ) {
	policy_assert( ! str_contains( $active_sources, $legacy ), 'Legacy authorization was revived: ' . $legacy );
}

echo "G1 common access Policy contract passed: Runtime, actor, 15 capabilities, Context, object/state, and four-surface parity.\n";

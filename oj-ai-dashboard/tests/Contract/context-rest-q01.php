<?php
/**
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 *
 * The adjacent platform source is used only as a public Addon API fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Context\Infrastructure\Platform_Teaching_Context_Query;
use OJAI\Dashboard\Context\Port\Teaching_Context_Query_Port_Interface;
use OJAI\Dashboard\Context\Presentation\Context_Rest_Controller;
use OJAI\Dashboard\Context\Presentation\Context_Rest_Module;
use OJAI\Dashboard\Context\Teaching_Context_Page;
use OJAI\Dashboard\Context\Teaching_Context_Query;
use OJAI\Dashboard\Context\Teaching_Context_Query_Result;
use OJAI\Dashboard\Context\Teaching_Context_Summary;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

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
		. DIRECTORY_SEPARATOR . 'V1' . DIRECTORY_SEPARATOR . 'AddonTeachingContextQueryV1.php'
	)
) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
	 */
	final class WP_REST_Response {
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct(
			private string $code,
			private string $message,
			private $data = array()
		) {
		}

		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['q01_actor']       = 0;
$GLOBALS['q01_hooks']       = array();
$GLOBALS['q01_routes']      = array();
$GLOBALS['q01_nonce_calls'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['q01_actor'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Wp verify nonce.
	 *
	 * @param string $nonce Nonce.
	 * @param string $action Action.
	 * @return mixed
	 */
	function wp_verify_nonce( string $nonce, string $action ) {
		$GLOBALS['q01_nonce_calls'][] = array( $nonce, $action );
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['q01_hooks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['q01_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platform_root );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Q01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Q01 throws.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q01_throws( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Throwable ) {
		return;
	}

	throw new RuntimeException( $message );
}

/**
 * Q01 platform summary.
 *
 * @param string                                   $type Type.
 * @param string                                   $id Id.
 * @param string                                   $lifecycle Lifecycle.
 * @param array<string,bool|int|float|string|null> $attributes Attributes.
 * @return AddonContextSummaryV1
 */
function q01_platform_summary(
	string $type,
	string $id,
	string $lifecycle,
	array $attributes
): AddonContextSummaryV1 {
	return new AddonContextSummaryV1(
		$type,
		$id,
		strtoupper( $type ) . ' ' . $id,
		$lifecycle,
		'revision-' . $id,
		$attributes
	);
}

/**
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 */
final class Q01_Runtime_Fixture implements Platform_Context_Runtime_Port_Interface {
	/**
	 * Initialize q01 runtime fixture dependencies and state.
	 *
	 * @param bool                       $ready Ready.
	 * @param AddonContextGatewayV1|null $gateway Gateway.
	 */
	public function __construct(
		public bool $ready,
		public ?AddonContextGatewayV1 $gateway
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
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 */
final class Q01_Gateway_Fixture implements AddonContextGatewayV1 {
	/**
	 * Last query.
	 *
	 * @var AddonTeachingContextQueryV1|null
	 */
	public ?AddonTeachingContextQueryV1 $last_query = null;
	/**
	 * Throw.
	 *
	 * @var bool
	 */
	public bool $throw = false;
	/**
	 * List calls.
	 *
	 * @var int
	 */
	public int $list_calls = 0;

	/**
	 * Initialize q01 gateway fixture dependencies and state.
	 *
	 * @param AddonContextPageV1 $page Page.
	 */
	public function __construct( public AddonContextPageV1 $page ) {
	}

	/**
	 * List teaching contexts.
	 *
	 * @param AddonTeachingContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listTeachingContexts( AddonTeachingContextQueryV1 $query ): AddonContextPageV1 {
		++$this->list_calls;
		$this->last_query = $query;
		if ( $this->throw ) {
			throw new RuntimeException( 'raw gateway exception' );
		}

		return $this->page;
	}

	/**
	 * Authorize.
	 *
	 * @param AddonContextAccessRequestV1 $request Request.
	 * @return AddonContextAccessDecisionV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( AddonContextAccessRequestV1 $request ): AddonContextAccessDecisionV1 {
		throw new RuntimeException( 'Q01 must not call Context authorize while listing actor-managed choices.' );
	}

	/**
	 * Snapshot.
	 *
	 * @param AddonContextSnapshotRequestV1 $request Request.
	 * @return AddonContextSnapshotResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( AddonContextSnapshotRequestV1 $request ): AddonContextSnapshotResultV1 {
		throw new RuntimeException( 'Q01 must not call Context snapshot.' );
	}
}

/**
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 */
final class Q01_Policy_Fixture implements Access_Policy_Port_Interface {
	/**
	 * Mode.
	 *
	 * @var string
	 */
	public string $mode = 'allowed';
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->last_request = $request;

		if ( 0 === $request->actor_user_id() ) {
			return Access_Policy_Decision::denied(
				401,
				'authentication_required',
				$request->correlation_id()
			);
		}
		if ( 'denied' === $this->mode ) {
			return Access_Policy_Decision::denied(
				403,
				'addon_capability_denied',
				$request->correlation_id()
			);
		}
		if ( 'unavailable' === $this->mode ) {
			return Access_Policy_Decision::denied(
				503,
				'addon_runtime_not_ready',
				$request->correlation_id()
			);
		}

		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 */
final class Q01_Request_Fixture {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers;

	/**
	 * Initialize q01 request fixture dependencies and state.
	 *
	 * @param array<string,mixed> $query Query.
	 * @param array<string,mixed> $headers Headers.
	 * @param int                 $body_actor_user_id Body actor user id.
	 * @param string              $method Method.
	 */
	public function __construct(
		private array $query = array(),
		array $headers = array(),
		public int $body_actor_user_id = 999,
		private string $method = 'GET'
	) {
		$this->headers = array();
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}

	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_header( string $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G1 teaching Context adapter and A-DS08-Q01 REST contract.
 */
final class Q01_Query_Port_Fixture implements Teaching_Context_Query_Port_Interface {
	/**
	 * Initialize q01 query port fixture dependencies and state.
	 *
	 * @param Teaching_Context_Query_Result $result Result.
	 */
	public function __construct( public Teaching_Context_Query_Result $result ) {
	}

	/**
	 * Query.
	 *
	 * @param Teaching_Context_Query $query Query.
	 * @return Teaching_Context_Query_Result
	 */
	public function query( Teaching_Context_Query $query ): Teaching_Context_Query_Result {
		return $this->result;
	}
}

$opaque_cursor = str_repeat( 'C', Teaching_Context_Query::MAXIMUM_CURSOR );
$query         = new Teaching_Context_Query( 42, '  algebra  ', $opaque_cursor, 100 );
q01_same( 42, $query->actor_user_id(), 'The neutral query must preserve the trusted actor.' );
q01_same( array( 'class', 'group' ), $query->types(), 'Q01 types must be fixed to class and group.' );
q01_same( 'algebra', $query->search(), 'Q01 search must be trimmed.' );
q01_same( $opaque_cursor, $query->cursor(), 'A 512-character opaque cursor must round trip unchanged.' );
$trimmed_boundary = new Teaching_Context_Query( 42, '  ' . str_repeat( 'S', 200 ) . '  ' );
q01_same(
	200,
	strlen( $trimmed_boundary->search() ),
	'The 200-character search limit must apply after trimming.'
);
q01_throws(
	static fn() => new Teaching_Context_Query( 42, '', str_repeat( 'X', 513 ) ),
	'Q01 must reject a cursor longer than its endpoint-specific 512-character profile.'
);
q01_throws(
	static fn() => new Teaching_Context_Query( 42, str_repeat( 'S', 201 ) ),
	'Q01 must reject search longer than 200 characters.'
);

$valid_page = new AddonContextPageV1(
	true,
	array(
		q01_platform_summary(
			'class',
			'class-01',
			'active',
			array(
				'enabled'       => true,
				'academic_year' => 2026,
				'grade_label'   => 'Grade 3',
				'private_note'  => 'must-not-leak',
			)
		),
		q01_platform_summary( 'group', 'group-02', 'inactive', array( 'enabled' => true ) ),
		q01_platform_summary( 'site', 'site-03', 'active', array( 'enabled' => true ) ),
		q01_platform_summary( 'class', 'class-04', 'active', array() ),
	),
	'next.cursor:1',
	null
);
$gateway    = new Q01_Gateway_Fixture( $valid_page );
$runtime    = new Q01_Runtime_Fixture( true, $gateway );
$adapter    = new Platform_Teaching_Context_Query( $runtime );
$result     = $adapter->query( $query );
q01_assert( $result->is_successful(), 'A valid public Context page must project successfully.' );
q01_same( 42, $gateway->last_query?->actorUserId(), 'The platform query actor must come from the neutral query.' );
q01_same( array( 'class', 'group' ), $gateway->last_query?->types(), 'The adapter must never widen Q01 Context types.' );
q01_same( 'algebra', $gateway->last_query?->search(), 'The adapter must send the normalized search.' );
q01_same( $opaque_cursor, $gateway->last_query?->cursor(), 'The adapter must not decode or truncate the cursor.' );
q01_same( 100, $gateway->last_query?->limit(), 'The adapter must preserve the bounded limit.' );
q01_same( 2, count( $result->page()?->items() ?? array() ), 'Only valid class and group summaries may remain.' );
q01_same( 2, $result->page()?->excluded_count(), 'Only add-on-observed invalid items may be counted.' );
q01_same( 'next.cursor:1', $result->page()?->next_cursor(), 'The next cursor must remain opaque.' );
$projected_items = $result->page()?->items() ?? array();
q01_same( '2026', $projected_items[0]->academic_year(), 'A safe integer academic year may become display text.' );
q01_same( 'Grade 3', $projected_items[0]->grade_label(), 'The optional grade label may be projected.' );
q01_same( true, $projected_items[0]->is_selectable(), 'Active and enabled Contexts must be selectable.' );
q01_same( false, $projected_items[1]->is_selectable(), 'Inactive Contexts must remain visible but disabled.' );

$gateway->page = new AddonContextPageV1(
	true,
	array(
		q01_platform_summary( 'class', 'duplicate-01', 'active', array( 'enabled' => true ) ),
		q01_platform_summary( 'group', 'duplicate-01', 'active', array( 'enabled' => true ) ),
	),
	'',
	null
);
$duplicate     = $adapter->query( new Teaching_Context_Query( 42 ) );
q01_same(
	Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE,
	$duplicate->reason(),
	'Duplicate stable IDs must fail the whole page.'
);

$gateway->page = new AddonContextPageV1(
	true,
	array( q01_platform_summary( 'class', 'class-05', 'active', array( 'enabled' => true ) ) ),
	'repeat-cursor',
	null
);
$repeated      = $adapter->query( new Teaching_Context_Query( 42, '', 'repeat-cursor' ) );
q01_same(
	Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE,
	$repeated->reason(),
	'A repeated non-empty cursor must fail the page.'
);

$platform_problem = new AddonApiProblemV1(
	'addon_context_unavailable',
	'raw platform message must not leak',
	'platform-correlation-01',
	true,
	array( 'private_detail' => 'must-not-leak' )
);
$gateway->page    = new AddonContextPageV1( false, array(), '', $platform_problem );
$failed           = $adapter->query( new Teaching_Context_Query( 42 ) );
q01_same(
	Teaching_Context_Query_Result::REASON_CONTEXT_UNAVAILABLE,
	$failed->reason(),
	'Public Context unavailable must map to a stable add-on reason.'
);
q01_same(
	'platform-correlation-01',
	$failed->platform_correlation_id(),
	'Only the validated platform correlation ID may survive for diagnostics.'
);

$gateway->page = new AddonContextPageV1(
	false,
	array(),
	'',
	new AddonApiProblemV1(
		'addon_context_denied',
		'raw denied message',
		'platform-correlation-02',
		false
	)
);
q01_same(
	Teaching_Context_Query_Result::REASON_CONTEXT_DENIED,
	$adapter->query( new Teaching_Context_Query( 42 ) )->reason(),
	'Public Context denied must map without message inspection.'
);

$gateway->page = new AddonContextPageV1(
	false,
	array(),
	'',
	new AddonApiProblemV1(
		'addon_handler_failed',
		'wrong public problem family',
		'platform-correlation-03',
		false
	)
);
q01_same(
	Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE,
	$adapter->query( new Teaching_Context_Query( 42 ) )->reason(),
	'Unexpected public problem codes must fail closed.'
);

$gateway->throw = true;
q01_same(
	Teaching_Context_Query_Result::REASON_CONTEXT_UNAVAILABLE,
	$adapter->query( new Teaching_Context_Query( 42 ) )->reason(),
	'Provider exceptions must become a stable unavailable reason.'
);
$gateway->throw = false;
$runtime->ready = false;
q01_same(
	Teaching_Context_Query_Result::REASON_RUNTIME_UNAVAILABLE,
	$adapter->query( new Teaching_Context_Query( 42 ) )->reason(),
	'An unready bridge must fail before calling the Gateway.'
);
$runtime->ready = true;

$gateway->page        = $valid_page;
$policy               = new Q01_Policy_Fixture();
$correlation_sequence = 0;
$correlations         = new Correlation_Id_Factory(
	static function () use ( &$correlation_sequence ): string {
		++$correlation_sequence;
		return 'server-q01-' . str_pad( (string) $correlation_sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes            = new Rest_Envelope_Factory();
$permissions          = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller           = new Context_Rest_Controller( $permissions, $adapter, $envelopes, $correlations );
$module               = new Context_Rest_Module( $controller );
$module->register();
$module->register();
q01_same( 1, count( $GLOBALS['q01_hooks']['rest_api_init'] ?? array() ), 'The REST module hook must register once.' );

call_user_func( $GLOBALS['q01_hooks']['rest_api_init'][0] );
call_user_func( $GLOBALS['q01_hooks']['rest_api_init'][0] );
q01_same( 1, count( $GLOBALS['q01_routes'] ), 'Q01 must register exactly once.' );
q01_same( 'oj-ai/v1', $GLOBALS['q01_routes'][0][0], 'The frozen REST namespace must be used.' );
q01_same( '/contexts', $GLOBALS['q01_routes'][0][1], 'Only the frozen Q01 path may be opened.' );
$route = $GLOBALS['q01_routes'][0][2];
q01_same( 'GET', $route['methods'], 'Q01 must remain read-only.' );
q01_assert( is_callable( $route['permission_callback'] ), 'Q01 requires a real permission callback.' );
q01_assert( is_callable( $route['callback'] ), 'Q01 requires a route callback.' );
q01_assert( ! array_key_exists( 'args', $route ), 'WordPress default validators must not bypass the frozen envelope.' );

$GLOBALS['q01_actor'] = 42;
$nonce_calls          = count( $GLOBALS['q01_nonce_calls'] );
$request              = new Q01_Request_Fixture(
	array(
		'search'        => '  algebra  ',
		'cursor'        => $opaque_cursor,
		'limit'         => '100',
		'actor_user_id' => 999,
	),
	array( 'X-Correlation-ID' => 'client-correlation-must-be-ignored' ),
	999
);
$permission           = call_user_func( $route['permission_callback'], $request );
q01_same( true, $permission, 'An authorized Q01 request must pass permission_callback.' );
q01_same( 42, $policy->last_request?->actor_user_id(), 'The policy actor must come from WordPress.' );
q01_same( Access_Policy_Request::SHELL_CONSOLE, $policy->last_request?->shell(), 'Q01 must use Console policy.' );
q01_same( 'workspace.contexts.list', $policy->last_request?->action_key(), 'Q01 must use its frozen action key.' );
q01_same( array(), $policy->last_request?->required_capabilities(), 'Console shell supplies the view_workspace baseline.' );
q01_same( null, $policy->last_request?->context(), 'Actor-scoped list lookup must not invent a selected Context.' );
q01_same( $nonce_calls, count( $GLOBALS['q01_nonce_calls'] ), 'GET Q01 must not verify a nonce.' );

$response = call_user_func( $route['callback'], $request );
q01_assert( $response instanceof WP_REST_Response, 'A valid Q01 request must return a REST response.' );
q01_same( 200, $response->get_status(), 'Q01 success must use HTTP 200.' );
$response_data = $response->get_data();
q01_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $response_data ), 'Q01 must use the common success envelope.' );
q01_same( 'server-q01-001', $response_data['meta']['correlation_id'], 'The permission correlation ID must reach the callback.' );
q01_same( 'next.cursor:1', $response_data['meta']['next_cursor'], 'The opaque next cursor must be returned in metadata.' );
q01_same( true, $response_data['meta']['has_more'], 'A non-empty next cursor must advertise another page.' );
q01_same( 100, $response_data['meta']['limit'], 'The canonical query limit must be reflected.' );
q01_same( 2, $response_data['meta']['excluded_count'], 'The observed exclusion count must be explicit.' );
q01_same( array(), $response_data['links'], 'Q01 must not hardcode REST URLs.' );
q01_same( array(), $response_data['actions'], 'Listing choices alone must not authorize a mutation action.' );
q01_same( 2, count( $response_data['data']['items'] ), 'Q01 must return only the safe class and group projection.' );
q01_same( true, $response_data['data']['items'][0]['selectable'], 'Active enabled choices must be selectable.' );
q01_same( false, $response_data['data']['items'][1]['selectable'], 'Inactive choices must be disabled.' );
q01_assert( ! isset( $response_data['data']['items'][0]['attributes'] ), 'The raw attributes map must never be exposed.' );
q01_assert( ! isset( $response_data['data']['items'][0]['private_note'] ), 'Unknown attributes must be discarded.' );
q01_same( 42, $gateway->last_query?->actorUserId(), 'Request actor fields must not override the server actor.' );

$consumed = call_user_func( $route['callback'], $request );
q01_assert( $consumed instanceof WP_Error, 'A permission grant must be consumable only once.' );
q01_same( 503, $consumed->get_error_data()['status'], 'A missing permission handoff must fail closed.' );

$calls_before_invalid = $gateway->list_calls;
$invalid_queries      = array(
	array( 'search' => str_repeat( 'S', 201 ) ),
	array( 'search' => array( 'not-a-string' ) ),
	array( 'cursor' => str_repeat( 'C', 513 ) ),
	array( 'cursor' => array( 'not-a-string' ) ),
	array( 'limit' => 0 ),
	array( 'limit' => 101 ),
	array( 'limit' => '01' ),
	array( 'limit' => '1.5' ),
);
foreach ( $invalid_queries as $invalid_query ) {
	$invalid_request = new Q01_Request_Fixture( $invalid_query );
	q01_same( true, call_user_func( $route['permission_callback'], $invalid_request ), 'Policy must run before Q01 input validation.' );
	$invalid_response = call_user_func( $route['callback'], $invalid_request );
	q01_assert( $invalid_response instanceof WP_Error, 'Invalid Q01 input must return a WordPress REST error.' );
	q01_same( 'oj_ai_validation_failed', $invalid_response->get_error_code(), 'Invalid Q01 input must use the frozen code.' );
	q01_same( 422, $invalid_response->get_error_data()['status'], 'Invalid Q01 input must use HTTP 422.' );
}
q01_same( $calls_before_invalid, $gateway->list_calls, 'Invalid Q01 input must not reach the platform Gateway.' );

$GLOBALS['q01_actor'] = 0;
$anonymous_request    = new Q01_Request_Fixture();
$anonymous            = call_user_func( $route['permission_callback'], $anonymous_request );
q01_assert( $anonymous instanceof WP_Error, 'Anonymous Q01 requests must fail in permission_callback.' );
q01_same( 401, $anonymous->get_error_data()['status'], 'Anonymous Q01 requests must use HTTP 401.' );

$GLOBALS['q01_actor'] = 42;
$policy->mode         = 'denied';
$denied_request       = new Q01_Request_Fixture();
$denied               = call_user_func( $route['permission_callback'], $denied_request );
q01_assert( $denied instanceof WP_Error, 'Capability denial must fail in permission_callback.' );
q01_same( 403, $denied->get_error_data()['status'], 'Capability denial must use HTTP 403.' );
$policy->mode = 'allowed';

$gateway->page       = new AddonContextPageV1( false, array(), '', $platform_problem );
$unavailable_request = new Q01_Request_Fixture();
q01_same( true, call_user_func( $route['permission_callback'], $unavailable_request ), 'Policy may pass before a provider failure.' );
$unavailable = call_user_func( $route['callback'], $unavailable_request );
q01_assert( $unavailable instanceof WP_Error, 'A failed Context page must fail Q01.' );
q01_same( 503, $unavailable->get_error_data()['status'], 'Context unavailable must use HTTP 503.' );
q01_assert(
	! str_contains( $unavailable->get_error_message(), 'raw platform message' ),
	'Platform messages must not leak into REST errors.'
);
q01_assert(
	'platform-correlation-01' !== $unavailable->get_error_data()['correlation_id'],
	'The public REST correlation ID must remain server generated.'
);

$gateway->page          = new AddonContextPageV1(
	false,
	array(),
	'',
	new AddonApiProblemV1( 'addon_context_denied', 'raw denied', 'platform-denied-01', false )
);
$context_denied_request = new Q01_Request_Fixture();
q01_same( true, call_user_func( $route['permission_callback'], $context_denied_request ), 'Common policy may pass before Gateway denial.' );
$context_denied = call_user_func( $route['callback'], $context_denied_request );
q01_same( 403, $context_denied->get_error_data()['status'], 'Gateway Context denial must map to HTTP 403.' );

$malformed_permission = $controller->permissions_check( new stdClass() );
q01_assert( $malformed_permission instanceof WP_Error, 'Malformed transport requests must fail closed.' );
q01_same( 500, $malformed_permission->get_error_data()['status'], 'Malformed transport requests must use the frozen internal failure.' );

$context_source   = '';
$context_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Context' )
);
foreach ( $context_iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$source          = file_get_contents( $file->getPathname() );
		$context_source .= is_string( $source ) ? $source : '';
	}
}
q01_assert( str_contains( $context_source, 'AddonApi\\V1' ), 'The adapter must use the public Addon API namespace.' );
q01_assert( ! str_contains( $context_source, 'OjPlatform\\Kernel' ), 'Product Context code must not import platform internals.' );
q01_assert( ! str_contains( $context_source, '$wpdb' ), 'Q01 must not access platform or WordPress tables.' );
q01_assert( ! str_contains( $context_source, 'base64_decode' ), 'Opaque cursors must not be decoded.' );
q01_assert( ! str_contains( $context_source, '->message()' ), 'Platform problem messages must not be consumed.' );
q01_assert( ! str_contains( $context_source, '->details()' ), 'Platform problem details must not be consumed.' );
q01_same( 1, substr_count( $context_source, 'register_rest_route(' ), 'The Context surface must register only Q01.' );

$composition_source = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src'
	. DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php'
);
q01_assert( is_string( $composition_source ), 'The Composition Root source must be readable.' );
q01_assert( str_contains( $composition_source, 'new Context_Rest_Module' ), 'Q01 must be reachable from the active Composition Root.' );
q01_assert( str_contains( $composition_source, 'new Platform_Teaching_Context_Query' ), 'The public Gateway adapter must be wired once.' );
foreach ( array( 'new Admin_Module', 'new Worksheet_Module', 'new Submission_Module' ) as $legacy_module ) {
	q01_assert( ! str_contains( $composition_source, $legacy_module ), 'The legacy Runtime must remain unreachable: ' . $legacy_module );
}

fwrite( STDOUT, "G1 teaching Context adapter and Q01 REST contract passed.\n" );

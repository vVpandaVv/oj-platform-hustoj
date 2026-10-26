<?php
/**
 * G2-IMP-02-S07 C02 REST boundary contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-02-S07 C02 REST boundary contract.
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
	 * G2-IMP-02-S07 C02 REST boundary contract.
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

$GLOBALS['s07_actor']       = 42;
$GLOBALS['s07_nonce_calls'] = array();
$GLOBALS['s07_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s07_actor'];
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
		$GLOBALS['s07_nonce_calls'][] = array( $nonce, $action );
		return 'valid-s07-nonce' === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' ) . '/';
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
		$GLOBALS['s07_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worksheet-save-draft-s06-operations.php';

/**
 * S07 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s07_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S07 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s07_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G2-IMP-02-S07 C02 REST boundary contract.
 */
final class S07_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();

	/**
	 * Initialize s07 request dependencies and state.
	 *
	 * @param string              $method Method.
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param array<string,mixed> $url Url.
	 */
	public function __construct(
		private string $method,
		array $headers,
		private array $json,
		private array $url
	) {
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
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}

	/**
	 * Get url params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_params(): array {
		return $this->url;
	}

	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return array();
	}
}

/**
 * G2-IMP-02-S07 C02 REST boundary contract.
 */
final class S07_Policy implements Access_Policy_Port_Interface {
	/**
	 * Manage allowed.
	 *
	 * @var bool
	 */
	public bool $manage_allowed = true;
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Initialize s07 policy dependencies and state.
	 *
	 * @param S05_Wpdb_Fixture $database Database.
	 * @param string           $worksheet_id Worksheet public identifier.
	 * @param int              $owner_user_id Owner user id.
	 */
	public function __construct(
		private S05_Wpdb_Fixture $database,
		private string $worksheet_id,
		private int $owner_user_id = 42
	) {
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		if ( 0 === $request->actor_user_id() ) {
			return Access_Policy_Decision::denied( 401, 'authentication_required', $request->correlation_id() );
		}
		if ( ! $request->request_integrity_verified() || ! $this->manage_allowed ) {
			return Access_Policy_Decision::denied( 403, 'worksheet_manage_denied', $request->correlation_id() );
		}
		$object = $request->object();
		if (
			array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET !== $object->type()
			|| 'edit' !== $object->action()
			|| $this->worksheet_id !== $object->id()
			|| $this->owner_user_id !== $request->actor_user_id()
		) {
			return Access_Policy_Decision::denied( 404, 'worksheet_owner_scope_denied', $request->correlation_id() );
		}

		foreach ( $this->database->tables['oj_ai_worksheets'] as $worksheet ) {
			if ( $worksheet['public_id'] === $this->worksheet_id ) {
				return Access_Policy_Decision::allowed(
					$request->correlation_id(),
					null,
					Object_Authorization_Result::allowed(
						(string) $worksheet['current_revision_public_id'],
						(string) $worksheet['state'],
						'owner'
					)
				);
			}
		}

		return Access_Policy_Decision::denied( 404, 'worksheet_not_found', $request->correlation_id() );
	}
}

/**
 * S07 body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $expected_revision_id Revision the caller read before this operation.
 * @param string $expected_state Expected state.
 * @param array  $document Document.
 * @param array  $staged_receipts Staged receipts.
 * @return array<string,mixed>
 */
function s07_body(
	string $command_id,
	string $expected_revision_id,
	string $expected_state,
	array $document,
	array $staged_receipts
): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_state'       => $expected_state,
			'expected_revision_id' => $expected_revision_id,
		),
		'payload'    => array(
			'teacher_document'         => $document,
			'achievement_links'        => array(),
			'staged_asset_receipt_ids' => $staged_receipts,
		),
	);
}

/**
 * S07 request.
 *
 * @param string $worksheet_id Worksheet public identifier.
 * @param string $idempotency_key Idempotency key.
 * @param array  $body Body.
 * @param string $nonce Nonce.
 * @return S07_Request
 */
function s07_request( string $worksheet_id, string $idempotency_key, array $body, string $nonce = 'valid-s07-nonce' ): S07_Request {
	$headers = array( 'X-WP-Nonce' => $nonce );
	if ( '' !== $idempotency_key ) {
		$headers['Idempotency-Key'] = $idempotency_key;
	}
	return new S07_Request( 'POST', $headers, $body, array( 'worksheet_id' => $worksheet_id ) );
}

/**
 * S07 route.
 *
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s07_route(): array {
	foreach ( $GLOBALS['s07_routes'] as $registration ) {
		if (
			'oj-ai/v1' === $registration[0]
			&& Worksheet_Rest_Controller::ROUTE_SAVE_DRAFT === $registration[1]
			&& 'POST' === ( $registration[2]['methods'] ?? null )
		) {
			return $registration[2];
		}
	}
	throw new RuntimeException( 'C02 save-draft route was not registered.' );
}

$context              = s06_context();
$canonical            = new Canonical_Json();
$repository           = new WordPress_Worksheet_Repository( $context['database'], $canonical );
$controller_ids       = s05_generator( 24000 );
$policy               = new S07_Policy( $context['database'], $context['worksheet_id'] );
$correlation_sequence = 0;
$correlations         = new Correlation_Id_Factory(
	static function () use ( &$correlation_sequence ): string {
		return 's07-correlation-' . str_pad( (string) ++$correlation_sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes            = new Rest_Envelope_Factory();
$permissions          = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller           = new Worksheet_Rest_Controller(
	$permissions,
	new Create_Worksheet(
		$repository,
		$controller_ids,
		new Initial_Document_Factory( $controller_ids, $canonical )
	),
	new Read_Worksheets( $repository ),
	$canonical,
	$envelopes,
	$correlations,
	$context['save']
);
$controller->register_routes();
$controller->register_routes();
s07_same( 5, count( $GLOBALS['s07_routes'] ), 'C02 must add one route declaration without duplicate registration.' );
$route = s07_route();
s07_assert( is_callable( $route['permission_callback'] ?? null ), 'C02 requires a permission callback.' );
s07_assert( is_callable( $route['callback'] ?? null ), 'C02 requires a route callback.' );
s07_assert( ! array_key_exists( 'args', $route ), 'C02 validation failures must use the common problem envelope.' );

$body    = s07_body(
	'command-s07-create-0001',
	$context['revision_id'],
	'draft',
	$context['document'],
	array( $context['asset_id'] )
);
$request = s07_request( $context['worksheet_id'], 's07-idempotency-create-0001', $body );
s07_same( true, call_user_func( $route['permission_callback'], $request ), 'An owner with manage capability must pass C02 policy.' );
s07_same( Worksheet_Rest_Controller::ACTION_SAVE_DRAFT, $policy->last_request?->action_key(), 'C02 must use its frozen action key.' );
s07_same( array( Addon_Capabilities::MANAGE_WORKSHEETS ), $policy->last_request?->required_capabilities(), 'C02 must require manage_worksheets.' );
s07_same( 'edit', $policy->last_request?->object()?->action(), 'C02 must authorize the Worksheet edit relation.' );
s07_same( null, $policy->last_request?->object()?->expected_revision(), 'C02 must defer expected revision until idempotent receipt lookup.' );
s07_same( array( 'valid-s07-nonce', 'wp_rest' ), $GLOBALS['s07_nonce_calls'][ array_key_last( $GLOBALS['s07_nonce_calls'] ) ], 'C02 must verify the standard wp_rest nonce.' );

$response = call_user_func( $route['callback'], $request );
s07_assert( $response instanceof WP_REST_Response, 'C02 success must return a REST response.' );
s07_same( 201, $response->get_status(), 'A changed C02 document must return 201.' );
$response_data = $response->get_data();
s07_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $response_data ), 'C02 must use the common success envelope.' );
s07_same( false, $response_data['meta']['idempotent_replay'], 'The first C02 operation is not a replay.' );
s07_same( false, $response_data['meta']['unchanged'], 'A changed C02 operation must declare unchanged=false.' );
s07_same( 2, $response_data['data']['revision']['revision_no'], 'C02 must append revision two.' );
s07_same( $context['revision_id'], $response_data['data']['revision']['source_revision_id'], 'C02 must expose the safe source revision ID.' );
s07_assert( in_array( Worksheet_Rest_Controller::ACTION_SAVE_DRAFT, $response_data['actions'], true ), 'C02 success must expose the authorized save action.' );
$saved_revision_id  = $response_data['data']['revision']['id'];
$counts_after_first = $context['database']->counts();

$retry = s07_request( $context['worksheet_id'], 's07-idempotency-create-0001', $body );
s07_same( true, call_user_func( $route['permission_callback'], $retry ), 'An exact C02 retry must pass owner policy after the pointer advances.' );
$retry_response = call_user_func( $route['callback'], $retry );
s07_same( 201, $retry_response->get_status(), 'A changed-operation replay must preserve its original 201 status.' );
s07_same( true, $retry_response->get_data()['meta']['idempotent_replay'], 'An exact C02 retry must declare replay.' );
s07_same( $saved_revision_id, $retry_response->get_data()['data']['revision']['id'], 'An exact C02 retry must return the first receipt.' );
s07_same( $counts_after_first, $context['database']->counts(), 'An exact C02 retry must add zero rows.' );

$unchanged_body    = s07_body(
	'command-s07-unchanged-0001',
	$saved_revision_id,
	'draft',
	$context['document'],
	array()
);
$before_unchanged  = $context['database']->counts();
$unchanged_request = s07_request( $context['worksheet_id'], 's07-idempotency-unchanged-0001', $unchanged_body );
s07_same( true, call_user_func( $route['permission_callback'], $unchanged_request ), 'A current unchanged command must pass owner policy.' );
$unchanged_response = call_user_func( $route['callback'], $unchanged_request );
s07_same( 200, $unchanged_response->get_status(), 'An unchanged C02 operation must return 200.' );
s07_same( true, $unchanged_response->get_data()['meta']['unchanged'], 'An unchanged C02 operation must declare unchanged=true.' );
$after_unchanged = $context['database']->counts();
s07_same( $before_unchanged['oj_ai_outbox_events'] + 1, $after_unchanged['oj_ai_outbox_events'], 'A new unchanged operation must persist one receipt.' );
s07_same( $before_unchanged['oj_ai_worksheet_revisions'], $after_unchanged['oj_ai_worksheet_revisions'], 'An unchanged operation must not append a revision.' );

$unchanged_retry = s07_request( $context['worksheet_id'], 's07-idempotency-unchanged-0001', $unchanged_body );
s07_same( true, call_user_func( $route['permission_callback'], $unchanged_retry ), 'An unchanged retry must pass owner policy.' );
$unchanged_replay = call_user_func( $route['callback'], $unchanged_retry );
s07_same( 200, $unchanged_replay->get_status(), 'An unchanged replay must preserve 200.' );
s07_same( true, $unchanged_replay->get_data()['meta']['idempotent_replay'], 'An unchanged retry must declare replay.' );
s07_same( $after_unchanged, $context['database']->counts(), 'An unchanged retry must add zero rows.' );

$counts_before_failures = $context['database']->counts();
$stale_body             = s07_body( 'command-s07-stale-0001', s05_id( 24990 ), 'draft', $context['document'], array() );
$stale_request          = s07_request( $context['worksheet_id'], 's07-idempotency-stale-0001', $stale_body );
s07_same( true, call_user_func( $route['permission_callback'], $stale_request ), 'Expected revision validation must run after owner policy.' );
$stale_response = call_user_func( $route['callback'], $stale_request );
s07_assert( $stale_response instanceof WP_Error, 'A stale C02 revision must return a problem.' );
s07_same( 'oj_ai_state_conflict', $stale_response->get_error_code(), 'A stale C02 revision must use the stable conflict code.' );
$stale_data = $stale_response->get_error_data();
s07_same( 409, $stale_data['status'], 'A stale C02 revision must return 409.' );
s07_same( s05_id( 24990 ), $stale_data['expected_revision_id'], 'C02 conflict context may expose only the expected public ID.' );
s07_same( $saved_revision_id, $stale_data['current_revision_id'], 'C02 conflict context must expose the current public ID.' );
s07_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $stale_data['current_revision_hash'] ), 'C02 conflict context must expose only the current hash.' );
s07_assert( ! str_contains( json_encode( $stale_data, JSON_THROW_ON_ERROR ), 'oj_ai_worksheets' ), 'C02 conflict data must not expose storage details.' );
s07_same( $counts_before_failures, $context['database']->counts(), 'A stale C02 revision must write zero rows.' );

$wrong_state_body    = s07_body( 'command-s07-state-0001', $saved_revision_id, 'active', $context['document'], array() );
$wrong_state_request = s07_request( $context['worksheet_id'], 's07-idempotency-state-0001', $wrong_state_body );
s07_same( true, call_user_func( $route['permission_callback'], $wrong_state_request ), 'Expected state validation must run inside C02.' );
$wrong_state = call_user_func( $route['callback'], $wrong_state_request );
s07_same( 409, $wrong_state->get_error_data()['status'], 'A stale C02 state must return 409.' );
s07_same( $counts_before_failures, $context['database']->counts(), 'A stale C02 state must write zero rows.' );

$invalid_body                  = $unchanged_body;
$invalid_body['actor_user_id'] = 999;
$invalid_request               = s07_request( $context['worksheet_id'], 's07-idempotency-invalid-0001', $invalid_body );
s07_same( true, call_user_func( $route['permission_callback'], $invalid_request ), 'Owner policy must run before full C02 body validation.' );
$invalid_response = call_user_func( $route['callback'], $invalid_request );
s07_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C02 body fields must return 422.' );
s07_same( $counts_before_failures, $context['database']->counts(), 'Invalid C02 input must write zero rows.' );

$GLOBALS['s07_actor'] = 99;
$outsider             = s07_request( $context['worksheet_id'], 's07-idempotency-owner-0001', $unchanged_body );
$outsider_result      = call_user_func( $route['permission_callback'], $outsider );
s07_same( 404, $outsider_result->get_error_data()['status'], 'A non-owner must be hidden with 404 before C02.' );

$GLOBALS['s07_actor']   = 42;
$policy->manage_allowed = false;
$capability_denied      = s07_request( $context['worksheet_id'], 's07-idempotency-capability-0001', $unchanged_body );
$capability_result      = call_user_func( $route['permission_callback'], $capability_denied );
s07_same( 403, $capability_result->get_error_data()['status'], 'Missing manage_worksheets must deny C02.' );

$policy->manage_allowed = true;
$bad_nonce              = s07_request( $context['worksheet_id'], 's07-idempotency-nonce-0001', $unchanged_body, 'invalid-nonce' );
$bad_nonce_result       = call_user_func( $route['permission_callback'], $bad_nonce );
s07_same( 403, $bad_nonce_result->get_error_data()['status'], 'An invalid wp_rest nonce must deny C02.' );

$missing_key        = s07_request( $context['worksheet_id'], '', $unchanged_body );
$missing_key_result = call_user_func( $route['permission_callback'], $missing_key );
s07_same( 422, $missing_key_result->get_error_data()['status'], 'A missing Idempotency-Key must return 422.' );

$GLOBALS['s07_actor'] = 0;
$anonymous            = s07_request( $context['worksheet_id'], 's07-idempotency-anonymous-0001', $unchanged_body );
$anonymous_result     = call_user_func( $route['permission_callback'], $anonymous );
s07_same( 401, $anonymous_result->get_error_data()['status'], 'An anonymous C02 request must return 401.' );
s07_same( $counts_before_failures, $context['database']->counts(), 'All C02 authorization failures must write zero rows.' );

$controller_source = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Worksheet'
	. DIRECTORY_SEPARATOR . 'Authoring' . DIRECTORY_SEPARATOR . 'Presentation'
	. DIRECTORY_SEPARATOR . 'Worksheet_Rest_Controller.php'
);
s07_assert( is_string( $controller_source ), 'C02 controller source must be readable.' );
s07_assert( ! str_contains( $controller_source, '$wpdb->prefix' ), 'C02 must not use a WordPress table prefix.' );
s07_assert( ! str_contains( $controller_source, 'CodingPanda\\OjPlatform' ), 'C02 must not import platform internals.' );

echo "G2-IMP-02-S07 C02 REST 계약 통과: owner·capability·wp_rest nonce·Idempotency-Key·200/201 replay·safe 409 확인\n";

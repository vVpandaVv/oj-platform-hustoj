<?php
/**
 * G1 common REST foundation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Idempotency_Key;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Rest\Rest_Request_Metadata;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G1 common REST foundation contract.
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
	 * G1 common REST foundation contract.
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

$GLOBALS['oj_ai_rest_actor']       = 0;
$GLOBALS['oj_ai_rest_nonce_calls'] = array();
$GLOBALS['oj_ai_rest_uuid']        = 'server-generated-correlation';

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['oj_ai_rest_actor'];
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
		$GLOBALS['oj_ai_rest_nonce_calls'][] = array( $nonce, $action );
		if ( 'valid-rest-nonce' === $nonce ) {
			return 1;
		}
		if ( 'previous-rest-nonce' === $nonce ) {
			return 2;
		}

		return false;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Wp generate uuid4.
	 *
	 * @return string
	 */
	function wp_generate_uuid4(): string {
		return (string) $GLOBALS['oj_ai_rest_uuid'];
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Rest foundation assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function rest_foundation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Rest foundation same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function rest_foundation_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Rest foundation throws.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function rest_foundation_throws( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Throwable ) {
		return;
	}

	throw new RuntimeException( $message );
}

/**
 * G1 common REST foundation contract.
 */
final class Rest_Foundation_Request_Fixture {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers;

	/**
	 * Initialize rest foundation request fixture dependencies and state.
	 *
	 * @param string              $method Method.
	 * @param array<string,mixed> $headers Headers.
	 * @param int                 $body_actor_user_id Body actor user id.
	 */
	public function __construct(
		private string $method,
		array $headers = array(),
		public int $body_actor_user_id = 999
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
	 * Get param.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_param( string $name ) {
		return 'actor_user_id' === $name ? $this->body_actor_user_id : null;
	}
}

/**
 * G1 common REST foundation contract.
 */
final class Rest_Foundation_Policy_Fixture implements Access_Policy_Port_Interface {
	/**
	 * Mode.
	 *
	 * @var string
	 */
	public string $mode = 'normal';
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->last_request = $request;

		if ( 'throw' === $this->mode ) {
			throw new RuntimeException( 'raw policy failure' );
		}
		if ( 'runtime_unavailable' === $this->mode ) {
			return Access_Policy_Decision::denied(
				503,
				'addon_runtime_not_ready',
				$request->correlation_id()
			);
		}
		if ( 0 === $request->actor_user_id() ) {
			return Access_Policy_Decision::denied(
				401,
				'authentication_required',
				$request->correlation_id()
			);
		}
		if ( ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied(
				403,
				'request_integrity_invalid',
				$request->correlation_id()
			);
		}

		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

$GLOBALS['oj_ai_rest_actor'] = 42;
$adapter                     = new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() );
$metadata                    = $adapter->capture(
	new Rest_Foundation_Request_Fixture(
		'post',
		array(
			'X-WP-Nonce'       => 'valid-rest-nonce',
			'Idempotency-Key'  => 'request-key-0001',
			'X-Correlation-ID' => 'client-must-not-control-this',
		),
		7
	)
);
rest_foundation_same( 42, $metadata->actor_user_id(), 'The actor must come from WordPress, not request input.' );
rest_foundation_same( 'POST', $metadata->method(), 'REST methods must be normalized.' );
rest_foundation_same( 'valid-rest-nonce', $metadata->nonce(), 'Only the standard nonce header should be captured.' );
rest_foundation_same( 'request-key-0001', $metadata->idempotency_key(), 'The idempotency header should be captured.' );

$policy       = new Rest_Foundation_Policy_Fixture();
$nonces       = new WordPress_Rest_Nonce_Verifier();
$correlations = new Correlation_Id_Factory( static fn(): string => 'server-correlation-0001' );
$authorizer   = new Rest_Request_Authorizer( $policy, $nonces, $correlations );

$nonce_calls = count( $GLOBALS['oj_ai_rest_nonce_calls'] );
$get_result  = $authorizer->authorize(
	new Rest_Request_Metadata( 42, 'GET', 'invalid-but-ignored', '' ),
	Access_Policy_Request::SHELL_CONSOLE,
	'workspace.contexts.list'
);
rest_foundation_assert( $get_result->is_allowed(), 'GET should pass without nonce or idempotency headers.' );
rest_foundation_same( $nonce_calls, count( $GLOBALS['oj_ai_rest_nonce_calls'] ), 'GET must not verify a nonce.' );
rest_foundation_same( Access_Policy_Request::SURFACE_REST, $policy->last_request?->surface(), 'The surface must be REST.' );
rest_foundation_same( 'server-correlation-0001', $get_result->correlation_id(), 'Correlation IDs must be server generated.' );

$bad_nonce = $authorizer->authorize(
	new Rest_Request_Metadata( 42, 'POST', 'invalid-rest-nonce', 'bad key' ),
	Access_Policy_Request::SHELL_CONSOLE,
	'worksheet.create'
);
rest_foundation_same( false, $bad_nonce->is_allowed(), 'POST with an invalid standard nonce must fail closed.' );
rest_foundation_same( 403, $bad_nonce->problem()?->status(), 'Invalid nonce must map through the common policy.' );
rest_foundation_same( 'request_integrity_invalid', $bad_nonce->reason_code(), 'Invalid nonce reason must remain internal.' );
rest_foundation_same(
	array( 'invalid-rest-nonce', 'wp_rest' ),
	$GLOBALS['oj_ai_rest_nonce_calls'][ array_key_last( $GLOBALS['oj_ai_rest_nonce_calls'] ) ],
	'No custom nonce action may be used.'
);

$GLOBALS['oj_ai_rest_actor'] = 0;
$anonymous_metadata          = $adapter->capture( new Rest_Foundation_Request_Fixture( 'POST' ) );
$anonymous                   = $authorizer->authorize(
	$anonymous_metadata,
	Access_Policy_Request::SHELL_CONSOLE,
	'worksheet.create'
);
rest_foundation_same( 401, $anonymous->problem()?->status(), 'Authentication must win before REST input validation.' );
$GLOBALS['oj_ai_rest_actor'] = 42;

$missing_key = $authorizer->authorize(
	new Rest_Request_Metadata( 42, 'POST', 'valid-rest-nonce', '' ),
	Access_Policy_Request::SHELL_CONSOLE,
	'worksheet.create'
);
rest_foundation_same( 422, $missing_key->problem()?->status(), 'Every authorized POST must require Idempotency-Key.' );
rest_foundation_same( 'idempotency_key_required', $missing_key->reason_code(), 'Missing key should have a stable internal reason.' );

foreach ( array( 'short', 'bad key value', str_repeat( 'A', 129 ) ) as $invalid_key ) {
	$result = $authorizer->authorize(
		new Rest_Request_Metadata( 42, 'POST', 'valid-rest-nonce', $invalid_key ),
		Access_Policy_Request::SHELL_CONSOLE,
		'worksheet.create'
	);
	rest_foundation_same( 422, $result->problem()?->status(), 'Invalid idempotency keys must return validation failure.' );
	rest_foundation_same( 'idempotency_key_invalid', $result->reason_code(), 'Invalid key reason must be stable.' );
}

foreach (
	array(
		'request-key-0001',
		'Key:2026_08-14@stage.one',
		'A' . str_repeat( 'b', 127 ),
	) as $valid_key
) {
	$result = $authorizer->authorize(
		new Rest_Request_Metadata( 42, 'POST', 'previous-rest-nonce', $valid_key ),
		Access_Policy_Request::SHELL_CONSOLE,
		'worksheet.create'
	);
	rest_foundation_assert( $result->is_allowed(), 'Valid nonce and idempotency token should pass the REST foundation.' );
	rest_foundation_same( $valid_key, $result->idempotency_key()?->value(), 'Idempotency key must remain opaque.' );
	rest_foundation_same( 64, strlen( (string) $result->idempotency_key()?->fingerprint() ), 'Key fingerprint must be SHA-256.' );
}

$policy->mode    = 'runtime_unavailable';
$runtime_failure = $authorizer->authorize(
	new Rest_Request_Metadata( 42, 'POST', 'valid-rest-nonce', 'bad key' ),
	Access_Policy_Request::SHELL_CONSOLE,
	'worksheet.create'
);
rest_foundation_same( 503, $runtime_failure->problem()?->status(), 'Policy failure must win before idempotency validation.' );
rest_foundation_same( 'addon_runtime_not_ready', $runtime_failure->reason_code(), 'Policy reason must be retained only for logs.' );

$policy->mode     = 'throw';
$policy_exception = $authorizer->authorize(
	new Rest_Request_Metadata( 42, 'GET' ),
	Access_Policy_Request::SHELL_CONSOLE,
	'workspace.contexts.list'
);
rest_foundation_same( 503, $policy_exception->problem()?->status(), 'Unexpected policy errors must fail closed.' );
rest_foundation_same( 'access_policy_failed', $policy_exception->reason_code(), 'Raw policy failures must not escape.' );
$policy->mode = 'normal';

$key = new Idempotency_Key( 'safe-key-0001' );
rest_foundation_same( hash( 'sha256', 'safe-key-0001' ), $key->fingerprint(), 'Key fingerprints must be deterministic.' );
rest_foundation_throws(
	static fn() => new Idempotency_Key( 'unsafe/key' ),
	'Idempotency keys must reject characters outside the safe-token profile.'
);
rest_foundation_throws(
	static fn() => new Rest_Request_Metadata( 42, 'DELETE' ),
	'Only frozen GET and POST methods may enter the REST foundation.'
);

$fallback_correlation = ( new Correlation_Id_Factory( static fn(): string => "bad correlation\n" ) )->create();
rest_foundation_assert(
	1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $fallback_correlation ),
	'Invalid generated correlation IDs must be replaced with a safe server value.'
);
rest_foundation_assert( "bad correlation\n" !== $fallback_correlation, 'Invalid correlation candidates must never be echoed.' );

$expected_problems = array(
	401 => array( 'oj_ai_authentication_required', '로그인이 필요합니다.' ),
	403 => array( 'oj_ai_access_denied', '이 작업을 할 수 없습니다.' ),
	404 => array( 'oj_ai_resource_not_found', '대상을 찾을 수 없습니다.' ),
	409 => array( 'oj_ai_state_conflict', '내용이 바뀌었습니다. 새로고침해 주세요.' ),
	410 => array( 'oj_ai_resource_gone', '더 이상 사용할 수 없습니다.' ),
	413 => array( 'oj_ai_payload_too_large', '파일 또는 내용이 너무 큽니다.' ),
	415 => array( 'oj_ai_media_type_unsupported', '지원하지 않는 파일 형식입니다.' ),
	422 => array( 'oj_ai_validation_failed', '확인할 항목이 있습니다.' ),
	429 => array( 'oj_ai_rate_limited', '잠시 후 다시 시도해 주세요.' ),
	500 => array( 'oj_ai_operation_failed', '처리하지 못했습니다.' ),
	503 => array( 'oj_ai_runtime_unavailable', '지금은 사용할 수 없습니다.' ),
);
rest_foundation_same( array_keys( $expected_problems ), Rest_Problem::supported_statuses(), 'The frozen error catalog must be exact.' );
foreach ( $expected_problems as $status => $definition ) {
	$problem = Rest_Problem::for_status( $status, 'server-correlation-0001' );
	rest_foundation_same( $definition[0], $problem->code(), 'Stable REST problem code drifted.' );
	rest_foundation_same( $definition[1], $problem->message(), 'Stable REST user message drifted.' );
	rest_foundation_same(
		array(
			'status'         => $status,
			'correlation_id' => 'server-correlation-0001',
		),
		$problem->data(),
		'REST problem data must expose only status and correlation ID.'
	);
}

$envelopes = new Rest_Envelope_Factory();
$success   = $envelopes->success(
	array( 'items' => array() ),
	'server-correlation-0001',
	202,
	array( 'next_cursor' => '' ),
	array( 'self' => 'https://example.test/rest/oj-ai/v1/contexts' ),
	array( 'workspace.contexts.list' )
);
rest_foundation_same( 202, $success->get_status(), 'Success envelope must preserve the allowed status.' );
rest_foundation_same(
	array(
		'data'    => array( 'items' => array() ),
		'meta'    => array(
			'correlation_id' => 'server-correlation-0001',
			'next_cursor'    => '',
		),
		'links'   => array( 'self' => 'https://example.test/rest/oj-ai/v1/contexts' ),
		'actions' => array( 'workspace.contexts.list' ),
	),
	$success->get_data(),
	'Success envelope shape must remain data/meta/links/actions.'
);

$validation_error = $envelopes->authorization_error( $missing_key );
rest_foundation_same( 'oj_ai_validation_failed', $validation_error->get_error_code(), 'Authorization errors must use the frozen code.' );
rest_foundation_same(
	array(
		'status'         => 422,
		'correlation_id' => 'server-correlation-0001',
	),
	$validation_error->get_error_data(),
	'WordPress error data must remain status and correlation ID only.'
);
rest_foundation_assert(
	! str_contains( json_encode( $validation_error->get_error_data(), JSON_THROW_ON_ERROR ), 'idempotency_key_required' ),
	'Internal REST reason codes must not be exposed.'
);

rest_foundation_throws(
	static fn() => $envelopes->success( array(), 'server-correlation-0001', 204 ),
	'Only 200, 201, and 202 success statuses are frozen.'
);
rest_foundation_throws(
	static fn() => $envelopes->success(
		array(),
		'server-correlation-0001',
		200,
		array( 'correlation_id' => 'client-value' )
	),
	'Response metadata must not override the correlation ID.'
);
rest_foundation_throws(
	static fn() => $envelopes->success(
		array(),
		'server-correlation-0001',
		200,
		array(),
		array(),
		array( 'worksheet.view', 'worksheet.view' )
	),
	'Response actions must be unique.'
);

$rest_source = '';
$rest_files  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Rest',
		FilesystemIterator::SKIP_DOTS
	)
);
foreach ( $rest_files as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$rest_source .= (string) file_get_contents( $file->getPathname() );
	}
}
foreach ( array( '/wp-json', '_wpnonce', 'register_rest_route' ) as $forbidden ) {
	rest_foundation_assert( ! str_contains( $rest_source, $forbidden ), 'The G1 REST foundation contains a forbidden or premature token: ' . $forbidden );
}

echo "G1 REST foundation contract passed: standard wp_rest, common Policy, stable envelopes, server correlation, POST idempotency, zero premature routes.\n";

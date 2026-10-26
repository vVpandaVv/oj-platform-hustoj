<?php
/**
 * G2-IMP-04-S01 worksheet revision validation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

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
use OJAI\Dashboard\Storage\Infrastructure\Worksheet_Asset_Readiness_Checker;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-04-S01 worksheet revision validation contract.
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
	 * G2-IMP-04-S01 worksheet revision validation contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct( private string $code, private string $message, private $data = array() ) {
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
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-04-S01 worksheet revision validation contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			return $value;
		}
	}
}

$GLOBALS['s17_actor']  = 42;
$GLOBALS['s17_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s17_actor'];
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
		return 's17-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s17_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S17 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s17_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S17 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s17_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S17 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s17_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S17 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s17_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s17_assert( Public_Id::is_valid( $id ), 'S01 fixture ID must be PublicIdV1.' );
	return $id;
}

/**
 * S17 element.
 *
 * @param string                              $type Type.
 * @param int                                 $id Id.
 * @param array<string,mixed>                 $properties Properties.
 * @param array<int,array<string,mixed>>|null $children Children.
 * @return array<string,mixed>
 */
function s17_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s17_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-04-S01 worksheet revision validation contract.
 */
final class S17_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize s17 repository dependencies and state.
	 *
	 * @param array<string,mixed>|null $source Source.
	 */
	public function __construct( public ?array $source ) {
	}
	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}
	/**
	 * Find owned worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function find_owned_worksheet( int $actor_user_id, string $worksheet_id ): ?array {
		return null;
	}
	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_owned_revisions( int $actor_user_id, string $worksheet_id, string $cursor, int $limit ): ?array {
		return null;
	}
	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		if ( 42 !== $actor_user_id || ! is_array( $this->source ) ) {
			return null;
		}
		return $revision_id === (string) ( $this->source['revision']['id'] ?? '' ) ? $this->source : null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $object_type Object type.
	 * @param string $object_id Object id.
	 * @return array|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $object_type, string $object_id ): ?array {
		return array(
			'current_revision' => $object_id,
			'state'            => 'draft',
			'relation'         => 'owner',
		);
	}
}

/**
 * G2-IMP-04-S01 worksheet revision validation contract.
 */
final class S17_Assets implements Worksheet_Asset_Lifecycle_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Find by public id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array|null
	 */
	public function find_by_public_id( string $asset_id ): ?array {
		return $this->rows[ $asset_id ] ?? null;
	}
	/**
	 * Mark quarantined purged.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @param string $purged_at_utc Purged at utc.
	 * @return bool
	 */
	public function mark_quarantined_purged( int $actor_user_id, string $worksheet_id, string $asset_id, string $purged_at_utc ): bool {
		return false;
	}
}

/**
 * G2-IMP-04-S01 worksheet revision validation contract.
 */
final class S17_Policy implements Access_Policy_Port_Interface {
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
		$this->last_request = $request;
		$object             = $request->object();
		if (
			42 !== $request->actor_user_id() || ! $request->request_integrity_verified()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's17_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $object->id(), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-04-S01 worksheet revision validation contract.
 */
final class S17_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize s17 request dependencies and state.
	 *
	 * @param string               $method Method.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $json Json.
	 * @param array<string,mixed>  $url Url.
	 */
	public function __construct( private string $method, array $headers, private array $json, private array $url ) {
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
	 * @return string
	 */
	public function get_header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return $this->json;
	}
	/**
	 * Get url params.
	 *
	 * @return array
	 */
	public function get_url_params(): array {
		return $this->url;
	}
	/**
	 * Get query params.
	 *
	 * @return array
	 */
	public function get_query_params(): array {
		return array();
	}
}

$worksheet_id              = s17_id( 1 );
$revision_id               = s17_id( 2 );
$asset_id                  = s17_id( 3 );
$source_document           = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => s17_id( 4 ),
	'locale'         => 'ko-KR',
	'title'          => '검증할 활동지',
	'nodes'          => array(
		s17_element( 'h1', 5, array(), array( array( 'text' => '검증할 활동지' ) ) ),
		s17_element(
			'file',
			6,
			array(
				'asset_id' => $asset_id,
				'label'    => '수업 자료',
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$canonical                 = new Canonical_Json();
$ids                       = new Public_Id_Generator( static fn (): string => pack( 'N4', 0, 0, 0, 1700 ) );
$receipt                   = ( new Worksheet_Document_Canonicalizer( $canonical, $ids ) )->canonicalize( $source_document );
$source                    = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'draft',
		'document_hash'   => $receipt->document_hash(),
		'validation_hash' => $receipt->validation_hash(),
	),
	'teacher_document' => $receipt->document(),
	'questions'        => array(),
);
$repository                = new S17_Repository( $source );
$assets                    = new S17_Assets();
$assets->rows[ $asset_id ] = array(
	'public_id'       => $asset_id,
	'owner_type'      => 'worksheet',
	'owner_public_id' => $worksheet_id,
	'purpose'         => 'worksheet_asset',
	'byte_size'       => '1200',
	'sha256'          => hash( 'sha256', 's17-asset' ),
	'state'           => 'ready',
	'scan_state'      => 'clean',
	'purged_at_utc'   => null,
);
$validator                 = new Validate_Worksheet_Revision(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Document_Id_Rewriter(),
	new Worksheet_Asset_Readiness_Checker( $assets ),
	$canonical,
	static fn (): string => '2026-08-21 14:00:00'
);
$command                   = new Validate_Worksheet_Revision_Command(
	42,
	$revision_id,
	'command-s17-validate-0001',
	'draft',
	$revision_id,
	hash( 'sha256', 's17-idempotency-key-0001' ),
	's17-correlation-001',
	$canonical
);
$result                    = $validator->execute( $command );
s17_same( 'pending', $result->status(), 'C05 must remain pending until preview and renderer checks are implemented.' );
s17_same( false, $result->release_ready(), 'S01 must fail closed before S02 and S03.' );
s17_same( array(), $result->blockers(), 'A ready exact revision must have no S01 blockers.' );
s17_same( 1, $result->statistics()['asset_count'], 'C05 must inspect every referenced asset.' );
s17_same( 1, $result->statistics()['ready_asset_count'], 'C05 must count ready assets.' );
s17_same( 'passed', $result->checks()[3]['status'], 'Ready asset check must pass.' );
s17_same( 'pending', $result->checks()[4]['status'], 'Student projection must remain an explicit pending gate.' );
s17_same( $result->projection_hash(), $validator->execute( $command )->projection_hash(), 'C05 projection hash must be deterministic.' );
s17_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $command->request_digest() ), 'C05 request digest must be canonical.' );
s17_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $command->idempotency_storage_key() ), 'C05 idempotency storage key must be scoped.' );

$assets->rows[ $asset_id ]['state'] = 'quarantined';
$blocked                            = $validator->execute( $command );
s17_same( 'blocked', $blocked->status(), 'A non-ready exact asset must block C05.' );
s17_same( 'worksheet_asset_not_ready', $blocked->blockers()[0]['code'], 'C05 asset blocker code drifted.' );
s17_same( 'state', $blocked->blockers()[0]['reason'], 'C05 must expose a safe recovery reason.' );
$assets->rows[ $asset_id ]['state'] = 'ready';

$stale_command = new Validate_Worksheet_Revision_Command(
	42,
	$revision_id,
	'command-s17-validate-0002',
	'draft',
	s17_id( 99 ),
	hash( 'sha256', 's17-idempotency-key-0002' ),
	's17-correlation-002',
	$canonical
);
$stale         = s17_failure( static fn () => $validator->execute( $stale_command ), 'Stale C05 expected revision must fail.' );
s17_assert( $stale instanceof Worksheet_Failure, 'Stale C05 must use a stable Worksheet failure.' );
s17_same( Worksheet_Failure::REVISION_CONFLICT, $stale->reason(), 'Stale C05 must return revision conflict.' );

$repository->source['revision']['validation_hash'] = hash( 'sha256', 'tampered' );
$tampered = s17_failure( static fn () => $validator->execute( $command ), 'Tampered stored validation hash must fail.' );
s17_assert( $tampered instanceof Worksheet_Failure, 'Tampered C05 source must use a stable Worksheet failure.' );
s17_same( Worksheet_Failure::STORED_DATA_INVALID, $tampered->reason(), 'Tampered C05 source must fail closed.' );
$repository->source = $source;

$policy       = new S17_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's17-rest-correlation-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Worksheet_Rest_Controller(
	$permissions,
	new Create_Worksheet( $repository, $ids, new Initial_Document_Factory( $ids, $canonical ) ),
	new Read_Worksheets( $repository ),
	$canonical,
	$envelopes,
	$correlations,
	null,
	null,
	$validator
);
$controller->register_routes();
$controller->register_routes();
$validation_routes = array_values(
	array_filter( $GLOBALS['s17_routes'], static fn ( array $route ): bool => Worksheet_Rest_Controller::ROUTE_VALIDATE === $route[1] )
);
s17_same( 1, count( $validation_routes ), 'C05 route must register exactly once.' );
$route   = $validation_routes[0][2];
$json    = array(
	'command_id' => 'command-s17-rest-0001',
	'expected'   => array(
		'expected_state'       => 'draft',
		'expected_revision_id' => $revision_id,
	),
	'payload'    => array(),
);
$request = new S17_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's17-valid-nonce',
		'Idempotency-Key' => 's17-rest-key-0001',
	),
	$json,
	array( 'revision_id' => $revision_id )
);
s17_same( true, ( $route['permission_callback'] )( $request ), 'C05 permission must accept owner manage + nonce.' );
$response = ( $route['callback'] )( $request );
s17_assert(
	$response instanceof WP_REST_Response,
	'C05 success must use the common response envelope. Actual: ' . get_debug_type( $response )
		. ( $response instanceof WP_Error ? ' ' . var_export( $response->get_error_data(), true ) : '' )
);
s17_same( 200, $response->get_status(), 'C05 must return 200.' );
$response_data = $response->get_data();
s17_same( 'pending', $response_data['data']['validation']['status'], 'C05 REST must expose fail-closed pending status.' );
s17_same( 'unavailable', $response_data['data']['approval']['status'], 'C05 must expose fail-closed approval readiness when C06 is unavailable.' );
s17_same( 0, $response_data['meta']['mutation_count'], 'C05 S01 must be mutation-free.' );
s17_same( Worksheet_Rest_Controller::ACTION_VALIDATE, $policy->last_request?->action_key(), 'C05 permission action drifted.' );

$missing_key        = new S17_Request(
	'POST',
	array( 'X-WP-Nonce' => 's17-valid-nonce' ),
	$json,
	array( 'revision_id' => $revision_id )
);
$missing_key_result = ( $route['permission_callback'] )( $missing_key );
s17_assert( $missing_key_result instanceof WP_Error, 'C05 must reject a missing Idempotency-Key before Application.' );
s17_same( 422, $missing_key_result->get_error_data()['status'], 'Missing C05 Idempotency-Key must return 422.' );

$invalid_request = new S17_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's17-valid-nonce',
		'Idempotency-Key' => 's17-rest-key-0002',
	),
	array_replace( $json, array( 'payload' => array( 'unexpected' => true ) ) ),
	array( 'revision_id' => $revision_id )
);
s17_same( true, ( $route['permission_callback'] )( $invalid_request ), 'C05 Policy must run before body validation.' );
$invalid_response = ( $route['callback'] )( $invalid_request );
s17_assert( $invalid_response instanceof WP_Error, 'Invalid C05 body must use the common problem envelope.' );
s17_same( 422, $invalid_response->get_error_data()['status'], 'Invalid C05 body must return 422.' );

$source_code = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Application/Validate_Worksheet_Revision.php' );
s17_assert( is_string( $source_code ), 'C05 source must be readable.' );
s17_assert( ! str_contains( $source_code, '$wpdb->prefix' ), 'C05 must never use a WordPress table prefix.' );
s17_assert( Ojai_Php_Source_Contract::contains( $source_code, "'renderer_parity', 'status' => 'pending'" ), 'S01 must keep renderer parity fail-closed.' );

echo "G2-IMP-04-S01 passed: exact revision validation, deterministic projection, asset readiness, fail-closed future gates and REST boundary.\n";

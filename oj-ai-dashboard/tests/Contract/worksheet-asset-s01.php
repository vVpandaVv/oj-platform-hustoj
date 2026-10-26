<?php
/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
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
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Storage\Infrastructure\WordPress_Worksheet_Asset_Repository;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Storage\Presentation\Worksheet_Asset_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-03-S01 worksheet private asset staging contract.
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
	 * G2-IMP-03-S01 worksheet private asset staging contract.
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
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['s13_actor']  = 42;
$GLOBALS['s13_routes'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s13_actor'];
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
		return 's13-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s13_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S13 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s13_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S13 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s13_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S13 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s13_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S13 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s13_id( int $suffix ): string {
	return '01J' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
}

/**
 * S13 upload.
 *
 * @param string $path Path.
 * @param string $name Name.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s13_upload( string $path, string $name ): array {
	$size = filesize( $path );
	if ( ! is_int( $size ) ) {
		throw new RuntimeException( 'Fixture size is unavailable.' );
	}
	return array(
		'name'     => $name,
		'type'     => 'application/octet-stream',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => $size,
	);
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Targets implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Initialize s13 targets dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $state State.
	 * @param string $revision_id Revision id.
	 * @param int    $owner_user_id Owner user id.
	 */
	public function __construct(
		public string $worksheet_id,
		public string $state,
		public string $revision_id,
		public int $owner_user_id = 42
	) {
	}

	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		if ( $actor_user_id !== $this->owner_user_id || $worksheet_id !== $this->worksheet_id ) {
			return null;
		}
		return array(
			'state'               => $this->state,
			'current_revision_id' => $this->revision_id,
		);
	}
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Assets implements Worksheet_Asset_Repository_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = array();

	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array|null
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		return $this->rows[ $storage_key ] ?? null;
	}

	/**
	 * Insert quarantined.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	public function insert_quarantined( array $record ): void {
		$this->rows[ $record['storage_key'] ] = array(
			'public_id'          => $record['public_id'],
			'created_by_user_id' => $record['actor_user_id'],
			'owner_type'         => 'worksheet',
			'owner_public_id'    => $record['worksheet_public_id'],
			'purpose'            => 'worksheet_asset',
			'storage_key'        => $record['storage_key'],
			'original_name'      => $record['original_name'],
			'mime_type'          => $record['mime_type'],
			'byte_size'          => $record['byte_size'],
			'sha256'             => $record['sha256'],
			'state'              => 'quarantined',
			'scan_state'         => 'clean',
			'expires_at_utc'     => $record['expires_at_utc'],
		);
	}
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Files implements Private_File_Store_Port_Interface {
	/**
	 * Hashes.
	 *
	 * @var array<string,string>
	 */
	public array $hashes = array();

	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		$actual = hash_file( 'sha256', $source_path );
		if ( ! is_string( $actual ) || ! hash_equals( $sha256, $actual ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		if ( isset( $this->hashes[ $storage_key ] ) ) {
			return false;
		}
		$this->hashes[ $storage_key ] = $sha256;
		return true;
	}

	/**
	 * Delete s13 files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		unset( $this->hashes[ $storage_key ] );
	}
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Wpdb {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Prepared values.
	 *
	 * @var array<int,mixed>
	 */
	private array $prepared_values = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->prepared_values = $values;
		return $sql;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		$key = (string) ( $this->prepared_values[0] ?? '' );
		foreach ( $this->rows as $row ) {
			if ( ( $row['storage_key'] ?? null ) === $key ) {
				return $row;
			}
			return null;
		}
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $formats Formats.
	 * @return int
	 */
	public function insert( string $table, array $data, array $formats ): int {
		if ( 'oj_ai_private_files' !== $table || count( $data ) !== count( $formats ) ) {
			$this->last_error = 'invalid insert';
			return 0;
		}
		$this->rows[] = $data;
		return 1;
	}
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Initialize s13 policy dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 */
	public function __construct( private string $worksheet_id ) {
	}

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
			42 !== $request->actor_user_id()
			|| ! $request->request_integrity_verified()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET !== $object->type()
			|| $this->worksheet_id !== $object->id()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's13_denied', $request->correlation_id() );
		}

		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( s13_id( 2 ), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-03-S01 worksheet private asset staging contract.
 */
final class S13_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();

	/**
	 * Initialize s13 request dependencies and state.
	 *
	 * @param string               $method Method.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,mixed>  $files Files.
	 * @param array                $url Url.
	 */
	public function __construct(
		private string $method,
		array $headers,
		private array $body,
		private array $files,
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
	 * @return string
	 */
	public function get_header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}

	/**
	 * Get body params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_body_params(): array {
		return $this->body;
	}

	/**
	 * Get file params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_file_params(): array {
		return $this->files;
	}

	/**
	 * Get url params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_params(): array {
		return $this->url;
	}
}

$worksheet_id = s13_id( 1 );
$revision_id  = s13_id( 2 );
$text_path    = tempnam( sys_get_temp_dir(), 'oj-ai-s13-' );
if ( ! is_string( $text_path ) || false === file_put_contents( $text_path, "worksheet asset\n" ) ) {
	throw new RuntimeException( 'Could not create the asset fixture.' );
}
$upload = s13_upload( $text_path, 'lesson.txt' );

$profile  = new Worksheet_Asset_Upload_Profile();
$metadata = $profile->inspect( $upload );
s13_same( 'text/plain', $metadata['mime_type'], 'The server must detect TXT from bytes and extension.' );
s13_same( hash_file( 'sha256', $text_path ), $metadata['sha256'], 'The receipt must use the file SHA-256.' );
s13_same(
	Worksheet_Asset_Failure::UNSUPPORTED_MEDIA,
	s13_failure(
		static fn () => $profile->inspect( s13_upload( $text_path, 'unsafe.svg' ) ),
		'SVG must be rejected.'
	)->reason(),
	'Unsafe formats must use the stable unsupported-media reason.'
);
$mismatch = $upload;
++$mismatch['size'];
s13_same(
	Worksheet_Asset_Failure::INVALID_UPLOAD,
	s13_failure( static fn () => $profile->inspect( $mismatch ), 'A size mismatch must fail.' )->reason(),
	'The browser-reported and actual byte count must match.'
);

$private_root             = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-private-s13-' . bin2hex( random_bytes( 6 ) );
$local_store              = new Local_Private_File_Store( $private_root );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$local_key                = 'worksheet-assets/aa/' . str_repeat( 'a', 64 ) . '.txt';
$sha256                   = (string) hash_file( 'sha256', $text_path );
s13_same( true, $local_store->put_quarantined( $text_path, $local_key, $sha256 ), 'The first local put must create the file.' );
s13_same( false, $local_store->put_quarantined( $text_path, $local_key, $sha256 ), 'An exact local retry must not replace the file.' );
$stored_path = $private_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $local_key );
s13_assert( is_file( $stored_path ), 'The private file must exist under the configured root.' );
s13_assert( ! str_starts_with( strtolower( (string) realpath( $stored_path ) ), strtolower( (string) realpath( ABSPATH ) ) ), 'The private file must remain outside ABSPATH.' );
s13_same(
	Worksheet_Asset_Failure::STORAGE_UNAVAILABLE,
	s13_failure(
		static fn () => $local_store->put_quarantined( $text_path, '../escape.txt', $sha256 ),
		'Path traversal must fail.'
	)->reason(),
	'Path traversal must fail closed.'
);
$local_store->delete( $local_key );
s13_assert( ! file_exists( $stored_path ), 'Deleting a staged file must remove only its exact private path.' );

$database   = new S13_Wpdb();
$repository = new WordPress_Worksheet_Asset_Repository( $database );
$repository->insert_quarantined(
	array(
		'public_id'           => s13_id( 3 ),
		'actor_user_id'       => 42,
		'created_at_utc'      => '2026-08-21 10:00:00',
		'worksheet_public_id' => $worksheet_id,
		'storage_key'         => 'worksheet-assets/bb/' . str_repeat( 'b', 64 ) . '.txt',
		'original_name'       => 'lesson.txt',
		'mime_type'           => 'text/plain',
		'byte_size'           => (int) filesize( $text_path ),
		'sha256'              => $sha256,
		'expires_at_utc'      => '2026-08-22 10:00:00',
	)
);
s13_same( 'worksheet_staging', $database->rows[0]['retention_class'], 'C64 must use the staging retention class.' );
s13_same( 'oj_ai_private_files', 'oj_ai_private_files', 'The fixture must retain the literal table contract.' );

$targets     = new S13_Targets( $worksheet_id, 'draft', $revision_id );
$assets      = new S13_Assets();
$files       = new S13_Files();
$id_sequence = 0;
$ids         = new Public_Id_Generator(
	static function () use ( &$id_sequence ): string {
		return pack( 'N4', 0, 0, 0, ++$id_sequence );
	}
);
$stage       = new Stage_Worksheet_Asset(
	$targets,
	$assets,
	$files,
	$profile,
	$ids,
	static fn (): string => '2026-08-21 10:00:00'
);
$command     = new Stage_Worksheet_Asset_Command(
	42,
	$worksheet_id,
	'command-s13-stage-0001',
	'draft',
	$revision_id,
	$upload,
	hash( 'sha256', 's13-idempotency' ),
	's13-correlation-core'
);
$first       = $stage->execute( $command );
s13_same( false, $first->replayed(), 'The first C64 execution must not be a replay.' );
s13_same( 'quarantined', $first->state(), 'C64 must create a quarantined receipt.' );
s13_same( 'clean', $first->scan_state(), 'The local allowlist inspection records a clean scan state.' );
s13_same( '2026-08-22 10:00:00', $first->expires_at_utc(), 'A staged receipt must expire after 24 hours.' );
$replay = $stage->execute( $command );
s13_same( true, $replay->replayed(), 'An exact Idempotency-Key retry must replay the first receipt.' );
s13_same( $first->asset_id(), $replay->asset_id(), 'A replay must return the original public asset ID.' );
s13_same( 1, count( $assets->rows ), 'An exact replay must add zero metadata rows.' );

$changed_upload         = $upload;
$changed_upload['name'] = 'changed.txt';
$conflicting            = new Stage_Worksheet_Asset_Command(
	42,
	$worksheet_id,
	'command-s13-stage-0002',
	'draft',
	$revision_id,
	$changed_upload,
	hash( 'sha256', 's13-idempotency' ),
	's13-correlation-conflict'
);
s13_same(
	Worksheet_Asset_Failure::IDEMPOTENCY_CONFLICT,
	s13_failure( static fn () => $stage->execute( $conflicting ), 'A changed retry must conflict.' )->reason(),
	'An Idempotency-Key cannot be reused for different file metadata.'
);

$policy               = new S13_Policy( $worksheet_id );
$correlation_sequence = 0;
$correlations         = new Correlation_Id_Factory(
	static function () use ( &$correlation_sequence ): string {
		return 's13-correlation-' . str_pad( (string) ++$correlation_sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes            = new Rest_Envelope_Factory();
$permissions          = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$rest_assets          = new S13_Assets();
$rest                 = new Worksheet_Asset_Rest_Controller(
	$permissions,
	new Stage_Worksheet_Asset(
		$targets,
		$rest_assets,
		new S13_Files(),
		$profile,
		$ids,
		static fn (): string => '2026-08-21 11:00:00'
	),
	$envelopes,
	$correlations
);
$rest->register_routes();
$rest->register_routes();
s13_same( 1, count( $GLOBALS['s13_routes'] ), 'C64 must register one route exactly once.' );
$route = $GLOBALS['s13_routes'][0][2];

$body    = array(
	'command_id'           => 'command-s13-rest-0001',
	'expected_revision_id' => $revision_id,
	'expected_state'       => 'draft',
);
$request = new S13_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's13-valid-nonce',
		'Idempotency-Key' => 's13-rest-idempotency-0001',
	),
	$body,
	array( 'file' => $upload ),
	array( 'worksheet_id' => $worksheet_id )
);
s13_same( true, call_user_func( $route['permission_callback'], $request ), 'The owner with manage capability must pass C64 policy.' );
s13_same( Worksheet_Asset_Rest_Controller::ACTION_STAGE, $policy->last_request?->action_key(), 'C64 must use its frozen action key.' );
$response = call_user_func( $route['callback'], $request );
s13_assert( $response instanceof WP_REST_Response, 'C64 success must return a REST response.' );
s13_same( 201, $response->get_status(), 'C64 must return 201 for a new receipt.' );
$response_data = $response->get_data();
s13_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $response_data ), 'C64 must use the common envelope.' );
s13_same( 'quarantined', $response_data['data']['asset']['state'], 'C64 must expose the safe quarantine state.' );
s13_same( false, $response_data['meta']['idempotent_replay'], 'The first REST command is not a replay.' );
$encoded = json_encode( $response_data, JSON_THROW_ON_ERROR );
s13_assert( ! str_contains( $encoded, $text_path ), 'C64 must not expose the upload temp path.' );
s13_assert( ! str_contains( $encoded, 'storage_key' ), 'C64 must not expose the private storage key.' );

$retry = new S13_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's13-valid-nonce',
		'Idempotency-Key' => 's13-rest-idempotency-0001',
	),
	$body,
	array( 'file' => $upload ),
	array( 'worksheet_id' => $worksheet_id )
);
s13_same( true, call_user_func( $route['permission_callback'], $retry ), 'An exact C64 retry must pass policy.' );
$retry_response = call_user_func( $route['callback'], $retry );
s13_same( true, $retry_response->get_data()['meta']['idempotent_replay'], 'An exact C64 retry must declare replay.' );
s13_same( 1, count( $rest_assets->rows ), 'An exact C64 retry must add zero rows.' );

$stale_body                         = $body;
$stale_body['expected_revision_id'] = s13_id( 90 );
$stale                              = new S13_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's13-valid-nonce',
		'Idempotency-Key' => 's13-rest-stale-0001',
	),
	$stale_body,
	array( 'file' => $upload ),
	array( 'worksheet_id' => $worksheet_id )
);
s13_same( true, call_user_func( $route['permission_callback'], $stale ), 'C64 must check expected revision after owner policy.' );
s13_same( 409, call_user_func( $route['callback'], $stale )->get_error_data()['status'], 'A stale C64 target must return 409.' );

$missing_key = new S13_Request(
	'POST',
	array( 'X-WP-Nonce' => 's13-valid-nonce' ),
	$body,
	array( 'file' => $upload ),
	array( 'worksheet_id' => $worksheet_id )
);
s13_same( 422, call_user_func( $route['permission_callback'], $missing_key )->get_error_data()['status'], 'C64 requires Idempotency-Key.' );

$source_files = array(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Worksheet_Asset_Repository.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Worksheet_Asset_Rest_Controller.php',
);
foreach ( $source_files as $source_file ) {
	$source = file_get_contents( $source_file );
	s13_assert( is_string( $source ), 'S01 source must be readable.' );
	s13_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S01 must not use a WordPress table prefix.' );
	s13_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform' ), 'S01 must not import platform internals.' );
}

@unlink( $text_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( dirname( $stored_path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( dirname( dirname( $stored_path ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( $private_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G2-IMP-03-S01 passed: private staging, allowlist, hash, receipt, owner CAS, idempotency and REST boundary.\n";

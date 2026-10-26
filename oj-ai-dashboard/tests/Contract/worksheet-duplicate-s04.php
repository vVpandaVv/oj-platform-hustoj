<?php
/**
 * G2-IMP-03-S04 worksheet duplicate contract.
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
use OJAI\Dashboard\Storage\Application\Clone_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Read_Worksheet_Asset_Content;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Source_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Duplicate_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-03-S04 worksheet duplicate contract.
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
	 * G2-IMP-03-S04 worksheet duplicate contract.
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

$GLOBALS['s16_actor'] = 42;
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-03-S04 worksheet duplicate contract.
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
$GLOBALS['s16_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s16_actor'];
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
		return 's16-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s16_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S16 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s16_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S16 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s16_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S16 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s16_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S16 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s16_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s16_assert( Public_Id::is_valid( $id ), 'S04 fixture ID must be PublicIdV1.' );
	return $id;
}

/**
 * S16 element.
 *
 * @param string     $type Type.
 * @param int        $id Id.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function s16_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s16_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Assets implements Worksheet_Asset_Repository_Port_Interface, Worksheet_Asset_Lifecycle_Port_Interface {
	/**
	 * By storage.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $by_storage = array();
	/**
	 * Storage by id.
	 *
	 * @var array<string,string>
	 */
	private array $storage_by_id = array();
	/**
	 * Purge writes.
	 *
	 * @var int
	 */
	public int $purge_writes = 0;

	/**
	 * Add source.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	public function add_source( array $row ): void {
		$this->by_storage[ $row['storage_key'] ]  = $row;
		$this->storage_by_id[ $row['public_id'] ] = $row['storage_key'];
	}
	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array|null
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		return $this->by_storage[ $storage_key ] ?? null;
	}
	/**
	 * Insert quarantined.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	public function insert_quarantined( array $record ): void {
		$row = array(
			'public_id'            => $record['public_id'],
			'created_by_user_id'   => $record['actor_user_id'],
			'owner_type'           => 'worksheet',
			'owner_public_id'      => $record['worksheet_public_id'],
			'purpose'              => 'worksheet_asset',
			'storage_key'          => $record['storage_key'],
			'original_name'        => $record['original_name'],
			'mime_type'            => $record['mime_type'],
			'byte_size'            => $record['byte_size'],
			'sha256'               => $record['sha256'],
			'state'                => 'quarantined',
			'scan_state'           => 'clean',
			'expires_at_utc'       => $record['expires_at_utc'],
			'legal_hold_until_utc' => null,
			'purged_at_utc'        => null,
		);
		$this->add_source( $row );
	}
	/**
	 * Find by public id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array|null
	 */
	public function find_by_public_id( string $asset_id ): ?array {
		$key = $this->storage_by_id[ $asset_id ] ?? null;
		return is_string( $key ) ? ( $this->by_storage[ $key ] ?? null ) : null;
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
		$key = $this->storage_by_id[ $asset_id ] ?? null;
		if ( ! is_string( $key ) || 'quarantined' !== ( $this->by_storage[ $key ]['state'] ?? null ) ) {
			return false;
		}
		$this->by_storage[ $key ]['state']         = 'purged';
		$this->by_storage[ $key ]['purged_at_utc'] = $purged_at_utc;
		++$this->purge_writes;
		return true;
	}
	/**
	 * Promote.
	 *
	 * @param array<int,string> $asset_ids Asset ids.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function promote( array $asset_ids ): void {
		foreach ( $asset_ids as $asset_id ) {
			$key = $this->storage_by_id[ $asset_id ] ?? '';
			if ( ! isset( $this->by_storage[ $key ] ) || 'quarantined' !== $this->by_storage[ $key ]['state'] ) {
				throw Worksheet_Failure::asset_not_ready();
			}
			$this->by_storage[ $key ]['state'] = 'ready';
		}
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Files implements Private_File_Store_Port_Interface, Private_File_Read_Port_Interface {
	/**
	 * Files.
	 *
	 * @var array<string,array{path:string,sha256:string}>
	 */
	public array $files = array();
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
		if ( isset( $this->files[ $storage_key ] ) ) {
			return false;
		}
		$this->files[ $storage_key ] = array(
			'path'   => $source_path,
			'sha256' => $sha256,
		);
		return true;
	}
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		$file = $this->files[ $storage_key ] ?? null;
		if ( ! is_array( $file ) || ! hash_equals( $sha256, $file['sha256'] ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		return $file['path'];
	}
	/**
	 * Delete s16 files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		unset( $this->files[ $storage_key ] );
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Sources implements Worksheet_Asset_Source_Port_Interface {
	/**
	 * Initialize s16 sources dependencies and state.
	 *
	 * @param string $revision_id Revision id.
	 * @param string $asset_id Asset id.
	 */
	public function __construct( private string $revision_id, private string $asset_id ) {
	}
	/**
	 * Owned source references asset.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	public function owned_source_references_asset( int $actor_user_id, string $source_type, string $source_id, string $asset_id ): bool {
		return 42 === $actor_user_id && 'revision' === $source_type
			&& $this->revision_id === $source_id && $this->asset_id === $asset_id;
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Targets implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		return null;
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Repository implements Worksheet_Repository_Port_Interface, Worksheet_Duplicate_Repository_Port_Interface {
	/**
	 * Fail next.
	 *
	 * @var bool
	 */
	public bool $fail_next = false;
	/**
	 * Duplicate writes.
	 *
	 * @var int
	 */
	public int $duplicate_writes = 0;
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,record:array<string,mixed>}>
	 */
	private array $receipts = array();
	/**
	 * Last record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_record = null;

	/**
	 * Initialize s16 repository dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param S16_Assets          $assets Assets.
	 */
	public function __construct( public array $source, private S16_Assets $assets ) {
	}

	/**
	 * Find duplicate receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Duplicate_Worksheet_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_duplicate_receipt( int $actor_user_id, string $key, string $digest ): ?Duplicate_Worksheet_Result {
		$receipt = $this->receipts[ $key ] ?? null;
		if ( ! is_array( $receipt ) ) {
			return null;
		}
		if ( ! hash_equals( $digest, $receipt['digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		return $this->result( $receipt['record'], true );
	}
	/**
	 * Find owned duplicate source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function find_owned_duplicate_source( int $actor_user_id, string $worksheet_id ): ?array {
		return 42 === $actor_user_id && $worksheet_id === $this->source['worksheet_id'] ? $this->source : null;
	}
	/**
	 * Duplicate initial.
	 *
	 * @param array $record Record.
	 * @return Duplicate_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function duplicate_initial( array $record ): Duplicate_Worksheet_Result {
		$key    = (string) $record['idempotency_storage_key'];
		$replay = $this->find_duplicate_receipt( (int) $record['actor_user_id'], $key, (string) $record['request_digest'] );
		if ( $replay instanceof Duplicate_Worksheet_Result ) {
			return $replay;
		}
		if ( $this->fail_next ) {
			$this->fail_next = false;
			throw Worksheet_Failure::storage_unavailable();
		}
		$this->assets->promote( $record['staged_asset_receipt_ids'] );
		$this->last_record = $record;
		++$this->duplicate_writes;
		$this->receipts[ $key ] = array(
			'digest' => $record['request_digest'],
			'record' => $record,
		);
		return $this->result( $record, false );
	}
	/**
	 * Result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Duplicate_Worksheet_Result
	 */
	private function result( array $record, bool $replayed ): Duplicate_Worksheet_Result {
		return new Duplicate_Worksheet_Result(
			(string) $record['worksheet_public_id'],
			(string) $record['revision_public_id'],
			(string) $record['source_worksheet_public_id'],
			(string) $record['expected_revision_public_id'],
			(string) $record['document_id'],
			(string) $record['document_hash'],
			(string) $record['validation_hash'],
			(string) $record['title'],
			(int) $record['asset_count'],
			(int) $record['reissued_id_count'],
			(string) $record['saved_at_utc'],
			$replayed
		);
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
		return null;
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
			'current_revision' => $this->source['current_revision_id'],
			'state'            => $this->source['state'],
			'relation'         => 'owner',
		);
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Policy implements Access_Policy_Port_Interface {
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
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_WORKSHEET !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's16_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $object->id(), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-03-S04 worksheet duplicate contract.
 */
final class S16_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize s16 request dependencies and state.
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

$asset_path = tempnam( sys_get_temp_dir(), 'oj-ai-s16-asset-' );
if ( ! is_string( $asset_path ) || false === file_put_contents( $asset_path, "Duplicate worksheet material\n" ) ) {
	throw new RuntimeException( 'Could not create S04 asset fixture.' );
}

$source_worksheet_id = s16_id( 1 );
$source_revision_id  = s16_id( 2 );
$source_document_id  = s16_id( 3 );
$source_asset_id     = s16_id( 4 );
$source_document     = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => $source_document_id,
	'locale'         => 'ko-KR',
	'title'          => '과학 탐구 활동지',
	'nodes'          => array(
		s16_element( 'h1', 5, array(), array( array( 'text' => '과학 탐구 활동지' ) ) ),
		s16_element(
			'file',
			6,
			array(
				'asset_id' => $source_asset_id,
				'label'    => '탐구 자료',
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$canonical           = new Canonical_Json();
$source_canonical    = ( new Worksheet_Document_Canonicalizer(
	$canonical,
	new Public_Id_Generator( static fn (): string => pack( 'N4', 0, 0, 0, 700 ) )
) )->canonicalize( $source_document );
$source_document     = $source_canonical->document();
$asset_hash          = hash_file( 'sha256', $asset_path );
$asset_size          = filesize( $asset_path );
if ( ! is_string( $asset_hash ) || ! is_int( $asset_size ) ) {
	throw new RuntimeException( 'S04 asset metadata failed.' );
}

$assets             = new S16_Assets();
$files              = new S16_Files();
$source_storage_key = 'worksheet-assets/source/material.txt';
$assets->add_source(
	array(
		'public_id'            => $source_asset_id,
		'created_by_user_id'   => 42,
		'owner_type'           => 'worksheet',
		'owner_public_id'      => $source_worksheet_id,
		'purpose'              => 'worksheet_asset',
		'storage_key'          => $source_storage_key,
		'original_name'        => 'material.txt',
		'mime_type'            => 'text/plain',
		'byte_size'            => $asset_size,
		'sha256'               => $asset_hash,
		'state'                => 'ready',
		'scan_state'           => 'clean',
		'expires_at_utc'       => null,
		'legal_hold_until_utc' => null,
		'purged_at_utc'        => null,
	)
);
$files->files[ $source_storage_key ] = array(
	'path'   => $asset_path,
	'sha256' => $asset_hash,
);
$source                              = array(
	'worksheet_id'         => $source_worksheet_id,
	'title'                => '과학 탐구 활동지',
	'state'                => 'draft',
	'current_revision_id'  => $source_revision_id,
	'source_document_hash' => $source_canonical->document_hash(),
	'source_content_hash'  => $source_canonical->document_hash(),
	'teacher_document'     => $source_document,
	'achievement_links'    => array(),
	'standard_snapshots'   => array(),
);

$counter      = 1000;
$ids          = new Public_Id_Generator(
	static function () use ( &$counter ): string {
		++$counter;
		return pack( 'N4', 0, 0, 1, $counter );
	}
);
$repository   = new S16_Repository( $source, $assets );
$read_content = new Read_Worksheet_Asset_Content(
	$assets,
	new S16_Sources( $source_revision_id, $source_asset_id ),
	new S16_Targets(),
	$files,
	static fn (): string => '2026-08-21 12:00:00'
);
$duplicate    = new Duplicate_Worksheet(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Revision_Projector( $canonical, $ids ),
	new Worksheet_Document_Id_Rewriter(),
	new Clone_Worksheet_Asset(
		$read_content,
		$assets,
		$files,
		new Worksheet_Asset_Upload_Profile(),
		$ids,
		static fn (): string => '2026-08-21 12:00:00'
	),
	$assets,
	$files,
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 12:00:00'
);
$command      = new Duplicate_Worksheet_Command(
	42,
	$source_worksheet_id,
	'command-s16-duplicate-0001',
	'draft',
	$source_revision_id,
	hash( 'sha256', 's16-duplicate-key-0001' ),
	's16-correlation-001',
	$canonical
);
$result       = $duplicate->execute( $command );
s16_same( false, $result->replayed(), 'First C03 execution must create a root.' );
s16_same( 1, $repository->duplicate_writes, 'C03 must commit exactly one aggregate.' );
s16_same( '과학 탐구 활동지 복사본', $result->title(), 'C03 must create a concise copy title.' );
s16_assert( $source_worksheet_id !== $result->worksheet_id(), 'C03 must issue a new root ID.' );
s16_assert( $source_revision_id !== $result->revision_id(), 'C03 must issue a new revision ID.' );
$record = $repository->last_record;
s16_assert( is_array( $record ), 'C03 must pass one complete repository record.' );
$production_repository = new WordPress_Worksheet_Repository( null, $canonical );
$record_validator      = new ReflectionMethod( $production_repository, 'assert_duplicate_record' );
$record_validator->invoke( $production_repository, $record );
$stored_document = json_decode( (string) $record['document_json'], true, 128, JSON_THROW_ON_ERROR );
s16_assert( $source_document_id !== $stored_document['document_id'], 'C03 must reissue document IDs.' );
s16_assert( s16_id( 5 ) !== $stored_document['nodes'][0]['node_id'], 'C03 must reissue node IDs.' );
$destination_asset_id = $stored_document['nodes'][1]['asset_id'];
s16_assert( $source_asset_id !== $destination_asset_id, 'C03 must reissue asset IDs.' );
s16_same( array( $destination_asset_id ), $record['staged_asset_receipt_ids'], 'C03 must bind the cloned asset receipt.' );
s16_same( 'ready', $assets->find_by_public_id( $destination_asset_id )['state'], 'Committed clone assets must be ready.' );

$replay = $duplicate->execute( $command );
s16_same( true, $replay->replayed(), 'Exact C03 retry must replay the receipt.' );
s16_same( 1, $repository->duplicate_writes, 'Exact C03 retry must create zero roots.' );
$asset_rows_after_replay = count( $assets->by_storage );
s16_same( 2, $asset_rows_after_replay, 'Exact C03 retry must clone zero assets.' );

$repository->fail_next = true;
$failed_command        = new Duplicate_Worksheet_Command(
	42,
	$source_worksheet_id,
	'command-s16-duplicate-0002',
	'draft',
	$source_revision_id,
	hash( 'sha256', 's16-duplicate-key-0002' ),
	's16-correlation-002',
	$canonical
);
$failure               = s16_failure( static fn () => $duplicate->execute( $failed_command ), 'C03 repository failure must escape.' );
s16_assert( $failure instanceof Worksheet_Failure, 'C03 must preserve stable worksheet failures.' );
s16_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $failure->reason(), 'C03 must preserve storage failure reason.' );
s16_same( 1, $assets->purge_writes, 'C03 must purge an uncommitted cloned asset.' );
s16_same( 1, $repository->duplicate_writes, 'Failed C03 must create zero partial roots.' );
$retry = $duplicate->execute( $failed_command );
s16_same( false, $retry->replayed(), 'A failed C03 command must be retryable with the same key.' );
s16_same( 2, $repository->duplicate_writes, 'Retry after failure must commit one root.' );

$stale         = new Duplicate_Worksheet_Command(
	42,
	$source_worksheet_id,
	'command-s16-duplicate-0003',
	'draft',
	s16_id( 99 ),
	hash( 'sha256', 's16-duplicate-key-0003' ),
	's16-correlation-003',
	$canonical
);
$stale_failure = s16_failure( static fn () => $duplicate->execute( $stale ), 'Stale C03 must fail.' );
s16_assert( $stale_failure instanceof Worksheet_Failure, 'Stale C03 must use worksheet conflict.' );
s16_same( Worksheet_Failure::REVISION_CONFLICT, $stale_failure->reason(), 'Stale C03 must be a revision conflict.' );

$policy       = new S16_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's16-rest-correlation-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
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
	$duplicate
);
$controller->register_routes();
$controller->register_routes();
$duplicate_routes = array_values(
	array_filter( $GLOBALS['s16_routes'], static fn ( array $route ): bool => Worksheet_Rest_Controller::ROUTE_DUPLICATE === $route[1] )
);
s16_same( 1, count( $duplicate_routes ), 'C03 route must register exactly once.' );
$route        = $duplicate_routes[0][2];
$rest_command = new Duplicate_Worksheet_Command(
	42,
	$source_worksheet_id,
	'command-s16-rest-0001',
	'draft',
	$source_revision_id,
	hash( 'sha256', 's16-rest-key-0001' ),
	's16-rest-correlation-seed',
	$canonical
);
$request      = new S16_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's16-valid-nonce',
		'Idempotency-Key' => 's16-rest-key-0001',
	),
	array(
		'command_id' => 'command-s16-rest-0001',
		'expected'   => array(
			'expected_state'       => 'draft',
			'expected_revision_id' => $source_revision_id,
		),
		'payload'    => array(),
	),
	array( 'worksheet_id' => $source_worksheet_id )
);
s16_same( true, ( $route['permission_callback'] )( $request ), 'C03 REST permission must accept owner manage + nonce.' );
$response = ( $route['callback'] )( $request );
s16_assert( $response instanceof WP_REST_Response, 'C03 REST success must use the common response envelope.' );
s16_same( 201, $response->get_status(), 'C03 REST must return 201.' );
$response_data = $response->get_data();
s16_same( $source_worksheet_id, $response_data['data']['source']['worksheet_id'], 'C03 REST must identify its source root.' );
s16_same( Worksheet_Rest_Controller::ACTION_DUPLICATE, $policy->last_request?->action_key(), 'C03 permission must use worksheet.duplicate.' );

$repository_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Repository.php' );
s16_assert( is_string( $repository_source ), 'C03 repository source must be readable.' );
s16_assert( str_contains( $repository_source, "DUPLICATE_EVENT_TYPE            = 'worksheet.duplicated'" ), 'C03 must persist a stable duplicate event.' );
s16_assert( str_contains( $repository_source, "query_or_fail( 'START TRANSACTION' )" ), 'C03 must use the existing aggregate transaction boundary.' );
s16_assert( ! str_contains( $repository_source, '$wpdb->prefix' ), 'C03 must keep literal oj_ai_* table names.' );

@unlink( $asset_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
echo "G2-IMP-03-S04 passed: atomic root duplicate, ID reissue, private asset reownership, compensation, replay and REST boundary.\n";

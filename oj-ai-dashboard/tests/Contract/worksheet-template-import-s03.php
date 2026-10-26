<?php
/**
 * G2-IMP-03-S03 portable worksheet template import contract.
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
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Import_Worksheet_Template;
use OJAI\Dashboard\Worksheet\Authoring\Application\Import_Worksheet_Template_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Import_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Package;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Template_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Zip_Worksheet_Template_Package_Reader;
use OJAI\Dashboard\Worksheet\Authoring\Port\Achievement_Standard_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Template_Package_Reader_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Template_Import_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-03-S03 portable worksheet template import contract.
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
	 * G2-IMP-03-S03 portable worksheet template import contract.
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

$GLOBALS['s15_actor']  = 42;
$GLOBALS['s15_routes'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s15_actor'];
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
		return 's15-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s15_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S15 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s15_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S15 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s15_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S15 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s15_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S15 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s15_id( int $suffix ): string {
	$id = '01J' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s15_assert( Public_Id::is_valid( $id ), 'S03 import fixture ID must be PublicIdV1.' );
	return $id;
}

/**
 * S15 element.
 *
 * @param string     $type Type.
 * @param int        $id Id.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function s15_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s15_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * S15 upload.
 *
 * @param string $path Path.
 * @param string $name Name.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s15_upload( string $path, string $name ): array {
	$size = filesize( $path );
	if ( ! is_int( $size ) ) {
		throw new RuntimeException( 'S03 fixture file size is unavailable.' );
	}
	return array(
		'name'     => $name,
		'type'     => Zip_Worksheet_Template_Package_Reader::PACKAGE_MIME,
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => $size,
	);
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Standards implements Achievement_Standard_Snapshot_Port_Interface {
	/**
	 * Find snapshots.
	 *
	 * @param array $standard_public_ids Standard public ids.
	 * @return array
	 */
	public function find_snapshots( array $standard_public_ids ): array {
		return array();
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Current revision id.
	 *
	 * @var string
	 */
	public string $current_revision_id;
	/**
	 * State.
	 *
	 * @var string
	 */
	public string $state = 'draft';
	/**
	 * Title.
	 *
	 * @var string
	 */
	public string $title = 'Destination';
	/**
	 * Fail next.
	 *
	 * @var bool
	 */
	public bool $fail_next = false;
	/**
	 * Append writes.
	 *
	 * @var int
	 */
	public int $append_writes = 0;
	/**
	 * Revision hashes.
	 *
	 * @var array<string,string>
	 */
	public array $revision_hashes = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $receipts = array();
	/**
	 * Last record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $last_record = null;

	/**
	 * Initialize s15 repository dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 */
	public function __construct( public string $worksheet_id, string $revision_id ) {
		$this->current_revision_id             = $revision_id;
		$this->revision_hashes[ $revision_id ] = hash( 'sha256', 'destination-initial' );
	}

	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new RuntimeException( 'Not used by S03 import fixture.' );
	}

	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		$key = (string) $record['idempotency_storage_key'];
		if ( isset( $this->receipts[ $key ] ) ) {
			$stored = $this->receipts[ $key ];
			if ( ! hash_equals( $stored['request_digest'], (string) $record['request_digest'] ) ) {
				throw Worksheet_Failure::idempotency_conflict();
			}
			return $this->result( $stored['record'], true );
		}
		if ( $this->fail_next ) {
			$this->fail_next = false;
			throw Worksheet_Failure::storage_unavailable();
		}
		$this->last_record = $record;
		++$this->append_writes;
		$this->current_revision_id                           = (string) $record['revision_public_id'];
		$this->revision_hashes[ $this->current_revision_id ] = (string) $record['document_hash'];
		$this->title            = (string) $record['title'];
		$this->receipts[ $key ] = array(
			'request_digest' => $record['request_digest'],
			'record'         => $record,
		);
		return $this->result( $record, false );
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Save_Worksheet_Draft_Result
	 */
	private function result( array $record, bool $replayed ): Save_Worksheet_Draft_Result {
		return new Save_Worksheet_Draft_Result(
			$this->worksheet_id,
			(string) $record['revision_public_id'],
			2,
			'draft',
			(string) $record['expected_revision_public_id'],
			(string) $record['document_id'],
			(string) $record['document_hash'],
			(string) $record['validation_hash'],
			(string) $record['title'],
			$this->state,
			(string) $record['saved_at_utc'],
			$record['temporary_id_map'],
			$replayed,
			false
		);
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
		if ( 42 !== $actor_user_id || $this->worksheet_id !== $worksheet_id ) {
			return null;
		}
		return array(
			'id'                    => $worksheet_id,
			'title'                 => $this->title,
			'state'                 => $this->state,
			'current_revision_id'   => $this->current_revision_id,
			'latest_publication_id' => null,
			'lock_version'          => 0,
			'created_at_utc'        => '2026-08-21 09:00:00',
			'updated_at_utc'        => '2026-08-21 09:00:00',
		);
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
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}

	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		if ( 42 !== $actor_user_id || ! isset( $this->revision_hashes[ $revision_id ] ) ) {
			return null;
		}
		return array(
			'worksheet_id'     => $this->worksheet_id,
			'revision'         => array(
				'id'                        => $revision_id,
				'revision_no'               => 1,
				'state'                     => 'draft',
				'source_revision_id'        => null,
				'editor_profile_version'    => Worksheet_Document_Profile::EDITOR_PROFILE,
				'document_schema_version'   => Worksheet_Document_Profile::SCHEMA_VERSION,
				'document_hash'             => $this->revision_hashes[ $revision_id ],
				'validation_schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
				'validation_hash'           => hash( 'sha256', 'destination-validation' ),
				'created_at_utc'            => '2026-08-21 09:00:00',
				'publication'               => null,
			),
			'teacher_document' => array(),
			'questions'        => array(),
		);
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
		if ( 42 !== $actor_user_id ) {
			return null;
		}
		return array(
			'current_revision' => $object_id,
			'state'            => 'draft',
			'relation'         => 'owner',
		);
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Targets implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Initialize s15 targets dependencies and state.
	 *
	 * @param S15_Repository $repository Persistence boundary used by this operation.
	 */
	public function __construct( private S15_Repository $repository ) {
	}
	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		if ( 42 !== $actor_user_id || $worksheet_id !== $this->repository->worksheet_id ) {
			return null;
		}
		return array(
			'state'               => $this->repository->state,
			'current_revision_id' => $this->repository->current_revision_id,
		);
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Assets implements Worksheet_Asset_Repository_Port_Interface, Worksheet_Asset_Lifecycle_Port_Interface {
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
		$row                                        = array(
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
		$this->by_storage[ $record['storage_key'] ] = $row;
		$this->storage_by_id[ $record['public_id'] ] = $record['storage_key'];
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
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Files implements Private_File_Store_Port_Interface {
	/**
	 * Files.
	 *
	 * @var array<string,string>
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
		$this->files[ $storage_key ] = $sha256;
		return true;
	}
	/**
	 * Delete s15 files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	public function delete( string $storage_key ): void {
		unset( $this->files[ $storage_key ] );
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Packages implements Worksheet_Template_Package_Reader_Port_Interface {
	/**
	 * Factory.
	 *
	 * @var callable():Worksheet_Template_Package
	 */
	private $factory;
	/**
	 * Initialize s15 packages dependencies and state.
	 *
	 * @param callable $factory Factory.
	 */
	public function __construct( callable $factory ) {
		$this->factory = $factory;
	}
	/**
	 * Read s15 packages.
	 *
	 * @param array $upload Upload.
	 * @return Worksheet_Template_Package
	 */
	public function read( array $upload ): Worksheet_Template_Package {
		return ( $this->factory )();
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Policy implements Access_Policy_Port_Interface {
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
			42 !== $request->actor_user_id()
			|| ! $request->request_integrity_verified()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_WORKSHEET_REVISION !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's15_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $object->id(), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-03-S03 portable worksheet template import contract.
 */
final class S15_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize s15 request dependencies and state.
	 *
	 * @param string               $method Method.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,mixed>  $files Files.
	 * @param array<string,mixed>  $url Url.
	 */
	public function __construct( private string $method, array $headers, private array $body, private array $files, private array $url ) {
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
	 * @return array
	 */
	public function get_body_params(): array {
		return $this->body;
	}
	/**
	 * Get file params.
	 *
	 * @return array
	 */
	public function get_file_params(): array {
		return $this->files;
	}
	/**
	 * Get url params.
	 *
	 * @return array
	 */
	public function get_url_params(): array {
		return $this->url;
	}
}

$asset_path   = tempnam( sys_get_temp_dir(), 'oj-ai-s15-asset-' );
$package_path = tempnam( sys_get_temp_dir(), 'oj-ai-s15-package-' );
if (
	! is_string( $asset_path ) || false === file_put_contents( $asset_path, "Imported worksheet material\n" )
	|| ! is_string( $package_path ) || false === file_put_contents( $package_path, "PK\x03\x04fixture" )
) {
	throw new RuntimeException( 'Could not create S03 import fixtures.' );
}

$worksheet_id            = s15_id( 1 );
$destination_revision_id = s15_id( 2 );
$package_revision_id     = s15_id( 3 );
$source_document_id      = s15_id( 4 );
$source_asset_id         = s15_id( 5 );
$source_document         = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => $source_document_id,
	'locale'         => 'ko-KR',
	'title'          => 'Imported template',
	'nodes'          => array(
		s15_element( 'h1', 6, array(), array( array( 'text' => 'Imported template' ) ) ),
		s15_element(
			'file',
			7,
			array(
				'asset_id' => $source_asset_id,
				'label'    => 'Material',
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);

$source_ids      = new Public_Id_Generator( static fn (): string => pack( 'N4', 0, 0, 0, 900 ) );
$canonical       = new Canonical_Json();
$source          = ( new Worksheet_Document_Canonicalizer( $canonical, $source_ids ) )->canonicalize( $source_document );
$source_document = $source->document();
$asset_size      = filesize( $asset_path );
$asset_hash      = hash_file( 'sha256', $asset_path );
if ( ! is_int( $asset_size ) || ! is_string( $asset_hash ) ) {
	throw new RuntimeException( 'S03 asset metadata is unavailable.' );
}

$release_count   = 0;
$package_factory = static function () use (
	&$release_count,
	$source,
	$package_revision_id,
	$source_asset_id,
	$asset_path,
	$asset_size,
	$asset_hash
): Worksheet_Template_Package {
	return new Worksheet_Template_Package(
		hash( 'sha256', 's15-package' ),
		$package_revision_id,
		$source->document_hash(),
		$source->document(),
		array(),
		array(
			array(
				'source_asset_id' => $source_asset_id,
				'original_name'   => 'material.txt',
				'mime_type'       => 'text/plain',
				'byte_size'       => $asset_size,
				'sha256'          => $asset_hash,
				'tmp_name'        => $asset_path,
			),
		),
		static function () use ( &$release_count ): void {
			++$release_count;
		}
	);
};

$counter    = 1000;
$ids        = new Public_Id_Generator(
	static function () use ( &$counter ): string {
		++$counter;
		return pack( 'N4', 0, 0, 0, $counter );
	}
);
$repository = new S15_Repository( $worksheet_id, $destination_revision_id );
$assets     = new S15_Assets();
$files      = new S15_Files();
$targets    = new S15_Targets( $repository );
$stage      = new Stage_Worksheet_Asset(
	$targets,
	$assets,
	$files,
	new Worksheet_Asset_Upload_Profile(),
	$ids,
	static fn (): string => '2026-08-21 10:00:00'
);
$save       = new Save_Worksheet_Draft(
	$repository,
	new S15_Standards(),
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Revision_Projector( $canonical, $ids ),
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 10:00:01'
);
$import     = new Import_Worksheet_Template(
	new Read_Worksheets( $repository ),
	new S15_Packages( $package_factory ),
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Revision_Projector( $canonical, $ids ),
	new Worksheet_Template_Id_Rewriter(),
	$stage,
	$save,
	$assets,
	$assets,
	$files,
	$canonical,
	static fn (): string => '2026-08-21 10:00:02'
);

$upload  = s15_upload( $package_path, 'worksheet.ojws' );
$command = new Import_Worksheet_Template_Command(
	42,
	$destination_revision_id,
	'command-s15-import-0001',
	'draft',
	$destination_revision_id,
	$upload + array( 'full_path' => '../../untrusted/worksheet.ojws' ),
	hash( 'sha256', 's15-import-key-0001' ),
	's15-correlation-001'
);
s15_same( $upload, $command->upload(), 'C04 must discard PHP 8.1 client full_path before passing uploads to storage.' );
foreach ( array( array( 'full_path' => array( 'nested' ) ), array( 'unexpected' => 'value' ) ) as $invalid_metadata ) {
	try {
		new Import_Worksheet_Template_Command(
			42,
			$destination_revision_id,
			'command-s15-import-invalid',
			'draft',
			$destination_revision_id,
			$upload + $invalid_metadata,
			hash( 'sha256', 's15-import-invalid' ),
			's15-correlation-invalid'
		);
		throw new RuntimeException( 'C04 accepted invalid upload metadata.' );
	} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		// Unsupported metadata must not reach the package reader.
	}
}
$result = $import->execute( $command );
s15_same( 1, $result->imported_asset_count(), 'C04 must import the declared asset exactly once.' );
s15_same( 4, $result->reissued_id_count(), 'C04 must reissue document, node and asset IDs.' );
s15_same( false, $result->saved()->replayed(), 'The first C04 command is not a replay.' );
s15_same( 1, $repository->append_writes, 'C04 must append one immutable draft revision.' );
s15_same( 1, count( $assets->by_storage ), 'C04 must stage one private metadata row.' );
s15_same( 1, count( $files->files ), 'C04 must stage one private binary.' );
s15_same( 1, $release_count, 'C04 must release extracted temporary files after success.' );

$record = $repository->last_record;
s15_assert( is_array( $record ), 'C04 must pass one validated record to C02 persistence.' );
$stored_document = json_decode( (string) $record['document_json'], true, 128, JSON_THROW_ON_ERROR );
s15_assert( is_array( $stored_document ), 'C04 stored document must decode.' );
s15_assert( $source_document_id !== $stored_document['document_id'], 'C04 must not retain the package document ID.' );
s15_assert( s15_id( 6 ) !== $stored_document['nodes'][0]['node_id'], 'C04 must not retain package node IDs.' );
$destination_asset_id = $stored_document['nodes'][1]['asset_id'];
s15_assert( is_string( $destination_asset_id ) && $source_asset_id !== $destination_asset_id, 'C04 must replace package asset IDs.' );
s15_same( array( $destination_asset_id ), $record['staged_asset_receipt_ids'], 'C04 must bind the reissued staged asset receipt.' );
s15_same( $destination_asset_id, $record['asset_bindings'][0]['asset_id'], 'C04 projection must use the destination asset ID.' );

$replay = $import->execute( $command );
s15_same( true, $replay->saved()->replayed(), 'An exact C04 retry must replay the original receipt.' );
s15_same( 1, $repository->append_writes, 'An exact C04 retry must append zero revisions.' );
s15_same( 1, count( $assets->by_storage ), 'An exact C04 retry must stage zero additional asset rows.' );
s15_same( 2, $release_count, 'C04 must release temporary files after replay.' );

$failing_revision_id   = $repository->current_revision_id;
$repository->fail_next = true;
$failing               = new Import_Worksheet_Template_Command(
	42,
	$failing_revision_id,
	'command-s15-import-0002',
	'draft',
	$failing_revision_id,
	$upload,
	hash( 'sha256', 's15-import-key-0002' ),
	's15-correlation-002'
);
$failure               = s15_failure( static fn () => $import->execute( $failing ), 'A C02 storage failure must escape C04.' );
s15_assert( $failure instanceof Worksheet_Failure, 'C04 must preserve the stable Worksheet failure.' );
s15_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $failure->reason(), 'C04 must preserve the storage failure reason.' );
s15_same( 1, $assets->purge_writes, 'C04 must purge a newly staged asset when revision persistence fails.' );
s15_same( 1, count( $files->files ), 'C04 compensation must remove only the failed command binary.' );
s15_same( 3, $release_count, 'C04 must release temporary files after failure.' );
$retry_after_failure = $import->execute( $failing );
s15_same( false, $retry_after_failure->saved()->replayed(), 'A failed C04 command must be retryable with the same key.' );
s15_same( 2, $repository->append_writes, 'A retry after failure must append exactly one revision.' );
s15_same( 3, count( $assets->by_storage ), 'A retry must preserve the purged receipt and stage the next deterministic attempt.' );
s15_same( 2, count( $files->files ), 'A successful retry must restore one active imported binary.' );
$retry_replay = $import->execute( $failing );
s15_same( true, $retry_replay->saved()->replayed(), 'The successful retry receipt must then replay exactly.' );
s15_same( 2, $repository->append_writes, 'The retry replay must append zero revisions.' );
s15_same( 5, $release_count, 'Every failed, retried and replayed C04 package must release temporary files.' );

$bad_manifest     = new Worksheet_Template_Id_Rewriter();
$manifest_failure = s15_failure(
	static fn () => $bad_manifest->assert_asset_manifest( $source->document(), array() ),
	'C04 must reject a document asset omitted from the manifest.'
);
s15_assert( $manifest_failure instanceof Worksheet_Template_Import_Failure, 'Asset manifest mismatch must use the stable import failure.' );

$zip_reader  = new Zip_Worksheet_Template_Package_Reader( $canonical, new Worksheet_Asset_Upload_Profile() );
$zip_failure = s15_failure( static fn () => $zip_reader->read( $upload ), 'The malformed fixture package must fail safely.' );
if ( class_exists( '\\ZipArchive' ) ) {
	s15_same( Worksheet_Template_Import_Failure::PACKAGE_INVALID, $zip_failure->reason(), 'Malformed ZIP must be rejected.' );
} else {
	s15_same( Worksheet_Template_Import_Failure::ENVIRONMENT_UNSUPPORTED, $zip_failure->reason(), 'Missing ext-zip must fail closed.' );
}

$policy       = new S15_Policy();
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's15-rest-correlation-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Worksheet_Template_Import_Rest_Controller( $permissions, $import, $envelopes, $correlations );
$controller->register_routes();
$controller->register_routes();
s15_same( 1, count( $GLOBALS['s15_routes'] ), 'C04 must register exactly one route once.' );
s15_same( Worksheet_Template_Import_Rest_Controller::ROUTE_IMPORT, $GLOBALS['s15_routes'][0][1], 'C04 must keep the frozen route.' );
$route            = $GLOBALS['s15_routes'][0][2];
$rest_revision_id = $repository->current_revision_id;
$request          = new S15_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's15-valid-nonce',
		'Idempotency-Key' => 's15-rest-import-key-0001',
	),
	array(
		'command_id'           => 'command-s15-rest-import-0001',
		'expected_state'       => 'draft',
		'expected_revision_id' => $rest_revision_id,
	),
	array( 'package' => $upload ),
	array( 'revision_id' => $rest_revision_id )
);
s15_same( true, call_user_func( $route['permission_callback'], $request ), 'C04 owner with manage capability must pass Policy.' );
s15_same( Worksheet_Template_Import_Rest_Controller::ACTION_IMPORT, $policy->last_request?->action_key(), 'C04 must use its frozen action key.' );
$response = call_user_func( $route['callback'], $request );
s15_assert( $response instanceof WP_REST_Response, 'C04 success must return a REST response.' );
s15_same( 201, $response->get_status(), 'C04 new revision must return 201.' );
$response_data = $response->get_data();
s15_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $response_data ), 'C04 must use the common envelope.' );
s15_same( 1, $response_data['data']['import']['imported_asset_count'], 'C04 response must expose only the safe import count.' );
s15_same( false, $response_data['meta']['idempotent_replay'], 'The first REST C04 command is not a replay.' );
$encoded = json_encode( $response_data, JSON_THROW_ON_ERROR );
s15_assert( ! str_contains( $encoded, $package_path ) && ! str_contains( $encoded, $asset_path ), 'C04 must not expose temporary paths.' );

$missing_key = new S15_Request(
	'POST',
	array( 'X-WP-Nonce' => 's15-valid-nonce' ),
	array(
		'command_id'           => 'command-s15-rest-import-0002',
		'expected_state'       => 'draft',
		'expected_revision_id' => $repository->current_revision_id,
	),
	array( 'package' => $upload ),
	array( 'revision_id' => $repository->current_revision_id )
);
s15_same( 422, call_user_func( $route['permission_callback'], $missing_key )->get_error_data()['status'], 'C04 must require Idempotency-Key.' );

$source_files = array(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Worksheet' . DIRECTORY_SEPARATOR . 'Authoring'
		. DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Zip_Worksheet_Template_Package_Reader.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Worksheet' . DIRECTORY_SEPARATOR . 'Authoring'
		. DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Worksheet_Template_Import_Rest_Controller.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php',
);
foreach ( $source_files as $source_file ) {
	$runtime_source = file_get_contents( $source_file );
	s15_assert( is_string( $runtime_source ), 'S03 runtime source must be readable.' );
	s15_assert( ! str_contains( $runtime_source, '$wpdb->prefix' ), 'S03 must not use a WordPress table prefix.' );
	s15_assert( ! str_contains( $runtime_source, 'CodingPanda\\OjPlatform' ), 'S03 must not import OJ Platform internals.' );
}

@unlink( $asset_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@unlink( $package_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G2-IMP-03-S03 passed: multipart C04, package validation, deterministic ID reissue, asset staging, compensation, replay and REST boundary.\n";

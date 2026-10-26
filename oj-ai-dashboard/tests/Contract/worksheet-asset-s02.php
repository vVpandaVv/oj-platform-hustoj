<?php
/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
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
use OJAI\Dashboard\Storage\Application\Discard_Staged_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Discard_Staged_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Application\Read_Worksheet_Asset_Content;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Source_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Storage\Presentation\Worksheet_Asset_Access_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Asset_Source_Reader;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-03-S02 worksheet asset stream and discard contract.
	 */
	final class WP_REST_Response {
		/**
		 * Headers.
		 *
		 * @var array<string,string>
		 */
		private array $headers = array();

		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		/**
		 * Header.
		 *
		 * @param string $key Key.
		 * @param string $value Value.
		 * @return void
		 */
		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Get headers.
		 *
		 * @return array<string,string>
		 */
		public function get_headers(): array {
			return $this->headers;
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
	 * G2-IMP-03-S02 worksheet asset stream and discard contract.
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

$GLOBALS['s14_actor']   = 42;
$GLOBALS['s14_routes']  = array();
$GLOBALS['s14_filters'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['s14_actor'];
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
		return 's14-valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['s14_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Add filter.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['s14_filters'][] = array( $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S14 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s14_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S14 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s14_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S14 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s14_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S14 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function s14_id( int $suffix ): string {
	return '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Targets implements Worksheet_Asset_Target_Port_Interface {
	/**
	 * Initialize s14 targets dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 */
	public function __construct( public string $worksheet_id, public string $revision_id ) {
	}

	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array {
		return 42 === $actor_user_id && $this->worksheet_id === $worksheet_id
			? array(
				'state'               => 'draft',
				'current_revision_id' => $this->revision_id,
			)
			: null;
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Lifecycle implements Worksheet_Asset_Lifecycle_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $rows = array();
	/**
	 * Purge writes.
	 *
	 * @var int
	 */
	public int $purge_writes = 0;

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
	public function mark_quarantined_purged(
		int $actor_user_id,
		string $worksheet_id,
		string $asset_id,
		string $purged_at_utc
	): bool {
		$row = $this->rows[ $asset_id ] ?? null;
		if (
			! is_array( $row )
			|| 42 !== $actor_user_id
			|| $worksheet_id !== ( $row['owner_public_id'] ?? null )
			|| 'quarantined' !== ( $row['state'] ?? null )
			|| null !== ( $row['legal_hold_until_utc'] ?? null )
		) {
			return false;
		}
		$this->rows[ $asset_id ]['state']          = 'purged';
		$this->rows[ $asset_id ]['purged_at_utc']  = $purged_at_utc;
		$this->rows[ $asset_id ]['expires_at_utc'] = null;
		++$this->purge_writes;
		return true;
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Sources implements Worksheet_Asset_Source_Port_Interface {
	/**
	 * References.
	 *
	 * @var array<string,bool>
	 */
	public array $references = array();

	/**
	 * Owned source references asset.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	public function owned_source_references_asset(
		int $actor_user_id,
		string $source_type,
		string $source_id,
		string $asset_id
	): bool {
		return 42 === $actor_user_id
			&& ( $this->references[ $source_type . '|' . $source_id . '|' . $asset_id ] ?? false );
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Worksheets implements Worksheet_Repository_Port_Interface {
	/**
	 * Revision.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $revision = null;

	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new LogicException( 'Not used.' );
	}

	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		throw new LogicException( 'Not used.' );
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
		return 42 === $actor_user_id && ( $this->revision['revision']['id'] ?? null ) === $revision_id
			? $this->revision
			: null;
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
		return null;
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Publication_Db {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Row.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $row = null;
	/**
	 * Values.
	 *
	 * @var array<int,mixed>
	 */
	private array $values = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->values = $values;
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
		return 42 === (int) ( $this->values[1] ?? 0 ) ? $this->row : null;
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();

	/**
	 * Initialize s14 policy dependencies and state.
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
		$this->requests[] = $request;
		if ( ! $this->allowed || 42 !== $request->actor_user_id() || ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied( 403, 's14_denied', $request->correlation_id() );
		}
		if ( Worksheet_Asset_Access_Rest_Controller::ACTION_CONTENT === $request->action_key() ) {
			if ( array( Addon_Capabilities::VIEW_WORKSHEETS ) !== $request->required_capabilities() || null !== $request->object() ) {
				return Access_Policy_Decision::denied( 403, 's14_content_denied', $request->correlation_id() );
			}
			return Access_Policy_Decision::allowed( $request->correlation_id() );
		}
		$object = $request->object();
		if (
			Worksheet_Asset_Access_Rest_Controller::ACTION_DISCARD !== $request->action_key()
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| $this->worksheet_id !== $object->id()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 's14_discard_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( s14_id( 2 ), 'draft', 'owner' )
		);
	}
}

/**
 * G2-IMP-03-S02 worksheet asset stream and discard contract.
 */
final class S14_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();

	/**
	 * Initialize s14 request dependencies and state.
	 *
	 * @param string               $method Method.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $url Url.
	 * @param array<string,mixed>  $query Query.
	 * @param array<string,mixed>  $json Json.
	 */
	public function __construct(
		private string $method,
		array $headers,
		private array $url,
		private array $query = array(),
		private array $json = array()
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
		return $this->query;
	}

	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}
}

$worksheet_id   = s14_id( 1 );
$revision_id    = s14_id( 2 );
$asset_id       = s14_id( 3 );
$publication_id = s14_id( 4 );
$content        = "private worksheet bytes\n";
$source_file    = tempnam( sys_get_temp_dir(), 'oj-ai-s14-' );
if ( ! is_string( $source_file ) || false === file_put_contents( $source_file, $content ) ) {
	throw new RuntimeException( 'Could not create S02 fixture.' );
}
$sha256                   = hash( 'sha256', $content );
$root                     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-private-s14-' . bin2hex( random_bytes( 6 ) );
$store                    = new Local_Private_File_Store( $root );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$key                      = 'worksheet-assets/cc/' . str_repeat( 'c', 64 ) . '.txt';
$store->put_quarantined( $source_file, $key, $sha256 );
s14_assert( is_file( $store->readable_path( $key, $sha256 ) ), 'The read port must return an integrity-checked private path.' );
s14_same(
	Worksheet_Asset_Failure::STORAGE_UNAVAILABLE,
	s14_failure( static fn () => $store->readable_path( $key, str_repeat( 'd', 64 ) ), 'A hash mismatch must fail.' )->reason(),
	'The private read port must reject a hash mismatch.'
);

$targets                      = new S14_Targets( $worksheet_id, $revision_id );
$lifecycle                    = new S14_Lifecycle();
$sources                      = new S14_Sources();
$lifecycle->rows[ $asset_id ] = array(
	'public_id'            => $asset_id,
	'created_by_user_id'   => 42,
	'owner_type'           => 'worksheet',
	'owner_public_id'      => $worksheet_id,
	'purpose'              => 'worksheet_asset',
	'storage_key'          => $key,
	'original_name'        => 'lesson.txt',
	'mime_type'            => 'text/plain',
	'byte_size'            => strlen( $content ),
	'sha256'               => $sha256,
	'state'                => 'quarantined',
	'scan_state'           => 'clean',
	'expires_at_utc'       => '2026-08-22 12:00:00',
	'legal_hold_until_utc' => null,
	'purged_at_utc'        => null,
);
$read                         = new Read_Worksheet_Asset_Content(
	$lifecycle,
	$sources,
	$targets,
	$store,
	static fn (): string => '2026-08-21 12:00:00'
);
$staged                       = $read->execute( 42, $asset_id, 'staged', $asset_id );
s14_same( $sha256, $staged->sha256(), 'A staged exact receipt may read its own bytes.' );
s14_same(
	Worksheet_Asset_Failure::NOT_FOUND,
	s14_failure( static fn () => $read->execute( 42, $asset_id, 'staged', $revision_id ), 'Wrong staged source must fail.' )->reason(),
	'A staged stream requires source_id=asset_id.'
);
$lifecycle->rows[ $asset_id ]['expires_at_utc'] = '2026-08-21 11:59:59';
s14_same(
	Worksheet_Asset_Failure::EXPIRED,
	s14_failure( static fn () => $read->execute( 42, $asset_id, 'staged', $asset_id ), 'Expired staged content must fail.' )->reason(),
	'Expired staged content must return the stable expired reason.'
);
$lifecycle->rows[ $asset_id ]['expires_at_utc'] = '2026-08-22 12:00:00';

$lifecycle->rows[ $asset_id ]['state']                               = 'ready';
$sources->references[ 'revision|' . $revision_id . '|' . $asset_id ] = true;
s14_same( $sha256, $read->execute( 42, $asset_id, 'revision', $revision_id )->sha256(), 'An exact owned revision reference may stream ready bytes.' );
s14_same(
	Worksheet_Asset_Failure::NOT_FOUND,
	s14_failure( static fn () => $read->execute( 42, $asset_id, 'publication', $publication_id ), 'Unreferenced publication must fail.' )->reason(),
	'An unreferenced source must be hidden with not-found.'
);

$worksheet_reader           = new S14_Worksheets();
$worksheet_reader->revision = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array( 'id' => $revision_id ),
	'teacher_document' => array(
		'nodes' => array(
			array(
				'type'     => 'img',
				'asset_id' => $asset_id,
			),
		),
	),
);
$publication_db             = new S14_Publication_Db();
$student_json               = json_encode( array( 'nodes' => array( array( 'asset_id' => $asset_id ) ) ), JSON_THROW_ON_ERROR );
$print_json                 = json_encode( array( 'nodes' => array() ), JSON_THROW_ON_ERROR );
$publication_db->row        = array(
	'state'                 => 'published',
	'student_document_json' => $student_json,
	'student_document_hash' => hash( 'sha256', $student_json ),
	'print_projection_json' => $print_json,
	'print_projection_hash' => hash( 'sha256', $print_json ),
);
$source_reader              = new WordPress_Worksheet_Asset_Source_Reader( $worksheet_reader, $publication_db );
s14_same( true, $source_reader->owned_source_references_asset( 42, 'revision', $revision_id, $asset_id ), 'The Worksheet owner adapter must find an exact revision asset field.' );
s14_same( true, $source_reader->owned_source_references_asset( 42, 'publication', $publication_id, $asset_id ), 'The Worksheet owner adapter must verify publication projection hashes and references.' );
s14_same( false, $source_reader->owned_source_references_asset( 42, 'revision', $revision_id, s14_id( 90 ) ), 'A different public ID is not an exact source reference.' );

$lifecycle->rows[ $asset_id ]['state'] = 'quarantined';
$discard                               = new Discard_Staged_Worksheet_Asset(
	$targets,
	$lifecycle,
	$store,
	static fn (): string => '2026-08-21 13:00:00'
);
$discard_command                       = new Discard_Staged_Worksheet_Asset_Command(
	42,
	$worksheet_id,
	$asset_id,
	'command-s14-discard-0001',
	'draft',
	$revision_id,
	hash( 'sha256', 's14-discard-key' ),
	's14-correlation-discard'
);
$discarded                             = $discard->execute( $discard_command );
s14_same( false, $discarded->replayed(), 'The first discard must perform one CAS transition.' );
s14_same( 'purged', $lifecycle->rows[ $asset_id ]['state'], 'C65 must mark the metadata purged before removing bytes.' );
s14_same( 1, $lifecycle->purge_writes, 'C65 must perform one purge write.' );
s14_same( true, $discard->execute( $discard_command )->replayed(), 'An exact discard retry must replay safely.' );
s14_same( 1, $lifecycle->purge_writes, 'A discard replay must add zero writes.' );

$store->put_quarantined( $source_file, $key, $sha256 );
$lifecycle->rows[ $asset_id ]['state']          = 'quarantined';
$lifecycle->rows[ $asset_id ]['purged_at_utc']  = null;
$lifecycle->rows[ $asset_id ]['expires_at_utc'] = '2026-08-22 12:00:00';

$policy       = new S14_Policy( $worksheet_id );
$sequence     = 0;
$correlations = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 's14-correlation-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes    = new Rest_Envelope_Factory();
$permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller   = new Worksheet_Asset_Access_Rest_Controller(
	$permissions,
	$read,
	$discard,
	$envelopes,
	$correlations
);
$controller->register_routes();
$controller->register_routes();
s14_same( 2, count( $GLOBALS['s14_routes'] ), 'S02 must register Q34 and C65 exactly once.' );
s14_same( 1, count( $GLOBALS['s14_filters'] ), 'Q34 must register one binary serve filter.' );

$routes = array();
foreach ( $GLOBALS['s14_routes'] as $registration ) {
	$routes[ $registration[1] ] = $registration[2];
}
$content_route = $routes[ Worksheet_Asset_Access_Rest_Controller::ROUTE_CONTENT ];
$discard_route = $routes[ Worksheet_Asset_Access_Rest_Controller::ROUTE_DISCARD ];

$content_request = new S14_Request(
	'GET',
	array(),
	array( 'asset_id' => $asset_id ),
	array(
		'source_type' => 'staged',
		'source_id'   => $asset_id,
	)
);
s14_same( true, call_user_func( $content_route['permission_callback'], $content_request ), 'Q34 must pass common view policy before exact source authorization.' );
$content_response = call_user_func( $content_route['callback'], $content_request );
s14_assert( $content_response instanceof WP_REST_Response, 'Q34 must return a REST response for binary serving.' );
s14_same( null, $content_response->get_data(), 'Q34 must not serialize an internal path or file bytes as JSON.' );
$headers = $content_response->get_headers();
s14_same( 'text/plain', $headers['Content-Type'], 'Q34 must retain the verified MIME type.' );
s14_same( (string) strlen( $content ), $headers['Content-Length'], 'Q34 must expose the verified byte length.' );
s14_same( 'nosniff', $headers['X-Content-Type-Options'], 'Q34 must disable MIME sniffing.' );
ob_start();
$served   = $controller->serve_stream( false, $content_response, $content_request, new stdClass() );
$streamed = ob_get_clean();
s14_same( true, $served, 'The binary filter must stop WordPress JSON serving.' );
s14_same( $content, $streamed, 'Q34 must stream the exact verified bytes.' );

$stream_row  = $lifecycle->rows[ $asset_id ];
$stream_path = $store->readable_path( $key, $sha256 );
foreach ( array( 'policy', 'actor', 'expiry', 'scan', 'purge', 'bytes', 'request' ) as $stream_case ) {
	$pending_request = new S14_Request(
		'GET',
		array(),
		array( 'asset_id' => $asset_id ),
		array(
			'source_type' => 'staged',
			'source_id'   => $asset_id,
		)
	);
	s14_same( true, $controller->permissions_content( $pending_request ), 'The pending stream starts authorized.' );
	$pending_response = $controller->content( $pending_request );
	s14_assert( $pending_response instanceof WP_REST_Response, 'The pending stream has a binary response.' );
	$serve_request = $pending_request;
	if ( 'policy' === $stream_case ) {
		$policy->allowed = false;
	}
	if ( 'actor' === $stream_case ) {
		$GLOBALS['s14_actor'] = 43;
	}
	if ( 'expiry' === $stream_case ) {
		$lifecycle->rows[ $asset_id ]['expires_at_utc'] = '2026-08-20 00:00:00';
	}
	if ( 'scan' === $stream_case ) {
		$lifecycle->rows[ $asset_id ]['scan_state'] = 'infected';
	}
	if ( 'purge' === $stream_case ) {
		$lifecycle->rows[ $asset_id ]['purged_at_utc'] = '2026-08-21 11:59:59';
	}
	if ( 'bytes' === $stream_case ) {
		file_put_contents( $stream_path, str_repeat( 'x', strlen( $content ) ) );
	}
	if ( 'request' === $stream_case ) {
		$serve_request = clone $pending_request;
	}
	try {
		ob_start();
		$controller->serve_stream( false, $pending_response, $serve_request, new stdClass() );
		$rejected_bytes = ob_get_clean();
		s14_same( '', $rejected_bytes, 'A changed stream must expose zero bytes: ' . $stream_case );
	} finally {
		$policy->allowed              = true;
		$GLOBALS['s14_actor']         = 42;
		$lifecycle->rows[ $asset_id ] = $stream_row;
		file_put_contents( $stream_path, $content );
	}
}

$discard_request = new S14_Request(
	'POST',
	array(
		'X-WP-Nonce'      => 's14-valid-nonce',
		'Idempotency-Key' => 's14-rest-discard-0001',
	),
	array(
		'worksheet_id' => $worksheet_id,
		'asset_id'     => $asset_id,
	),
	array(),
	array(
		'command_id'           => 'command-s14-rest-discard-0001',
		'expected_state'       => 'draft',
		'expected_revision_id' => $revision_id,
	)
);
s14_same( true, call_user_func( $discard_route['permission_callback'], $discard_request ), 'C65 must require nonce, manage capability and Worksheet edit scope.' );
$discard_response = call_user_func( $discard_route['callback'], $discard_request );
s14_same( 200, $discard_response->get_status(), 'C65 must return 200 after a successful discard.' );
s14_same( 'purged', $discard_response->get_data()['data']['asset']['state'], 'C65 must return only the safe purged state.' );
s14_same( false, $discard_response->get_data()['meta']['idempotent_replay'], 'The first REST discard is not a replay.' );

$after_purge = new S14_Request(
	'GET',
	array(),
	array( 'asset_id' => $asset_id ),
	array(
		'source_type' => 'staged',
		'source_id'   => $asset_id,
	)
);
s14_same( true, call_user_func( $content_route['permission_callback'], $after_purge ), 'Q34 common policy must not reveal purge state.' );
s14_same( 404, call_user_func( $content_route['callback'], $after_purge )->get_error_data()['status'], 'A purged asset must be hidden as 404.' );

$controller_source = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Storage'
	. DIRECTORY_SEPARATOR . 'Presentation' . DIRECTORY_SEPARATOR . 'Worksheet_Asset_Access_Rest_Controller.php'
);
s14_assert( is_string( $controller_source ), 'S02 controller source must be readable.' );
s14_assert( ! str_contains( $controller_source, '$wpdb->prefix' ), 'S02 must not use a WordPress table prefix.' );
s14_assert( ! str_contains( $controller_source, 'CodingPanda\\OjPlatform' ), 'S02 must not import platform internals.' );

@unlink( $source_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( dirname( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $key ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( dirname( dirname( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $key ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.
@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning.

echo "G2-IMP-03-S02 passed: exact source authorization, private binary stream, expiry, discard CAS and replay.\n";

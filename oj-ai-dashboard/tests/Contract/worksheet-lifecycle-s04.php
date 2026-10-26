<?php
/**
 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
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
use OJAI\Dashboard\Worksheet\Authoring\Application\Change_Worksheet_Lifecycle;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Lifecycle_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
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
	 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
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

$GLOBALS['lifecycle_s04_actor']  = 42;
$GLOBALS['lifecycle_s04_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['lifecycle_s04_actor'];
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
		return 'lifecycle-s04-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['lifecycle_s04_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * Lifecycle s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function lifecycle_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Lifecycle s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function lifecycle_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Lifecycle s04 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function lifecycle_s04_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Worksheet_Failure $failure ) {
		lifecycle_s04_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}
/**
 * Lifecycle s04 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function lifecycle_s04_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}


/**
 * Lifecycle s04 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function lifecycle_s04_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	lifecycle_s04_assert( Public_Id::is_valid( $id ), 'S04 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Lifecycle s04 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function lifecycle_s04_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function ( int $length ) use ( &$counter ): string {
			unset( $length );
			return substr( hash( 'sha256', 'lifecycle-s04-' . ( $counter++ ), true ), 0, 16 );
		}
	);
}

/**
 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
 */
final class Lifecycle_S04_Repository implements Worksheet_Lifecycle_Repository_Port_Interface {
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Worksheet_Lifecycle_Result}>
	 */
	private array $receipts = array();
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();

	/**
	 * Initialize lifecycle s04 repository dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string      $revision_id Revision id.
	 * @param string|null $publication_id Publication id.
	 * @param int         $lock_version Lock version.
	 * @param string|null $archived_at_utc Archived at utc.
	 */
	public function __construct(
		public string $state,
		public string $revision_id,
		public ?string $publication_id,
		public int $lock_version,
		public ?string $archived_at_utc = null
	) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $operation Operation.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Worksheet_Lifecycle_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( int $actor_user_id, string $operation, string $key, string $digest ): ?Worksheet_Lifecycle_Result {
		unset( $actor_user_id, $operation );
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $receipt['digest'] ) ) {
			throw Worksheet_Failure::idempotency_conflict();
		}
		$result = $receipt['result'];
		return new Worksheet_Lifecycle_Result(
			$result->operation(),
			$result->worksheet_id(),
			$result->previous_state(),
			$result->state(),
			$result->current_revision_id(),
			$result->latest_publication_id(),
			$result->lock_version(),
			$result->archived_at_utc(),
			$result->changed_by_user_id(),
			$result->changed_at_utc(),
			true
		);
	}

	/**
	 * Change state.
	 *
	 * @param array $record Record.
	 * @return Worksheet_Lifecycle_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function change_state( array $record ): Worksheet_Lifecycle_Result {
		if (
			$this->state !== $record['expected_state'] || $this->revision_id !== $record['expected_revision_id']
			|| $this->lock_version !== $record['expected_lock_version']
		) {
			throw Worksheet_Failure::lifecycle_conflict( $this->state, $this->revision_id, $this->lock_version );
		}
		$previous              = $this->state;
		$this->state           = Worksheet_Lifecycle_Command::ARCHIVE === $record['operation']
			? 'archived'
			: ( null === $this->publication_id ? 'draft' : 'active' );
		$this->archived_at_utc = Worksheet_Lifecycle_Command::ARCHIVE === $record['operation'] ? $record['changed_at_utc'] : null;
		++$this->lock_version;
		$this->records[] = $record;
		$result          = new Worksheet_Lifecycle_Result(
			$record['operation'],
			$record['worksheet_public_id'],
			$previous,
			$this->state,
			$this->revision_id,
			$this->publication_id,
			$this->lock_version,
			$this->archived_at_utc,
			$record['actor_user_id'],
			$record['changed_at_utc'],
			false
		);
		$this->receipts[ $record['idempotency_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
 */
final class Lifecycle_S04_Worksheets implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize lifecycle s04 worksheets dependencies and state.
	 *
	 * @param string $revision_id Revision id.
	 * @param string $state State.
	 */
	public function __construct( private string $revision_id, private string $state = 'active' ) {
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
		return 42 === $actor_user_id
			? array(
				'current_revision' => $this->revision_id,
				'state'            => $this->state,
				'relation'         => 'owner',
			)
			: null;
	}
}

/**
 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
 */
final class Lifecycle_S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Actions.
	 *
	 * @var array<int,string>
	 */
	public array $actions = array();
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->actions[] = $request->action_key();
		$object          = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| ! in_array( $request->action_key(), array( Worksheet_Rest_Controller::ACTION_ARCHIVE, Worksheet_Rest_Controller::ACTION_RESTORE ), true )
			|| array( Addon_Capabilities::MANAGE_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_WORKSHEET !== $object->type()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'lifecycle_s04_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $object->id(), 'active', 'owner' )
		);
	}
}

/**
 * G2-IMP-06-S04 C08/C09 worksheet archive and restore contract.
 */
final class Lifecycle_S04_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();
	/**
	 * Initialize lifecycle s04 request dependencies and state.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $json Json.
	 * @param string               $worksheet_id Worksheet public identifier.
	 */
	public function __construct( array $headers, private array $json, private string $worksheet_id ) {
		foreach ( $headers as $key => $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
		}
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'POST';
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
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'worksheet_id' => $this->worksheet_id );
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

$canonical      = new Canonical_Json();
$worksheet_id   = lifecycle_s04_id( 401 );
$revision_id    = lifecycle_s04_id( 402 );
$publication_id = lifecycle_s04_id( 403 );
$repository     = new Lifecycle_S04_Repository( 'active', $revision_id, $publication_id, 7 );
$application    = new Change_Worksheet_Lifecycle(
	$repository,
	lifecycle_s04_generator( 1 ),
	static fn (): string => '2026-08-21 21:15:00'
);
$archive        = new Worksheet_Lifecycle_Command(
	42,
	Worksheet_Lifecycle_Command::ARCHIVE,
	$worksheet_id,
	'archive-s04-command',
	'active',
	$revision_id,
	7,
	hash( 'sha256', 'archive-s04-key' ),
	'lifecycle:s04:archive',
	$canonical
);
$archive_again  = new Worksheet_Lifecycle_Command(
	42,
	Worksheet_Lifecycle_Command::ARCHIVE,
	$worksheet_id,
	'archive-s04-command',
	'active',
	$revision_id,
	7,
	hash( 'sha256', 'archive-s04-key' ),
	'lifecycle:s04:archive',
	$canonical
);
lifecycle_s04_same( $archive->request_digest(), $archive_again->request_digest(), 'C08 request digest must be deterministic.' );
$archived = $application->execute( $archive );
lifecycle_s04_same( 'archived', $archived->state(), 'C08 must archive an active worksheet root.' );
lifecycle_s04_same( 8, $archived->lock_version(), 'C08 must advance the root lock version once.' );
lifecycle_s04_same( $publication_id, $archived->latest_publication_id(), 'C08 must preserve the immutable publication pointer.' );
lifecycle_s04_same( '2026-08-21 21:15:00', $archived->archived_at_utc(), 'C08 must record the archive UTC value.' );
$archive_replay = $application->execute( $archive_again );
lifecycle_s04_same( true, $archive_replay->replayed(), 'C08 exact replay must return the durable receipt.' );
lifecycle_s04_same( 1, count( $repository->records ), 'C08 exact replay must add zero mutations.' );

$archive_conflict = new Worksheet_Lifecycle_Command(
	42,
	Worksheet_Lifecycle_Command::ARCHIVE,
	$worksheet_id,
	'archive-s04-command',
	'active',
	$revision_id,
	99,
	hash( 'sha256', 'archive-s04-key' ),
	'lifecycle:s04:archive',
	$canonical
);
lifecycle_s04_failure(
	static fn () => $application->execute( $archive_conflict ),
	Worksheet_Failure::IDEMPOTENCY_CONFLICT,
	'Same idempotency key with a different expected lock must conflict.'
);

$restore = new Worksheet_Lifecycle_Command(
	42,
	Worksheet_Lifecycle_Command::RESTORE,
	$worksheet_id,
	'restore-s04-command',
	'archived',
	$revision_id,
	8,
	hash( 'sha256', 'restore-s04-key' ),
	'lifecycle:s04:restore',
	$canonical
);
lifecycle_s04_assert( ! hash_equals( $archive->request_digest(), $restore->request_digest() ), 'C08 and C09 digest domains must be isolated.' );
$restored = $application->execute( $restore );
lifecycle_s04_same( 'active', $restored->state(), 'C09 must restore a published worksheet to active.' );
lifecycle_s04_same( null, $restored->archived_at_utc(), 'C09 must clear only the root archive marker.' );
lifecycle_s04_same( $revision_id, $restored->current_revision_id(), 'C09 must preserve the current revision pointer.' );
lifecycle_s04_same( $publication_id, $restored->latest_publication_id(), 'C09 must preserve the latest publication pointer.' );

$draft_repository  = new Lifecycle_S04_Repository( 'draft', $revision_id, null, 0 );
$draft_application = new Change_Worksheet_Lifecycle(
	$draft_repository,
	lifecycle_s04_generator( 11 ),
	static fn (): string => '2026-08-21 21:20:00'
);
$draft_application->execute(
	new Worksheet_Lifecycle_Command(
		42,
		Worksheet_Lifecycle_Command::ARCHIVE,
		$worksheet_id,
		'archive-draft-s04',
		'draft',
		$revision_id,
		0,
		hash( 'sha256', 'archive-draft-key' ),
		'lifecycle:s04:draft-archive',
		$canonical
	)
);
$draft_restored = $draft_application->execute(
	new Worksheet_Lifecycle_Command(
		42,
		Worksheet_Lifecycle_Command::RESTORE,
		$worksheet_id,
		'restore-draft-s04',
		'archived',
		$revision_id,
		1,
		hash( 'sha256', 'restore-draft-key' ),
		'lifecycle:s04:draft-restore',
		$canonical
	)
);
lifecycle_s04_same( 'draft', $draft_restored->state(), 'C09 must restore an unpublished worksheet to draft.' );
lifecycle_s04_invalid(
	static fn () => new Worksheet_Lifecycle_Result(
		Worksheet_Lifecycle_Command::ARCHIVE,
		$worksheet_id,
		'active',
		'archived',
		$revision_id,
		null,
		2,
		'2026-08-21 21:20:00',
		42,
		'2026-08-21 21:20:00',
		false
	),
	'Archived receipt must reject an active source without a publication pointer.'
);
lifecycle_s04_invalid(
	static fn () => new Worksheet_Lifecycle_Result(
		Worksheet_Lifecycle_Command::ARCHIVE,
		$worksheet_id,
		'draft',
		'archived',
		$revision_id,
		$publication_id,
		2,
		'2026-08-21 21:20:00',
		42,
		'2026-08-21 21:20:00',
		false
	),
	'Archived receipt must reject a draft source with a publication pointer.'
);


lifecycle_s04_failure(
	static fn () => $draft_application->execute(
		new Worksheet_Lifecycle_Command(
			42,
			Worksheet_Lifecycle_Command::ARCHIVE,
			$worksheet_id,
			'stale-s04-command',
			'draft',
			$revision_id,
			0,
			hash( 'sha256', 'stale-s04-key' ),
			'lifecycle:s04:stale',
			$canonical
		)
	),
	Worksheet_Failure::LIFECYCLE_CONFLICT,
	'Stale state, revision, or lock evidence must return the stable lifecycle conflict.'
);

$rest_repository  = new Lifecycle_S04_Repository( 'active', $revision_id, $publication_id, 10 );
$rest_application = new Change_Worksheet_Lifecycle(
	$rest_repository,
	lifecycle_s04_generator( 21 ),
	static fn (): string => '2026-08-21 21:25:00'
);
$worksheets       = new Lifecycle_S04_Worksheets( $revision_id );
$policy           = new Lifecycle_S04_Policy();
$sequence         = 0;
$correlations     = new Correlation_Id_Factory(
	static function () use ( &$sequence ): string {
		return 'lifecycle-s04-rest-' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes        = new Rest_Envelope_Factory();
$permissions      = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$ids              = lifecycle_s04_generator( 31 );
$controller       = new Worksheet_Rest_Controller(
	$permissions,
	new Create_Worksheet( $worksheets, $ids, new Initial_Document_Factory( $ids, $canonical ) ),
	new Read_Worksheets( $worksheets ),
	$canonical,
	$envelopes,
	$correlations,
	null,
	null,
	null,
	null,
	null,
	null,
	$rest_application
);
$controller->register_routes();
$controller->register_routes();
$archive_routes = array_values( array_filter( $GLOBALS['lifecycle_s04_routes'], static fn ( array $entry ): bool => Worksheet_Rest_Controller::ROUTE_ARCHIVE === $entry[1] ) );
$restore_routes = array_values( array_filter( $GLOBALS['lifecycle_s04_routes'], static fn ( array $entry ): bool => Worksheet_Rest_Controller::ROUTE_RESTORE === $entry[1] ) );
lifecycle_s04_same( 1, count( $archive_routes ), 'C08 route must register exactly once.' );
lifecycle_s04_same( 1, count( $restore_routes ), 'C09 route must register exactly once.' );

$archive_request = new Lifecycle_S04_Request(
	array(
		'X-WP-Nonce'      => 'lifecycle-s04-nonce',
		'Idempotency-Key' => 'lifecycle-s04-rest-key',
	),
	array(
		'command_id' => 'archive-rest-s04',
		'expected'   => array(
			'expected_state'        => 'active',
			'expected_revision_id'  => $revision_id,
			'expected_lock_version' => 10,
		),
		'payload'    => array(),
	),
	$worksheet_id
);
$archive_route   = $archive_routes[0][2];
lifecycle_s04_same( true, ( $archive_route['permission_callback'] )( $archive_request ), 'C08 permission must use manage_worksheets and root edit scope.' );
$archive_response = ( $archive_route['callback'] )( $archive_request );
lifecycle_s04_assert( $archive_response instanceof WP_REST_Response, 'C08 must return a common success envelope.' );
lifecycle_s04_same( 200, $archive_response->get_status(), 'C08 success status must be 200.' );
$archive_data = $archive_response->get_data();
lifecycle_s04_same( 'archived', $archive_data['data']['worksheet']['state'], 'C08 REST response state drifted.' );
lifecycle_s04_same( 11, $archive_data['data']['worksheet']['lock_version'], 'C08 REST response lock version drifted.' );
lifecycle_s04_same( 1, $archive_data['meta']['mutation_count'], 'C08 first response must report one mutation.' );
lifecycle_s04_same( Worksheet_Rest_Controller::ACTION_ARCHIVE, $policy->actions[0], 'C08 permission action drifted.' );

$invalid_restore = new Lifecycle_S04_Request(
	array(
		'X-WP-Nonce'      => 'lifecycle-s04-nonce',
		'Idempotency-Key' => 'lifecycle-s04-invalid-key',
	),
	array(
		'command_id' => 'restore-rest-s04',
		'expected'   => array(
			'expected_state'       => 'archived',
			'expected_revision_id' => $revision_id,
		),
		'payload'    => array(),
	),
	$worksheet_id
);
$restore_route   = $restore_routes[0][2];
lifecycle_s04_same( true, ( $restore_route['permission_callback'] )( $invalid_restore ), 'C09 policy must run before body validation.' );
$invalid_response = ( $restore_route['callback'] )( $invalid_restore );
lifecycle_s04_assert( $invalid_response instanceof WP_Error, 'Missing expected_lock_version must use the common problem envelope.' );
lifecycle_s04_same( 422, $invalid_response->get_error_data()['status'], 'Invalid C09 body must return 422.' );

$repository_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Lifecycle_Repository.php' );
$controller_source = file_get_contents( $addon_root . '/src/Worksheet/Authoring/Presentation/Worksheet_Rest_Controller.php' );
lifecycle_s04_assert( is_string( $repository_source ) && is_string( $controller_source ), 'S04 implementation source must be readable.' );
foreach ( array( 'START TRANSACTION', 'FOR UPDATE', 'expected_lock_version', 'archived_at_utc', 'worksheet.archived', 'worksheet.restored', 'assert_stored_exact_keys', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $marker ) {
	lifecycle_s04_assert( str_contains( $repository_source . $controller_source, $marker ), 'S04 lifecycle marker is missing: ' . $marker );
}
lifecycle_s04_assert( ! str_contains( $repository_source, "'UPDATE ' . self::TABLE_REVISIONS" ), 'C08/C09 must not update immutable revisions.' );
lifecycle_s04_assert( ! str_contains( $repository_source, "'UPDATE ' . self::TABLE_PUBLICATIONS" ), 'C08/C09 must not update immutable publications.' );
lifecycle_s04_assert( ! str_contains( $repository_source, 'DELETE FROM' ), 'C08/C09 must preserve all historical sources.' );
foreach ( array( $repository_source, $controller_source ) as $source_code ) {
	lifecycle_s04_assert( ! str_contains( $source_code, '$wpdb->prefix' ), 'C08/C09 must never use a WordPress table prefix.' );
	lifecycle_s04_assert( ! str_contains( $source_code, 'wp_oj_ai_' ), 'C08/C09 must never use a legacy prefixed table.' );
	lifecycle_s04_assert( ! str_contains( $source_code, 'OJ\Platform\\' ), 'C08/C09 must not depend on platform internals.' );
}

echo "G2-IMP-06-S04 passed: C08/C09 root-only archive/restore, lock-version CAS, permission, idempotency, audit, outbox and history preservation.\n";

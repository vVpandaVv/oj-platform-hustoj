<?php
/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Signed_Legacy_Preview_Token;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Approval_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Controller;
use OJAI\Dashboard\Operations\LegacyMigration\Presentation\Legacy_Migration_Approval_Rest_Module;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G5-IMP-07-S02 C70 approved-run creation contract.
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
	 * G5-IMP-07-S02 C70 approved-run creation contract.
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

$GLOBALS['g5_s02_hooks']  = array();
$GLOBALS['g5_s02_routes'] = array();
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g5_s02_hooks'][ $hook ][] = $callback;
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
		$GLOBALS['g5_s02_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s02 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s02_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s02_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Source implements Legacy_Source_Inspector_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Initialize g5 s02 source dependencies and state.
	 *
	 * @param Legacy_Source_Snapshot $snapshot Snapshot.
	 */
	public function __construct( public Legacy_Source_Snapshot $snapshot ) {
	}
	/**
	 * Inspect.
	 *
	 * @param string $source_profile Source profile.
	 * @return Legacy_Source_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( string $source_profile ): Legacy_Source_Snapshot {
		++$this->reads;
		if ( $source_profile !== $this->snapshot->profile() ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED );
		}
		return $this->snapshot;
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Site implements Legacy_Migration_Site_Port_Interface {
	/**
	 * Initialize g5 s02 site dependencies and state.
	 *
	 * @param string $site Site.
	 */
	public function __construct( private string $site = '7' ) {
	}
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return $this->site;
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * After rollback.
	 *
	 * @var null|Closure(G5_S02_Database):void
	 */
	public ?Closure $after_rollback = null;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_import_runs'   => array(),
		'oj_ai_import_items'  => array(),
		'oj_ai_audit_events'  => array(),
		'oj_ai_outbox_events' => array(),
		'oj_ai_worksheets'    => array( array( 'public_id' => 'protected-existing-business-row' ) ),
	);
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		return 'G5S02:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		list( $sql, $values ) = $this->decode( $prepared );
		if ( ! str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key = %s' ) ) {
			throw new RuntimeException( 'Unexpected C70 row query: ' . $sql );
		}
		$key = (string) ( $values[0] ?? '' );
		foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
			if ( $key === (string) $row['idempotency_key'] ) {
				return array(
					'aggregate_public_id' => $row['aggregate_public_id'],
					'event_type'          => $row['event_type'],
					'payload_schema'      => $row['payload_schema'],
					'payload_version'     => $row['payload_version'],
					'payload_json'        => $row['payload_json'],
					'payload_hash'        => $row['payload_hash'],
				);
			}
		}
		return null;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected C70 scalar query: ' . $sql );
		}
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? (string) $last['event_hash'] : null;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( ! array_key_exists( $table, $this->tables ) || $this->fail_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		if ( in_array( $table, array( 'oj_ai_import_runs', 'oj_ai_outbox_events' ), true ) ) {
			$key_name = 'oj_ai_import_runs' === $table ? 'idempotency_key' : 'idempotency_key';
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) $row[ $key_name ] === (string) $data[ $key_name ] ) {
					$this->last_error = 'duplicate idempotency key';
					return false;
				}
			}
		}
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables = $this->backup;
			}
			$this->backup         = null;
			$after_rollback       = $this->after_rollback;
			$this->after_rollback = null;
			if ( $after_rollback instanceof Closure ) {
				$after_rollback( $this );
			}
			$this->last_error = '';
			return 1;
		}
		throw new RuntimeException( 'Unexpected C70 transaction query: ' . $sql );
	}

	/**
	 * Decode g5 s02 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S02:' ) ) {
			throw new RuntimeException( 'C70 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value[0] ) || ! is_array( $value[1] ) ) {
			throw new RuntimeException( 'C70 prepared query is invalid.' );
		}
		return $value;
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Actor implements Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int {
		return 77;
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Nonce implements Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Initialize g5 s02 nonce dependencies and state.
	 *
	 * @param bool $valid Valid.
	 */
	public function __construct( private bool $valid = true ) {
	}
	/**
	 * Verify g5 s02 nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		return $this->valid && 'nonce-g5-imp07-s02' === $nonce;
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Policy implements Access_Policy_Port_Interface {
	/**
	 * Request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $request = null;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->request = $request;
		if ( ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied( 403, 'request_integrity_invalid', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G5-IMP-07-S02 C70 approved-run creation contract.
 */
final class G5_S02_Request {
	/**
	 * Initialize g5 s02 request dependencies and state.
	 *
	 * @param array<string,mixed>  $body Body.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $query Query.
	 */
	public function __construct( private array $body, private array $headers = array(), private array $query = array() ) {
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
		return $this->headers[ $name ] ?? '';
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
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
 * G5 s02 controller.
 *
 * @param Approve_Legacy_Migration $service Service.
 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
 * @param bool                     $nonce_valid Nonce valid.
 * @return array{0:Legacy_Migration_Approval_Rest_Controller,1:G5_S02_Policy}
 */
function g5_s02_controller( Approve_Legacy_Migration $service, Canonical_Json $canonical, bool $nonce_valid = true ): array {
	$policy       = new G5_S02_Policy();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new G5_S02_Actor() ),
		new Rest_Request_Authorizer( $policy, new G5_S02_Nonce( $nonce_valid ), $correlations ),
		$envelopes,
		$correlations
	);
	return array( new Legacy_Migration_Approval_Rest_Controller( $permissions, $service, $envelopes, $correlations, $canonical ), $policy );
}

/**
 * G5 s02 snapshot.
 *
 * @param string $fingerprint_seed Fingerprint seed.
 * @return Legacy_Source_Snapshot
 */
function g5_s02_snapshot( string $fingerprint_seed = 'source-v1' ): Legacy_Source_Snapshot {
	return new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', $fingerprint_seed ),
		array(
			array(
				'logical_table' => 'classes',
				'source_table'  => 'wp_oj_ai_classes',
				'disposition'   => 'mapping',
				'rows'          => 2,
			),
			array(
				'logical_table' => 'worksheet_templates',
				'source_table'  => 'wp_oj_ai_worksheet_templates',
				'disposition'   => 'migrate',
				'rows'          => 3,
			),
		),
		0,
		0,
		array(
			'context' => 0,
			'user'    => 0,
		),
		array(
			array(
				'code'  => 'local_academic_master_not_imported',
				'count' => 2,
			),
		),
		array()
	);
}

$canonical      = new Canonical_Json();
$database       = new G5_S02_Database();
$source         = new G5_S02_Source( g5_s02_snapshot() );
$site           = new G5_S02_Site();
$now            = '2026-08-25 12:00:00';
$token_clock    = static function () use ( &$now ): DateTimeImmutable {
	return new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) );
};
$nonce_counter  = 0;
$token_nonce    = static function () use ( &$nonce_counter ): string {
	++$nonce_counter;
	return str_pad( dechex( $nonce_counter ), 32, '0', STR_PAD_LEFT );
};
$tokens         = new Signed_Legacy_Preview_Token( $canonical, str_repeat( 's', 64 ), $token_clock, $token_nonce );
$preview        = new Preview_Legacy_Migration( $source, $tokens, $site, $canonical );
$repository     = new WordPress_Legacy_Migration_Approval_Repository( $canonical, $database );
$approval_clock = static function () use ( &$now ): string {
	return $now;
};
$approve        = new Approve_Legacy_Migration( $source, $tokens, $site, $repository, new Public_Id_Generator(), $canonical, $approval_clock );

$raw_key        = 'approve-key-0001';
$key_hash       = hash( 'sha256', $raw_key );
$preview_result = $preview->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $key_hash, 'corr-g5-imp07-s02-preview' ) );
$now            = '2026-08-25 12:05:00';
$command        = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $preview_result->preview_token(), $key_hash, 'corr-g5-imp07-s02-approve', $canonical );
$first          = $approve->execute( $command );
g5_s02_same( 'approved', $first->state(), 'C70 did not create an approved run.' );
g5_s02_same( false, $first->replayed(), 'The first C70 request was marked as replay.' );
g5_s02_same( 3, $first->item_total(), 'C70 item total must freeze migration candidates only.' );
g5_s02_same( 1, $first->mutation_count(), 'C70 must report one approved ledger mutation.' );
g5_s02_same( 0, $first->business_mutation_count(), 'C70 must not report a business mutation.' );
g5_s02_same( 0, $first->platform_write_count(), 'C70 must not report a platform write.' );
g5_s02_same( 1, count( $database->tables['oj_ai_import_runs'] ), 'C70 must create exactly one import run.' );
g5_s02_same( 0, count( $database->tables['oj_ai_import_items'] ), 'C70 must not create import items before C62.' );
g5_s02_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C70 must append one audit event.' );
g5_s02_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C70 must append one durable receipt.' );
g5_s02_same( array( array( 'public_id' => 'protected-existing-business-row' ) ), $database->tables['oj_ai_worksheets'], 'C70 changed business data.' );

$run_row = $database->tables['oj_ai_import_runs'][0];
g5_s02_same( 'oj_ai_dashboard_wpdb_prefixed', $run_row['source_kind'], 'C70 source kind drifted.' );
g5_s02_same( Legacy_Source_Profile_0_1_2::VERSION, $run_row['source_version'], 'C70 source version drifted.' );
g5_s02_same( 'oj_ai.legacy_migration_preview', $run_row['preview_schema'], 'C70 preview schema drifted.' );
g5_s02_same( 1, $run_row['preview_version'], 'C70 preview database version drifted.' );
g5_s02_same( null, $run_row['checkpoint_json'], 'C70 created an execution checkpoint before C62.' );
g5_s02_same( 'command:' . hash( 'sha256', '77|legacy.migration.approve|' . Legacy_Source_Profile_0_1_2::PROFILE . '|' . $key_hash ), $run_row['idempotency_key'], 'C70 stored a raw or unstable Idempotency-Key.' );
$stored_preview = json_decode( $run_row['preview_json'], true, 64, JSON_THROW_ON_ERROR );
g5_s02_same( Approve_Legacy_Migration::PREVIEW_PROFILE, $stored_preview['profile'], 'C70 stored preview profile drifted.' );
g5_s02_same( array(), $stored_preview['plan']['mapping_decisions'], 'C70 invented mapping decisions.' );
g5_s02_same( $preview_result->plan()['plan_hash'], $stored_preview['plan']['plan_hash'], 'C70 did not freeze the exact C61 plan.' );
g5_s02_same( hash( 'sha256', $run_row['preview_json'] ), $run_row['preview_hash'], 'C70 preview hash does not cover canonical JSON.' );
g5_s02_same( null, $database->tables['oj_ai_outbox_events'][0]['published_at_utc'], 'C70 durable event was falsely marked published.' );

$counts_after_first = $database->counts();
$replay             = $approve->execute( $command );
g5_s02_same( $first->run_id(), $replay->run_id(), 'Same C70 request did not replay the same run ID.' );
g5_s02_same( true, $replay->replayed(), 'C70 replay flag is false.' );
g5_s02_same( 0, $replay->mutation_count(), 'C70 replay reported a mutation.' );
g5_s02_same( $counts_after_first, $database->counts(), 'C70 replay appended duplicate rows.' );

$second_preview_same_key = $preview->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $key_hash, 'corr-g5-imp07-s02-preview2' ) );
$changed_token_command   = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $second_preview_same_key->preview_token(), $key_hash, 'corr-g5-imp07-s02-changed-token', $canonical );
g5_s02_failure( static fn () => $approve->execute( $changed_token_command ), Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT, 'C70 accepted a changed token under an already-used key.' );
g5_s02_same( $counts_after_first, $database->counts(), 'Changed-token conflict mutated the ledger.' );

$different_key_command = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $preview_result->preview_token(), hash( 'sha256', 'different-key' ), 'corr-g5-imp07-s02-different-key', $canonical );
g5_s02_failure( static fn () => $approve->execute( $different_key_command ), Legacy_Migration_Failure::TOKEN_SCOPE_MISMATCH, 'C70 accepted a preview token with a different Idempotency-Key.' );
g5_s02_same( $counts_after_first, $database->counts(), 'Different-key token reuse mutated the ledger.' );

$source->snapshot = g5_s02_snapshot( 'source-v2' );
$drift_command    = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $preview_result->preview_token(), $key_hash, 'corr-g5-imp07-s02-drift', $canonical );
g5_s02_failure( static fn () => $approve->execute( $drift_command ), Legacy_Migration_Failure::SOURCE_STALE, 'C70 accepted a changed source fingerprint.' );
g5_s02_same( $counts_after_first, $database->counts(), 'Source drift mutated the ledger.' );
$source->snapshot = g5_s02_snapshot();

$expiry_key_hash = hash( 'sha256', 'expiry-key' );
$now             = '2026-08-25 13:00:00';
$expiry_preview  = $preview->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $expiry_key_hash, 'corr-g5-imp07-s02-expiry-preview' ) );
$now             = '2026-08-25 13:30:00';
$expiry_command  = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $expiry_preview->preview_token(), $expiry_key_hash, 'corr-g5-imp07-s02-expired', $canonical );
g5_s02_failure( static fn () => $approve->execute( $expiry_command ), Legacy_Migration_Failure::TOKEN_EXPIRED, 'C70 accepted a token at its expiry boundary.' );
g5_s02_same( $counts_after_first, $database->counts(), 'Expired approval mutated the ledger.' );

$tampered         = substr( $expiry_preview->preview_token(), 0, -1 ) . ( str_ends_with( $expiry_preview->preview_token(), '0' ) ? '1' : '0' );
$tampered_command = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $tampered, $expiry_key_hash, 'corr-g5-imp07-s02-tampered', $canonical );
g5_s02_failure( static fn () => $approve->execute( $tampered_command ), Legacy_Migration_Failure::TOKEN_INVALID, 'C70 accepted a tampered token.' );

$rollback_key         = 'rollback-key';
$rollback_hash        = hash( 'sha256', $rollback_key );
$now                  = '2026-08-25 14:00:00';
$rollback_preview     = $preview->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $rollback_hash, 'corr-g5-imp07-s02-rollback-preview' ) );
$now                  = '2026-08-25 14:05:00';
$rollback_command     = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $rollback_preview->preview_token(), $rollback_hash, 'corr-g5-imp07-s02-rollback', $canonical );
$database->fail_table = 'oj_ai_audit_events';
g5_s02_failure( static fn () => $approve->execute( $rollback_command ), Legacy_Migration_Failure::STORAGE_UNAVAILABLE, 'C70 did not surface an atomic persistence failure.' );
g5_s02_same( $counts_after_first, $database->counts(), 'C70 left a partial run after audit failure.' );
$database->fail_table = null;
$rollback_success     = $approve->execute( $rollback_command );
g5_s02_assert( $rollback_success->run_id() !== $first->run_id(), 'A new C70 key did not create a new run.' );
g5_s02_same( 2, count( $database->tables['oj_ai_import_runs'] ), 'C70 retry after rollback did not create one run.' );
g5_s02_same( 0, count( $database->tables['oj_ai_import_items'] ), 'C70 retry created import items.' );

$race_key     = 'race-key';
$race_hash    = hash( 'sha256', $race_key );
$now          = '2026-08-25 14:10:00';
$race_preview = $preview->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $race_hash, 'corr-g5-imp07-s02-race-preview' ) );
$now          = '2026-08-25 14:15:00';
$race_command = new Approve_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $race_preview->preview_token(), $race_hash, 'corr-g5-imp07-s02-race', $canonical );

$winner_database          = new G5_S02_Database();
$winner_database->tables  = unserialize( serialize( $database->tables ), array( 'allowed_classes' => false ) );
$winner_repository        = new WordPress_Legacy_Migration_Approval_Repository( $canonical, $winner_database );
$winner_approve           = new Approve_Legacy_Migration( $source, $tokens, $site, $winner_repository, new Public_Id_Generator(), $canonical, $approval_clock );
$winner_result            = $winner_approve->execute( $race_command );
$winner_rows              = array(
	'oj_ai_import_runs'   => $winner_database->tables['oj_ai_import_runs'][ array_key_last( $winner_database->tables['oj_ai_import_runs'] ) ],
	'oj_ai_audit_events'  => $winner_database->tables['oj_ai_audit_events'][ array_key_last( $winner_database->tables['oj_ai_audit_events'] ) ],
	'oj_ai_outbox_events' => $winner_database->tables['oj_ai_outbox_events'][ array_key_last( $winner_database->tables['oj_ai_outbox_events'] ) ],
);
$counts_before_race       = $database->counts();
$database->fail_table     = 'oj_ai_import_runs';
$database->after_rollback = static function ( G5_S02_Database $loser ) use ( $winner_rows ): void {
	foreach ( $winner_rows as $table => $row ) {
		$loser->tables[ $table ][] = $row;
	}
};
$race_result              = $approve->execute( $race_command );
$database->fail_table     = null;
g5_s02_same( $winner_result->run_id(), $race_result->run_id(), 'Concurrent C70 loser did not recover the winner run.' );
g5_s02_same( true, $race_result->replayed(), 'Concurrent C70 winner recovery was not marked replay.' );
$counts_after_race = $database->counts();
g5_s02_same( $counts_before_race['oj_ai_import_runs'] + 1, $counts_after_race['oj_ai_import_runs'], 'Concurrent C70 recovery duplicated import runs.' );
g5_s02_same( $counts_before_race['oj_ai_audit_events'] + 1, $counts_after_race['oj_ai_audit_events'], 'Concurrent C70 recovery duplicated audit events.' );
g5_s02_same( $counts_before_race['oj_ai_outbox_events'] + 1, $counts_after_race['oj_ai_outbox_events'], 'Concurrent C70 recovery duplicated outbox receipts.' );
g5_s02_same( $counts_before_race['oj_ai_import_items'], $counts_after_race['oj_ai_import_items'], 'Concurrent C70 recovery created import items.' );
g5_s02_same( $counts_before_race['oj_ai_worksheets'], $counts_after_race['oj_ai_worksheets'], 'Concurrent C70 recovery changed business data.' );

$rest_database               = new G5_S02_Database();
$rest_source                 = new G5_S02_Source( g5_s02_snapshot( 'rest-source' ) );
$rest_now                    = '2026-08-25 15:00:00';
$rest_clock                  = static function () use ( &$rest_now ): DateTimeImmutable {
	return new DateTimeImmutable( $rest_now, new DateTimeZone( 'UTC' ) );
};
$rest_nonce_count            = 100;
$rest_nonce                  = static function () use ( &$rest_nonce_count ): string {
	++$rest_nonce_count;
	return str_pad( dechex( $rest_nonce_count ), 32, '0', STR_PAD_LEFT );
};
$rest_tokens                 = new Signed_Legacy_Preview_Token( $canonical, str_repeat( 'r', 64 ), $rest_clock, $rest_nonce );
$rest_site                   = new G5_S02_Site();
$rest_preview_service        = new Preview_Legacy_Migration( $rest_source, $rest_tokens, $rest_site, $canonical );
$rest_key                    = 'rest-approve-key';
$rest_key_hash               = hash( 'sha256', $rest_key );
$rest_preview                = $rest_preview_service->execute( new Preview_Legacy_Migration_Command( 77, Legacy_Source_Profile_0_1_2::PROFILE, $rest_key_hash, 'corr-g5-imp07-s02-rest-preview' ) );
$rest_now                    = '2026-08-25 15:05:00';
$rest_service                = new Approve_Legacy_Migration(
	$rest_source,
	$rest_tokens,
	$rest_site,
	new WordPress_Legacy_Migration_Approval_Repository( $canonical, $rest_database ),
	new Public_Id_Generator(),
	$canonical,
	static function () use ( &$rest_now ): string {
		return $rest_now;
	}
);
list( $controller, $policy ) = g5_s02_controller( $rest_service, $canonical );
$body                        = array(
	'source'        => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
	'preview_token' => $rest_preview->preview_token(),
);
$headers                     = array(
	'X-WP-Nonce'      => 'nonce-g5-imp07-s02',
	'Idempotency-Key' => $rest_key,
);
$request                     = new G5_S02_Request( $body, $headers );
g5_s02_same( true, $controller->permissions_approve( $request ), 'C70 permission handoff failed.' );
g5_s02_same( Legacy_Migration_Approval_Rest_Controller::ACTION_APPROVE, $policy->request?->action_key(), 'C70 common policy action drifted.' );
g5_s02_same( array( Addon_Capabilities::MANAGE_MIGRATIONS ), $policy->request?->required_capabilities(), 'C70 manage_migrations gate drifted.' );
$response = $controller->approve( $request );
g5_s02_assert( $response instanceof WP_REST_Response, 'C70 did not return the common REST envelope.' );
g5_s02_same( 201, $response->get_status(), 'First C70 REST response must be 201.' );
g5_s02_same( 'approved', $response->get_data()['data']['migration_run']['state'], 'C70 REST state drifted.' );
g5_s02_same( false, $response->get_data()['meta']['replayed'], 'First C70 REST response was marked replay.' );
g5_s02_same( 0, $response->get_data()['meta']['business_mutation_count'], 'C70 REST reported a business mutation.' );
g5_s02_same( 0, $response->get_data()['meta']['platform_write_count'], 'C70 REST reported a platform write.' );

g5_s02_same( true, $controller->permissions_approve( $request ), 'C70 replay permission handoff failed.' );
$replay_response = $controller->approve( $request );
g5_s02_same( 200, $replay_response->get_status(), 'C70 replay REST response must be 200.' );
g5_s02_same( $response->get_data()['data']['migration_run']['id'], $replay_response->get_data()['data']['migration_run']['id'], 'C70 REST replay changed run ID.' );
g5_s02_same( true, $replay_response->get_data()['meta']['replayed'], 'C70 REST replay flag is false.' );

$module = new Legacy_Migration_Approval_Rest_Module( $controller );
$module->register();
$module->register();
g5_s02_same( 1, count( $GLOBALS['g5_s02_hooks']['rest_api_init'] ?? array() ), 'C70 REST module registered twice.' );
$controller->register_routes();
$controller->register_routes();
g5_s02_same( 1, count( $GLOBALS['g5_s02_routes'] ), 'C70 REST route registered twice.' );
g5_s02_same( 'oj-ai/v1', $GLOBALS['g5_s02_routes'][0][0], 'C70 REST namespace drifted.' );
g5_s02_same( '/migration-runs/commands/approve', $GLOBALS['g5_s02_routes'][0][1], 'C70 REST path drifted.' );

$bad_body       = new G5_S02_Request(
	array(
		'source'        => array( 'profile' => Legacy_Source_Profile_0_1_2::PROFILE ),
		'preview_token' => $rest_preview->preview_token(),
		'extra'         => true,
	),
	$headers
);
$bad_body_error = $controller->permissions_approve( $bad_body );
g5_s02_assert( $bad_body_error instanceof WP_Error, 'C70 accepted an over-posted body.' );
g5_s02_same( 422, $bad_body_error->get_error_data()['status'], 'C70 over-posted body status drifted.' );

list( $missing_key_controller ) = g5_s02_controller( $rest_service, $canonical );
$missing_key_error              = $missing_key_controller->permissions_approve( new G5_S02_Request( $body, array( 'X-WP-Nonce' => 'nonce-g5-imp07-s02' ) ) );
g5_s02_assert( $missing_key_error instanceof WP_Error, 'C70 accepted a missing Idempotency-Key.' );
g5_s02_same( 422, $missing_key_error->get_error_data()['status'], 'C70 missing Idempotency-Key status drifted.' );

list( $bad_nonce_controller ) = g5_s02_controller( $rest_service, $canonical, false );
$bad_nonce_error              = $bad_nonce_controller->permissions_approve( new G5_S02_Request( $body, $headers ) );
g5_s02_assert( $bad_nonce_error instanceof WP_Error, 'C70 accepted an invalid standard nonce.' );
g5_s02_same( 403, $bad_nonce_error->get_error_data()['status'], 'C70 invalid nonce status drifted.' );

$runtime_files = array(
	'src/Operations/LegacyMigration/Application/Approve_Legacy_Migration.php',
	'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Approval_Repository.php',
	'src/Operations/LegacyMigration/Presentation/Legacy_Migration_Approval_Rest_Controller.php',
);
foreach ( $runtime_files as $relative ) {
	$source_code = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_s02_assert( is_string( $source_code ), 'C70 runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s02_assert( ! str_contains( $source_code, $forbidden ), 'C70 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}
$repository_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Approval_Repository.php' );
foreach ( array( "'oj_ai_import_runs'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'" ) as $literal_table ) {
	g5_s02_assert( str_contains( (string) $repository_source, $literal_table ), 'C70 lost literal target table: ' . $literal_table );
}
g5_s02_assert( ! str_contains( (string) $repository_source, "'oj_ai_import_items'" ), 'C70 coupled approval to import item creation.' );
$composition_source = file_get_contents( $root . '/src/Core/Composition_Root.php' );
g5_s02_assert( str_contains( (string) $composition_source, 'Legacy_Migration_Approval_Rest_Module' ), 'C70 production REST module is not composed.' );

fwrite( STDOUT, "G5-IMP-07-S02 passed: C70 token/source revalidation, atomic approved run, durable replay, rollback, common policy, and zero platform writes are frozen.\n" );

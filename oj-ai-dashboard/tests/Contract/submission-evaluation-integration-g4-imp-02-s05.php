<?php
/**
 * G4-IMP-02-S05 submission evaluation integration failure freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Control_Repository;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G4-IMP-02-S05 submission evaluation integration failure freeze.
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
			unset( $form );
			return $value;
		}
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s05 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s05_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s05 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s05 id.
 *
 * @return string
 */
function g4_s05_id(): string {
	static $suffix = 9500;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s05_assert( Public_Id::is_valid( $id ), 'S05 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4-IMP-02-S05 submission evaluation integration failure freeze.
 */
final class G4_S05_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Initialize g4 s05 database dependencies and state.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	public function __construct( public ?array $row ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		unset( $values );
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
		unset( $sql, $format );
		return $this->row;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		unset( $sql, $format );
		return array();
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ) {
		unset( $sql );
		return null;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function query( string $sql ): int {
		unset( $sql );
		return 1;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int
	 */
	public function insert( string $table, array $data ): int {
		unset( $table, $data );
		return 1;
	}
}

/**
 * G4 s05 receipt row.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
 * @param string              $aggregate_id Aggregate id.
 * @return array<string,mixed>
 */
function g4_s05_receipt_row( Canonical_Json $canonical, array $payload, string $aggregate_id ): array {
	$json = $canonical->encode( $payload );
	return array(
		'aggregate_public_id' => $aggregate_id,
		'event_type'          => 'submission_evaluation.retry_queued',
		'payload_schema'      => 'oj_ai.submission_evaluation_control_event',
		'payload_version'     => 1,
		'payload_json'        => $json,
		'payload_hash'        => hash( 'sha256', $json ),
	);
}

/**
 * G4 s05 retry record.
 *
 * @param string $command_key Command key.
 * @param string $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_s05_retry_record( string $command_key, string $request_digest ): array {
	return array(
		'actor_user_id'                    => 42,
		'command_id'                       => 'command-g4-s05-retry',
		'command_storage_key'              => $command_key,
		'request_digest'                   => $request_digest,
		'reason'                           => 'Retry after an operator review.',
		'correlation_id'                   => 'correlation-g4-s05',
		'occurred_at_utc'                  => '2026-08-23 13:00:00',
		'context_type'                     => 'class',
		'context_id'                       => 'class-g4-s05',
		'context_revision'                 => 'class-g4-s05-r1',
		'evaluation_public_id'             => g4_s05_id(),
		'submission_public_id'             => g4_s05_id(),
		'submission_revision_public_id'    => g4_s05_id(),
		'submission_revision_hash'         => str_repeat( 'c', 64 ),
		'worksheet_publication_public_id'  => g4_s05_id(),
		'publication_hash'                 => str_repeat( 'd', 64 ),
		'input_hash'                       => str_repeat( 'e', 64 ),
		'expected_evaluation_state'        => 'failed',
		'expected_evaluation_lock_version' => 3,
		'expected_run_public_id'           => g4_s05_id(),
		'expected_run_no'                  => 1,
		'expected_run_state'               => 'failed',
		'expected_run_lock_version'        => 4,
		'expected_latest_result_id'        => null,
		'expected_result_count'            => 0,
		'run_public_id'                    => g4_s05_id(),
		'run_no'                           => 2,
		'run_idempotency_key'              => 'run:' . str_repeat( 'f', 64 ),
		'audit_public_id'                  => g4_s05_id(),
		'outbox_public_id'                 => g4_s05_id(),
		'task_key'                         => Addon_Manifest::TASK_STUDENT_ASSESSMENT,
		'provider_key'                     => 'provider-g4-s05',
		'model_key'                        => 'model-g4-s05',
		'profile_fingerprint'              => str_repeat( '1', 64 ),
		'routing_revision'                 => 'routing-g4-s05-r1',
	);
}

$canonical         = new Canonical_Json();
$request_digest    = str_repeat( 'a', 64 );
$command_key       = 'command:' . str_repeat( 'b', 64 );
$invalid_stored_id = 'invalid-stored-evaluation-id';
$receipt           = array(
	'operation'              => Submission_Evaluation_Control_Result::OPERATION_RETRY,
	'evaluation_id'          => $invalid_stored_id,
	'evaluation_state'       => 'queued',
	'submission_id'          => g4_s05_id(),
	'submission_revision_id' => g4_s05_id(),
	'source_hash'            => str_repeat( 'c', 64 ),
	'publication_id'         => g4_s05_id(),
	'publication_hash'       => str_repeat( 'd', 64 ),
	'run_id'                 => g4_s05_id(),
	'run_no'                 => 2,
	'run_state'              => 'queued',
	'latest_result_id'       => null,
	'occurred_at_utc'        => '2026-08-23 13:00:00',
);
$payload           = array(
	'operation'      => Submission_Evaluation_Control_Result::OPERATION_RETRY,
	'receipt'        => $receipt,
	'request_digest' => $request_digest,
);
$control           = new WordPress_Submission_Evaluation_Control_Repository(
	$canonical,
	new G4_S05_Database( g4_s05_receipt_row( $canonical, $payload, $invalid_stored_id ) )
);
g4_s05_failure(
	static fn () => $control->find_command_receipt( $command_key, $request_digest, Submission_Evaluation_Control_Result::OPERATION_RETRY ),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'Malformed stored C31 receipt must fail closed as stored data.'
);
g4_s05_failure(
	static fn () => $control->retry( g4_s05_retry_record( $command_key, $request_digest ) ),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'Concurrent C31 receipt recovery must not leak a DTO input exception.'
);
g4_s05_invalid(
	static fn () => $control->find_command_receipt( 'bad-command-key', $request_digest, Submission_Evaluation_Control_Result::OPERATION_RETRY ),
	'Malformed caller command key must remain an input error.'
);

$evaluation_id = g4_s05_id();
$query         = new WordPress_Submission_Evaluation_Query(
	$canonical,
	null,
	new G4_S05_Database(
		array(
			'lock_version'     => 'corrupt-lock',
			'state'            => 'review_required',
			'context_type'     => 'class',
			'context_id'       => 'class-g4-s05',
			'context_revision' => 'class-g4-s05-r1',
		)
	)
);
g4_s05_failure(
	static fn () => $query->authorization_snapshot( $evaluation_id, 'class', 'class-g4-s05', 'class-g4-s05-r1' ),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'Malformed stored authorization revision must fail closed.'
);

$production_sources = array(
	$addon_root . '/src/Submission/Assessment/Application/Run_Next_Submission_Evaluation.php',
	$addon_root . '/src/Submission/Assessment/Domain/Submission_Evaluation_Output_Normalizer.php',
	$addon_root . '/src/Submission/Assessment/Domain/Submission_Evaluation_Query_Projector.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Control_Repository.php',
	$addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Query.php',
	$addon_root . '/src/Submission/Assessment/Presentation/Submission_Evaluation_Query_Rest_Controller.php',
);
$sources            = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_sources );
foreach ( $sources as $source ) {
	g4_s05_assert( '' !== $source, 'S05 production source must be readable.' );
	g4_s05_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S05 must not use the WordPress table prefix.' );
	g4_s05_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S05 must not use legacy prefixed table names.' );
	g4_s05_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S05 must not use OJ Platform internals.' );
}

$worker             = $sources[0];
$normalizer         = $sources[1];
$projector          = $sources[2];
$work_repository    = $sources[3];
$control_repository = $sources[4];
$query_controller   = $sources[6];
g4_s05_same( 2, substr_count( $worker, '$this->execution->snapshot( $item->task_key() )' ), 'Worker must retain routing preflight and postflight snapshots.' );
g4_s05_assert( str_contains( $worker, 'routing_changed_during_execute' ), 'Postflight routing drift must discard output.' );
g4_s05_assert( Ojai_Php_Source_Contract::contains( $normalizer, '$candidate_score = 0 === $abstained_count ? $this->decimal_string( $candidate_units ) : null;' ), 'Normalizer must represent any abstention with a null candidate score.' );
g4_s05_assert( str_contains( $projector, '$abstained && null !== $score' ), 'Query projection must reject an abstained non-null score.' );
g4_s05_assert( str_contains( $work_repository, "'candidate_score'" ) && str_contains( $work_repository, '$result->candidate_score()' ), 'Candidate score must be stored without a zero fallback.' );
g4_s05_assert( str_contains( $work_repository, "if ( ! \$this->lock_matches( \$item, 'running' )" ), 'Result commit must retain the running lease CAS gate.' );
g4_s05_assert( str_contains( $control_repository, 'Submission_Evaluation_Failure::STORED_DATA_INVALID' ), 'Stored control corruption must have a stable failure reason.' );
g4_s05_assert( str_contains( $query_controller, 'Rest_Problem::operation( 503' ), 'Stored query failures must map to a safe 503 operation problem.' );

$worker_contract  = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-worker-g4-imp-02-s02.php' );
$control_contract = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-control-g4-imp-02-s04.php' );
foreach ( array(
	'Abstain candidate score must remain SQL NULL.',
	'Routing drift must never commit a candidate result.',
	'Cancellation race must persist zero candidate results.',
) as $marker ) {
	g4_s05_assert( str_contains( $worker_contract, $marker ), 'S05 worker integration marker is missing: ' . $marker );
}
g4_s05_assert( str_contains( $control_contract, 'Late worker output must pass run state, lock, and lease CAS before result insertion.' ), 'S05 cancellation control marker is missing.' );

echo "G4-IMP-02-S05 passed: abstain NULL, postflight drift discard, stored corruption, and cancellation race are frozen.\n";

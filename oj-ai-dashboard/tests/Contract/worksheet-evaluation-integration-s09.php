<?php
/**
 * G2-IMP-05-S09 worksheet self-evaluation integration and failure contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Control_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Control_Repository;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Query;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Review_Repository;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S09 worksheet self-evaluation integration and failure contract.
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

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s09 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s09_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s09 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s09_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Failure $failure ) {
		eval_s09_assert( $reason === $failure->reason(), $message . ': ' . $failure->reason() );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s09 invalid argument.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s09_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s09 id.
 *
 * @return string
 */
function eval_s09_id(): string {
	static $suffix = 9000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s09_assert( Public_Id::is_valid( $id ), 'S09 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G2-IMP-05-S09 worksheet self-evaluation integration and failure contract.
 */
final class Eval_S09_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Initialize eval s09 database dependencies and state.
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
 * Eval s09 receipt row.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
 * @param string              $aggregate_id Aggregate id.
 * @param string              $event Event.
 * @param string              $schema Schema.
 * @return array<string,mixed>
 */
function eval_s09_receipt_row(
	Canonical_Json $canonical,
	array $payload,
	string $aggregate_id,
	string $event,
	string $schema
): array {
	$json = $canonical->encode( $payload );
	return array(
		'aggregate_public_id' => $aggregate_id,
		'event_type'          => $event,
		'payload_schema'      => $schema,
		'payload_version'     => 1,
		'payload_json'        => $json,
		'payload_hash'        => hash( 'sha256', $json ),
	);
}

$canonical         = new Canonical_Json();
$request_digest    = str_repeat( 'a', 64 );
$command_key       = 'command:' . str_repeat( 'b', 64 );
$invalid_stored_id = 'invalid-stored-id';

$control_receipt    = array(
	'operation'             => Worksheet_Evaluation_Control_Result::OPERATION_RETRY,
	'evaluation_id'         => $invalid_stored_id,
	'evaluation_state'      => 'queued',
	'worksheet_revision_id' => eval_s09_id(),
	'source_hash'           => str_repeat( 'c', 64 ),
	'run_id'                => eval_s09_id(),
	'run_no'                => 1,
	'run_state'             => 'queued',
	'occurred_at_utc'       => '2026-08-21 12:00:00',
);
$control_payload    = array(
	'operation'      => Worksheet_Evaluation_Control_Result::OPERATION_RETRY,
	'receipt'        => $control_receipt,
	'request_digest' => $request_digest,
);
$control_database   = new Eval_S09_Database(
	eval_s09_receipt_row(
		$canonical,
		$control_payload,
		$invalid_stored_id,
		'worksheet_evaluation.retry_queued',
		'oj_ai.worksheet_evaluation_control_event'
	)
);
$control_repository = new WordPress_Worksheet_Evaluation_Control_Repository( $canonical, $control_database );
eval_s09_failure(
	static fn () => $control_repository->find_command_receipt(
		$command_key,
		$request_digest,
		Worksheet_Evaluation_Control_Result::OPERATION_RETRY
	),
	Worksheet_Evaluation_Failure::STORED_DATA_INVALID,
	'C11/C12 malformed stored receipt must not be reported as a 422 request error.'
);
eval_s09_invalid_argument(
	static fn () => $control_repository->find_command_receipt(
		'bad-command-key',
		$request_digest,
		Worksheet_Evaluation_Control_Result::OPERATION_RETRY
	),
	'C11/C12 malformed caller storage key must remain an input error.'
);

$review_receipt    = array(
	'evaluation_id'         => $invalid_stored_id,
	'evaluation_state'      => 'review_required',
	'worksheet_revision_id' => eval_s09_id(),
	'source_hash'           => str_repeat( 'd', 64 ),
	'result_id'             => eval_s09_id(),
	'review_id'             => eval_s09_id(),
	'review_no'             => 1,
	'decision'              => 'in_progress',
	'item_decisions'        => array(),
	'occurred_at_utc'       => '2026-08-21 12:01:00',
);
$review_payload    = array(
	'operation'      => 'review',
	'receipt'        => $review_receipt,
	'request_digest' => $request_digest,
);
$review_database   = new Eval_S09_Database(
	eval_s09_receipt_row(
		$canonical,
		$review_payload,
		$invalid_stored_id,
		'worksheet_evaluation.review_progressed',
		'oj_ai.worksheet_evaluation_review_event'
	)
);
$review_repository = new WordPress_Worksheet_Evaluation_Review_Repository( $canonical, $review_database );
eval_s09_failure(
	static fn () => $review_repository->find_command_receipt( $command_key, $request_digest ),
	Worksheet_Evaluation_Failure::STORED_DATA_INVALID,
	'C33 malformed stored receipt must not be reported as a 422 request error.'
);
eval_s09_invalid_argument(
	static fn () => $review_repository->find_command_receipt( 'bad-command-key', $request_digest ),
	'C33 malformed caller storage key must remain an input error.'
);

$review_database->row = array(
	'source_hash'  => str_repeat( 'e', 64 ),
	'result_count' => 1,
	'result_id'    => $invalid_stored_id,
);
eval_s09_failure(
	static fn () => $review_repository->find_owned_snapshot( 42, eval_s09_id() ),
	Worksheet_Evaluation_Failure::STORED_DATA_INVALID,
	'C33 malformed stored snapshot identity must fail closed as stored data.'
);

$query_database   = new Eval_S09_Database(
	array(
		'current_revision' => $invalid_stored_id,
		'state'            => 'review_required',
	)
);
$query_repository = new WordPress_Worksheet_Evaluation_Query( $canonical, null, $query_database );
eval_s09_failure(
	static fn () => $query_repository->authorization_snapshot( 42, eval_s09_id() ),
	Worksheet_Evaluation_Failure::STORED_DATA_INVALID,
	'Object authorization must fail closed when the stored revision identity is malformed.'
);

$sources = array(
	file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Control_Repository.php' ),
	file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Review_Repository.php' ),
	file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Query.php' ),
	file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Application/Run_Next_Worksheet_Evaluation.php' ),
	file_get_contents( $addon_root . '/src/Platform/Worksheet_Evaluation_Workbench_Page_Handler.php' ),
);
foreach ( $sources as $source ) {
	eval_s09_assert( is_string( $source ), 'S09 source file must be readable.' );
	eval_s09_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S09 must not use the WordPress table prefix.' );
	eval_s09_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S09 must not use legacy prefixed table names.' );
	eval_s09_assert( ! str_contains( $source, 'OJ\\Platform\\' ), 'S09 must not use OJ Platform internals.' );
}

$worker_source = (string) $sources[3];
$page_source   = (string) $sources[4];
eval_s09_assert( str_contains( $worker_source, 'external_processing_not_approved' ), 'Worker must deny missing or drifted external processing consent.' );
eval_s09_assert( 2 === substr_count( $worker_source, '$this->execution->snapshot( $item->task_key() )' ), 'Worker must obtain preflight and postflight routing snapshots.' );
eval_s09_assert( str_contains( $worker_source, 'routing_changed_during_execute' ), 'Worker must reject routing drift across the Gateway call.' );
eval_s09_assert( str_contains( $page_source, 'data-consent-recorded' ), 'Evaluation Page must expose only the safe consent-record indicator.' );

echo "G2-IMP-05-S09 integration failure contract passed: stored corruption, caller validation, consent/routing boundary and platform isolation.\n";

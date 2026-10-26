<?php
/**
 * G4-IMP-03-S01 WordPress append-only review storage contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s01 storage assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s01 storage same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_storage_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s01 storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s01_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s01_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s01 storage id.
 *
 * @return string
 */
function g4_s01_storage_id(): string {
	static $suffix = 40100;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s01_storage_assert( Public_Id::is_valid( $id ), 'Storage fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s01 storage fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{snapshot:array<string,mixed>,items:array<int,array<string,mixed>>,evaluation_id:string,result_id:string,result_hash:string}
 */
function g4_s01_storage_fixture( Canonical_Json $canonical ): array {
	$evaluation_id   = g4_s01_storage_id();
	$result_id       = g4_s01_storage_id();
	$run_id          = g4_s01_storage_id();
	$submission_id   = g4_s01_storage_id();
	$revision_id     = g4_s01_storage_id();
	$criterion_id    = g4_s01_storage_id();
	$response_id     = g4_s01_storage_id();
	$binding_key     = str_repeat( 'a', 64 );
	$source_hash     = str_repeat( 'b', 64 );
	$provider_digest = str_repeat( 'c', 64 );
	$normalized_item = array(
		'binding_key'      => $binding_key,
		'criterion_id'     => $criterion_id,
		'response_id'      => $response_id,
		'scope'            => 'response',
		'decision'         => 'score',
		'candidate_points' => '3.0000',
		'maximum_points'   => '5.0000',
		'levels'           => null,
		'confidence'       => '0.8000',
		'rationale'        => 'The response meets the criterion.',
		'abstain_reason'   => null,
		'evidence'         => array(),
		'flags'            => array(),
		'position'         => 0,
	);
	$normalized      = array(
		'schema_id'          => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'schema_version'     => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
		'evaluation_ref'     => 'EV_' . str_repeat( 'A', 32 ),
		'source_hash'        => $source_hash,
		'provider_status'    => 'complete',
		'candidate_score'    => '3.0000',
		'maximum_score'      => '5.0000',
		'overall_confidence' => '0.8000',
		'abstained'          => false,
		'safety_flagged'     => false,
		'summary'            => 'A complete candidate score.',
		'items'              => array( $normalized_item ),
		'limitations'        => array(),
	);
	$normalized_json = $canonical->encode( $normalized );
	$normalized_hash = hash( 'sha256', $normalized_json );
	$result_hash     = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'          => $evaluation_id,
				'normalized_result_hash' => $normalized_hash,
				'provider_output_digest' => $provider_digest,
				'run_id'                 => $run_id,
			)
		)
	);
	$evidence        = array(
		'schema_id'      => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
		'schema_version' => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
		'binding_key'    => $binding_key,
		'decision'       => 'score',
		'rationale'      => 'The response meets the criterion.',
		'abstain_reason' => null,
		'evidence'       => array(),
	);
	$evidence_json   = $canonical->encode( $evidence );
	$evidence_hash   = hash( 'sha256', $evidence_json );
	$item_hash       = hash(
		'sha256',
		$canonical->encode(
			array(
				'candidate_points' => '3.0000',
				'confidence'       => '0.8000',
				'criterion_key'    => $criterion_id,
				'evidence_hash'    => $evidence_hash,
				'flags'            => null,
				'maximum_points'   => '5.0000',
				'position'         => 0,
				'question_key'     => $response_id,
			)
		)
	);
	$snapshot        = array(
		'evaluation_pk'               => '11',
		'evaluation_id'               => $evaluation_id,
		'evaluation_lock_version'     => '4',
		'evaluation_state'            => 'review_required',
		'submission_revision_id'      => $revision_id,
		'submission_revision_hash'    => $source_hash,
		'submission_revision_pk'      => '21',
		'source_revision_hash'        => $source_hash,
		'submission_revision_state'   => 'saved',
		'submission_pk'               => '31',
		'submission_id'               => $submission_id,
		'submission_lock_version'     => '7',
		'submission_state'            => 'submitted',
		'official_revision_public_id' => $revision_id,
		'official_review_id'          => null,
		'official_review_hash'        => null,
		'context_type'                => 'class',
		'context_id'                  => 'class-g4-s01',
		'context_revision'            => 'class-g4-s01-r1',
		'assignment_state'            => 'active',
		'target_state'                => 'active',
		'result_pk'                   => '41',
		'result_id'                   => $result_id,
		'result_hash'                 => $result_hash,
		'candidate_score'             => '3.0000',
		'maximum_score'               => '5.0000',
		'abstained'                   => '0',
		'safety_flagged'              => '0',
		'normalized_result_schema'    => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'normalized_result_version'   => '1',
		'normalized_result_json'      => $normalized_json,
		'normalized_result_hash'      => $normalized_hash,
		'provider_output_digest'      => $provider_digest,
		'result_run_id'               => $run_id,
		'result_run_state'            => 'succeeded',
		'run_result_id'               => $result_id,
		'latest_review_pk'            => null,
		'latest_review_id'            => null,
		'latest_review_hash'          => null,
		'latest_review_no'            => null,
		'latest_review_decision'      => null,
		'latest_official_score'       => null,
		'score_overrides_schema'      => null,
		'score_overrides_version'     => null,
		'score_overrides_json'        => null,
		'score_overrides_hash'        => null,
		'latest_review_reason'        => null,
		'previous_review_id'          => null,
		'latest_review_result_id'     => null,
		'latest_review_result_hash'   => null,
		'result_count'                => '1',
		'review_count'                => '0',
	);
	$items           = array(
		array(
			'item_content_hash' => $item_hash,
			'criterion_key'     => $criterion_id,
			'question_key'      => $response_id,
			'position'          => '0',
			'candidate_points'  => '3.0000',
			'maximum_points'    => '5.0000',
			'confidence'        => '0.8000',
			'evidence_schema'   => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
			'evidence_version'  => '1',
			'evidence_json'     => $evidence_json,
			'evidence_hash'     => $evidence_hash,
			'flags'             => null,
		),
	);
	return compact( 'snapshot', 'items', 'evaluation_id', 'result_id', 'result_hash' );
}

/**
 * G4-IMP-03-S01 WordPress append-only review storage contract.
 */
final class G4_S01_Storage_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Locked snapshot.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $locked_snapshot;
	/**
	 * Items.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items;
	/**
	 * Inserts.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $inserts = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Snapshot reads.
	 *
	 * @var int
	 */
	private int $snapshot_reads = 0;
	/**
	 * Prepared values.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private array $prepared_values = array();
	/**
	 * Drop updates.
	 *
	 * @var bool
	 */
	public bool $drop_updates = false;

	/**
	 * Initialize g4 s01 storage database dependencies and state.
	 *
	 * @param array<string,mixed>            $snapshot Snapshot.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>|null       $locked_snapshot Locked snapshot.
	 */
	public function __construct( array $snapshot, array $items, ?array $locked_snapshot = null ) {
		$this->snapshot        = $snapshot;
		$this->items           = $items;
		$this->locked_snapshot = $locked_snapshot;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->prepared_values[ $sql ] = $values;
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
		unset( $format );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			return null;
		}
		if ( str_contains( $sql, 'SELECT e.id AS evaluation_pk' ) ) {
			++$this->snapshot_reads;
			return $this->snapshot_reads > 1 && is_array( $this->locked_snapshot ) ? $this->locked_snapshot : $this->snapshot;
		}
		if ( str_contains( $sql, 'SELECT e.state AS evaluation_state' ) ) {
			$review = $this->inserts['oj_ai_submission_evaluation_reviews'][0] ?? null;
			if ( ! is_array( $review ) ) {
				return null;
			}
			return array(
				'evaluation_state'        => $this->snapshot['evaluation_state'],
				'evaluation_lock_version' => $this->snapshot['evaluation_lock_version'],
				'submission_lock_version' => $this->snapshot['submission_lock_version'],
				'current_release_id'      => $this->snapshot['current_release_id'] ?? null,
				'current_release_hash'    => $this->snapshot['current_release_hash'] ?? null,
				'review_no'               => $review['review_no'],
				'decision'                => $review['decision'],
				'content_hash'            => $review['content_hash'],
				'official_score'          => $review['official_score'],
				'score_overrides_hash'    => $review['score_overrides_hash'],
				'result_id'               => $this->snapshot['result_id'],
				'result_hash'             => $this->snapshot['result_hash'],
				'official_review_id'      => $this->snapshot['official_review_id'],
				'official_review_hash'    => $this->snapshot['official_review_hash'],
				'review_count'            => '1',
			);
		}
		return null;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		unset( $format );
		return str_contains( $sql, 'FROM oj_ai_submission_evaluation_items' ) ? $this->items : array();
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
		$this->queries[] = $sql;
		$values          = $this->prepared_values[ $sql ] ?? array();
		if ( ! $this->drop_updates && str_starts_with( $sql, 'UPDATE oj_ai_submission_evaluations SET' ) ) {
			$this->snapshot['evaluation_state'] = $values[0];
			++$this->snapshot['evaluation_lock_version'];
		}
		if ( ! $this->drop_updates && str_starts_with( $sql, 'UPDATE oj_ai_submissions SET' ) ) {
			$this->snapshot['official_review_id']   = $values[0];
			$this->snapshot['official_review_hash'] = $values[1];
			++$this->snapshot['submission_lock_version'];
		}
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
		$this->inserts[ $table ][] = $data;
		return 1;
	}
}

/**
 * G4 s01 storage command.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array          $fixture Fixture.
 * @return Review_Submission_Evaluation_Command
 */
function g4_s01_storage_command( Canonical_Json $canonical, array $fixture ): Review_Submission_Evaluation_Command {
	return new Review_Submission_Evaluation_Command(
		42,
		$fixture['evaluation_id'],
		'command-g4-s01-storage',
		$fixture['result_id'],
		$fixture['result_hash'],
		null,
		null,
		0,
		'accepted',
		array(),
		null,
		str_repeat( 'f', 64 ),
		'correlation-g4-s01-storage',
		'4',
		'review_required',
		'context_staff',
		$canonical
	);
}

$canonical   = new Canonical_Json();
$fixture     = g4_s01_storage_fixture( $canonical );
$database    = new G4_S01_Storage_Database( $fixture['snapshot'], $fixture['items'] );
$repository  = new WordPress_Submission_Evaluation_Review_Repository( $canonical, $database );
$application = new Review_Submission_Evaluation(
	$repository,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 16:00:00'
);
$result      = $application->execute( g4_s01_storage_command( $canonical, $fixture ) );
g4_s01_storage_same( '3.0000', $result->official_score(), 'Concrete repository official score drifted.' );
g4_s01_storage_same( 1, count( $database->inserts['oj_ai_submission_evaluation_reviews'] ?? array() ), 'Concrete repository must append one review.' );
g4_s01_storage_same( 1, count( $database->inserts['oj_ai_audit_events'] ?? array() ), 'Concrete repository must append one audit event.' );
g4_s01_storage_same( 1, count( $database->inserts['oj_ai_outbox_events'] ?? array() ), 'Concrete repository must append one outbox event.' );
$review_row = $database->inserts['oj_ai_submission_evaluation_reviews'][0];
g4_s01_storage_same( null, $review_row['previous_review_id'], 'First review must not invent a previous review.' );
g4_s01_storage_same( Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA, $review_row['score_overrides_schema'], 'Stored score profile schema drifted.' );
$outbox_row     = $database->inserts['oj_ai_outbox_events'][0];
$outbox_payload = json_decode( $outbox_row['payload_json'], true, 128, JSON_THROW_ON_ERROR );
g4_s01_storage_assert( ! array_key_exists( 'official_score', $outbox_payload ) && ! array_key_exists( 'score_decisions', $outbox_payload ), 'Outbox must not expose score content.' );
g4_s01_storage_assert( in_array( 'START TRANSACTION', $database->queries, true ) && in_array( 'COMMIT', $database->queries, true ), 'Concrete append must commit one transaction.' );
g4_s01_storage_assert( ! in_array( 'ROLLBACK', $database->queries, true ), 'Successful concrete append must not roll back.' );

$stale_snapshot                         = $fixture['snapshot'];
$stale_snapshot['official_review_id']   = g4_s01_storage_id();
$stale_snapshot['official_review_hash'] = str_repeat( '9', 64 );
$race_database                          = new G4_S01_Storage_Database( $fixture['snapshot'], $fixture['items'], $stale_snapshot );
$race_repository                        = new WordPress_Submission_Evaluation_Review_Repository( $canonical, $race_database );
$race_application                       = new Review_Submission_Evaluation(
	$race_repository,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 16:00:00'
);
g4_s01_storage_failure(
	static fn () => $race_application->execute( g4_s01_storage_command( $canonical, $fixture ) ),
	Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
	'Official pointer change between preflight and locked snapshot must roll back.'
);
g4_s01_storage_same( 0, count( $race_database->inserts['oj_ai_submission_evaluation_reviews'] ?? array() ), 'Stale pointer race must append no review.' );
g4_s01_storage_assert( in_array( 'ROLLBACK', $race_database->queries, true ), 'Stale pointer race must roll back.' );

$broken_database               = new G4_S01_Storage_Database( $fixture['snapshot'], $fixture['items'] );
$broken_database->drop_updates = true;
$broken_application            = new Review_Submission_Evaluation(
	new WordPress_Submission_Evaluation_Review_Repository( $canonical, $broken_database ),
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 16:00:00'
);
g4_s01_storage_failure(
	static fn () => $broken_application->execute( g4_s01_storage_command( $canonical, $fixture ) ),
	Submission_Evaluation_Failure::STORAGE_UNAVAILABLE,
	'A successful update count without persisted pointers and lock versions must fail the postcondition.'
);
g4_s01_storage_assert( in_array( 'ROLLBACK', $broken_database->queries, true ), 'Failed postcondition must roll back.' );

fwrite( STDOUT, "G4-IMP-03-S01 submission evaluation review storage contract passed.\n" );

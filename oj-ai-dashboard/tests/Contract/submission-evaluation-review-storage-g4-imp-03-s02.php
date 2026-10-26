<?php
/**
 * G4-IMP-03-S02 official review pointer and stale release transaction contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s02 storage assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s02 storage same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_storage_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s02 storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s02_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s02 storage id.
 *
 * @return string
 */
function g4_s02_storage_id(): string {
	static $suffix = 50100;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s02_storage_assert( Public_Id::is_valid( $id ), 'S02 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s02 release hash.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $evaluation_id Evaluation id.
 * @param string         $review_id Review id.
 * @param string|null    $feedback_id Feedback id.
 * @param int            $release_no Release no.
 * @param string         $state State.
 * @param string|null    $visible_score Visible score.
 * @param string         $projection_schema Projection schema.
 * @param int            $projection_version Projection version.
 * @param string         $projection_hash Projection hash.
 * @param string|null    $previous_release_id Previous release id.
 * @param string|null    $reason_code Reason code.
 * @param string|null    $reason_note Reason note.
 * @param string         $released_at Released at.
 * @return string
 */
function g4_s02_release_hash(
	Canonical_Json $canonical,
	string $evaluation_id,
	string $review_id,
	?string $feedback_id,
	int $release_no,
	string $state,
	?string $visible_score,
	string $projection_schema,
	int $projection_version,
	string $projection_hash,
	?string $previous_release_id,
	?string $reason_code,
	?string $reason_note,
	string $released_at
): string {
	return hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'              => $evaluation_id,
				'feedback_id'                => $feedback_id,
				'previous_release_id'        => $previous_release_id,
				'release_no'                 => $release_no,
				'release_reason_code'        => $reason_code,
				'release_reason_note'        => $reason_note,
				'released_at_utc'            => $released_at,
				'review_id'                  => $review_id,
				'state'                      => $state,
				'student_projection_hash'    => $projection_hash,
				'student_projection_schema'  => $projection_schema,
				'student_projection_version' => $projection_version,
				'visible_score'              => $visible_score,
			)
		)
	);
}

/**
 * G4 s02 storage fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param bool           $visible_release Visible release.
 * @return array{snapshot:array<string,mixed>,items:array<int,array<string,mixed>>,evaluation_id:string,result_id:string,result_hash:string}
 */
function g4_s02_storage_fixture( Canonical_Json $canonical, bool $visible_release, bool $manual = false ): array {
	$evaluation_id   = g4_s02_storage_id();
	$result_id       = g4_s02_storage_id();
	$run_id          = g4_s02_storage_id();
	$submission_id   = g4_s02_storage_id();
	$revision_id     = g4_s02_storage_id();
	$criterion_id    = g4_s02_storage_id();
	$response_id     = g4_s02_storage_id();
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
	if ( $manual ) {
		$normalized_item = array_replace( $normalized_item, array(
			'decision' => 'abstain', 'candidate_points' => null, 'confidence' => '0.0000', 'rationale' => null, 'abstain_reason' => 'not_requested',
			'levels' => array( array( 'label' => 'Complete', 'level_key' => g4_s02_storage_id(), 'points' => '5.0000', 'position' => 0 ), array( 'label' => 'Absent', 'level_key' => g4_s02_storage_id(), 'points' => '0.0000', 'position' => 1 ) ),
		) );
	}
	$normalized      = array(
		'schema_id'          => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
		'schema_version'     => Submission_Evaluation_Output_Normalizer::RESULT_VERSION,
		'evaluation_ref'     => 'EV_' . str_repeat( 'A', 32 ),
		'source_hash'        => $source_hash,
		'provider_status'    => $manual ? 'not_requested' : 'complete',
		'candidate_score'    => $normalized_item['candidate_points'],
		'maximum_score'      => '5.0000',
		'overall_confidence' => $normalized_item['confidence'],
		'abstained'          => $manual,
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
		'decision'       => $normalized_item['decision'],
		'rationale'      => $normalized_item['rationale'],
		'abstain_reason' => $normalized_item['abstain_reason'],
		'evidence'       => array(),
	);
	$evidence_json   = $canonical->encode( $evidence );
	$evidence_hash   = hash( 'sha256', $evidence_json );
	$item_hash       = hash(
		'sha256',
		$canonical->encode(
			array(
				'candidate_points' => $normalized_item['candidate_points'],
				'confidence'       => $normalized_item['confidence'],
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
		'current_release_id'          => null,
		'current_release_hash'        => null,
		'context_type'                => 'class',
		'context_id'                  => 'class-g4-s02',
		'context_revision'            => 'class-g4-s02-r1',
		'assignment_state'            => 'active',
		'target_state'                => 'active',
		'result_pk'                   => '41',
		'result_id'                   => $result_id,
		'result_hash'                 => $result_hash,
		'candidate_score'             => $normalized_item['candidate_points'],
		'maximum_score'               => '5.0000',
		'abstained'                   => $manual ? '1' : '0',
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
	if ( $visible_release ) {
		$old_review_id         = g4_s02_storage_id();
		$release_id            = g4_s02_storage_id();
		$release_evaluation_id = g4_s02_storage_id();
		$projection_schema     = 'oj_ai.student_release_projection';
		$projection            = array(
			'schema_id'      => $projection_schema,
			'schema_version' => '1.0.0',
			'visible_score'  => '3.0000',
		);
		$projection_json       = $canonical->encode( $projection );
		$projection_hash       = hash( 'sha256', $projection_json );
		$release_hash          = g4_s02_release_hash(
			$canonical,
			$release_evaluation_id,
			$old_review_id,
			null,
			1,
			'released',
			'3.0000',
			$projection_schema,
			1,
			$projection_hash,
			null,
			null,
			null,
			'2026-08-22 12:00:00'
		);
		$snapshot              = array_replace(
			$snapshot,
			array(
				'official_review_id'                     => $old_review_id,
				'official_review_hash'                   => str_repeat( '8', 64 ),
				'current_release_id'                     => $release_id,
				'current_release_hash'                   => $release_hash,
				'current_release_pk'                     => '71',
				'joined_current_release_id'              => $release_id,
				'joined_current_release_hash'            => $release_hash,
				'current_release_evaluation_pk'          => '61',
				'current_release_evaluation_id'          => $release_evaluation_id,
				'current_release_submission_pk'          => '31',
				'current_release_review_pk'              => '62',
				'current_release_review_id'              => $old_review_id,
				'current_release_review_evaluation_pk'   => '61',
				'current_release_feedback_pk'            => null,
				'current_release_feedback_id'            => null,
				'current_release_feedback_evaluation_pk' => null,
				'current_release_feedback_review_pk'     => null,
				'current_release_no'                     => '1',
				'current_release_state'                  => 'released',
				'current_release_visible_score'          => '3.0000',
				'current_student_projection_schema'      => $projection_schema,
				'current_student_projection_version'     => '1',
				'current_student_projection_json'        => $projection_json,
				'current_student_projection_hash'        => $projection_hash,
				'current_release_previous_id'            => null,
				'current_release_reason_code'            => null,
				'current_release_reason_note'            => null,
				'current_released_at_utc'                => '2026-08-22 12:00:00',
				'current_release_count'                  => '1',
			)
		);
	}
	$items = array(
		array(
			'item_content_hash' => $item_hash,
			'criterion_key'     => $criterion_id,
			'question_key'      => $response_id,
			'position'          => '0',
			'candidate_points'  => $normalized_item['candidate_points'],
			'maximum_points'    => '5.0000',
			'confidence'        => $normalized_item['confidence'],
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
 * G4-IMP-03-S02 official review pointer and stale release transaction contract.
 */
final class G4_S02_Storage_Database {
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
	 * Fail submission cas.
	 *
	 * @var bool
	 */
	public bool $fail_submission_cas = false;
	/**
	 * Fail evaluation cas.
	 *
	 * @var bool
	 */
	public bool $fail_evaluation_cas = false;
	/**
	 * Transaction snapshot.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $transaction_snapshot = null;
	/**
	 * Transaction items.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	public ?array $transaction_items = null;
	/**
	 * Whether the official review belongs to this evaluation.
	 *
	 * @var bool
	 */
	public bool $official_review_owned = true;
	/**
	 * Backup.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $backup = null;
	/**
	 * In transaction.
	 *
	 * @var bool
	 */
	private bool $in_transaction = false;

	/**
	 * Initialize g4 s02 storage database dependencies and state.
	 *
	 * @param array<string,mixed>            $snapshot Snapshot.
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	public function __construct( array $snapshot, array $items ) {
		$this->snapshot = $snapshot;
		$this->items    = $items;
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
		unset( $format );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			return null;
		}
		if ( str_starts_with( $sql, 'SELECT public_id FROM oj_ai_submission_evaluation_reviews' ) ) {
			return $this->official_review_owned ? array( 'public_id' => $this->snapshot['official_review_id'] ) : null;
		}
		if ( str_contains( $sql, 'SELECT e.state AS evaluation_state' ) ) {
			$reviews = $this->inserts['oj_ai_submission_evaluation_reviews'] ?? array();
			$review  = $reviews ? $reviews[ array_key_last( $reviews ) ] : null;
			if ( ! is_array( $review ) ) {
				return null;
			}
			return array(
				'evaluation_state'        => $this->snapshot['evaluation_state'],
				'evaluation_lock_version' => $this->snapshot['evaluation_lock_version'],
				'review_no'               => $review['review_no'],
				'decision'                => $review['decision'],
				'content_hash'            => $review['content_hash'],
				'official_score'          => $review['official_score'],
				'score_overrides_hash'    => $review['score_overrides_hash'],
				'result_id'               => $this->snapshot['result_id'],
				'result_hash'             => $this->snapshot['result_hash'],
				'official_review_id'      => $this->snapshot['official_review_id'],
				'official_review_hash'    => $this->snapshot['official_review_hash'],
				'current_release_id'      => $this->snapshot['current_release_id'],
				'current_release_hash'    => $this->snapshot['current_release_hash'],
				'submission_lock_version' => $this->snapshot['submission_lock_version'],
				'review_count'            => (string) count( $this->inserts['oj_ai_submission_evaluation_reviews'] ?? array() ),
			);
		}
		if ( str_contains( $sql, 'SELECT release_row.release_no' ) ) {
			$release = $this->inserts['oj_ai_submission_release_revisions'][0] ?? null;
			if ( ! is_array( $release ) ) {
				return null;
			}
			return array(
				'release_no'                 => $release['release_no'],
				'state'                      => $release['state'],
				'visible_score'              => $release['visible_score'],
				'student_projection_schema'  => $release['student_projection_schema'],
				'student_projection_version' => $release['student_projection_version'],
				'student_projection_hash'    => $release['student_projection_hash'],
				'release_reason_code'        => $release['release_reason_code'],
				'release_reason_note'        => $release['release_reason_note'],
				'released_at_utc'            => $release['released_at_utc'],
				'previous_release_id'        => $this->backup['snapshot']['current_release_id'] ?? null,
				'release_evaluation_id'      => $this->backup['snapshot']['current_release_evaluation_id'] ?? null,
				'release_review_id'          => $this->backup['snapshot']['current_release_review_id'] ?? null,
				'release_feedback_id'        => $this->backup['snapshot']['current_release_feedback_id'] ?? null,
			);
		}
		if ( str_contains( $sql, 'SELECT e.id AS evaluation_pk' ) ) {
			$snapshot = $this->in_transaction && is_array( $this->transaction_snapshot )
				? $this->transaction_snapshot
				: $this->snapshot;
			$reviews  = $this->inserts['oj_ai_submission_evaluation_reviews'] ?? array();
			if ( $reviews ) {
				$last     = $reviews[ array_key_last( $reviews ) ];
				$snapshot = array_replace(
					$snapshot,
					array(
						'latest_review_pk'          => count( $reviews ),
						'latest_review_id'          => $last['public_id'],
						'latest_review_hash'        => $last['content_hash'],
						'latest_review_no'          => $last['review_no'],
						'latest_review_decision'    => $last['decision'],
						'latest_official_score'     => $last['official_score'],
						'score_overrides_schema'    => $last['score_overrides_schema'],
						'score_overrides_version'   => $last['score_overrides_version'],
						'score_overrides_json'      => $last['score_overrides_json'],
						'score_overrides_hash'      => $last['score_overrides_hash'],
						'latest_review_reason'      => $last['reason'],
						'previous_review_id'        => count( $reviews ) > 1 ? $reviews[ count( $reviews ) - 2 ]['public_id'] : null,
						'latest_review_result_id'   => $snapshot['result_id'],
						'latest_review_result_hash' => $snapshot['result_hash'],
						'review_count'              => count( $reviews ),
					)
				);
			}
			return $snapshot;
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
		if ( ! str_contains( $sql, 'FROM oj_ai_submission_evaluation_items' ) ) {
			return array();
		}
		return $this->in_transaction && is_array( $this->transaction_items )
			? $this->transaction_items
			: $this->items;
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
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup         = array(
				'snapshot' => $this->snapshot,
				'inserts'  => $this->inserts,
			);
			$this->in_transaction = true;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->snapshot = $this->backup['snapshot'];
				$this->inserts  = $this->backup['inserts'];
			}
			$this->backup         = null;
			$this->in_transaction = false;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup         = null;
			$this->in_transaction = false;
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_submission_evaluations' ) ) {
			if ( $this->fail_evaluation_cas ) {
				return 0;
			}
			$reviews                                   = $this->inserts['oj_ai_submission_evaluation_reviews'];
			$this->snapshot['evaluation_state']        = 'withdrawn' === $reviews[ array_key_last( $reviews ) ]['decision'] ? 'review_required' : 'reviewed';
			$this->snapshot['evaluation_lock_version'] = (string) ( (int) $this->snapshot['evaluation_lock_version'] + 1 );
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_submissions' ) ) {
			if ( $this->fail_submission_cas ) {
				return 0;
			}
			$reviews = $this->inserts['oj_ai_submission_evaluation_reviews'] ?? array();
			$review  = $reviews ? $reviews[ array_key_last( $reviews ) ] : null;
			if ( ! is_array( $review ) ) {
				return 0;
			}
			$this->snapshot['official_review_id']   = 'withdrawn' === $review['decision'] ? null : $review['public_id'];
			$this->snapshot['official_review_hash'] = 'withdrawn' === $review['decision'] ? null : $review['content_hash'];
			$release                                = $this->inserts['oj_ai_submission_release_revisions'][0] ?? null;
			if ( is_array( $release ) ) {
				$this->snapshot['current_release_id']   = $release['public_id'];
				$this->snapshot['current_release_hash'] = $release['content_hash'];
			}
			$this->snapshot['submission_lock_version'] = (string) ( (int) $this->snapshot['submission_lock_version'] + 1 );
			return 1;
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
 * G4 s02 storage command.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array          $fixture Fixture.
 * @param string         $decision Decision.
 * @param string         $command_id Command identifier used for idempotency.
 * @param string|null    $reason Reason.
 * @return Review_Submission_Evaluation_Command
 */
function g4_s02_storage_command(
	Canonical_Json $canonical,
	array $fixture,
	string $decision,
	string $command_id,
	?string $reason = null,
	array $items = array()
): Review_Submission_Evaluation_Command {
	$snapshot = $fixture['snapshot'];
	return new Review_Submission_Evaluation_Command(
		42,
		$fixture['evaluation_id'],
		$command_id,
		$fixture['result_id'],
		$fixture['result_hash'],
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		(int) ( $snapshot['review_count'] ?? 0 ),
		$decision,
		$items,
		$reason,
		str_repeat( 'f', 64 ),
		'correlation-g4-s02-storage',
		(string) $snapshot['evaluation_lock_version'],
		$snapshot['evaluation_state'],
		'context_staff',
		$canonical
	);
}

/**
 * G4 s02 storage application.
 *
 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
 * @param G4_S02_Storage_Database $database Database.
 * @return Review_Submission_Evaluation
 */
function g4_s02_storage_application(
	Canonical_Json $canonical,
	G4_S02_Storage_Database $database
): Review_Submission_Evaluation {
	return new Review_Submission_Evaluation(
		new WordPress_Submission_Evaluation_Review_Repository( $canonical, $database ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => '2026-08-23 18:00:00'
	);
}

if ( defined( 'OJAI_G4_S02_FIXTURES_ONLY' ) ) {
	return;
}
$canonical = new Canonical_Json();

$first_fixture  = g4_s02_storage_fixture( $canonical, false );
$first_database = new G4_S02_Storage_Database( $first_fixture['snapshot'], $first_fixture['items'] );
$first_result   = g4_s02_storage_application( $canonical, $first_database )->execute(
	g4_s02_storage_command( $canonical, $first_fixture, 'accepted', 'command-g4-s02-first' )
);
g4_s02_storage_same( true, $first_result->official_pointer_advanced(), 'Accepted review must advance the official pointer.' );
g4_s02_storage_same( $first_result->review_id(), $first_result->official_review_id(), 'First official review pointer must target the appended review.' );
g4_s02_storage_same( 'reviewed', $first_database->snapshot['evaluation_state'], 'Accepted review must move the evaluation root to reviewed.' );
g4_s02_storage_same( '5', $first_database->snapshot['evaluation_lock_version'], 'Accepted review must increment the evaluation CAS version.' );
g4_s02_storage_same( '8', $first_database->snapshot['submission_lock_version'], 'Accepted review must increment the submission CAS version.' );
g4_s02_storage_same( 0, count( $first_database->inserts['oj_ai_submission_release_revisions'] ?? array() ), 'First official review without a release must not invent one.' );

$release_fixture  = g4_s02_storage_fixture( $canonical, true );
$release_database = new G4_S02_Storage_Database( $release_fixture['snapshot'], $release_fixture['items'] );
$old_release_id   = $release_fixture['snapshot']['current_release_id'];
$replacement      = g4_s02_storage_application( $canonical, $release_database )->execute(
	g4_s02_storage_command( $canonical, $release_fixture, 'accepted', 'command-g4-s02-replace' )
);
$stale_rows       = $release_database->inserts['oj_ai_submission_release_revisions'] ?? array();
g4_s02_storage_same( 1, count( $stale_rows ), 'Replacing a visible official result must append one stale release.' );
g4_s02_storage_same( 'stale', $stale_rows[0]['state'], 'Replacement release state must be stale.' );
g4_s02_storage_same( 2, $stale_rows[0]['release_no'], 'Stale release must continue the immutable release chain.' );
g4_s02_storage_same( 71, $stale_rows[0]['previous_release_id'], 'Stale release must point to the previous release row.' );
g4_s02_storage_same( $stale_rows[0]['public_id'], $release_database->snapshot['current_release_id'], 'Submission current release pointer must target the stale revision.' );
g4_s02_storage_same( $replacement->review_id(), $release_database->snapshot['official_review_id'], 'Replacement official pointer must target the new review.' );
g4_s02_storage_assert( $old_release_id !== $release_database->snapshot['current_release_id'], 'Replacement must not leave the old visible release current.' );

$reject_fixture  = g4_s02_storage_fixture( $canonical, true );
$reject_database = new G4_S02_Storage_Database( $reject_fixture['snapshot'], $reject_fixture['items'] );
$reject_before   = $reject_database->snapshot;
$rejected        = g4_s02_storage_application( $canonical, $reject_database )->execute(
	g4_s02_storage_command( $canonical, $reject_fixture, 'rejected', 'command-g4-s02-reject', 'The candidate result is not reliable.' )
);
g4_s02_storage_same( false, $rejected->official_pointer_advanced(), 'Rejected review must preserve the official pointer.' );
g4_s02_storage_same( $reject_before['official_review_id'], $reject_database->snapshot['official_review_id'], 'Rejected review must not move the official review pointer.' );
g4_s02_storage_same( $reject_before['current_release_id'], $reject_database->snapshot['current_release_id'], 'Rejected review must not move the release pointer.' );
g4_s02_storage_same( $reject_before['evaluation_lock_version'], $reject_database->snapshot['evaluation_lock_version'], 'Rejected review must not mutate the evaluation root.' );
g4_s02_storage_same( $reject_before['submission_lock_version'], $reject_database->snapshot['submission_lock_version'], 'Rejected review must not mutate the submission root.' );
g4_s02_storage_same( 0, count( $reject_database->inserts['oj_ai_submission_release_revisions'] ?? array() ), 'Rejected review must append no stale release.' );

$race_fixture                       = g4_s02_storage_fixture( $canonical, true );
$race_database                      = new G4_S02_Storage_Database( $race_fixture['snapshot'], $race_fixture['items'] );
$race_before                        = $race_database->snapshot;
$race_database->fail_submission_cas = true;
g4_s02_storage_failure(
	static fn () => g4_s02_storage_application( $canonical, $race_database )->execute(
		g4_s02_storage_command( $canonical, $race_fixture, 'accepted', 'command-g4-s02-race' )
	),
	Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
	'Submission pointer CAS failure must surface as review_source_stale.'
);
g4_s02_storage_same( $race_before, $race_database->snapshot, 'CAS rollback must restore both mutable roots.' );
g4_s02_storage_same( array(), $race_database->inserts, 'CAS rollback must leave review, release, audit, and outbox commit count at zero.' );
g4_s02_storage_assert( in_array( 'ROLLBACK', $race_database->queries, true ), 'CAS failure must roll back the transaction.' );
g4_s02_storage_assert( ! in_array( 'COMMIT', $race_database->queries, true ), 'CAS failure must not commit.' );

// One immutable approval -> withdrawal -> approval chain, including locked writes.
$withdraw_fixture                             = $first_fixture;
$withdraw_fixture['snapshot']                 = $first_database->snapshot;
$withdraw_fixture['snapshot']['review_count'] = 1;
$approved_rows                                = $first_database->inserts['oj_ai_submission_evaluation_reviews'];
g4_s02_storage_failure(
	static fn () => g4_s02_storage_application( $canonical, $first_database )->execute(
		g4_s02_storage_command( $canonical, $withdraw_fixture, 'accepted', 'command-duplicate-approval' )
	),
	Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
	'An approved score must be withdrawn before another review.'
);
g4_s02_storage_same( $approved_rows, $first_database->inserts['oj_ai_submission_evaluation_reviews'], 'Blocked duplicate approval appends no review.' );
$withdrawn = g4_s02_storage_application( $canonical, $first_database )->execute(
	g4_s02_storage_command( $canonical, $withdraw_fixture, 'withdrawn', 'command-withdraw-approval', 'Correct the score after checking evidence.' )
);
g4_s02_storage_same( null, $withdrawn->official_review_id(), 'Withdrawal clears the official pointer.' );
g4_s02_storage_same( true, $withdrawn->official_pointer_advanced(), 'Withdrawal records the official pointer change.' );
g4_s02_storage_same( 'review_required', $first_database->snapshot['evaluation_state'], 'Withdrawal reopens score editing.' );
g4_s02_storage_same( $approved_rows[0], $first_database->inserts['oj_ai_submission_evaluation_reviews'][0], 'Withdrawal preserves the original immutable approval.' );
$projected_rows = array();
foreach ( $first_database->inserts['oj_ai_submission_evaluation_reviews'] as $index => $review_row ) {
	$projected_rows[] = array(
		'review_id'               => $review_row['public_id'],
		'review_content_hash'     => $review_row['content_hash'],
		'review_no'               => $review_row['review_no'],
		'previous_review_id'      => 0 === $index ? null : $approved_rows[0]['public_id'],
		'review_result_id'        => $first_fixture['result_id'],
		'review_decision'         => $review_row['decision'],
		'official_score'          => $review_row['official_score'],
		'score_overrides_schema'  => $review_row['score_overrides_schema'],
		'score_overrides_version' => $review_row['score_overrides_version'],
		'score_overrides_json'    => $review_row['score_overrides_json'],
		'score_overrides_hash'    => $review_row['score_overrides_hash'],
		'review_reason'           => $review_row['reason'],
		'review_created_at_utc'   => $review_row['created_at_utc'],
	);
}
$projection = ( new ReflectionMethod( Submission_Evaluation_Query_Projector::class, 'reviews' ) )->invoke(
	new Submission_Evaluation_Query_Projector( $canonical ),
	array(
		'official_review_id'   => null,
		'official_review_hash' => null,
	),
	$projected_rows,
	array( array( 'result_id' => $first_fixture['result_id'] ) )
);
g4_s02_storage_same( array( 'accepted', 'withdrawn' ), array_column( $projection, 'decision' ), 'Query projection preserves approval and withdrawal history.' );
g4_s02_storage_same( array( false, false ), array_column( $projection, 'is_official' ), 'Withdrawn approval is not projected as official.' );
g4_s02_storage_assert( (bool) array_filter( $first_database->queries, static fn ( string $sql ): bool => str_contains( $sql, 'official_review_public_id = NULL, official_review_hash = NULL' ) ), 'Withdrawal SQL must clear both pointer columns using SQL NULL.' );
$reapprove_fixture                             = $first_fixture;
$reapprove_fixture['snapshot']                 = $first_database->snapshot;
$reapprove_fixture['snapshot']['review_count'] = 2;
g4_s02_storage_failure(
	static fn () => g4_s02_storage_application( $canonical, $first_database )->execute(
		g4_s02_storage_command( $canonical, $reapprove_fixture, 'withdrawn', 'command-withdraw-again', 'Duplicate withdrawal.' )
	),
	Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
	'An unapproved score cannot be withdrawn.'
);
$reapproved = g4_s02_storage_application( $canonical, $first_database )->execute(
	g4_s02_storage_command( $canonical, $reapprove_fixture, 'accepted', 'command-reapprove-score' )
);
g4_s02_storage_same( 3, $reapproved->review_no(), 'Reapproval continues the immutable history.' );
g4_s02_storage_same( 'reviewed', $first_database->snapshot['evaluation_state'], 'Reapproval locks the score again.' );

foreach ( array( 'normal', 'foreign', 'cas' ) as $mode ) {
	$visible_fixture                                 = g4_s02_storage_fixture( $canonical, true );
	$visible_fixture['snapshot']['evaluation_state'] = 'reviewed';
	$database                                        = new G4_S02_Storage_Database( $visible_fixture['snapshot'], $visible_fixture['items'] );
	$database->official_review_owned                 = 'foreign' !== $mode;
	$database->fail_submission_cas                   = 'cas' === $mode;
	$before    = $database->snapshot;
	$operation = static fn () => g4_s02_storage_application( $canonical, $database )->execute(
		g4_s02_storage_command( $canonical, $visible_fixture, 'withdrawn', 'command-visible-withdraw-' . $mode, 'Correct published score.' )
	);
	if ( 'normal' !== $mode ) {
		g4_s02_storage_failure( $operation, Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, 'Foreign approval and stale root writes must be rejected.' );
		g4_s02_storage_same( $before, $database->snapshot, 'Failed withdrawal restores roots.' );
		g4_s02_storage_same( array(), $database->inserts, 'Failed withdrawal leaves no review, release, audit, or outbox rows.' );
		continue;
	}
	$operation();
	g4_s02_storage_same( 'stale', $database->inserts['oj_ai_submission_release_revisions'][0]['state'], 'Withdrawing a published score hides the old publication.' );
	g4_s02_storage_same( null, $database->snapshot['official_review_id'], 'Published score withdrawal clears approval.' );
}

$repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Review_Repository.php' );
g4_s02_storage_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'S02 must keep literal oj_ai_* table names.' );
g4_s02_storage_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_(?:evaluation_results|evaluation_items|evaluation_reviews|release_revisions)\b/i', $repository_source ), 'S02 must not mutate immutable review or release history.' );
g4_s02_storage_assert( str_contains( $repository_source, 'expected_submission_lock_version' ) && str_contains( $repository_source, 'current_release_public_id' ), 'S02 must bind both root CAS expectations.' );
g4_s02_storage_assert( str_contains( $repository_source, 'official_review_changed' ) && Ojai_Php_Source_Contract::contains( $repository_source, "'state' => 'stale'" ), 'S02 stale release evidence drifted.' );

$manual_fixture = g4_s02_storage_fixture( $canonical, false, true );
$manual_database = new G4_S02_Storage_Database( $manual_fixture['snapshot'], $manual_fixture['items'] );
$manual_application = g4_s02_storage_application( $canonical, $manual_database );
$manual_items = array( array( 'binding_key' => str_repeat( 'a', 64 ), 'source' => 'teacher_assigned', 'official_points' => '5.0000', 'reason_code' => 'manual_assessment', 'reason_note' => null ) );
$off_level_items = $manual_items;
$off_level_items[0]['official_points'] = '3.0000';
try {
	$manual_application->execute( g4_s02_storage_command( $canonical, $manual_fixture, 'adjusted', 'manual-off-level', null, $off_level_items ) );
	throw new RuntimeException( 'Manual grading must not accept points outside the rubric levels.' );
} catch ( Submission_Evaluation_Failure $failure ) {
	// A teacher still chooses from the immutable published rubric.
	g4_s02_storage_same( Submission_Evaluation_Failure::REVIEW_SCORE_INVALID, $failure->reason(), 'Off-level manual points must be rejected.' );
}
$manual_review = $manual_application->execute( g4_s02_storage_command( $canonical, $manual_fixture, 'adjusted', 'manual-confirm', null, $manual_items ) );
g4_s02_storage_same( '5.0000', $manual_review->official_score(), 'Teacher can confirm a rubric score without any AI candidate.' );
g4_s02_storage_same( $manual_review->review_id(), $manual_database->snapshot['official_review_id'], 'Manual scoring follows the same official pointer contract.' );
$manual_fixture['snapshot'] = $manual_database->snapshot;
$manual_fixture['snapshot']['review_count'] = 1;
$manual_application->execute( g4_s02_storage_command( $canonical, $manual_fixture, 'withdrawn', 'manual-withdraw', 'Recheck student evidence.' ) );
g4_s02_storage_same( null, $manual_database->snapshot['official_review_id'], 'Manual score approval can be withdrawn.' );

$foreign_fixture = g4_s02_storage_fixture( $canonical, true );
$foreign_database = new G4_S02_Storage_Database( $foreign_fixture['snapshot'], $foreign_fixture['items'] );
$foreign_database->official_review_owned = false;
g4_s02_storage_failure( fn () => g4_s02_storage_application( $canonical, $foreign_database )->execute( g4_s02_storage_command( $canonical, $foreign_fixture, 'accepted', 'foreign-approve' ) ), Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, 'Another evaluation cannot replace a manually confirmed score.' );
g4_s02_storage_same( array(), $foreign_database->inserts, 'Foreign approval must not write anything.' );

fwrite( STDOUT, "G4-IMP-03-S02 official review pointer, manual rubric approval and stale release transaction contract passed.\n" );

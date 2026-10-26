<?php
/**
 * Safe Q09 and Q10 worksheet evaluation projections.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe Q09 and Q10 worksheet evaluation projections.
 */
final class Worksheet_Evaluation_Query_Projector {
	public const REVIEW_ITEMS_SCHEMA  = 'oj_ai.worksheet_evaluation_review_items';
	public const REVIEW_ITEMS_VERSION = 1;

	private const EVALUATION_STATES = array(
		'requested',
		'queued',
		'running',
		'review_required',
		'reviewed',
		'failed',
		'cancelled',
	);
	private const RUN_STATES        = array(
		'queued',
		'leased',
		'running',
		'succeeded',
		'retry_wait',
		'failed',
		'cancelled',
	);
	private const OVERALL_STATUSES  = array(
		'ready',
		'needs_attention',
		'action_required',
		'safety_review',
		'not_assessable',
	);
	private const RATINGS           = array( 'meets', 'minor_issue', 'major_issue', 'not_assessable' );
	private const SEVERITIES        = array( 'info', 'minor', 'major', 'critical' );
	private const ANCHOR_TYPES      = array( 'worksheet', 'node', 'response', 'achievement' );

	/**
	 * Initialize worksheet evaluation query projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * List item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_item( array $row ): array {
		try {
			$evaluation_id       = $this->public_id( $row['evaluation_id'] ?? null );
			$evaluation_state    = $this->one_of( $row['evaluation_state'] ?? null, self::EVALUATION_STATES );
			$worksheet_id        = $this->public_id( $row['worksheet_id'] ?? null );
			$revision_id         = $this->public_id( $row['worksheet_revision_id'] ?? null );
			$current_revision_id = $this->nullable_public_id( $row['current_revision_id'] ?? null );
			$source_hash         = $this->sha256( $row['worksheet_revision_hash'] ?? null );
			$revision_no         = $this->positive_integer( $row['revision_no'] ?? null );
			$revision_state      = $this->safe_token( $row['revision_state'] ?? null, 32 );
			$title               = $this->text( $row['worksheet_title'] ?? null, 255, false );
			$requested_at        = $this->datetime( $row['requested_at_utc'] ?? null );
			$updated_at          = $this->datetime( $row['evaluation_updated_at_utc'] ?? null );

			$latest_run_id = $this->nullable_public_id( $row['latest_run_id'] ?? null );
			$latest_run    = null;
			if ( null !== $latest_run_id ) {
				$latest_run = array(
					'id'             => $latest_run_id,
					'run_no'         => $this->positive_integer( $row['latest_run_no'] ?? null ),
					'state'          => $this->one_of( $row['latest_run_state'] ?? null, self::RUN_STATES ),
					'queued_at_utc'  => $this->datetime( $row['latest_run_queued_at_utc'] ?? null ),
					'updated_at_utc' => $this->datetime( $row['latest_run_updated_at_utc'] ?? null ),
				);
			} elseif (
				null !== ( $row['latest_run_no'] ?? null )
				|| null !== ( $row['latest_run_state'] ?? null )
				|| null !== ( $row['latest_run_queued_at_utc'] ?? null )
			) {
				throw new InvalidArgumentException( 'Partial latest run projection.' );
			}

			$result_id      = $this->nullable_public_id( $row['result_id'] ?? null );
			$overall_status = null;
			if ( null !== $result_id ) {
				$overall_status = $this->one_of( $row['overall_status'] ?? null, self::OVERALL_STATUSES );
			} elseif ( null !== ( $row['overall_status'] ?? null ) ) {
				throw new InvalidArgumentException( 'Result status exists without a result.' );
			}

			$finding_count   = $this->non_negative_integer( $row['finding_count'] ?? null );
			$attention_count = $this->non_negative_integer( $row['attention_count'] ?? null );
			$safety_count    = $this->non_negative_integer( $row['safety_count'] ?? null );
			if (
				$attention_count > $finding_count
				|| $safety_count > $finding_count
				|| ( null === $result_id && 0 !== $finding_count )
			) {
				throw new InvalidArgumentException( 'Worksheet evaluation aggregate counts are invalid.' );
			}
			$review_no = $this->nullable_positive_integer( $row['latest_review_no'] ?? null );
			$is_stale  = null === $current_revision_id || ! hash_equals( $revision_id, $current_revision_id );

			return array(
				'id'               => $evaluation_id,
				'state'            => $evaluation_state,
				'is_stale'         => $is_stale,
				'worksheet'        => array(
					'id'    => $worksheet_id,
					'title' => $title,
				),
				'revision'         => array(
					'id'          => $revision_id,
					'revision_no' => $revision_no,
					'state'       => $revision_state,
					'source_hash' => $source_hash,
					'is_current'  => ! $is_stale,
				),
				'status'           => array(
					'overall_status'        => $overall_status,
					'finding_count'         => $finding_count,
					'needs_attention_count' => $attention_count,
					'safety_flagged'        => $safety_count > 0,
					'latest_review_no'      => $review_no,
					'next_action'           => $this->next_action( $evaluation_state, $is_stale ),
				),
				'latest_run'       => $latest_run,
				'requested_at_utc' => $requested_at,
				'updated_at_utc'   => $updated_at,
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::LIST_ITEM_INVALID );
		}
	}

	/**
	 * Detail.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<string,mixed>|null       $result Result.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail(
		array $root,
		?array $result,
		array $items,
		array $runs,
		array $reviews
	): array {
		try {
			$evaluation        = $this->list_item( $root );
			$result_projection = null;
			if ( null !== $result ) {
				$result_projection = $this->result( $evaluation, $result, $items );
			} elseif ( array() !== $items ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDINGS_WITHOUT_RESULT );
			}

			$run_history = $this->runs( $runs );
			if ( array() === $run_history ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_HISTORY_INVALID );
			}
			$review_history = $this->reviews(
				$reviews,
				null === $result_projection ? null : $result_projection['id'],
				null === $result_projection ? array() : array_column( $result_projection['findings'], 'finding_key' )
			);
			$this->assert_detail_links( $evaluation, $result_projection, $run_history, $review_history );

			return array(
				'evaluation' => $evaluation,
				'result'     => $result_projection,
				'runs'       => $run_history,
				'reviews'    => $review_history,
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->stored_data_invalid();
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed>            $evaluation Evaluation.
	 * @param array<string,mixed>            $row Row.
	 * @param array<int,array<string,mixed>> $item_rows Item rows.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result( array $evaluation, array $row, array $item_rows ): array {
		try {
			$result_id       = $this->public_id( $row['result_id'] ?? null );
			$run_id          = $this->public_id( $row['result_run_id'] ?? null );
			$normalized_hash = $this->sha256( $row['normalized_result_hash'] ?? null );
			$provider_digest = $this->sha256( $row['provider_output_digest'] ?? null );
			$normalized      = $this->decode_canonical(
				$row['normalized_result_json'] ?? null,
				$normalized_hash
			);
			$this->exact_keys(
				$normalized,
				array(
					'schema_id',
					'schema_version',
					'source_hash',
					'provider_status',
					'overall_status',
					'overall_confidence',
					'abstained',
					'safety_flagged',
					'summary',
					'abstain_reason',
					'dimensions',
					'findings',
					'limitations',
				)
			);
			if (
				Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $row['normalized_result_schema'] ?? null )
				|| 1 !== $this->positive_integer( $row['normalized_result_version'] ?? null )
				|| Worksheet_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $normalized['schema_id'] ?? null )
				|| Worksheet_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $normalized['schema_version'] ?? null )
				|| Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION !== ( $row['output_schema_version'] ?? null )
				|| ! hash_equals( $evaluation['revision']['source_hash'], $this->sha256( $normalized['source_hash'] ?? null ) )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RESULT_SCHEMA_INVALID );
			}

			$overall_status     = $this->one_of( $normalized['overall_status'] ?? null, self::OVERALL_STATUSES );
			$overall_confidence = $this->confidence( $normalized['overall_confidence'] ?? null );
			$summary            = $this->text( $normalized['summary'] ?? null, 3000, true );
			if (
				$overall_status !== ( $row['overall_status'] ?? null )
				|| $overall_confidence !== $this->confidence( $row['overall_confidence'] ?? null )
				|| $summary !== ( $row['summary'] ?? null )
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RESULT_COLUMNS_DRIFTED );
			}

			$dimensions = $this->dimensions( $normalized['dimensions'] ?? null );
			$findings   = $this->findings( $normalized['findings'] ?? null, array_column( $dimensions, 'dimension_key' ) );
			$this->assert_item_rows( $findings, $item_rows );
			$limitations     = $this->text_list( $normalized['limitations'] ?? null, 20, 500 );
			$provider_status = $this->one_of( $normalized['provider_status'] ?? null, array( 'completed', 'partial', 'abstained' ) );
			$abstained       = $this->boolean( $normalized['abstained'] ?? null );
			$safety_flagged  = $this->boolean( $normalized['safety_flagged'] ?? null );
			$abstain_reason  = $this->nullable_text( $normalized['abstain_reason'] ?? null, 1500, true );
			if (
				$abstained !== ( 'abstained' === $provider_status )
				|| $safety_flagged !== (bool) array_filter(
					$findings,
					static fn ( array $finding ): bool => 'safety' === $finding['dimension_key']
				)
			) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RESULT_FLAGS_DRIFTED );
			}

			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'          => $evaluation['id'],
						'normalized_hash'        => $normalized_hash,
						'provider_output_digest' => $provider_digest,
						'run_id'                 => $run_id,
					)
				)
			);
			if ( ! hash_equals( $content_hash, $this->sha256( $row['result_content_hash'] ?? null ) ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RESULT_CONTENT_HASH_INVALID );
			}

			return array(
				'id'                 => $result_id,
				'run_id'             => $run_id,
				'overall_status'     => $overall_status,
				'overall_confidence' => $overall_confidence,
				'summary'            => $summary,
				'abstained'          => $abstained,
				'safety_flagged'     => $safety_flagged,
				'abstain_reason'     => $abstain_reason,
				'dimensions'         => $dimensions,
				'findings'           => $findings,
				'limitations'        => $limitations,
				'created_at_utc'     => $this->datetime( $row['result_created_at_utc'] ?? null ),
			);
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RESULT_SCHEMA_INVALID );
		}
	}

	/**
	 * Dimensions.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function dimensions( $value ): array {
		try {
			if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 6 || count( $value ) > 12 ) {
				throw new InvalidArgumentException( 'Worksheet evaluation dimensions are invalid.' );
			}
			$result = array();
			$seen   = array();
			foreach ( $value as $dimension ) {
				if ( ! is_array( $dimension ) || array_is_list( $dimension ) ) {
					throw new InvalidArgumentException( 'Worksheet evaluation dimension is invalid.' );
				}
				$this->exact_keys( $dimension, array( 'dimension_key', 'rating', 'confidence', 'summary' ) );
				$key = $this->safe_token( $dimension['dimension_key'] ?? null, 64 );
				if ( isset( $seen[ $key ] ) ) {
					throw new InvalidArgumentException( 'Worksheet evaluation dimension is duplicated.' );
				}
				$seen[ $key ] = true;
				$result[]     = array(
					'dimension_key' => $key,
					'rating'        => $this->one_of( $dimension['rating'] ?? null, self::RATINGS ),
					'confidence'    => $this->confidence( $dimension['confidence'] ?? null ),
					'summary'       => $this->text( $dimension['summary'] ?? null, 1000, true ),
				);
			}
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::DIMENSIONS_INVALID );
		}
	}

	/**
	 * Findings.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $dimensions Dimensions.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function findings( $value, array $dimensions ): array {
		try {
			if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
				throw new InvalidArgumentException( 'Worksheet evaluation findings are invalid.' );
			}
			$result = array();
			$keys   = array();
			foreach ( $value as $position => $finding ) {
				if ( ! is_array( $finding ) || array_is_list( $finding ) ) {
					throw new InvalidArgumentException( 'Worksheet evaluation finding is invalid.' );
				}
				$this->exact_keys(
					$finding,
					array(
						'dimension_key',
						'severity',
						'title',
						'rationale',
						'evidence',
						'recommendation',
						'confidence',
						'finding_key',
						'position',
					)
				);
				$key       = $this->sha256( $finding['finding_key'] ?? null );
				$dimension = $this->safe_token( $finding['dimension_key'] ?? null, 64 );
				if (
					isset( $keys[ $key ] )
					|| ! in_array( $dimension, $dimensions, true )
					|| $position !== $this->non_negative_integer( $finding['position'] ?? null )
				) {
					throw new InvalidArgumentException( 'Worksheet evaluation finding identity is invalid.' );
				}
				$keys[ $key ] = true;
				$result[]     = array(
					'finding_key'    => $key,
					'dimension_key'  => $dimension,
					'position'       => $position,
					'severity'       => $this->one_of( $finding['severity'] ?? null, self::SEVERITIES ),
					'title'          => $this->text( $finding['title'] ?? null, 160, false ),
					'rationale'      => $this->text( $finding['rationale'] ?? null, 1500, true ),
					'evidence'       => $this->evidence( $finding['evidence'] ?? null ),
					'recommendation' => $this->text( $finding['recommendation'] ?? null, 1500, true ),
					'confidence'     => $this->confidence( $finding['confidence'] ?? null ),
				);
			}
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDINGS_INVALID );
		}
	}

	/**
	 * Evidence.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence( $value ): array {
		try {
			if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > 3 ) {
				throw new InvalidArgumentException( 'Worksheet evaluation evidence is invalid.' );
			}
			$result = array();
			foreach ( $value as $evidence ) {
				if ( ! is_array( $evidence ) || array_is_list( $evidence ) ) {
					throw new InvalidArgumentException( 'Worksheet evaluation evidence item is invalid.' );
				}
				$this->exact_keys( $evidence, array( 'anchor_type', 'anchor_id', 'field', 'quote' ) );
				$result[] = array(
					'anchor_type' => $this->one_of( $evidence['anchor_type'] ?? null, self::ANCHOR_TYPES ),
					'anchor_id'   => $this->public_id( $evidence['anchor_id'] ?? null ),
					'field'       => $this->safe_field( $evidence['field'] ?? null ),
					'quote'       => $this->nullable_text( $evidence['quote'] ?? null, 240, true ),
				);
			}
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::EVIDENCE_INVALID );
		}
	}

	/**
	 * Reject invalid item rows.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_item_rows( array $findings, array $rows ): void {
		if ( count( $findings ) !== count( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDING_ROWS_INCOMPLETE );
		}
		foreach ( $rows as $position => $row ) {
			$finding = $findings[ $position ];
			if ( $position !== $this->non_negative_integer( $row['item_position'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDING_ROW_DRIFTED );
			}
			$evidence_hash = $this->sha256( $row['evidence_hash'] ?? null );
			$payload       = $this->decode_canonical( $row['evidence_json'] ?? null, $evidence_hash );
			$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'finding_key', 'title', 'rationale', 'evidence' ) );
			if (
				'oj_ai.worksheet_quality_finding_evidence' !== ( $row['evidence_schema'] ?? null )
				|| 1 !== $this->positive_integer( $row['evidence_version'] ?? null )
				|| 'oj_ai.worksheet_quality_finding_evidence' !== ( $payload['schema_id'] ?? null )
				|| '1.0.0' !== ( $payload['schema_version'] ?? null )
				|| $finding['finding_key'] !== ( $payload['finding_key'] ?? null )
				|| $finding['title'] !== ( $payload['title'] ?? null )
				|| $finding['rationale'] !== ( $payload['rationale'] ?? null )
				|| $this->canonical->encode( $finding['evidence'] ) !== $this->canonical->encode( $payload['evidence'] ?? null )
				|| $finding['dimension_key'] !== ( $row['item_dimension_key'] ?? null )
				|| $finding['severity'] !== ( $row['item_severity'] ?? null )
				|| $finding['confidence'] !== $this->confidence( $row['item_confidence'] ?? null )
				|| $finding['recommendation'] !== ( $row['item_recommendation'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDING_ROW_DRIFTED );
			}
			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'dimension_key'  => $finding['dimension_key'],
						'evidence_hash'  => $evidence_hash,
						'position'       => $position,
						'recommendation' => $finding['recommendation'],
						'severity'       => $finding['severity'],
					)
				)
			);
			if ( ! hash_equals( $content_hash, $this->sha256( $row['item_content_hash'] ?? null ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::FINDING_CONTENT_HASH_INVALID );
			}
		}
	}

	/**
	 * Runs.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function runs( array $rows ): array {
		try {
			$result  = array();
			$numbers = array();
			foreach ( $rows as $row ) {
				$run_no = $this->positive_integer( $row['run_no'] ?? null );
				if ( isset( $numbers[ $run_no ] ) ) {
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_NUMBER_DUPLICATED );
				}
				$numbers[ $run_no ] = true;
				$state              = $this->one_of( $row['run_state'] ?? null, self::RUN_STATES );
				$result_id          = $this->nullable_public_id( $row['run_result_id'] ?? null );
				if ( 'succeeded' === $state && null === $result_id ) {
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_SUCCEEDED_WITHOUT_RESULT );
				}
				try {
					$error_code = $this->nullable_safe_token( $row['run_last_error_code'] ?? null, 100 );
				} catch ( Throwable ) {
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_ERROR_CODE_INVALID );
				}
				try {
					$error_message = $this->nullable_text(
						$this->single_line( $row['run_last_error_message'] ?? null ),
						500,
						false
					);
				} catch ( Throwable ) {
					throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_ERROR_MESSAGE_INVALID );
				}
				$result[] = array(
					'id'                 => $this->public_id( $row['run_id'] ?? null ),
					'run_no'             => $run_no,
					'state'              => $state,
					'result_id'          => $result_id,
					'attempt_count'      => $this->non_negative_integer( $row['attempt_count'] ?? null ),
					'queued_at_utc'      => $this->datetime( $row['run_queued_at_utc'] ?? null ),
					'available_at_utc'   => $this->datetime( $row['run_available_at_utc'] ?? null ),
					'started_at_utc'     => $this->nullable_datetime( $row['run_started_at_utc'] ?? null ),
					'finished_at_utc'    => $this->nullable_datetime( $row['run_finished_at_utc'] ?? null ),
					'last_error_code'    => $error_code,
					'last_error_message' => $error_message,
				);
			}
			ksort( $numbers, SORT_NUMERIC );
			if ( array_keys( $numbers ) !== range( 1, count( $numbers ) ) ) {
				throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_HISTORY_NOT_CONTIGUOUS );
			}
			usort( $result, static fn ( array $left, array $right ): int => $right['run_no'] <=> $left['run_no'] );
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::RUN_HISTORY_INVALID );
		}
	}

	/**
	 * Reviews.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string|null                    $result_id Result id.
	 * @param array<int,string>              $finding_keys Finding keys.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reviews( array $rows, ?string $result_id, array $finding_keys ): array {
		try {
			$result      = array();
			$previous_id = null;
			foreach ( $rows as $index => $row ) {
				$review_id       = $this->public_id( $row['review_id'] ?? null );
				$review_no       = $this->positive_integer( $row['review_no'] ?? null );
				$row_result_id   = $this->public_id( $row['review_result_id'] ?? null );
				$row_previous_id = $this->nullable_public_id( $row['previous_review_public_id'] ?? null );
				if (
					$review_no !== $index + 1
					|| null === $result_id
					|| ! hash_equals( $result_id, $row_result_id )
					|| $row_previous_id !== $previous_id
				) {
					throw new InvalidArgumentException( 'Worksheet evaluation review chain is invalid.' );
				}
				$items       = $this->review_items( $row, $finding_keys );
				$result[]    = array(
					'id'             => $review_id,
					'review_no'      => $review_no,
					'decision'       => $this->one_of( $row['review_decision'] ?? null, array( 'in_progress', 'completed' ) ),
					'note'           => $this->nullable_text( $row['review_note'] ?? null, 3000, true ),
					'item_decisions' => $items,
					'previous_id'    => $row_previous_id,
					'created_at_utc' => $this->datetime( $row['review_created_at_utc'] ?? null ),
				);
				$previous_id = $review_id;
			}
			return $result;
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::REVIEW_HISTORY_INVALID );
		}
	}

	/**
	 * Review items.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $finding_keys Finding keys.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_items( array $row, array $finding_keys ): array {
		if (
			self::REVIEW_ITEMS_SCHEMA !== ( $row['item_decisions_schema'] ?? null )
			|| self::REVIEW_ITEMS_VERSION !== $this->positive_integer( $row['item_decisions_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review item schema is invalid.' );
		}
		$payload = $this->decode_canonical(
			$row['item_decisions_json'] ?? null,
			$this->sha256( $row['item_decisions_hash'] ?? null )
		);
		$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'items' ) );
		if (
			self::REVIEW_ITEMS_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| '1.0.0' !== ( $payload['schema_version'] ?? null )
			|| ! is_array( $payload['items'] ?? null )
			|| ! array_is_list( $payload['items'] )
			|| count( $payload['items'] ) > 100
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review item payload is invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $payload['items'] as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item is invalid.' );
			}
			$this->exact_keys(
				$item,
				array(
					'finding_key',
					'decision',
					'reason_code',
					'note',
					'resolution_revision_id',
					'resolution_source_hash',
				)
			);
			$key             = $this->sha256( $item['finding_key'] ?? null );
			$decision        = $this->one_of( $item['decision'] ?? null, array( 'resolved', 'deferred', 'dismissed' ) );
			$reason          = $this->nullable_safe_token( $item['reason_code'] ?? null, 64 );
			$note            = $this->nullable_text( $item['note'] ?? null, 1000, true );
			$resolution_id   = $this->nullable_public_id( $item['resolution_revision_id'] ?? null );
			$resolution_hash = $this->nullable_sha256( $item['resolution_source_hash'] ?? null );
			if (
				isset( $seen[ $key ] )
				|| ! in_array( $key, $finding_keys, true )
				|| ( 'resolved' === $decision && ( null === $resolution_id || null === $resolution_hash ) )
				|| ( 'resolved' !== $decision && ( null !== $resolution_id || null !== $resolution_hash || null === $reason || null === $note ) )
			) {
				throw new InvalidArgumentException( 'Worksheet evaluation review item decision is invalid.' );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'finding_key'            => $key,
				'decision'               => $decision,
				'reason_code'            => $reason,
				'note'                   => $note,
				'resolution_revision_id' => $resolution_id,
				'resolution_source_hash' => $resolution_hash,
			);
		}
		return $result;
	}

	/**
	 * Reject invalid detail links.
	 *
	 * @param array<string,mixed>            $evaluation Evaluation.
	 * @param array<string,mixed>|null       $result Result.
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_detail_links(
		array $evaluation,
		?array $result,
		array $runs,
		array $reviews
	): void {
		try {
			$latest_run  = $runs[0] ?? null;
			$summary_run = $evaluation['latest_run'] ?? null;
			if (
				! is_array( $latest_run )
				|| ! is_array( $summary_run )
				|| $latest_run['id'] !== $summary_run['id']
				|| $latest_run['run_no'] !== $summary_run['run_no']
				|| $latest_run['state'] !== $summary_run['state']
				|| $latest_run['queued_at_utc'] !== $summary_run['queued_at_utc']
			) {
				throw new InvalidArgumentException( 'Worksheet evaluation latest run projection drifted.' );
			}

			$linked_result = null;
			foreach ( $runs as $run ) {
				if ( null !== $result && $run['id'] === $result['run_id'] ) {
					$linked_result = $run;
				}
				if ( null === $result && null !== $run['result_id'] ) {
					throw new InvalidArgumentException( 'Worksheet evaluation result projection is missing.' );
				}
			}
			if (
				null !== $result
				&& (
					! is_array( $linked_result )
					|| 'succeeded' !== $linked_result['state']
					|| $result['id'] !== $linked_result['result_id']
					|| $result['overall_status'] !== $evaluation['status']['overall_status']
				)
			) {
				throw new InvalidArgumentException( 'Worksheet evaluation result and run projections drifted.' );
			}

			$latest_review_no = $evaluation['status']['latest_review_no'];
			$review_count     = count( $reviews );
			if (
				( 0 === $review_count && null !== $latest_review_no )
				|| ( 0 < $review_count && $reviews[ $review_count - 1 ]['review_no'] !== $latest_review_no )
			) {
				throw new InvalidArgumentException( 'Worksheet evaluation review summary drifted.' );
			}
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::DETAIL_LINKS_INVALID );
		}
	}

	/**
	 * Next action.
	 *
	 * @param string $state State.
	 * @param bool   $is_stale Is stale.
	 * @return string
	 */
	private function next_action( string $state, bool $is_stale ): string {
		if ( $is_stale ) {
			return 'evaluate_current_revision';
		}
		return match ( $state ) {
			'requested', 'queued', 'running' => 'cancel',
			'review_required' => 'review',
			'reviewed' => 'view',
			'failed', 'cancelled' => 'retry',
			default => 'view',
		};
	}

	/**
	 * Decode canonical.
	 *
	 * @param mixed  $value Value.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical( $value, string $expected_hash ): array {
		if ( ! is_string( $value ) || ! hash_equals( $expected_hash, hash( 'sha256', $value ) ) ) {
			throw new InvalidArgumentException( 'Stored canonical JSON hash is invalid.' );
		}
		try {
			$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Stored canonical JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || $value !== $this->canonical->encode( $decoded ) ) {
			throw new InvalidArgumentException( 'Stored canonical JSON representation drifted.' );
		}
		return $decoded;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Stored projection contains unknown or missing fields.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Stored public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Nullable public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id( $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
	}

	/**
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored SHA-256 is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable sha256.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_sha256( $value ): ?string {
		return null === $value ? null : $this->sha256( $value );
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Stored positive integer is invalid.' );
	}

	/**
	 * Nullable positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function nullable_positive_integer( $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Stored non-negative integer is invalid.' );
	}

	/**
	 * One of.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function one_of( $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Stored enum is invalid.' );
		}
		return $value;
	}

	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored UTC datetime is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable datetime.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_datetime( $value ): ?string {
		return null === $value ? null : $this->datetime( $value );
	}

	/**
	 * Confidence.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confidence( $value ): string {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/D', $value ) ) {
			return number_format( (float) $value, 4, '.', '' );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= 0 && $value <= 1 ) {
			return number_format( round( (float) $value, 4 ), 4, '.', '' );
		}
		throw new InvalidArgumentException( 'Stored confidence is invalid.' );
	}

	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function boolean( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'Stored boolean is invalid.' );
		}
		return $value;
	}

	/**
	 * Safe token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( $value, int $maximum ): string {
		if (
			! is_string( $value )
			|| strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'Stored safe token is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable safe token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 */
	private function nullable_safe_token( $value, int $maximum ): ?string {
		return null === $value ? null : $this->safe_token( $value, $maximum );
	}

	/**
	 * Safe field.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_field( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-z][a-z0-9_.]{1,63}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored evidence field is invalid.' );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_newline Allow newline.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum, bool $allow_newline ): string {
		if (
			! is_string( $value )
			|| '' === $value
			|| strlen( $value ) > $maximum * 4
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			throw new InvalidArgumentException( 'Stored text is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_newline Allow newline.
	 * @return string|null
	 */
	private function nullable_text( $value, int $maximum, bool $allow_newline ): ?string {
		return null === $value ? null : $this->text( $value, $maximum, $allow_newline );
	}

	/**
	 * Collapses any run of whitespace (including newlines the write path
	 * does not reject) into a single space, strips every other control
	 * character the read-side text() check forbids, and caps the length —
	 * so a legacy stored message (multi-line, oversized, or written before
	 * this column had validation) still projects as the single-line label
	 * the UI expects instead of failing the whole detail view.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function single_line( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( '' !== $value && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		$collapsed = preg_replace( '/\s+/u', ' ', $value );
		$value     = null === $collapsed ? $value : trim( $collapsed );
		$stripped  = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		$value     = null === $stripped ? $value : $stripped;
		$value     = mb_substr( $value, 0, 500, 'UTF-8' );
		while ( strlen( $value ) > 2000 ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The UTF-8 truncation changes the byte length on every iteration.
			$value = mb_substr( $value, 0, -1, 'UTF-8' );
		}
		return '' === $value ? null : $value;
	}

	/**
	 * Text list.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum_items Maximum items.
	 * @param int   $maximum_text Maximum text.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_list( $value, int $maximum_items, int $maximum_text ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum_items ) {
			throw new InvalidArgumentException( 'Stored text list is invalid.' );
		}
		$result = array();
		foreach ( $value as $item ) {
			$result[] = $this->text( $item, $maximum_text, true );
		}
		return $result;
	}

	/**
	 * Stored data invalid.
	 *
	 * @return Worksheet_Evaluation_Failure
	 */
	private function stored_data_invalid(): Worksheet_Evaluation_Failure {
		return Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::STORED_DATA_INVALID );
	}
}

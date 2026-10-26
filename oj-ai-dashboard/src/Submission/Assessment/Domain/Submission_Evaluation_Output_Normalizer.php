<?php
/**
 * Strict student submission score output normalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict student submission score output normalizer.
 */
final class Submission_Evaluation_Output_Normalizer {
	public const RESULT_SCHEMA             = 'oj_ai.submission_score_result';
	public const RESULT_VERSION            = '1.0.0';
	public const ITEM_EVIDENCE_SCHEMA      = 'oj_ai.submission_score_item_evidence';
	public const MAXIMUM_OUTPUT_BYTES      = 524288;
	public const MAXIMUM_JSON_DEPTH        = 12;
	public const MAXIMUM_EVIDENCE_PER_ITEM = 3;
	public const MAXIMUM_EVIDENCE_TOTAL    = 1200;

	public const FLAGS = array(
		'artifact_quality_issue',
		'evidence_limited',
		'response_ambiguous',
		'safety_review_required',
		'teacher_review_required',
	);

	private const ABSTAIN_REASONS = array(
		'artifact_unavailable',
		'insufficient_evidence',
		'ambiguous_response',
		'safety_review',
	);

	/**
	 * Initialize submission evaluation output normalizer dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Normalize.
	 *
	 * @param array<string,mixed> $output Output.
	 * @param array<string,mixed> $input Input.
	 * @return Submission_Evaluation_Normalized_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function normalize( array $output, array $input ): Submission_Evaluation_Normalized_Result {
		$this->assert_output_size( $output );
		$this->assert_depth( $output, 1 );
		$this->assert_no_forbidden_keys( $output );
		$this->assert_exact_keys(
			$output,
			array( 'schema_id', 'schema_version', 'evaluation_ref', 'source_hash', 'items', 'summary', 'limitations' ),
			'$'
		);

		$payload        = $this->input_payload( $input );
		$subject        = $payload['subject'];
		$evaluation_ref = $this->evaluation_ref( $subject['evaluation_ref'] ?? null, '$.input.subject.evaluation_ref' );
		$source_hash    = $this->sha256( $subject['source_hash'] ?? null, '$.input.subject.source_hash' );
		if (
			Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null )
			|| ! hash_equals( $evaluation_ref, $this->evaluation_ref( $output['evaluation_ref'] ?? null, '$.evaluation_ref' ) )
		) {
			throw $this->schema_failure( '$.schema_id|schema_version|evaluation_ref|source_hash' );
		}
		$this->assert_source_hash_echo( $output['source_hash'], $source_hash );

		$bindings  = $this->binding_index( $payload['bindings'] ?? null );
		$responses = $this->response_index( $payload['responses'] ?? null );
		$artifacts = $this->artifact_index( $payload['artifacts'] ?? null, $responses );
		$catalog   = array();
		if ( array_key_exists( 'evidence_catalog', $payload ) ) {
			$expected_catalog = Submission_Score_Evidence_Catalog::build( $payload['responses'], $payload['artifacts'] );
			if ( $this->encode( $payload['evidence_catalog'], '$.input.evidence_catalog' ) !== $this->encode( $expected_catalog, '$.input.evidence_catalog' ) ) {
				throw $this->domain_failure( '$.input.evidence_catalog (source mismatch)' );
			}
			$catalog = array_column( $expected_catalog, null, 'evidence_ref' );
		}
		$raw_items = $output['items'] ?? null;
		if (
			! is_array( $raw_items ) || ! array_is_list( $raw_items )
			|| count( $raw_items ) !== count( $bindings )
			|| count( $raw_items ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS
		) {
			throw $this->schema_failure( '$.items (count)' );
		}

		$normalized_items = array();
		$seen             = array();
		$evidence_total   = 0;
		foreach ( $raw_items as $index => $raw ) {
			$item_path = '$.items[' . $index . ']';
			if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $item_path );
			}
			$decision = $raw['decision'] ?? null;
			if ( 'score' === $decision ) {
				$this->assert_exact_keys(
					$raw,
					array( 'binding_key', 'decision', 'candidate_points', 'confidence', 'rationale', 'evidence', 'flags' ),
					$item_path
				);
			} elseif ( 'abstain' === $decision ) {
				$this->assert_exact_keys(
					$raw,
					array( 'binding_key', 'decision', 'candidate_points', 'confidence', 'abstain_reason', 'evidence', 'flags' ),
					$item_path
				);
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $item_path . '.decision (' . $this->safe_value_shape( $decision ) . ')' );
			}

			$binding_key = $this->resolve_binding_key( $raw['binding_key'] ?? null, $bindings, $item_path . '.binding_key' );
			if ( isset( $seen[ $binding_key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . '.binding_key (duplicate)' );
			}
			$seen[ $binding_key ] = true;
			$binding              = $bindings[ $binding_key ];
			$confidence           = $this->confidence( $raw['confidence'] ?? null, $item_path . '.confidence' );
			$flags                = $this->flags( $raw['flags'] ?? null, $item_path . '.flags' );
			$evidence_result      = $this->evidence( $raw['evidence'] ?? null, $binding, $responses, $artifacts, 'score' === $decision, $item_path . '.evidence', $catalog );
			$evidence             = $evidence_result['items'];
			if ( $evidence_result['reformatted'] && ! in_array( 'teacher_review_required', $flags, true ) ) {
				$flags[] = 'teacher_review_required';
				sort( $flags, SORT_STRING );
			}
			$evidence_total += count( $evidence ) + $evidence_result['invalid_count'];
			if ( $evidence_total > self::MAXIMUM_EVIDENCE_TOTAL ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( '$.items[].evidence (total > ' . self::MAXIMUM_EVIDENCE_TOTAL . ')' );
			}

			$maximum_units    = $this->decimal_units( $binding['maximum_points'], $item_path . '.binding.maximum_points' );
			$candidate_points = null;
			$rationale        = null;
			$abstain_reason   = null;
			if ( 'score' === $decision ) {
				$candidate_units = $this->decimal_units( $raw['candidate_points'] ?? null, $item_path . '.candidate_points' );
				if ( isset( $binding['levels'] ) ) {
					$level_units = $this->level_units( $binding['levels'] );
					if ( ! in_array( $candidate_units, $level_units, true ) ) {
						// The AI must pick exactly one level's points value (enforced in the
						// prompt), but models occasionally return a near miss. Snap to the
						// closest level instead of discarding the whole item, and flag it so
						// a teacher notices the correction during review. Ties favor the
						// lower level, matching this normalizer's generally conservative
						// stance on unverified AI output.
						$candidate_units = $this->nearest_level_units( $candidate_units, $level_units );
						if ( ! in_array( 'teacher_review_required', $flags, true ) ) {
							$flags[] = 'teacher_review_required';
							sort( $flags, SORT_STRING );
						}
					}
				} elseif ( $candidate_units > $maximum_units ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $item_path . '.candidate_points (over maximum_points, no levels)' );
				}
				$candidate_points = $this->decimal_string( $candidate_units, $item_path . '.candidate_points' );
				$rationale        = $this->text( $raw['rationale'] ?? null, 1500, true, $item_path . '.rationale' );
			} else {
				if ( null !== ( $raw['candidate_points'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->schema_failure( $item_path . '.candidate_points (must be null for abstain)' );
				}
				$abstain_reason = $raw['abstain_reason'] ?? null;
				if ( ! is_string( $abstain_reason ) || ! in_array( $abstain_reason, self::ABSTAIN_REASONS, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $item_path . '.abstain_reason' );
				}
			}

			// An unverifiable citation invalidates this item's score, not other bindings.
			if ( $evidence_result['invalid_count'] > 0 ) {
				$decision         = 'abstain';
				$candidate_points = null;
				$rationale        = null;
				$confidence       = '0.0000';
				$abstain_reason   = 'safety_review' === $abstain_reason || in_array( 'safety_review_required', $flags, true ) ? 'safety_review' : $evidence_result['failure_reason'];
				$flags            = array_values( array_unique( array_merge( $flags, array( 'evidence_limited', 'teacher_review_required' ) ) ) );
				sort( $flags, SORT_STRING );
			}

			$normalized_items[] = array(
				'binding_key'       => $binding_key,
				'criterion_id'      => $binding['criterion_id'],
				'response_id'       => $binding['response_id'],
				'scope'             => $binding['scope'],
				'binding_position'  => $binding['position'],
				'response_position' => null === $binding['response_id'] ? PHP_INT_MAX : $responses[ $binding['response_id'] ]['position'],
				'decision'          => $decision,
				'candidate_points'  => $candidate_points,
				'maximum_points'    => $this->decimal_string( $maximum_units, $item_path . '.binding.maximum_points' ),
				'levels'            => $binding['levels'] ?? null,
				'confidence'        => $confidence,
				'rationale'         => $rationale,
				'abstain_reason'    => $abstain_reason,
				'evidence'          => $evidence,
				'flags'             => $flags,
			);
		}

		if ( count( $seen ) !== count( $bindings ) ) {
			throw $this->domain_failure( '$.items (missing a required binding)' );
		}
		usort(
			$normalized_items,
			static fn ( array $left, array $right ): int => array( $left['response_position'], $left['binding_position'], $left['binding_key'] )
				<=> array( $right['response_position'], $right['binding_position'], $right['binding_key'] )
		);

		$candidate_units = 0;
		$maximum_units   = 0;
		$abstained_count = 0;
		$safety_flagged  = false;
		$storage_items   = array();
		foreach ( $normalized_items as $position => &$item ) {
			$item['position'] = $position;
			$maximum_units   += $this->decimal_units( $item['maximum_points'], '$.items[].maximum_points (aggregate)' );
			if ( null === $item['candidate_points'] ) {
				++$abstained_count;
			} else {
				$candidate_units += $this->decimal_units( $item['candidate_points'], '$.items[].candidate_points (aggregate)' );
			}
			$safety_flagged   = $safety_flagged
				|| 'safety_review' === $item['abstain_reason']
				|| in_array( 'safety_review_required', $item['flags'], true );
			$evidence_payload = array(
				'schema_id'      => self::ITEM_EVIDENCE_SCHEMA,
				'schema_version' => self::RESULT_VERSION,
				'binding_key'    => $item['binding_key'],
				'decision'       => $item['decision'],
				'rationale'      => $item['rationale'],
				'abstain_reason' => $item['abstain_reason'],
				'evidence'       => $item['evidence'],
			);
			$storage_items[]  = array(
				'criterion_key'    => $item['criterion_id'],
				'question_key'     => $item['response_id'],
				'position'         => $position,
				'candidate_points' => $item['candidate_points'],
				'maximum_points'   => $item['maximum_points'],
				'confidence'       => $item['confidence'],
				'evidence'         => $evidence_payload,
				'flags'            => $item['flags'],
			);
			unset( $item['response_position'], $item['binding_position'] );
		}
		unset( $item );

		$summary = $this->text( $output['summary'] ?? null, 3000, true, '$.summary' );
		// The prompt never specifies limitations' exact shape. "Nothing to
		// report" is a legitimate result the AI may express as a missing key
		// or null rather than an empty list, and a single limitation is
		// sometimes sent as a bare sentence rather than a one-item list —
		// accept both instead of requiring a strict list every time.
		$raw_limitations = $output['limitations'] ?? array();
		if ( is_string( $raw_limitations ) ) {
			$raw_limitations = '' === trim( $raw_limitations ) ? array() : array( $raw_limitations );
		}
		$limitations         = $this->text_list( $raw_limitations, 20, 500, '$.limitations' );
		$provider_status     = 0 === $abstained_count
			? 'complete'
			: ( $abstained_count === count( $normalized_items ) ? 'abstained' : 'partial' );
		$overall_confidence  = $this->mean_confidence( array_column( $normalized_items, 'confidence' ) );
		$candidate_score     = 0 === $abstained_count ? $this->decimal_string( $candidate_units ) : null;
		$maximum_score       = $this->decimal_string( $maximum_units, '$.maximum_score' );
		$normalized          = array(
			'schema_id'          => self::RESULT_SCHEMA,
			'schema_version'     => self::RESULT_VERSION,
			'evaluation_ref'     => $evaluation_ref,
			'source_hash'        => $source_hash,
			'provider_status'    => $provider_status,
			'candidate_score'    => $candidate_score,
			'maximum_score'      => $maximum_score,
			'overall_confidence' => $overall_confidence,
			'abstained'          => $abstained_count > 0,
			'safety_flagged'     => $safety_flagged,
			'summary'            => $summary,
			'items'              => $normalized_items,
			'limitations'        => $limitations,
		);
		$normalized_json     = $this->encode( $normalized, '$ (normalized result)' );
		$provider_projection = array(
			'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
			'evaluation_ref' => $evaluation_ref,
			'source_hash'    => $source_hash,
			'items'          => array_map( array( $this, 'provider_item' ), $normalized_items ),
			'summary'        => $summary,
			'limitations'    => $limitations,
		);

		return new Submission_Evaluation_Normalized_Result(
			$candidate_score,
			$maximum_score,
			$overall_confidence,
			$abstained_count > 0,
			$safety_flagged,
			$summary,
			$normalized_json,
			hash( 'sha256', $normalized_json ),
			hash( 'sha256', $this->encode( $provider_projection, '$ (provider projection)' ) ),
			$storage_items
		);
	}

	/**
	 * Provider item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return array<string,mixed>
	 */
	private function provider_item( array $item ): array {
		if ( 'score' === $item['decision'] ) {
			return array(
				'binding_key'      => $item['binding_key'],
				'decision'         => 'score',
				'candidate_points' => $item['candidate_points'],
				'confidence'       => $item['confidence'],
				'rationale'        => $item['rationale'],
				'evidence'         => $item['evidence'],
				'flags'            => $item['flags'],
			);
		}
		return array(
			'binding_key'      => $item['binding_key'],
			'decision'         => 'abstain',
			'candidate_points' => null,
			'confidence'       => $item['confidence'],
			'abstain_reason'   => $item['abstain_reason'],
			'evidence'         => $item['evidence'],
			'flags'            => $item['flags'],
		);
	}

	/**
	 * Input payload.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input_payload( array $input ): array {
		$this->assert_exact_keys( $input, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ), '$.input' );
		$payload = $input['operation_payload'] ?? null;
		if (
			Submission_Score_Input_Builder::OPERATION !== ( $input['operation'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $input['operation_contract'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_SCHEMA !== ( $input['operation_schema'] ?? null )
			|| ! is_array( $payload ) || array_is_list( $payload )
			|| ! hash_equals( hash( 'sha256', $this->encode( $payload, '$.input.operation_payload' ) ), $this->sha256( $input['payload_hash'] ?? null, '$.input.payload_hash' ) )
		) {
			throw $this->domain_failure( '$.input.operation|operation_contract|operation_schema|operation_payload|payload_hash' );
		}
		$output_contract = $payload['output_contract'] ?? null;
		$subject         = $payload['subject'] ?? null;
		if (
			! is_array( $output_contract ) || array_is_list( $output_contract )
			|| Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $output_contract['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $output_contract['schema_version'] ?? null )
			|| ! is_array( $subject ) || array_is_list( $subject )
		) {
			throw $this->domain_failure( '$.input.operation_payload.output_contract|subject' );
		}
		return $payload;
	}

	/**
	 * Binding index.
	 *
	 * @param mixed $raw Raw.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function binding_index( $raw ): array {
		if (
			! is_array( $raw ) || ! array_is_list( $raw ) || array() === $raw
			|| count( $raw ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS
		) {
			throw $this->domain_failure( '$.input.operation_payload.bindings (shape or count)' );
		}
		$result   = array();
		$criteria = array();
		$refs     = array();
		foreach ( $raw as $position => $binding ) {
			$path = '$.input.operation_payload.bindings[' . $position . ']';
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			$this->assert_allowed_keys(
				$binding,
				array( 'binding_key', 'binding_ref', 'criterion_id', 'description', 'label', 'levels', 'maximum_points', 'position', 'response_id', 'scope', 'correct_answer', 'example_answer' ),
				$path
			);
			foreach ( array( 'binding_key', 'criterion_id', 'label', 'maximum_points', 'position', 'response_id', 'scope' ) as $required ) {
				if ( ! array_key_exists( $required, $binding ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $path . '.' . $required . ' (missing)' );
				}
			}
			$key         = $this->sha256( $binding['binding_key'], $path . '.binding_key' );
			$criterion   = $this->public_id( $binding['criterion_id'], $path . '.criterion_id' );
			$scope       = $binding['scope'] ?? null;
			$response_id = $binding['response_id'] ?? null;
			if (
				isset( $result[ $key ] ) || isset( $criteria[ $criterion ] )
				|| $position !== ( $binding['position'] ?? null )
				|| ! in_array( $scope, array( 'response', 'submission' ), true )
				|| ( 'response' === $scope && null === $response_id )
				|| ( 'submission' === $scope && null !== $response_id )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '.position|scope|response_id' );
			}
			$response_id = null === $response_id ? null : $this->public_id( $response_id, $path . '.response_id' );
			$this->decimal_units( $binding['maximum_points'], $path . '.maximum_points' );
			$entry = array(
				'binding_key'    => $key,
				'criterion_id'   => $criterion,
				'maximum_points' => $binding['maximum_points'],
				'position'       => $position,
				'response_id'    => $response_id,
				'scope'          => $scope,
			);
			if ( array_key_exists( 'binding_ref', $binding ) ) {
				$ref = $binding['binding_ref'];
				if ( ! is_string( $ref ) || 1 !== preg_match( '/^B[1-9][0-9]{0,3}$/D', $ref ) || isset( $refs[ $ref ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates a safe field path; no provider text.
					throw $this->domain_failure( $path . '.binding_ref (invalid or duplicate)' );
				}
				$entry['binding_ref'] = $ref;
				$refs[ $ref ]         = true;
			}
			if ( array_key_exists( 'levels', $binding ) ) {
				$entry['levels'] = $this->binding_levels( $binding['levels'], $path . '.levels' );
			}
			$result[ $key ]         = $entry;
			$criteria[ $criterion ] = true;
		}
		return $result;
	}

	/**
	 * Resolve only identifiers explicitly issued in this immutable request.
	 *
	 * @param mixed                             $raw Provider identifier.
	 * @param array<string,array<string,mixed>> $bindings Validated bindings.
	 * @param string                            $path Output field path.
	 * @return string
	 * @throws Submission_Evaluation_Worker_Failure When the reference is unknown.
	 */
	private function resolve_binding_key( $raw, array $bindings, string $path ): string {
		if ( is_string( $raw ) ) {
			if ( isset( $bindings[ $raw ] ) ) {
				return $raw;
			}
			foreach ( $bindings as $key => $binding ) {
				if ( $raw === ( $binding['binding_ref'] ?? null ) ) {
					return $key;
				}
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates a safe field path; no provider text.
		throw $this->domain_failure( $path . ' (unknown)' );
	}

	/**
	 * Binding levels.
	 *
	 * @param mixed  $raw Raw.
	 * @param string $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function binding_levels( $raw, string $path ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 2 || count( $raw ) > 10 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (shape or count)' );
		}
		$result     = array();
		$seen_keys  = array();
		$seen_units = array();
		foreach ( $raw as $position => $level ) {
			$level_path = $path . '[' . $position . ']';
			if ( ! is_array( $level ) || array_is_list( $level ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $level_path );
			}
			$this->assert_exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ), $level_path );
			$id = $this->public_id( $level['level_key'] ?? null, $level_path . '.level_key' );
			if (
				isset( $seen_keys[ $id ] )
				|| $position !== ( $level['position'] ?? null )
				|| ! is_string( $level['label'] ?? null ) || '' === trim( $level['label'] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $level_path . '.level_key|position|label' );
			}
			$unit = $this->decimal_units( $level['points'] ?? null, $level_path . '.points' );
			if ( isset( $seen_units[ $unit ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $level_path . '.points (duplicate)' );
			}
			$seen_keys[ $id ]    = true;
			$seen_units[ $unit ] = true;
			$result[]            = array(
				'label'     => $level['label'],
				'level_key' => $id,
				'points'    => $this->decimal_string( $unit, $level_path . '.points' ),
				'position'  => $position,
			);
		}
		return $result;
	}

	/**
	 * Level units.
	 *
	 * @param array<int,array<string,mixed>> $levels Levels.
	 * @return array<int,int>
	 */
	private function level_units( array $levels ): array {
		return array_map( fn ( array $level ): int => $this->decimal_units( $level['points'], '$.levels[].points' ), $levels );
	}

	/**
	 * Nearest level units.
	 *
	 * @param int            $target Target.
	 * @param array<int,int> $level_units Level units.
	 * @return int
	 */
	private function nearest_level_units( int $target, array $level_units ): int {
		$closest          = $level_units[0];
		$closest_distance = abs( $target - $closest );
		foreach ( $level_units as $unit ) {
			$distance = abs( $target - $unit );
			if ( $distance < $closest_distance || ( $distance === $closest_distance && $unit < $closest ) ) {
				$closest          = $unit;
				$closest_distance = $distance;
			}
		}
		return $closest;
	}

	/**
	 * Response index.
	 *
	 * @param mixed $raw Raw.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_index( $raw ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > Submission_Score_Input_Builder::MAXIMUM_RESPONSES ) {
			throw $this->domain_failure( '$.input.operation_payload.responses (shape or count)' );
		}
		$result = array();
		foreach ( $raw as $position => $response ) {
			$path = '$.input.operation_payload.responses[' . $position . ']';
			if ( ! is_array( $response ) || array_is_list( $response ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			$this->assert_exact_keys( $response, array( 'answer', 'kind', 'label', 'position', 'response_id' ), $path );
			$id = $this->public_id( $response['response_id'] ?? null, $path . '.response_id' );
			if ( isset( $result[ $id ] ) || $position !== ( $response['position'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '.response_id (duplicate) or position' );
			}
			$result[ $id ] = array(
				'answer'   => $response['answer'] ?? null,
				'kind'     => $response['kind'] ?? null,
				'position' => $position,
			);
		}
		return $result;
	}

	/**
	 * Artifact index.
	 *
	 * @param mixed                             $raw Raw.
	 * @param array<string,array<string,mixed>> $responses Responses.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function artifact_index( $raw, array $responses ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > Submission_Score_Input_Builder::MAXIMUM_ARTIFACTS ) {
			throw $this->domain_failure( '$.input.operation_payload.artifacts (shape or count)' );
		}
		$result = array();
		foreach ( $raw as $artifact_index => $artifact ) {
			$path = '$.input.operation_payload.artifacts[' . $artifact_index . ']';
			if ( ! is_array( $artifact ) || array_is_list( $artifact ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path );
			}
			$this->assert_exact_keys(
				$artifact,
				array( 'anchors', 'artifact_ref', 'extractor_contract', 'kind', 'redaction_version', 'representation_hash', 'response_id', 'role', 'source_hash', 'state', 'text', 'unavailable_reason' ),
				$path
			);
			$response_id = $this->public_id( $artifact['response_id'] ?? null, $path . '.response_id' );
			$state       = $artifact['state'] ?? null;
			$text        = $artifact['text'] ?? null;
			$anchors     = $artifact['anchors'] ?? null;
			if (
				! isset( $responses[ $response_id ] )
				|| ! in_array( $state, array( 'ready', 'unavailable' ), true )
				|| ( 'ready' === $state && ! is_string( $text ) )
				|| ( 'unavailable' === $state && null !== $text )
				|| ! is_array( $anchors ) || ! array_is_list( $anchors ) || count( $anchors ) > 200
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '.response_id|state|text|anchors' );
			}
			foreach ( $anchors as $anchor_index => $anchor ) {
				$anchor_path = $path . '.anchors[' . $anchor_index . ']';
				if ( ! is_array( $anchor ) || array_is_list( $anchor ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $anchor_path );
				}
				$this->assert_exact_keys( $anchor, array( 'anchor', 'quote' ), $anchor_path );
				$key = $anchor['anchor'] ?? null;
				if (
					! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $key )
					|| isset( $result[ $key ] ) || ! is_string( $text )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $anchor_path . '.anchor (format or duplicate)' );
				}
				$known_quote = $this->text( $anchor['quote'] ?? null, 1000, true, $anchor_path . '.quote' );
				if ( ! str_contains( $text, $known_quote ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw $this->domain_failure( $anchor_path . '.quote (not a substring of the artifact text)' );
				}
				$result[ $key ] = array(
					'response_id' => $response_id,
					'text'        => $text,
				);
			}
		}
		return $result;
	}

	/**
	 * Evidence.
	 *
	 * @param mixed                             $raw Raw.
	 * @param array<string,mixed>               $binding Binding.
	 * @param array<string,array<string,mixed>> $responses Responses.
	 * @param array<string,array<string,mixed>> $artifacts Artifacts.
	 * @param bool                              $score Score.
	 * @param string                            $path Path.
	 * @param array<string,array<string,mixed>> $catalog Server-issued evidence references.
	 * @return array{items:array<int,array<string,mixed>>,reformatted:bool,invalid_count:int,failure_reason:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence( $raw, array $binding, array $responses, array $artifacts, bool $score, string $path, array $catalog ): array {
		if (
			! is_array( $raw ) || ! array_is_list( $raw )
			|| count( $raw ) > self::MAXIMUM_EVIDENCE_PER_ITEM
			|| ( $score && array() === $raw )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (shape or count)' );
		}
		$result        = array();
		$seen          = array();
		$reformatted   = false;
		$invalid_count = 0;
		$failures      = array();
		foreach ( $raw as $evidence_index => $item ) {
			$item_path = $path . '[' . $evidence_index . ']';
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $item_path );
			}
			if ( array_key_exists( 'evidence_ref', $item ) ) {
				$this->assert_exact_keys( $item, array( 'evidence_ref' ), $item_path );
				$ref = $item['evidence_ref'];
				if ( ! is_string( $ref ) || ! isset( $catalog[ $ref ] ) ) {
					++$invalid_count;
					$failures[] = 'evidence_reference_unknown';
					continue;
				}
				$item = array_intersect_key( $catalog[ $ref ], array_flip( array( 'source', 'anchor', 'quote' ) ) );
			}
			$this->assert_exact_keys( $item, array( 'source', 'anchor', 'quote' ), $item_path );
			$source         = $item['source'] ?? null;
			$anchor         = $item['anchor'] ?? null;
			$quote          = $this->nullable_text( $item['quote'] ?? null, 240, true, $item_path . '.quote' );
			$original_quote = $quote;
			if ( ! is_string( $source ) || ! in_array( $source, array( 'response', 'artifact' ), true ) || ! is_string( $anchor ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->schema_failure( $item_path . '.source|anchor' );
			}
			if ( 'response' === $source ) {
				// A bare ID is unambiguous only within this request's response index.
				$response_id = str_starts_with( $anchor, 'response:' ) ? substr( $anchor, 9 ) : $anchor;
				$anchor      = 'response:' . $response_id;
				if ( ! Public_Id::is_valid( $response_id ) || ! isset( $responses[ $response_id ] ) ) {
					++$invalid_count;
					$failures[] = 'evidence_reference_unknown';
					continue;
				}
				if ( 'response' === $binding['scope'] && ! hash_equals( (string) $binding['response_id'], $response_id ) ) {
					++$invalid_count;
					$failures[] = 'evidence_scope_mismatch';
					continue;
				}
				if ( null === $quote && in_array( $responses[ $response_id ]['kind'], array( 'short_text', 'long_text' ), true ) && '' !== trim( (string) ( $responses[ $response_id ]['answer']['text'] ?? '' ) ) ) {
					++$invalid_count;
					$failures[] = 'evidence_quote_mismatch';
					continue;
				}
				if ( null !== $quote ) {
					$quote = $this->match_quote( $responses[ $response_id ]['answer'], $quote, in_array( $responses[ $response_id ]['kind'], array( 'short_text', 'long_text' ), true ) );
					if ( null === $quote ) {
						++$invalid_count;
						$failures[] = 'evidence_quote_mismatch';
						continue;
					}
				}
			} else {
				if ( ! isset( $artifacts[ $anchor ] ) ) {
					++$invalid_count;
					$failures[] = 'evidence_reference_unknown';
					continue;
				}
				if ( 'response' === $binding['scope'] && ! hash_equals( (string) $binding['response_id'], $artifacts[ $anchor ]['response_id'] ) ) {
					++$invalid_count;
					$failures[] = 'evidence_scope_mismatch';
					continue;
				}
				if ( null === $quote || ! str_contains( $artifacts[ $anchor ]['text'], $quote ) ) {
					++$invalid_count;
					$failures[] = 'evidence_quote_mismatch';
					continue;
				}
			}
			$reformatted = $reformatted || $quote !== $original_quote;
			$normalized  = array(
				'source' => $source,
				'anchor' => $anchor,
				'quote'  => $quote,
			);
			$key         = $this->encode( $normalized, $item_path . ' (dedup key)' );
			if ( isset( $seen[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $item_path . ' (duplicate evidence item)' );
			}
			$seen[ $key ] = true;
			$result[]     = $normalized;
		}
		usort( $result, static fn ( array $left, array $right ): int => array( $left['source'], $left['anchor'], $left['quote'] ) <=> array( $right['source'], $right['anchor'], $right['quote'] ) );
		return array(
			'items'          => $result,
			'reformatted'    => $reformatted,
			'invalid_count'  => $invalid_count,
			'failure_reason' => array() === $failures ? null : ( 1 === count( array_unique( $failures ) ) ? $failures[0] : 'evidence_multiple_invalid' ),
		);
	}

	/**
	 * Resolve a citation inside one answer value, preserving its literal text.
	 *
	 * @param mixed  $value Value.
	 * @param string $quote Quote.
	 * @param bool   $allow_whitespace Whether prose whitespace may differ.
	 * @return string|null
	 */
	private function match_quote( $value, string $quote, bool $allow_whitespace ): ?string {
		if ( is_string( $value ) ) {
			if ( str_contains( $value, $quote ) ) {
				return $quote;
			}
			if ( ! $allow_whitespace ) {
				return null;
			}
			// Require every non-whitespace character in order, without joining fields.
			$tokens = preg_split( '/[\s\p{Z}]+/u', $quote, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $tokens ) || count( $tokens ) < 2 ) {
				return null;
			}
			$pattern = '/' . implode( '[\s\p{Z}]++', array_map( static fn ( string $token ): string => preg_quote( $token, '/' ), $tokens ) ) . '/u';
			if ( 1 === preg_match( $pattern, $value, $matches ) && preg_match_all( '/./us', $matches[0] ) <= 240 ) {
				return $matches[0];
			}
			return null;
		}
		if ( is_bool( $value ) ) {
			return str_contains( $value ? 'true' : 'false', $quote ) ? $quote : null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return str_contains( (string) $value, $quote ) ? $quote : null;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		foreach ( $value as $item ) {
			$matched = $this->match_quote( $item, $quote, $allow_whitespace );
			if ( null !== $matched ) {
				return $matched;
			}
		}
		return null;
	}

	/**
	 * Flags.
	 *
	 * @param mixed  $raw Raw.
	 * @param string $path Path.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function flags( $raw, string $path ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > count( self::FLAGS ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (shape or count)' );
		}
		$result = array();
		foreach ( $raw as $flag ) {
			if ( ! is_string( $flag ) || ! in_array( $flag, self::FLAGS, true ) || in_array( $flag, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . ' (unknown or duplicate flag)' );
			}
			$result[] = $flag;
		}
		sort( $result, SORT_STRING );
		if ( strlen( implode( ',', $result ) ) > 191 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (too long)' );
		}
		return $result;
	}

	/**
	 * Reject invalid output size.
	 *
	 * @param array<string,mixed> $output Output.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_output_size( array $output ): void {
		try {
			$json = json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		} catch ( JsonException ) {
			throw $this->schema_failure( '$ (not JSON-encodable)' );
		}
		if ( strlen( $json ) > self::MAXIMUM_OUTPUT_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::OUTPUT_TOO_LARGE );
		}
	}

	/**
	 * Reject invalid depth.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_depth( $value, int $depth ): void {
		if ( $depth > self::MAXIMUM_JSON_DEPTH ) {
			throw $this->schema_failure( '$ (max depth exceeded)' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assert_depth( $item, $depth + 1 );
			}
		}
	}

	/**
	 * Reject invalid no forbidden keys.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_forbidden_keys( $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$forbidden = array(
			'final_grade',
			'official_score',
			'student_feedback',
			'release',
			'approval',
			'reasoning',
			'chain_of_thought',
			'hidden_reasoning',
			'raw_response',
			'system_prompt',
			'prompt',
			'authorization',
			'secret',
			'api_key',
			'endpoint',
			'learner_id',
			'student_id',
			'student_name',
			'student_email',
		);
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized = strtolower( str_replace( '-', '_', $key ) );
				if ( in_array( $normalized, $forbidden, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID );
				}
			}
			$this->assert_no_forbidden_keys( $item );
		}
	}

	/**
	 * Confidence.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confidence( $value, string $path ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (not a number)' );
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < 0 || $number > 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (out of 0..1)' );
		}
		return number_format( round( $number, 4 ), 4, '.', '' );
	}

	/**
	 * Mean confidence.
	 *
	 * @param array<int,string> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mean_confidence( array $values ): string {
		if ( array() === $values ) {
			throw $this->domain_failure( '$.items (empty, cannot average confidence)' );
		}
		$total = 0.0;
		foreach ( $values as $value ) {
			$total += (float) $value;
		}
		return number_format( round( $total / count( $values ), 4 ), 4, '.', '' );
	}

	/**
	 * Decimal units.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_units( $value, string $path ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (not a decimal string)' );
		}
		$parts = explode( '.', $value, 2 );
		return ( (int) $parts[0] * 10000 ) + (int) str_pad( $parts[1] ?? '', 4, '0' );
	}

	/**
	 * Decimal string.
	 *
	 * @param int    $units Units.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_string( int $units, string $path = '$ (decimal_string)' ): string {
		if ( $units < 0 || $units > 999999999999 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (out of range)' );
		}
		return intdiv( $units, 10000 ) . '.' . str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Text.
	 *
	 * @param mixed  $value Value.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_newline Allow newline.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum, bool $allow_newline, string $path ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (not a string)' );
		}
		try {
			$value = $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", trim( $value ) ) );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (invalid UTF-8 or normalization failed)' );
		}
		$count = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value, $matches );
		if (
			'' === $value || false === $count || $count > $maximum
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (empty, too long, or contains a control character)' );
		}
		return $value;
	}

	/**
	 * Nullable text.
	 *
	 * @param mixed  $value Value.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_newline Allow newline.
	 * @param string $path Path.
	 * @return string|null
	 */
	private function nullable_text( $value, int $maximum, bool $allow_newline, string $path ): ?string {
		return null === $value ? null : $this->text( $value, $maximum, $allow_newline, $path );
	}

	/**
	 * Text list.
	 *
	 * @param mixed  $value Value.
	 * @param int    $maximum_items Maximum items.
	 * @param int    $maximum_text Maximum text.
	 * @param string $path Path.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_list( $value, int $maximum_items, int $maximum_text, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum_items ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (shape or count: ' . $this->safe_value_shape( $value ) . ')' );
		}
		$result = array();
		foreach ( $value as $index => $item ) {
			$text = $this->text( $item, $maximum_text, true, $path . '[' . $index . ']' );
			if ( in_array( $text, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '[' . $index . '] (duplicate)' );
			}
			$result[] = $text;
		}
		return $result;
	}

	/**
	 * Evaluation ref.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_ref( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (format)' );
		}
		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value, string $path ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (not a string)' );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->domain_failure( $path . ' (invalid public id)' );
		}
		return $value;
	}

	/**
	 * Sha256.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (not a sha256 hex string)' );
		}
		return $value;
	}

	/**
	 * Validate a required hash echo after the exact, source-bound evaluation reference.
	 *
	 * @param mixed  $value Provider metadata, not a source of grading facts.
	 * @param string $expected Validated hash from the immutable server input.
	 * @return void
	 * @throws Submission_Evaluation_Worker_Failure Rejects conflicting identities or invalid types.
	 */
	private function assert_source_hash_echo( mixed $value, string $expected ): void {
		if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > 256 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only a stable validation path crosses the boundary.
			throw $this->schema_failure( '$.source_hash (metadata shape)' );
		}
		// The exact EV reference already binds revision/hash, publication, configuration and artifacts.
		// Restore a malformed echo from that verified input, never from model text or another result.
		$text = preg_replace( '/[\x09-\x0D\x20]+/', '', strtolower( $value ?? '' ) ) ?? '';
		preg_match_all( '/[a-f0-9]{64}/', $text, $hashes );
		foreach ( $hashes[0] as $hash ) {
			if ( ! hash_equals( $expected, $hash ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only a stable validation path crosses the boundary.
				throw $this->schema_failure( '$.source_hash (source mismatch)' );
			}
		}
		$short = preg_replace( '/^(?:sha-?256:|0x)/', '', $text ) ?? $text;
		if ( 1 === preg_match( '/^[a-f0-9]{16,63}$/D', $short ) && ! str_starts_with( $expected, $short ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only a stable validation path crosses the boundary.
			throw $this->schema_failure( '$.source_hash (source prefix mismatch)' );
		}
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @param string                 $path Input path for validation errors.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected, string $path ): void {
		if ( array() !== $value && array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (must be an object)' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (keys: ' . implode( ',', $actual ) . ')' );
		}
	}

	/**
	 * Reject invalid allowed keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $allowed Allowed.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_allowed_keys( array $value, array $allowed, string $path ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->domain_failure( $path . '.' . $key . ' (unexpected key)' );
			}
		}
	}

	/**
	 * Encode submission evaluation output normalizer.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function encode( $value, string $path ): string {
		try {
			return $this->canonical->encode( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->schema_failure( $path . ' (not canonical-JSON-encodable)' );
		}
	}

	/**
	 * A safe, content-free description of an unexpected provider value —
	 * its PHP type and, for a string, its byte length — never the value
	 * itself, which may be untrusted provider content.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function safe_value_shape( $value ): string {
		if ( is_string( $value ) ) {
			return 'string(' . strlen( $value ) . ')';
		}
		if ( is_bool( $value ) ) {
			return 'bool(' . ( $value ? 'true' : 'false' ) . ')';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return gettype( $value );
		}
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? 'list(' . count( $value ) . ')' : 'object';
		}
		return null === $value ? 'null' : gettype( $value );
	}

	/**
	 * Schema failure.
	 *
	 * @param string $path Path.
	 * @return Submission_Evaluation_Worker_Failure
	 */
	private function schema_failure( string $path = '$' ): Submission_Evaluation_Worker_Failure {
		return Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, $path );
	}

	/**
	 * Domain failure.
	 *
	 * @param string $path Path.
	 * @return Submission_Evaluation_Worker_Failure
	 */
	private function domain_failure( string $path = '$' ): Submission_Evaluation_Worker_Failure {
		return Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, $path );
	}
}

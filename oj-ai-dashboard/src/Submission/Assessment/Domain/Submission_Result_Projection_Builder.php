<?php
/**
 * Build the only payload that may cross the student result boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the only payload that may cross the student result boundary.
 */
final class Submission_Result_Projection_Builder {
	public const SCORE_VISIBILITIES    = array( 'total', 'total_and_criteria', 'hidden' );
	public const FEEDBACK_VISIBILITIES = array( 'shown', 'hidden' );

	/**
	 * Initialize submission result projection builder dependencies and state.
	 *
	 * @param Submission_Feedback_Input_Builder     $inputs Inputs.
	 * @param Submission_Feedback_Output_Normalizer $outputs Outputs.
	 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Submission_Feedback_Input_Builder $inputs,
		private Submission_Feedback_Output_Normalizer $outputs,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param array<string,mixed>      $source Source.
	 * @param string                   $worksheet_title Worksheet title.
	 * @param string                   $score_visibility Score visibility.
	 * @param string                   $feedback_visibility Feedback visibility.
	 * @param array<string,mixed>|null $feedback Feedback.
	 * @param string                   $state State.
	 * @param string                   $released_at_utc Released at utc.
	 * @return Submission_Result_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		array $source,
		string $worksheet_title,
		string $score_visibility,
		string $feedback_visibility,
		?array $feedback,
		string $state,
		string $released_at_utc
	): Submission_Result_Projection {
		if (
			! in_array( $score_visibility, self::SCORE_VISIBILITIES, true )
			|| ! in_array( $feedback_visibility, self::FEEDBACK_VISIBILITIES, true )
			|| ( 'hidden' === $score_visibility && 'hidden' === $feedback_visibility )
			|| ! in_array( $state, array( 'released', 'corrected' ), true )
			|| '' === trim( $worksheet_title ) || strlen( $worksheet_title ) > 255
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $released_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission result projection settings are invalid.' );
		}

		$input    = $this->inputs->build( $source );
		$payload  = $input->gateway_input()['operation_payload'] ?? null;
		$criteria = is_array( $payload ) ? ( $payload['criteria'] ?? null ) : null;
		if ( ! is_array( $criteria ) || ! array_is_list( $criteria ) || array() === $criteria ) {
			throw new InvalidArgumentException( 'Submission result score source is invalid.' );
		}

		$score_items = array();
		$labels      = array();
		$total_units = 0;
		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) || array_is_list( $criterion ) ) {
				throw new InvalidArgumentException( 'Submission result criterion is invalid.' );
			}
			$key     = $this->hash_value( $criterion['binding_key'] ?? null );
			$label   = $this->text( $criterion['criterion_label'] ?? null, 255 );
			$points  = $this->decimal( $criterion['official_points'] ?? null );
			$maximum = $this->decimal( $criterion['maximum_points'] ?? null );
			if ( isset( $labels[ $key ] ) || $this->decimal_units( $points ) > $this->decimal_units( $maximum ) ) {
				throw new InvalidArgumentException( 'Submission result criterion score is invalid.' );
			}
			$labels[ $key ] = $label;
			$total_units   += $this->decimal_units( $points );
			$score_items[]  = array(
				'label'          => $label,
				'maximum_points' => $maximum,
				'points'         => $points,
			);
		}
		if ( $total_units > 999999999999 ) {
			throw new InvalidArgumentException( 'Submission result score is too large.' );
		}
		$total = intdiv( $total_units, 10000 ) . '.' . str_pad( (string) ( $total_units % 10000 ), 4, '0', STR_PAD_LEFT );

		$feedback_projection = array(
			'criteria'         => array(),
			'next_steps'       => array(),
			'overall_feedback' => null,
			'visibility'       => $feedback_visibility,
		);
		if ( 'shown' === $feedback_visibility ) {
			$feedback_projection = $this->shown_feedback( $feedback, $input->gateway_input(), $labels );
		}

		$visible_score = 'hidden' === $score_visibility ? null : $total;
		$projection    = array(
			'feedback'       => $feedback_projection,
			'release'        => array(
				'released_at_utc' => $released_at_utc,
				'state'           => $state,
			),
			'schema_id'      => Submission_Result_Projection::SCHEMA,
			'schema_version' => Submission_Result_Projection::SCHEMA_VERSION,
			'score'          => array(
				'criteria'   => 'total_and_criteria' === $score_visibility ? $score_items : array(),
				'total'      => $visible_score,
				'visibility' => $score_visibility,
			),
			'worksheet'      => array( 'title' => trim( $worksheet_title ) ),
		);
		$this->assert_student_safe( $projection );
		$json = $this->canonical->encode( $projection );
		return new Submission_Result_Projection( $projection, $json, hash( 'sha256', $json ), $visible_score );
	}

	/**
	 * Shown feedback.
	 *
	 * @param array<string,mixed>|null $feedback Feedback.
	 * @param array<string,mixed>      $input Input.
	 * @param array<string,string>     $labels Labels.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function shown_feedback( ?array $feedback, array $input, array $labels ): array {
		if (
			null === $feedback
			|| 'approved' !== ( $feedback['state'] ?? null )
			|| Submission_Feedback_Input_Builder::RESULT_SCHEMA !== ( $feedback['schema'] ?? null )
			|| 1 !== ( $feedback['version'] ?? null )
			|| ! is_string( $feedback['json'] ?? null )
			|| ! is_string( $feedback['hash'] ?? null )
			|| ! hash_equals( (string) $feedback['hash'], hash( 'sha256', (string) $feedback['json'] ) )
		) {
			throw new InvalidArgumentException( 'Approved submission feedback is required.' );
		}
		try {
			$profile = json_decode( (string) $feedback['json'], true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'Approved submission feedback is invalid.' );
		}
		if ( ! is_array( $profile ) || array_is_list( $profile ) || ! hash_equals( (string) $feedback['json'], $this->canonical->encode( $profile ) ) ) {
			throw new InvalidArgumentException( 'Approved submission feedback is invalid.' );
		}
		$gateway                   = $profile;
		$gateway['schema_id']      = Submission_Feedback_Input_Builder::OUTPUT_SCHEMA;
		$gateway['schema_version'] = Submission_Feedback_Input_Builder::OUTPUT_VERSION;
		$normalized                = $this->outputs->normalize( $gateway, $input );
		if ( $normalized->safety_flagged() || ! hash_equals( (string) $feedback['json'], $normalized->profile_json() ) || ! hash_equals( (string) $feedback['hash'], $normalized->profile_hash() ) ) {
			throw new InvalidArgumentException( 'Approved submission feedback is not releasable.' );
		}
		$items = array();
		foreach ( $normalized->profile()['criterion_feedback'] as $item ) {
			$key = $this->hash_value( $item['binding_key'] ?? null );
			if ( ! isset( $labels[ $key ] ) ) {
				throw new InvalidArgumentException( 'Approved submission feedback binding is invalid.' );
			}
			$items[] = array(
				'label'     => $labels[ $key ],
				'next_step' => $item['next_step'],
				'strength'  => $item['strength'],
			);
		}
		return array(
			'criteria'         => $items,
			'next_steps'       => $normalized->profile()['next_steps'],
			'overall_feedback' => $normalized->profile()['overall_feedback'],
			'visibility'       => 'shown',
		);
	}

	/**
	 * Reject invalid student safe.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @return void
	 */
	private function assert_student_safe( array $projection ): void {
		$forbidden = array( 'answer', 'answer_key', 'binding_key', 'confidence', 'criterion_id', 'model_key', 'provider_key', 'reason', 'reason_code', 'reason_note', 'response_id', 'rubric', 'safety_flags', 'student_note', 'teacher_memo' );
		$walk      = function ( $node ) use ( &$walk, $forbidden ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => $value ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
					throw new InvalidArgumentException( 'Submission result projection contains a forbidden field.' );
				}
				$walk( $value );
			}
		};
		$walk( $projection );
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'Submission result text is invalid.' );
		} return trim( $value );
	}
	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission result hash is invalid.' );
		} return $value;
	}
	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Submission result decimal is invalid.' );
		} return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}
	/**
	 * Decimal units.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function decimal_units( string $value ): int {
		[ $whole, $fraction ] = explode( '.', $value );
		return ( (int) $whole * 10000 ) + (int) $fraction;
	}
}

<?php
/**
 * Deterministic student-safe feedback-draft input builder.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic student-safe feedback-draft input builder.
 */
final class Submission_Feedback_Input_Builder {
	public const OUTER_INPUT_CONTRACT = 'student_submission_assessment.v1';
	public const OPERATION            = 'feedback_draft';
	public const OPERATION_CONTRACT   = 'submission_feedback.v1';
	public const REQUEST_SCHEMA       = 'oj_ai.submission_feedback_request';
	public const REQUEST_VERSION      = '1.0.0';
	public const OPERATION_SCHEMA     = 'oj_ai.submission_feedback_request@1.0.0';
	public const OUTPUT_SCHEMA        = 'oj_ai.submission_feedback_output';
	public const OUTPUT_VERSION       = '1.0.0';
	public const RESULT_SCHEMA        = 'oj_ai.submission_feedback_result';
	public const RESULT_VERSION       = '1.0.0';
	public const PROMPT_CONTRACT      = 'student_submission_assessment_prompt.v10';
	public const PROMPT_SECTION       = 'submission_feedback_prompt.v1';
	public const PROMPT_CONTENT_HASH  = '3db2e87732bcc18606a3c00acd4d9c1873dc05e21d1dd9aeeee07330172fccd6';

	public const MAXIMUM_INPUT_BYTES                   = 524288;
	public const MAXIMUM_CRITERION_FEEDBACK_CHARACTERS = 600;
	public const MAXIMUM_OVERALL_FEEDBACK_CHARACTERS   = 2000;
	public const MAXIMUM_NEXT_STEPS                    = 3;
	public const MAXIMUM_NEXT_STEP_CHARACTERS          = 300;
	private const MAXIMUM_EXCERPT_CHARACTERS           = 1000;

	/**
	 * Initialize submission feedback input builder dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Build.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return Submission_Feedback_Input
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build( array $source ): Submission_Feedback_Input {
		foreach ( array( 'evaluation_id', 'official_review_id', 'official_review_hash', 'result_id', 'result_hash', 'submission_revision_hash', 'score_input', 'score_decisions' ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				throw new InvalidArgumentException( 'Submission feedback source is incomplete.' );
			}
		}
		$evaluation_id   = $this->public_id( $source['evaluation_id'] );
		$review_id       = $this->public_id( $source['official_review_id'] );
		$review_hash     = $this->sha256( $source['official_review_hash'] );
		$result_id       = $this->public_id( $source['result_id'] );
		$result_hash     = $this->sha256( $source['result_hash'] );
		$submission_hash = $this->sha256( $source['submission_revision_hash'] );
		$score_payload   = $this->score_payload( $source['score_input'] );
		$bindings        = $this->bindings( $score_payload['bindings'] ?? null );
		$responses       = $this->responses( $score_payload['responses'] ?? null );
		$criteria        = $this->criteria( $source['score_decisions'], $bindings, $responses );
		$locale          = $score_payload['subject']['locale'] ?? null;
		if ( ! is_string( $locale ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale ) ) {
			throw new InvalidArgumentException( 'Submission feedback locale is invalid.' );
		}

		$feedback_ref = 'FB_' . $this->base32( substr( hash( 'sha256', $evaluation_id . ':' . $review_id, true ), 0, 20 ) );
		$payload      = array(
			'criteria'        => $criteria,
			'instruction'     => array(
				'content_hash'     => self::PROMPT_CONTENT_HASH,
				'contract_version' => self::PROMPT_CONTRACT,
				'section_contract' => self::PROMPT_SECTION,
			),
			'operation'       => self::OPERATION,
			'output_contract' => array(
				'allowed_keys'                 => array( 'criterion_feedback', 'feedback_ref', 'next_steps', 'overall_feedback', 'safety_flags', 'schema_id', 'schema_version', 'source_hash' ),
				'criterion_feedback'           => array(
					'allowed_keys'       => array( 'binding_key', 'next_step', 'strength' ),
					'maximum_characters' => self::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS,
				),
				'maximum_next_step_characters' => self::MAXIMUM_NEXT_STEP_CHARACTERS,
				'maximum_next_steps'           => self::MAXIMUM_NEXT_STEPS,
				'maximum_overall_characters'   => self::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS,
				'schema_id'                    => self::OUTPUT_SCHEMA,
				'schema_version'               => self::OUTPUT_VERSION,
			),
			'policy'          => array(
				'learning_stage'         => 'worksheet_response_review',
				'locale'                 => $locale,
				'prohibited_expressions' => array( 'answer_disclosure', 'comparative_rank', 'diagnostic_label', 'identity_reference', 'score_change' ),
				'tone'                   => 'supportive_specific_actionable',
			),
			'schema_id'       => self::REQUEST_SCHEMA,
			'schema_version'  => self::REQUEST_VERSION,
			'source'          => array(
				'official_review_hash'    => $review_hash,
				'official_review_ref'     => $review_id,
				'result_hash'             => $result_hash,
				'result_ref'              => $result_id,
				'submission_content_hash' => $submission_hash,
			),
			'subject'         => array(
				'feedback_ref' => $feedback_ref,
				'locale'       => $locale,
				'source_hash'  => $review_hash,
			),
		);
		$payload_json = $this->canonical->encode( $payload );
		$input        = array(
			'operation'          => self::OPERATION,
			'operation_contract' => self::OPERATION_CONTRACT,
			'operation_payload'  => $payload,
			'operation_schema'   => self::OPERATION_SCHEMA,
			'payload_hash'       => hash( 'sha256', $payload_json ),
		);
		$this->assert_safe( $input );
		$json = $this->canonical->encode( $input );
		if ( strlen( $json ) > self::MAXIMUM_INPUT_BYTES ) {
			throw new InvalidArgumentException( 'Submission feedback input is too large.' );
		}
		return new Submission_Feedback_Input( $input, $json, hash( 'sha256', $json ), $review_id, $review_hash, $result_id, $submission_hash );
	}

	/**
	 * Score payload.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_payload( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Stored score input is invalid.' );
		}
		$this->exact_keys( $value, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) );
		$payload = $value['operation_payload'] ?? null;
		if (
			Submission_Score_Input_Builder::OPERATION !== ( $value['operation'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $value['operation_contract'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_SCHEMA !== ( $value['operation_schema'] ?? null )
			|| ! is_array( $payload ) || array_is_list( $payload )
			|| ! hash_equals( $this->sha256( $value['payload_hash'] ?? null ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			throw new InvalidArgumentException( 'Stored score input contract is invalid.' );
		}
		return $payload;
	}

	/**
	 * Bindings.
	 *
	 * @param mixed $value Value.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bindings( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS ) {
			throw new InvalidArgumentException( 'Stored score bindings are invalid.' );
		}
		$result = array();
		foreach ( $value as $position => $binding ) {
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				throw new InvalidArgumentException( 'Stored score binding is invalid.' );
			}
			foreach ( array( 'binding_key', 'criterion_id', 'label', 'maximum_points', 'position', 'response_id', 'scope' ) as $key ) {
				if ( ! array_key_exists( $key, $binding ) ) {
					throw new InvalidArgumentException( 'Stored score binding is incomplete.' );
				}
			}
			$key = $this->sha256( $binding['binding_key'] );
			if ( isset( $result[ $key ] ) || $position !== $binding['position'] ) {
				throw new InvalidArgumentException( 'Stored score binding identity is invalid.' );
			}
			$this->public_id( $binding['criterion_id'] );
			if ( null !== $binding['response_id'] ) {
				$this->public_id( $binding['response_id'] );
			}
			if ( ! is_string( $binding['label'] ) || '' === trim( $binding['label'] ) || strlen( $binding['label'] ) > 255 ) {
				throw new InvalidArgumentException( 'Stored criterion label is invalid.' );
			}
			$this->decimal( $binding['maximum_points'] );
			if ( ! in_array( $binding['scope'], array( 'response', 'submission' ), true ) ) {
				throw new InvalidArgumentException( 'Stored criterion scope is invalid.' );
			}
			$result[ $key ] = $binding;
		}
		return $result;
	}

	/**
	 * Responses.
	 *
	 * @param mixed $value Value.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function responses( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > Submission_Score_Input_Builder::MAXIMUM_RESPONSES ) {
			throw new InvalidArgumentException( 'Stored score responses are invalid.' );
		}
		$result = array();
		foreach ( $value as $response ) {
			if ( ! is_array( $response ) || array_is_list( $response ) ) {
				throw new InvalidArgumentException( 'Stored score response is invalid.' );
			}
			$id = $this->public_id( $response['response_id'] ?? null );
			if ( isset( $result[ $id ] ) || ! is_string( $response['kind'] ?? null ) || ! is_array( $response['answer'] ?? null ) ) {
				throw new InvalidArgumentException( 'Stored score response identity is invalid.' );
			}
			$result[ $id ] = $response;
		}
		return $result;
	}

	/**
	 * Criteria.
	 *
	 * @param mixed                             $value Value.
	 * @param array<string,array<string,mixed>> $bindings Bindings.
	 * @param array<string,array<string,mixed>> $responses Responses.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function criteria( $value, array $bindings, array $responses ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) !== count( $bindings ) ) {
			throw new InvalidArgumentException( 'Official score decisions are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $decision ) {
			if ( ! is_array( $decision ) || array_is_list( $decision ) ) {
				throw new InvalidArgumentException( 'Official score decision is invalid.' );
			}
			foreach ( array( 'binding_key', 'official_points', 'maximum_points', 'position', 'response_id', 'scope' ) as $key ) {
				if ( ! array_key_exists( $key, $decision ) ) {
					throw new InvalidArgumentException( 'Official score decision is incomplete.' );
				}
			}
			$key = $this->sha256( $decision['binding_key'] );
			if ( isset( $seen[ $key ] ) || ! isset( $bindings[ $key ] ) ) {
				throw new InvalidArgumentException( 'Official score decision binding is invalid.' );
			}
			$binding = $bindings[ $key ];
			if (
				$decision['position'] !== $binding['position']
				|| $decision['response_id'] !== $binding['response_id']
				|| $decision['scope'] !== $binding['scope']
				|| $this->decimal( $decision['maximum_points'] ) !== $this->decimal( $binding['maximum_points'] )
			) {
				throw new InvalidArgumentException( 'Official score decision source is inconsistent.' );
			}
			$points = $this->decimal( $decision['official_points'] );
			if ( $this->decimal_units( $points ) > $this->decimal_units( $this->decimal( $binding['maximum_points'] ) ) ) {
				throw new InvalidArgumentException( 'Official score decision exceeds its maximum.' );
			}
			$response_id = $binding['response_id'];
			$excerpt     = null;
			if ( is_string( $response_id ) && isset( $responses[ $response_id ] ) ) {
				$excerpt = $this->excerpt( $responses[ $response_id ] );
			}
			$result[]     = array(
				'binding_key'      => $key,
				'criterion_label'  => trim( (string) $binding['label'] ),
				'maximum_points'   => $this->decimal( $binding['maximum_points'] ),
				'official_points'  => $points,
				'response_anchor'  => $response_id,
				'response_excerpt' => $excerpt,
			);
			$seen[ $key ] = true;
		}
		if ( count( $seen ) !== count( $bindings ) ) {
			throw new InvalidArgumentException( 'Official score decisions are incomplete.' );
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['binding_key'], $right['binding_key'] ) );
		return $result;
	}

	/**
	 * Excerpt.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string|null
	 */
	private function excerpt( array $response ): ?string {
		if ( in_array( $response['kind'] ?? null, array( 'image_upload', 'file_upload', 'drawing' ), true ) ) {
			return null;
		}
		$answer = $response['answer'] ?? null;
		if ( ! is_array( $answer ) || array( 'value' => null ) === $answer ) {
			return null;
		}
		$text = $this->canonical->encode( $answer );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, self::MAXIMUM_EXCERPT_CHARACTERS, 'UTF-8' ); } else {
			$text = substr( $text, 0, self::MAXIMUM_EXCERPT_CHARACTERS );
			}
			return '' === trim( $text ) ? null : $text;
	}

	/**
	 * Reject invalid safe.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 */
	private function assert_safe( array $value ): void {
		$forbidden = array( 'student_id', 'student_name', 'student_email', 'student_login', 'email', 'login', 'correct_answer', 'example_answer', 'answer_key', 'rubric', 'reason_code', 'reason_note', 'teacher_note', 'teacher_memo', 'provider_key', 'model_key', 'secret', 'routing_revision' );
		$walk      = function ( $node ) use ( &$walk, $forbidden ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => $child ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
					throw new InvalidArgumentException( 'Submission feedback input contains a forbidden field.' );
				}
				$walk( $child );
			}
		};
		$walk( $value );
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
			throw new InvalidArgumentException( 'Public ID is invalid.' );
		} new Public_Id( $value );
		return $value;
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
			throw new InvalidArgumentException( 'Hash is invalid.' );
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $m ) ) {
			throw new InvalidArgumentException( 'Decimal is invalid.' );
		} return $m[1] . '.' . str_pad( $m[2] ?? '', 4, '0' );
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
			throw new InvalidArgumentException( 'Object shape is invalid.' );
		}
	}
	/**
	 * Base32.
	 *
	 * @param string $binary Binary.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function base32( string $binary ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$buffer   = 0;
		$bits     = 0;
		$output   = '';
		foreach ( (array) unpack( 'C*', $binary ) as $byte ) {
			$buffer = ( $buffer << 8 ) | $byte;
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits   -= 5;
				$output .= $alphabet[ ( $buffer >> $bits ) & 31 ];
			} $buffer = 0 === $bits ? 0 : $buffer & ( ( 1 << $bits ) - 1 );
		} if ( 32 !== strlen( $output ) ) {
			throw new InvalidArgumentException( 'Feedback reference is invalid.' );
		} return $output;
	}
}

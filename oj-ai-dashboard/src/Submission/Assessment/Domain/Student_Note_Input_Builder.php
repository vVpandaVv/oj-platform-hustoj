<?php
/**
 * Deterministic, non-identifying student special-note input builder.
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
 * Deterministic, non-identifying student special-note input builder.
 */
final class Student_Note_Input_Builder {
	public const OUTER_INPUT_CONTRACT = 'student_submission_assessment.v1';
	public const OPERATION            = 'special_note_draft';
	public const OPERATION_CONTRACT   = 'student_special_note.v1';
	public const REQUEST_SCHEMA       = 'oj_ai.student_special_note_request';
	public const REQUEST_VERSION      = '1.0.0';
	public const OPERATION_SCHEMA     = 'oj_ai.student_special_note_request@1.0.0';
	public const OUTPUT_SCHEMA        = 'oj_ai.student_special_note_output';
	public const OUTPUT_VERSION       = '1.0.0';
	public const RESULT_SCHEMA        = 'oj_ai.student_special_note_result';
	public const RESULT_VERSION       = '1.0.0';
	public const PROMPT_CONTRACT      = 'student_submission_assessment_prompt.v10';
	public const PROMPT_SECTION       = 'submission_special_note_prompt.v1';
	public const PROMPT_CONTENT_HASH  = '3db2e87732bcc18606a3c00acd4d9c1873dc05e21d1dd9aeeee07330172fccd6';

	public const MAXIMUM_INPUT_BYTES                     = 32768;
	public const MAXIMUM_EVIDENCE                        = 20;
	public const MAXIMUM_EVIDENCE_OBSERVATION_CHARACTERS = 700;
	public const MAXIMUM_SUMMARY_CHARACTERS              = 2000;
	public const MAXIMUM_STRENGTHS                       = 3;
	public const MAXIMUM_SUPPORT_SUGGESTIONS             = 3;
	public const MAXIMUM_ITEM_CHARACTERS                 = 500;
	private const MAXIMUM_EXCERPT_CHARACTERS             = 1000;

	/**
	 * Initialize student note input builder dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Build.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return Student_Note_Input
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build( array $source ): Student_Note_Input {
		foreach ( array( 'evaluation_id', 'official_review_id', 'official_review_hash', 'result_id', 'result_hash', 'submission_revision_hash', 'score_input', 'score_decisions' ) as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				throw new InvalidArgumentException( 'Student note source is incomplete.' );
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
		$evidence        = $this->evidence( $source['score_decisions'], $bindings, $responses, $evaluation_id, $review_id );
		$locale          = $score_payload['subject']['locale'] ?? null;
		if ( ! is_string( $locale ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale ) ) {
			throw new InvalidArgumentException( 'Student note locale is invalid.' );
		}

		$note_ref = 'SN_' . $this->base32( substr( hash( 'sha256', $evaluation_id . ':' . $review_id, true ), 0, 20 ) );
		$payload  = array(
			'evidence'        => $evidence,
			'instruction'     => array(
				'content_hash'     => self::PROMPT_CONTENT_HASH,
				'contract_version' => self::PROMPT_CONTRACT,
				'section_contract' => self::PROMPT_SECTION,
			),
			'operation'       => self::OPERATION,
			'output_contract' => array(
				'allowed_keys'                => array( 'evidence', 'note_ref', 'safety_flags', 'schema_id', 'schema_version', 'source_hash', 'strengths', 'summary', 'support_suggestions' ),
				'evidence_allowed_keys'       => array( 'evidence_key', 'observation' ),
				'maximum_evidence'            => self::MAXIMUM_EVIDENCE,
				'maximum_item_characters'     => self::MAXIMUM_ITEM_CHARACTERS,
				'maximum_strengths'           => self::MAXIMUM_STRENGTHS,
				'maximum_summary_characters'  => self::MAXIMUM_SUMMARY_CHARACTERS,
				'maximum_support_suggestions' => self::MAXIMUM_SUPPORT_SUGGESTIONS,
				'narrative_allowed_keys'      => array( 'evidence_keys', 'text' ),
				'schema_id'                   => self::OUTPUT_SCHEMA,
				'schema_version'              => self::OUTPUT_VERSION,
			),
			'policy'          => array(
				'evidence_required'      => true,
				'include_in_reports'     => false,
				'locale'                 => $locale,
				'prohibited_expressions' => array( 'ability_inference', 'comparative_rank', 'diagnostic_label', 'family_circumstance_inference', 'health_inference', 'identity_reference', 'motivation_inference', 'personality_inference', 'score_disclosure' ),
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
				'locale'      => $locale,
				'note_ref'    => $note_ref,
				'source_hash' => $review_hash,
			),
		);
		// Keep historical evidence identities stable; supplement submission-wide observations separately.
		$payload['process_evidence'] = $this->process_evidence( $evidence, $responses, $payload );
		$payload_json                = $this->canonical->encode( $payload );
		$input                       = array(
			'operation'          => self::OPERATION,
			'operation_contract' => self::OPERATION_CONTRACT,
			'operation_payload'  => $payload,
			'operation_schema'   => self::OPERATION_SCHEMA,
			'payload_hash'       => hash( 'sha256', $payload_json ),
		);
		$this->assert_safe( $input );
		$json = $this->canonical->encode( $input );
		if ( strlen( $json ) > self::MAXIMUM_INPUT_BYTES ) {
			throw new InvalidArgumentException( 'Student note input is too large.' );
		}

		return new Student_Note_Input( $input, $json, hash( 'sha256', $json ), $review_id, $review_hash, $result_id, $submission_hash );
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
			$binding['label'] = $this->safe_label( $binding['label'] );
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
	 * Evidence.
	 *
	 * @param mixed                             $value Value.
	 * @param array<string,array<string,mixed>> $bindings Bindings.
	 * @param array<string,array<string,mixed>> $responses Responses.
	 * @param string                            $evaluation_id Evaluation id.
	 * @param string                            $review_id Review id.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evidence( $value, array $bindings, array $responses, string $evaluation_id, string $review_id ): array {
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
			$points  = $this->decimal( $decision['official_points'] );
			$maximum = $this->decimal( $binding['maximum_points'] );
			if ( $this->decimal_units( $points ) > $this->decimal_units( $maximum ) ) {
				throw new InvalidArgumentException( 'Official score decision exceeds its maximum.' );
			}
			$response_id = $binding['response_id'];
			$excerpt     = null;
			if ( is_string( $response_id ) && isset( $responses[ $response_id ] ) ) {
				$excerpt = $this->excerpt( $responses[ $response_id ] );
			}
			$outcome      = $this->outcome( $points, $maximum );
			$observation  = 'Official review outcome for "' . $binding['label'] . '": ' . $outcome . '.';
			$evidence_key = hash( 'sha256', $this->canonical->encode( array( $evaluation_id, $review_id, $key, $response_id, $excerpt, $outcome ) ) );
			$result[]     = array(
				'criterion_label'   => $binding['label'],
				'criterion_outcome' => $outcome,
				'evidence_key'      => $evidence_key,
				'known_observation' => $observation,
				'response_anchor'   => $response_id,
				'response_excerpt'  => $excerpt,
			);
			$seen[ $key ] = true;
		}
		if ( count( $seen ) !== count( $bindings ) ) {
			throw new InvalidArgumentException( 'Official score decisions are incomplete.' );
		}
		usort( $result, static fn ( array $left, array $right ): int => strcmp( $left['evidence_key'], $right['evidence_key'] ) );
		return array_slice( $result, 0, self::MAXIMUM_EVIDENCE );
	}

	/**
	 * Bounded excerpts supporting submission-wide evidence, without duplicating response evidence.
	 *
	 * @param array<int,array<string,mixed>>    $evidence Evidence.
	 * @param array<string,array<string,mixed>> $responses Responses.
	 * @param array<string,mixed>               $payload Base payload.
	 * @return array<int,array<string,mixed>>
	 */
	private function process_evidence( array $evidence, array $responses, array $payload ): array {
		$keys = array_column( array_filter( $evidence, static fn ( array $item ): bool => null === $item['response_anchor'] ), 'evidence_key' );
		if ( array() === $keys ) {
			return array();
		}
		$budget = min( 6000, self::MAXIMUM_INPUT_BYTES - strlen( $this->canonical->encode( $payload ) ) - 600 );
		$result = array();
		foreach ( $responses as $id => $response ) {
			$excerpt = $this->excerpt( $response );
			if ( null === $excerpt ) {
				continue;
			}
			$candidate   = $result;
			$candidate[] = array(
				'evidence_keys'    => $keys,
				'response_anchor'  => $id,
				'response_excerpt' => $excerpt,
			);
			if ( strlen( $this->canonical->encode( $candidate ) ) > $budget ) {
				continue;
			}
			$result = $candidate;
			if ( count( $result ) >= self::MAXIMUM_EVIDENCE ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Outcome.
	 *
	 * @param string $points Points.
	 * @param string $maximum Maximum.
	 * @return string
	 */
	private function outcome( string $points, string $maximum ): string {
		$point_units   = $this->decimal_units( $points );
		$maximum_units = $this->decimal_units( $maximum );
		if ( $point_units <= 0 || $maximum_units <= 0 ) {
			return 'not_observed';
		}
		return $point_units === $maximum_units ? 'demonstrated' : 'developing';
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
		$text         = $this->canonical->encode( $answer );
		$patterns     = array(
			'/<[^>]*>/u',
			'/\b(?:https?:\/\/|www\.)\S+/iu',
			'/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
			'/(?<!\d)(?:\+?82[- ]?)?0?1[016789][- ]?\d{3,4}[- ]?\d{4}(?!\d)/u',
			'/(?<!\d)\d{6}[- ]?[1-4]\d{6}(?!\d)/u',
		);
		$replacements = array( ' ', '[link removed]', '[email removed]', '[phone removed]', '[identifier removed]' );
		$text         = preg_replace( $patterns, $replacements, $text );
		if ( ! is_string( $text ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text ) ) {
			return null;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, self::MAXIMUM_EXCERPT_CHARACTERS, 'UTF-8' ); } else {
			$text = substr( $text, 0, self::MAXIMUM_EXCERPT_CHARACTERS );
			}
			$text = trim( $text );
			return '' === $text ? null : $text;
	}

	/**
	 * Safe label.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_label( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Stored criterion label is invalid.' );
		}
		$value = trim( $value );
		if ( '' === $value || $this->length( $value ) > 255 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]|(?:https?:\/\/|www\.)|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $value ) ) {
			throw new InvalidArgumentException( 'Stored criterion label is unsafe.' );
		}
		return $value;
	}

	/**
	 * Reject invalid safe.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 */
	private function assert_safe( array $value ): void {
		$forbidden = array( 'answer_key', 'class_name', 'correct_answer', 'email', 'example_answer', 'group_name', 'login', 'maximum_points', 'model_key', 'official_points', 'provider_key', 'rank', 'reason_code', 'reason_note', 'rubric', 'score', 'secret', 'student_email', 'student_id', 'student_login', 'student_name', 'teacher_memo', 'teacher_note' );
		$walk      = function ( $node ) use ( &$walk, $forbidden ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => $child ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
					throw new InvalidArgumentException( 'Student note input contains a forbidden field.' );
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Decimal is invalid.' );
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
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
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
			throw new InvalidArgumentException( 'Student note reference is invalid.' );
		} return $output;
	}
}

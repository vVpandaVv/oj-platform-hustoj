<?php
/**
 * Strict feedback-draft output normalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict feedback-draft output normalizer.
 */
final class Submission_Feedback_Output_Normalizer {
	public const MAXIMUM_OUTPUT_BYTES = 65536;
	public const SAFETY_FLAGS         = array( 'answer_disclosure_risk', 'comparative_language', 'identity_reference', 'stigmatizing_language', 'unsafe_instruction', 'unsupported_claim' );

	/**
	 * Initialize submission feedback output normalizer dependencies and state.
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
	 * @return Submission_Feedback_Normalized_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function normalize( array $output, array $input ): Submission_Feedback_Normalized_Result {
		$json = $this->canonical->encode( $output );
		if ( strlen( $json ) > self::MAXIMUM_OUTPUT_BYTES ) {
			throw new InvalidArgumentException( 'Submission feedback output is too large.' );
		}
		$this->exact_keys( $output, array( 'criterion_feedback', 'feedback_ref', 'next_steps', 'overall_feedback', 'safety_flags', 'schema_id', 'schema_version', 'source_hash' ) );
		$payload = $this->input_payload( $input );
		$subject = $payload['subject'] ?? null;
		if ( ! is_array( $subject ) || array_is_list( $subject ) ) {
			throw new InvalidArgumentException( 'Submission feedback input subject is invalid.' );
		}
		if (
			Submission_Feedback_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null )
			|| Submission_Feedback_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null )
			|| ! hash_equals( (string) ( $subject['feedback_ref'] ?? '' ), (string) ( $output['feedback_ref'] ?? '' ) )
			|| ! hash_equals( (string) ( $subject['source_hash'] ?? '' ), (string) ( $output['source_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Submission feedback output source is invalid.' );
		}

		$criteria  = $this->criterion_index( $payload['criteria'] ?? null );
		$raw_items = $output['criterion_feedback'] ?? null;
		if ( ! is_array( $raw_items ) || ! array_is_list( $raw_items ) || count( $raw_items ) !== count( $criteria ) ) {
			throw new InvalidArgumentException( 'Submission feedback criteria are incomplete.' );
		}
		$items = array();
		$seen  = array();
		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
				throw new InvalidArgumentException( 'Submission feedback criterion is invalid.' );
			}
			$this->exact_keys( $raw, array( 'binding_key', 'next_step', 'strength' ) );
			$key = $this->sha256( $raw['binding_key'] ?? null );
			if ( isset( $seen[ $key ] ) || ! isset( $criteria[ $key ] ) ) {
				throw new InvalidArgumentException( 'Submission feedback binding is invalid.' );
			}
			$strength = $this->text( $raw['strength'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
			$next     = $this->text( $raw['next_step'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
			if ( $this->length( $strength ) + $this->length( $next ) > Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS ) {
				throw new InvalidArgumentException( 'Submission feedback criterion is too long.' );
			}
			$items[]      = array(
				'binding_key' => $key,
				'next_step'   => $next,
				'strength'    => $strength,
			);
			$seen[ $key ] = true;
		}
		usort( $items, static fn ( array $left, array $right ): int => strcmp( $left['binding_key'], $right['binding_key'] ) );
		$overall      = $this->text( $output['overall_feedback'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS );
		$next_steps   = $this->text_list( $output['next_steps'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEPS, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEP_CHARACTERS );
		$flags        = $this->flags( $output['safety_flags'] ?? null );
		$profile      = array(
			'criterion_feedback' => $items,
			'feedback_ref'       => (string) $output['feedback_ref'],
			'next_steps'         => $next_steps,
			'overall_feedback'   => $overall,
			'safety_flags'       => $flags,
			'schema_id'          => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
			'schema_version'     => Submission_Feedback_Input_Builder::RESULT_VERSION,
			'source_hash'        => (string) $output['source_hash'],
		);
		$profile_json = $this->canonical->encode( $profile );
		return new Submission_Feedback_Normalized_Result( $profile, $profile_json, hash( 'sha256', $profile_json ), hash( 'sha256', $json ), array() !== $flags );
	}

	/**
	 * Input payload.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input_payload( array $input ): array {
		$this->exact_keys( $input, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) );
		$payload = $input['operation_payload'] ?? null;
		if (
			Submission_Feedback_Input_Builder::OPERATION !== ( $input['operation'] ?? null )
			|| Submission_Feedback_Input_Builder::OPERATION_CONTRACT !== ( $input['operation_contract'] ?? null )
			|| Submission_Feedback_Input_Builder::OPERATION_SCHEMA !== ( $input['operation_schema'] ?? null )
			|| ! is_array( $payload ) || array_is_list( $payload )
			|| ! hash_equals( $this->sha256( $input['payload_hash'] ?? null ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			throw new InvalidArgumentException( 'Submission feedback input contract is invalid.' );
		}
		return $payload;
	}

	/**
	 * Criterion index.
	 *
	 * @param mixed $value Value.
	 * @return array<string,bool>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function criterion_index( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value ) {
			throw new InvalidArgumentException( 'Submission feedback input criteria are invalid.' );
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission feedback input criterion is invalid.' );
			} $key = $this->sha256( $item['binding_key'] ?? null );
			if ( isset( $result[ $key ] ) ) {
				throw new InvalidArgumentException( 'Submission feedback input binding is duplicated.' );
			} $result[ $key ] = true;
		} return $result;
	}

	/**
	 * Text list.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum_count Maximum count.
	 * @param int   $maximum_length Maximum length.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_list( $value, int $maximum_count, int $maximum_length ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum_count ) {
			throw new InvalidArgumentException( 'Submission feedback text list is invalid.' );
		} $result = array();
		foreach ( $value as $item ) {
			$text = $this->text( $item, $maximum_length );
			if ( in_array( $text, $result, true ) ) {
				throw new InvalidArgumentException( 'Submission feedback text is duplicated.' );
			} $result[] = $text;
		} return $result;
	}
	/**
	 * Flags.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function flags( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > count( self::SAFETY_FLAGS ) ) {
			throw new InvalidArgumentException( 'Submission feedback safety flags are invalid.' );
		} $result = array();
		foreach ( $value as $flag ) {
			if ( ! is_string( $flag ) || ! in_array( $flag, self::SAFETY_FLAGS, true ) || in_array( $flag, $result, true ) ) {
				throw new InvalidArgumentException( 'Submission feedback safety flag is invalid.' );
			} $result[] = $flag;
		} sort( $result, SORT_STRING );
		return $result;
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
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback text is invalid.' );
		} $value = trim( $value );
		if ( '' === $value || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback text is invalid.' );
		} return $value;
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
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission feedback hash is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'Submission feedback object shape is invalid.' );
		}
	}
}

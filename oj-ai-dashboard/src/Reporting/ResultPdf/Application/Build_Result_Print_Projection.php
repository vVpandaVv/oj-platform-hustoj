<?php
/**
 * Builds the canonical student result print projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Own_Result_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pins the exact Q33 public projection before C52 creates an asynchronous job.
 */
final class Build_Result_Print_Projection {
	public const ARTIFACT_PROFILE_VERSION = 'result_print.v1';
	public const SCHEMA                   = 'oj_ai.result_print';
	public const VERSION                  = '1.0.0';
	public const PROJECTOR_VERSION        = '1.0.0';
	public const PROFILE                  = 'student_result';
	public const PAGE_PROFILE             = 'worksheet_a4@1';

	/**
	 * Initialize result print projection dependencies and state.
	 *
	 * @param Own_Result_Pdf_Source_Query_Port_Interface $sources Sources.
	 * @param Canonical_Json                             $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Own_Result_Pdf_Source_Query_Port_Interface $sources,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @param string $expected_release_hash Expected release hash.
	 * @param string $locale Locale.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		int $actor_user_id,
		string $release_id,
		string $expected_release_hash,
		string $locale = 'ko-KR'
	): array {
		$this->assert_request( $actor_user_id, $release_id, $expected_release_hash, $locale );
		$source = $this->sources->find_current_for_actor( $actor_user_id, $release_id );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::NOT_FOUND );
		}

		$this->assert_source_shape( $source );
		$current_id    = (string) $source['release_id'];
		$current_hash  = (string) $source['release_hash'];
		$current_state = (string) $source['release_state'];
		if (
			! hash_equals( $release_id, $current_id )
			|| ! hash_equals( $expected_release_hash, $current_hash )
			|| ! in_array( $current_state, array( 'released', 'corrected' ), true )
			|| ! is_array( $source['projection'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_STALE );
		}

		$projection = $source['projection'];
		$this->assert_public_projection( $projection, $current_state, (string) $source['released_at_utc'] );
		$projection_json = $this->canonical->encode( $projection );
		if (
			strlen( $projection_json ) > Submission_Result_Projection::MAXIMUM_BYTES
			|| ! hash_equals( (string) $source['projection_hash'], hash( 'sha256', $projection_json ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}

		$payload          = array(
			'profile' => self::PROFILE,
			'result'  => $projection,
		);
		$payload_json     = $this->canonical->encode( $payload );
		$envelope         = array(
			'schema_id'         => self::SCHEMA,
			'schema_version'    => self::VERSION,
			'projector_version' => self::PROJECTOR_VERSION,
			'source_hash'       => $current_hash,
			'payload_hash'      => hash( 'sha256', $payload_json ),
			'payload'           => $payload,
		);
		$print_projection = array(
			'kind'                    => 'result_print',
			'profile'                 => self::PROFILE,
			'result_print_projection' => $envelope,
		);

		return array(
			'source'  => array(
				'type'  => 'submission_release',
				'id'    => $current_id,
				'hash'  => $current_hash,
				'state' => $current_state,
			),
			'release' => array( 'released_at_utc' => (string) $source['released_at_utc'] ),
			'print'   => array(
				'artifact_profile_version' => self::ARTIFACT_PROFILE_VERSION,
				'schema_id'                => self::SCHEMA,
				'schema_version'           => self::VERSION,
				'projector_version'        => self::PROJECTOR_VERSION,
				'profile'                  => self::PROFILE,
				'page_profile'             => self::PAGE_PROFILE,
				'locale'                   => $locale,
				'profile_hash'             => hash( 'sha256', $this->canonical->encode( $envelope ) ),
				'projection_hash'          => hash( 'sha256', $this->canonical->encode( $print_projection ) ),
				'projection'               => $print_projection,
			),
		);
	}

	/**
	 * Reject invalid request.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @param string $expected_hash Expected hash.
	 * @param string $locale Locale.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_request( int $actor_user_id, string $release_id, string $expected_hash, string $locale ): void {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Result PDF actor is invalid.' );
		}
		new Public_Id( $release_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ) {
			throw new InvalidArgumentException( 'Result PDF source hash is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale ) ) {
			throw new InvalidArgumentException( 'Result PDF locale is invalid.' );
		}
	}

	/**
	 * Reject invalid source shape.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_shape( array $source ): void {
		$this->exact_keys( $source, array( 'projection', 'projection_hash', 'release_hash', 'release_id', 'release_state', 'released_at_utc' ) );
		$this->public_id( $source['release_id'] ?? null );
		$this->hash_value( $source['release_hash'] ?? null );
		$this->datetime( $source['released_at_utc'] ?? null );
		if ( ! in_array( $source['release_state'] ?? null, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		if ( null !== $source['projection_hash'] ) {
			$this->hash_value( $source['projection_hash'] );
		}
		if ( null !== $source['projection'] && ( ! is_array( $source['projection'] ) || array_is_list( $source['projection'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Reject invalid public projection.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $release_state Release state.
	 * @param string              $released_at_utc Released at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_public_projection( array $projection, string $release_state, string $released_at_utc ): void {
		$this->exact_keys( $projection, array( 'feedback', 'release', 'schema_id', 'schema_version', 'score', 'worksheet' ) );
		if ( Submission_Result_Projection::SCHEMA !== ( $projection['schema_id'] ?? null ) || Submission_Result_Projection::SCHEMA_VERSION !== ( $projection['schema_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}

		$release = $this->map( $projection['release'] ?? null );
		$this->exact_keys( $release, array( 'released_at_utc', 'state' ) );
		if ( $release_state !== ( $release['state'] ?? null ) || $released_at_utc !== ( $release['released_at_utc'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		$this->datetime( $release['released_at_utc'] );

		$worksheet = $this->map( $projection['worksheet'] ?? null );
		$this->exact_keys( $worksheet, array( 'title' ) );
		$this->text( $worksheet['title'] ?? null, 255, false );

		$score = $this->map( $projection['score'] ?? null );
		$this->exact_keys( $score, array( 'criteria', 'total', 'visibility' ) );
		$score_visibility = $score['visibility'] ?? null;
		if ( ! in_array( $score_visibility, Submission_Result_Projection_Builder::SCORE_VISIBILITIES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		$criteria = $this->list_value( $score['criteria'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
		$total    = null === ( $score['total'] ?? null ) ? null : $this->decimal( $score['total'] );
		if (
			( 'hidden' === $score_visibility && ( null !== $total || array() !== $criteria ) )
			|| ( 'total' === $score_visibility && ( null === $total || array() !== $criteria ) )
			|| ( 'total_and_criteria' === $score_visibility && ( null === $total || array() === $criteria ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		$total_units = 0;
		foreach ( $criteria as $criterion ) {
			$criterion = $this->map( $criterion );
			$this->exact_keys( $criterion, array( 'label', 'maximum_points', 'points' ) );
			$this->text( $criterion['label'] ?? null, 255, false );
			$maximum = $this->decimal( $criterion['maximum_points'] ?? null );
			$points  = $this->decimal( $criterion['points'] ?? null );
			if ( $this->decimal_units( $points ) > $this->decimal_units( $maximum ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
			}
			$total_units += $this->decimal_units( $points );
		}
		if ( array() !== $criteria && null !== $total && $total_units !== $this->decimal_units( $total ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}

		$feedback = $this->map( $projection['feedback'] ?? null );
		$this->exact_keys( $feedback, array( 'criteria', 'next_steps', 'overall_feedback', 'visibility' ) );
		$feedback_visibility = $feedback['visibility'] ?? null;
		if ( ! in_array( $feedback_visibility, Submission_Result_Projection_Builder::FEEDBACK_VISIBILITIES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		$feedback_items = $this->list_value( $feedback['criteria'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
		$next_steps     = $this->list_value( $feedback['next_steps'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEPS );
		$overall        = $feedback['overall_feedback'] ?? null;
		if ( 'hidden' === $feedback_visibility ) {
			if ( array() !== $feedback_items || array() !== $next_steps || null !== $overall ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
			}
		} else {
			if ( array() === $feedback_items ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
			}
			if ( null !== $overall ) {
				$this->text( $overall, Submission_Feedback_Input_Builder::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS, true );
			}
			foreach ( $feedback_items as $item ) {
				$item = $this->map( $item );
				$this->exact_keys( $item, array( 'label', 'next_step', 'strength' ) );
				$this->text( $item['label'] ?? null, 255, false );
				$next     = $this->text( $item['next_step'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS, false );
				$strength = $this->text( $item['strength'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS, false );
				if ( $this->length( $next ) + $this->length( $strength ) > Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
				}
			}
			$seen = array();
			foreach ( $next_steps as $step ) {
				$step = $this->text( $step, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEP_CHARACTERS, false );
				if ( isset( $seen[ $step ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
				}
				$seen[ $step ] = true;
			}
		}
		if ( 'hidden' === $score_visibility && 'hidden' === $feedback_visibility ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Map result print projection.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}

	/**
	 * List value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return array<int,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_value( mixed $value, int $maximum ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
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
	private function datetime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_empty Allow empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, int $maximum, bool $allow_empty ): string {
		if ( ! is_string( $value ) || ( ! $allow_empty && '' === trim( $value ) ) || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}
		return $value;
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
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}

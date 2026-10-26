<?php
/**
 * Result pdf request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Domain;

use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable C52 source, authorization Context, and public print projection.
 */
final class Result_Pdf_Request_Snapshot {
	public const SCHEMA           = 'oj_ai.result_pdf_request';
	public const VERSION          = '1.0.0';
	public const DATABASE_VERSION = 1;
	public const MAX_BYTES        = 1_048_576;

	/**
	 * Initialize result pdf request snapshot dependencies and state.
	 *
	 * @param array  $value Value.
	 * @param string $json Json.
	 * @param string $hash Hash.
	 */
	private function __construct( private array $value, private string $json, private string $hash ) {
	}

	/**
	 * Freeze.
	 *
	 * @param array<string,mixed>    $projection Projection.
	 * @param array<array-key,mixed> $context Access or execution context for this operation.
	 * @param string                 $timezone Timezone.
	 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $projection, array $context, string $timezone, Canonical_Json $canonical ): self {
		new DateTimeZone( $timezone );
		$source  = self::map( $projection['source'] ?? null );
		$release = self::map( $projection['release'] ?? null );
		$print   = self::map( $projection['print'] ?? null );
		$value   = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'            => $source['type'] ?? null,
				'id'              => $source['id'] ?? null,
				'hash'            => $source['hash'] ?? null,
				'state'           => $source['state'] ?? null,
				'released_at_utc' => $release['released_at_utc'] ?? null,
			),
			'context'        => array(
				'type'     => $context['type'] ?? null,
				'id'       => $context['id'] ?? null,
				'revision' => $context['revision'] ?? null,
			),
			'print'          => array(
				'artifact_profile_version' => $print['artifact_profile_version'] ?? null,
				'schema_id'                => $print['schema_id'] ?? null,
				'schema_version'           => $print['schema_version'] ?? null,
				'projector_version'        => $print['projector_version'] ?? null,
				'profile'                  => $print['profile'] ?? null,
				'page_profile'             => $print['page_profile'] ?? null,
				'locale'                   => $print['locale'] ?? null,
				'timezone'                 => $timezone,
				'profile_hash'             => $print['profile_hash'] ?? null,
				'projection_hash'          => $print['projection_hash'] ?? null,
				'projection'               => $print['projection'] ?? null,
			),
		);
		self::assert_value( $value, $canonical );
		$json = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Result PDF snapshot is too large.' );
		}
		return new self( $value, $json, hash( 'sha256', $json ) );
	}

	/**
	 * Restore.
	 *
	 * @param string         $json Json.
	 * @param string         $hash Hash.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function restore( string $json, string $hash, Canonical_Json $canonical ): self {
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES || ! self::is_hash( $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Result PDF snapshot integrity failed.' );
		}
		try {
			$value = json_decode( $json, true, 96, JSON_THROW_ON_ERROR ); } catch ( \JsonException ) {
			throw new InvalidArgumentException( 'Result PDF snapshot JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Result PDF snapshot contract is invalid.' );
			}
			self::assert_value( $value, $canonical );
			return new self( $value, $json, $hash );
	}

	/**
	 * Reject invalid value.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_value( array $value, Canonical_Json $canonical ): void {
		self::exact( $value, array( 'context', 'print', 'schema_id', 'schema_version', 'source' ) );
		if ( self::SCHEMA !== ( $value['schema_id'] ?? null ) || self::VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Result PDF snapshot schema is invalid.' );
		}
		$source  = self::map( $value['source'] ?? null );
		$context = self::map( $value['context'] ?? null );
		$print   = self::map( $value['print'] ?? null );
		self::exact( $source, array( 'hash', 'id', 'released_at_utc', 'state', 'type' ) );
		self::exact( $context, array( 'id', 'revision', 'type' ) );
		self::exact( $print, array( 'artifact_profile_version', 'locale', 'page_profile', 'profile', 'profile_hash', 'projection', 'projection_hash', 'projector_version', 'schema_id', 'schema_version', 'timezone' ) );
		try {
			new Public_Id( is_string( $source['id'] ?? null ) ? $source['id'] : '' );
			new DateTimeZone( is_string( $print['timezone'] ?? null ) ? $print['timezone'] : '' );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Result PDF snapshot identity is invalid.' );
		}
		if (
			'submission_release' !== ( $source['type'] ?? null )
			|| ! self::is_hash( $source['hash'] ?? null )
			|| ! in_array( $source['state'] ?? null, array( 'released', 'corrected' ), true )
			|| ! self::is_utc( $source['released_at_utc'] ?? null )
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| ! self::is_token( $context['id'] ?? null ) || ! self::is_token( $context['revision'] ?? null )
			|| Build_Result_Print_Projection::ARTIFACT_PROFILE_VERSION !== ( $print['artifact_profile_version'] ?? null )
			|| Build_Result_Print_Projection::SCHEMA !== ( $print['schema_id'] ?? null )
			|| Build_Result_Print_Projection::VERSION !== ( $print['schema_version'] ?? null )
			|| Build_Result_Print_Projection::PROJECTOR_VERSION !== ( $print['projector_version'] ?? null )
			|| Build_Result_Print_Projection::PROFILE !== ( $print['profile'] ?? null )
			|| Build_Result_Print_Projection::PAGE_PROFILE !== ( $print['page_profile'] ?? null )
			|| ! is_string( $print['locale'] ?? null ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $print['locale'] )
			|| ! self::is_hash( $print['profile_hash'] ?? null ) || ! self::is_hash( $print['projection_hash'] ?? null )
		) {
			throw new InvalidArgumentException( 'Result PDF snapshot values are invalid.' );
		}

		$projection = self::map( $print['projection'] ?? null );
		self::exact( $projection, array( 'kind', 'profile', 'result_print_projection' ) );
		$envelope = self::map( $projection['result_print_projection'] ?? null );
		self::exact( $envelope, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
		$payload = self::map( $envelope['payload'] ?? null );
		self::exact( $payload, array( 'profile', 'result' ) );
		$result = self::map( $payload['result'] ?? null );
		self::assert_public_result( $result, (string) $source['state'], (string) $source['released_at_utc'] );
		if (
			'result_print' !== ( $projection['kind'] ?? null )
			|| Build_Result_Print_Projection::PROFILE !== ( $projection['profile'] ?? null )
			|| Build_Result_Print_Projection::SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Build_Result_Print_Projection::VERSION !== ( $envelope['schema_version'] ?? null )
			|| Build_Result_Print_Projection::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| Build_Result_Print_Projection::PROFILE !== ( $payload['profile'] ?? null )
			|| ! hash_equals( (string) $source['hash'], (string) ( $envelope['source_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $envelope['payload_hash'] ?? '' ), hash( 'sha256', $canonical->encode( $payload ) ) )
			|| ! hash_equals( (string) $print['profile_hash'], hash( 'sha256', $canonical->encode( $envelope ) ) )
			|| ! hash_equals( (string) $print['projection_hash'], hash( 'sha256', $canonical->encode( $projection ) ) )
		) {
			throw new InvalidArgumentException( 'Result PDF snapshot projection is invalid.' );
		}
	}

	/**
	 * Reject invalid public result.
	 *
	 * @param array<string,mixed> $result Result.
	 * @param string              $state State.
	 * @param string              $released Released.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_public_result( array $result, string $state, string $released ): void {
		self::exact( $result, array( 'feedback', 'release', 'schema_id', 'schema_version', 'score', 'worksheet' ) );
		$release   = self::map( $result['release'] ?? null );
		$worksheet = self::map( $result['worksheet'] ?? null );
		$score     = self::map( $result['score'] ?? null );
		$feedback  = self::map( $result['feedback'] ?? null );
		self::exact( $release, array( 'released_at_utc', 'state' ) );
		self::exact( $worksheet, array( 'title' ) );
		self::exact( $score, array( 'criteria', 'total', 'visibility' ) );
		self::exact( $feedback, array( 'criteria', 'next_steps', 'overall_feedback', 'visibility' ) );
		$score_visibility    = $score['visibility'] ?? null;
		$feedback_visibility = $feedback['visibility'] ?? null;
		if (
			Submission_Result_Projection::SCHEMA !== ( $result['schema_id'] ?? null )
			|| Submission_Result_Projection::SCHEMA_VERSION !== ( $result['schema_version'] ?? null )
			|| $state !== ( $release['state'] ?? null )
			|| $released !== ( $release['released_at_utc'] ?? null )
			|| ! is_string( $worksheet['title'] ?? null ) || '' === trim( $worksheet['title'] )
			|| ! in_array( $score_visibility, Submission_Result_Projection_Builder::SCORE_VISIBILITIES, true )
			|| ! in_array( $feedback_visibility, Submission_Result_Projection_Builder::FEEDBACK_VISIBILITIES, true )
			|| ( 'hidden' === $score_visibility && 'hidden' === $feedback_visibility )
		) {
			throw new InvalidArgumentException( 'Result PDF public release is invalid.' );
		}
		if ( ! is_array( $score['criteria'] ?? null ) || ! array_is_list( $score['criteria'] ) || ! is_array( $feedback['criteria'] ?? null ) || ! array_is_list( $feedback['criteria'] ) || ! is_array( $feedback['next_steps'] ?? null ) || ! array_is_list( $feedback['next_steps'] ) ) {
			throw new InvalidArgumentException( 'Result PDF public lists are invalid.' );
		}
		foreach ( $score['criteria'] as $criterion ) {
			$criterion = self::map( $criterion );
			self::exact( $criterion, array( 'label', 'maximum_points', 'points' ) );
			self::strings( $criterion );
		}
		foreach ( $feedback['criteria'] as $criterion ) {
			$criterion = self::map( $criterion );
			self::exact( $criterion, array( 'label', 'next_step', 'strength' ) );
			self::strings( $criterion );
		}
		foreach ( $feedback['next_steps'] as $step ) {
			if ( ! is_string( $step ) ) {
				throw new InvalidArgumentException( 'Result PDF next step is invalid.' );
			}
		}
		if ( null !== ( $score['total'] ?? null ) && ! is_string( $score['total'] ) ) {
			throw new InvalidArgumentException( 'Result PDF total is invalid.' );
		}
		if ( null !== ( $feedback['overall_feedback'] ?? null ) && ! is_string( $feedback['overall_feedback'] ) ) {
			throw new InvalidArgumentException( 'Result PDF feedback is invalid.' );
		}
		if (
			( 'hidden' === $score_visibility && ( null !== $score['total'] || array() !== $score['criteria'] ) )
			|| ( 'total' === $score_visibility && ( ! is_string( $score['total'] ) || array() !== $score['criteria'] ) )
			|| ( 'total_and_criteria' === $score_visibility && ( ! is_string( $score['total'] ) || array() === $score['criteria'] ) )
			|| ( 'hidden' === $feedback_visibility && ( null !== $feedback['overall_feedback'] || array() !== $feedback['criteria'] || array() !== $feedback['next_steps'] ) )
			|| ( 'shown' === $feedback_visibility && ( ! is_string( $feedback['overall_feedback'] ) || array() === $feedback['criteria'] ) )
		) {
			throw new InvalidArgumentException( 'Result PDF visibility projection is invalid.' );
		}
	}

	/**
	 * Strings.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function strings( array $value ): void {
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new InvalidArgumentException( 'Result PDF public text is invalid.' );
			}
		}
	}
	/**
	 * Map result pdf request snapshot.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Result PDF snapshot map is invalid.' );
		} return $value;
	}
	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Result PDF snapshot shape is invalid.' );
		}
	}
	/**
	 * Is hash.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_hash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
	/**
	 * Is utc.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_utc( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value );
	}
	/**
	 * Is token.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_token( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}

	/**
	 * Value.
	 *
	 * @return array<string,mixed>
	 */
	public function value(): array {
		return $this->value;
	}
	/**
	 * Source.
	 *
	 * @return array<string,string>
	 */
	public function source(): array {
		return $this->value['source'];
	}
	/**
	 * Context.
	 *
	 * @return array<string,string>
	 */
	public function context(): array {
		return $this->value['context'];
	}
	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return $this->value['print']['projection'];
	}
	/**
	 * Json.
	 *
	 * @return string
	 */
	public function json(): string {
		return $this->json;
	}
	/**
	 * Hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}
	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return (string) $this->value['source']['id'];
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return (string) $this->value['source']['hash'];
	}
	/**
	 * Source state.
	 *
	 * @return string
	 */
	public function source_state(): string {
		return (string) $this->value['source']['state'];
	}
	/**
	 * Locale.
	 *
	 * @return string
	 */
	public function locale(): string {
		return (string) $this->value['print']['locale'];
	}
	/**
	 * Timezone.
	 *
	 * @return string
	 */
	public function timezone(): string {
		return (string) $this->value['print']['timezone'];
	}
	/**
	 * Profile version.
	 *
	 * @return string
	 */
	public function profile_version(): string {
		return (string) $this->value['print']['artifact_profile_version'];
	}
}

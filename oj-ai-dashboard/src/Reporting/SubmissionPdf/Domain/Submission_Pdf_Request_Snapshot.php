<?php
/**
 * Immutable C49 request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Domain;

use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freezes the exact official submission, Context, publication, and print projection.
 */
final class Submission_Pdf_Request_Snapshot {
	public const SCHEMA           = 'oj_ai.submission_pdf_request';
	public const VERSION          = '1.0.0';
	public const OWN_VERSION      = '1.1.0';
	public const DATABASE_VERSION = 1;
	public const MAX_BYTES        = 8_388_608;

	/**
	 * Initialize submission pdf request snapshot dependencies and state.
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
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $timezone Timezone.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $projection, string $timezone, Canonical_Json $canonical ): self {
		new DateTimeZone( $timezone );
		$source           = self::map( $projection['source'] ?? null );
		$context          = self::map( $projection['context'] ?? null );
		$submission       = self::map( $projection['submission'] ?? null );
		$publication      = self::map( $projection['publication'] ?? null );
		$print            = self::map( $projection['print'] ?? null );
		$print_projection = self::map( $print['projection'] ?? null );
		$envelope         = self::map( $print_projection['submission_print_projection'] ?? null );
		$payload          = self::map( $envelope['payload'] ?? null );
		$provenance       = self::map( $payload['provenance'] ?? null );

		$value = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'                => $source['type'] ?? null,
				'id'                  => $source['id'] ?? null,
				'hash'                => $source['hash'] ?? null,
				'attachment_set_hash' => $provenance['attachment_set_hash'] ?? null,
			),
			'context'        => array(
				'type'     => $context['type'] ?? null,
				'id'       => $context['id'] ?? null,
				'revision' => $context['revision'] ?? null,
			),
			'submission'     => array(
				'id'           => $submission['id'] ?? null,
				'state'        => $submission['state'] ?? null,
				'lock_version' => $submission['lock_version'] ?? null,
			),
			'publication'    => array(
				'id'   => $publication['id'] ?? null,
				'hash' => $publication['hash'] ?? null,
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
				'projection'               => $print_projection,
			),
		);
		self::assert_value( $value, $canonical );
		$json = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot is too large.' );
		}
		return new self( $value, $json, hash( 'sha256', $json ) );
	}

	/**
	 * Freeze own.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param array<string,mixed> $source Source.
	 * @param string              $timezone Timezone.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze_own( array $projection, array $source, string $timezone, Canonical_Json $canonical ): self {
		self::exact(
			$source,
			array(
				'assignment_id',
				'assignment_state',
				'attachment_set_hash',
				'context_id',
				'context_revision',
				'context_type',
				'official_revision_hash',
				'official_revision_id',
				'publication_hash',
				'publication_id',
				'submission_id',
				'submission_lock_version',
				'submission_state',
				'target_id',
				'target_state',
			)
		);
		$value             = self::freeze( $projection, $timezone, $canonical )->value();
		$projection_source = self::map( $value['source'] ?? null );
		$context           = self::map( $value['context'] ?? null );
		$submission        = self::map( $value['submission'] ?? null );
		$publication       = self::map( $value['publication'] ?? null );
		if (
			! hash_equals( (string) ( $source['official_revision_id'] ?? '' ), (string) ( $projection_source['id'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['official_revision_hash'] ?? '' ), (string) ( $projection_source['hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['attachment_set_hash'] ?? '' ), (string) ( $projection_source['attachment_set_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['context_type'] ?? '' ), (string) ( $context['type'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['context_id'] ?? '' ), (string) ( $context['id'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['context_revision'] ?? '' ), (string) ( $context['revision'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['submission_id'] ?? '' ), (string) ( $submission['id'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['submission_state'] ?? '' ), (string) ( $submission['state'] ?? '' ) )
			|| ( $source['submission_lock_version'] ?? null ) !== ( $submission['lock_version'] ?? null )
			|| ! hash_equals( (string) ( $source['publication_id'] ?? '' ), (string) ( $publication['id'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['publication_hash'] ?? '' ), (string) ( $publication['hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Own submission PDF source drifted.' );
		}
		$value['schema_version'] = self::OWN_VERSION;
		$value['ownership']      = array(
			'assignment_id'    => $source['assignment_id'],
			'assignment_state' => $source['assignment_state'],
			'target_id'        => $source['target_id'],
			'target_state'     => $source['target_state'],
		);
		$json                    = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Own submission PDF snapshot is too large.' );
		}
		return self::restore( $json, hash( 'sha256', $json ), $canonical );
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
		if (
			'' === $json
			|| strlen( $json ) > self::MAX_BYTES
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
		) {
			throw new InvalidArgumentException( 'Submission PDF snapshot integrity failed.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot JSON is invalid.' );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) ) ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot contract is invalid.' );
		}
		if ( self::SCHEMA !== ( $value['schema_id'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot schema is invalid.' );
		}
		if ( self::OWN_VERSION === ( $value['schema_version'] ?? null ) ) {
			self::assert_own_value( $value, $canonical );
		} elseif ( self::VERSION === ( $value['schema_version'] ?? null ) ) {
			self::assert_value( $value, $canonical );
		} else {
			throw new InvalidArgumentException( 'Submission PDF snapshot version is unsupported.' );
		}
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
		self::exact( $value, array( 'schema_id', 'schema_version', 'source', 'context', 'submission', 'publication', 'print' ) );
		if ( self::SCHEMA !== ( $value['schema_id'] ?? null ) || self::VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot schema is invalid.' );
		}
		$source      = self::map( $value['source'] ?? null );
		$context     = self::map( $value['context'] ?? null );
		$submission  = self::map( $value['submission'] ?? null );
		$publication = self::map( $value['publication'] ?? null );
		$print       = self::map( $value['print'] ?? null );
		self::exact( $source, array( 'type', 'id', 'hash', 'attachment_set_hash' ) );
		self::exact( $context, array( 'type', 'id', 'revision' ) );
		self::exact( $submission, array( 'id', 'state', 'lock_version' ) );
		self::exact( $publication, array( 'id', 'hash' ) );
		self::exact(
			$print,
			array(
				'artifact_profile_version',
				'schema_id',
				'schema_version',
				'projector_version',
				'profile',
				'page_profile',
				'locale',
				'timezone',
				'profile_hash',
				'projection_hash',
				'projection',
			)
		);
		try {
			new Public_Id( is_string( $source['id'] ?? null ) ? $source['id'] : '' );
			new Public_Id( is_string( $submission['id'] ?? null ) ? $submission['id'] : '' );
			new Public_Id( is_string( $publication['id'] ?? null ) ? $publication['id'] : '' );
			new DateTimeZone( is_string( $print['timezone'] ?? null ) ? $print['timezone'] : '' );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot identity is invalid.' );
		}
		foreach ( array( $source['hash'] ?? null, $source['attachment_set_hash'] ?? null, $publication['hash'] ?? null, $print['profile_hash'] ?? null, $print['projection_hash'] ?? null ) as $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Submission PDF snapshot hash is invalid.' );
			}
		}
		if (
			'submission_revision' !== ( $source['type'] ?? null )
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| ! self::is_token( $context['id'] ?? null )
			|| ! self::is_token( $context['revision'] ?? null )
			|| ! in_array( $submission['state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
			|| ! is_int( $submission['lock_version'] ?? null ) || $submission['lock_version'] < 0
			|| Build_Submission_Print_Projection::ARTIFACT_PROFILE_VERSION !== ( $print['artifact_profile_version'] ?? null )
			|| Build_Submission_Print_Projection::SCHEMA !== ( $print['schema_id'] ?? null )
			|| Build_Submission_Print_Projection::VERSION !== ( $print['schema_version'] ?? null )
			|| Build_Submission_Print_Projection::PROJECTOR_VERSION !== ( $print['projector_version'] ?? null )
			|| Build_Submission_Print_Projection::PROFILE !== ( $print['profile'] ?? null )
			|| Build_Submission_Print_Projection::PAGE_PROFILE !== ( $print['page_profile'] ?? null )
			|| ! is_string( $print['locale'] ?? null ) || '' === trim( $print['locale'] )
		) {
			throw new InvalidArgumentException( 'Submission PDF snapshot values are invalid.' );
		}

		$projection         = self::map( $print['projection'] ?? null );
		$envelope           = self::map( $projection['submission_print_projection'] ?? null );
		$payload            = self::map( $envelope['payload'] ?? null );
		$payload_submission = self::map( $payload['submission'] ?? null );
		$assignment         = self::map( $payload['assignment'] ?? null );
		$payload_context    = self::map( $assignment['context'] ?? null );
		$provenance         = self::map( $payload['provenance'] ?? null );
		$layout             = self::map( $payload['layout'] ?? null );
		$document           = self::map( $payload['document'] ?? null );
		if (
			'submission_print' !== ( $projection['kind'] ?? null )
			|| Build_Submission_Print_Projection::PROFILE !== ( $projection['profile'] ?? null )
			|| Build_Submission_Print_Projection::SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Build_Submission_Print_Projection::VERSION !== ( $envelope['schema_version'] ?? null )
			|| Build_Submission_Print_Projection::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| ! hash_equals( (string) $source['hash'], (string) ( $envelope['source_hash'] ?? '' ) )
			|| Build_Submission_Print_Projection::PROFILE !== ( $payload['profile'] ?? null )
			|| Build_Submission_Print_Projection::PAGE_PROFILE !== ( $layout['print_token'] ?? null )
			|| ! hash_equals( (string) $print['locale'], (string) ( $document['locale'] ?? '' ) )
			|| ! hash_equals( (string) $submission['id'], (string) ( $payload_submission['id'] ?? '' ) )
			|| ! hash_equals( (string) $submission['state'], (string) ( $payload_submission['state'] ?? '' ) )
			|| ! hash_equals( (string) $context['type'], (string) ( $payload_context['type'] ?? '' ) )
			|| ! hash_equals( (string) $context['id'], (string) ( $payload_context['id'] ?? '' ) )
			|| ! hash_equals( (string) $context['revision'], (string) ( $payload_context['revision'] ?? '' ) )
			|| ! hash_equals( (string) $source['id'], (string) ( $provenance['submission_revision_id'] ?? '' ) )
			|| ! hash_equals( (string) $source['hash'], (string) ( $provenance['submission_revision_hash'] ?? '' ) )
			|| ! hash_equals( (string) $source['attachment_set_hash'], (string) ( $provenance['attachment_set_hash'] ?? '' ) )
			|| ! hash_equals( (string) $publication['id'], (string) ( $provenance['publication_id'] ?? '' ) )
			|| ! hash_equals( (string) $publication['hash'], (string) ( $provenance['publication_hash'] ?? '' ) )
			|| ! hash_equals( (string) $envelope['payload_hash'], hash( 'sha256', $canonical->encode( $payload ) ) )
			|| ! hash_equals( (string) $print['profile_hash'], hash( 'sha256', $canonical->encode( $envelope ) ) )
			|| ! hash_equals( (string) $print['projection_hash'], hash( 'sha256', $canonical->encode( $projection ) ) )
		) {
			throw new InvalidArgumentException( 'Submission PDF snapshot projection is invalid.' );
		}
	}

	/**
	 * Reject invalid own value.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_own_value( array $value, Canonical_Json $canonical ): void {
		self::exact( $value, array( 'schema_id', 'schema_version', 'source', 'context', 'submission', 'publication', 'print', 'ownership' ) );
		$ownership = self::map( $value['ownership'] ?? null );
		self::exact( $ownership, array( 'assignment_id', 'assignment_state', 'target_id', 'target_state' ) );
		try {
			new Public_Id( is_string( $ownership['assignment_id'] ?? null ) ? $ownership['assignment_id'] : '' );
			new Public_Id( is_string( $ownership['target_id'] ?? null ) ? $ownership['target_id'] : '' );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Own submission PDF identity is invalid.' );
		}
		if (
			! in_array( $ownership['assignment_state'] ?? null, array( 'open', 'closed' ), true )
			|| 'active' !== ( $ownership['target_state'] ?? null )
		) {
			throw new InvalidArgumentException( 'Own submission PDF ownership is invalid.' );
		}

		$synthetic = $value;
		unset( $synthetic['ownership'] );
		$synthetic['schema_version'] = self::VERSION;
		self::assert_value( $synthetic, $canonical );

		$print      = self::map( $value['print'] ?? null );
		$projection = self::map( $print['projection'] ?? null );
		$envelope   = self::map( $projection['submission_print_projection'] ?? null );
		$payload    = self::map( $envelope['payload'] ?? null );
		$assignment = self::map( $payload['assignment'] ?? null );
		$learner    = self::map( $payload['learner'] ?? null );
		if (
			! hash_equals( (string) $ownership['assignment_id'], (string) ( $assignment['id'] ?? '' ) )
			|| ! hash_equals( (string) $ownership['target_id'], (string) ( $learner['target_id'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Own submission PDF projection ownership drifted.' );
		}
	}

	/**
	 * Map submission pdf request snapshot.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Submission PDF snapshot map is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Submission PDF snapshot shape is invalid.' );
		}
	}

	/**
	 * Is token.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_token( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 191
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
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
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return $this->value['print']['projection'];
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
	 * Submission.
	 *
	 * @return array<string,mixed>
	 */
	public function submission(): array {
		return $this->value['submission'];
	}
	/**
	 * Publication.
	 *
	 * @return array<string,string>
	 */
	public function publication(): array {
		return $this->value['publication'];
	}
	/**
	 * Ownership.
	 *
	 * @return array<string,string>|null
	 */
	public function ownership(): ?array {
		return $this->value['ownership'] ?? null;
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
	 * Version.
	 *
	 * @return string
	 */
	public function version(): string {
		return (string) $this->value['schema_version'];
	}
	/**
	 * Is own.
	 *
	 * @return bool
	 */
	public function is_own(): bool {
		return self::OWN_VERSION === $this->version();
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
	 * Attachment set hash.
	 *
	 * @return string
	 */
	public function attachment_set_hash(): string {
		return (string) $this->value['source']['attachment_set_hash'];
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

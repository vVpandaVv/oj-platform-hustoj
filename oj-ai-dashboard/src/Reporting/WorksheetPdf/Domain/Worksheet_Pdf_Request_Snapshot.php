<?php
/**
 * Worksheet pdf request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Domain;

use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical, complete input frozen before the asynchronous worker starts.
 */
final class Worksheet_Pdf_Request_Snapshot {
	public const SCHEMA           = 'oj_ai.worksheet_pdf_request';
	public const VERSION          = '1.0.0';
	public const OWN_VERSION      = '1.1.0';
	public const DATABASE_VERSION = 1;
	public const MAX_BYTES        = 4_194_304;

	/**
	 * Initialize worksheet pdf request snapshot dependencies and state.
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
	 * @param string              $source_state Source state.
	 * @param string              $timezone Timezone.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $projection, string $source_state, string $timezone, Canonical_Json $canonical ): self {
		if ( ! in_array( $source_state, array( 'draft', 'approved' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF source state is invalid.' );
		}
		new DateTimeZone( $timezone );
		$print  = $projection['print'] ?? null;
		$source = $projection['source'] ?? null;
		if ( ! is_array( $print ) || array_is_list( $print ) || ! is_array( $source ) || array_is_list( $source )
			|| 'revision' !== ( $source['type'] ?? null ) || 'student_blank' !== ( $print['profile'] ?? null )
			|| Build_Worksheet_Print_Projection::ARTIFACT_PROFILE_VERSION !== ( $print['artifact_profile_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF projection is invalid.' );
		}
		$value = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'  => 'worksheet_revision',
				'id'    => $source['id'] ?? null,
				'hash'  => $source['hash'] ?? null,
				'state' => $source_state,
			),
			'print'          => array(
				'artifact_profile_version' => $print['artifact_profile_version'],
				'schema_id'                => $print['schema_id'] ?? null,
				'schema_version'           => $print['schema_version'] ?? null,
				'projector_version'        => $print['projector_version'] ?? null,
				'profile'                  => $print['profile'],
				'page_profile'             => $print['page_profile'] ?? null,
				'locale'                   => $print['locale'] ?? null,
				'timezone'                 => $timezone,
				'profile_hash'             => $print['profile_hash'] ?? null,
				'projection_hash'          => $print['projection_hash'] ?? null,
				'projection'               => $print['projection'] ?? null,
			),
		);
		$json  = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot is too large.' );
		}
		return new self( $value, $json, hash( 'sha256', $json ) );
	}

	/**
	 * Freeze own.
	 *
	 * @param array<string,mixed>                          $projection Projection.
	 * @param array<string,mixed>                          $assignment Assignment.
	 * @param array{type:string,id:string,revision:string} $context Access or execution context for this operation.
	 * @param string                                       $timezone Timezone.
	 * @param Canonical_Json                               $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze_own( array $projection, array $assignment, array $context, string $timezone, Canonical_Json $canonical ): self {
		new DateTimeZone( $timezone );
		$print       = $projection['print'] ?? null;
		$source      = $projection['source'] ?? null;
		$publication = $projection['publication'] ?? null;
		if ( ! is_array( $print ) || array_is_list( $print ) || ! is_array( $source ) || array_is_list( $source )
			|| ! is_array( $publication ) || array_is_list( $publication ) || 'publication' !== ( $source['type'] ?? null )
			|| 'student_blank' !== ( $print['profile'] ?? null )
			|| Build_Worksheet_Print_Projection::ARTIFACT_PROFILE_VERSION !== ( $print['artifact_profile_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF projection is invalid.' );
		}
		self::assert_exact_keys(
			$assignment,
			array(
				'assignment_id',
				'assignment_lock_version',
				'assignment_state',
				'binding_context_revision',
				'context_id',
				'context_type',
				'publication_hash',
				'publication_id',
				'target_id',
				'target_state',
			)
		);
		self::assert_exact_keys( $context, array( 'id', 'revision', 'type' ) );
		$value = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::OWN_VERSION,
			'source'         => array(
				'type'  => 'worksheet_publication',
				'id'    => $source['id'] ?? null,
				'hash'  => $source['hash'] ?? null,
				'state' => $publication['state'] ?? null,
			),
			'assignment'     => array(
				'id'                       => $assignment['assignment_id'],
				'lock_version'             => $assignment['assignment_lock_version'],
				'state'                    => $assignment['assignment_state'],
				'target_id'                => $assignment['target_id'],
				'target_state'             => $assignment['target_state'],
				'publication_id'           => $assignment['publication_id'],
				'publication_hash'         => $assignment['publication_hash'],
				'binding_context_revision' => $assignment['binding_context_revision'],
			),
			'context'        => $context,
			'print'          => array(
				'artifact_profile_version' => $print['artifact_profile_version'],
				'schema_id'                => $print['schema_id'] ?? null,
				'schema_version'           => $print['schema_version'] ?? null,
				'projector_version'        => $print['projector_version'] ?? null,
				'profile'                  => $print['profile'],
				'page_profile'             => $print['page_profile'] ?? null,
				'locale'                   => $print['locale'] ?? null,
				'timezone'                 => $timezone,
				'profile_hash'             => $print['profile_hash'] ?? null,
				'projection_hash'          => $print['projection_hash'] ?? null,
				'projection'               => $print['projection'] ?? null,
			),
		);
		$json  = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Own worksheet PDF snapshot is too large.' );
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
		if ( '' === $json || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || strlen( $json ) > self::MAX_BYTES || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot integrity failed.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot JSON is invalid.' );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) )
			|| self::SCHEMA !== ( $value['schema_id'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot contract is invalid.' );
		}
		if ( self::OWN_VERSION === ( $value['schema_version'] ?? null ) ) {
			return self::restore_own( $value, $json, $hash, $canonical );
		}
		if ( self::VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot version is unsupported.' );
		}
		self::assert_exact_keys( $value, array( 'schema_id', 'schema_version', 'source', 'print' ) );
		$source = $value['source'] ?? null;
		$print  = $value['print'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) || ! is_array( $print ) || array_is_list( $print ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot values are invalid.' );
		}
		self::assert_exact_keys( $source, array( 'type', 'id', 'hash', 'state' ) );
		self::assert_exact_keys(
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
			new DateTimeZone( is_string( $print['timezone'] ?? null ) ? $print['timezone'] : '' );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot identity is invalid.' );
		}
		$projection = $print['projection'] ?? null;
		$envelope   = is_array( $projection ) ? ( $projection['print_projection'] ?? null ) : null;
		$payload    = is_array( $envelope ) ? ( $envelope['payload'] ?? null ) : null;
		$layout     = is_array( $payload ) ? ( $payload['layout'] ?? null ) : null;
		$document   = is_array( $payload ) ? ( $payload['document'] ?? null ) : null;
		if ( 'worksheet_revision' !== ( $source['type'] ?? null )
			|| ! in_array( $source['state'] ?? null, array( 'draft', 'approved' ), true )
			|| ! is_string( $source['hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source['hash'] )
			|| Build_Worksheet_Print_Projection::ARTIFACT_PROFILE_VERSION !== ( $print['artifact_profile_version'] ?? null )
			|| Worksheet_Preview_Projector::PRINT_SCHEMA !== ( $print['schema_id'] ?? null )
			|| Worksheet_Preview_Projector::PRINT_VERSION !== ( $print['schema_version'] ?? null )
			|| Worksheet_Preview_Projector::PROJECTOR_VERSION !== ( $print['projector_version'] ?? null )
			|| 'student_blank' !== ( $print['profile'] ?? null ) || 'worksheet_a4@1' !== ( $print['page_profile'] ?? null )
			|| ! is_string( $print['locale'] ?? null ) || '' === trim( $print['locale'] )
			|| ! is_string( $print['profile_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $print['profile_hash'] )
			|| ! is_string( $print['projection_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $print['projection_hash'] )
			|| ! is_array( $projection ) || array_is_list( $projection ) || 'print' !== ( $projection['kind'] ?? null )
			|| 'student_blank' !== ( $projection['profile'] ?? null ) || ! is_array( $envelope ) || array_is_list( $envelope )
			|| Worksheet_Preview_Projector::PRINT_SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Worksheet_Preview_Projector::PRINT_VERSION !== ( $envelope['schema_version'] ?? null )
			|| Worksheet_Preview_Projector::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| ! hash_equals( $source['hash'], (string) ( $envelope['source_hash'] ?? '' ) )
			|| ! is_array( $payload ) || array_is_list( $payload ) || 'student_blank' !== ( $payload['profile'] ?? null )
			|| ! is_array( $layout ) || 'worksheet_a4@1' !== ( $layout['print_token'] ?? null )
			|| ! is_array( $document ) || ! hash_equals( $print['locale'], (string) ( $document['locale'] ?? '' ) )
			|| ! hash_equals( $print['profile_hash'], hash( 'sha256', $canonical->encode( $envelope ) ) )
			|| ! hash_equals( $print['projection_hash'], hash( 'sha256', $canonical->encode( $projection ) ) )
			|| ! hash_equals( (string) ( $envelope['payload_hash'] ?? '' ), hash( 'sha256', $canonical->encode( $payload ) ) ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot values are invalid.' );
		}
		return new self( $value, $json, $hash );
	}

	/**
	 * Restore own.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $json Json.
	 * @param string              $hash Hash.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function restore_own( array $value, string $json, string $hash, Canonical_Json $canonical ): self {
		self::assert_exact_keys( $value, array( 'assignment', 'context', 'print', 'schema_id', 'schema_version', 'source' ) );
		$source     = $value['source'] ?? null;
		$assignment = $value['assignment'] ?? null;
		$context    = $value['context'] ?? null;
		$print      = $value['print'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) || ! is_array( $assignment ) || array_is_list( $assignment )
			|| ! is_array( $context ) || array_is_list( $context ) || ! is_array( $print ) || array_is_list( $print ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF snapshot maps are invalid.' );
		}
		self::assert_exact_keys( $source, array( 'type', 'id', 'hash', 'state' ) );
		self::assert_exact_keys(
			$assignment,
			array(
				'binding_context_revision',
				'id',
				'lock_version',
				'publication_hash',
				'publication_id',
				'state',
				'target_id',
				'target_state',
			)
		);
		self::assert_exact_keys( $context, array( 'id', 'revision', 'type' ) );
		foreach ( array( $source['id'] ?? null, $assignment['id'] ?? null, $assignment['publication_id'] ?? null, $assignment['target_id'] ?? null ) as $id ) {
			try {
				new Public_Id( is_string( $id ) ? $id : '' ); } catch ( \Throwable ) {
				throw new InvalidArgumentException( 'Own worksheet PDF identity is invalid.' );
				}
		}
		if ( 'worksheet_publication' !== ( $source['type'] ?? null )
			|| ! in_array( $source['state'] ?? null, array( 'active', 'retired' ), true )
			|| ! self::is_hash( $source['hash'] ?? null )
			|| ! is_int( $assignment['lock_version'] ?? null ) || $assignment['lock_version'] < 0
			|| ! in_array( $assignment['state'] ?? null, array( 'open', 'closed' ), true )
			|| 'active' !== ( $assignment['target_state'] ?? null )
			|| ! self::is_hash( $assignment['publication_hash'] ?? null )
			|| ! hash_equals( (string) $source['id'], (string) $assignment['publication_id'] )
			|| ! hash_equals( (string) $source['hash'], (string) $assignment['publication_hash'] )
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| ! self::is_token( $context['id'] ?? null ) || ! self::is_token( $context['revision'] ?? null )
			|| ! self::is_token( $assignment['binding_context_revision'] ?? null ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF snapshot values are invalid.' );
		}
		$projection    = $print['projection'] ?? null;
		$envelope      = is_array( $projection ) ? ( $projection['print_projection'] ?? null ) : null;
		$envelope_hash = is_array( $envelope ) ? ( $envelope['source_hash'] ?? null ) : null;
		if ( ! self::is_hash( $envelope_hash ) ) {
			throw new InvalidArgumentException( 'Own worksheet PDF projection source is invalid.' );
		}
		$synthetic      = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'  => 'worksheet_revision',
				'id'    => $source['id'],
				'hash'  => $envelope_hash,
				'state' => 'approved',
			),
			'print'          => $print,
		);
		$synthetic_json = $canonical->encode( $synthetic );
		self::restore( $synthetic_json, hash( 'sha256', $synthetic_json ), $canonical );
		return new self( $value, $json, $hash );
	}

	/**
	 * Is hash.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_hash( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/**
	 * Is token.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_token( $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 191
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet PDF snapshot shape is invalid.' );
		}
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
	 * Assignment.
	 *
	 * @return array<string,mixed>|null
	 */
	public function assignment(): ?array {
		return $this->value['assignment'] ?? null;
	}
	/**
	 * Context.
	 *
	 * @return array<string,string>|null
	 */
	public function context(): ?array {
		return $this->value['context'] ?? null;
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
	 * Source type.
	 *
	 * @return string
	 */
	public function source_type(): string {
		return (string) $this->value['source']['type'];
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
	 * Profile version.
	 *
	 * @return string
	 */
	public function profile_version(): string {
		return (string) $this->value['print']['artifact_profile_version'];
	}
}

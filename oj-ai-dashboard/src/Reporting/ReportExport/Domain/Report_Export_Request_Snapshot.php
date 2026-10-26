<?php
/**
 * Report export request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Domain;

use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable exact-report source for C46 CSV, C47 PDF, and C69 JSON package workers.
 */
final class Report_Export_Request_Snapshot {
	public const SCHEMA               = 'oj_ai.report_export_request';
	public const VERSION              = '1.0.0';
	public const DATABASE_VERSION     = 1;
	public const CSV_PROFILE          = 'report_csv.v1';
	public const PDF_PROFILE          = 'report_print.v1';
	public const JSON_PACKAGE_PROFILE = 'report_json_package.v1';
	public const MAXIMUM_ROWS         = 50_000;
	public const MAXIMUM_BYTES        = 52_428_800;

	private const PROFILES = array(
		'report_csv'          => self::CSV_PROFILE,
		'report_pdf'          => self::PDF_PROFILE,
		'report_json_package' => self::JSON_PACKAGE_PROFILE,
	);

	/**
	 * Initialize report export request snapshot dependencies and state.
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
	 * @param array<string,mixed>    $report Report.
	 * @param array<array-key,mixed> $context Access or execution context for this operation.
	 * @param string                 $artifact_kind Artifact kind.
	 * @param string                 $locale Locale.
	 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $report, array $context, string $artifact_kind, string $locale, Canonical_Json $canonical ): self {
		$filter         = self::map( $report['filter'] ?? null );
		$filter_payload = self::map( $filter['payload'] ?? null );
		$value          = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'             => 'report_snapshot',
				'id'               => $report['id'] ?? null,
				'hash'             => $report['snapshot_hash'] ?? null,
				'state'            => $report['state'] ?? null,
				'generated_at_utc' => $report['generated_at_utc'] ?? null,
			),
			'context'        => array(
				'type'     => $context['type'] ?? null,
				'id'       => $context['id'] ?? null,
				'revision' => $context['revision'] ?? null,
			),
			'export'         => array(
				'artifact_kind'        => $artifact_kind,
				'profile'              => self::PROFILES[ $artifact_kind ] ?? null,
				'locale'               => $locale,
				'timezone'             => $filter_payload['timezone'] ?? null,
				'row_count'            => $report['row_count'] ?? null,
				'filter_hash'          => $report['filter_hash'] ?? null,
				'source_manifest_hash' => $report['source_manifest_hash'] ?? null,
				'projection_hash'      => $report['projection_hash'] ?? null,
				'filter'               => $filter,
				'projection'           => $report['projection'] ?? null,
			),
		);
		self::assert_value( $value, $canonical );
		$json = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAXIMUM_BYTES ) {
			throw new InvalidArgumentException( 'Report export snapshot is too large.' );
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
		if ( '' === $json || strlen( $json ) > self::MAXIMUM_BYTES || ! self::is_hash( $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Report export snapshot integrity failed.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw new InvalidArgumentException( 'Report export snapshot JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Report export snapshot contract is invalid.' );
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
		self::exact( $value, array( 'context', 'export', 'schema_id', 'schema_version', 'source' ) );
		if ( self::SCHEMA !== ( $value['schema_id'] ?? null ) || self::VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Report export snapshot schema is invalid.' );
		}
		$source  = self::map( $value['source'] ?? null );
		$context = self::map( $value['context'] ?? null );
		$export  = self::map( $value['export'] ?? null );
		self::exact( $source, array( 'generated_at_utc', 'hash', 'id', 'state', 'type' ) );
		self::exact( $context, array( 'id', 'revision', 'type' ) );
		self::exact( $export, array( 'artifact_kind', 'filter', 'filter_hash', 'locale', 'profile', 'projection', 'projection_hash', 'row_count', 'source_manifest_hash', 'timezone' ) );
		try {
			new Public_Id( is_string( $source['id'] ?? null ) ? $source['id'] : '' );
			new DateTimeZone( is_string( $export['timezone'] ?? null ) ? $export['timezone'] : '' );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Report export snapshot identity is invalid.' );
		}

		$artifact_kind = is_string( $export['artifact_kind'] ?? null ) ? $export['artifact_kind'] : '';
		$profile       = is_string( $export['profile'] ?? null ) ? $export['profile'] : '';
		$row_count     = $export['row_count'] ?? null;
		if (
			'report_snapshot' !== ( $source['type'] ?? null ) || 'ready' !== ( $source['state'] ?? null )
			|| ! self::is_hash( $source['hash'] ?? null ) || ! self::is_utc( $source['generated_at_utc'] ?? null )
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true )
			|| ! self::is_token( $context['id'] ?? null ) || ! self::is_token( $context['revision'] ?? null )
			|| ! self::supports( $artifact_kind, $profile )
			|| ! is_string( $export['locale'] ?? null ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $export['locale'] )
			|| ! is_int( $row_count ) || $row_count < 0 || $row_count > self::MAXIMUM_ROWS
			|| ! self::is_hash( $export['filter_hash'] ?? null ) || ! self::is_hash( $export['source_manifest_hash'] ?? null ) || ! self::is_hash( $export['projection_hash'] ?? null )
		) {
			throw new InvalidArgumentException( 'Report export snapshot values are invalid.' );
		}

		$filter     = self::map( $export['filter'] ?? null );
		$projection = self::map( $export['projection'] ?? null );
		self::exact( $filter, array( 'payload', 'schema_id', 'schema_version' ) );
		$filter_payload = self::map( $filter['payload'] ?? null );
		self::exact( $filter_payload, array( 'assignment_states', 'context', 'lens', 'period', 'submission_states', 'timezone' ) );
		$filter_context = self::map( $filter_payload['context'] ?? null );
		self::exact( $filter_context, array( 'id', 'revision', 'type' ) );
		self::exact( $projection, array( 'baseline_at_utc', 'context', 'denominator_contract', 'filter_hash', 'filters', 'items', 'lens', 'metric_contract', 'schema_id', 'schema_version', 'source_manifest_hash' ) );
		$projection_context = self::map( $projection['context'] ?? null );
		$items              = $projection['items'] ?? null;
		if (
			Report_Filter::SCHEMA_ID !== ( $filter['schema_id'] ?? null ) || Report_Filter::VERSION !== ( $filter['schema_version'] ?? null )
			|| 'worksheet' !== ( $filter_payload['lens'] ?? null ) || ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) !== $row_count
			|| Report_Snapshot_Profile::PROJECTION_SCHEMA !== ( $projection['schema_id'] ?? null )
			|| Report_Snapshot_Profile::PROJECTION_VERSION !== ( $projection['schema_version'] ?? null )
			|| 'worksheet' !== ( $projection['lens'] ?? null ) || $source['generated_at_utc'] !== ( $projection['baseline_at_utc'] ?? null )
			|| $export['timezone'] !== ( $filter_payload['timezone'] ?? null )
			|| ! self::same( $filter_context, $projection_context, $canonical )
			|| $context['type'] !== ( $filter_context['type'] ?? null ) || $context['id'] !== ( $filter_context['id'] ?? null )
			|| ! hash_equals( (string) $export['filter_hash'], hash( 'sha256', $canonical->encode( $filter ) ) )
			|| ! hash_equals( (string) $export['projection_hash'], hash( 'sha256', $canonical->encode( $projection ) ) )
			|| ! hash_equals( (string) $export['filter_hash'], (string) ( $projection['filter_hash'] ?? '' ) )
			|| ! hash_equals( (string) $export['source_manifest_hash'], (string) ( $projection['source_manifest_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Report export source projection is invalid.' );
		}

		$identity = $canonical->encode(
			array(
				'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
				'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
				'snapshot_id'          => $source['id'],
				'filter_hash'          => $export['filter_hash'],
				'source_manifest_hash' => $export['source_manifest_hash'],
				'projection_hash'      => $export['projection_hash'],
			)
		);
		if ( ! hash_equals( (string) $source['hash'], hash( 'sha256', $identity ) ) ) {
			throw new InvalidArgumentException( 'Report export snapshot identity drifted.' );
		}
	}

	/**
	 * Supports.
	 *
	 * @param string $artifact_kind Artifact kind.
	 * @param string $profile Profile.
	 * @return bool
	 */
	public static function supports( string $artifact_kind, string $profile ): bool {
		return isset( self::PROFILES[ $artifact_kind ] ) && hash_equals( self::PROFILES[ $artifact_kind ], $profile );
	}
	/**
	 * Is artifact kind.
	 *
	 * @param string $artifact_kind Artifact kind.
	 * @return bool
	 */
	public static function is_artifact_kind( string $artifact_kind ): bool {
		return isset( self::PROFILES[ $artifact_kind ] );
	}
	/**
	 * Map report export request snapshot.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Report export snapshot map is invalid.' );
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
			throw new InvalidArgumentException( 'Report export snapshot shape is invalid.' );
		}
	}
	/**
	 * Same.
	 *
	 * @param mixed          $left Left.
	 * @param mixed          $right Right.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return bool
	 */
	private static function same( mixed $left, mixed $right, Canonical_Json $canonical ): bool {
		try {
			return hash_equals( $canonical->encode( $left ), $canonical->encode( $right ) );
		} catch ( \Throwable ) {
			return false;
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
	 * Context.
	 *
	 * @return array{type:string,id:string,revision:string}
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
		return $this->value['export']['projection'];
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
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return (string) $this->value['export']['artifact_kind'];
	}
	/**
	 * Profile.
	 *
	 * @return string
	 */
	public function profile(): string {
		return (string) $this->value['export']['profile'];
	}
	/**
	 * Locale.
	 *
	 * @return string
	 */
	public function locale(): string {
		return (string) $this->value['export']['locale'];
	}
	/**
	 * Timezone.
	 *
	 * @return string
	 */
	public function timezone(): string {
		return (string) $this->value['export']['timezone'];
	}
	/**
	 * Row count.
	 *
	 * @return int
	 */
	public function row_count(): int {
		return (int) $this->value['export']['row_count'];
	}
	/**
	 * Filter hash.
	 *
	 * @return string
	 */
	public function filter_hash(): string {
		return (string) $this->value['export']['filter_hash'];
	}
	/**
	 * Source manifest hash.
	 *
	 * @return string
	 */
	public function source_manifest_hash(): string {
		return (string) $this->value['export']['source_manifest_hash'];
	}
	/**
	 * Projection hash.
	 *
	 * @return string
	 */
	public function projection_hash(): string {
		return (string) $this->value['export']['projection_hash'];
	}
}

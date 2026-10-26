<?php
/**
 * Exact external AI processing consent record.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact external AI processing consent record.
 */
final class External_Ai_Consent_Record {
	public const SCHEMA          = 'oj_ai.external_ai_processing_consent';
	public const VERSION         = '1.0.0';
	public const DATA_CATEGORIES = array(
		'worksheet_assessment_configuration',
		'worksheet_asset_text',
		'worksheet_content',
	);

	/**
	 * Record.
	 *
	 * @var array<string,mixed>
	 */
	private array $record;

	/**
	 * Initialize external ai consent record dependencies and state.
	 *
	 * @param array<string,mixed> $record Record.
	 */
	private function __construct( array $record ) {
		$this->record = $record;
	}

	/**
	 * Approve.
	 *
	 * @param Worksheet_Evaluation_Routing_Snapshot $routing Routing.
	 * @param int                                   $actor_user_id Authenticated actor from the access context.
	 * @param string                                $approved_at_utc Approved at utc.
	 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function approve(
		Worksheet_Evaluation_Routing_Snapshot $routing,
		int $actor_user_id,
		string $approved_at_utc,
		Canonical_Json $canonical
	): self {
		if (
			$actor_user_id < 1
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approved_at_utc )
		) {
			throw new InvalidArgumentException( 'External AI consent approval evidence is invalid.' );
		}
		$record                 = array(
			'schema_id'           => self::SCHEMA,
			'schema_version'      => self::VERSION,
			'state'               => 'approved',
			'task_key'            => $routing->task_key(),
			'destination'         => array(
				'provider_key'        => $routing->provider_key(),
				'model_key'           => $routing->model_key(),
				'profile_fingerprint' => $routing->profile_fingerprint(),
			),
			'input_categories'    => self::DATA_CATEGORIES,
			'approved_by_user_id' => $actor_user_id,
			'approved_at_utc'     => $approved_at_utc,
		);
		$record['consent_hash'] = hash( 'sha256', $canonical->encode( $record ) );

		return self::from_array( $record, $canonical );
	}

	/**
	 * From array.
	 *
	 * @param mixed          $value Value.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function from_array( $value, Canonical_Json $canonical ): self {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'External AI consent record must be an object.' );
		}
		self::exact_keys(
			$value,
			array(
				'approved_at_utc',
				'approved_by_user_id',
				'consent_hash',
				'destination',
				'input_categories',
				'schema_id',
				'schema_version',
				'state',
				'task_key',
			)
		);
		$destination = $value['destination'] ?? null;
		if ( ! is_array( $destination ) || array_is_list( $destination ) ) {
			throw new InvalidArgumentException( 'External AI consent destination is invalid.' );
		}
		self::exact_keys( $destination, array( 'model_key', 'profile_fingerprint', 'provider_key' ) );

		$unsigned     = $value;
		$consent_hash = $unsigned['consent_hash'] ?? null;
		unset( $unsigned['consent_hash'] );
		if (
			self::SCHEMA !== ( $value['schema_id'] ?? null )
			|| self::VERSION !== ( $value['schema_version'] ?? null )
			|| 'approved' !== ( $value['state'] ?? null )
			|| ! is_string( $value['task_key'] ?? null )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,190}$/D', $value['task_key'] )
			|| ! is_string( $destination['provider_key'] ?? null )
			|| ! is_string( $destination['model_key'] ?? null )
			|| ! is_string( $destination['profile_fingerprint'] ?? null )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,99}$/D', $destination['provider_key'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{0,99}$/D', $destination['model_key'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $destination['profile_fingerprint'] )
			|| self::DATA_CATEGORIES !== ( $value['input_categories'] ?? null )
			|| ! is_int( $value['approved_by_user_id'] ?? null )
			|| $value['approved_by_user_id'] < 1
			|| ! is_string( $value['approved_at_utc'] ?? null )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['approved_at_utc'] )
			|| ! is_string( $consent_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $consent_hash )
			|| ! hash_equals( $consent_hash, hash( 'sha256', $canonical->encode( $unsigned ) ) )
		) {
			throw new InvalidArgumentException( 'External AI consent record integrity is invalid.' );
		}

		return new self( $value );
	}

	/**
	 * Matches routing.
	 *
	 * @param Worksheet_Evaluation_Routing_Snapshot $routing Routing.
	 * @return bool
	 */
	public function matches_routing( Worksheet_Evaluation_Routing_Snapshot $routing ): bool {
		$destination = $this->record['destination'];
		return hash_equals( (string) $this->record['task_key'], $routing->task_key() )
			&& hash_equals( (string) $destination['provider_key'], $routing->provider_key() )
			&& hash_equals( (string) $destination['model_key'], $routing->model_key() )
			&& hash_equals( (string) $destination['profile_fingerprint'], $routing->profile_fingerprint() );
	}

	/**
	 * To array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->record;
	}

	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return array(
			'consent_hash'        => (string) $this->record['consent_hash'],
			'approved_at_utc'     => (string) $this->record['approved_at_utc'],
			'approved_by_user_id' => (int) $this->record['approved_by_user_id'],
			'task_key'            => (string) $this->record['task_key'],
			'destination'         => $this->record['destination'],
		);
	}

	/**
	 * Consent hash.
	 *
	 * @return string
	 */
	public function consent_hash(): string {
		return (string) $this->record['consent_hash'];
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'External AI consent record fields are invalid.' );
		}
	}
}

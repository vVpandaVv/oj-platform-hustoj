<?php
/**
 * Safe current schema repair state.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Installation_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe current schema repair state.
 */
final class Schema_Repair_State {
	public const REPAIRABLE   = 'repairable';
	public const NOT_REQUIRED = 'not_required';
	public const BLOCKED      = 'blocked';

	/**
	 * Initialize schema repair state dependencies and state.
	 *
	 * @param string      $disposition Disposition.
	 * @param string      $code Code.
	 * @param string|null $installation_state Installation state.
	 * @param string|null $catalog_fingerprint Catalog fingerprint.
	 * @param string|null $repair_state_hash Repair state hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		private string $disposition,
		private string $code,
		private ?string $installation_state,
		private ?string $catalog_fingerprint,
		private ?string $repair_state_hash
	) {
		if ( ! in_array( $disposition, array( self::REPAIRABLE, self::NOT_REQUIRED, self::BLOCKED ), true ) ) {
			throw new InvalidArgumentException( 'Unknown schema repair disposition.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Schema repair state requires a stable code.' );
		}
		if ( self::REPAIRABLE === $disposition ) {
			if (
				! in_array( $installation_state, array( Installation_State::INSTALLING, Installation_State::MAINTENANCE_REQUIRED ), true )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $catalog_fingerprint )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $repair_state_hash )
			) {
				throw new InvalidArgumentException( 'Repairable schema state is incomplete.' );
			}
		} elseif ( null !== $installation_state || null !== $catalog_fingerprint || null !== $repair_state_hash ) {
			throw new InvalidArgumentException( 'Unavailable schema repair state cannot expose expected values.' );
		}
	}

	/**
	 * Repairable.
	 *
	 * @param string $installation_state Installation state.
	 * @param string $catalog_fingerprint Catalog fingerprint.
	 * @param string $repair_state_hash Repair state hash.
	 * @return self
	 */
	public static function repairable( string $installation_state, string $catalog_fingerprint, string $repair_state_hash ): self {
		return new self( self::REPAIRABLE, 'schema_forward_repair_available', $installation_state, $catalog_fingerprint, $repair_state_hash );
	}

	/**
	 * Not required.
	 *
	 * @return self
	 */
	public static function not_required(): self {
		return new self( self::NOT_REQUIRED, 'schema_repair_not_required', null, null, null );
	}

	/**
	 * Blocked.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function blocked( string $code ): self {
		return new self( self::BLOCKED, $code, null, null, null );
	}

	/**
	 * Is repairable.
	 *
	 * @return bool
	 */
	public function is_repairable(): bool {
		return self::REPAIRABLE === $this->disposition;
	}

	/**
	 * Disposition.
	 *
	 * @return string
	 */
	public function disposition(): string {
		return $this->disposition;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Installation state.
	 *
	 * @return string|null
	 */
	public function installation_state(): ?string {
		return $this->installation_state;
	}

	/**
	 * Catalog fingerprint.
	 *
	 * @return string|null
	 */
	public function catalog_fingerprint(): ?string {
		return $this->catalog_fingerprint;
	}

	/**
	 * Repair state hash.
	 *
	 * @return string|null
	 */
	public function repair_state_hash(): ?string {
		return $this->repair_state_hash;
	}

	/**
	 * Matches.
	 *
	 * @param string $installation_state Installation state.
	 * @param string $catalog_fingerprint Catalog fingerprint.
	 * @param string $repair_state_hash Repair state hash.
	 * @return bool
	 */
	public function matches( string $installation_state, string $catalog_fingerprint, string $repair_state_hash ): bool {
		return $this->is_repairable()
			&& hash_equals( (string) $this->installation_state, $installation_state )
			&& hash_equals( (string) $this->catalog_fingerprint, $catalog_fingerprint )
			&& hash_equals( (string) $this->repair_state_hash, $repair_state_hash );
	}

	/**
	 * Projection.
	 *
	 * @return array{available:bool,code:string,expected:?array{installation_state:string,catalog_fingerprint:string,repair_state_hash:string}}
	 */
	public function projection(): array {
		return array(
			'available' => $this->is_repairable(),
			'code'      => $this->code,
			'expected'  => $this->is_repairable()
				? array(
					'installation_state'  => (string) $this->installation_state,
					'catalog_fingerprint' => (string) $this->catalog_fingerprint,
					'repair_state_hash'   => (string) $this->repair_state_hash,
				)
				: null,
		);
	}
}

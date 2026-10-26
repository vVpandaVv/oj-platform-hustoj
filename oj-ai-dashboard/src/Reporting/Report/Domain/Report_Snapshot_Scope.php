<?php
/**
 * Minimal immutable scope used to authorize a report snapshot before reading it.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal immutable scope used to authorize a report snapshot before reading it.
 */
final class Report_Snapshot_Scope {
	/**
	 * Initialize report snapshot scope dependencies and state.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @param string $state State.
	 * @param string $snapshot_hash Snapshot hash.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $snapshot_id,
		private string $state,
		private string $snapshot_hash,
		private string $context_type,
		private string $context_id,
		private string $context_revision
	) {
		new Public_Id( $snapshot_id );
		new Context_Scope( $context_type, $context_id, Context_Scope::OPERATION_REPORT, null, $context_revision );
		if ( '' === $state || strlen( $state ) > 32 || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $state ) ) {
			throw new InvalidArgumentException( 'Report snapshot state is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot_hash ) ) {
			throw new InvalidArgumentException( 'Report snapshot identity hash is invalid.' );
		}
	}

	/**
	 * Snapshot id.
	 *
	 * @return string
	 */
	public function snapshot_id(): string {
		return $this->snapshot_id;
	}

	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Snapshot hash.
	 *
	 * @return string
	 */
	public function snapshot_hash(): string {
		return $this->snapshot_hash;
	}

	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}

	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}

	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
}

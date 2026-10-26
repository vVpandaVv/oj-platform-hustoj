<?php
/**
 * Discard staged worksheet asset command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discard staged worksheet asset command.
 */
final class Discard_Staged_Worksheet_Asset_Command {
	/**
	 * Initialize discard staged worksheet asset command dependencies and state.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @param string $command_id Command identifier used for idempotency.
	 * @param string $expected_state Expected state.
	 * @param string $expected_revision_id Revision the caller read before this operation.
	 * @param string $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $worksheet_id,
		private string $asset_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_revision_id,
		private string $idempotency_fingerprint,
		private string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Discard asset requires an authenticated actor.' );
		}
		new Public_Id( $worksheet_id );
		new Public_Id( $asset_id );
		new Public_Id( $expected_revision_id );
		if ( ! in_array( $expected_state, array( 'draft', 'active' ), true ) ) {
			throw new InvalidArgumentException( 'Discard asset expected state is invalid.' );
		}
		if (
			strlen( $command_id ) < 8 || strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Discard asset command metadata is invalid.' );
		}
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}

	/**
	 * Worksheet id.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return $this->worksheet_id;
	}

	/**
	 * Asset id.
	 *
	 * @return string
	 */
	public function asset_id(): string {
		return $this->asset_id;
	}

	/**
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
	}

	/**
	 * Expected revision id.
	 *
	 * @return string
	 */
	public function expected_revision_id(): string {
		return $this->expected_revision_id;
	}

	/**
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.asset.discard|' . $this->asset_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
}

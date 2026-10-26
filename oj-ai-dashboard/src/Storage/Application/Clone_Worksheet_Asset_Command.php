<?php
/**
 * Internal worksheet asset clone command.
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
 * Carries trusted destination ownership after source authorization.
 */
final class Clone_Worksheet_Asset_Command {
	/**
	 * Initialize clone worksheet asset command dependencies and state.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_asset_id Source asset id.
	 * @param string $source_revision_id Source revision id.
	 * @param string $destination_worksheet_id Destination worksheet id.
	 * @param string $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $source_asset_id,
		private string $source_revision_id,
		private string $destination_worksheet_id,
		private string $idempotency_fingerprint,
		private string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'CloneWorksheetAsset requires an authenticated actor.' );
		}
		new Public_Id( $source_asset_id );
		new Public_Id( $source_revision_id );
		new Public_Id( $destination_worksheet_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'CloneWorksheetAsset idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'CloneWorksheetAsset correlation ID is invalid.' );
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
	 * Source asset id.
	 *
	 * @return string
	 */
	public function source_asset_id(): string {
		return $this->source_asset_id;
	}

	/**
	 * Source revision id.
	 *
	 * @return string
	 */
	public function source_revision_id(): string {
		return $this->source_revision_id;
	}

	/**
	 * Destination worksheet id.
	 *
	 * @return string
	 */
	public function destination_worksheet_id(): string {
		return $this->destination_worksheet_id;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Storage key.
	 *
	 * @param string $extension Extension.
	 * @param int    $attempt Attempt.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function storage_key( string $extension, int $attempt ): string {
		if ( $attempt < 0 || $attempt > 7 || 1 !== preg_match( '/^[a-z0-9]{1,8}$/D', $extension ) ) {
			throw new InvalidArgumentException( 'CloneWorksheetAsset storage key input is invalid.' );
		}
		$key = hash(
			'sha256',
			$this->actor_user_id . '|worksheet.asset.clone|' . $this->destination_worksheet_id
				. '|' . $this->source_asset_id . '|' . $this->idempotency_fingerprint . '|' . $attempt
		);
		return 'worksheet-assets/' . substr( $key, 0, 2 ) . '/' . $key . '.' . $extension;
	}
}

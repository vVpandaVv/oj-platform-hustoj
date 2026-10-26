<?php
/**
 * Discard staged worksheet asset result.
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
 * Discard staged worksheet asset result.
 */
final class Discard_Staged_Worksheet_Asset_Result {
	/**
	 * Initialize discard staged worksheet asset result dependencies and state.
	 *
	 * @param string $asset_id Asset id.
	 * @param string $discarded_at_utc Discarded at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $asset_id,
		private string $discarded_at_utc,
		private bool $replayed
	) {
		new Public_Id( $asset_id );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $discarded_at_utc ) ) {
			throw new InvalidArgumentException( 'Discard asset result time is invalid.' );
		}
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
	 * Discarded at utc.
	 *
	 * @return string
	 */
	public function discarded_at_utc(): string {
		return $this->discarded_at_utc;
	}

	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}

<?php
/**
 * StageWorksheetAsset result.
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
 * Safe upload receipt returned to the authoring workbench.
 */
final class Stage_Worksheet_Asset_Result {
	/**
	 * Asset id.
	 *
	 * @var string
	 */
	private string $asset_id;
	/**
	 * Original name.
	 *
	 * @var string
	 */
	private string $original_name;
	/**
	 * Mime type.
	 *
	 * @var string
	 */
	private string $mime_type;
	/**
	 * Byte size.
	 *
	 * @var int
	 */
	private int $byte_size;
	/**
	 * Sha256.
	 *
	 * @var string
	 */
	private string $sha256;
	/**
	 * State.
	 *
	 * @var string
	 */
	private string $state;
	/**
	 * Scan state.
	 *
	 * @var string
	 */
	private string $scan_state;
	/**
	 * Expires at utc.
	 *
	 * @var string
	 */
	private string $expires_at_utc;
	/**
	 * Replayed.
	 *
	 * @var bool
	 */
	private bool $replayed;

	/**
	 * Initialize stage worksheet asset result dependencies and state.
	 *
	 * @param string $asset_id Asset id.
	 * @param string $original_name Original name.
	 * @param string $mime_type Mime type.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @param string $state State.
	 * @param string $scan_state Scan state.
	 * @param string $expires_at_utc Expires at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $asset_id,
		string $original_name,
		string $mime_type,
		int $byte_size,
		string $sha256,
		string $state,
		string $scan_state,
		string $expires_at_utc,
		bool $replayed
	) {
		new Public_Id( $asset_id );
		if ( '' === $original_name || strlen( $original_name ) > 255 ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset result filename is invalid.' );
		}
		if ( '' === $mime_type || strlen( $mime_type ) > 191 || $byte_size < 1 ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset result media is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset result hash is invalid.' );
		}
		if ( ! in_array( $state, array( 'quarantined', 'ready' ), true ) || 'clean' !== $scan_state ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset result state is invalid.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expires_at_utc ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset result expiry is invalid.' );
		}

		$this->asset_id       = $asset_id;
		$this->original_name  = $original_name;
		$this->mime_type      = $mime_type;
		$this->byte_size      = $byte_size;
		$this->sha256         = $sha256;
		$this->state          = $state;
		$this->scan_state     = $scan_state;
		$this->expires_at_utc = $expires_at_utc;
		$this->replayed       = $replayed;
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
	 * Original name.
	 *
	 * @return string
	 */
	public function original_name(): string {
		return $this->original_name;
	}

	/**
	 * Mime type.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->mime_type;
	}

	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return $this->byte_size;
	}

	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->sha256;
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
	 * Scan state.
	 *
	 * @return string
	 */
	public function scan_state(): string {
		return $this->scan_state;
	}

	/**
	 * Expires at utc.
	 *
	 * @return string
	 */
	public function expires_at_utc(): string {
		return $this->expires_at_utc;
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

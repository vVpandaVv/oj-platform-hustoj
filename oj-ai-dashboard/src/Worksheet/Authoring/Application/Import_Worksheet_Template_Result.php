<?php
/**
 * ImportWorksheetTemplate receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImportWorksheetTemplate receipt.
 */
final class Import_Worksheet_Template_Result {
	/**
	 * Initialize import worksheet template result dependencies and state.
	 *
	 * @param Save_Worksheet_Draft_Result $saved Saved.
	 * @param string                      $package_hash Package hash.
	 * @param string                      $package_source_revision_id Package source revision id.
	 * @param int                         $imported_asset_count Imported asset count.
	 * @param int                         $reissued_id_count Reissued id count.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private Save_Worksheet_Draft_Result $saved,
		private string $package_hash,
		private string $package_source_revision_id,
		private int $imported_asset_count,
		private int $reissued_id_count
	) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $package_hash ) ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate package hash is invalid.' );
		}
		new Public_Id( $package_source_revision_id );
		if ( $imported_asset_count < 0 || $imported_asset_count > 100 || $reissued_id_count < 1 ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate receipt counts are invalid.' );
		}
	}

	/**
	 * Saved.
	 *
	 * @return Save_Worksheet_Draft_Result
	 */
	public function saved(): Save_Worksheet_Draft_Result {
		return $this->saved;
	}

	/**
	 * Package hash.
	 *
	 * @return string
	 */
	public function package_hash(): string {
		return $this->package_hash;
	}

	/**
	 * Package source revision id.
	 *
	 * @return string
	 */
	public function package_source_revision_id(): string {
		return $this->package_source_revision_id;
	}

	/**
	 * Imported asset count.
	 *
	 * @return int
	 */
	public function imported_asset_count(): int {
		return $this->imported_asset_count;
	}

	/**
	 * Reissued id count.
	 *
	 * @return int
	 */
	public function reissued_id_count(): int {
		return $this->reissued_id_count;
	}
}

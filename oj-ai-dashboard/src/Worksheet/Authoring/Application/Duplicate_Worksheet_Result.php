<?php
/**
 * DuplicateWorksheet result.
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
 * DuplicateWorksheet result.
 */
final class Duplicate_Worksheet_Result {
	/**
	 * Initialize duplicate worksheet result dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $source_worksheet_id Source worksheet id.
	 * @param string $source_revision_id Source revision id.
	 * @param string $document_id Document id.
	 * @param string $document_hash Document hash.
	 * @param string $validation_hash Validation hash.
	 * @param string $title Title.
	 * @param int    $asset_count Asset count.
	 * @param int    $reissued_id_count Reissued id count.
	 * @param string $created_at_utc Created at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private string $source_worksheet_id,
		private string $source_revision_id,
		private string $document_id,
		private string $document_hash,
		private string $validation_hash,
		private string $title,
		private int $asset_count,
		private int $reissued_id_count,
		private string $created_at_utc,
		private bool $replayed
	) {
		foreach ( array( $worksheet_id, $revision_id, $source_worksheet_id, $source_revision_id, $document_id ) as $id ) {
			new Public_Id( $id );
		}
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $document_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $validation_hash )
			|| '' === $title || $asset_count < 0 || $asset_count > 100 || $reissued_id_count < 1
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at_utc )
		) {
			throw new InvalidArgumentException( 'DuplicateWorksheet result is invalid.' );
		}
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
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
	}
	/**
	 * Source worksheet id.
	 *
	 * @return string
	 */
	public function source_worksheet_id(): string {
		return $this->source_worksheet_id;
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
	 * Document id.
	 *
	 * @return string
	 */
	public function document_id(): string {
		return $this->document_id;
	}
	/**
	 * Document hash.
	 *
	 * @return string
	 */
	public function document_hash(): string {
		return $this->document_hash;
	}
	/**
	 * Validation hash.
	 *
	 * @return string
	 */
	public function validation_hash(): string {
		return $this->validation_hash;
	}
	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}
	/**
	 * Asset count.
	 *
	 * @return int
	 */
	public function asset_count(): int {
		return $this->asset_count;
	}
	/**
	 * Reissued id count.
	 *
	 * @return int
	 */
	public function reissued_id_count(): int {
		return $this->reissued_id_count;
	}
	/**
	 * Created at utc.
	 *
	 * @return string
	 */
	public function created_at_utc(): string {
		return $this->created_at_utc;
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

<?php
/**
 * Stage submission zip result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe C19 receipt with no private path or entry filename projection.
 */
final class Stage_Submission_Zip_Result {
	/**
	 * Initialize stage submission zip result dependencies and state.
	 *
	 * @param string $upload_id Upload id.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $file_id File id.
	 * @param string $state State.
	 * @param string $original_name Original name.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @param int    $entry_count Entry count.
	 * @param int    $uncompressed_bytes Uncompressed bytes.
	 * @param string $created_at_utc Created at utc.
	 * @param string $expires_at_utc Expires at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $upload_id,
		private string $assignment_id,
		private string $file_id,
		private string $state,
		private string $original_name,
		private int $byte_size,
		private string $sha256,
		private int $entry_count,
		private int $uncompressed_bytes,
		private string $created_at_utc,
		private string $expires_at_utc,
		private bool $replayed
	) {
		new Public_Id( $upload_id );
		new Public_Id( $assignment_id );
		new Public_Id( $file_id );
		if (
			'scanning' !== $state
			|| '' === $original_name || strlen( $original_name ) > 255
			|| $byte_size < 22 || $entry_count < 1 || $uncompressed_bytes < 0
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at_utc )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expires_at_utc )
		) {
			throw new InvalidArgumentException( 'StageSubmissionZip result is invalid.' );
		}
	}

	/**
	 * Upload.
	 *
	 * @return array<string,mixed>
	 */
	public function upload(): array {
		return array(
			'id'                 => $this->upload_id,
			'assignment_id'      => $this->assignment_id,
			'file_id'            => $this->file_id,
			'state'              => $this->state,
			'original_name'      => $this->original_name,
			'byte_size'          => $this->byte_size,
			'sha256'             => $this->sha256,
			'entry_count'        => $this->entry_count,
			'uncompressed_bytes' => $this->uncompressed_bytes,
			'created_at_utc'     => $this->created_at_utc,
		);
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

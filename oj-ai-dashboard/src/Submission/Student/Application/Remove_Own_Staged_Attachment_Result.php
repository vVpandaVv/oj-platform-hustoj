<?php
/**
 * Remove own staged attachment result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe C25 receipt projection.
 */
final class Remove_Own_Staged_Attachment_Result {
	/**
	 * Initialize remove own staged attachment result dependencies and state.
	 *
	 * @param string $attachment_id Attachment id.
	 * @param string $removed_at_utc Removed at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $attachment_id,
		private string $removed_at_utc,
		private bool $replayed
	) {
		new Public_Id( $attachment_id );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $removed_at_utc ) ) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment result time is invalid.' );
		}
	}

	/**
	 * Attachment.
	 *
	 * @return array{id:string,state:string}
	 */
	public function attachment(): array {
		return array(
			'id'    => $this->attachment_id,
			'state' => 'removed',
		);
	}

	/**
	 * Removed at utc.
	 *
	 * @return string
	 */
	public function removed_at_utc(): string {
		return $this->removed_at_utc;
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

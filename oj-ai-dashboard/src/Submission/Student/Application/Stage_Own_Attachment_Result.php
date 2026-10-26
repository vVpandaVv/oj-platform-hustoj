<?php
/**
 * Stage own attachment result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe C24 attachment receipt.
 */
final class Stage_Own_Attachment_Result {
	/**
	 * Initialize stage own attachment result dependencies and state.
	 *
	 * @param string $attachment_id Attachment id.
	 * @param string $response_id Response id.
	 * @param string $original_name Original name.
	 * @param string $mime_type Mime type.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @param string $state State.
	 * @param string $scan_state Scan state.
	 * @param string $expires_at_utc Expires at utc.
	 * @param bool   $replayed Replayed.
	 */
	public function __construct(
		private string $attachment_id,
		private string $response_id,
		private string $original_name,
		private string $mime_type,
		private int $byte_size,
		private string $sha256,
		private string $state,
		private string $scan_state,
		private string $expires_at_utc,
		private bool $replayed
	) {
	}

	/**
	 * Attachment.
	 *
	 * @return array<string,mixed>
	 */
	public function attachment(): array {
		return array(
			'id'            => $this->attachment_id,
			'response_id'   => $this->response_id,
			'original_name' => $this->original_name,
			'mime_type'     => $this->mime_type,
			'byte_size'     => $this->byte_size,
			'sha256'        => $this->sha256,
			'state'         => $this->state,
			'scan_state'    => $this->scan_state,
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

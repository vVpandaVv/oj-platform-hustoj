<?php
/**
 * Safe projection of one manual storage-worker resume run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Application;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe projection of one manual storage-worker resume run.
 */
final class Resume_Storage_Jobs_Result {
	/**
	 * Initialize resume storage jobs result dependencies and state.
	 *
	 * @param Resume_Storage_Jobs_Worker_Outcome $attachment_scan Attachment scan.
	 * @param Resume_Storage_Jobs_Worker_Outcome $attachment_cleanup Attachment cleanup.
	 * @param Resume_Storage_Jobs_Worker_Outcome $zip_scan Zip scan.
	 * @param Resume_Storage_Jobs_Worker_Outcome $zip_cleanup Zip cleanup.
	 * @param Resume_Storage_Jobs_Worker_Outcome $export_cleanup Export cleanup.
	 */
	public function __construct(
		private Resume_Storage_Jobs_Worker_Outcome $attachment_scan,
		private Resume_Storage_Jobs_Worker_Outcome $attachment_cleanup,
		private Resume_Storage_Jobs_Worker_Outcome $zip_scan,
		private Resume_Storage_Jobs_Worker_Outcome $zip_cleanup,
		private Resume_Storage_Jobs_Worker_Outcome $export_cleanup
	) {
	}

	/**
	 * Attachment scan.
	 *
	 * @return Resume_Storage_Jobs_Worker_Outcome
	 */
	public function attachment_scan(): Resume_Storage_Jobs_Worker_Outcome {
		return $this->attachment_scan;
	}

	/**
	 * Attachment cleanup.
	 *
	 * @return Resume_Storage_Jobs_Worker_Outcome
	 */
	public function attachment_cleanup(): Resume_Storage_Jobs_Worker_Outcome {
		return $this->attachment_cleanup;
	}

	/**
	 * Zip scan.
	 *
	 * @return Resume_Storage_Jobs_Worker_Outcome
	 */
	public function zip_scan(): Resume_Storage_Jobs_Worker_Outcome {
		return $this->zip_scan;
	}

	/**
	 * Zip cleanup.
	 *
	 * @return Resume_Storage_Jobs_Worker_Outcome
	 */
	public function zip_cleanup(): Resume_Storage_Jobs_Worker_Outcome {
		return $this->zip_cleanup;
	}

	/**
	 * Total iterations.
	 *
	 * @return int
	 */
	public function total_iterations(): int {
		return $this->attachment_scan->iterations()
			+ $this->attachment_cleanup->iterations()
			+ $this->zip_scan->iterations()
			+ $this->zip_cleanup->iterations()
			+ $this->export_cleanup->iterations();
	}

	/**
	 * All succeeded.
	 *
	 * @return bool
	 */
	public function all_succeeded(): bool {
		return $this->attachment_scan->succeeded()
			&& $this->attachment_cleanup->succeeded()
			&& $this->zip_scan->succeeded()
			&& $this->zip_cleanup->succeeded()
			&& $this->export_cleanup->succeeded();
	}

	/**
	 * Projection.
	 *
	 * @return array<string,array{iterations:int,succeeded:bool}>
	 */
	public function projection(): array {
		return array(
			'attachment_scan'    => $this->attachment_scan->projection(),
			'attachment_cleanup' => $this->attachment_cleanup->projection(),
			'zip_scan'           => $this->zip_scan->projection(),
			'zip_cleanup'        => $this->zip_cleanup->projection(),
			'export_cleanup'     => $this->export_cleanup->projection(),
		);
	}
}

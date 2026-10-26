<?php
/**
 * PublishWorksheetRevision result.
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
 * PublishWorksheetRevision result.
 */
final class Publish_Worksheet_Revision_Result {
	/**
	 * Initialize publish worksheet revision result dependencies and state.
	 *
	 * @param string               $worksheet_id Worksheet public identifier.
	 * @param string               $revision_id Revision id.
	 * @param string               $publication_id Publication id.
	 * @param int                  $publication_no Publication no.
	 * @param string               $document_hash Document hash.
	 * @param string               $approval_decision_hash Approval decision hash.
	 * @param string               $projection_manifest_version Projection manifest version.
	 * @param string               $publication_hash Publication hash.
	 * @param array<string,string> $profile_hashes Profile hashes.
	 * @param int                  $published_by_user_id Published by user id.
	 * @param string               $published_at_utc Published at utc.
	 * @param bool                 $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private string $publication_id,
		private int $publication_no,
		private string $document_hash,
		private string $approval_decision_hash,
		private string $projection_manifest_version,
		private string $publication_hash,
		private array $profile_hashes,
		private int $published_by_user_id,
		private string $published_at_utc,
		private bool $replayed
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		new Public_Id( $publication_id );
		if ( $publication_no < 1 || $published_by_user_id < 1 || '1.0.0' !== $projection_manifest_version ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision receipt identity is invalid.' );
		}
		foreach ( array( $document_hash, $approval_decision_hash, $publication_hash ) as $hash ) {
			$this->assert_hash( $hash );
		}
		$expected = array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' );
		$actual   = array_keys( $profile_hashes );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision profile receipt is invalid.' );
		}
		foreach ( $profile_hashes as $hash ) {
			$this->assert_hash( $hash );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $published_at_utc ) ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision receipt UTC value is invalid.' );
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
	 * Publication id.
	 *
	 * @return string
	 */
	public function publication_id(): string {
		return $this->publication_id;
	}
	/**
	 * Publication no.
	 *
	 * @return int
	 */
	public function publication_no(): int {
		return $this->publication_no;
	}
	/**
	 * Publication state.
	 *
	 * @return string
	 */
	public function publication_state(): string {
		return 'active';
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
	 * Approval decision hash.
	 *
	 * @return string
	 */
	public function approval_decision_hash(): string {
		return $this->approval_decision_hash;
	}
	/**
	 * Projection manifest version.
	 *
	 * @return string
	 */
	public function projection_manifest_version(): string {
		return $this->projection_manifest_version;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
	}
	/**
	 * Profile hashes.
	 *
	 * @return array<string,string>
	 */
	public function profile_hashes(): array {
		return $this->profile_hashes;
	}
	/**
	 * Published by user id.
	 *
	 * @return int
	 */
	public function published_by_user_id(): int {
		return $this->published_by_user_id;
	}
	/**
	 * Published at utc.
	 *
	 * @return string
	 */
	public function published_at_utc(): string {
		return $this->published_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $hash ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new InvalidArgumentException( 'PublishWorksheetRevision receipt hash is invalid.' );
		}
	}
}

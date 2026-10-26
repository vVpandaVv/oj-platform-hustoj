<?php
/**
 * ApproveWorksheetRevision result.
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
 * ApproveWorksheetRevision result.
 */
final class Approve_Worksheet_Revision_Result {
	/**
	 * Initialize worksheet revision result dependencies and state.
	 *
	 * @param string      $worksheet_id Worksheet public identifier.
	 * @param string      $revision_id Revision id.
	 * @param int         $revision_no Revision no.
	 * @param string      $document_hash Document hash.
	 * @param string      $validation_projection_hash Validation projection hash.
	 * @param string      $policy_revision_id Policy revision id.
	 * @param string      $policy_hash Policy hash.
	 * @param string|null $evaluation_projection_hash Evaluation projection hash.
	 * @param string      $approval_evidence_hash Approval evidence hash.
	 * @param string      $approval_decision_hash Approval decision hash.
	 * @param int         $approved_by_user_id Approved by user id.
	 * @param string      $approved_at_utc Approved at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private int $revision_no,
		private string $document_hash,
		private string $validation_projection_hash,
		private string $policy_revision_id,
		private string $policy_hash,
		private ?string $evaluation_projection_hash,
		private string $approval_evidence_hash,
		private string $approval_decision_hash,
		private int $approved_by_user_id,
		private string $approved_at_utc,
		private bool $replayed
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		new Public_Id( $policy_revision_id );
		if ( $revision_no < 1 || $approved_by_user_id < 1 ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision receipt identity is invalid.' );
		}
		foreach (
			array(
				$document_hash,
				$validation_projection_hash,
				$policy_hash,
				$approval_evidence_hash,
				$approval_decision_hash,
			) as $hash
		) {
			$this->assert_hash( $hash );
		}
		if ( null !== $evaluation_projection_hash ) {
			$this->assert_hash( $evaluation_projection_hash );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approved_at_utc ) ) {
			throw new InvalidArgumentException( 'ApproveWorksheetRevision receipt UTC value is invalid.' );
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
	 * Revision no.
	 *
	 * @return int
	 */
	public function revision_no(): int {
		return $this->revision_no;
	}
	/**
	 * Revision state.
	 *
	 * @return string
	 */
	public function revision_state(): string {
		return 'approved';
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
	 * Validation projection hash.
	 *
	 * @return string
	 */
	public function validation_projection_hash(): string {
		return $this->validation_projection_hash;
	}
	/**
	 * Policy revision id.
	 *
	 * @return string
	 */
	public function policy_revision_id(): string {
		return $this->policy_revision_id;
	}
	/**
	 * Policy hash.
	 *
	 * @return string
	 */
	public function policy_hash(): string {
		return $this->policy_hash;
	}
	/**
	 * Evaluation projection hash.
	 *
	 * @return string|null
	 */
	public function evaluation_projection_hash(): ?string {
		return $this->evaluation_projection_hash;
	}
	/**
	 * Approval evidence hash.
	 *
	 * @return string
	 */
	public function approval_evidence_hash(): string {
		return $this->approval_evidence_hash;
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
	 * Approved by user id.
	 *
	 * @return int
	 */
	public function approved_by_user_id(): int {
		return $this->approved_by_user_id;
	}
	/**
	 * Approved at utc.
	 *
	 * @return string
	 */
	public function approved_at_utc(): string {
		return $this->approved_at_utc;
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
			throw new InvalidArgumentException( 'ApproveWorksheetRevision receipt hash is invalid.' );
		}
	}
}

<?php
/**
 * Control teacher submission result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable C27-C28 result and durable idempotency projection.
 */
final class Control_Teacher_Submission_Result {
	/**
	 * Initialize control teacher submission result dependencies and state.
	 *
	 * @param array<string,mixed>      $submission Submission.
	 * @param array<string,mixed>      $receipt Receipt.
	 * @param array<string,mixed>|null $editable_revision Editable revision.
	 * @param bool                     $created Created.
	 * @param bool                     $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $submission,
		private array $receipt,
		private ?array $editable_revision,
		private bool $created,
		private bool $replayed
	) {
		$this->assert_submission( $submission );
		$this->assert_receipt( $receipt );
		if ( null !== $editable_revision ) {
			$this->assert_revision( $editable_revision );
		}
		if (
			$submission['id'] !== $receipt['submission_id']
			|| $submission['official_attempt_no'] !== $receipt['attempt_no']
			|| ( $replayed && ! $created )
		) {
			throw new InvalidArgumentException( 'Teacher submission control result is inconsistent.' );
		}
		if ( 'reopen' === $receipt['action'] ) {
			if (
				'reopened' !== $submission['state']
				|| null === $editable_revision
				|| $submission['current_revision_id'] !== $editable_revision['id']
				|| $receipt['submission_revision_id'] !== $editable_revision['id']
				|| $receipt['submission_revision_hash'] !== $editable_revision['content_hash']
				|| null !== $submission['closed_at_utc']
			) {
				throw new InvalidArgumentException( 'Teacher reopen result is inconsistent.' );
			}
		} elseif (
			'lock' !== $receipt['action']
			|| 'locked' !== $submission['state']
			|| null !== $editable_revision
			|| $submission['current_revision_id'] !== $submission['official_revision_id']
			|| $receipt['submission_revision_id'] !== $submission['official_revision_id']
			|| null === $submission['closed_at_utc']
		) {
			throw new InvalidArgumentException( 'Teacher lock result is inconsistent.' );
		}
		ksort( $submission, SORT_STRING );
		ksort( $receipt, SORT_STRING );
		if ( null !== $editable_revision ) {
			ksort( $editable_revision, SORT_STRING );
		}
		$this->submission        = $submission;
		$this->receipt           = $receipt;
		$this->editable_revision = $editable_revision;
	}

	/**
	 * Submission.
	 *
	 * @return array<string,mixed>
	 */
	public function submission(): array {
		return $this->submission;
	}
	/**
	 * Receipt.
	 *
	 * @return array<string,mixed>
	 */
	public function receipt(): array {
		return $this->receipt;
	}
	/**
	 * Editable revision.
	 *
	 * @return array<string,mixed>|null
	 */
	public function editable_revision(): ?array {
		return $this->editable_revision;
	}
	/**
	 * Created.
	 *
	 * @return bool
	 */
	public function created(): bool {
		return $this->created;
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
	 * Reject invalid submission.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_submission( array $value ): void {
		$this->exact_keys(
			$value,
			array(
				'actions',
				'assignment_id',
				'closed_at_utc',
				'current_revision_id',
				'id',
				'lock_version',
				'official_attempt_no',
				'official_revision_id',
				'state',
				'submitted_at_utc',
			)
		);
		foreach ( array( 'id', 'assignment_id', 'current_revision_id', 'official_revision_id' ) as $field ) {
			new Public_Id( (string) $value[ $field ] );
		}
		if (
			! in_array( $value['state'], array( 'reopened', 'locked' ), true )
			|| ! is_int( $value['lock_version'] ) || $value['lock_version'] < 1
			|| ! is_int( $value['official_attempt_no'] ) || $value['official_attempt_no'] < 1
			|| ! is_string( $value['submitted_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['submitted_at_utc'] )
			|| ( null !== $value['closed_at_utc'] && ( ! is_string( $value['closed_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['closed_at_utc'] ) ) )
			|| ! is_array( $value['actions'] ) || array() !== $value['actions']
		) {
			throw new InvalidArgumentException( 'Teacher submission control projection is invalid.' );
		}
	}

	/**
	 * Reject invalid receipt.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_receipt( array $value ): void {
		$this->exact_keys(
			$value,
			array(
				'action',
				'actor_type',
				'assignment_schedule_hash',
				'assignment_schedule_version',
				'attachment_set_hash',
				'attempt_no',
				'content_hash',
				'context_revision',
				'id',
				'publication_hash',
				'publication_id',
				'reason',
				'receipt_no',
				'received_at_utc',
				'resubmission_policy_hash',
				'submission_id',
				'submission_revision_hash',
				'submission_revision_id',
				'timeliness',
			)
		);
		foreach ( array( 'id', 'publication_id', 'submission_id', 'submission_revision_id' ) as $field ) {
			new Public_Id( (string) $value[ $field ] );
		}
		foreach ( array( 'assignment_schedule_hash', 'attachment_set_hash', 'content_hash', 'publication_hash', 'resubmission_policy_hash', 'submission_revision_hash' ) as $field ) {
			if ( ! is_string( $value[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value[ $field ] ) ) {
				throw new InvalidArgumentException( 'Teacher submission control receipt hash is invalid.' );
			}
		}
		if (
			! in_array( $value['action'], array( 'reopen', 'lock' ), true )
			|| 'teacher' !== $value['actor_type']
			|| ! is_string( $value['reason'] ) || '' === $value['reason'] || strlen( $value['reason'] ) > 500
			|| ! in_array( $value['timeliness'], array( 'on_time', 'late' ), true )
			|| ! is_int( $value['receipt_no'] ) || $value['receipt_no'] < 1
			|| ! is_int( $value['attempt_no'] ) || $value['attempt_no'] < 1
			|| ! is_int( $value['assignment_schedule_version'] ) || $value['assignment_schedule_version'] < 1
			|| ! is_string( $value['context_revision'] ) || '' === $value['context_revision'] || strlen( $value['context_revision'] ) > 191
			|| ! is_string( $value['received_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['received_at_utc'] )
		) {
			throw new InvalidArgumentException( 'Teacher submission control receipt is invalid.' );
		}
	}

	/**
	 * Reject invalid revision.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_revision( array $value ): void {
		$this->exact_keys(
			$value,
			array( 'attachment_set_hash', 'base_revision_no', 'content_hash', 'created_at_utc', 'id', 'response_hash', 'revision_no', 'state' )
		);
		new Public_Id( (string) $value['id'] );
		if (
			'reopened_fork' !== $value['state']
			|| ! is_int( $value['revision_no'] ) || $value['revision_no'] < 2
			|| ! is_int( $value['base_revision_no'] ) || $value['base_revision_no'] < 1 || $value['base_revision_no'] >= $value['revision_no']
			|| ! is_string( $value['created_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['created_at_utc'] )
		) {
			throw new InvalidArgumentException( 'Teacher reopened revision is invalid.' );
		}
		foreach ( array( 'attachment_set_hash', 'content_hash', 'response_hash' ) as $field ) {
			if ( ! is_string( $value[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value[ $field ] ) ) {
				throw new InvalidArgumentException( 'Teacher reopened revision hash is invalid.' );
			}
		}
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Teacher submission control result must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Teacher submission control result fields are invalid.' );
		}
	}
}

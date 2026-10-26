<?php
/**
 * Submit own submission result.
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
 * Immutable C26 result and durable idempotency receipt projection.
 */
final class Submit_Own_Submission_Result {
	/**
	 * Initialize submit own submission result dependencies and state.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $created Created.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $submission, private array $receipt, private bool $created, private bool $replayed ) {
		$this->assert_submission( $submission );
		$this->assert_receipt( $receipt );
		if (
			$submission['id'] !== $receipt['submission_id']
			|| $submission['official_revision_id'] !== $receipt['submission_revision_id']
			|| $submission['official_attempt_no'] !== $receipt['attempt_no']
			|| ( $replayed && ! $created )
		) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission result is inconsistent.' );
		}
		$this->submission = array(
			'id'                   => $submission['id'],
			'assignment_id'        => $submission['assignment_id'],
			'state'                => $submission['state'],
			'lock_version'         => $submission['lock_version'],
			'current_revision_id'  => $submission['current_revision_id'],
			'official_revision_id' => $submission['official_revision_id'],
			'official_attempt_no'  => $submission['official_attempt_no'],
			'submitted_at_utc'     => $submission['submitted_at_utc'],
			'actions'              => $submission['actions'],
		);
		$this->receipt    = array(
			'id'                          => $receipt['id'],
			'content_hash'                => $receipt['content_hash'],
			'receipt_no'                  => $receipt['receipt_no'],
			'action'                      => $receipt['action'],
			'actor_type'                  => $receipt['actor_type'],
			'submission_id'               => $receipt['submission_id'],
			'submission_revision_id'      => $receipt['submission_revision_id'],
			'attempt_no'                  => $receipt['attempt_no'],
			'submission_revision_hash'    => $receipt['submission_revision_hash'],
			'publication_id'              => $receipt['publication_id'],
			'publication_hash'            => $receipt['publication_hash'],
			'attachment_set_hash'         => $receipt['attachment_set_hash'],
			'assignment_schedule_version' => $receipt['assignment_schedule_version'],
			'assignment_schedule_hash'    => $receipt['assignment_schedule_hash'],
			'resubmission_policy_hash'    => $receipt['resubmission_policy_hash'],
			'timeliness'                  => $receipt['timeliness'],
			'received_at_utc'             => $receipt['received_at_utc'],
			'context_revision'            => $receipt['context_revision'],
		);
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
		$this->exact_keys( $value, array( 'actions', 'assignment_id', 'current_revision_id', 'id', 'lock_version', 'official_attempt_no', 'official_revision_id', 'state', 'submitted_at_utc' ) );
		new Public_Id( (string) $value['id'] );
		new Public_Id( (string) $value['assignment_id'] );
		new Public_Id( (string) $value['current_revision_id'] );
		new Public_Id( (string) $value['official_revision_id'] );
		if (
			'submitted' !== $value['state']
			|| $value['current_revision_id'] !== $value['official_revision_id']
			|| ! is_int( $value['lock_version'] ) || $value['lock_version'] < 1
			|| ! is_int( $value['official_attempt_no'] ) || $value['official_attempt_no'] < 1
			|| ! is_string( $value['submitted_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['submitted_at_utc'] )
			|| array() !== $value['actions']
		) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission submission result is invalid.' );
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
				throw new InvalidArgumentException( 'SubmitOwnSubmission receipt hash is invalid.' );
			}
		}
		if (
			'submit' !== $value['action'] || 'learner' !== $value['actor_type']
			|| ! in_array( $value['timeliness'], array( 'on_time', 'late' ), true )
			|| ! is_int( $value['receipt_no'] ) || $value['receipt_no'] < 1
			|| ! is_int( $value['attempt_no'] ) || $value['attempt_no'] < 1
			|| ! is_int( $value['assignment_schedule_version'] ) || $value['assignment_schedule_version'] < 1
			|| ! is_string( $value['context_revision'] ) || '' === $value['context_revision'] || strlen( $value['context_revision'] ) > 191
			|| ! is_string( $value['received_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['received_at_utc'] )
		) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission receipt result is invalid.' );
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
			throw new InvalidArgumentException( 'SubmitOwnSubmission result must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'SubmitOwnSubmission result fields are invalid.' );
		}
	}
}

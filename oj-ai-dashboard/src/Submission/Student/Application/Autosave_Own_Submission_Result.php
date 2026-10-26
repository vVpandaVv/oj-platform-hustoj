<?php
/**
 * Autosave own submission result.
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
 * Immutable C23 result and durable idempotency receipt projection.
 */
final class Autosave_Own_Submission_Result {
	/**
	 * Initialize autosave own submission result dependencies and state.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @param array<string,mixed> $revision Revision.
	 * @param bool                $created Created.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $submission, private array $revision, private bool $created, private bool $replayed ) {
		$this->assert_submission( $submission );
		$this->assert_revision( $revision );
		if ( $submission['current_revision_id'] !== $revision['id'] || ( $replayed && ! $created ) ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission result is inconsistent.' );
		}
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
	 * Revision.
	 *
	 * @return array<string,mixed>
	 */
	public function revision(): array {
		return $this->revision;
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
		$this->exact_keys( $value, array( 'actions', 'assignment_id', 'current_revision_id', 'id', 'lock_version', 'official_revision_id', 'save_token', 'state' ) );
		new Public_Id( (string) $value['id'] );
		new Public_Id( (string) $value['assignment_id'] );
		new Public_Id( (string) $value['current_revision_id'] );
		if ( null !== $value['official_revision_id'] ) {
			new Public_Id( (string) $value['official_revision_id'] );
		}
		if (
			! in_array( $value['state'], array( 'draft', 'reopened' ), true )
			|| ! is_int( $value['lock_version'] ) || $value['lock_version'] < 1
			|| ! is_string( $value['save_token'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['save_token'] )
			|| array( 'submission.autosave' ) !== $value['actions']
		) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission submission result is invalid.' );
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
		$this->exact_keys( $value, array( 'attachment_set_hash', 'base_revision_no', 'content_hash', 'created_at_utc', 'id', 'response_hash', 'revision_no', 'state' ) );
		new Public_Id( (string) $value['id'] );
		if (
			! is_int( $value['revision_no'] ) || $value['revision_no'] < 1
			|| ( null !== $value['base_revision_no'] && ( ! is_int( $value['base_revision_no'] ) || $value['base_revision_no'] < 1 ) )
			|| 'saved' !== $value['state']
			|| ! is_string( $value['created_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['created_at_utc'] )
		) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission revision result is invalid.' );
		}
		foreach ( array( 'response_hash', 'content_hash', 'attachment_set_hash' ) as $field ) {
			if ( ! is_string( $value[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value[ $field ] ) ) {
				throw new InvalidArgumentException( 'AutosaveOwnSubmission revision hash is invalid.' );
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
			throw new InvalidArgumentException( 'AutosaveOwnSubmission result must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'AutosaveOwnSubmission result fields are invalid.' );
		}
	}
}

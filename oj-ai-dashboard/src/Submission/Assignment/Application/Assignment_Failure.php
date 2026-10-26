<?php
/**
 * Stable assignment application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps platform and storage details out of public responses.
 */
final class Assignment_Failure extends RuntimeException {
	public const ROSTER_DENIED                                 = 'assignment_roster_denied';
	public const ROSTER_UNAVAILABLE                            = 'assignment_roster_unavailable';
	public const ROSTER_INVALID                                = 'assignment_roster_invalid';
	public const ROSTER_TOO_LARGE                              = 'assignment_roster_too_large';
	public const ROSTER_EMPTY                                  = 'assignment_roster_empty';
	public const DISPLAY_UNAVAILABLE                           = 'assignment_display_unavailable';
	public const PUBLICATION_CONFLICT                          = 'assignment_publication_conflict';
	public const TARGET_OVERLAP                                = 'assignment_target_overlap';
	public const TARGETS_CLOSED                                = 'assignment_targets_closed';
	public const IDEMPOTENCY_CONFLICT                          = 'assignment_idempotency_conflict';
	public const NOT_FOUND                                     = 'assignment_not_found';
	public const STATE_CONFLICT                                = 'assignment_state_conflict';
	public const SUBMISSION_REVISION_CONFLICT                  = 'submission_revision_conflict';
	public const SUBMISSION_VALIDATION_FAILED                  = 'submission_validation_failed';
	public const ATTACHMENT_NOT_READY                          = 'attachment_not_ready';
	public const ATTACHMENT_LIMIT_EXCEEDED                     = 'attachment_limit_exceeded';
	public const ATTACHMENT_UNSUPPORTED_MEDIA                  = 'attachment_unsupported_media';
	public const ATTACHMENT_INVALID_UPLOAD                     = 'attachment_invalid_upload';
	public const SUBMISSION_ALREADY_OFFICIAL                   = 'submission_already_official';
	public const STORAGE_UNAVAILABLE                           = 'assignment_storage_unavailable';
	public const STORED_DATA_INVALID                           = 'assignment_stored_data_invalid';
	public const TEACHER_SUBMISSION_ATTEMPT_MISMATCH           = 'teacher_submission_attempt_mismatch';
	public const TEACHER_SUBMISSION_MISSING_OFFICIAL_REVISION  = 'teacher_submission_missing_official_revision';
	public const TEACHER_SUBMISSION_RELEASE_WITHOUT_REVIEW     = 'teacher_submission_release_without_review';
	public const TEACHER_SUBMISSION_REVIEW_POINTER_INCOMPLETE  = 'teacher_submission_review_pointer_incomplete';
	public const TEACHER_SUBMISSION_RELEASE_POINTER_INCOMPLETE = 'teacher_submission_release_pointer_incomplete';
	public const TEACHER_TARGET_DISPLAY_SCHEMA_INVALID         = 'teacher_target_display_schema_invalid';
	public const SUBMIT_SUBMISSION_ID_MISMATCH                 = 'submit_submission_id_mismatch';
	public const SUBMIT_SCHEDULE_WINDOW_INVALID                = 'submit_schedule_window_invalid';
	public const SUBMIT_SCHEDULE_HASH_MISMATCH                 = 'submit_schedule_hash_mismatch';
	public const SUBMIT_PUBLICATION_HASH_MISMATCH              = 'submit_publication_hash_mismatch';
	public const SUBMIT_RESUBMISSION_POLICY_SCHEMA_INVALID     = 'submit_resubmission_policy_schema_invalid';
	public const SUBMIT_RESUBMISSION_POLICY_VALUES_INVALID     = 'submit_resubmission_policy_values_invalid';
	public const SUBMIT_RESUBMISSION_POLICY_WINDOW_INVALID     = 'submit_resubmission_policy_window_invalid';
	public const SUBMIT_ATTEMPT_LEDGER_INCONSISTENT            = 'submit_attempt_ledger_inconsistent';
	public const SUBMIT_REVISION_INTEGRITY_INVALID             = 'submit_revision_integrity_invalid';
	public const SUBMIT_REVISION_PUBLICATION_MISMATCH          = 'submit_revision_publication_mismatch';
	public const SUBMIT_REVISION_ANSWERS_MISMATCH              = 'submit_revision_answers_mismatch';
	public const SUBMIT_REVISION_CONTENT_HASH_MISMATCH         = 'submit_revision_content_hash_mismatch';
	public const SUBMIT_RESPONSE_SCHEMA_INVALID                = 'submit_response_schema_invalid';
	public const SUBMIT_RESPONSE_SOURCE_HASH_MISMATCH          = 'submit_response_source_hash_mismatch';
	public const SUBMIT_ATTACHMENT_ROWS_INVALID                = 'submit_attachment_rows_invalid';
	public const SUBMIT_ATTACHMENT_SET_HASH_MISMATCH           = 'submit_attachment_set_hash_mismatch';
	public const SUBMIT_POSTCONDITION_MISMATCH                 = 'submit_postcondition_mismatch';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Initialize assignment failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string $reason Reason.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function because( string $reason ): self {
		if ( ! in_array( $reason, self::reasons(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown assignment failure reason.' );
		}
		return new self( $reason );
	}

	/**
	 * Reasons.
	 *
	 * @return array<int,string>
	 */
	public static function reasons(): array {
		return array(
			self::ROSTER_DENIED,
			self::ROSTER_UNAVAILABLE,
			self::ROSTER_INVALID,
			self::ROSTER_TOO_LARGE,
			self::ROSTER_EMPTY,
			self::DISPLAY_UNAVAILABLE,
			self::PUBLICATION_CONFLICT,
			self::TARGET_OVERLAP,
			self::TARGETS_CLOSED,
			self::IDEMPOTENCY_CONFLICT,
			self::NOT_FOUND,
			self::STATE_CONFLICT,
			self::SUBMISSION_REVISION_CONFLICT,
			self::SUBMISSION_VALIDATION_FAILED,
			self::ATTACHMENT_NOT_READY,
			self::ATTACHMENT_LIMIT_EXCEEDED,
			self::ATTACHMENT_UNSUPPORTED_MEDIA,
			self::ATTACHMENT_INVALID_UPLOAD,
			self::SUBMISSION_ALREADY_OFFICIAL,
			self::STORAGE_UNAVAILABLE,
			self::STORED_DATA_INVALID,
			self::TEACHER_SUBMISSION_ATTEMPT_MISMATCH,
			self::TEACHER_SUBMISSION_MISSING_OFFICIAL_REVISION,
			self::TEACHER_SUBMISSION_RELEASE_WITHOUT_REVIEW,
			self::TEACHER_SUBMISSION_REVIEW_POINTER_INCOMPLETE,
			self::TEACHER_SUBMISSION_RELEASE_POINTER_INCOMPLETE,
			self::TEACHER_TARGET_DISPLAY_SCHEMA_INVALID,
			self::SUBMIT_SUBMISSION_ID_MISMATCH,
			self::SUBMIT_SCHEDULE_WINDOW_INVALID,
			self::SUBMIT_SCHEDULE_HASH_MISMATCH,
			self::SUBMIT_PUBLICATION_HASH_MISMATCH,
			self::SUBMIT_RESUBMISSION_POLICY_SCHEMA_INVALID,
			self::SUBMIT_RESUBMISSION_POLICY_VALUES_INVALID,
			self::SUBMIT_RESUBMISSION_POLICY_WINDOW_INVALID,
			self::SUBMIT_ATTEMPT_LEDGER_INCONSISTENT,
			self::SUBMIT_REVISION_INTEGRITY_INVALID,
			self::SUBMIT_REVISION_PUBLICATION_MISMATCH,
			self::SUBMIT_REVISION_ANSWERS_MISMATCH,
			self::SUBMIT_REVISION_CONTENT_HASH_MISMATCH,
			self::SUBMIT_RESPONSE_SCHEMA_INVALID,
			self::SUBMIT_RESPONSE_SOURCE_HASH_MISMATCH,
			self::SUBMIT_ATTACHMENT_ROWS_INVALID,
			self::SUBMIT_ATTACHMENT_SET_HASH_MISMATCH,
			self::SUBMIT_POSTCONDITION_MISMATCH,
		);
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}

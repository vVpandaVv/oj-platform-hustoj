<?php
/**
 * Stable student submission evaluation application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only stable reason codes and non-sensitive recovery data.
 */
final class Submission_Evaluation_Failure extends RuntimeException {
	public const NOT_FOUND                   = 'submission_evaluation_not_found';
	public const SOURCE_STALE                = 'submission_evaluation_source_stale';
	public const SOURCE_INVALID              = 'submission_evaluation_source_invalid';
	public const INPUT_INVALID               = 'submission_evaluation_input_invalid';
	public const INPUT_TOO_LARGE             = 'submission_evaluation_input_too_large';
	public const SUBMISSION_NOT_OFFICIAL     = 'submission_not_official';
	public const GRADING_SCHEMA_UNSUPPORTED  = 'grading_schema_unsupported';
	public const GRADING_SCHEMA_INVALID      = 'grading_schema_invalid';
	public const ARTIFACT_NOT_READY          = 'artifact_not_ready';
	public const NOT_ROUTABLE                = 'submission_evaluation_not_routable';
	public const ROUTING_SNAPSHOT_INVALID    = 'submission_evaluation_routing_snapshot_invalid';
	public const IDEMPOTENCY_CONFLICT        = 'submission_evaluation_idempotency_conflict';
	public const STATE_CONFLICT              = 'submission_evaluation_state_conflict';
	public const REVIEW_SOURCE_STALE         = 'review_source_stale';
	public const REVIEW_ITEMS_INCOMPLETE     = 'review_items_incomplete';
	public const FEEDBACK_SOURCE_STALE       = 'feedback_source_stale';
	public const FEEDBACK_SCHEMA_INVALID     = 'feedback_schema_invalid';
	public const FEEDBACK_NOT_APPROVED       = 'feedback_not_approved';
	public const RELEASE_SOURCE_STALE        = 'release_source_stale';
	public const RELEASE_PROJECTION_INVALID  = 'release_projection_invalid';
	public const RELEASE_ALREADY_CURRENT     = 'release_already_current';
	public const STUDENT_NOTE_SOURCE_STALE   = 'student_note_source_stale';
	public const STUDENT_NOTE_SCHEMA_INVALID = 'student_note_schema_invalid';
	public const REVIEW_SCORE_INVALID        = 'review_score_invalid';
	public const SAFETY_REVIEW_REQUIRED      = 'safety_review_required';
	public const CONFIGURATION_CHANGED       = 'submission_evaluation_configuration_changed';
	public const STORAGE_UNAVAILABLE         = 'submission_evaluation_storage_unavailable';
	public const STORED_DATA_INVALID         = 'submission_evaluation_stored_data_invalid';

	/**
	 * Safe context.
	 *
	 * @var array<string,int|string>
	 */
	private array $safe_context;

	/**
	 * Initialize submission evaluation failure dependencies and state.
	 *
	 * @param string                   $reason Reason.
	 * @param array<string,int|string> $safe_context Safe context.
	 */
	private function __construct( private string $reason, array $safe_context = array() ) {
		parent::__construct( $reason );
		$this->safe_context = $safe_context;
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string                   $reason Reason.
	 * @param array<string,int|string> $safe_context Safe context.
	 * @return self
	 */
	public static function because( string $reason, array $safe_context = array() ): self {
		return new self( $reason, $safe_context );
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Safe context.
	 *
	 * @return array<string,int|string>
	 */
	public function safe_context(): array {
		return $this->safe_context;
	}
}

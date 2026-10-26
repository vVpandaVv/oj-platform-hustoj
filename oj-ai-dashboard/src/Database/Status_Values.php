<?php
/**
 * Database-backed status values.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps status values in one place for schema and repositories.
 */
final class Status_Values {
	public const RECORD_LIFECYCLE = array( 'draft', 'active', 'archived' );
	public const ASSIGNMENT       = array( 'draft', 'assigned', 'closed', 'archived' );
	public const SUBMISSION       = array( 'draft', 'autosave_conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'submitted_with_pending_attachment_review', 'locked' );
	public const ATTACHMENT       = array( 'pending_upload', 'uploaded', 'linked', 'failed', 'removed' );
	public const ZIP_ITEM         = array( 'uploaded', 'auto_matched', 'needs_manual_mapping', 'mapped', 'imported', 'rejected' );
	public const JOB              = array( 'queued', 'running', 'succeeded', 'failed', 'cancelled' );
	public const ASSESSMENT_PLAN  = array( 'draft', 'ready', 'archived' );
	public const ASSESSMENT       = array( 'draft', 'review_required', 'accepted', 'change_pending', 'rejected' );
	public const REGRADE_REVIEW   = array( 'pending', 'accepted', 'rejected', 'superseded' );
	public const FEEDBACK         = array( 'teacher_draft', 'pending_approval', 'approved', 'published', 'rejected', 'unpublished' );
	public const FEEDBACK_ACTION  = array( 'approve', 'reject', 'publish', 'unpublish', 'request_changes' );
	public const SPECIAL_NOTE     = array( 'draft', 'reviewed', 'approved', 'included_in_report' );
	public const REPORT           = array( 'draft', 'generated', 'archived' );
	public const IMPORT_ITEM      = array( 'pending', 'previewed', 'skipped', 'imported', 'failed' );

	/**
	 * Build an enum SQL fragment.
	 *
	 * @param string[] $values Allowed values.
	 * @return string
	 */
	public static function enum_sql( array $values ) {
		$quoted = array();

		foreach ( $values as $value ) {
			$quoted[] = "'" . str_replace( "'", "''", (string) $value ) . "'";
		}

		return 'enum(' . implode( ',', $quoted ) . ')';
	}
}

<?php
/**
 * Database table name registry.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes plugin table names.
 */
final class Table_Names {
	public const ACADEMIC_YEARS              = 'academic_years';
	public const CLASSES                     = 'classes';
	public const GROUPS                      = 'groups';
	public const GROUP_MEMBERS               = 'group_members';
	public const ACADEMIC_CONTEXT_MAPPINGS   = 'academic_context_mappings';
	public const STUDENT_MAPPINGS            = 'student_mappings';
	public const ACTIVITY_EVENTS             = 'activity_events';
	public const AUDIT_LOGS                  = 'audit_logs';
	public const WORKSHEET_TEMPLATES         = 'worksheet_templates';
	public const WORKSHEET_QUESTIONS         = 'worksheet_questions';
	public const WORKSHEET_ASSIGNMENTS       = 'worksheet_assignments';
	public const WORKSHEET_SUBMISSIONS       = 'worksheet_submissions';
	public const SUBMISSION_REVISIONS        = 'submission_revisions';
	public const SUBMISSION_ATTACHMENTS      = 'submission_attachments';
	public const ZIP_UPLOADS                 = 'zip_uploads';
	public const ZIP_UPLOAD_ITEMS            = 'zip_upload_items';
	public const CURRICULUMS                 = 'curriculums';
	public const ACHIEVEMENT_STANDARDS       = 'achievement_standards';
	public const WORKSHEET_ACHIEVEMENT_LINKS = 'worksheet_achievement_links';
	public const QUESTION_ACHIEVEMENT_LINKS  = 'question_achievement_links';
	public const ASSESSMENT_PLANS            = 'assessment_plans';
	public const ASSESSMENT_RUNS             = 'assessment_runs';
	public const GRADING_INPUTS              = 'grading_inputs';
	public const RUBRIC_SNAPSHOTS            = 'rubric_snapshots';
	public const GRADING_ITEMS               = 'grading_items';
	public const ASSESSMENT_RESULTS          = 'assessment_results';
	public const REGRADE_REVIEWS             = 'regrade_reviews';
	public const FEEDBACK                    = 'feedback';
	public const FEEDBACK_APPROVALS          = 'feedback_approvals';
	public const SPECIAL_NOTES               = 'special_notes';
	public const REPORTS                     = 'reports';
	public const EXPORT_JOBS                 = 'export_jobs';
	public const EXPORT_FILES                = 'export_files';
	public const IMPORT_RUNS                 = 'import_runs';
	public const IMPORT_ITEMS                = 'import_items';

	/**
	 * Return physical table names keyed by logical table id.
	 *
	 * @return array
	 */
	public static function suffixes() {
		return array(
			self::ACADEMIC_YEARS              => 'oj_ai_academic_years',
			self::CLASSES                     => 'oj_ai_classes',
			self::GROUPS                      => 'oj_ai_groups',
			self::GROUP_MEMBERS               => 'oj_ai_group_members',
			self::ACADEMIC_CONTEXT_MAPPINGS   => 'oj_ai_academic_context_mappings',
			self::STUDENT_MAPPINGS            => 'oj_ai_student_mappings',
			self::ACTIVITY_EVENTS             => 'oj_ai_activity_events',
			self::AUDIT_LOGS                  => 'oj_ai_audit_logs',
			self::WORKSHEET_TEMPLATES         => 'oj_ai_worksheet_templates',
			self::WORKSHEET_QUESTIONS         => 'oj_ai_worksheet_questions',
			self::WORKSHEET_ASSIGNMENTS       => 'oj_ai_worksheet_assignments',
			self::WORKSHEET_SUBMISSIONS       => 'oj_ai_worksheet_submissions',
			self::SUBMISSION_REVISIONS        => 'oj_ai_submission_revisions',
			self::SUBMISSION_ATTACHMENTS      => 'oj_ai_submission_attachments',
			self::ZIP_UPLOADS                 => 'oj_ai_zip_uploads',
			self::ZIP_UPLOAD_ITEMS            => 'oj_ai_zip_upload_items',
			self::CURRICULUMS                 => 'oj_ai_curriculums',
			self::ACHIEVEMENT_STANDARDS       => 'oj_ai_achievement_standards',
			self::WORKSHEET_ACHIEVEMENT_LINKS => 'oj_ai_worksheet_achievement_links',
			self::QUESTION_ACHIEVEMENT_LINKS  => 'oj_ai_question_achievement_links',
			self::ASSESSMENT_PLANS            => 'oj_ai_assessment_plans',
			self::ASSESSMENT_RUNS             => 'oj_ai_assessment_runs',
			self::GRADING_INPUTS              => 'oj_ai_grading_inputs',
			self::RUBRIC_SNAPSHOTS            => 'oj_ai_rubric_snapshots',
			self::GRADING_ITEMS               => 'oj_ai_grading_items',
			self::ASSESSMENT_RESULTS          => 'oj_ai_assessment_results',
			self::REGRADE_REVIEWS             => 'oj_ai_regrade_reviews',
			self::FEEDBACK                    => 'oj_ai_feedback',
			self::FEEDBACK_APPROVALS          => 'oj_ai_feedback_approvals',
			self::SPECIAL_NOTES               => 'oj_ai_special_notes',
			self::REPORTS                     => 'oj_ai_reports',
			self::EXPORT_JOBS                 => 'oj_ai_export_jobs',
			self::EXPORT_FILES                => 'oj_ai_export_files',
			self::IMPORT_RUNS                 => 'oj_ai_import_runs',
			self::IMPORT_ITEMS                => 'oj_ai_import_items',
		);
	}

	/**
	 * Return a full table name.
	 *
	 * @param string $key Logical table key.
	 * @return string
	 */
	public static function table( $key ) {
		$suffixes = self::suffixes();

		if ( ! isset( $suffixes[ $key ] ) ) {
			return '';
		}

		return $suffixes[ $key ];
	}

	/**
	 * Return every full table name.
	 *
	 * @return array
	 */
	public static function all() {
		$tables = array();

		foreach ( array_keys( self::suffixes() ) as $key ) {
			$tables[ $key ] = self::table( $key );
		}

		return $tables;
	}

	/**
	 * Return the plugin table prefix.
	 *
	 * @return string
	 */
	public static function prefix() {
		return '';
	}
}

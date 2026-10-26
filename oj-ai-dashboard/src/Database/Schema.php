<?php
/**
 * Database schema definitions.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds dbDelta-compatible CREATE TABLE statements.
 */
final class Schema {
	/**
	 * Return all CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public function sql() {
		$sql = array();

		foreach ( $this->definitions() as $key => $definition ) {
			$sql[] = $this->create_table_sql(
				Table_Names::table( $key ),
				$definition['columns'],
				isset( $definition['keys'] ) ? $definition['keys'] : array()
			);
		}

		return $sql;
	}

	/**
	 * Return table definitions keyed by logical table id.
	 *
	 * @return array
	 */
	public function definitions() {
		return array(
			Table_Names::ACADEMIC_YEARS              => $this->academic_years(),
			Table_Names::CLASSES                     => $this->classes(),
			Table_Names::GROUPS                      => $this->groups(),
			Table_Names::GROUP_MEMBERS               => $this->group_members(),
			Table_Names::ACADEMIC_CONTEXT_MAPPINGS   => $this->academic_context_mappings(),
			Table_Names::STUDENT_MAPPINGS            => $this->student_mappings(),
			Table_Names::ACTIVITY_EVENTS             => $this->activity_events(),
			Table_Names::AUDIT_LOGS                  => $this->audit_logs(),
			Table_Names::WORKSHEET_TEMPLATES         => $this->worksheet_templates(),
			Table_Names::WORKSHEET_QUESTIONS         => $this->worksheet_questions(),
			Table_Names::WORKSHEET_ASSIGNMENTS       => $this->worksheet_assignments(),
			Table_Names::WORKSHEET_SUBMISSIONS       => $this->worksheet_submissions(),
			Table_Names::SUBMISSION_REVISIONS        => $this->submission_revisions(),
			Table_Names::SUBMISSION_ATTACHMENTS      => $this->submission_attachments(),
			Table_Names::ZIP_UPLOADS                 => $this->zip_uploads(),
			Table_Names::ZIP_UPLOAD_ITEMS            => $this->zip_upload_items(),
			Table_Names::CURRICULUMS                 => $this->curriculums(),
			Table_Names::ACHIEVEMENT_STANDARDS       => $this->achievement_standards(),
			Table_Names::WORKSHEET_ACHIEVEMENT_LINKS => $this->worksheet_achievement_links(),
			Table_Names::QUESTION_ACHIEVEMENT_LINKS  => $this->question_achievement_links(),
			Table_Names::ASSESSMENT_PLANS            => $this->assessment_plans(),
			Table_Names::ASSESSMENT_RUNS             => $this->assessment_runs(),
			Table_Names::GRADING_INPUTS              => $this->grading_inputs(),
			Table_Names::RUBRIC_SNAPSHOTS            => $this->rubric_snapshots(),
			Table_Names::GRADING_ITEMS               => $this->grading_items(),
			Table_Names::ASSESSMENT_RESULTS          => $this->assessment_results(),
			Table_Names::REGRADE_REVIEWS             => $this->regrade_reviews(),
			Table_Names::FEEDBACK                    => $this->feedback(),
			Table_Names::FEEDBACK_APPROVALS          => $this->feedback_approvals(),
			Table_Names::SPECIAL_NOTES               => $this->special_notes(),
			Table_Names::REPORTS                     => $this->reports(),
			Table_Names::EXPORT_JOBS                 => $this->export_jobs(),
			Table_Names::EXPORT_FILES                => $this->export_files(),
			Table_Names::IMPORT_RUNS                 => $this->import_runs(),
			Table_Names::IMPORT_ITEMS                => $this->import_items(),
		);
	}

	/**
	 * Create a dbDelta-compatible CREATE TABLE statement.
	 *
	 * @param string   $table Table name.
	 * @param string[] $columns Column definitions.
	 * @param string[] $keys Index definitions.
	 * @return string
	 */
	private function create_table_sql( $table, array $columns, array $keys ) {
		$parts = array_merge( $columns, array( 'PRIMARY KEY  (id)' ), $keys );

		return 'CREATE TABLE ' . $table . " (\n\t" . implode( ",\n\t", $parts ) . "\n) " . $this->charset_collate() . ';';
	}

	/**
	 * Return common columns for normal records.
	 *
	 * @param string[] $extra Extra columns.
	 * @return string[]
	 */
	private function common_columns( array $extra = array() ) {
		return array_merge(
			array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				"tenant_context varchar(191) NOT NULL DEFAULT 'default'",
			),
			$extra,
			array(
				'created_by bigint(20) unsigned NOT NULL DEFAULT 0',
				'updated_by bigint(20) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'deleted_at datetime NULL DEFAULT NULL',
				"source_type varchar(64) NOT NULL DEFAULT ''",
				"source_key varchar(191) NOT NULL DEFAULT ''",
				"source_id varchar(191) NOT NULL DEFAULT ''",
			)
		);
	}

	/**
	 * Return charset/collation SQL.
	 *
	 * @return string
	 */
	private function charset_collate() {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) {
			return $wpdb->get_charset_collate();
		}

		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * Academic years.
	 *
	 * @return mixed
	 */
	private function academic_years() {
		return array(
			'columns' => $this->common_columns(
				array(
					'academic_year varchar(20) NOT NULL',
					'label varchar(191) NOT NULL',
					'starts_on date NULL DEFAULT NULL',
					'ends_on date NULL DEFAULT NULL',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY tenant_year (tenant_context,academic_year)',
				'KEY active (active)',
			),
		);
	}

	/**
	 * Classes.
	 *
	 * @return mixed
	 */
	private function classes() {
		return array(
			'columns' => $this->common_columns(
				array(
					'academic_year varchar(20) NOT NULL',
					'grade varchar(20) NOT NULL',
					'class_name varchar(64) NOT NULL',
					'teacher_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY tenant_class (tenant_context,academic_year,grade,class_name)',
				'KEY teacher_user_id (teacher_user_id)',
			),
		);
	}

	/**
	 * Groups.
	 *
	 * @return mixed
	 */
	private function groups() {
		return array(
			'columns' => $this->common_columns(
				array(
					'group_key varchar(191) NOT NULL',
					'group_name varchar(191) NOT NULL',
					"origin enum('local','oj','imported') NOT NULL DEFAULT 'local'",
					'academic_year varchar(20) NOT NULL DEFAULT \'\'',
					'grade varchar(20) NOT NULL DEFAULT \'\'',
					'class_name varchar(64) NOT NULL DEFAULT \'\'',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY tenant_group_key (tenant_context,group_key)',
				'KEY academic_context (academic_year,grade,class_name)',
			),
		);
	}

	/**
	 * Group members.
	 *
	 * @return mixed
	 */
	private function group_members() {
		return array(
			'columns' => $this->common_columns(
				array(
					'group_id bigint(20) unsigned NOT NULL',
					'student_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'student_number varchar(20) NOT NULL DEFAULT \'\'',
					'display_name varchar(191) NOT NULL DEFAULT \'\'',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY group_student (group_id,student_user_id)',
				'KEY student_number (student_number)',
			),
		);
	}

	/**
	 * Academic context mappings.
	 *
	 * @return mixed
	 */
	private function academic_context_mappings() {
		return array(
			'columns' => $this->common_columns(
				array(
					"mapping_source_type enum('oj_group','oj_user','local_group','local_user') NOT NULL",
					'mapping_source_id varchar(191) NOT NULL',
					'academic_year varchar(20) NOT NULL',
					'grade varchar(20) NOT NULL',
					'class_name varchar(64) NOT NULL',
					'student_number varchar(20) NOT NULL DEFAULT \'\'',
					'teacher_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY tenant_source_context (tenant_context,mapping_source_type,mapping_source_id,academic_year,grade,class_name,student_number)',
				'KEY academic_context (academic_year,grade,class_name)',
				'KEY teacher_user_id (teacher_user_id)',
			),
		);
	}

	/**
	 * Student mappings.
	 *
	 * @return mixed
	 */
	private function student_mappings() {
		return array(
			'columns' => $this->common_columns(
				array(
					'student_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'login varchar(191) NOT NULL DEFAULT \'\'',
					'email varchar(191) NOT NULL DEFAULT \'\'',
					'display_name varchar(191) NOT NULL DEFAULT \'\'',
					'academic_year varchar(20) NOT NULL',
					'grade varchar(20) NOT NULL',
					'class_name varchar(64) NOT NULL',
					'student_number varchar(20) NOT NULL',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY tenant_roster_number (tenant_context,academic_year,grade,class_name,student_number)',
				'KEY student_user_id (student_user_id)',
				'KEY login (login)',
				'KEY email (email)',
			),
		);
	}

	/**
	 * Activity events.
	 *
	 * @return mixed
	 */
	private function activity_events() {
		return array(
			'columns' => $this->common_columns(
				array(
					'actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'student_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'event_type varchar(64) NOT NULL',
					'object_type varchar(64) NOT NULL',
					'object_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'event_at datetime NOT NULL',
					'payload_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY student_event (student_user_id,event_type)',
				'KEY object_lookup (object_type,object_id)',
				'KEY event_at (event_at)',
			),
		);
	}

	/**
	 * Audit logs.
	 *
	 * @return mixed
	 */
	private function audit_logs() {
		return array(
			'columns' => $this->common_columns(
				array(
					'actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'action varchar(64) NOT NULL',
					'object_type varchar(64) NOT NULL',
					'object_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'ip_address varchar(64) NOT NULL DEFAULT \'\'',
					'user_agent varchar(255) NOT NULL DEFAULT \'\'',
					'payload_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY actor_action (actor_user_id,action)',
				'KEY object_lookup (object_type,object_id)',
			),
		);
	}

	/**
	 * Worksheet templates.
	 *
	 * @return mixed
	 */
	private function worksheet_templates() {
		return array(
			'columns' => $this->common_columns(
				array(
					'title varchar(191) NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::RECORD_LIFECYCLE ) . " NOT NULL DEFAULT 'draft'",
					'plate_value_json longtext NULL',
					'form_schema_json longtext NULL',
					"rubric_mode enum('worksheet','question') NOT NULL DEFAULT 'worksheet'",
					'worksheet_rubric_json longtext NULL',
					'curriculum_links_json longtext NULL',
					'settings_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY status (status)',
				'KEY source_lookup (source_type,source_key,source_id)',
			),
		);
	}

	/**
	 * Worksheet questions.
	 *
	 * @return mixed
	 */
	private function worksheet_questions() {
		return array(
			'columns' => $this->common_columns(
				array(
					'template_id bigint(20) unsigned NOT NULL',
					'question_id varchar(191) NOT NULL',
					'title varchar(191) NOT NULL DEFAULT \'\'',
					'sort_order int(11) NOT NULL DEFAULT 0',
					'points decimal(10,2) NOT NULL DEFAULT 0.00',
					'grading_enabled tinyint(1) NOT NULL DEFAULT 1',
					'question_schema_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY template_question (template_id,question_id)',
				'KEY sort_order (sort_order)',
			),
		);
	}

	/**
	 * Worksheet assignments.
	 *
	 * @return mixed
	 */
	private function worksheet_assignments() {
		return array(
			'columns' => $this->common_columns(
				array(
					'template_id bigint(20) unsigned NOT NULL',
					'title varchar(191) NOT NULL',
					'academic_year varchar(20) NOT NULL DEFAULT \'\'',
					'grade varchar(20) NOT NULL DEFAULT \'\'',
					'class_name varchar(64) NOT NULL DEFAULT \'\'',
					'group_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'due_at datetime NULL DEFAULT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::ASSIGNMENT ) . " NOT NULL DEFAULT 'draft'",
					'settings_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY template_id (template_id)',
				'KEY due_at (due_at)',
				'KEY academic_context (academic_year,grade,class_name)',
			),
		);
	}

	/**
	 * Worksheet submissions.
	 *
	 * @return mixed
	 */
	private function worksheet_submissions() {
		return array(
			'columns' => $this->common_columns(
				array(
					'assignment_id bigint(20) unsigned NOT NULL',
					'student_user_id bigint(20) unsigned NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::SUBMISSION ) . " NOT NULL DEFAULT 'draft'",
					'server_revision bigint(20) unsigned NOT NULL DEFAULT 0',
					'autosave_session_id varchar(191) NOT NULL DEFAULT \'\'',
					'submitted_at datetime NULL DEFAULT NULL',
					'locked_at datetime NULL DEFAULT NULL',
					'content_json longtext NULL',
					'grading_schema_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY assignment_student (assignment_id,student_user_id)',
				'KEY status (status)',
				'KEY student_user_id (student_user_id)',
			),
		);
	}

	/**
	 * Submission revisions.
	 *
	 * @return mixed
	 */
	private function submission_revisions() {
		return array(
			'columns' => $this->common_columns(
				array(
					'submission_id bigint(20) unsigned NOT NULL',
					'revision bigint(20) unsigned NOT NULL',
					'idempotency_key varchar(191) NOT NULL',
					'actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'content_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY submission_revision (submission_id,revision)',
				'UNIQUE KEY idempotency_key (idempotency_key)',
			),
		);
	}

	/**
	 * Submission attachments.
	 *
	 * @return mixed
	 */
	private function submission_attachments() {
		return array(
			'columns' => $this->common_columns(
				array(
					'submission_id bigint(20) unsigned NOT NULL',
					'block_id varchar(191) NOT NULL DEFAULT \'\'',
					"attachment_type enum('image','file','drawing') NOT NULL DEFAULT 'file'",
					'status ' . Status_Values::enum_sql( Status_Values::ATTACHMENT ) . " NOT NULL DEFAULT 'pending_upload'",
					'file_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'file_url text NULL',
					'meta_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY submission_id (submission_id)',
				'KEY block_id (block_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Zip uploads.
	 *
	 * @return mixed
	 */
	private function zip_uploads() {
		return array(
			'columns' => $this->common_columns(
				array(
					'assignment_id bigint(20) unsigned NOT NULL',
					'uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'status ' . Status_Values::enum_sql( Status_Values::JOB ) . " NOT NULL DEFAULT 'queued'",
					'original_filename varchar(255) NOT NULL DEFAULT \'\'',
					'file_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'summary_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY assignment_id (assignment_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Zip upload items.
	 *
	 * @return mixed
	 */
	private function zip_upload_items() {
		return array(
			'columns' => $this->common_columns(
				array(
					'zip_upload_id bigint(20) unsigned NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::ZIP_ITEM ) . " NOT NULL DEFAULT 'uploaded'",
					'matched_student_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'confidence decimal(5,4) NOT NULL DEFAULT 0.0000',
					'original_path varchar(500) NOT NULL',
					'normalized_name varchar(255) NOT NULL DEFAULT \'\'',
					'mapping_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY zip_item_path (zip_upload_id,original_path(191))',
				'KEY status (status)',
				'KEY matched_student_user_id (matched_student_user_id)',
			),
		);
	}

	/**
	 * Curriculums.
	 *
	 * @return mixed
	 */
	private function curriculums() {
		return array(
			'columns' => $this->common_columns(
				array(
					'curriculum_key varchar(191) NOT NULL',
					'title varchar(191) NOT NULL',
					'description longtext NULL',
					'version varchar(64) NOT NULL DEFAULT \'\'',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY curriculum_key (tenant_context,curriculum_key)',
				'KEY active (active)',
			),
		);
	}

	/**
	 * Achievement standards.
	 *
	 * @return mixed
	 */
	private function achievement_standards() {
		return array(
			'columns' => $this->common_columns(
				array(
					'curriculum_id bigint(20) unsigned NOT NULL',
					'standard_key varchar(191) NOT NULL',
					'title varchar(191) NOT NULL',
					'description longtext NULL',
					'metadata_json longtext NULL',
					'active tinyint(1) NOT NULL DEFAULT 1',
				)
			),
			'keys'    => array(
				'UNIQUE KEY curriculum_standard (curriculum_id,standard_key)',
				'KEY active (active)',
			),
		);
	}

	/**
	 * Worksheet achievement links.
	 *
	 * @return mixed
	 */
	private function worksheet_achievement_links() {
		return array(
			'columns' => $this->common_columns(
				array(
					'template_id bigint(20) unsigned NOT NULL',
					'standard_id bigint(20) unsigned NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY template_standard (template_id,standard_id)',
			),
		);
	}

	/**
	 * Question achievement links.
	 *
	 * @return mixed
	 */
	private function question_achievement_links() {
		return array(
			'columns' => $this->common_columns(
				array(
					'template_id bigint(20) unsigned NOT NULL',
					'question_id varchar(191) NOT NULL',
					'standard_id bigint(20) unsigned NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY question_standard (template_id,question_id,standard_id)',
			),
		);
	}

	/**
	 * Assessment plans.
	 *
	 * @return mixed
	 */
	private function assessment_plans() {
		return array(
			'columns' => $this->common_columns(
				array(
					'assignment_id bigint(20) unsigned NOT NULL',
					'template_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'title varchar(191) NOT NULL DEFAULT \'\'',
					'academic_year varchar(20) NOT NULL DEFAULT \'\'',
					'grade varchar(20) NOT NULL DEFAULT \'\'',
					'class_name varchar(64) NOT NULL DEFAULT \'\'',
					"rubric_mode enum('worksheet','question') NOT NULL DEFAULT 'worksheet'",
					'rubric_json longtext NULL',
					'standard_links_json longtext NULL',
					'target_json longtext NULL',
					'model_key varchar(191) NOT NULL DEFAULT \'\'',
					'policy_version varchar(64) NOT NULL DEFAULT \'\'',
					'status ' . Status_Values::enum_sql( Status_Values::ASSESSMENT_PLAN ) . " NOT NULL DEFAULT 'draft'",
				)
			),
			'keys'    => array(
				'KEY assignment_id (assignment_id)',
				'KEY template_id (template_id)',
				'KEY academic_context (academic_year,grade,class_name)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Assessment runs.
	 *
	 * @return mixed
	 */
	private function assessment_runs() {
		return array(
			'columns' => $this->common_columns(
				array(
					'submission_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'triggered_by bigint(20) unsigned NOT NULL DEFAULT 0',
					"run_type enum('score','feedback','special_note') NOT NULL DEFAULT 'score'",
					'status ' . Status_Values::enum_sql( Status_Values::JOB ) . " NOT NULL DEFAULT 'queued'",
					'model_key varchar(191) NOT NULL DEFAULT \'\'',
					'policy_version varchar(64) NOT NULL DEFAULT \'\'',
					'started_at datetime NULL DEFAULT NULL',
					'finished_at datetime NULL DEFAULT NULL',
					'error_message text NULL',
				)
			),
			'keys'    => array(
				'KEY submission_id (submission_id)',
				'KEY status (status)',
				'KEY run_type (run_type)',
			),
		);
	}

	/**
	 * Grading inputs.
	 *
	 * @return mixed
	 */
	private function grading_inputs() {
		return array(
			'columns' => $this->common_columns(
				array(
					'run_id bigint(20) unsigned NOT NULL',
					'submission_id bigint(20) unsigned NOT NULL',
					'grading_fingerprint varchar(191) NOT NULL',
					'input_json longtext NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY grading_fingerprint (grading_fingerprint)',
				'KEY run_id (run_id)',
				'KEY submission_id (submission_id)',
			),
		);
	}

	/**
	 * Rubric snapshots.
	 *
	 * @return mixed
	 */
	private function rubric_snapshots() {
		return array(
			'columns' => $this->common_columns(
				array(
					'template_id bigint(20) unsigned NOT NULL',
					"rubric_mode enum('worksheet','question') NOT NULL DEFAULT 'worksheet'",
					'rubric_hash varchar(191) NOT NULL',
					'rubric_json longtext NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY template_rubric_hash (template_id,rubric_hash)',
				'KEY rubric_mode (rubric_mode)',
			),
		);
	}

	/**
	 * Grading items.
	 *
	 * @return mixed
	 */
	private function grading_items() {
		return array(
			'columns' => $this->common_columns(
				array(
					'result_id bigint(20) unsigned NOT NULL',
					'question_id varchar(191) NOT NULL',
					'score decimal(10,2) NOT NULL DEFAULT 0.00',
					'max_score decimal(10,2) NOT NULL DEFAULT 0.00',
					'reason_text longtext NULL',
					'normalized_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY result_question (result_id,question_id)',
			),
		);
	}

	/**
	 * Assessment results.
	 *
	 * @return mixed
	 */
	private function assessment_results() {
		return array(
			'columns' => $this->common_columns(
				array(
					'submission_id bigint(20) unsigned NOT NULL',
					'run_id bigint(20) unsigned NOT NULL',
					'rubric_snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'grading_fingerprint varchar(191) NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::ASSESSMENT ) . " NOT NULL DEFAULT 'draft'",
					'total_score decimal(10,2) NOT NULL DEFAULT 0.00',
					'max_score decimal(10,2) NOT NULL DEFAULT 0.00',
					'review_comment longtext NULL',
					'raw_ai_response longtext NULL',
					'normalized_result longtext NULL',
					'accepted_at datetime NULL DEFAULT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY grading_fingerprint (grading_fingerprint)',
				'KEY submission_id (submission_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Regrade reviews.
	 *
	 * @return mixed
	 */
	private function regrade_reviews() {
		return array(
			'columns' => $this->common_columns(
				array(
					'current_result_id bigint(20) unsigned NOT NULL',
					'proposed_result_id bigint(20) unsigned NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::REGRADE_REVIEW ) . " NOT NULL DEFAULT 'pending'",
					'score_delta decimal(10,2) NOT NULL DEFAULT 0.00',
					'reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'reviewed_at datetime NULL DEFAULT NULL',
					'decision_note longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY current_proposed (current_result_id,proposed_result_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Feedback.
	 *
	 * @return mixed
	 */
	private function feedback() {
		return array(
			'columns' => $this->common_columns(
				array(
					'assessment_result_id bigint(20) unsigned NOT NULL',
					'student_user_id bigint(20) unsigned NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::FEEDBACK ) . " NOT NULL DEFAULT 'teacher_draft'",
					'feedback_text longtext NULL',
					'approved_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'approved_at datetime NULL DEFAULT NULL',
					'published_at datetime NULL DEFAULT NULL',
				)
			),
			'keys'    => array(
				'KEY assessment_result_id (assessment_result_id)',
				'KEY student_user_id (student_user_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Feedback approvals.
	 *
	 * @return mixed
	 */
	private function feedback_approvals() {
		return array(
			'columns' => $this->common_columns(
				array(
					'feedback_id bigint(20) unsigned NOT NULL',
					'action ' . Status_Values::enum_sql( Status_Values::FEEDBACK_ACTION ) . " NOT NULL DEFAULT 'approve'",
					'actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'note longtext NULL',
				)
			),
			'keys'    => array(
				'KEY feedback_id (feedback_id)',
				'KEY action (action)',
			),
		);
	}

	/**
	 * Special notes.
	 *
	 * @return mixed
	 */
	private function special_notes() {
		return array(
			'columns' => $this->common_columns(
				array(
					'assessment_result_id bigint(20) unsigned NOT NULL',
					'feedback_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'student_user_id bigint(20) unsigned NOT NULL',
					'status ' . Status_Values::enum_sql( Status_Values::SPECIAL_NOTE ) . " NOT NULL DEFAULT 'draft'",
					'note_text longtext NULL',
					'context_hash varchar(191) NOT NULL',
					'approved_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'approved_at datetime NULL DEFAULT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY student_context_hash (student_user_id,context_hash)',
				'KEY assessment_result_id (assessment_result_id)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Reports.
	 *
	 * @return mixed
	 */
	private function reports() {
		return array(
			'columns' => $this->common_columns(
				array(
					"report_type enum('student','class','submission_bulk') NOT NULL DEFAULT 'student'",
					'academic_year varchar(20) NOT NULL DEFAULT \'\'',
					'grade varchar(20) NOT NULL DEFAULT \'\'',
					'class_name varchar(64) NOT NULL DEFAULT \'\'',
					'student_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'status ' . Status_Values::enum_sql( Status_Values::REPORT ) . " NOT NULL DEFAULT 'draft'",
					'options_json longtext NULL',
				)
			),
			'keys'    => array(
				'KEY report_type (report_type)',
				'KEY academic_context (academic_year,grade,class_name)',
				'KEY student_user_id (student_user_id)',
			),
		);
	}

	/**
	 * Export jobs.
	 *
	 * @return mixed
	 */
	private function export_jobs() {
		return array(
			'columns' => $this->common_columns(
				array(
					"job_type enum('pdf','csv','zip') NOT NULL DEFAULT 'pdf'",
					'status ' . Status_Values::enum_sql( Status_Values::JOB ) . " NOT NULL DEFAULT 'queued'",
					'requested_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'payload_json longtext NULL',
					'result_file_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'idempotency_key varchar(191) NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY idempotency_key (idempotency_key)',
				'KEY job_type (job_type)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Export files.
	 *
	 * @return mixed
	 */
	private function export_files() {
		return array(
			'columns' => $this->common_columns(
				array(
					'export_job_id bigint(20) unsigned NOT NULL',
					'file_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'file_url text NULL',
					'mime_type varchar(127) NOT NULL DEFAULT \'\'',
					'size_bytes bigint(20) unsigned NOT NULL DEFAULT 0',
					'expires_at datetime NULL DEFAULT NULL',
				)
			),
			'keys'    => array(
				'KEY export_job_id (export_job_id)',
				'KEY file_id (file_id)',
			),
		);
	}

	/**
	 * Import runs.
	 *
	 * @return mixed
	 */
	private function import_runs() {
		return array(
			'columns' => $this->common_columns(
				array(
					"import_type enum('legacy','roster','template') NOT NULL DEFAULT 'legacy'",
					'status ' . Status_Values::enum_sql( Status_Values::JOB ) . " NOT NULL DEFAULT 'queued'",
					'requested_by bigint(20) unsigned NOT NULL DEFAULT 0',
					'import_source_key varchar(191) NOT NULL DEFAULT \'\'',
					'preview_json longtext NULL',
					'commit_summary_json longtext NULL',
					'overwrite_existing tinyint(1) NOT NULL DEFAULT 0',
					'idempotency_key varchar(191) NOT NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY idempotency_key (idempotency_key)',
				'KEY import_type (import_type)',
				'KEY status (status)',
			),
		);
	}

	/**
	 * Import items.
	 *
	 * @return mixed
	 */
	private function import_items() {
		return array(
			'columns' => $this->common_columns(
				array(
					'import_run_id bigint(20) unsigned NOT NULL',
					'import_source_type varchar(64) NOT NULL DEFAULT \'\'',
					'import_source_key varchar(191) NOT NULL DEFAULT \'\'',
					'import_source_id varchar(191) NOT NULL DEFAULT \'\'',
					'status ' . Status_Values::enum_sql( Status_Values::IMPORT_ITEM ) . " NOT NULL DEFAULT 'pending'",
					'target_type varchar(64) NOT NULL DEFAULT \'\'',
					'target_id bigint(20) unsigned NOT NULL DEFAULT 0',
					'error_message text NULL',
					'payload_json longtext NULL',
				)
			),
			'keys'    => array(
				'UNIQUE KEY run_source (import_run_id,import_source_type,import_source_key,import_source_id)',
				'KEY status (status)',
				'KEY target_lookup (target_type,target_id)',
			),
		);
	}
}

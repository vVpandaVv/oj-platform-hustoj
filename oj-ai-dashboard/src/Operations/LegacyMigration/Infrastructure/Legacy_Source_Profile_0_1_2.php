<?php
/**
 * Legacy source profile 0 1 2.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use LogicException;
use OJAI\Dashboard\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact table inventory for the last pre-add-on 0.1.2 release.
 */
final class Legacy_Source_Profile_0_1_2 {
	public const PROFILE = 'oj_ai_dashboard_wpdb_prefixed@0.1.2';
	public const VERSION = '0.1.2';

	/**
	 * Legacy source profile 0 1 2.
	 *
	 * @var array<string,array{suffix:string,disposition:string,mapping_kind:?string,exclusion_code:?string}>
	 */
	private const TABLES = array(
		'academic_years'              => array(
			'suffix'         => 'oj_ai_academic_years',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'context',
			'exclusion_code' => 'local_academic_master_not_imported',
		),
		'classes'                     => array(
			'suffix'         => 'oj_ai_classes',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'context',
			'exclusion_code' => 'local_academic_master_not_imported',
		),
		'groups'                      => array(
			'suffix'         => 'oj_ai_groups',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'context',
			'exclusion_code' => 'local_academic_master_not_imported',
		),
		'group_members'               => array(
			'suffix'         => 'oj_ai_group_members',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'user',
			'exclusion_code' => 'local_academic_master_not_imported',
		),
		'academic_context_mappings'   => array(
			'suffix'         => 'oj_ai_academic_context_mappings',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'context',
			'exclusion_code' => 'legacy_mapping_requires_confirmation',
		),
		'student_mappings'            => array(
			'suffix'         => 'oj_ai_student_mappings',
			'disposition'    => 'mapping',
			'mapping_kind'   => 'user',
			'exclusion_code' => 'legacy_mapping_requires_confirmation',
		),
		'activity_events'             => array(
			'suffix'         => 'oj_ai_activity_events',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_activity_not_replayed',
		),
		'audit_logs'                  => array(
			'suffix'         => 'oj_ai_audit_logs',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_audit_not_replayed',
		),
		'worksheet_templates'         => array(
			'suffix'         => 'oj_ai_worksheet_templates',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'worksheet_questions'         => array(
			'suffix'         => 'oj_ai_worksheet_questions',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'worksheet_assignments'       => array(
			'suffix'         => 'oj_ai_worksheet_assignments',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'worksheet_submissions'       => array(
			'suffix'         => 'oj_ai_worksheet_submissions',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'submission_revisions'        => array(
			'suffix'         => 'oj_ai_submission_revisions',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'submission_attachments'      => array(
			'suffix'         => 'oj_ai_submission_attachments',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'zip_uploads'                 => array(
			'suffix'         => 'oj_ai_zip_uploads',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'zip_upload_items'            => array(
			'suffix'         => 'oj_ai_zip_upload_items',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'curriculums'                 => array(
			'suffix'         => 'oj_ai_curriculums',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'achievement_standards'       => array(
			'suffix'         => 'oj_ai_achievement_standards',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'worksheet_achievement_links' => array(
			'suffix'         => 'oj_ai_worksheet_achievement_links',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'question_achievement_links'  => array(
			'suffix'         => 'oj_ai_question_achievement_links',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'assessment_plans'            => array(
			'suffix'         => 'oj_ai_assessment_plans',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'assessment_runs'             => array(
			'suffix'         => 'oj_ai_assessment_runs',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'grading_inputs'              => array(
			'suffix'         => 'oj_ai_grading_inputs',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'rubric_snapshots'            => array(
			'suffix'         => 'oj_ai_rubric_snapshots',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'grading_items'               => array(
			'suffix'         => 'oj_ai_grading_items',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'assessment_results'          => array(
			'suffix'         => 'oj_ai_assessment_results',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'regrade_reviews'             => array(
			'suffix'         => 'oj_ai_regrade_reviews',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'feedback'                    => array(
			'suffix'         => 'oj_ai_feedback',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'feedback_approvals'          => array(
			'suffix'         => 'oj_ai_feedback_approvals',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'special_notes'               => array(
			'suffix'         => 'oj_ai_special_notes',
			'disposition'    => 'migrate',
			'mapping_kind'   => null,
			'exclusion_code' => null,
		),
		'reports'                     => array(
			'suffix'         => 'oj_ai_reports',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_report_regenerated',
		),
		'export_jobs'                 => array(
			'suffix'         => 'oj_ai_export_jobs',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_export_not_imported',
		),
		'export_files'                => array(
			'suffix'         => 'oj_ai_export_files',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_export_not_imported',
		),
		'import_runs'                 => array(
			'suffix'         => 'oj_ai_import_runs',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_import_ledger_not_replayed',
		),
		'import_items'                => array(
			'suffix'         => 'oj_ai_import_items',
			'disposition'    => 'exclude',
			'mapping_kind'   => null,
			'exclusion_code' => 'legacy_import_ledger_not_replayed',
		),
	);

	/** Physical inventory order is preserved above; execution follows owner dependencies below. */
	private const EXECUTION_ORDER = array(
		'academic_years',
		'classes',
		'groups',
		'group_members',
		'academic_context_mappings',
		'student_mappings',
		'activity_events',
		'audit_logs',
		'curriculums',
		'achievement_standards',
		'worksheet_templates',
		'worksheet_questions',
		'worksheet_achievement_links',
		'question_achievement_links',
		'worksheet_assignments',
		'worksheet_submissions',
		'submission_revisions',
		'submission_attachments',
		'zip_uploads',
		'zip_upload_items',
		'assessment_plans',
		'assessment_runs',
		'grading_inputs',
		'rubric_snapshots',
		'grading_items',
		'assessment_results',
		'regrade_reviews',
		'feedback',
		'feedback_approvals',
		'special_notes',
		'reports',
		'export_jobs',
		'export_files',
		'import_runs',
		'import_items',
	);

	/**
	 * Columns.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $columns;

	/**
	 * Initialize legacy source profile 0 1 2 dependencies and state.
	 *
	 * @param Schema|null $schema Schema.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( ?Schema $schema = null ) {
		$definitions = ( $schema ?? new Schema() )->definitions();
		if ( array_keys( self::TABLES ) !== array_keys( $definitions ) ) {
			throw new LogicException( 'Legacy 0.1.2 source profile table order drifted.' );
		}

		$execution_order = self::EXECUTION_ORDER;
		sort( $execution_order, SORT_STRING );
		$inventory = array_keys( self::TABLES );
		sort( $inventory, SORT_STRING );
		if ( $execution_order !== $inventory ) {
			throw new LogicException( 'Legacy 0.1.2 execution order inventory drifted.' );
		}

		$this->columns = array();
		foreach ( $definitions as $logical_table => $definition ) {
			$columns = array();
			foreach ( $definition['columns'] ?? array() as $column_sql ) {
				if ( ! is_string( $column_sql ) || 1 !== preg_match( '/^([a-z][a-z0-9_]*)\s/i', $column_sql, $matches ) ) {
					throw new LogicException( 'Legacy 0.1.2 source profile column drifted.' );
				}
				$columns[] = $matches[1];
			}
			if ( array() === $columns || 'id' !== $columns[0] || count( $columns ) !== count( array_unique( $columns ) ) ) {
				throw new LogicException( 'Legacy 0.1.2 source profile columns are invalid.' );
			}
			$this->columns[ $logical_table ] = $columns;
		}
	}

	/**
	 * Tables.
	 *
	 * @return array<string,array{suffix:string,disposition:string,mapping_kind:?string,exclusion_code:?string}>
	 */
	public function tables(): array {
		return self::TABLES;
	}

	/**
	 * Execution tables.
	 *
	 * @return array<string,array{suffix:string,disposition:string,mapping_kind:?string,exclusion_code:?string}>
	 */
	public function execution_tables(): array {
		$result = array();
		foreach ( self::EXECUTION_ORDER as $logical_table ) {
			$result[ $logical_table ] = self::TABLES[ $logical_table ];
		}
		return $result;
	}

	/**
	 * Columns.
	 *
	 * @param string $logical_table Logical table.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function columns( string $logical_table ): array {
		if ( ! isset( $this->columns[ $logical_table ] ) ) {
			throw new LogicException( 'Legacy source table is outside the selected profile.' );
		}
		return $this->columns[ $logical_table ];
	}

	/**
	 * Carries file id.
	 *
	 * @param string $logical_table Logical table.
	 * @return bool
	 */
	public function carries_file_id( string $logical_table ): bool {
		return in_array( $logical_table, array( 'submission_attachments', 'zip_uploads', 'export_files' ), true );
	}
}

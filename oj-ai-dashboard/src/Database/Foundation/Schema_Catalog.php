<?php
/**
 * Frozen 45-table database catalog.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the A-DS-05 physical schema contract.
 */
final class Schema_Catalog {
	public const ADDON_ID       = 'oj-ai-dashboard';
	public const SCHEMA_VERSION = '1.0.4';
	public const TABLE_COUNT    = 48;

	public const OWNER_DATABASE                 = 'Database';
	public const OWNER_OPERATIONS               = 'Operations';
	public const OWNER_CURRICULUM               = 'Curriculum';
	public const OWNER_WORKSHEET                = 'Worksheet';
	public const OWNER_ASSESSMENT_CONFIGURATION = 'AssessmentConfiguration';
	public const OWNER_SUBMISSION               = 'Submission';
	public const OWNER_ASSESSMENT               = 'Assessment';
	public const OWNER_STORAGE                  = 'Storage';
	public const OWNER_REPORTING                = 'Reporting';
	public const OWNER_IMPORT                   = 'Import';

	private const EXPECTED_TABLE_NAMES = array(
		'oj_ai_installations',
		'oj_ai_schema_migrations',
		'oj_ai_audit_events',
		'oj_ai_outbox_events',
		'oj_ai_purge_jobs',
		'oj_ai_curriculums',
		'oj_ai_achievement_standards',
		'oj_ai_worksheets',
		'oj_ai_worksheet_revisions',
		'oj_ai_worksheet_revision_questions',
		'oj_ai_worksheet_achievement_links',
		'oj_ai_question_achievement_links',
		'oj_ai_worksheet_publications',
		'oj_ai_evaluation_policy_revisions',
		'oj_ai_rubric_revisions',
		'oj_ai_review_rule_revisions',
		'oj_ai_assignments',
		'oj_ai_assignment_targets',
		'oj_ai_submissions',
		'oj_ai_submission_revisions',
		'oj_ai_submission_receipts',
		'oj_ai_submission_attachments',
		'oj_ai_zip_uploads',
		'oj_ai_zip_upload_items',
		'oj_ai_worksheet_evaluations',
		'oj_ai_worksheet_evaluation_inputs',
		'oj_ai_worksheet_evaluation_results',
		'oj_ai_worksheet_evaluation_items',
		'oj_ai_worksheet_evaluation_reviews',
		'oj_ai_submission_evaluations',
		'oj_ai_submission_evaluation_inputs',
		'oj_ai_submission_evaluation_results',
		'oj_ai_submission_evaluation_items',
		'oj_ai_submission_evaluation_reviews',
		'oj_ai_submission_feedback_revisions',
		'oj_ai_submission_release_revisions',
		'oj_ai_student_note_revisions',
		'oj_ai_evaluation_runs',
		'oj_ai_evaluation_attempts',
		'oj_ai_private_files',
		'oj_ai_report_snapshots',
		'oj_ai_export_jobs',
		'oj_ai_pdf_artifacts',
		'oj_ai_import_runs',
		'oj_ai_import_items',
		'oj_ai_assessment_projects',
		'oj_ai_assessment_project_revisions',
		'oj_ai_assessment_project_worksheets',
	);

	/**
	 * Tables.
	 *
	 * @var array<string,Table_Definition>|null
	 */
	private ?array $tables = null;

	/**
	 * Return all definitions in the frozen documentation order.
	 *
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function tables(): array {
		if ( null !== $this->tables ) {
			return $this->tables;
		}

		$definitions = array_merge(
			$this->system_tables(),
			$this->content_tables(),
			$this->submission_tables(),
			$this->assessment_tables(),
			$this->artifact_tables(),
			$this->project_tables()
		);
		$tables      = array();
		foreach ( $definitions as $definition ) {
			if ( isset( $tables[ $definition->name() ] ) ) {
				throw new LogicException( 'Duplicate physical table: ' . $definition->name() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
			$tables[ $definition->name() ] = $definition;
		}

		if ( self::EXPECTED_TABLE_NAMES !== array_keys( $tables ) ) {
			throw new LogicException( 'The physical schema differs from the versioned table manifest.' );
		}
		$this->assert_owner_counts( $tables );
		$this->assert_foreign_key_ownership( $tables );
		$this->tables = $tables;

		return $this->tables;
	}

	/**
	 * Table names.
	 *
	 * @return array<int,string>
	 */
	public function table_names(): array {
		return array_keys( $this->tables() );
	}

	/**
	 * Table.
	 *
	 * @param string $name Name.
	 * @return Table_Definition
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function table( string $name ): Table_Definition {
		$tables = $this->tables();
		if ( ! isset( $tables[ $name ] ) ) {
			throw new LogicException( 'Unknown frozen table: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}

		return $tables[ $name ];
	}

	/**
	 * Owner counts.
	 *
	 * @return array<string,int>
	 */
	public function owner_counts(): array {
		$counts = array_fill_keys( array_keys( self::expected_owner_counts() ), 0 );
		foreach ( $this->tables() as $table ) {
			++$counts[ $table->owner() ];
		}

		return $counts;
	}

	/**
	 * Return a dependency-safe table creation order without changing ownership.
	 *
	 * @return array<int,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function creation_order(): array {
		$pending  = $this->tables();
		$resolved = array();
		$order    = array();

		while ( array() !== $pending ) {
			$progress = false;
			foreach ( $pending as $name => $table ) {
				if ( array_diff( $table->dependencies(), array_keys( $resolved ) ) ) {
					continue;
				}
				$order[]           = $table;
				$resolved[ $name ] = true;
				unset( $pending[ $name ] );
				$progress = true;
			}

			if ( ! $progress ) {
				throw new LogicException( 'The frozen schema contains a foreign-key dependency cycle.' );
			}
		}

		return $order;
	}

	/**
	 * Create statements.
	 *
	 * @return array<int,string>
	 */
	public function create_statements(): array {
		return array_map(
			static fn ( Table_Definition $table ): string => $table->create_sql(),
			$this->creation_order()
		);
	}

	/**
	 * Fingerprint.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function fingerprint(): string {
		$payload = array_map(
			static fn ( Table_Definition $table ): array => $table->normalized_definition(),
			array_values( $this->tables() )
		);
		$json    = json_encode( $payload, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json ) {
			throw new LogicException( 'Unable to encode the frozen schema catalog.' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Owner marker.
	 *
	 * @return string
	 */
	public function owner_marker(): string {
		return hash( 'sha256', self::ADDON_ID . '|literal-oj-ai-schema-owner|v1' );
	}

	/**
	 * Project tables.
	 *
	 * @return array<int,Table_Definition>
	 */
	private function project_tables(): array {
		return array(
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_assessment_projects',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'title'                      => 'VARCHAR(255) NOT NULL',
					'owner_user_id'              => 'BIGINT UNSIGNED NOT NULL',
					'state'                      => 'VARCHAR(32) NOT NULL',
					'current_revision_public_id' => 'CHAR(26) NOT NULL',
					'creation_key'               => 'CHAR(64) NOT NULL',
					'creation_digest'            => 'CHAR(64) NOT NULL',
				),
				array( self::uq( 'uq_project_public', 'public_id' ), self::uq( 'uq_project_creation', 'creation_key' ), self::ix( 'ix_project_owner', 'owner_user_id', 'state', 'id' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_assessment_project_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'project_id'     => 'BIGINT UNSIGNED NOT NULL',
						'revision_no'    => 'INT UNSIGNED NOT NULL',
						'state'          => 'VARCHAR(32) NOT NULL',
						'command_key'    => 'CHAR(64) NOT NULL',
						'request_digest' => 'CHAR(64) NOT NULL',
					),
					Schema_Profile::json_columns( 'configuration' )
				),
				array( self::uq( 'uq_project_revision_public', 'public_id' ), self::uq( 'uq_project_revision_no', 'project_id', 'revision_no' ), self::uq( 'uq_project_revision_command', 'command_key' ) ),
				array( self::fk( 'fk_project_revision_root', 'project_id', 'oj_ai_assessment_projects' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_assessment_project_worksheets',
				array( Schema_Profile::PK, Schema_Profile::MUT ),
				array(
					'project_id'          => 'BIGINT UNSIGNED NOT NULL',
					'project_revision_id' => 'BIGINT UNSIGNED NOT NULL',
					'worksheet_id'        => 'BIGINT UNSIGNED NOT NULL',
				),
				array( self::uq( 'uq_project_worksheet', 'worksheet_id' ), self::ix( 'ix_project_worksheets', 'project_id', 'id' ) ),
				array( self::fk( 'fk_project_worksheet_root', 'project_id', 'oj_ai_assessment_projects' ), self::fk( 'fk_project_worksheet_revision', 'project_revision_id', 'oj_ai_assessment_project_revisions' ) )
			),
		);
	}

	/**
	 * System tables.
	 *
	 * @return array
	 */
	private function system_tables(): array {
		return array(
			self::define(
				self::OWNER_DATABASE,
				'oj_ai_installations',
				array( Schema_Profile::PK, Schema_Profile::MUT ),
				array(
					'addon_id'             => 'VARCHAR(100) NOT NULL',
					'owner_marker'         => 'CHAR(64) NOT NULL',
					'schema_version'       => 'VARCHAR(32) NOT NULL',
					'schema_fingerprint'   => 'CHAR(64) NOT NULL',
					'state'                => 'VARCHAR(32) NOT NULL',
					'last_verified_at_utc' => 'DATETIME NULL',
				),
				array( self::uq( 'uq_installation_addon', 'addon_id' ) )
			),
			self::define(
				self::OWNER_DATABASE,
				'oj_ai_schema_migrations',
				array( Schema_Profile::PK, Schema_Profile::SYS ),
				self::columns(
					array(
						'migration_id'     => 'VARCHAR(100) NOT NULL',
						'checksum'         => 'CHAR(64) NOT NULL',
						'state'            => 'VARCHAR(32) NOT NULL',
						'attempt_count'    => 'INT UNSIGNED NOT NULL',
						'started_at_utc'   => 'DATETIME NOT NULL',
						'completed_at_utc' => 'DATETIME NULL',
						'error_code'       => 'VARCHAR(100) NULL',
					),
					Schema_Profile::json_columns( 'checkpoint', true )
				),
				array(
					self::uq( 'uq_migration_id', 'migration_id' ),
					self::ix( 'ix_migration_state', 'state', 'id' ),
				)
			),
			self::define(
				self::OWNER_OPERATIONS,
				'oj_ai_audit_events',
				array( Schema_Profile::PK, Schema_Profile::PID ),
				self::columns(
					array(
						'occurred_at_utc'  => 'DATETIME NOT NULL',
						'actor_user_id'    => 'BIGINT UNSIGNED NULL',
						'action_key'       => 'VARCHAR(100) NOT NULL',
						'object_type'      => 'VARCHAR(64) NOT NULL',
						'object_public_id' => 'CHAR(26) NULL',
					),
					Schema_Profile::context_columns( true ),
					array(
						'correlation_id' => 'VARCHAR(100) NOT NULL',
						'before_digest'  => 'CHAR(64) NULL',
						'after_digest'   => 'CHAR(64) NULL',
					),
					Schema_Profile::json_columns( 'metadata' ),
					array(
						'previous_event_hash' => 'CHAR(64) NULL',
						'event_hash'          => 'CHAR(64) NOT NULL',
					)
				),
				array(
					self::uq( 'uq_audit_public', 'public_id' ),
					self::uq( 'uq_audit_event_hash', 'event_hash' ),
					self::ix( 'ix_audit_object', 'object_type', 'object_public_id', 'occurred_at_utc' ),
					self::ix( 'ix_audit_actor', 'actor_user_id', 'occurred_at_utc' ),
					self::ix( 'ix_audit_correlation', 'correlation_id' ),
				)
			),
			self::define(
				self::OWNER_OPERATIONS,
				'oj_ai_outbox_events',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'aggregate_type'      => 'VARCHAR(64) NOT NULL',
						'aggregate_public_id' => 'CHAR(26) NOT NULL',
						'event_type'          => 'VARCHAR(100) NOT NULL',
						'idempotency_key'     => 'VARCHAR(191) NOT NULL',
					),
					Schema_Profile::json_columns( 'payload' ),
					array(
						'available_at_utc'     => 'DATETIME NOT NULL',
						'published_at_utc'     => 'DATETIME NULL',
						'lease_token'          => 'CHAR(36) NULL',
						'lease_expires_at_utc' => 'DATETIME NULL',
						'attempt_count'        => 'INT UNSIGNED NOT NULL',
						'last_error_code'      => 'VARCHAR(100) NULL',
					)
				),
				array(
					self::uq( 'uq_outbox_public', 'public_id' ),
					self::uq( 'uq_outbox_idempotency', 'idempotency_key' ),
					self::ix( 'ix_outbox_ready', 'published_at_utc', 'available_at_utc', 'id' ),
				)
			),
			self::define(
				self::OWNER_OPERATIONS,
				'oj_ai_purge_jobs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'scope_type'      => 'VARCHAR(64) NOT NULL',
						'scope_public_id' => 'CHAR(26) NULL',
						'state'           => 'VARCHAR(32) NOT NULL',
						'idempotency_key' => 'VARCHAR(191) NOT NULL',
					),
					Schema_Profile::json_columns( 'preview' ),
					array(
						'approved_by_user_id' => 'BIGINT UNSIGNED NULL',
						'approved_at_utc'     => 'DATETIME NULL',
						'approval_token_hash' => 'CHAR(64) NULL',
					),
					Schema_Profile::json_columns( 'checkpoint', true ),
					array(
						'completed_at_utc' => 'DATETIME NULL',
						'error_code'       => 'VARCHAR(100) NULL',
					)
				),
				array(
					self::uq( 'uq_purge_public', 'public_id' ),
					self::uq( 'uq_purge_idempotency', 'idempotency_key' ),
					self::uq( 'uq_purge_approval_token', 'approval_token_hash' ),
					self::ix( 'ix_purge_state', 'state', 'updated_at_utc' ),
					self::ix( 'ix_purge_scope', 'scope_type', 'scope_public_id' ),
				)
			),
		);
	}

	/**
	 * Content tables.
	 *
	 * @return array<int,Table_Definition>
	 */
	private function content_tables(): array {
		return array(
			self::define(
				self::OWNER_CURRICULUM,
				'oj_ai_curriculums',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'source_kind'    => 'VARCHAR(32) NOT NULL',
						'source_key'     => 'VARCHAR(191) NOT NULL',
						'source_version' => 'VARCHAR(64) NOT NULL',
						'title'          => 'VARCHAR(255) NOT NULL',
						'locale'         => 'VARCHAR(16) NOT NULL',
						'state'          => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'metadata' ),
					array( 'content_hash' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_curriculum_public', 'public_id' ),
					self::uq( 'uq_curriculum_source', 'source_kind', 'source_key', 'source_version' ),
					self::ix( 'ix_curriculum_state', 'state', 'title' ),
				)
			),
			self::define(
				self::OWNER_CURRICULUM,
				'oj_ai_achievement_standards',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'curriculum_id' => 'BIGINT UNSIGNED NOT NULL',
						'stable_key'    => 'VARCHAR(191) NOT NULL',
						'code'          => 'VARCHAR(100) NOT NULL',
						'title'         => 'VARCHAR(255) NOT NULL',
						'description'   => 'TEXT NOT NULL',
						'grade_label'   => 'VARCHAR(100) NULL',
					),
					Schema_Profile::json_columns( 'metadata' ),
					array( 'content_hash' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_standard_public', 'public_id' ),
					self::uq( 'uq_standard_key', 'curriculum_id', 'stable_key' ),
					self::ix( 'ix_standard_code', 'curriculum_id', 'code' ),
				),
				array( self::fk( 'fk_standard_curriculum', 'curriculum_id', 'oj_ai_curriculums' ) )
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_worksheets',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'title'                        => 'VARCHAR(255) NOT NULL',
					'owner_user_id'                => 'BIGINT UNSIGNED NOT NULL',
					'state'                        => 'VARCHAR(32) NOT NULL',
					'current_revision_public_id'   => 'CHAR(26) NULL',
					'latest_publication_public_id' => 'CHAR(26) NULL',
					'archived_at_utc'              => 'DATETIME NULL',
				),
				array(
					self::uq( 'uq_worksheet_public', 'public_id' ),
					self::ix( 'ix_worksheet_owner', 'owner_user_id', 'state', 'updated_at_utc' ),
				)
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_worksheet_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_id'            => 'BIGINT UNSIGNED NOT NULL',
						'revision_no'             => 'INT UNSIGNED NOT NULL',
						'state'                   => 'VARCHAR(32) NOT NULL',
						'source_revision_id'      => 'BIGINT UNSIGNED NULL',
						'editor_profile_version'  => 'VARCHAR(32) NOT NULL',
						'document_schema_version' => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'teacher_document' ),
					array(
						'document_hash'             => 'CHAR(64) NOT NULL',
						'validation_schema_version' => 'VARCHAR(32) NOT NULL',
						'validation_hash'           => 'CHAR(64) NOT NULL',
						'approved_by_user_id'       => 'BIGINT UNSIGNED NULL',
						'approved_at_utc'           => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_worksheet_revision_public', 'public_id' ),
					self::uq( 'uq_worksheet_revision_no', 'worksheet_id', 'revision_no' ),
					self::ix( 'ix_worksheet_revision_state', 'worksheet_id', 'state', 'revision_no' ),
				),
				array(
					self::fk( 'fk_revision_worksheet', 'worksheet_id', 'oj_ai_worksheets' ),
					self::fk( 'fk_revision_source', 'source_revision_id', 'oj_ai_worksheet_revisions' ),
				)
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_worksheet_revision_questions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_revision_id' => 'BIGINT UNSIGNED NOT NULL',
						'question_key'          => 'VARCHAR(100) NOT NULL',
						'position'              => 'INT UNSIGNED NOT NULL',
						'response_type'         => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'question' ),
					Schema_Profile::json_columns( 'private_metadata', true )
				),
				array(
					self::uq( 'uq_question_public', 'public_id' ),
					self::uq( 'uq_question_key', 'worksheet_revision_id', 'question_key' ),
					self::uq( 'uq_question_position', 'worksheet_revision_id', 'position' ),
				),
				array( self::fk( 'fk_question_revision', 'worksheet_revision_id', 'oj_ai_worksheet_revisions' ) )
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_worksheet_achievement_links',
				array( Schema_Profile::PK, Schema_Profile::IMM ),
				array(
					'worksheet_revision_id'   => 'BIGINT UNSIGNED NOT NULL',
					'standard_public_id'      => 'CHAR(26) NOT NULL',
					'standard_source_version' => 'VARCHAR(64) NOT NULL',
					'standard_code_snapshot'  => 'VARCHAR(100) NOT NULL',
					'standard_label_snapshot' => 'VARCHAR(255) NOT NULL',
					'alignment_type'          => 'VARCHAR(32) NOT NULL',
					'position'                => 'INT UNSIGNED NOT NULL',
				),
				array(
					self::uq( 'uq_worksheet_standard', 'worksheet_revision_id', 'standard_public_id', 'alignment_type' ),
					self::ix( 'ix_worksheet_standard_lookup', 'standard_public_id', 'worksheet_revision_id' ),
				),
				array( self::fk( 'fk_worksheet_link_revision', 'worksheet_revision_id', 'oj_ai_worksheet_revisions' ) )
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_question_achievement_links',
				array( Schema_Profile::PK, Schema_Profile::IMM ),
				array(
					'question_id'             => 'BIGINT UNSIGNED NOT NULL',
					'standard_public_id'      => 'CHAR(26) NOT NULL',
					'standard_source_version' => 'VARCHAR(64) NOT NULL',
					'standard_code_snapshot'  => 'VARCHAR(100) NOT NULL',
					'standard_label_snapshot' => 'VARCHAR(255) NOT NULL',
					'alignment_type'          => 'VARCHAR(32) NOT NULL',
					'position'                => 'INT UNSIGNED NOT NULL',
				),
				array(
					self::uq( 'uq_question_standard', 'question_id', 'standard_public_id', 'alignment_type' ),
					self::ix( 'ix_question_standard_lookup', 'standard_public_id', 'question_id' ),
				),
				array( self::fk( 'fk_question_link_question', 'question_id', 'oj_ai_worksheet_revision_questions' ) )
			),
			self::define(
				self::OWNER_WORKSHEET,
				'oj_ai_worksheet_publications',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_id'          => 'BIGINT UNSIGNED NOT NULL',
						'worksheet_revision_id' => 'BIGINT UNSIGNED NOT NULL',
						'publication_no'        => 'INT UNSIGNED NOT NULL',
						'state'                 => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'student_document' ),
					Schema_Profile::json_columns( 'response_schema' ),
					Schema_Profile::json_columns( 'grading_schema' ),
					Schema_Profile::json_columns( 'print_projection' ),
					array(
						'projection_manifest_version' => 'VARCHAR(32) NOT NULL',
						'publication_hash'            => 'CHAR(64) NOT NULL',
						'published_at_utc'            => 'DATETIME NOT NULL',
						'retired_by_user_id'          => 'BIGINT UNSIGNED NULL',
						'retired_at_utc'              => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_publication_public', 'public_id' ),
					self::uq( 'uq_publication_no', 'worksheet_id', 'publication_no' ),
					self::uq( 'uq_publication_revision', 'worksheet_revision_id' ),
					self::ix( 'ix_publication_state', 'worksheet_id', 'state', 'published_at_utc' ),
				),
				array(
					self::fk( 'fk_publication_worksheet', 'worksheet_id', 'oj_ai_worksheets' ),
					self::fk( 'fk_publication_revision', 'worksheet_revision_id', 'oj_ai_worksheet_revisions' ),
				)
			),
		);
	}

	/**
	 * Submission tables.
	 *
	 * @return array<int,Table_Definition>
	 */
	private function submission_tables(): array {
		$config_activation = array(
			'activated_by_user_id' => 'BIGINT UNSIGNED NULL',
			'activated_at_utc'     => 'DATETIME NULL',
			'retired_by_user_id'   => 'BIGINT UNSIGNED NULL',
			'retired_at_utc'       => 'DATETIME NULL',
		);

		return array(
			self::define(
				self::OWNER_ASSESSMENT_CONFIGURATION,
				'oj_ai_evaluation_policy_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'subject_type' => 'VARCHAR(32) NOT NULL',
						'revision_no'  => 'INT UNSIGNED NOT NULL',
						'state'        => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'policy' ),
					array( 'content_hash' => 'CHAR(64) NOT NULL' ),
					$config_activation
				),
				array(
					self::uq( 'uq_policy_public', 'public_id' ),
					self::uq( 'uq_policy_revision', 'subject_type', 'revision_no' ),
					self::ix( 'ix_policy_active', 'subject_type', 'state', 'revision_no' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT_CONFIGURATION,
				'oj_ai_rubric_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'subject_type' => 'VARCHAR(32) NOT NULL',
						'revision_no'  => 'INT UNSIGNED NOT NULL',
						'state'        => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'criteria' ),
					array(
						'total_points' => 'DECIMAL(12,4) NULL',
						'content_hash' => 'CHAR(64) NOT NULL',
					),
					$config_activation
				),
				array(
					self::uq( 'uq_rubric_public', 'public_id' ),
					self::uq( 'uq_rubric_revision', 'subject_type', 'revision_no' ),
					self::ix( 'ix_rubric_active', 'subject_type', 'state', 'revision_no' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT_CONFIGURATION,
				'oj_ai_review_rule_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'subject_type'              => 'VARCHAR(32) NOT NULL',
						'revision_no'               => 'INT UNSIGNED NOT NULL',
						'state'                     => 'VARCHAR(32) NOT NULL',
						'review_required'           => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
						'explicit_release_required' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
						'confidence_threshold'      => 'DECIMAL(5,4) NOT NULL',
					),
					Schema_Profile::json_columns( 'rules' ),
					array( 'content_hash' => 'CHAR(64) NOT NULL' ),
					$config_activation
				),
				array(
					self::uq( 'uq_review_rule_public', 'public_id' ),
					self::uq( 'uq_review_rule_revision', 'subject_type', 'revision_no' ),
					self::ix( 'ix_review_rule_active', 'subject_type', 'state', 'revision_no' ),
				)
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_assignments',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT, Schema_Profile::CTX ),
				self::columns(
					array(
						'worksheet_publication_public_id' => 'CHAR(26) NOT NULL',
						'publication_hash'                => 'CHAR(64) NOT NULL',
						'title_snapshot'                  => 'VARCHAR(255) NOT NULL',
						'state'                           => 'VARCHAR(32) NOT NULL',
						'opens_at_utc'                    => 'DATETIME NULL',
						'due_at_utc'                      => 'DATETIME NULL',
						'closes_at_utc'                   => 'DATETIME NULL',
						'target_roster_revision'          => 'VARCHAR(191) NOT NULL',
						'schedule_version'                => 'INT UNSIGNED NOT NULL',
						'schedule_hash'                   => 'CHAR(64) NOT NULL',
						'auto_submit_enabled'             => 'TINYINT UNSIGNED NOT NULL',
						'auto_submit_completed_at_utc'    => 'DATETIME NULL',
					),
					Schema_Profile::json_columns( 'resubmission_policy' ),
					array( 'target_snapshot_hash' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_assignment_public', 'public_id' ),
					self::ix( 'ix_assignment_context', 'context_type', 'context_id', 'state', 'due_at_utc' ),
					self::ix( 'ix_assignment_publication', 'worksheet_publication_public_id', 'state' ),
				)
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_assignment_targets',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'assignment_id'           => 'BIGINT UNSIGNED NOT NULL',
						'target_type'             => 'VARCHAR(16) NOT NULL',
						'target_context_id'       => 'VARCHAR(191) NOT NULL',
						'target_context_revision' => 'VARCHAR(191) NOT NULL',
						'membership_revision'     => 'VARCHAR(191) NOT NULL',
						'learner_user_id'         => 'BIGINT UNSIGNED NULL',
					),
					Schema_Profile::json_columns( 'display_snapshot' ),
					array(
						'snapshot_hash' => 'CHAR(64) NOT NULL',
						'state'         => 'VARCHAR(32) NOT NULL',
					)
				),
				array(
					self::uq( 'uq_assignment_target_public', 'public_id' ),
					self::uq( 'uq_assignment_target', 'assignment_id', 'target_type', 'target_context_id', 'learner_user_id' ),
					self::ix( 'ix_target_learner', 'learner_user_id', 'assignment_id' ),
				),
				array( self::fk( 'fk_target_assignment', 'assignment_id', 'oj_ai_assignments' ) )
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_submissions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'assignment_id'               => 'BIGINT UNSIGNED NOT NULL',
					'learner_user_id'             => 'BIGINT UNSIGNED NOT NULL',
					'state'                       => 'VARCHAR(32) NOT NULL',
					'current_revision_public_id'  => 'CHAR(26) NULL',
					'official_revision_public_id' => 'CHAR(26) NULL',
					'official_attempt_no'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
					'official_review_public_id'   => 'CHAR(26) NULL',
					'official_review_hash'        => 'CHAR(64) NULL',
					'current_release_public_id'   => 'CHAR(26) NULL',
					'current_release_hash'        => 'CHAR(64) NULL',
					'started_at_utc'              => 'DATETIME NULL',
					'submitted_at_utc'            => 'DATETIME NULL',
					'closed_at_utc'               => 'DATETIME NULL',
				),
				array(
					self::uq( 'uq_submission_public', 'public_id' ),
					self::uq( 'uq_submission_learner', 'assignment_id', 'learner_user_id' ),
					self::ix( 'ix_submission_state', 'assignment_id', 'state', 'submitted_at_utc' ),
					self::ix( 'ix_submission_learner', 'learner_user_id', 'state' ),
				),
				array( self::fk( 'fk_submission_assignment', 'assignment_id', 'oj_ai_assignments' ) )
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_submission_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_id'           => 'BIGINT UNSIGNED NOT NULL',
						'revision_no'             => 'INT UNSIGNED NOT NULL',
						'base_revision_no'        => 'INT UNSIGNED NULL',
						'state'                   => 'VARCHAR(32) NOT NULL',
						'response_schema_version' => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'response' ),
					array( 'attachment_set_hash' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_submission_revision_public', 'public_id' ),
					self::uq( 'uq_submission_revision_no', 'submission_id', 'revision_no' ),
					self::ix( 'ix_submission_revision_state', 'submission_id', 'state', 'revision_no' ),
				),
				array( self::fk( 'fk_submission_revision_root', 'submission_id', 'oj_ai_submissions' ) )
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_submission_receipts',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::SYS ),
				array(
					'content_hash'                => 'CHAR(64) NOT NULL',
					'submission_id'               => 'BIGINT UNSIGNED NOT NULL',
					'submission_revision_id'      => 'BIGINT UNSIGNED NOT NULL',
					'receipt_no'                  => 'INT UNSIGNED NOT NULL',
					'action'                      => 'VARCHAR(32) NOT NULL',
					'idempotency_key'             => 'VARCHAR(191) NOT NULL',
					'context_revision'            => 'VARCHAR(191) NOT NULL',
					'actor_type'                  => 'VARCHAR(16) NOT NULL',
					'actor_user_id'               => 'BIGINT UNSIGNED NULL',
					'reason'                      => 'VARCHAR(500) NULL',
					'attempt_no'                  => 'INT UNSIGNED NOT NULL',
					'submission_revision_hash'    => 'CHAR(64) NOT NULL',
					'publication_hash'            => 'CHAR(64) NOT NULL',
					'attachment_set_hash'         => 'CHAR(64) NOT NULL',
					'assignment_schedule_version' => 'INT UNSIGNED NOT NULL',
					'assignment_schedule_hash'    => 'CHAR(64) NOT NULL',
					'resubmission_policy_hash'    => 'CHAR(64) NOT NULL',
					'timeliness'                  => 'VARCHAR(16) NOT NULL',
					'received_at_utc'             => 'DATETIME NOT NULL',
				),
				array(
					self::uq( 'uq_receipt_public', 'public_id' ),
					self::uq( 'uq_receipt_no', 'submission_id', 'receipt_no' ),
					self::uq( 'uq_receipt_idempotency', 'idempotency_key' ),
				),
				array(
					self::fk( 'fk_receipt_submission', 'submission_id', 'oj_ai_submissions' ),
					self::fk( 'fk_receipt_revision', 'submission_revision_id', 'oj_ai_submission_revisions' ),
				)
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_submission_attachments',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'submission_id'          => 'BIGINT UNSIGNED NOT NULL',
						'submission_revision_id' => 'BIGINT UNSIGNED NULL',
						'response_key'           => 'VARCHAR(100) NOT NULL',
						'file_public_id'         => 'CHAR(26) NOT NULL',
						'state'                  => 'VARCHAR(32) NOT NULL',
						'original_name'          => 'VARCHAR(255) NOT NULL',
						'mime_type'              => 'VARCHAR(191) NOT NULL',
						'byte_size'              => 'BIGINT UNSIGNED NOT NULL',
						'sha256'                 => 'CHAR(64) NOT NULL',
						'bound_at_utc'           => 'DATETIME NULL',
					),
					Schema_Profile::json_columns( 'ai_representation', true )
				),
				array(
					self::uq( 'uq_attachment_public', 'public_id' ),
					self::uq( 'uq_attachment_file', 'file_public_id' ),
					self::ix( 'ix_attachment_submission', 'submission_id', 'state' ),
					self::ix( 'ix_attachment_revision', 'submission_revision_id', 'response_key' ),
				),
				array(
					self::fk( 'fk_attachment_submission', 'submission_id', 'oj_ai_submissions' ),
					self::fk( 'fk_attachment_revision', 'submission_revision_id', 'oj_ai_submission_revisions' ),
				)
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_zip_uploads',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'assignment_id'       => 'BIGINT UNSIGNED NOT NULL',
						'uploaded_by_user_id' => 'BIGINT UNSIGNED NOT NULL',
						'file_public_id'      => 'CHAR(26) NOT NULL',
						'state'               => 'VARCHAR(32) NOT NULL',
						'idempotency_key'     => 'VARCHAR(191) NOT NULL',
					),
					Schema_Profile::json_columns( 'mapping' ),
					array(
						'item_total'        => 'INT UNSIGNED NOT NULL',
						'success_total'     => 'INT UNSIGNED NOT NULL',
						'failure_total'     => 'INT UNSIGNED NOT NULL',
						'cleanup_after_utc' => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_zip_public', 'public_id' ),
					self::uq( 'uq_zip_idempotency', 'idempotency_key' ),
					self::ix( 'ix_zip_assignment', 'assignment_id', 'state' ),
					self::ix( 'ix_zip_cleanup', 'state', 'cleanup_after_utc' ),
				),
				array( self::fk( 'fk_zip_assignment', 'assignment_id', 'oj_ai_assignments' ) )
			),
			self::define(
				self::OWNER_SUBMISSION,
				'oj_ai_zip_upload_items',
				array( Schema_Profile::PK, Schema_Profile::MUT ),
				array(
					'zip_upload_id'               => 'BIGINT UNSIGNED NOT NULL',
					'item_no'                     => 'INT UNSIGNED NOT NULL',
					'relative_path'               => 'VARCHAR(512) NOT NULL',
					'file_public_id'              => 'CHAR(26) NULL',
					'learner_user_id'             => 'BIGINT UNSIGNED NULL',
					'state'                       => 'VARCHAR(32) NOT NULL',
					'byte_size'                   => 'BIGINT UNSIGNED NOT NULL',
					'sha256'                      => 'CHAR(64) NOT NULL',
					'result_submission_public_id' => 'CHAR(26) NULL',
					'error_code'                  => 'VARCHAR(100) NULL',
				),
				array(
					self::uq( 'uq_zip_item_no', 'zip_upload_id', 'item_no' ),
					self::uq( 'uq_zip_item_path', 'zip_upload_id', 'relative_path(191)' ),
					self::ix( 'ix_zip_item_state', 'zip_upload_id', 'state', 'item_no' ),
				),
				array( self::fk( 'fk_zip_item_upload', 'zip_upload_id', 'oj_ai_zip_uploads' ) )
			),
		);
	}

	/**
	 * Assessment tables.
	 *
	 * @return array<int,Table_Definition>
	 */
	private function assessment_tables(): array {
		return array(
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_worksheet_evaluations',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'worksheet_revision_public_id'   => 'CHAR(26) NOT NULL',
					'worksheet_revision_hash'        => 'CHAR(64) NOT NULL',
					'policy_revision_public_id'      => 'CHAR(26) NOT NULL',
					'policy_hash'                    => 'CHAR(64) NOT NULL',
					'rubric_revision_public_id'      => 'CHAR(26) NOT NULL',
					'rubric_hash'                    => 'CHAR(64) NOT NULL',
					'review_rule_revision_public_id' => 'CHAR(26) NOT NULL',
					'review_rule_hash'               => 'CHAR(64) NOT NULL',
					'prompt_contract_version'        => 'VARCHAR(32) NOT NULL',
					'input_schema_version'           => 'VARCHAR(32) NOT NULL',
					'output_schema_version'          => 'VARCHAR(32) NOT NULL',
					'state'                          => 'VARCHAR(32) NOT NULL',
					'idempotency_key'                => 'VARCHAR(191) NOT NULL',
					'requested_at_utc'               => 'DATETIME NOT NULL',
					'authorization_envelope_version' => 'VARCHAR(32) NOT NULL',
					'authorization_envelope_json'    => 'LONGTEXT NOT NULL',
					'authorization_envelope_hash'    => 'CHAR(64) NOT NULL',
					'enqueue_correlation_id'         => 'VARCHAR(100) NOT NULL',
				),
				array(
					self::uq( 'uq_worksheet_eval_public', 'public_id' ),
					self::uq( 'uq_worksheet_eval_idempotency', 'idempotency_key' ),
					self::ix( 'ix_worksheet_eval_source', 'worksheet_revision_public_id', 'state', 'created_at_utc' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_worksheet_evaluation_inputs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_evaluation_id'   => 'BIGINT UNSIGNED NOT NULL',
						'projection_schema_version' => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'input' ),
					array(
						'input_byte_size'   => 'INT UNSIGNED NOT NULL',
						'redaction_version' => 'VARCHAR(32) NOT NULL',
					)
				),
				array(
					self::uq( 'uq_worksheet_input_public', 'public_id' ),
					self::uq( 'uq_worksheet_input_eval', 'worksheet_evaluation_id' ),
				),
				array( self::fk( 'fk_worksheet_input_eval', 'worksheet_evaluation_id', 'oj_ai_worksheet_evaluations' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_worksheet_evaluation_results',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_evaluation_id' => 'BIGINT UNSIGNED NOT NULL',
						'evaluation_run_id'       => 'BIGINT UNSIGNED NOT NULL',
						'output_schema_version'   => 'VARCHAR(32) NOT NULL',
						'overall_status'          => 'VARCHAR(32) NOT NULL',
						'overall_confidence'      => 'DECIMAL(5,4) NOT NULL',
						'summary'                 => 'TEXT NOT NULL',
					),
					Schema_Profile::json_columns( 'normalized_result' ),
					array( 'provider_output_digest' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_worksheet_result_public', 'public_id' ),
					self::uq( 'uq_worksheet_result_run', 'evaluation_run_id' ),
					self::ix( 'ix_worksheet_result_eval', 'worksheet_evaluation_id', 'created_at_utc' ),
				),
				array(
					self::fk( 'fk_worksheet_result_eval', 'worksheet_evaluation_id', 'oj_ai_worksheet_evaluations' ),
					self::fk( 'fk_worksheet_result_run', 'evaluation_run_id', 'oj_ai_evaluation_runs' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_worksheet_evaluation_items',
				array( Schema_Profile::PK, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_evaluation_result_id' => 'BIGINT UNSIGNED NOT NULL',
						'dimension_key'                  => 'VARCHAR(100) NOT NULL',
						'position'                       => 'INT UNSIGNED NOT NULL',
						'severity'                       => 'VARCHAR(32) NOT NULL',
						'confidence'                     => 'DECIMAL(5,4) NOT NULL',
					),
					Schema_Profile::json_columns( 'evidence' ),
					array( 'recommendation' => 'TEXT NOT NULL' )
				),
				array(
					self::uq( 'uq_worksheet_item', 'worksheet_evaluation_result_id', 'dimension_key', 'position' ),
					self::ix( 'ix_worksheet_item_severity', 'worksheet_evaluation_result_id', 'severity' ),
				),
				array( self::fk( 'fk_worksheet_item_result', 'worksheet_evaluation_result_id', 'oj_ai_worksheet_evaluation_results' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_worksheet_evaluation_reviews',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'worksheet_evaluation_id'        => 'BIGINT UNSIGNED NOT NULL',
						'worksheet_evaluation_result_id' => 'BIGINT UNSIGNED NOT NULL',
						'review_no'                      => 'INT UNSIGNED NOT NULL',
						'decision'                       => 'VARCHAR(32) NOT NULL',
						'note'                           => 'TEXT NULL',
					),
					Schema_Profile::json_columns( 'item_decisions' ),
					array( 'previous_review_id' => 'BIGINT UNSIGNED NULL' )
				),
				array(
					self::uq( 'uq_worksheet_review_public', 'public_id' ),
					self::uq( 'uq_worksheet_review_no', 'worksheet_evaluation_id', 'review_no' ),
					self::ix( 'ix_worksheet_review_decision', 'worksheet_evaluation_id', 'decision', 'created_at_utc' ),
				),
				array(
					self::fk( 'fk_worksheet_review_eval', 'worksheet_evaluation_id', 'oj_ai_worksheet_evaluations' ),
					self::fk( 'fk_worksheet_review_result', 'worksheet_evaluation_result_id', 'oj_ai_worksheet_evaluation_results' ),
					self::fk( 'fk_worksheet_review_previous', 'previous_review_id', 'oj_ai_worksheet_evaluation_reviews' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_evaluations',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'submission_revision_public_id'   => 'CHAR(26) NOT NULL',
					'submission_revision_hash'        => 'CHAR(64) NOT NULL',
					'worksheet_publication_public_id' => 'CHAR(26) NOT NULL',
					'publication_hash'                => 'CHAR(64) NOT NULL',
					'policy_revision_public_id'       => 'CHAR(26) NOT NULL',
					'policy_hash'                     => 'CHAR(64) NOT NULL',
					'rubric_revision_public_id'       => 'CHAR(26) NOT NULL',
					'rubric_hash'                     => 'CHAR(64) NOT NULL',
					'review_rule_revision_public_id'  => 'CHAR(26) NOT NULL',
					'review_rule_hash'                => 'CHAR(64) NOT NULL',
					'prompt_contract_version'         => 'VARCHAR(64) NOT NULL',
					'input_schema_version'            => 'VARCHAR(32) NOT NULL',
					'output_schema_version'           => 'VARCHAR(32) NOT NULL',
					'state'                           => 'VARCHAR(32) NOT NULL',
					'idempotency_key'                 => 'VARCHAR(191) NOT NULL',
					'requested_at_utc'                => 'DATETIME NOT NULL',
					'authorization_envelope_version'  => 'VARCHAR(32) NOT NULL',
					'authorization_envelope_json'     => 'LONGTEXT NOT NULL',
					'authorization_envelope_hash'     => 'CHAR(64) NOT NULL',
					'enqueue_correlation_id'          => 'VARCHAR(100) NOT NULL',
				),
				array(
					self::uq( 'uq_submission_eval_public', 'public_id' ),
					self::uq( 'uq_submission_eval_idempotency', 'idempotency_key' ),
					self::ix( 'ix_submission_eval_source', 'submission_revision_public_id', 'state', 'created_at_utc' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_evaluation_inputs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_id'  => 'BIGINT UNSIGNED NOT NULL',
						'projection_schema_version' => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'input' ),
					Schema_Profile::json_columns( 'artifact_references' ),
					array(
						'input_byte_size'   => 'INT UNSIGNED NOT NULL',
						'redaction_version' => 'VARCHAR(32) NOT NULL',
					)
				),
				array(
					self::uq( 'uq_submission_input_public', 'public_id' ),
					self::uq( 'uq_submission_input_eval', 'submission_evaluation_id' ),
				),
				array( self::fk( 'fk_submission_input_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_evaluation_results',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_id' => 'BIGINT UNSIGNED NOT NULL',
						'evaluation_run_id'        => 'BIGINT UNSIGNED NOT NULL',
						'output_schema_version'    => 'VARCHAR(32) NOT NULL',
						'candidate_score'          => 'DECIMAL(12,4) NULL',
						'maximum_score'            => 'DECIMAL(12,4) NOT NULL',
						'overall_confidence'       => 'DECIMAL(5,4) NOT NULL',
						'abstained'                => 'TINYINT UNSIGNED NOT NULL',
						'safety_flagged'           => 'TINYINT UNSIGNED NOT NULL',
						'summary'                  => 'TEXT NOT NULL',
					),
					Schema_Profile::json_columns( 'normalized_result' ),
					array( 'provider_output_digest' => 'CHAR(64) NOT NULL' )
				),
				array(
					self::uq( 'uq_submission_result_public', 'public_id' ),
					self::uq( 'uq_submission_result_run', 'evaluation_run_id' ),
					self::ix( 'ix_submission_result_eval', 'submission_evaluation_id', 'created_at_utc' ),
				),
				array(
					self::fk( 'fk_submission_result_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ),
					self::fk( 'fk_submission_result_run', 'evaluation_run_id', 'oj_ai_evaluation_runs' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_evaluation_items',
				array( Schema_Profile::PK, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_result_id' => 'BIGINT UNSIGNED NOT NULL',
						'criterion_key'                   => 'VARCHAR(100) NOT NULL',
						'question_key'                    => 'VARCHAR(100) NULL',
						'position'                        => 'INT UNSIGNED NOT NULL',
						'candidate_points'                => 'DECIMAL(12,4) NULL',
						'maximum_points'                  => 'DECIMAL(12,4) NOT NULL',
						'confidence'                      => 'DECIMAL(5,4) NOT NULL',
					),
					Schema_Profile::json_columns( 'evidence' ),
					array( 'flags' => 'VARCHAR(191) NULL' )
				),
				array(
					self::uq( 'uq_submission_item', 'submission_evaluation_result_id', 'criterion_key', 'position' ),
					self::ix( 'ix_submission_item_question', 'submission_evaluation_result_id', 'question_key' ),
				),
				array( self::fk( 'fk_submission_item_result', 'submission_evaluation_result_id', 'oj_ai_submission_evaluation_results' ) )
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_evaluation_reviews',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_id'        => 'BIGINT UNSIGNED NOT NULL',
						'submission_evaluation_result_id' => 'BIGINT UNSIGNED NOT NULL',
						'review_no'                       => 'INT UNSIGNED NOT NULL',
						'decision'                        => 'VARCHAR(32) NOT NULL',
						'official_score'                  => 'DECIMAL(12,4) NULL',
					),
					Schema_Profile::json_columns( 'score_overrides', true ),
					array(
						'reason'             => 'TEXT NULL',
						'previous_review_id' => 'BIGINT UNSIGNED NULL',
					)
				),
				array(
					self::uq( 'uq_submission_review_public', 'public_id' ),
					self::uq( 'uq_submission_review_no', 'submission_evaluation_id', 'review_no' ),
					self::ix( 'ix_submission_review_decision', 'submission_evaluation_id', 'decision', 'created_at_utc' ),
				),
				array(
					self::fk( 'fk_submission_review_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ),
					self::fk( 'fk_submission_review_result', 'submission_evaluation_result_id', 'oj_ai_submission_evaluation_results' ),
					self::fk( 'fk_submission_review_previous', 'previous_review_id', 'oj_ai_submission_evaluation_reviews' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_feedback_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_id' => 'BIGINT UNSIGNED NOT NULL',
						'submission_review_id'     => 'BIGINT UNSIGNED NOT NULL',
						'revision_no'              => 'INT UNSIGNED NOT NULL',
						'audience'                 => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'feedback' ),
					array(
						'state'                           => 'VARCHAR(32) NOT NULL',
						'previous_revision_id'            => 'BIGINT UNSIGNED NULL',
						'source_evaluation_run_public_id' => 'CHAR(26) NULL',
					)
				),
				array(
					self::uq( 'uq_feedback_public', 'public_id' ),
					self::uq( 'uq_feedback_revision', 'submission_evaluation_id', 'revision_no' ),
					self::ix( 'ix_feedback_review', 'submission_review_id', 'state' ),
				),
				array(
					self::fk( 'fk_feedback_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ),
					self::fk( 'fk_feedback_review', 'submission_review_id', 'oj_ai_submission_evaluation_reviews' ),
					self::fk( 'fk_feedback_previous', 'previous_revision_id', 'oj_ai_submission_feedback_revisions' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_submission_release_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'submission_evaluation_id' => 'BIGINT UNSIGNED NOT NULL',
						'submission_review_id'     => 'BIGINT UNSIGNED NOT NULL',
						'feedback_revision_id'     => 'BIGINT UNSIGNED NULL',
						'release_no'               => 'INT UNSIGNED NOT NULL',
						'state'                    => 'VARCHAR(32) NOT NULL',
						'visible_score'            => 'DECIMAL(12,4) NULL',
					),
					Schema_Profile::json_columns( 'student_projection' ),
					array(
						'previous_release_id' => 'BIGINT UNSIGNED NULL',
						'release_reason_code' => 'VARCHAR(64) NULL',
						'release_reason_note' => 'TEXT NULL',
						'released_at_utc'     => 'DATETIME NOT NULL',
					)
				),
				array(
					self::uq( 'uq_release_public', 'public_id' ),
					self::uq( 'uq_release_no', 'submission_evaluation_id', 'release_no' ),
					self::ix( 'ix_release_current', 'submission_evaluation_id', 'state', 'released_at_utc' ),
				),
				array(
					self::fk( 'fk_release_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ),
					self::fk( 'fk_release_review', 'submission_review_id', 'oj_ai_submission_evaluation_reviews' ),
					self::fk( 'fk_release_feedback', 'feedback_revision_id', 'oj_ai_submission_feedback_revisions' ),
					self::fk( 'fk_release_previous', 'previous_release_id', 'oj_ai_submission_release_revisions' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_student_note_revisions',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				self::columns(
					array(
						'student_note_public_id'          => 'CHAR(26) NOT NULL',
						'submission_evaluation_id'        => 'BIGINT UNSIGNED NOT NULL',
						'submission_review_id'            => 'BIGINT UNSIGNED NULL',
						'source_evaluation_run_public_id' => 'CHAR(26) NULL',
						'revision_no'                     => 'INT UNSIGNED NOT NULL',
						'state'                           => 'VARCHAR(32) NOT NULL',
						'include_in_reports'              => 'TINYINT UNSIGNED NOT NULL',
					),
					Schema_Profile::json_columns( 'note' ),
					array(
						'previous_revision_id' => 'BIGINT UNSIGNED NULL',
						'approved_by_user_id'  => 'BIGINT UNSIGNED NULL',
						'approved_at_utc'      => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_student_note_revision_public', 'public_id' ),
					self::uq( 'uq_student_note_revision', 'student_note_public_id', 'revision_no' ),
					self::ix( 'ix_student_note_eval', 'submission_evaluation_id', 'state', 'created_at_utc' ),
					self::ix( 'ix_student_note_reports', 'include_in_reports', 'state', 'created_at_utc' ),
				),
				array(
					self::fk( 'fk_student_note_eval', 'submission_evaluation_id', 'oj_ai_submission_evaluations' ),
					self::fk( 'fk_student_note_review', 'submission_review_id', 'oj_ai_submission_evaluation_reviews' ),
					self::fk( 'fk_student_note_previous', 'previous_revision_id', 'oj_ai_student_note_revisions' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_evaluation_runs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'subject_type'          => 'VARCHAR(32) NOT NULL',
					'evaluation_public_id'  => 'CHAR(26) NOT NULL',
					'run_no'                => 'INT UNSIGNED NOT NULL',
					'state'                 => 'VARCHAR(32) NOT NULL',
					'task_key'              => 'VARCHAR(191) NOT NULL',
					'contract_version'      => 'VARCHAR(32) NOT NULL',
					'operation_key'         => 'VARCHAR(64) NOT NULL',
					'input_schema_version'  => 'VARCHAR(32) NOT NULL',
					'output_schema_version' => 'VARCHAR(32) NOT NULL',
					'input_digest'          => 'CHAR(64) NOT NULL',
					'result_type'           => 'VARCHAR(64) NULL',
					'result_public_id'      => 'CHAR(26) NULL',
					'idempotency_key'       => 'VARCHAR(191) NOT NULL',
					'correlation_id'        => 'VARCHAR(100) NOT NULL',
					'provider_key'          => 'VARCHAR(100) NULL',
					'model_key'             => 'VARCHAR(100) NULL',
					'profile_fingerprint'   => 'CHAR(64) NULL',
					'routing_revision'      => 'VARCHAR(191) NULL',
					'available_at_utc'      => 'DATETIME NOT NULL',
					'queued_at_utc'         => 'DATETIME NOT NULL',
					'lease_token'           => 'CHAR(36) NULL',
					'lease_expires_at_utc'  => 'DATETIME NULL',
					'started_at_utc'        => 'DATETIME NULL',
					'finished_at_utc'       => 'DATETIME NULL',
					'last_error_code'       => 'VARCHAR(100) NULL',
					'last_error_message'    => 'VARCHAR(500) NULL',
				),
				array(
					self::uq( 'uq_eval_run_public', 'public_id' ),
					self::uq( 'uq_eval_run_no', 'subject_type', 'evaluation_public_id', 'run_no' ),
					self::uq( 'uq_eval_run_idempotency', 'idempotency_key' ),
					self::ix( 'ix_eval_run_queue', 'state', 'available_at_utc', 'id' ),
					self::ix( 'ix_eval_run_lease', 'state', 'lease_expires_at_utc' ),
				)
			),
			self::define(
				self::OWNER_ASSESSMENT,
				'oj_ai_evaluation_attempts',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::SYS ),
				self::columns(
					array(
						'evaluation_run_id'      => 'BIGINT UNSIGNED NOT NULL',
						'attempt_no'             => 'INT UNSIGNED NOT NULL',
						'state'                  => 'VARCHAR(32) NOT NULL',
						'request_hash'           => 'CHAR(64) NOT NULL',
						'provider_output_digest' => 'CHAR(64) NULL',
					),
					Schema_Profile::json_columns( 'usage', true ),
					array(
						'gateway_correlation_id' => 'VARCHAR(100) NULL',
						'duration_ms'            => 'INT UNSIGNED NULL',
						'error_code'             => 'VARCHAR(100) NULL',
						'started_at_utc'         => 'DATETIME NOT NULL',
						'finished_at_utc'        => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_eval_attempt_public', 'public_id' ),
					self::uq( 'uq_eval_attempt_no', 'evaluation_run_id', 'attempt_no' ),
					self::ix( 'ix_eval_attempt_state', 'state', 'started_at_utc' ),
					self::ix( 'ix_eval_attempt_gateway', 'gateway_correlation_id' ),
				),
				array( self::fk( 'fk_eval_attempt_run', 'evaluation_run_id', 'oj_ai_evaluation_runs' ) )
			),
		);
	}

	/**
	 * Artifact tables.
	 *
	 * @return array<int,Table_Definition>
	 */
	private function artifact_tables(): array {
		return array(
			self::define(
				self::OWNER_STORAGE,
				'oj_ai_private_files',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				array(
					'owner_type'           => 'VARCHAR(64) NOT NULL',
					'owner_public_id'      => 'CHAR(26) NOT NULL',
					'purpose'              => 'VARCHAR(64) NOT NULL',
					'storage_key'          => 'VARCHAR(512) NOT NULL',
					'original_name'        => 'VARCHAR(255) NULL',
					'mime_type'            => 'VARCHAR(191) NOT NULL',
					'byte_size'            => 'BIGINT UNSIGNED NOT NULL',
					'sha256'               => 'CHAR(64) NOT NULL',
					'state'                => 'VARCHAR(32) NOT NULL',
					'scan_state'           => 'VARCHAR(32) NOT NULL',
					'retention_class'      => 'VARCHAR(64) NOT NULL',
					'expires_at_utc'       => 'DATETIME NULL',
					'legal_hold_until_utc' => 'DATETIME NULL',
					'purged_at_utc'        => 'DATETIME NULL',
				),
				array(
					self::uq( 'uq_private_file_public', 'public_id' ),
					self::uq( 'uq_private_storage_key', 'storage_key(191)' ),
					self::ix( 'ix_private_file_owner', 'owner_type', 'owner_public_id', 'state' ),
					self::ix( 'ix_private_file_cleanup', 'state', 'expires_at_utc' ),
				)
			),
			self::define(
				self::OWNER_REPORTING,
				'oj_ai_report_snapshots',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT, Schema_Profile::CTX ),
				self::columns(
					array(
						'report_type'       => 'VARCHAR(64) NOT NULL',
						'subject_type'      => 'VARCHAR(64) NOT NULL',
						'subject_public_id' => 'CHAR(26) NULL',
						'state'             => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'filter' ),
					array(
						'source_watermark'        => 'VARCHAR(191) NOT NULL',
						'source_manifest_schema'  => 'VARCHAR(100) NOT NULL',
						'source_manifest_version' => 'SMALLINT UNSIGNED NOT NULL',
						'source_manifest_json'    => 'LONGTEXT NOT NULL',
						'source_manifest_hash'    => 'CHAR(64) NOT NULL',
					),
					Schema_Profile::json_columns( 'projection' ),
					array(
						'generated_by_user_id' => 'BIGINT UNSIGNED NOT NULL',
						'generated_at_utc'     => 'DATETIME NULL',
						'expires_at_utc'       => 'DATETIME NULL',
					)
				),
				array(
					self::uq( 'uq_report_public', 'public_id' ),
					self::ix( 'ix_report_subject', 'report_type', 'subject_type', 'subject_public_id', 'generated_at_utc' ),
					self::ix( 'ix_report_context', 'context_type', 'context_id', 'state' ),
				)
			),
			self::define(
				self::OWNER_REPORTING,
				'oj_ai_export_jobs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					Schema_Profile::context_columns( true ),
					array(
						'report_snapshot_id'       => 'BIGINT UNSIGNED NULL',
						'artifact_kind'            => 'VARCHAR(32) NOT NULL',
						'source_type'              => 'VARCHAR(64) NOT NULL',
						'source_public_id'         => 'CHAR(26) NOT NULL',
						'source_hash'              => 'CHAR(64) NOT NULL',
						'request_contract_version' => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'request_snapshot' ),
					array(
						'request_hash'          => 'CHAR(64) NOT NULL',
						'state'                 => 'VARCHAR(32) NOT NULL',
						'requested_by_user_id'  => 'BIGINT UNSIGNED NOT NULL',
						'idempotency_key'       => 'VARCHAR(191) NOT NULL',
						'active_identity_key'   => 'VARCHAR(191) NULL',
						'print_profile_version' => 'VARCHAR(32) NULL',
						'available_at_utc'      => 'DATETIME NOT NULL',
						'queued_at_utc'         => 'DATETIME NOT NULL',
						'lease_token'           => 'CHAR(36) NULL',
						'lease_expires_at_utc'  => 'DATETIME NULL',
						'started_at_utc'        => 'DATETIME NULL',
						'finished_at_utc'       => 'DATETIME NULL',
						'result_file_public_id' => 'CHAR(26) NULL',
						'error_code'            => 'VARCHAR(100) NULL',
					)
				),
				array(
					self::uq( 'uq_export_public', 'public_id' ),
					self::uq( 'uq_export_idempotency', 'idempotency_key' ),
					self::uq( 'uq_export_active_identity', 'active_identity_key' ),
					self::ix( 'ix_export_queue', 'state', 'available_at_utc', 'id' ),
					self::ix( 'ix_export_requester', 'requested_by_user_id', 'state', 'created_at_utc' ),
				),
				array( self::fk( 'fk_export_report', 'report_snapshot_id', 'oj_ai_report_snapshots' ) )
			),
			self::define(
				self::OWNER_REPORTING,
				'oj_ai_pdf_artifacts',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::IMM ),
				array(
					'export_job_id'         => 'BIGINT UNSIGNED NOT NULL',
					'file_public_id'        => 'CHAR(26) NOT NULL',
					'source_type'           => 'VARCHAR(64) NOT NULL',
					'source_public_id'      => 'CHAR(26) NOT NULL',
					'source_hash'           => 'CHAR(64) NOT NULL',
					'print_profile_version' => 'VARCHAR(32) NOT NULL',
					'renderer_version'      => 'VARCHAR(32) NOT NULL',
					'page_count'            => 'INT UNSIGNED NULL',
					'state'                 => 'VARCHAR(32) NOT NULL',
					'expires_at_utc'        => 'DATETIME NULL',
				),
				array(
					self::uq( 'uq_pdf_public', 'public_id' ),
					self::uq( 'uq_pdf_export', 'export_job_id' ),
					self::ix( 'ix_pdf_source', 'source_type', 'source_public_id', 'created_at_utc' ),
				),
				array( self::fk( 'fk_pdf_export', 'export_job_id', 'oj_ai_export_jobs' ) )
			),
			self::define(
				self::OWNER_IMPORT,
				'oj_ai_import_runs',
				array( Schema_Profile::PK, Schema_Profile::PID, Schema_Profile::MUT ),
				self::columns(
					array(
						'source_kind'        => 'VARCHAR(64) NOT NULL',
						'source_version'     => 'VARCHAR(64) NOT NULL',
						'source_fingerprint' => 'CHAR(64) NOT NULL',
						'state'              => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'preview' ),
					array(
						'approved_by_user_id' => 'BIGINT UNSIGNED NULL',
						'approved_at_utc'     => 'DATETIME NULL',
						'idempotency_key'     => 'VARCHAR(191) NOT NULL',
					),
					Schema_Profile::json_columns( 'checkpoint', true ),
					array(
						'item_total'    => 'INT UNSIGNED NOT NULL',
						'success_total' => 'INT UNSIGNED NOT NULL',
						'failure_total' => 'INT UNSIGNED NOT NULL',
						'skipped_total' => 'INT UNSIGNED NOT NULL',
						'error_code'    => 'VARCHAR(100) NULL',
					)
				),
				array(
					self::uq( 'uq_import_public', 'public_id' ),
					self::uq( 'uq_import_idempotency', 'idempotency_key' ),
					self::ix( 'ix_import_source', 'source_kind', 'source_fingerprint' ),
					self::ix( 'ix_import_state', 'state', 'updated_at_utc' ),
				)
			),
			self::define(
				self::OWNER_IMPORT,
				'oj_ai_import_items',
				array( Schema_Profile::PK, Schema_Profile::MUT ),
				self::columns(
					array(
						'import_run_id'   => 'BIGINT UNSIGNED NOT NULL',
						'item_no'         => 'INT UNSIGNED NOT NULL',
						'source_type'     => 'VARCHAR(64) NOT NULL',
						'source_key'      => 'VARCHAR(191) NOT NULL',
						'source_checksum' => 'CHAR(64) NOT NULL',
						'state'           => 'VARCHAR(32) NOT NULL',
					),
					Schema_Profile::json_columns( 'payload' ),
					array(
						'target_type'      => 'VARCHAR(64) NULL',
						'target_public_id' => 'CHAR(26) NULL',
						'error_code'       => 'VARCHAR(100) NULL',
					)
				),
				array(
					self::uq( 'uq_import_item_no', 'import_run_id', 'item_no' ),
					self::uq( 'uq_import_item_source', 'import_run_id', 'source_type', 'source_key' ),
					self::ix( 'ix_import_item_state', 'import_run_id', 'state', 'item_no' ),
				),
				array( self::fk( 'fk_import_item_run', 'import_run_id', 'oj_ai_import_runs' ) )
			),
		);
	}

	/**
	 * Define.
	 *
	 * @param string                                                                    $owner Owner module.
	 * @param string                                                                    $name Physical table name.
	 * @param array<int,string>                                                         $profiles Fixed profiles.
	 * @param array<string,string>                                                      $columns Business columns.
	 * @param array<int,array{name:string,columns:array<int,string>,unique:bool}>       $indexes Indexes.
	 * @param array<int,array{name:string,column:string,table:string,reference:string}> $foreign_keys Foreign keys.
	 * @return Table_Definition
	 */
	private static function define(
		string $owner,
		string $name,
		array $profiles,
		array $columns,
		array $indexes = array(),
		array $foreign_keys = array()
	): Table_Definition {
		return new Table_Definition(
			$name,
			$owner,
			Schema_Profile::merge_columns( Schema_Profile::columns( $profiles ), $columns ),
			$indexes,
			$foreign_keys
		);
	}

	/**
	 * Columns.
	 *
	 * @param array<string,string> ...$sets Sets.
	 * @return array<string,string>
	 */
	private static function columns( array ...$sets ): array {
		return Schema_Profile::merge_columns( ...$sets );
	}

	/**
	 * Uq.
	 *
	 * @param string $name Name.
	 * @param string ...$columns Columns.
	 * @return array{name:string,columns:array<int,string>,unique:bool}
	 */
	private static function uq( string $name, string ...$columns ): array {
		return array(
			'name'    => $name,
			'columns' => $columns,
			'unique'  => true,
		);
	}

	/**
	 * Ix.
	 *
	 * @param string $name Name.
	 * @param string ...$columns Columns.
	 * @return array{name:string,columns:array<int,string>,unique:bool}
	 */
	private static function ix( string $name, string ...$columns ): array {
		return array(
			'name'    => $name,
			'columns' => $columns,
			'unique'  => false,
		);
	}

	/**
	 * Fk.
	 *
	 * @param string $name Name.
	 * @param string $column Column.
	 * @param string $table Table.
	 * @return array{name:string,column:string,table:string,reference:string}
	 */
	private static function fk( string $name, string $column, string $table ): array {
		return array(
			'name'      => $name,
			'column'    => $column,
			'table'     => $table,
			'reference' => 'id',
		);
	}

	/**
	 * Expected owner counts.
	 *
	 * @return array<string,int>
	 */
	private static function expected_owner_counts(): array {
		return array(
			self::OWNER_DATABASE                 => 2,
			self::OWNER_OPERATIONS               => 3,
			self::OWNER_CURRICULUM               => 2,
			self::OWNER_WORKSHEET                => 6,
			self::OWNER_ASSESSMENT_CONFIGURATION => 3,
			self::OWNER_SUBMISSION               => 8,
			self::OWNER_ASSESSMENT               => 18,
			self::OWNER_STORAGE                  => 1,
			self::OWNER_REPORTING                => 3,
			self::OWNER_IMPORT                   => 2,
		);
	}

	/**
	 * Reject invalid owner counts.
	 *
	 * @param array<string,Table_Definition> $tables Tables.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_owner_counts( array $tables ): void {
		$counts = array_fill_keys( array_keys( self::expected_owner_counts() ), 0 );
		foreach ( $tables as $table ) {
			if ( ! array_key_exists( $table->owner(), $counts ) ) {
				throw new LogicException( 'Unknown table owner: ' . $table->owner() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
			++$counts[ $table->owner() ];
		}
		if ( self::expected_owner_counts() !== $counts ) {
			throw new LogicException( 'The frozen 10-owner table allocation has changed.' );
		}
	}

	/**
	 * Reject invalid foreign key ownership.
	 *
	 * @param array<string,Table_Definition> $tables Tables.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_foreign_key_ownership( array $tables ): void {
		foreach ( $tables as $table ) {
			foreach ( $table->foreign_keys() as $foreign_key ) {
				if ( ! isset( $tables[ $foreign_key['table'] ] ) ) {
					throw new LogicException( 'Foreign key target is outside the frozen catalog.' );
				}
				if ( $tables[ $foreign_key['table'] ]->owner() !== $table->owner() ) {
					throw new LogicException( 'Physical foreign keys may not cross owner boundaries.' );
				}
			}
		}
	}
}

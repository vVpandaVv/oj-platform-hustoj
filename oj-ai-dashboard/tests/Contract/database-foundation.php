<?php
/**
 * G1 database foundation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Database foundation assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function database_foundation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Database foundation has issue.
 *
 * @param array<int,array{code:string,message:string,table?:string}> $issues Issues.
 * @param string                                                     $code Code.
 * @return bool
 */
function database_foundation_has_issue( array $issues, string $code ): bool {
	foreach ( $issues as $issue ) {
		if ( $issue['code'] === $code ) {
			return true;
		}
	}

	return false;
}

$expected_names = array(
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

$catalog = new Schema_Catalog();
$tables  = $catalog->tables();
database_foundation_assert( 48 === ( count( $tables ) ), 'The catalog must contain 45 existing and 3 assessment project tables.' );
database_foundation_assert( $catalog->table_names() === $expected_names, 'The 45-table order or name set changed.' );
database_foundation_assert(
	$catalog->owner_counts() === array(
		'Database'                => 2,
		'Operations'              => 3,
		'Curriculum'              => 2,
		'Worksheet'               => 6,
		'AssessmentConfiguration' => 3,
		'Submission'              => 8,
		'Assessment'              => 18,
		'Storage'                 => 1,
		'Reporting'               => 3,
		'Import'                  => 2,
	),
	'The 10-owner table allocation changed.'
);

$foreign_key_names = array();
foreach ( $tables as $name => $table ) {
	database_foundation_assert( 1 === ( preg_match( '/^oj_ai_[a-z0-9_]+$/', $name ) ), 'A table escaped the literal namespace.' );
	database_foundation_assert(
		'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT' === ( $table->columns()['id'] ?? '' ),
		'Every table must have the unsigned PK profile.'
	);
	$sql = $table->create_sql();
	database_foundation_assert(
		str_ends_with( $sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ),
		'Every table must use the frozen engine, charset, and collation.'
	);
	foreach ( $table->foreign_keys() as $foreign_key ) {
		database_foundation_assert(
			$tables[ $foreign_key['table'] ]->owner() === $table->owner(),
			'Physical foreign keys may not cross owner boundaries.'
		);
		database_foundation_assert(
			! isset( $foreign_key_names[ $foreign_key['name'] ] ),
			'Foreign-key names must be unique across the add-on schema.'
		);
		$foreign_key_names[ $foreign_key['name'] ] = true;
		database_foundation_assert(
			str_contains( $sql, 'ON UPDATE RESTRICT ON DELETE RESTRICT' ),
			'Every physical foreign key must be explicitly restrictive.'
		);
	}
}

$required_columns = array(
	'oj_ai_worksheet_revisions'           => array( 'document_schema_version', 'document_hash', 'validation_hash' ),
	'oj_ai_worksheet_publications'        => array( 'projection_manifest_version', 'publication_hash' ),
	'oj_ai_worksheet_evaluation_reviews'  => array(
		'item_decisions_schema',
		'item_decisions_version',
		'item_decisions_json',
		'item_decisions_hash',
	),
	'oj_ai_assignments'                   => array(
		'target_roster_revision',
		'schedule_version',
		'schedule_hash',
		'auto_submit_enabled',
		'auto_submit_completed_at_utc',
	),
	'oj_ai_assignment_targets'            => array( 'membership_revision' ),
	'oj_ai_submission_receipts'           => array(
		'content_hash',
		'actor_type',
		'reason',
		'attempt_no',
		'assignment_schedule_hash',
		'resubmission_policy_hash',
		'timeliness',
	),
	'oj_ai_submission_attachments'        => array(
		'ai_representation_schema',
		'ai_representation_version',
		'ai_representation_json',
		'ai_representation_hash',
	),
	'oj_ai_submissions'                   => array(
		'official_review_public_id',
		'official_review_hash',
		'current_release_public_id',
		'current_release_hash',
	),
	'oj_ai_submission_feedback_revisions' => array( 'previous_revision_id', 'source_evaluation_run_public_id' ),
	'oj_ai_submission_release_revisions'  => array( 'release_reason_code', 'release_reason_note' ),
	'oj_ai_report_snapshots'              => array(
		'source_manifest_schema',
		'source_manifest_version',
		'source_manifest_json',
		'source_manifest_hash',
	),
	'oj_ai_export_jobs'                   => array(
		'source_type',
		'source_public_id',
		'source_hash',
		'request_contract_version',
		'request_snapshot_schema',
		'request_snapshot_version',
		'request_snapshot_json',
		'request_snapshot_hash',
		'active_identity_key',
	),
	'oj_ai_purge_jobs'                    => array( 'idempotency_key', 'preview_schema', 'preview_version', 'preview_json', 'preview_hash' ),
	'oj_ai_import_runs'                   => array( 'preview_schema', 'preview_version', 'preview_json', 'preview_hash' ),
);
foreach ( $required_columns as $table_name => $columns ) {
	foreach ( $columns as $column ) {
		database_foundation_assert(
			isset( $tables[ $table_name ]->columns()[ $column ] ),
			$table_name . ' is missing frozen amendment column ' . $column . '.'
		);
	}
}

foreach ( array( 'oj_ai_worksheet_evaluations', 'oj_ai_submission_evaluations' ) as $root_name ) {
	$columns = $tables[ $root_name ]->columns();
	database_foundation_assert(
		isset( $columns['authorization_envelope_version'], $columns['authorization_envelope_json'], $columns['authorization_envelope_hash'] ),
		'Evaluation roots must preserve the authorization envelope triplet.'
	);
	database_foundation_assert(
		! isset( $columns['authorization_envelope_schema'] ),
		'The evaluation authorization envelope exception must not duplicate its explicit version and hash.'
	);
}

$creation_order = $catalog->creation_order();
$positions      = array();
foreach ( $creation_order as $position => $table ) {
	$positions[ $table->name() ] = $position;
}
foreach ( $tables as $table ) {
	foreach ( $table->dependencies() as $dependency ) {
		database_foundation_assert(
			$positions[ $dependency ] < $positions[ $table->name() ],
			'Creation order must place every FK dependency first.'
		);
	}
}

$fingerprint = $catalog->fingerprint();
database_foundation_assert( 1 === ( preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ), 'Schema fingerprint must be SHA-256.' );
database_foundation_assert(
	'acacb197494135acaa976d4b8207171f93f9fd9f98fe545b73fbc6f51018bc6a' === ( $fingerprint ),
	'The 1.0.4 target fingerprint changed without a new immutable migration.'
);
database_foundation_assert( '1.0.4' === ( Schema_Catalog::SCHEMA_VERSION ), 'The target schema version changed.' );
database_foundation_assert( 64 === ( strlen( $catalog->owner_marker() ) ), 'Owner marker must be a 64-character digest.' );

$preflight = new Schema_Preflight( $catalog );
$fresh     = $preflight->inspect( array( 'wp_oj_ai_worksheets' ), null );
database_foundation_assert( $fresh->can_install(), 'Prefixed legacy names must not be discovered or adopted.' );

$unknown = $preflight->inspect( array( 'oj_ai_legacy_table' ), null );
database_foundation_assert( $unknown->is_blocked(), 'Unknown literal add-on tables must block installation.' );
database_foundation_assert(
	database_foundation_has_issue( $unknown->issues(), 'unexpected_addon_table' ),
	'Unknown literal table diagnostics are required.'
);

$partial = $preflight->inspect( array_slice( $expected_names, 0, 3 ), null );
database_foundation_assert( $partial->is_blocked(), 'A partially-created schema must require repair.' );
database_foundation_assert(
	database_foundation_has_issue( $partial->issues(), 'partial_schema' ),
	'Partial-schema diagnostics are required.'
);

$unowned = $preflight->inspect( $expected_names, null );
database_foundation_assert( $unowned->is_blocked(), 'Existing target names without an owner marker must not be adopted.' );

$installation = array(
	'addon_id'           => Schema_Catalog::ADDON_ID,
	'owner_marker'       => $catalog->owner_marker(),
	'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
	'schema_fingerprint' => $fingerprint,
);
$ready        = $preflight->inspect( $expected_names, $installation );
database_foundation_assert( $ready->is_ready(), 'A fully-owned matching schema must be ready.' );

$drifted                       = $installation;
$drifted['schema_fingerprint'] = str_repeat( '0', 64 );
$drift                         = $preflight->inspect( $expected_names, $drifted );
database_foundation_assert( $drift->is_blocked(), 'Schema fingerprint drift must fail closed.' );

$plan       = new Migration_Plan( $catalog );
$migrations = $plan->migrations();
database_foundation_assert( 'oj_ai_dashboard_schema' === ( Migration_Plan::LOCK_NAME ), 'The named lock changed.' );
database_foundation_assert( 6 === ( count( $migrations ) ), 'The plan must preserve five migrations and append assessment projects.' );
database_foundation_assert( '1.0.0' === ( Migration_Plan::BASELINE_SCHEMA_VERSION ), 'The immutable baseline version changed.' );
database_foundation_assert( '1.0.1' === ( Migration_Plan::PREVIOUS_SCHEMA_VERSION ), 'The immutable previous version changed.' );
database_foundation_assert( '31cee35952177177a1b36bd483f43ea1d6c1880c7d2a1e1ad891f896a41e32b1' === ( Migration_Plan::PREVIOUS_SCHEMA_FINGERPRINT ), 'The immutable 1.0.1 fingerprint changed.' );
database_foundation_assert( '1.0.2' === ( Migration_Plan::PREVIOUS_2_SCHEMA_VERSION ), 'The immutable 1.0.2 version changed.' );
database_foundation_assert( 'e49cb1fbcd726f4aae44fe4cb0ad952eabda49971420a7a2a398eee037fd6ee6' === ( Migration_Plan::PREVIOUS_2_SCHEMA_FINGERPRINT ), 'The immutable 1.0.2 fingerprint changed.' );
database_foundation_assert(
	'c55b775563273920fef127142e99e64321a07699e6a3f081579714dfb4a526c1' === ( Migration_Plan::BASELINE_SCHEMA_FINGERPRINT ),
	'The immutable 1.0.0 fingerprint changed.'
);
database_foundation_assert( '8628d701c7c97fc4625de51a1e5963b430ac1076d4ab6f338ddbacbb2f4d8f96' === ( $migrations[0]->checksum() ), 'Bootstrap migration history changed.' );
database_foundation_assert( 'de4d6707df0279376aea5aaa18fde9def85aab63757770dbb08d70ad1b7d6d64' === ( $migrations[1]->checksum() ), 'Frozen schema migration history changed.' );
database_foundation_assert( '5aadb2dd2480ad99025f32f2ad21658ff254e06cc24e379995990cf271c155b8' === ( $migrations[2]->checksum() ), 'The immutable 1.0.1 migration checksum changed.' );
database_foundation_assert( '1ac53bf10fd16145608882d8a4e958e53b11ff8e58307e5ba03207a75cab92ce' === ( $migrations[3]->checksum() ), 'The immutable 1.0.2 migration checksum changed.' );
database_foundation_assert( '07f4b7d55f2eabbb48f6c4d6997bf93a75bfeb7e69994cabf9f455c87b029827' === ( $migrations[4]->checksum() ), 'The evaluation run error message migration checksum changed.' );
database_foundation_assert( 2 === ( count( $migrations[0]->table_names() ) ), 'Bootstrap must create two owner tables.' );
database_foundation_assert( 43 === ( count( $migrations[1]->table_names() ) ), 'The target migration must create 43 tables.' );
database_foundation_assert( $migrations[0]->creates_tables() && $migrations[1]->creates_tables(), 'The 1.0.0 baseline must remain CREATE-only.' );
database_foundation_assert( $migrations[2]->alters_tables(), 'The 1.0.1 delta must be ALTER-only.' );
database_foundation_assert( $migrations[2]->table_names() === array( 'oj_ai_export_jobs' ), 'The 1.0.1 delta may only alter export jobs.' );
database_foundation_assert( $migrations[2]->target_version() === Migration_Plan::PREVIOUS_SCHEMA_VERSION, 'The 1.0.1 migration target changed.' );
database_foundation_assert( $migrations[2]->schema_fingerprint() === Migration_Plan::PREVIOUS_SCHEMA_FINGERPRINT, 'The 1.0.1 migration fingerprint changed.' );
database_foundation_assert( $migrations[3]->alters_tables(), 'The submission prompt contract delta must be ALTER-only.' );
database_foundation_assert( $migrations[3]->table_names() === array( 'oj_ai_submission_evaluations' ), 'The submission prompt contract delta may only widen the submission prompt contract.' );
database_foundation_assert( $migrations[3]->target_version() === Migration_Plan::PREVIOUS_2_SCHEMA_VERSION, 'The 1.0.2 migration target changed.' );
database_foundation_assert( $migrations[3]->schema_fingerprint() === Migration_Plan::PREVIOUS_2_SCHEMA_FINGERPRINT, 'The 1.0.2 migration fingerprint changed.' );
database_foundation_assert( $migrations[4]->alters_tables(), 'The evaluation run error message delta must be ALTER-only.' );
database_foundation_assert( $migrations[4]->table_names() === array( 'oj_ai_evaluation_runs' ), 'The evaluation run error message delta may only add the error message column.' );
database_foundation_assert( $migrations[4]->schema_fingerprint() === Migration_Plan::PREVIOUS_3_SCHEMA_FINGERPRINT, 'The immutable 1.0.3 migration must retain its fingerprint.' );
database_foundation_assert( $migrations[5]->schema_fingerprint() === $fingerprint && $migrations[5]->creates_tables() && 3 === ( count( $migrations[5]->table_names() ) ), 'Assessment migration must create only its three tables.' );
database_foundation_assert(
	$migrations[0]->table_names() === array( 'oj_ai_installations', 'oj_ai_schema_migrations' ),
	'Bootstrap table order changed.'
);

$covered = array();
foreach ( $migrations as $migration ) {
	if ( $migration->creates_tables() ) {
		$covered = array_merge( $covered, $migration->table_names() );
	}
	database_foundation_assert( 1 === ( preg_match( '/^[a-f0-9]{64}$/', $migration->checksum() ) ), 'Migration checksum must be SHA-256.' );
	database_foundation_assert( $migration->checksum() === $migration->checksum(), 'Migration checksums must be deterministic.' );
	foreach ( $migration->statements() as $statement ) {
		$expected_prefix = $migration->creates_tables() ? 'CREATE TABLE `oj_ai_' : 'ALTER TABLE `oj_ai_';
		database_foundation_assert( str_starts_with( $statement, $expected_prefix ), 'Migrations may only target literal tables with their declared operation.' );
		database_foundation_assert(
			1 !== ( preg_match( '/\b(DROP|RENAME|TRUNCATE)\b|\bDELETE\s+FROM\b/i', $statement ) ),
			'The initial migration plan may not contain destructive SQL.'
		);
	}
}
sort( $covered, SORT_STRING );
$sorted_expected = $expected_names;
sort( $sorted_expected, SORT_STRING );
database_foundation_assert( $covered === $sorted_expected, 'The migration plan must cover all 48 tables exactly once.' );

$baseline_payload = array_map(
	static fn ( $table ): array => $table->normalized_definition(),
	array_values( $plan->baseline_tables() )
);
database_foundation_assert(
	hash( 'sha256', (string) json_encode( $baseline_payload, JSON_UNESCAPED_SLASHES ) ) === Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	'The reconstructed baseline table definitions changed.'
);
$previous_payload = array_map(
	static fn ( $table ): array => $table->normalized_definition(),
	array_values( $plan->previous_tables() )
);
database_foundation_assert(
	hash( 'sha256', (string) json_encode( $previous_payload, JSON_UNESCAPED_SLASHES ) ) === Migration_Plan::PREVIOUS_SCHEMA_FINGERPRINT,
	'The reconstructed 1.0.1 table definitions changed.'
);
database_foundation_assert(
	'VARCHAR(32) NOT NULL' === ( $plan->previous_tables()['oj_ai_submission_evaluations']->columns()['prompt_contract_version'] ),
	'The 1.0.1 prompt contract width changed.'
);
database_foundation_assert(
	'VARCHAR(64) NOT NULL' === ( $tables['oj_ai_submission_evaluations']->columns()['prompt_contract_version'] ),
	'The 1.0.2 prompt contract width must hold the frozen submission prompt identifier.'
);
$export_columns = $tables['oj_ai_export_jobs']->columns();
foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $context_column ) {
	database_foundation_assert( $export_columns[ $context_column ] === ( 'context_type' === $context_column ? 'VARCHAR(16) NULL' : 'VARCHAR(191) NULL' ), 'Export context must be optional in 1.0.1.' );
}
$active_identity_indexes = array_values(
	array_filter(
		$tables['oj_ai_export_jobs']->indexes(),
		static fn ( array $index ): bool => 'uq_export_active_identity' === $index['name']
	)
);
database_foundation_assert(
	1 === ( count( $active_identity_indexes ) )
		&& true === $active_identity_indexes[0]['unique']
		&& array( 'active_identity_key' ) === $active_identity_indexes[0]['columns'],
	'Export active identity must use one nullable unique key.'
);

database_foundation_assert(
	Migration_Ledger_State::can_transition( Migration_Ledger_State::PLANNED, Migration_Ledger_State::RUNNING ),
	'Planned migrations must be startable.'
);
database_foundation_assert(
	Migration_Ledger_State::can_transition( Migration_Ledger_State::RUNNING, Migration_Ledger_State::REPAIR_REQUIRED ),
	'Partial failures must enter repair_required.'
);
database_foundation_assert(
	! Migration_Ledger_State::can_transition( Migration_Ledger_State::APPLIED, Migration_Ledger_State::RUNNING ),
	'Applied migrations must be immutable.'
);

$foundation_root = $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database'
	. DIRECTORY_SEPARATOR . 'Foundation';
$iterator        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $foundation_root ) );
$source          = '';
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$source .= (string) file_get_contents( $file->getPathname() );
	}
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE' ) as $forbidden ) {
	database_foundation_assert( ! str_contains( $source, $forbidden ), 'Foundation source contains forbidden token: ' . $forbidden );
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The CLI summary prints only the schema manifest SHA-256 fingerprint.
echo 'G1 database foundation contract passed: 48 tables, 10 owners, 6 immutable migrations, target fingerprint '
	. $fingerprint . ".\n";
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

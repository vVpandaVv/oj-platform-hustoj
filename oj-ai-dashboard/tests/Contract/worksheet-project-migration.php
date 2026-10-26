<?php
/**
 * Non-destructive worksheet workspace migration.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assessment-projects.php';

$migration_db         = new Project_Test_Database();
$migration_ids        = eval_s02_generator( 90000 );
$migration_profile    = new \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration( $json, $migration_ids );
$migration_repo       = new \OJAI\Dashboard\Assessment\Project\Infrastructure\WordPress_Project_Repository( $json, $migration_ids, $migration_profile, $migration_db );
$migration_worksheets = new \OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository( $migration_db, $json, null, null, $migration_repo );
$migration_create     = new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet( $migration_worksheets, $migration_ids, new \OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory( $migration_ids, $json ) );
$unlinked             = $migration_create->execute( new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command( 41, 'migration-source', 'absent', 'Existing worksheet', 'ko-KR', $hash( 'migration-source' ), 'migration-test', $json ) );
$snapshot             = static function () use ( $migration_db ): array {
	$rows = array();
	foreach ( ( new \OJAI\Dashboard\Database\Foundation\Schema_Catalog() )->tables() as $name => $table ) {
		if ( ! str_starts_with( $name, 'oj_ai_assessment_project' ) ) {
			$rows[ $name ] = $migration_db->get_results( 'SELECT * FROM ' . $name . ' ORDER BY id' );
		}
	}
	return $rows;
};
$before_migration     = $snapshot();
project_failure( fn() => $migration_repo->adopt_worksheet( 42, $unlinked->worksheet_id() ), 404 );
$migration_db->fail_revision = true;
project_failure( fn() => $migration_repo->adopt_worksheet( 41, $unlinked->worksheet_id() ), 503 );
project_assert( 0 === (int) $migration_db->get_var( 'SELECT COUNT(*) FROM oj_ai_assessment_projects' ), 'Failed migration left a project root' );
project_assert( $before_migration === $snapshot(), 'Failed migration rewrote existing data' );
$migration_db->fail_revision = false;
$adopted                     = $migration_repo->adopt_worksheet( 41, $unlinked->worksheet_id() );
project_assert( 'draft' === $adopted['state'] && array() === $adopted['configuration']['criteria'], 'Migration fabricated or approved missing criteria' );
project_assert( $before_migration === $snapshot(), 'Migration modified a non-project table' );
project_assert( $adopted['project_id'] === $migration_repo->adopt_worksheet( 41, $unlinked->worksheet_id() )['project_id'], 'Migration replay created another project' );
$migration_repo->migrate_unlinked();
$migration_repo->migrate_unlinked();
project_assert( 1 === (int) $migration_db->get_var( 'SELECT COUNT(*) FROM oj_ai_assessment_projects' ), 'Upgrade replay duplicated membership' );
project_assert( null === $migration_repo->for_worksheet( $unlinked->worksheet_id() ), 'Navigation migration changed historical grading criteria' );
project_assert( null === $migration_repo->for_draft_save( $unlinked->worksheet_id(), null, 41 ), 'Existing unpinned save was blocked' );
project_assert( $adopted['project_id'] === $migration_repo->for_worksheet( $unlinked->worksheet_id(), null, 41, true )['project_id'], 'Migrated worksheet missing from current navigation' );
project_failure( fn() => $migration_repo->for_draft_save( $unlinked->worksheet_id(), \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::pin( $adopted ), 41 ), 409 );
$confirmed = $migration_repo->save(
	41,
	$adopted['project_id'],
	$adopted['revision_id'],
	array(
		'title'         => $adopted['title'],
		'configuration' => $config,
		'state'         => 'confirmed',
	),
	$hash( 'migration-confirm' ),
	$hash( 'migration-confirm' )
);
project_assert( $confirmed['revision_id'] === $migration_repo->for_draft_save( $unlinked->worksheet_id(), \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::pin( $confirmed ), 41 )['revision_id'], 'Confirmed criteria cannot be explicitly applied to migrated draft' );
project_assert( $before_migration === $snapshot(), 'Criteria confirmation changed old worksheet bytes' );

$damaged = $migration_create->execute( new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command( 41, 'migration-damaged', 'absent', 'Damaged source', 'ko-KR', $hash( 'migration-damaged' ), 'migration-test', $json ) );
$migration_db->update( 'oj_ai_worksheet_revisions', array( 'teacher_document_hash' => str_repeat( '0', 64 ) ), array( 'public_id' => $damaged->revision_id() ) );
project_failure( fn() => $migration_repo->adopt_worksheet( 41, $damaged->worksheet_id() ), 503 );
project_assert( 1 === (int) $migration_db->get_var( 'SELECT COUNT(*) FROM oj_ai_assessment_project_worksheets' ), 'Corrupt source was silently migrated' );

$rubric_profile = new \OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile( $json );
$rubric         = $rubric_profile->prepare(
	'rubric',
	array(
		'subject_type' => 'student_submission',
		'total_points' => $config['total_points'],
		'value'        => array( 'criteria' => $config['criteria'] ),
	),
	true
);
$rubric_id      = $migration_ids->create()->value();
$now            = '2026-10-23 00:00:00';
project_assert(
	1 === $migration_db->insert(
		'oj_ai_rubric_revisions',
		array(
			'public_id'          => $rubric_id,
			'subject_type'       => 'student_submission',
			'revision_no'        => 1,
			'state'              => 'retired',
			'criteria_schema'    => $rubric['schema'],
			'criteria_version'   => 1,
			'criteria_json'      => $rubric['json'],
			'criteria_hash'      => $rubric['hash'],
			'total_points'       => $rubric['total_points'],
			'content_hash'       => $rubric['hash'],
			'lock_version'       => 0,
			'created_by_user_id' => 41,
			'updated_by_user_id' => 41,
			'created_at_utc'     => $now,
			'updated_at_utc'     => $now,
		)
	),
	'Recorded rubric fixture failed'
);
$make_source                = static function ( string $key, ?int $origin = null, int $owner = 41 ) use ( $migration_create, $migration_db, $json, $hash, $config, $rubric_id, $rubric ): array {
	$created                                = $migration_create->execute( new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command( $owner, $key, 'absent', $key, 'ko-KR', $hash( $key ), 'migration-test', $json ) );
	$row                                    = $migration_db->get_row( $migration_db->prepare( 'SELECT * FROM oj_ai_worksheet_revisions WHERE public_id = %s', $created->revision_id() ) );
	$document                               = json_decode( $row['teacher_document_json'], true, 128, JSON_THROW_ON_ERROR );
	$document['settings']['learning_goals'] = array_map(
		static fn( array $goal ): array => $goal + array(
			'scope'       => 'worksheet',
			'response_id' => null,
		),
		$config['learning_goals']
	);
	$document['settings']['assessment_bindings'] = array_map(
		static fn( array $criterion ): array => array(
			'criterion_id' => $criterion['criterion_id'],
			'scope'        => 'worksheet',
			'response_id'  => null,
		),
		$config['criteria']
	);
	$document['settings']['preparation']         = null === $origin
		? array(
			'state'               => 'confirmed',
			'rubric_revision_id'  => $rubric_id,
			'rubric_content_hash' => $rubric['hash'],
		)
		: \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preparation::draft();
	$encoded                                     = $json->encode( $document );
	$migration_db->update(
		'oj_ai_worksheet_revisions',
		array(
			'teacher_document_json' => $encoded,
			'teacher_document_hash' => $hash( $encoded ),
			'source_revision_id'    => $origin,
		),
		array( 'id' => $row['id'] )
	);
	return array( $created, (int) $row['id'] );
};
[ $recorded, $recorded_pk ] = $make_source( 'recorded-criteria' );
$standard                   = $config['achievement_standards'][0];
project_assert(
	1 === $migration_db->insert(
		'oj_ai_worksheet_achievement_links',
		array(
			'worksheet_revision_id'   => $recorded_pk,
			'standard_public_id'      => $standard['standard_id'],
			'standard_source_version' => '2022',
			'standard_code_snapshot'  => $standard['code'],
			'standard_label_snapshot' => $standard['text'],
			'alignment_type'          => 'primary',
			'position'                => 0,
			'content_hash'            => $hash( 'standard' ),
			'created_by_user_id'      => 41,
			'created_at_utc'          => $now,
		)
	),
	'Recorded achievement fixture failed'
);
[ $copy_one, $copy_pk ] = $make_source( 'copy-one', $recorded_pk );
[ $copy_two ]           = $make_source( 'copy-two', $copy_pk );
[ $foreign_copy ]       = $make_source( 'foreign-copy', $recorded_pk, 42 );
$migration_db->update( 'oj_ai_worksheets', array( 'state' => 'archived' ), array( 'public_id' => $recorded->worksheet_id() ) );
$before_rich_migration = $snapshot();
$recorded_project      = $migration_repo->adopt_worksheet( 41, $recorded->worksheet_id() );
$copied_project        = $migration_repo->adopt_worksheet( 41, $copy_two->worksheet_id() );
project_assert( $recorded_project['archived'] && 'draft' === $recorded_project['state'], 'Archived worksheet was restored or auto-confirmed' );
project_assert( $config === $recorded_project['configuration'], 'Recorded rubric, goals or achievement snapshots changed' );
project_assert( $config === $copied_project['configuration'], 'Copy ancestry lost criteria or existing binding identities' );
project_assert( $recorded_project['project_id'] !== $copied_project['project_id'], 'Copy did not receive an independent project' );
project_assert( array() === $migration_repo->adopt_worksheet( 42, $foreign_copy->worksheet_id() )['configuration']['criteria'], 'Migration read another owner\'s source criteria' );
project_assert( $before_rich_migration === $snapshot(), 'Rich migration modified existing source rows' );
$migration_db->update( 'oj_ai_worksheets', array( 'state' => 'active' ), array( 'public_id' => $unlinked->worksheet_id() ) );
project_failure( fn() => $migration_repo->for_draft_save( $unlinked->worksheet_id(), \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::pin( $confirmed ), 41 ), 409 );
echo "Worksheet migration passed: immutable source tables, ownership, rollback, replay, absent and retired criteria, goals, standards, copy ancestry, archived state, independent projects, separate grading contract, explicit draft adoption and integrity rejection.\n";

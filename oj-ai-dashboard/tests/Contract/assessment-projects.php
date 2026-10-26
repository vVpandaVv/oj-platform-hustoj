<?php
/**
 * Assessment projects.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Failure;
use OJAI\Dashboard\Assessment\Project\Infrastructure\WordPress_Project_Repository;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;

require __DIR__ . '/worksheet-evaluation-input-s02.php';

/**
 * Project assert.
 *
 * @param bool   $ok Ok.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function project_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}
/**
 * Project failure.
 *
 * @param callable $fn Fn.
 * @param int      $status Status.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function project_failure( callable $fn, int $status ): void {
	try {
		$fn();
	} catch ( Project_Failure $failure ) {
		project_assert( $status === $failure->status, 'Wrong failure status' );
		return;
	}
	throw new RuntimeException( 'Expected project failure: ' . $status );
}

// SQLite exercises SQL, ownership and transactions; it does not emulate MySQL row locks.
/**
 * Project assert for assessment-projects.
 */
final class Project_Test_Database {
	/**
	 * Pdo.
	 *
	 * @var PDO
	 */
	public PDO $pdo;
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail revision.
	 *
	 * @var bool
	 */
	public bool $fail_revision = false;
	/**
	 * Fail worksheet revision.
	 *
	 * @var bool
	 */
	public bool $fail_worksheet_revision = false;
	/**
	 * Initialize test database dependencies and state.
	 */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		foreach ( ( new Schema_Catalog() )->tables() as $name => $table ) {
			$columns = array();
			foreach ( $table->columns() as $column => $type ) {
				$columns[] = '`' . $column . '` ' . ( 'id' === ( $column ) ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : str_replace( array( ' UNSIGNED', ' AUTO_INCREMENT' ), array( '', '' ), $type ) );
			}
			$this->pdo->exec( 'CREATE TABLE `' . $name . '` (' . implode( ',', $columns ) . ')' );
		}
		$this->pdo->exec( 'CREATE UNIQUE INDEX project_create ON oj_ai_assessment_projects(creation_key)' );
		$this->pdo->exec( 'CREATE UNIQUE INDEX project_revision_number ON oj_ai_assessment_project_revisions(project_id,revision_no)' );
		$this->pdo->exec( 'CREATE UNIQUE INDEX project_command ON oj_ai_assessment_project_revisions(command_key)' );
		$this->pdo->exec( 'CREATE UNIQUE INDEX project_membership ON oj_ai_assessment_project_worksheets(worksheet_id)' );
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $match ) use ( $args, &$index ) {
				$v = $args[ $index++ ];
				return '%d' === ( $match[0] ) ? (string) (int) $v : $this->pdo->quote( (string) $v );
			},
			$sql
		);
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		$this->last_error = '';
		try {
			return $this->pdo->exec( 'START TRANSACTION' === ( $sql ) ? 'BEGIN' : $sql );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array|null
	 */
	public function get_results( string $sql, mixed $mode = null ): ?array {
		$this->last_error = '';
		try {
			return $this->pdo->query( str_replace( ' FOR UPDATE', '', $sql ) )->fetchAll( PDO::FETCH_ASSOC );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array|null
	 */
	public function get_row( string $sql, mixed $mode = null ): ?array {
		return $this->get_results( $sql, $mode )[0] ?? null;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		$row = $this->get_row( $sql );
		return $row ? array_values( $row )[0] : null;
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @param mixed  $formats Formats.
	 * @return int|false
	 */
	public function insert( string $table, array $data, mixed $formats = null ): int|false {
		$this->last_error = '';
		if ( ( $this->fail_revision && 'oj_ai_assessment_project_revisions' === ( $table ) ) || ( $this->fail_worksheet_revision && 'oj_ai_worksheet_revisions' === ( $table ) ) ) {
			$this->last_error = 'Injected failure';
			return false;
		}
		try {
			$stmt = $this->pdo->prepare( 'INSERT INTO `' . $table . '` (`' . implode( '`,`', array_keys( $data ) ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
			$stmt->execute( array_values( $data ) );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return $stmt->rowCount();
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}
	/**
	 * Update test database.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @param array  $where Where.
	 * @param mixed  $formats Formats.
	 * @param mixed  $where_formats Where formats.
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, mixed $formats = null, mixed $where_formats = null ): int|false {
		$this->last_error = '';
		try {
			$stmt = $this->pdo->prepare( 'UPDATE `' . $table . '` SET ' . implode( ',', array_map( fn( $k )=>'`' . $k . '`=?', array_keys( $data ) ) ) . ' WHERE ' . implode( ' AND ', array_map( fn( $k )=>'`' . $k . '`=?', array_keys( $where ) ) ) );
			$stmt->execute( array( ...array_values( $data ), ...array_values( $where ) ) );
			return $stmt->rowCount();
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}
}

project_assert( in_array( 'sqlite', PDO::getAvailableDrivers(), true ), 'Run PHP with -d extension=pdo_sqlite' );
$db       = new Project_Test_Database();
$ids      = eval_s02_generator( 20000 );
$json     = new Canonical_Json();
$profile  = new Project_Configuration( $json, $ids );
$repo     = new WordPress_Project_Repository( $json, $ids, $profile, $db );
$hash     = static fn( string $v ): string=>hash( 'sha256', $v );
$a        = $repo->create(
	41,
	array(
		'title'              => 'Evaluation A',
		'copy_from'          => null,
		'source_revision_id' => null,
	),
	$hash( 'create-a' ),
	$hash( 'a' )
);
$replayed = $repo->create(
	41,
	array(
		'title'              => 'Evaluation A',
		'copy_from'          => null,
		'source_revision_id' => null,
	),
	$hash( 'create-a' ),
	$hash( 'a' )
);
project_assert( $a['project_id'] === $replayed['project_id'], 'Create replay duplicated assessment' );
project_failure( fn()=>$repo->read( 42, $a['project_id'] ), 404 );
project_failure( fn()=>$repo->listing( 0 ), 401 );
project_failure(
	fn()=>$repo->create(
		41,
		array(
			'title'              => 'Changed',
			'copy_from'          => null,
			'source_revision_id' => null,
		),
		$hash( 'create-a' ),
		$hash( 'changed' )
	),
	409
);
$config = $profile->normalize(
	array(
		'learning_goals'        => array(
			array(
				'goal_id' => $ids->create()->value(),
				'text'    => 'Use evidence to explain a claim.',
			),
		),
		'achievement_standards' => array(
			array(
				'standard_id' => $ids->create()->value(),
				'code'        => 'EVIDENCE',
				'text'        => 'Compare sources, then justify a conclusion.',
			),
		),
		'criteria'              => $configuration['grading_rubric']['criteria'],
		'total_points'          => $configuration['grading_rubric']['total_points'],
	),
	true
);
$save   = array(
	'title'         => 'Evaluation A',
	'configuration' => $config,
	'state'         => 'confirmed',
);
$old    = $a;
$a      = $repo->save( 41, $a['project_id'], $a['revision_id'], $save, $hash( 'confirm-a' ), $hash( 'confirm' ) );
project_assert( $repo->save( 41, $a['project_id'], $old['revision_id'], $save, $hash( 'confirm-a' ), $hash( 'confirm' ) )['revision_id'] === $a['revision_id'], 'Save replay must precede stale revision check' );
project_failure( fn()=>$repo->save( 41, $a['project_id'], $old['revision_id'], $save, $hash( 'stale' ), $hash( 'stale' ) ), 409 );
$b = $repo->create(
	41,
	array(
		'title'              => 'Evaluation B',
		'copy_from'          => $a['project_id'],
		'source_revision_id' => $a['revision_id'],
	),
	$hash( 'copy-b' ),
	$hash( 'b' )
);
project_assert( 'draft' === ( $b['state'] ) && $b['worksheets'] === array(), 'Copied criteria require confirmation without linking original worksheets' );
project_assert( $a['configuration']['criteria'][0]['criterion_id'] !== $b['configuration']['criteria'][0]['criterion_id'], 'Copied criterion IDs must be independent' );
$b_config                              = $b['configuration'];
$b_config['learning_goals'][0]['text'] = 'Different goal.';
$b                                     = $repo->save(
	41,
	$b['project_id'],
	$b['revision_id'],
	array(
		'title'         => 'Evaluation B',
		'configuration' => $b_config,
		'state'         => 'confirmed',
	),
	$hash( 'confirm-b' ),
	$hash( 'confirm-b' )
);
project_assert( $repo->read( 41, $a['project_id'] )['configuration'] === $config, 'Editing a copy mutated its source' );

$worksheets = new \OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository( $db, $json, null, null, $repo );
$create     = new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet( $worksheets, $ids, new Initial_Document_Factory( $ids, $json ) );
$command    = new \OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command( 41, 'project-test-create', 'absent', 'Worksheet A', 'ko-KR', $hash( 'worksheet-a' ), 'project-test', $json );
$worksheet  = $create->execute( $command, $a );
project_assert( $create->execute( $command, $a )->replayed(), 'Worksheet creation must replay atomically' );
project_assert( 1 === ( count( $repo->read( 41, $a['project_id'] )['worksheets'] ) ), 'Worksheet membership missing' );
project_assert( $repo->for_worksheet( $worksheet->worksheet_id(), Project_Configuration::pin( $a ), 41 )['revision_id'] === $a['revision_id'], 'Worksheet criteria snapshot missing' );
project_failure( fn()=>$repo->for_worksheet( $worksheet->worksheet_id(), Project_Configuration::pin( $b ), 41 ), 409 );
$new_config                              = $a['configuration'];
$new_config['learning_goals'][0]['text'] = 'A later goal.';
$latest                                  = $repo->save(
	41,
	$a['project_id'],
	$a['revision_id'],
	array(
		'title'         => 'Evaluation A',
		'configuration' => $new_config,
		'state'         => 'confirmed',
	),
	$hash( 'later-a' ),
	$hash( 'later-a' )
);
project_assert( $repo->for_worksheet( $worksheet->worksheet_id() )['configuration'] === $config, 'New criteria retroactively changed a worksheet' );
$db->query( 'START TRANSACTION' );
project_failure( fn()=>$repo->attach_created( 999, 41, Project_Configuration::pin( $a ) ), 409 );
$db->query( 'ROLLBACK' );
$before            = (int) $db->get_var( 'SELECT COUNT(*) FROM oj_ai_assessment_projects' );
$db->fail_revision = true;
project_failure(
	fn()=>$repo->create(
		41,
		array(
			'title'              => 'Rollback',
			'copy_from'          => null,
			'source_revision_id' => null,
		),
		$hash( 'rollback' ),
		$hash( 'rollback' )
	),
	503
);
$db->fail_revision = false;
project_assert( (int) $db->get_var( 'SELECT COUNT(*) FROM oj_ai_assessment_projects' ) === $before, 'Failed revision left a partial root' );
$all = $repo->listing( 41 );
project_assert( 2 === ( count( $all['projects'] ) ), 'Owner list has wrong projects' );

$seeded                 = Project_Configuration::seed( $source->document(), $a );
$with_previous_criteria = $seeded;
$with_previous_criteria['children'][0]['grading']['rubric_criterion_ids'] = array( $ids->create()->value() );
$reseeded = Project_Configuration::seed( $with_previous_criteria, $a );
project_assert( $reseeded['children'][0]['grading']['rubric_criterion_ids'] === array(), 'Copying worksheet content retained unrelated assessment criteria' );
$canonical_document        = ( new Worksheet_Document_Canonicalizer( $json, $ids ) )->canonicalize( $seeded );
$projected                 = ( new Worksheet_Revision_Projector( $json, $ids ) )->project( $canonical_document, array(), array() );
$quality                   = $configuration;
$quality['grading_rubric'] = array( 'assessment_enabled' => true ) + Project_Configuration::rubric( $a );
$quality_input             = $builder->build( $worksheet_id, $revision_id, $canonical_document, $projected, $asset_snapshots, $quality, $a['configuration']['achievement_standards'] )->input();
project_assert( str_contains( $json->encode( $quality_input ), 'Compare sources, then justify a conclusion.' ), 'AI review omitted assessment achievement standard text' );
project_assert( str_contains( $json->encode( $quality_input ), 'Use evidence to explain a claim.' ), 'AI review omitted assessment learning goal' );
$copy = ( new Worksheet_Document_Id_Rewriter() )->rewrite( $seeded, array(), array_combine( ( new Worksheet_Document_Id_Rewriter() )->asset_ids( $seeded ), ( new Worksheet_Document_Id_Rewriter() )->asset_ids( $seeded ) ), $hash( 'rewrite' ) )['teacher_document'];
project_assert( ! isset( $copy['settings']['assessment_project'] ) && 'draft' === ( $copy['settings']['preparation']['state'] ), 'Unscoped copy inherited the original assessment' );
echo "Assessment projects passed: SQL ownership, creation/save replay, CAS, rollback, independent criteria copy, atomic worksheet creation, pinned versions, self-review goals/standards, copy detachment.\n";

$publication_config = array(
	'guard'       => array(),
	'policy'      => array_intersect_key( Project_Configuration::policy( $a, $quality['review_rule']['revision_id'] ), array_flip( array( 'revision_id', 'content_hash', 'subject_type' ) ) ),
	'rubric'      => Project_Configuration::rubric( $a ),
	'review_rule' => array_intersect_key( $quality['review_rule'], array_flip( array( 'revision_id', 'content_hash', 'review_required', 'explicit_release_required' ) ) ),
);
$publisher          = new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector( $json, new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector( $json ), new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector( $json ) );
$published          = $publisher->project( $canonical_document, $publication_config );
project_assert( $published->profile( 'grading_schema' )['payload']['rubric']['revision_id'] === $a['revision_id'], 'Published criteria must use project revision' );
project_assert( ! str_contains( $json->encode( $published->profile( 'student_document' ) ), 'assessment_project' ), 'Private assessment membership leaked into student document' );
echo "Assessment publication passed: pinned rubric, selected bindings, private project context excluded.\n";

( static function (): void {
	require __DIR__ . '/submission-assessment-input-g4-imp-01-s02.php';
	$project                                 = array(
		'revision_id'   => g4_s02_id( 99001 ),
		'configuration' => array(
			'criteria'     => $rubric_value['criteria'],
			'total_points' => '10',
		),
	);
	$configuration['policy']                 = Project_Configuration::policy( $project, $review_id );
	$configuration['rubric']['revision_id']  = $project['revision_id'];
	$configuration['rubric']['content_hash'] = Project_Configuration::rubric( $project )['content_hash'];
	$publication['grading_schema']['payload']['policy']                = array_intersect_key( $configuration['policy'], array_flip( array( 'revision_id', 'content_hash' ) ) );
	$publication['grading_schema']['payload']['rubric']['revision_id'] = $project['revision_id'];
	g4_s02_rehash_publication( $canonical, $publication );
	g4_s02_rebind_official( $canonical, $integrity, $submission_id, $publication['publication_hash'], $official_revision );
	$result = $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, $artifacts );
	project_assert( $result->configuration_snapshot()['rubric']['revision_id'] === $project['revision_id'], 'Student score input lost the assessment rubric' );
	echo "Assessment student scoring passed: immutable project policy and rubric resolve against official published submission.\n";
} )();

$long_config                          = $config;
$long_config['criteria'][0]['levels'] = array();
foreach ( array( 61, 120, 255, 256, 499, 500, 500 ) as $position => $length ) {
	$long_config['criteria'][0]['levels'][] = array(
		'label'     => 6 === $position ? str_repeat( '가', 249 ) . "\n" . str_repeat( '나', 250 ) : str_repeat( '가', $length ),
		'level_key' => $ids->create()->value(),
		'points'    => (string) ( 20 - 2 * $position ),
		'position'  => $position,
	);
}
$long_config['criteria'][0]['maximum_points'] = '20';
$long_config['total_points']                  = '23';
$long_saved                                   = $repo->save(
	41,
	$a['project_id'],
	$latest['revision_id'],
	array(
		'title'         => $a['title'],
		'state'         => 'confirmed',
		'configuration' => $long_config,
	),
	$hash( 'long-performance-levels' ),
	$hash( 'long-performance-levels' )
);
project_assert( $long_saved['configuration'] === $long_config, 'Save truncated long performance descriptions or changed seven score levels.' );
project_assert( $repo->read( 41, $a['project_id'] )['configuration'] === $long_config, 'Reload changed long performance descriptions.' );
$long_copy = $profile->copy( $long_config );
project_assert( array_column( $long_copy['criteria'][0]['levels'], 'label' ) === array_column( $long_config['criteria'][0]['levels'], 'label' ), 'Copy changed performance descriptions.' );
foreach ( array( str_repeat( '가', 501 ), str_repeat( 'a', 501 ), '', "invalid\x00text" ) as $invalid_label ) {
	$invalid_config                                      = $long_config;
	$invalid_config['criteria'][0]['levels'][0]['label'] = $invalid_label;
	try {
		$profile->normalize( $invalid_config, true );
		throw new RuntimeException( 'Invalid performance description accepted.' );
	} catch ( InvalidArgumentException $expected ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		// Keep the existing length and control-character safety checks.
	}
}
echo "Performance descriptions passed: seven levels, 61/120/255/256/499/500 characters, multiline save/read/copy, invalid text rejected.\n";

$refresh_config                               = $long_config;
$refresh_config['criteria'][0]['label']       = 'Updated assessment element';
$refresh_config['criteria'][0]['description'] = 'Updated assessment method';
$refresh_config['learning_goals'][0]['text']  = 'Updated learning goal';
$refresh_target                               = $repo->save(
	41,
	$a['project_id'],
	$long_saved['revision_id'],
	array(
		'title'         => $a['title'],
		'state'         => 'confirmed',
		'configuration' => $refresh_config,
	),
	$hash( 'refresh-target' ),
	$hash( 'refresh-target' )
);
$original_revision                            = $worksheets->find_owned_revision( 41, $worksheet->revision_id() );
$refresh_document                             = Project_Configuration::seed( $original_revision['teacher_document'], $refresh_target );
$standards_port                               = new class() implements \OJAI\Dashboard\Worksheet\Authoring\Port\Achievement_Standard_Snapshot_Port_Interface {
	/**
	 * Find snapshots.
	 *
	 * @param array $standard_public_ids Standard public ids.
	 * @return array
	 */
	public function find_snapshots( array $standard_public_ids ): array {
		return array();
	}
};
$draft_saver                                  = new \OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft( $worksheets, $standards_port, new Worksheet_Document_Canonicalizer( $json, $ids ), new Worksheet_Revision_Projector( $json, $ids ), $ids, $json, null, $repo );
$refresh_command                              = new \OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command( 41, $worksheet->worksheet_id(), 'refresh-criteria-test', 'draft', $worksheet->revision_id(), $refresh_document, array(), array(), $hash( 'refresh-criteria-test' ), 'project-test', $json );
project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $b ), 41 ), 409 );
project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $refresh_target ), 42 ), 409 );
project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $latest ), 41 ), 409 );
$db->fail_worksheet_revision = true;
try {
	$draft_saver->execute( $refresh_command );
	throw new RuntimeException( 'Injected draft failure accepted' ); } catch ( \OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure $expected ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	$db->fail_worksheet_revision = false;
	project_assert( $repo->for_worksheet( $worksheet->worksheet_id() )['revision_id'] === $a['revision_id'], 'Failed draft write changed membership outside its transaction' );
	$refreshed = $draft_saver->execute( $refresh_command );
	project_assert( $draft_saver->execute( $refresh_command )->replayed(), 'Criteria refresh retry must replay the same revision' );
	project_assert( $repo->for_worksheet( $worksheet->worksheet_id() )['configuration'] === $refresh_config, 'Latest criteria were not attached to the saved worksheet' );
	project_assert( $json->encode( $worksheets->find_owned_revision( 41, $refreshed->revision_id() )['teacher_document']['settings']['assessment_project'] ) === $json->encode( Project_Configuration::pin( $refresh_target ) ), 'Saved document and membership differ' );
	project_assert( $worksheets->find_owned_revision( 41, $worksheet->revision_id() )['teacher_document'] === $original_revision['teacher_document'], 'Refresh mutated the historical document' );
	$draft_target = $repo->save(
		41,
		$a['project_id'],
		$refresh_target['revision_id'],
		array(
			'title'         => $a['title'],
			'state'         => 'draft',
			'configuration' => $refresh_config,
		),
		$hash( 'unconfirmed-target' ),
		$hash( 'unconfirmed-target' )
	);
	project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $draft_target ), 41 ), 409 );
	$next_target  = $repo->save(
		41,
		$a['project_id'],
		$draft_target['revision_id'],
		array(
			'title'         => $a['title'],
			'state'         => 'confirmed',
			'configuration' => $refresh_config,
		),
		$hash( 'next-confirmed-target' ),
		$hash( 'next-confirmed-target' )
	);
	$worksheet_pk = (int) $db->get_var( $db->prepare( 'SELECT id FROM oj_ai_worksheets WHERE public_id = %s', $worksheet->worksheet_id() ) );
	$db->update( 'oj_ai_worksheets', array( 'state' => 'active' ), array( 'id' => $worksheet_pk ) );
	project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $next_target ), 41 ), 409 );
	$db->update( 'oj_ai_worksheets', array( 'state' => 'draft' ), array( 'id' => $worksheet_pk ) );
	// This fixture checks the existence guard, not publication payload validation.
	$publication_guard = array();
	foreach ( ( new Schema_Catalog() )->tables()['oj_ai_worksheet_publications']->columns() as $column => $type ) {
		if ( 'id' !== $column && str_contains( $type, 'NOT NULL' ) ) {
			$publication_guard[ $column ] = str_contains( $type, 'INT' ) ? 1 : 'guard-fixture';
		}
	}
	$publication_guard['public_id']    = $ids->create()->value();
	$publication_guard['worksheet_id'] = $worksheet_pk;
	project_assert( false !== $db->insert( 'oj_ai_worksheet_publications', $publication_guard ), 'Publication guard fixture failed' );
	project_failure( fn() => $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $next_target ), 41 ), 409 );
	project_assert( $repo->for_draft_save( $worksheet->worksheet_id(), Project_Configuration::pin( $refresh_target ), 41 )['revision_id'] === $refresh_target['revision_id'], 'Existing published criteria must remain usable' );
	echo "Criteria refresh passed: atomic revision and membership, rollback, replay, ownership, same-assessment/latest/confirmed guards, historical document and publication protection.\n";

	// Archival affects the owner's list only; immutable criteria and child records survive.
	$before_archive                 = $repo->read( 41, $a['project_id'] );
	$linked_before                  = $repo->for_worksheet( $worksheet->worksheet_id() );
	$publication_guard['public_id'] = (string) $publication_guard['public_id'];
	$published_before               = $repo->for_publication( $publication_guard['public_id'], $linked_before['revision_id'] );
	$archive_version                = $before_archive['lock_version'];
	project_failure( fn() => $repo->set_archived( 42, $a['project_id'], $archive_version, true ), 404 );
	project_failure( fn() => $repo->set_archived( 0, $a['project_id'], $archive_version, true ), 401 );
	project_failure( fn() => $repo->set_archived( 41, $a['project_id'], $archive_version - 1, true ), 409 );
	$archived = $repo->set_archived( 41, $a['project_id'], $archive_version, true );
	project_assert( true === $archived['archived'] && $archived['lock_version'] === $archive_version + 1, 'Archive state or version missing' );
	foreach ( array( 'state', 'configuration', 'revision_id', 'content_hash', 'history', 'worksheets' ) as $field ) {
		project_assert( $archived[ $field ] === $before_archive[ $field ], 'Archive changed criteria or linked records: ' . $field );
	}
	project_assert( $repo->set_archived( 41, $a['project_id'], $archive_version, true ) === $archived, 'Lost archive response must replay without another update' );
	project_assert( array_column( $repo->listing( 41 )['projects'], 'project_id' ) === array( $b['project_id'] ), 'Default active list contains archived project' );
	project_assert( array_column( $repo->listing( 41, 0, true )['projects'], 'project_id' ) === array( $a['project_id'] ), 'Archive list missing project' );
	project_assert( $repo->listing( 42, 0, true )['projects'] === array(), 'Other owner can list archive' );
	foreach ( array( 'configuration', 'revision_id', 'state', 'content_hash' ) as $field ) {
		project_assert( $repo->for_worksheet( $worksheet->worksheet_id() )[ $field ] === $linked_before[ $field ], 'Archive changed worksheet criteria' );
		project_assert( $repo->for_publication( $publication_guard['public_id'], $linked_before['revision_id'] )[ $field ] === $published_before[ $field ], 'Archive changed published criteria' );
	}
	$archived_edit = $repo->save(
		41,
		$a['project_id'],
		$archived['revision_id'],
		array(
			'title'         => 'Edited in archive',
			'configuration' => $archived['configuration'],
			'state'         => 'draft',
		),
		$hash( 'archive-save' ),
		$hash( 'archive-save' )
	);
	project_assert( $archived_edit['archived'] && 'draft' === $archived_edit['state'], 'Saving silently restored an archived project' );
	project_failure( fn() => $repo->set_archived( 41, $a['project_id'], $archived['lock_version'], false ), 409 );
	$restored = $repo->set_archived( 41, $a['project_id'], $archived_edit['lock_version'], false );
	project_assert( ! $restored['archived'] && 'draft' === $restored['state'], 'Restore lost latest criteria state' );
	project_assert( $restored['history'] === $archived_edit['history'], 'Restore added a criteria revision' );
	project_assert( 2 === count( $repo->listing( 41 )['projects'] ) && array() === $repo->listing( 41, 0, true )['projects'], 'Restore did not return to active list' );
	project_assert( $repo->set_archived( 41, $a['project_id'], $archived_edit['lock_version'], false ) === $restored, 'Restore retry is not idempotent' );
	project_failure( fn() => $repo->set_archived( 41, $a['project_id'], $archive_version, true ), 409 );

	// Mixed active/archived pagination must filter before the page limit.
	for ( $i = 0; $i < 52; ++$i ) {
		$page_project = $repo->create(
			51,
			array(
				'title'              => 'Page ' . $i,
				'copy_from'          => null,
				'source_revision_id' => null,
			),
			$hash( 'page-' . $i ),
			$hash( 'page-' . $i )
		);
		$repo->set_archived( 51, $page_project['project_id'], $page_project['lock_version'], true );
	}
	$first_page  = $repo->listing( 51, 0, true );
	$second_page = $repo->listing( 51, $first_page['next_cursor'], true );
	project_assert( 50 === count( $first_page['projects'] ) && 2 === count( $second_page['projects'] ) && null === $second_page['next_cursor'], 'Archive pagination omitted records' );
	project_assert( array() === $repo->listing( 51 )['projects'], 'Active pagination leaks archived records' );
	echo "Project archive passed: ownership, active/archive SQL filtering, pagination, retries, stale-version rejection, archive-preserving save, restore, immutable worksheet/publication criteria.\n";

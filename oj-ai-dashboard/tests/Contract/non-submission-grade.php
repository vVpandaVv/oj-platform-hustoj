<?php
/**
 * Teacher target grades: real SQL, state, bounds, replay, preservation and rollback.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( ABSPATH . 'src' ) )->register();

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Submission\Assessment\Application\Manage_Non_Submission_Grade;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Non_Submission_Grade_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * Assert an independent grading invariant.
 *
 * @param bool   $condition Test condition.
 * @param string $message Failure description.
 * @return void
 * @throws RuntimeException Fails the contract.
 */
function grade_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}

/** SQLite adapter preserving schema nullability and transaction rollback. */
final class Grade_Test_Database {
	/**
	 * In-memory connection.
	 *
	 * @var PDO
	 */
	public PDO $pdo;
	/**
	 * Last storage error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Injected failed table.
	 *
	 * @var string
	 */
	public string $fail_table = '';
	/**
	 * Recorded write targets.
	 *
	 * @var array
	 */
	public array $writes = array();
	/** Create current owned schema tables. */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		foreach ( ( new Schema_Catalog() )->tables() as $name => $table ) {
			$columns = array();
			foreach ( $table->columns() as $column => $type ) {
				$columns[] = '`' . $column . '` ' . ( 'id' === $column ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : str_replace( array( ' UNSIGNED', ' AUTO_INCREMENT' ), '', $type ) );
			}
			$this->pdo->exec( 'CREATE TABLE `' . $name . '` (' . implode( ',', $columns ) . ')' );
		}
		$this->pdo->exec( 'CREATE UNIQUE INDEX grade_replay ON oj_ai_outbox_events(idempotency_key)' );
	}
	/**
	 * Bind SQL parameters.
	 *
	 * @param string $sql Template.
	 * @param mixed  ...$values Parameters.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( $values, &$i ) {
				$v = $values[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v );
			},
			$sql
		);
	}
	/**
	 * Read associative rows.
	 *
	 * @param string $sql Query.
	 * @param mixed  $mode WordPress output mode.
	 * @return array
	 */
	public function get_results( string $sql, mixed $mode = null ): array {
		return $this->pdo->query( str_replace( ' FOR UPDATE', '', $sql ) )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Execute transaction SQL.
	 *
	 * @param string $sql Query.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		return $this->pdo->exec( 'START TRANSACTION' === $sql ? 'BEGIN' : $sql );
	}
	/**
	 * Insert one row with failure injection.
	 *
	 * @param string $table Owned table.
	 * @param array  $data Row.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		$this->writes[] = $table;
		if ( $table === $this->fail_table ) {
			return false; }
		$stmt = $this->pdo->prepare( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
		$stmt->execute( array_values( $data ) );
		return $stmt->rowCount();
	}
	/**
	 * Match WordPress null-aware compare-and-swap updates.
	 *
	 * @param string $table Owned table.
	 * @param array  $data Changes.
	 * @param array  $where Exact expected values.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		$this->writes[] = $table;
		$clauses        = array();
		$values         = array_values( $data );
		foreach ( $where as $key => $value ) {
			$clauses[] = $key . ( null === $value ? ' IS NULL' : ' = ?' );
			if ( null !== $value ) {
				$values[] = $value; }
		}
		$stmt = $this->pdo->prepare( 'UPDATE ' . $table . ' SET ' . implode( ',', array_map( fn( $key ) => $key . '=?', array_keys( $data ) ) ) . ' WHERE ' . implode( ' AND ', $clauses ) );
		$stmt->execute( $values );
		return $stmt->rowCount();
	}
	/**
	 * Seed only fixture data while satisfying unrelated mandatory columns.
	 *
	 * @param string $table Owned table.
	 * @param array  $overrides Fixture values.
	 * @return void
	 */
	public function seed( string $table, array $overrides ): void {
		$data = array();
		foreach ( ( new Schema_Catalog() )->tables()[ $table ]->columns() as $name => $type ) {
			if ( 'id' !== $name && str_contains( $type, 'NOT NULL' ) && ! str_contains( $type, 'DEFAULT' ) ) {
				$data[ $name ] = str_contains( $type, 'INT' ) ? 0 : '';
			}
		}
		$this->insert( $table, array_replace( $data, $overrides ) );
	}
}

$db          = new Grade_Test_Database();
$canonical   = new Canonical_Json();
$hash        = str_repeat( 'a', 64 );
$id          = fn( int $i ): string => str_pad( (string) $i, 26, '0', STR_PAD_LEFT );
$scope       = array(
	'assignment_id'    => $id( 1 ),
	'context_type'     => 'group',
	'context_id'       => 'group:10',
	'context_revision' => 'revision-1',
);
$binding_key = str_repeat( 'b', 64 );
$payload     = array(
	'rubric'   => array(
		'total_points'     => '20.0000',
		'exception_scores' => array(
			'not_submitted' => '0',
			'long_absence'  => '8',
		),
	),
	'bindings' => array(
		array(
			'binding_key'    => $binding_key,
			'label'          => '근거 설명',
			'maximum_points' => '20',
			'levels'         => array(
				array(
					'level_key' => $id( 40 ),
					'label'     => '충분함',
					'points'    => '20',
				),
				array(
					'level_key' => $id( 41 ),
					'label'     => '부분 충족',
					'points'    => '8.5',
				),
			),
		),
	),
);
$profile     = array(
	'schema_id'    => 'worksheet_grading',
	'payload'      => $payload,
	'payload_hash' => hash( 'sha256', $canonical->encode( $payload ) ),
);
$json        = $canonical->encode( $profile );
$db->seed(
	'oj_ai_worksheet_publications',
	array(
		'public_id'              => $id( 2 ),
		'content_hash'           => $hash,
		'publication_hash'       => $hash,
		'state'                  => 'active',
		'grading_schema_schema'  => 'worksheet_grading',
		'grading_schema_version' => 1,
		'grading_schema_json'    => $json,
		'grading_schema_hash'    => hash( 'sha256', $json ),
	)
);
$db->seed(
	'oj_ai_assignments',
	array(
		'id'                              => 1,
		'public_id'                       => $id( 1 ),
		'context_type'                    => 'group',
		'context_id'                      => 'group:10',
		'context_revision'                => 'revision-1',
		'worksheet_publication_public_id' => $id( 2 ),
		'publication_hash'                => $hash,
		'state'                           => 'open',
	)
);
foreach ( array( 11, 12 ) as $learner ) {
	$db->seed(
		'oj_ai_assignment_targets',
		array(
			'id'                    => $learner,
			'public_id'             => $id( $learner ),
			'assignment_id'         => 1,
			'learner_user_id'       => $learner,
			'state'                 => 'active',
			'content_hash'          => 'preserve-target',
			'display_snapshot_json' => 'preserve-display',
		)
	);
}
$db->seed(
	'oj_ai_submissions',
	array(
		'public_id'                  => $id( 30 ),
		'assignment_id'              => 1,
		'learner_user_id'            => 12,
		'state'                      => 'draft',
		'current_revision_public_id' => $id( 31 ),
	)
);
$db->seed(
	'oj_ai_submission_revisions',
	array(
		'public_id'     => $id( 31 ),
		'response_json' => '{"answers":"preserve draft"}',
	)
);
$before_submissions = $db->get_results( 'SELECT * FROM oj_ai_submissions' );
$before_answers     = $db->get_results( 'SELECT * FROM oj_ai_submission_revisions' );
$store              = new WordPress_Non_Submission_Grade_Repository( $canonical, new Public_Id_Generator(), $db );
$service            = new Manage_Non_Submission_Grade( $store, $canonical );
$input              = array(
	'target_id'        => $id( 11 ),
	'action'           => 'confirm',
	'category'         => 'not_submitted',
	'score'            => '0',
	'memo'             => '미제출 기본 평가',
	'expected_hash'    => null,
	'publication_hash' => $hash,
);
$run                = fn( array $body, string $key, array $context = array() ) => $service->execute( 7, array() === $context ? $scope : $context, $body, $key, 'grade-contract' );
$reject             = function ( array $body, string $key, array $context = array() ) use ( $run, $db ): void {
	$snapshot = $db->get_results( 'SELECT * FROM oj_ai_assignment_targets' );
	$events   = $db->get_results( 'SELECT * FROM oj_ai_audit_events' );
	$failed   = false;
	try {
		$run( $body, $key, $context );
	} catch ( Throwable ) {
		$failed = true; }
	grade_assert( $failed, 'Invalid grade command succeeded: ' . $key );
	grade_assert( $snapshot === $db->get_results( 'SELECT * FROM oj_ai_assignment_targets' ) && $events === $db->get_results( 'SELECT * FROM oj_ai_audit_events' ), 'Rejected command changed grades or audit.' );
};

foreach ( array( '-1', '20.0001', 'NaN', '1e1', '0.00001', '' ) as $score ) {
	$reject( array_replace( $input, array( 'score' => $score ) ), 'bounds-' . $score ); }
$reject( $input, 'cross-context', array_replace( $scope, array( 'context_id' => 'group:999' ) ) );
$reject( array_replace( $input, array( 'target_id' => $id( 99 ) ) ), 'cross-target' );
$rubric_items = array(
	array(
		'binding_key' => $binding_key,
		'level_key'   => $id( 41 ),
		'score'       => '8.5',
	),
);
$reject( array_replace( $input, array( 'items' => $rubric_items ) ), 'mixed-special-rubric' );
$reject(
	array_replace(
		$input,
		array(
			'category' => 'rubric',
			'items'    => array(),
			'score'    => '0',
		)
	),
	'missing-rubric'
);
$reject(
	array_replace(
		$input,
		array(
			'category' => 'rubric',
			'items'    => $rubric_items,
			'score'    => '20',
		)
	),
	'altered-total'
);
$bad_items             = $rubric_items;
$bad_items[0]['score'] = '2';
$reject(
	array_replace(
		$input,
		array(
			'category' => 'rubric',
			'items'    => $bad_items,
			'score'    => '2',
		)
	),
	'altered-level-score'
);
$bad_items[0]['level_key'] = $id( 99 );
$reject(
	array_replace(
		$input,
		array(
			'category' => 'rubric',
			'items'    => $bad_items,
			'score'    => '2',
		)
	),
	'unknown-level'
);
$reject(
	array_replace(
		$input,
		array(
			'category' => 'rubric',
			'items'    => array_merge( $rubric_items, $rubric_items ),
			'score'    => '17',
		)
	),
	'duplicate-binding'
);
$saved = $run( $input, 'zero' );
grade_assert( 'confirmed' === $saved['grade']['status'] && '0' === $saved['grade']['score'], 'Zero-point grade not confirmed.' );
grade_assert( true === $run( $input, 'zero' )['replayed'] && 1 === count( $db->get_results( 'SELECT * FROM oj_ai_audit_events' ) ), 'Replay duplicated grade/audit.' );
$reject( array_replace( $input, array( 'score' => '1' ) ), 'zero' );
$reject(
	array_replace(
		$input,
		array(
			'expected_hash' => $saved['grade']['hash'],
			'score'         => '5',
		)
	),
	'edit-approved'
);
$withdraw = $run(
	array_replace(
		$input,
		array(
			'action'        => 'withdraw',
			'expected_hash' => $saved['grade']['hash'],
		)
	),
	'withdraw'
);
grade_assert( 'withdrawn' === $withdraw['grade']['status'], 'Withdrawal failed.' );
$again = $run(
	array_replace(
		$input,
		array(
			'expected_hash' => $withdraw['grade']['hash'],
			'score'         => '8.50',
			'category'      => 'rubric',
			'items'         => $rubric_items,
		)
	),
	'reconfirm'
);
grade_assert( '8.50' === $again['grade']['score'] && 3 === $again['grade']['revision'], 'Correction lost grade history.' );
$draft_input = array_replace(
	$input,
	array(
		'target_id' => $id( 12 ),
		'score'     => '8',
		'category'  => 'long_absence',
	)
);
foreach ( array( 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $table ) {
	$db->fail_table = $table;
	$reject( $draft_input, 'rollback-' . $table );
	$db->fail_table = '';
}
$run( $draft_input, 'draft-grade' );
grade_assert( $before_submissions === $db->get_results( 'SELECT * FROM oj_ai_submissions' ) && $before_answers === $db->get_results( 'SELECT * FROM oj_ai_submission_revisions' ), 'Teacher grade altered or fabricated a submission.' );
grade_assert( 2 === count( $store->read( $scope ) ), 'Never-started and draft grades missing.' );
// A previously released v1 base score remains readable and withdrawable.
$legacy = $store->read( $scope )[ $id( 12 ) ]['grade'];
unset( $legacy['items'], $legacy['hash'] );
$legacy['category'] = 'base';
$legacy_json        = $canonical->encode( $legacy );
$legacy_hash        = hash( 'sha256', $legacy_json );
$db->update(
	'oj_ai_assignment_targets',
	array(
		'non_submission_grade_version' => 1,
		'non_submission_grade_json'    => $legacy_json,
		'non_submission_grade_hash'    => $legacy_hash,
	),
	array( 'id' => 12 )
);
grade_assert( 'base' === $store->read( $scope )[ $id( 12 ) ]['grade']['category'], 'Legacy base grade cannot be read.' );
$run(
	array_replace(
		$draft_input,
		array(
			'category'      => 'base',
			'action'        => 'withdraw',
			'expected_hash' => $legacy_hash,
		)
	),
	'legacy-withdraw'
);
grade_assert( 'withdrawn' === $store->read( $scope )[ $id( 12 ) ]['grade']['status'], 'Legacy approval cannot be withdrawn.' );
$db->update(
	'oj_ai_submissions',
	array(
		'official_revision_public_id' => $id( 31 ),
		'state'                       => 'submitted',
	),
	array( 'learner_user_id' => 12 )
);
grade_assert( ! isset( $store->read( $scope )[ $id( 12 ) ] ), 'Official submission did not supersede non-submission grade.' );
$reject( $draft_input, 'already-submitted' );
foreach ( array( 'scheduled', 'withdrawn' ) as $state ) {
	$db->update( 'oj_ai_assignments', array( 'state' => $state ), array( 'id' => 1 ) );
	$reject( $input, 'state-' . $state );
}
$db->update( 'oj_ai_assignments', array( 'state' => 'closed' ), array( 'id' => 1 ) );
grade_assert( true === $store->read( $scope )[ $id( 11 ) ]['grading']['allowed'], 'Closed assignment cannot be graded.' );
$db->update( 'oj_ai_assignment_targets', array( 'state' => 'revoked' ), array( 'id' => 11 ) );
$reject( $input, 'revoked' );
grade_assert( 0 === count( $db->get_results( 'SELECT * FROM oj_ai_submission_receipts' ) ), 'Teacher grading fabricated a receipt.' );
grade_assert( 0 === count( $db->get_results( 'SELECT * FROM oj_ai_submission_evaluations' ) ), 'Teacher grading fabricated an AI result.' );
echo "Non-submission grades passed: rubric/special exclusivity, levels, zero, bounds, scope, replay, withdraw/reconfirm, draft preservation, supersession, rollback.\n";

<?php
/**
 * Student result discovery uses owned, current, explicitly published results.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-assignment-g3-imp-03.php';

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository;

// Execute production SQL with real joins. This does not emulate MySQL row locks.
/**
 * Student result discovery uses owned, current, explicitly published results.
 */
final class Student_Result_Navigation_Database {
	/**
	 * Pdo.
	 *
	 * @var PDO
	 */
	public PDO $pdo;
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Initialize student result navigation database dependencies and state.
	 */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		foreach ( ( new Schema_Catalog() )->tables() as $name => $table ) {
			$columns = array();
			foreach ( $table->columns() as $column => $type ) {
				$columns[] = '`' . $column . '` ' . ( 'id' === $column ? 'INTEGER PRIMARY KEY' : ( false !== ( stripos( $type, 'int' ) ) ? 'INTEGER' : 'TEXT' ) );
			}
			$this->pdo->exec( 'CREATE TABLE `' . $name . '` (' . implode( ',', $columns ) . ')' );
		}
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
			function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ];
				return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$sql
		);
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array
	 */
	public function get_results( string $sql, mixed $mode = null ): array {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $mode Mode.
	 * @return array|null
	 */
	public function get_row( string $sql, mixed $mode = null ): ?array {
		return $this->get_results( $sql )[0] ?? null;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		return $this->pdo->query( $sql )->fetchColumn();
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		return $this->pdo->exec( $sql );
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $row Row.
	 * @return int
	 */
	public function insert( string $table, array $row ): int {
		$statement = $this->pdo->prepare( 'INSERT INTO `' . $table . '` (`' . implode( '`,`', array_keys( $row ) ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $row ), '?' ) ) . ')' );
		$statement->execute( array_values( $row ) );
		return $statement->rowCount();
	}
	/**
	 * Change.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param mixed  $value Value.
	 * @param int    $id Id.
	 * @return void
	 */
	public function change( string $table, string $column, mixed $value, int $id ): void {
		$statement = $this->pdo->prepare( 'UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE id = ?' );
		$statement->execute( array( $value, $id ) );
	}
}

g3_assert( in_array( 'sqlite', PDO::getAvailableDrivers(), true ), 'Run with -d extension=pdo_sqlite.' );
$db = new Student_Result_Navigation_Database();
$db->insert( 'oj_ai_assignments', $assignment );
foreach ( $targets as $target ) {
	$db->insert( 'oj_ai_assignment_targets', $target );
}
$db->insert( 'oj_ai_worksheet_publications', $publication );
$id   = static fn ( int $number ): string => str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
$root = $student_database->tables['oj_ai_submissions'][0];
$root = array_replace(
	$root,
	array(
		'state'                       => 'submitted',
		'current_revision_public_id'  => $id( 201 ),
		'official_revision_public_id' => $id( 201 ),
		'official_review_public_id'   => $id( 203 ),
		'official_review_hash'        => hash( 'sha256', 'review' ),
		'current_release_public_id'   => $id( 204 ),
		'current_release_hash'        => hash( 'sha256', 'release' ),
	)
);
$db->insert( 'oj_ai_submissions', $root );
$db->insert(
	'oj_ai_submission_revisions',
	array(
		'id'            => 201,
		'public_id'     => $id( 201 ),
		'submission_id' => $root['id'],
	)
);
$db->insert(
	'oj_ai_submission_evaluations',
	array(
		'id'                            => 202,
		'public_id'                     => $id( 202 ),
		'submission_revision_public_id' => $id( 201 ),
		'state'                         => 'reviewed',
	)
);
$db->insert(
	'oj_ai_submission_evaluation_reviews',
	array(
		'id'                       => 203,
		'public_id'                => $id( 203 ),
		'submission_evaluation_id' => 202,
		'content_hash'             => $root['official_review_hash'],
	)
);
$db->insert(
	'oj_ai_submission_release_revisions',
	array(
		'id'                       => 204,
		'public_id'                => $id( 204 ),
		'submission_evaluation_id' => 202,
		'submission_review_id'     => 203,
		'content_hash'             => $root['current_release_hash'],
		'state'                    => 'released',
	)
);
$repository = new WordPress_Student_Assignment_Repository( $db, $canonical );
$read       = static fn (): array => $repository->list_for_actor( 7, $learning_contexts, '', 30, '2026-08-22 10:00:00' );
$expected   = array( 'release_id' => $id( 204 ) );
g3_same( $expected, $read()['items'][0]['result'], 'Current published result must be discoverable before the deadline.' );
g3_same( $expected, $repository->find_for_actor( $assignment_id, 7, $learning_contexts['class:class-21'], '2026-08-22 10:00:00' )['assignment']['result'], 'Detail must use the same result link contract.' );
$db->change( 'oj_ai_assignments', 'state', 'closed', $assignment['id'] );
g3_same( $expected, $read()['items'][0]['result'], 'Closing the assignment must not remove a published result.' );
$db->change( 'oj_ai_assignments', 'state', $assignment['state'], $assignment['id'] );
$db->change( 'oj_ai_submission_release_revisions', 'state', 'corrected', 204 );
g3_same( $expected, $read()['items'][0]['result'], 'Corrected current results must be discoverable.' );
$db->change( 'oj_ai_submission_release_revisions', 'state', 'released', 204 );

$cases = array(
	array( 'oj_ai_submission_release_revisions', 'state', 'stale', 'released', 204 ),
	array( 'oj_ai_submission_release_revisions', 'state', 'withdrawn', 'released', 204 ),
	array( 'oj_ai_submission_evaluations', 'state', 'completed', 'reviewed', 202 ),
	array( 'oj_ai_submissions', 'current_release_public_id', null, $id( 204 ), $root['id'] ),
	array( 'oj_ai_submissions', 'current_release_hash', hash( 'sha256', 'wrong' ), $root['current_release_hash'], $root['id'] ),
	array( 'oj_ai_submissions', 'official_revision_public_id', $id( 301 ), $id( 201 ), $root['id'] ),
	array( 'oj_ai_submissions', 'official_review_public_id', $id( 303 ), $id( 203 ), $root['id'] ),
	array( 'oj_ai_submissions', 'official_review_hash', hash( 'sha256', 'wrong' ), $root['official_review_hash'], $root['id'] ),
	array( 'oj_ai_submission_revisions', 'submission_id', $root['id'] + 1, $root['id'], 201 ),
	array( 'oj_ai_submission_evaluation_reviews', 'submission_evaluation_id', 999, 202, 203 ),
	array( 'oj_ai_submissions', 'learner_user_id', 8, 7, $root['id'] ),
);
foreach ( $cases as [ $table, $column, $invalid, $original, $pk ] ) {
	$db->change( $table, $column, $invalid, $pk );
	g3_same( null, $read()['items'][0]['result'], 'Non-current or foreign result leaked: ' . $table . '.' . $column );
	$db->change( $table, $column, $original, $pk );
}
$other_contexts = array( 'class:class-999' => array_replace( $learning_contexts['class:class-21'], array( 'id' => 'class-999' ) ) );
g3_same(
	array(
		'items'       => array(),
		'next_cursor' => '',
	),
	$repository->list_for_actor( 7, array(), '', 30, '2026-08-22 10:00:00' ),
	'A learner with no contexts must receive an empty list, not an error.'
);
g3_failure( static fn () => $repository->list_for_actor( 7, array_values( $learning_contexts ), '', 30, '2026-08-22 10:00:00' ), \OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure::ROSTER_INVALID, 'A nonempty malformed context map must still fail.' );
g3_same( array(), $repository->list_for_actor( 7, $other_contexts, '', 30, '2026-08-22 10:00:00' )['items'], 'A result must not bypass context membership.' );
$db->change( 'oj_ai_assignments', 'state', 'withdrawn', $assignment['id'] );
g3_same( array(), $read()['items'], 'Withdrawn assignments must not expose result links.' );
$db->change( 'oj_ai_assignments', 'state', $assignment['state'], $assignment['id'] );
$db->query( "UPDATE oj_ai_assignment_targets SET state = 'revoked' WHERE learner_user_id = 7" );
g3_same( array(), $read()['items'], 'Revoked learners must not discover results.' );

echo "Student result navigation passed: production SQL, explicit release, ownership, current answer/review/hash, open/closed, corrected, revoked and withdrawn guards.\n";

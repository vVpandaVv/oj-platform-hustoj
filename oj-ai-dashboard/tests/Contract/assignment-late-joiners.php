<?php
/**
 * Late joiners through real assignment SQL and a synthetic platform roster.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Submission\Assignment\Application\Add_Assignment_Targets;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Command;
use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Repository;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;

ob_start();
require __DIR__ . '/assignment-create-g3-imp-01.php';
ob_end_clean();

/** Real SQL with transaction rollback, catalog columns and unique constraints. */
final class Late_Joiner_Database {
	/**
	 * In-memory connection.
	 *
	 * @var PDO
	 */
	public PDO $connection;
	/**
	 * Last SQL error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Last inserted ID.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Inject an insert failure.
	 *
	 * @var string
	 */
	public string $fail_table = '';
	/**
	 * Inject a race before the transaction.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_begin = null;
	/** Build synthetic tables from actual addon-owned columns. */
	public function __construct() {
		$this->connection = new PDO( 'sqlite::memory:' );
		$this->connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$catalog = new Schema_Catalog();
		foreach ( array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_submissions', 'oj_ai_submission_revisions', 'oj_ai_submission_evaluation_reviews', 'oj_ai_worksheet_publications', 'oj_ai_worksheets', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $table ) {
			$columns = array();
			foreach ( $catalog->table( $table )->columns() as $column => $type ) {
				$columns[] = $column . ( 'id' === $column ? ' INTEGER PRIMARY KEY AUTOINCREMENT' : ( str_contains( $type, 'INT' ) ? ' INTEGER' : ' TEXT' ) );
			}
			$this->connection->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
			$this->connection->exec( 'CREATE UNIQUE INDEX ' . $table . '_public ON ' . $table . '(public_id)' );
		}
		$this->connection->exec( 'CREATE UNIQUE INDEX target_learner ON oj_ai_assignment_targets(assignment_id,learner_user_id)' );
		$this->connection->exec( 'CREATE UNIQUE INDEX outbox_key ON oj_ai_outbox_events(idempotency_key)' );
	}
	/**
	 * Expand wpdb placeholders using SQLite escaping.
	 *
	 * @param string $sql SQL.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( array $match ) use ( $args, &$i ): string {
				$value = $args[ $i++ ];
				return '%d' === $match[0] ? (string) (int) $value : $this->connection->quote( (string) $value );
			},
			$sql
		);
	}
	/**
	 * Execute a query without MySQL lock syntax.
	 *
	 * @param string $sql SQL.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			if ( $this->on_begin ) {
				$callback       = $this->on_begin;
				$this->on_begin = null;
				$callback();
			}
			$sql = 'BEGIN';
		}
		return $this->connection->exec( $sql );
	}
	/**
	 * Read rows.
	 *
	 * @param string $sql SQL.
	 * @param string $format wpdb format.
	 * @return array
	 */
	public function get_results( string $sql, string $format = 'ARRAY_A' ): array {
		return $this->connection->query( str_replace( ' FOR UPDATE', '', $sql ) )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Read a row.
	 *
	 * @param string $sql SQL.
	 * @param string $format wpdb format.
	 * @return array|null
	 */
	public function get_row( string $sql, string $format = 'ARRAY_A' ): ?array {
		return $this->get_results( $sql )[0] ?? null;
	}
	/**
	 * Read a scalar.
	 *
	 * @param string $sql SQL.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		$row = $this->get_row( $sql );
		return null === $row ? null : array_values( $row )[0];
	}
	/**
	 * Insert a row, optionally failing at the requested table.
	 *
	 * @param string $table Table.
	 * @param array  $row Values.
	 * @return int|false
	 */
	public function insert( string $table, array $row ): int|false {
		if ( $this->fail_table === $table ) {
			return false;
		}
		$this->connection->prepare( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $row ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $row ), '?' ) ) . ')' )->execute( array_values( $row ) );
		$this->insert_id = (int) $this->connection->lastInsertId();
		return 1;
	}
}

$db = new Late_Joiner_Database();
$db->insert(
	'oj_ai_worksheets',
	array(
		'id'                           => 1,
		'state'                        => 'active',
		'latest_publication_public_id' => $publication_id,
	)
);
$publication = ( new ReflectionProperty( G3_Database::class, 'publication' ) )->getValue( $database );
unset( $publication['worksheet_state'], $publication['latest_publication_public_id'] );
$publication['worksheet_id'] = 1;
$db->insert( 'oj_ai_worksheet_publications', $publication );
$initial_roster  = new G3_Roster_Port(
	new Assignment_Roster_Snapshot(
		'group',
		'group:10',
		'group-r1',
		'initial-roster',
		array(
			array(
				'learner_user_id'     => 7,
				'membership_revision' => 'member-7',
			),
			array(
				'learner_user_id'     => 9,
				'membership_revision' => 'member-9',
			),
		)
	)
);
$initial_command = new Create_Assignment_Command(
	42,
	'late-initial-0001',
	$publication_id,
	$publication_hash,
	'group',
	'group:10',
	'group-r1',
	array(
		'opens_at_utc'        => null,
		'due_at_utc'          => '2099-09-20T00:00:00Z',
		'closes_at_utc'       => '2099-09-21T00:00:00Z',
		'auto_submit_enabled' => false,
	),
	array(
		'max_attempts'          => 2,
		'copy_previous_answers' => true,
		'reopen_until_utc'      => null,
	),
	hash( 'sha256', 'late-initial' ),
	'late-initial-0001',
	$canonical
);
$initial         = new Create_Assignment( new WordPress_Assignment_Repository( $db, $canonical ), $initial_roster, new G3_Display_Port(), $ids, $canonical );
$initial->execute( $initial_command );
$assignment = $db->get_row( 'SELECT * FROM oj_ai_assignments LIMIT 1' );
$id         = $assignment['public_id'];
$pk         = (int) $assignment['id'];
$db->query( "UPDATE oj_ai_assignments SET state = 'open'" );
$db->query( $db->prepare( 'UPDATE oj_ai_worksheets SET latest_publication_public_id = %s', $ids->create()->value() ) );
$db->insert(
	'oj_ai_submissions',
	array(
		'public_id'           => $ids->create()->value(),
		'assignment_id'       => $pk,
		'official_attempt_no' => 2,
		'state'               => 'submitted',
	)
);
$db->insert(
	'oj_ai_submission_revisions',
	array(
		'public_id'     => $ids->create()->value(),
		'response_json' => '{"synthetic":"original answer"}',
	)
);
$db->insert( 'oj_ai_submission_evaluation_reviews', array( 'public_id' => $ids->create()->value() ) );
$preserved = array();
foreach ( array( 'oj_ai_assignment_targets', 'oj_ai_submissions', 'oj_ai_submission_revisions', 'oj_ai_submission_evaluation_reviews' ) as $table ) {
	$preserved[ $table ] = $db->get_results( 'SELECT * FROM ' . $table );
}
$context       = array(
	'context_type'     => $assignment['context_type'],
	'context_id'       => $assignment['context_id'],
	'context_revision' => $assignment['context_revision'],
);
$live          = new class() implements Assignment_Roster_Port_Interface {
	/**
	 * Learner IDs.
	 *
	 * @var array
	 */
	public array $members = array();
	/**
	 * Current roster revision.
	 *
	 * @var string
	 */
	public string $revision = 'new-roster';
	/**
	 * Calls for replay detection.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Current synthetic roster with real domain validation.
	 *
	 * @param int    $actor_user_id Actor.
	 * @param string $context_type Type.
	 * @param string $context_id ID.
	 * @param string $expected_context_revision Revision.
	 * @return Assignment_Roster_Snapshot
	 */
	public function collect( int $actor_user_id, string $context_type, string $context_id, string $expected_context_revision ): Assignment_Roster_Snapshot {
		g3_same( 42, $actor_user_id, 'The teacher identity must reach the platform.' );
		++$this->calls;
		return new Assignment_Roster_Snapshot(
			$context_type,
			$context_id,
			$expected_context_revision,
			$this->revision,
			array_map(
				static fn ( int $id ): array => array(
					'learner_user_id'     => $id,
					'membership_revision' => 'member-' . $id,
				),
				$this->members
			)
		);
	}
};
$existing_ids  = array_column( $preserved['oj_ai_assignment_targets'], 'learner_user_id' );
$live->members = array_merge( $existing_ids, array( 10001, 10002, 10003 ) );
$repo          = new WordPress_Assignment_Repository( $db, $canonical );
$service       = new Add_Assignment_Targets( $repo, $live, new G3_Display_Port(), $ids, $canonical );
$preview       = $service->preview( 42, $id, $context );
g3_same( array( 10001, 10002, 10003 ), array_column( $preview['items'], 'learner_user_id' ), 'Only late joiners appear.' );
$input = array(
	'expected'         => $preview['expected'],
	'learner_user_ids' => array( 10001 ),
);
foreach ( array( array(), array( 10001, 10001 ), array( '10001' ), array( -1 ) ) as $invalid ) {
	g3_invalid( static fn () => Add_Assignment_Targets::input( array_merge( $input, array( 'learner_user_ids' => $invalid ) ) ), 'Invalid selection must be rejected.' );
}
foreach ( array( 99999, $existing_ids[0] ) as $invalid ) {
	g3_failure( static fn () => $service->execute( 42, $id, $context, array_merge( $input, array( 'learner_user_ids' => array( $invalid ) ) ), hash( 'sha256', 'bad-' . $invalid ), 'late-test' ), Assignment_Failure::STATE_CONFLICT, 'Nonmembers and existing targets cannot be appended.' );
}
$live->revision = 'raced-roster';
g3_failure( static fn () => $service->execute( 42, $id, $context, $input, hash( 'sha256', 'raced' ), 'late-test' ), Assignment_Failure::STATE_CONFLICT, 'Changed membership invalidates preview.' );
$live->revision = 'new-roster';
$result         = $service->execute( 42, $id, $context, $input, hash( 'sha256', 'success' ), 'late-test' );
g3_same( 1, $result['added_count'], 'One selected student added.' );
g3_same( count( $existing_ids ) + 1, $result['target_count'], 'Full roster count updated.' );
foreach ( $preserved as $table => $rows ) {
	$actual = $db->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id' );
	g3_same( $rows, array_slice( $actual, 0, count( $rows ) ), 'Existing data must not change: ' . $table );
}
$calls          = $live->calls;
$live->revision = 'after-success';
$replay         = $service->execute( 42, $id, $context, $input, hash( 'sha256', 'success' ), 'late-test' );
g3_same( true, $replay['replayed'], 'Network retry returns receipt even after roster changes.' );
g3_same( $calls, $live->calls, 'Receipt replay does not call platform.' );
g3_failure( static fn () => $service->execute( 42, $id, $context, array_merge( $input, array( 'learner_user_ids' => array( 10002 ) ) ), hash( 'sha256', 'success' ), 'late-test' ), Assignment_Failure::IDEMPOTENCY_CONFLICT, 'Same key cannot change selection.' );
$audit = $db->get_row( "SELECT * FROM oj_ai_audit_events WHERE action_key = 'assignment.targets_added'" );
g3_same( $assignment['target_snapshot_hash'], $audit['before_digest'], 'Original snapshot retained in audit.' );
g3_same( $result['target_snapshot_hash'], $audit['after_digest'], 'Updated snapshot is audited.' );
$new_target = $db->get_row( 'SELECT * FROM oj_ai_assignment_targets WHERE learner_user_id = 10001' );
g3_same( 'active', $new_target['state'], 'New learner has an active target.' );
g3_same( 0, (int) $db->get_var( 'SELECT COUNT(*) FROM oj_ai_submissions WHERE assignment_id = ' . $pk . ' AND learner_user_id = 10001' ), 'New learner starts without a submission or consumed attempt.' );

$fresh  = $service->preview( 42, $id, $context );
$next   = array(
	'expected'         => $fresh['expected'],
	'learner_user_ids' => array( 10002 ),
);
$before = $repo->target_snapshot( $id );
foreach ( array( 'oj_ai_assignment_targets', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $failure_table ) {
	$db->fail_table = $failure_table;
	g3_failure( static fn () => $service->execute( 42, $id, $context, $next, hash( 'sha256', $failure_table ), 'late-test' ), Assignment_Failure::STORAGE_UNAVAILABLE, 'Injected write failure rolls back everything.' );
	g3_same( $before, $repo->target_snapshot( $id ), 'Failed transaction must restore targets and assignment snapshot.' );
}
$db->fail_table = '';
$db->on_begin   = static function () use ( $db ): void {
	$db->query( 'UPDATE oj_ai_assignments SET lock_version = lock_version + 1' );
};
g3_failure( static fn () => $service->execute( 42, $id, $context, $next, hash( 'sha256', 'cas-race' ), 'late-test' ), Assignment_Failure::STATE_CONFLICT, 'Transaction rechecks lock version.' );

$other = $db->get_row( 'SELECT * FROM oj_ai_assignments LIMIT 1' );
unset( $other['id'] );
$other['public_id']  = $ids->create()->value();
$other['context_id'] = 'group:other';
$db->insert( 'oj_ai_assignments', $other );
$other_pk = $db->insert_id;
$overlap  = $new_target;
unset( $overlap['id'] );
$overlap['public_id']       = $ids->create()->value();
$overlap['assignment_id']   = $other_pk;
$overlap['learner_user_id'] = 10002;
$db->insert( 'oj_ai_assignment_targets', $overlap );
$fresh            = $service->preview( 42, $id, $context );
$next['expected'] = $fresh['expected'];
g3_failure( static fn () => $service->execute( 42, $id, $context, $next, hash( 'sha256', 'overlap' ), 'late-test' ), Assignment_Failure::TARGET_OVERLAP, 'Same publication cannot be assigned twice across groups.' );

foreach ( array( 'closed', 'withdrawn' ) as $state ) {
	$db->query( "UPDATE oj_ai_assignments SET state = '$state'" );
	g3_failure( static fn () => $service->preview( 42, $id, $context ), Assignment_Failure::TARGETS_CLOSED, 'Closed assignments are never reopened implicitly.' );
}
$db->query( "UPDATE oj_ai_assignments SET state = 'open', due_at_utc = '2000-01-01 00:00:00'" );
g3_failure( static fn () => $service->preview( 42, $id, $context ), Assignment_Failure::TARGETS_CLOSED, 'Expired due date must not promise a submission opportunity.' );
g3_failure( static fn () => $service->preview( 42, $id, array_merge( $context, array( 'context_id' => 'group:unrelated' ) ) ), Assignment_Failure::NOT_FOUND, 'Cross-context access fails closed.' );
echo "PASS late joiners: real SQL, selection, membership/version races, immutable prior answers, attempts and reviews, replay, overlap, deadline, rollback.\n";

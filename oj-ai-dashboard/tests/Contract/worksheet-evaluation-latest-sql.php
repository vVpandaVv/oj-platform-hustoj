<?php
/**
 * Execute the production latest-list SQL against isolated relational fixtures.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/worksheet-evaluation-query-s05.php';

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	throw new RuntimeException( 'pdo_sqlite is required for latest-list SQL verification.' );
}
$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE oj_ai_worksheets (id INTEGER PRIMARY KEY, public_id TEXT, owner_user_id INTEGER, current_revision_public_id TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_worksheet_revisions (id INTEGER PRIMARY KEY, public_id TEXT, worksheet_id INTEGER, revision_no INTEGER)' );
$pdo->exec( 'CREATE TABLE oj_ai_worksheet_evaluations (id INTEGER PRIMARY KEY, public_id TEXT, worksheet_revision_public_id TEXT, state TEXT, updated_at_utc TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_evaluation_runs (id INTEGER, subject_type TEXT, evaluation_public_id TEXT, run_no INTEGER)' );
$pdo->exec( 'CREATE TABLE oj_ai_worksheet_evaluation_results (id INTEGER, worksheet_evaluation_id INTEGER)' );
$pdo->exec( "INSERT INTO oj_ai_worksheets VALUES (1,'w1',42,'r2'),(2,'w2',42,'r3'),(3,'w3',7,'r4')" );
$pdo->exec( "INSERT INTO oj_ai_worksheet_revisions VALUES (1,'r1',1,1),(2,'r2',1,2),(3,'r3',2,1),(4,'r4',3,1)" );
$pdo->exec(
	"INSERT INTO oj_ai_worksheet_evaluations VALUES
 (1,'old','r1','failed','2026-09-11 11:00:00'),
 (2,'previous-policy','r2','failed','2026-09-11 10:00:00'),
 (3,'latest','r2','reviewed','2026-09-11 09:00:00'),
 (4,'second','r3','failed','2026-09-11 08:00:00'),
 (5,'private','r4','reviewed','2026-09-11 12:00:00')"
);

$select_ids = static function ( string $state, string $worksheet, string $revision, int $limit, bool $latest ) use ( $repository, $database, $pdo ): array {
	$database->list_pages = array( array() );
	$repository->list_owned( 42, $state, $worksheet, $revision, '', $limit, $latest );
	$prepared = $database->prepared[ array_key_last( $database->prepared ) ];
	// Keep actual joins, ownership, grouping, filters and pagination; omit only display projections.
	$sql    = 'SELECT e.public_id ' . substr( $prepared['sql'], strpos( $prepared['sql'], 'FROM oj_ai_worksheet_evaluations e ' ) );
	$values = array_slice( $prepared['values'], 2 );
	$sql    = preg_replace_callback(
		'/%[sd]/',
		static function ( array $match ) use ( &$values, $pdo ): string {
			$value = array_shift( $values );
			return '%d' === $match[0] ? (string) (int) $value : $pdo->quote( (string) $value );
		},
		$sql
	);
	return $pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
};

eval_s05_same( array( 'latest', 'second' ), $select_ids( '', '', '', 30, true ), 'Only newest per worksheet, regardless of old-review update time.' );
eval_s05_same( array( 'second' ), $select_ids( 'failed', '', '', 30, true ), 'Failed history must not reappear when newest is reviewed.' );
eval_s05_same( array( 'old', 'previous-policy', 'latest', 'second' ), $select_ids( '', '', '', 30, false ), 'History remains complete without another owner.' );
eval_s05_same( array( 'latest' ), $select_ids( '', 'w1', '', 30, true ), 'Worksheet scoping is preserved.' );
eval_s05_same( array(), $select_ids( '', 'w3', '', 30, true ), 'Another owner cannot be queried.' );
eval_s05_same( array(), $select_ids( '', '', 'r1', 30, true ), 'Revision filter cannot resurrect an older evaluation.' );
eval_s05_same( array( 'latest', 'second' ), $select_ids( '', '', '', 1, true ), 'Page lookahead counts worksheets, not discarded history.' );
$pdo->exec( "UPDATE oj_ai_worksheets SET current_revision_public_id='unassessed-revision' WHERE id=1" );
eval_s05_same( array( 'latest' ), $select_ids( 'stale', '', '', 30, true ), 'A modified worksheet retains one last result with stale status.' );
echo "Latest worksheet evaluation relational SQL checks passed (8 scenarios).\n";

<?php
/**
 * Execute production queue selection SQL against an isolated relational fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( dirname( __DIR__, 2 ) . '/src' ) )->register();
$pdo = new PDO( 'sqlite::memory:' );
$pdo->sqliteCreateFunction( 'UTC_TIMESTAMP', static fn (): string => '2026-09-14 12:00:00' );
$pdo->exec( 'CREATE TABLE oj_ai_evaluation_runs (id INTEGER, lock_version INTEGER, subject_type TEXT, operation_key TEXT, evaluation_public_id TEXT, state TEXT, available_at_utc TEXT, lease_expires_at_utc TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_worksheet_evaluations (id INTEGER, public_id TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_worksheet_evaluation_inputs (worksheet_evaluation_id INTEGER)' );
$pdo->exec( 'CREATE TABLE oj_ai_submission_evaluations (id INTEGER, public_id TEXT, state TEXT)' );
$pdo->exec( 'CREATE TABLE oj_ai_submission_evaluation_inputs (submission_evaluation_id INTEGER)' );
$pdo->exec( "INSERT INTO oj_ai_worksheet_evaluations VALUES (1,'worksheet'),(2,'missing_input')" );
$pdo->exec( 'INSERT INTO oj_ai_worksheet_evaluation_inputs VALUES (1)' );
$pdo->exec( "INSERT INTO oj_ai_submission_evaluations VALUES (1,'score','queued'),(2,'review','reviewed'),(3,'hidden','cancelled')" );
$pdo->exec( 'INSERT INTO oj_ai_submission_evaluation_inputs VALUES (1),(2),(3)' );
$insert = $pdo->prepare( 'INSERT INTO oj_ai_evaluation_runs VALUES (?,0,?,?,?,?,?,?)' );
$rows   = array(
	array( 1, 'worksheet_evaluation', 'evaluate', 'worksheet', 'queued', '2026-09-14 13:00:00', null ),
	array( 2, 'submission_evaluation', 'score', 'review', 'queued', '2026-09-14 10:00:00', null ),
	array( 3, 'submission_evaluation', 'feedback_draft', 'review', 'queued', '2026-09-14 11:00:00', null ),
	array( 4, 'worksheet_evaluation', 'evaluate', 'worksheet', 'running', '2026-09-14 10:00:00', '2026-09-14 13:00:00' ),
	array( 5, 'submission_evaluation', 'score', 'score', 'queued', '2026-09-14 11:00:00', null ),
	array( 6, 'submission_evaluation', 'unknown', 'review', 'queued', '2026-09-14 10:00:00', null ),
	array( 7, 'submission_evaluation', 'special_note_draft', 'review', 'retry_wait', '2026-09-14 11:00:00', null ),
	array( 8, 'worksheet_evaluation', 'evaluate', 'missing_input', 'queued', '2026-09-14 10:00:00', null ),
	array( 9, 'worksheet_evaluation', 'evaluate', 'worksheet', 'leased', '2026-09-14 10:00:00', '2026-09-14 12:00:00' ),
	array( 10, 'submission_evaluation', 'feedback_draft', 'hidden', 'queued', '2026-09-14 10:00:00', null ),
	array( 11, 'submission_evaluation', 'score', 'score', 'cancelled', '2026-09-14 10:00:00', null ),
	array( 12, 'submission_evaluation', 'score', 'score', 'retry_wait', '2026-09-14 13:00:00', null ),
);
foreach ( $rows as $row ) {
	$insert->execute( $row );
}
$database   = new class( $pdo ) {
	/**
	 * No synthetic database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Keep the SQL fixture connection.
	 *
	 * @param PDO $pdo Isolated database.
	 */
	public function __construct( private PDO $pdo ) {}
	/**
	 * Execute the production SELECT without rewriting its predicates.
	 *
	 * @param string $sql Production SQL.
	 * @param string $format WordPress row shape.
	 * @return array|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		$row = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC );
		return false === $row ? null : $row;
	}
};
$dispatcher = new OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Evaluation_Queue_Dispatcher( $database );
$next       = new ReflectionMethod( $dispatcher, 'next' );
$observed   = array();
$row        = $next->invoke( $dispatcher );
while ( null !== $row ) {
	$observed[] = (int) $row['id'];
	$pdo->exec( 'DELETE FROM oj_ai_evaluation_runs WHERE id = ' . (int) $row['id'] );
	$row = $next->invoke( $dispatcher );
}
if ( array( 9, 3, 5, 7 ) !== $observed ) {
	throw new RuntimeException( 'Production SQL must preserve FIFO, exact lease expiry, retry time, input joins and root-state boundaries.' );
}
fwrite( STDOUT, "PASS evaluation queue SQL: 12 relational eligibility cases, expected FIFO 9/3/5/7.\n" );

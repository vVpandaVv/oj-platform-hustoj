<?php
/**
 * Context report exports must not require ownership of editable worksheets.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Report_Publication_Access;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/report-export-enqueue-g5-imp-03-s01.php';

/**
 * Context report exports must not require ownership of editable worksheets.
 */
final class Report_Context_Access_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Queries.
	 *
	 * @var array
	 */
	public array $queries = array();
	/**
	 * Fail.
	 *
	 * @var bool
	 */
	public bool $fail = false;
	/**
	 * Pdo.
	 *
	 * @var PDO
	 */
	public PDO $pdo;
	/**
	 * Initialize report context access database dependencies and state.
	 */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE oj_ai_worksheets (id INTEGER PRIMARY KEY, public_id TEXT, owner_user_id INTEGER)' );
		$this->pdo->exec( 'CREATE TABLE oj_ai_worksheet_publications (public_id TEXT PRIMARY KEY, worksheet_id INTEGER, publication_hash TEXT, state TEXT)' );
		$this->pdo->exec( 'CREATE TABLE oj_ai_assignments (worksheet_publication_public_id TEXT, publication_hash TEXT, context_type TEXT, context_id TEXT, context_revision TEXT, state TEXT)' );
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$position = strpos( $sql, '%s' );
			if ( false === $position ) {
				throw new RuntimeException( 'Unexpected binding.' );
			}
			$sql = substr_replace( $sql, $this->pdo->quote( (string) $value ), $position, 2 );
		}
		return $sql;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array
	 */
	public function get_results( string $sql, string $format ): array {
		g5_report_export_same( 'ARRAY_A', $format, 'Unexpected query format.' );
		g5_report_export_assert( str_starts_with( $sql, 'SELECT ' ), 'Authorization attempted a write.' );
		$this->queries[] = $sql;
		if ( $this->fail ) {
			$this->last_error = 'private database detail';
			return array();
		}
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}
}

/**
 * Context report exports must not require ownership of editable worksheets.
 */
final class Report_Context_Access_Gateway implements Context_Authorization_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var array
	 */
	public array $calls = array();
	/**
	 * Mode.
	 *
	 * @var string
	 */
	public string $mode = 'allowed';
	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result {
		$this->calls[] = array( $actor_user_id, $scope );
		g5_report_export_same( Context_Scope::OPERATION_REPORT, $scope->operation(), 'Must ask for report permission, not participation.' );
		g5_report_export_same( null, $scope->learner_user_id(), 'Must not authorize a learner self scope.' );
		if ( 'denied' === $this->mode ) {
			return Context_Authorization_Result::denied();
		}
		if ( 'unavailable' === $this->mode ) {
			return Context_Authorization_Result::unavailable();
		}
		return Context_Authorization_Result::allowed(
			$scope->type(),
			'wrong_context' === $this->mode ? 'other-class' : $scope->id(),
			'active',
			'wrong_revision' === $this->mode ? 'changed' : (string) $scope->expected_revision(),
			'wrong_relation' === $this->mode ? 'other-relation' : 'educator'
		);
	}
}

$canonical = new Canonical_Json();
$fixture   = g5_snapshot_q22_record( $canonical );
$context   = Context_Authorization_Result::allowed( 'class', 'class-g5-snapshot', 'active', 'class-g5-snapshot-r2', 'educator' );
$database  = new Report_Context_Access_Database();
$sources   = array();
foreach ( $fixture['projection']['items'] as $i => $item ) {
	$database->pdo->prepare( 'INSERT INTO oj_ai_worksheets VALUES (?, ?, ?)' )->execute( array( $i + 1, $item['worksheet']['id'], 999 ) );
	$database->pdo->prepare( 'INSERT INTO oj_ai_worksheet_publications VALUES (?, ?, ?, ?)' )->execute( array( $item['publication']['id'], $i + 1, $item['publication']['hash'], 'active' ) );
	$database->pdo->prepare( 'INSERT INTO oj_ai_assignments VALUES (?, ?, ?, ?, ?, ?)' )->execute( array( $item['publication']['id'], $item['publication']['hash'], $context->type(), $context->id(), $context->revision(), 'closed' ) );
	$sources[] = array(
		'worksheet_id'     => $item['worksheet']['id'],
		'publication_id'   => $item['publication']['id'],
		'publication_hash' => $item['publication']['hash'],
	);
}
$gateway          = new Report_Context_Access_Gateway();
$access           = new WordPress_Report_Publication_Access( $gateway, $database );
$objects          = new G5_Snapshot_Q22_Objects();
$objects->deny_id = $sources[0]['worksheet_id'];
$query            = new G5_Snapshot_Q22_Query( $fixture['record'], $canonical );
$legacy           = new Read_Report_Snapshot( $query, $objects, new Report_Snapshot_Profile( $canonical ), $canonical );
try {
	$legacy->read( 77, $fixture['record']['public_id'], $context );
	throw new RuntimeException( 'The owner-only regression was not reproduced.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_report_export_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'Unexpected owner-only failure.' );
}
$reader = new Read_Report_Snapshot( $query, $objects, new Report_Snapshot_Profile( $canonical ), $canonical, $access );
$before = count( $objects->calls );
g5_report_export_same( $fixture['projection'], $reader->read( 77, $fixture['record']['public_id'], $context )->payload()['projection'], 'Context access changed immutable report content.' );
g5_report_export_same( $before, count( $objects->calls ), 'Context reporting queried draft ownership.' );
$repository = new G5_Report_Export_Memory_Repository();
$enqueue    = new Enqueue_Report_Export( $reader, $repository, new G5_Report_Export_Queue(), new Public_Id_Generator(), $canonical );
foreach ( array( 'report_csv', 'report_pdf' ) as $kind ) {
	$result = $enqueue->execute( g5_report_export_command( $kind, $fixture, $canonical, 'context-' . $kind, 'context-' . $kind ), $context );
	g5_report_export_same( 'queued', $result->state(), 'Context reporter could not enqueue an export.' );
}

$cases = 0;
foreach ( array(
	"UPDATE oj_ai_assignments SET context_id = 'other-class'",
	"UPDATE oj_ai_assignments SET context_type = 'group'",
	"UPDATE oj_ai_assignments SET context_revision = 'old-revision'",
	"UPDATE oj_ai_assignments SET state = 'withdrawn'",
	"UPDATE oj_ai_assignments SET publication_hash = 'changed'",
	"UPDATE oj_ai_worksheet_publications SET publication_hash = 'changed'",
	'UPDATE oj_ai_worksheet_publications SET worksheet_id = 999',
	'DELETE FROM oj_ai_worksheet_publications WHERE worksheet_id = 2',
	'DELETE FROM oj_ai_assignments',
) as $mutation ) {
	$database->pdo->beginTransaction();
	$database->pdo->exec( $mutation );
	g5_report_export_assert( $access->authorize( 77, $context, $sources )->is_denied(), 'Publication binding failure was accepted: ' . $mutation );
	$database->pdo->rollBack();
	++$cases;
}
foreach ( array( 'denied', 'unavailable', 'wrong_context', 'wrong_revision', 'wrong_relation' ) as $mode ) {
	$gateway->mode = $mode;
	g5_report_export_assert( ! $access->authorize( 77, $context, $sources )->is_allowed(), 'Current platform failure was accepted: ' . $mode );
	++$cases;
}
$gateway->mode  = 'allowed';
$database->fail = true;
g5_report_export_assert( $access->authorize( 77, $context, $sources )->is_unavailable(), 'Database error did not fail closed.' );
$database->fail = false;
g5_report_export_assert( $access->authorize( 0, $context, $sources )->is_denied(), 'Anonymous actor was accepted.' );
g5_report_export_assert( $access->authorize( 77, Context_Authorization_Result::denied(), $sources )->is_denied(), 'Denied context was accepted.' );
g5_report_export_assert( $access->authorize( 77, $context, array( $sources[0], $sources[0] ) )->is_denied(), 'Duplicate publication was accepted.' );
$objects->deny_id = null;
$gateway->mode    = 'denied';
try {
	$reader->read( 77, $fixture['record']['public_id'], $context );
	throw new RuntimeException( 'Revoked context fell back to worksheet ownership.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_report_export_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'Context revocation failure changed.' );
}
$composition = file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Composition_Root.php' );
g5_report_export_assert( 1 === preg_match( '/new Read_Report_Snapshot\([\s\S]*?new \\\\OJAI\\\\Dashboard\\\\Submission\\\\Assignment\\\\Infrastructure\\\\WordPress_Report_Publication_Access\( \$contexts \)/', $composition ), 'Production reader did not use context publication authorization.' );
echo 'Context report access passed: owner-only failure reproduced; exact immutable projection and CSV/PDF enqueue passed; ' . ( (int) $cases + 5 ) . " denial/error checks passed.\n";

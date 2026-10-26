<?php
/**
 * Count-only storage diagnosis with real aggregate SQL and protected-file coverage.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Storage_Backlog_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_Storage_Operations_Detail;

require __DIR__ . '/operations-storage-detail.php';

/**
 * Count-only storage diagnosis with real aggregate SQL and protected-file coverage.
 */
final class OSB_Database {
	/**
	 * Connection.
	 *
	 * @var PDO
	 */
	public PDO $connection;
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
	 * Initialize osb database dependencies and state.
	 */
	public function __construct() {
		$this->connection = new PDO( 'sqlite::memory:' );
		$this->connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$catalog = new \OJAI\Dashboard\Database\Foundation\Schema_Catalog();
		foreach ( array( 'oj_ai_private_files', 'oj_ai_export_jobs', 'oj_ai_pdf_artifacts', 'oj_ai_submission_attachments', 'oj_ai_zip_uploads', 'oj_ai_zip_upload_items' ) as $table ) {
			$columns = array();
			foreach ( $catalog->table( $table )->columns() as $name => $type ) {
				$columns[] = $name . ( str_contains( $type, 'INT' ) ? ' INTEGER' : ' TEXT' );
			}
			$this->connection->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
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
		$index = 0;
		return preg_replace_callback(
			'/%s/',
			function () use ( $args, &$index ): string {
				return $this->connection->quote( $args[ $index++ ] );
			},
			$sql
		);
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array
	 */
	public function get_results( string $sql, string $format ): array {
		$this->queries[] = $sql;
		return $this->connection->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Add.
	 *
	 * @param string      $purpose Purpose.
	 * @param string      $retention Retention.
	 * @param string      $state State.
	 * @param string|null $hold Hold.
	 * @param string|null $expires Expires.
	 * @param string|null $purged Purged.
	 * @return void
	 */
	public function add( string $purpose, string $retention, string $state = 'ready', ?string $hold = null, ?string $expires = '2026-09-08 10:00:00', ?string $purged = null ): void {
		$this->connection->prepare( 'INSERT INTO oj_ai_private_files (purpose,retention_class,state,expires_at_utc,legal_hold_until_utc,purged_at_utc) VALUES (?,?,?,?,?,?)' )->execute( array( $purpose, $retention, $state, $expires, $hold, $purged ) );
	}
}

$database         = new OSB_Database();
$backlog          = new WordPress_Storage_Backlog_Diagnostics( $database );
$zero             = array(
	'expired' => 0,
	'held'    => 0,
	'bound'   => 0,
);
$empty            = array_fill_keys( array( 'attachment', 'zip', 'export', 'worksheet', 'other' ), $zero );
$empty['export'] += array_fill_keys( array( 'eligible', 'preserved', 'in_use', 'blocked' ), 0 );
osd_same( $empty, $backlog->inspect( '2026-09-08 12:00:00' ), 'Empty database must report true zero counts.' );
$database->add( 'submission_attachment', 'submission_staging' );
$database->add( 'submission_attachment', 'submission_staging', 'bound', '2026-09-09 12:00:00' );
$database->add( 'submission_attachment', 'submission_staging', 'ready', '2026-09-07 12:00:00' );
$database->add( 'submission_zip_source', 'submission_zip_staging' );
$database->add( 'submission_zip_item', 'submission_zip_staging' );
foreach ( array( 'generated_pdf', 'worksheet_package', 'report_csv', 'report_json_package', 'submission_bundle' ) as $purpose ) {
	$database->add( $purpose, 'generated_export_24h' );
}
$database->add( 'worksheet_asset', 'worksheet_staging', 'bound' );
$database->add( 'unrecognized', 'unrecognized' );
$database->add( 'submission_attachment', 'submission_staging', 'purged' );
$database->add( 'submission_attachment', 'submission_staging', 'ready', null, null );
$database->add( 'submission_attachment', 'submission_staging', 'ready', null, '2026-09-09 12:00:00' );
$database->add( 'submission_attachment', 'submission_staging', 'ready', null, '2026-09-08 10:00:00', '2026-09-08 11:00:00' );
$counts = $backlog->inspect( '2026-09-08 12:00:00' );
osd_same(
	array(
		'expired' => 3,
		'held'    => 2,
		'bound'   => 1,
	),
	$counts['attachment'],
	'All holds, including elapsed holds, must remain visible without double-counting expiration.'
);
osd_same( 2, $counts['zip']['expired'], 'Both ZIP purposes must be classified.' );
osd_same( 5, $counts['export']['expired'], 'Export retention must be classified separately from cleanup workers.' );
osd_same(
	array(
		'expired' => 1,
		'held'    => 0,
		'bound'   => 1,
	),
	$counts['worksheet'],
	'Bound worksheet assets must remain visible and untouched.'
);
osd_same( 1, $counts['other']['expired'], 'Unknown categories must not disappear.' );
$detail = new Read_Storage_Operations_Detail( new OSD_Storage_Port( $ready_result ), $heartbeats, static fn (): string => '2026-09-08 12:00:00', $backlog );
osd_same( $counts, $detail->execute()['backlog'], 'Breakdown must reach the authorized detail projection.' );
$database->last_error = 'Injected private SQL error';
osd_same( null, $detail->execute()['backlog'], 'Query failure must not invent zeros or expose SQL errors.' );
foreach ( $database->queries as $query ) {
	osd_assert( str_starts_with( $query, 'SELECT CASE ' ), 'Backlog diagnosis must be read-only aggregation.' );
	osd_assert( ! preg_match( '/storage_key|original_name|owner_user_id|SELECT\s+\*/i', $query ), 'Diagnosis must not fetch file content or identity.' );
}
osd_same( 16, (int) $database->connection->query( 'SELECT COUNT(*) FROM oj_ai_private_files' )->fetchColumn(), 'Diagnosis must never mutate records.' );
echo "Storage backlog contract passed: SQL categories, hold/bound protection, no identity, unavailable on error.\n";

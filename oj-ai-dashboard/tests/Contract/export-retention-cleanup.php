<?php
/**
 * SQL execution regression for export retention; isolated in-memory database only.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Storage_Backlog_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Private_Storage_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Cleanup_Repository;

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'src/Core/Autoloader.php';
( new Autoloader( ABSPATH . 'src' ) )->register();
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	throw new RuntimeException( 'pdo_sqlite is required; retention SQL was not tested.' );
}

/**
 * Check.
 *
 * @param bool   $ok Ok.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function check( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}

/**
 * SQL execution regression for export retention; isolated in-memory database only.
 */
final class Retention_DB {
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
	 * Log.
	 *
	 * @var array
	 */
	public array $log = array();
	/**
	 * After claim.
	 *
	 * @var Closure|null
	 */
	public ?Closure $after_claim = null;
	/**
	 * Fail sql.
	 *
	 * @var string|null
	 */
	public ?string $fail_sql = null;
	/**
	 * Initialize retention db dependencies and state.
	 */
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$catalog   = new Schema_Catalog();
		foreach ( array( 'oj_ai_private_files', 'oj_ai_export_jobs', 'oj_ai_pdf_artifacts', 'oj_ai_submission_attachments', 'oj_ai_zip_uploads', 'oj_ai_zip_upload_items', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $table ) {
			$columns = array();
			foreach ( $catalog->table( $table )->columns() as $name => $type ) {
				$columns[] = $name . ( 'id' === $name ? ' INTEGER PRIMARY KEY' : ( str_contains( $type, 'INT' ) ? ' INTEGER' : ' TEXT' ) );
			}
			$this->pdo->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
		}
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		$i   = 0;
		$sql = preg_replace_callback(
			'/%[sd]/',
			function ( array $m ) use ( &$i, $values ): string {
				if ( ! array_key_exists( $i, $values ) ) {
					throw new RuntimeException( 'Missing SQL binding.' );
				}
				$value = $values[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$sql
		);
		check( $i === count( $values ), 'Extra SQL bindings.' );
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $mode Mode.
	 * @return array|null
	 */
	public function get_row( string $sql, string $mode = '' ): ?array {
		return $this->get_results( $sql, $mode )[0] ?? null;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $mode Mode.
	 * @return array
	 */
	public function get_results( string $sql, string $mode = '' ): array {
		$this->log[] = $sql;
		return $this->pdo->query( str_replace( ' FOR UPDATE', '', $sql ) )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int {
		$this->log[] = $sql;
		if ( null !== $this->fail_sql && str_starts_with( $sql, $this->fail_sql ) ) {
			throw new RuntimeException( 'Injected write failure.' );
		}
		$result = $this->pdo->exec( 'START TRANSACTION' === $sql ? 'BEGIN' : $sql );
		if ( str_starts_with( $sql, 'UPDATE oj_ai_private_files SET lock_version' ) && null !== $this->after_claim ) {
			( $this->after_claim )( $this );
			$this->after_claim = null;
		}
		return $result;
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return int
	 */
	public function insert( string $table, array $data ): int {
		$sql = 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')';
		$this->pdo->prepare( $sql )->execute( array_values( $data ) );
		return 1;
	}
	/**
	 * Value.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function value( string $sql ): mixed {
		return $this->pdo->query( $sql )->fetchColumn();
	}
}

const FILE_ID   = '00000000000000000000000001';
const JOB_ID    = '00000000000000000000000002';
const PDF_ID    = '00000000000000000000000003';
const SOURCE_ID = '00000000000000000000000004';
const AUDIT_ID  = '00000000000000000000000005';
const OUTBOX_ID = '00000000000000000000000006';
const NOW_UTC   = '2026-09-08 12:00:00';
const RETRY_UTC = '2026-09-08 11:55:00';

/**
 * Fixture.
 *
 * @param string $kind Kind.
 * @return Retention_DB
 */
function fixture( string $kind = 'report_csv' ): Retention_DB {
	$db          = new Retention_DB();
	$pdf         = in_array( $kind, array( 'worksheet_pdf', 'submission_pdf', 'result_pdf', 'report_pdf' ), true );
	$source_type = match ( $kind ) {
		'worksheet_pdf', 'worksheet_package' => 'worksheet_revision', 'submission_pdf' => 'submission_revision',
		'result_pdf' => 'submission_release', 'submission_bundle' => 'assignment', default => 'report_snapshot',
	};
	$expiry = '2026-09-07 12:00:00';
	$db->insert(
		'oj_ai_private_files',
		array(
			'id'                 => 1,
			'public_id'          => FILE_ID,
			'lock_version'       => 0,
			'created_by_user_id' => 10,
			'created_at_utc'     => '2026-09-06 12:00:00',
			'updated_at_utc'     => '2026-09-06 12:00:00',
			'owner_type'         => $pdf ? 'pdf_artifact' : 'export_job',
			'owner_public_id'    => $pdf ? PDF_ID : JOB_ID,
			'purpose'            => $pdf ? 'generated_pdf' : $kind,
			'retention_class'    => 'generated_export_24h',
			'state'              => 'ready',
			'scan_state'         => 'clean',
			'storage_key'        => 'exports/example.bin',
			'sha256'             => str_repeat( 'a', 64 ),
			'expires_at_utc'     => $expiry,
			'mime_type'          => $pdf ? 'application/pdf' : match ( $kind ) {
			'report_csv' => 'text/csv', 'worksheet_package' => 'application/vnd.oj.ai-worksheet+zip', default => 'application/zip' },
		)
	);
	$db->insert(
		'oj_ai_export_jobs',
		array(
			'id'                    => 1,
			'public_id'             => JOB_ID,
			'lock_version'          => 0,
			'requested_by_user_id'  => 10,
			'state'                 => 'ready',
			'artifact_kind'         => $kind,
			'source_type'           => $source_type,
			'source_public_id'      => SOURCE_ID,
			'source_hash'           => str_repeat( 'b', 64 ),
			'result_file_public_id' => FILE_ID,
		)
	);
	if ( $pdf ) {
		$db->insert(
			'oj_ai_pdf_artifacts',
			array(
				'id'               => 1,
				'public_id'        => PDF_ID,
				'export_job_id'    => 1,
				'file_public_id'   => FILE_ID,
				'state'            => 'ready',
				'expires_at_utc'   => $expiry,
				'source_type'      => $source_type,
				'source_public_id' => SOURCE_ID,
				'source_hash'      => str_repeat(
					'b',
					64
				),
			)
		);
	}
	return $db;
}

/**
 * Run cleanup.
 *
 * @param Retention_DB $db Db.
 * @param callable     $delete Delete.
 * @param string       $now Clock value supplied for deterministic scheduling.
 * @param string       $retry Retry.
 * @return string
 */
function run_cleanup( Retention_DB $db, callable $delete, string $now = NOW_UTC, string $retry = RETRY_UTC ): string {
	return ( new WordPress_Export_Cleanup_Repository( $db ) )->purge_next( $now, $retry, $delete, AUDIT_ID, OUTBOX_ID );
}

$scenarios = 0;
foreach ( array( 'report_csv', 'report_json_package', 'submission_bundle', 'worksheet_package', 'worksheet_pdf', 'submission_pdf', 'result_pdf', 'report_pdf' ) as $kind ) {
	$db        = fixture( $kind );
	$deletions = 0;
	$backlog   = ( new WordPress_Storage_Backlog_Diagnostics( $db ) )->inspect( NOW_UTC );
	check( 1 === $backlog['export']['eligible'], $kind . ' not eligible in diagnostics.' );
	check(
		'purged' === run_cleanup(
			$db,
			function ( string $key ) use ( &$deletions, $db ): void {
				check( 'exports/example.bin' === $key, 'Unexpected file key.' );
				check( 'ready' === $db->value( 'SELECT state FROM oj_ai_private_files' ), 'Metadata changed before deletion.' );
				++$deletions;
			}
		),
		$kind . ' was not purged.'
	);
	check( 'expired' === $db->value( 'SELECT state FROM oj_ai_export_jobs' ), 'Job not expired.' );
	check( 1 === (int) $db->value( 'SELECT COUNT(*) FROM oj_ai_audit_events' ), 'Audit missing.' );
	check( 1 === (int) $db->value( 'SELECT COUNT(*) FROM oj_ai_outbox_events' ), 'Outbox missing.' );
	check( SOURCE_ID === $db->value( 'SELECT source_public_id FROM oj_ai_export_jobs' ), 'Source history changed.' );
	check(
		'idle' === run_cleanup(
			$db,
			function () use ( &$deletions ): void {
				++$deletions;
			}
		),
		'Repeated cleanup was not idle.'
	);
	check( 1 === $deletions, 'Repeated byte deletion.' );
	check( 0 === ( new WordPress_Storage_Backlog_Diagnostics( $db ) )->inspect( NOW_UTC )['export']['expired'], 'Cleaned file still warned.' );
	++$scenarios;
}

$blocked_cases = array(
	"UPDATE oj_ai_private_files SET legal_hold_until_utc = '2027-01-01 00:00:00'",
	"UPDATE oj_ai_private_files SET legal_hold_until_utc = '2020-01-01 00:00:00'",
	"UPDATE oj_ai_private_files SET state = 'bound'",
	"UPDATE oj_ai_private_files SET expires_at_utc = '2027-01-01 00:00:00'",
	'UPDATE oj_ai_private_files SET expires_at_utc = NULL',
	"UPDATE oj_ai_private_files SET purpose = 'worksheet_asset'",
	"UPDATE oj_ai_private_files SET retention_class = 'submission_staging'",
	'UPDATE oj_ai_private_files SET created_by_user_id = 999',
	"UPDATE oj_ai_private_files SET scan_state = 'pending'",
	"UPDATE oj_ai_private_files SET owner_public_id = '00000000000000000000000099'",
	"UPDATE oj_ai_export_jobs SET state = 'running'",
	"UPDATE oj_ai_export_jobs SET state = 'queued'",
	"UPDATE oj_ai_export_jobs SET state = 'failed'",
	"UPDATE oj_ai_export_jobs SET lease_token = 'busy'",
	"UPDATE oj_ai_export_jobs SET source_type = 'submission_revision'",
	"INSERT INTO oj_ai_submission_attachments (id,file_public_id) VALUES (1,'" . FILE_ID . "')",
	"INSERT INTO oj_ai_zip_uploads (id,file_public_id) VALUES (1,'" . FILE_ID . "')",
	"INSERT INTO oj_ai_zip_upload_items (id,file_public_id) VALUES (1,'" . FILE_ID . "')",
	"INSERT INTO oj_ai_export_jobs (id,result_file_public_id) VALUES (2,'" . FILE_ID . "')",
	"INSERT INTO oj_ai_pdf_artifacts (id,file_public_id) VALUES (2,'" . FILE_ID . "')",
);
foreach ( $blocked_cases as $sql ) {
	$db = fixture();
	$db->query( $sql );
	check(
		'idle' === run_cleanup(
			$db,
			static function (): void {
				throw new RuntimeException( 'Protected file deleted.' );
			}
		),
		$sql
	);
	check( 0 === (int) $db->value( 'SELECT COUNT(*) FROM oj_ai_audit_events' ), 'Protected file logged as deleted.' );
	++$scenarios;
}
foreach ( array( "UPDATE oj_ai_pdf_artifacts SET expires_at_utc = '2027-01-01 00:00:00'", "UPDATE oj_ai_pdf_artifacts SET source_hash = 'changed'", "UPDATE oj_ai_pdf_artifacts SET file_public_id = 'missing'" ) as $sql ) {
	$db = fixture( 'report_pdf' );
	$db->query( $sql );
	check(
		'idle' === run_cleanup(
			$db,
			static function (): void {
				throw new RuntimeException( 'Mismatched PDF deleted.' );
			}
		),
		'PDF metadata mismatch not blocked.'
	);
	++$scenarios;
}

// Mutation between selection and locked execution must invalidate the claim.
foreach ( array( "UPDATE oj_ai_private_files SET legal_hold_until_utc = '2027-01-01 00:00:00'", "UPDATE oj_ai_private_files SET storage_key = 'changed.bin'", "UPDATE oj_ai_private_files SET expires_at_utc = '2027-01-01 00:00:00'", 'UPDATE oj_ai_private_files SET lock_version = lock_version + 1', "UPDATE oj_ai_export_jobs SET state = 'running'" ) as $mutation ) {
	$db              = fixture();
	$db->after_claim = static fn ( Retention_DB $db ) => $db->pdo->exec( $mutation );
	check(
		'stale' === run_cleanup(
			$db,
			static function (): void {
				throw new RuntimeException( 'Stale claim deleted.' );
			}
		),
		'Stale mutation not blocked.'
	);
	++$scenarios;
}

foreach ( array( 'unlink', 'UPDATE oj_ai_export_jobs', 'COMMIT' ) as $failure ) {
	$db           = fixture( 'report_pdf' );
	$db->fail_sql = 'unlink' === $failure ? null : $failure;
	$deleted      = false;
	try {
		run_cleanup(
			$db,
			static function () use ( $failure, &$deleted ): void {
				if ( 'unlink' === $failure ) {
					throw new RuntimeException( 'Injected deletion failure.' );
				}
				$deleted = true;
			}
		);
		throw new LogicException( 'Failure not propagated.' );
	} catch ( RuntimeException $expected ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	check( 'ready' === $db->value( 'SELECT state FROM oj_ai_private_files' ), 'Failed operation marked purged.' );
	check( 'ready' === $db->value( 'SELECT state FROM oj_ai_export_jobs' ), 'Failed operation expired job.' );
	check( 0 === (int) $db->value( 'SELECT COUNT(*) FROM oj_ai_audit_events' ), 'Failed operation retained audit.' );
	$db->fail_sql = null;
	check(
		'idle' === run_cleanup(
			$db,
			static function (): void {
				throw new RuntimeException( 'Backoff bypassed.' );
			}
		),
		'Missing retry backoff.'
	);
	check(
		'purged' === run_cleanup(
			$db,
			static function (): void {
			},
			'2026-09-08 12:10:00',
			'2026-09-08 12:05:00'
		),
		'Crash recovery failed.'
	);
	check( 1 === (int) $db->value( 'SELECT COUNT(*) FROM oj_ai_audit_events' ), 'Crash recovery audit was not exact-once.' );
	++$scenarios;
}

// Count retained and active exports separately, and never hide inconsistent references.
foreach ( array(
	'preserved' => "UPDATE oj_ai_private_files SET legal_hold_until_utc = '2020-01-01 00:00:00'",
	'in_use'    => "UPDATE oj_ai_export_jobs SET state = 'running'",
	'blocked'   => "UPDATE oj_ai_private_files SET owner_public_id = 'missing'",
) as $category => $sql ) {
	$db = fixture();
	$db->query( $sql );
	$counts = ( new WordPress_Storage_Backlog_Diagnostics( $db ) )->inspect( NOW_UTC );
	check( 1 === $counts['export'][ $category ] && 0 === $counts['export']['eligible'], 'Retention disposition wrong.' );
	$health = ( new WordPress_Private_Storage_Diagnostics(
		static fn () => $db,
		static fn ( string $hook ): int => 1,
		static fn () => new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true ),
		static fn (): string => NOW_UTC,
		static fn (): bool => true
	) )->inspect();
	check( 0 === $health->metrics()['delayed_cleanup'], 'Retained export counted as overdue cleanup.' );
	check( ( 'blocked' === $category ? 'attention' : 'ready' ) === $health->status(), 'Storage warning disposition is incorrect.' );
	++$scenarios;
}

$db     = fixture();
$health = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => $db,
	static fn ( string $hook ): int => 1,
	static fn () => new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true ),
	static fn (): string => NOW_UTC,
	static fn (): bool => true
) )->inspect();
check( 'attention' === $health->status() && 1 === $health->metrics()['delayed_cleanup'], 'Eligible overdue export warning was hidden.' );
run_cleanup(
	$db,
	static function (): void {
	}
);
$health = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => $db,
	static fn ( string $hook ): int => 1,
	static fn () => new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true ),
	static fn (): string => NOW_UTC,
	static fn (): bool => true
) )->inspect();
check( 'ready' === $health->status() && 0 === $health->metrics()['cleanup_due'], 'Warning did not clear after successful cleanup.' );
++$scenarios;

echo 'PASS export retention: ' . (int) $scenarios . " SQL and transactional regression scenarios (SQLite; MySQL locking requires integration test).\n";

<?php
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_System_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Task_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\Local_Private_Storage_Path_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\WordPress_Private_Storage_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Port\Ai_Routing_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_Storage_Root;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Q28 storage assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q28 storage same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q28_storage_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Prepared sql.
	 *
	 * @var string
	 */
	public string $prepared_sql = '';
	/**
	 * Prepared values.
	 *
	 * @var array<int,mixed>
	 */
	public array $prepared_values = array();
	/**
	 * Initialize q28 storage database dependencies and state.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	public function __construct( public ?array $row ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->prepared_sql    = $sql;
		$this->prepared_values = $values;
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		return $this->row;
	}
}

/**
 * Q28 storage zero row.
 *
 * @return array<string,string>
 */
function q28_storage_zero_row(): array {
	return array(
		'pending_scan_count'    => '0',
		'delayed_scan_count'    => '0',
		'cleanup_due_count'     => '0',
		'delayed_cleanup_count' => '0',
		'metadata_issue_count'  => '0',
	);
}

/**
 * Q28 storage ready path.
 *
 * @return Storage_Path_Diagnostics
 */
function q28_storage_ready_path(): Storage_Path_Diagnostics {
	return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true );
}

/**
 * Q28 storage schedule.
 *
 * @param array<int,string> &$calls Calls.
 * @param int               $missing_index Missing index.
 * @return Closure(string):int|false
 */
function q28_storage_schedule( array &$calls, int $missing_index = -1 ): Closure {
	return static function ( string $hook ) use ( &$calls, $missing_index ) {
		$calls[] = $hook;
		$index   = count( $calls ) - 1;
		return $index === $missing_index ? false : 1_777_111_200 + $index;
	};
}

$schedule_calls = array();
$ready_database = new Q28_Storage_Database( q28_storage_zero_row() );
$ready_adapter  = new WordPress_Private_Storage_Diagnostics(
	static fn () => $ready_database,
	q28_storage_schedule( $schedule_calls ),
	static fn (): Storage_Path_Diagnostics => q28_storage_ready_path(),
	static fn (): string => '2026-08-25 12:00:00',
	static fn (): bool => true
);
$ready          = $ready_adapter->inspect();
q28_storage_same( Storage_Diagnostics_Result::READY, $ready->status(), 'Healthy private storage was not ready.' );
q28_storage_same( 'storage_runtime_ready', $ready->code(), 'Ready private storage code drifted.' );
q28_storage_same( 'responding', $ready->scanner_status(), 'Healthy scanner observation was lost.' );
q28_storage_same(
	array(
		Submission_Attachment_Scan_Worker_Module::SWEEP_HOOK,
		Submission_Attachment_Cleanup_Worker_Module::SWEEP_HOOK,
		Submission_Zip_Scan_Worker_Module::SWEEP_HOOK,
		Submission_Zip_Cleanup_Worker_Module::SWEEP_HOOK,
		\OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module::SWEEP_HOOK,
	),
	$schedule_calls,
	'Q28 did not inspect the four storage scan and cleanup workers exactly once.'
);
q28_storage_same( 5, $ready->metrics()['expected_workers'], 'Storage worker count drifted.' );
q28_storage_same( 5, $ready->metrics()['scheduled_workers'], 'A ready storage worker schedule was lost.' );
q28_storage_assert( str_contains( $ready_database->prepared_sql, 'FROM oj_ai_private_files' ), 'Storage diagnostics did not use the literal metadata table.' );
q28_storage_assert( ! str_contains( $ready_database->prepared_sql, 'wp_oj_ai_' ), 'Storage diagnostics prefixed the literal table.' );
q28_storage_assert( ! str_contains( $ready_database->prepared_sql, 'storage_key' ), 'Storage diagnostics selected a private storage key.' );
q28_storage_assert( ! str_contains( $ready_database->prepared_sql, 'original_name' ), 'Storage diagnostics selected a private filename.' );
q28_storage_assert( ! str_contains( $ready_database->prepared_sql, 'sha256' ), 'Storage diagnostics selected a private file hash.' );
q28_storage_assert( in_array( '2026-08-25 11:45:00', $ready_database->prepared_values, true ), 'Storage diagnostics lost the 15-minute delay window.' );
q28_storage_assert( in_array( 'expired', $ready_database->prepared_values, true ), 'Storage diagnostics rejected the cleanup worker expired state.' );

$attention_row                          = q28_storage_zero_row();
$attention_row['pending_scan_count']    = '4';
$attention_row['delayed_scan_count']    = '2';
$attention_row['cleanup_due_count']     = '5';
$attention_row['delayed_cleanup_count'] = '3';
$attention_row['metadata_issue_count']  = '1';
$attention_calls                        = array();
$attention                              = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => new Q28_Storage_Database( $attention_row ),
	q28_storage_schedule( $attention_calls ),
	static fn (): Storage_Path_Diagnostics => q28_storage_ready_path(),
	static fn (): string => '2026-08-25 12:00:00',
	static fn (): bool => true
) )->inspect();
q28_storage_same( Storage_Diagnostics_Result::ATTENTION, $attention->status(), 'Delayed or inconsistent private storage did not require attention.' );
q28_storage_same( 'storage_metadata_inconsistent', $attention->code(), 'Storage issue priority drifted.' );
q28_storage_same(
	array( 'storage_metadata_inconsistent', 'storage_scan_delayed', 'storage_cleanup_delayed' ),
	$attention->issue_codes(),
	'Storage issue aggregation drifted.'
);

$inactive_calls = array();
$inactive       = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => new Q28_Storage_Database( q28_storage_zero_row() ),
	q28_storage_schedule( $inactive_calls, 2 ),
	static fn (): Storage_Path_Diagnostics => q28_storage_ready_path(),
	static fn (): string => '2026-08-25 12:00:00',
	static fn (): bool => true
) )->inspect();
q28_storage_same( Storage_Diagnostics_Result::UNAVAILABLE, $inactive->status(), 'A missing storage worker did not fail closed.' );
q28_storage_same( 'storage_schedule_inactive', $inactive->code(), 'A missing storage worker used an unstable code.' );
q28_storage_same( 4, $inactive->metrics()['scheduled_workers'], 'Storage schedule count drifted.' );

$unsafe_path  = new Storage_Path_Diagnostics( Storage_Path_Diagnostics::UNAVAILABLE, 'storage_path_not_private', false, false, false, false );
$unsafe_calls = array();
$unsafe       = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => new Q28_Storage_Database( q28_storage_zero_row() ),
	q28_storage_schedule( $unsafe_calls ),
	static fn (): Storage_Path_Diagnostics => $unsafe_path,
	static fn (): string => '2026-08-25 12:00:00',
	static fn (): bool => true
) )->inspect();
q28_storage_same( Storage_Diagnostics_Result::UNAVAILABLE, $unsafe->status(), 'A public storage path was accepted.' );
q28_storage_same( 'storage_path_not_private', $unsafe->code(), 'Unsafe path code drifted.' );
q28_storage_same( false, $unsafe->path()['private'], 'Unsafe path projection was not fail closed.' );

$broken_database             = new Q28_Storage_Database( null );
$broken_database->last_error = 'raw SQL secret-marker D:\\private\\absolute';
$broken_calls                = array();
$broken                      = ( new WordPress_Private_Storage_Diagnostics(
	static fn () => $broken_database,
	q28_storage_schedule( $broken_calls ),
	static fn (): Storage_Path_Diagnostics => q28_storage_ready_path(),
	static fn (): string => '2026-08-25 12:00:00',
	static fn (): bool => true
) )->inspect();
q28_storage_same( Storage_Diagnostics_Result::UNAVAILABLE, $broken->status(), 'A storage metadata error did not fail closed.' );
q28_storage_same( 'storage_observation_failed', $broken->code(), 'A storage metadata error leaked its raw reason.' );

foreach ( array(
	static fn (): bool => false,
	static fn (): string => 'PONG',
	static function (): never {
		throw new RuntimeException( 'private socket address' );
	},
) as $probe ) {
	$probe_calls     = array();
	$scanner_failure = ( new WordPress_Private_Storage_Diagnostics(
		static fn () => new Q28_Storage_Database( q28_storage_zero_row() ),
		q28_storage_schedule( $probe_calls ),
		static fn (): Storage_Path_Diagnostics => q28_storage_ready_path(),
		static fn (): string => '2026-08-25 12:00:00',
		$probe
	) )->inspect();
	q28_storage_same( Storage_Diagnostics_Result::ATTENTION, $scanner_failure->status(), 'Unavailable scanner cannot be reported ready even with no backlog.' );
	q28_storage_same( 'storage_scanner_unavailable', $scanner_failure->code(), 'Scanner failure must have a stable, content-free code.' );
	q28_storage_same( 'unavailable', $scanner_failure->scanner_status(), 'Failed scanner observation was lost.' );
	q28_storage_same( 0, $scanner_failure->metrics()['pending_scans'], 'Scanner outage must not fabricate pending file counts.' );
}

$fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-storage-diagnostic-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $fixture_root, 0700, true ) && ! is_dir( $fixture_root ) ) {
	throw new RuntimeException( 'Storage diagnostic fixture directory could not be created.' );
}
$missing_root = $fixture_root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'site-1';
$path_result  = ( new Local_Private_Storage_Path_Diagnostics( $missing_root, ABSPATH ) )->inspect();
q28_storage_same( Storage_Path_Diagnostics::READY, $path_result->status(), 'A private writable parent was not accepted for lazy root creation.' );
q28_storage_assert( ! file_exists( $missing_root ), 'Read-only storage diagnostics created the private root.' );
$public_result = ( new Local_Private_Storage_Path_Diagnostics( ABSPATH . 'future-private', ABSPATH ) )->inspect();
q28_storage_same( Storage_Path_Diagnostics::UNAVAILABLE, $public_result->status(), 'A path below the public web root was accepted.' );
q28_storage_same( 'storage_path_not_private', $public_result->code(), 'Public path rejection code drifted.' );
q28_storage_assert( rmdir( $fixture_root ), 'Storage diagnostic fixture directory could not be removed.' );

$fallback_fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-storage-root-' . bin2hex( random_bytes( 6 ) );
$fallback_web     = $fallback_fixture . DIRECTORY_SEPARATOR . 'public';
$fallback_home    = $fallback_fixture . DIRECTORY_SEPARATOR . 'home';
$fallback_legacy  = $fallback_fixture . DIRECTORY_SEPARATOR . 'blocked-parent';
if (
	( ! mkdir( $fallback_web, 0700, true ) && ! is_dir( $fallback_web ) )
	|| ( ! mkdir( $fallback_home, 0700, true ) && ! is_dir( $fallback_home ) )
	|| false === file_put_contents( $fallback_legacy, 'not-a-directory' )
) {
	throw new RuntimeException( 'Storage root fallback fixture could not be created.' );
}
try {
	$selected_home = Local_Private_Storage_Root::resolve(
		null,
		array(
			'site_id'          => 7,
			'legacy_parent'    => $fallback_legacy,
			'home_directories' => array( $fallback_home ),
			'web_root'         => $fallback_web,
		)
	);
	$expected_home = $fallback_home . DIRECTORY_SEPARATOR . '.oj-ai-private' . DIRECTORY_SEPARATOR . 'site-7';
	q28_storage_same( $expected_home, $selected_home, 'Writable HOME fallback was not selected outside the web root.' );
	$fallback_result = ( new Local_Private_Storage_Path_Diagnostics( $selected_home, $fallback_web ) )->inspect();
	q28_storage_same( Storage_Path_Diagnostics::READY, $fallback_result->status(), 'Writable HOME fallback did not pass read-only diagnostics.' );
	q28_storage_assert( ! file_exists( $selected_home ), 'HOME fallback selection created the private root.' );

	$legacy_parent = $fallback_fixture . DIRECTORY_SEPARATOR . 'legacy';
	$legacy_root   = $legacy_parent . DIRECTORY_SEPARATOR . 'oj-ai-private' . DIRECTORY_SEPARATOR . 'site-7';
	if ( ! mkdir( $legacy_root, 0700, true ) && ! is_dir( $legacy_root ) ) {
		throw new RuntimeException( 'Existing legacy storage fixture could not be created.' );
	}
	$selected_legacy = Local_Private_Storage_Root::resolve(
		null,
		array(
			'site_id'          => 7,
			'legacy_parent'    => $legacy_parent,
			'home_directories' => array( $fallback_home ),
			'web_root'         => $fallback_web,
		)
	);
	q28_storage_same( $legacy_root, $selected_legacy, 'An existing legacy private root was not preserved.' );
} finally {
	if ( is_file( $fallback_legacy ) ) {
		unlink( $fallback_legacy );
	}
	if ( isset( $legacy_root ) && is_dir( $legacy_root ) ) {
		rmdir( $legacy_root );
	}
	if ( isset( $legacy_parent ) && is_dir( $legacy_parent . DIRECTORY_SEPARATOR . 'oj-ai-private' ) ) {
		rmdir( $legacy_parent . DIRECTORY_SEPARATOR . 'oj-ai-private' );
	}
	if ( isset( $legacy_parent ) && is_dir( $legacy_parent ) ) {
		rmdir( $legacy_parent );
	}
	if ( is_dir( $fallback_web ) ) {
		rmdir( $fallback_web );
	}
	if ( is_dir( $fallback_home ) ) {
		rmdir( $fallback_home );
	}
	if ( is_dir( $fallback_fixture ) ) {
		rmdir( $fallback_fixture );
	}
}

/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Bridge implements Bridge_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	public function inspect(): Bridge_Diagnostics_Result {
		return new Bridge_Diagnostics_Result( Bridge_Diagnostics_Result::READY, 'addon_runtime_ready', '2.0.0-dev.74', '1.1.0' );
	}
}
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Schema implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Verify q28 storage schema.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		return new Schema_Runtime_Readiness_Result( Schema_Runtime_Readiness_Result::READY, 'schema_runtime_ready' );
	}
}
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Queue implements Queue_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 */
	public function inspect(): Queue_Diagnostics_Result {
		return new Queue_Diagnostics_Result(
			Queue_Diagnostics_Result::READY,
			'queue_runtime_ready',
			array(
				'expected_workers'  => 4,
				'scheduled_workers' => 4,
				'pending'           => 0,
				'active'            => 0,
				'delayed'           => 0,
				'stale_leases'      => 0,
				'recent_failures'   => 0,
			)
		);
	}
}
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Ai implements Ai_Routing_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Ai_Routing_Diagnostics_Result
	 */
	public function inspect(): Ai_Routing_Diagnostics_Result {
		return new Ai_Routing_Diagnostics_Result(
			new Ai_Routing_Task_Diagnostics( Ai_Routing_Task_Diagnostics::SUBJECT_WORKSHEET_QUALITY, Ai_Routing_Task_Diagnostics::READY, 'ai_task_routing_ready', 'route-r1', 'profile-r1' ),
			new Ai_Routing_Task_Diagnostics( Ai_Routing_Task_Diagnostics::SUBJECT_STUDENT_SUBMISSION, Ai_Routing_Task_Diagnostics::READY, 'ai_task_routing_ready', 'route-r1', 'profile-r1' )
		);
	}
}
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Storage_Port implements Storage_Diagnostics_Port_Interface {
	/**
	 * Initialize q28 storage port dependencies and state.
	 *
	 * @param Storage_Diagnostics_Result $result Result.
	 */
	public function __construct( private Storage_Diagnostics_Result $result ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 */
	public function inspect(): Storage_Diagnostics_Result {
		return $this->result;
	}
}
/**
 * G5-IMP-05-S03 Q28 private storage and secret-free projection contract.
 */
final class Q28_Throwing_Storage implements Storage_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Storage_Diagnostics_Result {
		throw new RuntimeException( 'D:\\secret\\private API_KEY=hidden' );
	}
}

$projection = ( new Read_System_Diagnostics(
	new Q28_Storage_Bridge(),
	new Q28_Storage_Schema(),
	new Q28_Storage_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	new Q28_Storage_Ai(),
	new Q28_Storage_Port( $ready )
) )->execute();
q28_storage_same( array( 'bridge', 'schema', 'queue', 'storage', 'ai_routing' ), array_keys( $projection['checks'] ), 'Q28 five-check order drifted.' );
q28_storage_same( 'ready', $projection['status'], 'Ready storage did not preserve the ready Q28 aggregate.' );
q28_storage_same( 'none', $projection['checks']['storage']['next_action'], 'Ready storage exposed an unnecessary action.' );
q28_storage_same( array( 'private', 'readable', 'writable', 'space_available' ), array_keys( $projection['checks']['storage']['path'] ), 'Storage path projection exposed an unexpected field.' );

$safe_json = json_encode( $projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
foreach ( array(
	'storage_key',
	'original_name',
	'sha256',
	'oj_ai_private_files',
	'wp_oj_ai_',
	'$wpdb->prefix',
	'api_key',
	'authorization',
	'bearer ',
	'credential',
	'secret_reference',
	'endpoint',
	'raw sql',
	'secret-marker',
	'd:\\private',
	'provider_key',
	'model_key',
) as $forbidden ) {
	q28_storage_assert( ! str_contains( strtolower( $safe_json ), strtolower( $forbidden ) ), 'Q28 leaked a forbidden storage or secret value: ' . $forbidden );
}

$throwing_projection = ( new Read_System_Diagnostics(
	new Q28_Storage_Bridge(),
	new Q28_Storage_Schema(),
	new Q28_Storage_Queue(),
	static fn (): string => '2026-08-25 12:00:00',
	new Q28_Storage_Ai(),
	new Q28_Throwing_Storage()
) )->execute();
q28_storage_same( 'unavailable', $throwing_projection['checks']['storage']['status'], 'A thrown storage diagnostic did not fail closed.' );
q28_storage_same( 'storage_observation_failed', $throwing_projection['checks']['storage']['code'], 'A thrown storage diagnostic leaked its reason.' );
q28_storage_same( 'retry_storage_check', $throwing_projection['checks']['storage']['next_action'], 'A thrown storage diagnostic lost its safe action.' );
q28_storage_assert( ! str_contains( strtolower( json_encode( $throwing_projection, JSON_THROW_ON_ERROR ) ), 'api_key' ), 'Thrown storage error text leaked into Q28.' );

$diagnostic_source = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Operations' . DIRECTORY_SEPARATOR . 'Diagnostics' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Private_Storage_Diagnostics.php' );
$path_source       = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Operations' . DIRECTORY_SEPARATOR . 'Diagnostics' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Local_Private_Storage_Path_Diagnostics.php' );
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'storage_key', 'original_name', 'sha256', '->insert(', '->update(', '->delete(', '->query(' ) as $forbidden ) {
	q28_storage_assert( ! str_contains( $diagnostic_source, $forbidden ), 'Storage diagnostics crossed a read-only or privacy boundary: ' . $forbidden );
}
foreach ( array( 'mkdir(', 'fopen(', 'file_put_contents(', 'rename(', 'unlink(', 'chmod(' ) as $forbidden ) {
	q28_storage_assert( ! str_contains( $path_source, $forbidden ), 'Storage path diagnostics performed a filesystem mutation: ' . $forbidden );
}
$store_source = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Local_Private_File_Store.php' );
q28_storage_assert( str_contains( $store_source, 'Local_Private_Storage_Root::resolve( $root )' ), 'File storage and diagnostics do not share the canonical root resolver.' );

$composition_source = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Composition_Root.php' );
q28_storage_assert( str_contains( $composition_source, 'new WordPress_Private_Storage_Diagnostics()' ), 'Composition Root did not inject private storage diagnostics.' );

fwrite( STDOUT, "G5-IMP-05-S03 passed: Q28 reports private path, four storage workers, scan and cleanup delay, and metadata consistency without paths, file identity, secrets, writes, or AI execution.\n" );

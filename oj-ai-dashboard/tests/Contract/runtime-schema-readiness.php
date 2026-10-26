<?php
/**
 * G1 read-only Runtime schema readiness contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Ledger_Reader;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Runtime_Readiness;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Reader_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;
use OJAI\Dashboard\Database\Foundation\Schema_Migration_Checkpoint;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Verifier;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Runtime schema assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function runtime_schema_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Runtime schema same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function runtime_schema_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G1 read-only Runtime schema readiness contract.
 */
final class Runtime_Schema_Inspector_Fixture implements Schema_Inspector_Port_Interface {
	/**
	 * Initialize runtime schema inspector fixture dependencies and state.
	 *
	 * @param array<int,string>                 $names Names.
	 * @param array<string,array<string,mixed>> $metadata Metadata.
	 * @param bool                              $throw_on_names Throw on names.
	 * @param bool                              $throw_on_metadata Throw on metadata.
	 */
	public function __construct(
		public array $names,
		public array $metadata,
		public bool $throw_on_names = false,
		public bool $throw_on_metadata = false
	) {
	}

	/**
	 * Literal table names.
	 *
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function literal_table_names(): array {
		if ( $this->throw_on_names ) {
			throw new RuntimeException( 'raw_database_error_must_not_escape' );
		}

		return $this->names;
	}

	/**
	 * Metadata.
	 *
	 * @param array $table_names Table names.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function metadata( array $table_names ): array {
		if ( $this->throw_on_metadata ) {
			throw new RuntimeException( 'raw_metadata_error_must_not_escape' );
		}

		$result = array();
		foreach ( $table_names as $table_name ) {
			if ( isset( $this->metadata[ $table_name ] ) ) {
				$result[ $table_name ] = $this->metadata[ $table_name ];
			}
		}

		return $result;
	}
}

/**
 * G1 read-only Runtime schema readiness contract.
 */
final class Runtime_Schema_Ledger_Fixture implements Schema_Ledger_Reader_Port_Interface {
	/**
	 * Installation reads.
	 *
	 * @var int
	 */
	public int $installation_reads = 0;
	/**
	 * Migration reads.
	 *
	 * @var int
	 */
	public int $migration_reads = 0;

	/**
	 * Initialize runtime schema ledger fixture dependencies and state.
	 *
	 * @param array<string,mixed>|null          $installation Installation.
	 * @param array<string,array<string,mixed>> $migrations Migrations.
	 */
	public function __construct(
		public ?array $installation,
		public array $migrations
	) {
	}

	/**
	 * Find installation.
	 *
	 * @return array|null
	 */
	public function find_installation(): ?array {
		++$this->installation_reads;

		return $this->installation;
	}

	/**
	 * All migrations.
	 *
	 * @return array
	 */
	public function all_migrations(): array {
		++$this->migration_reads;

		return $this->migrations;
	}
}

/**
 * G1 read-only Runtime schema readiness contract.
 */
final class Runtime_Read_Only_Database_Fixture {
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Initialize runtime read only database fixture dependencies and state.
	 *
	 * @param array<string,mixed>            $installation Installation.
	 * @param array<int,array<string,mixed>> $migrations Migrations.
	 */
	public function __construct(
		private array $installation,
		private array $migrations
	) {
	}

	/**
	 * Prepare.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $query, mixed ...$values ): string {
		$this->queries[] = $query;

		return str_replace( '%s', (string) ( $values[0] ?? '' ), $query );
	}

	/**
	 * Get row.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<string,mixed>
	 */
	public function get_row( string $query, mixed $mode ): array {
		$this->queries[] = $query;

		return $this->installation;
	}

	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, mixed $mode ): array {
		$this->queries[] = $query;

		return $this->migrations;
	}
}

/**
 * Runtime schema fixture.
 *
 * @param string $state State.
 * @return array{
 *   0:Schema_Catalog,
 *   1:Migration_Plan,
 *   2:Runtime_Schema_Inspector_Fixture,
 *   3:Runtime_Schema_Ledger_Fixture,
 *   4:Schema_Runtime_Readiness_Verifier
 * }
 */
function runtime_schema_fixture(
	string $state = Installation_State::REGISTRATION_PENDING
): array {
	$catalog  = new Schema_Catalog();
	$plan     = new Migration_Plan( $catalog );
	$metadata = array();
	foreach ( $catalog->tables() as $table_name => $definition ) {
		$metadata[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
	}

	$installation = array(
		'addon_id'           => Schema_Catalog::ADDON_ID,
		'owner_marker'       => $catalog->owner_marker(),
		'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
		'schema_fingerprint' => $catalog->fingerprint(),
		'state'              => $state,
	);
	$migrations   = array();
	foreach ( $plan->migrations() as $migration ) {
		$checkpoint                     = array(
			'phase'            => 'completed',
			'cursor'           => count( $migration->table_names() ),
			'completed_tables' => $migration->table_names(),
			'next_table'       => null,
			'postcondition'    => 'passed',
			'correlation_id'   => 'runtime:fixture-applied',
			'repair'           => false,
		);
		$migrations[ $migration->id() ] = array_merge(
			array(
				'migration_id' => $migration->id(),
				'checksum'     => $migration->checksum(),
				'state'        => Migration_Ledger_State::APPLIED,
			),
			Schema_Migration_Checkpoint::columns( $checkpoint )
		);
	}

	$inspector = new Runtime_Schema_Inspector_Fixture( $catalog->table_names(), $metadata );
	$ledger    = new Runtime_Schema_Ledger_Fixture( $installation, $migrations );
	$verifier  = new Schema_Runtime_Readiness_Verifier(
		$catalog,
		$plan,
		new Schema_Preflight( $catalog ),
		$inspector,
		$ledger,
		new Schema_Postcondition_Verifier( $catalog, $inspector )
	);

	return array( $catalog, $plan, $inspector, $ledger, $verifier );
}

list( , , , $ready_ledger, $ready_verifier ) = runtime_schema_fixture();
$ready_result                                = $ready_verifier->verify();
runtime_schema_assert( $ready_result->is_ready(), 'A registration_pending exact schema must be Runtime ready.' );
runtime_schema_same( 'schema_runtime_ready', $ready_result->code(), 'The ready code changed.' );
runtime_schema_same( 1, $ready_ledger->installation_reads, 'Runtime verification must read the owner row once.' );
runtime_schema_same( 1, $ready_ledger->migration_reads, 'Runtime verification must read the migration ledger once.' );

list( , , , , $already_ready_verifier ) = runtime_schema_fixture( Installation_State::RUNTIME_READY );
runtime_schema_assert(
	$already_ready_verifier->verify()->is_ready(),
	'A previously runtime_ready schema must remain eligible after a new boot.'
);

list( , , $fresh_inspector, $fresh_ledger, $fresh_verifier ) = runtime_schema_fixture();
$fresh_inspector->names                                      = array( 'wp_oj_ai_worksheets' );
$fresh_ledger->installation                                  = null;
$fresh_result = $fresh_verifier->verify();
runtime_schema_same( 'schema_not_installed', $fresh_result->code(), 'A fresh schema must fail closed.' );
runtime_schema_same( 0, $fresh_ledger->installation_reads, 'Absent owner tables must not be queried.' );
runtime_schema_same( 0, $fresh_ledger->migration_reads, 'Fresh schemas must not query migration rows.' );

list( , , , , $maintenance_verifier ) = runtime_schema_fixture( Installation_State::MAINTENANCE_REQUIRED );
$maintenance_result                   = $maintenance_verifier->verify();
runtime_schema_same(
	'schema_installation_not_ready',
	$maintenance_result->code(),
	'Maintenance state must block Runtime connection.'
);
runtime_schema_assert(
	in_array( 'installation_state_maintenance_required', $maintenance_result->issue_codes(), true ),
	'Maintenance state must retain a stable internal issue code.'
);

list( , , $partial_inspector, , $partial_verifier ) = runtime_schema_fixture();
$partial_inspector->names                           = array_slice( $partial_inspector->names, 0, 3 );
$partial_result                                     = $partial_verifier->verify();
runtime_schema_same( 'schema_preflight_blocked', $partial_result->code(), 'Partial schemas must fail preflight.' );
runtime_schema_assert(
	in_array( 'partial_schema', $partial_result->issue_codes(), true ),
	'Partial schemas must expose only the stable preflight issue code.'
);

list( , , $drift_inspector, , $drift_verifier )             = runtime_schema_fixture();
$drift_inspector->metadata['oj_ai_installations']['engine'] = 'MyISAM';
$drift_result = $drift_verifier->verify();
runtime_schema_same( 'schema_catalog_drift', $drift_result->code(), 'Physical metadata drift must fail closed.' );
runtime_schema_assert(
	in_array( 'table_engine_mismatch', $drift_result->issue_codes(), true ),
	'Physical drift must retain its stable issue code.'
);

list( , $ledger_plan, , $invalid_ledger, $invalid_ledger_verifier ) = runtime_schema_fixture();
$migration_ids = array_keys( $invalid_ledger->migrations );
unset( $invalid_ledger->migrations[ $migration_ids[0] ] );
$invalid_ledger->migrations[ $migration_ids[1] ]['checksum'] = str_repeat( '0', 64 );
$invalid_ledger->migrations[ $migration_ids[1] ]['state']    = Migration_Ledger_State::RUNNING;
$invalid_ledger->migrations['209901010000_unknown']          = array(
	'migration_id' => '209901010000_unknown',
	'checksum'     => str_repeat( 'f', 64 ),
	'state'        => Migration_Ledger_State::APPLIED,
);
$invalid_ledger_result                                       = $invalid_ledger_verifier->verify();
runtime_schema_same(
	'schema_migration_ledger_invalid',
	$invalid_ledger_result->code(),
	'Incomplete or changed migration history must fail closed.'
);
foreach (
	array(
		'schema_migration_missing',
		'schema_checksum_mismatch',
		'schema_migration_not_applied',
		'unknown_schema_migration',
	) as $issue_code
) {
	runtime_schema_assert(
		in_array( $issue_code, $invalid_ledger_result->issue_codes(), true ),
		'Migration ledger diagnostics are missing: ' . $issue_code
	);
}
runtime_schema_same( 6, count( $ledger_plan->migrations() ), 'The Runtime test fixture must cover the frozen plan.' );

list( , , $throwing_inspector, , $throwing_verifier ) = runtime_schema_fixture();
$throwing_inspector->throw_on_names                   = true;
$failure_result                                       = $throwing_verifier->verify();
runtime_schema_same(
	'schema_runtime_verification_failed',
	$failure_result->code(),
	'Raw database errors must collapse to one stable Runtime code.'
);
runtime_schema_same( array(), $failure_result->issue_codes(), 'Raw database details must not escape.' );

list( $reader_catalog, $reader_plan, , $reader_ledger ) = runtime_schema_fixture();
$read_database = new Runtime_Read_Only_Database_Fixture(
	$reader_ledger->installation ?? array(),
	array_values( $reader_ledger->migrations )
);
$reader        = new WordPress_Schema_Ledger_Reader( $read_database );
runtime_schema_same( Schema_Catalog::ADDON_ID, $reader->find_installation()['addon_id'] ?? '', 'Reader owner lookup failed.' );
runtime_schema_same( 6, count( $reader->all_migrations() ), 'Reader migration lookup failed.' );
foreach ( $read_database->queries as $query ) {
	runtime_schema_assert( str_starts_with( ltrim( $query ), 'SELECT ' ), 'Runtime ledger queries must be SELECT-only.' );
	runtime_schema_assert( ! str_contains( $query, 'wp_oj_ai_' ), 'Runtime ledger queries must not use a WP prefix.' );
}
runtime_schema_same( 48, count( $reader_catalog->table_names() ), 'Reader fixture must use the exact frozen catalog.' );
runtime_schema_same( 6, count( $reader_plan->migrations() ), 'Reader fixture must use the exact frozen plan.' );

$database_provider_calls = 0;
new WordPress_Schema_Runtime_Readiness(
	static function () use ( &$database_provider_calls ): object {
		++$database_provider_calls;

		return new stdClass();
	}
);
runtime_schema_same( 0, $database_provider_calls, 'Composition must not touch the database before accepted onReady.' );

$read_only_sources = array(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Foundation'
		. DIRECTORY_SEPARATOR . 'Schema_Runtime_Readiness_Verifier.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Foundation'
		. DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Schema_Runtime_Readiness.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Foundation'
		. DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Schema_Ledger_Reader.php',
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform'
		. DIRECTORY_SEPARATOR . 'Addon_Runtime_Readiness_Gate.php',
);
$read_only_runtime = '';
foreach ( $read_only_sources as $source ) {
	$read_only_runtime .= (string) file_get_contents( $source );
}
foreach (
	array(
		'->insert(',
		'->update(',
		'->delete(',
		'->query(',
		'GET_LOCK(',
		'RELEASE_LOCK(',
		'CREATE TABLE',
		'ALTER TABLE',
		'DROP TABLE',
		'dbDelta(',
		'update_option(',
		'add_option(',
		'$wpdb->prefix',
		'Schema_Installation_Runner',
		'Schema_Statement_Executor',
		'Schema_Lock',
		'new WordPress_Schema_Ledger(',
	) as $forbidden
) {
	runtime_schema_assert(
		! str_contains( $read_only_runtime, $forbidden ),
		'Accepted onReady contains a write or legacy database dependency: ' . $forbidden
	);
}

$reader_port_source = (string) file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Foundation'
	. DIRECTORY_SEPARATOR . 'Port' . DIRECTORY_SEPARATOR . 'Schema_Ledger_Reader_Port_Interface.php'
);
foreach (
	array(
		'create_installation',
		'update_installation',
		'start_migration',
		'resume_migration',
		'save_checkpoint',
		'finish_migration',
	) as $mutation_method
) {
	runtime_schema_assert(
		! str_contains( $reader_port_source, $mutation_method ),
		'The Runtime ledger Port exposes a mutation method: ' . $mutation_method
	);
}

echo "G1 Runtime schema readiness contract passed: exact owner/catalog/ledger verification, SELECT-only adapter, fail-closed codes.\n";

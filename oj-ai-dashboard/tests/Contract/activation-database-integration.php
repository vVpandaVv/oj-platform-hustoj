<?php
/**
 * G1 activation and database integration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Activation_Workflow;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Installation_Runner_Factory;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Activation database assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function activation_database_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Deterministic installation port double.
 */
final class Activation_Database_Test_Installer implements Schema_Installer_Port_Interface {
	/**
	 * Outcome.
	 *
	 * @var Schema_Installation_Result|Throwable
	 */
	private $outcome;
	/**
	 * On install.
	 *
	 * @var Closure
	 */
	private Closure $on_install;

	/**
	 * Initialize activation database test installer dependencies and state.
	 *
	 * @param Schema_Installation_Result|Throwable $outcome Outcome.
	 * @param Closure                              $on_install On install.
	 */
	public function __construct( $outcome, Closure $on_install ) {
		$this->outcome    = $outcome;
		$this->on_install = $on_install;
	}

	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		( $this->on_install )( $actor_user_id, $correlation_id );
		if ( $this->outcome instanceof Throwable ) {
			throw $this->outcome;
		}

		return $this->outcome;
	}
}

/**
 * Constructor-only wpdb double for the WordPress runner factory.
 */
final class Activation_Database_Test_Wpdb {
	/**
	 * Get col.
	 *
	 * @param string $query Query.
	 * @return array<int,string>
	 */
	public function get_col( string $query ): array {
		return array();
	}

	/**
	 * Get results.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, $mode = null ): array {
		return array();
	}

	/**
	 * Get row.
	 *
	 * @param string $query Query.
	 * @param mixed  $mode Mode.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, $mode = null ): ?array {
		return null;
	}

	/**
	 * Get var.
	 *
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( string $query ) {
		return null;
	}

	/**
	 * Prepare.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @param array  $format Format.
	 * @return int
	 */
	public function insert( string $table, array $data, array $format = array() ): int {
		return 1;
	}

	/**
	 * Update activation database test wpdb.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @param array  $where Where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		return 1;
	}

	/**
	 * Query.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( string $query ): int {
		return 1;
	}
}

$correlation_id  = 'activation:test-0001';
$events          = array();
$written_version = null;
$installed       = new Schema_Installation_Result(
	Schema_Installation_Result::INSTALLED,
	'schema_installed',
	$correlation_id,
	array( 'oj_ai_installations' )
);
$workflow        = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$installed,
		static function ( int $actor_user_id, string $actual_correlation ) use ( &$events, $correlation_id ): void {
			activation_database_assert( 31 === $actor_user_id, 'Activation must pass the current operator ID.' );
			activation_database_assert( $correlation_id === $actual_correlation, 'Activation must preserve the correlation ID.' );
			$events[] = 'install';
		}
	),
	static function ( string $version ) use ( &$events, &$written_version ): bool {
		$events[]        = 'write_version';
		$written_version = $version;

		return true;
	}
);
$problem         = $workflow->run( 31, $correlation_id, '0.1.7' );
activation_database_assert( null === $problem, 'A verified fresh install must activate.' );
activation_database_assert( array( 'install', 'write_version' ) === $events, 'Version storage must run strictly after schema success.' );
activation_database_assert( '0.1.7' === $written_version, 'Activation must store the running code version.' );

$already_ready_writes = 0;
$already_ready        = new Schema_Installation_Result(
	Schema_Installation_Result::ALREADY_READY,
	'schema_already_ready',
	'activation:test-0002'
);
$workflow             = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$already_ready,
		static function (): void {
		}
	),
	static function () use ( &$already_ready_writes ): bool {
		++$already_ready_writes;

		return true;
	}
);
activation_database_assert(
	null === $workflow->run( 31, 'activation:test-0002', '0.1.7' ),
	'A verified reactivation must be idempotent.'
);
activation_database_assert( 1 === $already_ready_writes, 'Reactivation must refresh the code version after verification.' );

$blocked_writes = 0;
$lock_result    = new Schema_Installation_Result(
	Schema_Installation_Result::LOCK_UNAVAILABLE,
	'schema_lock_unavailable',
	'activation:test-0003'
);
$workflow       = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$lock_result,
		static function (): void {
		}
	),
	static function () use ( &$blocked_writes ): bool {
		++$blocked_writes;

		return true;
	}
);
$problem        = $workflow->run( 31, 'activation:test-0003', '0.1.7' );
activation_database_assert( 'schema_lock_unavailable' === $problem['code'], 'Lock contention must keep its stable code.' );
activation_database_assert( str_contains( $problem['message'], '잠시 후' ), 'Lock contention must provide a short retry action.' );
activation_database_assert( 0 === $blocked_writes, 'Lock contention must not write the code version.' );

$repair_result = new Schema_Installation_Result(
	Schema_Installation_Result::REPAIR_REQUIRED,
	'simulated_schema_failure',
	'activation:test-0004',
	array( 'oj_ai_installations' ),
	array(
		array( 'code' => 'partial_schema' ),
		array(
			'code'  => 'initial_resume_checkpoint_invalid',
			'table' => 'oj_ai_private_table',
		),
		array(
			'raw_sql'   => 'DROP TABLE secret_table',
			'exception' => 'database password',
		),
	)
);
$workflow      = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$repair_result,
		static function (): void {
		}
	),
	static fn (): bool => true
);
$problem       = $workflow->run( 31, 'activation:test-0004', '0.1.7' );
$encoded       = json_encode( $problem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
activation_database_assert( 'simulated_schema_failure' === $problem['code'], 'Partial failure must keep its stable repair code.' );
activation_database_assert( str_contains( $problem['message'], '운영자 복구' ), 'Partial failure must direct the operator to repair.' );
activation_database_assert( 'initial_resume_checkpoint_invalid' === ( $problem['diagnostic_code'] ?? '' ), 'Activation must expose only the prioritized stable diagnostic code.' );
activation_database_assert( ! str_contains( (string) $encoded, 'DROP TABLE' ), 'Activation errors must not expose SQL.' );
activation_database_assert( ! str_contains( (string) $encoded, 'password' ), 'Activation errors must not expose raw exceptions.' );
activation_database_assert( ! str_contains( (string) $encoded, 'oj_ai_private_table' ), 'Activation diagnostics must not expose table names.' );

$owner_conflict = new Schema_Installation_Result(
	Schema_Installation_Result::BLOCKED,
	'schema_owner_conflict',
	'activation:test-0005'
);
$workflow       = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$owner_conflict,
		static function (): void {
		}
	),
	static fn (): bool => true
);
$problem        = $workflow->run( 31, 'activation:test-0005', '0.1.7' );
activation_database_assert( '기존 데이터 확인이 필요합니다.' === $problem['message'], 'Owner conflicts need the frozen user message.' );

$workflow = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		new RuntimeException( 'SQLSTATE secret failure' ),
		static function (): void {
		}
	),
	static fn (): bool => true
);
$problem  = $workflow->run( 31, 'activation:test-0006', '0.1.7' );
activation_database_assert( 'schema_installation_failed' === $problem['code'], 'Thrown errors must collapse to a stable code.' );
activation_database_assert( ! str_contains( $problem['message'], 'SQLSTATE' ), 'Thrown errors must not reach the activation screen.' );

$workflow = new Activation_Workflow(
	new Activation_Database_Test_Installer(
		$installed,
		static function (): void {
		}
	),
	static fn (): bool => false
);
$problem  = $workflow->run( 31, $correlation_id, '0.1.7' );
activation_database_assert( 'addon_version_option_write_failed' === $problem['code'], 'Version verification failure must fail closed.' );

$runner = WordPress_Schema_Installation_Runner_Factory::create( new Activation_Database_Test_Wpdb() );
activation_database_assert( $runner instanceof Schema_Installer_Port_Interface, 'The WordPress factory must return the installation port.' );

$activation_source   = file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Activation.php' );
$installation_source = file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Installation_Manager.php' );
$factory_source      = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Foundation'
	. DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'WordPress_Schema_Installation_Runner_Factory.php'
);
activation_database_assert(
	is_string( $activation_source ) && is_string( $installation_source ) && is_string( $factory_source ),
	'Activation integration sources must be readable.'
);
$preflight_position = strpos( $activation_source, 'Activation_Preflight::inspect(' );
$delegate_position  = strpos( $activation_source, "Installation_Manager::run( 'activation' )" );
$factory_position   = strpos( $installation_source, 'WordPress_Schema_Installation_Runner_Factory::create' );
activation_database_assert(
	false !== $preflight_position && false !== $delegate_position && $preflight_position < $delegate_position,
	'Compatibility preflight must precede the shared installation workflow.'
);
activation_database_assert( false !== $factory_position, 'The shared installation workflow must construct the verified schema runner.' );
activation_database_assert(
	str_contains( $installation_source, 'run_pending_upgrade' )
	&& str_contains( $installation_source, "get_option( 'oj_ai_dashboard_version'" )
	&& str_contains( $installation_source, "return self::run( 'upgrade' )" ),
	'ZIP replacement must dispatch the shared workflow only on an add-on version mismatch.'
);
$installation_boundary = $activation_source . $installation_source . $factory_source;
activation_database_assert( ! str_contains( $installation_boundary, '$wpdb->prefix' ), 'Activation must never derive target table names from a WordPress prefix.' );
activation_database_assert( 0 === preg_match( '/\b(?:Migrator|Table_Names)\b/', $installation_boundary ), 'Activation must not call the legacy schema stack.' );
activation_database_assert( 0 === preg_match( '/\b(?:add_role|add_cap)\s*\(/', $installation_boundary ), 'Activation must not grant roles or capabilities.' );

echo "Activation database integration contract passed.\n";
echo "- schema install precedes version storage\n";
echo "- fresh and already-ready activation paths\n";
echo "- lock, repair, exception, and option failure paths\n";
echo "- WordPress runner factory and safe diagnostics\n";

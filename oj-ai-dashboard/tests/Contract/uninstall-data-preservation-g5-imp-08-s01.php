<?php
/**
 * G5-IMP-08-S01 preservation-only WordPress uninstall contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 imp08 s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 imp08 s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_imp08_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 imp08 s01 mutation.
 *
 * @param string $operation Operation.
 * @param string $target Target.
 * @return true
 */
function g5_imp08_s01_mutation( string $operation, string $target ): bool {
	$GLOBALS['g5_imp08_s01_mutations'][] = array( $operation, $target );
	return true;
}

/**
 * Delete option.
 *
 * @param string $name Name.
 * @return bool
 */
function delete_option( string $name ): bool {
	return g5_imp08_s01_mutation( __FUNCTION__, $name );
}

/**
 * Delete site option.
 *
 * @param string $name Name.
 * @return bool
 */
function delete_site_option( string $name ): bool {
	return g5_imp08_s01_mutation( __FUNCTION__, $name );
}

/**
 * Update option.
 *
 * @param string $name Name.
 * @param mixed  $value Value.
 * @return bool
 */
function update_option( string $name, mixed $value ): bool {
	unset( $value );
	return g5_imp08_s01_mutation( __FUNCTION__, $name );
}

/**
 * Wp delete file.
 *
 * @param string $path Path.
 * @return void
 */
function wp_delete_file( string $path ): void {
	g5_imp08_s01_mutation( __FUNCTION__, $path );
}

/**
 * Wp clear scheduled hook.
 *
 * @param string $hook Hook.
 * @return int
 */
function wp_clear_scheduled_hook( string $hook ): int {
	g5_imp08_s01_mutation( __FUNCTION__, $hook );
	return 1;
}

/**
 * G5-IMP-08-S01 preservation-only WordPress uninstall contract.
 */
final class G5_Imp08_S01_Wpdb_Trap {
	/**
	 * Calls.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public array $calls = array();

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return bool
	 */
	public function query( string $sql ): bool {
		$this->calls[] = array( __FUNCTION__, $sql );
		return true;
	}

	/**
	 * Call.
	 *
	 * @param string           $method Method.
	 * @param array<int,mixed> $arguments Arguments.
	 * @return bool
	 */
	public function __call( string $method, array $arguments ): bool {
		$this->calls[] = array( $method, serialize( $arguments ) );
		return true;
	}
}

$catalog = new Schema_Catalog();
$tables  = array();
foreach ( $catalog->table_names() as $table_name ) {
	$tables[ $table_name ] = array(
		array(
			'id'      => 1,
			'payload' => hash( 'sha256', $table_name . ':preserved' ),
		),
	);
}
g5_imp08_s01_same( Schema_Catalog::TABLE_COUNT, count( $tables ), 'The uninstall fixture no longer covers the frozen 48-table catalog.' );
foreach ( array_keys( $tables ) as $table_name ) {
	g5_imp08_s01_assert( str_starts_with( $table_name, 'oj_ai_' ), 'The uninstall fixture crossed the literal table boundary: ' . $table_name );
	g5_imp08_s01_assert( ! str_starts_with( $table_name, 'wp_oj_ai_' ), 'A WordPress-prefixed target table entered the uninstall fixture.' );
}

$options              = array(
	'oj_ai_dashboard_version'              => '0.1.7',
	'oj_ai_dashboard_schema_version'       => Schema_Catalog::SCHEMA_VERSION,
	'oj_ai_dashboard_external_ai_consent'  => array( 'state' => 'approved' ),
	'oj_ai_submission_external_ai_consent' => array( 'state' => 'approved' ),
);
$platform_assignments = array(
	'oj_ai.manage_migrations' => array( 'principal-17' ),
	'oj_ai.delete_plugins'    => array( 'principal-17' ),
);

$private_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-g5-imp-08-s01-' . bin2hex( random_bytes( 8 ) );
$private_file = $private_root . DIRECTORY_SEPARATOR . 'preserved.bin';
if ( ! mkdir( $private_root, 0700, true ) && ! is_dir( $private_root ) ) {
	throw new RuntimeException( 'The uninstall private-file fixture directory is unavailable.' );
}
if ( false === file_put_contents( $private_file, 'preserve-private-bytes' ) ) {
	throw new RuntimeException( 'The uninstall private-file fixture is unavailable.' );
}

$GLOBALS['g5_imp08_s01_mutations'] = array();
$GLOBALS['wpdb']                   = new G5_Imp08_S01_Wpdb_Trap();
$before_tables                     = serialize( $tables );
$before_options                    = serialize( $options );
$before_assignments                = serialize( $platform_assignments );
$before_private_hash               = hash_file( 'sha256', $private_file );

try {
	defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
	require $root . DIRECTORY_SEPARATOR . 'uninstall.php';

	g5_imp08_s01_same( $before_tables, serialize( $tables ), 'Uninstall changed one or more add-on table fixtures.' );
	g5_imp08_s01_same( $before_options, serialize( $options ), 'Uninstall changed an add-on option fixture.' );
	g5_imp08_s01_same( $before_assignments, serialize( $platform_assignments ), 'Uninstall changed a platform assignment fixture.' );
	g5_imp08_s01_assert( is_file( $private_file ), 'Uninstall removed a private file.' );
	g5_imp08_s01_same( $before_private_hash, hash_file( 'sha256', $private_file ), 'Uninstall changed private file bytes.' );
	g5_imp08_s01_same( array(), $GLOBALS['g5_imp08_s01_mutations'], 'Uninstall invoked a WordPress mutation API.' );
	g5_imp08_s01_same( array(), $GLOBALS['wpdb']->calls, 'Uninstall invoked a database API.' );
	g5_imp08_s01_assert( ! defined( 'OJ_AI_DASHBOARD_VERSION' ), 'Uninstall bootstrapped the add-on runtime.' );
	g5_imp08_s01_assert( ! class_exists( 'OJAI\\Dashboard\\Core\\Plugin', false ), 'Uninstall loaded the add-on Plugin runtime.' );
} finally {
	if ( is_file( $private_file ) ) {
		unlink( $private_file );
	}
	if ( is_dir( $private_root ) ) {
		rmdir( $private_root );
	}
}

$uninstall_source = file_get_contents( $root . DIRECTORY_SEPARATOR . 'uninstall.php' );
g5_imp08_s01_assert( is_string( $uninstall_source ), 'uninstall.php is unreadable.' );
foreach ( array( 'WP_UNINSTALL_PLUGIN', 'A-DS15-D22', 'G5-IMP-08-S01', 'Intentionally no mutation' ) as $marker ) {
	g5_imp08_s01_assert( str_contains( $uninstall_source, $marker ), 'The uninstall preservation marker is missing: ' . $marker );
}

$executable = '';
foreach ( token_get_all( $uninstall_source ) as $token ) {
	if ( is_array( $token ) ) {
		if ( in_array( $token[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$executable .= $token[1];
		continue;
	}
	$executable .= $token;
}
foreach ( array( '$wpdb', 'DROP TABLE', 'TRUNCATE TABLE', 'DELETE FROM', 'delete_option', 'delete_site_option', 'update_option', 'wp_delete_file', 'unlink(', 'rmdir(', 'require', 'include', 'CodingPanda\\OjPlatform\\', 'wp_oj_ai_' ) as $forbidden ) {
	g5_imp08_s01_assert( ! str_contains( $executable, $forbidden ), 'Uninstall executable code contains a forbidden mutation or dependency: ' . $forbidden );
}

$release_source = file_get_contents( $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-release.ps1' );
g5_imp08_s01_assert( is_string( $release_source ), 'The release build script is unreadable.' );
$required_start = strpos( $release_source, '$requiredEntries = @(' );
$optional_start = strpos( $release_source, '$optionalEntries = @(' );
$copy_start     = strpos( $release_source, 'foreach ($relativePath in $requiredEntries)' );
g5_imp08_s01_assert( false !== $required_start && false !== $optional_start && false !== $copy_start, 'Release entry sections are missing.' );
$required_source = substr( $release_source, $required_start, $optional_start - $required_start );
$optional_source = substr( $release_source, $optional_start, $copy_start - $optional_start );
g5_imp08_s01_assert( str_contains( $required_source, "'uninstall.php'" ), 'Release packages may omit the preservation-only uninstall entry.' );
g5_imp08_s01_assert( ! str_contains( $optional_source, "'uninstall.php'" ), 'The uninstall preservation entry is still optional.' );

fwrite( STDOUT, "G5-IMP-08-S01 passed: uninstall preserves all 48 literal tables, options, private bytes, and platform assignments with zero runtime, database, file, or platform mutation.\n" );

<?php
/**
 * G5-IMP-06-S01 fresh installation integration boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Activation_Preflight;
use OJAI\Dashboard\Core\Activation_Workflow;
use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}
}

/**
 * G5-IMP-06-S01 fresh installation integration boundary.
 */
final class G5_S01_Installer implements Schema_Installer_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Initialize g5 s01 installer dependencies and state.
	 *
	 * @param Schema_Installation_Result $result Result.
	 * @param array                      &$events Events.
	 */
	public function __construct(
		private Schema_Installation_Result $result,
		private array &$events
	) {
	}

	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		++$this->calls;
		$this->events[] = 'schema';
		g5_s01_same( 77, $actor_user_id, 'Activation changed the installation actor.' );
		g5_s01_same( 'fresh-install:20260825', $correlation_id, 'Activation changed the correlation ID.' );

		return $this->result;
	}
}

$extensions        = array( 'dom', 'fileinfo', 'intl', 'mbstring', 'mysqli', 'openssl', 'xmlwriter', 'zip' );
$valid_environment = array(
	'wordpress_version'      => '6.6',
	'php_version'            => '8.1.0',
	'is_multisite'           => false,
	'network_wide'           => false,
	'platform_version'       => '2.0.0-dev.35',
	'addon_api_version'      => '1.1.0',
	'api_contract_available' => true,
	'extensions'             => $extensions,
	'private_storage_ready'  => true,
);

g5_s01_same( null, Activation_Preflight::inspect( $valid_environment ), 'The valid fresh-install preflight failed.' );

$failure_cases = array(
	'wordpress'        => array( array( 'wordpress_version' => '6.5.9' ), 'addon_wordpress_incompatible' ),
	'php'              => array( array( 'php_version' => '8.0.30' ), 'addon_php_incompatible' ),
	'multisite'        => array( array( 'is_multisite' => true ), 'multisite_unsupported' ),
	'network'          => array( array( 'network_wide' => true ), 'multisite_unsupported' ),
	'platform_missing' => array( array( 'platform_version' => '' ), 'platform_missing' ),
	'platform_range'   => array( array( 'platform_version' => '3.0.0' ), 'addon_platform_incompatible' ),
	'api_missing'      => array( array( 'addon_api_version' => '' ), 'addon_api_missing' ),
	'api_range'        => array( array( 'addon_api_version' => '2.0.0' ), 'addon_api_incompatible' ),
	'api_symbols'      => array( array( 'api_contract_available' => false ), 'addon_api_unavailable' ),
	'private_storage'  => array(
		array(
			'private_storage_ready' => false,
			'private_storage_code'  => 'storage_path_not_writable',
		),
		'storage_path_not_writable',
	),
);
foreach ( $failure_cases as $name => $case ) {
	$problem = Activation_Preflight::inspect( array_replace( $valid_environment, $case[0] ) );
	g5_s01_same( $case[1], $problem['code'] ?? '', 'Preflight failure code changed for ' . $name . '.' );
}
$storage_problem = Activation_Preflight::inspect(
	array_replace(
		$valid_environment,
		array(
			'private_storage_ready' => false,
			'private_storage_code'  => 'storage_path_not_writable',
		)
	)
);
g5_s01_assert( str_contains( (string) ( $storage_problem['message'] ?? '' ), 'OJ_AI_PRIVATE_STORAGE_DIR' ), 'Storage activation failure did not provide the safe configuration action.' );
foreach ( $extensions as $extension ) {
	$problem = Activation_Preflight::inspect(
		array_replace(
			$valid_environment,
			array( 'extensions' => array_values( array_diff( $extensions, array( $extension ) ) ) )
		)
	);
	g5_s01_same( 'addon_php_extension_missing', $problem['code'] ?? '', 'Missing extension was accepted: ' . $extension );
}

$catalog = new Schema_Catalog();
g5_s01_same( 48, count( $catalog->table_names() ), 'The fresh-install target must contain exactly 48 tables.' );
g5_s01_same( 10, count( $catalog->owner_counts() ), 'The fresh-install target must retain 10 owners.' );
foreach ( $catalog->table_names() as $table_name ) {
	g5_s01_assert( 1 === preg_match( '/^oj_ai_[a-z0-9_]+$/D', $table_name ), 'A target table is not a literal oj_ai_* name.' );
	g5_s01_assert( ! str_starts_with( $table_name, 'wp_' ), 'A target table contains a WordPress prefix.' );
}
g5_s01_same( 'oj_ai_dashboard_schema', Migration_Plan::LOCK_NAME, 'The fresh-install named lock changed.' );

$events    = array();
$installed = new Schema_Installation_Result(
	Schema_Installation_Result::INSTALLED,
	'schema_installed',
	'fresh-install:20260825',
	$catalog->table_names()
);
$installer = new G5_S01_Installer( $installed, $events );
$workflow  = new Activation_Workflow(
	$installer,
	static function ( string $version ) use ( &$events ): bool {
		$events[] = 'version';
		return '0.1.7' === $version;
	},
	static function ( int $actor_user_id, string $correlation_id ) use ( &$events ): bool {
		$events[] = 'configuration';
		return 77 === $actor_user_id && 'fresh-install:20260825' === $correlation_id;
	}
);
g5_s01_same( null, $workflow->run( 77, 'fresh-install:20260825', '0.1.7' ), 'The fresh activation workflow failed.' );
g5_s01_same( array( 'schema', 'configuration', 'version' ), $events, 'Fresh activation order changed.' );

$blocked_events    = array();
$blocked_installer = new G5_S01_Installer(
	new Schema_Installation_Result(
		Schema_Installation_Result::BLOCKED,
		'schema_preflight_blocked',
		'fresh-install:20260825'
	),
	$blocked_events
);
$blocked_workflow  = new Activation_Workflow(
	$blocked_installer,
	static function () use ( &$blocked_events ): bool {
		$blocked_events[] = 'version';
		return true;
	},
	static function () use ( &$blocked_events ): bool {
		$blocked_events[] = 'configuration';
		return true;
	}
);
$blocked_problem   = $blocked_workflow->run( 77, 'fresh-install:20260825', '0.1.7' );
g5_s01_same( 'schema_preflight_blocked', $blocked_problem['code'] ?? '', 'Unknown ownership must fail closed.' );
g5_s01_same( array( 'schema' ), $blocked_events, 'Blocked schema must not seed settings or write the version.' );

$activation_source   = (string) file_get_contents( $addon_root . '/src/Core/Activation.php' );
$installation_source = (string) file_get_contents( $addon_root . '/src/Core/Installation_Manager.php' );
$preflight_source    = (string) file_get_contents( $addon_root . '/src/Core/Activation_Preflight.php' );
$manifest_source     = (string) file_get_contents( $addon_root . '/src/Platform/Addon_Manifest.php' );
$readiness_sources   = array(
	(string) file_get_contents( $addon_root . '/src/Platform/Addon_Runtime_Readiness_Gate.php' ),
	(string) file_get_contents( $addon_root . '/src/Database/Foundation/Schema_Runtime_Readiness_Verifier.php' ),
	(string) file_get_contents( $addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Runtime_Readiness.php' ),
);

$preflight_position    = strpos( $activation_source, 'Activation_Preflight::inspect(' );
$installation_position = strpos( $activation_source, "Installation_Manager::run( 'activation' )" );
g5_s01_assert( false !== $preflight_position && false !== $installation_position && $preflight_position < $installation_position, 'Preflight must run before installation delegation.' );
g5_s01_assert( str_contains( $installation_source, 'global $wpdb;' ), 'The shared installation manager must own database resolution.' );
g5_s01_assert( str_contains( $activation_source, "'network_wide' => (bool) \$network_wide" ), 'Network activation must be passed to preflight.' );
g5_s01_assert( str_contains( $preflight_source, 'Local_Private_Storage_Path_Diagnostics' ), 'Private storage must be checked before schema installation.' );
g5_s01_assert( str_contains( $manifest_source, '$builder->onReady( array( $readiness, \'connect\' ) );' ), 'Manifest acceptance must own the Runtime readiness callback.' );

$active_install_sources = $activation_source . $installation_source . $preflight_source;
foreach ( array( '$wpdb->prefix', 'add_role(', 'add_cap(', 'oj_platform_' ) as $forbidden ) {
	g5_s01_assert( ! str_contains( $active_install_sources, $forbidden ), 'Activation crossed a frozen boundary: ' . $forbidden );
}
foreach ( $readiness_sources as $source ) {
	foreach ( array( 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'INSERT INTO', 'DELETE FROM', 'GET_LOCK(', 'RELEASE_LOCK(', '$wpdb->prefix' ) as $forbidden ) {
		g5_s01_assert( ! str_contains( strtoupper( $source ), strtoupper( $forbidden ) ), 'onReady readiness is not read-only: ' . $forbidden );
	}
}

$composer = json_decode( (string) file_get_contents( $addon_root . '/composer.json' ), true );
g5_s01_assert( is_array( $composer ) && isset( $composer['require'] ), 'Composer requirements are unreadable.' );
foreach ( array( 'ext-dom', 'ext-intl', 'ext-mbstring' ) as $requirement ) {
	g5_s01_assert( isset( $composer['require'][ $requirement ] ), 'Composer and activation requirements diverged: ' . $requirement );
}

echo "G5-IMP-06-S01 fresh-install boundary passed.\n";
echo "- environment, platform, Addon API, single-site, and private-storage preflight\n";
echo "- 48 literal tables, 10 owners, named lock, and no WordPress prefix\n";
echo "- schema, configuration, version order and blocked short circuit\n";
echo "- accepted onReady SELECT-only readiness and no platform writes\n";

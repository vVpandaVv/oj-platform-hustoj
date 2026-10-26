<?php
/**
 * WordPress active-plugin load-order regression contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Core\Plugin;
use OJAI\Dashboard\Core\Installation_Manager;
use OJAI\Dashboard\Security\Addon_Capabilities;

$addonRoot    = dirname( __DIR__, 2 );
$platformRoot = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platformRoot ) || '' === ( $platformRoot ) ) {
	$platformRoot = dirname( $addonRoot ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platformRoot = realpath( $platformRoot );
if ( ! is_string( $platformRoot ) || ! is_file(
	$platformRoot . DIRECTORY_SEPARATOR . 'src'
	. DIRECTORY_SEPARATOR . 'Kernel' . DIRECTORY_SEPARATOR . 'Autoloader.php'
) ) {
	throw new RuntimeException( 'Adjacent OJ Platform Addon API source was not found.' );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addonRoot . DIRECTORY_SEPARATOR );
$pluginSource = (string) file_get_contents( $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
if ( 1 !== ( preg_match( "/define\\(\\s*'OJ_AI_DASHBOARD_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $pluginSource, $versionMatch ) ) ) {
	throw new RuntimeException( 'The add-on version constant was not found.' );
}
defined( 'OJ_AI_DASHBOARD_VERSION' ) || define( 'OJ_AI_DASHBOARD_VERSION', $versionMatch[1] );
defined( 'OJ_AI_DASHBOARD_FILE' ) || define( 'OJ_AI_DASHBOARD_FILE', $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $addonRoot . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_URL' ) || define( 'OJ_AI_DASHBOARD_URL', 'https://example.test/wp-content/plugins/oj-ai-dashboard/' );
defined( 'OJ_AI_DASHBOARD_BASENAME' ) || define( 'OJ_AI_DASHBOARD_BASENAME', 'oj-ai-dashboard/oj-ai-dashboard.php' );

$GLOBALS['ojai_load_order_actions']      = array();
$GLOBALS['ojai_load_order_filters']      = array();
$GLOBALS['ojai_load_order_capabilities'] = array( 'manage_options' );
$GLOBALS['ojai_load_order_options']      = array( 'oj_ai_dashboard_version' => OJ_AI_DASHBOARD_VERSION );
$GLOBALS['ojai_load_order_option_reads'] = 0;
$GLOBALS['ojai_load_order_super_admin']  = false;

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param mixed $hook Hook.
	 * @param mixed $callback Callback.
	 * @param mixed $priority Priority.
	 * @param mixed $acceptedArgs Accepted args.
	 * @return bool
	 */
	function add_action( $hook, $callback, $priority = 10, $acceptedArgs = 1 ): bool {
		$GLOBALS['ojai_load_order_actions'][] = array(
			'hook'          => (string) $hook,
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $acceptedArgs,
		);

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Add filter.
	 *
	 * @param mixed $hook Hook.
	 * @param mixed $callback Callback.
	 * @param mixed $priority Priority.
	 * @param mixed $acceptedArgs Accepted args.
	 * @return bool
	 */
	function add_filter( $hook, $callback, $priority = 10, $acceptedArgs = 1 ): bool {
		$GLOBALS['ojai_load_order_filters'][] = array(
			'hook'          => (string) $hook,
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $acceptedArgs,
		);

		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Current user can.
	 *
	 * @param mixed $capability Capability.
	 * @return bool
	 */
	function current_user_can( $capability ): bool {
		return in_array( (string) $capability, $GLOBALS['ojai_load_order_capabilities'], true );
	}
}
if ( ! function_exists( 'is_super_admin' ) ) {
	/**
	 * Is super admin.
	 *
	 * @return bool
	 */
	function is_super_admin(): bool {
		return (bool) $GLOBALS['ojai_load_order_super_admin'];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param mixed $name Name.
	 * @param mixed $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		++$GLOBALS['ojai_load_order_option_reads'];
		return $GLOBALS['ojai_load_order_options'][ (string) $name ] ?? $default;
	}
}


if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Get current screen.
	 *
	 * @return object
	 */
	function get_current_screen(): object {
		return (object) array( 'id' => 'plugins' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Home url.
	 *
	 * @param mixed $path Path.
	 * @return string
	 */
	function home_url( $path = '' ): string {
		return 'https://example.test' . (string) $path;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Esc url.
	 *
	 * @param mixed $url Url.
	 * @return string
	 */
	function esc_url( $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

/**
 * Load order assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function load_order_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once $addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addonRoot . DIRECTORY_SEPARATOR . 'src' ) )->register();

Plugin::instance()->boot();
Plugin::instance()->boot();

$pluginsLoadedHooks = array_values(
	array_filter(
		$GLOBALS['ojai_load_order_actions'],
		static fn ( array $action ): bool => 'plugins_loaded' === ( $action['hook'] )
	)
);
$initHooks          = array_values(
	array_filter(
		$GLOBALS['ojai_load_order_actions'],
		static fn ( array $action ): bool => 'init' === ( $action['hook'] )
	)
);
$adminNoticeHooks   = array_values(
	array_filter(
		$GLOBALS['ojai_load_order_actions'],
		static fn ( array $action ): bool => 'admin_notices' === ( $action['hook'] )
	)
);
$pluginActionLinks  = array_values(
	array_filter(
		$GLOBALS['ojai_load_order_filters'],
		static fn ( array $filter ): bool => $filter['hook'] === 'plugin_action_links_' . OJ_AI_DASHBOARD_BASENAME
	)
);
$registrationHooks  = static fn (): array => array_values(
	array_filter(
		$GLOBALS['ojai_load_order_actions'],
		static fn ( array $action ): bool => 'oj_platform_register_addons' === ( $action['hook'] )
	)
);

load_order_assert( 1 === ( count( $pluginsLoadedHooks ) ), 'Plugin must register one deferred Runtime boot hook.' );
load_order_assert( 0 === ( $pluginsLoadedHooks[0]['priority'] ), 'Runtime boot must precede OJ Platform priority 1 boot.' );
load_order_assert( 1 === ( count( $initHooks ) ) && 0 === ( $initHooks[0]['priority'] ), 'Translations must load at init, not during plugin-file loading.' );
load_order_assert( 1 === ( count( $adminNoticeHooks ) ), 'The plugin list must explain the platform permission entry point once.' );
load_order_assert( 1 === ( count( $pluginActionLinks ) ), 'The plugin list must expose one platform permission action link.' );
load_order_assert( $registrationHooks() === array(), 'Composition Root must not load before OJ Platform API is available.' );
load_order_assert(
	! interface_exists( 'CodingPanda\\OjPlatform\\AddonApi\\V1\\AddonPageHandlerV1', false ),
	'The platform interface must be absent in the pre-platform fixture.'
);

call_user_func( $pluginsLoadedHooks[0]['callback'] );
load_order_assert( $registrationHooks() === array(), 'Missing platform API must fail closed without constructing page handlers.' );

require_once $platformRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register( $platformRoot );

call_user_func( $pluginsLoadedHooks[0]['callback'] );
call_user_func( $pluginsLoadedHooks[0]['callback'] );

$registered = $registrationHooks();
load_order_assert(
	1 === ( $GLOBALS['ojai_load_order_option_reads'] ),
	'A current installed version must pass the Runtime upgrade gate exactly once without touching the database.'
);
$runtimeSource       = (string) file_get_contents( $addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Plugin.php' );
$upgradePosition     = strpos( $runtimeSource, 'Installation_Manager::run_pending_upgrade()' );
$compositionPosition = strpos( $runtimeSource, 'new Composition_Root()' );
load_order_assert(
	false !== ( $upgradePosition ) && false !== ( $compositionPosition ) && $upgradePosition < $compositionPosition,
	'Pending ZIP upgrades must finish before the add-on Runtime graph is constructed.'
);
$GLOBALS['ojai_load_order_options']['oj_ai_dashboard_version'] = '0.1.49';
$pendingUpgrade = Installation_Manager::run_pending_upgrade();
load_order_assert(
	is_array( $pendingUpgrade )
	&& 'schema_database_unavailable' === ( $pendingUpgrade['code'] ?? '' )
	&& str_starts_with( (string) ( $pendingUpgrade['correlation_id'] ?? '' ), 'upgrade:' ),
	'A version mismatch must dispatch the shared upgrade workflow with a stable upgrade correlation.'
);
$versionParts = array_map( 'intval', explode( '.', OJ_AI_DASHBOARD_VERSION ) );
$newerVersion = sprintf( '%d.%d.%d', $versionParts[0], $versionParts[1], $versionParts[2] + 1 );
$GLOBALS['ojai_load_order_options']['oj_ai_dashboard_version'] = $newerVersion;
$rollbackBlocked = Installation_Manager::run_pending_upgrade();
load_order_assert(
	is_array( $rollbackBlocked )
	&& 'addon_version_rollback_blocked' === ( $rollbackBlocked['code'] ?? '' )
	&& str_starts_with( (string) ( $rollbackBlocked['correlation_id'] ?? '' ), 'upgrade:' ),
	'A newer installed version must fail closed before database or version mutation.'
);
$GLOBALS['ojai_load_order_options']['oj_ai_dashboard_version'] = 'invalid-version';
$invalidVersion = Installation_Manager::run_pending_upgrade();
load_order_assert(
	is_array( $invalidVersion )
	&& 'addon_version_state_invalid' === ( $invalidVersion['code'] ?? '' ),
	'An invalid installed version must fail closed before upgrade dispatch.'
);
$GLOBALS['ojai_load_order_options']['oj_ai_dashboard_version'] = OJ_AI_DASHBOARD_VERSION;


load_order_assert( 1 === ( count( $registered ) ), 'Deferred Runtime boot must register one platform add-on callback.' );
load_order_assert( 1 === ( $registered[0]['accepted_args'] ), 'Platform registration callback must accept one gateway.' );

$actionLinks = Plugin::instance()->add_plugin_action_links( array() );
load_order_assert(
	1 === ( count( $actionLinks ) ) && str_contains( $actionLinks[0], '/oj-console/roles#addon-capabilities' ),
	'The plugin action link must target the platform-owned add-on permission section.'
);
ob_start();
Plugin::instance()->render_admin_entry_notice();
$entryNotice = (string) ob_get_clean();
load_order_assert(
	str_contains( $entryNotice, '수업 &gt; 활동지 평가' )
	&& str_contains( $entryNotice, '/oj-console/addons/oj-ai-dashboard/settings' )
	&& str_contains( $entryNotice, '교사 메뉴 › 활동지 평가 › 설정' )
	&& ! str_contains( $entryNotice, 'wp-admin' )
	&& ! str_contains( $entryNotice, '수업 &gt; AI 활동지' )
	&& str_contains( $entryNotice, '/oj-console/roles#addon-capabilities' )
	&& str_contains( $entryNotice, 'AI 활동지 작업 공간 보기' )
	&& str_contains( $entryNotice, '활동지 보기' )
	&& str_contains( $entryNotice, '활동지 만들기·편집' ),
	'The plugin screen notice must explain all three authoring capabilities without adding a wp-admin page.'
);

$GLOBALS['ojai_load_order_capabilities'] = array(
	'manage_options',
	Addon_Capabilities::wordpress( Addon_Capabilities::VIEW_WORKSPACE ),
	Addon_Capabilities::wordpress( Addon_Capabilities::VIEW_WORKSHEETS ),
	Addon_Capabilities::wordpress( Addon_Capabilities::MANAGE_WORKSHEETS ),
);
$authorizedLinks                         = Plugin::instance()->add_plugin_action_links( array() );
load_order_assert(
	2 === ( count( $authorizedLinks ) )
	&& str_contains( $authorizedLinks[0], '/oj-console/addons/oj-ai-dashboard/worksheets' )
	&& str_contains( $authorizedLinks[1], '/oj-console/roles#addon-capabilities' ),
	'An authorized administrator must receive worksheet and permission action links.'
);
ob_start();
Plugin::instance()->render_admin_entry_notice();
$authorizedNotice = (string) ob_get_clean();
load_order_assert( '' === ( $authorizedNotice ), 'The permission notice must close after all authoring capabilities are granted.' );

$GLOBALS['ojai_load_order_super_admin'] = true;
$siteAdminLinks                         = Plugin::instance()->add_plugin_action_links( array() );
load_order_assert(
	3 === ( count( $siteAdminLinks ) )
	&& str_contains( $siteAdminLinks[0], '/oj-console/addons/oj-ai-dashboard/settings' )
	&& str_contains( $siteAdminLinks[0], '>설정<' ),
	'A site administrator must get the console settings link first.'
);
$GLOBALS['ojai_load_order_super_admin']  = false;
$GLOBALS['ojai_load_order_capabilities'] = array( Addon_Capabilities::wordpress( Addon_Capabilities::MANAGE_POLICIES ) );
$teacherLinks                            = Plugin::instance()->add_plugin_action_links( array() );
load_order_assert(
	1 === ( count( $teacherLinks ) ) && str_contains( $teacherLinks[0], '/oj-console/addons/oj-ai-dashboard/settings' ),
	'A teacher with the settings capability must get only the console settings link.'
);
foreach ( array( 'add_menu_page', 'add_submenu_page', 'add_options_page', 'add_management_page' ) as $wpAdminMenu ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'src', FilesystemIterator::SKIP_DOTS ) ) as $sourceFile ) {
		if ( 'php' === $sourceFile->getExtension() ) {
			load_order_assert(
				! str_contains( (string) file_get_contents( $sourceFile->getPathname() ), $wpAdminMenu . '(' ),
				'No wp-admin menu may be registered: ' . $wpAdminMenu . ' in ' . $sourceFile->getFilename()
			);
		}
	}
}

echo "Plugin load-order contract passed: pre-platform load is non-fatal, priority 0 boot registers once, and the three-capability authoring guidance targets OJ Console.\n";

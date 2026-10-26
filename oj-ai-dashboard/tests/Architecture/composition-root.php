<?php
/**
 * G1 Composition Root architecture contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Core\Composition_Root;

$addonRoot    = dirname( __DIR__, 2 );
$platformRoot = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platformRoot ) || '' === ( $platformRoot ) ) {
	$platformRoot = dirname( $addonRoot ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platformRoot = realpath( $platformRoot );
if ( ! is_string( $platformRoot ) || ! is_file( $platformRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel' . DIRECTORY_SEPARATOR . 'Autoloader.php' ) ) {
	throw new RuntimeException( 'Adjacent OJ Platform Addon API source was not found.' );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addonRoot . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_VERSION' ) || define( 'OJ_AI_DASHBOARD_VERSION', '0.1.7' );
defined( 'OJ_AI_DASHBOARD_FILE' ) || define( 'OJ_AI_DASHBOARD_FILE', $addonRoot . DIRECTORY_SEPARATOR . 'oj-ai-dashboard.php' );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $addonRoot . DIRECTORY_SEPARATOR );
$GLOBALS['ojai_g1_filters'] = array();

require_once $platformRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register( $platformRoot );

require_once $addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addonRoot . DIRECTORY_SEPARATOR . 'src' ) )->register();

$GLOBALS['ojai_g1_actions'] = array();

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
		$GLOBALS['ojai_g1_actions'][] = array(
			'hook'          => (string) $hook,
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $acceptedArgs,
		);
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
				$GLOBALS['ojai_g1_filters'][] = array(
					'hook'          => (string) $hook,
					'callback'      => $callback,
					'priority'      => (int) $priority,
					'accepted_args' => (int) $acceptedArgs,
				);

				return true;
			}
		}

		return true;
	}
}

/**
 * Composition assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function composition_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = new Composition_Root();
$root->boot();
$root->boot();

$registrationHooks = array_values(
	array_filter(
		$GLOBALS['ojai_g1_actions'],
		static fn ( array $action ): bool => 'oj_platform_register_addons' === ( $action['hook'] )
	)
);
composition_assert( 1 === ( count( $registrationHooks ) ), 'Composition Root must register one add-on hook after duplicate boot.' );
composition_assert( 1 === ( $registrationHooks[0]['accepted_args'] ), 'The add-on registration hook must accept one gateway.' );

$rootSource           = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Composition_Root.php'
);
$pluginSource         = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Plugin.php'
);
$handlerSource        = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform'
	. DIRECTORY_SEPARATOR . 'Addon_Page_Handler.php'
);
$bridgeSource         = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform'
	. DIRECTORY_SEPARATOR . 'Addon_Runtime_Bridge.php'
);
$policySource         = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security'
	. DIRECTORY_SEPARATOR . 'Policy' . DIRECTORY_SEPARATOR . 'Access_Policy.php'
);
$actorAdapterSource   = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security'
	. DIRECTORY_SEPARATOR . 'Policy' . DIRECTORY_SEPARATOR . 'Infrastructure'
	. DIRECTORY_SEPARATOR . 'WordPress_Actor_Authorization.php'
);
$contextAdapterSource = (string) file_get_contents(
	$addonRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security'
	. DIRECTORY_SEPARATOR . 'Policy' . DIRECTORY_SEPARATOR . 'Infrastructure'
	. DIRECTORY_SEPARATOR . 'Platform_Context_Authorization.php'
);

foreach (
	array(
		'Addon_Runtime_Bridge',
		'Addon_Runtime_Readiness_Gate',
		'WordPress_Schema_Runtime_Readiness',
		'Access_Policy',
		'WordPress_Actor_Authorization',
		'Platform_Context_Authorization',
		'Unavailable_Object_Authorization',
		'Addon_Integration',
		'Addon_Module',
		'Module_Manager',
	) as $concrete
) {
	composition_assert(
		1 === ( substr_count( $rootSource, 'new ' . $concrete . '(' ) ),
		'Composition Root must create exactly one ' . $concrete . '.'
	);
	composition_assert(
		! str_contains( $pluginSource, 'new ' . $concrete . '(' ),
		'Plugin must not create ' . $concrete . ' outside Composition Root.'
	);
}

composition_assert(
	1 === ( substr_count( $pluginSource, 'new Composition_Root(' ) ),
	'Plugin must create exactly one Composition Root.'
);
composition_assert( ! str_contains( $pluginSource, 'function modules(' ), 'Plugin must not expose a module service locator.' );
composition_assert(
	! str_contains( $pluginSource, 'function platform_runtime(' ),
	'Plugin must not expose the concrete Runtime bridge.'
);
composition_assert(
	str_contains( $handlerSource, 'Access_Policy_Port_Interface $policy' ),
	'Page handlers must consume the common access Policy Port.'
);
composition_assert(
	str_contains( $bridgeSource, 'implements Platform_Context_Runtime_Port_Interface' ),
	'The Runtime bridge must implement the public Context Runtime Port.'
);
composition_assert(
	str_contains( $policySource, 'implements Access_Policy_Port_Interface' ),
	'The common access Policy must implement its application Port.'
);
composition_assert(
	str_contains( $actorAdapterSource, 'user_can(' ) && ! str_contains( $actorAdapterSource, 'current_user_can(' ),
	'Actor authorization must check the responsible actor instead of ambient user state.'
);
composition_assert(
	str_contains( $contextAdapterSource, 'AddonContextAccessRequestV1' )
		&& ! str_contains( $contextAdapterSource, 'CodingPanda\\OjPlatform\\Kernel' ),
	'Context authorization must use only the public Addon API request.'
);

$activeRuntime = $rootSource . $pluginSource . $handlerSource . $bridgeSource . $policySource
	. $actorAdapterSource . $contextAdapterSource;
foreach ( array( 'Security_Module', 'Admin_Module', 'Platform_Module', 'oj_platform_loaded', 'route_registry', 'console_menu_registry' ) as $forbidden ) {
	composition_assert( ! str_contains( $activeRuntime, $forbidden ), 'Active G1 composition contains legacy dependency: ' . $forbidden );
}

echo "G1 Composition Root contract passed: one root, one common Policy, public Context adapter, duplicate boot ignored.\n";

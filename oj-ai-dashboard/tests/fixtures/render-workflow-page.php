<?php
/**
 * Render workflow page.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Assignment_Workbench_Page_Handler;
use OJAI\Dashboard\Platform\External_Ai_Consent_Settings_Page_Handler;
use OJAI\Dashboard\Platform\Report_Dashboard_Page_Handler;
use OJAI\Dashboard\Platform\Worksheet_Evaluation_Workbench_Page_Handler;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$root     = dirname( __DIR__, 2 );
$platform = getenv( 'OJ_PLATFORM_SOURCE' );
$platform = $platform ? $platform : dirname( $root ) . '/oj-integration-rebuild/oj-platform';
define( 'ABSPATH', $root . '/' );
define( 'OJ_AI_DASHBOARD_URL', '/' );
require $platform . '/src/Kernel/Autoloader.php';
Autoloader::register( $platform );
require $root . '/src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( $root . '/src' ) )->register();
/**
 * Home url.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( string $path = '' ): string {
	return $path;
}
/**
 * Rest url.
 *
 * @param string $path Path.
 * @return string
 */
function rest_url( string $path = '' ): string {
	return '/wp-json/' . ltrim( $path, '/' );
}
/**
 * Wp create nonce.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( string $action ): string {
	return 'workflow-test-nonce';
}
/**
 * Get option.
 *
 * @param string $name Name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( string $name, mixed $default = false ): mixed {
	return $default;
}
$policy = new class() implements Access_Policy_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
};
$page   = $argv[1] ?? 'assessment';
\OJAI\Dashboard\Platform\Worksheet_Workflow::use_platform_local_navigation( '1' === getenv( 'OJAI_PLATFORM_LOCAL_NAV' ) );
$query           = json_decode( $argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR );
[$route, $class] = match ( $page ) {
	'projects' => array( Addon_Manifest::CONSOLE_PROJECTS, \OJAI\Dashboard\Platform\Assessment_Project_Page_Handler::class ),
	'worksheets' => array( Addon_Manifest::CONSOLE_WORKSHEETS, \OJAI\Dashboard\Platform\Worksheet_Workbench_Page_Handler::class ),
	'assignment' => array( Addon_Manifest::CONSOLE_ASSIGNMENTS, Assignment_Workbench_Page_Handler::class ),
	'report' => array( Addon_Manifest::CONSOLE_REPORTS, Report_Dashboard_Page_Handler::class ),
	'criteria' => array( Addon_Manifest::CONSOLE_CRITERIA, External_Ai_Consent_Settings_Page_Handler::class ),
	'settings' => array( Addon_Manifest::CONSOLE_SETTINGS, External_Ai_Consent_Settings_Page_Handler::class ),
	default => array( Addon_Manifest::CONSOLE_ASSESSMENTS, Worksheet_Evaluation_Workbench_Page_Handler::class ),
};
if ( 'projects' === $page ) {
	require __DIR__ . '/project-page-repository.php';
	$project = json_decode( $argv[3] ?? '{}', true, 512, JSON_THROW_ON_ERROR );
	$handler = new $class( $policy, new Project_Page_Test_Repository( $project ) );
} elseif ( 'worksheets' === $page ) {
	$handler = new $class( $policy, $route, $class::MODE_LIST );
} else {
	$handler = new $class( $policy, $route );
}
	$response = $handler->handle( new AddonPageRequestV1( $route, 41, array(), $query, 'ko_KR', 'workflow-page' ) );
if ( 200 !== $response->status() ) {
	throw new RuntimeException( 'Page did not render.' );
}
if ( '1' === getenv( 'OJAI_SCREEN_MODE' ) && ! $response->supportsDarkMode() ) {
	throw new RuntimeException( 'Page did not opt into the platform color scheme.' );
}
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Browser fixture intentionally renders the complete escaped PageResponse HTML.
echo $response->body();
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

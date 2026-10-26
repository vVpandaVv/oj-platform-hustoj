<?php
/**
 * G5-IMP-01-S04 report dashboard Page and capability contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Report_Dashboard_Page_Handler;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$addon_root    = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $addon_root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) ) {
	fwrite( STDERR, "Adjacent OJ Platform source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platform_root );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Wp create nonce.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	function wp_create_nonce( string $action ): string {
		return 'wp_rest' === $action ? 'report-s04-rest-nonce' : '';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Home url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

/**
 * G5 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s04_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G5-IMP-01-S04 report dashboard Page and capability contract.
 */
final class G5_S04_Report_Policy implements Access_Policy_Port_Interface {
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();
	/**
	 * Status.
	 *
	 * @var int
	 */
	public int $status = 200;
	/**
	 * Export status.
	 *
	 * @var int
	 */
	public int $export_status = 200;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		$status           = 'report.export.open' === $request->action_key() ? $this->export_status : $this->status;
		if ( 200 !== $status ) {
			return Access_Policy_Decision::denied( $status, 'g5_s04_report_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G5 s04 page.
 *
 * @param G5_S04_Report_Policy $policy Policy.
 * @param string               $route Route.
 * @return object
 */
function g5_s04_page( G5_S04_Report_Policy $policy, string $route = Addon_Manifest::CONSOLE_REPORTS ): object {
	return ( new Report_Dashboard_Page_Handler( $policy, Addon_Manifest::CONSOLE_REPORTS ) )->handle(
		new AddonPageRequestV1( $route, 41, array(), array(), 'ko_KR', 'g5-s04-report-page' )
	);
}

$policy   = new G5_S04_Report_Policy();
$response = g5_s04_page( $policy );
g5_s04_same( 'html', $response->kind(), 'The report dashboard must return shell HTML.' );
g5_s04_same( 200, $response->status(), 'The report dashboard must return 200.' );
$body = (string) $response->body();
foreach (
	array(
		'id="oj-ai-report-app"',
		'data-rest-root="https://example.test/wp-json/oj-ai/v1"',
		'data-rest-nonce="report-s04-rest-nonce"',
		'data-field="context"',
		'data-field="period-from"',
		'data-field="period-through"',
		'data-filter="assignment"',
		'data-filter="submission"',
		'data-role="breadcrumbs"',
		'data-role="metrics"',
		'data-role="list"',
		'data-can-export="1"',
		'aria-label="보고서 보기"',
		'data-lens="worksheet"',
		'data-lens="group"',
		'data-lens="student"',
		'data-role="export-controls"',
	) as $marker
) {
	g5_s04_assert( str_contains( $body, $marker ), 'Report Page marker is missing: ' . $marker );
}
g5_s04_assert( ! str_contains( $body, 'data-oj-ai-local-navigation' ), 'The Page must rely on OJ Console menus without rendering a duplicate local navigation.' );
foreach ( array( 'schema', 'projection_hash', 'source_manifest_hash', 'provider_key', 'raw_response' ) as $forbidden ) {
	g5_s04_assert( ! str_contains( $body, $forbidden ), 'Internal contract copy must not be rendered: ' . $forbidden );
}
$policy->requests = array_values( array_filter( $policy->requests, static fn( Access_Policy_Request $r ): bool => 'workflow.navigation' !== $r->action_key() ) );
g5_s04_same( 2, count( $policy->requests ), 'The Page must separately authorize report viewing and export entry.' );
$access = $policy->requests[0];
g5_s04_same( Access_Policy_Request::SHELL_CONSOLE, $access->shell(), 'Report Page shell drifted.' );
g5_s04_same( Access_Policy_Request::SURFACE_PAGE, $access->surface(), 'Report Page surface drifted.' );
g5_s04_same( 'report.dashboard.view', $access->action_key(), 'Report Page action drifted.' );
g5_s04_same( array( Addon_Capabilities::VIEW_REPORTS ), $access->required_capabilities(), 'Report Page lost view_reports.' );
$export_access = $policy->requests[1];
g5_s04_same( Access_Policy_Request::SHELL_CONSOLE, $export_access->shell(), 'Report export shell drifted.' );
g5_s04_same( Access_Policy_Request::SURFACE_PAGE, $export_access->surface(), 'Report export surface drifted.' );
g5_s04_same( 'report.export.open', $export_access->action_key(), 'Report export action drifted.' );
g5_s04_same( array( Addon_Capabilities::EXPORT_REPORTS ), $export_access->required_capabilities(), 'Report Page lost export_reports.' );

$view_only                = new G5_S04_Report_Policy();
$view_only->export_status = 403;
$view_only_response       = g5_s04_page( $view_only );
g5_s04_same( 200, $view_only_response->status(), 'A viewer without export permission must still receive the report.' );
g5_s04_assert( str_contains( (string) $view_only_response->body(), 'data-can-export="0"' ), 'View-only report data marker drifted.' );
g5_s04_assert( ! str_contains( (string) $view_only_response->body(), 'data-role="export-controls"' ), 'View-only users must not receive export controls.' );

$denied          = new G5_S04_Report_Policy();
$denied->status  = 403;
$denied_response = g5_s04_page( $denied );
g5_s04_same( 403, $denied_response->status(), 'A revoked report capability must return 403.' );
g5_s04_assert( ! str_contains( (string) $denied_response->body(), 'id="oj-ai-report-app"' ), 'Denied users must not receive the report application.' );
g5_s04_assert( str_contains( (string) $denied_response->body(), '이 보고서를 볼 수 없습니다.' ), 'Denied Page copy drifted.' );

$unavailable          = new G5_S04_Report_Policy();
$unavailable->status  = 503;
$unavailable_response = g5_s04_page( $unavailable );
g5_s04_same( 'unavailable', $unavailable_response->kind(), 'Runtime failure must use the unavailable response.' );
g5_s04_same( 'addon_runtime_unavailable', $unavailable_response->code(), 'Runtime unavailable code drifted.' );

$mismatch = g5_s04_page( new G5_S04_Report_Policy(), Addon_Manifest::CONSOLE_WORKSHEETS );
g5_s04_same( 'unavailable', $mismatch->kind(), 'A route mismatch must fail closed.' );
g5_s04_same( 'addon_handler_failed', $mismatch->code(), 'Route mismatch code drifted.' );

$manifest = file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Manifest.php' );
g5_s04_assert( is_string( $manifest ), 'Addon Manifest source is unavailable.' );
g5_s04_assert( 1 === preg_match( '/self::CONSOLE_REPORTS\s*=>\s*Addon_Capabilities::VIEW_REPORTS/', $manifest ), 'Report capability registration is missing.' );
foreach ( array( 'Report_Dashboard_Page_Handler', 'resources/css/report-dashboard.css', 'resources/js/report-dashboard.js' ) as $marker ) {
	g5_s04_assert( str_contains( $manifest, $marker ), 'Report Manifest registration is missing: ' . $marker );
}

echo "G5-IMP-01-S04 Page contract passed: five direct Console menus, no duplicate page navigation, separate view/export authorization and fail-closed report markup.\n";

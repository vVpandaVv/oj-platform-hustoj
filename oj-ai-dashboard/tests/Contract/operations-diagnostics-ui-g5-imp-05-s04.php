<?php
/**
 * G5-IMP-05-S04 operations diagnostics settings Page contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\External_Ai_Consent_Settings_Page_Handler;
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
		return 'wp_rest' === $action ? 'operations-s04-rest-nonce' : '';
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
 * Operations ui assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function operations_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Operations ui same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function operations_ui_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G5-IMP-05-S04 operations diagnostics settings Page contract.
 */
final class Operations_Ui_Policy implements Access_Policy_Port_Interface {
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
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if ( 200 !== $this->status ) {
			return Access_Policy_Decision::denied( $this->status, 'operations_ui_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * Operations ui page.
 *
 * @param Operations_Ui_Policy $policy Policy.
 * @param string               $route Route.
 * @return object
 */
function operations_ui_page( Operations_Ui_Policy $policy, string $route = Addon_Manifest::CONSOLE_SETTINGS ): object {
	return ( new External_Ai_Consent_Settings_Page_Handler( $policy, Addon_Manifest::CONSOLE_SETTINGS ) )->handle(
		new AddonPageRequestV1( $route, 41, array(), array(), 'ko_KR', 'g5-imp-05-s04-page' )
	);
}

$policy   = new Operations_Ui_Policy();
$response = operations_ui_page( $policy );
operations_ui_same( 'html', $response->kind(), 'The settings Page must return shell HTML.' );
operations_ui_same( 200, $response->status(), 'The settings Page must return 200.' );
$body = (string) $response->body();
foreach (
	array(
		'data-settings-tab="system"',
		'>시스템 상태</button>',
		'data-settings-panel="system"',
		'id="oj-ai-operations-diagnostics"',
		'data-rest-root="https://example.test/wp-json/oj-ai/v1"',
		'data-rest-nonce="operations-s04-rest-nonce"',
		'data-platform-operations-url="https://example.test/oj-console/system-status"',
		'data-platform-ai-url="https://example.test/oj-console/settings/ai-routing"',
		'data-diagnostics-role="summary"',
		'data-diagnostics-role="checked-at"',
		'data-diagnostics-role="notice"',
		'data-diagnostics-role="list"',
		'data-diagnostics-action="refresh"',
	) as $marker
) {
	operations_ui_assert( str_contains( $body, $marker ), 'Operations UI marker is missing: ' . $marker );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'provider_key', 'model_key', 'next_action', 'original_name', 'storage_key', 'sha256', 'raw exception', 'stack trace' ) as $forbidden ) {
	operations_ui_assert( ! str_contains( strtolower( $body ), strtolower( $forbidden ) ), 'Operations Page exposed internal copy: ' . $forbidden );
}
$page_requests = array_filter( $policy->requests, static fn ( Access_Policy_Request $request ): bool => 'workflow.navigation' !== $request->action_key() );
operations_ui_same( 1, count( $page_requests ), 'The settings Page must perform one complete authorization independently of navigation visibility.' );
$access = $policy->requests[0];
operations_ui_same( Access_Policy_Request::SHELL_CONSOLE, $access->shell(), 'Operations Page shell drifted.' );
operations_ui_same( Access_Policy_Request::SURFACE_PAGE, $access->surface(), 'Operations Page surface drifted.' );
operations_ui_same( 'external_ai.consent.read', $access->action_key(), 'The existing settings Page action must remain stable.' );
operations_ui_same( array( Addon_Capabilities::MANAGE_POLICIES ), $access->required_capabilities(), 'The settings Page lost manage_policies.' );

$denied          = new Operations_Ui_Policy();
$denied->status  = 403;
$denied_response = operations_ui_page( $denied );
operations_ui_same( 403, $denied_response->status(), 'A revoked settings capability must return 403.' );
operations_ui_assert( ! str_contains( (string) $denied_response->body(), 'id="oj-ai-operations-diagnostics"' ), 'Denied users must not receive the diagnostics application.' );

$unavailable          = new Operations_Ui_Policy();
$unavailable->status  = 503;
$unavailable_response = operations_ui_page( $unavailable );
operations_ui_same( 'unavailable', $unavailable_response->kind(), 'Runtime failure must use the unavailable response.' );
operations_ui_same( 'addon_runtime_unavailable', $unavailable_response->code(), 'Runtime unavailable code drifted.' );

$mismatch = operations_ui_page( new Operations_Ui_Policy(), Addon_Manifest::CONSOLE_REPORTS );
operations_ui_same( 'unavailable', $mismatch->kind(), 'A route mismatch must fail closed.' );
operations_ui_same( 'addon_handler_failed', $mismatch->code(), 'Route mismatch code drifted.' );

$manifest = file_get_contents( $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR . 'Addon_Manifest.php' );
operations_ui_assert( is_string( $manifest ), 'Addon Manifest source is unavailable.' );
foreach ( array( 'resources/css/operations-diagnostics.css', 'resources/js/operations-diagnostics.js' ) as $marker ) {
	operations_ui_assert( str_contains( $manifest, $marker ), 'Operations asset registration is missing: ' . $marker );
}

echo "G5-IMP-05-S04 Page contract passed: existing settings route, manage_policies gate, safe platform links and no internal diagnostics copy.\n";

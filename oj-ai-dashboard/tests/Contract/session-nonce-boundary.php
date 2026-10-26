<?php
/**
 * Session nonce recovery route and origin regression fixtures.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Plugin;
use OJAI\Dashboard\Security\Session_Nonce_Request_Guard;

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'vendor/autoload.php';

/**
 * Session nonce recovery route and origin regression fixtures.
 */
class WP {
	/**
	 * Query vars.
	 *
	 * @var array
	 */
	public array $query_vars = array();
}
/**
 * Session nonce recovery route and origin regression fixtures.
 */
class WP_Error {
	/**
	 * Initialize wp error dependencies and state.
	 *
	 * @param array $codes Codes.
	 */
	public function __construct( private array $codes ) {
	}
	/**
	 * Get error codes.
	 *
	 * @return array
	 */
	public function get_error_codes(): array {
		return $this->codes;
	}
	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->codes[0];
	}
}
/**
 * Session nonce recovery route and origin regression fixtures.
 */
class WP_REST_Response {
	/**
	 * Initialize wp rest response dependencies and state.
	 *
	 * @param array $data Data.
	 * @param int   $status Status.
	 * @param array $headers Headers.
	 */
	public function __construct( public array $data, public int $status, public array $headers ) {
	}
}
/**
 * Is wp error.
 *
 * @param mixed $value Value.
 * @return bool
 */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
/**
 * Is user logged in.
 *
 * @return bool
 */
function is_user_logged_in(): bool {
	return $GLOBALS['ojai_nonce_signed_in'];
}
/**
 * Home url.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( string $path ): string {
	return $GLOBALS['ojai_nonce_origin'] . $path;
}
/**
 * Wp unslash.
 *
 * @param string $value Value.
 * @return string
 */
function wp_unslash( string $value ): string {
	return stripslashes( $value );
}
/**
 * Sanitize text field.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
/**
 * Esc url raw.
 *
 * @param string $value Value.
 * @param array  $protocols Protocols.
 * @return string
 */
function esc_url_raw( string $value, array $protocols ): string {
	$scheme = parse_url( $value, PHP_URL_SCHEME );
	return in_array( $scheme, $protocols, true ) ? $value : '';
}
/**
 * Wp parse url.
 *
 * @param string $value Value.
 * @return array|false
 */
function wp_parse_url( string $value ): array|false {
	return parse_url( $value );
}
/**
 * Wp create nonce.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( string $action ): string {
	return 'fixture-' . $action;
}
/**
 * Get option.
 *
 * @param string $name Name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( string $name, mixed $default = false ): mixed {
	return true;
}
/**
 * Register rest route.
 *
 * @param string $namespace Namespace.
 * @param string $route Route.
 * @param array  $args Args.
 * @return void
 */
function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['ojai_nonce_routes'][ '/' . $namespace . $route ] = $args[0];
}

$GLOBALS['wp']                   = new WP();
$GLOBALS['ojai_nonce_signed_in'] = true;
$GLOBALS['ojai_nonce_origin']    = 'https://example.test';
$ojai_error                      = new WP_Error( array( 'rest_cookie_invalid_nonce' ) );
$ojai_other_error                = new WP_Error( array( 'rest_cookie_invalid_nonce', 'another_authentication_error' ) );
$ojai_controllers                = array(
	'/oj-ai/v1/session-nonce' => Plugin::instance(),
);
$ojai_checks                     = 0;
foreach ( $ojai_controllers as $ojai_route => $ojai_controller ) {
	foreach ( array( $ojai_route, $ojai_route . '/' ) as $ojai_resolved ) {
		$GLOBALS['wp']->query_vars = array( 'rest_route' => $ojai_resolved );
		$_SERVER                   = array(
			'REQUEST_METHOD'      => 'GET',
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'HTTP_REFERER'        => 'https://example.test/lesson',
		);
		if ( null !== $ojai_controller->exempt_session_nonce_route_from_cookie_check( $ojai_error ) ) {
			throw new RuntimeException( 'Same-origin stale-nonce refresh was denied.' );
		}
		++$ojai_checks;
	}
	$ojai_cases = array(
		array( '/oj-ai/v1/legacy-session-nonce', array() ),
		array( '/oj-ai/v1/submissions', array( 'REQUEST_URI' => '/wp-json/oj-ai/v1/submissions?next=' . $ojai_route ) ),
		array( '/unrelated/session-nonce', array( 'REQUEST_URI' => $ojai_route ) ),
		array( $ojai_route . '/extra', array() ),
		array( $ojai_route . '-suffix', array() ),
		array( $ojai_route, array( 'REQUEST_METHOD' => 'POST' ) ),
		array( $ojai_route, array( 'REQUEST_METHOD' => 'HEAD' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'https://foreign.test' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'null' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'https://example.test.foreign.test' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'http://example.test' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'https://example.test:444' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => 'https://user:pass@example.test' ) ),
		array( $ojai_route, array( 'HTTP_ORIGIN' => array( 'https://example.test' ) ) ),
		array( $ojai_route, array( 'HTTP_REFERER' => 'https://foreign.test/page' ) ),
		array( $ojai_route, array( 'HTTP_SEC_FETCH_SITE' => 'cross-site' ) ),
		array( $ojai_route, array( 'HTTP_SEC_FETCH_SITE' => 'same-site' ) ),
	);
	foreach ( $ojai_cases as [ $ojai_resolved, $ojai_headers ] ) {
		$GLOBALS['wp']->query_vars = array( 'rest_route' => $ojai_resolved );
		$_SERVER                   = array_merge( array( 'REQUEST_METHOD' => 'GET' ), $ojai_headers );
		if ( $ojai_error !== $ojai_controller->exempt_session_nonce_route_from_cookie_check( $ojai_error ) ) {
			throw new RuntimeException( 'Unrelated or cross-origin request lost its authentication error.' );
		}
		++$ojai_checks;
	}
	$GLOBALS['wp']->query_vars = array( 'rest_route' => $ojai_route );
	$_SERVER                   = array(
		'REQUEST_METHOD' => 'GET',
		'HTTP_ORIGIN'    => 'https://example.test:443',
	);
	if ( null !== $ojai_controller->exempt_session_nonce_route_from_cookie_check( $ojai_error ) ) {
		throw new RuntimeException( 'Equivalent HTTPS port was rejected.' );
	}
	if ( $ojai_other_error !== $ojai_controller->exempt_session_nonce_route_from_cookie_check( $ojai_other_error ) ) {
		throw new RuntimeException( 'Another authentication error was discarded.' );
	}
	$GLOBALS['ojai_nonce_signed_in'] = false;
	if ( $ojai_error !== $ojai_controller->exempt_session_nonce_route_from_cookie_check( $ojai_error ) ) {
		throw new RuntimeException( 'Anonymous refresh was allowed.' );
	}
	$GLOBALS['ojai_nonce_signed_in'] = true;
	$ojai_response                   = $ojai_controller->session_nonce();
	if ( 'fixture-wp_rest' !== $ojai_response->data['nonce'] || ! str_contains( $ojai_response->headers['Cache-Control'], 'no-store' ) ) {
		throw new RuntimeException( 'Session nonce response can be cached or changed its payload.' );
	}
	$ojai_checks += 4;
}
Plugin::instance()->register_session_nonce_route();
$ojai_permission           = $GLOBALS['ojai_nonce_routes']['/oj-ai/v1/session-nonce']['permission_callback'];
$GLOBALS['wp']->query_vars = array( 'rest_route' => '/oj-ai/v1/session-nonce' );
$_SERVER                   = array(
	'REQUEST_METHOD' => 'GET',
	'HTTP_ORIGIN'    => 'https://foreign.test',
);
if ( $ojai_permission() ) {
	throw new RuntimeException( 'A valid nonce would bypass the origin permission check.' );
}
echo 'Session nonce boundary PASS: ' . (int) ( $ojai_checks + 1 ) . " route, origin, error preservation and cache checks.\n";

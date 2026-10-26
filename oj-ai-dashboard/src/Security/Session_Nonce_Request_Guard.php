<?php
/**
 * Restrict stale-nonce recovery to the resolved same-origin read endpoint.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks routing and browser origin before issuing a session nonce.
 */
final class Session_Nonce_Request_Guard {
	/**
	 * Check the route WordPress will dispatch, never a substring of REQUEST_URI.
	 *
	 * @param string $expected_route Exact registered nonce route.
	 * @return bool
	 */
	public static function permits( string $expected_route ): bool {
		global $wp;

		if ( ! $wp instanceof \WP || ! is_user_logged_in() ) {
			return false;
		}
		$route = $wp->query_vars['rest_route'] ?? null;
		if ( ! is_string( $route ) || $expected_route !== rtrim( $route, '/' ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'GET' !== $method ) {
			return false;
		}
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && is_string( $_SERVER['HTTP_SEC_FETCH_SITE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( '' !== $fetch_site && ! in_array( $fetch_site, array( 'same-origin', 'none' ), true ) ) {
			return false;
		}
		$expected_origin = self::origin( home_url( '/' ) );
		if ( null === $expected_origin ) {
			return false;
		}
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
			if ( ! isset( $_SERVER[ $header ] ) ) {
				continue;
			}
			if ( ! is_string( $_SERVER[ $header ] ) ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the raw value to reject any change made by esc_url_raw on the next line.
			$raw = wp_unslash( $_SERVER[ $header ] );
			$url = esc_url_raw( $raw, array( 'http', 'https' ) );
			if ( '' === $url || $raw !== $url || $expected_origin !== self::origin( $url ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compare scheme, host and effective port without accepting user information.
	 *
	 * @param string $url Absolute origin or referring page.
	 * @return array{string,string,int}|null
	 */
	private static function origin( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = strtolower( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return null;
		}
		return array( $scheme, $host, $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
	}
}

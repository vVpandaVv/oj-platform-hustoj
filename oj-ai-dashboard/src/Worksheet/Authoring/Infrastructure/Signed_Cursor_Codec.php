<?php
/**
 * Signed worksheet cursor codec.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents clients from changing owner-list sort positions or filter scope.
 */
final class Signed_Cursor_Codec {
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Secret.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Initialize signed cursor codec dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string         $secret Secret.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( Canonical_Json $canonical, string $secret = '' ) {
		$this->canonical = $canonical;
		$this->secret    = '' === $secret ? $this->wordpress_secret() : $secret;
		if ( strlen( $this->secret ) < 16 ) {
			throw new InvalidArgumentException( 'Worksheet cursor secret is too short.' );
		}
	}

	/**
	 * Encode signed cursor codec.
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function encode( string $kind, array $payload ): string {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $kind ) ) {
			throw new InvalidArgumentException( 'Worksheet cursor kind is invalid.' );
		}

		$body      = $this->base64url_encode( $this->canonical->encode( array_merge( array( 'kind' => $kind ), $payload ) ) );
		$signature = substr( hash_hmac( 'sha256', $body, $this->secret ), 0, 32 );
		$cursor    = $body . '.' . $signature;
		if ( strlen( $cursor ) > Read_Worksheets::MAXIMUM_CURSOR ) {
			throw new InvalidArgumentException( 'Worksheet cursor exceeds QueryInputV1.' );
		}

		return $cursor;
	}

	/**
	 * Decode signed cursor codec.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param string $expected_kind Expected kind.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function decode( string $cursor, string $expected_kind ): array {
		if ( '' === $cursor ) {
			return array();
		}
		if (
			strlen( $cursor ) > Read_Worksheets::MAXIMUM_CURSOR
			|| 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{32})$/D', $cursor, $matches )
		) {
			throw new InvalidArgumentException( 'Worksheet cursor is invalid.' );
		}

		$expected_signature = substr( hash_hmac( 'sha256', $matches[1], $this->secret ), 0, 32 );
		if ( ! hash_equals( $expected_signature, $matches[2] ) ) {
			throw new InvalidArgumentException( 'Worksheet cursor signature is invalid.' );
		}

		$json = $this->base64url_decode( $matches[1] );
		try {
			$payload = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Worksheet cursor payload is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $payload ) || ( $payload['kind'] ?? null ) !== $expected_kind ) {
			throw new InvalidArgumentException( 'Worksheet cursor scope is invalid.' );
		}
		unset( $payload['kind'] );

		return $payload;
	}

	/**
	 * WordPress secret.
	 *
	 * @return string
	 */
	private function wordpress_secret(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'oj_ai_cursor' );
			if ( is_string( $salt ) && '' !== $salt ) {
				return $salt;
			}
		}
		if ( defined( 'AUTH_SALT' ) && is_string( AUTH_SALT ) && '' !== AUTH_SALT ) {
			return AUTH_SALT;
		}

		return hash( 'sha256', __FILE__ . '|oj-ai-dashboard-cursor-v1' );
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is the explicit cursor/binary representation codec, not code obfuscation.
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function base64url_decode( string $value ): string {
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		$decoded = base64_decode( strtr( $value . str_repeat( '=', $padding ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64 is the explicit cursor/binary representation codec, not code obfuscation.
		if ( ! is_string( $decoded ) ) {
			throw new InvalidArgumentException( 'Worksheet cursor encoding is invalid.' );
		}

		return $decoded;
	}
}

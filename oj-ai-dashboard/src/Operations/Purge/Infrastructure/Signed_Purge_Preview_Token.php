<?php
/**
 * Signed purge preview token.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Preview_Token_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Preview_Token_Verifier_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed purge preview token.
 */
final class Signed_Purge_Preview_Token implements Purge_Preview_Token_Port_Interface, Purge_Preview_Token_Verifier_Port_Interface {
	public const PURPOSE             = 'addon_purge_preview';
	public const MAXIMUM_TOKEN_BYTES = 4096;
	private const TTL_SECONDS        = 1800;

	/**
	 * Secret.
	 *
	 * @var string
	 */
	private string $secret;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Nonce.
	 *
	 * @var Closure
	 */
	private Closure $nonce;

	/**
	 * Initialize signed purge preview token dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string         $secret Secret.
	 * @param Closure|null   $clock Clock.
	 * @param Closure|null   $nonce Nonce.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		string $secret = '',
		?Closure $clock = null,
		?Closure $nonce = null
	) {
		$this->secret = '' === $secret ? $this->wordpress_secret() : $secret;
		if ( '' !== $this->secret && strlen( $this->secret ) < 32 ) {
			throw new InvalidArgumentException( 'Purge preview token secret is too short.' );
		}
		$this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->nonce = $nonce ?? static fn (): string => bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Issue.
	 *
	 * @param string $site_id Site id.
	 * @param string $scope_fingerprint Scope fingerprint.
	 * @param string $preview_hash Preview hash.
	 * @param string $idempotency_key_hash Idempotency key hash.
	 * @return array{preview_token:string,issued_at_utc:string,expires_at_utc:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function issue( string $site_id, string $scope_fingerprint, string $preview_hash, string $idempotency_key_hash ): array {
		if ( '' === $this->secret ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::TOKEN_UNAVAILABLE );
		}
		$this->assert_scope( $site_id, $scope_fingerprint, $preview_hash, $idempotency_key_hash );
		$issued = ( $this->clock )();
		if ( ! $issued instanceof DateTimeImmutable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::TOKEN_UNAVAILABLE );
		}
		$issued = $issued->setTimezone( new DateTimeZone( 'UTC' ) );
		$nonce  = ( $this->nonce )();
		if ( ! is_string( $nonce ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $nonce ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::TOKEN_UNAVAILABLE );
		}

		$payload = array(
			'purpose'              => self::PURPOSE,
			'site'                 => $site_id,
			'scope_fingerprint'    => $scope_fingerprint,
			'preview_hash'         => $preview_hash,
			'idempotency_key_hash' => $idempotency_key_hash,
			'issued_at_utc'        => $issued->format( 'Y-m-d H:i:s' ),
			'expires_at_utc'       => $issued->modify( '+' . self::TTL_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' ),
			'nonce'                => $nonce,
		);
		$body    = $this->base64url_encode( $this->canonical->encode( $payload ) );
		$token   = $body . '.' . hash_hmac( 'sha256', $body, $this->secret );
		if ( strlen( $token ) > self::MAXIMUM_TOKEN_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::TOKEN_UNAVAILABLE );
		}

		return array(
			'preview_token'  => $token,
			'issued_at_utc'  => $payload['issued_at_utc'],
			'expires_at_utc' => $payload['expires_at_utc'],
		);
	}

	/**
	 * Verify signed purge preview token.
	 *
	 * @param string $token Token.
	 * @return array{purpose:string,site:string,scope_fingerprint:string,preview_hash:string,idempotency_key_hash:string,issued_at_utc:string,expires_at_utc:string,nonce:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify( string $token ): array {
		try {
			$payload = $this->decode( $token );
			$issued  = $this->time( $payload['issued_at_utc'] );
			$expires = $this->time( $payload['expires_at_utc'] );
			$now     = ( $this->clock )();
			if ( ! $now instanceof DateTimeImmutable ) {
				throw new InvalidArgumentException( 'Purge preview token clock is invalid.' );
			}
			$now = $now->setTimezone( new DateTimeZone( 'UTC' ) );
			if ( $now < $issued ) {
				throw Purge_Failure::because( Purge_Failure::TOKEN_INVALID );
			}
			if ( $now >= $expires ) {
				throw Purge_Failure::because( Purge_Failure::TOKEN_EXPIRED );
			}
			return $payload;
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::TOKEN_INVALID, $error );
		}
	}

	/**
	 * Decode signed purge preview token.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function decode( string $token ): array {
		if (
			'' === $this->secret
			|| strlen( $token ) > self::MAXIMUM_TOKEN_BYTES
			|| 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $matches )
		) {
			throw new InvalidArgumentException( 'Purge preview token is invalid.' );
		}
		$signature = hash_hmac( 'sha256', $matches[1], $this->secret );
		if ( ! hash_equals( $signature, $matches[2] ) ) {
			throw new InvalidArgumentException( 'Purge preview token signature is invalid.' );
		}

		try {
			$payload = json_decode( $this->base64url_decode( $matches[1] ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Purge preview token payload is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		$expected = array( 'purpose', 'site', 'scope_fingerprint', 'preview_hash', 'idempotency_key_hash', 'issued_at_utc', 'expires_at_utc', 'nonce' );
		sort( $expected, SORT_STRING );
		if ( ! is_array( $payload ) || array_is_list( $payload ) || array_keys( $payload ) !== $expected ) {
			throw new InvalidArgumentException( 'Purge preview token envelope is invalid.' );
		}
		if ( self::PURPOSE !== $payload['purpose'] || ! is_string( $payload['nonce'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $payload['nonce'] ) ) {
			throw new InvalidArgumentException( 'Purge preview token purpose is invalid.' );
		}
		$this->assert_scope( $payload['site'], $payload['scope_fingerprint'], $payload['preview_hash'], $payload['idempotency_key_hash'] );
		$issued  = $this->time( $payload['issued_at_utc'] );
		$expires = $this->time( $payload['expires_at_utc'] );
		if ( $expires->getTimestamp() - $issued->getTimestamp() !== self::TTL_SECONDS ) {
			throw new InvalidArgumentException( 'Purge preview token lifetime is invalid.' );
		}
		return $payload;
	}

	/**
	 * Reject invalid scope.
	 *
	 * @param mixed $site_id Site id.
	 * @param mixed $scope_fingerprint Scope fingerprint.
	 * @param mixed $preview_hash Preview hash.
	 * @param mixed $idempotency_key_hash Idempotency key hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_scope( $site_id, $scope_fingerprint, $preview_hash, $idempotency_key_hash ): void {
		if ( ! is_string( $site_id ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $site_id ) ) {
			throw new InvalidArgumentException( 'Purge preview token site is invalid.' );
		}
		foreach ( array( $scope_fingerprint, $preview_hash, $idempotency_key_hash ) as $digest ) {
			if ( ! is_string( $digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Purge preview token digest is invalid.' );
			}
		}
	}

	/**
	 * Time.
	 *
	 * @param mixed $value Value.
	 * @return DateTimeImmutable
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function time( $value ): DateTimeImmutable {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Purge preview token time is invalid.' );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Purge preview token time is invalid.' );
		}
		return $time;
	}

	/**
	 * WordPress secret.
	 *
	 * @return string
	 */
	private function wordpress_secret(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'oj_ai_purge_preview' );
			if ( is_string( $salt ) && strlen( $salt ) >= 32 ) {
				return $salt;
			}
		}
		foreach ( array( 'NONCE_SALT', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'AUTH_KEY' ) as $constant ) {
			if ( defined( $constant ) ) {
				$value = constant( $constant );
				if ( is_string( $value ) && strlen( $value ) >= 32 ) {
					return hash_hmac( 'sha256', self::PURPOSE, $value );
				}
			}
		}
		return '';
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
			throw new InvalidArgumentException( 'Purge preview token encoding is invalid.' );
		}
		return $decoded;
	}
}

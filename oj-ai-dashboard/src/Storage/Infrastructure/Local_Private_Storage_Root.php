<?php
/**
 * Canonical local private storage root resolution.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical local private storage root resolution.
 */
final class Local_Private_Storage_Root {
	/**
	 * Reject web-served locations, including WordPress subdirectory siblings.
	 *
	 * @param string      $root Root.
	 * @param string|null $web_root Web root.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function assert_private( string $root, ?string $web_root = null ): void {
		$web_root ??= defined( 'OJ_AI_WEB_ROOT' ) ? (string) constant( 'OJ_AI_WEB_ROOT' ) : (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-provided filesystem path is validated with realpath; preserve filesystem path bytes rather than HTML-sanitizing them.
		if ( '' === $web_root || false === realpath( $web_root ) || ! is_dir( $web_root ) ) {
			throw new RuntimeException( 'Private storage web root is unavailable.' );
		}
		$effective = self::resolvable_path( $root )['effective'];
		foreach ( array( $web_root, (string) ABSPATH ) as $public_root ) {
			$public = realpath( $public_root );
			if ( false !== $public && self::contains( $public, $effective ) ) {
				throw new RuntimeException( 'Storage path is inside a public root.' );
			}
		}
	}

	/**
	 * Resolve local private storage root.
	 *
	 * @param string|null         $root Root.
	 * @param array<string,mixed> $environment Deterministic resolver overrides for contract tests.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function resolve( ?string $root = null, array $environment = array() ): string {
		$candidate = rtrim( trim( $root ?? self::configured( $environment ) ), '/\\' );
		if ( '' === $candidate || str_contains( $candidate, "\0" ) || ! self::is_absolute_path( $candidate ) ) {
			throw new RuntimeException( 'Private storage root is unavailable.' );
		}

		return $candidate;
	}

	/**
	 * Configured.
	 *
	 * @param array<string,mixed> $environment Environment.
	 * @return string
	 */
	private static function configured( array $environment ): string {
		if ( defined( 'OJ_AI_PRIVATE_STORAGE_DIR' ) ) {
			$configured = constant( 'OJ_AI_PRIVATE_STORAGE_DIR' );
			if ( is_string( $configured ) && '' !== trim( $configured ) ) {
				return $configured;
			}
		}

		$site_id       = array_key_exists( 'site_id', $environment )
			? max( 1, (int) $environment['site_id'] )
			: ( function_exists( 'get_current_blog_id' ) ? max( 1, (int) get_current_blog_id() ) : 1 );
		$legacy_parent = isset( $environment['legacy_parent'] ) && is_string( $environment['legacy_parent'] )
			? rtrim( trim( $environment['legacy_parent'] ), '/\\' )
			: dirname( rtrim( (string) ABSPATH, '/\\' ) );
		$legacy        = $legacy_parent
			. DIRECTORY_SEPARATOR . 'oj-ai-private' . DIRECTORY_SEPARATOR . 'site-' . $site_id;

		// Preserve the original location when an earlier installation already created it.
		if ( is_dir( $legacy ) && ! is_link( $legacy ) ) {
			return $legacy;
		}

		$web_root = isset( $environment['web_root'] ) && is_string( $environment['web_root'] )
			? $environment['web_root']
			: (string) ABSPATH;
		foreach ( self::home_directories( $environment ) as $home ) {
			$candidate = $home . DIRECTORY_SEPARATOR . '.oj-ai-private' . DIRECTORY_SEPARATOR . 'site-' . $site_id;
			if ( self::candidate_is_available( $candidate, $web_root ) ) {
				return $candidate;
			}
		}

		// Keep the stable legacy candidate so diagnostics can return a precise fail-closed code.
		return $legacy;
	}

	/**
	 * Home directories.
	 *
	 * @param array<string,mixed> $environment Environment.
	 * @return array<int,string>
	 */
	private static function home_directories( array $environment ): array {
		$values = array();
		if ( isset( $environment['home_directories'] ) && is_array( $environment['home_directories'] ) ) {
			$values = $environment['home_directories'];
		} else {
			foreach ( array( 'HOME', 'USERPROFILE' ) as $name ) {
				$value = getenv( $name );
				if ( is_string( $value ) ) {
					$values[] = $value;
				}
			}
			$drive = getenv( 'HOMEDRIVE' );
			$path  = getenv( 'HOMEPATH' );
			if ( is_string( $drive ) && is_string( $path ) ) {
				$values[] = $drive . $path;
			}
		}

		$directories = array();
		$seen        = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$directory = rtrim( trim( $value ), '/\\' );
			if ( '' === $directory || str_contains( $directory, "\0" ) || ! self::is_absolute_path( $directory ) ) {
				continue;
			}
			$key = strtolower( str_replace( '\\', '/', $directory ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]  = true;
			$directories[] = $directory;
		}

		return $directories;
	}

	/**
	 * Candidate is available.
	 *
	 * @param string $candidate Candidate.
	 * @param string $web_root Web root.
	 * @return bool
	 */
	private static function candidate_is_available( string $candidate, string $web_root ): bool {
		if ( ! self::is_absolute_path( $candidate ) ) {
			return false;
		}

		try {
			$resolved = self::resolvable_path( $candidate );
		} catch ( RuntimeException ) {
			return false;
		}

		$web = realpath( $web_root );
		return false !== $web
			&& ! self::contains( $web, $resolved['effective'] )
			&& is_readable( $resolved['probe'] )
			&& is_writable( $resolved['probe'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
	}

	/**
	 * Resolvable path.
	 *
	 * @param string $candidate Candidate.
	 * @return array{effective:string,probe:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function resolvable_path( string $candidate ): array {
		if ( file_exists( $candidate ) ) {
			if ( is_link( $candidate ) || ! is_dir( $candidate ) ) {
				throw new RuntimeException( 'Private storage candidate is invalid.' );
			}
			$real = realpath( $candidate );
			if ( false === $real ) {
				throw new RuntimeException( 'Private storage candidate cannot be resolved.' );
			}
			return array(
				'effective' => $real,
				'probe'     => $real,
			);
		}

		$probe   = $candidate;
		$missing = array();
		while ( ! file_exists( $probe ) ) {
			if ( is_link( $probe ) ) {
				throw new RuntimeException( 'Private storage candidate contains a link.' );
			}
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				throw new RuntimeException( 'Private storage candidate parent is unavailable.' );
			}
			array_unshift( $missing, basename( $probe ) );
			$probe = $parent;
		}
		if ( is_link( $probe ) || ! is_dir( $probe ) ) {
			throw new RuntimeException( 'Private storage candidate parent is invalid.' );
		}
		$real = realpath( $probe );
		if ( false === $real ) {
			throw new RuntimeException( 'Private storage candidate parent cannot be resolved.' );
		}

		$effective = rtrim( $real, '/\\' );
		foreach ( $missing as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || str_contains( $part, "\0" ) ) {
				throw new RuntimeException( 'Private storage candidate path is invalid.' );
			}
			$effective .= DIRECTORY_SEPARATOR . $part;
		}

		return array(
			'effective' => $effective,
			'probe'     => $real,
		);
	}

	/**
	 * Contains.
	 *
	 * @param string $parent Parent.
	 * @param string $child Child.
	 * @return bool
	 */
	private static function contains( string $parent, string $child ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$parent = strtolower( rtrim( str_replace( '\\', '/', $parent ), '/' ) ) . '/';
		$child  = strtolower( rtrim( str_replace( '\\', '/', $child ), '/' ) ) . '/';
		return str_starts_with( $child, $parent );
	}

	/**
	 * Is absolute path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute_path( string $path ): bool {
		return str_starts_with( $path, '/' )
			|| (
				strlen( $path ) >= 3
				&& ctype_alpha( $path[0] )
				&& ':' === $path[1]
				&& in_array( $path[2], array( '\\', '/' ), true )
			);
	}
}

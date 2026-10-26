<?php
/**
 * Read-only local private storage path diagnostics.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_Storage_Root;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only local private storage path diagnostics.
 */
final class Local_Private_Storage_Path_Diagnostics {
	/**
	 * Initialize local private storage path diagnostics dependencies and state.
	 *
	 * @param string|null $root Root.
	 * @param string|null $web_root Web root.
	 */
	public function __construct(
		private ?string $root = null,
		private ?string $web_root = null
	) {
	}

	/**
	 * Inspect.
	 *
	 * @return Storage_Path_Diagnostics
	 */
	public function inspect(): Storage_Path_Diagnostics {
		try {
			$root     = Local_Private_Storage_Root::resolve( $this->root );
			$web_root = $this->web_root ?? ( defined( 'OJ_AI_WEB_ROOT' ) ? (string) constant( 'OJ_AI_WEB_ROOT' ) : (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-provided filesystem path is validated with realpath; preserve filesystem path bytes rather than HTML-sanitizing them.
			$web      = '' === $web_root ? false : realpath( $web_root );
			if ( false === $web || ! is_dir( $web ) ) {
				return Storage_Path_Diagnostics::unavailable( 'storage_web_root_unavailable' );
			}

			$resolved = $this->resolvable_path( $root );
			$private  = ! $this->contains( $web, $resolved['effective'] );
			$wp       = realpath( (string) ABSPATH );
			$private  = $private && ( false === $wp || ! $this->contains( $wp, $resolved['effective'] ) );
			$readable = $private && is_readable( $resolved['probe'] );
			$writable = $readable && is_writable( $resolved['probe'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			$free     = $writable ? disk_free_space( $resolved['probe'] ) : false;
			$space    = false !== $free && $free > 0;

			if ( ! $private ) {
				return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::UNAVAILABLE, 'storage_path_not_private', false, false, false, false );
			}
			if ( ! $readable ) {
				return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::UNAVAILABLE, 'storage_path_not_readable', true, false, false, false );
			}
			if ( ! $writable ) {
				return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::UNAVAILABLE, 'storage_path_not_writable', true, true, false, false );
			}
			if ( ! $space ) {
				return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::UNAVAILABLE, 'storage_space_unavailable', true, true, true, false );
			}

			return new Storage_Path_Diagnostics( Storage_Path_Diagnostics::READY, 'storage_path_ready', true, true, true, true );
		} catch ( Throwable ) {
			return Storage_Path_Diagnostics::unavailable();
		}
	}

	/**
	 * Resolvable path.
	 *
	 * @param string $root Root.
	 * @return array{effective:string,probe:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function resolvable_path( string $root ): array {
		if ( file_exists( $root ) ) {
			if ( is_link( $root ) || ! is_dir( $root ) ) {
				throw new RuntimeException( 'Private storage root cannot be inspected.' );
			}
			$real = realpath( $root );
			if ( false === $real ) {
				throw new RuntimeException( 'Private storage root cannot be resolved.' );
			}
			return array(
				'effective' => $real,
				'probe'     => $real,
			);
		}

		$probe   = $root;
		$missing = array();
		while ( ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				throw new RuntimeException( 'Private storage parent is unavailable.' );
			}
			array_unshift( $missing, basename( $probe ) );
			$probe = $parent;
		}
		if ( ! is_dir( $probe ) ) {
			throw new RuntimeException( 'Private storage parent is invalid.' );
		}
		$real = realpath( $probe );
		if ( false === $real ) {
			throw new RuntimeException( 'Private storage parent cannot be resolved.' );
		}
		$effective = rtrim( $real, '/\\' );
		foreach ( $missing as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || str_contains( $part, "\0" ) ) {
				throw new RuntimeException( 'Private storage path is invalid.' );
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
	private function contains( string $parent, string $child ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$parent = strtolower( rtrim( str_replace( '\\', '/', $parent ), '/' ) ) . '/';
		$child  = strtolower( rtrim( str_replace( '\\', '/', $child ), '/' ) ) . '/';
		return str_starts_with( $child, $parent );
	}
}

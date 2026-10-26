<?php
/**
 * PSR-4 compatible autoloader for the plugin namespace.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin classes from the src directory.
 */
final class Autoloader {
	/**
	 * Root namespace prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'OJAI\\Dashboard\\';

	/**
	 * Base directory for source files.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Initialize autoloader dependencies and state.
	 *
	 * @param string $base_dir Absolute path to the source directory.
	 */
	public function __construct( $base_dir ) {
		$this->base_dir = rtrim( (string) $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register(
			function ( string $class ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
				$this->load( $class );
			}
		);
	}

	/**
	 * Load a class if it belongs to the plugin namespace.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return bool
	 */
	public function load( $class ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$prefix_length = strlen( self::PREFIX );

		if ( 0 !== strncmp( self::PREFIX, $class, $prefix_length ) ) {
			return false;
		}

		$relative_class = substr( $class, $prefix_length );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = $this->base_dir . $relative_path;

		if ( ! is_readable( $file ) ) {
			return false;
		}

		require $file;
		return true;
	}
}

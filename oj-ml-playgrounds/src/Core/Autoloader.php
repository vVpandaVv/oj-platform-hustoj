<?php
/**
 * Plugin-local PSR-4-compatible autoloader.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads classes in the OJML\Playgrounds namespace from src/.
 */
final class Autoloader {
	private const PREFIX = 'OJML\\Playgrounds\\';

	/**
	 * Absolute src directory with a trailing separator.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * @param string $base_dir Absolute source directory.
	 */
	public function __construct( $base_dir ) {
		$this->base_dir = rtrim( (string) $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Register this loader with SPL.
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Load one class owned by this plugin.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return bool
	 */
	public function load( $class ) {
		$prefix_length = strlen( self::PREFIX );

		if ( ! is_string( $class ) || 0 !== strncmp( self::PREFIX, $class, $prefix_length ) ) {
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

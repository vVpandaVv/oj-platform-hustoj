<?php
/**
 * Main plugin runtime.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Core;

use OJML\Playgrounds\Platform\Addon_Integration;
use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the add-on before OJ Platform freezes its registration catalog.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * @var bool
	 */
	private $booted = false;

	/**
	 * @var bool
	 */
	private $runtime_booted = false;

	/**
	 * @var Addon_Runtime_Bridge|null
	 */
	private $runtime;

	/**
	 * Keep the integration object alive for its WordPress callback.
	 *
	 * @var Addon_Integration|null
	 */
	private $integration;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( ! self::$instance instanceof self ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}

	/**
	 * Register plugin lifecycle hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		add_action( 'plugins_loaded', array( $this, 'boot_runtime' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
	}

	/**
	 * Compose only the public OJ Platform Addon API bridge and manifest entry.
	 *
	 * @return void
	 */
	public function boot_runtime() {
		if ( $this->runtime_booted || ! Addon_Integration::environment_available() ) {
			return;
		}

		$runtime     = new Addon_Runtime_Bridge();
		$integration = new Addon_Integration( $runtime );
		$integration->register_hooks();

		$this->runtime         = $runtime;
		$this->integration     = $integration;
		$this->runtime_booted  = true;

		/**
		 * Fires after the add-on's local graph exists but before the platform
		 * registration window opens at plugins_loaded priority 1.
		 *
		 * Consumers must use only plugin-owned contracts and the supplied public
		 * Runtime bridge; OJ Platform internals are intentionally unavailable.
		 */
		do_action( 'oj_ml_playgrounds_compose', $this, $runtime );
	}

	/**
	 * Expose the plugin-owned bridge to the local composition root.
	 *
	 * @return Addon_Runtime_Bridge|null
	 */
	public function runtime() {
		return $this->runtime;
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'oj-ml-playgrounds',
			false,
			dirname( OJ_ML_PLAYGROUNDS_BASENAME ) . '/languages'
		);
	}
}

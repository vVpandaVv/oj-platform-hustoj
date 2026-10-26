<?php
/**
 * Main plugin runtime.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

use OJAI\Dashboard\Platform\Addon_Integration;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Session_Nonce_Request_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin bootstrapping.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Add-on composition root.
	 *
	 * @var Composition_Root|null
	 */
	private $composition_root = null;

	/**
	 * Whether plugin boot has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Whether the Runtime graph has been booted.
	 *
	 * @var bool
	 */
	private $runtime_booted = false;

	/**
	 * Stable installation problem from a pending ZIP upgrade.
	 *
	 * @var array<string,string>|null
	 */
	private $runtime_upgrade_problem = null;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin constructor.
	 */
	private function __construct() {
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		$this->register_core_hooks();
	}

	/**
	 * Register core WordPress hooks.
	 *
	 * @return void
	 */
	private function register_core_hooks() {
		add_action( 'plugins_loaded', array( $this, 'boot_runtime' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'admin_notices', array( $this, 'render_admin_entry_notice' ) );
		add_filter( 'plugin_action_links_' . OJ_AI_DASHBOARD_BASENAME, array( $this, 'add_plugin_action_links' ) );
		add_filter( 'rest_json_encode_options', array( $this, 'unescape_rest_json_response' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_session_nonce_route' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'exempt_session_nonce_route_from_cookie_check' ), PHP_INT_MAX );
	}

	/**
	 * WordPress rejects every logged-in REST request that carries a stale
	 * X-WP-Nonce with a rest_cookie_invalid_nonce error, before any
	 * route's own permission_callback runs (rest_cookie_check_errors on
	 * rest_authentication_errors). That includes a request to THIS
	 * add-on's own session-nonce refresh endpoint, so a stale nonce could
	 * never recover — hence PHP_INT_MAX, so this always runs after
	 * WordPress's own check regardless of the exact priority it uses.
	 * Lift only that one rejection for this one route, and only once the
	 * normal auth cookie has already proven the visitor is logged in;
	 * every other route keeps WordPress's default nonce check.
	 *
	 * @param mixed $result Prior authentication error/state.
	 * @return mixed
	 */
	public function exempt_session_nonce_route_from_cookie_check( $result ) {
		if (
			is_wp_error( $result )
			&& array( 'rest_cookie_invalid_nonce' ) === $result->get_error_codes()
			&& Session_Nonce_Request_Guard::permits( '/oj-ai/v1/session-nonce' )
		) {
			return null;
		}

		return $result;
	}

	/**
	 * Registers a logged-in-only endpoint that hands back a live REST
	 * nonce. The public student pages localize wpApiSettings.nonce once
	 * into the page HTML; if a host or CDN caches that HTML, every later
	 * visitor gets back the first visitor's frozen value and fails
	 * WordPress's own cookie/nonce check. The front-end scripts call this
	 * endpoint and retry once whenever a request comes back 401/403, so a
	 * correct result no longer depends on that HTML never being cached.
	 *
	 * @return void
	 */
	public function register_session_nonce_route() {
		register_rest_route(
			'oj-ai/v1',
			'/session-nonce',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'session_nonce' ),
					'permission_callback' => static function () {
						return Session_Nonce_Request_Guard::permits( '/oj-ai/v1/session-nonce' );
					},
				),
			)
		);
	}

	/**
	 * Returns a live REST nonce for the current session.
	 *
	 * @return \WP_REST_Response
	 */
	public function session_nonce() {
		return new \WP_REST_Response(
			array( 'nonce' => wp_create_nonce( 'wp_rest' ) ),
			200,
			array(
				'Cache-Control' => 'no-store, private, max-age=0',
				'Vary'          => 'Cookie, Origin',
			)
		);
	}

	/**
	 * Renders this add-on's own REST JSON bodies with literal Korean text
	 * instead of \uXXXX escapes, so raw responses read the same as the
	 * already-correct browser-side (JSON.parse) rendering.
	 *
	 * @param int   $options Encoding options.
	 * @param mixed $request Request being served; unrelated filter values are left unchanged.
	 * @return int
	 */
	public function unescape_rest_json_response( $options, $request ) {
		if ( $request instanceof \WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/oj-ai/v1/' ) ) {
			return (int) $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		}

		return $options;
	}

	/**
	 * Build the Runtime only after every active plugin file has loaded.
	 *
	 * OJ Platform registers its autoloader while its root plugin file loads,
	 * then opens the add-on registration window at plugins_loaded priority 1.
	 * Priority 0 therefore avoids depending on active-plugin file order while
	 * still registering this add-on before the platform freezes its catalog.
	 *
	 * @return void
	 */
	public function boot_runtime() {
		if ( $this->runtime_booted || null !== $this->composition_root ) {
			return;
		}

		if ( ! Addon_Integration::api_contract_available() ) {
			return;
		}

		$upgrade_problem = Installation_Manager::run_pending_upgrade();
		if ( null !== $upgrade_problem ) {
			$this->runtime_upgrade_problem = $upgrade_problem;
			return;
		}

		$composition_root = new Composition_Root();
		$composition_root->boot();

		$this->composition_root = $composition_root;
		$this->runtime_booted   = true;
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'oj-ai-dashboard',
			false,
			dirname( OJ_AI_DASHBOARD_BASENAME ) . '/languages'
		);
	}

	/**
	 * Add direct paths to the console settings, worksheets and permission screens.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( array $links ) {
		$actions = array();
		if ( $this->can_manage_settings() ) {
			$actions[] = '<a href="' . $this->escape_url( $this->settings_url() ) . '">설정</a>';
		}
		if ( $this->can_manage_addon_roles() ) {
			if ( $this->can_author_worksheets() ) {
				$actions[] = '<a href="' . $this->escape_url( $this->worksheets_url() ) . '">활동지</a>';
			}
			$actions[] = '<a href="' . $this->escape_url( $this->roles_url() ) . '">권한 설정</a>';
		}

		return array_merge( $actions, $links );
	}

	/**
	 * Explain the one required first-run action without adding a wp-admin page.
	 *
	 * @return void
	 */
	public function render_admin_entry_notice() {
		if ( ! $this->can_manage_addon_roles() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if (
			null === $screen
			|| ! in_array( (string) $screen->id, array( 'plugins', 'plugins-network' ), true )
		) {
			return;
		}

		if ( null !== $this->runtime_upgrade_problem ) {
			$code = (string) ( $this->runtime_upgrade_problem['code'] ?? 'addon_upgrade_failed' );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
				$code = 'addon_upgrade_failed';
			}
			$correlation_id = (string) ( $this->runtime_upgrade_problem['correlation_id'] ?? '' );
			$reference      = $code;
			if ( 1 === preg_match( '/^[A-Za-z0-9._:-]{8,100}$/D', $correlation_id ) ) {
				$reference .= ', ' . $correlation_id;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The only dynamic value is escaped with ENT_QUOTES, ENT_SUBSTITUTE and explicit UTF-8 below.
			echo '<div class="notice notice-error"><p><strong>AI 평가 애드온 업데이트를 완료하지 못했습니다.</strong> 플러그인을 다시 활성화한 뒤 같은 문제가 반복되면 운영 복구를 진행하세요. (' . htmlspecialchars( $reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . ')</p></div>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$missing_capabilities = $this->missing_authoring_capability_labels();
		if ( array() === $missing_capabilities ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- URL uses the local esc_url wrapper; labels use explicit native quote escaping below.
		echo '<div class="notice notice-info"><p><strong>활동지 평가</strong>는 OJ 콘솔에서 사용합니다. 설정은 <a href="' . $this->escape_url( $this->settings_url() ) . '">교사 메뉴 › 활동지 평가 › 설정</a>에서 합니다. '
			. '<a href="' . $this->escape_url( $this->roles_url() ) . '">역할 설정 화면</a>의 애드온 권한에서 교사 역할에 '
			. '<strong>' . htmlspecialchars( implode( ', ', $missing_capabilities ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</strong> 권한을 배정하고 저장하세요. '
			. '이후 <strong>수업 &gt; 활동지 평가</strong>에서 새 활동지를 만들 수 있습니다.</p></div>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Whether the current user has the complete worksheet authoring bundle.
	 *
	 * @return bool
	 */
	private function can_author_worksheets() {
		return array() === $this->missing_authoring_capability_labels();
	}

	/**
	 * Return labels for missing worksheet authoring capabilities.
	 *
	 * @return string[]
	 */
	private function missing_authoring_capability_labels() {
		$required = array(
			Addon_Capabilities::VIEW_WORKSPACE    => 'AI 활동지 작업 공간 보기',
			Addon_Capabilities::VIEW_WORKSHEETS   => '활동지 보기',
			Addon_Capabilities::MANAGE_WORKSHEETS => '활동지 만들기·편집',
		);
		$missing  = array();

		foreach ( $required as $declared_capability => $label ) {
			$wordpress_capability = Addon_Capabilities::wordpress( $declared_capability );
			if ( '' === $wordpress_capability || ! current_user_can( $wordpress_capability ) ) {
				$missing[] = $label;
			}
		}

		return $missing;
	}

	/**
	 * Whether the current operator may manage platform role assignments.
	 *
	 * @return bool
	 */
	private function can_manage_addon_roles() {
		return function_exists( 'current_user_can' )
			&& ( current_user_can( 'oj_manage_roles' ) || current_user_can( 'manage_options' ) );
	}

	/**
	 * Whether the current user may open the console settings screen.
	 *
	 * Site administrators pass without a granted role, like the platform's own
	 * console page check; teachers need the settings capability.
	 *
	 * @return bool
	 */
	private function can_manage_settings() {
		if ( function_exists( 'is_super_admin' ) && is_super_admin() ) {
			return true;
		}
		$wordpress_capability = Addon_Capabilities::wordpress( Addon_Capabilities::MANAGE_POLICIES );

		return '' !== $wordpress_capability
			&& function_exists( 'current_user_can' )
			&& current_user_can( $wordpress_capability );
	}

	/**
	 * Return the console settings URL under the teacher menu.
	 *
	 * @return string
	 */
	private function settings_url() {
		return function_exists( 'home_url' )
			? home_url( '/oj-console/addons/oj-ai-dashboard/settings' )
			: '/oj-console/addons/oj-ai-dashboard/settings';
	}

	/**
	 * Return the platform-owned role assignment URL.
	 *
	 * @return string
	 */
	private function roles_url() {
		return function_exists( 'home_url' )
			? home_url( '/oj-console/roles#addon-capabilities' )
			: '/oj-console/roles#addon-capabilities';
	}

	/**
	 * Return the platform-owned worksheet authoring URL.
	 *
	 * @return string
	 */
	private function worksheets_url() {
		return function_exists( 'home_url' )
			? home_url( '/oj-console/addons/oj-ai-dashboard/worksheets' )
			: '/oj-console/addons/oj-ai-dashboard/worksheets';
	}

	/**
	 * Escape a URL when WordPress is available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function escape_url( $url ) {
		return function_exists( 'esc_url' )
			? esc_url( $url )
			: htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

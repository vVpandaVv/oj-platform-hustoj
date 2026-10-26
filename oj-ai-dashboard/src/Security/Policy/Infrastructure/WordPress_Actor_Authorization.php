<?php
/**
 * WordPress actor authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Infrastructure;

use Closure;
use OJAI\Dashboard\Security\Policy\Port\Actor_Authorization_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the exact actor rather than relying on ambient current-user state.
 */
final class WordPress_Actor_Authorization implements Actor_Authorization_Port_Interface {
	/**
	 * User loader.
	 *
	 * @var Closure
	 */
	private Closure $user_loader;
	/**
	 * Capability checker.
	 *
	 * @var Closure
	 */
	private Closure $capability_checker;
	/**
	 * Site administrator checker.
	 *
	 * @var Closure
	 */
	private Closure $super_admin_checker;

	/**
	 * Initialize WordPress actor authorization dependencies and state.
	 *
	 * @param Closure|null $user_loader User loader.
	 * @param Closure|null $capability_checker Capability checker.
	 * @param Closure|null $super_admin_checker Site administrator checker.
	 */
	public function __construct( ?Closure $user_loader = null, ?Closure $capability_checker = null, ?Closure $super_admin_checker = null ) {
		$this->user_loader         = $user_loader ?? static function ( int $actor_user_id ) {
			return function_exists( 'get_userdata' ) ? get_userdata( $actor_user_id ) : false;
		};
		$this->capability_checker  = $capability_checker ?? static function (
			int $actor_user_id,
			string $wordpress_capability
		): bool {
			return function_exists( 'user_can' ) && user_can( $actor_user_id, $wordpress_capability );
		};
		$this->super_admin_checker = $super_admin_checker ?? static function ( int $actor_user_id ): bool {
			return function_exists( 'is_super_admin' ) && is_super_admin( $actor_user_id );
		};
	}

	/**
	 * Is active.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return bool
	 */
	public function is_active( int $actor_user_id ): bool {
		$user = ( $this->user_loader )( $actor_user_id );
		if ( ! is_object( $user ) ) {
			return false;
		}
		if ( method_exists( $user, 'exists' ) && ! $user->exists() ) {
			return false;
		}

		return ! isset( $user->user_status ) || 0 === (int) $user->user_status;
	}

	/**
	 * Has capability.
	 *
	 * Site administrators pass every capability check, the same rule the
	 * platform applies to its console pages, so they can open and save the
	 * add-on settings without any role being granted to them.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $wordpress_capability WordPress capability.
	 * @return bool
	 */
	public function has_capability( int $actor_user_id, string $wordpress_capability ): bool {
		if ( $actor_user_id > 0 && (bool) ( $this->super_admin_checker )( $actor_user_id ) ) {
			return true;
		}

		return (bool) ( $this->capability_checker )( $actor_user_id, $wordpress_capability );
	}
}

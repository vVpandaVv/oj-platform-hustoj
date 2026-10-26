<?php
/**
 * Object authorization router.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates each object type to its owning module and fails closed otherwise.
 */
final class Object_Authorization_Router implements Object_Authorization_Port_Interface {
	/**
	 * Routes.
	 *
	 * @var array<string,Object_Authorization_Port_Interface>
	 */
	private array $routes;
	/**
	 * Fallback.
	 *
	 * @var Object_Authorization_Port_Interface
	 */
	private Object_Authorization_Port_Interface $fallback;

	/**
	 * Initialize object authorization router dependencies and state.
	 *
	 * @param array<array-key,mixed>                   $routes Routes.
	 * @param Object_Authorization_Port_Interface|null $fallback Fallback.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		array $routes,
		?Object_Authorization_Port_Interface $fallback = null
	) {
		foreach ( $routes as $type => $route ) {
			if ( ! is_string( $type ) || ! in_array( $type, Object_Scope::types(), true ) ) {
				throw new InvalidArgumentException( 'Object authorization route type is invalid.' );
			}
			if ( ! $route instanceof Object_Authorization_Port_Interface ) {
				throw new InvalidArgumentException( 'Object authorization route is invalid.' );
			}
		}

		$this->routes   = $routes;
		$this->fallback = $fallback ?? new Unavailable_Object_Authorization();
	}

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): Object_Authorization_Result {
		$route = $this->routes[ $object->type() ] ?? $this->fallback;

		return $route->authorize( $actor_user_id, $action_key, $context, $object );
	}
}

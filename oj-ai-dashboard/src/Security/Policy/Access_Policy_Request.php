<?php
/**
 * Common access policy request.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Addon_Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures the same authorization inputs for pages, REST, jobs, and artifacts.
 */
final class Access_Policy_Request {
	public const SHELL_CONSOLE = 'console';
	public const SHELL_PUBLIC  = 'public';

	public const SURFACE_PAGE     = 'page';
	public const SURFACE_REST     = 'rest';
	public const SURFACE_JOB      = 'job';
	public const SURFACE_ARTIFACT = 'artifact';

	public const CORE_DELETE_PLUGINS = 'delete_plugins';

	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Shell.
	 *
	 * @var string
	 */
	private string $shell;
	/**
	 * Surface.
	 *
	 * @var string
	 */
	private string $surface;
	/**
	 * Action key.
	 *
	 * @var string
	 */
	private string $action_key;

	/**
	 * Required capabilities.
	 *
	 * @var array<int,string>
	 */
	private array $required_capabilities;

	/**
	 * Required core capabilities.
	 *
	 * @var array<int,string>
	 */
	private array $required_core_capabilities;

	/**
	 * Access or execution context for this operation.
	 *
	 * @var Context_Scope|null
	 */
	private ?Context_Scope $context;
	/**
	 * Object.
	 *
	 * @var Object_Scope|null
	 */
	private ?Object_Scope $object;
	/**
	 * Request integrity verified.
	 *
	 * @var bool
	 */
	private bool $request_integrity_verified;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;

	/**
	 * Initialize access policy request dependencies and state.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $shell Shell.
	 * @param string             $surface Surface.
	 * @param string             $action_key Action key.
	 * @param array<int,string>  $required_capabilities Declared add-on capability keys.
	 * @param Context_Scope|null $context Access or execution context for this operation.
	 * @param Object_Scope|null  $object Object.
	 * @param bool               $request_integrity_verified Request integrity verified.
	 * @param string             $correlation_id Request correlation identifier for diagnostics.
	 * @param array<int,string>  $required_core_capabilities Explicit frozen WordPress core capabilities.
	 */
	public function __construct(
		int $actor_user_id,
		string $shell,
		string $surface,
		string $action_key,
		array $required_capabilities,
		?Context_Scope $context,
		?Object_Scope $object, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		bool $request_integrity_verified,
		string $correlation_id,
		array $required_core_capabilities = array()
	) {
		Policy_Value::actor_id( $actor_user_id );
		Policy_Value::one_of( $shell, array( self::SHELL_CONSOLE, self::SHELL_PUBLIC ), 'shell' );
		Policy_Value::one_of(
			$surface,
			array( self::SURFACE_PAGE, self::SURFACE_REST, self::SURFACE_JOB, self::SURFACE_ARTIFACT ),
			'surface'
		);
		Policy_Value::simple_key( $action_key, 'action_key' );
		Policy_Value::correlation_id( $correlation_id );

		$this->actor_user_id              = $actor_user_id;
		$this->shell                      = $shell;
		$this->surface                    = $surface;
		$this->action_key                 = $action_key;
		$this->required_capabilities      = $this->normalize_capabilities( $required_capabilities );
		$this->required_core_capabilities = $this->normalize_core_capabilities( $required_core_capabilities );
		$this->context                    = $context;
		$this->object                     = $object;
		$this->request_integrity_verified = $request_integrity_verified;
		$this->correlation_id             = $correlation_id;
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}

	/**
	 * Shell.
	 *
	 * @return string
	 */
	public function shell(): string {
		return $this->shell;
	}

	/**
	 * Surface.
	 *
	 * @return string
	 */
	public function surface(): string {
		return $this->surface;
	}

	/**
	 * Action key.
	 *
	 * @return string
	 */
	public function action_key(): string {
		return $this->action_key;
	}

	/**
	 * Required capabilities.
	 *
	 * @return array<int,string>
	 */
	public function required_capabilities(): array {
		return $this->required_capabilities;
	}

	/**
	 * Required core capabilities.
	 *
	 * @return array<int,string>
	 */
	public function required_core_capabilities(): array {
		return $this->required_core_capabilities;
	}

	/**
	 * Context.
	 *
	 * @return Context_Scope|null
	 */
	public function context(): ?Context_Scope {
		return $this->context;
	}

	/**
	 * Object.
	 *
	 * @return Object_Scope|null
	 */
	public function object(): ?Object_Scope {
		return $this->object;
	}

	/**
	 * Request integrity verified.
	 *
	 * @return bool
	 */
	public function request_integrity_verified(): bool {
		return $this->request_integrity_verified;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Normalize capabilities.
	 *
	 * @param array<array-key,mixed> $capabilities Capabilities.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_capabilities( array $capabilities ): array {
		if ( ! array_is_list( $capabilities ) ) {
			throw new InvalidArgumentException( 'Required capabilities must be a list.' );
		}

		$declared   = Addon_Capabilities::keys();
		$normalized = array();
		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || ! in_array( $capability, $declared, true ) ) {
				throw new InvalidArgumentException( 'Access policy requires an undeclared add-on capability.' );
			}
			$normalized[ $capability ] = true;
		}

		return array_keys( $normalized );
	}

	/**
	 * Normalize core capabilities.
	 *
	 * @param array<int,string> $capabilities Capabilities.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_core_capabilities( array $capabilities ): array {
		if ( ! array_is_list( $capabilities ) ) {
			throw new InvalidArgumentException( 'Required core capabilities must be a list.' );
		}

		$normalized = array();
		foreach ( $capabilities as $capability ) {
			if ( self::CORE_DELETE_PLUGINS !== $capability ) {
				throw new InvalidArgumentException( 'Access policy requires an unfrozen core capability.' );
			}
			$normalized[ $capability ] = true;
		}

		return array_keys( $normalized );
	}
}

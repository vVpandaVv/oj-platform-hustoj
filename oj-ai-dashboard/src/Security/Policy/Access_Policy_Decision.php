<?php
/**
 * Common access policy decision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Separates the stable public error code from the safe internal reason code.
 */
final class Access_Policy_Decision {
	public const CODE_ALLOWED                 = 'oj_ai_access_allowed';
	public const CODE_AUTHENTICATION_REQUIRED = 'oj_ai_authentication_required';
	public const CODE_ACCESS_DENIED           = 'oj_ai_access_denied';
	public const CODE_RESOURCE_NOT_FOUND      = 'oj_ai_resource_not_found';
	public const CODE_STATE_CONFLICT          = 'oj_ai_state_conflict';
	public const CODE_RUNTIME_UNAVAILABLE     = 'oj_ai_runtime_unavailable';

	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	private bool $allowed;
	/**
	 * Status.
	 *
	 * @var int
	 */
	private int $status;
	/**
	 * Code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Reason code.
	 *
	 * @var string
	 */
	private string $reason_code;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;
	/**
	 * Access or execution context for this operation.
	 *
	 * @var Context_Authorization_Result|null
	 */
	private ?Context_Authorization_Result $context;
	/**
	 * Object.
	 *
	 * @var Object_Authorization_Result|null
	 */
	private ?Object_Authorization_Result $object;

	/**
	 * Initialize access policy decision dependencies and state.
	 *
	 * @param bool                              $allowed Allowed.
	 * @param int                               $status Status.
	 * @param string                            $code Code.
	 * @param string                            $reason_code Reason code.
	 * @param string                            $correlation_id Request correlation identifier for diagnostics.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Authorization_Result|null  $object Object.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		bool $allowed,
		int $status,
		string $code,
		string $reason_code,
		string $correlation_id,
		?Context_Authorization_Result $context = null,
		?Object_Authorization_Result $object = null // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	) {
		$codes = array(
			200 => self::CODE_ALLOWED,
			401 => self::CODE_AUTHENTICATION_REQUIRED,
			403 => self::CODE_ACCESS_DENIED,
			404 => self::CODE_RESOURCE_NOT_FOUND,
			409 => self::CODE_STATE_CONFLICT,
			503 => self::CODE_RUNTIME_UNAVAILABLE,
		);
		if ( ! isset( $codes[ $status ] ) || $codes[ $status ] !== $code || $allowed !== ( 200 === $status ) ) {
			throw new InvalidArgumentException( 'Access policy status and public code do not match.' );
		}
		Policy_Value::stable_code( $reason_code, 'reason_code' );
		Policy_Value::correlation_id( $correlation_id );

		$this->allowed        = $allowed;
		$this->status         = $status;
		$this->code           = $code;
		$this->reason_code    = $reason_code;
		$this->correlation_id = $correlation_id;
		$this->context        = $context;
		$this->object         = $object;
	}

	/**
	 * Allowed.
	 *
	 * @param string                            $correlation_id Request correlation identifier for diagnostics.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Authorization_Result|null  $object Object.
	 * @return self
	 */
	public static function allowed(
		string $correlation_id,
		?Context_Authorization_Result $context = null,
		?Object_Authorization_Result $object = null // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): self {
		return new self( true, 200, self::CODE_ALLOWED, 'access_policy_allowed', $correlation_id, $context, $object );
	}

	/**
	 * Denied.
	 *
	 * @param int    $status Status.
	 * @param string $reason_code Reason code.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function denied( int $status, string $reason_code, string $correlation_id ): self {
		$codes = array(
			401 => self::CODE_AUTHENTICATION_REQUIRED,
			403 => self::CODE_ACCESS_DENIED,
			404 => self::CODE_RESOURCE_NOT_FOUND,
			409 => self::CODE_STATE_CONFLICT,
			503 => self::CODE_RUNTIME_UNAVAILABLE,
		);
		if ( ! isset( $codes[ $status ] ) ) {
			throw new InvalidArgumentException( 'Unsupported access policy denial status.' );
		}

		return new self( false, $status, $codes[ $status ], $reason_code, $correlation_id );
	}

	/**
	 * Is allowed.
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Status.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Reason code.
	 *
	 * @return string
	 */
	public function reason_code(): string {
		return $this->reason_code;
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
	 * Context.
	 *
	 * @return Context_Authorization_Result|null
	 */
	public function context(): ?Context_Authorization_Result {
		return $this->context;
	}

	/**
	 * Object.
	 *
	 * @return Object_Authorization_Result|null
	 */
	public function object(): ?Object_Authorization_Result {
		return $this->object;
	}
}

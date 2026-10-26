<?php
/**
 * REST authorization result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Policy_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves the common policy decision while adding REST input validation.
 */
final class Rest_Authorization_Result {
	/**
	 * Policy decision.
	 *
	 * @var Access_Policy_Decision
	 */
	private Access_Policy_Decision $policy_decision;
	/**
	 * Problem.
	 *
	 * @var Rest_Problem|null
	 */
	private ?Rest_Problem $problem;
	/**
	 * Idempotency key.
	 *
	 * @var Idempotency_Key|null
	 */
	private ?Idempotency_Key $idempotency_key;
	/**
	 * Reason code.
	 *
	 * @var string
	 */
	private string $reason_code;

	/**
	 * Initialize rest authorization result dependencies and state.
	 *
	 * @param Access_Policy_Decision $policy_decision Policy decision.
	 * @param Rest_Problem|null      $problem Problem.
	 * @param Idempotency_Key|null   $idempotency_key Idempotency key.
	 * @param string                 $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		Access_Policy_Decision $policy_decision,
		?Rest_Problem $problem,
		?Idempotency_Key $idempotency_key,
		string $reason_code
	) {
		Policy_Value::stable_code( $reason_code, 'rest_reason_code' );

		if (
			( ! $policy_decision->is_allowed() && ! ( $problem instanceof Rest_Problem ) )
			|| ( $problem instanceof Rest_Problem && $idempotency_key instanceof Idempotency_Key )
		) {
			throw new InvalidArgumentException( 'REST authorization result state is inconsistent.' );
		}
		if (
			$problem instanceof Rest_Problem
			&& ! hash_equals( $problem->correlation_id(), $policy_decision->correlation_id() )
		) {
			throw new InvalidArgumentException( 'REST authorization correlation IDs do not match.' );
		}

		$this->policy_decision = $policy_decision;
		$this->problem         = $problem;
		$this->idempotency_key = $idempotency_key;
		$this->reason_code     = $reason_code;
	}

	/**
	 * From policy.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @return self
	 */
	public static function from_policy( Access_Policy_Decision $decision ): self {
		if ( $decision->is_allowed() ) {
			return new self( $decision, null, null, $decision->reason_code() );
		}

		return new self(
			$decision,
			Rest_Problem::from_policy( $decision ),
			null,
			$decision->reason_code()
		);
	}

	/**
	 * Allowed.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @param Idempotency_Key|null   $idempotency_key Idempotency key.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function allowed(
		Access_Policy_Decision $decision,
		?Idempotency_Key $idempotency_key = null
	): self {
		if ( ! $decision->is_allowed() ) {
			throw new InvalidArgumentException( 'A denied policy decision cannot allow a REST request.' );
		}

		return new self( $decision, null, $idempotency_key, $decision->reason_code() );
	}

	/**
	 * Validation failed.
	 *
	 * @param Access_Policy_Decision $decision Decision.
	 * @param string                 $reason_code Reason code.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function validation_failed(
		Access_Policy_Decision $decision,
		string $reason_code
	): self {
		if ( ! $decision->is_allowed() ) {
			throw new InvalidArgumentException( 'REST input validation runs only after policy authorization.' );
		}

		return new self(
			$decision,
			Rest_Problem::for_status( 422, $decision->correlation_id() ),
			null,
			$reason_code
		);
	}

	/**
	 * Is allowed.
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return null === $this->problem;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->policy_decision->correlation_id();
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
	 * Policy decision.
	 *
	 * @return Access_Policy_Decision
	 */
	public function policy_decision(): Access_Policy_Decision {
		return $this->policy_decision;
	}

	/**
	 * Problem.
	 *
	 * @return Rest_Problem|null
	 */
	public function problem(): ?Rest_Problem {
		return $this->problem;
	}

	/**
	 * Idempotency key.
	 *
	 * @return Idempotency_Key|null
	 */
	public function idempotency_key(): ?Idempotency_Key {
		return $this->idempotency_key;
	}
}

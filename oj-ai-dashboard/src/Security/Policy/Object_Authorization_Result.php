<?php
/**
 * Normalized add-on object authorization result.
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
 * Carries only safe object authorization evidence and stable reason codes.
 */
final class Object_Authorization_Result {
	public const ALLOWED     = 'allowed';
	public const DENIED      = 'denied';
	public const CONFLICT    = 'conflict';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;
	/**
	 * Code.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Current revision.
	 *
	 * @var string|null
	 */
	private ?string $current_revision;
	/**
	 * Current state.
	 *
	 * @var string|null
	 */
	private ?string $current_state;
	/**
	 * Actor relation.
	 *
	 * @var string|null
	 */
	private ?string $actor_relation;

	/**
	 * Initialize object authorization result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string      $code Code.
	 * @param string|null $current_revision Current revision.
	 * @param string|null $current_state Current state.
	 * @param string|null $actor_relation Actor relation.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		string $status,
		string $code,
		?string $current_revision = null,
		?string $current_state = null,
		?string $actor_relation = null
	) {
		Policy_Value::one_of(
			$status,
			array( self::ALLOWED, self::DENIED, self::CONFLICT, self::UNAVAILABLE ),
			'object_result_status'
		);
		Policy_Value::stable_code( $code );

		if ( self::ALLOWED === $status ) {
			if ( null === $current_revision || null === $current_state || null === $actor_relation ) {
				throw new InvalidArgumentException( 'Allowed object results require complete evidence.' );
			}
			Policy_Value::safe_token( $current_revision, 'object_current_revision' );
			Policy_Value::safe_token( $current_state, 'object_current_state', 64 );
			Policy_Value::safe_token( $actor_relation, 'object_actor_relation', 80 );
		} elseif ( null !== $current_revision || null !== $current_state || null !== $actor_relation ) {
			throw new InvalidArgumentException( 'Denied object results cannot carry object evidence.' );
		}

		$this->status           = $status;
		$this->code             = $code;
		$this->current_revision = $current_revision;
		$this->current_state    = $current_state;
		$this->actor_relation   = $actor_relation;
	}

	/**
	 * Allowed.
	 *
	 * @param string $current_revision Current revision.
	 * @param string $current_state Current state.
	 * @param string $actor_relation Actor relation.
	 * @return self
	 */
	public static function allowed( string $current_revision, string $current_state, string $actor_relation ): self {
		return new self( self::ALLOWED, 'object_authorized', $current_revision, $current_state, $actor_relation );
	}

	/**
	 * Denied.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function denied( string $code = 'object_scope_denied' ): self {
		return new self( self::DENIED, $code );
	}

	/**
	 * Conflict.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function conflict( string $code = 'object_state_conflict' ): self {
		return new self( self::CONFLICT, $code );
	}

	/**
	 * Unavailable.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function unavailable( string $code = 'object_policy_unavailable' ): self {
		return new self( self::UNAVAILABLE, $code );
	}

	/**
	 * Is allowed.
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return self::ALLOWED === $this->status;
	}

	/**
	 * Is denied.
	 *
	 * @return bool
	 */
	public function is_denied(): bool {
		return self::DENIED === $this->status;
	}

	/**
	 * Is conflict.
	 *
	 * @return bool
	 */
	public function is_conflict(): bool {
		return self::CONFLICT === $this->status;
	}

	/**
	 * Is unavailable.
	 *
	 * @return bool
	 */
	public function is_unavailable(): bool {
		return self::UNAVAILABLE === $this->status;
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
	 * Current revision.
	 *
	 * @return string|null
	 */
	public function current_revision(): ?string {
		return $this->current_revision;
	}

	/**
	 * Current state.
	 *
	 * @return string|null
	 */
	public function current_state(): ?string {
		return $this->current_state;
	}

	/**
	 * Actor relation.
	 *
	 * @return string|null
	 */
	public function actor_relation(): ?string {
		return $this->actor_relation;
	}
}

<?php
/**
 * Normalized Platform Context authorization result.
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
 * Removes provider details while retaining evidence needed by object policy.
 */
final class Context_Authorization_Result {
	public const ALLOWED     = 'allowed';
	public const DENIED      = 'denied';
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
	 * Type.
	 *
	 * @var string|null
	 */
	private ?string $type;
	/**
	 * Id.
	 *
	 * @var string|null
	 */
	private ?string $id;
	/**
	 * Lifecycle state.
	 *
	 * @var string|null
	 */
	private ?string $lifecycle_state;
	/**
	 * Revision.
	 *
	 * @var string|null
	 */
	private ?string $revision;
	/**
	 * Actor relation.
	 *
	 * @var string|null
	 */
	private ?string $actor_relation;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string|null
	 */
	private ?string $correlation_id;

	/**
	 * Initialize context authorization result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string      $code Code.
	 * @param string|null $type Type.
	 * @param string|null $id Id.
	 * @param string|null $lifecycle_state Lifecycle state.
	 * @param string|null $revision Revision.
	 * @param string|null $actor_relation Actor relation.
	 * @param string|null $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		string $status,
		string $code,
		?string $type = null,
		?string $id = null,
		?string $lifecycle_state = null,
		?string $revision = null,
		?string $actor_relation = null,
		?string $correlation_id = null
	) {
		Policy_Value::one_of( $status, array( self::ALLOWED, self::DENIED, self::UNAVAILABLE ), 'context_result_status' );
		Policy_Value::stable_code( $code );
		Policy_Value::optional_safe_token( $correlation_id, 'context_correlation_id', 128 );

		if ( self::ALLOWED === $status ) {
			if ( null === $type || null === $id || null === $lifecycle_state || null === $revision || null === $actor_relation ) {
				throw new InvalidArgumentException( 'Allowed Context results require complete evidence.' );
			}
			Policy_Value::one_of( $type, array( Context_Scope::TYPE_CLASS, Context_Scope::TYPE_GROUP ), 'context_result_type' );
			Policy_Value::safe_token( $id, 'context_result_id' );
			Policy_Value::safe_token( $lifecycle_state, 'context_lifecycle_state', 64 );
			Policy_Value::safe_token( $revision, 'context_revision' );
			Policy_Value::safe_token( $actor_relation, 'context_actor_relation', 80 );
		} elseif ( null !== $type || null !== $id || null !== $lifecycle_state || null !== $revision || null !== $actor_relation ) {
			throw new InvalidArgumentException( 'Denied Context results cannot carry Context evidence.' );
		}

		$this->status          = $status;
		$this->code            = $code;
		$this->type            = $type;
		$this->id              = $id;
		$this->lifecycle_state = $lifecycle_state;
		$this->revision        = $revision;
		$this->actor_relation  = $actor_relation;
		$this->correlation_id  = $correlation_id;
	}

	/**
	 * Allowed.
	 *
	 * @param string      $type Type.
	 * @param string      $id Id.
	 * @param string      $lifecycle_state Lifecycle state.
	 * @param string      $revision Revision.
	 * @param string      $actor_relation Actor relation.
	 * @param string|null $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 */
	public static function allowed(
		string $type,
		string $id,
		string $lifecycle_state,
		string $revision,
		string $actor_relation,
		?string $correlation_id = null
	): self {
		return new self(
			self::ALLOWED,
			'context_authorized',
			$type,
			$id,
			$lifecycle_state,
			$revision,
			$actor_relation,
			$correlation_id
		);
	}

	/**
	 * Denied.
	 *
	 * @param string      $code Code.
	 * @param string|null $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 */
	public static function denied( string $code = 'context_scope_denied', ?string $correlation_id = null ): self {
		return new self( self::DENIED, $code, null, null, null, null, null, $correlation_id );
	}

	/**
	 * Unavailable.
	 *
	 * @param string      $code Code.
	 * @param string|null $correlation_id Request correlation identifier for diagnostics.
	 * @return self
	 */
	public static function unavailable(
		string $code = 'context_authorization_unavailable',
		?string $correlation_id = null
	): self {
		return new self( self::UNAVAILABLE, $code, null, null, null, null, null, $correlation_id );
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
	 * Type.
	 *
	 * @return string|null
	 */
	public function type(): ?string {
		return $this->type;
	}

	/**
	 * Id.
	 *
	 * @return string|null
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * Lifecycle state.
	 *
	 * @return string|null
	 */
	public function lifecycle_state(): ?string {
		return $this->lifecycle_state;
	}

	/**
	 * Revision.
	 *
	 * @return string|null
	 */
	public function revision(): ?string {
		return $this->revision;
	}

	/**
	 * Actor relation.
	 *
	 * @return string|null
	 */
	public function actor_relation(): ?string {
		return $this->actor_relation;
	}

	/**
	 * Correlation id.
	 *
	 * @return string|null
	 */
	public function correlation_id(): ?string {
		return $this->correlation_id;
	}
}

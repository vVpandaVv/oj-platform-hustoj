<?php
/**
 * Platform Context authorization scope.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the exact class or group authorization request.
 */
final class Context_Scope {
	public const TYPE_CLASS = 'class';
	public const TYPE_GROUP = 'group';

	public const OPERATION_VIEW        = 'view';
	public const OPERATION_ASSIGN      = 'assign';
	public const OPERATION_EVALUATE    = 'evaluate';
	public const OPERATION_REPORT      = 'report';
	public const OPERATION_MANAGE      = 'manage';
	public const OPERATION_PARTICIPATE = 'participate';

	/**
	 * Type.
	 *
	 * @var string
	 */
	private string $type;
	/**
	 * Id.
	 *
	 * @var string
	 */
	private string $id;
	/**
	 * Operation.
	 *
	 * @var string
	 */
	private string $operation;
	/**
	 * Learner user id.
	 *
	 * @var int|null
	 */
	private ?int $learner_user_id;
	/**
	 * Expected revision.
	 *
	 * @var string|null
	 */
	private ?string $expected_revision;

	/**
	 * Initialize context scope dependencies and state.
	 *
	 * @param string      $type Type.
	 * @param string      $id Id.
	 * @param string      $operation Operation.
	 * @param int|null    $learner_user_id Learner user id.
	 * @param string|null $expected_revision Expected revision.
	 */
	public function __construct(
		string $type,
		string $id,
		string $operation,
		?int $learner_user_id = null,
		?string $expected_revision = null
	) {
		Policy_Value::one_of( $type, array( self::TYPE_CLASS, self::TYPE_GROUP ), 'context_type' );
		Policy_Value::safe_token( $id, 'context_id' );
		Policy_Value::one_of(
			$operation,
			array(
				self::OPERATION_VIEW,
				self::OPERATION_ASSIGN,
				self::OPERATION_EVALUATE,
				self::OPERATION_REPORT,
				self::OPERATION_MANAGE,
				self::OPERATION_PARTICIPATE,
			),
			'context_operation'
		);
		if ( null !== $learner_user_id ) {
			Policy_Value::positive_id( $learner_user_id, 'learner_user_id' );
		}
		Policy_Value::optional_safe_token( $expected_revision, 'expected_context_revision' );

		$this->type              = $type;
		$this->id                = $id;
		$this->operation         = $operation;
		$this->learner_user_id   = $learner_user_id;
		$this->expected_revision = $expected_revision;
	}

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Learner user id.
	 *
	 * @return int|null
	 */
	public function learner_user_id(): ?int {
		return $this->learner_user_id;
	}

	/**
	 * Expected revision.
	 *
	 * @return string|null
	 */
	public function expected_revision(): ?string {
		return $this->expected_revision;
	}
}

<?php
/**
 * Add-on object authorization scope.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifies one object, expected version, and requested transition.
 */
final class Object_Scope {
	public const TYPE_WORKSHEET          = 'worksheet';
	public const TYPE_WORKSHEET_REVISION = 'worksheet_revision';
	public const TYPE_ASSIGNMENT         = 'assignment';
	public const TYPE_SUBMISSION         = 'submission';
	public const TYPE_EVALUATION         = 'evaluation';
	public const TYPE_STUDENT_NOTE       = 'student_note';
	public const TYPE_RELEASE            = 'release';
	public const TYPE_REPORT             = 'report';
	public const TYPE_ARTIFACT           = 'artifact';
	public const TYPE_POLICY             = 'policy';
	public const TYPE_RUBRIC             = 'rubric';
	public const TYPE_MIGRATION          = 'migration';
	public const TYPE_PURGE_PLAN         = 'purge_plan';

	private const CONTEXT_FREE_TYPES = array(
		self::TYPE_WORKSHEET,
		self::TYPE_WORKSHEET_REVISION,
		self::TYPE_EVALUATION,
		self::TYPE_POLICY,
		self::TYPE_RUBRIC,
		self::TYPE_MIGRATION,
		self::TYPE_PURGE_PLAN,
	);

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
	 * Action.
	 *
	 * @var string
	 */
	private string $action;
	/**
	 * Expected revision.
	 *
	 * @var string|null
	 */
	private ?string $expected_revision;
	/**
	 * Expected state.
	 *
	 * @var string|null
	 */
	private ?string $expected_state;

	/**
	 * Initialize object scope dependencies and state.
	 *
	 * @param string      $type Type.
	 * @param string      $id Id.
	 * @param string      $action Action.
	 * @param string|null $expected_revision Expected revision.
	 * @param string|null $expected_state Expected state.
	 */
	public function __construct(
		string $type,
		string $id,
		string $action,
		?string $expected_revision = null,
		?string $expected_state = null
	) {
		Policy_Value::one_of( $type, self::types(), 'object_type' );
		Policy_Value::safe_token( $id, 'object_id' );
		Policy_Value::simple_key( $action, 'object_action' );
		Policy_Value::optional_safe_token( $expected_revision, 'expected_object_revision' );
		Policy_Value::optional_safe_token( $expected_state, 'expected_object_state', 64 );

		$this->type              = $type;
		$this->id                = $id;
		$this->action            = $action;
		$this->expected_revision = $expected_revision;
		$this->expected_state    = $expected_state;
	}

	/**
	 * Types.
	 *
	 * @return array<int,string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_WORKSHEET,
			self::TYPE_WORKSHEET_REVISION,
			self::TYPE_ASSIGNMENT,
			self::TYPE_SUBMISSION,
			self::TYPE_EVALUATION,
			self::TYPE_STUDENT_NOTE,
			self::TYPE_RELEASE,
			self::TYPE_REPORT,
			self::TYPE_ARTIFACT,
			self::TYPE_POLICY,
			self::TYPE_RUBRIC,
			self::TYPE_MIGRATION,
			self::TYPE_PURGE_PLAN,
		);
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
	 * Action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Expected revision.
	 *
	 * @return string|null
	 */
	public function expected_revision(): ?string {
		return $this->expected_revision;
	}

	/**
	 * Expected state.
	 *
	 * @return string|null
	 */
	public function expected_state(): ?string {
		return $this->expected_state;
	}

	/**
	 * Requires context.
	 *
	 * @return bool
	 */
	public function requires_context(): bool {
		return ! in_array( $this->type, self::CONTEXT_FREE_TYPES, true );
	}
}

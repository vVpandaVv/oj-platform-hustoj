<?php
/**
 * Neutral teaching Context summary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains only fields approved for the teacher selection projection.
 */
final class Teaching_Context_Summary {
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
	 * Label.
	 *
	 * @var string
	 */
	private string $label;
	/**
	 * Lifecycle state.
	 *
	 * @var string
	 */
	private string $lifecycle_state;
	/**
	 * Revision.
	 *
	 * @var string
	 */
	private string $revision;
	/**
	 * Enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;
	/**
	 * Academic year.
	 *
	 * @var string|null
	 */
	private ?string $academic_year;
	/**
	 * Grade label.
	 *
	 * @var string|null
	 */
	private ?string $grade_label;

	/**
	 * Initialize teaching context summary dependencies and state.
	 *
	 * @param string      $type Type.
	 * @param string      $id Id.
	 * @param string      $label Label.
	 * @param string      $lifecycle_state Lifecycle state.
	 * @param string      $revision Revision.
	 * @param bool        $enabled Enabled.
	 * @param string|null $academic_year Academic year.
	 * @param string|null $grade_label Grade label.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $type,
		string $id,
		string $label,
		string $lifecycle_state,
		string $revision,
		bool $enabled,
		?string $academic_year = null,
		?string $grade_label = null
	) {
		if ( ! in_array( $type, array( 'class', 'group' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported teaching Context type.' );
		}
		Context_Value::safe_token( $id, 'id' );
		Context_Value::label( $label );
		Context_Value::safe_token( $lifecycle_state, 'lifecycle_state', 64 );
		Context_Value::safe_token( $revision, 'revision' );
		if ( null !== $academic_year ) {
			Context_Value::text( $academic_year, 'academic_year', 32 );
		}
		if ( null !== $grade_label ) {
			Context_Value::label( $grade_label, 'grade_label' );
		}

		$this->type            = $type;
		$this->id              = $id;
		$this->label           = $label;
		$this->lifecycle_state = $lifecycle_state;
		$this->revision        = $revision;
		$this->enabled         = $enabled;
		$this->academic_year   = $academic_year;
		$this->grade_label     = $grade_label;
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
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Lifecycle state.
	 *
	 * @return string
	 */
	public function lifecycle_state(): string {
		return $this->lifecycle_state;
	}

	/**
	 * Revision.
	 *
	 * @return string
	 */
	public function revision(): string {
		return $this->revision;
	}

	/**
	 * Enabled.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Academic year.
	 *
	 * @return string|null
	 */
	public function academic_year(): ?string {
		return $this->academic_year;
	}

	/**
	 * Grade label.
	 *
	 * @return string|null
	 */
	public function grade_label(): ?string {
		return $this->grade_label;
	}

	/**
	 * Is selectable.
	 *
	 * @return bool
	 */
	public function is_selectable(): bool {
		return 'active' === $this->lifecycle_state && $this->enabled;
	}
}

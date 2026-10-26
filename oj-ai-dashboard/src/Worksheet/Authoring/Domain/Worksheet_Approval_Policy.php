<?php
/**
 * Immutable worksheet approval policy revision.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the policy values needed before an explicit approval command.
 */
final class Worksheet_Approval_Policy {
	public const SCHEMA_ID      = 'oj_ai.worksheet_approval_policy';
	public const SCHEMA_VERSION = '1.0.0';

	public const EVALUATION_REQUIRED = 'required';
	public const EVALUATION_OPTIONAL = 'optional';

	/**
	 * Content hash.
	 *
	 * @var string
	 */
	private string $content_hash;

	/**
	 * Initialize worksheet approval policy dependencies and state.
	 *
	 * @param string         $revision_id Revision id.
	 * @param string         $evaluation_requirement Evaluation requirement.
	 * @param bool           $allow_finding_dismissal Allow finding dismissal.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $revision_id,
		private string $evaluation_requirement,
		private bool $allow_finding_dismissal,
		Canonical_Json $canonical
	) {
		new Public_Id( $revision_id );
		if ( ! in_array( $evaluation_requirement, array( self::EVALUATION_REQUIRED, self::EVALUATION_OPTIONAL ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet approval evaluation requirement is invalid.' );
		}

		$this->content_hash = hash( 'sha256', $canonical->encode( $this->projection_without_hash() ) );
	}

	/**
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
	}

	/**
	 * Evaluation requirement.
	 *
	 * @return string
	 */
	public function evaluation_requirement(): string {
		return $this->evaluation_requirement;
	}

	/**
	 * Evaluation required.
	 *
	 * @return bool
	 */
	public function evaluation_required(): bool {
		return self::EVALUATION_REQUIRED === $this->evaluation_requirement;
	}

	/**
	 * Allow finding dismissal.
	 *
	 * @return bool
	 */
	public function allow_finding_dismissal(): bool {
		return $this->allow_finding_dismissal;
	}

	/**
	 * Review required.
	 *
	 * @return bool
	 */
	public function review_required(): bool {
		return true;
	}

	/**
	 * Explicit release required.
	 *
	 * @return bool
	 */
	public function explicit_release_required(): bool {
		return true;
	}

	/**
	 * Content hash.
	 *
	 * @return string
	 */
	public function content_hash(): string {
		return $this->content_hash;
	}

	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return array_merge(
			$this->projection_without_hash(),
			array( 'content_hash' => $this->content_hash )
		);
	}

	/**
	 * Projection without hash.
	 *
	 * @return array<string,mixed>
	 */
	private function projection_without_hash(): array {
		return array(
			'schema_id'                 => self::SCHEMA_ID,
			'schema_version'            => self::SCHEMA_VERSION,
			'revision_id'               => $this->revision_id,
			'subject_type'              => 'worksheet_quality',
			'evaluation_requirement'    => $this->evaluation_requirement,
			'allow_finding_dismissal'   => $this->allow_finding_dismissal,
			'review_required'           => true,
			'explicit_release_required' => true,
		);
	}
}

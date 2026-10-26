<?php
/**
 * Exact assignment roster snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries one race-checked platform roster without PII.
 */
final class Assignment_Roster_Snapshot {
	/**
	 * Context type.
	 *
	 * @var string
	 */
	private string $context_type;
	/**
	 * Context id.
	 *
	 * @var string
	 */
	private string $context_id;
	/**
	 * Context revision.
	 *
	 * @var string
	 */
	private string $context_revision;
	/**
	 * Roster revision.
	 *
	 * @var string
	 */
	private string $roster_revision;

	/**
	 * Learners.
	 *
	 * @var array<int,array{learner_user_id:int,membership_revision:string}>
	 */
	private array $learners;

	/**
	 * Initialize assignment roster snapshot dependencies and state.
	 *
	 * @param string                 $context_type Context type.
	 * @param string                 $context_id Context id.
	 * @param string                 $context_revision Context revision.
	 * @param string                 $roster_revision Roster revision.
	 * @param array<array-key,mixed> $learners Raw roster entries, validated and sorted before storage.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $context_type,
		string $context_id,
		string $context_revision,
		string $roster_revision,
		array $learners
	) {
		if ( ! in_array( $context_type, array( Context_Scope::TYPE_CLASS, Context_Scope::TYPE_GROUP ), true ) ) {
			throw new InvalidArgumentException( 'Assignment roster Context type is invalid.' );
		}
		foreach ( array( $context_id, $context_revision, $roster_revision ) as $token ) {
			if ( '' === $token || strlen( $token ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $token ) ) {
				throw new InvalidArgumentException( 'Assignment roster token is invalid.' );
			}
		}
		if ( ! array_is_list( $learners ) || count( $learners ) > 5000 ) {
			throw new InvalidArgumentException( 'Assignment roster size is invalid.' );
		}
		$seen = array();
		foreach ( $learners as $learner ) {
			if ( ! is_array( $learner ) || array_keys( $learner ) !== array( 'learner_user_id', 'membership_revision' ) ) {
				throw new InvalidArgumentException( 'Assignment learner shape is invalid.' );
			}
			$user_id  = $learner['learner_user_id'];
			$revision = $learner['membership_revision'];
			if ( ! is_int( $user_id ) || $user_id < 1 || isset( $seen[ $user_id ] ) || ! is_string( $revision ) ) {
				throw new InvalidArgumentException( 'Assignment learner is invalid.' );
			}
			if ( '' === $revision || strlen( $revision ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $revision ) ) {
				throw new InvalidArgumentException( 'Assignment membership revision is invalid.' );
			}
			$seen[ $user_id ] = true;
		}

		usort( $learners, static fn ( array $left, array $right ): int => $left['learner_user_id'] <=> $right['learner_user_id'] );
		$this->context_type     = $context_type;
		$this->context_id       = $context_id;
		$this->context_revision = $context_revision;
		$this->roster_revision  = $roster_revision;
		$this->learners         = $learners;
	}

	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Roster revision.
	 *
	 * @return string
	 */
	public function roster_revision(): string {
		return $this->roster_revision;
	}
	/**
	 * Learners.
	 *
	 * @return array<int,array{learner_user_id:int,membership_revision:string}>
	 */
	public function learners(): array {
		return $this->learners;
	}
	/**
	 * Count.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->learners );
	}
}

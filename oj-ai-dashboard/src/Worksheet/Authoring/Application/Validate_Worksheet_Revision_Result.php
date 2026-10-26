<?php
/**
 * ValidateWorksheetRevision result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ValidateWorksheetRevision result.
 */
final class Validate_Worksheet_Revision_Result {
	/**
	 * Initialize worksheet revision result dependencies and state.
	 *
	 * @param string                         $worksheet_id Worksheet public identifier.
	 * @param string                         $revision_id Revision id.
	 * @param string                         $revision_state Revision state.
	 * @param string                         $document_hash Document hash.
	 * @param string                         $source_validation_hash Source validation hash.
	 * @param string                         $status Status.
	 * @param bool                           $release_ready Release ready.
	 * @param array<int,mixed>               $checks Check records validated by the approval policy gate.
	 * @param array<int,array<string,mixed>> $blockers Blockers.
	 * @param array<int,array<string,mixed>> $warnings Warnings.
	 * @param array<string,int>              $statistics Statistics.
	 * @param string                         $projection_hash Projection hash.
	 * @param string                         $validated_at_utc Validated at utc.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $worksheet_id,
		private string $revision_id,
		private string $revision_state,
		private string $document_hash,
		private string $source_validation_hash,
		private string $status,
		private bool $release_ready,
		private array $checks,
		private array $blockers,
		private array $warnings,
		private array $statistics,
		private string $projection_hash,
		private string $validated_at_utc
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $revision_id );
		if ( ! in_array( $revision_state, array( 'draft', 'approved' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet validation revision state is invalid.' );
		}
		foreach ( array( $document_hash, $source_validation_hash, $projection_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Worksheet validation hash is invalid.' );
			}
		}
		if ( ! in_array( $status, array( 'pending', 'blocked', 'ready' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet validation status is invalid.' );
		}
		if ( $release_ready !== ( 'ready' === $status ) ) {
			throw new InvalidArgumentException( 'Worksheet validation readiness is inconsistent.' );
		}
		if ( ! array_is_list( $checks ) || ! array_is_list( $blockers ) || ! array_is_list( $warnings ) ) {
			throw new InvalidArgumentException( 'Worksheet validation lists are invalid.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $validated_at_utc ) ) {
			throw new InvalidArgumentException( 'Worksheet validation UTC value is invalid.' );
		}
	}

	/**
	 * Worksheet id.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return $this->worksheet_id;
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
	 * Revision state.
	 *
	 * @return string
	 */
	public function revision_state(): string {
		return $this->revision_state;
	}
	/**
	 * Document hash.
	 *
	 * @return string
	 */
	public function document_hash(): string {
		return $this->document_hash;
	}
	/**
	 * Source validation hash.
	 *
	 * @return string
	 */
	public function source_validation_hash(): string {
		return $this->source_validation_hash;
	}
	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}
	/**
	 * Release ready.
	 *
	 * @return bool
	 */
	public function release_ready(): bool {
		return $this->release_ready;
	}
	/**
	 * Checks.
	 *
	 * @return list<mixed> Check records validated by the approval policy gate.
	 */
	public function checks(): array {
		return $this->checks;
	}
	/**
	 * Blockers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function blockers(): array {
		return $this->blockers;
	}
	/**
	 * Warnings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function warnings(): array {
		return $this->warnings;
	}
	/**
	 * Statistics.
	 *
	 * @return array<string,int>
	 */
	public function statistics(): array {
		return $this->statistics;
	}
	/**
	 * Projection hash.
	 *
	 * @return string
	 */
	public function projection_hash(): string {
		return $this->projection_hash;
	}
	/**
	 * Validated at utc.
	 *
	 * @return string
	 */
	public function validated_at_utc(): string {
		return $this->validated_at_utc;
	}
}

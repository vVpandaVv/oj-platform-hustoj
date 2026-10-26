<?php
/**
 * Legacy mapping manifest.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OverflowException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds PII-free mapping requirements from actual assignment dependencies.
 */
final class Legacy_Mapping_Manifest {
	public const MAXIMUM_REQUIREMENTS = 50000;

	/**
	 * Assignment contexts.
	 *
	 * @var array<int,array{assignment_source_ref:string,context_source_ref:string}>
	 */
	private array $assignment_contexts = array();

	/**
	 * Contexts.
	 *
	 * @var array<string,array{kind:string,source_ref:string,source_type:string}>
	 */
	private array $contexts = array();

	/**
	 * Users.
	 *
	 * @var array<string,array{assignment_source_ref:string,context_source_ref:string,kind:string,source_ref:string}>
	 */
	private array $users = array();

	/**
	 * Conflicts.
	 *
	 * @var array<string,int>
	 */
	private array $conflicts = array();

	/**
	 * Initialize legacy mapping manifest dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string         $source_profile Source profile.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private string $source_profile
	) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $source_profile ) ) {
			throw new InvalidArgumentException( 'Legacy mapping source profile is invalid.' );
		}
	}

	/**
	 * Observe.
	 *
	 * @param string              $logical_table Logical table.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	public function observe( string $logical_table, array $record ): void {
		if ( 'worksheet_assignments' === $logical_table ) {
			$this->observe_assignment( $record );
		} elseif ( 'worksheet_submissions' === $logical_table ) {
			$this->observe_submission( $record );
		}
	}

	/**
	 * Manifest.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function manifest(): array {
		ksort( $this->contexts, SORT_STRING );
		ksort( $this->users, SORT_STRING );
		return array_values( array_merge( $this->contexts, $this->users ) );
	}

	/**
	 * Requirements.
	 *
	 * @return array{context:int,user:int}
	 */
	public function requirements(): array {
		return array(
			'context' => count( $this->contexts ),
			'user'    => count( $this->users ),
		);
	}

	/**
	 * Conflict counts.
	 *
	 * @return array<string,int>
	 */
	public function conflict_counts(): array {
		ksort( $this->conflicts, SORT_STRING );
		return $this->conflicts;
	}

	/**
	 * Context ref for assignment.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string|null
	 */
	public function context_ref_for_assignment( array $record ): ?string {
		$group_id = $this->non_negative_integer( $record['group_id'] ?? null );
		if ( $group_id > 0 ) {
			return $this->source_ref(
				'context',
				array(
					'group_id'    => $group_id,
					'source_type' => 'group',
				)
			);
		}

		$academic_year = $this->source_text( $record['academic_year'] ?? null, 20 );
		$grade         = $this->source_text( $record['grade'] ?? null, 20 );
		$class_name    = $this->source_text( $record['class_name'] ?? null, 64 );
		if ( '' === $academic_year || '' === $grade || '' === $class_name ) {
			return null;
		}

		return $this->source_ref(
			'context',
			array(
				'academic_year' => $academic_year,
				'class_name'    => $class_name,
				'grade'         => $grade,
				'source_type'   => 'class',
			)
		);
	}

	/**
	 * Assignment ref.
	 *
	 * @param int $source_assignment_id Source assignment id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assignment_ref( int $source_assignment_id ): string {
		if ( $source_assignment_id < 1 ) {
			throw new InvalidArgumentException( 'Legacy assignment mapping identity is invalid.' );
		}
		return $this->source_ref(
			'assignment',
			array( 'source_assignment_id' => $source_assignment_id )
		);
	}

	/**
	 * User ref.
	 *
	 * @param string $context_source_ref Context source ref.
	 * @param string $assignment_source_ref Assignment source ref.
	 * @param int    $source_user_id Source user id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function user_ref( string $context_source_ref, string $assignment_source_ref, int $source_user_id ): string {
		$this->hash_value( $context_source_ref );
		$this->hash_value( $assignment_source_ref );
		if ( $source_user_id < 1 ) {
			throw new InvalidArgumentException( 'Legacy mapping source user is invalid.' );
		}
		return $this->source_ref(
			'user',
			array(
				'assignment_source_ref' => $assignment_source_ref,
				'context_source_ref'    => $context_source_ref,
				'source_user_id'        => $source_user_id,
			)
		);
	}

	/**
	 * Observe assignment.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function observe_assignment( array $record ): void {
		$assignment_id = $this->positive_integer( $record['id'] ?? null );
		if ( isset( $this->assignment_contexts[ $assignment_id ] ) ) {
			throw new InvalidArgumentException( 'Legacy assignment mapping identity is duplicated.' );
		}
		$source_ref = $this->context_ref_for_assignment( $record );
		if ( null === $source_ref ) {
			$this->conflict( 'legacy_assignment_context_missing' );
			return;
		}
		$source_type                                 = $this->non_negative_integer( $record['group_id'] ?? null ) > 0 ? 'group' : 'class';
		$this->assignment_contexts[ $assignment_id ] = array(
			'assignment_source_ref' => $this->assignment_ref( $assignment_id ),
			'context_source_ref'    => $source_ref,
		);
		$this->contexts[ $source_ref ]               = array(
			'kind'        => 'context',
			'source_ref'  => $source_ref,
			'source_type' => $source_type,
		);
		$this->assert_limit();
	}

	/**
	 * Observe submission.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function observe_submission( array $record ): void {
		$assignment_id  = $this->positive_integer( $record['assignment_id'] ?? null );
		$source_user_id = $this->positive_integer( $record['student_user_id'] ?? null );
		$assignment     = $this->assignment_contexts[ $assignment_id ] ?? null;
		if ( ! is_array( $assignment ) ) {
			$this->conflict( 'legacy_submission_context_missing' );
			return;
		}
		$context_source_ref         = $assignment['context_source_ref'];
		$assignment_source_ref      = $assignment['assignment_source_ref'];
		$source_ref                 = $this->user_ref( $context_source_ref, $assignment_source_ref, $source_user_id );
		$this->users[ $source_ref ] = array(
			'assignment_source_ref' => $assignment_source_ref,
			'context_source_ref'    => $context_source_ref,
			'kind'                  => 'user',
			'source_ref'            => $source_ref,
		);
		$this->assert_limit();
	}

	/**
	 * Source ref.
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $identity Identity.
	 * @return string
	 */
	private function source_ref( string $kind, array $identity ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'identity'       => $identity,
					'kind'           => $kind,
					'source_profile' => $this->source_profile,
				)
			)
		);
	}

	/**
	 * Conflict.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	private function conflict( string $code ): void {
		$this->conflicts[ $code ] = ( $this->conflicts[ $code ] ?? 0 ) + 1;
	}

	/**
	 * Reject invalid limit.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_limit(): void {
		if ( count( $this->contexts ) + count( $this->users ) > self::MAXIMUM_REQUIREMENTS ) {
			throw new OverflowException( 'Legacy mapping requirement limit exceeded.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			throw new InvalidArgumentException( 'Legacy mapping integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Legacy mapping integer is invalid.' );
	}

	/**
	 * Source text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_text( $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy mapping text is invalid.' );
		}
		$value  = trim( $value );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Legacy mapping text is invalid.' );
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy mapping hash is invalid.' );
		}
	}
}

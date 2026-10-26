<?php
/**
 * Deterministic worksheet preview projection value.
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
 * Deterministic worksheet preview projection value.
 */
final class Worksheet_Preview_Projection {
	/**
	 * Initialize worksheet preview projection dependencies and state.
	 *
	 * @param string                 $kind Kind.
	 * @param string                 $profile Profile.
	 * @param array<array-key,mixed> $projection Projection.
	 * @param string                 $projection_hash Projection hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $kind,
		private string $profile,
		private array $projection,
		private string $projection_hash
	) {
		if ( ! in_array( $kind, array( 'student', 'print' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet preview kind is invalid.' );
		}
		if (
			( 'student' === $kind && 'student' !== $profile )
			|| ( 'print' === $kind && ! in_array( $profile, array( 'student_blank', 'teacher_blank' ), true ) )
		) {
			throw new InvalidArgumentException( 'Worksheet preview profile is invalid.' );
		}
		if ( array() === $projection || array_is_list( $projection ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $projection_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet preview projection is invalid.' );
		}
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}
	/**
	 * Profile.
	 *
	 * @return string
	 */
	public function profile(): string {
		return $this->profile;
	}
	/**
	 * Projection.
	 *
	 * @return array<string,mixed>
	 */
	public function projection(): array {
		return $this->projection;
	}
	/**
	 * Projection hash.
	 *
	 * @return string
	 */
	public function projection_hash(): string {
		return $this->projection_hash;
	}
}

<?php
/**
 * Immutable worksheet publication projection.
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
 * Immutable worksheet publication projection.
 */
final class Worksheet_Publication_Projection {
	/**
	 * Initialize worksheet publication projection dependencies and state.
	 *
	 * @param array<string,array<array-key,mixed>> $profiles Profiles.
	 * @param array<array-key,mixed>               $manifest Manifest.
	 * @param string                               $manifest_version Manifest version.
	 * @param string                               $publication_hash Publication hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $profiles,
		private array $manifest,
		private string $manifest_version,
		private string $publication_hash
	) {
		$expected = array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' );
		$actual   = array_keys( $profiles );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if (
			$actual !== $expected
			|| array() === $manifest
			|| array_is_list( $manifest )
			|| '1.0.0' !== $manifest_version
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $publication_hash )
		) {
			throw new InvalidArgumentException( 'Worksheet publication projection is invalid.' );
		}
		foreach ( $profiles as $profile ) {
			if ( array() === $profile || array_is_list( $profile ) ) {
				throw new InvalidArgumentException( 'Worksheet publication profile is invalid.' );
			}
		}
	}

	/**
	 * Profiles.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function profiles(): array {
		return $this->profiles;
	}
	/**
	 * Profile.
	 *
	 * @param string $name Name.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function profile( string $name ): array {
		if ( ! isset( $this->profiles[ $name ] ) ) {
			throw new InvalidArgumentException( 'Worksheet publication profile name is invalid.' );
		}
		return $this->profiles[ $name ];
	}
	/**
	 * Manifest.
	 *
	 * @return array<string,mixed>
	 */
	public function manifest(): array {
		return $this->manifest;
	}
	/**
	 * Manifest version.
	 *
	 * @return string
	 */
	public function manifest_version(): string {
		return $this->manifest_version;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
	}
}

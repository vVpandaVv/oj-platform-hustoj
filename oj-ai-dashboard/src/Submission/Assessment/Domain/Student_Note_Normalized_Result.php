<?php
/**
 * Student note normalized result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student note normalized result.
 */
final class Student_Note_Normalized_Result {
	/**
	 * Initialize student note normalized result dependencies and state.
	 *
	 * @param array<array-key,mixed> $profile Profile.
	 * @param string                 $profile_json Profile json.
	 * @param string                 $profile_hash Profile hash.
	 * @param string                 $provider_output_digest Provider output digest.
	 * @param bool                   $safety_flagged Safety flagged.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $profile,
		private string $profile_json,
		private string $profile_hash,
		private string $provider_output_digest,
		private bool $safety_flagged
	) {
		if ( array_is_list( $profile ) || ! hash_equals( $profile_hash, hash( 'sha256', $profile_json ) ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $provider_output_digest ) ) {
			throw new InvalidArgumentException( 'Student note normalized result is invalid.' );
		}
	}
	/**
	 * Profile.
	 *
	 * @return array<string,mixed>
	 */
	public function profile(): array {
		return $this->profile;
	}
	/**
	 * Profile json.
	 *
	 * @return string
	 */
	public function profile_json(): string {
		return $this->profile_json;
	}
	/**
	 * Profile hash.
	 *
	 * @return string
	 */
	public function profile_hash(): string {
		return $this->profile_hash;
	}
	/**
	 * Provider output digest.
	 *
	 * @return string
	 */
	public function provider_output_digest(): string {
		return $this->provider_output_digest;
	}
	/**
	 * Safety flagged.
	 *
	 * @return bool
	 */
	public function safety_flagged(): bool {
		return $this->safety_flagged;
	}
}

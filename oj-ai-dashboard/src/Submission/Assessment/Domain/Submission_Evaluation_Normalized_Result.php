<?php
/**
 * Submission evaluation normalized result.
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
 * Submission evaluation normalized result.
 */
final class Submission_Evaluation_Normalized_Result {
	/**
	 * Initialize submission evaluation normalized result dependencies and state.
	 *
	 * @param string|null                    $candidate_score Candidate score.
	 * @param string                         $maximum_score Maximum score.
	 * @param string                         $overall_confidence Overall confidence.
	 * @param bool                           $abstained Abstained.
	 * @param bool                           $safety_flagged Safety flagged.
	 * @param string                         $summary Summary.
	 * @param string                         $normalized_json Normalized json.
	 * @param string                         $normalized_hash Normalized hash.
	 * @param string                         $provider_output_digest Provider output digest.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private ?string $candidate_score,
		private string $maximum_score,
		private string $overall_confidence,
		private bool $abstained,
		private bool $safety_flagged,
		private string $summary,
		private string $normalized_json,
		private string $normalized_hash,
		private string $provider_output_digest,
		private array $items
	) {
		$decimal = '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D';
		if (
			( null !== $candidate_score && 1 !== preg_match( $decimal, $candidate_score ) )
			|| 1 !== preg_match( $decimal, $maximum_score )
			|| 1 !== preg_match( '/^(?:0\.[0-9]{4}|1\.0000)$/D', $overall_confidence )
			|| '' === $summary
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $normalized_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $provider_output_digest )
			|| ! hash_equals( $normalized_hash, hash( 'sha256', $normalized_json ) )
			|| ! array_is_list( $items )
		) {
			throw new InvalidArgumentException( 'Submission evaluation normalized result is invalid.' );
		}
	}

	/**
	 * Candidate score.
	 *
	 * @return string|null
	 */
	public function candidate_score(): ?string {
		return $this->candidate_score;
	}
	/**
	 * Maximum score.
	 *
	 * @return string
	 */
	public function maximum_score(): string {
		return $this->maximum_score;
	}
	/**
	 * Overall confidence.
	 *
	 * @return string
	 */
	public function overall_confidence(): string {
		return $this->overall_confidence;
	}
	/**
	 * Abstained.
	 *
	 * @return bool
	 */
	public function abstained(): bool {
		return $this->abstained;
	}
	/**
	 * Safety flagged.
	 *
	 * @return bool
	 */
	public function safety_flagged(): bool {
		return $this->safety_flagged;
	}
	/**
	 * Summary.
	 *
	 * @return string
	 */
	public function summary(): string {
		return $this->summary;
	}
	/**
	 * Normalized json.
	 *
	 * @return string
	 */
	public function normalized_json(): string {
		return $this->normalized_json;
	}
	/**
	 * Normalized hash.
	 *
	 * @return string
	 */
	public function normalized_hash(): string {
		return $this->normalized_hash;
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
	 * Items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items(): array {
		return $this->items;
	}
}

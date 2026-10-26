<?php
/**
 * Worksheet evaluation normalized result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation normalized result.
 */
final class Worksheet_Evaluation_Normalized_Result {
	/**
	 * Initialize worksheet evaluation normalized result dependencies and state.
	 *
	 * @param string                         $overall_status Overall status.
	 * @param string                         $overall_confidence Overall confidence.
	 * @param string                         $summary Summary.
	 * @param string                         $normalized_json Normalized json.
	 * @param string                         $normalized_hash Normalized hash.
	 * @param string                         $provider_output_digest Provider output digest.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $overall_status,
		private string $overall_confidence,
		private string $summary,
		private string $normalized_json,
		private string $normalized_hash,
		private string $provider_output_digest,
		private array $items
	) {
		if (
			! in_array( $overall_status, array( 'ready', 'needs_attention', 'action_required', 'safety_review', 'not_assessable' ), true )
			|| 1 !== preg_match( '/^(?:0(?:\.[0-9]{1,4})?|1(?:\.0{1,4})?)$/D', $overall_confidence )
			|| '' === $summary
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $normalized_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $provider_output_digest )
			|| ! hash_equals( $normalized_hash, hash( 'sha256', $normalized_json ) )
			|| ! array_is_list( $items )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation normalized result is invalid.' );
		}
	}

	/**
	 * Overall status.
	 *
	 * @return string
	 */
	public function overall_status(): string {
		return $this->overall_status;
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

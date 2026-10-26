<?php
/**
 * Canonical feedback-draft gateway input.
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
 * Canonical feedback-draft gateway input.
 */
final class Submission_Feedback_Input {
	/**
	 * Initialize submission feedback input dependencies and state.
	 *
	 * @param array<array-key,mixed> $gateway_input Gateway input.
	 * @param string                 $gateway_input_json Gateway input json.
	 * @param string                 $gateway_input_hash Gateway input hash.
	 * @param string                 $official_review_id Official review id.
	 * @param string                 $official_review_hash Official review hash.
	 * @param string                 $result_id Result id.
	 * @param string                 $submission_revision_hash Submission revision hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $gateway_input,
		private string $gateway_input_json,
		private string $gateway_input_hash,
		private string $official_review_id,
		private string $official_review_hash,
		private string $result_id,
		private string $submission_revision_hash
	) {
		if (
			array_is_list( $gateway_input )
			|| '' === $gateway_input_json
			|| ! hash_equals( $gateway_input_hash, hash( 'sha256', $gateway_input_json ) )
			|| 1 !== preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/D', $official_review_id )
			|| 1 !== preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/D', $result_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_review_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $submission_revision_hash )
		) {
			throw new InvalidArgumentException( 'Submission feedback input is invalid.' );
		}
	}

	/**
	 * Gateway input.
	 *
	 * @return array<string,mixed>
	 */
	public function gateway_input(): array {
		return $this->gateway_input;
	}
	/**
	 * Gateway input json.
	 *
	 * @return string
	 */
	public function gateway_input_json(): string {
		return $this->gateway_input_json;
	}
	/**
	 * Gateway input hash.
	 *
	 * @return string
	 */
	public function gateway_input_hash(): string {
		return $this->gateway_input_hash;
	}
	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return strlen( $this->gateway_input_json );
	}
	/**
	 * Official review id.
	 *
	 * @return string
	 */
	public function official_review_id(): string {
		return $this->official_review_id;
	}
	/**
	 * Official review hash.
	 *
	 * @return string
	 */
	public function official_review_hash(): string {
		return $this->official_review_hash;
	}
	/**
	 * Result id.
	 *
	 * @return string
	 */
	public function result_id(): string {
		return $this->result_id;
	}
	/**
	 * Submission revision hash.
	 *
	 * @return string
	 */
	public function submission_revision_hash(): string {
		return $this->submission_revision_hash;
	}
}

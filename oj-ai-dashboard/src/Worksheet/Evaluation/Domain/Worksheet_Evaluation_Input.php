<?php
/**
 * Immutable worksheet evaluation input receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds canonical P04 and outer request bytes before any queue or Gateway call.
 */
final class Worksheet_Evaluation_Input {
	/**
	 * Initialize worksheet evaluation input dependencies and state.
	 *
	 * @param array<string,mixed>             $input Input.
	 * @param string                          $input_json Input json.
	 * @param string                          $input_hash Input hash.
	 * @param array<string,mixed>             $worksheet_projection Worksheet projection.
	 * @param string                          $worksheet_projection_hash Worksheet projection hash.
	 * @param string                          $configuration_hash Configuration hash.
	 * @param array<string,array<int,string>> $anchor_manifest Anchor manifest.
	 */
	public function __construct(
		private array $input,
		private string $input_json,
		private string $input_hash,
		private array $worksheet_projection,
		private string $worksheet_projection_hash,
		private string $configuration_hash,
		private array $anchor_manifest
	) {
	}

	/**
	 * Input.
	 *
	 * @return array<string,mixed>
	 */
	public function input(): array {
		return $this->input;
	}

	/**
	 * Input json.
	 *
	 * @return string
	 */
	public function input_json(): string {
		return $this->input_json;
	}

	/**
	 * Input hash.
	 *
	 * @return string
	 */
	public function input_hash(): string {
		return $this->input_hash;
	}

	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return strlen( $this->input_json );
	}

	/**
	 * Worksheet projection.
	 *
	 * @return array<string,mixed>
	 */
	public function worksheet_projection(): array {
		return $this->worksheet_projection;
	}

	/**
	 * Worksheet projection hash.
	 *
	 * @return string
	 */
	public function worksheet_projection_hash(): string {
		return $this->worksheet_projection_hash;
	}

	/**
	 * Configuration hash.
	 *
	 * @return string
	 */
	public function configuration_hash(): string {
		return $this->configuration_hash;
	}

	/**
	 * Anchor manifest.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function anchor_manifest(): array {
		return $this->anchor_manifest;
	}
}

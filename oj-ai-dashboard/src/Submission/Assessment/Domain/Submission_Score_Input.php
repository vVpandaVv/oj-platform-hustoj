<?php
/**
 * Immutable student submission score input receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds canonical Gateway input and private provenance before enqueue.
 */
final class Submission_Score_Input {
	/**
	 * Initialize submission score input dependencies and state.
	 *
	 * @param array<string,mixed>            $gateway_input Gateway input.
	 * @param string                         $gateway_input_json Gateway input json.
	 * @param string                         $gateway_input_hash Gateway input hash.
	 * @param array<string,mixed>            $operation_payload Operation payload.
	 * @param string                         $operation_payload_hash Operation payload hash.
	 * @param array<string,mixed>            $source_snapshot Source snapshot.
	 * @param string                         $source_snapshot_hash Source snapshot hash.
	 * @param array<string,mixed>            $configuration_snapshot Configuration snapshot.
	 * @param string                         $configuration_snapshot_hash Configuration snapshot hash.
	 * @param array<int,array<string,mixed>> $artifact_references Artifact references.
	 * @param string                         $artifact_references_json Artifact references json.
	 * @param string                         $artifact_references_hash Artifact references hash.
	 * @param string                         $representation_set_hash Representation set hash.
	 * @param array<int,array<string,mixed>> $binding_manifest Binding manifest.
	 * @param string                         $redaction_version Redaction version.
	 */
	public function __construct(
		private array $gateway_input,
		private string $gateway_input_json,
		private string $gateway_input_hash,
		private array $operation_payload,
		private string $operation_payload_hash,
		private array $source_snapshot,
		private string $source_snapshot_hash,
		private array $configuration_snapshot,
		private string $configuration_snapshot_hash,
		private array $artifact_references,
		private string $artifact_references_json,
		private string $artifact_references_hash,
		private string $representation_set_hash,
		private array $binding_manifest,
		private string $redaction_version
	) {
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
	 * Operation payload.
	 *
	 * @return array<string,mixed>
	 */
	public function operation_payload(): array {
		return $this->operation_payload;
	}
	/**
	 * Operation payload hash.
	 *
	 * @return string
	 */
	public function operation_payload_hash(): string {
		return $this->operation_payload_hash;
	}
	/**
	 * Source snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function source_snapshot(): array {
		return $this->source_snapshot;
	}
	/**
	 * Source snapshot hash.
	 *
	 * @return string
	 */
	public function source_snapshot_hash(): string {
		return $this->source_snapshot_hash;
	}
	/**
	 * Configuration snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function configuration_snapshot(): array {
		return $this->configuration_snapshot;
	}
	/**
	 * Configuration snapshot hash.
	 *
	 * @return string
	 */
	public function configuration_snapshot_hash(): string {
		return $this->configuration_snapshot_hash;
	}
	/**
	 * Artifact references.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function artifact_references(): array {
		return $this->artifact_references;
	}
	/**
	 * Artifact references json.
	 *
	 * @return string
	 */
	public function artifact_references_json(): string {
		return $this->artifact_references_json;
	}
	/**
	 * Artifact references hash.
	 *
	 * @return string
	 */
	public function artifact_references_hash(): string {
		return $this->artifact_references_hash;
	}
	/**
	 * Representation set hash.
	 *
	 * @return string
	 */
	public function representation_set_hash(): string {
		return $this->representation_set_hash;
	}
	/**
	 * Binding manifest.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function binding_manifest(): array {
		return $this->binding_manifest;
	}
	/**
	 * Redaction version.
	 *
	 * @return string
	 */
	public function redaction_version(): string {
		return $this->redaction_version;
	}
}

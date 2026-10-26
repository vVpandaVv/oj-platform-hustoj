<?php
/**
 * Canonical worksheet document receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable result returned after worksheet_plate@1 validation.
 */
final class Canonical_Worksheet_Document {
	/**
	 * Document.
	 *
	 * @var array<string,mixed>
	 */
	private array $document;
	/**
	 * Document json.
	 *
	 * @var string
	 */
	private string $document_json;
	/**
	 * Document hash.
	 *
	 * @var string
	 */
	private string $document_hash;
	/**
	 * Validation json.
	 *
	 * @var string
	 */
	private string $validation_json;
	/**
	 * Validation hash.
	 *
	 * @var string
	 */
	private string $validation_hash;

	/**
	 * Temporary id map.
	 *
	 * @var array<string,string>
	 */
	private array $temporary_id_map;

	/**
	 * Warnings.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $warnings;

	/**
	 * Statistics.
	 *
	 * @var array<string,int>
	 */
	private array $statistics;

	/**
	 * Initialize canonical worksheet document dependencies and state.
	 *
	 * @param array<string,mixed>            $document Document.
	 * @param string                         $document_json Document json.
	 * @param string                         $document_hash Document hash.
	 * @param string                         $validation_json Validation json.
	 * @param string                         $validation_hash Validation hash.
	 * @param array<string,string>           $temporary_id_map Temporary id map.
	 * @param array<int,array<string,mixed>> $warnings Warnings.
	 * @param array<string,int>              $statistics Statistics.
	 */
	public function __construct(
		array $document,
		string $document_json,
		string $document_hash,
		string $validation_json,
		string $validation_hash,
		array $temporary_id_map,
		array $warnings,
		array $statistics
	) {
		$this->document         = $document;
		$this->document_json    = $document_json;
		$this->document_hash    = $document_hash;
		$this->validation_json  = $validation_json;
		$this->validation_hash  = $validation_hash;
		$this->temporary_id_map = $temporary_id_map;
		$this->warnings         = $warnings;
		$this->statistics       = $statistics;
	}

	/**
	 * Document.
	 *
	 * @return array<string,mixed>
	 */
	public function document(): array {
		return $this->document;
	}

	/**
	 * Document json.
	 *
	 * @return string
	 */
	public function document_json(): string {
		return $this->document_json;
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
	 * Validation json.
	 *
	 * @return string
	 */
	public function validation_json(): string {
		return $this->validation_json;
	}

	/**
	 * Validation hash.
	 *
	 * @return string
	 */
	public function validation_hash(): string {
		return $this->validation_hash;
	}

	/**
	 * Temporary id map.
	 *
	 * @return array<string,string>
	 */
	public function temporary_id_map(): array {
		return $this->temporary_id_map;
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
}

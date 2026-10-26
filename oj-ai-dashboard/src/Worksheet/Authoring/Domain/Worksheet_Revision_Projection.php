<?php
/**
 * Immutable worksheet revision projection receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries records that the save transaction must persist or verify together.
 */
final class Worksheet_Revision_Projection {
	/**
	 * Questions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $questions;

	/**
	 * Worksheet achievement links.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $worksheet_achievement_links;

	/**
	 * Question achievement links.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $question_achievement_links;

	/**
	 * Asset bindings.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $asset_bindings;

	/**
	 * Projection json.
	 *
	 * @var string
	 */
	private string $projection_json;
	/**
	 * Projection hash.
	 *
	 * @var string
	 */
	private string $projection_hash;

	/**
	 * Statistics.
	 *
	 * @var array<string,int>
	 */
	private array $statistics;

	/**
	 * Initialize worksheet revision projection dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $questions Questions.
	 * @param array<int,array<string,mixed>> $worksheet_achievement_links Worksheet achievement links.
	 * @param array<int,array<string,mixed>> $question_achievement_links Question achievement links.
	 * @param array<int,array<string,mixed>> $asset_bindings Asset bindings.
	 * @param string                         $projection_json Projection json.
	 * @param string                         $projection_hash Projection hash.
	 * @param array<string,int>              $statistics Statistics.
	 */
	public function __construct(
		array $questions,
		array $worksheet_achievement_links,
		array $question_achievement_links,
		array $asset_bindings,
		string $projection_json,
		string $projection_hash,
		array $statistics
	) {
		$this->questions                   = $questions;
		$this->worksheet_achievement_links = $worksheet_achievement_links;
		$this->question_achievement_links  = $question_achievement_links;
		$this->asset_bindings              = $asset_bindings;
		$this->projection_json             = $projection_json;
		$this->projection_hash             = $projection_hash;
		$this->statistics                  = $statistics;
	}

	/**
	 * Questions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function questions(): array {
		return $this->questions;
	}

	/**
	 * Worksheet achievement links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function worksheet_achievement_links(): array {
		return $this->worksheet_achievement_links;
	}

	/**
	 * Question achievement links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function question_achievement_links(): array {
		return $this->question_achievement_links;
	}

	/**
	 * Asset bindings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function asset_bindings(): array {
		return $this->asset_bindings;
	}

	/**
	 * Projection json.
	 *
	 * @return string
	 */
	public function projection_json(): string {
		return $this->projection_json;
	}

	/**
	 * Projection hash.
	 *
	 * @return string
	 */
	public function projection_hash(): string {
		return $this->projection_hash;
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

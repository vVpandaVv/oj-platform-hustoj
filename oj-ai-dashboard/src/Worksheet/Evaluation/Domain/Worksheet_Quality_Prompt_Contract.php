<?php
/**
 * Worksheet quality prompt contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable descriptor for the package-owned worksheet quality prompt.
 */
final class Worksheet_Quality_Prompt_Contract {
	public const VERSION      = 'worksheet_quality_prompt.v6';
	public const CONTENT_HASH = '8b450eeed05a2d5200ce21414b197186b9754f037a93a1c4f28a097efde11cd0';
	public const FILENAME     = 'worksheet-quality-prompt-v6.txt';
	/**
	 * Initialize worksheet quality prompt contract dependencies and state.
	 */
	private function __construct() {
	}
}

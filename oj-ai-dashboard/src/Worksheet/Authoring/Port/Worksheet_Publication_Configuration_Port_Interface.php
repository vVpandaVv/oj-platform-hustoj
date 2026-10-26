<?php
/**
 * Active student-submission publication configuration port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active student-submission publication configuration port.
 */
interface Worksheet_Publication_Configuration_Port_Interface {
	/**
	 * Active.
	 *
	 * @return array<string,mixed>
	 */
	public function active(): array;
}

<?php
/**
 * Scoped publication configuration port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Scoped publication configuration port interface.
 */
interface Scoped_Publication_Configuration_Port_Interface extends Worksheet_Publication_Configuration_Port_Interface {
	/**
	 * For worksheet.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param array  $document Document.
	 * @return array
	 */
	public function for_worksheet( string $worksheet_id, array $document ): array;
}

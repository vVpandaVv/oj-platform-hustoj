<?php
/**
 * Export control queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Export control queue wakeup port interface.
 */
interface Export_Control_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @param string $artifact_kind Artifact kind.
	 * @return void
	 */
	public function wake( string $artifact_kind ): void;
}

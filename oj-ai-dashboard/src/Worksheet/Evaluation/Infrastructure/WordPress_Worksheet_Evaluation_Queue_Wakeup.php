<?php
/**
 * Best-effort post-commit queue wake-up.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best-effort post-commit queue wake-up.
 */
final class WordPress_Worksheet_Evaluation_Queue_Wakeup implements Worksheet_Evaluation_Queue_Wakeup_Port_Interface {
	public const HOOK = 'oj_ai_dashboard_evaluation_worker';

	/**
	 * Share the evaluation dispatch transport.
	 *
	 * @param WordPress_Evaluation_Queue_Dispatcher|null $dispatcher Server-side dispatcher.
	 */
	public function __construct( private ?WordPress_Evaluation_Queue_Dispatcher $dispatcher = null ) {
		$this->dispatcher ??= WordPress_Evaluation_Queue_Dispatcher::from_globals();
	}

	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		$this->dispatcher->wake();
	}
}

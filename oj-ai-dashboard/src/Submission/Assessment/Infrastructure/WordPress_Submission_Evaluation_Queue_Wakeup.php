<?php
/**
 * WordPress submission evaluation queue wakeup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Evaluation_Queue_Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best-effort post-commit wake-up for the shared evaluation run queue.
 */
final class WordPress_Submission_Evaluation_Queue_Wakeup implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
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

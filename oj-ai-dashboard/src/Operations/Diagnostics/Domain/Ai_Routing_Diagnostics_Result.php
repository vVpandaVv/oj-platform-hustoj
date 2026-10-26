<?php
/**
 * Safe aggregate diagnostic result for add-on AI task routing.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe aggregate diagnostic result for add-on AI task routing.
 */
final class Ai_Routing_Diagnostics_Result {
	public const READY       = 'ready';
	public const ATTENTION   = 'attention';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Tasks.
	 *
	 * @var array<string,Ai_Routing_Task_Diagnostics>
	 */
	private array $tasks;

	/**
	 * Initialize ai routing diagnostics result dependencies and state.
	 *
	 * @param Ai_Routing_Task_Diagnostics $worksheet_quality Worksheet quality.
	 * @param Ai_Routing_Task_Diagnostics $student_submission Student submission.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		Ai_Routing_Task_Diagnostics $worksheet_quality,
		Ai_Routing_Task_Diagnostics $student_submission
	) {
		if ( Ai_Routing_Task_Diagnostics::SUBJECT_WORKSHEET_QUALITY !== $worksheet_quality->subject() ) {
			throw new InvalidArgumentException( 'Worksheet routing diagnostics are out of position.' );
		}
		if ( Ai_Routing_Task_Diagnostics::SUBJECT_STUDENT_SUBMISSION !== $student_submission->subject() ) {
			throw new InvalidArgumentException( 'Submission routing diagnostics are out of position.' );
		}

		$this->tasks = array(
			Ai_Routing_Task_Diagnostics::SUBJECT_WORKSHEET_QUALITY => $worksheet_quality,
			Ai_Routing_Task_Diagnostics::SUBJECT_STUDENT_SUBMISSION => $student_submission,
		);
	}

	/**
	 * Unavailable.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function unavailable( string $code = 'ai_task_routing_unavailable' ): self {
		return new self(
			new Ai_Routing_Task_Diagnostics(
				Ai_Routing_Task_Diagnostics::SUBJECT_WORKSHEET_QUALITY,
				Ai_Routing_Task_Diagnostics::RUNTIME_UNAVAILABLE,
				$code
			),
			new Ai_Routing_Task_Diagnostics(
				Ai_Routing_Task_Diagnostics::SUBJECT_STUDENT_SUBMISSION,
				Ai_Routing_Task_Diagnostics::RUNTIME_UNAVAILABLE,
				$code
			)
		);
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		$statuses = array_map(
			static fn ( Ai_Routing_Task_Diagnostics $task ): string => $task->status(),
			$this->tasks
		);

		if (
			in_array( Ai_Routing_Task_Diagnostics::RUNTIME_UNAVAILABLE, $statuses, true )
			|| in_array( Ai_Routing_Task_Diagnostics::UNROUTABLE_TRANSIENT, $statuses, true )
		) {
			return self::UNAVAILABLE;
		}
		if (
			in_array( Ai_Routing_Task_Diagnostics::ROUTING_STALE, $statuses, true )
			|| in_array( Ai_Routing_Task_Diagnostics::UNROUTABLE, $statuses, true )
		) {
			return self::ATTENTION;
		}
		return self::READY;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		$statuses = array_map(
			static fn ( Ai_Routing_Task_Diagnostics $task ): string => $task->status(),
			$this->tasks
		);

		if ( in_array( Ai_Routing_Task_Diagnostics::RUNTIME_UNAVAILABLE, $statuses, true ) ) {
			return 'ai_routing_observation_failed';
		}
		if ( in_array( Ai_Routing_Task_Diagnostics::UNROUTABLE_TRANSIENT, $statuses, true ) ) {
			return 'ai_routing_temporarily_unavailable';
		}
		if ( in_array( Ai_Routing_Task_Diagnostics::ROUTING_STALE, $statuses, true ) ) {
			return 'ai_routing_refresh_required';
		}
		if ( in_array( Ai_Routing_Task_Diagnostics::UNROUTABLE, $statuses, true ) ) {
			return 'ai_routing_configuration_required';
		}
		return 'ai_routing_ready';
	}

	/**
	 * Tasks.
	 *
	 * @return array<string,array{status:string,label:string,code:string,details:array<string,string>}>
	 */
	public function tasks(): array {
		$projection = array();
		foreach ( $this->tasks as $subject => $task ) {
			$projection[ $subject ] = $task->projection();
		}
		return $projection;
	}
}

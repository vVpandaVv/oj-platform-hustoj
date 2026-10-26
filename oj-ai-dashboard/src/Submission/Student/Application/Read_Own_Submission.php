<?php
/**
 * Student submission self query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements Q32 while deriving the learner exclusively from the actor.
 */
final class Read_Own_Submission {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize own submission dependencies and state.
	 *
	 * @param Student_Assignment_Repository_Port_Interface $submissions Submissions.
	 * @param Learning_Context_Query_Port_Interface        $contexts Contexts.
	 * @param callable|null                                $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Repository_Port_Interface $submissions,
		private Learning_Context_Query_Port_Interface $contexts,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Read own submission.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $submission_id Submission public identifier.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $actor_user_id, string $submission_id ): array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student submission actor is invalid.' );
		}
		new Public_Id( $submission_id );
		$snapshot = $this->submissions->submission_authorization_snapshot( $submission_id, $actor_user_id );
		if (
			null === $snapshot
			|| 'active' !== $snapshot['target_state']
			|| 'withdrawn' === $snapshot['assignment_state']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		$contexts = $this->contexts->for_actor( $actor_user_id );
		$key      = $snapshot['context_type'] . ':' . $snapshot['context_id'];
		$context  = $contexts[ $key ] ?? null;
		if ( ! is_array( $context ) || 'active' !== ( $context['lifecycle_state'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		$result = $this->submissions->find_submission_for_actor( $submission_id, $actor_user_id, $context, $this->now() );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$now = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now ) ) {
			throw new InvalidArgumentException( 'Student submission clock is invalid.' );
		}

		return $now;
	}
}

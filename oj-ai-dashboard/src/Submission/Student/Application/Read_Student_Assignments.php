<?php
/**
 * Student assignment self queries.
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
 * Implements Q30 and Q31 without accepting a learner ID from input.
 */
final class Read_Student_Assignments {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 512;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize student assignments dependencies and state.
	 *
	 * @param Student_Assignment_Repository_Port_Interface $assignments Assignments.
	 * @param Learning_Context_Query_Port_Interface        $contexts Contexts.
	 * @param callable|null                                $clock Clock.
	 */
	public function __construct(
		private Student_Assignment_Repository_Port_Interface $assignments,
		private Learning_Context_Query_Port_Interface $contexts,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * List student assignments.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list( int $actor_user_id, string $cursor, int $limit ): array {
		$this->actor( $actor_user_id );
		$this->page( $cursor, $limit );
		$contexts = $this->contexts->for_actor( $actor_user_id );

		return $this->assignments->list_for_actor( $actor_user_id, $contexts, $cursor, $limit, $this->now() );
	}

	/**
	 * Detail.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $assignment_id Distribution public identifier.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail( int $actor_user_id, string $assignment_id ): array {
		$this->actor( $actor_user_id );
		new Public_Id( $assignment_id );
		$snapshot = $this->assignments->authorization_snapshot( $assignment_id, $actor_user_id );
		if ( null === $snapshot || 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['state'] ) {
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
		$result = $this->assignments->find_for_actor( $assignment_id, $actor_user_id, $context, $this->now() );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function actor( int $actor_user_id ): void {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student assignment actor is invalid.' );
		}
	}

	/**
	 * Page.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function page( string $cursor, int $limit ): void {
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Student assignment page is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Student assignment clock is invalid.' );
		}

		return $now;
	}
}

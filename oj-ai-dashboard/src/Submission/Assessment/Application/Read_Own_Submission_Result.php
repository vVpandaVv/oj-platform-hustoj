<?php
/**
 * Student result self query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements Q33 without accepting a learner identity from input.
 */
final class Read_Own_Submission_Result {
	/**
	 * Initialize own submission result dependencies and state.
	 *
	 * @param Submission_Result_Query_Port_Interface $results Results.
	 * @param Learning_Context_Query_Port_Interface  $contexts Contexts.
	 */
	public function __construct(
		private Submission_Result_Query_Port_Interface $results,
		private Learning_Context_Query_Port_Interface $contexts
	) {
	}

	/**
	 * Read own submission result.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $actor_user_id, string $release_id ): array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student result actor is invalid.' );
		}
		new Public_Id( $release_id );

		$snapshot = $this->results->authorization_snapshot( $release_id, $actor_user_id );
		if (
			null === $snapshot
			|| 'active' !== $snapshot['target_state']
			|| 'withdrawn' === $snapshot['assignment_state']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}

		$contexts = $this->contexts->for_actor( $actor_user_id );
		$key      = $snapshot['context_type'] . ':' . $snapshot['context_id'];
		$context  = $contexts[ $key ] ?? null;
		if (
			! is_array( $context )
			|| 'active' !== ( $context['lifecycle_state'] )
			|| $snapshot['context_type'] !== ( $context['type'] )
			|| $snapshot['context_id'] !== ( $context['id'] )
			|| $snapshot['context_revision'] !== ( $context['revision'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}

		$current = $this->results->find_current_for_actor( $release_id, $actor_user_id, $context );
		if ( null === $current ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}

		$state   = $current['state'];
		$visible = in_array( $state, array( 'released', 'corrected' ), true );
		$notice  = match ( $state ) {
			'withdrawn' => array(
				'code'    => 'result_withdrawn',
				'message' => '공개된 결과가 회수되었습니다.',
			),
			'stale' => array(
				'code'    => 'result_updating',
				'message' => '결과를 수정하고 있습니다.',
			),
			default => null,
		};

		return array(
			'notice'     => $notice,
			'projection' => $visible ? $current['projection'] : null,
			'release'    => array(
				'hash'            => $current['hash'],
				'id'              => $current['id'],
				'released_at_utc' => $current['released_at_utc'],
				'state'           => $state,
			),
		);
	}
}

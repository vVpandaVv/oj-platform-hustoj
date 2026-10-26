<?php
/**
 * Complete assignment submission status for the current teaching roster.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use RuntimeException;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only roster projection; never includes response bodies or teacher notes. */
final class Read_Submission_Evaluation_Roster {
	private const MAXIMUM_PAGES = 50;

	/**
	 * Initialize authorized read sources.
	 *
	 * @param Read_Teacher_Submissions                $submissions Submission status reader.
	 * @param Assignment_Roster_Port_Interface        $roster Authorized platform roster.
	 * @param Learner_Display_Snapshot_Port_Interface $displays Learner display names.
	 */
	public function __construct(
		private Read_Teacher_Submissions $submissions,
		private Assignment_Roster_Port_Interface $roster,
		private Learner_Display_Snapshot_Port_Interface $displays
	) {
	}

	/**
	 * Read every target and current context learner without partial results.
	 *
	 * @param int    $actor_user_id Authenticated teacher.
	 * @param string $assignment_id Authorized assignment.
	 * @param string $context_type Context type.
	 * @param string $context_id Context identifier.
	 * @param string $context_revision Authorized revision.
	 * @return array{items:array<int,array<string,mixed>>}
	 * @throws RuntimeException Rejects incomplete pages rather than guessing missing submissions.
	 */
	public function read( int $actor_user_id, string $assignment_id, string $context_type, string $context_id, string $context_revision ): array {
		$rows   = array();
		$cursor = '';
		$seen   = array( '' => true );
		for ( $page_no = 0; $page_no < self::MAXIMUM_PAGES; ++$page_no ) {
			$page = $this->submissions->list_assignment( $assignment_id, $context_type, $context_id, $context_revision, $cursor, Read_Teacher_Submissions::MAXIMUM_LIMIT );
			foreach ( $page['items'] as $item ) {
				$user_id = $item['target']['learner_user_id'];
				if ( isset( $rows[ $user_id ] ) ) {
					throw new RuntimeException( 'Duplicate assignment learner.' );
				}
				$submission       = $item['submission'];
				$rows[ $user_id ] = array(
					'id'             => $item['target']['id'],
					'learner'        => array(
						'user_id'      => $user_id,
						'display_name' => $item['target']['display_name'],
					),
					'assigned'       => 'active' === $item['target']['state'],
					'target_state'   => $item['target']['state'],
					'current_member' => false,
					'status'         => $item['status'],
					'submission'     => null === $submission ? null : array(
						'id'                   => $submission['id'],
						'official_revision_id' => $submission['official_revision_id'],
						'submitted_at_utc'     => $submission['submitted_at_utc'],
					),
				);
			}
			$cursor = $page['next_cursor'];
			if ( '' === $cursor ) {
				break;
			}
			if ( isset( $seen[ $cursor ] ) ) {
				throw new RuntimeException( 'Repeated assignment roster cursor.' );
			}
			$seen[ $cursor ] = true;
		}
		if ( '' !== $cursor ) {
			throw new RuntimeException( 'Assignment roster page limit exceeded.' );
		}
		$current = $this->roster->collect( $actor_user_id, $context_type, $context_id, $context_revision );
		foreach ( $current->learners() as $learner ) {
			$user_id = $learner['learner_user_id'];
			if ( isset( $rows[ $user_id ] ) ) {
				$rows[ $user_id ]['current_member'] = true;
				continue;
			}
			$display          = $this->displays->snapshot( $user_id );
			$rows[ $user_id ] = array(
				'id'             => 'learner:' . $user_id,
				'learner'        => array(
					'user_id'      => $user_id,
					'display_name' => $display['display_name'],
				),
				'assigned'       => false,
				'current_member' => true,
				'status'         => 'not_assigned',
				'submission'     => null,
			);
		}
		return array( 'items' => array_values( $rows ) );
	}
}

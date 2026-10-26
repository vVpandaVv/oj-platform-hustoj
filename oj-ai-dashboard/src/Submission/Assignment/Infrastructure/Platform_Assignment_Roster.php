<?php
/**
 * OJ Platform exact assignment roster adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects one stable roster and rejects every partial or racing response.
 */
final class Platform_Assignment_Roster implements Assignment_Roster_Port_Interface {
	private const PAGE_LIMIT       = 100;
	private const MAXIMUM_PAGES    = 50;
	private const MAXIMUM_LEARNERS = 5000;

	/**
	 * Initialize platform assignment roster dependencies and state.
	 *
	 * @param Platform_Context_Runtime_Port_Interface $runtime Runtime.
	 */
	public function __construct( private Platform_Context_Runtime_Port_Interface $runtime ) {
	}

	/**
	 * Collect.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $expected_context_revision Expected context revision.
	 * @return Assignment_Roster_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function collect(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $expected_context_revision
	): Assignment_Roster_Snapshot {
		if ( ! $this->runtime->is_ready() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
		}
		$gateway = $this->runtime->contexts();
		if ( ! $gateway instanceof AddonAssignmentRosterGatewayV1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
		}

		$cursor          = '';
		$roster_revision = null;
		$learners        = array();
		$seen_learners   = array();
		$seen_cursors    = array( '' => true );

		for ( $page_no = 1; $page_no <= self::MAXIMUM_PAGES; ++$page_no ) {
			try {
				$page = $gateway->listAssignmentLearners(
					new AddonAssignmentRosterQueryV1(
						$actor_user_id,
						$context_type,
						$context_id,
						$expected_context_revision,
						$roster_revision,
						$cursor,
						self::PAGE_LIMIT
					)
				);
			} catch ( Throwable ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_UNAVAILABLE );
			}

			if ( ! $page->isSuccessful() ) {
				$problem = $page->problem();
				$reason  = $problem instanceof AddonApiProblemV1 && 'addon_context_denied' === $problem->code()
					? Assignment_Failure::ROSTER_DENIED
					: Assignment_Failure::ROSTER_UNAVAILABLE;
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( $reason );
			}

			$current_revision = $page->rosterRevision();
			if ( ! is_string( $current_revision ) || '' === $current_revision ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
			}
			if ( null === $roster_revision ) {
				$roster_revision = $current_revision;
			} elseif ( ! hash_equals( $roster_revision, $current_revision ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
			}

			foreach ( $page->items() as $item ) {
				$user_id = $item->learnerUserId();
				if ( isset( $seen_learners[ $user_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
				}
				$seen_learners[ $user_id ] = true;
				$learners[]                = array(
					'learner_user_id'     => $user_id,
					'membership_revision' => $item->membershipRevision(),
				);
				if ( count( $learners ) > self::MAXIMUM_LEARNERS ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
				}
			}

			$next = $page->nextCursor();
			if ( '' === $next ) {
				try {
					return new Assignment_Roster_Snapshot(
						$context_type,
						$context_id,
						$expected_context_revision,
						(string) $roster_revision,
						$learners
					);
				} catch ( Throwable ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
				}
			}
			if ( isset( $seen_cursors[ $next ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ROSTER_INVALID );
			}
			$seen_cursors[ $next ] = true;
			$cursor                = $next;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::ROSTER_TOO_LARGE );
	}
}

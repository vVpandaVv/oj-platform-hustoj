<?php
/**
 * Worksheet self-evaluation query use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet self-evaluation query use cases.
 */
final class Read_Worksheet_Evaluations {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 191;

	public const STATES = array(
		'',
		'requested',
		'queued',
		'running',
		'review_required',
		'reviewed',
		'failed',
		'cancelled',
		'stale',
	);

	/**
	 * Initialize worksheet evaluations dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Query_Port_Interface $queries Queries.
	 */
	public function __construct( private Worksheet_Evaluation_Query_Port_Interface $queries ) {
	}

	/**
	 * List worksheet evaluations.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param bool   $latest_only Latest only.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list(
		int $actor_user_id,
		string $state,
		string $worksheet_id,
		string $revision_id,
		string $cursor,
		int $limit,
		bool $latest_only = false
	): array {
		$this->assert_actor( $actor_user_id );
		$this->assert_page( $cursor, $limit );
		if ( ! in_array( $state, self::STATES, true ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation state filter is invalid.' );
		}
		$this->optional_public_id( $worksheet_id );
		$this->optional_public_id( $revision_id );

		return $this->queries->list_owned(
			$actor_user_id,
			$state,
			$worksheet_id,
			$revision_id,
			$cursor,
			$limit,
			$latest_only
		);
	}

	/**
	 * Detail.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail( int $actor_user_id, string $evaluation_id ): array {
		$this->assert_actor( $actor_user_id );
		new Public_Id( $evaluation_id );
		$result = $this->queries->find_owned( $actor_user_id, $evaluation_id );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Reject invalid actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_actor( int $actor_user_id ): void {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet evaluation query requires an authenticated actor.' );
		}
	}

	/**
	 * Reject invalid page.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_page( string $cursor, int $limit ): void {
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR ) {
			throw new InvalidArgumentException( 'Worksheet evaluation cursor is too long.' );
		}
		if ( $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Worksheet evaluation limit is invalid.' );
		}
	}

	/**
	 * Optional public id.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function optional_public_id( string $value ): void {
		if ( '' !== $value ) {
			new Public_Id( $value );
		}
	}
}

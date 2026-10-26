<?php
/**
 * Worksheet query use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates QueryInputV1 before delegating owner-filtered reads.
 */
final class Read_Worksheets {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 191;

	/**
	 * Worksheets.
	 *
	 * @var Worksheet_Repository_Port_Interface
	 */
	private Worksheet_Repository_Port_Interface $worksheets;

	/**
	 * Initialize worksheets dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 */
	public function __construct( Worksheet_Repository_Port_Interface $worksheets ) {
		$this->worksheets = $worksheets;
	}

	/**
	 * List worksheets.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		$this->assert_actor( $actor_user_id );
		$this->assert_page( $cursor, $limit );
		if ( ! in_array( $state, array( '', 'draft', 'active', 'archived' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet state filter is invalid.' );
		}

		return $this->worksheets->list_owned( $actor_user_id, $state, $cursor, $limit );
	}

	/**
	 * Worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function worksheet( int $actor_user_id, string $worksheet_id ): array {
		$this->assert_actor( $actor_user_id );
		new Public_Id( $worksheet_id );
		$result = $this->worksheets->find_owned_worksheet( $actor_user_id, $worksheet_id );

		if ( null === $result ) {
			throw Worksheet_Failure::not_found();
		}

		return $result;
	}

	/**
	 * Revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function revisions(
		int $actor_user_id,
		string $worksheet_id,
		string $cursor,
		int $limit
	): array {
		$this->assert_actor( $actor_user_id );
		new Public_Id( $worksheet_id );
		$this->assert_page( $cursor, $limit );
		$result = $this->worksheets->list_owned_revisions( $actor_user_id, $worksheet_id, $cursor, $limit );

		if ( null === $result ) {
			throw Worksheet_Failure::not_found();
		}

		return $result;
	}

	/**
	 * Revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function revision( int $actor_user_id, string $revision_id ): array {
		$this->assert_actor( $actor_user_id );
		new Public_Id( $revision_id );
		$result = $this->worksheets->find_owned_revision( $actor_user_id, $revision_id );

		if ( null === $result ) {
			throw Worksheet_Failure::not_found();
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
			throw new InvalidArgumentException( 'Worksheet query requires an authenticated actor.' );
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
			throw new InvalidArgumentException( 'Worksheet cursor is too long.' );
		}
		if ( $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Worksheet limit is invalid.' );
		}
	}
}

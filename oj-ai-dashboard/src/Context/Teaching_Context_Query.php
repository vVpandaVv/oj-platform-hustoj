<?php
/**
 * Neutral teaching Context list input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the endpoint-specific Q01 query without exposing platform DTOs.
 */
final class Teaching_Context_Query {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_SEARCH = 200;
	public const MAXIMUM_CURSOR = 512;

	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Search.
	 *
	 * @var string
	 */
	private string $search;
	/**
	 * Continuation cursor from the previous page.
	 *
	 * @var string
	 */
	private string $cursor;
	/**
	 * Maximum number of entries requested.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Initialize teaching context query dependencies and state.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $search Search.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $search = '',
		string $cursor = '',
		int $limit = self::DEFAULT_LIMIT
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Teaching Context actor must be authenticated.' );
		}

		Context_Value::text_syntax( $search, 'search', true );
		$search = trim( $search );
		Context_Value::text( $search, 'search', self::MAXIMUM_SEARCH, true );
		Context_Value::text( $cursor, 'cursor', self::MAXIMUM_CURSOR, true );
		if ( $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Teaching Context limit is outside the frozen range.' );
		}

		$this->actor_user_id = $actor_user_id;
		$this->search        = $search;
		$this->cursor        = $cursor;
		$this->limit         = $limit;
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}

	/**
	 * Types.
	 *
	 * @return array<int,string>
	 */
	public function types(): array {
		return array( 'class', 'group' );
	}

	/**
	 * Search.
	 *
	 * @return string
	 */
	public function search(): string {
		return $this->search;
	}

	/**
	 * Cursor.
	 *
	 * @return string
	 */
	public function cursor(): string {
		return $this->cursor;
	}

	/**
	 * Limit.
	 *
	 * @return int
	 */
	public function limit(): int {
		return $this->limit;
	}
}

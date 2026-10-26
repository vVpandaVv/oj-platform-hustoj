<?php
/**
 * Read assignments.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Q11 and Q12 application query boundary.
 */
final class Read_Assignments {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 512;
	/**
	 * Initialize assignments dependencies and state.
	 *
	 * @param Assignment_Repository_Port_Interface $assignments Assignments.
	 */
	public function __construct( private Assignment_Repository_Port_Interface $assignments ) {
	}
	/**
	 * List assignments.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list( string $type, string $id, string $revision, string $cursor, int $limit ): array {
		$this->context( $type, $id, $revision );
		$this->page( $cursor, $limit );
		return $this->assignments->list_context( $type, $id, $revision, $cursor, $limit );
	}
	/**
	 * Detail.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail( string $assignment_id, string $type, string $id, string $revision, string $cursor, int $limit ): array {
		new Public_Id( $assignment_id );
		$this->context( $type, $id, $revision );
		$this->page( $cursor, $limit );
		$result = $this->assignments->find_context_assignment( $assignment_id, $type, $id, $revision, $cursor, $limit );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		return $result;
	}
	/**
	 * Context.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context( string $type, string $id, string $revision ): void {
		if ( ! in_array( $type, array( Context_Scope::TYPE_CLASS, Context_Scope::TYPE_GROUP ), true ) ) {
			throw new InvalidArgumentException( 'Assignment Context type is invalid.' );
		}
		foreach ( array( $id, $revision ) as $token ) {
			if ( '' === $token || strlen( $token ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $token ) ) {
				throw new InvalidArgumentException( 'Assignment Context token is invalid.' );
			}
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
			throw new InvalidArgumentException( 'Assignment page is invalid.' );
		}
	}
}

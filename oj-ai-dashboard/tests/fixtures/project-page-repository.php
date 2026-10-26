<?php
/**
 * Project page repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface;
use OJAI\Dashboard\Assessment\Project\Domain\Project_Failure;

/**
 * Project page test repository contract and implementation.
 */
final class Project_Page_Test_Repository implements Project_Repository_Port_Interface {
	/**
	 * Initialize page test repository dependencies and state.
	 *
	 * @param array $project Project.
	 * @param int   $owner Owner.
	 */
	public function __construct( private array $project, private int $owner = 41 ) {
	}
	/**
	 * Read page test repository.
	 *
	 * @param int         $actor Actor.
	 * @param string      $id Id.
	 * @param string|null $revision Revision.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $actor, string $id, ?string $revision = null ): array {
		if ( $actor !== $this->owner || $id !== ( $this->project['project_id'] ?? '' ) ) {
			throw new Project_Failure( 404, 'Not found' );
		}
		return $this->project;
	}
	/**
	 * Listing.
	 *
	 * @param int  $actor Actor.
	 * @param int  $after After.
	 * @param bool $archived Archived.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listing( int $actor, int $after = 0, bool $archived = false ): array {
		throw new LogicException( 'Not used' );
	}
	/**
	 * Reject mutation in this page fixture.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param int    $expected Expected version.
	 * @param bool   $archived Archived.
	 * @return array
	 * @throws LogicException This fixture is read-only.
	 */
	public function set_archived( int $actor, string $id, int $expected, bool $archived ): array {
		throw new LogicException( 'Read-only fixture' );
	}
	/**
	 * Create page test repository.
	 *
	 * @param int    $actor Actor.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( int $actor, array $payload, string $key, string $digest ): array {
		throw new LogicException( 'Read-only fixture' );
	}
	/**
	 * Save page test repository.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param string $expected Expected.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save( int $actor, string $id, string $expected, array $payload, string $key, string $digest ): array {
		throw new LogicException( 'Read-only fixture' );
	}
	/**
	 * Attach created.
	 *
	 * @param int   $worksheet_pk Worksheet pk.
	 * @param int   $actor Actor.
	 * @param array $pin Pin.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function attach_created( int $worksheet_pk, int $actor, array $pin ): void {
		throw new LogicException( 'Read-only fixture' );
	}
	/**
	 * For worksheet.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int|null   $actor Actor.
	 * @param bool       $workspace Include navigation-only migrated membership.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_worksheet( string $worksheet, ?array $pin = null, ?int $actor = null, bool $workspace = false ): ?array {
		throw new LogicException( 'Not used' );
	}
	/**
	 * For publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_publication( string $publication_id, string $revision_id ): ?array {
		throw new LogicException( 'Not used' );
	}
	/**
	 * Reject unexpected writes in the page fixture.
	 *
	 * @param int    $actor Owner.
	 * @param string $worksheet Worksheet ID.
	 * @return array
	 * @throws RuntimeException Unexpected write.
	 */
	public function adopt_worksheet( int $actor, string $worksheet ): array {
		throw new RuntimeException( 'Unexpected adoption in page fixture.' );
	}

	/**
	 * For draft save.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_draft_save( string $worksheet, ?array $pin, int $actor ): ?array {
		throw new LogicException( 'Not used' );
	}
	/**
	 * Sync draft pin.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function sync_draft_pin( string $worksheet, ?array $pin, int $actor ): void {
		throw new LogicException( 'Read-only fixture' );
	}
}

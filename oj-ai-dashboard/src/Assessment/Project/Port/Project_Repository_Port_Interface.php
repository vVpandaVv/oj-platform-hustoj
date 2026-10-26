<?php
/**
 * Project repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Assessment\Project\Port;

/**
 * Project repository port interface.
 */
interface Project_Repository_Port_Interface {
	/**
	 * Listing.
	 *
	 * @param int  $actor Actor.
	 * @param int  $after After.
	 * @param bool $archived Whether to list archived projects.
	 * @return array
	 */
	public function listing( int $actor, int $after = 0, bool $archived = false ): array;
	/**
	 * Change list membership without changing criteria or published work.
	 *
	 * @param int    $actor Owner.
	 * @param string $id Project public ID.
	 * @param int    $expected Expected root lock version.
	 * @param bool   $archived Desired archive state.
	 * @return array
	 */
	public function set_archived( int $actor, string $id, int $expected, bool $archived ): array;
	/**
	 * Read repository port interface.
	 *
	 * @param int         $actor Actor.
	 * @param string      $id Id.
	 * @param string|null $revision Revision.
	 * @return array
	 */
	public function read( int $actor, string $id, ?string $revision = null ): array;
	/**
	 * Create repository port interface.
	 *
	 * @param int    $actor Actor.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 */
	public function create( int $actor, array $payload, string $key, string $digest ): array;
	/**
	 * Save repository port interface.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param string $expected Expected.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 */
	public function save( int $actor, string $id, string $expected, array $payload, string $key, string $digest ): array;
	/**
	 * Attach created.
	 *
	 * @param int   $worksheet_pk Worksheet pk.
	 * @param int   $actor Actor.
	 * @param array $pin Pin.
	 * @return void
	 */
	public function attach_created( int $worksheet_pk, int $actor, array $pin ): void;
	/**
	 * For worksheet.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int|null   $actor Actor.
	 * @param bool       $workspace Include navigation-only migrated membership.
	 * @return array|null
	 */
	public function for_worksheet( string $worksheet, ?array $pin = null, ?int $actor = null, bool $workspace = false ): ?array;
	/**
	 * Attach an existing worksheet without rewriting its immutable records.
	 *
	 * @param int    $actor Owner.
	 * @param string $worksheet Worksheet ID.
	 * @return array
	 */
	public function adopt_worksheet( int $actor, string $worksheet ): array;
	/**
	 * For draft save.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return array|null
	 */
	public function for_draft_save( string $worksheet, ?array $pin, int $actor ): ?array;
	/**
	 * Sync draft pin.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return void
	 */
	public function sync_draft_pin( string $worksheet, ?array $pin, int $actor ): void;
	/**
	 * For publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function for_publication( string $publication_id, string $revision_id ): ?array;
}

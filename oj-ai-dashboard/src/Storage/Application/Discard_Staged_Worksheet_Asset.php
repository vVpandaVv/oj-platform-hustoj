<?php
/**
 * Discard an unbound staged worksheet asset.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use Closure;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discard an unbound staged worksheet asset.
 */
final class Discard_Staged_Worksheet_Asset {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize discard staged worksheet asset dependencies and state.
	 *
	 * @param Worksheet_Asset_Target_Port_Interface    $targets Targets.
	 * @param Worksheet_Asset_Lifecycle_Port_Interface $assets Assets.
	 * @param Private_File_Store_Port_Interface        $files Files.
	 * @param callable|null                            $clock Clock.
	 */
	public function __construct(
		private Worksheet_Asset_Target_Port_Interface $targets,
		private Worksheet_Asset_Lifecycle_Port_Interface $assets,
		private Private_File_Store_Port_Interface $files,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute discard staged worksheet asset.
	 *
	 * @param Discard_Staged_Worksheet_Asset_Command $command Command.
	 * @return Discard_Staged_Worksheet_Asset_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Discard_Staged_Worksheet_Asset_Command $command ): Discard_Staged_Worksheet_Asset_Result {
		$target = $this->targets->current_owned_target( $command->actor_user_id(), $command->worksheet_id() );
		if ( null === $target ) {
			throw Worksheet_Asset_Failure::not_found();
		}
		if (
			! hash_equals( $command->expected_state(), $target['state'] )
			|| ! hash_equals( $command->expected_revision_id(), $target['current_revision_id'] )
		) {
			throw Worksheet_Asset_Failure::target_conflict();
		}

		$row   = $this->owned_staged_row( $command );
		$state = (string) ( $row['state'] ?? '' );
		if ( 'ready' === $state ) {
			throw Worksheet_Asset_Failure::target_conflict();
		}
		if ( 'purged' === $state ) {
			$purged_at = (string) ( $row['purged_at_utc'] ?? '' );
			$this->files->delete( (string) ( $row['storage_key'] ?? '' ) );
			return new Discard_Staged_Worksheet_Asset_Result( $command->asset_id(), $purged_at, true );
		}
		if (
			'quarantined' !== $state
			|| 'clean' !== (string) ( $row['scan_state'] ?? '' )
			|| null !== ( $row['legal_hold_until_utc'] ?? null )
		) {
			throw Worksheet_Asset_Failure::target_conflict();
		}

		$purged_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $purged_at ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$changed = $this->assets->mark_quarantined_purged(
			$command->actor_user_id(),
			$command->worksheet_id(),
			$command->asset_id(),
			$purged_at
		);
		if ( ! $changed ) {
			$race = $this->owned_staged_row( $command );
			if ( 'purged' !== (string) ( $race['state'] ?? '' ) ) {
				throw Worksheet_Asset_Failure::target_conflict();
			}
			$row       = $race;
			$purged_at = (string) ( $race['purged_at_utc'] ?? '' );
		}

		$this->files->delete( (string) ( $row['storage_key'] ?? '' ) );
		return new Discard_Staged_Worksheet_Asset_Result( $command->asset_id(), $purged_at, ! $changed );
	}

	/**
	 * Owned staged row.
	 *
	 * @param Discard_Staged_Worksheet_Asset_Command $command Command.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function owned_staged_row( Discard_Staged_Worksheet_Asset_Command $command ): array {
		$row = $this->assets->find_by_public_id( $command->asset_id() );
		if (
			! is_array( $row )
			|| $command->actor_user_id() !== (int) ( $row['created_by_user_id'] ?? 0 )
			|| 'worksheet' !== (string) ( $row['owner_type'] ?? '' )
			|| ! hash_equals( $command->worksheet_id(), (string) ( $row['owner_public_id'] ?? '' ) )
			|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
		) {
			throw Worksheet_Asset_Failure::not_found();
		}
		return $row;
	}
}

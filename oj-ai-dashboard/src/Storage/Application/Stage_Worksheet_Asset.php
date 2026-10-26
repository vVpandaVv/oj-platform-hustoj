<?php
/**
 * StageWorksheetAsset use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a private quarantine file and its metadata receipt with compensation.
 */
final class Stage_Worksheet_Asset {
	/**
	 * Targets.
	 *
	 * @var Worksheet_Asset_Target_Port_Interface
	 */
	private Worksheet_Asset_Target_Port_Interface $targets;
	/**
	 * Assets.
	 *
	 * @var Worksheet_Asset_Repository_Port_Interface
	 */
	private Worksheet_Asset_Repository_Port_Interface $assets;
	/**
	 * Files.
	 *
	 * @var Private_File_Store_Port_Interface
	 */
	private Private_File_Store_Port_Interface $files;
	/**
	 * Profile.
	 *
	 * @var Worksheet_Asset_Upload_Profile
	 */
	private Worksheet_Asset_Upload_Profile $profile;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize stage worksheet asset dependencies and state.
	 *
	 * @param Worksheet_Asset_Target_Port_Interface     $targets Targets.
	 * @param Worksheet_Asset_Repository_Port_Interface $assets Assets.
	 * @param Private_File_Store_Port_Interface         $files Files.
	 * @param Worksheet_Asset_Upload_Profile            $profile Profile.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		Worksheet_Asset_Target_Port_Interface $targets,
		Worksheet_Asset_Repository_Port_Interface $assets,
		Private_File_Store_Port_Interface $files,
		Worksheet_Asset_Upload_Profile $profile,
		Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->targets = $targets;
		$this->assets  = $assets;
		$this->files   = $files;
		$this->profile = $profile;
		$this->ids     = $ids;
		$this->clock   = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute stage worksheet asset.
	 *
	 * @param Stage_Worksheet_Asset_Command $command Command.
	 * @return Stage_Worksheet_Asset_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Stage_Worksheet_Asset_Command $command ): Stage_Worksheet_Asset_Result {
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

		$metadata    = $this->profile->inspect( $command->upload() );
		$storage_key = $command->storage_key( $metadata['extension'] );
		$existing    = $this->assets->find_by_storage_key( $storage_key );
		if ( is_array( $existing ) ) {
			return $this->replay( $command, $metadata, $existing );
		}

		$created_at = ( $this->clock )();
		$this->assert_utc( $created_at );
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( $created_at . ' UTC' ) + 86400 );
		$asset_id     = $this->ids->create()->value();
		$file_created = false;

		try {
			$file_created = $this->files->put_quarantined( $metadata['tmp_name'], $storage_key, $metadata['sha256'] );
			$this->assets->insert_quarantined(
				array(
					'public_id'           => $asset_id,
					'actor_user_id'       => $command->actor_user_id(),
					'created_at_utc'      => $created_at,
					'worksheet_public_id' => $command->worksheet_id(),
					'storage_key'         => $storage_key,
					'original_name'       => $metadata['original_name'],
					'mime_type'           => $metadata['mime_type'],
					'byte_size'           => $metadata['byte_size'],
					'sha256'              => $metadata['sha256'],
					'expires_at_utc'      => $expires_at,
				)
			);
		} catch ( Worksheet_Asset_Failure $failure ) {
			$race = $this->assets->find_by_storage_key( $storage_key );
			if ( is_array( $race ) ) {
				return $this->replay( $command, $metadata, $race );
			}
			if ( $file_created ) {
				$this->compensate( $storage_key );
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $file_created ) {
				$this->compensate( $storage_key );
			}
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		return new Stage_Worksheet_Asset_Result(
			$asset_id,
			$metadata['original_name'],
			$metadata['mime_type'],
			$metadata['byte_size'],
			$metadata['sha256'],
			'quarantined',
			'clean',
			$expires_at,
			false
		);
	}

	/**
	 * Replay.
	 *
	 * @param Stage_Worksheet_Asset_Command $command Command.
	 * @param array<string,mixed>           $metadata Metadata.
	 * @param array<string,mixed>           $row Row.
	 * @return Stage_Worksheet_Asset_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function replay(
		Stage_Worksheet_Asset_Command $command,
		array $metadata,
		array $row
	): Stage_Worksheet_Asset_Result {
		$matches = (int) ( $row['created_by_user_id'] ?? 0 ) === $command->actor_user_id()
			&& hash_equals( (string) ( $row['owner_public_id'] ?? '' ), $command->worksheet_id() )
			&& 'worksheet' === (string) ( $row['owner_type'] ?? '' )
			&& 'worksheet_asset' === (string) ( $row['purpose'] ?? '' )
			&& hash_equals( (string) ( $row['sha256'] ?? '' ), $metadata['sha256'] )
			&& (int) ( $row['byte_size'] ?? 0 ) === $metadata['byte_size']
			&& hash_equals( (string) ( $row['mime_type'] ?? '' ), $metadata['mime_type'] )
			&& hash_equals( (string) ( $row['original_name'] ?? '' ), $metadata['original_name'] )
			&& 'clean' === (string) ( $row['scan_state'] ?? '' )
			&& in_array( (string) ( $row['state'] ?? '' ), array( 'quarantined', 'ready' ), true );
		if ( ! $matches ) {
			throw Worksheet_Asset_Failure::idempotency_conflict();
		}

		return new Stage_Worksheet_Asset_Result(
			(string) $row['public_id'],
			(string) $row['original_name'],
			(string) $row['mime_type'],
			(int) $row['byte_size'],
			(string) $row['sha256'],
			(string) $row['state'],
			(string) $row['scan_state'],
			(string) $row['expires_at_utc'],
			true
		);
	}

	/**
	 * Compensate.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	private function compensate( string $storage_key ): void {
		try {
			$this->files->delete( $storage_key );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			// An unreferenced quarantine file is handled by storage cleanup.
		}
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset clock returned an invalid UTC value.' );
		}
	}
}

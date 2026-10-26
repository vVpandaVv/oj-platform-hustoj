<?php
/**
 * Clone an authorized worksheet asset into a pending worksheet root.
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revalidates source bytes and creates a quarantined receipt for C03 only.
 */
final class Clone_Worksheet_Asset {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize clone worksheet asset dependencies and state.
	 *
	 * @param Read_Worksheet_Asset_Content              $read_source Read source.
	 * @param Worksheet_Asset_Repository_Port_Interface $assets Assets.
	 * @param Private_File_Store_Port_Interface         $files Files.
	 * @param Worksheet_Asset_Upload_Profile            $profile Profile.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Read_Worksheet_Asset_Content $read_source,
		private Worksheet_Asset_Repository_Port_Interface $assets,
		private Private_File_Store_Port_Interface $files,
		private Worksheet_Asset_Upload_Profile $profile,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute clone worksheet asset.
	 *
	 * @param Clone_Worksheet_Asset_Command $command Command.
	 * @return Stage_Worksheet_Asset_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Clone_Worksheet_Asset_Command $command ): Stage_Worksheet_Asset_Result {
		$source   = $this->read_source->execute(
			$command->actor_user_id(),
			$command->source_asset_id(),
			'revision',
			$command->source_revision_id()
		);
		$metadata = $this->profile->inspect(
			array(
				'name'     => $source->original_name(),
				'type'     => $source->mime_type(),
				'tmp_name' => $source->path(),
				'error'    => UPLOAD_ERR_OK,
				'size'     => $source->byte_size(),
			)
		);
		if (
			! hash_equals( $source->sha256(), $metadata['sha256'] )
			|| ! hash_equals( $source->mime_type(), $metadata['mime_type'] )
			|| $source->byte_size() !== $metadata['byte_size']
		) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$storage_key = $command->storage_key( $metadata['extension'], $attempt );
			$existing    = $this->assets->find_by_storage_key( $storage_key );
			if ( is_array( $existing ) ) {
				$replay = $this->replay( $command, $metadata, $existing );
				if ( null === $replay ) {
					continue;
				}
				return $replay;
			}
			$created = $this->create( $command, $metadata, $storage_key );
			if ( null !== $created ) {
				return $created;
			}
		}

		throw Worksheet_Asset_Failure::storage_unavailable();
	}

	/**
	 * Create clone worksheet asset.
	 *
	 * @param Clone_Worksheet_Asset_Command $command Command.
	 * @param array<string,mixed>           $metadata Metadata.
	 * @param string                        $storage_key Storage key.
	 * @return Stage_Worksheet_Asset_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create(
		Clone_Worksheet_Asset_Command $command,
		array $metadata,
		string $storage_key
	): ?Stage_Worksheet_Asset_Result {
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
					'worksheet_public_id' => $command->destination_worksheet_id(),
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
				$this->compensate_file( $storage_key );
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $file_created ) {
				$this->compensate_file( $storage_key );
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
	 * @param Clone_Worksheet_Asset_Command $command Command.
	 * @param array<string,mixed>           $metadata Metadata.
	 * @param array<string,mixed>           $row Row.
	 * @return Stage_Worksheet_Asset_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function replay(
		Clone_Worksheet_Asset_Command $command,
		array $metadata,
		array $row
	): ?Stage_Worksheet_Asset_Result {
		$matches = $command->actor_user_id() === (int) ( $row['created_by_user_id'] ?? 0 )
			&& 'worksheet' === (string) ( $row['owner_type'] ?? '' )
			&& hash_equals( $command->destination_worksheet_id(), (string) ( $row['owner_public_id'] ?? '' ) )
			&& 'worksheet_asset' === (string) ( $row['purpose'] ?? '' )
			&& hash_equals( $metadata['sha256'], (string) ( $row['sha256'] ?? '' ) )
			&& $metadata['byte_size'] === (int) ( $row['byte_size'] ?? 0 )
			&& hash_equals( $metadata['mime_type'], (string) ( $row['mime_type'] ?? '' ) )
			&& hash_equals( $metadata['original_name'], (string) ( $row['original_name'] ?? '' ) )
			&& 'clean' === (string) ( $row['scan_state'] ?? '' )
			&& in_array( (string) ( $row['state'] ?? '' ), array( 'quarantined', 'ready', 'purged' ), true );
		if ( ! $matches ) {
			throw Worksheet_Asset_Failure::idempotency_conflict();
		}
		$asset_id = is_string( $row['public_id'] ?? null ) ? $row['public_id'] : '';
		new Public_Id( $asset_id );
		if ( 'purged' === (string) $row['state'] ) {
			return null;
		}

		return new Stage_Worksheet_Asset_Result(
			$asset_id,
			(string) $row['original_name'],
			(string) $row['mime_type'],
			(int) $row['byte_size'],
			(string) $row['sha256'],
			(string) $row['state'],
			(string) $row['scan_state'],
			(string) ( $row['expires_at_utc'] ?? '1970-01-01 00:00:00' ),
			true
		);
	}

	/**
	 * Compensate file.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 */
	private function compensate_file( string $storage_key ): void {
		try {
			$this->files->delete( $storage_key );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
			// Retention cleanup removes an unreferenced quarantine file.
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
			throw new InvalidArgumentException( 'CloneWorksheetAsset clock returned an invalid UTC value.' );
		}
	}
}

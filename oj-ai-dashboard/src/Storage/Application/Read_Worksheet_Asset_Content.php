<?php
/**
 * Read authorized worksheet asset content.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use Closure;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Source_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Target_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reauthorizes the exact source reference before resolving an internal path.
 */
final class Read_Worksheet_Asset_Content {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize worksheet asset content dependencies and state.
	 *
	 * @param Worksheet_Asset_Lifecycle_Port_Interface $assets Assets.
	 * @param Worksheet_Asset_Source_Port_Interface    $sources Sources.
	 * @param Worksheet_Asset_Target_Port_Interface    $targets Targets.
	 * @param Private_File_Read_Port_Interface         $files Files.
	 * @param callable|null                            $clock Clock.
	 */
	public function __construct(
		private Worksheet_Asset_Lifecycle_Port_Interface $assets,
		private Worksheet_Asset_Source_Port_Interface $sources,
		private Worksheet_Asset_Target_Port_Interface $targets,
		private Private_File_Read_Port_Interface $files,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute worksheet asset content.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $asset_id Asset id.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @return Worksheet_Asset_Content_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute(
		int $actor_user_id,
		string $asset_id,
		string $source_type,
		string $source_id
	): Worksheet_Asset_Content_Result {
		if ( $actor_user_id < 1 ) {
			throw Worksheet_Asset_Failure::not_found();
		}
		new Public_Id( $asset_id );
		new Public_Id( $source_id );
		if ( ! in_array( $source_type, array( 'revision', 'publication', 'staged' ), true ) ) {
			throw Worksheet_Asset_Failure::not_found();
		}

		$row = $this->assets->find_by_public_id( $asset_id );
		if ( ! is_array( $row ) || ! $this->is_worksheet_asset( $row ) ) {
			throw Worksheet_Asset_Failure::not_found();
		}

		$state = (string) ( $row['state'] ?? '' );
		if ( 'staged' === $source_type ) {
			$this->authorize_staged( $actor_user_id, $asset_id, $source_id, $row );
		} elseif (
			'ready' !== $state
			|| ! $this->sources->owned_source_references_asset(
				$actor_user_id,
				$source_type,
				$source_id,
				$asset_id
			)
		) {
			throw Worksheet_Asset_Failure::not_found();
		}

		$sha256    = (string) ( $row['sha256'] ?? '' );
		$byte_size = $this->positive_integer( $row['byte_size'] ?? null );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$path        = $this->files->readable_path( (string) ( $row['storage_key'] ?? '' ), $sha256 );
		$actual_size = filesize( $path );
		if ( ! is_int( $actual_size ) || $actual_size !== $byte_size ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		return new Worksheet_Asset_Content_Result(
			$path,
			(string) ( $row['original_name'] ?? '' ),
			(string) ( $row['mime_type'] ?? '' ),
			$byte_size,
			$sha256
		);
	}

	/**
	 * Authorize staged.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $asset_id Asset id.
	 * @param string              $source_id Source id.
	 * @param array<string,mixed> $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorize_staged( int $actor_user_id, string $asset_id, string $source_id, array $row ): void {
		if (
			! hash_equals( $asset_id, $source_id )
			|| 'quarantined' !== (string) ( $row['state'] ?? '' )
			|| $actor_user_id !== (int) ( $row['created_by_user_id'] ?? 0 )
		) {
			throw Worksheet_Asset_Failure::not_found();
		}
		$expires_at = (string) ( $row['expires_at_utc'] ?? '' );
		$now        = ( $this->clock )();
		if ( '' === $expires_at || strcmp( $expires_at, $now ) <= 0 ) {
			throw Worksheet_Asset_Failure::expired();
		}
		$worksheet_id = (string) ( $row['owner_public_id'] ?? '' );
		if ( null === $this->targets->current_owned_target( $actor_user_id, $worksheet_id ) ) {
			throw Worksheet_Asset_Failure::not_found();
		}
	}

	/**
	 * Is worksheet asset.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return bool
	 */
	private function is_worksheet_asset( array $row ): bool {
		return 'worksheet' === (string) ( $row['owner_type'] ?? '' )
			&& 'worksheet_asset' === (string) ( $row['purpose'] ?? '' )
			&& 'clean' === (string) ( $row['scan_state'] ?? '' )
			&& null === ( $row['purged_at_utc'] ?? null );
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$value = (int) $value;
		if ( $value < 1 ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		return $value;
	}
}

<?php
/**
 * Worksheet revision asset readiness adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet revision asset readiness adapter.
 */
final class Worksheet_Asset_Readiness_Checker implements Worksheet_Asset_Readiness_Port_Interface {
	/**
	 * Initialize worksheet asset readiness checker dependencies and state.
	 *
	 * @param Worksheet_Asset_Lifecycle_Port_Interface $assets Assets.
	 */
	public function __construct( private Worksheet_Asset_Lifecycle_Port_Interface $assets ) {
	}

	/**
	 * Inspect.
	 *
	 * @param int                    $actor_user_id Authenticated actor from the access context.
	 * @param string                 $worksheet_id Worksheet public identifier.
	 * @param array<array-key,mixed> $asset_ids Values validated by this boundary.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( int $actor_user_id, string $worksheet_id, array $asset_ids ): array {
		if ( $actor_user_id < 1 || ! array_is_list( $asset_ids ) || count( $asset_ids ) > Worksheet_Document_Profile::MAX_BODY_ASSETS ) {
			throw new InvalidArgumentException( 'Worksheet asset readiness input is invalid.' );
		}
		new Public_Id( $worksheet_id );
		foreach ( $asset_ids as $asset_id ) {
			if ( ! is_string( $asset_id ) ) {
				throw new InvalidArgumentException( 'Worksheet asset readiness ID is invalid.' );
			}
			new Public_Id( $asset_id );
		}
		if ( count( $asset_ids ) !== count( array_unique( $asset_ids ) ) ) {
			throw new InvalidArgumentException( 'Worksheet asset readiness IDs must be unique.' );
		}

		$sorted = $asset_ids;
		sort( $sorted, SORT_STRING );
		$blockers    = array();
		$ready_count = 0;
		$total_bytes = 0;
		foreach ( $sorted as $asset_id ) {
			$row    = $this->assets->find_by_public_id( $asset_id );
			$reason = $this->blocker_reason( $row, $worksheet_id, $asset_id );
			if ( null !== $reason ) {
				$blockers[] = array(
					'asset_id' => $asset_id,
					'code'     => 'worksheet_asset_not_ready',
					'reason'   => $reason,
				);
				continue;
			}

			$bytes        = $this->positive_byte_size( $row['byte_size'] );
			$total_bytes += $bytes;
			++$ready_count;
		}
		if ( $total_bytes > Worksheet_Document_Profile::MAX_REVISION_ASSET_BYTES ) {
			$blockers[] = array(
				'asset_id' => '',
				'code'     => 'worksheet_asset_limit_exceeded',
				'reason'   => 'total_bytes',
			);
		}

		return array(
			'ready'      => array() === $blockers,
			'blockers'   => $blockers,
			'statistics' => array(
				'asset_count'       => count( $asset_ids ),
				'ready_asset_count' => $ready_count,
				'asset_total_bytes' => $total_bytes,
			),
		);
	}

	/**
	 * Blocker reason.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @param string                   $worksheet_id Worksheet public identifier.
	 * @param string                   $asset_id Asset id.
	 * @return string|null
	 */
	private function blocker_reason( ?array $row, string $worksheet_id, string $asset_id ): ?string {
		if ( null === $row ) {
			return 'missing';
		}
		if (
			$asset_id !== (string) ( $row['public_id'] ?? '' )
			|| 'worksheet' !== (string) ( $row['owner_type'] ?? '' )
			|| $worksheet_id !== (string) ( $row['owner_public_id'] ?? '' )
			|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
		) {
			return 'owner';
		}
		if ( 'ready' !== (string) ( $row['state'] ?? '' ) || null !== ( $row['purged_at_utc'] ?? null ) ) {
			return 'state';
		}
		if ( 'clean' !== (string) ( $row['scan_state'] ?? '' ) ) {
			return 'scan';
		}
		$byte_size = $this->positive_byte_size( $row['byte_size'] ?? null );
		if ( $byte_size < 1 || $byte_size > Worksheet_Document_Profile::MAX_FILE_BYTES ) {
			return 'byte_size';
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $row['sha256'] ?? '' ) ) ) {
			return 'hash';
		}
		return null;
	}

	/**
	 * Positive byte size.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_byte_size( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if (
			is_string( $value )
			&& strlen( $value ) <= 10
			&& 1 === preg_match( '/^[1-9][0-9]*$/D', $value )
		) {
			return (int) $value;
		}
		return 0;
	}
}

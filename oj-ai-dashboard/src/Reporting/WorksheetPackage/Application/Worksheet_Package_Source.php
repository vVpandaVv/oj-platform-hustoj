<?php
/**
 * Worksheet package source.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Content_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package source.
 */
final class Worksheet_Package_Source {
	/**
	 * Initialize worksheet package source dependencies and state.
	 *
	 * @param Worksheet_Package_Request_Snapshot $snapshot Snapshot.
	 * @param array<array-key,mixed>             $assets Assets.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private Worksheet_Package_Request_Snapshot $snapshot, private array $assets ) {
		$expected = array_column( $snapshot->assets(), 'source_asset_id' );
		$actual   = array_keys( $assets );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( 'Worksheet package asset content is incomplete.' );
		}
		foreach ( $assets as $asset ) {
			if ( ! $asset instanceof Worksheet_Asset_Content_Result ) {
				throw new InvalidArgumentException( 'Worksheet package asset content is invalid.' );
			}
		}
	}
	/**
	 * Snapshot.
	 *
	 * @return Worksheet_Package_Request_Snapshot
	 */
	public function snapshot(): Worksheet_Package_Request_Snapshot {
		return $this->snapshot;
	}
	/**
	 * Assets.
	 *
	 * @return array<string,Worksheet_Asset_Content_Result>
	 */
	public function assets(): array {
		return $this->assets;
	}
}

<?php
/**
 * Validated, non-identifying inventory of a legacy migration source.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe inventory projection. Source records and filesystem paths never cross this boundary.
 */
final class Legacy_Source_Snapshot {
	/**
	 * Inventory counts without source record values.
	 *
	 * @var array<int,array{logical_table:string,source_table:string,disposition:string,rows:int}>
	 */
	private array $tables;

	/**
	 * Required context and user mappings by kind.
	 *
	 * @var array<string,int>
	 */
	private array $mapping_requirements;

	/**
	 * Opaque mapping references without learner identities.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mapping_manifest;

	/**
	 * Exclusions grouped by stable reason code.
	 *
	 * @var array<int,array{code:string,count:int}>
	 */
	private array $exclusions;

	/**
	 * Conflicts grouped by stable reason code.
	 *
	 * @var array<int,array{code:string,count:int}>
	 */
	private array $conflicts;

	/**
	 * Validate an inventory before it crosses the preview boundary.
	 *
	 * @param string              $profile Source profile identifier.
	 * @param string              $source_version Source semantic version.
	 * @param string              $source_fingerprint Source SHA-256 fingerprint.
	 * @param array<int,mixed>    $tables Unvalidated source table inventory.
	 * @param int                 $blob_count Number of source blobs.
	 * @param int                 $blob_bytes Total source blob bytes.
	 * @param array<string,mixed> $mapping_requirements Unvalidated mapping counts.
	 * @param array<int,mixed>    $exclusions Unvalidated exclusion counts.
	 * @param array<int,mixed>    $conflicts Unvalidated conflict counts.
	 * @param array<int,mixed>    $mapping_manifest Unvalidated mapping entries.
	 * @throws InvalidArgumentException When an inventory field is invalid.
	 */
	public function __construct(
		private string $profile,
		private string $source_version,
		private string $source_fingerprint,
		array $tables,
		private int $blob_count,
		private int $blob_bytes,
		array $mapping_requirements,
		array $exclusions,
		array $conflicts,
		array $mapping_manifest = array()
	) {
		if (
			1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $profile )
			|| 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $source_version )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint )
			|| $blob_count < 0
			|| $blob_bytes < 0
		) {
			throw new InvalidArgumentException( 'Legacy source identity is invalid.' );
		}

		$seen = array();
		foreach ( $tables as $table ) {
			if (
				! is_array( $table )
				|| array( 'logical_table', 'source_table', 'disposition', 'rows' ) !== array_keys( $table )
				|| ! is_string( $table['logical_table'] )
				|| ! is_string( $table['source_table'] )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $table['logical_table'] )
				|| 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $table['source_table'] )
				|| ! in_array( $table['disposition'], array( 'migrate', 'mapping', 'exclude' ), true )
				|| ! is_int( $table['rows'] )
				|| $table['rows'] < 0
				|| isset( $seen[ $table['logical_table'] ] )
			) {
				throw new InvalidArgumentException( 'Legacy source table inventory is invalid.' );
			}
			$seen[ $table['logical_table'] ] = true;
		}
		if ( array() === $tables ) {
			throw new InvalidArgumentException( 'Legacy source table inventory is empty.' );
		}

		foreach ( $mapping_requirements as $kind => $count ) {
			if (
				! in_array( $kind, array( 'context', 'user' ), true )
				|| ! is_int( $count )
				|| $count < 0
			) {
				throw new InvalidArgumentException( 'Legacy source mapping inventory is invalid.' );
			}
		}
		ksort( $mapping_requirements, SORT_STRING );

		$mapping_manifest = $this->normalize_mapping_manifest( $mapping_manifest, $mapping_requirements );

		$this->tables               = array_values( $tables );
		$this->mapping_requirements = $mapping_requirements;
		$this->mapping_manifest     = $mapping_manifest;
		$this->exclusions           = $this->safe_counts( $exclusions );
		$this->conflicts            = $this->safe_counts( $conflicts );
	}

	/**
	 * Source format used to interpret this inventory.
	 *
	 * @return string
	 */
	public function profile(): string {
		return $this->profile;
	}

	/**
	 * Version bound to the migration preview.
	 *
	 * @return string
	 */
	public function source_version(): string {
		return $this->source_version;
	}

	/**
	 * Fingerprint used to reject stale migration previews.
	 *
	 * @return string
	 */
	public function source_fingerprint(): string {
		return $this->source_fingerprint;
	}

	/**
	 * Return the validated table inventory, never source records.
	 *
	 * @return array<int,array{logical_table:string,source_table:string,disposition:string,rows:int}>
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * Number of tables included in the inventory.
	 *
	 * @return int
	 */
	public function table_count(): int {
		return count( $this->tables );
	}

	/**
	 * Total rows, including excluded and mapping-only sources.
	 *
	 * @return int
	 */
	public function row_count(): int {
		return array_sum( array_column( $this->tables, 'rows' ) );
	}

	/**
	 * Number of blobs in the frozen inventory.
	 *
	 * @return int
	 */
	public function blob_count(): int {
		return $this->blob_count;
	}

	/**
	 * Total bytes for storage preflight.
	 *
	 * @return int
	 */
	public function blob_bytes(): int {
		return $this->blob_bytes;
	}

	/**
	 * Return required mapping counts by kind.
	 *
	 * @return array<string,int>
	 */
	public function mapping_requirements(): array {
		return $this->mapping_requirements;
	}

	/**
	 * Return canonically sorted opaque mapping entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mapping_manifest(): array {
		return $this->mapping_manifest;
	}

	/**
	 * Return exclusion counts safe for preview output.
	 *
	 * @return array<int,array{code:string,count:int}>
	 */
	public function exclusions(): array {
		return $this->exclusions;
	}

	/**
	 * Return conflict counts safe for preview output.
	 *
	 * @return array<int,array{code:string,count:int}>
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Summarize the migration plan without exposing source rows.
	 *
	 * @return array{source_rows:int,migration_candidates:int,excluded:int,conflicts:int,mapping_needed:int}
	 */
	public function counts(): array {
		$migration = 0;
		$excluded  = 0;
		foreach ( $this->tables as $table ) {
			if ( 'migrate' === $table['disposition'] ) {
				$migration += $table['rows'];
			} else {
				$excluded += $table['rows'];
			}
		}

		return array(
			'source_rows'          => $this->row_count(),
			'migration_candidates' => $migration,
			'excluded'             => $excluded,
			'conflicts'            => array_sum( array_column( $this->conflicts, 'count' ) ),
			'mapping_needed'       => array_sum( $this->mapping_requirements ),
		);
	}

	/**
	 * Validate mapping identities, references and declared counts.
	 *
	 * @param array<int,mixed>  $items Unvalidated mapping entries.
	 * @param array<string,int> $requirements Validated counts by kind.
	 * @return array<int,array<string,mixed>>
	 * @throws InvalidArgumentException When mapping identities or counts differ.
	 */
	private function normalize_mapping_manifest( array $items, array $requirements ): array {
		if ( ! array_is_list( $items ) || count( $items ) > Legacy_Mapping_Manifest::MAXIMUM_REQUIREMENTS ) {
			throw new InvalidArgumentException( 'Legacy source mapping manifest is invalid.' );
		}
		$seen     = array();
		$contexts = array();
		$counts   = array(
			'context' => 0,
			'user'    => 0,
		);
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Legacy source mapping manifest is invalid.' );
			}
			$kind       = $item['kind'] ?? null;
			$source_ref = $item['source_ref'] ?? null;
			if (
				! in_array( $kind, array( 'context', 'user' ), true )
				|| ! is_string( $source_ref )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_ref )
				|| isset( $seen[ $source_ref ] )
			) {
				throw new InvalidArgumentException( 'Legacy source mapping manifest identity is invalid.' );
			}
			$seen[ $source_ref ] = true;
			++$counts[ $kind ];
			if ( 'context' === $kind ) {
				if (
					array( 'kind', 'source_ref', 'source_type' ) !== array_keys( $item )
					|| ! in_array( $item['source_type'] ?? null, array( 'class', 'group' ), true )
				) {
					throw new InvalidArgumentException( 'Legacy source Context mapping requirement is invalid.' );
				}
				$contexts[ $source_ref ] = true;
			} elseif (
				array( 'assignment_source_ref', 'context_source_ref', 'kind', 'source_ref' ) !== array_keys( $item )
				|| ! is_string( $item['assignment_source_ref'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $item['assignment_source_ref'] )
				|| ! is_string( $item['context_source_ref'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $item['context_source_ref'] )
			) {
				throw new InvalidArgumentException( 'Legacy source learner mapping requirement is invalid.' );
			}
		}
		foreach ( $items as $item ) {
			if ( 'user' === $item['kind'] && ! isset( $contexts[ $item['context_source_ref'] ] ) ) {
				throw new InvalidArgumentException( 'Legacy source learner Context requirement is missing.' );
			}
		}
		if (
			( $requirements['context'] ?? null ) !== $counts['context']
			|| ( $requirements['user'] ?? null ) !== $counts['user']
		) {
			throw new InvalidArgumentException( 'Legacy source mapping requirement count drifted.' );
		}
		usort(
			$items,
			static fn ( array $left, array $right ): int => array( $left['kind'], $left['source_ref'] ) <=> array( $right['kind'], $right['source_ref'] )
		);
		return $items;
	}

	/**
	 * Validate stable reason codes and positive counts.
	 *
	 * @param array<int,mixed> $items Unvalidated count entries.
	 * @return array<int,array{code:string,count:int}>
	 * @throws InvalidArgumentException When counts or codes are invalid.
	 */
	private function safe_counts( array $items ): array {
		$seen = array();
		foreach ( $items as $item ) {
			if (
				! is_array( $item )
				|| array( 'code', 'count' ) !== array_keys( $item )
				|| ! is_string( $item['code'] )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $item['code'] )
				|| ! is_int( $item['count'] )
				|| $item['count'] < 1
				|| isset( $seen[ $item['code'] ] )
			) {
				throw new InvalidArgumentException( 'Legacy source safe count is invalid.' );
			}
			$seen[ $item['code'] ] = true;
		}
		return array_values( $items );
	}
}

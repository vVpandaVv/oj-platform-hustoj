<?php
/**
 * WordPress prefixed legacy source item reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Item_Reader_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only exact-profile migrate rows in dependency order with a stable keyset cursor.
 */
final class WordPress_Prefixed_Legacy_Source_Item_Reader implements Legacy_Source_Item_Reader_Port_Interface {
	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;
	/**
	 * Database provider.
	 *
	 * @var Closure
	 */
	private Closure $database_provider;

	/**
	 * Initialize WordPress prefixed legacy source item reader dependencies and state.
	 *
	 * @param Canonical_Json                   $canonical Canonical JSON encoder used for stable digests.
	 * @param Legacy_Source_Profile_0_1_2|null $profile Profile.
	 * @param callable|null                    $database_provider Database provider.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?callable $database_provider = null
	) {
		$this->profile           = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->database_provider = null === $database_provider
			? static fn (): ?object => is_object( $GLOBALS['wpdb'] ?? null ) ? $GLOBALS['wpdb'] : null
			: Closure::fromCallable( $database_provider );
	}

	/**
	 * Read WordPress prefixed legacy source item reader.
	 *
	 * @param string $source_profile Source profile.
	 * @param int    $table_index Table index.
	 * @param int    $after_source_id After source id.
	 * @param int    $next_item_no Next item no.
	 * @param int    $limit Maximum number of entries requested.
	 * @return Legacy_Source_Item_Batch
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read(
		string $source_profile,
		int $table_index,
		int $after_source_id,
		int $next_item_no,
		int $limit
	): Legacy_Source_Item_Batch {
		try {
			if (
				Legacy_Source_Profile_0_1_2::PROFILE !== $source_profile
				|| $table_index < 0
				|| $after_source_id < 0
				|| $next_item_no < 1
				|| $limit < 1
				|| $limit > Execute_Legacy_Migration::BATCH_SIZE
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::EXECUTION_INVALID );
			}

			$database = ( $this->database_provider )();
			$this->assert_database( $database );
			$prefix           = $this->source_prefix( $database );
			$execution_tables = $this->profile->execution_tables();
			$profiles         = array_values( $execution_tables );
			$logical_tables   = array_keys( $execution_tables );
			if ( $table_index > count( $profiles ) || ( $table_index === count( $profiles ) && $after_source_id > 0 ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::EXECUTION_INVALID );
			}

			$items         = array();
			$profile_count = count( $profiles );
			while ( $table_index < $profile_count && count( $items ) < $limit ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Each table batch appends items, so its size must be measured again.
				$table_profile = $profiles[ $table_index ];
				$logical_table = $logical_tables[ $table_index ];
				if ( 'migrate' !== $table_profile['disposition'] ) {
					++$table_index;
					$after_source_id = 0;
					continue;
				}

				$remaining    = $limit - count( $items );
				$source_table = $prefix . $table_profile['suffix'];
				$this->assert_identifier( $source_table );
				if ( $source_table === $table_profile['suffix'] ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_COLLISION );
				}
				$rows    = $this->rows( $database, $logical_table, $source_table, $after_source_id, $remaining );
				$last_id = $after_source_id;
				foreach ( $rows as $row ) {
					$source_id = $this->positive_integer( $row['id'] ?? null );
					if ( $source_id <= $last_id ) {
						throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
					}
					$checksum = hash(
						'sha256',
						$this->canonical->encode(
							array(
								'profile'     => $source_profile,
								'source_key'  => (string) $source_id,
								'source_type' => $logical_table,
								'record'      => $row,
							)
						)
					);
					$items[]  = new Legacy_Source_Item( $next_item_no, $logical_table, $source_id, $checksum, $row );
					++$next_item_no;
					$last_id = $source_id;
				}

				if ( count( $rows ) === $remaining ) {
					return new Legacy_Source_Item_Batch( $items, $table_index, $last_id, $next_item_no, false );
				}
				++$table_index;
				$after_source_id = 0;
			}

			return new Legacy_Source_Item_Batch(
				$items,
				$table_index,
				$table_index < count( $profiles ) ? $after_source_id : 0,
				$next_item_no,
				$table_index >= count( $profiles )
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Rows.
	 *
	 * @param object $database Database.
	 * @param string $logical_table Logical table.
	 * @param string $source_table Source table.
	 * @param int    $after_source_id After source id.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( object $database, string $logical_table, string $source_table, int $after_source_id, int $limit ): array {
		$columns = $this->profile->columns( $logical_table );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$sql     = $database->prepare(
			'SELECT ' . $select . ' FROM `' . $source_table . '` WHERE `id` > %d ORDER BY `id` ASC LIMIT %d',
			$after_source_id,
			$limit
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE );
		}
		$this->reset_error( $database );
		$rows = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) || count( $rows ) > $limit ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || array_keys( $row ) !== $columns ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
			}
		}
		return array_values( $rows );
	}

	/**
	 * Reject invalid database.
	 *
	 * @param mixed $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database( $database ): void {
		foreach ( array( 'prepare', 'get_results' ) as $method ) {
			if ( ! is_object( $database ) || ! method_exists( $database, $method ) ) {
				throw new RuntimeException( 'Legacy source item database is unavailable.' );
			}
		}
	}

	/**
	 * Source prefix.
	 *
	 * @param object $database Database.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_prefix( object $database ): string {
		$prefix = $database->prefix ?? null;
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_COLLISION );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/D', $prefix ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
		}
		return $prefix;
	}

	/**
	 * Reject invalid identifier.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_identifier( string $value ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
		}
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
	}
}

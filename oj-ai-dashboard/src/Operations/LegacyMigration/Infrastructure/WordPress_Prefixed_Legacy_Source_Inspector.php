<?php
/**
 * WordPress prefixed legacy source inspector.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OverflowException;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the current site's explicitly prefixed 0.1.2 source tables.
 */
final class WordPress_Prefixed_Legacy_Source_Inspector implements Legacy_Source_Inspector_Port_Interface {
	private const PAGE_SIZE        = 250;
	private const MAX_SOURCE_ROWS  = 1000000;
	private const MAX_SOURCE_BYTES = 2147483648;

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
	 * Blob reader.
	 *
	 * @var Closure
	 */
	private Closure $blob_reader;

	/**
	 * Initialize WordPress prefixed legacy source inspector dependencies and state.
	 *
	 * @param Canonical_Json                   $canonical Canonical JSON encoder used for stable digests.
	 * @param Legacy_Source_Profile_0_1_2|null $profile Profile.
	 * @param Closure|null                     $database_provider Database provider.
	 * @param Closure|null                     $blob_reader Blob reader.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?Closure $database_provider = null,
		?Closure $blob_reader = null
	) {
		$this->profile           = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->database_provider = $database_provider ?? static fn () => $GLOBALS['wpdb'] ?? null;
		$this->blob_reader       = $blob_reader ?? fn ( int $file_id ): array => $this->wordpress_blob( $file_id );
	}

	/**
	 * Inspect.
	 *
	 * @param string $source_profile Source profile.
	 * @return Legacy_Source_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( string $source_profile ): Legacy_Source_Snapshot {
		if ( Legacy_Source_Profile_0_1_2::PROFILE !== $source_profile ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED );
		}

		try {
			$database = ( $this->database_provider )();
			$this->assert_database( $database );
			$prefix = $this->source_prefix( $database );

			$hash = hash_init( 'sha256' );
			hash_update(
				$hash,
				$this->canonical->encode(
					array(
						'profile' => $source_profile,
						'version' => Legacy_Source_Profile_0_1_2::VERSION,
					)
				)
			);
			$tables           = array();
			$file_ids         = array();
			$source_rows      = 0;
			$source_bytes     = 0;
			$mapping          = new Legacy_Mapping_Manifest( $this->canonical, $source_profile );
			$exclusion_counts = array();

			foreach ( $this->profile->tables() as $logical_table => $table_profile ) {
				$source_table = $prefix . $table_profile['suffix'];
				$this->assert_identifier( $source_table );
				if ( $source_table === $table_profile['suffix'] ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_COLLISION );
				}
				$this->assert_table_exists( $database, $source_table );
				$columns = $this->columns( $database, $source_table );
				if ( $columns !== $this->profile->columns( $logical_table ) ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
				}

				hash_update(
					$hash,
					$this->canonical->encode(
						array(
							'columns'       => $columns,
							'logical_table' => $logical_table,
							'source_table'  => $source_table,
						)
					)
				);
				$row_count    = $this->hash_rows( $database, $logical_table, $source_table, $hash, $file_ids, $source_bytes, $mapping );
				$source_rows += $row_count;
				if ( $source_rows > self::MAX_SOURCE_ROWS ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED );
				}

				$tables[] = array(
					'logical_table' => $logical_table,
					'source_table'  => $source_table,
					'disposition'   => $table_profile['disposition'],
					'rows'          => $row_count,
				);
				if ( null !== $table_profile['exclusion_code'] && $row_count > 0 ) {
					$exclusion_counts[ $table_profile['exclusion_code'] ] = ( $exclusion_counts[ $table_profile['exclusion_code'] ] ?? 0 ) + $row_count;
				}
			}

			ksort( $file_ids, SORT_NUMERIC );
			$blob_bytes     = 0;
			$blob_conflicts = array();
			foreach ( array_keys( $file_ids ) as $file_id ) {
				$blob = ( $this->blob_reader )( (int) $file_id );
				$blob = $this->valid_blob( $blob );
				hash_update(
					$hash,
					$this->canonical->encode(
						array(
							'file_id'    => (int) $file_id,
							'state'      => $blob['state'],
							'size_bytes' => $blob['size_bytes'],
							'sha256'     => $blob['sha256'],
						)
					)
				);
				$blob_bytes += $blob['size_bytes'];
				if ( 'ready' !== $blob['state'] ) {
					$code                    = 'legacy_blob_' . $blob['state'];
					$blob_conflicts[ $code ] = ( $blob_conflicts[ $code ] ?? 0 ) + 1;
				}
			}
			foreach ( $mapping->conflict_counts() as $code => $count ) {
				$blob_conflicts[ $code ] = ( $blob_conflicts[ $code ] ?? 0 ) + $count;
			}
			if ( $source_bytes + $blob_bytes > self::MAX_SOURCE_BYTES ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED );
			}

			return new Legacy_Source_Snapshot(
				Legacy_Source_Profile_0_1_2::PROFILE,
				Legacy_Source_Profile_0_1_2::VERSION,
				hash_final( $hash ),
				$tables,
				count( $file_ids ),
				$blob_bytes,
				$mapping->requirements(),
				$this->safe_counts( $exclusion_counts ),
				$this->safe_counts( $blob_conflicts ),
				$mapping->manifest()
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( OverflowException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED, $error );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Reject invalid database.
	 *
	 * @param mixed $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database( $database ): void {
		foreach ( array( 'prepare', 'get_var', 'get_results' ) as $method ) {
			if ( ! is_object( $database ) || ! method_exists( $database, $method ) ) {
				throw new RuntimeException( 'Legacy source database is unavailable.' );
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
	private function source_prefix( $database ): string {
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
	 * Reject invalid table exists.
	 *
	 * @param object $database Database.
	 * @param string $table Table.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_table_exists( $database, string $table ): void {
		$sql = $database->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		);
		if ( ! is_string( $sql ) || $table !== $database->get_var( $sql ) || '' !== (string) ( $database->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
		}
	}

	/**
	 * Columns.
	 *
	 * @param object $database Database.
	 * @param string $table Table.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function columns( $database, string $table ): array {
		$rows = $database->get_results( 'SHOW COLUMNS FROM `' . $table . '`', defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || '' !== (string) ( $database->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE );
		}
		$columns = array();
		foreach ( $rows as $row ) {
			$field = is_array( $row ) ? ( $row['Field'] ?? null ) : null;
			if ( ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $field ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
			}
			$columns[] = $field;
		}
		return $columns;
	}

	/**
	 * Hash rows.
	 *
	 * @param object                  $database Database.
	 * @param string                  $logical_table Logical table.
	 * @param string                  $table Table.
	 * @param resource|\HashContext   $hash Hash.
	 * @param array<int,bool>         &$file_ids File ids.
	 * @param int                     &$source_bytes Source bytes.
	 * @param Legacy_Mapping_Manifest $mapping Mapping.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_rows( $database, string $logical_table, string $table, $hash, array &$file_ids, int &$source_bytes, Legacy_Mapping_Manifest $mapping ): int {
		$offset = 0;
		while ( true ) {
			$rows = $database->get_results(
				'SELECT * FROM `' . $table . '` ORDER BY `id` ASC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
				defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
			);
			if ( ! is_array( $rows ) || '' !== (string) ( $database->last_error ?? '' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_UNAVAILABLE );
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || array_is_list( $row ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
				}
				if ( array_keys( $row ) !== $this->profile->columns( $logical_table ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
				}
				$encoded       = $this->canonical->encode( $row );
				$source_bytes += strlen( $encoded );
				if ( $source_bytes > self::MAX_SOURCE_BYTES ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED );
				}
				hash_update( $hash, $encoded );
				$mapping->observe( $logical_table, $row );
				if ( $this->profile->carries_file_id( $logical_table ) ) {
					$file_id = $row['file_id'] ?? null;
					if ( ( is_int( $file_id ) && $file_id > 0 ) || ( is_string( $file_id ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $file_id ) ) ) {
						$file_ids[ (int) $file_id ] = true;
					}
				}
			}

			$count   = count( $rows );
			$offset += $count;
			if ( $offset > self::MAX_SOURCE_ROWS ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED );
			}
			if ( $count < self::PAGE_SIZE ) {
				return $offset;
			}
		}
	}

	/**
	 * Valid blob.
	 *
	 * @param mixed $value Value.
	 * @return array{state:string,size_bytes:int,sha256:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function valid_blob( $value ): array {
		if ( ! is_array( $value ) || array( 'state', 'size_bytes', 'sha256' ) !== array_keys( $value ) ) {
			throw new RuntimeException( 'Legacy blob inspection returned an invalid shape.' );
		}
		if (
			! in_array( $value['state'], array( 'ready', 'missing', 'unsafe', 'unavailable' ), true )
			|| ! is_int( $value['size_bytes'] )
			|| $value['size_bytes'] < 0
			|| ( 'ready' === $value['state'] && ( ! is_string( $value['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['sha256'] ) ) )
			|| ( 'ready' !== $value['state'] && null !== $value['sha256'] )
		) {
			throw new RuntimeException( 'Legacy blob inspection result is invalid.' );
		}
		return $value;
	}

	/**
	 * WordPress blob.
	 *
	 * @param int $file_id File id.
	 * @return array{state:string,size_bytes:int,sha256:?string}
	 */
	private function wordpress_blob( int $file_id ): array {
		if ( ! function_exists( 'get_attached_file' ) || ! function_exists( 'wp_get_upload_dir' ) ) {
			return array(
				'state'      => 'unavailable',
				'size_bytes' => 0,
				'sha256'     => null,
			);
		}
		$path    = get_attached_file( $file_id, true );
		$uploads = wp_get_upload_dir();
		$base    = is_array( $uploads ) ? ( $uploads['basedir'] ?? null ) : null;
		if ( ! is_string( $path ) || '' === $path || ! is_string( $base ) || '' === $base ) {
			return array(
				'state'      => 'missing',
				'size_bytes' => 0,
				'sha256'     => null,
			);
		}
		$real_path = realpath( $path );
		$real_base = realpath( $base );
		if ( ! is_string( $real_path ) || ! is_string( $real_base ) || ! $this->within( $real_path, $real_base ) ) {
			return array(
				'state'      => 'unsafe',
				'size_bytes' => 0,
				'sha256'     => null,
			);
		}
		if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			return array(
				'state'      => 'missing',
				'size_bytes' => 0,
				'sha256'     => null,
			);
		}
		$size   = filesize( $real_path );
		$digest = hash_file( 'sha256', $real_path );
		if ( false === $size || ! is_string( $digest ) ) {
			return array(
				'state'      => 'unavailable',
				'size_bytes' => 0,
				'sha256'     => null,
			);
		}
		return array(
			'state'      => 'ready',
			'size_bytes' => $size,
			'sha256'     => $digest,
		);
	}

	/**
	 * Within.
	 *
	 * @param string $path Path.
	 * @param string $base Base.
	 * @return bool
	 */
	private function within( string $path, string $base ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		return $path === $base || str_starts_with( $path, $base . '/' );
	}

	/**
	 * Reject invalid identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $identifier ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH );
		}
	}

	/**
	 * Safe counts.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return array<int,array{code:string,count:int}>
	 */
	private function safe_counts( array $counts ): array {
		ksort( $counts, SORT_STRING );
		$result = array();
		foreach ( $counts as $code => $count ) {
			if ( $count > 0 ) {
				$result[] = array(
					'code'  => $code,
					'count' => $count,
				);
			}
		}
		return $result;
	}
}

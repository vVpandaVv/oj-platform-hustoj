<?php
/**
 * Validate report source envelopes before aggregation or disclosure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural checks shared by report readers and snapshot creation.
 */
final class Report_Source_Validator {
	/**
	 * Check a bounded list page returned by a source adapter.
	 *
	 * @param array<string,mixed> $page Raw adapter response.
	 * @param int                 $limit Maximum permitted records.
	 * @param int                 $cursor_limit Maximum cursor bytes.
	 * @return bool
	 */
	public static function list_page( array $page, int $limit, int $cursor_limit ): bool {
		return is_array( $page['items'] ?? null )
			&& self::records( $page['items'], $limit )
			&& is_string( $page['next_cursor'] ?? null )
			&& strlen( $page['next_cursor'] ) <= $cursor_limit;
	}

	/**
	 * Check one detail source and its continuation cursor.
	 *
	 * @param array<string,mixed> $page Raw adapter response.
	 * @param int                 $cursor_limit Maximum cursor bytes.
	 * @return bool
	 */
	public static function detail_page( array $page, int $cursor_limit ): bool {
		return self::record( $page['source'] ?? null )
			&& is_string( $page['next_cursor'] ?? null )
			&& strlen( $page['next_cursor'] ) <= $cursor_limit;
	}

	/**
	 * Reject malformed rows before consumers access their fields.
	 *
	 * @param array<mixed> $records Raw record sequence.
	 * @param int          $limit Maximum permitted records.
	 * @return bool
	 */
	public static function records( array $records, int $limit ): bool {
		if ( ! array_is_list( $records ) || count( $records ) > $limit ) {
			return false;
		}
		foreach ( $records as $record ) {
			if ( ! self::record( $record ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Require a field map before accessing a source's fields.
	 *
	 * @param mixed $record Raw record value.
	 * @return bool
	 */
	private static function record( mixed $record ): bool {
		if ( ! is_array( $record ) ) {
			return false;
		}
		foreach ( array_keys( $record ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return true;
	}
}

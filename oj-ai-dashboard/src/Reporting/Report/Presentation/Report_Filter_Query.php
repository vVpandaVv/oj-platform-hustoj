<?php
/**
 * Report filter query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Presentation;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report filter query.
 */
final class Report_Filter_Query {
	public const KEYS = array( 'period_from', 'period_to', 'assignment_states', 'submission_states' );

	/**
	 * From params.
	 *
	 * @param array<string,mixed> $params Params.
	 * @return Report_Filter
	 */
	public static function from_params( array $params ): Report_Filter {
		$period_from = self::optional_string( $params, 'period_from' );
		$period_to   = self::optional_string( $params, 'period_to' );

		return Report_Filter::from_query(
			$period_from,
			$period_to,
			self::site_timezone(),
			self::comma_list( $params, 'assignment_states' ),
			self::comma_list( $params, 'submission_states' )
		);
	}

	/**
	 * Optional string.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param string              $key Key.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function optional_string( array $params, string $key ): ?string {
		if ( ! array_key_exists( $key, $params ) ) {
			return null;
		}
		$value = $params[ $key ];
		if ( ! is_string( $value ) || '' === $value ) {
			throw new InvalidArgumentException( 'Report filter query value is invalid.' );
		}
		return $value;
	}

	/**
	 * Comma list.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param string              $key Key.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function comma_list( array $params, string $key ): array {
		if ( ! array_key_exists( $key, $params ) ) {
			return array();
		}
		$value = $params[ $key ];
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Report filter list is invalid.' );
		}
		$items = array_map( 'trim', explode( ',', $value ) );
		if ( in_array( '', $items, true ) ) {
			throw new InvalidArgumentException( 'Report filter list is invalid.' );
		}
		return $items;
	}

	/**
	 * Site timezone.
	 *
	 * @return string
	 */
	private static function site_timezone(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$value = wp_timezone_string();
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return 'UTC';
	}
}

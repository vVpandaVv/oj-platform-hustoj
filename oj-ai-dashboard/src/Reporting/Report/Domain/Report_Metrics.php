<?php
/**
 * Shared live-report metric contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared live-report metric contract.
 */
final class Report_Metrics {
	public const CONTRACT               = 'oj_ai.reporting_metrics@1.0.0';
	public const MINIMUM_AGGREGATE_SIZE = 5;

	/**
	 * Ratio.
	 *
	 * @param int $numerator Numerator.
	 * @param int $denominator Denominator.
	 * @return array{numerator:int,denominator:int,value:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function ratio( int $numerator, int $denominator ): array {
		if ( $numerator < 0 || $denominator < 0 || $numerator > $denominator ) {
			throw new InvalidArgumentException( 'Report ratio is invalid.' );
		}
		return array(
			'numerator'   => $numerator,
			'denominator' => $denominator,
			'value'       => 0 === $denominator ? null : $this->tenths( intdiv( ( $numerator * 1000 ) + intdiv( $denominator, 2 ), $denominator ) ),
		);
	}

	/**
	 * Average.
	 *
	 * @param int $basis_points_sum Basis points sum.
	 * @param int $count Count.
	 * @return array{value:?string,n:int,suppressed:bool,reason:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function average( int $basis_points_sum, int $count ): array {
		if ( $basis_points_sum < 0 || $count < 0 || $basis_points_sum > $count * 10000 ) {
			throw new InvalidArgumentException( 'Report average is invalid.' );
		}
		if ( 0 === $count ) {
			return array(
				'value'      => null,
				'n'          => 0,
				'suppressed' => false,
				'reason'     => 'no_data',
			);
		}
		if ( $count < self::MINIMUM_AGGREGATE_SIZE ) {
			return array(
				'value'      => null,
				'n'          => $count,
				'suppressed' => true,
				'reason'     => 'small_group',
			);
		}
		return array(
			'value'      => $this->tenths( intdiv( $basis_points_sum + ( 5 * $count ), 10 * $count ) ),
			'n'          => $count,
			'suppressed' => false,
			'reason'     => null,
		);
	}

	/**
	 * Averages basis points across one already-identified subject's own
	 * items (e.g. one student's scores across several worksheets). Unlike
	 * average(), this never suppresses on a small count: the k-anonymity
	 * concern average() guards against (a small group of *students* could
	 * make an individual identifiable) does not apply when the subject is
	 * already named and the count is simply how many of their own items
	 * were scored.
	 *
	 * @param int $basis_points_sum Basis points sum.
	 * @param int $count Count.
	 * @return array{value:?string,n:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function individual_average( int $basis_points_sum, int $count ): array {
		if ( $basis_points_sum < 0 || $count < 0 || $basis_points_sum > $count * 10000 ) {
			throw new InvalidArgumentException( 'Individual report average is invalid.' );
		}
		if ( 0 === $count ) {
			return array(
				'value' => null,
				'n'     => 0,
			);
		}
		return array(
			'value' => $this->tenths( intdiv( $basis_points_sum + ( 5 * $count ), 10 * $count ) ),
			'n'     => $count,
		);
	}

	/**
	 * Individual score.
	 *
	 * @param int $basis_points Basis points.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function individual_score( int $basis_points ): string {
		if ( $basis_points < 0 || $basis_points > 10000 ) {
			throw new InvalidArgumentException( 'Individual report score is invalid.' );
		}
		return $this->tenths( intdiv( $basis_points + 5, 10 ) );
	}

	/**
	 * Tenths.
	 *
	 * @param int $value Value.
	 * @return string
	 */
	private function tenths( int $value ): string {
		return intdiv( $value, 10 ) . '.' . ( $value % 10 );
	}
}

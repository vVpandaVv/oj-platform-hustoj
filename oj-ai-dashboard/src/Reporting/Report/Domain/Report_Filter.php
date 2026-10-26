<?php
/**
 * Canonical report filter shared by Q20 and Q21.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical report filter shared by Q20 and Q21.
 */
final class Report_Filter {
	public const SCHEMA_ID = 'oj_ai.report_filter';
	public const VERSION   = '1.0.0';
	public const BOUNDARY  = '[from,to)';

	public const ASSIGNMENT_STATES = array( 'scheduled', 'open', 'closed' );
	public const SUBMISSION_STATES = array( 'not_started', 'in_progress', 'official' );

	/**
	 * Initialize report filter dependencies and state.
	 *
	 * @param array<int,string> $assignment_states Assignment states.
	 * @param array<int,string> $submission_states Submission states.
	 * @param string            $timezone Timezone.
	 * @param string|null       $period_from Period from.
	 * @param string|null       $period_to Period to.
	 * @param string|null       $from_utc From utc.
	 * @param string|null       $to_utc To utc.
	 */
	private function __construct(
		private array $assignment_states,
		private array $submission_states,
		private string $timezone,
		private ?string $period_from,
		private ?string $period_to,
		private ?string $from_utc,
		private ?string $to_utc
	) {
	}

	/**
	 * Defaults.
	 *
	 * @param string $timezone Timezone.
	 * @return self
	 */
	public static function defaults( string $timezone = 'UTC' ): self {
		return self::from_query( null, null, $timezone, array(), array() );
	}

	/**
	 * Dates are local calendar dates. The upper date is exclusive.
	 *
	 * @param string|null      $period_from Period from.
	 * @param string|null      $period_to Period to.
	 * @param string           $timezone Timezone.
	 * @param array<int,mixed> $assignment_states Assignment states.
	 * @param array<int,mixed> $submission_states Submission states.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function from_query(
		?string $period_from,
		?string $period_to,
		string $timezone,
		array $assignment_states,
		array $submission_states
	): self {
		$timezone = trim( $timezone );
		if ( '' === $timezone || strlen( $timezone ) > 64 ) {
			throw new InvalidArgumentException( 'Report filter timezone is invalid.' );
		}
		try {
			$zone = new DateTimeZone( $timezone );
		} catch ( Throwable ) {
			throw new InvalidArgumentException( 'Report filter timezone is invalid.' );
		}

		if ( ( null === $period_from ) !== ( null === $period_to ) ) {
			throw new InvalidArgumentException( 'Report filter period must include both boundaries.' );
		}
		$from_utc = null;
		$to_utc   = null;
		if ( null !== $period_from && null !== $period_to ) {
			$from = self::local_date( $period_from, $zone );
			$to   = self::local_date( $period_to, $zone );
			if ( $to <= $from ) {
				throw new InvalidArgumentException( 'Report filter period is empty or reversed.' );
			}
			$utc      = new DateTimeZone( 'UTC' );
			$from_utc = $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			$to_utc   = $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		return new self(
			self::states( $assignment_states, self::ASSIGNMENT_STATES, 'assignment' ),
			self::states( $submission_states, self::SUBMISSION_STATES, 'submission' ),
			$timezone,
			$period_from,
			$period_to,
			$from_utc,
			$to_utc
		);
	}

	/**
	 * Assignment states.
	 *
	 * @return array<int,string>
	 */
	public function assignment_states(): array {
		return $this->assignment_states;
	}

	/**
	 * Submission states.
	 *
	 * @return array<int,string>
	 */
	public function submission_states(): array {
		return $this->submission_states;
	}

	/**
	 * Timezone.
	 *
	 * @return string
	 */
	public function timezone(): string {
		return $this->timezone;
	}

	/**
	 * From utc.
	 *
	 * @return string|null
	 */
	public function from_utc(): ?string {
		return $this->from_utc;
	}

	/**
	 * To utc.
	 *
	 * @return string|null
	 */
	public function to_utc(): ?string {
		return $this->to_utc;
	}

	/**
	 * Has period.
	 *
	 * @return bool
	 */
	public function has_period(): bool {
		return null !== $this->from_utc;
	}

	/**
	 * Payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return array(
			'assignment_states' => $this->assignment_states,
			'submission_states' => $this->submission_states,
			'timezone'          => $this->timezone,
			'period'            => null === $this->period_from ? null : array(
				'from'     => $this->period_from,
				'to'       => $this->period_to,
				'boundary' => self::BOUNDARY,
				'from_utc' => $this->from_utc,
				'to_utc'   => $this->to_utc,
			),
		);
	}

	/**
	 * Available.
	 *
	 * @return array<string,mixed>
	 */
	public function available(): array {
		return array(
			'assignment_states' => self::ASSIGNMENT_STATES,
			'submission_states' => self::SUBMISSION_STATES,
			'period'            => array(
				'format'   => 'YYYY-MM-DD',
				'boundary' => self::BOUNDARY,
				'timezone' => $this->timezone,
			),
		);
	}

	/**
	 * Local date.
	 *
	 * @param string       $value Value.
	 * @param DateTimeZone $zone Zone.
	 * @return DateTimeImmutable
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function local_date( string $value, DateTimeZone $zone ): DateTimeImmutable {
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if (
			false === $date || $date->format( 'Y-m-d' ) !== $value
			|| ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
		) {
			throw new InvalidArgumentException( 'Report filter date is invalid.' );
		}
		return $date;
	}

	/**
	 * States.
	 *
	 * @param array<int,mixed>  $values Values.
	 * @param array<int,string> $allowed Allowed.
	 * @param string            $kind Kind.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function states( array $values, array $allowed, string $kind ): array {
		if ( array() === $values ) {
			return $allowed;
		}
		if ( ! array_is_list( $values ) || count( $values ) > 16 ) {
			throw new InvalidArgumentException( 'Report filter ' . $kind . ' states are invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		$selected = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Report filter ' . $kind . ' state is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
			$selected[ $value ] = true;
		}
		$result = array_values( array_filter( $allowed, static fn ( string $value ): bool => isset( $selected[ $value ] ) ) );
		if ( array() === $result ) {
			throw new InvalidArgumentException( 'Report filter ' . $kind . ' states are empty.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $result;
	}
}

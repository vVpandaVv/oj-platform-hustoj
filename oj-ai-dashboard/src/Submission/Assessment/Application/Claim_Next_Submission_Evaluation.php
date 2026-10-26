<?php
/**
 * Atomically lease at most one due student submission evaluation run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomically lease at most one due student submission evaluation run.
 */
final class Claim_Next_Submission_Evaluation {
	public const LEASE_SECONDS = 300;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Lease tokens.
	 *
	 * @var Closure
	 */
	private Closure $lease_tokens;

	/**
	 * Initialize claim next submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Work_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param callable|null                                        $clock Clock.
	 * @param callable|null                                        $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Submission_Evaluation_Work_Repository_Port_Interface $repository,
		?callable $clock = null,
		?callable $lease_tokens = null
	) {
		$this->clock        = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->lease_tokens = null === $lease_tokens ? static fn (): string => self::new_lease_token() : Closure::fromCallable( $lease_tokens );
	}

	/**
	 * Execute claim next submission evaluation.
	 *
	 * @return Submission_Evaluation_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute(): ?Submission_Evaluation_Work_Item {
		$now   = $this->now();
		$token = ( $this->lease_tokens )();
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $this->repository->claim_due( $now, $token, $this->plus_seconds( $now, self::LEASE_SECONDS ) );
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $value;
	}

	/**
	 * Plus seconds.
	 *
	 * @param string $utc Utc.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function plus_seconds( string $utc, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * New lease token.
	 *
	 * @return string
	 */
	private static function new_lease_token(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 )
			. '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}

<?php
/**
 * Finalize started submissions after an assignment closes.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submit_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finalize started submissions after an assignment closes.
 */
final class Run_Auto_Submit_Due {
	public const LEASE_SECONDS   = 300;
	public const RETRY_SECONDS   = 300;
	public const CANDIDATE_LIMIT = 200;

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
	 * Initialize auto submit due dependencies and state.
	 *
	 * @param Auto_Submit_Assignment_Repository_Port_Interface $assignments Assignments.
	 * @param Auto_Submission_Commit_Repository_Port_Interface $submissions Submissions.
	 * @param Public_Id_Generator                              $ids Ids.
	 * @param callable|null                                    $clock Clock.
	 * @param callable|null                                    $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Auto_Submit_Assignment_Repository_Port_Interface $assignments,
		private Auto_Submission_Commit_Repository_Port_Interface $submissions,
		private Public_Id_Generator $ids,
		?callable $clock = null,
		?callable $lease_tokens = null
	) {
		$this->clock        = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
		$this->lease_tokens = null === $lease_tokens ? static fn (): string => self::new_lease_token() : Closure::fromCallable( $lease_tokens );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Auto_Submit_Due_Result
	 */
	public function run_once(): Run_Auto_Submit_Due_Result {
		$now  = $this->now();
		$item = $this->assignments->claim_due( $now, $this->lease_token(), $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $item ) {
			$this->assignments->compensate_due( $now, $this->ids->create()->value() );
			$item = $this->assignments->claim_due( $now, $this->lease_token(), $this->plus_seconds( $now, self::LEASE_SECONDS ) );
		}
		if ( null === $item ) {
			return new Run_Auto_Submit_Due_Result( Run_Auto_Submit_Due_Result::IDLE );
		}

		$submitted  = 0;
		$error      = null;
		$candidates = $this->assignments->candidates( $item, self::CANDIDATE_LIMIT );
		foreach ( $candidates as $candidate ) {
			try {
				$outcome = $this->submissions->auto_submit( $this->record( $item, $candidate, $now ) );
				if ( Auto_Submission_Commit_Repository_Port_Interface::AUTO_SUBMITTED === $outcome ) {
					++$submitted;
				}
			} catch ( Assignment_Failure $failure ) {
				$error ??= $failure->reason();
			} catch ( Throwable ) {
				$error ??= 'auto_submit_storage_unavailable';
			}
		}

		$finished_at = $this->now();
		$finish      = $this->assignments->finish(
			$item,
			$finished_at,
			$this->plus_seconds( $finished_at, self::RETRY_SECONDS ),
			$error
		);
		$state       = 'completed' === $finish
			? Run_Auto_Submit_Due_Result::COMPLETED
			: ( 'retry_wait' === $finish ? Run_Auto_Submit_Due_Result::RETRY_WAIT : Run_Auto_Submit_Due_Result::STALE );
		return new Run_Auto_Submit_Due_Result( $state, $item->assignment_id(), $submitted, $error );
	}

	/**
	 * Record.
	 *
	 * @param \OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item                                $item Item.
	 * @param array{submission_id:string,learner_user_id:int,state:string,lock_version:int,current_revision_id:?string} $candidate Candidate.
	 * @param string                                                                                                    $now Clock value supplied for deterministic scheduling.
	 * @return array<string,mixed>
	 */
	private function record( \OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item $item, array $candidate, string $now ): array {
		$identity = hash( 'sha256', $candidate['submission_id'] . '|' . $item->schedule_hash() );
		return array(
			'assignment_public_id'         => $item->assignment_id(),
			'learner_user_id'              => $candidate['learner_user_id'],
			'submission_public_id'         => $candidate['submission_id'],
			'receipt_public_id'            => $this->ids->create()->value(),
			'audit_public_id'              => $this->ids->create()->value(),
			'outbox_public_id'             => $this->ids->create()->value(),
			'command_id'                   => 'auto-submit:' . substr( $identity, 0, 32 ),
			'correlation_id'               => 'auto-submit:' . substr( hash( 'sha256', $item->assignment_id() . '|' . $item->lease_token() ), 0, 32 ),
			'idempotency_storage_key'      => 'submission:' . $identity,
			'occurred_at_utc'              => $now,
			'effective_received_at_utc'    => $item->closes_at_utc(),
			'expected_state'               => $candidate['state'],
			'expected_lock_version'        => $candidate['lock_version'],
			'expected_current_revision_id' => $candidate['current_revision_id'],
			'expected_schedule_version'    => $item->schedule_version(),
			'expected_schedule_hash'       => $item->schedule_hash(),
			'authorized_context_revision'  => $item->context_revision(),
		);
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
			throw new \RuntimeException( 'Auto-submit clock is invalid.' );
		}
		return $value;
	}

	/**
	 * Lease token.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lease_token(): string {
		$value = ( $this->lease_tokens )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $value ) ) {
			throw new \RuntimeException( 'Auto-submit lease token is invalid.' );
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
			throw new \RuntimeException( 'Auto-submit clock cannot be converted.' );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * New lease token.
	 *
	 * @return string
	 */
	private static function new_lease_token(): string {
		$hex = bin2hex( random_bytes( 16 ) );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}
}

<?php
/**
 * Run next submission evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Execution_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run next submission evaluation.
 */
final class Run_Next_Submission_Evaluation {
	public const ACTION_EXECUTE   = 'submission.evaluation.execute';
	public const LEASE_SECONDS    = 300;
	public const MAXIMUM_ATTEMPTS = 3;

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
	 * Initialize submission evaluation dependencies and state.
	 *
	 * @param Submission_Evaluation_Work_Repository_Port_Interface   $repository Persistence boundary used by this operation.
	 * @param Submission_Evaluation_Execution_Port_Interface         $execution Execution.
	 * @param Submission_Evaluation_Execution_Consent_Port_Interface $consent Consent.
	 * @param Submission_Evaluation_Source_Port_Interface            $sources Sources.
	 * @param Access_Policy_Port_Interface                           $policy Policy.
	 * @param Submission_Evaluation_Output_Normalizer                $normalizer Normalizer.
	 * @param Public_Id_Generator                                    $ids Ids.
	 * @param callable|null                                          $clock Clock.
	 * @param callable|null                                          $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Submission_Evaluation_Work_Repository_Port_Interface $repository,
		private Submission_Evaluation_Execution_Port_Interface $execution,
		private Submission_Evaluation_Execution_Consent_Port_Interface $consent,
		private Submission_Evaluation_Source_Port_Interface $sources,
		private Access_Policy_Port_Interface $policy,
		private Submission_Evaluation_Output_Normalizer $normalizer,
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
	 * @return Run_Submission_Evaluation_Result
	 */
	public function run_once(): Run_Submission_Evaluation_Result {
		$now           = $this->now();
		$lease_token   = ( $this->lease_tokens )();
		$lease_expires = $this->plus_seconds( $now, self::LEASE_SECONDS );
		$item          = $this->repository->claim_due( $now, $lease_token, $lease_expires );
		if ( null === $item ) {
			return new Run_Submission_Evaluation_Result( Run_Submission_Evaluation_Result::IDLE );
		}

		if ( $item->attempt_count() >= self::MAXIMUM_ATTEMPTS ) {
			return $this->fail( $item, '', 'worker_lost', null, null, null, null, false, $now );
		}
		if ( $this->deadline_exceeded( $item, $now ) ) {
			return $this->fail( $item, '', 'queue_deadline_exceeded', null, null, null, null, false, $now );
		}
		if ( ! $this->authorized( $item ) ) {
			return $this->fail( $item, '', 'context_denied', null, null, null, null, false, $now );
		}
		if ( ! $this->source_matches( $item ) ) {
			return $this->fail( $item, '', 'source_stale', null, null, null, null, false, $now );
		}
		if ( ! $this->consent->allows( $item ) ) {
			return $this->fail( $item, '', 'external_processing_not_approved', null, null, null, null, false, $now );
		}

		$preflight = $this->execution->snapshot( $item->task_key() );
		if ( null === $preflight || ! $this->same_assignment( $item->planned_routing(), $preflight ) ) {
			return $this->fail( $item, '', 'routing_stale', null, null, null, $preflight, false, $now );
		}
		$started = $this->repository->start_attempt( $item, $this->ids->create()->value(), $now, $lease_expires );
		if ( null === $started ) {
			return new Run_Submission_Evaluation_Result( Run_Submission_Evaluation_Result::STALE, $item->run_id() );
		}
		$item = $started;

		$gateway = $this->execution->execute( $item );
		if ( ! $gateway->is_successful() ) {
			$retry     = 'addon_ai_task_failed' === $gateway->error_code() && $gateway->retryable() && $item->attempt_no() < self::MAXIMUM_ATTEMPTS;
			$available = $retry ? $this->plus_seconds( $now, 1 === $item->attempt_no() ? 60 : 300 ) : $now;
			return $this->fail(
				$item,
				'gateway_failed',
				(string) $gateway->error_code(),
				$gateway->correlation_id(),
				null,
				$gateway->duration_ms(),
				null,
				$retry,
				$available
			);
		}

		try {
			$normalized = $this->normalizer->normalize( $gateway->output(), $item->input() );
		} catch ( Submission_Evaluation_Worker_Failure $failure ) {
			return $this->fail(
				$item,
				'output_invalid',
				$failure->reason(),
				$gateway->correlation_id(),
				null,
				$gateway->duration_ms(),
				$gateway->routing(),
				false,
				$now,
				$failure->context()
			);
		}

		$postflight     = $this->execution->snapshot( $item->task_key() );
		$result_routing = $gateway->routing();
		if (
			null === $result_routing || null === $postflight
			|| ! $this->same_assignment( $item->planned_routing(), $result_routing )
			|| ! $this->same_assignment( $item->planned_routing(), $postflight )
		) {
			return $this->fail(
				$item,
				'gateway_succeeded',
				'routing_changed_during_execute',
				$gateway->correlation_id(),
				$normalized->provider_output_digest(),
				$gateway->duration_ms(),
				$result_routing,
				false,
				$now
			);
		}
		if ( ! $this->authorized( $item ) ) {
			return $this->fail(
				$item,
				'gateway_succeeded',
				'context_denied',
				$gateway->correlation_id(),
				$normalized->provider_output_digest(),
				$gateway->duration_ms(),
				$result_routing,
				false,
				$now
			);
		}
		if ( ! $this->source_matches( $item ) ) {
			return $this->fail(
				$item,
				'gateway_succeeded',
				'source_stale',
				$gateway->correlation_id(),
				$normalized->provider_output_digest(),
				$gateway->duration_ms(),
				$result_routing,
				false,
				$now
			);
		}
		if ( ! $this->consent->allows( $item ) ) {
			return $this->fail(
				$item,
				'gateway_succeeded',
				'external_processing_not_approved',
				$gateway->correlation_id(),
				$normalized->provider_output_digest(),
				$gateway->duration_ms(),
				$result_routing,
				false,
				$now
			);
		}

		$committed = $this->repository->complete_success(
			$item,
			$gateway,
			$normalized,
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			$this->now()
		);
		return new Run_Submission_Evaluation_Result(
			$committed ? Run_Submission_Evaluation_Result::SUCCEEDED : Run_Submission_Evaluation_Result::STALE,
			$item->run_id()
		);
	}

	/**
	 * Authorized.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	private function authorized( Submission_Evaluation_Work_Item $item ): bool {
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
					new Context_Scope( $item->context_type(), $item->context_id(), Context_Scope::OPERATION_EVALUATE, null, $item->context_revision() ),
					new Object_Scope( Object_Scope::TYPE_SUBMISSION, $item->submission_id(), 'evaluate', (string) $item->source_lock_version(), $item->source_state() ),
					true,
					$this->job_correlation_id( $item )
				)
			);
			$object   = $decision->object();
			return $decision->is_allowed() && null !== $object
				&& 'context_staff' === $object->actor_relation()
				&& hash_equals( (string) $item->source_lock_version(), (string) $object->current_revision() )
				&& hash_equals( $item->source_state(), (string) $object->current_state() );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Source matches.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	private function source_matches( Submission_Evaluation_Work_Item $item ): bool {
		try {
			$source = $this->sources->find_official( $item->submission_id(), $item->revision_id() );
			return null !== $source
				&& $item->source_lock_version() === $source->lock_version()
				&& hash_equals( $item->submission_id(), $source->submission_id() )
				&& hash_equals( $item->revision_id(), $source->official_revision_id() )
				&& hash_equals( $item->source_hash(), $source->source_hash() )
				&& hash_equals( $item->source_state(), $source->state() )
				&& hash_equals( $item->publication_id(), $source->publication_id() )
				&& hash_equals( $item->publication_hash(), $source->publication_hash() )
				&& hash_equals( $item->context_type(), $source->context_type() )
				&& hash_equals( $item->context_id(), $source->context_id() )
				&& hash_equals( $item->context_revision(), $source->context_revision() );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Same assignment.
	 *
	 * @param Submission_Evaluation_Routing_Snapshot $expected Expected.
	 * @param Submission_Evaluation_Routing_Snapshot $actual Actual.
	 * @return bool
	 */
	private function same_assignment( Submission_Evaluation_Routing_Snapshot $expected, Submission_Evaluation_Routing_Snapshot $actual ): bool {
		return hash_equals( $expected->task_key(), $actual->task_key() )
			&& hash_equals( $expected->profile_fingerprint(), $actual->profile_fingerprint() )
			&& hash_equals( $expected->routing_revision(), $actual->routing_revision() );
	}

	/**
	 * Fail.
	 *
	 * @param Submission_Evaluation_Work_Item             $item Item.
	 * @param string                                      $attempt_state Attempt state.
	 * @param string                                      $error_code Error code.
	 * @param string|null                                 $gateway_correlation_id Gateway correlation id.
	 * @param string|null                                 $provider_output_digest Provider output digest.
	 * @param int|null                                    $duration_ms Duration ms.
	 * @param Submission_Evaluation_Routing_Snapshot|null $actual_routing Actual routing.
	 * @param bool                                        $retry Retry.
	 * @param string                                      $available_at_utc Available at utc.
	 * @param string|null                                 $error_message Error message.
	 * @return Run_Submission_Evaluation_Result
	 */
	private function fail(
		Submission_Evaluation_Work_Item $item,
		string $attempt_state,
		string $error_code,
		?string $gateway_correlation_id,
		?string $provider_output_digest,
		?int $duration_ms,
		?Submission_Evaluation_Routing_Snapshot $actual_routing,
		bool $retry,
		string $available_at_utc,
		?string $error_message = null
	): Run_Submission_Evaluation_Result {
		$committed = $this->repository->complete_failure(
			$item,
			$attempt_state,
			$error_code,
			$gateway_correlation_id,
			$provider_output_digest,
			$duration_ms,
			$actual_routing,
			$retry,
			$available_at_utc,
			$this->ids->create()->value(),
			$this->ids->create()->value(),
			$this->now(),
			$error_message
		);
		return new Run_Submission_Evaluation_Result(
			! $committed ? Run_Submission_Evaluation_Result::STALE : ( $retry ? Run_Submission_Evaluation_Result::RETRY_WAIT : Run_Submission_Evaluation_Result::FAILED ),
			$item->run_id()
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
	 * Deadline exceeded.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $now Clock value supplied for deterministic scheduling.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deadline_exceeded( Submission_Evaluation_Work_Item $item, string $now ): bool {
		$timezone = new DateTimeZone( 'UTC' );
		$queued   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $item->queued_at_utc(), $timezone );
		$current  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $now, $timezone );
		if ( false === $queued || false === $current ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $current->getTimestamp() - $queued->getTimestamp() > 86400;
	}

	/**
	 * Job correlation id.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return string
	 */
	private function job_correlation_id( Submission_Evaluation_Work_Item $item ): string {
		return 'worker:' . substr( hash( 'sha256', $item->run_id() . ':' . $item->lease_token() ), 0, 32 );
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

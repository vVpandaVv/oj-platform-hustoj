<?php
/**
 * Execute one queued special-note-draft run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Execution_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute one queued special-note-draft run.
 */
final class Run_Next_Student_Note_Draft {
	public const ACTION_EXECUTE   = 'submission.student_note.execute';
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
	 * Initialize student note draft dependencies and state.
	 *
	 * @param Student_Note_Work_Repository_Port_Interface   $work Work.
	 * @param Student_Note_Repository_Port_Interface        $sources Sources.
	 * @param Student_Note_Execution_Port_Interface         $execution Execution.
	 * @param Student_Note_Execution_Consent_Port_Interface $consent Consent.
	 * @param Access_Policy_Port_Interface                  $policy Policy.
	 * @param Student_Note_Input_Builder                    $inputs Inputs.
	 * @param Student_Note_Output_Normalizer                $normalizer Normalizer.
	 * @param Public_Id_Generator                           $ids Ids.
	 * @param callable|null                                 $clock Clock.
	 * @param callable|null                                 $lease_tokens Lease tokens.
	 */
	public function __construct(
		private Student_Note_Work_Repository_Port_Interface $work,
		private Student_Note_Repository_Port_Interface $sources,
		private Student_Note_Execution_Port_Interface $execution,
		private Student_Note_Execution_Consent_Port_Interface $consent,
		private Access_Policy_Port_Interface $policy,
		private Student_Note_Input_Builder $inputs,
		private Student_Note_Output_Normalizer $normalizer,
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
	 * @return Run_Student_Note_Draft_Result
	 */
	public function run_once(): Run_Student_Note_Draft_Result {
		$now     = $this->now();
		$token   = ( $this->lease_tokens )();
		$expires = $this->plus_seconds( $now, self::LEASE_SECONDS );
		$item    = $this->work->claim_due( $now, $token, $expires );
		if ( null === $item ) {
			return new Run_Student_Note_Draft_Result( Run_Student_Note_Draft_Result::IDLE );
		}
		$started_ns = hrtime( true );
		try {
			return $this->execute_claimed( $item, $now, $expires );
		} catch ( Throwable ) {
			// Unexpected exceptions may contain credentials or student content.
			$duration = max( 0, (int) round( ( hrtime( true ) - $started_ns ) / 1_000_000 ) );
			return $this->fail( $item, $item->attempt_no() > 0 ? 'gateway_failed' : '', 'worker_exception', $this->job_correlation_id( $item ), null, $duration, null, false, $now );
		}
	}

	/**
	 * Execute claimed.
	 *
	 * @param Student_Note_Work_Item &$item Item.
	 * @param string                 $now Clock value supplied for deterministic scheduling.
	 * @param string                 $expires Expires.
	 * @return Run_Student_Note_Draft_Result
	 */
	private function execute_claimed( Student_Note_Work_Item &$item, string $now, string $expires ): Run_Student_Note_Draft_Result {
		if ( $item->attempt_count() >= self::MAXIMUM_ATTEMPTS ) {
			return $this->fail( $item, '', 'worker_lost', null, null, null, null, false, $now );
		}
		if ( $this->deadline_exceeded( $item, $now ) ) {
			return $this->fail( $item, '', 'queue_deadline_exceeded', null, null, null, null, false, $now );
		}
		$prepared = $this->prepare( $item );
		if ( null === $prepared ) {
			return $this->fail( $item, '', Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, null, null, null, null, false, $now );
		}
		$item = $prepared;
		if ( ! $this->authorized( $item ) ) {
			return $this->fail( $item, '', 'context_denied', null, null, null, null, false, $now );
		}
		if ( ! $this->consent->allows( $item ) ) {
			return $this->fail( $item, '', 'external_processing_not_approved', null, null, null, null, false, $now );
		}
		$preflight = $this->execution->snapshot( $item->task_key() );
		if ( null === $preflight || ! $this->same_assignment( $item->planned_routing(), $preflight ) ) {
			return $this->fail( $item, '', 'routing_stale', null, null, null, $preflight, false, $now );
		}
		$started = $this->work->start_attempt( $item, $this->ids->create()->value(), $now, $expires );
		if ( null === $started ) {
			return new Run_Student_Note_Draft_Result( Run_Student_Note_Draft_Result::STALE, $item->run_id() );
		}
		$item    = $started;
		$gateway = $this->execution->execute( $item );
		if ( ! $gateway->is_successful() ) {
			$retry = 'addon_ai_task_failed' === $gateway->error_code() && $gateway->retryable() && $item->attempt_no() < self::MAXIMUM_ATTEMPTS;
			return $this->fail( $item, 'gateway_failed', (string) $gateway->error_code(), $gateway->correlation_id(), null, $gateway->duration_ms(), null, $retry, $retry ? $this->plus_seconds( $now, 1 === $item->attempt_no() ? 60 : 300 ) : $now );
		}
		try {
			$normalized = $this->normalizer->normalize( $gateway->output(), $item->input() ); } catch ( InvalidArgumentException $error ) {
			return $this->fail( $item, 'output_invalid', Submission_Evaluation_Failure::STUDENT_NOTE_SCHEMA_INVALID, $gateway->correlation_id(), null, $gateway->duration_ms(), $gateway->routing(), false, $now, Student_Note_Output_Normalizer::failure_message( $error ) );
			}
			$postflight = $this->execution->snapshot( $item->task_key() );
			$actual     = $gateway->routing();
			if ( null === $actual || null === $postflight || ! $this->same_assignment( $item->planned_routing(), $actual ) || ! $this->same_assignment( $item->planned_routing(), $postflight ) ) {
				return $this->fail( $item, 'gateway_succeeded', 'routing_changed_during_execute', $gateway->correlation_id(), $normalized->provider_output_digest(), $gateway->duration_ms(), $actual, false, $now );
			}
			$refreshed = $this->prepare( $item );
			if ( null === $refreshed || ! $this->authorized( $refreshed ) ) {
				return $this->fail( $item, 'gateway_succeeded', Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $gateway->correlation_id(), $normalized->provider_output_digest(), $gateway->duration_ms(), $actual, false, $now );
			}
			$item = $refreshed;
			if ( ! $this->consent->allows( $item ) ) {
				return $this->fail( $item, 'gateway_succeeded', 'external_processing_not_approved', $gateway->correlation_id(), $normalized->provider_output_digest(), $gateway->duration_ms(), $actual, false, $now );
			}
			$status = $this->work->complete_success( $item, $gateway, $normalized, $this->ids->create()->value(), $this->ids->create()->value(), $this->ids->create()->value(), $this->now() );
			return new Run_Student_Note_Draft_Result( Student_Note_Work_Repository_Port_Interface::COMMIT_SUCCEEDED === $status ? Run_Student_Note_Draft_Result::SUCCEEDED : ( Student_Note_Work_Repository_Port_Interface::COMMIT_SOURCE_STALE === $status ? Run_Student_Note_Draft_Result::FAILED : Run_Student_Note_Draft_Result::STALE ), $item->run_id() );
	}

	/**
	 * Prepare.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return Student_Note_Work_Item|null
	 */
	private function prepare( Student_Note_Work_Item $item ): ?Student_Note_Work_Item {
		try {
			$source = $this->sources->find_owned_source( $item->actor_user_id(), $item->evaluation_id() );
			if ( null === $source || 'reviewed' !== ( $source['evaluation_state'] ?? null ) ) {
				return null;
			}
			$input = $this->inputs->build( $source );
			if ( ! hash_equals( $item->input_digest(), $input->gateway_input_hash() ) ) {
				return null;
			}
			if ( $item->is_prepared() && ( ! hash_equals( $item->official_review_id(), $input->official_review_id() ) || ! hash_equals( $item->official_review_hash(), $input->official_review_hash() ) ) ) {
				return null;
			}
			return $item->prepared( $source, $input );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Authorized.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return bool
	 */
	private function authorized( Student_Note_Work_Item $item ): bool {
		try {
			$decision = $this->policy->authorize(
				new Access_Policy_Request(
					$item->actor_user_id(),
					Access_Policy_Request::SHELL_CONSOLE,
					Access_Policy_Request::SURFACE_JOB,
					self::ACTION_EXECUTE,
					array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
					new Context_Scope( $item->context_type(), $item->context_id(), Context_Scope::OPERATION_REPORT, null, $item->context_revision() ),
					new Object_Scope( Object_Scope::TYPE_EVALUATION, $item->evaluation_id(), 'report', (string) $item->evaluation_lock_version(), $item->evaluation_state() ),
					true,
					$this->job_correlation_id( $item )
				)
			);
			$object   = $decision->object();
			return $decision->is_allowed() && null !== $object && 'context_staff' === $object->actor_relation() && hash_equals( (string) $item->evaluation_lock_version(), (string) $object->current_revision() ) && hash_equals( $item->evaluation_state(), (string) $object->current_state() );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Fail.
	 *
	 * @param Student_Note_Work_Item                      $item Item.
	 * @param string                                      $attempt_state Attempt state.
	 * @param string                                      $code Code.
	 * @param string|null                                 $correlation Correlation.
	 * @param string|null                                 $digest Digest.
	 * @param int|null                                    $duration Duration.
	 * @param Submission_Evaluation_Routing_Snapshot|null $routing Routing.
	 * @param bool                                        $retry Retry.
	 * @param string                                      $available Available.
	 * @param string|null                                 $error_message Error message.
	 * @return Run_Student_Note_Draft_Result
	 */
	private function fail( Student_Note_Work_Item $item, string $attempt_state, string $code, ?string $correlation, ?string $digest, ?int $duration, ?Submission_Evaluation_Routing_Snapshot $routing, bool $retry, string $available, ?string $error_message = null ): Run_Student_Note_Draft_Result {
		$committed = $this->work->complete_failure( $item, $attempt_state, $code, $correlation, $digest, $duration, $routing, $retry, $available, $this->ids->create()->value(), $this->ids->create()->value(), $this->now(), $error_message );
		return new Run_Student_Note_Draft_Result( ! $committed ? Run_Student_Note_Draft_Result::STALE : ( $retry ? Run_Student_Note_Draft_Result::RETRY_WAIT : Run_Student_Note_Draft_Result::FAILED ), $item->run_id() );
	}

	/**
	 * Same assignment.
	 *
	 * @param Submission_Evaluation_Routing_Snapshot $expected Expected.
	 * @param Submission_Evaluation_Routing_Snapshot $actual Actual.
	 * @return bool
	 */
	private function same_assignment( Submission_Evaluation_Routing_Snapshot $expected, Submission_Evaluation_Routing_Snapshot $actual ): bool {
		return hash_equals( $expected->task_key(), $actual->task_key() ) && hash_equals( $expected->profile_fingerprint(), $actual->profile_fingerprint() ) && hash_equals( $expected->routing_revision(), $actual->routing_revision() );
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
			throw new InvalidArgumentException( 'Student note worker clock is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'Student note worker time is invalid.' );
		} return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
	/**
	 * Deadline exceeded.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @param string                 $now Clock value supplied for deterministic scheduling.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deadline_exceeded( Student_Note_Work_Item $item, string $now ): bool {
		$tz      = new DateTimeZone( 'UTC' );
		$queued  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $item->queued_at_utc(), $tz );
		$current = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $now, $tz );
		if ( false === $queued || false === $current ) {
			throw new InvalidArgumentException( 'Student note queue time is invalid.' );
		} return $current->getTimestamp() - $queued->getTimestamp() > 86400;
	}
	/**
	 * Job correlation id.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return string
	 */
	private function job_correlation_id( Student_Note_Work_Item $item ): string {
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
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}

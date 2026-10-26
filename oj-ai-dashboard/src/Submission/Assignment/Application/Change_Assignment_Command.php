<?php
/**
 * Assignment schedule and lifecycle command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the frozen C14-C18 command envelope before storage access.
 */
final class Change_Assignment_Command {
	public const UPDATE_SCHEDULE = 'update_schedule';
	public const OPEN            = 'open';
	public const CLOSE           = 'close';
	public const REOPEN          = 'reopen';
	public const WITHDRAW        = 'withdraw';

	/**
	 * Schedule.
	 *
	 * @var array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}|null
	 */
	private ?array $schedule;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize change assignment command dependencies and state.
	 *
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $assignment_id Distribution public identifier.
	 * @param string                   $action Action.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $expected_state Expected state.
	 * @param int                      $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param int                      $expected_schedule_version Expected schedule version.
	 * @param string                   $expected_schedule_hash Expected schedule hash.
	 * @param array<string,mixed>|null $schedule Schedule.
	 * @param string                   $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $assignment_id,
		private string $action,
		private string $command_id,
		private string $expected_state,
		private int $expected_lock_version,
		private int $expected_schedule_version,
		private string $expected_schedule_hash,
		?array $schedule,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Assignment command actor is invalid.' );
		}
		new Public_Id( $assignment_id );
		$this->safe_command_token( $command_id, 'command_id' );
		$this->safe_command_token( $correlation_id, 'correlation_id' );
		$this->hash( $expected_schedule_hash, 'expected_schedule_hash' );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		if ( $expected_lock_version < 0 || $expected_schedule_version < 1 ) {
			throw new InvalidArgumentException( 'Assignment expected versions are invalid.' );
		}
		$this->assert_transition( $action, $expected_state, $schedule );
		$this->schedule       = null === $schedule ? null : $this->normalize_schedule( $schedule );
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'action'        => $action,
					'assignment_id' => $assignment_id,
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_lock_version'     => $expected_lock_version,
						'expected_schedule_hash'    => $expected_schedule_hash,
						'expected_schedule_version' => $expected_schedule_version,
						'expected_state'            => $expected_state,
					),
					'payload'       => null === $this->schedule ? array() : array( 'schedule' => $this->schedule ),
				)
			)
		);
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}
	/**
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
	}
	/**
	 * Action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}
	/**
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
	}
	/**
	 * Expected lock version.
	 *
	 * @return int
	 */
	public function expected_lock_version(): int {
		return $this->expected_lock_version;
	}
	/**
	 * Expected schedule version.
	 *
	 * @return int
	 */
	public function expected_schedule_version(): int {
		return $this->expected_schedule_version;
	}
	/**
	 * Expected schedule hash.
	 *
	 * @return string
	 */
	public function expected_schedule_hash(): string {
		return $this->expected_schedule_hash;
	}
	/**
	 * Schedule.
	 *
	 * @return array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}|null
	 */
	public function schedule(): ?array {
		return $this->schedule;
	}
	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
	/**
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}
	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'assignment:' . hash( 'sha256', $this->actor_user_id . '|assignment.' . $this->action . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Reject invalid transition.
	 *
	 * @param string                   $action Action.
	 * @param string                   $state State.
	 * @param array<string,mixed>|null $schedule Schedule.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_transition( string $action, string $state, ?array $schedule ): void {
		$allowed = array(
			self::UPDATE_SCHEDULE => array( 'draft', 'scheduled', 'open' ),
			self::OPEN            => array( 'draft', 'scheduled' ),
			self::CLOSE           => array( 'open' ),
			self::REOPEN          => array( 'closed' ),
			self::WITHDRAW        => array( 'draft', 'scheduled', 'open', 'closed' ),
		);
		if ( ! isset( $allowed[ $action ] ) || ! in_array( $state, $allowed[ $action ], true ) ) {
			throw new InvalidArgumentException( 'Assignment lifecycle transition is invalid.' );
		}
		$requires_schedule = in_array( $action, array( self::UPDATE_SCHEDULE, self::REOPEN ), true );
		if ( $requires_schedule !== is_array( $schedule ) ) {
			throw new InvalidArgumentException( 'Assignment command schedule presence is invalid.' );
		}
	}

	/**
	 * Normalize schedule.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_schedule( array $schedule ): array {
		$this->exact_keys( $schedule, array( 'auto_submit_enabled', 'closes_at_utc', 'due_at_utc', 'opens_at_utc' ) );
		$opens  = $this->utc( $schedule['opens_at_utc'] ?? null, true, 'opens_at_utc' );
		$due    = $this->utc( $schedule['due_at_utc'] ?? null, false, 'due_at_utc' );
		$closes = $this->utc( $schedule['closes_at_utc'] ?? null, false, 'closes_at_utc' );
		if ( ! is_bool( $schedule['auto_submit_enabled'] ?? null ) || ( null !== $opens && $opens > $due ) || $due > $closes ) {
			throw new InvalidArgumentException( 'Assignment command schedule is invalid.' );
		}
		return array(
			'opens_at_utc'        => $opens,
			'due_at_utc'          => $due,
			'closes_at_utc'       => $closes,
			'auto_submit_enabled' => $schedule['auto_submit_enabled'],
		);
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assignment command section must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Assignment command section keys are invalid.' );
		}
	}

	/**
	 * Utc.
	 *
	 * @param mixed  $value Value.
	 * @param bool   $nullable Nullable.
	 * @param string $field Field.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( mixed $value, bool $nullable, string $field ): ?string {
		if ( null === $value && $nullable ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment command ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			throw new InvalidArgumentException( 'Assignment command ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Safe command token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_command_token( string $value, string $field ): void {
		if ( strlen( $value ) < 8 || strlen( $value ) > 128 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment command ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value, string $field ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment command ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}

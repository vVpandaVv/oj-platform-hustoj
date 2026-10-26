<?php
/**
 * CreateAssignment command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the frozen C13 command before any roster read.
 */
final class Create_Assignment_Command {
	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Command identifier used for idempotency.
	 *
	 * @var string
	 */
	private string $command_id;
	/**
	 * Publication id.
	 *
	 * @var string
	 */
	private string $publication_id;
	/**
	 * Publication hash.
	 *
	 * @var string
	 */
	private string $publication_hash;
	/**
	 * Context type.
	 *
	 * @var string
	 */
	private string $context_type;
	/**
	 * Context id.
	 *
	 * @var string
	 */
	private string $context_id;
	/**
	 * Context revision.
	 *
	 * @var string
	 */
	private string $context_revision;
	/**
	 * Schedule.
	 *
	 * @var array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}
	 */
	private array $schedule;
	/**
	 * Resubmission policy.
	 *
	 * @var array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool}
	 */
	private array $resubmission_policy;
	/**
	 * Fingerprint scoped to the actor and command.
	 *
	 * @var string
	 */
	private string $idempotency_fingerprint;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize assignment command dependencies and state.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $publication_id Publication id.
	 * @param string              $publication_hash Publication hash.
	 * @param string              $context_type Context type.
	 * @param string              $context_id Context id.
	 * @param string              $context_revision Context revision.
	 * @param array<string,mixed> $schedule Schedule.
	 * @param array<string,mixed> $resubmission_policy Resubmission policy.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $command_id,
		string $publication_id,
		string $publication_hash,
		string $context_type,
		string $context_id,
		string $context_revision,
		array $schedule,
		array $resubmission_policy,
		string $idempotency_fingerprint,
		string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'CreateAssignment actor is invalid.' );
		}
		$this->safe_command_token( $command_id, 'command_id' );
		new Public_Id( $publication_id );
		$this->hash( $publication_hash, 'publication_hash' );
		if ( ! in_array( $context_type, array( Context_Scope::TYPE_CLASS, Context_Scope::TYPE_GROUP ), true ) ) {
			throw new InvalidArgumentException( 'CreateAssignment Context type is invalid.' );
		}
		$this->safe_token( $context_id, 'context_id' );
		$this->safe_token( $context_revision, 'context_revision' );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		$this->safe_command_token( $correlation_id, 'correlation_id' );

		$this->exact_keys( $schedule, array( 'auto_submit_enabled', 'closes_at_utc', 'due_at_utc', 'opens_at_utc' ) );
		$opens  = $this->utc( $schedule['opens_at_utc'] ?? null, true, 'opens_at_utc' );
		$due    = $this->utc( $schedule['due_at_utc'] ?? null, false, 'due_at_utc' );
		$closes = $this->utc( $schedule['closes_at_utc'] ?? null, false, 'closes_at_utc' );
		if ( ! is_bool( $schedule['auto_submit_enabled'] ?? null ) ) {
			throw new InvalidArgumentException( 'CreateAssignment auto-submit flag is invalid.' );
		}
		if ( ( null !== $opens && $opens > $due ) || $due > $closes ) {
			throw new InvalidArgumentException( 'CreateAssignment schedule order is invalid.' );
		}

		$this->exact_keys( $resubmission_policy, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$max_attempts  = $resubmission_policy['max_attempts'] ?? null;
		$copy_previous = $resubmission_policy['copy_previous_answers'] ?? null;
		$reopen_until  = $this->utc( $resubmission_policy['reopen_until_utc'] ?? null, true, 'reopen_until_utc' );
		if ( ! is_int( $max_attempts ) || $max_attempts < 1 || $max_attempts > 10 || ! is_bool( $copy_previous ) ) {
			throw new InvalidArgumentException( 'CreateAssignment resubmission policy is invalid.' );
		}
		if ( null !== $reopen_until && $reopen_until > $closes ) {
			throw new InvalidArgumentException( 'CreateAssignment reopen time exceeds closing time.' );
		}

		$this->actor_user_id           = $actor_user_id;
		$this->command_id              = $command_id;
		$this->publication_id          = $publication_id;
		$this->publication_hash        = $publication_hash;
		$this->context_type            = $context_type;
		$this->context_id              = $context_id;
		$this->context_revision        = $context_revision;
		$this->schedule                = array(
			'opens_at_utc'        => $opens,
			'due_at_utc'          => $due,
			'closes_at_utc'       => $closes,
			'auto_submit_enabled' => $schedule['auto_submit_enabled'],
		);
		$this->resubmission_policy     = array(
			'max_attempts'          => $max_attempts,
			'reopen_until_utc'      => $reopen_until,
			'copy_previous_answers' => $copy_previous,
		);
		$this->idempotency_fingerprint = $idempotency_fingerprint;
		$this->correlation_id          = $correlation_id;
		$this->request_digest          = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_context_revision' => $context_revision,
						'expected_publication_hash' => $publication_hash,
					),
					'payload'    => array(
						'context'                  => array(
							'id'   => $context_id,
							'type' => $context_type,
						),
						'resubmission_policy'      => $this->resubmission_policy,
						'schedule'                 => $this->schedule,
						'worksheet_publication_id' => $publication_id,
					),
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
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Publication id.
	 *
	 * @return string
	 */
	public function publication_id(): string {
		return $this->publication_id;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
	}
	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Schedule.
	 *
	 * @return array{opens_at_utc:?string,due_at_utc:string,closes_at_utc:string,auto_submit_enabled:bool}
	 */
	public function schedule(): array {
		return $this->schedule;
	}
	/**
	 * Resubmission policy.
	 *
	 * @return array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool}
	 */
	public function resubmission_policy(): array {
		return $this->resubmission_policy;
	}
	/**
	 * Initial state.
	 *
	 * @return string
	 */
	public function initial_state(): string {
		return null === $this->schedule['opens_at_utc'] ? 'draft' : 'scheduled';
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
		return 'assignment:' . hash( 'sha256', $this->actor_user_id . '|assignment.create|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'CreateAssignment section must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'CreateAssignment section keys are invalid.' );
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
			throw new InvalidArgumentException( 'CreateAssignment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			throw new InvalidArgumentException( 'CreateAssignment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'CreateAssignment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
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
			throw new InvalidArgumentException( 'CreateAssignment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
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
			throw new InvalidArgumentException( 'CreateAssignment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}

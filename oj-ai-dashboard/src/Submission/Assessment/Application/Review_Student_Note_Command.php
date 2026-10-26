<?php
/**
 * C39 exact student-note review command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C39 exact student-note review command.
 */
final class Review_Student_Note_Command {
	/**
	 * Note.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $note;
	/**
	 * Reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize review student note command dependencies and state.
	 *
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $note_id Note id.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $expected_official_review_id Expected official review id.
	 * @param string                   $expected_official_review_hash Expected official review hash.
	 * @param string|null              $expected_revision_id Revision the caller read before this operation.
	 * @param string|null              $expected_revision_hash Expected revision hash.
	 * @param int                      $expected_revision_no Expected revision no.
	 * @param string|null              $expected_state Expected state.
	 * @param bool                     $expected_include_in_reports Expected include in reports.
	 * @param string                   $decision Decision.
	 * @param string                   $source_mode Source mode.
	 * @param array<string,mixed>|null $note Note.
	 * @param string|null              $reason Reason.
	 * @param string                   $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param string                   $context_type Context type.
	 * @param string                   $context_id Context id.
	 * @param string                   $context_revision Context revision.
	 * @param string                   $authorized_revision Authorized revision.
	 * @param string                   $authorized_state Authorized state.
	 * @param string                   $actor_relation Actor relation.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $note_id,
		private string $command_id,
		private string $expected_official_review_id,
		private string $expected_official_review_hash,
		private ?string $expected_revision_id,
		private ?string $expected_revision_hash,
		private int $expected_revision_no,
		private ?string $expected_state,
		private bool $expected_include_in_reports,
		private string $decision,
		private string $source_mode,
		?array $note,
		?string $reason,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_revision_no < 0 ) {
			throw new InvalidArgumentException( 'ReviewStudentNote command identity is invalid.' );
		}
		new Public_Id( $note_id );
		new Public_Id( $expected_official_review_id );
		$this->hash_value( $expected_official_review_hash );
		$this->revision_pointer( $expected_revision_id, $expected_revision_hash, $expected_revision_no, $expected_state );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $context_id, 'context_id', 1, 191, true );
		$this->safe_token( $context_revision, 'context_revision', 1, 191, true );
		if (
			! in_array( $decision, array( 'teacher_draft', 'rejected' ), true )
			|| ! in_array( $source_mode, array( 'direct', 'edit' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'ReviewStudentNote command evidence is invalid.' );
		}

		$this->note   = null === $note ? null : $this->normalize_note( $note );
		$this->reason = $this->normalize_reason( $reason );
		if (
			( 'teacher_draft' === $decision && ( null === $this->note || null !== $this->reason ) )
			|| ( 'edit' === $source_mode && null === $expected_revision_id )
			|| ( 'rejected' === $decision && ( 'edit' !== $source_mode || null !== $this->note || null === $this->reason ) )
			|| ( null === $expected_revision_id && ( $expected_include_in_reports || 'teacher_draft' !== $decision ) )
		) {
			throw new InvalidArgumentException( 'ReviewStudentNote decision payload is invalid.' );
		}
		if ( null === $expected_revision_id ) {
			if ( 'reviewed' !== $authorized_state || ! hash_equals( $expected_official_review_hash, $authorized_revision ) ) {
				throw new InvalidArgumentException( 'ReviewStudentNote initial authorization is stale.' );
			}
		} elseif ( ! hash_equals( (string) $expected_revision_hash, $authorized_revision ) || $expected_state !== $authorized_state ) {
			throw new InvalidArgumentException( 'ReviewStudentNote revision authorization is stale.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'note_id'    => $note_id,
					'expected'   => array(
						'expected_include_in_reports'   => $expected_include_in_reports,
						'expected_official_review_hash' => $expected_official_review_hash,
						'expected_official_review_id'   => $expected_official_review_id,
						'expected_revision_hash'        => $expected_revision_hash,
						'expected_revision_id'          => $expected_revision_id,
						'expected_revision_no'          => $expected_revision_no,
						'expected_state'                => $expected_state,
					),
					'payload'    => array(
						'decision'    => $decision,
						'note'        => $this->note,
						'reason'      => $this->reason,
						'source_mode' => $source_mode,
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
	 * Note id.
	 *
	 * @return string
	 */
	public function note_id(): string {
		return $this->note_id;
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
	 * Expected official review id.
	 *
	 * @return string
	 */
	public function expected_official_review_id(): string {
		return $this->expected_official_review_id;
	}
	/**
	 * Expected official review hash.
	 *
	 * @return string
	 */
	public function expected_official_review_hash(): string {
		return $this->expected_official_review_hash;
	}
	/**
	 * Expected revision id.
	 *
	 * @return string|null
	 */
	public function expected_revision_id(): ?string {
		return $this->expected_revision_id;
	}
	/**
	 * Expected revision hash.
	 *
	 * @return string|null
	 */
	public function expected_revision_hash(): ?string {
		return $this->expected_revision_hash;
	}
	/**
	 * Expected revision no.
	 *
	 * @return int
	 */
	public function expected_revision_no(): int {
		return $this->expected_revision_no;
	}
	/**
	 * Expected state.
	 *
	 * @return string|null
	 */
	public function expected_state(): ?string {
		return $this->expected_state;
	}
	/**
	 * Expected include in reports.
	 *
	 * @return bool
	 */
	public function expected_include_in_reports(): bool {
		return $this->expected_include_in_reports;
	}
	/**
	 * Decision.
	 *
	 * @return string
	 */
	public function decision(): string {
		return $this->decision;
	}
	/**
	 * Source mode.
	 *
	 * @return string
	 */
	public function source_mode(): string {
		return $this->source_mode;
	}
	/**
	 * Note.
	 *
	 * @return array<string,mixed>|null
	 */
	public function note(): ?array {
		return $this->note;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
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
	 * Authorized revision.
	 *
	 * @return string
	 */
	public function authorized_revision(): string {
		return $this->authorized_revision;
	}
	/**
	 * Authorized state.
	 *
	 * @return string
	 */
	public function authorized_state(): string {
		return $this->authorized_state;
	}
	/**
	 * Actor relation.
	 *
	 * @return string
	 */
	public function actor_relation(): string {
		return $this->actor_relation;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|student_note.review|' . $this->note_id . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Normalize note.
	 *
	 * @param array<array-key,mixed> $note Note.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_note( array $note ): array {
		if ( array_is_list( $note ) ) {
			throw new InvalidArgumentException( 'ReviewStudentNote note must be an object.' );
		}
		$this->exact_keys( $note, array( 'evidence', 'strengths', 'summary', 'support_suggestions' ) );
		if (
			! is_array( $note['evidence'] ?? null ) || ! array_is_list( $note['evidence'] )
			|| ! is_array( $note['strengths'] ?? null ) || ! array_is_list( $note['strengths'] )
			|| ! is_array( $note['summary'] ?? null ) || array_is_list( $note['summary'] )
			|| ! is_array( $note['support_suggestions'] ?? null ) || ! array_is_list( $note['support_suggestions'] )
		) {
			throw new InvalidArgumentException( 'ReviewStudentNote note structure is invalid.' );
		}
		return $note;
	}

	/**
	 * Normalize reason.
	 *
	 * @param string|null $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_reason( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || $this->length( $value ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'ReviewStudentNote reason is invalid.' );
		}
		return $value;
	}

	/**
	 * Revision pointer.
	 *
	 * @param string|null $id Id.
	 * @param string|null $hash Hash.
	 * @param int         $revision_no Revision no.
	 * @param string|null $state State.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_pointer( ?string $id, ?string $hash, int $revision_no, ?string $state ): void {
		if ( ( null === $id ) !== ( null === $hash ) || ( null === $id ) !== ( 0 === $revision_no ) || ( null === $id ) !== ( null === $state ) ) {
			throw new InvalidArgumentException( 'ReviewStudentNote source pointer is partial.' );
		}
		if ( null !== $id ) {
			new Public_Id( $id );
			$this->hash_value( (string) $hash );
			if ( ! in_array( $state, array( 'ai_draft', 'teacher_draft', 'approved', 'rejected', 'superseded' ), true ) ) {
				throw new InvalidArgumentException( 'ReviewStudentNote source state is invalid.' );
			}
		}
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'ReviewStudentNote hash is invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @param bool   $slash Slash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum, bool $slash = false ): void {
		$pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'ReviewStudentNote ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'ReviewStudentNote object shape is invalid.' );
		}
	}
}

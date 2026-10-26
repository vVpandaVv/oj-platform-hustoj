<?php
/**
 * C41/C42 exact student-note report inclusion command.
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
 * C41/C42 exact student-note report inclusion command.
 */
final class Change_Student_Note_Report_Inclusion_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize change student note report inclusion command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $note_id Note id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_official_review_id Expected official review id.
	 * @param string         $expected_official_review_hash Expected official review hash.
	 * @param string         $expected_revision_id Revision the caller read before this operation.
	 * @param string         $expected_revision_hash Expected revision hash.
	 * @param int            $expected_revision_no Expected revision no.
	 * @param string         $expected_state Expected state.
	 * @param bool           $expected_include_in_reports Expected include in reports.
	 * @param bool           $target_include_in_reports Target include in reports.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $note_id,
		private string $command_id,
		private string $expected_official_review_id,
		private string $expected_official_review_hash,
		private string $expected_revision_id,
		private string $expected_revision_hash,
		private int $expected_revision_no,
		private string $expected_state,
		private bool $expected_include_in_reports,
		private bool $target_include_in_reports,
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
		if ( $actor_user_id < 1 || $expected_revision_no < 1 ) {
			throw new InvalidArgumentException( 'ChangeStudentNoteReportInclusion command identity is invalid.' );
		}
		foreach ( array( $note_id, $expected_official_review_id, $expected_revision_id ) as $id ) {
			new Public_Id( $id );
		}
		$this->hash_value( $expected_official_review_hash );
		$this->hash_value( $expected_revision_hash );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $context_id, 'context_id', 1, 191, true );
		$this->safe_token( $context_revision, 'context_revision', 1, 191, true );
		if (
			'approved' !== $expected_state || $expected_include_in_reports === $target_include_in_reports
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! hash_equals( $expected_revision_hash, $authorized_revision )
			|| ! hash_equals( $expected_state, $authorized_state )
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'ChangeStudentNoteReportInclusion command evidence is invalid.' );
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
						'expected_state'                => 'approved',
					),
					'payload'    => array(),
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
	 * @return string
	 */
	public function expected_revision_id(): string {
		return $this->expected_revision_id;
	}
	/**
	 * Expected revision hash.
	 *
	 * @return string
	 */
	public function expected_revision_hash(): string {
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
	 * @return string
	 */
	public function expected_state(): string {
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
	 * Target include in reports.
	 *
	 * @return bool
	 */
	public function target_include_in_reports(): bool {
		return $this->target_include_in_reports;
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
		$action = $this->target_include_in_reports ? 'student_note.include_in_reports' : 'student_note.exclude_from_reports';
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|' . $action . '|' . $this->note_id . '|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'ChangeStudentNoteReportInclusion hash is invalid.' );
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
			throw new InvalidArgumentException( 'ChangeStudentNoteReportInclusion ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		}
	} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
}

<?php
/**
 * Enqueue own submission pdf command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C67 request plus consumed self-authorization evidence.
 */
final class Enqueue_Own_Submission_Pdf_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue own submission pdf command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $submission_id Submission public identifier.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_assignment_id Expected assignment id.
	 * @param string         $expected_assignment_state Expected assignment state.
	 * @param string         $expected_official_revision_id Expected official revision id.
	 * @param string         $expected_official_revision_hash Expected official revision hash.
	 * @param string         $expected_attachment_set_hash Expected attachment set hash.
	 * @param string         $expected_publication_id Expected publication id.
	 * @param string         $expected_publication_hash Expected publication hash.
	 * @param int            $expected_submission_lock_version Expected submission lock version.
	 * @param string         $expected_submission_state Expected submission state.
	 * @param string         $expected_context_revision Expected context revision.
	 * @param string         $expected_target_state Expected target state.
	 * @param string         $profile Profile.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_submission_revision Authorized submission revision.
	 * @param string         $authorized_submission_state Authorized submission state.
	 * @param string         $authorized_actor_relation Authorized actor relation.
	 * @param string         $authorized_context_type Authorized context type.
	 * @param string         $authorized_context_id Authorized context id.
	 * @param string         $authorized_context_revision Authorized context revision.
	 * @param string         $authorized_context_relation Authorized context relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $submission_id,
		private string $command_id,
		private string $expected_assignment_id,
		private string $expected_assignment_state,
		private string $expected_official_revision_id,
		private string $expected_official_revision_hash,
		private string $expected_attachment_set_hash,
		private string $expected_publication_id,
		private string $expected_publication_hash,
		private int $expected_submission_lock_version,
		private string $expected_submission_state,
		private string $expected_context_revision,
		private string $expected_target_state,
		private string $profile,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_submission_revision,
		private string $authorized_submission_state,
		private string $authorized_actor_relation,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $authorized_context_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_submission_lock_version < 0 ) {
			throw new InvalidArgumentException( 'Own submission PDF actor or lock version is invalid.' );
		}
		foreach ( array( $submission_id, $expected_assignment_id, $expected_official_revision_id, $expected_publication_id ) as $id ) {
			new Public_Id( $id );
		}
		$this->token( $command_id, 8, 128 );
		$this->token( $correlation_id, 1, 100 );
		$this->context_token( $expected_context_revision );
		$this->context_token( $authorized_context_id );
		$this->context_token( $authorized_context_revision );
		$this->context_token( $authorized_context_relation, 80 );
		foreach ( array( $expected_official_revision_hash, $expected_attachment_set_hash, $expected_publication_hash, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Own submission PDF hash evidence is invalid.' );
			}
		}
		if (
			! in_array( $expected_assignment_state, array( 'open', 'closed' ), true )
			|| ! in_array( $expected_submission_state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
			|| (string) $expected_submission_lock_version !== $authorized_submission_revision
			|| ! hash_equals( $expected_submission_state, $authorized_submission_state )
			|| 'self_submission' !== $authorized_actor_relation
			|| ! in_array( $authorized_context_type, array( 'class', 'group' ), true )
			|| ! hash_equals( $expected_context_revision, $authorized_context_revision )
			|| 'active' !== $expected_target_state
			|| Build_Submission_Print_Projection::PROFILE !== $profile
		) {
			throw new InvalidArgumentException( 'Own submission PDF command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_assignment_id'           => $expected_assignment_id,
						'expected_assignment_state'        => $expected_assignment_state,
						'expected_attachment_set_hash'     => $expected_attachment_set_hash,
						'expected_context_revision'        => $expected_context_revision,
						'expected_official_revision_hash'  => $expected_official_revision_hash,
						'expected_official_revision_id'    => $expected_official_revision_id,
						'expected_publication_hash'        => $expected_publication_hash,
						'expected_publication_id'          => $expected_publication_id,
						'expected_submission_lock_version' => $expected_submission_lock_version,
						'expected_submission_state'        => $expected_submission_state,
						'expected_target_state'            => $expected_target_state,
					),
					'payload'       => array( 'profile' => $profile ),
					'submission_id' => $submission_id,
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
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
	 * Expected assignment id.
	 *
	 * @return string
	 */
	public function expected_assignment_id(): string {
		return $this->expected_assignment_id;
	}
	/**
	 * Expected assignment state.
	 *
	 * @return string
	 */
	public function expected_assignment_state(): string {
		return $this->expected_assignment_state;
	}
	/**
	 * Expected official revision id.
	 *
	 * @return string
	 */
	public function expected_official_revision_id(): string {
		return $this->expected_official_revision_id;
	}
	/**
	 * Expected official revision hash.
	 *
	 * @return string
	 */
	public function expected_official_revision_hash(): string {
		return $this->expected_official_revision_hash;
	}
	/**
	 * Expected attachment set hash.
	 *
	 * @return string
	 */
	public function expected_attachment_set_hash(): string {
		return $this->expected_attachment_set_hash;
	}
	/**
	 * Expected publication id.
	 *
	 * @return string
	 */
	public function expected_publication_id(): string {
		return $this->expected_publication_id;
	}
	/**
	 * Expected publication hash.
	 *
	 * @return string
	 */
	public function expected_publication_hash(): string {
		return $this->expected_publication_hash;
	}
	/**
	 * Expected submission lock version.
	 *
	 * @return int
	 */
	public function expected_submission_lock_version(): int {
		return $this->expected_submission_lock_version;
	}
	/**
	 * Expected submission state.
	 *
	 * @return string
	 */
	public function expected_submission_state(): string {
		return $this->expected_submission_state;
	}
	/**
	 * Expected context revision.
	 *
	 * @return string
	 */
	public function expected_context_revision(): string {
		return $this->expected_context_revision;
	}
	/**
	 * Expected target state.
	 *
	 * @return string
	 */
	public function expected_target_state(): string {
		return $this->expected_target_state;
	}
	/**
	 * Profile.
	 *
	 * @return string
	 */
	public function profile(): string {
		return $this->profile;
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
		return $this->authorized_context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->authorized_context_id;
	}
	/**
	 * Context relation.
	 *
	 * @return string
	 */
	public function context_relation(): string {
		return $this->authorized_context_relation;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|own.submission.pdf.enqueue|' . $this->submission_id . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( string $value, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Own submission PDF command token is invalid.' );
		}
	}

	/**
	 * Context token.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( string $value, int $maximum = 191 ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Own submission PDF Context token is invalid.' );
		}
	}
}

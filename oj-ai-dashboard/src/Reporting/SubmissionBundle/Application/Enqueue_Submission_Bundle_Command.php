<?php
/**
 * Enqueue submission bundle command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen C50 command plus consumed Context and assignment authorization evidence.
 */
final class Enqueue_Submission_Bundle_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue submission bundle command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $assignment_id Distribution public identifier.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_assignment_id Expected assignment id.
	 * @param string         $expected_assignment_hash Expected assignment hash.
	 * @param int            $expected_schedule_version Expected schedule version.
	 * @param string         $expected_assignment_state Expected assignment state.
	 * @param string         $locale Locale.
	 * @param string         $profile Profile.
	 * @param bool           $include_allowed_originals Include allowed originals.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_assignment_revision Authorized assignment revision.
	 * @param string         $authorized_assignment_state Authorized assignment state.
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
		private string $assignment_id,
		private string $command_id,
		private string $expected_assignment_id,
		private string $expected_assignment_hash,
		private int $expected_schedule_version,
		private string $expected_assignment_state,
		private string $locale,
		private string $profile,
		private bool $include_allowed_originals,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_assignment_revision,
		private string $authorized_assignment_state,
		private string $authorized_actor_relation,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $authorized_context_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Submission bundle actor is invalid.' );
		}
		new Public_Id( $assignment_id );
		new Public_Id( $expected_assignment_id );
		$this->token( $command_id, 8, 128 );
		$this->token( $correlation_id, 1, 100 );
		$this->context_token( $authorized_context_id );
		$this->context_token( $authorized_context_revision );
		$this->context_token( $authorized_context_relation, 80 );
		$this->context_token( $authorized_actor_relation, 80 );
		foreach ( array( $expected_assignment_hash, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Submission bundle hash evidence is invalid.' );
			}
		}
		if (
			$assignment_id !== $expected_assignment_id
			|| $expected_schedule_version < 1
			|| (string) $expected_schedule_version !== $authorized_assignment_revision
			|| $expected_assignment_state !== $authorized_assignment_state
			|| ! in_array( $expected_assignment_state, array( 'open', 'closed' ), true )
			|| 'context_staff' !== $authorized_actor_relation
			|| ! in_array( $authorized_context_type, array( 'class', 'group' ), true )
			|| Submission_Bundle_Request_Snapshot::PROFILE !== $profile
			|| 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale )
		) {
			throw new InvalidArgumentException( 'Submission bundle command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'assignment_id' => $assignment_id,
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_assignment_hash'  => $expected_assignment_hash,
						'expected_assignment_id'    => $expected_assignment_id,
						'expected_assignment_state' => $expected_assignment_state,
						'expected_schedule_version' => $expected_schedule_version,
					),
					'payload'       => array(
						'include_allowed_originals' => $include_allowed_originals,
						'locale'                    => $locale,
						'profile'                   => $profile,
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
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
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
	 * Expected assignment hash.
	 *
	 * @return string
	 */
	public function expected_assignment_hash(): string {
		return $this->expected_assignment_hash;
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
	 * Expected assignment state.
	 *
	 * @return string
	 */
	public function expected_assignment_state(): string {
		return $this->expected_assignment_state;
	}
	/**
	 * Locale.
	 *
	 * @return string
	 */
	public function locale(): string {
		return $this->locale;
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
	 * Include allowed originals.
	 *
	 * @return bool
	 */
	public function include_allowed_originals(): bool {
		return $this->include_allowed_originals;
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
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->authorized_context_revision;
	}

	/**
	 * Context.
	 *
	 * @return array{type:string,id:string,revision:string}
	 */
	public function context(): array {
		return array(
			'type'     => $this->context_type(),
			'id'       => $this->context_id(),
			'revision' => $this->context_revision(),
		);
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|submission.bundle.enqueue|' . $this->assignment_id . '|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'Submission bundle command token is invalid.' );
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
			throw new InvalidArgumentException( 'Submission bundle Context token is invalid.' );
		}
	}
}

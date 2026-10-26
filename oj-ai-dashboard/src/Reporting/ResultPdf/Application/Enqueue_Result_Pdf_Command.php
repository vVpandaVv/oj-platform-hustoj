<?php
/**
 * Enqueue result pdf command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen C52 request plus consumed PublicUI authorization evidence.
 */
final class Enqueue_Result_Pdf_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue result pdf command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $release_id Release id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_release_hash Expected release hash.
	 * @param string         $expected_release_state Expected release state.
	 * @param string         $locale Locale.
	 * @param string         $profile Profile.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_release_revision Authorized release revision.
	 * @param string         $authorized_release_state Authorized release state.
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
		private string $release_id,
		private string $command_id,
		private string $expected_release_hash,
		private string $expected_release_state,
		private string $locale,
		private string $profile,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_release_revision,
		private string $authorized_release_state,
		private string $authorized_actor_relation,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $authorized_context_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Result PDF actor is invalid.' );
		}
		new Public_Id( $release_id );
		$this->token( $command_id, 8, 128 );
		$this->token( $correlation_id, 1, 100 );
		$this->context_token( $authorized_context_id );
		$this->context_token( $authorized_context_revision );
		$this->context_token( $authorized_context_relation, 80 );
		foreach ( array( $expected_release_hash, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Result PDF hash evidence is invalid.' );
			}
		}
		if (
			! in_array( $expected_release_state, array( 'released', 'corrected' ), true )
			|| ! hash_equals( $expected_release_hash, $authorized_release_revision )
			|| ! hash_equals( $expected_release_state, $authorized_release_state )
			|| 'self_release' !== $authorized_actor_relation
			|| ! in_array( $authorized_context_type, array( 'class', 'group' ), true )
			|| Build_Result_Print_Projection::PROFILE !== $profile
			|| 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale )
		) {
			throw new InvalidArgumentException( 'Result PDF command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_release_hash'  => $expected_release_hash,
						'expected_release_state' => $expected_release_state,
					),
					'payload'    => array(
						'locale'  => $locale,
						'profile' => $profile,
					),
					'release_id' => $release_id,
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
	 * Release id.
	 *
	 * @return string
	 */
	public function release_id(): string {
		return $this->release_id;
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
	 * Expected release hash.
	 *
	 * @return string
	 */
	public function expected_release_hash(): string {
		return $this->expected_release_hash;
	}
	/**
	 * Expected release state.
	 *
	 * @return string
	 */
	public function expected_release_state(): string {
		return $this->expected_release_state;
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
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->authorized_context_revision;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|own.result.pdf.enqueue|' . $this->release_id . '|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'Result PDF command token is invalid.' );
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
			throw new InvalidArgumentException( 'Result PDF Context token is invalid.' );
		}
	}
}

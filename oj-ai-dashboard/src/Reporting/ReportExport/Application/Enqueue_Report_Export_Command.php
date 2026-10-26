<?php
/**
 * Enqueue report export command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen C46/C47 body plus consumed Console authorization evidence.
 */
final class Enqueue_Report_Export_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue report export command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $artifact_kind Artifact kind.
	 * @param string         $snapshot_id Snapshot id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_snapshot_id Expected snapshot id.
	 * @param string         $expected_snapshot_hash Expected snapshot hash.
	 * @param string         $expected_snapshot_state Expected snapshot state.
	 * @param string         $locale Locale.
	 * @param string         $profile Profile.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_snapshot_revision Authorized snapshot revision.
	 * @param string         $authorized_snapshot_state Authorized snapshot state.
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
		private string $artifact_kind,
		private string $snapshot_id,
		private string $command_id,
		private string $expected_snapshot_id,
		private string $expected_snapshot_hash,
		private string $expected_snapshot_state,
		private string $locale,
		private string $profile,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_snapshot_revision,
		private string $authorized_snapshot_state,
		private string $authorized_actor_relation,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $authorized_context_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Report export actor is invalid.' );
		}
		new Public_Id( $snapshot_id );
		new Public_Id( $expected_snapshot_id );
		$this->token( $command_id, 8, 128 );
		$this->token( $correlation_id, 1, 100 );
		$this->context_token( $authorized_context_id );
		$this->context_token( $authorized_context_revision );
		$this->context_token( $authorized_context_relation, 80 );
		$this->context_token( $authorized_actor_relation, 80 );
		foreach ( array( $expected_snapshot_hash, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Report export hash evidence is invalid.' );
			}
		}
		if (
			$snapshot_id !== $expected_snapshot_id
			|| 'ready' !== $expected_snapshot_state
			|| ! hash_equals( $expected_snapshot_hash, $authorized_snapshot_revision )
			|| ! hash_equals( $expected_snapshot_state, $authorized_snapshot_state )
			|| ! in_array( $authorized_context_type, array( 'class', 'group' ), true )
			|| ! Report_Export_Request_Snapshot::supports( $artifact_kind, $profile )
			|| 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale )
		) {
			throw new InvalidArgumentException( 'Report export command evidence is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'artifact_kind' => $artifact_kind,
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_snapshot_hash'  => $expected_snapshot_hash,
						'expected_snapshot_id'    => $expected_snapshot_id,
						'expected_snapshot_state' => $expected_snapshot_state,
					),
					'payload'       => array(
						'locale'  => $locale,
						'profile' => $profile,
					),
					'snapshot_id'   => $snapshot_id,
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
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
	}
	/**
	 * Snapshot id.
	 *
	 * @return string
	 */
	public function snapshot_id(): string {
		return $this->snapshot_id;
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
	 * Expected snapshot hash.
	 *
	 * @return string
	 */
	public function expected_snapshot_hash(): string {
		return $this->expected_snapshot_hash;
	}
	/**
	 * Expected snapshot state.
	 *
	 * @return string
	 */
	public function expected_snapshot_state(): string {
		return $this->expected_snapshot_state;
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
	 * Context relation.
	 *
	 * @return string
	 */
	public function context_relation(): string {
		return $this->authorized_context_relation;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|report.export.' . $this->artifact_kind . '|' . $this->snapshot_id . '|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'Report export command token is invalid.' );
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
			throw new InvalidArgumentException( 'Report export Context token is invalid.' );
		}
	}
}

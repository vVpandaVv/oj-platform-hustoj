<?php
/**
 * Enqueue worksheet package command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C66 command and authorization evidence.
 */
final class Enqueue_Worksheet_Package_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize enqueue worksheet package command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $revision_id Revision id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_revision_id Revision the caller read before this operation.
	 * @param string         $expected_source_hash Expected source hash.
	 * @param string         $expected_state Expected state.
	 * @param int            $format_version Format version.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $revision_id,
		private string $command_id,
		private string $expected_revision_id,
		private string $expected_source_hash,
		private string $expected_state,
		private int $format_version,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet package actor is invalid.' );
		}
		new Public_Id( $revision_id );
		new Public_Id( $expected_revision_id );
		new Public_Id( $authorized_revision );
		$this->token( $command_id, 8, 128 );
		$this->token( $correlation_id, 1, 100 );
		if (
			! hash_equals( $revision_id, $expected_revision_id )
			|| ! hash_equals( $revision_id, $authorized_revision )
			|| ! hash_equals( $expected_state, $authorized_state )
			|| ! in_array( $expected_state, array( 'draft', 'approved' ), true )
			|| 1 !== $format_version
			|| 'owner' !== $actor_relation
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
		) {
			throw new InvalidArgumentException( 'Worksheet package command evidence is invalid.' );
		}
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'  => $command_id,
					'expected'    => array(
						'expected_revision_id' => $expected_revision_id,
						'expected_source_hash' => $expected_source_hash,
						'expected_state'       => $expected_state,
					),
					'payload'     => array( 'format_version' => $format_version ),
					'revision_id' => $revision_id,
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
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
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
	 * Expected source hash.
	 *
	 * @return string
	 */
	public function expected_source_hash(): string {
		return $this->expected_source_hash;
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
	 * Format version.
	 *
	 * @return int
	 */
	public function format_version(): int {
		return $this->format_version;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|worksheet.package.enqueue|' . $this->revision_id . '|' . $this->idempotency_fingerprint );
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
			throw new InvalidArgumentException( 'Worksheet package command token is invalid.' );
		}
	}
}

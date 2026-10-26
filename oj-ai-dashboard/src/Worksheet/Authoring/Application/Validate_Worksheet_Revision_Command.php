<?php
/**
 * ValidateWorksheetRevision command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ValidateWorksheetRevision command input.
 */
final class Validate_Worksheet_Revision_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize worksheet revision command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $revision_id Revision id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_revision_id Revision the caller read before this operation.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $revision_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_revision_id,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision requires an authenticated actor.' );
		}
		new Public_Id( $revision_id );
		new Public_Id( $expected_revision_id );
		if (
			strlen( $command_id ) < 8 || strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
		) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision command_id is invalid.' );
		}
		if ( ! in_array( $expected_state, array( 'draft', 'approved' ), true ) ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision expected_state is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'ValidateWorksheetRevision correlation ID is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'  => $command_id,
					'expected'    => array(
						'expected_revision_id' => $expected_revision_id,
						'expected_state'       => $expected_state,
					),
					'payload'     => array(),
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
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
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
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.revision.validate|' . $this->revision_id
				. '|' . $this->idempotency_fingerprint
		);
	}
}

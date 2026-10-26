<?php
/**
 * ArchiveWorksheet and RestoreWorksheet command input.
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
 * ArchiveWorksheet and RestoreWorksheet command input.
 */
final class Worksheet_Lifecycle_Command {
	public const ARCHIVE = 'archive';
	public const RESTORE = 'restore';

	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize worksheet lifecycle command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $operation Operation.
	 * @param string         $worksheet_id Worksheet public identifier.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_revision_id Revision the caller read before this operation.
	 * @param int            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $operation,
		private string $worksheet_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_revision_id,
		private int $expected_lock_version,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle command requires an authenticated actor.' );
		}
		if ( ! in_array( $operation, array( self::ARCHIVE, self::RESTORE ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle operation is invalid.' );
		}
		new Public_Id( $worksheet_id );
		new Public_Id( $expected_revision_id );
		if (
			strlen( $command_id ) < 8
			|| strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle command_id is invalid.' );
		}
		if (
			( self::ARCHIVE === $operation && ! in_array( $expected_state, array( 'draft', 'active' ), true ) )
			|| ( self::RESTORE === $operation && 'archived' !== $expected_state )
			|| $expected_lock_version < 0
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle expected state is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle correlation ID is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'   => $command_id,
					'expected'     => array(
						'expected_lock_version' => $expected_lock_version,
						'expected_revision_id'  => $expected_revision_id,
						'expected_state'        => $expected_state,
					),
					'operation'    => $operation,
					'payload'      => array(),
					'worksheet_id' => $worksheet_id,
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
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}
	/**
	 * Worksheet id.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return $this->worksheet_id;
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
	 * Expected lock version.
	 *
	 * @return int
	 */
	public function expected_lock_version(): int {
		return $this->expected_lock_version;
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
			$this->actor_user_id . '|worksheet.' . $this->operation . '|' . $this->worksheet_id . '|'
				. $this->idempotency_fingerprint
		);
	}
}

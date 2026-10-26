<?php
/**
 * StageWorksheetAsset command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts trusted actor metadata and one WordPress upload descriptor.
 */
final class Stage_Worksheet_Asset_Command {
	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Worksheet public identifier.
	 *
	 * @var string
	 */
	private string $worksheet_id;
	/**
	 * Command identifier used for idempotency.
	 *
	 * @var string
	 */
	private string $command_id;
	/**
	 * Expected state.
	 *
	 * @var string
	 */
	private string $expected_state;
	/**
	 * Revision the caller read before this operation.
	 *
	 * @var string
	 */
	private string $expected_revision_id;
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
	 * Upload.
	 *
	 * @var array<string,mixed>
	 */
	private array $upload;

	/**
	 * Initialize stage worksheet asset command dependencies and state.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $worksheet_id Worksheet public identifier.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $expected_state Expected state.
	 * @param string              $expected_revision_id Revision the caller read before this operation.
	 * @param array<string,mixed> $upload Upload.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $worksheet_id,
		string $command_id,
		string $expected_state,
		string $expected_revision_id,
		array $upload,
		string $idempotency_fingerprint,
		string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset requires an authenticated actor.' );
		}
		new Public_Id( $worksheet_id );
		new Public_Id( $expected_revision_id );
		if ( ! in_array( $expected_state, array( 'draft', 'active' ), true ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset expected state is invalid.' );
		}
		if (
			strlen( $command_id ) < 8
			|| strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
		) {
			throw new InvalidArgumentException( 'StageWorksheetAsset command ID is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'StageWorksheetAsset correlation ID is invalid.' );
		}
		if ( array_diff( array_keys( $upload ), array( 'name', 'type', 'tmp_name', 'error', 'size' ) ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset upload contains unknown fields.' );
		}
		foreach ( array( 'name', 'tmp_name' ) as $key ) {
			if ( ! is_string( $upload[ $key ] ?? null ) || '' === $upload[ $key ] ) {
				throw new InvalidArgumentException( 'StageWorksheetAsset upload is incomplete.' );
			}
		}
		if ( ! is_int( $upload['error'] ?? null ) || ! is_int( $upload['size'] ?? null ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset upload metadata is invalid.' );
		}

		$this->actor_user_id           = $actor_user_id;
		$this->worksheet_id            = $worksheet_id;
		$this->command_id              = $command_id;
		$this->expected_state          = $expected_state;
		$this->expected_revision_id    = $expected_revision_id;
		$this->upload                  = $upload;
		$this->idempotency_fingerprint = $idempotency_fingerprint;
		$this->correlation_id          = $correlation_id;
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
	 * Upload.
	 *
	 * @return array<string,mixed>
	 */
	public function upload(): array {
		return $this->upload;
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
	 * Storage key.
	 *
	 * @param string $extension Extension.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function storage_key( string $extension ): string {
		if ( 1 !== preg_match( '/^[a-z0-9]{2,5}$/D', $extension ) ) {
			throw new InvalidArgumentException( 'StageWorksheetAsset extension is invalid.' );
		}
		$idempotency_key = $this->idempotency_storage_key();
		return 'worksheet-assets/' . substr( $idempotency_key, 0, 2 ) . '/'
			. $idempotency_key . '.' . $extension;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.asset.stage|' . $this->worksheet_id . '|' . $this->idempotency_fingerprint
		);
	}
}

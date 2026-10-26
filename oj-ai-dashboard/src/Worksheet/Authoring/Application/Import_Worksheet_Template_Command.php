<?php
/**
 * ImportWorksheetTemplate command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImportWorksheetTemplate command input.
 */
final class Import_Worksheet_Template_Command {
	/**
	 * Initialize import worksheet template command dependencies and state.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $revision_id Revision id.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $expected_state Expected state.
	 * @param string              $expected_revision_id Revision the caller read before this operation.
	 * @param array<string,mixed> $upload Upload.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $revision_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_revision_id,
		private array $upload,
		private string $idempotency_fingerprint,
		private string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate requires an authenticated actor.' );
		}
		new Public_Id( $revision_id );
		new Public_Id( $expected_revision_id );
		if ( ! hash_equals( $revision_id, $expected_revision_id ) ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate target revision must match expected_revision_id.' );
		}
		if ( ! in_array( $expected_state, array( 'draft', 'active' ), true ) ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate expected state is invalid.' );
		}
		if (
			strlen( $command_id ) < 8 || strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate command metadata is invalid.' );
		}
		// Never use the optional client path as a server storage location.
		if ( array_key_exists( 'full_path', $upload ) ) {
			if ( ! is_string( $upload['full_path'] ) ) {
				throw new InvalidArgumentException( 'ImportWorksheetTemplate client path metadata is invalid.' );
			}
			unset( $upload['full_path'] );
		}
		$this->upload = $upload;
		$keys         = array_keys( $upload );
		$expected     = array( 'error', 'name', 'size', 'tmp_name', 'type' );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate upload shape is invalid.' );
		}
		if (
			! is_string( $upload['name'] ) || ! str_ends_with( strtolower( $upload['name'] ), '.ojws' )
			|| ! is_string( $upload['type'] ) || ! is_string( $upload['tmp_name'] ) || '' === $upload['tmp_name']
			|| ! is_int( $upload['error'] ) || ! is_int( $upload['size'] )
		) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate upload metadata is invalid.' );
		}
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
	 * Save idempotency fingerprint.
	 *
	 * @return string
	 */
	public function save_idempotency_fingerprint(): string {
		return hash( 'sha256', 'worksheet.import-template|' . $this->idempotency_fingerprint );
	}

	/**
	 * Asset idempotency fingerprint.
	 *
	 * @param int $position Position.
	 * @param int $attempt Attempt.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function asset_idempotency_fingerprint( int $position, int $attempt = 0 ): string {
		if ( $position < 0 || $position > 99 || $attempt < 0 || $attempt > 7 ) {
			throw new InvalidArgumentException( 'ImportWorksheetTemplate asset position is invalid.' );
		}
		return hash( 'sha256', 'worksheet.import-template.asset|' . $this->idempotency_fingerprint . '|' . $position . '|' . $attempt );
	}

	/**
	 * Asset command id.
	 *
	 * @param int $position Position.
	 * @param int $attempt Attempt.
	 * @return string
	 */
	public function asset_command_id( int $position, int $attempt = 0 ): string {
		return 'import-asset-' . substr( $this->asset_idempotency_fingerprint( $position, $attempt ), 0, 32 );
	}

	/**
	 * Rewrite seed.
	 *
	 * @return string
	 */
	public function rewrite_seed(): string {
		return hash(
			'sha256',
			$this->actor_user_id . '|worksheet.import-template|' . $this->revision_id . '|'
				. $this->idempotency_fingerprint
		);
	}
}

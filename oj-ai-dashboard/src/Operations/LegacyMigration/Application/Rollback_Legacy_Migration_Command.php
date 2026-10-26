<?php
/**
 * Rollback legacy migration command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict A-DS08-C63 compensation command.
 */
final class Rollback_Legacy_Migration_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize rollback legacy migration command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $run_id Run id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_checkpoint_hash Expected checkpoint hash.
	 * @param string         $expected_updated_at_utc Expected updated at utc.
	 * @param string         $idempotency_key_hash Idempotency key hash.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $run_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_checkpoint_hash,
		private string $expected_updated_at_utc,
		private string $idempotency_key_hash,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if (
			$actor_user_id < 1
			|| ! Public_Id::is_valid( $run_id )
			|| ! Public_Id::is_valid( $command_id )
			|| ! in_array( $expected_state, array( 'completed', 'completed_with_errors' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_checkpoint_hash )
			|| ! self::is_utc( $expected_updated_at_utc )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Legacy migration rollback command is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'checkpoint_hash' => $expected_checkpoint_hash,
						'state'           => $expected_state,
						'updated_at_utc'  => $expected_updated_at_utc,
					),
					'payload'    => array(),
					'run_id'     => $run_id,
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
	 * Run id.
	 *
	 * @return string
	 */
	public function run_id(): string {
		return $this->run_id;
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
	 * Expected checkpoint hash.
	 *
	 * @return string
	 */
	public function expected_checkpoint_hash(): string {
		return $this->expected_checkpoint_hash;
	}
	/**
	 * Expected updated at utc.
	 *
	 * @return string
	 */
	public function expected_updated_at_utc(): string {
		return $this->expected_updated_at_utc;
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
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|legacy.migration.rollback|' . $this->run_id . '|' . $this->idempotency_key_hash
		);
	}

	/**
	 * Is utc.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_utc( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return false;
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $time instanceof DateTimeImmutable && $time->format( 'Y-m-d H:i:s' ) === $value;
	}
}

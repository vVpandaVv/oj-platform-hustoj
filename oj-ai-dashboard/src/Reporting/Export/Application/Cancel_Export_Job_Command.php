<?php
/**
 * C53 cancel export job command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C53 cancel export job command.
 */
final class Cancel_Export_Job_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize export job command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $job_id Job id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_source_hash Expected source hash.
	 * @param string         $reason Reason.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $job_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_source_hash,
		private string $reason,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'CancelExportJob actor is invalid.' );
		}
		new Public_Id( $job_id );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->assert_reason( $reason );
		if ( 'queued' !== $expected_state || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_source_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'CancelExportJob expected evidence is invalid.' );
		}
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_source_hash' => $expected_source_hash,
						'expected_state'       => $expected_state,
					),
					'job_id'     => $job_id,
					'payload'    => array( 'reason' => $reason ),
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
	 * Job id.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
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
	 * Expected source hash.
	 *
	 * @return string
	 */
	public function expected_source_hash(): string {
		return $this->expected_source_hash;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|export.job.cancel|' . $this->job_id . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'CancelExportJob ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Reject invalid reason.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_reason( string $value ): void {
		if ( $value !== trim( $value ) || strlen( $value ) < 3 || strlen( $value ) > 1000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'CancelExportJob reason is invalid.' );
		}
	}
}

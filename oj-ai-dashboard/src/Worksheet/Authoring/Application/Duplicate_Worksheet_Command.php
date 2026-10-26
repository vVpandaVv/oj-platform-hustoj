<?php
/**
 * DuplicateWorksheet command input.
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
 * DuplicateWorksheet command input.
 */
final class Duplicate_Worksheet_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize duplicate worksheet command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $source_worksheet_id Source worksheet id.
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
		private string $source_worksheet_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_revision_id,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet requires an authenticated actor.' );
		}
		new Public_Id( $source_worksheet_id );
		new Public_Id( $expected_revision_id );
		if (
			strlen( $command_id ) < 8 || strlen( $command_id ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $command_id )
		) {
			throw new InvalidArgumentException( 'DuplicateWorksheet command_id is invalid.' );
		}
		if ( ! in_array( $expected_state, array( 'draft', 'active', 'archived' ), true ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet expected_state is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet idempotency fingerprint is invalid.' );
		}
		if (
			strlen( $correlation_id ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'DuplicateWorksheet correlation ID is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'          => $command_id,
					'expected'            => array(
						'expected_revision_id' => $expected_revision_id,
						'expected_state'       => $expected_state,
					),
					'payload'             => array(),
					'source_worksheet_id' => $source_worksheet_id,
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
	 * Source worksheet id.
	 *
	 * @return string
	 */
	public function source_worksheet_id(): string {
		return $this->source_worksheet_id;
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
			$this->actor_user_id . '|worksheet.duplicate|' . $this->source_worksheet_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Rewrite seed.
	 *
	 * @return string
	 */
	public function rewrite_seed(): string {
		return hash(
			'sha256',
			'worksheet.duplicate.rewrite|' . $this->actor_user_id . '|' . $this->source_worksheet_id
				. '|' . $this->expected_revision_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Asset idempotency fingerprint.
	 *
	 * @param int $position Position.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function asset_idempotency_fingerprint( int $position ): string {
		if ( $position < 0 || $position >= 100 ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet asset position is invalid.' );
		}
		return hash( 'sha256', 'worksheet.duplicate.asset|' . $this->idempotency_fingerprint . '|' . $position );
	}

	/**
	 * Copy title.
	 *
	 * @param string $source_title Source title.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function copy_title( string $source_title ): string {
		$source_title = trim( $source_title );
		if ( '' === $source_title ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet source title is invalid.' );
		}
		$suffix     = ' 복사본';
		$characters = preg_split( '//u', $source_title, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $characters ) {
			throw new InvalidArgumentException( 'DuplicateWorksheet source title encoding is invalid.' );
		}
		$limit = 255 - 4;
		if ( count( $characters ) > $limit ) {
			$source_title = rtrim( implode( '', array_slice( $characters, 0, $limit ) ) );
		}
		return $source_title . $suffix;
	}
}

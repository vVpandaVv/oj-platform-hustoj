<?php
/**
 * Worksheet pdf work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet pdf work item.
 */
final class Worksheet_Pdf_Work_Item {
	/**
	 * Initialize worksheet pdf work item dependencies and state.
	 *
	 * @param int         $job_pk Job pk.
	 * @param string      $job_id Job id.
	 * @param int         $lock_version Lock version.
	 * @param int         $actor_user_id Authenticated actor from the access context.
	 * @param string      $source_type Source type.
	 * @param string      $source_id Source id.
	 * @param string      $source_hash Source hash.
	 * @param string|null $context_type Context type.
	 * @param string|null $context_id Context id.
	 * @param string|null $context_revision Context revision.
	 * @param string      $snapshot_json Snapshot json.
	 * @param string      $snapshot_hash Snapshot hash.
	 * @param string      $request_hash Request hash.
	 * @param string      $profile_version Profile version.
	 * @param string      $queued_at_utc Queued at utc.
	 * @param string      $lease_token Lease token.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $job_pk,
		private string $job_id,
		private int $lock_version,
		private int $actor_user_id,
		private string $source_type,
		private string $source_id,
		private string $source_hash,
		private ?string $context_type,
		private ?string $context_id,
		private ?string $context_revision,
		private string $snapshot_json,
		private string $snapshot_hash,
		private string $request_hash,
		private string $profile_version,
		private string $queued_at_utc,
		private string $lease_token
	) {
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		if ( $job_pk < 1 || $lock_version < 0 || $actor_user_id < 1
			|| ! in_array( $source_type, array( 'worksheet_revision', 'worksheet_publication' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_hash )
			|| 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $lease_token ) || '' === $snapshot_json ) {
			throw new InvalidArgumentException( 'Worksheet PDF work item is invalid.' );
		}
		$has_context = null !== $context_type || null !== $context_id || null !== $context_revision;
		if ( ( 'worksheet_revision' === $source_type && $has_context )
			|| ( 'worksheet_publication' === $source_type && ( ! in_array( $context_type, array( 'class', 'group' ), true ) || ! self::token( $context_id ) || ! self::token( $context_revision ) ) ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF work Context is invalid.' );
		}
	}
	/**
	 * Job pk.
	 *
	 * @return int
	 */
	public function job_pk(): int {
		return $this->job_pk;
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
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
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
	 * Source type.
	 *
	 * @return string
	 */
	public function source_type(): string {
		return $this->source_type;
	}
	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return $this->source_id;
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
	}
	/**
	 * Context.
	 *
	 * @return array{id:string,revision:string,type:string}|null
	 */
	public function context(): ?array {
		return null === $this->context_type ? null : array(
			'id'       => $this->context_id,
			'revision' => $this->context_revision,
			'type'     => $this->context_type,
		);
	}
	/**
	 * Snapshot json.
	 *
	 * @return string
	 */
	public function snapshot_json(): string {
		return $this->snapshot_json;
	}
	/**
	 * Snapshot hash.
	 *
	 * @return string
	 */
	public function snapshot_hash(): string {
		return $this->snapshot_hash;
	}
	/**
	 * Request hash.
	 *
	 * @return string
	 */
	public function request_hash(): string {
		return $this->request_hash;
	}
	/**
	 * Profile version.
	 *
	 * @return string
	 */
	public function profile_version(): string {
		return $this->profile_version;
	}
	/**
	 * Queued at utc.
	 *
	 * @return string
	 */
	public function queued_at_utc(): string {
		return $this->queued_at_utc;
	}
	/**
	 * Lease token.
	 *
	 * @return string
	 */
	public function lease_token(): string {
		return $this->lease_token;
	}
	/**
	 * Token.
	 *
	 * @param string|null $value Value.
	 * @return bool
	 */
	private static function token( ?string $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}
}

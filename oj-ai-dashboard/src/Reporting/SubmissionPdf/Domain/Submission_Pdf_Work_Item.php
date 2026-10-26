<?php
/**
 * Submission pdf work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the claimed C49 job row and its exact Context lease evidence.
 */
final class Submission_Pdf_Work_Item {
	/**
	 * Initialize submission pdf work item dependencies and state.
	 *
	 * @param int    $job_pk Job pk.
	 * @param string $job_id Job id.
	 * @param int    $lock_version Lock version.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $source_id Source id.
	 * @param string $source_hash Source hash.
	 * @param string $snapshot_json Snapshot json.
	 * @param string $snapshot_hash Snapshot hash.
	 * @param string $request_hash Request hash.
	 * @param string $profile_version Profile version.
	 * @param string $queued_at_utc Queued at utc.
	 * @param string $lease_token Lease token.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $job_pk,
		private string $job_id,
		private int $lock_version,
		private int $actor_user_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $source_id,
		private string $source_hash,
		private string $snapshot_json,
		private string $snapshot_hash,
		private string $request_hash,
		private string $profile_version,
		private string $queued_at_utc,
		private string $lease_token
	) {
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		if (
			$job_pk < 1 || $lock_version < 0 || $actor_user_id < 1
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| ! self::is_context_token( $context_id ) || ! self::is_context_token( $context_revision )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_hash )
			|| 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $lease_token )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
			|| '' === $snapshot_json
		) {
			throw new InvalidArgumentException( 'Submission PDF work item is invalid.' );
		}
	}

	/**
	 * Is context token.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_context_token( string $value ): bool {
		return '' !== $value && strlen( $value ) <= 191
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
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
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
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
}

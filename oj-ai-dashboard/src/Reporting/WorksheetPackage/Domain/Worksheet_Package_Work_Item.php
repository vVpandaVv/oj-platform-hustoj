<?php
/**
 * Worksheet package work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package work item.
 */
final class Worksheet_Package_Work_Item {
	/**
	 * Initialize worksheet package work item dependencies and state.
	 *
	 * @param int    $job_pk Job pk.
	 * @param string $job_id Job id.
	 * @param int    $lock_version Lock version.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_id Source id.
	 * @param string $source_hash Source hash.
	 * @param string $source_state Source state.
	 * @param string $snapshot_json Snapshot json.
	 * @param string $snapshot_hash Snapshot hash.
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
		private string $source_id,
		private string $source_hash,
		private string $source_state,
		private string $snapshot_json,
		private string $snapshot_hash,
		private string $profile_version,
		private string $queued_at_utc,
		private string $lease_token
	) {
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		if ( $job_pk < 1 || $lock_version < 1 || $actor_user_id < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot_hash )
			|| ! in_array( $source_state, array( 'draft', 'approved' ), true ) || Worksheet_Package_Request_Snapshot::PROFILE_VERSION !== $profile_version
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
			|| 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $lease_token ) ) {
			throw new InvalidArgumentException( 'Worksheet package work item is invalid.' );
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
	 * Source state.
	 *
	 * @return string
	 */
	public function source_state(): string {
		return $this->source_state;
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

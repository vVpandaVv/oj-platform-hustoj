<?php
/**
 * Export work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One leased export job whose immutable request snapshot still needs artifact bytes.
 */
final class Export_Work_Item {
	private const KINDS = array( 'report_csv', 'report_pdf', 'report_json_package', 'submission_bundle' );

	/**
	 * Initialize export work item dependencies and state.
	 *
	 * @param int    $job_pk Job pk.
	 * @param string $job_id Job id.
	 * @param int    $lock_version Lock version.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_kind Artifact kind.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $source_hash Source hash.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $request_contract_version Request contract version.
	 * @param string $snapshot_schema Snapshot schema.
	 * @param int    $snapshot_version Snapshot version.
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
		private string $artifact_kind,
		private string $source_type,
		private string $source_id,
		private string $source_hash,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $request_contract_version,
		private string $snapshot_schema,
		private int $snapshot_version,
		private string $snapshot_json,
		private string $snapshot_hash,
		private string $request_hash,
		private string $profile_version,
		private string $queued_at_utc,
		private string $lease_token
	) {
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		$valid_pair = ( 'report_snapshot' === $source_type && in_array( $artifact_kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) )
			|| ( 'assignment' === $source_type && 'submission_bundle' === $artifact_kind );
		if (
			$job_pk < 1 || $lock_version < 1 || $actor_user_id < 1 || ! in_array( $artifact_kind, self::KINDS, true ) || ! $valid_pair
			|| ! in_array( $context_type, array( 'class', 'group' ), true ) || ! self::token( $context_id ) || ! self::token( $context_revision )
			|| '' === $request_contract_version || strlen( $request_contract_version ) > 32
			|| '' === $snapshot_schema || strlen( $snapshot_schema ) > 100 || $snapshot_version < 1 || '' === $snapshot_json
			|| ! self::hash_value( $source_hash ) || ! self::hash_value( $snapshot_hash ) || ! self::hash_value( $request_hash )
			|| ! hash_equals( $snapshot_hash, $request_hash ) || '' === $profile_version || strlen( $profile_version ) > 32
			|| ! self::utc( $queued_at_utc ) || 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $lease_token )
		) {
			throw new InvalidArgumentException( 'Export work item is invalid.' );
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
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
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
	 * Request contract version.
	 *
	 * @return string
	 */
	public function request_contract_version(): string {
		return $this->request_contract_version;
	}
	/**
	 * Snapshot schema.
	 *
	 * @return string
	 */
	public function snapshot_schema(): string {
		return $this->snapshot_schema;
	}
	/**
	 * Snapshot version.
	 *
	 * @return int
	 */
	public function snapshot_version(): int {
		return $this->snapshot_version;
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
	 * Context.
	 *
	 * @return array{type:string,id:string,revision:string}
	 */
	public function context(): array {
		return array(
			'type'     => $this->context_type,
			'id'       => $this->context_id,
			'revision' => $this->context_revision,
		);
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function hash_value( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function token( string $value ): bool {
		return '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}
	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function utc( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value );
	}
}

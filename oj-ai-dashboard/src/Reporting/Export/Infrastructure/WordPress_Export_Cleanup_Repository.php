<?php
/**
 * Atomic expiry of completed export artifacts and their private binaries.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup;
use OJAI\Dashboard\Reporting\Export\Port\Export_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use RuntimeException;
use Throwable;

/**
 * Expire disposable artifacts without deleting sources, snapshots or history.
 */
final class WordPress_Export_Cleanup_Repository implements Export_Cleanup_Repository_Port_Interface {
	/**
	 * Uses the owning WordPress database and canonical audit encoding.
	 *
	 * @param object|null    $database WordPress database or isolated test adapter.
	 * @param Canonical_Json $canonical Audit serializer.
	 */
	public function __construct( private ?object $database = null, private Canonical_Json $canonical = new Canonical_Json() ) {
	}

	/**
	 * Claims one due artifact and rechecks its guards under root-first locks.
	 *
	 * @param string   $now_utc Current UTC cutoff.
	 * @param string   $retry_before_utc Previous attempts must precede this cutoff.
	 * @param callable $delete Private binary deletion callback.
	 * @param string   $audit_id Audit event public ID.
	 * @param string   $outbox_id Outbox event public ID.
	 * @throws Throwable When deletion or transactional completion fails.
	 * @return string
	 */
	public function purge_next( string $now_utc, string $retry_before_utc, callable $delete, string $audit_id, string $outbox_id ): string {
		new Public_Id( $audit_id );
		new Public_Id( $outbox_id );
		$select = 'SELECT f.*, j.id AS job_pk, j.public_id AS job_public_id, j.lock_version AS job_lock, j.artifact_kind, p.id AS pdf_pk FROM oj_ai_private_files f' . Export_Retention_Query::joins()
			. ' WHERE ' . Export_Retention_Query::eligible() . ' AND f.expires_at_utc <= %s';
		$item   = $this->row( $this->prepare( $select . ' AND f.updated_at_utc <= %s ORDER BY f.expires_at_utc, f.id LIMIT 1', $now_utc, $retry_before_utc ) );
		if ( null === $item ) {
			return Run_Next_Export_Cleanup::IDLE;
		}
		$this->validate( $item );

		// The committed claim provides retry backoff even if unlink or COMMIT fails.
		$claimed = $this->query(
			$this->prepare(
				'UPDATE oj_ai_private_files SET lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = \'ready\' AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL AND updated_at_utc <= %s',
				$now_utc,
				$item['id'],
				$item['lock_version'],
				$retry_before_utc
			)
		);
		if ( 1 !== $claimed ) {
			return Run_Next_Export_Cleanup::STALE;
		}
		$item['lock_version'] = (int) $item['lock_version'] + 1;
		$this->query( 'START TRANSACTION' );
		try {
			// Same root-first order as export generation. Recheck eligibility using locking reads.
			$this->row( $this->prepare( 'SELECT id FROM oj_ai_export_jobs WHERE id = %d FOR UPDATE', $item['job_pk'] ) );
			if ( null !== $item['pdf_pk'] ) {
				$this->row( $this->prepare( 'SELECT id FROM oj_ai_pdf_artifacts WHERE id = %d FOR UPDATE', $item['pdf_pk'] ) );
			}
			$current = $this->row( $this->prepare( $select . ' AND f.id = %d AND f.lock_version = %d FOR UPDATE', $now_utc, $item['id'], $item['lock_version'] ) );
			if ( null === $current || ! $this->same_claim( $item, $current ) ) {
				$this->query( 'ROLLBACK' );
				return Run_Next_Export_Cleanup::STALE;
			}
			// The private store rejects traversal/symlinks; missing bytes allow crash recovery.
			$delete( (string) $current['storage_key'] );
			$this->change(
				$this->prepare(
					'UPDATE oj_ai_private_files SET state = \'purged\', purged_at_utc = %s, expires_at_utc = NULL, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d',
					$now_utc,
					$now_utc,
					$current['id'],
					$current['lock_version']
				)
			);
			$this->change(
				$this->prepare(
					'UPDATE oj_ai_export_jobs SET state = \'expired\', lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND state = \'ready\' AND result_file_public_id = %s',
					$now_utc,
					$current['job_pk'],
					$current['job_lock'],
					$current['public_id']
				)
			);
			if ( null !== $current['pdf_pk'] ) {
				$this->change( $this->prepare( "UPDATE oj_ai_pdf_artifacts SET state = 'expired' WHERE id = %d AND state = 'ready'", $current['pdf_pk'] ) );
			}
			$this->record( $current, $audit_id, $outbox_id, $now_utc );
			$this->query( 'COMMIT' );
			return Run_Next_Export_Cleanup::PURGED;
		} catch ( Throwable $error ) {
			try {
				$this->query( 'ROLLBACK' );
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
				// Preserve the original storage failure if rollback also fails.
			}
			throw $error;
		}
	}

	/**
	 * Validates claim identifiers before any write.
	 *
	 * @param array<string,mixed> $item Stored candidate.
	 * @throws RuntimeException When the candidate is malformed.
	 * @return void
	 */
	private function validate( array $item ): void {
		foreach ( array( 'public_id', 'owner_public_id', 'job_public_id' ) as $key ) {
			new Public_Id( (string) ( $item[ $key ] ?? '' ) );
		}
		foreach ( array( 'id', 'job_pk', 'created_by_user_id' ) as $key ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', (string) ( $item[ $key ] ?? '' ) ) ) {
				throw new RuntimeException( 'Invalid export cleanup identity.' );
			}
		}
		foreach ( array( 'lock_version', 'job_lock' ) as $key ) {
			if ( 1 !== preg_match( '/^[0-9]+$/D', (string) ( $item[ $key ] ?? '' ) ) ) {
				throw new RuntimeException( 'Invalid export cleanup version.' );
			}
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $item['sha256'] ?? '' ) ) ) {
			throw new RuntimeException( 'Invalid export cleanup digest.' );
		}
	}

	/**
	 * Rejects a file, owner or revision changed after selection.
	 *
	 * @param array<string,mixed> $claim Original candidate.
	 * @param array<string,mixed> $current Locked candidate.
	 * @return bool
	 */
	private function same_claim( array $claim, array $current ): bool {
		foreach ( array( 'public_id', 'lock_version', 'job_pk', 'job_lock', 'job_public_id', 'pdf_pk', 'owner_type', 'owner_public_id', 'purpose', 'storage_key', 'sha256', 'expires_at_utc' ) as $key ) {
			if ( (string) ( $current[ $key ] ?? '' ) !== (string) $claim[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Retains an audit chain and outbox receipt without private paths or content.
	 *
	 * @param array<string,mixed> $item Locked candidate.
	 * @param string              $audit_id Audit event public ID.
	 * @param string              $outbox_id Outbox event public ID.
	 * @param string              $utc Completion time.
	 * @throws RuntimeException When the audit chain is invalid.
	 * @return void
	 */
	private function record( array $item, string $audit_id, string $outbox_id, string $utc ): void {
		$action        = 'export.file.purged';
		$previous      = $this->row( 'SELECT event_hash FROM oj_ai_audit_events ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		$previous_hash = $previous['event_hash'] ?? null;
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw new RuntimeException( 'Invalid audit chain.' );
		}
		$json        = $this->canonical->encode(
			array(
				'file_id'         => $item['public_id'],
				'job_id'          => $item['job_public_id'],
				'artifact_kind'   => $item['artifact_kind'],
				'retention_class' => Export_Retention_Query::RETENTION_CLASS,
			)
		);
		$hash        = hash( 'sha256', $json );
		$correlation = 'export-cleanup:' . $item['job_public_id'];
		$event       = array(
			'action_key'          => $action,
			'actor_user_id'       => null,
			'after_digest'        => $hash,
			'before_digest'       => null,
			'context_id'          => null,
			'context_revision'    => null,
			'context_type'        => null,
			'correlation_id'      => $correlation,
			'metadata_hash'       => $hash,
			'object_public_id'    => $item['job_public_id'],
			'object_type'         => 'export_job',
			'occurred_at_utc'     => $utc,
			'previous_event_hash' => $previous_hash,
		);
		$this->insert(
			'oj_ai_audit_events',
			array_merge(
				$event,
				array(
					'public_id'        => $audit_id,
					'metadata_schema'  => 'oj_ai.export_cleanup_audit',
					'metadata_version' => 1,
					'metadata_json'    => $json,
					'event_hash'       => hash( 'sha256', $this->canonical->encode( $event ) ),
				)
			)
		);
		$this->insert(
			'oj_ai_outbox_events',
			array(
				'public_id'            => $outbox_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $item['created_by_user_id'],
				'created_at_utc'       => $utc,
				'updated_by_user_id'   => $item['created_by_user_id'],
				'updated_at_utc'       => $utc,
				'aggregate_type'       => 'export_job',
				'aggregate_public_id'  => $item['job_public_id'],
				'event_type'           => $action,
				'idempotency_key'      => 'export-cleanup:purged:' . $item['public_id'],
				'payload_schema'       => 'oj_ai.export_cleanup_event',
				'payload_version'      => 1,
				'payload_json'         => $json,
				'payload_hash'         => $hash,
				'available_at_utc'     => $utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Clears the previous database error before an operation.
	 *
	 * @throws RuntimeException When WordPress storage is unavailable.
	 * @return object
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			throw new RuntimeException( 'Export cleanup storage unavailable.' );
		}
		$db->last_error = '';
		return $db;
	}
	/**
	 * Rejects database errors even when the adapter returned a value.
	 *
	 * @param object $db Database used for the operation.
	 * @throws RuntimeException When the last query failed.
	 * @return void
	 */
	private function ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'Export cleanup storage failed.' );
		}
	}
	/**
	 * Binds values using the WordPress query adapter.
	 *
	 * @param string $sql SQL template.
	 * @param mixed  ...$values Bound parameters.
	 * @throws RuntimeException When preparation fails.
	 * @return string
	 */
	private function prepare( string $sql, mixed ...$values ): string {
		$result = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $result ) || '' === $result ) {
			throw new RuntimeException( 'Export cleanup query failed.' );
		}
		return $result;
	}
	/**
	 * Reads one row without conflating SQL failure with no candidate.
	 *
	 * @param string $sql Prepared query.
	 * @return array<string,mixed>|null
	 * @throws RuntimeException When the result shape is invalid.
	 */
	private function row( string $sql ): ?array {
		$db  = $this->db();
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->ok( $db );
		if ( null !== $row && ! is_array( $row ) ) {
			throw new RuntimeException( 'Export cleanup read failed.' );
		}
		return $row;
	}
	/**
	 * Executes a statement and checks both result and error state.
	 *
	 * @param string $sql Prepared query.
	 * @throws RuntimeException When the write fails.
	 * @return int
	 */
	private function query( string $sql ): int {
		$db     = $this->db();
		$result = $db->query( $sql );
		$this->ok( $db );
		if ( ! is_int( $result ) ) {
			throw new RuntimeException( 'Export cleanup write failed.' );
		}
		return $result;
	}
	/**
	 * Requires exactly one claimed row to change.
	 *
	 * @param string $sql Prepared update.
	 * @throws RuntimeException When the claim no longer matches.
	 * @return void
	 */
	private function change( string $sql ): void {
		if ( 1 !== $this->query( $sql ) ) {
			throw new RuntimeException( 'Export cleanup claim changed.' );
		}
	}
	/**
	 * Writes exactly one transactional receipt.
	 *
	 * @param string              $table Add-on owned event table.
	 * @param array<string,mixed> $data Receipt fields.
	 * @throws RuntimeException When receipt persistence fails.
	 * @return void
	 */
	private function insert( string $table, array $data ): void {
		$db     = $this->db();
		$result = $db->insert( $table, $data );
		$this->ok( $db );
		if ( 1 !== $result ) {
			throw new RuntimeException( 'Export cleanup event failed.' );
		}
	}
}

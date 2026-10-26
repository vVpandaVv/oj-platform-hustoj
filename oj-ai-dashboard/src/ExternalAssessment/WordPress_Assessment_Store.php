<?php
/**
 * AI-owned ML queue metadata and private result snapshots.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\ExternalAssessment;

use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use RuntimeException;
use Throwable;

/** WordPress Assessment Store boundary. */
final class WordPress_Assessment_Store implements Assessment_Store {
	private const AGGREGATE = 'external_ml_assessment';
	private const LOCK      = 'oj_ai_external_ml_worker_v1';
	/**
	 * Locked state.
	 *
	 * @var bool
	 */
	private bool $locked = false;

	/**
	 * Connect the owned persistence and execution dependencies.
	 *
	 * @param Local_Private_File_Store $files files.
	 * @param object|null              $database database.
	 * @return void
	 */
	public function __construct( private Local_Private_File_Store $files, private ?object $database = null ) {}

	/**
	 * Read the newest metadata record for an exact source.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function latest( string $source, string $source_id ): ?array {
		$row = $this->row( $this->sql( 'SELECT * FROM oj_ai_outbox_events WHERE aggregate_type = %s AND aggregate_public_id = %s ORDER BY id DESC LIMIT 1', self::AGGREGATE, $this->identity( $source, $source_id ) ) );
		return null === $row ? null : $this->decode( $row );
	}

	/**
	 * Queue one approved snapshot without duplicating a pending or completed run.
	 *
	 * @param array $metadata metadata.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $metadata ): array {
		$this->assert_active();
		$lock = 'oj_ai_ml_' . substr( $this->identity( $metadata['source'], $metadata['source_id'] ), 0, 26 );
		if ( '1' !== (string) $this->call( 'get_var', $this->sql( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'ml_ai_busy' );
		}
		try {
			$prior = $this->latest( $metadata['source'], $metadata['source_id'] );
			if ( null !== $prior && hash_equals( $prior['input_hash'], $metadata['input_hash'] )
				&& hash_equals( $prior['routing_fingerprint'], $metadata['routing_fingerprint'] )
				&& in_array( $prior['state'], array( 'queued', 'running', 'ready', 'needs_review' ), true ) ) {
				if ( in_array( $prior['state'], array( 'queued', 'running' ), true ) || null !== $this->result( $prior ) ) {
					return $prior;
				}
			}
			$run     = $metadata + array(
				'run_id'         => ( new Public_Id_Generator() )->create()->value(),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'result_file_id' => null,
			);
			$json    = Assessment_Profile::encode( $run );
			$written = $this->call(
				'insert',
				'oj_ai_outbox_events',
				array(
					'public_id'            => $run['run_id'],
					'lock_version'         => 0,
					'created_by_user_id'   => $run['actor_id'],
					'created_at_utc'       => $run['created_at'],
					'updated_by_user_id'   => $run['actor_id'],
					'updated_at_utc'       => $run['created_at'],
					'aggregate_type'       => self::AGGREGATE,
					'aggregate_public_id'  => $this->identity( $run['source'], $run['source_id'] ),
					'event_type'           => 'external_ml.queued',
					'idempotency_key'      => 'external-ml:' . $run['run_id'],
					'payload_schema'       => 'oj_ai.external_ml_metadata',
					'payload_version'      => 1,
					'payload_json'         => $json,
					'payload_hash'         => hash( 'sha256', $json ),
					'available_at_utc'     => $run['created_at'],
					// This is a private command ledger, never a generic dispatchable outbox event.
					'published_at_utc'     => $run['created_at'],
					'lease_token'          => null,
					'lease_expires_at_utc' => null,
					'attempt_count'        => 0,
					'last_error_code'      => null,
				)
			);
			if ( 1 !== $written ) {
				throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
			return $run + array( 'state' => 'queued' );
		} finally {
			$this->call( 'get_var', $this->sql( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Claim at most one run under the serial worker lock.
	 *
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim(): ?array {
		$this->assert_active();
		if ( '1' !== (string) $this->call( 'get_var', $this->sql( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return null; }
		$this->locked = true;
		$now          = gmdate( 'Y-m-d H:i:s' );
		// A crashed provider call has unknown billing status. Require a teacher's explicit retry.
		$this->call( 'query', $this->sql( "UPDATE oj_ai_outbox_events SET event_type = 'external_ml.failed', last_error_code = 'ml_ai_interrupted', lease_token = NULL, lease_expires_at_utc = NULL WHERE aggregate_type = %s AND event_type = 'external_ml.running' AND lease_expires_at_utc < %s", self::AGGREGATE, $now ) );
		$row = $this->row( $this->sql( "SELECT * FROM oj_ai_outbox_events WHERE aggregate_type = %s AND event_type = 'external_ml.queued' ORDER BY id ASC LIMIT 1", self::AGGREGATE ) );
		if ( null === $row ) {
			return null; }
		$lease   = bin2hex( random_bytes( 16 ) );
		$written = $this->call( 'query', $this->sql( "UPDATE oj_ai_outbox_events SET event_type = 'external_ml.running', lease_token = %s, lease_expires_at_utc = %s, attempt_count = attempt_count + 1, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND aggregate_type = %s AND event_type = 'external_ml.queued'", $lease, gmdate( 'Y-m-d H:i:s', time() + 600 ), $now, (int) $row['id'], self::AGGREGATE ) );
		if ( 1 !== $written ) {
			return null; }
		$row['event_type']  = 'external_ml.running';
		$row['lease_token'] = $lease;
		return $this->decode( $row );
	}

	/**
	 * Commit a private result and terminal metadata under the exact lease.
	 *
	 * @param array      $run run.
	 * @param string     $state state.
	 * @param array|null $result result.
	 * @param string     $error_code error code.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function finish( array $run, string $state, ?array $result, string $error_code = '' ): void {
		$this->assert_active();
		if ( ! in_array( $state, array( 'ready', 'needs_review', 'failed', 'stale' ), true ) || in_array( $state, array( 'ready', 'needs_review' ), true ) !== ( null !== $result ) ) {
			throw new RuntimeException( 'ml_ai_invalid_state' );
		}
		$file        = null === $result ? null : $this->write_result( $run, $result );
		$transaction = false;
		try {
			$this->call( 'query', 'START TRANSACTION' );
			$transaction = true;
			$this->assert_active( true );
			if ( null !== $file ) {
				if ( 1 !== $this->call( 'insert', 'oj_ai_private_files', $file ) ) {
					throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
			}
			$stored = $run;
			unset( $stored['_id'], $stored['_lease'], $stored['state'], $stored['error_code'] );
			$stored['result_file_id'] = $file['public_id'] ?? null;
			$json                     = Assessment_Profile::encode( $stored );
			$written                  = $this->call( 'query', $this->sql( 'UPDATE oj_ai_outbox_events SET event_type = %s, payload_json = %s, payload_hash = %s, last_error_code = %s, lease_token = NULL, lease_expires_at_utc = NULL, lock_version = lock_version + 1, updated_at_utc = %s WHERE id = %d AND aggregate_type = %s AND event_type = %s AND lease_token = %s', 'external_ml.' . $state, $json, hash( 'sha256', $json ), $error_code, gmdate( 'Y-m-d H:i:s' ), $run['_id'], self::AGGREGATE, 'external_ml.running', $run['_lease'] ) );
			if ( 1 !== $written ) {
				throw new RuntimeException( 'ml_ai_lease_lost' ); }
			$this->call( 'query', 'COMMIT' );
			$transaction = false;
		} catch ( Throwable $error ) {
			try {
				if ( $transaction ) {
					$this->call( 'query', 'ROLLBACK' ); }
			} finally {
				if ( null !== $file ) {
					$this->files->delete( $file['storage_key'] ); }
			}
			throw $error;
		}
	}

	/**
	 * Read an unexpired private result for its exact owning run.
	 *
	 * @param array $run run.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function result( array $run ): ?array {
		if ( ! is_string( $run['result_file_id'] ?? null ) ) {
			return null; }
		$row = $this->row( $this->sql( "SELECT storage_key, sha256, byte_size FROM oj_ai_private_files WHERE public_id = %s AND owner_type = %s AND owner_public_id = %s AND state = 'ready' AND scan_state = 'clean' AND (expires_at_utc IS NULL OR expires_at_utc > %s OR legal_hold_until_utc > %s) LIMIT 1", $run['result_file_id'], self::AGGREGATE, $run['run_id'], gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) );
		if ( null === $row || (int) $row['byte_size'] > 100000 ) {
			return null; }
		try {
			$path = $this->files->readable_path( $row['storage_key'], $row['sha256'] );
		} catch ( Throwable ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verified local private file, never a remote request.
		$data = file_get_contents( $path );
		if ( ! is_string( $data ) || strlen( $data ) > 100000 ) {
			return null; }
		$result = json_decode( $data, true, 20, JSON_THROW_ON_ERROR );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Release the worker lock even after failures.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function release(): void {
		if ( $this->locked ) {
			$this->locked = false;
			$this->call( 'get_var', $this->sql( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
	}

	/**
	 * Write a normalized result into AI-owned private storage.
	 *
	 * @param array $run run.
	 * @param array $result result.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_result( array $run, array $result ): array {
		$id   = ( new Public_Id_Generator() )->create()->value();
		$key  = 'external-ml/' . strtolower( $run['run_id'] ) . '/' . strtolower( $id ) . '.json';
		$json = Assessment_Profile::encode( $result );
		if ( strlen( $json ) > 100000 ) {
			throw new RuntimeException( 'ml_ai_invalid_output' ); }
		$hash      = hash( 'sha256', $json );
		$temporary = tmpfile();
		if ( false === $temporary ) {
			throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private temporary stream is handed to the owned file store.
			if ( fwrite( $temporary, $json ) !== strlen( $json ) || ! fflush( $temporary ) ) {
				throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
			$metadata = stream_get_meta_data( $temporary );
			$this->files->put_quarantined( $metadata['uri'], $key, $hash );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the private temporary stream in all outcomes.
			fclose( $temporary );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		return array(
			'public_id'            => $id,
			'lock_version'         => 0,
			'created_by_user_id'   => $run['actor_id'],
			'created_at_utc'       => $now,
			'updated_by_user_id'   => $run['actor_id'],
			'updated_at_utc'       => $now,
			'owner_type'           => self::AGGREGATE,
			'owner_public_id'      => $run['run_id'],
			'purpose'              => 'ml_assessment_result',
			'storage_key'          => $key,
			'original_name'        => 'assessment.json',
			'mime_type'            => 'application/json',
			'byte_size'            => strlen( $json ),
			'sha256'               => $hash,
			'state'                => 'ready',
			'scan_state'           => 'clean',
			'retention_class'      => 'assessment_record',
			'expires_at_utc'       => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ),
			'legal_hold_until_utc' => null,
			'purged_at_utc'        => null,
		);
	}

	/**
	 * Purge expired result bytes in bounded batches while honoring legal holds.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cleanup(): void {
		$transaction = false;
		try {
			$this->call( 'query', 'START TRANSACTION' );
			$transaction = true;
			$now         = gmdate( 'Y-m-d H:i:s' );
			$rows        = $this->call( 'get_results', $this->sql( "SELECT id, storage_key FROM oj_ai_private_files WHERE owner_type = %s AND state = 'ready' AND expires_at_utc <= %s AND (legal_hold_until_utc IS NULL OR legal_hold_until_utc <= %s) ORDER BY id ASC LIMIT 20 FOR UPDATE", self::AGGREGATE, $now, $now ), defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
			if ( ! is_array( $rows ) ) {
				throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
			foreach ( $rows as $row ) {
				$this->files->delete( (string) $row['storage_key'] );
				$this->call( 'query', $this->sql( "UPDATE oj_ai_private_files SET state = 'purged', purged_at_utc = %s, updated_at_utc = %s, lock_version = lock_version + 1 WHERE id = %d AND owner_type = %s", $now, $now, (int) $row['id'], self::AGGREGATE ) );
			}
			$this->call( 'query', 'COMMIT' );
			$transaction = false;
		} finally {
			if ( $transaction ) {
				$this->call( 'query', 'ROLLBACK' ); }
		}
	}

	/**
	 * Derive a stable non-content aggregate identifier.
	 *
	 * @param string $source source.
	 * @param string $id id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function identity( string $source, string $id ): string {
		return substr( strtoupper( hash( 'sha256', $source . ':' . $id ) ), 0, 26 ); }

	/**
	 * Verify stored metadata integrity before using the queue record.
	 *
	 * @param array $row row.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( array $row ): array {
		if ( ( $row['aggregate_type'] ?? null ) !== self::AGGREGATE || 'oj_ai.external_ml_metadata' !== ( $row['payload_schema'] ?? null )
			|| 1 !== (int) ( $row['payload_version'] ?? 0 ) || ! hash_equals( (string) $row['payload_hash'], hash( 'sha256', (string) $row['payload_json'] ) ) ) {
			throw new RuntimeException( 'ml_ai_storage_invalid' );
		}
		$run = json_decode( $row['payload_json'], true, 12, JSON_THROW_ON_ERROR );
		if ( ! is_array( $run ) || ( $run['run_id'] ?? null ) !== $row['public_id'] ) {
			throw new RuntimeException( 'ml_ai_storage_invalid' ); }
		$run['state']      = substr( (string) $row['event_type'], strlen( 'external_ml.' ) );
		$run['error_code'] = (string) ( $row['last_error_code'] ?? '' );
		$run['_id']        = (int) $row['id'];
		$run['_lease']     = (string) ( $row['lease_token'] ?? '' );
		return $run;
	}

	/**
	 * Prepare SQL with bound values.
	 *
	 * @param string $query query.
	 * @param mixed  ...$values values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sql( string $query, mixed ...$values ): string {
		$sql = $this->db()->prepare( $query, ...$values );
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
		return $sql;
	}

	/**
	 * Read one owned record and reject database errors.
	 *
	 * @param string $sql sql.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$row = $this->call( 'get_row', $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( null !== $row && ! is_array( $row ) ) {
			throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
		return $row;
	}

	/**
	 * Invoke a database operation with explicit failure detection.
	 *
	 * @param string $method method.
	 * @param mixed  ...$args args.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function call( string $method, mixed ...$args ): mixed {
		$db             = $this->db();
		$db->last_error = '';
		$result         = $db->$method( ...$args );
		if ( false === $result || '' !== (string) ( $db->last_error ?? '' ) ) {
			throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
		return $result;
	}

	/**
	 * Resolve the injectable WordPress database dependency.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			throw new RuntimeException( 'ml_ai_storage_unavailable' ); }
		return $db;
	}

	/**
	 * Fail closed during installation maintenance, including a concurrent purge.
	 *
	 * @param bool $for_update Hold the installation row while committing a result.
	 * @return void
	 * @throws RuntimeException When the add-on is not in an executable state.
	 */
	private function assert_active( bool $for_update = false ): void {
		$sql = $this->sql( 'SELECT state FROM oj_ai_installations WHERE addon_id = %s LIMIT 1', 'oj-ai-dashboard' );
		$row = $this->row( $sql . ( $for_update ? ' FOR UPDATE' : '' ) );
		if ( null === $row || ! in_array( $row['state'] ?? null, array( 'registration_pending', 'runtime_ready' ), true ) ) {
			throw new RuntimeException( 'ml_ai_maintenance_required' );
		}
	}
}

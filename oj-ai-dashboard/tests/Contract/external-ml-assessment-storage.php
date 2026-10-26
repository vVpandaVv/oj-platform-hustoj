<?php
/**
 * Real SQL and private-file regression for the ML assessment command ledger.
 * SQLite executes production SQL; named locks are a connection-aware test shim.
 * MySQL/InnoDB cross-process locking remains a server integration check.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\ExternalAssessment\Assessment_Profile;
use OJAI\Dashboard\ExternalAssessment\WordPress_Assessment_Store;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;

$fixture_root = dirname( __DIR__, 3 ) . '/_notes/tmp/ojai-ml-storage-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture_root . '/public', 0700, true );
define( 'ABSPATH', $fixture_root . '/public/' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new \OJAI\Dashboard\Core\Autoloader( dirname( __DIR__, 2 ) . '/src' ) )->register();

/**
 * Assert a production storage invariant.
 *
 * @param bool   $ok Expected condition.
 * @param string $message Failure description.
 * @return void
 * @throws RuntimeException If the invariant fails.
 */
function ml_store_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
}

/**
 * Expect a stable failure from a production operation.
 *
 * @param callable $operation Operation to execute.
 * @param string   $code Expected failure code.
 * @return void
 * @throws RuntimeException If no expected failure occurs.
 */
function ml_store_fails( callable $operation, string $code ): void {
	try {
		$operation(); } catch ( Throwable $error ) {
		ml_store_assert( $error->getMessage() === $code, 'Expected ' . $code . ', got ' . $error->getMessage() );
		return;
		}
		throw new RuntimeException( 'Expected failure: ' . $code );
}

/** SQL adapter with simulated connection-scoped MySQL named locks. */
final class ML_Assessment_SQLite {
	/**
	 * Last database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Table whose insertion should fail.
	 *
	 * @var string
	 */
	public string $fail_insert = '';
	/**
	 * Whether to inject a commit failure.
	 *
	 * @var bool
	 */
	public bool $fail_commit = false;
	/**
	 * Whether to inject a rollback connection failure.
	 *
	 * @var bool
	 */
	public bool $fail_rollback = false;
	/**
	 * One-shot concurrent maintenance hook.
	 *
	 * @var Closure|null
	 */
	public ?Closure $before_begin = null;
	/**
	 * Executed SQL statements.
	 *
	 * @var array
	 */
	public array $queries = array();
	/**
	 * Simulated lock owners shared by fixture connections.
	 *
	 * @var array
	 */
	private static array $locks = array();
	/**
	 * Attach one connection to the shared test database.
	 *
	 * @param PDO $pdo Test database connection.
	 */
	public function __construct( public PDO $pdo ) {}
	/**
	 * Expand WordPress placeholders with SQLite escaping.
	 *
	 * @param string $query Query template.
	 * @param mixed  ...$args Bound values.
	 * @return string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$offset = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( array $match ) use ( $args, &$offset ): string {
				$value = $args[ $offset++ ];
				return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
	}
	/**
	 * Read a scalar, handling MySQL named locks separately.
	 *
	 * @param string $query Prepared query.
	 * @return mixed
	 */
	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		if ( 1 === preg_match( "/^SELECT (GET_LOCK|RELEASE_LOCK)\('([^']+)'/", $query, $match ) ) {
			$owner = spl_object_id( $this );
			if ( 'GET_LOCK' === $match[1] ) {
				if ( isset( self::$locks[ $match[2] ] ) && self::$locks[ $match[2] ] !== $owner ) {
					return 0; }
				self::$locks[ $match[2] ] = $owner;
				return 1;
			}
			if ( ( self::$locks[ $match[2] ] ?? null ) !== $owner ) {
				return 0; }
			unset( self::$locks[ $match[2] ] );
			return 1;
		}
		$value = $this->pdo->query( $query )->fetchColumn();
		return false === $value ? null : $value;
	}
	/**
	 * Execute a rowset query without unsupported SQLite row locks.
	 *
	 * @param string $query Prepared query.
	 * @param mixed  $format WordPress output mode.
	 * @return array
	 */
	public function get_results( string $query, mixed $format = null ): array {
		$this->queries[] = $query;
		return $this->pdo->query( str_replace( ' FOR UPDATE', '', $query ) )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Read one row.
	 *
	 * @param string $query Prepared query.
	 * @param mixed  $format WordPress output mode.
	 * @return array|null
	 */
	public function get_row( string $query, mixed $format = null ): ?array {
		return $this->get_results( $query, $format )[0] ?? null;
	}
	/**
	 * Execute transactional SQL with explicit fault injection.
	 *
	 * @param string $query Prepared statement.
	 * @return int|false
	 */
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( $this->fail_commit && 'COMMIT' === $query ) {
			return false; }
		if ( $this->fail_rollback && 'ROLLBACK' === $query ) {
			return false; }
		if ( 'START TRANSACTION' === $query && null !== $this->before_begin ) {
			$hook               = $this->before_begin;
			$this->before_begin = null;
			$hook();
		}
		return $this->pdo->exec( 'START TRANSACTION' === $query ? 'BEGIN' : $query );
	}
	/**
	 * Insert an owned row using bound values.
	 *
	 * @param string $table Physical table name.
	 * @param array  $data Column values.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		if ( $this->fail_insert === $table ) {
			return false; }
		$statement = $this->pdo->prepare( 'INSERT INTO `' . $table . '` (`' . implode( '`,`', array_keys( $data ) ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
		$statement->execute( array_values( $data ) );
		return $statement->rowCount();
	}
	/**
	 * Mutate one fixture row to simulate corruption or concurrency.
	 *
	 * @param string $table Physical table name.
	 * @param string $column Column name.
	 * @param mixed  $value Replacement value.
	 * @param int    $id Owned fixture row identifier.
	 * @return void
	 */
	public function change( string $table, string $column, mixed $value, int $id ): void {
		$statement = $this->pdo->prepare( 'UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE id = ?' );
		$statement->execute( array( $value, $id ) );
	}
}

$db    = null;
$store = null;
$other = null;
try {
	ml_store_assert( in_array( 'sqlite', PDO::getAvailableDrivers(), true ), 'Run PHP with -d extension=pdo_sqlite.' );
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	foreach ( ( new Schema_Catalog() )->tables() as $name => $table ) {
		if ( ! in_array( $name, array( 'oj_ai_outbox_events', 'oj_ai_private_files', 'oj_ai_installations' ), true ) ) {
			continue; }
		$columns = array();
		foreach ( $table->columns() as $column => $type ) {
			$columns[] = '`' . $column . '` ' . ( 'id' === $column ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : str_replace( array( ' UNSIGNED', ' AUTO_INCREMENT' ), '', $type ) );
		}
		$pdo->exec( 'CREATE TABLE `' . $name . '` (' . implode( ',', $columns ) . ')' );
	}
	$pdo->exec( 'CREATE UNIQUE INDEX ml_outbox_public_id ON oj_ai_outbox_events(public_id)' );
	$pdo->exec( 'CREATE UNIQUE INDEX ml_outbox_idempotency ON oj_ai_outbox_events(idempotency_key)' );
	$pdo->exec( 'CREATE UNIQUE INDEX ml_file_public_id ON oj_ai_private_files(public_id)' );
	$db       = new ML_Assessment_SQLite( $pdo );
	$other_db = new ML_Assessment_SQLite( $pdo );
	$files    = new Local_Private_File_Store( $fixture_root . '/private' );
	$store    = new WordPress_Assessment_Store( $files, $db );
	$other    = new WordPress_Assessment_Store( $files, $other_db );
	$db->insert(
		'oj_ai_installations',
		array(
			'addon_id'           => 'oj-ai-dashboard',
			'owner_marker'       => str_repeat( 'a', 64 ),
			'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
			'schema_fingerprint' => str_repeat( 'b', 64 ),
			'state'              => 'runtime_ready',
			'lock_version'       => 0,
			'created_by_user_id' => 1,
			'created_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
			'updated_by_user_id' => 1,
			'updated_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	$source      = 'oj-ml-playgrounds';
	$source_id   = '11111111-1111-4111-8111-111111111111';
	$metadata    = static fn( string $id = '11111111-1111-4111-8111-111111111111', string $input = 'first' ): array => array(
		'source'              => 'oj-ml-playgrounds',
		'source_id'           => $id,
		'actor_id'            => 7,
		'source_sha256'       => hash( 'sha256', 'submitted archive' ),
		'input_hash'          => hash( 'sha256', $input ),
		'routing_fingerprint' => hash( 'sha256', 'route' ),
		'consent_version'     => 'ml_external_ai.v1',
		'consented_at'        => gmdate( 'Y-m-d H:i:s' ),
	);
	$output      = array(
		'summary'      => 'PRIVATE_AI_FEEDBACK_SENTINEL',
		'criteria'     => array(
			array(
				'id'       => 'process',
				'score'    => 8,
				'feedback' => 'PRIVATE_CRITERION_SENTINEL',
				'evidence' => 'PRIVATE_EVIDENCE_SENTINEL',
			),
		),
		'total_score'  => 8,
		'max_score'    => 10,
		'needs_review' => false,
		'limitations'  => array( 'Not rerun.' ),
	);
	$count_rows  = static fn( string $table ): int => (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $table );
	$count_files = static function () use ( $fixture_root ): int {
		if ( ! is_dir( $fixture_root . '/private' ) ) {
			return 0; }
		return iterator_count( new CallbackFilterIterator( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture_root . '/private', FilesystemIterator::SKIP_DOTS ) ), static fn( SplFileInfo $file ): bool => $file->isFile() ) );
	};
	$complete    = static function ( array $meta, string $state = 'ready' ) use ( $store, $source, $output ): array {
		$store->enqueue( $meta );
		$claimed = $store->claim();
		ml_store_assert( is_array( $claimed ) && $claimed['source_id'] === $meta['source_id'], 'Expected requested queue head.' );
		$store->finish( $claimed, $state, $output );
		$store->release();
		return $store->latest( $source, $meta['source_id'] );
	};

	// Queue and worker locks protect the same immutable input from duplicate billing.
	ml_store_assert( null === $store->latest( $source, $source_id ), 'Initially empty source.' );
	$enqueue_lock = 'oj_ai_ml_' . substr( strtoupper( hash( 'sha256', $source . ':' . $source_id ) ), 0, 26 );
	$other_db->get_var( $other_db->prepare( 'SELECT GET_LOCK(%s, 0)', $enqueue_lock ) );
	ml_store_fails( static fn() => $store->enqueue( $metadata() ), 'ml_ai_busy' );
	ml_store_assert( 0 === $count_rows( 'oj_ai_outbox_events' ), 'Contended request cannot append a duplicate ledger row.' );
	$other_db->get_var( $other_db->prepare( 'SELECT RELEASE_LOCK(%s)', $enqueue_lock ) );
	$db->change( 'oj_ai_installations', 'state', 'purging', 1 );
	ml_store_fails( static fn() => $store->enqueue( $metadata() ), 'ml_ai_maintenance_required' );
	ml_store_fails( static fn() => $store->claim(), 'ml_ai_maintenance_required' );
	$db->change( 'oj_ai_installations', 'state', 'runtime_ready', 1 );
	$queued = $store->enqueue( $metadata() );
	$again  = $store->enqueue( $metadata() );
	ml_store_assert( $queued['run_id'] === $again['run_id'] && 1 === $count_rows( 'oj_ai_outbox_events' ), 'Queued request is idempotent.' );
	ml_store_assert( null === $store->latest( 'other-source', $source_id ), 'Source namespaces stay isolated.' );
	$ledger = $db->get_row( 'SELECT * FROM oj_ai_outbox_events' );
	ml_store_assert( null !== $ledger['published_at_utc'] && 'oj_ai.external_ml_metadata' === $ledger['payload_schema'], 'Ledger is not generic-dispatchable.' );
	ml_store_assert( ! str_contains( $ledger['payload_json'], 'PRIVATE_' ), 'Queue contains references, not evidence or output.' );
	$claimed = $store->claim();
	ml_store_assert( 'running' === $claimed['state'] && '' !== $claimed['_lease'], 'Claim creates a lease.' );
	ml_store_assert( null === $other->claim(), 'A second worker cannot claim while the global lock is owned.' );
	ml_store_assert( $store->enqueue( $metadata() )['run_id'] === $claimed['run_id'], 'Running request is idempotent.' );
	$store->finish( $claimed, 'ready', $output );
	$store->release();
	$ready = $store->latest( $source, $source_id );
	ml_store_assert( Assessment_Profile::encode( $store->result( $ready ) ) === Assessment_Profile::encode( $output ), 'Normalized output round-trips through the real private file.' );
	ml_store_assert( $store->enqueue( $metadata() )['run_id'] === $ready['run_id'], 'Ready result is reused without another run.' );
	ml_store_assert( null === $store->claim(), 'Ready jobs are never claimed again.' );
	$store->release();
	ml_store_assert( 1 === (int) $db->get_var( 'SELECT attempt_count FROM oj_ai_outbox_events WHERE id = 1' ), 'Only one worker attempt was recorded.' );
	$file = $db->get_row( 'SELECT * FROM oj_ai_private_files' );
	ml_store_assert( 'external_ml_assessment' === $file['owner_type'] && $file['owner_public_id'] === $ready['run_id'], 'Private ownership binds exactly one run.' );
	ml_store_assert( 'ml_assessment_result' === $file['purpose'] && 'clean' === $file['scan_state'] && 'ready' === $file['state'], 'Private result metadata is typed.' );
	ml_store_assert( abs( strtotime( $file['expires_at_utc'] . ' UTC' ) - time() - 30 * 86400 ) < 10, 'AI drafts have a bounded 30-day lifetime.' );
	ml_store_assert( ! str_contains( $db->get_var( 'SELECT payload_json FROM oj_ai_outbox_events WHERE id = 1' ), 'PRIVATE_' ), 'Finished ledger also excludes normalized feedback.' );

	// Ownership, integrity, size, expiry and legal holds are enforced on every read.
	foreach ( array(
		'owner_public_id' => 'other-run',
		'owner_type'      => 'other-owner',
		'state'           => 'purged',
		'scan_state'      => 'infected',
		'sha256'          => str_repeat( '0', 64 ),
		'byte_size'       => 100001,
	) as $field => $bad ) {
		$db->change( 'oj_ai_private_files', $field, $bad, (int) $file['id'] );
		ml_store_assert( null === $store->result( $ready ), 'Unsafe result rejected: ' . $field );
		$db->change( 'oj_ai_private_files', $field, $file[ $field ], (int) $file['id'] );
	}
	$db->change( 'oj_ai_private_files', 'expires_at_utc', '2000-01-01 00:00:00', (int) $file['id'] );
	ml_store_assert( null === $store->result( $ready ), 'Expired drafts are not readable.' );
	$db->change( 'oj_ai_private_files', 'legal_hold_until_utc', '2999-01-01 00:00:00', (int) $file['id'] );
	ml_store_assert( Assessment_Profile::encode( $store->result( $ready ) ) === Assessment_Profile::encode( $output ), 'Legal hold preserves access to expired retained output.' );
	$store->cleanup();
	ml_store_assert( Assessment_Profile::encode( $store->result( $ready ) ) === Assessment_Profile::encode( $output ), 'Cleanup respects legal hold.' );
	$db->change( 'oj_ai_private_files', 'legal_hold_until_utc', null, (int) $file['id'] );
	$store->cleanup();
	ml_store_assert( null === $store->result( $ready ) && ! is_file( $fixture_root . '/private/' . $file['storage_key'] ), 'Expired result bytes are removed.' );
	ml_store_assert( 'purged' === $db->get_var( 'SELECT state FROM oj_ai_private_files WHERE id = 1' ), 'Removed file metadata remains a tombstone.' );
	$renewed = $store->enqueue( $metadata() );
	ml_store_assert( $renewed['run_id'] !== $ready['run_id'], 'Explicit request may regenerate an expired result.' );
	$claimed = $store->claim();
	$store->finish( $claimed, 'failed', null, 'ml_ai_provider_failed' );
	$store->release();
	ml_store_assert( null === $store->claim(), 'Failed provider calls do not retry automatically.' );
	$store->release();

	// In-flight interruption is not silently billed again; only a new user enqueue retries.
	$retry       = $store->enqueue( $metadata() );
	$interrupted = $store->claim();
	$store->release();
	$db->change( 'oj_ai_outbox_events', 'lease_expires_at_utc', '2000-01-01 00:00:00', $interrupted['_id'] );
	ml_store_assert( null === $other->claim(), 'Expired running work is not reclaimed as another provider call.' );
	$other->release();
	$failed = $store->latest( $source, $source_id );
	ml_store_assert( 'failed' === $failed['state'] && 'ml_ai_interrupted' === $failed['error_code'], 'Interrupted billing state is explicit.' );
	ml_store_assert( 1 === $db->get_var( 'SELECT attempt_count FROM oj_ai_outbox_events WHERE id = ' . $interrupted['_id'] ), 'Lease expiry does not increment provider attempts.' );
	$explicit = $store->enqueue( $metadata() );
	ml_store_assert( $explicit['run_id'] !== $retry['run_id'], 'Explicit retry creates a separate auditable run.' );
	$active = $store->claim();

	// Failed insert/commit and stale leases roll back metadata and remove orphan bytes.
	foreach ( array( 'insert', 'commit', 'lease', 'maintenance' ) as $fault ) {
		$rows_before     = $count_rows( 'oj_ai_private_files' );
		$bytes_before    = $count_files();
		$db->fail_insert = 'insert' === $fault ? 'oj_ai_private_files' : '';
		$db->fail_commit = 'commit' === $fault;
		$wrong           = $active;
		if ( 'lease' === $fault ) {
			$wrong['_lease'] = 'wrong-lease'; }
		if ( 'maintenance' === $fault ) {
			$db->before_begin = static fn() => $db->change( 'oj_ai_installations', 'state', 'purging', 1 ); }
		$expected_code = 'lease' === $fault ? 'ml_ai_lease_lost' : ( 'maintenance' === $fault ? 'ml_ai_maintenance_required' : 'ml_ai_storage_unavailable' );
		ml_store_fails( static fn() => $store->finish( $wrong, 'ready', $output ), $expected_code );
		$db->fail_insert = '';
		$db->fail_commit = false;
		$db->change( 'oj_ai_installations', 'state', 'runtime_ready', 1 );
		ml_store_assert( ! $pdo->inTransaction(), 'Failed finish rolls back its transaction.' );
		ml_store_assert( $count_rows( 'oj_ai_private_files' ) === $rows_before && $count_files() === $bytes_before, 'No orphan bytes or metadata after ' . $fault );
		ml_store_assert( 'running' === $store->latest( $source, $source_id )['state'], 'Failed finish cannot finalize the job.' );
	}
	// A failed database rollback must not skip compensation of private result bytes.
	$rows_before       = $count_rows( 'oj_ai_private_files' );
	$bytes_before      = $count_files();
	$db->fail_commit   = true;
	$db->fail_rollback = true;
	ml_store_fails( static fn() => $store->finish( $active, 'ready', $output ), 'ml_ai_storage_unavailable' );
	ml_store_assert( $count_files() === $bytes_before, 'Rollback failure still removes newly written private bytes.' );
	$pdo->exec( 'ROLLBACK' );
	$db->fail_commit   = false;
	$db->fail_rollback = false;
	ml_store_assert( $count_rows( 'oj_ai_private_files' ) === $rows_before, 'Fixture connection recovery leaves no result metadata.' );
	ml_store_assert( 'running' === $store->latest( $source, $source_id )['state'], 'Rollback failure does not finalize the job.' );
	ml_store_fails( static fn() => $store->finish( $active, 'ready', null ), 'ml_ai_invalid_state' );
	$store->finish( $active, 'needs_review', $output );
	$store->release();
	ml_store_assert( $store->enqueue( $metadata() )['run_id'] === $active['run_id'], 'Needs-review output is not blindly regenerated.' );
	$current      = $store->latest( $source, $source_id );
	$current_file = $db->get_row( $db->prepare( 'SELECT * FROM oj_ai_private_files WHERE public_id = %s', $current['result_file_id'] ) );
	$files->delete( $current_file['storage_key'] );
	$recovered = $complete( $metadata() );
	ml_store_assert( $recovered['run_id'] !== $current['run_id'], 'Missing private bytes allow explicit recovery.' );

	// Tampered ledger bytes cannot be interpreted as commands or results.
	$ledger = $db->get_row( $db->prepare( 'SELECT * FROM oj_ai_outbox_events WHERE public_id = %s', $recovered['run_id'] ) );
	foreach ( array(
		'payload_json'    => $ledger['payload_json'] . ' ',
		'payload_hash'    => str_repeat( '0', 64 ),
		'payload_version' => 2,
		'payload_schema'  => 'other-schema',
		'public_id'       => 'OTHER_PUBLIC_ID',
	) as $field => $bad ) {
		$db->change( 'oj_ai_outbox_events', $field, $bad, (int) $ledger['id'] );
		ml_store_fails( static fn() => $store->latest( $source, $source_id ), 'ml_ai_storage_invalid' );
		$db->change( 'oj_ai_outbox_events', $field, $ledger[ $field ], (int) $ledger['id'] );
	}

	// A changed approved provider route must not reuse a previous model's result.
	$route_metadata                        = $metadata( '33333333-3333-4333-8333-333333333333' );
	$old_route_run                         = $complete( $route_metadata );
	$route_metadata['routing_fingerprint'] = hash( 'sha256', 'newly approved route' );
	$before_route_change                   = $count_rows( 'oj_ai_outbox_events' );
	$new_route_run                         = $complete( $route_metadata );
	ml_store_assert( $old_route_run['run_id'] !== $new_route_run['run_id'], 'Changed routing creates a new approved run.' );
	ml_store_assert( $old_route_run['input_hash'] === $new_route_run['input_hash'], 'The fixture changed only routing, not evidence.' );
	ml_store_assert( $new_route_run['routing_fingerprint'] === $route_metadata['routing_fingerprint'], 'New run records the newly approved route.' );
	ml_store_assert( $before_route_change + 1 === $count_rows( 'oj_ai_outbox_events' ), 'Exactly one run is created for changed routing.' );
	ml_store_assert( $store->enqueue( $route_metadata )['run_id'] === $new_route_run['run_id'], 'Same new route and input still deduplicate.' );
	ml_store_assert( Assessment_Profile::encode( $store->result( $old_route_run ) ) === Assessment_Profile::encode( $output ), 'New routing leaves the old private result immutable.' );

	// Common sources share persistence, not identities, even for identical record IDs.
	$common_meta                    = $metadata( 'submission:42' );
	$common_meta['source']          = 'example-plugin';
	$common_meta['consent_version'] = 'external_assessment_ai.v1';
	$common_first                   = $store->enqueue( $common_meta );
	$common_meta['source']          = 'other-plugin';
	$common_second                  = $store->enqueue( $common_meta );
	ml_store_assert( $common_first['run_id'] !== $common_second['run_id'], 'Common sources never share a run despite identical IDs, input hashes and routing.' );
	foreach ( array( $common_first, $common_second ) as $expected_run ) {
		$common_claimed = $store->claim();
		ml_store_assert( $expected_run['run_id'] === $common_claimed['run_id'], 'Common queue uses the same serial worker.' );
		$store->finish( $common_claimed, 'ready', $output );
		$store->release();
		$common_ready = $store->latest( $expected_run['source'], $expected_run['source_id'] );
		ml_store_assert( $expected_run['run_id'] === $common_ready['run_id'] && Assessment_Profile::encode( $output ) === Assessment_Profile::encode( $store->result( $common_ready ) ), 'Each common source retrieves its own private result.' );
	}
	ml_store_assert( null === $store->latest( 'unregistered-plugin', 'submission:42' ), 'Other source cannot retrieve common output.' );

	// Cleanup is scoped, bounded to twenty files and keeps held rows untouched.
	$expiry_ids = array();
	for ( $index = 0; $index < 24; ++$index ) {
		$id           = sprintf( '22222222-2222-4222-8222-%012d', $index );
		$run          = $complete( $metadata( $id ) );
		$row          = $db->get_row( $db->prepare( 'SELECT * FROM oj_ai_private_files WHERE public_id = %s', $run['result_file_id'] ) );
		$expiry_ids[] = (int) $row['id'];
		$db->change( 'oj_ai_private_files', 'expires_at_utc', '2000-01-01 00:00:00', (int) $row['id'] );
	}
	$hold_id    = $expiry_ids[0];
	$foreign_id = $expiry_ids[1];
	$db->change( 'oj_ai_private_files', 'legal_hold_until_utc', '2999-01-01 00:00:00', $hold_id );
	$db->change( 'oj_ai_private_files', 'owner_type', 'other-owner', $foreign_id );
	$store->cleanup();
	ml_store_assert( 20 === (int) $db->get_var( 'SELECT COUNT(*) FROM oj_ai_private_files WHERE id IN (' . implode( ',', $expiry_ids ) . ") AND state = 'purged'" ), 'Cleanup processes at most twenty eligible rows.' );
	foreach ( array( $hold_id, $foreign_id ) as $preserved_id ) {
		$row = $db->get_row( 'SELECT * FROM oj_ai_private_files WHERE id = ' . $preserved_id );
		ml_store_assert( 'ready' === $row['state'] && is_file( $fixture_root . '/private/' . $row['storage_key'] ), 'Cleanup preserves held and non-ML owners.' );
	}
	$store->cleanup();
	ml_store_assert( 22 === (int) $db->get_var( 'SELECT COUNT(*) FROM oj_ai_private_files WHERE id IN (' . implode( ',', $expiry_ids ) . ") AND state = 'purged'" ), 'The next cleanup processes the remaining two eligible files.' );
	ml_store_assert( ! $pdo->inTransaction(), 'Cleanup closes its transaction.' );
	echo "ML assessment storage passed: real schema SQL/private files, idempotent billing guards, worker lock/lease, explicit retry, rollback, hash/owner checks, TTL, legal hold and bounded cleanup. MySQL locks need server verification.\n";
} finally {
	if ( $store instanceof WordPress_Assessment_Store ) {
		$store->release(); }
	if ( $other instanceof WordPress_Assessment_Store ) {
		$other->release(); }
	$resolved = realpath( $fixture_root );
	if ( false !== $resolved && str_starts_with( basename( $resolved ), 'ojai-ml-storage-' ) ) {
		$boundary = strtolower( str_replace( '\\', '/', $resolved ) ) . '/';
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $resolved, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$real = $item->getRealPath();
			if ( false === $real || ! str_starts_with( strtolower( str_replace( '\\', '/', $real ) ), $boundary ) || $item->isLink() ) {
				throw new RuntimeException( 'Unsafe test cleanup path.' ); }
			$item->isDir() ? rmdir( $real ) : unlink( $real );
		}
		rmdir( $resolved );
	}
}

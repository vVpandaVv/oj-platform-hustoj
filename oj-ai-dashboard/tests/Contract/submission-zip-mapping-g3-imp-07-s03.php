<?php
/**
 * G3-IMP-07-S03 C68 explicit submission ZIP mapping contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-zip-scan-preview-g3-imp-07-s02.php';

use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items;
use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items_Command;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Mapping_Repository;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Preview_Query;
use OJAI\Dashboard\Submission\ZipImport\Presentation\Submission_Zip_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-07-S03 C68 explicit submission ZIP mapping contract.
 */
final class C68_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Root.
	 *
	 * @var array<string,mixed>
	 */
	public array $root;
	/**
	 * Items.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items;
	/**
	 * Targets.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $targets;
	/**
	 * Audit.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $audit = array();
	/**
	 * Outbox.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $outbox = array();
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Initialize c68 database dependencies and state.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<int,array<string,mixed>> $targets Targets.
	 */
	public function __construct( array $root, array $items, array $targets ) {
		$this->root    = $root;
		$this->items   = $items;
		$this->targets = $targets;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'C68:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		[ $sql, $arguments ] = $this->decode( $prepared );
		$this->queries[]     = $sql;
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->outbox as $row ) {
				if ( ( $arguments[0] ?? null ) === ( $row['idempotency_key'] ?? null ) ) {
					return array_intersect_key(
						$row,
						array_flip(
							array(
								'aggregate_type',
								'aggregate_public_id',
								'event_type',
								'payload_schema',
								'payload_version',
								'payload_json',
								'payload_hash',
							)
						)
					);
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'u.id AS upload_pk' ) ) {
			return ( $arguments[0] ?? null ) === $this->root['upload_id'] ? $this->root : null;
		}
		throw new RuntimeException( 'Unexpected C68 get_row SQL: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, mixed $output = null ): array {
		unset( $output );
		[ $sql, $arguments ] = $this->decode( $prepared );
		$this->queries[]     = $sql;
		if ( str_contains( $sql, 'FROM oj_ai_zip_upload_items' ) ) {
			return ( $arguments[0] ?? null ) === $this->root['upload_pk'] ? $this->items : array();
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignment_targets' ) ) {
			return ( $arguments[0] ?? null ) === $this->root['assignment_pk'] ? $this->targets : array();
		}
		throw new RuntimeException( 'Unexpected C68 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ): mixed {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$last = end( $this->audit );
			return is_array( $last ) ? ( $last['event_hash'] ?? null ) : null;
		}
		throw new RuntimeException( 'Unexpected C68 get_var SQL: ' . $sql );
	}

	/**
	 * Query.
	 *
	 * @param string $value Value.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $value ): int|false {
		if ( 'START TRANSACTION' === $value ) {
			$this->snapshot  = unserialize( serialize( array( $this->root, $this->items, $this->audit, $this->outbox ) ), array( 'allowed_classes' => false ) );
			$this->queries[] = $value;
			return 1;
		}
		if ( 'COMMIT' === $value ) {
			$this->snapshot  = null;
			$this->queries[] = $value;
			return 1;
		}
		if ( 'ROLLBACK' === $value ) {
			if ( is_array( $this->snapshot ) ) {
				[ $this->root, $this->items, $this->audit, $this->outbox ] = $this->snapshot;
			}
			$this->snapshot  = null;
			$this->queries[] = $value;
			return 1;
		}

		[ $sql, $arguments ] = $this->decode( $value );
		$this->queries[]     = $sql;
		if ( str_starts_with( $sql, 'UPDATE oj_ai_zip_upload_items' ) ) {
			$excluded      = str_contains( $sql, 'learner_user_id = NULL' );
			$id            = $arguments[ $excluded ? 3 : 4 ] ?? null;
			$lock          = $arguments[ $excluded ? 4 : 5 ] ?? null;
			$current_state = $arguments[ $excluded ? 5 : 6 ] ?? null;
			foreach ( $this->items as &$row ) {
				if ( $row['id'] !== $id || $row['lock_version'] !== $lock || $row['state'] !== $current_state ) {
					continue;
				}
				$row['learner_user_id'] = $excluded ? null : $arguments[0];
				$row['state']           = $arguments[ $excluded ? 0 : 1 ];
				if ( ! $excluded ) {
					$row['error_code'] = null;
				}
				++$row['lock_version'];
				$row['updated_by_user_id'] = $arguments[ $excluded ? 1 : 2 ];
				$row['updated_at_utc']     = $arguments[ $excluded ? 2 : 3 ];
				unset( $row );
				return 1;
			}
			unset( $row );
			return 0;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_zip_uploads' ) ) {
			if (
				$this->root['upload_pk'] !== ( $arguments[5] ?? null ) || $this->root['upload_id'] !== ( $arguments[6] ?? null )
				|| $this->root['upload_lock_version'] !== ( $arguments[7] ?? null ) || $this->root['upload_state'] !== ( $arguments[8] ?? null )
				|| $this->root['mapping_schema'] !== ( $arguments[9] ?? null ) || $this->root['mapping_version'] !== ( $arguments[10] ?? null )
				|| $this->root['mapping_hash'] !== ( $arguments[11] ?? null )
			) {
				return 0;
			}
			$this->root['upload_state'] = $arguments[0];
			$this->root['mapping_json'] = $arguments[1];
			$this->root['mapping_hash'] = $arguments[2];
			++$this->root['upload_lock_version'];
			$this->root['updated_by_user_id'] = $arguments[3];
			$this->root['updated_at_utc']     = $arguments[4];
			return 1;
		}
		throw new RuntimeException( 'Unexpected C68 query SQL: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $record Record.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $record ): int|false {
		++$this->insert_id;
		if ( 'oj_ai_audit_events' === $table ) {
			$this->audit[] = $record;
			return 1;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			$this->outbox[] = $record;
			return 1;
		}
		throw new RuntimeException( 'Unexpected C68 insert table: ' . $table );
	}

	/**
	 * Decode c68 database.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $value ): array {
		if ( ! str_starts_with( $value, 'C68:' ) ) {
			throw new RuntimeException( 'C68 SQL was not prepared.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) ) {
			throw new RuntimeException( 'C68 prepared SQL is invalid.' );
		}
		return $decoded;
	}
}

/**
 * C68 mapping.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $response_a Response a.
 * @param string         $response_b Response b.
 * @param string         $target_a Target a.
 * @return array<string,mixed>
 */
function c68_mapping( Canonical_Json $canonical, string $response_a, string $response_b, string $target_a ): array {
	$source = array(
		'byte_size'              => 300,
		'compressed_entry_bytes' => 120,
		'entry_count'            => 3,
		'sha256'                 => hash( 'sha256', 'c68-source' ),
		'uncompressed_bytes'     => 240,
	);
	return array(
		'items'            => array(
			array(
				'compatible_response_ids' => array( $response_a ),
				'detected_mime_type'      => 'text/plain',
				'item_no'                 => 1,
				'selection'               => null,
				'suggestion'              => array(
					'learner'  => array(
						'learner_user_id' => 101,
						'method'          => 'exact_path_token',
						'target_id'       => $target_a,
					),
					'response' => array(
						'method'      => 'exact_path_token',
						'response_id' => $response_a,
					),
				),
			),
			array(
				'compatible_response_ids' => array( $response_b ),
				'detected_mime_type'      => 'application/pdf',
				'item_no'                 => 2,
				'selection'               => null,
				'suggestion'              => array(
					'learner'  => null,
					'response' => null,
				),
			),
			array(
				'compatible_response_ids' => array(),
				'detected_mime_type'      => null,
				'item_no'                 => 3,
				'selection'               => null,
				'suggestion'              => array(
					'learner'  => null,
					'response' => null,
				),
			),
		),
		'mapping_revision' => 1,
		'scan_error_code'  => null,
		'source'           => $source,
	);
}

/**
 * C68 database.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array          $ids Ids.
 * @return C68_Database
 */
function c68_database( Canonical_Json $canonical, array $ids ): C68_Database {
	$mapping      = c68_mapping( $canonical, $ids['response_a'], $ids['response_b'], $ids['target_a'] );
	$mapping_json = $canonical->encode( $mapping );
	return new C68_Database(
		array(
			'upload_pk'           => 11,
			'upload_id'           => $ids['upload'],
			'upload_lock_version' => 2,
			'upload_state'        => 'ready',
			'mapping_schema'      => 'oj_ai.submission_zip_mapping',
			'mapping_version'     => 1,
			'mapping_json'        => $mapping_json,
			'mapping_hash'        => hash( 'sha256', $mapping_json ),
			'item_total'          => 3,
			'success_total'       => 2,
			'failure_total'       => 1,
			'assignment_pk'       => 7,
			'assignment_id'       => $ids['assignment'],
			'assignment_state'    => 'open',
			'context_type'        => 'class',
			'context_id'          => 'class-21',
			'context_revision'    => 'class-r4',
			'created_at_utc'      => '2026-08-22 11:00:00',
			'updated_at_utc'      => '2026-08-22 12:00:00',
		),
		array(
			array(
				'id'              => 21,
				'lock_version'    => 0,
				'item_no'         => 1,
				'learner_user_id' => null,
				'state'           => 'ready',
				'error_code'      => null,
				'relative_path'   => 'student-101/a.txt',
				'byte_size'       => 80,
			),
			array(
				'id'              => 22,
				'lock_version'    => 0,
				'item_no'         => 2,
				'learner_user_id' => null,
				'state'           => 'ready',
				'error_code'      => null,
				'relative_path'   => 'student-102/b.pdf',
				'byte_size'       => 160,
			),
			array(
				'id'              => 23,
				'lock_version'    => 0,
				'item_no'         => 3,
				'learner_user_id' => null,
				'state'           => 'rejected',
				'error_code'      => 'zip_item_type_not_allowed',
				'relative_path'   => 'student-103/c.html',
				'byte_size'       => 0,
			),
		),
		array(
			array(
				'target_id'       => $ids['target_a'],
				'learner_user_id' => 101,
				'state'           => 'active',
			),
			array(
				'target_id'       => $ids['target_b'],
				'learner_user_id' => 102,
				'state'           => 'active',
			),
		)
	);
}

/**
 * C68 command.
 *
 * @param array                          $ids Ids.
 * @param array<int,array<string,mixed>> $items Items.
 * @param int                            $version Version.
 * @param string                         $fingerprint Fingerprint.
 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
 * @return Map_Submission_Zip_Items_Command
 */
function c68_command( array $ids, array $items, int $version, string $fingerprint, Canonical_Json $canonical ): Map_Submission_Zip_Items_Command {
	return new Map_Submission_Zip_Items_Command(
		42,
		$ids['upload'],
		'zip-map-command-0001',
		$version,
		$items,
		'class',
		'class-21',
		'class-r4',
		hash( 'sha256', $fingerprint ),
		'zip-map-correlation-0001',
		$canonical
	);
}

$c68_seed          = 130;
$c68_ids_generator = new Public_Id_Generator(
	static function ( int $length ) use ( &$c68_seed ): string {
		++$c68_seed;
		return str_repeat( chr( $c68_seed ), $length );
	}
);
$c68_ids           = array(
	'assignment'     => $c68_ids_generator->create()->value(),
	'upload'         => $c68_ids_generator->create()->value(),
	'target_a'       => $c68_ids_generator->create()->value(),
	'target_b'       => $c68_ids_generator->create()->value(),
	'response_a'     => $c68_ids_generator->create()->value(),
	'response_b'     => $c68_ids_generator->create()->value(),
	'response_other' => $c68_ids_generator->create()->value(),
);
$c68_canonical     = new Canonical_Json();
$c68_decisions     = array(
	array(
		'item_no'         => 2,
		'target_id'       => $c68_ids['target_b'],
		'learner_user_id' => 102,
		'response_id'     => $c68_ids['response_b'],
	),
	array(
		'item_no'         => 1,
		'target_id'       => $c68_ids['target_a'],
		'learner_user_id' => 101,
		'response_id'     => $c68_ids['response_a'],
	),
);

$c68_db         = c68_database( $c68_canonical, $c68_ids );
$c68_repository = new WordPress_Submission_Zip_Mapping_Repository( $c68_db, $c68_canonical );
$c68_use_case   = new Map_Submission_Zip_Items( $c68_repository, $c68_ids_generator, static fn (): string => '2026-08-22 13:00:00' );
$c68_command    = c68_command( $c68_ids, $c68_decisions, 1, 'c68-key-1', $c68_canonical );
g3_same( array( 1, 2 ), array_column( $c68_command->items(), 'item_no' ), 'C68 command must canonicalize item order before hashing.' );
$c68_result = $c68_use_case->execute( $c68_command );
g3_same( false, $c68_result->replayed(), 'The first C68 command must not be a replay.' );
g3_same( 'mapped', $c68_result->upload()['state'] ?? null, 'C68 must move the ZIP root to mapped.' );
g3_same( 2, $c68_result->upload()['mapping_version'] ?? null, 'C68 must increment mapping version exactly once.' );
g3_same( 2, $c68_result->upload()['mapped_total'] ?? null, 'C68 must map every clean item.' );
g3_same( 1, $c68_result->upload()['excluded_total'] ?? null, 'C68 must explicitly exclude rejected scan items.' );
g3_same( array( 'mapped', 'mapped', 'excluded' ), array_column( $c68_db->items, 'state' ), 'C68 item state transition drifted.' );
g3_same( array( 101, 102, null ), array_column( $c68_db->items, 'learner_user_id' ), 'C68 must persist only exact learner mappings.' );
g3_same( 1, count( $c68_db->audit ), 'C68 must write one audit event.' );
g3_same( 1, count( $c68_db->outbox ), 'C68 must write one durable outbox receipt.' );
$c68_stored_mapping = json_decode( $c68_db->root['mapping_json'], true, 512, JSON_THROW_ON_ERROR );
g3_same( $c68_ids['response_a'], $c68_stored_mapping['items'][0]['selection']['response_id'] ?? null, 'C68 must persist the exact upload response ID.' );
g3_same( null, $c68_stored_mapping['items'][2]['selection'] ?? null, 'Rejected items must never gain a mapping selection.' );

$c68_db->root['assignment_state'] = 'withdrawn';
$c68_replay                       = $c68_use_case->execute( $c68_command );
g3_same( true, $c68_replay->replayed(), 'An exact C68 retry must replay before mutable assignment state checks.' );
g3_same( 1, count( $c68_db->audit ), 'C68 replay must not duplicate audit evidence.' );
g3_same( 1, count( $c68_db->outbox ), 'C68 replay must not duplicate the outbox receipt.' );

$changed_decisions                   = $c68_decisions;
$changed_decisions[1]['response_id'] = $c68_ids['response_b'];
$changed_command                     = c68_command( $c68_ids, $changed_decisions, 1, 'c68-key-1', $c68_canonical );
z19_failure( static fn () => $c68_use_case->execute( $changed_command ), Zip_Import_Failure::IDEMPOTENCY_CONFLICT, 'A reused C68 idempotency key with another digest must conflict.' );

$stale_db       = c68_database( $c68_canonical, $c68_ids );
$stale_use_case = new Map_Submission_Zip_Items( new WordPress_Submission_Zip_Mapping_Repository( $stale_db, $c68_canonical ), $c68_ids_generator, static fn (): string => '2026-08-22 13:01:00' );
$stale_json     = $stale_db->root['mapping_json'];
z19_failure( static fn () => $stale_use_case->execute( c68_command( $c68_ids, $c68_decisions, 2, 'c68-key-stale', $c68_canonical ) ), Zip_Import_Failure::STATE_CONFLICT, 'A stale C68 mapping version must lose the CAS.' );
g3_same( $stale_json, $stale_db->root['mapping_json'], 'A stale C68 command must not change the root mapping.' );
g3_same( array(), $stale_db->audit, 'A stale C68 command must not write audit evidence.' );
g3_same( array(), $stale_db->outbox, 'A stale C68 command must not write an outbox event.' );

$invalid_response_db                      = c68_database( $c68_canonical, $c68_ids );
$invalid_response_items                   = $c68_decisions;
$invalid_response_items[1]['response_id'] = $c68_ids['response_other'];
$invalid_response_use_case                = new Map_Submission_Zip_Items( new WordPress_Submission_Zip_Mapping_Repository( $invalid_response_db, $c68_canonical ), $c68_ids_generator, static fn (): string => '2026-08-22 13:02:00' );
z19_failure( static fn () => $invalid_response_use_case->execute( c68_command( $c68_ids, $invalid_response_items, 1, 'c68-key-response', $c68_canonical ) ), Zip_Import_Failure::MAPPING_INVALID, 'C68 must reject a response outside the scan compatibility set.' );
g3_same( 'ready', $invalid_response_db->root['upload_state'], 'Invalid response mapping must roll back root state.' );

$removed_target_db                      = c68_database( $c68_canonical, $c68_ids );
$removed_target_db->targets[0]['state'] = 'removed';
$removed_target_use_case                = new Map_Submission_Zip_Items( new WordPress_Submission_Zip_Mapping_Repository( $removed_target_db, $c68_canonical ), $c68_ids_generator, static fn (): string => '2026-08-22 13:03:00' );
z19_failure( static fn () => $removed_target_use_case->execute( c68_command( $c68_ids, $c68_decisions, 1, 'c68-key-removed', $c68_canonical ) ), Zip_Import_Failure::MAPPING_INVALID, 'C68 must reject a removed assignment target.' );

$missing_db       = c68_database( $c68_canonical, $c68_ids );
$missing_use_case = new Map_Submission_Zip_Items( new WordPress_Submission_Zip_Mapping_Repository( $missing_db, $c68_canonical ), $c68_ids_generator, static fn (): string => '2026-08-22 13:04:00' );
z19_failure( static fn () => $missing_use_case->execute( c68_command( $c68_ids, array( $c68_decisions[1] ), 1, 'c68-key-missing', $c68_canonical ) ), Zip_Import_Failure::MAPPING_INVALID, 'C68 must reject a missing clean-item mapping.' );
g3_same( array( 'ready', 'ready', 'rejected' ), array_column( $missing_db->items, 'state' ), 'Missing mapping failure must roll back all item states.' );

g3_invalid(
	static fn () => c68_command( $c68_ids, array( $c68_decisions[1], $c68_decisions[1] ), 1, 'c68-key-duplicate', $c68_canonical ),
	'C68 command must reject duplicate item numbers.'
);

$mapped_preview_root  = array(
	'upload_pk'       => $c68_db->root['upload_pk'],
	'upload_id'       => $c68_db->root['upload_id'],
	'assignment_id'   => $c68_db->root['assignment_id'],
	'created_at_utc'  => $c68_db->root['created_at_utc'],
	'updated_at_utc'  => $c68_db->root['updated_at_utc'],
	'state'           => 'mapped',
	'mapping_schema'  => $c68_db->root['mapping_schema'],
	'mapping_version' => $c68_db->root['mapping_version'],
	'mapping_json'    => $c68_db->root['mapping_json'],
	'mapping_hash'    => $c68_db->root['mapping_hash'],
	'item_total'      => $c68_db->root['item_total'],
	'success_total'   => $c68_db->root['success_total'],
	'failure_total'   => $c68_db->root['failure_total'],
);
$mapped_preview_items = array_map(
	static fn ( array $item ): array => array_intersect_key( $item, array_flip( array( 'item_no', 'relative_path', 'state', 'byte_size', 'error_code' ) ) ),
	$c68_db->items
);
$mapped_preview       = ( new WordPress_Submission_Zip_Preview_Query( new Q35_Preview_Database( $mapped_preview_root, $mapped_preview_items ), $c68_canonical ) )
	->find_preview( $c68_ids['upload'], 'class', 'class-21', 'class-r4' );
g3_same( $c68_ids['target_a'], $mapped_preview['items'][0]['selection']['target_id'] ?? null, 'Q35 must expose the safe confirmed C68 selection.' );
g3_same( null, $mapped_preview['items'][2]['selection'] ?? null, 'Q35 must keep excluded item selection null.' );

/**
 * G3-IMP-07-S03 C68 explicit submission ZIP mapping contract.
 */
final class C68_Request {
	/**
	 * Initialize c68 request dependencies and state.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	public function __construct( private array $body ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->body;
	}
}
$c68_controller   = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$c68_input_method = ( new ReflectionClass( Submission_Zip_Rest_Controller::class ) )->getMethod( 'map_input' );
$c68_body         = array(
	'command_id'               => 'zip-map-rest-0001',
	'expected_mapping_version' => 1,
	'items'                    => $c68_decisions,
);
$c68_parsed       = $c68_input_method->invoke( $c68_controller, new C68_Request( $c68_body ) );
g3_same( 1, $c68_parsed['expected_mapping_version'] ?? null, 'C68 REST body must preserve an integer mapping version.' );
$c68_body['unknown'] = true;
g3_invalid( static fn () => $c68_input_method->invoke( $c68_controller, new C68_Request( $c68_body ) ), 'C68 REST must reject unknown body fields.' );

$addon_root  = dirname( __DIR__, 2 );
$c68_sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Application/Map_Submission_Zip_Items_Command.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Application/Map_Submission_Zip_Items.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Infrastructure/WordPress_Submission_Zip_Mapping_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/ZipImport/Presentation/Submission_Zip_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $c68_sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C68 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C68 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C68 must not consume OJ Platform internals.' );
}
foreach ( array( "'oj_ai_zip_uploads'", "'oj_ai_zip_upload_items'", "'oj_ai_assignment_targets'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", 'START TRANSACTION', 'FOR UPDATE', 'expected_mapping_version' ) as $marker ) {
	g3_assert( str_contains( $c68_sources[2], $marker ), 'C68 repository marker is missing: ' . $marker );
}
foreach ( array( 'ROUTE_MAP', "'POST'", 'ACTION_MAP', 'MANAGE_SUBMISSIONS', "'import_zip'", 'Map_Submission_Zip_Items_Command' ) as $marker ) {
	g3_assert( str_contains( $c68_sources[3], $marker ), 'C68 REST marker is missing: ' . $marker );
}
g3_assert( str_contains( $c68_sources[4], 'WordPress_Submission_Zip_Mapping_Repository' ), 'The single Composition Root must wire C68 mapping.' );
$c68_sql = implode( "\n", $c68_db->queries );
g3_assert( ! str_contains( $c68_sql, 'oj_ai_submissions' ) && ! str_contains( $c68_sql, 'oj_ai_submission_revisions' ), 'C68 must not create a submission or revision.' );

echo "G3-IMP-07-S03 passed: exact target/learner/response mapping, version CAS, atomic item/root/audit/outbox, durable replay, rollback, and Q35 safe selection.\n";

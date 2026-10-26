<?php
/**
 * G2-IMP-02-S05 immutable draft revision transaction contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Curriculum\Infrastructure\WordPress_Achievement_Standard_Snapshot_Reader;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Port\Achievement_Standard_Snapshot_Port_Interface;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S05 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Worksheet_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s05_failure( callable $callback, string $reason, string $message ): Worksheet_Failure {
	try {
		$callback();
	} catch ( Worksheet_Failure $failure ) {
		s05_same( $reason, $failure->reason(), $message );
		return $failure;
	}
	throw new RuntimeException( $message . ' Expected failure was not raised.' );
}

/**
 * S05 id.
 *
 * @param int $number Number.
 * @return string
 */
function s05_id( int $number ): string {
	$id = '01K' . str_pad( (string) $number, 23, '0', STR_PAD_LEFT );
	s05_assert( Public_Id::is_valid( $id ), 'S05 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * S05 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function s05_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * S05 document.
 *
 * @param string $document_id Document id.
 * @param string $response_one Response one.
 * @param string $response_two Response two.
 * @param string $title Title.
 * @return array<string,mixed>
 */
function s05_document( string $document_id, string $response_one, string $response_two, string $title ): array {
	return array(
		'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
		'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
		'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
		'document_id'    => $document_id,
		'locale'         => 'ko-KR',
		'title'          => $title,
		'nodes'          => array(
			array(
				'type'     => 'h1',
				'node_id'  => s05_id( 110 ),
				'children' => array( array( 'text' => $title ) ),
			),
			array(
				'type'        => 'oj_ai_response',
				'node_id'     => s05_id( 111 ),
				'response_id' => $response_one,
				'kind'        => 'short_text',
				'label'       => 'State the key idea',
				'required'    => true,
				'constraints' => array(
					'max_chars' => 200,
					'trim'      => true,
				),
				'children'    => array( array( 'text' => '' ) ),
			),
			array(
				'type'        => 'oj_ai_response',
				'node_id'     => s05_id( 112 ),
				'response_id' => $response_two,
				'kind'        => 'long_text',
				'label'       => 'Explain the evidence',
				'required'    => false,
				'constraints' => array( 'max_chars' => 2000 ),
				'children'    => array( array( 'text' => '' ) ),
			),
		),
		'settings'       => array(
			'student_navigation' => 'document',
			'print_profile'      => 'worksheet_a4@1',
		),
	);
}

/**
 * G2-IMP-02-S05 immutable draft revision transaction contract.
 */
final class S05_Standard_Snapshots implements Achievement_Standard_Snapshot_Port_Interface {
	/**
	 * Snapshots.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $snapshots;

	/**
	 * Queries.
	 *
	 * @var array<int,array<int,string>>
	 */
	public array $queries = array();

	/**
	 * Initialize s05 standard snapshots dependencies and state.
	 *
	 * @param array<int,array<string,string>> $snapshots Snapshots.
	 */
	public function __construct( array $snapshots ) {
		$this->snapshots = array();
		foreach ( $snapshots as $snapshot ) {
			$this->snapshots[ $snapshot['standard_public_id'] ] = $snapshot;
		}
	}

	/**
	 * Find snapshots.
	 *
	 * @param array $standard_public_ids Standard public ids.
	 * @return array
	 */
	public function find_snapshots( array $standard_public_ids ): array {
		$this->queries[] = $standard_public_ids;
		$result          = array();
		foreach ( $standard_public_ids as $standard_id ) {
			if ( isset( $this->snapshots[ $standard_id ] ) ) {
				$result[] = $this->snapshots[ $standard_id ];
			}
		}
		return $result;
	}
}

/**
 * G2-IMP-02-S05 immutable draft revision transaction contract.
 */
final class S05_Wpdb_Fixture {
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;

	/**
	 * Insert id.
	 *
	 * @var int|string
	 */
	public $insert_id = 0;
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail next insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_next_insert_table = null;

	/**
	 * Prepared.
	 *
	 * @var array<string,array{sql:string,args:array<int,mixed>}>
	 */
	private array $prepared = array();

	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array();
	/**
	 * Sequence.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Snapshot.
	 *
	 * @var array{tables:array<string,array<int,array<string,mixed>>>,next_ids:array<string,int>,insert_id:int|string}|null
	 */
	private ?array $snapshot = null;

	/**
	 * Initialize s05 wpdb fixture dependencies and state.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $old_hash Old hash.
	 */
	public function __construct( string $worksheet_id, string $revision_id, string $old_hash ) {
		$this->tables = array(
			'oj_ai_worksheets'                   => array(
				array(
					'id'                         => 1,
					'public_id'                  => $worksheet_id,
					'owner_user_id'              => 42,
					'state'                      => 'draft',
					'current_revision_public_id' => $revision_id,
					'lock_version'               => 0,
					'title'                      => 'Existing worksheet',
				),
			),
			'oj_ai_worksheet_revisions'          => array(
				array(
					'id'            => 1,
					'public_id'     => $revision_id,
					'worksheet_id'  => 1,
					'revision_no'   => 1,
					'state'         => 'draft',
					'content_hash'  => $old_hash,
					'document_hash' => $old_hash,
				),
			),
			'oj_ai_worksheet_revision_questions' => array(),
			'oj_ai_worksheet_achievement_links'  => array(),
			'oj_ai_question_achievement_links'   => array(),
			'oj_ai_audit_events'                 => array(),
			'oj_ai_outbox_events'                => array(),
		);
		foreach ( $this->tables as $table => $rows ) {
			$this->next_ids[ $table ] = count( $rows ) + 1;
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		$token                    = '__S05_PREPARED_' . ( ++$this->sequence );
		$this->prepared[ $token ] = array(
			'sql'  => $sql,
			'args' => $args,
		);
		return $token;
	}

	/**
	 * Get row.
	 *
	 * @param string $token Token.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $token, $output = null ): ?array {
		unset( $output );
		$prepared = $this->resolve( $token );
		$sql      = $prepared['sql'];
		$args     = $prepared['args'];
		if ( str_starts_with( $sql, 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $row['idempotency_key'] === $args[0] ) {
					return array_intersect_key(
						$row,
						array_flip( array( 'aggregate_public_id', 'event_type', 'payload_schema', 'payload_version', 'payload_json', 'payload_hash' ) )
					);
				}
			}
			return null;
		}
		if ( str_starts_with( $sql, 'SELECT id, state, current_revision_public_id, lock_version FROM oj_ai_worksheets' ) ) {
			foreach ( $this->tables['oj_ai_worksheets'] as $row ) {
				if ( $row['public_id'] === $args[0] && $row['owner_user_id'] === $args[1] ) {
					return array_intersect_key( $row, array_flip( array( 'id', 'state', 'current_revision_public_id', 'lock_version' ) ) );
				}
			}
			return null;
		}
		if ( str_starts_with( $sql, 'SELECT id, public_id, revision_no, state, content_hash, document_hash FROM oj_ai_worksheet_revisions' ) ) {
			foreach ( $this->tables['oj_ai_worksheet_revisions'] as $row ) {
				if ( $row['worksheet_id'] === $args[0] && $row['public_id'] === $args[1] ) {
					return array_intersect_key(
						$row,
						array_flip( array( 'id', 'public_id', 'revision_no', 'state', 'content_hash', 'document_hash' ) )
					);
				}
			}
			return null;
		}
		if ( str_starts_with( $sql, 'SELECT w.current_revision_public_id, w.lock_version' ) ) {
			$revision  = $this->find_by_id( 'oj_ai_worksheet_revisions', (int) $args[0] );
			$worksheet = $this->find_by_id( 'oj_ai_worksheets', (int) $args[1] );
			if ( null === $revision || null === $worksheet ) {
				return null;
			}
			return array(
				'current_revision_public_id' => $worksheet['current_revision_public_id'],
				'lock_version'               => $worksheet['lock_version'],
				'revision_public_id'         => $revision['public_id'],
				'worksheet_id'               => $revision['worksheet_id'],
			);
		}
		throw new RuntimeException( 'Unexpected S05 get_row SQL: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $sql, $output = null ): array {
		unset( $output );
		$prepared = $this->resolve( $sql );
		if (
			str_starts_with( $prepared['sql'], 'SELECT public_id, owner_type, owner_public_id, purpose, mime_type, byte_size, sha256, state, scan_state FROM oj_ai_private_files' )
			&& isset( $this->tables['oj_ai_private_files'] )
		) {
			$wanted = array_fill_keys( $prepared['args'], true );
			return array_values(
				array_filter(
					$this->tables['oj_ai_private_files'],
					static fn ( array $row ): bool => isset( $wanted[ $row['public_id'] ] )
				)
			);
		}
		throw new RuntimeException( 'Unexpected S05 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( 'SELECT event_hash FROM oj_ai_audit_events ORDER BY id DESC LIMIT 1 FOR UPDATE' === $sql ) {
			$rows = $this->tables['oj_ai_audit_events'];
			if ( array() === $rows ) {
				return null;
			}
			return $rows[ array_key_last( $rows ) ]['event_hash'];
		}
		throw new RuntimeException( 'Unexpected S05 get_var SQL: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $formats Formats.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data, array $formats ): bool {
		if ( ! isset( $this->tables[ $table ] ) || count( $data ) !== count( $formats ) ) {
			throw new RuntimeException( 'Unexpected S05 insert contract for ' . $table );
		}
		if ( $this->fail_next_insert_table === $table ) {
			$this->fail_next_insert_table = null;
			return false;
		}
		$id                       = $this->next_ids[ $table ]++;
		$this->insert_id          = $id;
		$data['id']               = $id;
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ) {
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = array(
				'tables'    => $this->tables,
				'next_ids'  => $this->next_ids,
				'insert_id' => $this->insert_id,
			);
			return true;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return true;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->tables    = $this->snapshot['tables'];
				$this->next_ids  = $this->snapshot['next_ids'];
				$this->insert_id = $this->snapshot['insert_id'];
			}
			$this->snapshot = null;
			return true;
		}
		$prepared = $this->resolve( $sql );
		if ( str_starts_with( $prepared['sql'], 'UPDATE oj_ai_private_files SET state = %s' ) ) {
			$args = $prepared['args'];
			foreach ( $this->tables['oj_ai_private_files'] as &$row ) {
				if (
					$row['public_id'] === $args[3]
					&& $row['owner_type'] === $args[4]
					&& $row['owner_public_id'] === $args[5]
					&& $row['purpose'] === $args[6]
					&& $row['state'] === $args[7]
					&& $row['scan_state'] === $args[8]
				) {
					$row['state'] = $args[0];
					++$row['lock_version'];
					$row['updated_by_user_id'] = $args[1];
					$row['updated_at_utc']     = $args[2];
					$row['expires_at_utc']     = null;
					unset( $row );
					return 1;
				}
			}
			unset( $row );
			return 0;
		}
		if ( ! str_starts_with( $prepared['sql'], 'UPDATE oj_ai_worksheets SET title = %s' ) ) {
			throw new RuntimeException( 'Unexpected S05 query SQL: ' . $prepared['sql'] );
		}
		$args = $prepared['args'];
		foreach ( $this->tables['oj_ai_worksheets'] as &$row ) {
			if (
				$row['id'] === $args[4]
				&& $row['lock_version'] === $args[5]
				&& $row['current_revision_public_id'] === $args[6]
				&& $row['state'] === $args[7]
			) {
				$row['title']                      = $args[0];
				$row['current_revision_public_id'] = $args[1];
				++$row['lock_version'];
				$row['updated_by_user_id'] = $args[2];
				$row['updated_at_utc']     = $args[3];
				unset( $row );
				return 1;
			}
		}
		unset( $row );
		return 0;
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}

	/**
	 * Resolve s05 wpdb fixture.
	 *
	 * @param string $token Token.
	 * @return array{sql:string,args:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function resolve( string $token ): array {
		if ( ! isset( $this->prepared[ $token ] ) ) {
			throw new RuntimeException( 'Unknown S05 prepared token: ' . $token );
		}
		return $this->prepared[ $token ];
	}

	/**
	 * Find by id.
	 *
	 * @param string $table Table.
	 * @param int    $id Id.
	 * @return array<string,mixed>|null
	 */
	private function find_by_id( string $table, int $id ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}
}

/**
 * G2-IMP-02-S05 immutable draft revision transaction contract.
 */
final class S05_Curriculum_Wpdb {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Prepare count.
	 *
	 * @var int
	 */
	public int $prepare_count = 0;
	/**
	 * Sql.
	 *
	 * @var string
	 */
	public string $sql = '';

	/**
	 * Args.
	 *
	 * @var array<int,mixed>
	 */
	public array $args = array();

	/**
	 * Rows.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $rows;

	/**
	 * Initialize s05 curriculum wpdb dependencies and state.
	 *
	 * @param array<int,array<string,string>> $rows Rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		++$this->prepare_count;
		$this->sql  = $sql;
		$this->args = $args;
		return '__S05_CURRICULUM__';
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,string>>
	 */
	public function get_results( string $sql, $output = null ): array {
		unset( $output );
		s05_same( '__S05_CURRICULUM__', $sql, 'Curriculum reader must execute its prepared statement.' );
		return $this->rows;
	}
}

$canonical           = new Canonical_Json();
$worksheet_id        = s05_id( 1 );
$initial_revision_id = s05_id( 2 );
$document_id         = s05_id( 3 );
$response_one        = s05_id( 4 );
$response_two        = s05_id( 5 );
$standard_one        = s05_id( 30 );
$standard_two        = s05_id( 20 );
$initial_hash        = str_repeat( 'a', 64 );

$snapshots  = array(
	array(
		'standard_public_id'      => $standard_two,
		'standard_source_version' => '2022',
		'standard_code_snapshot'  => 'SCI-02',
		'standard_label_snapshot' => 'Compare evidence',
	),
	array(
		'standard_public_id'      => $standard_one,
		'standard_source_version' => '2022',
		'standard_code_snapshot'  => 'SCI-01',
		'standard_label_snapshot' => 'Explain phenomena',
	),
);
$standards  = new S05_Standard_Snapshots( $snapshots );
$database   = new S05_Wpdb_Fixture( $worksheet_id, $initial_revision_id, $initial_hash );
$repository = new WordPress_Worksheet_Repository( $database, $canonical );
$save       = new Save_Worksheet_Draft(
	$repository,
	$standards,
	new Worksheet_Document_Canonicalizer( $canonical, s05_generator( 1000 ) ),
	new Worksheet_Revision_Projector( $canonical, s05_generator( 2000 ) ),
	s05_generator( 3000 ),
	$canonical,
	static fn (): string => '2026-08-20 10:00:00'
);

$document = s05_document( $document_id, $response_one, $response_two, 'Inquiry worksheet' );
$links    = array(
	array(
		'scope'              => 'worksheet',
		'response_id'        => null,
		'standard_public_id' => $standard_one,
		'alignment_type'     => 'primary',
	),
	array(
		'scope'              => 'response',
		'response_id'        => $response_one,
		'standard_public_id' => $standard_two,
		'alignment_type'     => 'supporting',
	),
);

/**
 * Worksheet save draft s05 transaction.
 *
 * @param array<string,mixed> $teacher_document
 * @param array<int,array<string,mixed>> $achievement_links
 */
$command = static function (
	int $actor,
	string $expected_revision,
	string $expected_state,
	array $teacher_document,
	array $achievement_links,
	string $suffix
) use (
	$canonical,
	$worksheet_id
): Save_Worksheet_Draft_Command {
	return new Save_Worksheet_Draft_Command(
		$actor,
		$worksheet_id,
		'command-' . $suffix,
		$expected_state,
		$expected_revision,
		$teacher_document,
		$achievement_links,
		array(),
		hash( 'sha256', 'idem-' . $suffix ),
		'corr-' . $suffix,
		$canonical
	);
};

$first = $save->execute( $command( 42, $initial_revision_id, 'draft', $document, $links, 'save-0001' ) );
s05_same( 201, $first->http_status(), 'A changed draft must append one immutable revision.' );
s05_same( 2, $first->revision_no(), 'The first save must create revision two.' );
s05_same( $initial_revision_id, $first->source_revision_id(), 'Revision two must point to revision one.' );
s05_same( false, $first->unchanged(), 'A changed draft must not be marked unchanged.' );
s05_same( false, $first->replayed(), 'A fresh save must not be marked as an idempotent replay.' );
s05_same(
	array(
		'oj_ai_worksheets'                   => 1,
		'oj_ai_worksheet_revisions'          => 2,
		'oj_ai_worksheet_revision_questions' => 2,
		'oj_ai_worksheet_achievement_links'  => 1,
		'oj_ai_question_achievement_links'   => 1,
		'oj_ai_audit_events'                 => 1,
		'oj_ai_outbox_events'                => 1,
	),
	$database->counts(),
	'S05 plus S06 must persist the revision, projections, audit event, and outbox receipt atomically.'
);
s05_same( $first->revision_id(), $database->tables['oj_ai_worksheets'][0]['current_revision_public_id'], 'Root pointer CAS must target revision two.' );
s05_same( 1, $database->tables['oj_ai_worksheets'][0]['lock_version'], 'Root pointer CAS must increment lock_version once.' );
s05_same( $initial_hash, $database->tables['oj_ai_worksheet_revisions'][0]['content_hash'], 'The source revision must remain immutable.' );
s05_same( array( $standard_two, $standard_one ), $standards->queries[0], 'Curriculum snapshot IDs must be unique and sorted before lookup.' );

$counts_after_first = $database->counts();
$unchanged          = $save->execute( $command( 42, $first->revision_id(), 'draft', $document, $links, 'save-0002' ) );
s05_same( 200, $unchanged->http_status(), 'Equivalent aggregate content must return 200.' );
s05_same( true, $unchanged->unchanged(), 'Equivalent aggregate content must be marked unchanged.' );
s05_same( $first->revision_id(), $unchanged->revision_id(), 'Equivalent content must reuse the current revision.' );
$counts_after_unchanged = $counts_after_first;
++$counts_after_unchanged['oj_ai_outbox_events'];
s05_same( $counts_after_unchanged, $database->counts(), 'Equivalent content must add only its durable idempotency receipt.' );
s05_same( 1, $database->tables['oj_ai_worksheets'][0]['lock_version'], 'Equivalent content must not move the root lock.' );

$stale = s05_failure(
	static fn () => $save->execute( $command( 42, $initial_revision_id, 'draft', $document, $links, 'save-0003' ) ),
	Worksheet_Failure::REVISION_CONFLICT,
	'Stale expected revision must fail with the stable 409 reason.'
);
s05_same( $first->revision_id(), $stale->safe_context()['current_revision_id'], 'Conflict context must expose only the safe current revision ID.' );
s05_same( $counts_after_unchanged, $database->counts(), 'Stale expected revision must write zero rows.' );

s05_failure(
	static fn () => $save->execute( $command( 42, $first->revision_id(), 'active', $document, $links, 'save-0004' ) ),
	Worksheet_Failure::REVISION_CONFLICT,
	'Stale expected root state must fail with the stable 409 reason.'
);
s05_same( $counts_after_unchanged, $database->counts(), 'Stale expected state must write zero rows.' );

$changed_links                      = $links;
$changed_links[0]['alignment_type'] = 'supporting';
$second                             = $save->execute( $command( 42, $first->revision_id(), 'draft', $document, $changed_links, 'save-0005' ) );
s05_same( 3, $second->revision_no(), 'Achievement-only changes must append a new revision.' );
s05_same( $first->document_hash(), $second->document_hash(), 'Achievement-only changes must keep the teacher document hash.' );
s05_assert(
	$database->tables['oj_ai_worksheet_revisions'][1]['content_hash']
		!== $database->tables['oj_ai_worksheet_revisions'][2]['content_hash'],
	'Aggregate content hash must include frozen achievement links.'
);

$counts_before_failure            = $database->counts();
$pointer_before_failure           = $database->tables['oj_ai_worksheets'][0];
$database->fail_next_insert_table = 'oj_ai_worksheet_revision_questions';
$changed_document                 = s05_document( $document_id, $response_one, $response_two, 'Revised inquiry worksheet' );
s05_failure(
	static fn () => $save->execute( $command( 42, $second->revision_id(), 'draft', $changed_document, $changed_links, 'save-0006' ) ),
	Worksheet_Failure::STORAGE_UNAVAILABLE,
	'A question insert failure must surface as the stable storage reason.'
);
s05_same( $counts_before_failure, $database->counts(), 'A mid-transaction insert failure must roll every inserted row back.' );
s05_same( $pointer_before_failure, $database->tables['oj_ai_worksheets'][0], 'A failed transaction must not move the root pointer.' );

s05_failure(
	static fn () => $save->execute( $command( 43, $second->revision_id(), 'draft', $document, $changed_links, 'save-0007' ) ),
	Worksheet_Failure::NOT_FOUND,
	'An actor who does not own the worksheet must receive not-found.'
);
s05_same( $counts_before_failure, $database->counts(), 'Owner isolation failure must write zero rows.' );

$reader_rows         = array( $snapshots[0], $snapshots[1] );
$curriculum_database = new S05_Curriculum_Wpdb( $reader_rows );
$reader              = new WordPress_Achievement_Standard_Snapshot_Reader( $curriculum_database );
s05_same( array(), $reader->find_snapshots( array() ), 'An empty standard request must avoid SQL.' );
s05_same( 0, $curriculum_database->prepare_count, 'An empty standard request must not touch the database.' );
s05_same( $reader_rows, $reader->find_snapshots( array( $standard_two, $standard_one ) ), 'Curriculum reader must return authoritative snapshots.' );
s05_same( array( $standard_two, $standard_one ), $curriculum_database->args, 'Curriculum reader must bind only requested public IDs.' );
s05_assert( str_contains( $curriculum_database->sql, 'FROM oj_ai_achievement_standards' ), 'Curriculum reader must use the literal add-on table.' );
s05_assert( str_contains( $curriculum_database->sql, 'INNER JOIN oj_ai_curriculums' ), 'Curriculum source version must come from its owning table.' );
s05_assert( ! str_contains( $curriculum_database->sql, 'wp_' ), 'Curriculum reader must never use a WordPress table prefix.' );

try {
	$reader->find_snapshots( array( $standard_one, $standard_two ) );
} catch ( InvalidArgumentException ) {
	fwrite( STDOUT, "G2-IMP-02-S05 immutable draft revision transaction contract passed.\n" );
	return;
}
throw new RuntimeException( 'Curriculum reader must reject unsorted standard IDs.' );

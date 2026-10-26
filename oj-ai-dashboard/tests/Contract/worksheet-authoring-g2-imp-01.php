<?php
/**
 * G2-IMP-01 Worksheet authoring vertical-slice contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Infrastructure\Object_Authorization_Router;
use OJAI\Dashboard\Security\Policy\Infrastructure\Unavailable_Object_Authorization;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Read_Worksheets;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Initial_Document_Factory;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Worksheet_Object_Authorization;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Presentation\Worksheet_Rest_Module;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-01 Worksheet authoring vertical-slice contract.
	 */
	final class WP_REST_Response {
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G2-IMP-01 Worksheet authoring vertical-slice contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct(
			private string $code,
			private string $message,
			private $data = array()
		) {
		}

		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['g2_actor']       = 0;
$GLOBALS['g2_hooks']       = array();
$GLOBALS['g2_routes']      = array();
$GLOBALS['g2_nonce_calls'] = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g2_actor'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Wp verify nonce.
	 *
	 * @param string $nonce Nonce.
	 * @param string $action Action.
	 * @return mixed
	 */
	function wp_verify_nonce( string $nonce, string $action ) {
		$GLOBALS['g2_nonce_calls'][] = array( $nonce, $action );
		return 'valid-nonce' === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Wp salt.
	 *
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		return 'g2-wordpress-salt-' . $scheme . '-2026';
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	/**
	 * Determine locale.
	 *
	 * @return string
	 */
	function determine_locale(): string {
		return 'ko_KR';
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' ) . '/';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Add action.
	 *
	 * @param string   $hook Hook.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['g2_hooks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['g2_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G2 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G2 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G2 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2_failure( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}

	throw new RuntimeException( $message );
}

/**
 * A narrow wpdb double that executes only the SQL owned by the first slice.
 */
final class G2_Wpdb_Fixture {
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_worksheets'                   => array(),
		'oj_ai_worksheet_revisions'          => array(),
		'oj_ai_worksheet_revision_questions' => array(),
		'oj_ai_worksheet_achievement_links'  => array(),
		'oj_ai_question_achievement_links'   => array(),
		'oj_ai_worksheet_publications'       => array(),
		'oj_ai_audit_events'                 => array(),
		'oj_ai_outbox_events'                => array(),
	);

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
	 * Fail next read method.
	 *
	 * @var string|null
	 */
	public ?string $fail_next_read_method = null;

	/**
	 * Insert tables.
	 *
	 * @var array<int,string>
	 */
	public array $insert_tables = array();

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
	 * Prepare sequence.
	 *
	 * @var int
	 */
	private int $prepare_sequence = 0;

	/**
	 * Snapshot.
	 *
	 * @var array{tables:array<string,array<int,array<string,mixed>>>,next_ids:array<string,int>,insert_id:int|string}|null
	 */
	private ?array $snapshot = null;

	/**
	 * Initialize g2 wpdb fixture dependencies and state.
	 */
	public function __construct() {
		foreach ( array_keys( $this->tables ) as $table ) {
			$this->next_ids[ $table ] = 1;
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, ...$arguments ): string {
		++$this->prepare_sequence;
		$token                    = '__G2_PREPARED_' . $this->prepare_sequence . '__';
		$this->prepared[ $token ] = array(
			'sql'  => $sql,
			'args' => $arguments,
		);
		return $token;
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, $output = ARRAY_A ): ?array {
		if ( 'get_row' === $this->fail_next_read_method ) {
			$this->fail_next_read_method = null;
			$this->last_error            = 'Simulated get_row failure.';
			return null;
		}
		unset( $output );
		$query = $this->prepared_query( $prepared );
		$sql   = $query['sql'];
		$args  = $query['args'];

		if ( str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key = %s' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $row['idempotency_key'] === $args[0] ) {
					return $row;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'SELECT w.current_revision_public_id' ) ) {
			$revision  = $this->row_by_internal_id( 'oj_ai_worksheet_revisions', (int) $args[0] );
			$worksheet = $this->row_by_internal_id( 'oj_ai_worksheets', (int) $args[1] );
			if ( null === $revision || null === $worksheet ) {
				return null;
			}

			return array(
				'current_revision_public_id' => $worksheet['current_revision_public_id'],
				'revision_public_id'         => $revision['public_id'],
				'worksheet_id'               => $revision['worksheet_id'],
			);
		}

		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revisions r' ) ) {
			return $this->joined_revision( (string) $args[0], (int) $args[1] );
		}

		if (
			( str_contains( $sql, 'FROM oj_ai_worksheets w' ) && str_contains( $sql, 'WHERE w.public_id = %s' ) )
			|| (
				str_contains( $sql, 'FROM oj_ai_worksheets WHERE public_id = %s' )
			)
		) {
			return $this->owned_worksheet( (string) $args[0], (int) $args[1] );
		}

		throw new RuntimeException( 'Unexpected G2 get_row SQL: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_results( string $prepared, $output = ARRAY_A ): array {
		if ( 'get_results' === $this->fail_next_read_method ) {
			$this->fail_next_read_method = null;
			$this->last_error            = 'Simulated get_results failure.';
			return array();
		}
		unset( $output );
		$query = $this->prepared_query( $prepared );
		$sql   = $query['sql'];
		$args  = $query['args'];

		if ( str_contains( $sql, 'FROM oj_ai_worksheet_achievement_links' ) ) {
			$revision_id = (int) $args[0];
			$rows        = array_values(
				array_filter(
					$this->tables['oj_ai_worksheet_achievement_links'],
					static fn ( array $row ): bool => (int) $row['worksheet_revision_id'] === $revision_id
				)
			);
			usort(
				$rows,
				static function ( array $left, array $right ): int {
					$position = (int) $left['position'] <=> (int) $right['position'];
					return 0 !== $position ? $position : ( (int) $left['id'] <=> (int) $right['id'] );
				}
			);
			return $rows;
		}

		if ( str_contains( $sql, 'FROM oj_ai_question_achievement_links l' ) ) {
			$revision_id = (int) $args[0];
			$questions   = array();
			foreach ( $this->tables['oj_ai_worksheet_revision_questions'] as $question ) {
				if ( (int) $question['worksheet_revision_id'] !== $revision_id ) {
					continue;
				}
				$questions[ (int) $question['id'] ] = array(
					'response_id' => (string) $question['question_key'],
					'position'    => (int) $question['position'],
				);
			}
			$rows = array();
			foreach ( $this->tables['oj_ai_question_achievement_links'] as $row ) {
				$question = $questions[ (int) ( $row['question_id'] ?? 0 ) ] ?? null;
				if ( null === $question ) {
					continue;
				}
				$rows[] = array(
					'response_id'             => $question['response_id'],
					'standard_public_id'      => $row['standard_public_id'],
					'standard_source_version' => $row['standard_source_version'],
					'standard_code_snapshot'  => $row['standard_code_snapshot'],
					'standard_label_snapshot' => $row['standard_label_snapshot'],
					'alignment_type'          => $row['alignment_type'],
					'position'                => $row['position'],
					'_question_position'      => $question['position'],
					'_link_id'                => $row['id'],
				);
			}
			usort(
				$rows,
				static function ( array $left, array $right ): int {
					$question_position = (int) $left['_question_position'] <=> (int) $right['_question_position'];
					if ( 0 !== $question_position ) {
						return $question_position;
					}
					$position = (int) $left['position'] <=> (int) $right['position'];
					return 0 !== $position ? $position : ( (int) $left['_link_id'] <=> (int) $right['_link_id'] );
				}
			);
			foreach ( $rows as &$row ) {
				unset( $row['_question_position'], $row['_link_id'] );
			}
			unset( $row );
			return $rows;
		}

		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revision_questions' ) ) {
			$revision_id = (int) $args[0];
			$rows        = array_values(
				array_filter(
					$this->tables['oj_ai_worksheet_revision_questions'],
					static fn ( array $row ): bool => (int) $row['worksheet_revision_id'] === $revision_id
				)
			);
			usort(
				$rows,
				static function ( array $left, array $right ): int {
					$position = (int) $left['position'] <=> (int) $right['position'];
					return 0 !== $position ? $position : strcmp( (string) $left['public_id'], (string) $right['public_id'] );
				}
			);
			return $rows;
		}

		if ( str_contains( $sql, 'FROM oj_ai_worksheet_revisions r' ) ) {
			return $this->revision_page( $sql, $args );
		}

		if ( str_contains( $sql, 'FROM oj_ai_worksheets w' ) && str_contains( $sql, 'WHERE w.owner_user_id = %d' ) ) {
			return $this->worksheet_page( $sql, $args );
		}

		throw new RuntimeException( 'Unexpected G2 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( 'get_var' === $this->fail_next_read_method ) {
			$this->fail_next_read_method = null;
			$this->last_error            = 'Simulated get_var failure.';
			return null;
		}
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected G2 get_var SQL: ' . $sql );
		}
		$rows = $this->tables['oj_ai_audit_events'];
		if ( array() === $rows ) {
			return null;
		}
		return $rows[ count( $rows ) - 1 ]['event_hash'];
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
		unset( $formats );
		if ( ! array_key_exists( $table, $this->tables ) ) {
			throw new RuntimeException( 'Unexpected G2 insert table: ' . $table );
		}
		if ( $this->fail_next_insert_table === $table ) {
			$this->fail_next_insert_table = null;
			return false;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( $row['idempotency_key'] === $data['idempotency_key'] ) {
					return false;
				}
			}
		}

		$id                       = $this->next_ids[ $table ]++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		$this->insert_tables[]    = $table;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ) {
		if ( 'START TRANSACTION' === $sql ) {
			if ( null !== $this->snapshot ) {
				return false;
			}
			$this->snapshot = array(
				'tables'    => $this->tables,
				'next_ids'  => $this->next_ids,
				'insert_id' => $this->insert_id,
			);
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->tables    = $this->snapshot['tables'];
				$this->next_ids  = $this->snapshot['next_ids'];
				$this->insert_id = $this->snapshot['insert_id'];
			}
			$this->snapshot = null;
			return 1;
		}

		throw new RuntimeException( 'Unexpected G2 transaction SQL: ' . $sql );
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		$counts = array();
		foreach ( $this->tables as $table => $rows ) {
			$counts[ $table ] = count( $rows );
		}
		return $counts;
	}

	/**
	 * Prepared query.
	 *
	 * @param string $token Token.
	 * @return array{sql:string,args:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared_query( string $token ): array {
		if ( ! isset( $this->prepared[ $token ] ) ) {
			throw new RuntimeException( 'G2 SQL was not prepared.' );
		}
		return $this->prepared[ $token ];
	}

	/**
	 * Row by internal id.
	 *
	 * @param string $table Table.
	 * @param int    $id Id.
	 * @return array<string,mixed>|null
	 */
	private function row_by_internal_id( string $table, int $id ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( (int) $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Owned worksheet.
	 *
	 * @param string $public_id Public id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	private function owned_worksheet( string $public_id, int $actor_user_id ): ?array {
		foreach ( $this->tables['oj_ai_worksheets'] as $row ) {
			if ( $row['public_id'] === $public_id && (int) $row['owner_user_id'] === $actor_user_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Joined revision.
	 *
	 * @param string $public_id Public id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	private function joined_revision( string $public_id, int $actor_user_id ): ?array {
		foreach ( $this->tables['oj_ai_worksheet_revisions'] as $revision ) {
			if ( $revision['public_id'] !== $public_id ) {
				continue;
			}
			$worksheet = $this->row_by_internal_id( 'oj_ai_worksheets', (int) $revision['worksheet_id'] );
			if ( null === $worksheet || (int) $worksheet['owner_user_id'] !== $actor_user_id ) {
				return null;
			}

			return $this->join_revision_row( $revision, $worksheet );
		}
		return null;
	}

	/**
	 * Join revision row.
	 *
	 * @param array<string,mixed> $revision Revision.
	 * @param array<string,mixed> $worksheet Worksheet.
	 * @return array<string,mixed>
	 */
	private function join_revision_row( array $revision, array $worksheet ): array {
		$source      = null === $revision['source_revision_id']
			? null
			: $this->row_by_internal_id( 'oj_ai_worksheet_revisions', (int) $revision['source_revision_id'] );
		$publication = null;
		foreach ( $this->tables['oj_ai_worksheet_publications'] as $candidate ) {
			if ( (int) $candidate['worksheet_revision_id'] === (int) $revision['id'] ) {
				$publication = $candidate;
				break;
			}
		}

		return array_merge(
			$revision,
			array(
				'revision_internal_id'      => $revision['id'],
				'source_revision_public_id' => null === $source ? null : $source['public_id'],
				'worksheet_public_id'       => $worksheet['public_id'],
				'publication_public_id'     => null === $publication ? null : $publication['public_id'],
				'publication_state'         => null === $publication ? null : $publication['state'],
			)
		);
	}

	/**
	 * Worksheet page.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	private function worksheet_page( string $sql, array $args ): array {
		$actor = (int) $args[0];
		$index = 1;
		$state = null;
		if ( str_contains( $sql, 'AND w.state = %s' ) ) {
			$state = (string) $args[ $index++ ];
		}
		$cursor_time = null;
		$cursor_id   = null;
		if ( str_contains( $sql, 'AND (w.updated_at_utc < %s' ) ) {
			$cursor_time = (string) $args[ $index ];
			$cursor_id   = (string) $args[ $index + 2 ];
		}
		$limit = (int) $args[ count( $args ) - 1 ];

		$rows = array_values(
			array_filter(
				$this->tables['oj_ai_worksheets'],
				static function ( array $row ) use ( $actor, $state, $cursor_time, $cursor_id ): bool {
					if ( (int) $row['owner_user_id'] !== $actor || ( null !== $state && $row['state'] !== $state ) ) {
						return false;
					}
					if ( null === $cursor_time ) {
						return true;
					}
					return $row['updated_at_utc'] < $cursor_time
						|| ( $row['updated_at_utc'] === $cursor_time && $row['public_id'] < $cursor_id );
				}
			)
		);
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$updated = strcmp( $right['updated_at_utc'], $left['updated_at_utc'] );
				return 0 !== $updated ? $updated : strcmp( $right['public_id'], $left['public_id'] );
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Revision page.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	private function revision_page( string $sql, array $args ): array {
		$worksheet_public_id = (string) $args[0];
		$actor               = (int) $args[1];
		$cursor_no           = null;
		$cursor_id           = null;
		if ( str_contains( $sql, 'AND (r.revision_no < %d' ) ) {
			$cursor_no = (int) $args[2];
			$cursor_id = (string) $args[4];
		}
		$limit = (int) $args[ count( $args ) - 1 ];

		$worksheet = $this->owned_worksheet( $worksheet_public_id, $actor );
		if ( null === $worksheet ) {
			return array();
		}
		$rows = array();
		foreach ( $this->tables['oj_ai_worksheet_revisions'] as $revision ) {
			if ( (int) $revision['worksheet_id'] !== (int) $worksheet['id'] ) {
				continue;
			}
			if (
				null !== $cursor_no
				&& ! (
					(int) $revision['revision_no'] < $cursor_no
					|| ( (int) $revision['revision_no'] === $cursor_no && $revision['public_id'] < $cursor_id )
				)
			) {
				continue;
			}
			$rows[] = $this->join_revision_row( $revision, $worksheet );
		}
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$revision = (int) $right['revision_no'] <=> (int) $left['revision_no'];
				return 0 !== $revision ? $revision : strcmp( $right['public_id'], $left['public_id'] );
			}
		);
		return array_slice( $rows, 0, $limit );
	}
}

/**
 * G2-IMP-01 Worksheet authoring vertical-slice contract.
 */
final class G2_Request_Fixture {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();

	/**
	 * Initialize g2 request fixture dependencies and state.
	 *
	 * @param string              $method Method.
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param array<string,mixed> $query Query.
	 * @param array<string,mixed> $url Url.
	 */
	public function __construct(
		private string $method = 'GET',
		array $headers = array(),
		private array $json = array(),
		private array $query = array(),
		private array $url = array()
	) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}

	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_header( string $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}

	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}

	/**
	 * Get url params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_params(): array {
		return $this->url;
	}
}

/**
 * G2-IMP-01 Worksheet authoring vertical-slice contract.
 */
final class G2_Policy_Fixture implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Initialize g2 policy fixture dependencies and state.
	 *
	 * @param Object_Authorization_Port_Interface|null $objects Objects.
	 */
	public function __construct( private ?Object_Authorization_Port_Interface $objects = null ) {
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->last_request = $request;
		if ( $request->actor_user_id() < 1 ) {
			return Access_Policy_Decision::denied( 401, 'authentication_required', $request->correlation_id() );
		}
		if ( ! $request->request_integrity_verified() ) {
			return Access_Policy_Decision::denied( 403, 'request_integrity_failed', $request->correlation_id() );
		}
		if ( null !== $request->object() && null !== $this->objects ) {
			$result = $this->objects->authorize(
				$request->actor_user_id(),
				$request->action_key(),
				null,
				$request->object()
			);
			if ( ! $result->is_allowed() ) {
				return Access_Policy_Decision::denied( 403, $result->code(), $request->correlation_id() );
			}
		}

		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G2 command.
 *
 * @param int            $actor_user_id Authenticated actor from the access context.
 * @param string         $command_id Command identifier used for idempotency.
 * @param string         $title Title.
 * @param string         $idempotency_key Idempotency key.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return Create_Worksheet_Command
 */
function g2_command(
	int $actor_user_id,
	string $command_id,
	string $title,
	string $idempotency_key,
	Canonical_Json $canonical
): Create_Worksheet_Command {
	return new Create_Worksheet_Command(
		$actor_user_id,
		$command_id,
		'absent',
		$title,
		'ko_KR',
		hash( 'sha256', $idempotency_key ),
		'corr-' . $command_id,
		$canonical
	);
}

/**
 * G2 create body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $title Title.
 * @return array<string,mixed>
 */
function g2_create_body( string $command_id, string $title ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array( 'expected_state' => 'absent' ),
		'payload'    => array( 'title' => $title ),
	);
}

/**
 * G2 route.
 *
 * @param string $path Path.
 * @param string $method Method.
 * @return array<string,mixed>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2_route( string $path, string $method ): array {
	foreach ( $GLOBALS['g2_routes'] as $registration ) {
		if ( 'oj-ai/v1' !== $registration[0] || $path !== $registration[1] ) {
			continue;
		}
		$definitions = array_is_list( $registration[2] ) ? $registration[2] : array( $registration[2] );
		foreach ( $definitions as $definition ) {
			if ( ( $definition['methods'] ?? null ) === $method ) {
				return $definition;
			}
		}
	}

	throw new RuntimeException( 'G2 route was not registered: ' . $method . ' ' . $path );
}

$database  = new G2_Wpdb_Fixture();
$canonical = new Canonical_Json();
g2_same( 'ASCII is already NFC.', $canonical->text( 'ASCII is already NFC.' ), 'ASCII canonical text must not require intl.' );
$composed   = "\u{00E9}";
$decomposed = "e\u{0301}";
if ( class_exists( '\\Normalizer' ) ) {
	g2_same(
		$canonical->encode( array( 'value' => $composed ) ),
		$canonical->encode( array( 'value' => $decomposed ) ),
		'Canonical JSON must normalize canonically equivalent Unicode to the same bytes.'
	);
} else {
	$normalization_failure = g2_failure(
		static fn () => $canonical->text( $composed ),
		'Non-ASCII canonical text must fail closed when PHP intl is unavailable.'
	);
	g2_assert( $normalization_failure instanceof InvalidArgumentException, 'Missing Unicode normalization must be an input contract failure.' );
}
$entropy_sequence = 0;
$ids              = new Public_Id_Generator(
	static function ( int $length ) use ( &$entropy_sequence ): string {
		++$entropy_sequence;
		return str_repeat( chr( $entropy_sequence ), $length );
	}
);
$documents        = new Initial_Document_Factory( $ids, $canonical );
$cursors          = new Signed_Cursor_Codec( $canonical, 'g2-contract-cursor-secret-2026' );
$repository       = new WordPress_Worksheet_Repository( $database, $canonical, $cursors );
$create           = new Create_Worksheet(
	$repository,
	$ids,
	$documents,
	static fn (): string => '2026-08-14 01:02:03'
);
$read             = new Read_Worksheets( $repository );

$first_command = g2_command( 42, 'command-0001', 'First worksheet', 'caller-idem-0001', $canonical );
$first         = $create->execute( $first_command );
g2_same(
	array(
		'oj_ai_worksheets'                   => 1,
		'oj_ai_worksheet_revisions'          => 1,
		'oj_ai_worksheet_revision_questions' => 0,
		'oj_ai_worksheet_achievement_links'  => 0,
		'oj_ai_question_achievement_links'   => 0,
		'oj_ai_worksheet_publications'       => 0,
		'oj_ai_audit_events'                 => 1,
		'oj_ai_outbox_events'                => 1,
	),
	$database->counts(),
	'C01 must atomically create one root, revision, audit event, and outbox event.'
);
g2_same( false, $first->replayed(), 'The first C01 result must not be marked as a replay.' );

$worksheet_row = $database->tables['oj_ai_worksheets'][0];
$revision_row  = $database->tables['oj_ai_worksheet_revisions'][0];
$audit_row     = $database->tables['oj_ai_audit_events'][0];
$outbox_row    = $database->tables['oj_ai_outbox_events'][0];
$document      = json_decode( $revision_row['teacher_document_json'], true, 128, JSON_THROW_ON_ERROR );

g2_same( 'draft', $worksheet_row['state'], 'A new Worksheet root must start in draft.' );
g2_same( 'draft', $revision_row['state'], 'The initial revision must start in draft.' );
g2_same( 1, $revision_row['revision_no'], 'The initial immutable revision number must be one.' );
g2_same( $first->revision_id(), $worksheet_row['current_revision_public_id'], 'The root pointer must target revision one.' );
g2_same( $first->worksheet_id(), $worksheet_row['public_id'], 'C01 must return the persisted Worksheet ID.' );
g2_same( $first->revision_id(), $revision_row['public_id'], 'C01 must return the persisted revision ID.' );
g2_same( Initial_Document_Factory::DOCUMENT_SCHEMA, $document['schema_id'], 'teacher_document must use the frozen schema.' );
g2_same( Initial_Document_Factory::EDITOR_PROFILE, $document['editor_profile'], 'The initial document must use Plate profile one.' );
g2_same( 'h1', $document['nodes'][0]['type'], 'The initial document must start with a title heading.' );
g2_same( 'p', $document['nodes'][1]['type'], 'The initial document must include an editable paragraph.' );
g2_same( 'document', $document['settings']['student_navigation'], 'Student navigation must use the document profile.' );
g2_same( 'worksheet_a4@1', $document['settings']['print_profile'], 'The print profile must remain worksheet A4 v1.' );
g2_same( hash( 'sha256', $revision_row['teacher_document_json'] ), $revision_row['document_hash'], 'The document hash must cover canonical teacher_document JSON.' );
g2_assert( $revision_row['document_hash'] !== $revision_row['validation_hash'], 'Validation evidence must not reuse the document hash.' );
g2_same( 'worksheet.created', $audit_row['action_key'], 'C01 must append the frozen audit action.' );
g2_same( 'worksheet.created', $outbox_row['event_type'], 'C01 must append the frozen outbox event.' );
g2_assert( 'caller-idem-0001' !== ( $outbox_row['idempotency_key'] ), 'The caller idempotency token must not be stored raw.' );
g2_assert( ! str_contains( $outbox_row['payload_json'], 'caller-idem-0001' ), 'The caller token must not leak into the outbox payload.' );

$issued_ids = array(
	$first->worksheet_id(),
	$first->revision_id(),
	$first->document_id(),
	$document['nodes'][0]['node_id'],
	$document['nodes'][1]['node_id'],
);
g2_same( count( $issued_ids ), count( array_unique( $issued_ids ) ), 'Server-issued Worksheet IDs must be unique.' );
foreach ( $issued_ids as $issued_id ) {
	g2_assert( Public_Id::is_valid( $issued_id ), 'Every server-issued Worksheet ID must use the 26-character public profile.' );
}

$counts_after_first = $database->counts();
$replay             = $create->execute( $first_command );
g2_same( true, $replay->replayed(), 'The same idempotency key and request must replay its receipt.' );
g2_same( $first->worksheet_id(), $replay->worksheet_id(), 'A replay must return the original Worksheet ID.' );
g2_same( $counts_after_first, $database->counts(), 'A replay must not add database rows.' );

$stored_payload_json                      = $database->tables['oj_ai_outbox_events'][0]['payload_json'];
$stored_payload_hash                      = $database->tables['oj_ai_outbox_events'][0]['payload_hash'];
$stored_payload                           = json_decode( $stored_payload_json, true, 128, JSON_THROW_ON_ERROR );
$stored_payload['receipt']['revision_id'] = 'invalid-stored-public-id';
$invalid_payload_json                     = $canonical->encode( $stored_payload );
$database->tables['oj_ai_outbox_events'][0]['payload_json'] = $invalid_payload_json;
$database->tables['oj_ai_outbox_events'][0]['payload_hash'] = hash( 'sha256', $invalid_payload_json );
$stored_receipt_failure                                     = g2_failure( static fn () => $create->execute( $first_command ), 'An invalid stored receipt must fail.' );
g2_assert( $stored_receipt_failure instanceof Worksheet_Failure, 'Stored receipt corruption must not look like client input failure.' );
g2_same( Worksheet_Failure::STORED_DATA_INVALID, $stored_receipt_failure->reason(), 'Stored receipt corruption must use the stable stored-data reason.' );
$database->tables['oj_ai_outbox_events'][0]['payload_json'] = $stored_payload_json;
$database->tables['oj_ai_outbox_events'][0]['payload_hash'] = $stored_payload_hash;


$conflict = g2_failure(
	static fn () => $create->execute(
		g2_command( 42, 'command-0001', 'Changed title', 'caller-idem-0001', $canonical )
	),
	'Reusing an idempotency key with another request must fail.'
);
g2_assert( $conflict instanceof Worksheet_Failure, 'An idempotency conflict must use the stable Worksheet failure.' );
g2_same( Worksheet_Failure::IDEMPOTENCY_CONFLICT, $conflict->reason(), 'The conflict reason must remain stable.' );
g2_same( $counts_after_first, $database->counts(), 'An idempotency conflict must not add rows.' );

$database->fail_next_insert_table = 'oj_ai_outbox_events';
$rollback                         = g2_failure(
	static fn () => $create->execute(
		g2_command( 42, 'command-rollback', 'Rollback worksheet', 'caller-idem-rollback', $canonical )
	),
	'An outbox failure must fail C01.'
);
g2_assert( $rollback instanceof Worksheet_Failure, 'A failed transaction must become a stable Worksheet failure.' );
g2_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $rollback->reason(), 'A failed write must map to storage unavailable.' );
g2_same( $counts_after_first, $database->counts(), 'An outbox failure must roll back the root, revision, and audit rows.' );
$database->fail_next_read_method = 'get_var';
$scalar_read_failure             = g2_failure(
	static fn () => $create->execute(
		g2_command( 42, 'command-scalar-read-failure', 'Scalar read failure', 'caller-idem-scalar-read-failure', $canonical )
	),
	'A scalar database read error must fail C01.'
);
g2_assert( $scalar_read_failure instanceof Worksheet_Failure, 'A scalar database read error must use the stable Worksheet failure.' );
g2_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $scalar_read_failure->reason(), 'A scalar database read error must map to storage unavailable.' );
g2_same( $counts_after_first, $database->counts(), 'A scalar database read error must roll back all C01 rows.' );

$second   = $create->execute(
	g2_command( 42, 'command-0002', 'Second worksheet', 'caller-idem-0002', $canonical )
);
$outsider = $create->execute(
	g2_command( 99, 'command-0003', 'Other owner worksheet', 'caller-idem-0003', $canonical )
);

$database->fail_next_read_method = 'get_results';
$list_read_failure               = g2_failure(
	static fn () => $read->list( 42, '', '', 1 ),
	'A list database read error must fail Q04.'
);
g2_assert( $list_read_failure instanceof Worksheet_Failure, 'A list database read error must use the stable Worksheet failure.' );
g2_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $list_read_failure->reason(), 'A list database read error must map to storage unavailable.' );

$first_page = $read->list( 42, '', '', 1 );
g2_same( 1, count( $first_page['items'] ), 'Q04 must honor the requested page limit.' );
g2_assert( '' !== $first_page['next_cursor'], 'Q04 must issue a cursor when another owned row exists.' );
g2_assert( strlen( $first_page['next_cursor'] ) <= Read_Worksheets::MAXIMUM_CURSOR, 'The signed cursor must fit QueryInputV1.' );
$second_page = $read->list( 42, '', $first_page['next_cursor'], 1 );
g2_same( 1, count( $second_page['items'] ), 'The next Q04 page must contain the remaining owned row.' );
g2_same( '', $second_page['next_cursor'], 'The final Q04 page must not return a cursor.' );
$owned_ids = array( $first_page['items'][0]['id'], $second_page['items'][0]['id'] );
sort( $owned_ids, SORT_STRING );
$expected_owned_ids = array( $first->worksheet_id(), $second->worksheet_id() );
sort( $expected_owned_ids, SORT_STRING );
g2_same( $expected_owned_ids, $owned_ids, 'Q04 SQL must filter by owner before projection.' );
g2_assert( ! in_array( $outsider->worksheet_id(), $owned_ids, true ), 'Q04 must never expose another owner row.' );

$draft_page = $read->list( 42, 'draft', '', 1 );
g2_assert( '' !== $draft_page['next_cursor'], 'A filtered Q04 page must issue a cursor when another row exists.' );
g2_assert( strlen( $draft_page['next_cursor'] ) <= Read_Worksheets::MAXIMUM_CURSOR, 'A filtered Q04 cursor must fit QueryInputV1.' );
$draft_next = $read->list( 42, 'draft', $draft_page['next_cursor'], 1 );
g2_same( 1, count( $draft_next['items'] ), 'A filtered Q04 cursor must retrieve the remaining row.' );

$cursor          = $first_page['next_cursor'];
$tampered_cursor = substr( $cursor, 0, -1 ) . ( '0' === substr( $cursor, -1 ) ? '1' : '0' );
g2_assert(
	g2_failure(
		static fn () => $read->list( 42, '', $tampered_cursor, 1 ),
		'A modified Q04 cursor must fail.'
	) instanceof InvalidArgumentException,
	'A modified Q04 cursor must fail signature verification.'
);
g2_assert(
	g2_failure(
		static fn () => $read->list( 42, 'draft', $cursor, 1 ),
		'A cursor must not cross state filters.'
	) instanceof InvalidArgumentException,
	'A Q04 cursor must remain bound to its original state filter.'
);

$database->fail_next_read_method = 'get_row';
$row_read_failure                = g2_failure(
	static fn () => $read->worksheet( 42, $first->worksheet_id() ),
	'A single-row database read error must fail Q05.'
);
g2_assert( $row_read_failure instanceof Worksheet_Failure, 'A single-row database read error must use the stable Worksheet failure.' );
g2_same( Worksheet_Failure::STORAGE_UNAVAILABLE, $row_read_failure->reason(), 'A single-row database read error must not look like not found.' );

$root_projection = $read->worksheet( 42, $first->worksheet_id() );
g2_same( $first->worksheet_id(), $root_projection['id'], 'Q05 must return the requested owned Worksheet.' );
g2_same( 'owner', $root_projection['relation'], 'Q05 must expose only the supported owner relation.' );
g2_assert( ! array_key_exists( 'owner_user_id', $root_projection ), 'Q05 must not expose storage owner columns.' );
g2_same( null, $root_projection['latest_publication_hash'], 'A draft Q05 projection must expose a null latest publication hash.' );

$stored_created_at = $database->tables['oj_ai_worksheets'][0]['created_at_utc'];
$database->tables['oj_ai_worksheets'][0]['created_at_utc'] = 'invalid-stored-time';
$stored_failure = g2_failure(
	static fn () => $read->worksheet( 42, $first->worksheet_id() ),
	'Invalid stored Worksheet timestamps must fail.'
);
g2_assert( $stored_failure instanceof Worksheet_Failure, 'Stored timestamp corruption must not look like client input failure.' );
g2_same( Worksheet_Failure::STORED_DATA_INVALID, $stored_failure->reason(), 'Stored timestamp corruption must map to stable storage evidence failure.' );
$database->tables['oj_ai_worksheets'][0]['created_at_utc'] = $stored_created_at;


$revision_page = $read->revisions( 42, $first->worksheet_id(), '', 30 );
g2_same( 1, count( $revision_page['items'] ), 'Q06 must return the initial revision.' );
g2_same( $first->revision_id(), $revision_page['items'][0]['id'], 'Q06 must use the public revision ID.' );
g2_assert( ! array_key_exists( 'revision_internal_id', $revision_page['items'][0] ), 'Q06 must not expose internal primary keys.' );

$standard_public_id                                      = $ids->create()->value();
$database->tables['oj_ai_worksheet_achievement_links'][] = array(
	'id'                      => 1,
	'worksheet_revision_id'   => (int) $revision_row['id'],
	'standard_public_id'      => $standard_public_id,
	'standard_source_version' => '2022',
	'standard_code_snapshot'  => 'SCI-G2-01',
	'standard_label_snapshot' => '관찰한 근거로 현상을 설명한다.',
	'alignment_type'          => 'primary',
	'position'                => 0,
);

$revision_projection = $read->revision( 42, $first->revision_id() );
g2_same( $first->worksheet_id(), $revision_projection['worksheet_id'], 'Q07 must retain the public Worksheet relationship.' );
g2_same( $document, $revision_projection['teacher_document'], 'Q07 must return the validated canonical teacher_document.' );
g2_same( array(), $revision_projection['questions'], 'The first empty revision must not invent question projections.' );
g2_same(
	array(
		array(
			'scope'              => 'worksheet',
			'response_id'        => null,
			'standard_public_id' => $standard_public_id,
			'alignment_type'     => 'primary',
		),
	),
	$revision_projection['achievement_links'],
	'Q07 must return the stored Worksheet achievement link.'
);
g2_same(
	array(
		array(
			'standard_public_id'      => $standard_public_id,
			'standard_source_version' => '2022',
			'standard_code_snapshot'  => 'SCI-G2-01',
			'standard_label_snapshot' => '관찰한 근거로 현상을 설명한다.',
		),
	),
	$revision_projection['standard_snapshots'],
	'Q07 must return the immutable standard snapshot used by the link.'
);
g2_assert( ! array_key_exists( 'revision_internal_id', $revision_projection ), 'Q07 must not expose internal primary keys.' );

$second_revision                                 = $revision_row;
$second_revision['id']                           = 900;
$second_revision['public_id']                    = $ids->create()->value();
$second_revision['revision_no']                  = 2;
$second_revision['source_revision_id']           = $revision_row['id'];
$database->tables['oj_ai_worksheet_revisions'][] = $second_revision;
$revision_cursor_page                            = $read->revisions( 42, $first->worksheet_id(), '', 1 );
g2_assert( '' !== $revision_cursor_page['next_cursor'], 'Q06 must issue a cursor when another revision exists.' );
g2_assert( strlen( $revision_cursor_page['next_cursor'] ) <= Read_Worksheets::MAXIMUM_CURSOR, 'The Q06 cursor must fit QueryInputV1.' );
$revision_cursor_next = $read->revisions( 42, $first->worksheet_id(), $revision_cursor_page['next_cursor'], 1 );
$revision_cursor_ids  = array( $revision_cursor_page['items'][0]['id'], $revision_cursor_next['items'][0]['id'] );
sort( $revision_cursor_ids, SORT_STRING );
$expected_revision_cursor_ids = array( $first->revision_id(), $second_revision['public_id'] );
sort( $expected_revision_cursor_ids, SORT_STRING );
g2_same( $expected_revision_cursor_ids, $revision_cursor_ids, 'Q06 signed pagination must return each owned revision once.' );


$not_owned = g2_failure(
	static fn () => $read->worksheet( 99, $first->worksheet_id() ),
	'Q05 must not return another owner Worksheet.'
);
g2_assert( $not_owned instanceof Worksheet_Failure, 'Owner isolation must use the stable not-found failure.' );
g2_same( Worksheet_Failure::NOT_FOUND, $not_owned->reason(), 'Owner isolation must fail closed as not found.' );

$worksheet_objects = new Worksheet_Object_Authorization( $repository );
$owner_decision    = $worksheet_objects->authorize(
	42,
	'worksheet.view',
	null,
	new Object_Scope( Object_Scope::TYPE_WORKSHEET, $first->worksheet_id(), 'view' )
);
g2_assert( $owner_decision->is_allowed(), 'The Worksheet object adapter must authorize its owner.' );
g2_same( 'owner', $owner_decision->actor_relation(), 'Object evidence must retain the owner relation.' );
$outsider_decision = $worksheet_objects->authorize(
	99,
	'worksheet.view',
	null,
	new Object_Scope( Object_Scope::TYPE_WORKSHEET, $first->worksheet_id(), 'view' )
);
g2_assert( $outsider_decision->is_denied(), 'The Worksheet object adapter must deny another actor.' );
$edit_decision = $worksheet_objects->authorize(
	42,
	'worksheet.save-draft',
	null,
	new Object_Scope( Object_Scope::TYPE_WORKSHEET, $first->worksheet_id(), 'edit' )
);
g2_assert( $edit_decision->is_allowed(), 'C02 must authorize the owner edit relation.' );
$unsupported_action = $worksheet_objects->authorize(
	42,
	'worksheet.delete',
	null,
	new Object_Scope( Object_Scope::TYPE_WORKSHEET, $first->worksheet_id(), 'delete' )
);
g2_assert( $unsupported_action->is_unavailable(), 'Unopened Worksheet object actions must fail closed.' );

$object_router     = new Object_Authorization_Router(
	array(
		Object_Scope::TYPE_WORKSHEET          => $worksheet_objects,
		Object_Scope::TYPE_WORKSHEET_REVISION => $worksheet_objects,
	),
	new Unavailable_Object_Authorization()
);
$fallback_decision = $object_router->authorize(
	42,
	'assignment.view',
	null,
	new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, 'assignment-0001', 'view' )
);
g2_assert( $fallback_decision->is_unavailable(), 'Unimplemented object modules must stay on the unavailable fallback.' );
g2_same(
	false,
	( new Object_Scope( Object_Scope::TYPE_WORKSHEET, $first->worksheet_id(), 'view' ) )->requires_context(),
	'Worksheet owner access must not invent class or group Context.'
);
g2_same(
	true,
	( new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, 'assignment-0001', 'view' ) )->requires_context(),
	'Assignment access must remain Context-bound.'
);

$policy               = new G2_Policy_Fixture( $object_router );
$correlation_sequence = 0;
$correlations         = new Correlation_Id_Factory(
	static function () use ( &$correlation_sequence ): string {
		++$correlation_sequence;
		return 'server-g2-' . str_pad( (string) $correlation_sequence, 3, '0', STR_PAD_LEFT );
	}
);
$envelopes            = new Rest_Envelope_Factory();
$permissions          = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller           = new Worksheet_Rest_Controller(
	$permissions,
	$create,
	$read,
	$canonical,
	$envelopes,
	$correlations
);
$module               = new Worksheet_Rest_Module( $controller );
$module->register();
$module->register();
g2_same( 1, count( $GLOBALS['g2_hooks']['rest_api_init'] ?? array() ), 'The Worksheet REST module hook must register once.' );
call_user_func( $GLOBALS['g2_hooks']['rest_api_init'][0] );
call_user_func( $GLOBALS['g2_hooks']['rest_api_init'][0] );
g2_same( 5, count( $GLOBALS['g2_routes'] ), 'Worksheet authoring must register exactly five route declarations through C02.' );

$route_index     = g2_route( '/worksheets', 'GET' );
$route_create    = g2_route( '/worksheets', 'POST' );
$route_show      = g2_route( Worksheet_Rest_Controller::ROUTE_WORKSHEET, 'GET' );
$route_revisions = g2_route( Worksheet_Rest_Controller::ROUTE_REVISIONS, 'GET' );
$route_revision  = g2_route( Worksheet_Rest_Controller::ROUTE_REVISION, 'GET' );
$route_save      = g2_route( Worksheet_Rest_Controller::ROUTE_SAVE_DRAFT, 'POST' );
foreach ( array( $route_index, $route_create, $route_show, $route_revisions, $route_revision, $route_save ) as $route ) {
	g2_assert( is_callable( $route['permission_callback'] ?? null ), 'Every Worksheet endpoint needs a permission callback.' );
	g2_assert( is_callable( $route['callback'] ?? null ), 'Every Worksheet endpoint needs a route callback.' );
	g2_assert( ! array_key_exists( 'args', $route ), 'Frozen input errors must use the common envelope.' );
}

$GLOBALS['g2_actor'] = 42;
$rest_body           = g2_create_body( 'command-rest-0001', 'REST worksheet' );
$rest_request        = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'valid-nonce',
		'Idempotency-Key' => 'rest-idem-0001',
	),
	$rest_body
);
g2_same( true, call_user_func( $route_create['permission_callback'], $rest_request ), 'Authorized C01 must pass permission_callback.' );
g2_same( Worksheet_Rest_Controller::ACTION_CREATE, $policy->last_request?->action_key(), 'C01 must use its frozen action key.' );
g2_same( array( Addon_Capabilities::MANAGE_WORKSHEETS ), $policy->last_request?->required_capabilities(), 'C01 must require manage_worksheets.' );
g2_same( null, $policy->last_request?->context(), 'C01 must not invent a teaching Context.' );
g2_same( null, $policy->last_request?->object(), 'C01 creates an absent root and has no object scope yet.' );
g2_same( array( 'valid-nonce', 'wp_rest' ), $GLOBALS['g2_nonce_calls'][ count( $GLOBALS['g2_nonce_calls'] ) - 1 ], 'C01 must verify the standard wp_rest nonce.' );
$rest_response = call_user_func( $route_create['callback'], $rest_request );
g2_assert( $rest_response instanceof WP_REST_Response, 'C01 success must return a REST response.' );
g2_same( 201, $rest_response->get_status(), 'C01 success must use HTTP 201.' );
$rest_data = $rest_response->get_data();
g2_same( array( 'data', 'meta', 'links', 'actions' ), array_keys( $rest_data ), 'C01 must use the common success envelope.' );
g2_same( false, $rest_data['meta']['idempotent_replay'], 'The first REST C01 call must not be a replay.' );
$rest_worksheet_id = $rest_data['data']['worksheet']['id'];
$rest_revision_id  = $rest_data['data']['revision']['id'];
g2_assert( Public_Id::is_valid( $rest_worksheet_id ), 'C01 must return a server-issued Worksheet ID.' );
g2_assert( Public_Id::is_valid( $rest_revision_id ), 'C01 must return a server-issued revision ID.' );

$rows_before_rest_replay = $database->counts();
$rest_replay_request     = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'valid-nonce',
		'Idempotency-Key' => 'rest-idem-0001',
	),
	$rest_body
);
g2_same( true, call_user_func( $route_create['permission_callback'], $rest_replay_request ), 'A valid C01 retry must pass policy.' );
$rest_replay = call_user_func( $route_create['callback'], $rest_replay_request );
g2_same( true, $rest_replay->get_data()['meta']['idempotent_replay'], 'A REST retry must declare receipt replay.' );
g2_same( $rest_worksheet_id, $rest_replay->get_data()['data']['worksheet']['id'], 'A REST retry must return the original Worksheet.' );
g2_same( $rows_before_rest_replay, $database->counts(), 'A REST replay must not add rows.' );

$rest_conflict_request = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'valid-nonce',
		'Idempotency-Key' => 'rest-idem-0001',
	),
	g2_create_body( 'command-rest-0001', 'Changed REST worksheet' )
);
g2_same( true, call_user_func( $route_create['permission_callback'], $rest_conflict_request ), 'A syntactically valid conflicting retry reaches C01.' );
$rest_conflict = call_user_func( $route_create['callback'], $rest_conflict_request );
g2_assert( $rest_conflict instanceof WP_Error, 'A REST idempotency conflict must return a problem.' );
g2_same( 409, $rest_conflict->get_error_data()['status'], 'A REST idempotency conflict must use HTTP 409.' );

$invalid_body                  = g2_create_body( 'command-rest-0002', 'Invalid body' );
$invalid_body['actor_user_id'] = 999;
$invalid_request               = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'valid-nonce',
		'Idempotency-Key' => 'rest-idem-0002',
	),
	$invalid_body
);
g2_same( true, call_user_func( $route_create['permission_callback'], $invalid_request ), 'Policy must run before C01 body validation.' );
$invalid_response = call_user_func( $route_create['callback'], $invalid_request );
g2_assert( $invalid_response instanceof WP_Error, 'Unknown C01 body fields must fail.' );
g2_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C01 body fields must use HTTP 422.' );
g2_same( $rows_before_rest_replay, $database->counts(), 'Invalid C01 input must not write rows.' );

$wrong_expected_body                               = g2_create_body( 'command-rest-0003', 'Wrong expected' );
$wrong_expected_body['expected']['expected_state'] = 'draft';
$wrong_expected_request                            = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'valid-nonce',
		'Idempotency-Key' => 'rest-idem-0003',
	),
	$wrong_expected_body
);
g2_same( true, call_user_func( $route_create['permission_callback'], $wrong_expected_request ), 'Policy must run before expected-state validation.' );
$wrong_expected = call_user_func( $route_create['callback'], $wrong_expected_request );
g2_same( 422, $wrong_expected->get_error_data()['status'], 'C01 expected_state must remain absent.' );
g2_same( $rows_before_rest_replay, $database->counts(), 'A wrong expected state must not write rows.' );

$missing_idempotency        = new G2_Request_Fixture(
	'POST',
	array( 'X-WP-Nonce' => 'valid-nonce' ),
	g2_create_body( 'command-rest-0004', 'Missing idempotency' )
);
$missing_idempotency_result = call_user_func( $route_create['permission_callback'], $missing_idempotency );
g2_assert( $missing_idempotency_result instanceof WP_Error, 'C01 must require Idempotency-Key in permission_callback.' );
g2_same( 422, $missing_idempotency_result->get_error_data()['status'], 'A missing Idempotency-Key must use HTTP 422.' );

$bad_nonce        = new G2_Request_Fixture(
	'POST',
	array(
		'X-WP-Nonce'      => 'bad-nonce',
		'Idempotency-Key' => 'rest-idem-0005',
	),
	g2_create_body( 'command-rest-0005', 'Bad nonce' )
);
$bad_nonce_result = call_user_func( $route_create['permission_callback'], $bad_nonce );
g2_assert( $bad_nonce_result instanceof WP_Error, 'C01 must reject an invalid wp_rest nonce.' );
g2_same( 403, $bad_nonce_result->get_error_data()['status'], 'An invalid C01 nonce must fail authorization.' );

$nonce_count   = count( $GLOBALS['g2_nonce_calls'] );
$index_request = new G2_Request_Fixture( 'GET', array(), array(), array( 'limit' => '100' ) );
g2_same( true, call_user_func( $route_index['permission_callback'], $index_request ), 'Authorized Q04 must pass policy.' );
g2_same( Worksheet_Rest_Controller::ACTION_LIST, $policy->last_request?->action_key(), 'Q04 must use its frozen action key.' );
g2_same( array( Addon_Capabilities::VIEW_WORKSHEETS ), $policy->last_request?->required_capabilities(), 'Q04 must require view_worksheets.' );
g2_same( $nonce_count, count( $GLOBALS['g2_nonce_calls'] ), 'GET Worksheet queries must not verify a nonce.' );
$index_response = call_user_func( $route_index['callback'], $index_request );
g2_same( 200, $index_response->get_status(), 'Q04 success must use HTTP 200.' );
g2_same( 'owner', $index_response->get_data()['meta']['relation_scope'], 'Q04 must declare its owner-only scope.' );

$show_request = new G2_Request_Fixture( 'GET', array(), array(), array(), array( 'worksheet_id' => $rest_worksheet_id ) );
g2_same( true, call_user_func( $route_show['permission_callback'], $show_request ), 'Authorized Q05 must pass object policy.' );
g2_same( Object_Scope::TYPE_WORKSHEET, $policy->last_request?->object()?->type(), 'Q05 must authorize the Worksheet root.' );
g2_same( false, $policy->last_request?->object()?->requires_context(), 'Q05 must remain owner-scoped without Context.' );
$show_response = call_user_func( $route_show['callback'], $show_request );
g2_same( $rest_worksheet_id, $show_response->get_data()['data']['worksheet']['id'], 'Q05 must return the requested Worksheet.' );

$revisions_request = new G2_Request_Fixture( 'GET', array(), array(), array(), array( 'worksheet_id' => $rest_worksheet_id ) );
g2_same( true, call_user_func( $route_revisions['permission_callback'], $revisions_request ), 'Authorized Q06 must pass object policy.' );
g2_same( Worksheet_Rest_Controller::ACTION_LIST_REVISIONS, $policy->last_request?->action_key(), 'Q06 must use its frozen action key.' );
$revisions_response = call_user_func( $route_revisions['callback'], $revisions_request );
g2_same( $rest_revision_id, $revisions_response->get_data()['data']['items'][0]['id'], 'Q06 must return the first revision.' );

$revision_request = new G2_Request_Fixture( 'GET', array(), array(), array(), array( 'revision_id' => $rest_revision_id ) );
g2_same( true, call_user_func( $route_revision['permission_callback'], $revision_request ), 'Authorized Q07 must pass object policy.' );
g2_same( Object_Scope::TYPE_WORKSHEET_REVISION, $policy->last_request?->object()?->type(), 'Q07 must authorize the revision object.' );
$revision_response = call_user_func( $route_revision['callback'], $revision_request );
g2_same( $rest_revision_id, $revision_response->get_data()['data']['revision']['id'], 'Q07 must return the requested revision.' );
g2_assert( isset( $revision_response->get_data()['data']['teacher_document'] ), 'Q07 must return teacher_document as the source of truth.' );

$GLOBALS['g2_actor']   = 99;
$outsider_show_request = new G2_Request_Fixture( 'GET', array(), array(), array(), array( 'worksheet_id' => $rest_worksheet_id ) );
$outsider_show         = call_user_func( $route_show['permission_callback'], $outsider_show_request );
g2_assert( $outsider_show instanceof WP_Error, 'Q05 object policy must reject another owner.' );
g2_same( 403, $outsider_show->get_error_data()['status'], 'Another owner must fail before the Q05 callback.' );

$authoring_source   = '';
$authoring_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Worksheet'
		. DIRECTORY_SEPARATOR . 'Authoring'
	)
);
foreach ( $authoring_iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$source            = file_get_contents( $file->getPathname() );
		$authoring_source .= is_string( $source ) ? $source : '';
	}
}
g2_assert( ! str_contains( $authoring_source, '$wpdb->prefix' ), 'Worksheet Authoring must never use the WordPress table prefix.' );
g2_assert( ! str_contains( $authoring_source, 'Table_Names' ), 'Worksheet Authoring must own literal oj_ai_* table names.' );
g2_assert( ! str_contains( $authoring_source, 'wp_oj_ai_' ), 'Worksheet Authoring must not retain prefixed table candidates.' );
g2_assert( ! str_contains( $authoring_source, 'CodingPanda\\OjPlatform' ), 'Worksheet Authoring must not import platform internals.' );

$repository_source = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Worksheet'
	. DIRECTORY_SEPARATOR . 'Authoring' . DIRECTORY_SEPARATOR . 'Infrastructure'
	. DIRECTORY_SEPARATOR . 'WordPress_Worksheet_Repository.php'
);
g2_assert( is_string( $repository_source ), 'The Worksheet repository source must be readable.' );
preg_match_all( "/private const TABLE_[A-Z_]+\\s*=\\s*'([^']+)'/", $repository_source, $table_matches );
$declared_tables = $table_matches[1] ?? array();
sort( $declared_tables, SORT_STRING );
$expected_tables = array(
	'oj_ai_audit_events',
	'oj_ai_outbox_events',
	'oj_ai_question_achievement_links',
	'oj_ai_worksheet_achievement_links',
	'oj_ai_worksheet_publications',
	'oj_ai_worksheet_revision_questions',
	'oj_ai_worksheet_revisions',
	'oj_ai_worksheets',
);
sort( $expected_tables, SORT_STRING );
g2_same( $expected_tables, $declared_tables, 'The repository must declare only the eight fixed literal tables used by the implemented slices.' );
foreach ( $database->insert_tables as $insert_table ) {
	g2_assert( str_starts_with( $insert_table, 'oj_ai_' ), 'Every C01 insert must target a literal oj_ai_* table.' );
	g2_assert( ! str_starts_with( $insert_table, 'wp_' ), 'No C01 insert may use a WordPress prefix.' );
}

$composition_source = file_get_contents(
	$addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Composition_Root.php'
);
g2_assert( is_string( $composition_source ), 'The active Composition Root must be readable.' );
g2_assert( str_contains( $composition_source, 'new WordPress_Worksheet_Repository' ), 'The active root must wire the Worksheet repository.' );
g2_assert( str_contains( $composition_source, 'new Object_Authorization_Router' ), 'The active root must wire module-owned object authorization.' );
g2_assert( str_contains( $composition_source, 'new Worksheet_Rest_Module' ), 'The active root must expose the G2 Worksheet REST module.' );
g2_assert( ! str_contains( $composition_source, 'new Worksheet_Module(' ), 'The legacy Worksheet runtime must remain unreachable.' );

fwrite( STDOUT, "G2-IMP-01 Worksheet authoring vertical-slice contract passed.\n" );

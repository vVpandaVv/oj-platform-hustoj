<?php
/**
 * G3-IMP-02 assignment schedule and lifecycle contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment_Command;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Lifecycle_Repository;
use OJAI\Dashboard\Submission\Assignment\Presentation\Assignment_Lifecycle_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-02 assignment schedule and lifecycle contract.
 */
final class G3_Lifecycle_Database {
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
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Next id.
	 *
	 * @var int
	 */
	private int $next_id = 500;
	/**
	 * Transaction snapshot.
	 *
	 * @var array|null
	 */
	private ?array $transaction_snapshot = null;

	/**
	 * Initialize g3 lifecycle database dependencies and state.
	 *
	 * @param array<string,mixed>            $assignment Assignment.
	 * @param array<int,array<string,mixed>> $audit Audit.
	 * @param array<int,array<string,mixed>> $outbox Outbox.
	 */
	public function __construct( array $assignment, array $audit, array $outbox ) {
		$this->tables = array(
			'oj_ai_assignments'   => array( $assignment ),
			'oj_ai_audit_events'  => $audit,
			'oj_ai_outbox_events' => $outbox,
		);
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return 'G3L:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		[ $sql, $args ] = $this->prepared( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( ( $row['idempotency_key'] ?? null ) === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments' ) && str_contains( $sql, 'WHERE public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_assignments'] as $row ) {
				if ( $row['public_id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments' ) && str_contains( $sql, 'WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_assignments'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected G3 lifecycle get_row SQL: ' . $sql );
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'Unexpected G3 lifecycle get_var SQL.' );
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? ( $last['event_hash'] ?? null ) : null;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ): int|false {
		if ( ! array_key_exists( $table, $this->tables ) ) {
			throw new RuntimeException( 'Unexpected G3 lifecycle insert table.' );
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( $row['idempotency_key'] === $data['idempotency_key'] ) {
					$this->last_error = 'Duplicate outbox idempotency key';
					return false;
				}
			}
		}
		$id                       = $this->next_id++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		return 1;
	}
	/**
	 * Update g3 lifecycle database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		g3_same( 'oj_ai_assignments', $table, 'Lifecycle may update only the literal assignment root.' );
		foreach ( $this->tables[ $table ] as $index => $row ) {
			$matches = true;
			foreach ( $where as $key => $value ) {
				if ( ( $row[ $key ] ?? null ) !== $value ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				$this->tables[ $table ][ $index ] = array_replace( $row, $data );
				return 1;
			}
		}
		return 0;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function query( string $sql ): int {
		$this->queries[] = $sql;
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction_snapshot = $this->tables;
		}
		if ( 'ROLLBACK' === $sql && null !== $this->transaction_snapshot ) {
			$this->tables = $this->transaction_snapshot;
		}
		if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) {
			$this->transaction_snapshot = null;
		}
		return 1;
	}
	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		if ( ! str_starts_with( $value, 'G3L:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared G3 lifecycle SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid G3 lifecycle prepared SQL.' );
		}
		return $decoded;
	}
}

/**
 * G3-IMP-02 assignment schedule and lifecycle contract.
 */
final class G3_Lifecycle_Request {
	/**
	 * Initialize g3 lifecycle request dependencies and state.
	 *
	 * @param string              $action Action.
	 * @param array<string,mixed> $json Json.
	 */
	public function __construct( private string $action, private array $json ) {
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
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array(
			'assignment_id'      => '01040G2081040G2081040G2081',
			'assignment_command' => $this->action,
		);
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,string>
	 */
	public function get_query_params(): array {
		return array(
			'context_type'     => 'class',
			'context_id'       => 'class-21',
			'context_revision' => 'context-r1',
		);
	}
}

/**
 * G3l body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param array  $assignment Assignment.
 * @param array  $payload Input payload at this boundary; see the declared shape.
 * @return array<string,mixed>
 */
function g3l_body( string $command_id, array $assignment, array $payload ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_state'            => $assignment['state'],
			'expected_lock_version'     => $assignment['lock_version'],
			'expected_schedule_version' => $assignment['schedule_version'],
			'expected_schedule_hash'    => $assignment['schedule_hash'],
		),
		'payload'    => $payload,
	);
}

$seed                 = $database->tables['oj_ai_assignments'][0];
$lifecycle_database   = new G3_Lifecycle_Database( $seed, $database->tables['oj_ai_audit_events'], $database->tables['oj_ai_outbox_events'] );
$lifecycle_repository = new WordPress_Assignment_Lifecycle_Repository( $lifecycle_database, $canonical );
$lifecycle_id         = 70;
$lifecycle_ids        = new Public_Id_Generator(
	static function ( int $length ) use ( &$lifecycle_id ): string {
		++$lifecycle_id;
		return str_repeat( chr( $lifecycle_id ), $length );
	}
);
$clock_value          = '2026-08-21 23:00:00';
$lifecycle            = new Change_Assignment(
	$lifecycle_repository,
	$lifecycle_ids,
	static function () use ( &$clock_value ): string {
		return $clock_value;
	}
);

$schedule_update = array(
	'opens_at_utc'        => '2026-08-22T09:00:00Z',
	'due_at_utc'          => '2026-08-22T10:30:00Z',
	'closes_at_utc'       => '2026-08-22T11:30:00Z',
	'auto_submit_enabled' => true,
);
$update          = new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::UPDATE_SCHEDULE, 'g3l-update-0001', 'scheduled', 0, 1, $seed['schedule_hash'], $schedule_update, hash( 'sha256', 'g3l-update' ), 'g3l-correlation-0001', $canonical );
$updated         = $lifecycle->execute( $update );
g3_same( 'scheduled', $updated->assignment()['state'], 'C14 must retain scheduled state for a future opens time.' );
g3_same( 1, $updated->assignment()['lock_version'], 'C14 must increment root lock_version.' );
g3_same( 2, $updated->assignment()['schedule_version'], 'C14 must increment schedule_version.' );
g3_assert( ! hash_equals( $seed['schedule_hash'], $updated->assignment()['schedule_hash'] ), 'C14 must issue a new schedule hash.' );
$update_replay = $lifecycle->execute( $update );
g3_same( true, $update_replay->replayed(), 'An exact C14 retry must replay its durable receipt.' );
g3_same( 1, count( array_filter( $lifecycle_database->tables['oj_ai_outbox_events'], static fn ( array $row ): bool => 'assignment.schedule_updated' === ( $row['event_type'] ?? null ) ) ), 'C14 replay must not append another event.' );

$stale = new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::OPEN, 'g3l-open-stale1', 'scheduled', 0, 1, $seed['schedule_hash'], null, hash( 'sha256', 'g3l-stale' ), 'g3l-correlation-stale1', $canonical );
g3_failure( static fn () => $lifecycle->execute( $stale ), Assignment_Failure::STATE_CONFLICT, 'Stale assignment CAS must fail closed.' );

$current     = $updated->assignment();
$clock_value = '2026-08-22 08:30:00';
$opened      = $lifecycle->execute( new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::OPEN, 'g3l-open-000001', $current['state'], $current['lock_version'], $current['schedule_version'], $current['schedule_hash'], null, hash( 'sha256', 'g3l-open' ), 'g3l-correlation-0002', $canonical ) );
g3_same( 'open', $opened->assignment()['state'], 'C15 must open a scheduled assignment.' );
g3_same( '2026-08-22 08:30:00', $opened->assignment()['schedule']['opens_at_utc'], 'C15 manual open must pin server UTC.' );
g3_same( 3, $opened->assignment()['schedule_version'], 'C15 early open must create a new schedule version.' );
$deadline_root = $lifecycle_database->tables['oj_ai_assignments'][0];

$current     = $opened->assignment();
$clock_value = '2026-08-22 09:15:00';
$closed      = $lifecycle->execute( new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::CLOSE, 'g3l-close-00001', $current['state'], $current['lock_version'], $current['schedule_version'], $current['schedule_hash'], null, hash( 'sha256', 'g3l-close' ), 'g3l-correlation-0003', $canonical ) );
g3_same( 'closed', $closed->assignment()['state'], 'C16 must close an open assignment.' );
g3_same( '2026-08-22 09:15:00', $closed->assignment()['schedule']['closes_at_utc'], 'C16 must pin the effective manual close.' );
g3_same( '2026-08-22 09:15:00', $closed->assignment()['schedule']['due_at_utc'], 'C16 must preserve schedule ordering when closing before due.' );
g3_same( 1, count( array_filter( $lifecycle_database->tables['oj_ai_outbox_events'], static fn ( array $row ): bool => 'assignment.auto_submit_due' === ( $row['event_type'] ?? null ) ) ), 'C16 auto-submit close must enqueue one due event in the transaction.' );

$current         = $closed->assignment();
$clock_value     = '2026-08-22 09:30:00';
$reopen_schedule = array(
	'opens_at_utc'        => null,
	'due_at_utc'          => '2026-08-22T10:30:00Z',
	'closes_at_utc'       => '2026-08-22T11:00:00Z',
	'auto_submit_enabled' => true,
);
$reopened        = $lifecycle->execute( new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::REOPEN, 'g3l-reopen-0001', $current['state'], $current['lock_version'], $current['schedule_version'], $current['schedule_hash'], $reopen_schedule, hash( 'sha256', 'g3l-reopen' ), 'g3l-correlation-0004', $canonical ) );
g3_same( 'open', $reopened->assignment()['state'], 'C17 must reopen only a closed assignment.' );
g3_same( '2026-08-22 09:30:00', $reopened->assignment()['schedule']['opens_at_utc'], 'C17 must pin the reopen server time.' );

$current   = $reopened->assignment();
$withdrawn = $lifecycle->execute( new Change_Assignment_Command( 42, $seed['public_id'], Change_Assignment_Command::WITHDRAW, 'g3l-withdraw-01', $current['state'], $current['lock_version'], $current['schedule_version'], $current['schedule_hash'], null, hash( 'sha256', 'g3l-withdraw' ), 'g3l-correlation-0005', $canonical ) );
g3_same( 'withdrawn', $withdrawn->assignment()['state'], 'C18 must make withdrawal terminal.' );
g3_same( $current['schedule_version'], $withdrawn->assignment()['schedule_version'], 'C18 must not invent a schedule revision.' );
g3_same( 5, count( array_filter( $lifecycle_database->tables['oj_ai_audit_events'], static fn ( array $row ): bool => str_starts_with( (string) ( $row['action_key'] ?? '' ), 'assignment.' ) && 'assignment.created' !== ( $row['action_key'] ?? null ) ) ), 'C14-C18 must each append one audit event.' );

// Cron may enqueue or finish the same schedule before a teacher closes it.
$deadline_payload = array(
	'assignment_id'    => $deadline_root['public_id'],
	'schedule_hash'    => $deadline_root['schedule_hash'],
	'schedule_version' => $deadline_root['schedule_version'],
);
foreach ( array( 'queued', 'in_progress', 'completed', 'completed_pruned', 'missing' ) as $case ) {
	$case_root                                 = $deadline_root;
	$case_root['auto_submit_completed_at_utc'] = str_starts_with( $case, 'completed' ) ? '2026-08-22 11:31:00' : null;
	$payload                                   = $deadline_payload;
	if ( 'in_progress' === $case ) {
		$payload['candidate_cursor'] = 200;
	}
	$json         = $canonical->encode( $payload );
	$due_event    = array(
		'id'                  => 450,
		'public_id'           => $seed['public_id'],
		'aggregate_type'      => 'assignment',
		'aggregate_public_id' => $case_root['public_id'],
		'event_type'          => 'assignment.auto_submit_due',
		'payload_schema'      => 'oj_ai.assignment_auto_submit_due',
		'payload_version'     => 1,
		'idempotency_key'     => 'assignment-auto-submit:' . hash( 'sha256', $case_root['public_id'] . '|' . $case_root['schedule_hash'] ),
		'payload_json'        => $json,
		'payload_hash'        => hash( 'sha256', $json ),
		'lease_token'         => 'in_progress' === $case ? str_repeat( 'a', 64 ) : null,
		'published_at_utc'    => 'completed' === $case ? '2026-08-22 11:31:00' : null,
	);
	$events       = in_array( $case, array( 'missing', 'completed_pruned' ), true ) ? array() : array( $due_event );
	$case_db      = new G3_Lifecycle_Database( $case_root, array(), $events );
	$case_service = new Change_Assignment( new WordPress_Assignment_Lifecycle_Repository( $case_db, $canonical ), $lifecycle_ids, static fn (): string => '2026-08-22 12:00:00' );
	$command      = new Change_Assignment_Command( 42, $case_root['public_id'], Change_Assignment_Command::CLOSE, 'g3l-late-' . $case, 'open', $case_root['lock_version'], $case_root['schedule_version'], $case_root['schedule_hash'], null, hash( 'sha256', $case ), 'g3l-late-' . $case, $canonical );
	$result       = $case_service->execute( $command );
	g3_same( 'closed', $result->assignment()['state'], $case . ': deadline close must commit.' );
	g3_same( $case_root['schedule_version'], $result->assignment()['schedule_version'], $case . ': unchanged deadline must retain its version.' );
	g3_same( $case_root['auto_submit_completed_at_utc'], $case_db->tables['oj_ai_assignments'][0]['auto_submit_completed_at_utc'], $case . ': close must preserve the completion receipt.' );
	$due_events = array_values( array_filter( $case_db->tables['oj_ai_outbox_events'], static fn ( array $event ): bool => 'assignment.auto_submit_due' === $event['event_type'] ) );
	g3_same( 'completed_pruned' === $case ? 0 : 1, count( $due_events ), $case . ': close must not duplicate completed or queued work.' );
	if ( $events ) {
		g3_same( $due_event, $due_events[0], $case . ': close must not replace a worker lease or cursor.' );
	}
	g3_same( true, $case_service->execute( $command )->replayed(), $case . ': close retry must replay the durable command receipt.' );
}

$bad_event                        = $due_event;
$bad_event['aggregate_public_id'] = str_repeat( 'Z', 26 );
$bad_db                           = new G3_Lifecycle_Database( $deadline_root, array(), array( $bad_event ) );
$bad_before                       = $bad_db->tables;
$bad_service                      = new Change_Assignment( new WordPress_Assignment_Lifecycle_Repository( $bad_db, $canonical ), $lifecycle_ids, static fn (): string => '2026-08-22 12:00:00' );
$bad_command                      = new Change_Assignment_Command( 42, $deadline_root['public_id'], Change_Assignment_Command::CLOSE, 'g3l-late-corrupt', 'open', $deadline_root['lock_version'], $deadline_root['schedule_version'], $deadline_root['schedule_hash'], null, hash( 'sha256', 'corrupt' ), 'g3l-late-corrupt', $canonical );
g3_failure( static fn () => $bad_service->execute( $bad_command ), Assignment_Failure::STORED_DATA_INVALID, 'A conflicting queue identity must fail closed.' );
g3_same( $bad_before, $bad_db->tables, 'Queue corruption must roll back the close and audit event.' );

$controller_reflection = new ReflectionClass( Assignment_Lifecycle_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input_method          = $controller_reflection->getMethod( 'change_input' );
$rest_current          = array_replace(
	$seed,
	array(
		'state'            => 'scheduled',
		'lock_version'     => 0,
		'schedule_version' => 1,
	)
);
$body                  = g3l_body( 'g3l-rest-update1', $rest_current, array( 'schedule' => $schedule_update ) );
$parsed                = $input_method->invoke( $controller, new G3_Lifecycle_Request( 'update-schedule', $body ) );
g3_same( Change_Assignment_Command::UPDATE_SCHEDULE, $parsed['action'], 'C14 path must map to update_schedule.' );
$body['payload']['learner_user_ids'] = array( 7 );
g3_invalid( static fn () => $input_method->invoke( $controller, new G3_Lifecycle_Request( 'update-schedule', $body ) ), 'C14 must reject unrelated payload fields.' );
$empty_body  = g3l_body( 'g3l-rest-open001', $rest_current, array() );
$parsed_open = $input_method->invoke( $controller, new G3_Lifecycle_Request( 'open', $empty_body ) );
g3_same( null, $parsed_open['schedule'], 'C15 must accept an exact empty payload only.' );

$lifecycle_source  = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/WordPress_Assignment_Lifecycle_Repository.php' );
$controller_source = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Presentation/Assignment_Lifecycle_Rest_Controller.php' );
$object_source     = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/Assignment_Object_Authorization.php' );
foreach ( array( 'oj_ai_assignments', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE', 'expected_lock_version', 'schedule_version', 'schedule_hash', 'assignment.auto_submit_due' ) as $marker ) {
	g3_assert( str_contains( $lifecycle_source, $marker ), 'Lifecycle transaction marker is missing: ' . $marker );
}
foreach ( array( 'update-schedule|open|close|reopen|withdraw', 'expected_schedule_version', 'expected_schedule_hash' ) as $marker ) {
	g3_assert( str_contains( $controller_source, $marker ), 'C14-C18 REST marker is missing: ' . $marker );
}
foreach ( array( 'update_schedule', 'open', 'close', 'reopen', 'withdraw' ) as $action ) {
	g3_assert( str_contains( $object_source, "'" . $action . "'" ), 'Object Policy action is missing: ' . $action );
}
foreach ( array( $lifecycle_source, $controller_source, $object_source ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3-IMP-02 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3-IMP-02 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3-IMP-02 must not depend on platform internals.' );
}

echo "G3-IMP-02 backend passed: C14-C18 exact bodies, schedule and lock CAS, audit, outbox, auto-submit due, replay, and terminal withdrawal.\n";

<?php
/**
 * G3-IMP-01 assignment creation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentLearnerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as Platform_Autoloader;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Command;
use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\Platform_Assignment_Roster;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Repository;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Presentation\Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root    = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $addon_root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) || ! is_file( $platform_root . '/src/AddonApi/V1/AddonAssignmentRosterGatewayV1.php' ) ) {
	fwrite( STDERR, "Adjacent OJ Platform Addon API source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_VERSION' ) || define( 'OJ_AI_DASHBOARD_VERSION', '0.1.7' );
defined( 'OJ_AI_DASHBOARD_FILE' ) || define( 'OJ_AI_DASHBOARD_FILE', $addon_root . '/oj-ai-dashboard.php' );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $addon_root . DIRECTORY_SEPARATOR );
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G3-IMP-01 assignment creation contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			unset( $form );
			return $value;
		}
	}
}

require_once $platform_root . '/src/Kernel/Autoloader.php';
Platform_Autoloader::register( $platform_root );
require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G3 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G3 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G3 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation(); } catch ( Assignment_Failure $failure ) {
		g3_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ' No Assignment_Failure was thrown.' );
}

/**
 * G3 invalid.
 *
 * @param callable $operation Operation.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3_invalid( callable $operation, string $message ): void {
	try {
		$operation(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message . ' No InvalidArgumentException was thrown.' );
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Roster_Gateway implements AddonAssignmentRosterGatewayV1 {
	/**
	 * Queries.
	 *
	 * @var array<int,AddonAssignmentRosterQueryV1>
	 */
	public array $queries = array();
	/**
	 * Producer.
	 *
	 * @var Closure
	 */
	private Closure $producer;

	/**
	 * Initialize g3 roster gateway dependencies and state.
	 *
	 * @param callable $producer Producer.
	 */
	public function __construct( callable $producer ) {
		$this->producer = Closure::fromCallable( $producer );
	}
	/**
	 * List assignment learners.
	 *
	 * @param AddonAssignmentRosterQueryV1 $query Query.
	 * @return AddonAssignmentLearnerPageV1
	 */
	public function listAssignmentLearners( AddonAssignmentRosterQueryV1 $query ): AddonAssignmentLearnerPageV1 {
		$this->queries[] = $query;
		return ( $this->producer )( $query, count( $this->queries ) );
	}
	/**
	 * List teaching contexts.
	 *
	 * @param AddonTeachingContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listTeachingContexts( AddonTeachingContextQueryV1 $query ): AddonContextPageV1 {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Authorize.
	 *
	 * @param AddonContextAccessRequestV1 $request Request.
	 * @return AddonContextAccessDecisionV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorize( AddonContextAccessRequestV1 $request ): AddonContextAccessDecisionV1 {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Snapshot.
	 *
	 * @param AddonContextSnapshotRequestV1 $request Request.
	 * @return AddonContextSnapshotResultV1
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( AddonContextSnapshotRequestV1 $request ): AddonContextSnapshotResultV1 {
		throw new RuntimeException( 'Not used.' );
	}
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Runtime implements Platform_Context_Runtime_Port_Interface {
	/**
	 * Initialize g3 runtime dependencies and state.
	 *
	 * @param bool                  $ready Ready.
	 * @param AddonContextGatewayV1 $gateway Gateway.
	 */
	public function __construct( private bool $ready, private AddonContextGatewayV1 $gateway ) {
	}
	/**
	 * Is ready.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->ready;
	}
	/**
	 * Contexts.
	 *
	 * @return AddonContextGatewayV1|null
	 */
	public function contexts(): ?AddonContextGatewayV1 {
		return $this->gateway;
	}
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Roster_Port implements Assignment_Roster_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g3 roster port dependencies and state.
	 *
	 * @param Assignment_Roster_Snapshot $snapshot Snapshot.
	 */
	public function __construct( private Assignment_Roster_Snapshot $snapshot ) {
	}
	/**
	 * Collect.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $expected_context_revision Expected context revision.
	 * @return Assignment_Roster_Snapshot
	 */
	public function collect( int $actor_user_id, string $context_type, string $context_id, string $expected_context_revision ): Assignment_Roster_Snapshot {
		++$this->calls;
		g3_same( 42, $actor_user_id, 'CreateAssignment actor drifted before CG11.' );
		g3_same( $this->snapshot->context_type(), $context_type, 'CreateAssignment Context type drifted.' );
		g3_same( $this->snapshot->context_id(), $context_id, 'CreateAssignment Context ID drifted.' );
		g3_same( $this->snapshot->context_revision(), $expected_context_revision, 'CreateAssignment Context revision drifted.' );
		return $this->snapshot;
	}
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Display_Port implements Learner_Display_Snapshot_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param int $learner_user_id Learner user id.
	 * @return array{display_name:string}
	 */
	public function snapshot( int $learner_user_id ): array {
		return array(
			'display_name' => '학생 ' . $learner_user_id,
			'email'        => 'must-not-persist@example.test',
		);
	}
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Database {
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
	public array $tables = array();
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
	private int $next_id = 1;
	/**
	 * Publication.
	 *
	 * @var array<string,mixed>
	 */
	private array $publication;

	/**
	 * Initialize g3 database dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string         $publication_id Publication id.
	 * @param string         $publication_hash Publication hash.
	 */
	public function __construct( Canonical_Json $canonical, string $publication_id, string $publication_hash ) {
		$payload           = array( 'title' => '분수 비교 활동지' );
		$document          = array(
			'payload'      => $payload,
			'payload_hash' => hash( 'sha256', $canonical->encode( $payload ) ),
		);
		$document_json     = $canonical->encode( $document );
		$this->publication = array(
			'public_id'                    => $publication_id,
			'content_hash'                 => $publication_hash,
			'publication_hash'             => $publication_hash,
			'state'                        => 'active',
			'worksheet_state'              => 'active',
			'latest_publication_public_id' => $publication_id,
			'student_document_json'        => $document_json,
			'student_document_hash'        => hash( 'sha256', $document_json ),
		);
		foreach ( array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_audit_events', 'oj_ai_outbox_events' ) as $table ) {
			$this->tables[ $table ] = array();
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return 'G3:' . base64_encode( serialize( array( $sql, $args ) ) );
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
				if ( $row['idempotency_key'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_worksheet_publications p' ) ) {
			return $this->publication;
		}
		if ( str_contains( $sql, 'SELECT a.id FROM oj_ai_assignments a INNER JOIN oj_ai_assignment_targets t' ) ) {
			g3_assert( str_ends_with( $sql, 'FOR UPDATE' ), 'Overlap reads must see committed targets under the publication lock.' );
			g3_assert( ! str_contains( $sql, 'context_type =' ), 'Overlap checks must include both groups and classes.' );
			foreach ( $this->tables['oj_ai_assignments'] as $assignment ) {
				if ( $assignment['worksheet_publication_public_id'] !== $args[0] || $assignment['state'] === $args[1] ) {
					continue;
				}
				foreach ( $this->tables['oj_ai_assignment_targets'] as $target ) {
					if ( $target['assignment_id'] === $assignment['id'] && $target['state'] === $args[2] && in_array( $target['learner_user_id'], array_slice( $args, 3 ), true ) ) {
						return array( 'id' => $assignment['id'] );
					}
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_assignments a WHERE a.id = %d' ) ) {
			foreach ( $this->tables['oj_ai_assignments'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					$row['target_count'] = count( array_filter( $this->tables['oj_ai_assignment_targets'], static fn ( array $target ): bool => $target['assignment_id'] === $row['id'] ) );
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected G3 get_row SQL: ' . $sql );
	}
	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $prepared, mixed $output = null ): array {
		unset( $prepared, $output );
		return array();
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'Unexpected G3 get_var SQL.' );
		return null;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ): int {
		if ( ! array_key_exists( $table, $this->tables ) ) {
			throw new RuntimeException( 'Unexpected G3 insert table: ' . $table );
		}
		$id                       = $this->next_id++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		return 1;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function query( string $sql ): int {
		$this->queries[] = $sql;
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
		if ( ! str_starts_with( $value, 'G3:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared G3 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 3 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid G3 prepared SQL.' );
		}
		return $decoded;
	}
}

/**
 * G3-IMP-01 assignment creation contract.
 */
final class G3_Request {
	/**
	 * Initialize g3 request dependencies and state.
	 *
	 * @param array<string,mixed> $json Json.
	 */
	public function __construct( private array $json ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}
}

/**
 * G3 page.
 *
 * @param array  $learners Learners.
 * @param string $next Next.
 * @param string $revision Revision.
 * @return AddonAssignmentLearnerPageV1
 */
function g3_page( array $learners, string $next, string $revision ): AddonAssignmentLearnerPageV1 {
	return new AddonAssignmentLearnerPageV1( true, $learners, $next, $revision, null );
}

$normal_gateway = new G3_Roster_Gateway(
	static function ( AddonAssignmentRosterQueryV1 $query ): AddonAssignmentLearnerPageV1 {
		return '' === $query->cursor()
			? g3_page( array( new AddonAssignmentLearnerV1( 19, 'member-19' ) ), 'cursor-2', 'roster-r1' )
			: g3_page( array( new AddonAssignmentLearnerV1( 7, 'member-7' ) ), '', 'roster-r1' );
	}
);
$adapter        = new Platform_Assignment_Roster( new G3_Runtime( true, $normal_gateway ) );
$normal         = $adapter->collect( 42, 'class', 'class-21', 'context-r1' );
g3_same( array( 7, 19 ), array_column( $normal->learners(), 'learner_user_id' ), 'CG11 learners must be unique and deterministically ordered.' );
g3_same( null, $normal_gateway->queries[0]->expectedRosterRevision(), 'The first CG11 page must not invent a roster revision.' );
g3_same( '', $normal_gateway->queries[0]->cursor(), 'The first CG11 cursor must be empty.' );
g3_same( 'roster-r1', $normal_gateway->queries[1]->expectedRosterRevision(), 'The first roster revision must pin every later page.' );
g3_same( 'cursor-2', $normal_gateway->queries[1]->cursor(), 'The opaque CG11 cursor must be preserved.' );
g3_same( 100, $normal_gateway->queries[1]->limit(), 'CG11 must use its frozen bounded page size.' );

$race_gateway = new G3_Roster_Gateway( static fn ( AddonAssignmentRosterQueryV1 $query ): AddonAssignmentLearnerPageV1 => '' === $query->cursor() ? g3_page( array(), 'next', 'roster-a' ) : g3_page( array(), '', 'roster-b' ) );
g3_failure( static fn () => ( new Platform_Assignment_Roster( new G3_Runtime( true, $race_gateway ) ) )->collect( 42, 'class', 'class-21', 'context-r1' ), Assignment_Failure::ROSTER_INVALID, 'A CG11 roster race must discard the complete assignment.' );

$duplicate_gateway = new G3_Roster_Gateway( static fn ( AddonAssignmentRosterQueryV1 $query ): AddonAssignmentLearnerPageV1 => '' === $query->cursor() ? g3_page( array( new AddonAssignmentLearnerV1( 7, 'member-7' ) ), 'next', 'roster-a' ) : g3_page( array( new AddonAssignmentLearnerV1( 7, 'member-7' ) ), '', 'roster-a' ) );
g3_failure( static fn () => ( new Platform_Assignment_Roster( new G3_Runtime( true, $duplicate_gateway ) ) )->collect( 42, 'class', 'class-21', 'context-r1' ), Assignment_Failure::ROSTER_INVALID, 'A learner repeated across CG11 pages must fail closed.' );

$cursor_gateway = new G3_Roster_Gateway( static fn ( AddonAssignmentRosterQueryV1 $query ): AddonAssignmentLearnerPageV1 => g3_page( array(), 'same-cursor', 'roster-a' ) );
g3_failure( static fn () => ( new Platform_Assignment_Roster( new G3_Runtime( true, $cursor_gateway ) ) )->collect( 42, 'class', 'class-21', 'context-r1' ), Assignment_Failure::ROSTER_INVALID, 'A repeated CG11 cursor must fail closed.' );

$maximum_gateway = new G3_Roster_Gateway(
	static function ( AddonAssignmentRosterQueryV1 $query, int $call ): AddonAssignmentLearnerPageV1 {
		unset( $query );
		$items = array();
		for ( $offset = 1; $offset <= 100; ++$offset ) {
			$items[] = new AddonAssignmentLearnerV1( ( ( $call - 1 ) * 100 ) + $offset, 'member-' . ( ( $call - 1 ) * 100 + $offset ) );
		}
		return g3_page( $items, 50 === $call ? '' : 'page-' . $call, 'roster-5000' );
	}
);
$maximum         = ( new Platform_Assignment_Roster( new G3_Runtime( true, $maximum_gateway ) ) )->collect( 42, 'group', 'group-5000', 'context-r5000' );
g3_same( 5000, $maximum->count(), 'The frozen 5,000 learner upper bound must remain usable.' );
g3_same( 50, count( $maximum_gateway->queries ), 'A 5,000 learner roster must remain bounded to 50 pages.' );

$overflow_gateway = new G3_Roster_Gateway( static fn ( AddonAssignmentRosterQueryV1 $query, int $call ): AddonAssignmentLearnerPageV1 => g3_page( array(), 'page-' . $call, 'roster-overflow' ) );
g3_failure( static fn () => ( new Platform_Assignment_Roster( new G3_Runtime( true, $overflow_gateway ) ) )->collect( 42, 'group', 'group-overflow', 'context-r1' ), Assignment_Failure::ROSTER_TOO_LARGE, 'A CG11 continuation past 50 pages must fail closed.' );
g3_failure( static fn () => ( new Platform_Assignment_Roster( new G3_Runtime( false, $normal_gateway ) ) )->collect( 42, 'class', 'class-21', 'context-r1' ), Assignment_Failure::ROSTER_UNAVAILABLE, 'CG11 must not run before onReady Runtime readiness.' );

$canonical        = new Canonical_Json();
$publication_id   = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
$publication_hash = hash( 'sha256', 'g3-publication' );
$schedule         = array(
	'opens_at_utc'        => '2026-08-22T09:00:00Z',
	'due_at_utc'          => '2026-08-22T10:00:00Z',
	'closes_at_utc'       => '2026-08-22T11:00:00Z',
	'auto_submit_enabled' => true,
);
$resubmission     = array(
	'max_attempts'          => 2,
	'reopen_until_utc'      => null,
	'copy_previous_answers' => true,
);
$command          = new Create_Assignment_Command( 42, 'g3-command-0001', $publication_id, $publication_hash, 'class', 'class-21', 'context-r1', $schedule, $resubmission, hash( 'sha256', 'g3-idempotency' ), 'g3-correlation-0001', $canonical );
g3_same( 'scheduled', $command->initial_state(), 'An assignment with opens_at must start scheduled.' );
g3_same( '2026-08-22 10:00:00', $command->schedule()['due_at_utc'], 'C13 must normalize strict UTC timestamps.' );
g3_invalid(
	static fn () => new Create_Assignment_Command( 42, 'g3-command-bad1', $publication_id, $publication_hash, 'class', 'class-21', 'context-r1', array_replace( $schedule, array( 'opens_at_utc' => '2026-08-22T10:30:00Z' ) ), $resubmission, hash( 'sha256', 'g3-bad-idem' ), 'g3-correlation-bad1', $canonical ),
	'C13 must reject opens_at after due_at.'
);

$rest_body             = array(
	'command_id' => 'g3-rest-command-0001',
	'expected'   => array(
		'expected_context_revision' => 'context-r1',
		'expected_publication_hash' => $publication_hash,
	),
	'payload'    => array(
		'worksheet_publication_id' => $publication_id,
		'context'                  => array(
			'type' => 'class',
			'id'   => 'class-21',
		),
		'schedule'                 => $schedule,
		'resubmission_policy'      => $resubmission,
	),
);
$controller_reflection = new ReflectionClass( Assignment_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$create_input          = $controller_reflection->getMethod( 'create_input' );
$parsed                = $create_input->invoke( $controller, new G3_Request( $rest_body ) );
g3_same( 'class-21', $parsed['context_id'], 'C13 REST must project only its exact Context input.' );
$caller_roster                                = $rest_body;
$caller_roster['payload']['learner_user_ids'] = array( 7, 19 );
g3_invalid( static fn () => $create_input->invoke( $controller, new G3_Request( $caller_roster ) ), 'C13 must reject every caller-supplied learner list.' );

$snapshot    = new Assignment_Roster_Snapshot(
	'class',
	'class-21',
	'context-r1',
	'roster-r1',
	array(
		array(
			'learner_user_id'     => 19,
			'membership_revision' => 'member-19',
		),
		array(
			'learner_user_id'     => 7,
			'membership_revision' => 'member-7',
		),
	)
);
$rosters     = new G3_Roster_Port( $snapshot );
$database    = new G3_Database( $canonical, $publication_id, $publication_hash );
$repository  = new WordPress_Assignment_Repository( $database, $canonical );
$id_sequence = 0;
$ids         = new Public_Id_Generator(
	static function ( int $length ) use ( &$id_sequence ): string {
		++$id_sequence;
		return str_repeat( chr( $id_sequence ), $length );
	}
);
$application = new Create_Assignment( $repository, $rosters, new G3_Display_Port(), $ids, $canonical, static fn (): string => '2026-08-21 22:00:00' );
$result      = $application->execute( $command );
g3_same( false, $result->replayed(), 'The first C13 execution must create one assignment.' );
g3_same( '분수 비교 활동지', $result->assignment()['title'], 'C13 must snapshot the immutable student publication title.' );
g3_same( $publication_hash, $result->assignment()['publication_hash'], 'C13 must pin the exact publication hash.' );
g3_same( 'context-r1', $result->assignment()['context']['revision'], 'C13 must pin the exact Context revision.' );
g3_same( 'roster-r1', $result->assignment()['target_roster_revision'], 'C13 must pin the exact roster revision.' );
g3_same( 2, $result->assignment()['target_count'], 'C13 target count must match the exact CG11 snapshot.' );
g3_same( array( 'START TRANSACTION', 'COMMIT' ), $database->queries, 'C13 assignment, targets, audit, and outbox must commit atomically.' );
g3_same( 1, count( $database->tables['oj_ai_assignments'] ), 'C13 must write one literal assignment row.' );
g3_same( 2, count( $database->tables['oj_ai_assignment_targets'] ), 'C13 must write one literal target row per learner.' );
g3_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C13 must append one audit event.' );
g3_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C13 must append one outbox event.' );
g3_same( array( 7, 19 ), array_column( $database->tables['oj_ai_assignment_targets'], 'learner_user_id' ), 'C13 target persistence must be deterministic.' );
foreach ( $database->tables['oj_ai_assignment_targets'] as $target ) {
	$display = json_decode( $target['display_snapshot_json'], true, 512, JSON_THROW_ON_ERROR );
	g3_same( array( 'display_name' ), array_keys( $display ), 'Only the minimal same-site display name may persist.' );
	g3_assert( ! str_contains( $target['display_snapshot_json'], 'example.test' ), 'Email must never enter an assignment target snapshot.' );
}

$replay = $application->execute( $command );
g3_same( true, $replay->replayed(), 'An exact C13 retry must replay its durable receipt.' );
g3_same( 1, $rosters->calls, 'C13 replay must occur before another CG11 roster read.' );
g3_same( 1, count( $database->tables['oj_ai_assignments'] ), 'C13 replay must not append an assignment.' );

$stale = new Create_Assignment_Command( 42, 'g3-command-0002', $publication_id, hash( 'sha256', 'stale-publication' ), 'class', 'class-21', 'context-r1', $schedule, $resubmission, hash( 'sha256', 'g3-idempotency-2' ), 'g3-correlation-0002', $canonical );
g3_failure( static fn () => $application->execute( $stale ), Assignment_Failure::PUBLICATION_CONFLICT, 'C13 must reject a changed publication under lock.' );
g3_same( 1, count( $database->tables['oj_ai_assignments'] ), 'A publication conflict must leave no partial assignment.' );
g3_same( 'ROLLBACK', $database->queries[ count( $database->queries ) - 1 ], 'A publication conflict must roll back the C13 transaction.' );

$overlap_database   = clone $database;
$overlap_repository = new WordPress_Assignment_Repository( $overlap_database, $canonical );
$group_roster       = new Assignment_Roster_Snapshot( 'group', 'group-1', 'group-r1', 'group-roster-r1', $snapshot->learners() );
$group_application  = new Create_Assignment( $overlap_repository, new G3_Roster_Port( $group_roster ), new G3_Display_Port(), $ids, $canonical, static fn (): string => '2026-08-21 22:00:00' );
$group_command      = new Create_Assignment_Command( 42, 'group-overlap-0001', $publication_id, $publication_hash, 'group', 'group-1', 'group-r1', $schedule, $resubmission, hash( 'sha256', 'group-overlap-0001' ), 'group-overlap-0001', $canonical );
foreach ( array( 'draft', 'scheduled', 'open', 'closed' ) as $existing_state ) {
	$overlap_database->tables['oj_ai_assignments'][0]['state'] = $existing_state;
	$before = $overlap_database->tables;
	g3_failure( static fn () => $group_application->execute( $group_command ), Assignment_Failure::TARGET_OVERLAP, 'The same learners must not receive a second copy through a group.' );
	g3_same( $before, $overlap_database->tables, 'Overlap rejection must not append targets, assignments, audits, or receipts.' );
	g3_same( 'ROLLBACK', end( $overlap_database->queries ), 'Overlap rejection must roll back.' );
}
$other_roster      = new Assignment_Roster_Snapshot(
	'group',
	'group-2',
	'group-r2',
	'roster-r2',
	array(
		array(
			'learner_user_id'     => 88,
			'membership_revision' => 'member-88',
		),
	)
);
$other_application = new Create_Assignment( $overlap_repository, new G3_Roster_Port( $other_roster ), new G3_Display_Port(), $ids, $canonical, static fn (): string => '2026-08-21 22:00:00' );
$other_command     = new Create_Assignment_Command( 42, 'group-other-0001', $publication_id, $publication_hash, 'group', 'group-2', 'group-r2', $schedule, $resubmission, hash( 'sha256', 'group-other-0001' ), 'group-other-0001', $canonical );
g3_same( false, $other_application->execute( $other_command )->replayed(), 'One publication can be distributed independently to another group.' );
g3_same( true, $other_application->execute( $other_command )->replayed(), 'Successful group creation remains idempotent.' );
$overlap_database->tables['oj_ai_assignments'][0]['state'] = 'withdrawn';
g3_same( false, $group_application->execute( $group_command )->replayed(), 'A withdrawn assignment no longer blocks a replacement.' );

$repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/WordPress_Assignment_Repository.php' );
$roster_source     = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/Platform_Assignment_Roster.php' );
$controller_source = (string) file_get_contents( $addon_root . '/src/Submission/Assignment/Presentation/Assignment_Rest_Controller.php' );
$handler_source    = (string) file_get_contents( $addon_root . '/src/Platform/Assignment_Workbench_Page_Handler.php' );
$ui_source         = (string) file_get_contents( $addon_root . '/resources/js/assignment-workbench.js' );
foreach ( array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE', 'assignment.created' ) as $marker ) {
	g3_assert( str_contains( $repository_source, $marker ), 'C13 transaction marker is missing: ' . $marker );
}
g3_assert( str_contains( $roster_source, 'instanceof AddonAssignmentRosterGatewayV1' ), 'CG11 must use the public extension interface.' );
g3_assert( ! str_contains( $roster_source, 'method_exists' ), 'CG11 support must not be guessed with method_exists().' );
g3_assert( str_contains( $controller_source, "'/assignments'" ) && str_contains( $controller_source, "'/assignments/(?P<assignment_id>" ), 'Q11, Q12, and C13 REST routes must remain registered.' );
g3_assert( ! str_contains( $controller_source, 'learner_user_ids' ), 'C13 REST source must not accept a caller roster.' );
foreach ( array( '발행본', '대상', '일정', '확인', '배포 만들기' ) as $label ) {
	g3_assert( str_contains( $handler_source, $label ), 'The short assignment UI label is missing: ' . $label );
}
g3_assert( str_contains( $handler_source, 'role="dialog"' ) && str_contains( $handler_source, 'aria-modal="true"' ), 'The four-step assignment flow must expose dialog semantics.' );
g3_assert( str_contains( $ui_source, "allPages(request, '/contexts?limit=100')" ) && str_contains( $ui_source, "request('/assignments'" ), 'The assignment UI must paginate Q01 and consume C13.' );
g3_assert( str_contains( $repository_source, 'w.id = p.worksheet_id WHERE p.public_id = a.worksheet_publication_public_id' ), 'Assignment worksheet identity must come from its own immutable publication, not the latest publication.' );
g3_assert( Ojai_Php_Source_Contract::contains( $repository_source, "'worksheet_id' => \$row['worksheet_public_id']" ), 'Assignment list and detail must expose the owning worksheet.' );
$create_ui_source = substr( $ui_source, strpos( $ui_source, 'const createAssignment =' ), strpos( $ui_source, 'const lifecycleNotice =' ) - strpos( $ui_source, 'const createAssignment =' ) );
g3_assert( ! str_contains( $create_ui_source, 'learner_user_ids' ), 'Initial assignment creation must never accept a caller roster; later additions revalidate selected IDs separately.' );
foreach ( array( $repository_source, $roster_source, $controller_source ) as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3 product Runtime must not depend on platform internals.' );
}

echo "G3-IMP-01 passed: Q11/Q12, CG11 exact roster, C13 atomic assignment, 5,000 bound, idempotency, PII minimization, and four-step UI contract.\n";

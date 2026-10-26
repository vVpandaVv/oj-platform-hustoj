<?php
/**
 * G3-IMP-03 learner assignment and StartOwnSubmission contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessDecisionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotResultV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Read_Student_Assignments;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Infrastructure\Platform_Learning_Context_Query;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-03 learner assignment and StartOwnSubmission contract.
 */
final class G3_Student_Learning_Gateway implements AddonLearningContextGatewayV1 {
	/**
	 * Queries.
	 *
	 * @var array<int,AddonLearningContextQueryV1>
	 */
	public array $queries = array();
	/**
	 * Producer.
	 *
	 * @var Closure
	 */
	private Closure $producer;
	/**
	 * Initialize g3 student learning gateway dependencies and state.
	 *
	 * @param callable $producer Producer.
	 */
	public function __construct( callable $producer ) {
		$this->producer = Closure::fromCallable( $producer );
	}
	/**
	 * List learning contexts.
	 *
	 * @param AddonLearningContextQueryV1 $query Query.
	 * @return AddonContextPageV1
	 */
	public function listLearningContexts( AddonLearningContextQueryV1 $query ): AddonContextPageV1 {
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
 * G3-IMP-03 learner assignment and StartOwnSubmission contract.
 */
final class G3_Student_Context_Port implements Learning_Context_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g3 student context port dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $contexts Contexts.
	 */
	public function __construct( private array $contexts ) {
	}
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		g3_same( 7, $actor_user_id, 'Q30/Q31/C22 must derive the learner from the authenticated actor.' );
		return $this->contexts;
	}
}

/**
 * G3-IMP-03 learner assignment and StartOwnSubmission contract.
 */
final class G3_Student_Database {
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
	private int $next_id = 100;

	/**
	 * Initialize g3 student database dependencies and state.
	 *
	 * @param array<string,mixed>            $assignment Assignment.
	 * @param array<int,array<string,mixed>> $targets Targets.
	 * @param array<string,mixed>            $publication Publication.
	 */
	public function __construct( array $assignment, array $targets, array $publication ) {
		$this->tables = array(
			'oj_ai_assignments'            => array( $assignment ),
			'oj_ai_assignment_targets'     => $targets,
			'oj_ai_worksheet_publications' => array( $publication ),
			'oj_ai_submissions'            => array(),
			'oj_ai_audit_events'           => array(),
			'oj_ai_outbox_events'          => array(),
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
		return 'G3S:' . base64_encode( serialize( array( $sql, $args ) ) );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $prepared, mixed $output = null ): array {
		unset( $output );
		[ $sql, $args ] = $this->prepared( $prepared );
		g3_assert( str_contains( $sql, 't.learner_user_id = %d' ) && str_contains( $sql, 's.learner_user_id = %d' ), 'Q30 SQL must bind the actor to target and submission joins.' );
		$actor = (int) ( $args[0] ?? 0 );
		$rows  = array();
		foreach ( $this->tables['oj_ai_assignments'] as $assignment ) {
			$target = $this->target( (int) $assignment['id'], $actor );
			if ( null !== $target && 'active' === $target['state'] && in_array( $assignment['state'], array( 'scheduled', 'open', 'closed' ), true ) ) {
				$rows[] = $this->projection( $assignment, $target, $this->submission( (int) $assignment['id'], $actor ) );
			}
		}
		return $rows;
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
		if ( str_contains( $sql, 'student_document_schema' ) ) {
			$actor      = (int) ( $args[0] ?? 0 );
			$assignment = $this->assignment_by_public_id( (string) ( $args[3] ?? '' ) );
			$target     = null === $assignment ? null : $this->target( (int) $assignment['id'], $actor );
			if ( null === $assignment || null === $target || 'active' !== $target['state'] ) {
				return null;
			}
			$publication = $this->tables['oj_ai_worksheet_publications'][0];
			return array_merge(
				$this->projection( $assignment, $target, $this->submission( (int) $assignment['id'], $actor ) ),
				array(
					'stored_content_hash'      => $publication['content_hash'],
					'stored_publication_hash'  => $publication['publication_hash'],
					'student_document_schema'  => $publication['student_document_schema'],
					'student_document_version' => $publication['student_document_version'],
					'student_document_json'    => $publication['student_document_json'],
					'student_document_hash'    => $publication['student_document_hash'],
					'response_schema_schema'   => $publication['response_schema_schema'],
					'response_schema_version'  => $publication['response_schema_version'],
					'response_schema_json'     => $publication['response_schema_json'],
					'response_schema_hash'     => $publication['response_schema_hash'],
				)
			);
		}
		if ( str_contains( $sql, 'SELECT a.public_id, a.lock_version' ) ) {
			$assignment = $this->assignment_by_public_id( (string) ( $args[0] ?? '' ) );
			$target     = null === $assignment ? null : $this->target( (int) $assignment['id'], (int) ( $args[1] ?? 0 ) );
			if ( null === $assignment || null === $target ) {
				return null;
			}
			return array(
				'public_id'        => $assignment['public_id'],
				'lock_version'     => $assignment['lock_version'],
				'context_type'     => $assignment['context_type'],
				'context_id'       => $assignment['context_id'],
				'context_revision' => $assignment['context_revision'],
				'state'            => $assignment['state'],
				'target_state'     => $target['state'],
			);
		}
		if ( str_contains( $sql, 'SELECT a.id AS assignment_pk' ) ) {
			$assignment = $this->assignment_by_public_id( (string) ( $args[0] ?? '' ) );
			$target     = null === $assignment ? null : $this->target( (int) $assignment['id'], (int) ( $args[1] ?? 0 ) );
			return null === $assignment || null === $target ? null : $this->projection( $assignment, $target, null );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submissions WHERE assignment_id = %d' ) ) {
			return $this->submission( (int) ( $args[0] ?? 0 ), (int) ( $args[1] ?? 0 ) );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submissions WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_submissions'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected G3 student get_row SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'C22 audit chain must use the literal table.' );
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? ( $last['event_hash'] ?? null ) : null;
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
			throw new RuntimeException( 'Unexpected G3 student insert table: ' . $table );
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
		if ( ! str_starts_with( $value, 'G3S:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared G3 student SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid G3 student prepared SQL.' );
		}
		return $decoded;
	}

	/**
	 * Assignment by public id.
	 *
	 * @param string $id Id.
	 * @return array<string,mixed>|null
	 */
	private function assignment_by_public_id( string $id ): ?array {
		foreach ( $this->tables['oj_ai_assignments'] as $row ) {
			if ( $row['public_id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Target.
	 *
	 * @param int $assignment_id Distribution public identifier.
	 * @param int $actor Actor.
	 * @return array<string,mixed>|null
	 */
	private function target( int $assignment_id, int $actor ): ?array {
		foreach ( $this->tables['oj_ai_assignment_targets'] as $row ) {
			if ( $row['assignment_id'] === $assignment_id && $row['learner_user_id'] === $actor ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Submission.
	 *
	 * @param int $assignment_id Distribution public identifier.
	 * @param int $actor Actor.
	 * @return array<string,mixed>|null
	 */
	private function submission( int $assignment_id, int $actor ): ?array {
		foreach ( $this->tables['oj_ai_submissions'] as $row ) {
			if ( $row['assignment_id'] === $assignment_id && $row['learner_user_id'] === $actor ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Projection.
	 *
	 * @param array<string,mixed>      $assignment Assignment.
	 * @param array<string,mixed>      $target Target.
	 * @param array<string,mixed>|null $submission Submission.
	 * @return array<string,mixed>
	 */
	private function projection( array $assignment, array $target, ?array $submission ): array {
		return array(
			'assignment_pk'                   => $assignment['id'],
			'assignment_id'                   => $assignment['public_id'],
			'assignment_lock_version'         => $assignment['lock_version'],
			'context_type'                    => $assignment['context_type'],
			'context_id'                      => $assignment['context_id'],
			'context_revision'                => $assignment['context_revision'],
			'worksheet_publication_public_id' => $assignment['worksheet_publication_public_id'],
			'publication_hash'                => $assignment['publication_hash'],
			'title_snapshot'                  => $assignment['title_snapshot'],
			'assignment_state'                => $assignment['state'],
			'opens_at_utc'                    => $assignment['opens_at_utc'],
			'due_at_utc'                      => $assignment['due_at_utc'],
			'closes_at_utc'                   => $assignment['closes_at_utc'],
			'schedule_version'                => $assignment['schedule_version'],
			'schedule_hash'                   => $assignment['schedule_hash'],
			'auto_submit_enabled'             => $assignment['auto_submit_enabled'],
			'updated_at_utc'                  => $assignment['updated_at_utc'],
			'target_id'                       => $target['public_id'],
			'target_state'                    => $target['state'],
			'submission_id'                   => $submission['public_id'] ?? null,
			'submission_lock_version'         => $submission['lock_version'] ?? null,
			'submission_state'                => $submission['state'] ?? null,
			'current_revision_public_id'      => $submission['current_revision_public_id'] ?? null,
			'official_revision_public_id'     => $submission['official_revision_public_id'] ?? null,
			'started_at_utc'                  => $submission['started_at_utc'] ?? null,
			'submitted_at_utc'                => $submission['submitted_at_utc'] ?? null,
			'closed_at_utc'                   => $submission['closed_at_utc'] ?? null,
		);
	}
}

/**
 * G3-IMP-03 learner assignment and StartOwnSubmission contract.
 */
final class G3_Student_Request {
	/**
	 * Initialize g3 student request dependencies and state.
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
 * G3s context page.
 *
 * @param array  $items Items.
 * @param string $next Next.
 * @return AddonContextPageV1
 */
function g3s_context_page( array $items, string $next = '' ): AddonContextPageV1 {
	return new AddonContextPageV1( true, $items, $next, null );
}

/**
 * G3s profile.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $schema Schema.
 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
 * @param string              $source_hash Source hash.
 * @return array{json:string,hash:string}
 */
function g3s_profile( Canonical_Json $canonical, string $schema, array $payload, string $source_hash ): array {
	$envelope = array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => '1.0.0',
		'schema_id'         => $schema,
		'schema_version'    => '1.0.0',
		'source_hash'       => $source_hash,
	);
	$json     = $canonical->encode( $envelope );
	return array(
		'json' => $json,
		'hash' => hash( 'sha256', $json ),
	);
}

$learning_gateway  = new G3_Student_Learning_Gateway(
	static fn ( AddonLearningContextQueryV1 $query, int $call ): AddonContextPageV1 => 1 === $call
		? g3s_context_page( array( new AddonContextSummaryV1( 'class', 'class-21', '2학년 1반', 'active', 'member-r2', array( 'subject_label' => '수학' ) ) ), 'next' )
		: g3s_context_page( array( new AddonContextSummaryV1( 'group', 'club-1', '수학 동아리', 'inactive', 'member-r1' ) ) )
);
$learning_adapter  = new Platform_Learning_Context_Query( new G3_Runtime( true, $learning_gateway ) );
$learning_contexts = $learning_adapter->for_actor( 7 );
g3_same( array( 'class:class-21' ), array_keys( $learning_contexts ), 'CG02 must retain only active current learning Contexts.' );
g3_same( 7, $learning_gateway->queries[0]->actorUserId(), 'CG02 must use the authenticated actor.' );
g3_same( array( 'class', 'group' ), $learning_gateway->queries[0]->types(), 'CG02 must query only learning Context types.' );
g3_same( 100, $learning_gateway->queries[0]->limit(), 'CG02 must use the frozen bounded page size.' );

$duplicate_learning = new G3_Student_Learning_Gateway(
	static fn ( AddonLearningContextQueryV1 $query, int $call ): AddonContextPageV1 => g3s_context_page(
		array( new AddonContextSummaryV1( 'group', 'inactive-1', '종료 그룹', 'inactive', 'member-r1' ) ),
		1 === $call ? 'next' : ''
	)
);
g3_failure( static fn () => ( new Platform_Learning_Context_Query( new G3_Runtime( true, $duplicate_learning ) ) )->for_actor( 7 ), Assignment_Failure::ROSTER_INVALID, 'CG02 must reject duplicates even when the Context is inactive.' );

$canonical          = new Canonical_Json();
$assignment_id      = '01040G2081040G2081040G2081';
$publication_id     = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
$publication_hash   = hash( 'sha256', 'g3-student-publication' );
$schedule           = array(
	'auto_submit_enabled' => true,
	'closes_at_utc'       => '2026-08-22 12:00:00',
	'due_at_utc'          => '2026-08-22 11:00:00',
	'opens_at_utc'        => null,
	'schedule_version'    => 1,
);
$student_document   = g3s_profile(
	$canonical,
	'oj_ai.student_document',
	array(
		'nodes' => array(
			array(
				'type'     => 'paragraph',
				'children' => array( array( 'text' => '분수를 비교하세요.' ) ),
			),
			array(
				'type'        => 'oj_ai_response',
				'response_id' => 'response-1',
				'children'    => array( array( 'text' => '' ) ),
			),
		),
	),
	$publication_hash
);
$response_schema    = g3s_profile(
	$canonical,
	'oj_ai.student_response_schema',
	array(
		'responses' => array(
			array(
				'id'       => 'response-1',
				'type'     => 'long_text',
				'required' => true,
			),
		),
	),
	$publication_hash
);
$assignment         = array(
	'id'                              => 10,
	'public_id'                       => $assignment_id,
	'lock_version'                    => 0,
	'context_type'                    => 'class',
	'context_id'                      => 'class-21',
	'context_revision'                => 'context-snapshot-r1',
	'worksheet_publication_public_id' => $publication_id,
	'publication_hash'                => $publication_hash,
	'title_snapshot'                  => '분수 비교 활동지',
	'state'                           => 'open',
	'opens_at_utc'                    => null,
	'due_at_utc'                      => $schedule['due_at_utc'],
	'closes_at_utc'                   => $schedule['closes_at_utc'],
	'schedule_version'                => 1,
	'schedule_hash'                   => hash( 'sha256', $canonical->encode( $schedule ) ),
	'auto_submit_enabled'             => 1,
	'updated_at_utc'                  => '2026-08-22 09:00:00',
);
$targets            = array(
	array(
		'id'              => 20,
		'public_id'       => '02040G2081040G2081040G2082',
		'assignment_id'   => 10,
		'learner_user_id' => 7,
		'state'           => 'active',
	),
	array(
		'id'              => 21,
		'public_id'       => '03040G2081040G2081040G2083',
		'assignment_id'   => 10,
		'learner_user_id' => 99,
		'state'           => 'active',
	),
);
$publication        = array(
	'public_id'                => $publication_id,
	'content_hash'             => $publication_hash,
	'publication_hash'         => $publication_hash,
	'student_document_schema'  => 'oj_ai.student_document',
	'student_document_version' => 1,
	'student_document_json'    => $student_document['json'],
	'student_document_hash'    => $student_document['hash'],
	'response_schema_schema'   => 'oj_ai.student_response_schema',
	'response_schema_version'  => 1,
	'response_schema_json'     => $response_schema['json'],
	'response_schema_hash'     => $response_schema['hash'],
);
$student_database   = new G3_Student_Database( $assignment, $targets, $publication );
$student_repository = new WordPress_Student_Assignment_Repository( $student_database, $canonical );
$context_port       = new G3_Student_Context_Port( $learning_contexts );
$read_student       = new Read_Student_Assignments( $student_repository, $context_port, static fn (): string => '2026-08-22 10:00:00' );

$list = $read_student->list( 7, '', 30 );
g3_same( 1, count( $list['items'] ), 'Q30 must return only the authenticated learner target.' );
g3_same( $assignment_id, $list['items'][0]['id'], 'Q30 returned the wrong assignment.' );
g3_same( 'open', $list['items'][0]['availability'], 'An open assignment with no opens_at must be immediately available.' );
g3_assert( ! str_contains( $canonical->encode( $list ), 'learner_user_id' ), 'Q30 must not expose a learner identifier field.' );
g3_assert( ! str_contains( $canonical->encode( $list ), $targets[1]['public_id'] ), 'Q30 must not expose another learner target.' );

$detail = $read_student->detail( 7, $assignment_id );
g3_same( 'oj_ai.student_document', $detail['assignment']['student_document']['schema_id'], 'Q31 must return P01 after opening.' );
g3_same( 'oj_ai.student_response_schema', $detail['assignment']['response_schema']['schema_id'], 'Q31 must return P02 after opening.' );

$after_close   = new Read_Student_Assignments( $student_repository, $context_port, static fn (): string => '2026-08-22 12:00:01' );
$closed_detail = $after_close->detail( 7, $assignment_id );
g3_same( 'closed', $closed_detail['assignment']['availability'], 'The clock must close an open assignment.' );
g3_same( $detail['assignment']['student_document'], $closed_detail['assignment']['student_document'], 'Clock-based closure must retain the safe read-only worksheet.' );
g3_same( $detail['assignment']['response_schema'], $closed_detail['assignment']['response_schema'], 'Clock-based closure must retain answer rendering metadata.' );

$student_database->tables['oj_ai_assignments'][0]['state']        = 'scheduled';
$student_database->tables['oj_ai_assignments'][0]['opens_at_utc'] = '2026-08-22 10:30:00';
$future_schedule = array_replace( $schedule, array( 'opens_at_utc' => '2026-08-22 10:30:00' ) );
$student_database->tables['oj_ai_assignments'][0]['schedule_hash'] = hash( 'sha256', $canonical->encode( $future_schedule ) );
$before_open = $read_student->detail( 7, $assignment_id );
g3_same( null, $before_open['assignment']['student_document'], 'Q31 must hide P01 before opening.' );
g3_same( null, $before_open['assignment']['response_schema'], 'Q31 must hide P02 before opening.' );
g3_same( null, $after_close->detail( 7, $assignment_id )['assignment']['student_document'], 'An unopened scheduled assignment must remain private even after its close time.' );

$student_database->tables['oj_ai_assignments'][0] = $assignment;
$unsafe = g3s_profile(
	$canonical,
	'oj_ai.student_document',
	array(
		'nodes'  => array(),
		'rubric' => array( 'secret' => true ),
	),
	$publication_hash
);
$student_database->tables['oj_ai_worksheet_publications'][0]['student_document_json'] = $unsafe['json'];
$student_database->tables['oj_ai_worksheet_publications'][0]['student_document_hash'] = $unsafe['hash'];
g3_failure( static fn () => $read_student->detail( 7, $assignment_id ), Assignment_Failure::STORED_DATA_INVALID, 'Q31 must fail closed if a private grading key enters P01.' );
$student_database->tables['oj_ai_worksheet_publications'][0] = $publication;
$unsafe_camel = g3s_profile(
	$canonical,
	'oj_ai.student_document',
	array(
		'nodes'       => array(),
		'teacherNote' => 'private',
	),
	$publication_hash
);
$student_database->tables['oj_ai_worksheet_publications'][0]['student_document_json'] = $unsafe_camel['json'];
$student_database->tables['oj_ai_worksheet_publications'][0]['student_document_hash'] = $unsafe_camel['hash'];
g3_failure( static fn () => $read_student->detail( 7, $assignment_id ), Assignment_Failure::STORED_DATA_INVALID, 'Q31 must reject camelCase variants of private projection keys.' );
$student_database->tables['oj_ai_worksheet_publications'][0] = $publication;

$id_seed       = 80;
$student_ids   = new Public_Id_Generator(
	static function ( int $length ) use ( &$id_seed ): string {
		++$id_seed;
		return str_repeat( chr( $id_seed ), $length );
	}
);
$start_student = new Start_Own_Submission( $student_repository, $context_port, $student_ids, static fn (): string => '2026-08-22 10:05:00' );
$command       = new Start_Own_Submission_Command(
	7,
	$assignment_id,
	'student-start-0001',
	'open',
	0,
	1,
	$assignment['schedule_hash'],
	$publication_hash,
	hash( 'sha256', 'student-idempotency' ),
	'student-correlation-0001',
	$canonical
);
$started       = $start_student->execute( $command );
g3_same( true, $started->created(), 'C22 must create the learner submission root once.' );
g3_same( null, $started->submission()['current_revision_id'], 'C22 must not create a revision before C23.' );
g3_same( 1, count( $student_database->tables['oj_ai_submissions'] ), 'C22 must persist one submission root.' );
g3_same( 1, count( $student_database->tables['oj_ai_audit_events'] ), 'C22 must append one audit event.' );
g3_same( 1, count( $student_database->tables['oj_ai_outbox_events'] ), 'C22 must append one durable receipt event.' );
g3_same( array( 'START TRANSACTION', 'COMMIT' ), $student_database->queries, 'C22 root, audit, and outbox must commit atomically.' );

$replayed = $start_student->execute( $command );
g3_same( true, $replayed->replayed(), 'An exact C22 retry must replay its durable receipt.' );
g3_same( 1, count( $student_database->tables['oj_ai_submissions'] ), 'C22 replay must not create another root.' );
$student_database->tables['oj_ai_submissions'][0]['state'] = 'submitted';
$submitted_list = $read_student->list( 7, '', 30 );
g3_same( array( 'assignment.export_pdf' ), $submitted_list['items'][0]['actions'], 'A submitted root must expose PDF without exposing continue while the assignment remains open.' );
$student_database->tables['oj_ai_submissions'][0]['state'] = 'draft';

$stale_command = new Start_Own_Submission_Command(
	7,
	$assignment_id,
	'student-start-stale',
	'open',
	1,
	1,
	$assignment['schedule_hash'],
	$publication_hash,
	hash( 'sha256', 'student-idempotency-stale' ),
	'student-correlation-stale',
	$canonical
);
g3_failure( static fn () => $start_student->execute( $stale_command ), Assignment_Failure::STATE_CONFLICT, 'C22 must reject stale lock CAS.' );
g3_same( 'ROLLBACK', $student_database->queries[ count( $student_database->queries ) - 1 ], 'A stale C22 attempt must roll back.' );

$student_database->tables['oj_ai_assignment_targets'][0]['state'] = 'revoked';
g3_failure( static fn () => $read_student->detail( 7, $assignment_id ), Assignment_Failure::NOT_FOUND, 'A revoked target must disappear as not found.' );
$student_database->tables['oj_ai_assignment_targets'][0]['state'] = 'active';
$student_database->tables['oj_ai_assignments'][0]['state']        = 'withdrawn';
g3_failure( static fn () => $read_student->detail( 7, $assignment_id ), Assignment_Failure::NOT_FOUND, 'A withdrawn assignment must disappear as not found.' );
$student_database->tables['oj_ai_assignments'][0]['state'] = 'open';

$controller_reflection = new ReflectionClass( Student_Assignment_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$start_input           = $controller_reflection->getMethod( 'start_input' );
$start_body            = array(
	'command_id' => 'student-rest-start1',
	'expected'   => array(
		'expected_state'            => 'open',
		'expected_lock_version'     => 0,
		'expected_schedule_version' => 1,
		'expected_schedule_hash'    => $assignment['schedule_hash'],
		'expected_publication_hash' => $publication_hash,
	),
	'payload'    => array(),
);
$parsed                = $start_input->invoke( $controller, new G3_Student_Request( $start_body ) );
g3_same( $assignment['schedule_hash'], $parsed['expected_schedule_hash'], 'C22 REST must preserve the exact schedule CAS.' );
$start_body['payload']['answer'] = 'caller data';
g3_invalid( static fn () => $start_input->invoke( $controller, new G3_Student_Request( $start_body ) ), 'C22 start payload must be exactly empty.' );

$student_sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Platform_Learning_Context_Query.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Assignment_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Platform/Student_Assignment_Page_Handler.php' ),
);
g3_assert( str_contains( $student_sources[0], 'instanceof AddonLearningContextGatewayV1' ), 'CG02 must use the public learning Context gateway.' );
g3_assert( ! str_contains( $student_sources[0], 'method_exists' ), 'CG02 must not guess platform support.' );
foreach ( array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_submissions', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE' ) as $marker ) {
	g3_assert( str_contains( $student_sources[1], $marker ), 'G3-IMP-03 persistence marker is missing: ' . $marker );
}
foreach ( array( '/me/assignments', 'commands/start', 'expected_publication_hash', 'expected_schedule_hash' ) as $marker ) {
	g3_assert( str_contains( $student_sources[2], $marker ), 'Q30/Q31/C22 REST marker is missing: ' . $marker );
}
foreach ( $student_sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3-IMP-03 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3-IMP-03 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3-IMP-03 product Runtime must not depend on platform internals.' );
}

echo "G3-IMP-03 backend passed: CG02, Q30/Q31 self-only projection, P01/P02 privacy, C22 CAS, atomic root, audit, outbox, replay, and not-found hiding.\n";

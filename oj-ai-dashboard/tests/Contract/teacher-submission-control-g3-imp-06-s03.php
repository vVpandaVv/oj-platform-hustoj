<?php
/**
 * G3-IMP-06-S03 C27-C28 teacher reopen and lock contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Object_Authorization;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Command;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Control_Repository;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Presentation\Teacher_Submission_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C27 id.
 *
 * @param int $number Number.
 * @return string
 */
function c27_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-06-S03 C27-C28 teacher reopen and lock contract.
 */
final class C27_Request {
	/**
	 * Initialize c27 request dependencies and state.
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
 * G3-IMP-06-S03 C27-C28 teacher reopen and lock contract.
 */
final class C27_Database {
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
	 * Initialize c27 database dependencies and state.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $tables Tables.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		return 'C27:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		if ( str_contains( $sql, 'a.resubmission_policy_schema' ) ) {
			$submission  = $this->tables['oj_ai_submissions'][0] ?? null;
			$assignment  = $this->tables['oj_ai_assignments'][0] ?? null;
			$target      = $this->tables['oj_ai_assignment_targets'][0] ?? null;
			$publication = $this->tables['oj_ai_worksheet_publications'][0] ?? null;
			if (
				! is_array( $submission ) || ! is_array( $assignment ) || ! is_array( $target ) || ! is_array( $publication )
				|| $submission['public_id'] !== ( $args[0] ?? null )
				|| $assignment['context_type'] !== ( $args[1] ?? null )
				|| $assignment['context_id'] !== ( $args[2] ?? null )
				|| $assignment['context_revision'] !== ( $args[3] ?? null )
			) {
				return null;
			}
			return array(
				'submission_pk'                   => $submission['id'],
				'submission_id'                   => $submission['public_id'],
				'submission_lock_version'         => $submission['lock_version'],
				'submission_state'                => $submission['state'],
				'current_revision_public_id'      => $submission['current_revision_public_id'],
				'official_revision_public_id'     => $submission['official_revision_public_id'],
				'official_attempt_no'             => $submission['official_attempt_no'],
				'submitted_at_utc'                => $submission['submitted_at_utc'],
				'closed_at_utc'                   => $submission['closed_at_utc'],
				'assignment_pk'                   => $assignment['id'],
				'assignment_id'                   => $assignment['public_id'],
				'context_type'                    => $assignment['context_type'],
				'context_id'                      => $assignment['context_id'],
				'context_revision'                => $assignment['context_revision'],
				'assignment_state'                => $assignment['state'],
				'worksheet_publication_public_id' => $assignment['worksheet_publication_public_id'],
				'publication_hash'                => $assignment['publication_hash'],
				'opens_at_utc'                    => $assignment['opens_at_utc'],
				'due_at_utc'                      => $assignment['due_at_utc'],
				'closes_at_utc'                   => $assignment['closes_at_utc'],
				'schedule_version'                => $assignment['schedule_version'],
				'schedule_hash'                   => $assignment['schedule_hash'],
				'auto_submit_enabled'             => $assignment['auto_submit_enabled'],
				'resubmission_policy_schema'      => $assignment['resubmission_policy_schema'],
				'resubmission_policy_version'     => $assignment['resubmission_policy_version'],
				'resubmission_policy_json'        => $assignment['resubmission_policy_json'],
				'resubmission_policy_hash'        => $assignment['resubmission_policy_hash'],
				'target_state'                    => $target['state'],
				'publication_content_hash'        => $publication['content_hash'],
				'stored_publication_hash'         => $publication['publication_hash'],
				'publication_source_hash'         => $publication['source_hash'],
				'response_schema_schema'          => $publication['response_schema_schema'],
				'response_schema_version'         => $publication['response_schema_version'],
				'response_schema_json'            => $publication['response_schema_json'],
				'response_schema_hash'            => $publication['response_schema_hash'],
			);
		}
		if ( str_contains( $sql, 'id AS revision_pk' ) && str_contains( $sql, 'oj_ai_submission_revisions' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['submission_id'] === ( $args[0] ?? null ) && $row['public_id'] === ( $args[1] ?? null ) ) {
					return array_merge( $row, array( 'revision_pk' => $row['id'] ) );
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'SELECT revision_no FROM oj_ai_submission_revisions' ) ) {
			$rows = array_values( array_filter( $this->tables['oj_ai_submission_revisions'], static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) ) );
			if ( array() === $rows ) {
				return null;
			}
			usort( $rows, static fn ( array $left, array $right ): int => $right['revision_no'] <=> $left['revision_no'] );
			return array( 'revision_no' => $rows[0]['revision_no'] );
		}
		if ( str_contains( $sql, 'SELECT receipt_no FROM oj_ai_submission_receipts' ) ) {
			$rows = array_values( array_filter( $this->tables['oj_ai_submission_receipts'], static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) ) );
			if ( array() === $rows ) {
				return null;
			}
			usort( $rows, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			return array( 'receipt_no' => $rows[0]['receipt_no'] );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submissions WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_submissions'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_receipts WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_submission_receipts'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'SELECT public_id, content_hash FROM oj_ai_submission_revisions' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C27 get_row SQL: ' . $sql );
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
		[ $sql ] = $this->prepared( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_submission_attachments' ) ) {
			return array();
		}
		throw new RuntimeException( 'Unexpected C27 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'C27-C28 audit chain must use the literal table.' );
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
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C27 insert table: ' . $table );
		}
		$id                       = $this->next_id++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		return 1;
	}

	/**
	 * Update c27 database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		foreach ( $this->tables[ $table ] ?? array() as $index => $row ) {
			$matches = true;
			foreach ( $where as $key => $value ) {
				if ( ! array_key_exists( $key, $row ) || $row[ $key ] !== $value ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				$this->tables[ $table ][ $index ] = array_merge( $row, $data );
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
		if ( ! str_starts_with( $value, 'C27:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared C27 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) ) {
			throw new RuntimeException( 'Invalid C27 prepared SQL.' );
		}
		return $decoded;
	}
}

/**
 * G3-IMP-06-S03 C27-C28 teacher reopen and lock contract.
 */
final class C27_Student_Access implements Student_Assignment_Access_Port_Interface {
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		unset( $assignment_id, $actor_user_id );
		return null;
	}
	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		unset( $submission_id, $actor_user_id );
		return null;
	}
}

/**
 * G3-IMP-06-S03 C27-C28 teacher reopen and lock contract.
 */
final class C27_Teacher_Query implements Teacher_Submission_Query_Port_Interface {
	/**
	 * Initialize c27 teacher query dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( private array $snapshot ) {
	}
	/**
	 * List assignment submissions.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_assignment_submissions( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		unset( $assignment_id, $context_type, $context_id, $context_revision, $cursor, $limit );
		return null;
	}
	/**
	 * Find submission.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 */
	public function find_submission( string $submission_id, string $context_type, string $context_id, string $context_revision ): ?array {
		unset( $submission_id, $context_type, $context_id, $context_revision );
		return null;
	}
	/**
	 * List submission history.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_submission_history( string $submission_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		unset( $submission_id, $context_type, $context_id, $context_revision, $cursor, $limit );
		return null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @return array|null
	 */
	public function authorization_snapshot( string $submission_id ): ?array {
		return $submission_id === $this->snapshot['submission_id'] ? $this->snapshot : null;
	}
}

/**
 * C27 fixture.
 *
 * @param int         $maximum Maximum.
 * @param string|null $reopen_until Reopen until.
 * @param bool        $copy Copy.
 * @return array<string,mixed>
 */
function c27_fixture( int $maximum = 2, ?string $reopen_until = null, bool $copy = true ): array {
	$canonical               = new Canonical_Json();
	$assignment_id           = c27_id( 1 );
	$publication_id          = c27_id( 2 );
	$submission_id           = c27_id( 3 );
	$revision_id             = c27_id( 4 );
	$response_id             = c27_id( 5 );
	$publication_hash        = hash( 'sha256', 'c27-publication' );
	$publication_source_hash = hash( 'sha256', 'c27-publication-source' );
	$payload                 = array(
		'items' => array(
			array(
				'response_id' => $response_id,
				'kind'        => 'short_text',
				'label'       => 'Answer',
				'required'    => true,
				'position'    => 0,
				'constraints' => array(
					'min_chars' => 1,
					'max_chars' => 100,
					'trim'      => true,
				),
			),
		),
	);
	$profile                 = array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => '1.0.0',
		'schema_id'         => 'oj_ai.student_response_schema',
		'schema_version'    => '1.0.0',
		'source_hash'       => $publication_source_hash,
	);
	$profile_json            = $canonical->encode( $profile );
	$response                = array(
		'answers'                 => array( $response_id => 'official answer' ),
		'publication_hash'        => $publication_hash,
		'publication_id'          => $publication_id,
		'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
	);
	$response_json           = $canonical->encode( $response );
	$response_hash           = hash( 'sha256', $response_json );
	$attachment_hash         = hash( 'sha256', $canonical->encode( array() ) );
	$content_hash            = ( new Submission_Revision_Integrity( $canonical ) )->content_hash(
		$submission_id,
		1,
		null,
		'saved',
		Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
		$response_hash,
		$attachment_hash
	);
	$schedule                = array(
		'auto_submit_enabled' => true,
		'closes_at_utc'       => '2026-08-22 12:00:00',
		'due_at_utc'          => '2026-08-22 10:00:00',
		'opens_at_utc'        => null,
		'schedule_version'    => 1,
	);
	$policy                  = array(
		'max_attempts'          => $maximum,
		'reopen_until_utc'      => $reopen_until,
		'copy_previous_answers' => $copy,
	);
	$policy_json             = $canonical->encode( $policy );
	$tables                  = array(
		'oj_ai_assignments'            => array(
			array(
				'id'                              => 10,
				'public_id'                       => $assignment_id,
				'context_type'                    => 'class',
				'context_id'                      => 'class-21',
				'context_revision'                => 'target-r1',
				'state'                           => 'open',
				'worksheet_publication_public_id' => $publication_id,
				'publication_hash'                => $publication_hash,
				'opens_at_utc'                    => null,
				'due_at_utc'                      => $schedule['due_at_utc'],
				'closes_at_utc'                   => $schedule['closes_at_utc'],
				'schedule_version'                => 1,
				'schedule_hash'                   => hash( 'sha256', $canonical->encode( $schedule ) ),
				'auto_submit_enabled'             => 1,
				'resubmission_policy_schema'      => 'oj_ai.assignment_resubmission_policy',
				'resubmission_policy_version'     => 1,
				'resubmission_policy_json'        => $policy_json,
				'resubmission_policy_hash'        => hash( 'sha256', $policy_json ),
			),
		),
		'oj_ai_assignment_targets'     => array(
			array(
				'id'              => 20,
				'assignment_id'   => 10,
				'learner_user_id' => 7,
				'state'           => 'active',
			),
		),
		'oj_ai_worksheet_publications' => array(
			array(
				'public_id'               => $publication_id,
				'content_hash'            => $publication_hash,
				'publication_hash'        => $publication_hash,
				'source_hash'             => $publication_source_hash,
				'response_schema_schema'  => 'oj_ai.student_response_schema',
				'response_schema_version' => 1,
				'response_schema_json'    => $profile_json,
				'response_schema_hash'    => hash( 'sha256', $profile_json ),
			),
		),
		'oj_ai_submissions'            => array(
			array(
				'id'                          => 30,
				'public_id'                   => $submission_id,
				'lock_version'                => 1,
				'learner_user_id'             => 7,
				'assignment_id'               => 10,
				'state'                       => 'submitted',
				'current_revision_public_id'  => $revision_id,
				'official_revision_public_id' => $revision_id,
				'official_attempt_no'         => 1,
				'submitted_at_utc'            => '2026-08-22 09:30:00',
				'closed_at_utc'               => null,
			),
		),
		'oj_ai_submission_revisions'   => array(
			array(
				'id'                      => 40,
				'public_id'               => $revision_id,
				'content_hash'            => $content_hash,
				'submission_id'           => 30,
				'revision_no'             => 1,
				'base_revision_no'        => null,
				'state'                   => 'saved',
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
				'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
				'response_json'           => $response_json,
				'response_hash'           => $response_hash,
				'attachment_set_hash'     => $attachment_hash,
			),
		),
		'oj_ai_submission_receipts'    => array(
			array(
				'id'                     => 90,
				'submission_id'          => 30,
				'submission_revision_id' => 40,
				'receipt_no'             => 1,
			),
		),
		'oj_ai_submission_attachments' => array(),
		'oj_ai_audit_events'           => array(),
		'oj_ai_outbox_events'          => array(),
	);
	return array(
		'canonical'            => $canonical,
		'database'             => new C27_Database( $tables ),
		'submission_id'        => $submission_id,
		'official_revision_id' => $revision_id,
		'publication_hash'     => $publication_hash,
		'schedule_hash'        => $tables['oj_ai_assignments'][0]['schedule_hash'],
		'response_id'          => $response_id,
	);
}

/**
 * C27 service.
 *
 * @param array<string,mixed> $fixture Fixture.
 * @return Control_Teacher_Submission
 */
function c27_service( array $fixture ): Control_Teacher_Submission {
	$characters = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'M', 'N' );
	$index      = 0;
	$ids        = new Public_Id_Generator(
		static function ( int $length ) use ( &$characters, &$index ): string {
			return str_repeat( $characters[ $index++ ], $length );
		}
	);
	return new Control_Teacher_Submission(
		new WordPress_Teacher_Submission_Control_Repository( $fixture['database'], $fixture['canonical'] ),
		$ids,
		static fn (): string => '2026-08-22 10:30:00'
	);
}

/**
 * C27 command.
 *
 * @param array<string,mixed> $fixture Fixture.
 * @param string              $action Action.
 * @param string              $fingerprint Fingerprint.
 * @param string              $state State.
 * @param int                 $lock Lock.
 * @param string              $command_id Command identifier used for idempotency.
 * @return Control_Teacher_Submission_Command
 */
function c27_command( array $fixture, string $action, string $fingerprint, string $state, int $lock, string $command_id ): Control_Teacher_Submission_Command {
	return new Control_Teacher_Submission_Command(
		19,
		$fixture['submission_id'],
		$action,
		$command_id,
		$state,
		$lock,
		$fixture['official_revision_id'],
		$fixture['attempt_no'] ?? 1,
		1,
		$fixture['schedule_hash'],
		$fixture['publication_hash'],
		'Teacher requested another attempt.',
		'class',
		'class-21',
		'target-r1',
		hash( 'sha256', $fingerprint ),
		'teacher-control-correlation-0001',
		$fixture['canonical']
	);
}

$fixture        = c27_fixture();
$service        = c27_service( $fixture );
$reopen_command = c27_command( $fixture, Control_Teacher_Submission_Command::REOPEN, 'reopen-key', 'submitted', 1, 'teacher-reopen-command-0001' );
$reopened       = $service->execute( $reopen_command );
$database       = $fixture['database'];
g3_same( 'reopened', $reopened->submission()['state'], 'C27 must make the submission editable without replacing the old official revision.' );
g3_same( $fixture['official_revision_id'], $reopened->submission()['official_revision_id'], 'C27 must preserve the old official pointer.' );
g3_same( 1, $reopened->submission()['official_attempt_no'], 'C27 must not consume the next attempt before C26 resubmission.' );
g3_same( 2, $reopened->submission()['lock_version'], 'C27 must advance the root lock once.' );
g3_same( 'reopen', $reopened->receipt()['action'], 'C27 must append a reopen receipt.' );
g3_same( 'teacher', $reopened->receipt()['actor_type'], 'C27 receipt must identify the teacher actor type.' );
g3_same( 'Teacher requested another attempt.', $reopened->receipt()['reason'], 'C27 must preserve the required teacher reason.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'C27 must append one immutable editable fork.' );
g3_same( 2, count( $database->tables['oj_ai_submission_receipts'] ), 'C27 must append one immutable receipt.' );
g3_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C27 must append one audit event.' );
g3_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C27 must append one durable replay event.' );
g3_same( array( 'START TRANSACTION', 'COMMIT' ), $database->queries, 'C27 root, fork, receipt, audit, and outbox must commit together.' );
$fork = $database->tables['oj_ai_submission_revisions'][1];
g3_same( 'reopened_fork', $fork['state'], 'C27 must mark the new revision as an editable reopened fork.' );
g3_same( 1, $fork['base_revision_no'], 'C27 fork must point to the old official revision number.' );
g3_same( $database->tables['oj_ai_submission_revisions'][0]['response_json'], $fork['response_json'], 'copy_previous_answers=true must copy the exact canonical official response.' );
g3_same( $fixture['official_revision_id'], $database->tables['oj_ai_submissions'][0]['official_revision_public_id'], 'C27 storage must not mutate the old official pointer.' );

$replayed = $service->execute( $reopen_command );
g3_same( true, $replayed->replayed(), 'An exact C27 retry must return the durable result.' );
g3_same( $reopened->receipt(), $replayed->receipt(), 'C27 replay must return the same receipt.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'C27 replay must not append another fork.' );
g3_failure(
	static fn () => $service->execute( c27_command( $fixture, Control_Teacher_Submission_Command::REOPEN, 'reopen-key', 'submitted', 1, 'teacher-reopen-command-changed' ) ),
	Assignment_Failure::IDEMPOTENCY_CONFLICT,
	'C27 must reject a changed request that reuses the same Idempotency-Key.'
);

$lock_command = c27_command( $fixture, Control_Teacher_Submission_Command::LOCK, 'lock-key', 'reopened', 2, 'teacher-lock-command-0001' );
$locked       = $service->execute( $lock_command );
g3_same( 'locked', $locked->submission()['state'], 'C28 must lock the submission root.' );
g3_same( $fixture['official_revision_id'], $locked->submission()['current_revision_id'], 'C28 must restore current to the preserved official revision.' );
g3_same( $fixture['official_revision_id'], $locked->submission()['official_revision_id'], 'C28 must preserve the official pointer.' );
g3_same( null, $locked->editable_revision(), 'C28 must not create a revision.' );
g3_same( '2026-08-22 10:30:00', $locked->submission()['closed_at_utc'], 'C28 must record the server lock time.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'C28 must preserve revision history without appending a new revision.' );
g3_same( 3, count( $database->tables['oj_ai_submission_receipts'] ), 'C28 must append a lock receipt.' );
g3_same( 2, count( $database->tables['oj_ai_audit_events'] ), 'C28 must append an audit event.' );
g3_same( 2, count( $database->tables['oj_ai_outbox_events'] ), 'C28 must append a durable replay event.' );
g3_same( array( 'START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'COMMIT' ), $database->queries, 'C27 and C28 must each commit atomically.' );
g3_same( true, $service->execute( $lock_command )->replayed(), 'An exact C28 retry must recover the durable lock result.' );

foreach ( array( 1, 2, 10 ) as $attempt_limit ) {
	$exhausted               = c27_fixture( $attempt_limit );
	$exhausted['attempt_no'] = $attempt_limit;
	$exhausted['database']->tables['oj_ai_submissions'][0]['official_attempt_no'] = $attempt_limit;
	$before            = $exhausted['database']->tables;
	$exhausted_service = c27_service( $exhausted );
	$grant_command     = c27_command( $exhausted, Control_Teacher_Submission_Command::REOPEN, 'exhausted-key', 'submitted', 1, 'teacher-reopen-exhausted' );
	$grant             = $exhausted_service->execute( $grant_command );
	g3_same( 'reopened', $grant->submission()['state'], 'A teacher may grant one additional submission at the limit.' );
	g3_same( $attempt_limit, $grant->submission()['official_attempt_no'], 'Granting must not erase or increment earlier attempts.' );
	g3_same( $before['oj_ai_assignments'], $exhausted['database']->tables['oj_ai_assignments'], 'The class-wide limit must not change.' );
	g3_same( $before['oj_ai_submission_revisions'][0], $exhausted['database']->tables['oj_ai_submission_revisions'][0], 'The official answer must remain immutable.' );
	g3_same( $before['oj_ai_submission_receipts'][0], $exhausted['database']->tables['oj_ai_submission_receipts'][0], 'Earlier receipts must remain immutable.' );
	g3_same( 'Teacher requested another attempt.', $grant->receipt()['reason'], 'The individual grant must retain its reason.' );
	$after = $exhausted['database']->tables;
	g3_same( true, $exhausted_service->execute( $grant_command )->replayed(), 'Repeating the grant must return its original receipt.' );
	g3_same( $after, $exhausted['database']->tables, 'Replayed grants must not stack allowances.' );
	g3_failure(
		static fn () => $exhausted_service->execute( c27_command( $exhausted, Control_Teacher_Submission_Command::REOPEN, 'second-grant-key', 'submitted', 1, 'teacher-reopen-again' ) ),
		Assignment_Failure::STATE_CONFLICT,
		'An already reopened submission must reject another grant even with a new key.'
	);
	g3_same( $after, $exhausted['database']->tables, 'A second grant must not change pending work.' );
}

$expired = c27_fixture( 2, '2026-08-22 10:00:00' );
g3_failure(
	static fn () => c27_service( $expired )->execute( c27_command( $expired, Control_Teacher_Submission_Command::REOPEN, 'expired-key', 'submitted', 1, 'teacher-reopen-expired' ) ),
	Assignment_Failure::STATE_CONFLICT,
	'C27 must enforce reopen_until_utc using server time.'
);

$empty          = c27_fixture( 2, null, false );
$empty_result   = c27_service( $empty )->execute( c27_command( $empty, Control_Teacher_Submission_Command::REOPEN, 'empty-key', 'submitted', 1, 'teacher-reopen-empty' ) );
$empty_response = json_decode( $empty['database']->tables['oj_ai_submission_revisions'][1]['response_json'], true, 64, JSON_THROW_ON_ERROR );
g3_same( array(), $empty_response['answers'], 'copy_previous_answers=false must create an empty editable answer map.' );
g3_same( hash( 'sha256', $empty['canonical']->encode( array() ) ), $empty_result->editable_revision()['attachment_set_hash'], 'An empty C27 fork must have the canonical empty attachment set.' );

g3_invalid(
	static fn () => new Control_Teacher_Submission_Command(
		19,
		$fixture['submission_id'],
		Control_Teacher_Submission_Command::REOPEN,
		'teacher-reopen-invalid',
		'submitted',
		1,
		$fixture['official_revision_id'],
		1,
		1,
		$fixture['schedule_hash'],
		$fixture['publication_hash'],
		'   ',
		'class',
		'class-21',
		'target-r1',
		hash( 'sha256', 'invalid-reason' ),
		'teacher-control-invalid',
		$fixture['canonical']
	),
	'C27-C28 must reject a missing teacher reason before persistence.'
);

$snapshot = array(
	'submission_id'    => $fixture['submission_id'],
	'assignment_id'    => c27_id( 1 ),
	'context_type'     => 'class',
	'context_id'       => 'class-21',
	'context_revision' => 'target-r1',
	'current_revision' => '1',
	'state'            => 'submitted',
	'assignment_state' => 'open',
	'target_state'     => 'active',
);
$policy   = new Submission_Object_Authorization( new C27_Student_Access(), new C27_Teacher_Query( $snapshot ) );
$context  = Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'target-r1', 'teacher' );
$allowed  = $policy->authorize( 19, 'teacher_submission.reopen', $context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $fixture['submission_id'], 'reopen', '1', 'submitted' ) );
g3_assert( $allowed->is_allowed(), 'C27 Object Policy must allow assigned Context staff for an active submitted target.' );
$wrong_context = Context_Authorization_Result::allowed( 'class', 'class-99', 'active', 'target-r1', 'teacher' );
g3_assert( $policy->authorize( 19, 'teacher_submission.reopen', $wrong_context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $fixture['submission_id'], 'reopen' ) )->is_denied(), 'C27 Object Policy must deny a different Context.' );

$controller   = ( new ReflectionClass( Teacher_Submission_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Teacher_Submission_Rest_Controller::class ) )->getMethod( 'control_input' );
$body         = array(
	'command_id' => 'teacher-reopen-rest-0001',
	'expected'   => array(
		'expected_state'                => 'submitted',
		'expected_lock_version'         => 1,
		'expected_official_revision_id' => $fixture['official_revision_id'],
		'expected_official_attempt_no'  => 1,
		'expected_schedule_version'     => 1,
		'expected_schedule_hash'        => $fixture['schedule_hash'],
		'expected_publication_hash'     => $fixture['publication_hash'],
	),
	'payload'    => array( 'reason' => 'Please revise the final response.' ),
);
$parsed       = $input_method->invoke( $controller, new C27_Request( $body ) );
g3_same( 1, $parsed['expected_official_attempt_no'], 'C27-C28 REST must preserve the expected official attempt.' );
$body['payload']['unknown'] = true;
g3_invalid( static fn () => $input_method->invoke( $controller, new C27_Request( $body ) ), 'C27-C28 REST must reject unknown payload keys.' );
g3_assert( str_contains( Teacher_Submission_Rest_Controller::ROUTE_SUBMISSION_REOPEN, 'commands/reopen' ), 'C27 REST route marker is missing.' );
g3_assert( str_contains( Teacher_Submission_Rest_Controller::ROUTE_SUBMISSION_LOCK, 'commands/lock' ), 'C28 REST route marker is missing.' );

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Teacher/Infrastructure/WordPress_Teacher_Submission_Control_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Teacher/Presentation/Teacher_Submission_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C27-C28 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C27-C28 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C27-C28 must not depend on OJ Platform internals.' );
}
foreach ( array( 'oj_ai_submission_receipts', 'oj_ai_submission_revisions', 'oj_ai_submissions', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE', 'submission.reopened', 'submission.locked' ) as $marker ) {
	g3_assert( str_contains( $sources[0], $marker ), 'C27-C28 persistence marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[2], 'WordPress_Teacher_Submission_Control_Repository' ), 'C27-C28 Composition Root wiring is missing.' );

echo "G3-IMP-06-S03 passed: C27 preserved-official editable fork, C28 lock, immutable receipts, policy, CAS, atomicity, Object Policy, and durable replay.\n";

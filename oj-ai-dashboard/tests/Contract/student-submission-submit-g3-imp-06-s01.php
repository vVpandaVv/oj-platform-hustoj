<?php
/**
 * G3-IMP-06-S01 C26 official submission and immutable receipt contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Object_Authorization;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Submission_Commit_Repository;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C26 id.
 *
 * @param int $number Number.
 * @return string
 */
function c26_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-06-S01 C26 official submission and immutable receipt contract.
 */
final class C26_Request {
	/**
	 * Initialize c26 request dependencies and state.
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
 * G3-IMP-06-S01 C26 official submission and immutable receipt contract.
 */
final class C26_Context_Port implements Learning_Context_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		g3_same( 7, $actor_user_id, 'C26 must derive the learner from the authenticated actor.' );
		return array(
			'class:class-21' => array(
				'type'            => 'class',
				'id'              => 'class-21',
				'label'           => 'Class 21',
				'lifecycle_state' => 'active',
				'revision'        => 'member-r3',
				'attributes'      => array(),
			),
		);
	}
}

/**
 * G3-IMP-06-S01 C26 official submission and immutable receipt contract.
 */
final class C26_Database {
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
	 * Transaction tables.
	 *
	 * @var array|null
	 */
	private ?array $transaction_tables = null;
	/**
	 * Fail insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_insert_table = null;
	/**
	 * Fail update.
	 *
	 * @var bool
	 */
	public bool $fail_update = false;
	/**
	 * Ignored update columns.
	 *
	 * @var array
	 */
	public array $ignored_update_columns = array();

	/**
	 * Initialize c26 database dependencies and state.
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
		return 'C26:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		list( $sql, $args ) = $this->prepared( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( ( $row['idempotency_key'] ?? null ) === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'a.resubmission_policy_schema' ) ) {
			$submission = $this->submission_by_public_id( (string) ( $args[0] ?? '' ), (int) ( $args[1] ?? 0 ) );
			if ( null === $submission || (int) ( $args[1] ?? 0 ) !== (int) ( $args[2] ?? 0 ) ) {
				return null;
			}
			$assignment  = $this->tables['oj_ai_assignments'][0];
			$target      = $this->tables['oj_ai_assignment_targets'][0];
			$publication = $this->tables['oj_ai_worksheet_publications'][0];
			return array(
				'submission_pk'                   => $submission['id'],
				'submission_id'                   => $submission['public_id'],
				'submission_lock_version'         => $submission['lock_version'],
				'submission_state'                => $submission['state'],
				'current_revision_public_id'      => $submission['current_revision_public_id'],
				'official_revision_public_id'     => $submission['official_revision_public_id'],
				'official_attempt_no'             => $submission['official_attempt_no'],
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
		if ( str_contains( $sql, 'SELECT receipt_no FROM oj_ai_submission_receipts' ) ) {
			$matches = array_values(
				array_filter(
					$this->tables['oj_ai_submission_receipts'],
					static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null )
				)
			);
			if ( array() === $matches ) {
				return null;
			}
			usort( $matches, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			return array( 'receipt_no' => $matches[0]['receipt_no'] );
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
		throw new RuntimeException( 'Unexpected C26 get_row SQL: ' . $sql );
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
		list( $sql, $args ) = $this->prepared( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_submission_attachments' ) ) {
			$submission_pk = (int) ( $args[0] ?? 0 );
			return array_values(
				array_filter(
					$this->tables['oj_ai_submission_attachments'],
					static fn ( array $row ): bool => $row['submission_id'] === $submission_pk && in_array( $row['state'], array( 'staged', 'scanning', 'ready', 'rejected', 'bound' ), true )
				)
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_private_files' ) ) {
			return array_values(
				array_filter(
					$this->tables['oj_ai_private_files'],
					static fn ( array $row ): bool => in_array( $row['public_id'], $args, true )
				)
			);
		}
		throw new RuntimeException( 'Unexpected C26 get_results SQL: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'C26 audit chain must use the literal table.' );
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
		if ( $this->fail_insert_table === $table ) {
			throw new RuntimeException( 'Injected insert failure.' );
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C26 insert table: ' . $table );
		}
		$id                       = $this->next_id++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		return 1;
	}

	/**
	 * Update c26 database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int {
		if ( $this->fail_update ) {
			return 0;
		}
		$data = array_diff_key( $data, array_flip( $this->ignored_update_columns ) );
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
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction_tables = $this->tables;
		}
		if ( 'ROLLBACK' === $sql && null !== $this->transaction_tables ) {
			$this->tables = $this->transaction_tables;
		}
		if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) {
			$this->transaction_tables = null;
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
		if ( ! str_starts_with( $value, 'C26:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared C26 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) ) {
			throw new RuntimeException( 'Invalid C26 prepared SQL.' );
		}
		return $decoded;
	}

	/**
	 * Submission by public id.
	 *
	 * @param string $id Id.
	 * @param int    $actor Actor.
	 * @return array<string,mixed>|null
	 */
	private function submission_by_public_id( string $id, int $actor ): ?array {
		foreach ( $this->tables['oj_ai_submissions'] as $row ) {
			if ( $row['public_id'] === $id && $row['learner_user_id'] === $actor ) {
				return $row;
			}
		}
		return null;
	}
}

/**
 * G3-IMP-06-S01 C26 official submission and immutable receipt contract.
 */
final class C26_Access_Port implements Student_Assignment_Access_Port_Interface {
	/**
	 * Initialize c26 access port dependencies and state.
	 *
	 * @param C26_Database $database Database.
	 */
	public function __construct( private C26_Database $database ) {
	}
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
		$submission = $this->database->tables['oj_ai_submissions'][0] ?? null;
		$assignment = $this->database->tables['oj_ai_assignments'][0] ?? null;
		$target     = $this->database->tables['oj_ai_assignment_targets'][0] ?? null;
		if ( ! is_array( $submission ) || ! is_array( $assignment ) || ! is_array( $target ) ) {
			return null;
		}
		if ( $submission['public_id'] !== $submission_id || $submission['learner_user_id'] !== $actor_user_id ) {
			return null;
		}
		return array(
			'submission_id'    => $submission_id,
			'assignment_id'    => $assignment['public_id'],
			'context_type'     => $assignment['context_type'],
			'context_id'       => $assignment['context_id'],
			'context_revision' => $assignment['context_revision'],
			'current_revision' => (string) $submission['lock_version'],
			'state'            => $submission['state'],
			'assignment_state' => $assignment['state'],
			'target_state'     => $target['state'],
		);
	}
}

/**
 * C26 fixture.
 *
 * @param string $state State.
 * @param int    $maximum_attempts Maximum attempts.
 * @return array<string,mixed>
 */
function c26_fixture( string $state = 'draft', int $maximum_attempts = 2 ): array {
	$canonical               = new Canonical_Json();
	$assignment_id           = c26_id( 1 );
	$publication_id          = c26_id( 2 );
	$submission_id           = c26_id( 3 );
	$revision_id             = c26_id( 4 );
	$previous_revision_id    = c26_id( 5 );
	$attachment_id           = c26_id( 6 );
	$file_id                 = c26_id( 7 );
	$text_id                 = c26_id( 8 );
	$check_id                = c26_id( 9 );
	$file_response_id        = c26_id( 10 );
	$publication_hash        = hash( 'sha256', 'c26-publication' );
	$publication_source_hash = hash( 'sha256', 'c26-publication-source' );
	$items                   = array(
		array(
			'response_id' => $text_id,
			'kind'        => 'short_text',
			'label'       => 'Answer',
			'required'    => true,
			'position'    => 0,
			'constraints' => array(
				'min_chars' => 3,
				'max_chars' => 100,
				'trim'      => true,
			),
		),
		array(
			'response_id' => $check_id,
			'kind'        => 'checkbox',
			'label'       => 'Confirm',
			'required'    => true,
			'position'    => 1,
			'constraints' => array(),
		),
		array(
			'response_id' => $file_response_id,
			'kind'        => 'file_upload',
			'label'       => 'File',
			'required'    => true,
			'position'    => 2,
			'constraints' => array(
				'mime_types' => array( 'application/pdf' ),
				'max_files'  => 1,
				'max_bytes'  => 1048576,
			),
		),
	);
	$payload                 = array( 'items' => $items );
	$profile                 = array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => '1.0.0',
		'schema_id'         => 'oj_ai.student_response_schema',
		'schema_version'    => '1.0.0',
		'source_hash'       => $publication_source_hash,
	);
	$profile_json            = $canonical->encode( $profile );
	$answers                 = array(
		$text_id          => 'complete answer',
		$check_id         => true,
		$file_response_id => array( $attachment_id ),
	);
	$response                = array(
		'answers'                 => $answers,
		'publication_hash'        => $publication_hash,
		'publication_id'          => $publication_id,
		'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
	);
	$response_json           = $canonical->encode( $response );
	$response_hash           = hash( 'sha256', $response_json );
	$attachment_set_hash     = hash( 'sha256', $canonical->encode( array( $attachment_id ) ) );
	$content_hash            = ( new Submission_Revision_Integrity( $canonical ) )->content_hash(
		$submission_id,
		1,
		null,
		'saved',
		Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
		$response_hash,
		$attachment_set_hash
	);
	$schedule                = array(
		'auto_submit_enabled' => true,
		'closes_at_utc'       => '2026-08-22 12:00:00',
		'due_at_utc'          => '2026-08-22 11:00:00',
		'opens_at_utc'        => null,
		'schedule_version'    => 1,
	);
	$policy                  = array(
		'max_attempts'          => $maximum_attempts,
		'reopen_until_utc'      => null,
		'copy_previous_answers' => true,
	);
	$policy_json             = $canonical->encode( $policy );
	$official_attempt        = 'reopened' === $state ? $maximum_attempts : 0;
	$receipts                = 'reopened' === $state ? array(
		array(
			'id'            => 90,
			'submission_id' => 30,
			'receipt_no'    => 2,
		),
	) : array();
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
				'state'                       => $state,
				'current_revision_public_id'  => $revision_id,
				'official_revision_public_id' => 'reopened' === $state ? $previous_revision_id : null,
				'official_review_public_id'   => null,
				'official_review_hash'        => null,
				'current_release_public_id'   => null,
				'current_release_hash'        => null,
				'official_attempt_no'         => $official_attempt,
				'submitted_at_utc'            => 'reopened' === $state ? '2026-08-21 10:00:00' : null,
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
				'attachment_set_hash'     => $attachment_set_hash,
			),
		),
		'oj_ai_submission_receipts'    => $receipts,
		'oj_ai_submission_attachments' => array(
			array(
				'id'                     => 50,
				'public_id'              => $attachment_id,
				'created_by_user_id'     => 7,
				'submission_id'          => 30,
				'submission_revision_id' => 40,
				'response_key'           => $file_response_id,
				'file_public_id'         => $file_id,
				'state'                  => 'bound',
				'original_name'          => 'answer.pdf',
				'mime_type'              => 'application/pdf',
				'byte_size'              => 512,
				'sha256'                 => hash( 'sha256', 'c26-file' ),
				'bound_at_utc'           => '2026-08-22 10:00:00',
			),
		),
		'oj_ai_private_files'          => array(
			array(
				'id'                 => 60,
				'public_id'          => $file_id,
				'created_by_user_id' => 7,
				'owner_type'         => 'submission',
				'owner_public_id'    => $submission_id,
				'purpose'            => 'submission_attachment',
				'original_name'      => 'answer.pdf',
				'mime_type'          => 'application/pdf',
				'byte_size'          => 512,
				'sha256'             => hash( 'sha256', 'c26-file' ),
				'state'              => 'ready',
				'scan_state'         => 'clean',
				'retention_class'    => 'submission_staging',
				'purged_at_utc'      => null,
			),
		),
		'oj_ai_audit_events'           => array(),
		'oj_ai_outbox_events'          => array(),
	);
	$database                = new C26_Database( $tables );
	return array(
		'canonical'        => $canonical,
		'database'         => $database,
		'assignment_id'    => $assignment_id,
		'publication_id'   => $publication_id,
		'publication_hash' => $publication_hash,
		'submission_id'    => $submission_id,
		'revision_id'      => $revision_id,
		'attachment_id'    => $attachment_id,
		'profile'          => $profile,
		'answers'          => $answers,
		'schedule_hash'    => $tables['oj_ai_assignments'][0]['schedule_hash'],
		'save_token'       => ( new Submission_Save_Token( $canonical ) )->create(
			$submission_id,
			$assignment_id,
			1,
			$revision_id,
			$publication_hash,
			$tables['oj_ai_assignments'][0]['schedule_hash']
		),
	);
}

/**
 * C26 service.
 *
 * @param array<string,mixed> $fixture Fixture.
 * @return Submit_Own_Submission
 */
function c26_service( array $fixture ): Submit_Own_Submission {
	$seed = 70;
	$ids  = new Public_Id_Generator(
		static function ( int $length ) use ( &$seed ): string {
			return str_repeat( chr( $seed++ ), $length );
		}
	);
	return new Submit_Own_Submission(
		new C26_Access_Port( $fixture['database'] ),
		new C26_Context_Port(),
		new WordPress_Student_Submission_Commit_Repository( $fixture['database'], $fixture['canonical'] ),
		$ids,
		static fn (): string => '2026-08-22 10:30:00'
	);
}

/**
 * C26 command.
 *
 * @param array<string,mixed> $fixture Fixture.
 * @param string              $fingerprint_seed Fingerprint seed.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $state State.
 * @return Submit_Own_Submission_Command
 */
function c26_command( array $fixture, string $fingerprint_seed, string $command_id = 'submit-command-0001', string $state = 'draft' ): Submit_Own_Submission_Command {
	return new Submit_Own_Submission_Command(
		7,
		$fixture['submission_id'],
		$command_id,
		$state,
		1,
		$fixture['revision_id'],
		1,
		$fixture['schedule_hash'],
		$fixture['publication_hash'],
		$fixture['save_token'],
		hash( 'sha256', $fingerprint_seed ),
		'submit-correlation-0001',
		$fixture['canonical']
	);
}

/**
 * C26 reviewed fixture.
 *
 * @param bool $same_revision Same revision.
 * @return array<string,mixed>
 */
function c26_reviewed_fixture( bool $same_revision = false ): array {
	$fixture                           = c26_fixture( 'reopened', 3 );
	$root                              =& $fixture['database']->tables['oj_ai_submissions'][0];
	$root['official_attempt_no']       = 1;
	$root['official_review_public_id'] = c26_id( 90 );
	$root['official_review_hash']      = hash( 'sha256', 'previous-review' );
	$root['current_release_public_id'] = c26_id( 91 );
	$root['current_release_hash']      = hash( 'sha256', 'previous-release' );
	if ( $same_revision ) {
		$root['official_revision_public_id'] = $fixture['revision_id'];
	}
	$fixture['database']->tables['oj_ai_submission_evaluation_reviews'] = array(
		array(
			'id'           => 90,
			'public_id'    => $root['official_review_public_id'],
			'content_hash' => $root['official_review_hash'],
		),
	);
	$fixture['database']->tables['oj_ai_submission_release_revisions']  = array(
		array(
			'id'           => 91,
			'public_id'    => $root['current_release_public_id'],
			'content_hash' => $root['current_release_hash'],
		),
	);
	return $fixture;
}

/**
 * C26 assert result history.
 *
 * @param array<string,mixed> $before Before.
 * @param array<string,mixed> $after After.
 * @param bool                $same_revision Same revision.
 * @return void
 */
function c26_assert_result_history( array $before, array $after, bool $same_revision ): void {
	foreach ( array( 'official_review_public_id', 'official_review_hash', 'current_release_public_id', 'current_release_hash' ) as $column ) {
		g3_same( $same_revision ? $before['oj_ai_submissions'][0][ $column ] : null, $after['oj_ai_submissions'][0][ $column ], 'Result pointer mismatch: ' . $column );
	}
	foreach ( array( 'oj_ai_submission_evaluation_reviews', 'oj_ai_submission_release_revisions', 'oj_ai_submission_revisions' ) as $table ) {
		g3_same( $before[ $table ], $after[ $table ], 'Immutable history changed: ' . $table );
	}
	g3_same( $before['oj_ai_submission_receipts'][0], $after['oj_ai_submission_receipts'][0], 'Prior receipt must survive resubmission.' );
}

foreach ( array( false, true ) as $same_revision ) {
	$resubmission     = c26_reviewed_fixture( $same_revision );
	$before           = $resubmission['database']->tables;
	$resubmit_service = c26_service( $resubmission );
	$resubmit_command = c26_command( $resubmission, 'reviewed-resubmit', 'reviewed-resubmit-command', 'reopened' );
	if ( $same_revision ) {
		g3_failure( static fn () => $resubmit_service->execute( $resubmit_command ), Assignment_Failure::STATE_CONFLICT, 'Unchanged answer must not be resubmitted.' );
		g3_same( $before, $resubmission['database']->tables, 'Rejected unchanged answer must preserve results.' );
		continue;
	}
	$resubmit_service->execute( $resubmit_command );
	c26_assert_result_history( $before, $resubmission['database']->tables, $same_revision );
	$after = $resubmission['database']->tables;
	g3_same( $resubmission['revision_id'], $after['oj_ai_submissions'][0]['official_revision_public_id'], 'Resubmission must promote the new answer.' );
	g3_same( true, $resubmit_service->execute( $resubmit_command )->replayed(), 'Resubmission retry must replay.' );
	g3_same( $after, $resubmission['database']->tables, 'Replay must not reset or append results again.' );
}

foreach ( array( 'audit', 'outbox', 'cas', 'official_review_public_id', 'official_review_hash', 'current_release_public_id', 'current_release_hash' ) as $fault ) {
	$failed                       = c26_reviewed_fixture();
	$failed_db                    = $failed['database'];
	$before                       = $failed_db->tables;
	$failed_db->fail_insert_table = match ( $fault ) {
		'audit' => 'oj_ai_audit_events', 'outbox' => 'oj_ai_outbox_events', default => null };
	$failed_db->fail_update            = 'cas' === $fault;
	$failed_db->ignored_update_columns = str_contains( $fault, '_' ) ? array( $fault ) : array();
	$expected_failure                  = match ( $fault ) {
		'audit', 'outbox' => Assignment_Failure::STORAGE_UNAVAILABLE,
		'cas' => Assignment_Failure::SUBMISSION_REVISION_CONFLICT,
		default => Assignment_Failure::SUBMIT_POSTCONDITION_MISMATCH,
	};
	g3_failure( static fn () => c26_service( $failed )->execute( c26_command( $failed, 'resubmit-failure', 'resubmit-failure-command', 'reopened' ) ), $expected_failure, 'Failed resubmission must be rejected: ' . $fault );
	g3_same( $before, $failed_db->tables, 'Failed resubmission must restore answers, results, receipts and events: ' . $fault );
	g3_same( array( 'START TRANSACTION', 'ROLLBACK' ), $failed_db->queries, 'Failed resubmission must roll back: ' . $fault );
}

$fixture   = c26_fixture();
$service   = c26_service( $fixture );
$command   = c26_command( $fixture, 'submit-key-1' );
$submitted = $service->execute( $command );
$database  = $fixture['database'];
g3_same( 'submitted', $submitted->submission()['state'], 'C26 must make the exact current revision official.' );
g3_same( $fixture['revision_id'], $submitted->submission()['official_revision_id'], 'C26 official pointer must equal the expected current revision.' );
g3_same( 1, $submitted->submission()['official_attempt_no'], 'The first C26 submit must create attempt one.' );
g3_same( 2, $submitted->submission()['lock_version'], 'C26 must advance the submission root lock once.' );
g3_same( 'on_time', $submitted->receipt()['timeliness'], 'C26 must derive timeliness from server time and the frozen due time.' );
g3_same( 1, count( $database->tables['oj_ai_submission_receipts'] ), 'C26 must append one immutable receipt.' );
g3_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C26 must append one audit event.' );
g3_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C26 must append one durable replay event.' );
g3_same( array( 'START TRANSACTION', 'COMMIT' ), $database->queries, 'Root, receipt, audit, and outbox must commit together.' );

$receipt_row     = $database->tables['oj_ai_submission_receipts'][0];
$receipt_content = array(
	'action'                      => $receipt_row['action'],
	'actor_type'                  => $receipt_row['actor_type'],
	'actor_user_id'               => $receipt_row['actor_user_id'],
	'assignment_schedule_hash'    => $receipt_row['assignment_schedule_hash'],
	'assignment_schedule_version' => $receipt_row['assignment_schedule_version'],
	'attachment_set_hash'         => $receipt_row['attachment_set_hash'],
	'context_revision'            => $receipt_row['context_revision'],
	'idempotency_key'             => $receipt_row['idempotency_key'],
	'attempt_no'                  => $receipt_row['attempt_no'],
	'publication_hash'            => $receipt_row['publication_hash'],
	'reason'                      => $receipt_row['reason'],
	'receipt_no'                  => $receipt_row['receipt_no'],
	'received_at_utc'             => $receipt_row['received_at_utc'],
	'resubmission_policy_hash'    => $receipt_row['resubmission_policy_hash'],
	'submission_id'               => $fixture['submission_id'],
	'submission_revision_hash'    => $receipt_row['submission_revision_hash'],
	'submission_revision_id'      => $fixture['revision_id'],
	'timeliness'                  => $receipt_row['timeliness'],
);
g3_same( hash( 'sha256', $fixture['canonical']->encode( $receipt_content ) ), $receipt_row['content_hash'], 'C26 receipt content hash must cover the full frozen receipt.' );

$replayed = $service->execute( $command );
g3_same( true, $replayed->replayed(), 'An exact C26 retry must return the same durable receipt after the root is official.' );
g3_same( $submitted->receipt(), $replayed->receipt(), 'An exact C26 retry must return the identical receipt projection.' );
g3_same( 1, count( $database->tables['oj_ai_submission_receipts'] ), 'C26 replay must not append another receipt.' );
g3_failure(
	static fn () => $service->execute( c26_command( $fixture, 'submit-key-1', 'submit-command-changed' ) ),
	Assignment_Failure::IDEMPOTENCY_CONFLICT,
	'C26 must reject a different request that reuses the same Idempotency-Key.'
);
g3_failure(
	static fn () => $service->execute( c26_command( $fixture, 'submit-key-new', 'submit-command-new' ) ),
	Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL,
	'A new C26 key must not submit an already official root again.'
);

$validator       = new Submission_Response_Validator( $fixture['canonical'] );
$partial_answers = $fixture['answers'];
unset( $partial_answers[ c26_id( 8 ) ] );
$validator->validate( $fixture['profile'], $partial_answers );
g3_failure(
	static fn () => $validator->validate_complete( $fixture['profile'], $partial_answers ),
	Assignment_Failure::SUBMISSION_VALIDATION_FAILED,
	'C23 may preserve a partial draft, but C26 must reject a missing required answer.'
);

$pending = c26_fixture();
$pending['database']->tables['oj_ai_private_files'][0]['scan_state'] = 'pending';
g3_failure(
	static fn () => c26_service( $pending )->execute( c26_command( $pending, 'submit-pending' ) ),
	Assignment_Failure::ATTACHMENT_NOT_READY,
	'C26 must reject a bound attachment whose private file is not clean.'
);
g3_same( 0, count( $pending['database']->tables['oj_ai_submission_receipts'] ), 'Attachment failure must not append a receipt.' );
g3_same( array( 'START TRANSACTION', 'ROLLBACK' ), $pending['database']->queries, 'Attachment failure must roll back the C26 transaction.' );

$exhausted        = c26_fixture( 'reopened', 2 );
$exhausted_before = $exhausted['database']->tables;
$extra_service    = c26_service( $exhausted );
$extra_command    = c26_command( $exhausted, 'submit-exhausted', 'submit-command-reopen', 'reopened' );
$extra_result     = $extra_service->execute( $extra_command );
g3_same( 3, $extra_result->submission()['official_attempt_no'], 'A teacher-opened submission must permit attempt three after a two-attempt limit.' );
g3_same( $exhausted_before['oj_ai_assignments'], $exhausted['database']->tables['oj_ai_assignments'], 'Using an individual allowance must not increase the assignment limit.' );
g3_same( $exhausted_before['oj_ai_submission_receipts'][0], $exhausted['database']->tables['oj_ai_submission_receipts'][0], 'Earlier receipts must be preserved.' );
$extra_after = $exhausted['database']->tables;
g3_same( true, $extra_service->execute( $extra_command )->replayed(), 'An exact extra-submit replay must not consume another attempt.' );
g3_same( $extra_after, $exhausted['database']->tables, 'An extra-submit replay must preserve all records.' );
g3_failure(
	static fn () => $extra_service->execute( c26_command( $exhausted, 'submit-fourth', 'submit-command-fourth', 'reopened' ) ),
	Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL,
	'The student must not reuse an already consumed teacher allowance with another key.'
);
g3_same( $extra_after, $exhausted['database']->tables, 'An unapproved fourth attempt must write nothing.' );

$policy        = new Submission_Object_Authorization( new C26_Access_Port( $database ) );
$context       = Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'member-r3', 'learner' );
$policy_result = $policy->authorize( 7, 'student_submission.submit', $context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $fixture['submission_id'], 'submit' ) );
g3_assert( $policy_result->is_allowed(), 'C26 Object Policy must allow an owned official root through so exact replay can resolve before state rejection.' );

$controller   = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->getMethod( 'submit_input' );
$body         = array(
	'command_id' => 'submit-rest-0001',
	'expected'   => array(
		'expected_state'               => 'draft',
		'expected_lock_version'        => 1,
		'expected_current_revision_id' => $fixture['revision_id'],
		'expected_schedule_version'    => 1,
		'expected_schedule_hash'       => $fixture['schedule_hash'],
		'expected_publication_hash'    => $fixture['publication_hash'],
		'expected_save_token'          => $fixture['save_token'],
	),
	'payload'    => array(),
);
$parsed       = $input_method->invoke( $controller, new C26_Request( $body ) );
g3_same( $fixture['revision_id'], $parsed['expected_current_revision_id'], 'C26 REST must preserve the exact official revision candidate.' );
$body['payload']['unknown'] = true;
g3_invalid( static fn () => $input_method->invoke( $controller, new C26_Request( $body ) ), 'C26 REST must reject unknown payload keys.' );
g3_invalid(
	static fn () => new Submit_Own_Submission_Command(
		7,
		$fixture['submission_id'],
		'submit-command-invalid',
		'conflict',
		1,
		$fixture['revision_id'],
		1,
		$fixture['schedule_hash'],
		$fixture['publication_hash'],
		$fixture['save_token'],
		hash( 'sha256', 'invalid-state' ),
		'submit-correlation-invalid',
		$fixture['canonical']
	),
	'C26 must reject conflict state before persistence.'
);

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Commit_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C26 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C26 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C26 must not depend on OJ Platform internals.' );
}
foreach ( array( 'oj_ai_submission_receipts', 'oj_ai_submission_revisions', 'oj_ai_private_files', 'oj_ai_submissions', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE' ) as $marker ) {
	g3_assert( str_contains( $sources[0], $marker ), 'C26 persistence marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[1], 'commands/submit' ), 'C26 REST route marker is missing.' );
g3_assert( str_contains( $sources[2], 'WordPress_Student_Submission_Commit_Repository' ), 'C26 Composition Root wiring is missing.' );

echo "G3-IMP-06-S01 passed: C26 exact official revision, immutable receipt, completeness, clean attachments, policy, atomicity, and durable replay.\n";

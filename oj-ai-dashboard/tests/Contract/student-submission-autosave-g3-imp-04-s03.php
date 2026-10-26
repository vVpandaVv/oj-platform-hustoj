<?php
/**
 * G3-IMP-04-S03 C23 immutable autosave contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Command;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Object_Authorization;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C23 id.
 *
 * @param int $number Number.
 * @return string
 */
function c23_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-04-S03 C23 immutable autosave contract.
 */
final class C23_Context_Port implements Learning_Context_Query_Port_Interface {
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
		g3_same( 7, $actor_user_id, 'C23 must derive the learner from the authenticated actor.' );
		return array(
			'class:class-21' => array(
				'type'            => 'class',
				'id'              => 'class-21',
				'label'           => '2학년 1반',
				'lifecycle_state' => 'active',
				'revision'        => 'member-r3',
				'attributes'      => array(),
			),
		);
	}
}

/**
 * G3-IMP-04-S03 C23 immutable autosave contract.
 */
final class C23_Request {
	/**
	 * Initialize c23 request dependencies and state.
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
 * G3-IMP-04-S03 C23 immutable autosave contract.
 */
final class C23_Database {
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
	 * Transaction tables.
	 *
	 * @var array|null
	 */
	private ?array $transaction_tables = null;
	/**
	 * Fail update table.
	 *
	 * @var string|null
	 */
	public ?string $fail_update_table = null;

	/**
	 * Initialize c23 database dependencies and state.
	 *
	 * @param array<string,mixed>            $assignment Assignment.
	 * @param array<string,mixed>            $target Target.
	 * @param array<string,mixed>            $publication Publication.
	 * @param array<string,mixed>            $submission Submission.
	 * @param array<int,array<string,mixed>> $attachments Attachments.
	 */
	public function __construct( array $assignment, array $target, array $publication, array $submission, array $attachments ) {
		$this->tables = array(
			'oj_ai_assignments'            => array( $assignment ),
			'oj_ai_assignment_targets'     => array( $target ),
			'oj_ai_worksheet_publications' => array( $publication ),
			'oj_ai_submissions'            => array( $submission ),
			'oj_ai_submission_revisions'   => array(),
			'oj_ai_submission_attachments' => $attachments,
			'oj_ai_audit_events'           => array(),
			'oj_ai_outbox_events'          => array(),
			'oj_ai_private_files'          => array(),
		);
		foreach ( $attachments as $attachment ) {
			$this->tables['oj_ai_private_files'][] = array(
				'id'                 => $attachment['id'] + 1000,
				'public_id'          => $attachment['file_public_id'],
				'lock_version'       => 0,
				'created_by_user_id' => 7,
				'owner_type'         => 'submission',
				'owner_public_id'    => $submission['public_id'],
				'purpose'            => 'submission_attachment',
				'original_name'      => $attachment['original_name'],
				'mime_type'          => $attachment['mime_type'],
				'byte_size'          => $attachment['byte_size'],
				'sha256'             => $attachment['sha256'],
				'state'              => 'ready',
				'scan_state'         => 'clean',
				'retention_class'    => 'submission_staging',
				'expires_at_utc'     => '2026-08-23 10:00:00',
				'purged_at_utc'      => null,
			);
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
		return 'C23:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		if ( str_contains( $sql, 'FROM oj_ai_private_files' ) ) {
			foreach ( $this->tables['oj_ai_private_files'] as $row ) {
				if ( $row['public_id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( ( $row['idempotency_key'] ?? null ) === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'p.response_schema_schema' ) ) {
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
		if ( str_contains( $sql, 'SELECT s.public_id AS submission_id' ) ) {
			$submission = $this->submission_by_public_id( (string) ( $args[0] ?? '' ), (int) ( $args[1] ?? 0 ) );
			if ( null === $submission || (int) ( $args[1] ?? 0 ) !== (int) ( $args[2] ?? 0 ) ) {
				return null;
			}
			$assignment = $this->tables['oj_ai_assignments'][0];
			$target     = $this->tables['oj_ai_assignment_targets'][0];
			return array(
				'submission_id'           => $submission['public_id'],
				'submission_lock_version' => $submission['lock_version'],
				'submission_state'        => $submission['state'],
				'assignment_id'           => $assignment['public_id'],
				'context_type'            => $assignment['context_type'],
				'context_id'              => $assignment['context_id'],
				'context_revision'        => $assignment['context_revision'],
				'assignment_state'        => $assignment['state'],
				'target_state'            => $target['state'],
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions WHERE submission_id = %d AND public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['submission_id'] === ( $args[0] ?? null ) && $row['public_id'] === ( $args[1] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submissions WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_submissions'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions WHERE id = %d' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['id'] === ( $args[0] ?? null ) ) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C23 get_row SQL: ' . $sql );
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
		if ( ! str_contains( $sql, 'FROM oj_ai_submission_attachments' ) ) {
			throw new RuntimeException( 'Unexpected C23 get_results SQL: ' . $sql );
		}
		$submission_pk = (int) array_shift( $args );
		return array_values(
			array_filter(
				$this->tables['oj_ai_submission_attachments'],
				static fn ( array $row ): bool => $row['submission_id'] === $submission_pk && in_array( $row['public_id'], $args, true )
			)
		);
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		g3_assert( str_contains( $sql, 'oj_ai_audit_events' ), 'C23 audit chain must use the literal table.' );
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
			throw new RuntimeException( 'Unexpected C23 insert table: ' . $table );
		}
		$id                       = $this->next_id++;
		$this->insert_id          = $id;
		$this->tables[ $table ][] = array_merge( array( 'id' => $id ), $data );
		return 1;
	}

	/**
	 * Update c23 database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function update( string $table, array $data, array $where ): int {
		if ( $table === $this->fail_update_table ) {
			return 0;
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C23 update table: ' . $table );
		}
		foreach ( $this->tables[ $table ] as $index => $row ) {
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
		if ( ! str_starts_with( $value, 'C23:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared C23 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 4 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) ) {
			throw new RuntimeException( 'Invalid C23 prepared SQL.' );
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
 * C23 profile.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
 * @param string              $source_hash Source hash.
 * @return array{json:string,hash:string}
 */
function c23_profile( Canonical_Json $canonical, array $payload, string $source_hash ): array {
	$profile = array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => '1.0.0',
		'schema_id'         => 'oj_ai.student_response_schema',
		'schema_version'    => '1.0.0',
		'source_hash'       => $source_hash,
	);
	$json    = $canonical->encode( $profile );
	return array(
		'json' => $json,
		'hash' => hash( 'sha256', $json ),
	);
}

$canonical               = new Canonical_Json();
$assignment_id           = c23_id( 1 );
$publication_id          = c23_id( 2 );
$submission_id           = c23_id( 3 );
$response_ids            = array_map( 'c23_id', range( 10, 19 ) );
$option_ids              = array_map( 'c23_id', range( 20, 23 ) );
$row_id                  = c23_id( 24 );
$column_text             = c23_id( 25 );
$column_check            = c23_id( 26 );
$attachment_ids          = array_map( 'c23_id', range( 31, 35 ) );
$publication_hash        = hash( 'sha256', 'c23-publication' );
$publication_source_hash = hash( 'sha256', 'c23-publication-source' );
$items                   = array(
	array(
		'response_id' => $response_ids[0],
		'kind'        => 'short_text',
		'label'       => 'Short',
		'required'    => true,
		'position'    => 0,
		'constraints' => array(
			'min_chars' => 1,
			'max_chars' => 100,
			'trim'      => true,
		),
	),
	array(
		'response_id' => $response_ids[1],
		'kind'        => 'long_text',
		'label'       => 'Long',
		'required'    => false,
		'position'    => 1,
		'constraints' => array( 'max_chars' => 2000 ),
	),
	array(
		'response_id' => $response_ids[2],
		'kind'        => 'single_choice',
		'label'       => 'One',
		'required'    => false,
		'position'    => 2,
		'constraints' => array(
			'options' => array(
				array(
					'option_id' => $option_ids[0],
					'value'     => 'a',
					'label'     => 'A',
				),
				array(
					'option_id' => $option_ids[1],
					'value'     => 'b',
					'label'     => 'B',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[3],
		'kind'        => 'multiple_choice',
		'label'       => 'Many',
		'required'    => false,
		'position'    => 3,
		'constraints' => array(
			'options'      => array(
				array(
					'option_id' => $option_ids[2],
					'value'     => 'c',
					'label'     => 'C',
				),
				array(
					'option_id' => $option_ids[3],
					'value'     => 'd',
					'label'     => 'D',
				),
			),
			'min_selected' => 0,
			'max_selected' => 2,
		),
	),
	array(
		'response_id' => $response_ids[4],
		'kind'        => 'checkbox',
		'label'       => 'Check',
		'required'    => false,
		'position'    => 4,
		'constraints' => array(),
	),
	array(
		'response_id' => $response_ids[5],
		'kind'        => 'number',
		'label'       => 'Number',
		'required'    => false,
		'position'    => 5,
		'constraints' => array(
			'min'            => '-1',
			'max'            => '10.5',
			'step'           => '0.5',
			'decimal_places' => 2,
		),
	),
	array(
		'response_id' => $response_ids[6],
		'kind'        => 'table',
		'label'       => 'Table',
		'required'    => false,
		'position'    => 6,
		'constraints' => array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $row_id,
					'label'  => 'Row',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $column_text,
					'label'     => 'Text',
					'cell_type' => 'short_text',
				),
				array(
					'column_id' => $column_check,
					'label'     => 'Check',
					'cell_type' => 'checkbox',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[7],
		'kind'        => 'image_upload',
		'label'       => 'Image',
		'required'    => false,
		'position'    => 7,
		'constraints' => array(
			'mime_types' => array( 'image/png' ),
			'max_files'  => 2,
			'max_bytes'  => 1048576,
		),
	),
	array(
		'response_id' => $response_ids[8],
		'kind'        => 'file_upload',
		'label'       => 'File',
		'required'    => false,
		'position'    => 8,
		'constraints' => array(
			'mime_types' => array( 'application/pdf' ),
			'max_files'  => 2,
			'max_bytes'  => 2097152,
		),
	),
	array(
		'response_id' => $response_ids[9],
		'kind'        => 'drawing',
		'label'       => 'Drawing',
		'required'    => false,
		'position'    => 9,
		'constraints' => array(
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1.0.0',
			'preview_mime_type'     => 'image/png',
			'aspect_ratio'          => '1.333',
			'max_strokes'           => 2000,
		),
	),
);
$profile                 = c23_profile( $canonical, array( 'items' => $items ), $publication_source_hash );
$schedule                = array(
	'auto_submit_enabled' => true,
	'closes_at_utc'       => '2026-08-22 12:00:00',
	'due_at_utc'          => '2026-08-22 11:00:00',
	'opens_at_utc'        => null,
	'schedule_version'    => 1,
);
$assignment              = array(
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
);
$target                  = array(
	'id'              => 20,
	'assignment_id'   => 10,
	'learner_user_id' => 7,
	'state'           => 'active',
);
$publication             = array(
	'public_id'               => $publication_id,
	'content_hash'            => $publication_hash,
	'publication_hash'        => $publication_hash,
	'source_hash'             => $publication_source_hash,
	'response_schema_schema'  => 'oj_ai.student_response_schema',
	'response_schema_version' => 1,
	'response_schema_json'    => $profile['json'],
	'response_schema_hash'    => $profile['hash'],
);
$submission              = array(
	'id'                          => 30,
	'public_id'                   => $submission_id,
	'lock_version'                => 0,
	'learner_user_id'             => 7,
	'assignment_id'               => 10,
	'state'                       => 'draft',
	'current_revision_public_id'  => null,
	'official_revision_public_id' => null,
);
$attachments             = array(
	array(
		'id'                     => 41,
		'public_id'              => $attachment_ids[0],
		'lock_version'           => 0,
		'submission_id'          => 30,
		'submission_revision_id' => null,
		'response_key'           => $response_ids[7],
		'state'                  => 'ready',
		'mime_type'              => 'image/png',
		'byte_size'              => 100,
	),
	array(
		'id'                     => 42,
		'public_id'              => $attachment_ids[1],
		'lock_version'           => 0,
		'submission_id'          => 30,
		'submission_revision_id' => null,
		'response_key'           => $response_ids[8],
		'state'                  => 'ready',
		'mime_type'              => 'application/pdf',
		'byte_size'              => 200,
	),
	array(
		'id'                     => 43,
		'public_id'              => $attachment_ids[2],
		'lock_version'           => 0,
		'submission_id'          => 30,
		'submission_revision_id' => null,
		'response_key'           => $response_ids[9],
		'state'                  => 'ready',
		'mime_type'              => 'application/json',
		'byte_size'              => 300,
	),
	array(
		'id'                     => 44,
		'public_id'              => $attachment_ids[3],
		'lock_version'           => 0,
		'submission_id'          => 30,
		'submission_revision_id' => null,
		'response_key'           => $response_ids[9],
		'state'                  => 'ready',
		'mime_type'              => 'image/png',
		'byte_size'              => 400,
	),
	array(
		'id'                     => 45,
		'public_id'              => $attachment_ids[4],
		'lock_version'           => 0,
		'submission_id'          => 30,
		'submission_revision_id' => null,
		'response_key'           => $response_ids[7],
		'state'                  => 'scanning',
		'mime_type'              => 'image/png',
		'byte_size'              => 500,
	),
);
foreach ( $attachments as $index => $attachment ) {
	$attachments[ $index ] += array(
		'file_public_id'     => c23_id( 500 + $index ),
		'created_by_user_id' => 7,
		'original_name'      => 'answer-' . $index,
		'sha256'             => hash( 'sha256', 'answer-' . $index ),
	);
}
$database           = new C23_Database( $assignment, $target, $publication, $submission, $attachments );
$repository         = new WordPress_Student_Assignment_Repository( $database, $canonical );
$contexts           = new C23_Context_Port();
$id_seed            = 100;
$ids                = new Public_Id_Generator(
	static function ( int $length ) use ( &$id_seed ): string {
		++$id_seed;
		return str_repeat( chr( $id_seed ), $length );
	}
);
$autosave           = new Autosave_Own_Submission( $repository, $contexts, $ids, static fn (): string => '2026-08-22 10:10:00' );
$answers            = array(
	$response_ids[0] => '  hello  ',
	$response_ids[1] => "line 1\r\nline 2",
	$response_ids[2] => $option_ids[0],
	$response_ids[3] => array( $option_ids[3], $option_ids[2] ),
	$response_ids[4] => true,
	$response_ids[5] => '2.00',
	$response_ids[6] => array(
		$row_id => array(
			$column_text  => 'cell',
			$column_check => false,
		),
	),
	$response_ids[7] => array( $attachment_ids[0] ),
	$response_ids[8] => array( $attachment_ids[1] ),
	$response_ids[9] => array(
		'document_attachment_id' => $attachment_ids[2],
		'preview_attachment_id'  => $attachment_ids[3],
	),
);
$initial_save_token = ( new Submission_Save_Token( $canonical ) )->create( $submission_id, $assignment_id, 0, null, $publication_hash, $assignment['schedule_hash'] );
$command            = new Autosave_Own_Submission_Command(
	7,
	$submission_id,
	'autosave-command-0001',
	'draft',
	0,
	null,
	1,
	$assignment['schedule_hash'],
	$publication_hash,
	$initial_save_token,
	$answers,
	array_slice( $attachment_ids, 0, 4 ),
	hash( 'sha256', 'autosave-key-1' ),
	'autosave-correlation-0001',
	$canonical
);
$saved              = $autosave->execute( $command );
g3_same( 1, count( $database->tables['oj_ai_submission_revisions'] ), 'C23 must append one immutable revision.' );
g3_same( 1, $saved->revision()['revision_no'], 'The first C23 revision number must be one.' );
g3_same( 1, $saved->submission()['lock_version'], 'C23 must advance the root lock once.' );
g3_same( $saved->revision()['id'], $saved->submission()['current_revision_id'], 'C23 must atomically advance the current pointer.' );
g3_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C23 must append one audit event.' );
g3_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C23 must append one durable receipt event.' );
g3_same( array( 'START TRANSACTION', 'COMMIT' ), $database->queries, 'Revision, attachments, root, audit, and outbox must commit together.' );
$stored_response = json_decode( $database->tables['oj_ai_submission_revisions'][0]['response_json'], true, 64, JSON_THROW_ON_ERROR );
g3_same( 'hello', $stored_response['answers'][ $response_ids[0] ], 'short_text trim normalization failed.' );
g3_same( "line 1\nline 2", $stored_response['answers'][ $response_ids[1] ], 'long_text LF normalization failed.' );
g3_same( array( $option_ids[2], $option_ids[3] ), $stored_response['answers'][ $response_ids[3] ], 'multiple_choice schema-order normalization failed.' );
g3_same( '2', $stored_response['answers'][ $response_ids[5] ], 'decimal string normalization failed.' );
$first_revision_pk = $database->tables['oj_ai_submission_revisions'][0]['id'];
foreach ( array_slice( $database->tables['oj_ai_submission_attachments'], 0, 4 ) as $row ) {
	g3_same( 'bound', $row['state'], 'A ready staged attachment must become bound.' );
	g3_same( $first_revision_pk, $row['submission_revision_id'], 'A newly bound attachment must record its origin revision.' );
}
foreach ( array_slice( $database->tables['oj_ai_private_files'], 0, 4 ) as $file ) {
	g3_same( null, $file['expires_at_utc'], 'Bound files must no longer expire as temporary uploads.' );
	g3_same( 1, $file['lock_version'], 'Retention update must advance the file CAS version.' );
}
g3_same( '2026-08-23 10:00:00', $database->tables['oj_ai_private_files'][4]['expires_at_utc'], 'Unbound upload expiry must be preserved.' );

$replay = $autosave->execute( $command );
g3_same( true, $replay->replayed(), 'An exact C23 retry must replay its durable receipt.' );
g3_same( 1, count( $database->tables['oj_ai_submission_revisions'] ), 'C23 replay must not append another revision.' );

$second_answers                     = $answers;
$second_answers[ $response_ids[1] ] = 'second revision';
$second                             = $autosave->execute(
	new Autosave_Own_Submission_Command(
		7,
		$submission_id,
		'autosave-command-0002',
		'draft',
		1,
		$saved->revision()['id'],
		1,
		$assignment['schedule_hash'],
		$publication_hash,
		$saved->submission()['save_token'],
		$second_answers,
		array(),
		hash( 'sha256', 'autosave-key-2' ),
		'autosave-correlation-0002',
		$canonical
	)
);
g3_same( 2, $second->revision()['revision_no'], 'A later autosave must append the next revision.' );
g3_same( 1, $second->revision()['base_revision_no'], 'A later autosave must retain its exact base revision number.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'Earlier immutable revisions must remain present.' );
foreach ( array_slice( $database->tables['oj_ai_submission_attachments'], 0, 4 ) as $row ) {
	g3_same( $first_revision_pk, $row['submission_revision_id'], 'A later revision must not move an attachment origin link.' );
}

$stale = new Autosave_Own_Submission_Command(
	7,
	$submission_id,
	'autosave-command-stale',
	'draft',
	1,
	$saved->revision()['id'],
	1,
	$assignment['schedule_hash'],
	$publication_hash,
	$saved->submission()['save_token'],
	$answers,
	array(),
	hash( 'sha256', 'autosave-key-stale' ),
	'autosave-correlation-stale',
	$canonical
);
g3_failure( static fn () => $autosave->execute( $stale ), Assignment_Failure::SUBMISSION_REVISION_CONFLICT, 'C23 must reject a stale root without overwriting either revision.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'A stale C23 request must not append a revision.' );

$not_ready_answers                     = $second_answers;
$not_ready_answers[ $response_ids[7] ] = array( $attachment_ids[4] );
$not_ready                             = new Autosave_Own_Submission_Command(
	7,
	$submission_id,
	'autosave-command-scan',
	'draft',
	2,
	$second->revision()['id'],
	1,
	$assignment['schedule_hash'],
	$publication_hash,
	$second->submission()['save_token'],
	$not_ready_answers,
	array( $attachment_ids[4] ),
	hash( 'sha256', 'autosave-key-scan' ),
	'autosave-correlation-scan',
	$canonical
);
g3_failure( static fn () => $autosave->execute( $not_ready ), Assignment_Failure::ATTACHMENT_NOT_READY, 'C23 must not bind a scanning attachment.' );
g3_same( 2, count( $database->tables['oj_ai_submission_revisions'] ), 'Attachment failure must roll back the candidate revision.' );

$autosave_policy                                   = new Submission_Object_Authorization( $repository );
$autosave_context                                  = Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'member-r3', 'learner' );
$autosave_scope                                    = new Object_Scope( Object_Scope::TYPE_SUBMISSION, $submission_id, 'autosave' );
$database->tables['oj_ai_submissions'][0]['state'] = 'submitted';
g3_failure( static fn () => $autosave->execute( $not_ready ), Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL, 'C23 must expose an official submission as a 409 domain conflict.' );
$official_policy = $autosave_policy->authorize( 7, 'student_submission.autosave', $autosave_context, $autosave_scope );
g3_assert( $official_policy->is_conflict(), 'Autosave Object Policy must report an official submission as a conflict.' );
g3_same( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL, $official_policy->code(), 'Autosave Object Policy must preserve the stable official-state code.' );
$database->tables['oj_ai_submissions'][0]['state'] = 'draft';

$database->tables['oj_ai_assignments'][0]['state'] = 'closed';
g3_failure( static fn () => $autosave->execute( $not_ready ), Assignment_Failure::STATE_CONFLICT, 'C23 must expose a closed assignment as a 409 domain conflict.' );
$closed_policy = $autosave_policy->authorize( 7, 'student_submission.autosave', $autosave_context, $autosave_scope );
g3_assert( $closed_policy->is_conflict(), 'Autosave Object Policy must report a closed assignment as a conflict.' );
g3_same( Assignment_Failure::STATE_CONFLICT, $closed_policy->code(), 'Autosave Object Policy must preserve the stable assignment-state code.' );
$database->tables['oj_ai_assignments'][0]['state'] = 'open';

$controller   = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->getMethod( 'autosave_input' );
$body         = array(
	'command_id' => 'autosave-rest-0001',
	'expected'   => array(
		'expected_state'               => 'draft',
		'expected_lock_version'        => 2,
		'expected_current_revision_id' => $second->revision()['id'],
		'expected_schedule_version'    => 1,
		'expected_schedule_hash'       => $assignment['schedule_hash'],
		'expected_publication_hash'    => $publication_hash,
		'expected_save_token'          => $second->submission()['save_token'],
	),
	'payload'    => array(
		'answers'               => $second_answers,
		'staged_attachment_ids' => array(),
	),
);
$parsed       = $input_method->invoke( $controller, new C23_Request( $body ) );
g3_same( $second->revision()['id'], $parsed['expected_current_revision_id'], 'C23 REST must preserve the exact expected revision.' );
$body['payload']['unknown'] = true;
g3_invalid( static fn () => $input_method->invoke( $controller, new C23_Request( $body ) ), 'C23 REST must reject unknown payload keys.' );

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Autosave.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C23 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C23 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C23 must not depend on OJ Platform internals.' );
}
foreach ( array( 'oj_ai_submission_revisions', 'oj_ai_submission_attachments', 'oj_ai_submissions', 'oj_ai_audit_events', 'oj_ai_outbox_events', 'START TRANSACTION', 'FOR UPDATE' ) as $marker ) {
	g3_assert( str_contains( $sources[0], $marker ), 'C23 persistence marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[1], 'commands/autosave' ), 'C23 REST route marker is missing.' );

foreach ( array(
	array( 'created_by_user_id', 8 ),
	array( 'owner_type', 'worksheet' ),
	array( 'owner_public_id', c23_id( 999 ) ),
	array( 'purpose', 'worksheet_asset' ),
	array( 'retention_class', 'report_export' ),
	array( 'state', 'expired' ),
	array( 'scan_state', 'pending' ),
	array( 'scan_state', 'infected' ),
	array( 'purged_at_utc', '2026-08-22 09:00:00' ),
	array( 'expires_at_utc', '2026-08-22 10:10:00' ),
	array( 'expires_at_utc', '2026-08-22 10:09:59' ),
	array( 'byte_size', 999 ),
	array( 'sha256', hash( 'sha256', 'changed' ) ),
	array( 'mime_type', 'text/html' ),
	array( 'original_name', 'other-file' ),
) as [ $column, $value ] ) {
	$failing_db = new C23_Database( $assignment, $target, $publication, $submission, $attachments );
	$failing_db->tables['oj_ai_private_files'][1][ $column ] = $value;
	$before       = $failing_db->tables;
	$failing_save = new Autosave_Own_Submission( new WordPress_Student_Assignment_Repository( $failing_db, $canonical ), $contexts, $ids, static fn (): string => '2026-08-22 10:10:00' );
	g3_failure( static fn () => $failing_save->execute( $command ), Assignment_Failure::ATTACHMENT_NOT_READY, 'Unsafe file cannot be retained: ' . $column );
	g3_same( $before, $failing_db->tables, 'Failure must roll back prior bindings, file expiry, revision and receipts: ' . $column );
}
foreach ( array( 'oj_ai_private_files', 'oj_ai_submission_attachments', 'oj_ai_submissions' ) as $table ) {
	$failing_db                    = new C23_Database( $assignment, $target, $publication, $submission, $attachments );
	$failing_db->fail_update_table = $table;
	$before                        = $failing_db->tables;
	$failing_save                  = new Autosave_Own_Submission( new WordPress_Student_Assignment_Repository( $failing_db, $canonical ), $contexts, $ids, static fn (): string => '2026-08-22 10:10:00' );
	try {
		$failing_save->execute( $command );
		throw new RuntimeException( 'CAS failure was accepted.' ); } catch ( Assignment_Failure ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		}
		g3_same( $before, $failing_db->tables, 'File, attachment and submission CAS must roll back together: ' . $table );
}

echo "G3-IMP-04-S03 passed: C23 10-kind validation, immutable revisions, attachment bind, root CAS, audit/outbox atomicity, replay, and stale/not-ready rejection.\n";

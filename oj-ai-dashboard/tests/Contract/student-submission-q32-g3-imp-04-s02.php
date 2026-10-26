<?php
/**
 * G3-IMP-04-S02 Q32 own submission projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Read_Own_Submission;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Object_Authorization;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G3-IMP-04-S02 Q32 own submission projection contract.
 */
final class G3_Q32_Context_Port implements Learning_Context_Query_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g3 q32 context port dependencies and state.
	 *
	 * @param array<string,array<string,mixed>> $contexts Contexts.
	 */
	public function __construct( public array $contexts ) {
	}
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		g3_same( 7, $actor_user_id, 'Q32 must derive the learner from the authenticated actor.' );
		return $this->contexts;
	}
}

/**
 * G3-IMP-04-S02 Q32 own submission projection contract.
 */
final class G3_Q32_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();

	/**
	 * Initialize g3 q32 database dependencies and state.
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
		return 'G3Q32:' . base64_encode( serialize( array( $sql, $args ) ) );
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
		$this->sql[]    = $sql;
		if ( str_contains( $sql, 'FROM oj_ai_submissions s' ) ) {
			g3_assert( str_contains( $sql, 's.learner_user_id = %d' ) && str_contains( $sql, 't.learner_user_id = %d' ), 'Q32 root SQL must bind the actor to submission and target.' );
			$submission = $this->submission( (string) ( $args[0] ?? '' ), (int) ( $args[1] ?? 0 ) );
			if ( null === $submission || (int) ( $args[2] ?? 0 ) !== (int) $submission['learner_user_id'] ) {
				return null;
			}
			$assignment = $this->by_id( 'oj_ai_assignments', (int) $submission['assignment_id'] );
			$target     = null === $assignment ? null : $this->target( (int) $assignment['id'], (int) $submission['learner_user_id'] );
			if ( null === $assignment || null === $target ) {
				return null;
			}
			$base = array(
				'submission_id'           => $submission['public_id'],
				'submission_lock_version' => $submission['lock_version'],
				'submission_state'        => $submission['state'],
				'assignment_id'           => $assignment['public_id'],
				'assignment_state'        => $assignment['state'],
				'context_type'            => $assignment['context_type'],
				'context_id'              => $assignment['context_id'],
				'context_revision'        => $assignment['context_revision'],
				'target_state'            => $target['state'],
			);
			if ( ! str_contains( $sql, 'a.schedule_version' ) ) {
				return $base;
			}
			if ( 'active' !== $target['state'] ) {
				return null;
			}
			return array_merge(
				$base,
				array(
					'submission_pk'                   => $submission['id'],
					'current_revision_public_id'      => $submission['current_revision_public_id'],
					'official_revision_public_id'     => $submission['official_revision_public_id'],
					'official_attempt_no'             => $submission['official_attempt_no'],
					'started_at_utc'                  => $submission['started_at_utc'],
					'submitted_at_utc'                => $submission['submitted_at_utc'],
					'closed_at_utc'                   => $submission['closed_at_utc'],
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
				)
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['submission_id'] === ( $args[0] ?? null ) && $row['public_id'] === ( $args[1] ?? null ) ) {
					return array_merge( $row, array( 'revision_pk' => $row['id'] ) );
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_receipts r INNER JOIN oj_ai_submission_revisions v' ) ) {
			g3_same( 30, $args[0] ?? null, 'Q32 control receipt lookup must use the owning submission row.' );
			$matches = array_values(
				array_filter(
					$this->tables['oj_ai_submission_receipts'],
					static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) && in_array( $row['action'], array_slice( $args, 1, 2 ), true )
				)
			);
			if ( array() === $matches ) {
				return null;
			}
			usort( $matches, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			$receipt  = $matches[0];
			$revision = $this->by_id( 'oj_ai_submission_revisions', (int) $receipt['submission_revision_id'] );
			if ( null === $revision ) {
				return null;
			}
			return array_merge(
				$receipt,
				array(
					'revision_public_id'    => $revision['public_id'],
					'revision_content_hash' => $revision['content_hash'],
				)
			);
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_receipts' ) ) {
			g3_same( 30, $args[0] ?? null, 'Q32 receipt lookup must use the owning submission row.' );
			g3_same( 40, $args[1] ?? null, 'Q32 receipt lookup must use the official revision row.' );
			$matches = array_values(
				array_filter(
					$this->tables['oj_ai_submission_receipts'],
					static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) && $row['submission_revision_id'] === ( $args[1] ?? null )
				)
			);
			g3_assert( array() !== $matches, 'Q32 receipt fixture must resolve the official revision receipt.' );
			usort( $matches, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			return $matches[0] ?? null;
		}
		throw new RuntimeException( 'Unexpected Q32 get_row SQL: ' . $sql );
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
		$this->sql[]    = $sql;
		g3_assert( str_contains( $sql, 'FROM oj_ai_submission_attachments' ), 'Q32 must read only its literal attachment table.' );
		g3_assert( ! str_contains( $sql, 'file_public_id' ) && ! str_contains( $sql, 'ai_representation' ), 'Q32 attachment SQL must not select private storage or AI fields.' );
		return array_values(
			array_filter(
				$this->tables['oj_ai_submission_attachments'],
				static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) && in_array( $row['state'], array_slice( $args, 1, 5 ), true )
			)
		);
	}

	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		if ( ! str_starts_with( $value, 'G3Q32:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared Q32 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid Q32 SQL payload.' );
		}
		return $decoded;
	}

	/**
	 * Submission.
	 *
	 * @param string $id Id.
	 * @param int    $actor Actor.
	 * @return array<string,mixed>|null
	 */
	private function submission( string $id, int $actor ): ?array {
		foreach ( $this->tables['oj_ai_submissions'] as $row ) {
			if ( $row['public_id'] === $id && $row['learner_user_id'] === $actor ) {
				return $row;
			}
		}
		return null;
	}
	/**
	 * By id.
	 *
	 * @param string $table Table.
	 * @param int    $id Id.
	 * @return array<string,mixed>|null
	 */
	private function by_id( string $table, int $id ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		} return null;
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
		} return null;
	}
}

/**
 * G3-IMP-04-S02 Q32 own submission projection contract.
 */
final class G3_Q32_Request {
	/**
	 * Initialize g3 q32 request dependencies and state.
	 *
	 * @param array<string,mixed> $url Url.
	 */
	public function __construct( private array $url ) {
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
 * G3 q32 receipt hash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $values Values.
 * @return string
 */
function g3_q32_receipt_hash( Canonical_Json $canonical, array $values ): string {
	return hash( 'sha256', $canonical->encode( $values ) );
}

$q32_canonical              = new Canonical_Json();
$q32_integrity              = new Submission_Revision_Integrity( $q32_canonical );
$q32_assignment_id          = '11040G2081040G2081040G2081';
$q32_submission_id          = '12040G2081040G2081040G2082';
$q32_official_id            = '13040G2081040G2081040G2083';
$q32_current_id             = '14040G2081040G2081040G2084';
$q32_receipt_id             = '15040G2081040G2081040G2085';
$q32_control_receipt_id     = '1A040G2081040G2081040G208B';
$q32_attachment_id          = '16040G2081040G2081040G2086';
$q32_staged_id              = '17040G2081040G2081040G2087';
$q32_private_file_id        = '18040G2081040G2081040G2088';
$q32_publication_id         = '19040G2081040G2081040G2089';
$q32_publication_hash       = hash( 'sha256', 'q32-publication' );
$q32_empty_attachments      = hash( 'sha256', $q32_canonical->encode( array() ) );
$q32_current_attachments    = hash( 'sha256', $q32_canonical->encode( array( $q32_attachment_id ) ) );
$q32_schedule_value         = array(
	'auto_submit_enabled' => true,
	'closes_at_utc'       => '2026-08-22 12:00:00',
	'due_at_utc'          => '2026-08-22 11:00:00',
	'opens_at_utc'        => null,
	'schedule_version'    => 2,
);
$q32_policy_value           = array(
	'copy_previous_answers' => true,
	'max_attempts'          => 2,
	'reopen_until_utc'      => '2026-08-22 12:00:00',
);
$q32_policy_json            = $q32_canonical->encode( $q32_policy_value );
$q32_policy_hash            = hash( 'sha256', $q32_policy_json );
$q32_response_1             = array(
	'answers'                 => array(
		'response-1' => '첫 답안',
		'response-2' => array( $q32_attachment_id ),
	),
	'publication_hash'        => $q32_publication_hash,
	'publication_id'          => $q32_publication_id,
	'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
);
$q32_response_2             = array_replace(
	$q32_response_1,
	array(
		'answers' => array(
			'response-1' => '고친 답안',
			'response-2' => array( $q32_attachment_id ),
		),
	)
);
$q32_response_json_1        = $q32_canonical->encode( $q32_response_1 );
$q32_response_json_2        = $q32_canonical->encode( $q32_response_2 );
$q32_response_hash_1        = hash( 'sha256', $q32_response_json_1 );
$q32_response_hash_2        = hash( 'sha256', $q32_response_json_2 );
$q32_revision_1_hash        = $q32_integrity->content_hash( $q32_submission_id, 1, null, 'saved', '1.0.0', $q32_response_hash_1, $q32_current_attachments );
$q32_revision_2_hash        = $q32_integrity->content_hash( $q32_submission_id, 2, 1, 'saved', '1.0.0', $q32_response_hash_2, $q32_current_attachments );
$q32_receipt_values         = array(
	'action'                      => 'submit',
	'actor_type'                  => 'learner',
	'actor_user_id'               => 7,
	'assignment_schedule_hash'    => hash( 'sha256', $q32_canonical->encode( $q32_schedule_value ) ),
	'assignment_schedule_version' => 2,
	'attachment_set_hash'         => $q32_current_attachments,
	'context_revision'            => 'member-r2',
	'idempotency_key'             => 'submission:receipt-q32',
	'attempt_no'                  => 1,
	'publication_hash'            => $q32_publication_hash,
	'reason'                      => null,
	'receipt_no'                  => 1,
	'received_at_utc'             => '2026-08-22 10:20:00',
	'resubmission_policy_hash'    => $q32_policy_hash,
	'submission_id'               => $q32_submission_id,
	'submission_revision_hash'    => $q32_revision_1_hash,
	'submission_revision_id'      => $q32_official_id,
	'timeliness'                  => 'on_time',
);
$q32_control_receipt_values = array(
	'action'                      => 'reopen',
	'actor_type'                  => 'teacher',
	'actor_user_id'               => 19,
	'assignment_schedule_hash'    => $q32_receipt_values['assignment_schedule_hash'],
	'assignment_schedule_version' => 2,
	'attachment_set_hash'         => $q32_current_attachments,
	'context_revision'            => 'member-r2',
	'idempotency_key'             => 'teacher:reopen-q32',
	'attempt_no'                  => 1,
	'publication_hash'            => $q32_publication_hash,
	'reason'                      => '답안을 보완해 다시 제출하세요.',
	'receipt_no'                  => 2,
	'received_at_utc'             => '2026-08-22 10:30:00',
	'resubmission_policy_hash'    => $q32_policy_hash,
	'submission_id'               => $q32_submission_id,
	'submission_revision_hash'    => $q32_revision_2_hash,
	'submission_revision_id'      => $q32_current_id,
	'timeliness'                  => 'on_time',
);
$q32_tables                 = array(
	'oj_ai_assignments'            => array(
		array(
			'id'                              => 10,
			'public_id'                       => $q32_assignment_id,
			'state'                           => 'open',
			'context_type'                    => 'class',
			'context_id'                      => 'class-21',
			'context_revision'                => 'class-snapshot-r1',
			'worksheet_publication_public_id' => $q32_publication_id,
			'publication_hash'                => $q32_publication_hash,
			'opens_at_utc'                    => null,
			'due_at_utc'                      => '2026-08-22 11:00:00',
			'closes_at_utc'                   => '2026-08-22 12:00:00',
			'schedule_version'                => 2,
			'schedule_hash'                   => $q32_receipt_values['assignment_schedule_hash'],
			'auto_submit_enabled'             => 1,
			'resubmission_policy_schema'      => 'oj_ai.assignment_resubmission_policy',
			'resubmission_policy_version'     => 1,
			'resubmission_policy_json'        => $q32_policy_json,
			'resubmission_policy_hash'        => $q32_policy_hash,
		),
	),
	'oj_ai_assignment_targets'     => array(
		array(
			'id'              => 20,
			'assignment_id'   => 10,
			'learner_user_id' => 7,
			'state'           => 'active',
		),
		array(
			'id'              => 21,
			'assignment_id'   => 10,
			'learner_user_id' => 99,
			'state'           => 'active',
		),
	),
	'oj_ai_submissions'            => array(
		array(
			'id'                          => 30,
			'public_id'                   => $q32_submission_id,
			'lock_version'                => 2,
			'assignment_id'               => 10,
			'learner_user_id'             => 7,
			'state'                       => 'reopened',
			'current_revision_public_id'  => $q32_current_id,
			'official_revision_public_id' => $q32_official_id,
			'official_attempt_no'         => 1,
			'started_at_utc'              => '2026-08-22 10:00:00',
			'submitted_at_utc'            => '2026-08-22 10:20:00',
			'closed_at_utc'               => null,
		),
	),
	'oj_ai_submission_revisions'   => array(
		array(
			'id'                      => 40,
			'public_id'               => $q32_official_id,
			'submission_id'           => 30,
			'content_hash'            => $q32_revision_1_hash,
			'created_at_utc'          => '2026-08-22 10:10:00',
			'revision_no'             => 1,
			'base_revision_no'        => null,
			'state'                   => 'saved',
			'response_schema_version' => '1.0.0',
			'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
			'response_version'        => 1,
			'response_json'           => $q32_response_json_1,
			'response_hash'           => $q32_response_hash_1,
			'attachment_set_hash'     => $q32_current_attachments,
		),
		array(
			'id'                      => 41,
			'public_id'               => $q32_current_id,
			'submission_id'           => 30,
			'content_hash'            => $q32_revision_2_hash,
			'created_at_utc'          => '2026-08-22 10:30:00',
			'revision_no'             => 2,
			'base_revision_no'        => 1,
			'state'                   => 'saved',
			'response_schema_version' => '1.0.0',
			'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
			'response_version'        => 1,
			'response_json'           => $q32_response_json_2,
			'response_hash'           => $q32_response_hash_2,
			'attachment_set_hash'     => $q32_current_attachments,
		),
	),
	'oj_ai_submission_receipts'    => array(
		array_merge(
			$q32_receipt_values,
			array(
				'id'                     => 50,
				'public_id'              => $q32_receipt_id,
				'submission_id'          => 30,
				'submission_revision_id' => 40,
				'content_hash'           => g3_q32_receipt_hash( $q32_canonical, $q32_receipt_values ),
			)
		),
		array_merge(
			$q32_control_receipt_values,
			array(
				'id'                     => 51,
				'public_id'              => $q32_control_receipt_id,
				'submission_id'          => 30,
				'submission_revision_id' => 41,
				'content_hash'           => g3_q32_receipt_hash( $q32_canonical, $q32_control_receipt_values ),
			)
		),
	),
	'oj_ai_submission_attachments' => array(
		array(
			'id'                     => 60,
			'public_id'              => $q32_attachment_id,
			'submission_id'          => 30,
			'submission_revision_id' => 40,
			'response_key'           => 'response-2',
			'file_public_id'         => $q32_private_file_id,
			'state'                  => 'bound',
			'original_name'          => '풀이.pdf',
			'mime_type'              => 'application/pdf',
			'byte_size'              => 1024,
			'bound_at_utc'           => '2026-08-22 10:30:00',
			'ai_representation'      => array( 'private' => true ),
		),
		array(
			'id'                     => 61,
			'public_id'              => $q32_staged_id,
			'submission_id'          => 30,
			'submission_revision_id' => null,
			'response_key'           => 'response-3',
			'file_public_id'         => '20040G2081040G2081040G208A',
			'state'                  => 'ready',
			'original_name'          => '사진.jpg',
			'mime_type'              => 'image/jpeg',
			'byte_size'              => 2048,
			'bound_at_utc'           => null,
			'ai_representation'      => array( 'private' => true ),
		),
	),
);
$q32_database               = new G3_Q32_Database( $q32_tables );
$q32_repository             = new WordPress_Student_Assignment_Repository( $q32_database, $q32_canonical );
$q32_context                = array(
	'type'            => 'class',
	'id'              => 'class-21',
	'label'           => '2학년 1반',
	'lifecycle_state' => 'active',
	'revision'        => 'member-r2',
	'attributes'      => array( 'subject_label' => '수학' ),
);
$q32_contexts               = new G3_Q32_Context_Port( array( 'class:class-21' => $q32_context ) );
$q32_reader                 = new Read_Own_Submission( $q32_repository, $q32_contexts, static fn (): string => '2026-08-22 10:40:00' );

$q32_result     = $q32_reader->read( 7, $q32_submission_id );
$q32_projection = $q32_result['submission'];
g3_same( $q32_current_id, $q32_projection['current_revision']['id'], 'Q32 must return the exact current revision.' );
g3_same( $q32_official_id, $q32_projection['official_revision']['id'], 'Q32 must return the exact official revision separately.' );
g3_same( $q32_receipt_id, $q32_projection['receipt']['id'], 'Q32 must recover the safe official receipt.' );
g3_same( 'reopen', $q32_projection['control_receipt']['action'], 'Q32 must recover the latest verified teacher control action.' );
g3_same( '답안을 보완해 다시 제출하세요.', $q32_projection['control_receipt']['reason'], 'Q32 must expose the learner-facing reopen reason.' );
g3_same( 2, $q32_projection['resubmission']['max_attempts'], 'Q32 must expose the verified attempt limit.' );
g3_same( 1, $q32_projection['resubmission']['remaining_attempts'], 'Q32 must derive remaining attempts from the official attempt counter.' );
g3_same( array( 'submission.autosave', 'submission.submit', 'submission.export_pdf' ), $q32_projection['actions'], 'A reopened submission with an official revision must remain editable, resubmittable, and allow its own result PDF.' );

$q32_before_extra = $q32_database->tables;
$q32_database->tables['oj_ai_submissions'][0]['official_attempt_no'] = 2;
foreach ( array( $q32_receipt_values, $q32_control_receipt_values ) as $index => $receipt_values ) {
	$receipt_values['attempt_no'] = 2;
	$q32_database->tables['oj_ai_submission_receipts'][ $index ]['attempt_no']   = 2;
	$q32_database->tables['oj_ai_submission_receipts'][ $index ]['content_hash'] = g3_q32_receipt_hash( $q32_canonical, $receipt_values );
}
$q32_extra = $q32_reader->read( 7, $q32_submission_id )['submission'];
g3_same( 2, $q32_extra['resubmission']['max_attempts'], 'An individual allowance must retain the default two-attempt policy.' );
g3_same( 1, $q32_extra['resubmission']['remaining_attempts'], 'A verified teacher reopen must expose one remaining attempt after two submissions.' );
g3_assert( in_array( 'submission.submit', $q32_extra['actions'], true ), 'The owner must be able to use the granted attempt.' );
g3_failure( static fn () => $q32_reader->read( 99, $q32_submission_id ), Assignment_Failure::NOT_FOUND, 'An allowance must not grant another student access.' );
$q32_database->tables = $q32_before_extra;
g3_same( 2, count( $q32_projection['attachments'] ), 'Q32 must return current and staged own attachments.' );
g3_same( array( 'current', 'official' ), $q32_projection['attachments'][0]['revision_scope'], 'A reused bound attachment must derive current and official scope from revision answers, not its origin FK.' );
g3_same( array( 'staged' ), $q32_projection['attachments'][1]['revision_scope'], 'An unbound attachment must remain staged.' );

$q32_json = $q32_canonical->encode( $q32_result );
foreach ( array( 'file_public_id', 'ai_representation', 'idempotency_key', 'actor_user_id', 'resubmission_policy_hash', 'submission_revision_hash', 'grading_schema', 'teacher_note' ) as $private_key ) {
	g3_assert( ! str_contains( $q32_json, $private_key ), 'Q32 leaked a private projection key: ' . $private_key );
}
g3_assert( ! str_contains( $q32_json, $q32_private_file_id ), 'Q32 leaked a private storage file ID.' );
g3_assert( ! str_contains( $q32_json, 'submission:receipt-q32' ), 'Q32 leaked a receipt idempotency key.' );

$q32_snapshot = $q32_repository->submission_authorization_snapshot( $q32_submission_id, 7 );
g3_same( 'reopened', $q32_snapshot['state'], 'Q32 authorization must use the submission state.' );
g3_same( null, $q32_repository->submission_authorization_snapshot( $q32_submission_id, 99 ), 'Another learner must not resolve the submission snapshot.' );

$q32_policy           = new Submission_Object_Authorization( $q32_repository );
$q32_context_decision = Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'member-r2', 'learner' );
$q32_policy_result    = $q32_policy->authorize( 7, 'student_submission.view', $q32_context_decision, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $q32_submission_id, 'self_view' ) );
g3_assert( $q32_policy_result->is_allowed(), 'Submission Object Policy must allow the owner in the active Context.' );
$q32_wrong_context = Context_Authorization_Result::allowed( 'class', 'class-99', 'active', 'member-r1', 'learner' );
g3_assert( $q32_policy->authorize( 7, 'student_submission.view', $q32_wrong_context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $q32_submission_id, 'self_view' ) )->is_denied(), 'Submission Object Policy must deny a mismatched Context.' );

$q32_database->tables['oj_ai_assignment_targets'][0]['state'] = 'revoked';
g3_failure( static fn () => $q32_reader->read( 7, $q32_submission_id ), Assignment_Failure::NOT_FOUND, 'Q32 must hide a revoked learner target.' );
$q32_database->tables['oj_ai_assignment_targets'][0]['state'] = 'active';
$q32_contexts->contexts                                       = array();
g3_failure( static fn () => $q32_reader->read( 7, $q32_submission_id ), Assignment_Failure::NOT_FOUND, 'Q32 must recheck current platform membership.' );
$q32_contexts->contexts = array( 'class:class-21' => $q32_context );

$q32_original_revision = $q32_database->tables['oj_ai_submission_revisions'][1];
$q32_unsafe_response   = array_replace( $q32_response_2, array( 'answers' => array( 'response-1' => array( 'teacherNote' => 'private' ) ) ) );
$q32_unsafe_json       = $q32_canonical->encode( $q32_unsafe_response );
$q32_database->tables['oj_ai_submission_revisions'][1]['response_json'] = $q32_unsafe_json;
$q32_database->tables['oj_ai_submission_revisions'][1]['response_hash'] = hash( 'sha256', $q32_unsafe_json );
$q32_database->tables['oj_ai_submission_revisions'][1]['content_hash']  = $q32_integrity->content_hash(
	$q32_submission_id,
	2,
	1,
	'saved',
	'1.0.0',
	hash( 'sha256', $q32_unsafe_json ),
	$q32_current_attachments
);
g3_failure( static fn () => $q32_reader->read( 7, $q32_submission_id ), Assignment_Failure::STORED_DATA_INVALID, 'Q32 must fail closed on camelCase private response data.' );
$q32_database->tables['oj_ai_submission_revisions'][1]                 = $q32_original_revision;
$q32_database->tables['oj_ai_submission_revisions'][1]['content_hash'] = hash( 'sha256', 'tampered-q32' );
g3_failure( static fn () => $q32_reader->read( 7, $q32_submission_id ), Assignment_Failure::STORED_DATA_INVALID, 'Q32 must reject an invalid immutable revision content hash.' );
$q32_database->tables['oj_ai_submission_revisions'][1] = $q32_original_revision;

$q32_original_control_receipt = $q32_database->tables['oj_ai_submission_receipts'][1];
$q32_database->tables['oj_ai_submission_receipts'][1]['content_hash'] = hash( 'sha256', 'tampered-control-receipt' );
g3_failure( static fn () => $q32_reader->read( 7, $q32_submission_id ), Assignment_Failure::STORED_DATA_INVALID, 'Q32 must reject a tampered teacher control receipt.' );
$q32_database->tables['oj_ai_submission_receipts'][1] = $q32_original_control_receipt;

$q32_controller_reflection = new ReflectionClass( Student_Assignment_Rest_Controller::class );
$q32_controller            = $q32_controller_reflection->newInstanceWithoutConstructor();
$q32_path_method           = $q32_controller_reflection->getMethod( 'submission_path_id' );
g3_same( $q32_submission_id, $q32_path_method->invoke( $q32_controller, new G3_Q32_Request( array( 'submission_id' => $q32_submission_id ) ) ), 'Q32 REST path must preserve the submission public ID.' );
g3_invalid( static fn () => $q32_path_method->invoke( $q32_controller, new G3_Q32_Request( array( 'submission_id' => '7' ) ) ), 'Q32 REST must reject numeric or malformed submission IDs.' );

$q32_sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Submission_Query.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Submission_Object_Authorization.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( array( 'oj_ai_submissions', 'oj_ai_submission_revisions', 'oj_ai_submission_receipts', 'oj_ai_submission_attachments' ) as $table ) {
	g3_assert( str_contains( $q32_sources[0], $table ), 'Q32 literal table marker is missing: ' . $table );
}
foreach ( array( '/me/submissions/', 'student_submission.view', 'TYPE_SUBMISSION' ) as $marker ) {
	g3_assert( str_contains( implode( "\n", $q32_sources ), $marker ), 'Q32 REST or Policy marker is missing: ' . $marker );
}
foreach ( $q32_sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3-IMP-04-S02 must not use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3-IMP-04-S02 must not use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3-IMP-04-S02 must not depend on platform internals.' );
}

echo "G3-IMP-04-S02 passed: Q32 self-only current/official revisions, safe receipt and attachments, Context recheck, integrity, and private-field exclusion.\n";

<?php
/**
 * G3-IMP-06-S02 teacher submission Q13-Q15 contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\Assignment_Object_Authorization;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Repository;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Object_Authorization;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Submission_Query;
use OJAI\Dashboard\Submission\Teacher\Presentation\Teacher_Submission_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

/**
 * G3-IMP-06-S02 teacher submission Q13-Q15 contract.
 */
final class G3_Teacher_Student_Access implements Student_Assignment_Access_Port_Interface {
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
 * G3-IMP-06-S02 teacher submission Q13-Q15 contract.
 */
final class G3_Teacher_Request {
	/**
	 * Initialize g3 teacher request dependencies and state.
	 *
	 * @param array<string,mixed> $url Url.
	 * @param array<string,mixed> $query Query.
	 */
	public function __construct( private array $url, private array $query = array() ) {
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_url_params(): array {
		return $this->url;
	}
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G3-IMP-06-S02 teacher submission Q13-Q15 contract.
 */
final class G3_Teacher_Database {
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
	 * Initialize g3 teacher database dependencies and state.
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
		return 'G3TSQ:' . base64_encode( serialize( array( $sql, $args ) ) );
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		unset( $sql );
		return null;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		unset( $table, $data );
		return false;
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		unset( $sql );
		return false;
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
		if ( str_contains( $sql, 'SELECT context_type, context_id, context_revision, schedule_version, state FROM oj_ai_assignments' ) ) {
			$assignment = $this->by_public( 'oj_ai_assignments', (string) ( $args[0] ?? '' ) );
			if ( null === $assignment ) {
				return null;
			}
			return array_intersect_key( $assignment, array_flip( array( 'context_type', 'context_id', 'context_revision', 'schedule_version', 'state' ) ) );
		}
		if ( str_contains( $sql, 'a.id AS assignment_pk' ) ) {
			g3_assert( ! str_contains( $sql, 'a.target_count' ), 'Q13 must not read a synthetic assignment target_count column.' );
			g3_assert( str_contains( $sql, 'counted_targets.assignment_id = a.id) AS target_count' ), 'Q13 must derive target_count from assignment targets.' );
			$assignment = $this->by_public( 'oj_ai_assignments', (string) ( $args[0] ?? '' ) );
			if ( null === $assignment || ! $this->context_matches( $assignment, $args, 1 ) ) {
				return null;
			}
			return $this->assignment_fields( $assignment );
		}
		if ( str_contains( $sql, 'r.public_id AS revision_id' ) ) {
			$revision = $this->by_public( 'oj_ai_submission_revisions', (string) ( $args[0] ?? '' ) );
			if ( null === $revision ) {
				return null;
			}
			$submission = $this->by_id( 'oj_ai_submissions', (int) $revision['submission_id'] );
			$assignment = null === $submission ? null : $this->by_id( 'oj_ai_assignments', (int) $submission['assignment_id'] );
			if ( null === $submission || null === $assignment ) {
				return null;
			}
			return array(
				'revision_id'                 => $revision['public_id'],
				'submission_id'               => $submission['public_id'],
				'official_revision_public_id' => $submission['official_revision_public_id'],
				'context_type'                => $assignment['context_type'],
				'context_id'                  => $assignment['context_id'],
				'context_revision'            => $assignment['context_revision'],
			);
		}
		if ( str_contains( $sql, 'SELECT s.public_id AS submission_id' ) ) {
			$submission = $this->by_public( 'oj_ai_submissions', (string) ( $args[0] ?? '' ) );
			if ( null === $submission ) {
				return null;
			}
			$assignment = $this->by_id( 'oj_ai_assignments', (int) $submission['assignment_id'] );
			$target     = $this->target( (int) $submission['assignment_id'], (int) $submission['learner_user_id'] );
			if ( null === $assignment || null === $target ) {
				return null;
			}
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
		if ( str_contains( $sql, 's.id AS submission_pk' ) ) {
			$submission = $this->by_public( 'oj_ai_submissions', (string) ( $args[0] ?? '' ) );
			if ( null === $submission ) {
				return null;
			}
			$assignment = $this->by_id( 'oj_ai_assignments', (int) $submission['assignment_id'] );
			$target     = $this->target( (int) $submission['assignment_id'], (int) $submission['learner_user_id'] );
			if ( null === $assignment || null === $target || ! $this->context_matches( $assignment, $args, 1 ) ) {
				return null;
			}
			return array_merge( $this->submission_fields( $submission ), $this->assignment_fields( $assignment ), $this->target_fields( $target ) );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions' ) ) {
			foreach ( $this->tables['oj_ai_submission_revisions'] as $row ) {
				if ( $row['submission_id'] === ( $args[0] ?? null ) && $row['public_id'] === ( $args[1] ?? null ) ) {
					return array_merge( $row, array( 'revision_pk' => $row['id'] ) );
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_receipts' ) ) {
			$rows = array_values(
				array_filter(
					$this->tables['oj_ai_submission_receipts'],
					static fn ( array $row ): bool => $row['submission_id'] === ( $args[0] ?? null ) && $row['submission_revision_id'] === ( $args[1] ?? null )
				)
			);
			usort( $rows, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			return $rows[0] ?? null;
		}
		throw new RuntimeException( 'Unexpected teacher submission get_row SQL: ' . $sql );
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
		[ $sql, $args ] = $this->prepared( $prepared );
		$this->sql[]    = $sql;
		if ( str_contains( $sql, 'FROM oj_ai_assignment_targets t' ) ) {
			g3_assert( str_contains( $sql, 'LEFT JOIN oj_ai_submissions' ), 'Q13 must start from immutable targets and left join submissions.' );
			$assignment_id = (int) ( $args[0] ?? 0 );
			$after         = count( $args ) > 2 ? (string) $args[1] : '';
			$limit         = (int) $args[ count( $args ) - 1 ];
			$targets       = array_values(
				array_filter(
					$this->tables['oj_ai_assignment_targets'],
					static fn ( array $row ): bool => $row['assignment_id'] === $assignment_id && ( '' === $after || $row['public_id'] > $after )
				)
			);
			usort( $targets, static fn ( array $left, array $right ): int => $left['public_id'] <=> $right['public_id'] );
			$result = array();
			foreach ( array_slice( $targets, 0, $limit ) as $target ) {
				$row        = $this->target_fields( $target );
				$submission = $this->submission_for_target( $assignment_id, (int) $target['learner_user_id'] );
				$result[]   = array_merge( $row, null === $submission ? $this->empty_submission_fields() : $this->submission_fields( $submission ) );
			}
			return $result;
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_revisions' ) ) {
			$submission_id = (int) ( $args[0] ?? 0 );
			$before        = count( $args ) > 2 ? (int) $args[1] : PHP_INT_MAX;
			$limit         = (int) $args[ count( $args ) - 1 ];
			$rows          = array_values(
				array_filter(
					$this->tables['oj_ai_submission_revisions'],
					static fn ( array $row ): bool => $row['submission_id'] === $submission_id && $row['revision_no'] < $before
				)
			);
			usort( $rows, static fn ( array $left, array $right ): int => $right['revision_no'] <=> $left['revision_no'] );
			return array_map( static fn ( array $row ): array => array_merge( $row, array( 'revision_pk' => $row['id'] ) ), array_slice( $rows, 0, $limit ) );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_receipts' ) ) {
			$submission_id = (int) ( $args[0] ?? 0 );
			$revision_ids  = array_map( 'intval', array_slice( $args, 1, -1 ) );
			$limit         = (int) $args[ count( $args ) - 1 ];
			$rows          = array_values(
				array_filter(
					$this->tables['oj_ai_submission_receipts'],
					static fn ( array $row ): bool => $row['submission_id'] === $submission_id && in_array( $row['submission_revision_id'], $revision_ids, true )
				)
			);
			usort( $rows, static fn ( array $left, array $right ): int => $right['receipt_no'] <=> $left['receipt_no'] );
			return array_slice( $rows, 0, $limit );
		}
		if ( str_contains( $sql, 'FROM oj_ai_submission_attachments' ) ) {
			g3_assert( ! str_contains( $sql, 'file_public_id' ) && ! str_contains( $sql, 'ai_representation' ), 'Q14 must not select private file storage or AI representation fields.' );
			$submission_id = (int) ( $args[0] ?? 0 );
			$states        = array_slice( $args, 1, 5 );
			$rows          = array_values(
				array_filter(
					$this->tables['oj_ai_submission_attachments'],
					static fn ( array $row ): bool => $row['submission_id'] === $submission_id && in_array( $row['state'], $states, true )
				)
			);
			return $rows;
		}
		throw new RuntimeException( 'Unexpected teacher submission get_results SQL: ' . $sql );
	}

	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		if ( ! str_starts_with( $value, 'G3TSQ:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared teacher submission SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid teacher submission SQL payload.' );
		}
		return $decoded;
	}

	/**
	 * Context matches.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<int,mixed>    $args Args.
	 * @param int                 $offset Offset.
	 * @return bool
	 */
	private function context_matches( array $row, array $args, int $offset ): bool {
		return $row['context_type'] === ( $args[ $offset ] ?? null ) && $row['context_id'] === ( $args[ $offset + 1 ] ?? null ) && $row['context_revision'] === ( $args[ $offset + 2 ] ?? null );
	}

	/**
	 * Assignment fields.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function assignment_fields( array $row ): array {
		$target_count = count(
			array_filter(
				$this->tables['oj_ai_assignment_targets'],
				static fn ( array $target ): bool => $target['assignment_id'] === $row['id']
			)
		);
		return array(
			'assignment_pk'                   => $row['id'],
			'assignment_id'                   => $row['public_id'],
			'title_snapshot'                  => $row['title_snapshot'],
			'assignment_state'                => $row['state'],
			'assignment_lock_version'         => $row['lock_version'],
			'context_type'                    => $row['context_type'],
			'context_id'                      => $row['context_id'],
			'context_revision'                => $row['context_revision'],
			'worksheet_publication_public_id' => $row['worksheet_publication_public_id'],
			'publication_hash'                => $row['publication_hash'],
			'target_count'                    => $target_count,
			'opens_at_utc'                    => $row['opens_at_utc'],
			'due_at_utc'                      => $row['due_at_utc'],
			'closes_at_utc'                   => $row['closes_at_utc'],
			'schedule_version'                => $row['schedule_version'],
			'schedule_hash'                   => $row['schedule_hash'],
			'auto_submit_enabled'             => $row['auto_submit_enabled'],
		);
	}

	/**
	 * Target fields.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function target_fields( array $row ): array {
		return array(
			'target_id'                => $row['public_id'],
			'target_content_hash'      => $row['content_hash'],
			'learner_user_id'          => $row['learner_user_id'],
			'membership_revision'      => $row['membership_revision'],
			'display_snapshot_schema'  => $row['display_snapshot_schema'],
			'display_snapshot_version' => $row['display_snapshot_version'],
			'display_snapshot_json'    => $row['display_snapshot_json'],
			'display_snapshot_hash'    => $row['display_snapshot_hash'],
			'snapshot_hash'            => $row['snapshot_hash'],
			'target_state'             => $row['state'],
		);
	}

	/**
	 * Submission fields.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function submission_fields( array $row ): array {
		return array(
			'submission_pk'               => $row['id'],
			'submission_id'               => $row['public_id'],
			'submission_lock_version'     => $row['lock_version'],
			'submission_state'            => $row['state'],
			'current_revision_public_id'  => $row['current_revision_public_id'],
			'official_revision_public_id' => $row['official_revision_public_id'],
			'official_attempt_no'         => $row['official_attempt_no'],
			'official_review_public_id'   => $row['official_review_public_id'],
			'official_review_hash'        => $row['official_review_hash'],
			'current_release_public_id'   => $row['current_release_public_id'],
			'current_release_hash'        => $row['current_release_hash'],
			'started_at_utc'              => $row['started_at_utc'],
			'submitted_at_utc'            => $row['submitted_at_utc'],
			'closed_at_utc'               => $row['closed_at_utc'],
		);
	}

	/**
	 * Empty submission fields.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_submission_fields(): array {
		return array(
			'submission_pk'               => null,
			'submission_id'               => null,
			'submission_lock_version'     => null,
			'submission_state'            => null,
			'current_revision_public_id'  => null,
			'official_revision_public_id' => null,
			'official_attempt_no'         => null,
			'official_review_public_id'   => null,
			'official_review_hash'        => null,
			'current_release_public_id'   => null,
			'current_release_hash'        => null,
			'started_at_utc'              => null,
			'submitted_at_utc'            => null,
			'closed_at_utc'               => null,
		);
	}

	/**
	 * By public.
	 *
	 * @param string $table Table.
	 * @param string $id Id.
	 * @return array<string,mixed>|null
	 */
	private function by_public( string $table, string $id ): ?array {
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $row['public_id'] === $id ) {
				return $row;
			}
		} return null;
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
	 * @param int $learner_id Learner id.
	 * @return array<string,mixed>|null
	 */
	private function target( int $assignment_id, int $learner_id ): ?array {
		foreach ( $this->tables['oj_ai_assignment_targets'] as $row ) {
			if ( $row['assignment_id'] === $assignment_id && $row['learner_user_id'] === $learner_id ) {
				return $row;
			}
		} return null;
	}
	/**
	 * Submission for target.
	 *
	 * @param int $assignment_id Distribution public identifier.
	 * @param int $learner_id Learner id.
	 * @return array<string,mixed>|null
	 */
	private function submission_for_target( int $assignment_id, int $learner_id ): ?array {
		foreach ( $this->tables['oj_ai_submissions'] as $row ) {
			if ( $row['assignment_id'] === $assignment_id && $row['learner_user_id'] === $learner_id ) {
				return $row;
			}
		} return null;
	}
}

$tsq_canonical           = new Canonical_Json();
$tsq_cursor              = new Signed_Cursor_Codec( $tsq_canonical, 'teacher-submission-contract-secret' );
$tsq_integrity           = new Submission_Revision_Integrity( $tsq_canonical );
$tsq_assignment_id       = '31040G2081040G2081040G2081';
$tsq_target_1            = '32040G2081040G2081040G2082';
$tsq_target_2            = '33040G2081040G2081040G2083';
$tsq_target_3            = '34040G2081040G2081040G2084';
$tsq_draft_submission    = '35040G2081040G2081040G2085';
$tsq_official_submission = '36040G2081040G2081040G2086';
$tsq_draft_revision      = '37040G2081040G2081040G2087';
$tsq_old_revision        = '38040G2081040G2081040G2088';
$tsq_official_revision   = '39040G2081040G2081040G2089';
$tsq_receipt_id          = '3A040G2081040G2081040G208A';
$tsq_attachment_id       = '3B040G2081040G2081040G208B';
$tsq_publication_id      = '3C040G2081040G2081040G208C';
$tsq_review_id           = '3D040G2081040G2081040G208D';
$tsq_release_id          = '3E040G2081040G2081040G208E';
$tsq_publication_hash    = hash( 'sha256', 'teacher-publication' );
$tsq_schedule_value      = array(
	'auto_submit_enabled' => true,
	'closes_at_utc'       => '2026-08-22 12:00:00',
	'due_at_utc'          => '2026-08-22 11:00:00',
	'opens_at_utc'        => null,
	'schedule_version'    => 3,
);
$tsq_schedule_hash       = hash( 'sha256', $tsq_canonical->encode( $tsq_schedule_value ) );
$tsq_empty_attachments   = hash( 'sha256', $tsq_canonical->encode( array() ) );
$tsq_bound_attachments   = hash( 'sha256', $tsq_canonical->encode( array( $tsq_attachment_id ) ) );
$tsq_old_response        = array(
	'answers'                 => array( 'response-1' => '초안' ),
	'publication_hash'        => $tsq_publication_hash,
	'publication_id'          => $tsq_publication_id,
	'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
);
$tsq_official_response   = array_replace(
	$tsq_old_response,
	array(
		'answers' => array(
			'response-1' => '공식 답안',
			'response-2' => array( $tsq_attachment_id ),
		),
	)
);
$tsq_draft_response      = array_replace( $tsq_old_response, array( 'answers' => array( 'response-1' => '작성 중' ) ) );

/**
 * G3 tsq revision.
 *
 * @param Canonical_Json                $canonical Canonical JSON encoder used for stable digests.
 * @param Submission_Revision_Integrity $integrity Integrity.
 * @param string                        $id Id.
 * @param int                           $pk Pk.
 * @param int                           $submission_pk Submission pk.
 * @param string                        $submission_id Submission public identifier.
 * @param int                           $revision_no Revision no.
 * @param int|null                      $base_revision_no Base revision no.
 * @param array<string,mixed>           $response Response.
 * @param string                        $attachment_hash Attachment hash.
 * @return array
 */
function g3_tsq_revision( Canonical_Json $canonical, Submission_Revision_Integrity $integrity, string $id, int $pk, int $submission_pk, string $submission_id, int $revision_no, ?int $base_revision_no, array $response, string $attachment_hash ): array {
	$json          = $canonical->encode( $response );
	$response_hash = hash( 'sha256', $json );
	return array(
		'id'                      => $pk,
		'public_id'               => $id,
		'submission_id'           => $submission_pk,
		'content_hash'            => $integrity->content_hash( $submission_id, $revision_no, $base_revision_no, 'saved', '1.0.0', $response_hash, $attachment_hash ),
		'created_at_utc'          => sprintf( '2026-08-22 10:%02d:00', $revision_no * 10 ),
		'revision_no'             => $revision_no,
		'base_revision_no'        => $base_revision_no,
		'state'                   => 'saved',
		'response_schema_version' => '1.0.0',
		'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
		'response_version'        => 1,
		'response_json'           => $json,
		'response_hash'           => $response_hash,
		'attachment_set_hash'     => $attachment_hash,
	);
}

/**
 * G3 tsq target.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param int            $pk Pk.
 * @param string         $id Id.
 * @param int            $learner_id Learner id.
 * @param string         $name Name.
 * @return array<string,mixed>
 */
function g3_tsq_target( Canonical_Json $canonical, int $pk, string $id, int $learner_id, string $name ): array {
	$display = $canonical->encode( array( 'display_name' => $name ) );
	return array(
		'id'                       => $pk,
		'public_id'                => $id,
		'content_hash'             => hash( 'sha256', 'target-content-' . $id ),
		'assignment_id'            => 10,
		'learner_user_id'          => $learner_id,
		'membership_revision'      => 'member-r3',
		'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
		'display_snapshot_version' => 1,
		'display_snapshot_json'    => $display,
		'display_snapshot_hash'    => hash( 'sha256', $display ),
		'snapshot_hash'            => hash( 'sha256', 'target-snapshot-' . $id ),
		'state'                    => 'active',
	);
}

$tsq_draft_revision_row    = g3_tsq_revision( $tsq_canonical, $tsq_integrity, $tsq_draft_revision, 40, 30, $tsq_draft_submission, 1, null, $tsq_draft_response, $tsq_empty_attachments );
$tsq_old_revision_row      = g3_tsq_revision( $tsq_canonical, $tsq_integrity, $tsq_old_revision, 41, 31, $tsq_official_submission, 1, null, $tsq_old_response, $tsq_empty_attachments );
$tsq_official_revision_row = g3_tsq_revision( $tsq_canonical, $tsq_integrity, $tsq_official_revision, 42, 31, $tsq_official_submission, 2, 1, $tsq_official_response, $tsq_bound_attachments );
$tsq_receipt_content       = array(
	'action'                      => 'submit',
	'actor_type'                  => 'learner',
	'actor_user_id'               => 103,
	'assignment_schedule_hash'    => $tsq_schedule_hash,
	'assignment_schedule_version' => 3,
	'attachment_set_hash'         => $tsq_bound_attachments,
	'context_revision'            => 'member-r3',
	'idempotency_key'             => 'submission:teacher-contract',
	'attempt_no'                  => 1,
	'publication_hash'            => $tsq_publication_hash,
	'reason'                      => null,
	'receipt_no'                  => 1,
	'received_at_utc'             => '2026-08-22 10:25:00',
	'resubmission_policy_hash'    => hash( 'sha256', 'teacher-policy' ),
	'submission_id'               => $tsq_official_submission,
	'submission_revision_hash'    => $tsq_official_revision_row['content_hash'],
	'submission_revision_id'      => $tsq_official_revision,
	'timeliness'                  => 'on_time',
);
$tsq_tables                = array(
	'oj_ai_assignments'            => array(
		array(
			'id'                              => 10,
			'public_id'                       => $tsq_assignment_id,
			'title_snapshot'                  => '분수 활동지',
			'state'                           => 'open',
			'lock_version'                    => 4,
			'context_type'                    => 'class',
			'context_id'                      => 'class-21',
			'context_revision'                => 'class-r3',
			'worksheet_publication_public_id' => $tsq_publication_id,
			'publication_hash'                => $tsq_publication_hash,
			'opens_at_utc'                    => null,
			'due_at_utc'                      => '2026-08-22 11:00:00',
			'closes_at_utc'                   => '2026-08-22 12:00:00',
			'schedule_version'                => 3,
			'schedule_hash'                   => $tsq_schedule_hash,
			'auto_submit_enabled'             => 1,
		),
	),
	'oj_ai_assignment_targets'     => array(
		g3_tsq_target( $tsq_canonical, 20, $tsq_target_1, 101, '김하늘' ),
		g3_tsq_target( $tsq_canonical, 21, $tsq_target_2, 102, '박나무' ),
		g3_tsq_target( $tsq_canonical, 22, $tsq_target_3, 103, '이바다' ),
	),
	'oj_ai_submissions'            => array(
		array(
			'id'                          => 30,
			'public_id'                   => $tsq_draft_submission,
			'lock_version'                => 1,
			'assignment_id'               => 10,
			'learner_user_id'             => 102,
			'state'                       => 'draft',
			'current_revision_public_id'  => $tsq_draft_revision,
			'official_revision_public_id' => null,
			'official_attempt_no'         => 0,
			'official_review_public_id'   => null,
			'official_review_hash'        => null,
			'current_release_public_id'   => null,
			'current_release_hash'        => null,
			'started_at_utc'              => '2026-08-22 10:00:00',
			'submitted_at_utc'            => null,
			'closed_at_utc'               => null,
		),
		array(
			'id'                          => 31,
			'public_id'                   => $tsq_official_submission,
			'lock_version'                => 2,
			'assignment_id'               => 10,
			'learner_user_id'             => 103,
			'state'                       => 'submitted',
			'current_revision_public_id'  => $tsq_official_revision,
			'official_revision_public_id' => $tsq_official_revision,
			'official_attempt_no'         => 1,
			'official_review_public_id'   => $tsq_review_id,
			'official_review_hash'        => hash( 'sha256', 'review' ),
			'current_release_public_id'   => $tsq_release_id,
			'current_release_hash'        => hash( 'sha256', 'release' ),
			'started_at_utc'              => '2026-08-22 10:00:00',
			'submitted_at_utc'            => '2026-08-22 10:25:00',
			'closed_at_utc'               => null,
		),
	),
	'oj_ai_submission_revisions'   => array( $tsq_draft_revision_row, $tsq_old_revision_row, $tsq_official_revision_row ),
	'oj_ai_submission_receipts'    => array(
		array_merge(
			$tsq_receipt_content,
			array(
				'id'                     => 50,
				'public_id'              => $tsq_receipt_id,
				'content_hash'           => hash( 'sha256', $tsq_canonical->encode( $tsq_receipt_content ) ),
				'submission_id'          => 31,
				'submission_revision_id' => 42,
			)
		),
	),
	'oj_ai_submission_attachments' => array(
		array(
			'id'                     => 60,
			'public_id'              => $tsq_attachment_id,
			'submission_id'          => 31,
			'submission_revision_id' => 42,
			'response_key'           => 'response-2',
			'file_public_id'         => '3F040G2081040G2081040G208F',
			'state'                  => 'bound',
			'original_name'          => '풀이.pdf',
			'mime_type'              => 'application/pdf',
			'byte_size'              => 2048,
			'sha256'                 => hash( 'sha256', 'attachment' ),
			'bound_at_utc'           => '2026-08-22 10:24:00',
			'ai_representation'      => array( 'private' => true ),
		),
	),
);

$tsq_database   = new G3_Teacher_Database( $tsq_tables );
$tsq_repository = new WordPress_Teacher_Submission_Query( $tsq_database, $tsq_canonical, $tsq_cursor );
$tsq_reader     = new Read_Teacher_Submissions( $tsq_repository );

$tsq_list = $tsq_reader->list_assignment( $tsq_assignment_id, 'class', 'class-21', 'class-r3', '', 100 );
g3_same( 3, count( $tsq_list['items'] ), 'Q13 must include every immutable assignment target.' );
g3_same( 'not_started', $tsq_list['items'][0]['status'], 'Q13 must derive not_started when no submission root exists.' );
g3_same( 3, $tsq_list['assignment']['target_count'], 'Q13 must derive the assignment target count from immutable target rows.' );
g3_same( null, $tsq_list['items'][0]['submission'], 'Q13 must not manufacture an empty submission for a learner who never started.' );
g3_same( 'draft', $tsq_list['items'][1]['status'], 'Q13 must expose the current submission state.' );
g3_same( 'reviewed', $tsq_list['items'][2]['evaluation_status'], 'Q13 must derive evaluation status from the official review pointer.' );
g3_same( 'released', $tsq_list['items'][2]['release_status'], 'Q13 must derive release status from the current release pointer.' );
g3_same( $tsq_publication_hash, $tsq_list['assignment']['source']['publication_hash'], 'Q13 must preserve the exact assignment publication source.' );
g3_same( $tsq_schedule_hash, $tsq_list['assignment']['schedule']['hash'], 'Q13 must preserve the exact assignment schedule hash.' );

$tsq_first_page = $tsq_reader->list_assignment( $tsq_assignment_id, 'class', 'class-21', 'class-r3', '', 2 );
g3_assert( '' !== $tsq_first_page['next_cursor'], 'Q13 must return a signed target cursor when more targets remain.' );
$tsq_second_page = $tsq_reader->list_assignment( $tsq_assignment_id, 'class', 'class-21', 'class-r3', $tsq_first_page['next_cursor'], 2 );
g3_same( $tsq_target_3, $tsq_second_page['items'][0]['target']['id'], 'Q13 signed cursor must resume after the exact target.' );

$tsq_detail = $tsq_reader->detail( $tsq_official_submission, 'class', 'class-21', 'class-r3' )['submission'];
g3_same( $tsq_official_revision, $tsq_detail['official_revision']['id'], 'Q14 must return the exact official revision.' );
g3_same( $tsq_receipt_id, $tsq_detail['receipt']['id'], 'Q14 must return the immutable official receipt.' );
g3_same(
	array(
		'type'    => 'learner',
		'user_id' => 103,
	),
	$tsq_detail['receipt']['actor'],
	'Q14 teacher receipt must retain the accountable actor.'
);
g3_same( array( 'current', 'official' ), $tsq_detail['attachments'][0]['revision_scope'], 'Q14 must derive attachment scope from revision answers.' );
g3_same( hash( 'sha256', 'attachment' ), $tsq_detail['attachments'][0]['sha256'], 'Q14 must expose safe attachment evidence.' );
$tsq_detail_json = $tsq_canonical->encode( $tsq_detail );
foreach ( array( 'idempotency_key', 'file_public_id', 'ai_representation', 'system_prompt', 'api_key' ) as $private_key ) {
	g3_assert( ! str_contains( $tsq_detail_json, $private_key ), 'Q14 leaked a private field: ' . $private_key );
}
g3_assert( ! str_contains( $tsq_detail_json, 'submission:teacher-contract' ), 'Q14 leaked the receipt idempotency key value.' );

$tsq_print_source = $tsq_repository->find_official_revision( $tsq_official_revision );
g3_same( $tsq_official_submission, $tsq_print_source['id'], 'C49 source must resolve the official revision to its submission root.' );
g3_same( $tsq_official_revision_row['content_hash'], $tsq_print_source['official_revision']['content_hash'], 'C49 source must preserve the official revision content hash.' );
g3_same( $tsq_publication_hash, $tsq_print_source['source']['publication_hash'], 'C49 source must preserve the assignment publication hash.' );
g3_same( null, $tsq_repository->find_official_revision( $tsq_old_revision ), 'C49 source must hide a revision that is not the root official pointer.' );

$tsq_original_revision_hash                             = $tsq_database->tables['oj_ai_submission_receipts'][0]['submission_revision_hash'];
$tsq_cross_tampered_receipt                             = $tsq_receipt_content;
$tsq_cross_tampered_receipt['submission_revision_hash'] = hash( 'sha256', 'cross-evidence-tamper' );
$tsq_database->tables['oj_ai_submission_receipts'][0]['submission_revision_hash'] = $tsq_cross_tampered_receipt['submission_revision_hash'];
$tsq_database->tables['oj_ai_submission_receipts'][0]['content_hash']             = hash( 'sha256', $tsq_canonical->encode( $tsq_cross_tampered_receipt ) );
g3_failure(
	static fn () => $tsq_repository->find_official_revision( $tsq_official_revision ),
	Assignment_Failure::STORED_DATA_INVALID,
	'C49 source must cross-check a valid receipt body against the official revision content hash.'
);
$tsq_database->tables['oj_ai_submission_receipts'][0]['submission_revision_hash'] = $tsq_original_revision_hash;
$tsq_database->tables['oj_ai_submission_receipts'][0]['content_hash']             = hash( 'sha256', $tsq_canonical->encode( $tsq_receipt_content ) );

$tsq_history_1 = $tsq_reader->history( $tsq_official_submission, 'class', 'class-21', 'class-r3', '', 1 );
g3_same( $tsq_official_revision, $tsq_history_1['revisions'][0]['id'], 'Q15 must order immutable revisions newest first.' );
g3_same( true, $tsq_history_1['revisions'][0]['is_official'], 'Q15 must identify the exact official pointer.' );
g3_same( $tsq_receipt_id, $tsq_history_1['revisions'][0]['receipts'][0]['id'], 'Q15 must bind receipts to their exact revision.' );
g3_assert( '' !== $tsq_history_1['next_cursor'], 'Q15 must paginate revision history with a signed cursor.' );
$tsq_history_2 = $tsq_reader->history( $tsq_official_submission, 'class', 'class-21', 'class-r3', $tsq_history_1['next_cursor'], 1 );
g3_same( $tsq_old_revision, $tsq_history_2['revisions'][0]['id'], 'Q15 cursor must resume at the previous immutable revision.' );
g3_same( array(), $tsq_history_2['revisions'][0]['receipts'], 'Q15 must not invent a receipt for an autosave-only revision.' );

g3_failure(
	static fn () => $tsq_reader->detail( $tsq_official_submission, 'class', 'class-21', 'class-wrong' ),
	Assignment_Failure::NOT_FOUND,
	'Q14 must hide a submission outside the exact Context revision.'
);

$tsq_context           = Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'class-r3', 'educator' );
$tsq_wrong_context     = Context_Authorization_Result::allowed( 'class', 'class-99', 'active', 'class-r3', 'educator' );
$tsq_submission_policy = new Submission_Object_Authorization( new G3_Teacher_Student_Access(), $tsq_repository );
g3_assert(
	$tsq_submission_policy->authorize( 9, 'teacher_submission.view', $tsq_context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $tsq_official_submission, 'teacher_view' ) )->is_allowed(),
	'Q14 submission object policy must allow staff in the exact Context.'
);
g3_assert(
	$tsq_submission_policy->authorize( 9, 'teacher_submission.view', $tsq_wrong_context, new Object_Scope( Object_Scope::TYPE_SUBMISSION, $tsq_official_submission, 'teacher_view' ) )->is_denied(),
	'Q14 submission object policy must deny a mismatched Context.'
);
$tsq_assignment_repository = new WordPress_Assignment_Repository( $tsq_database, $tsq_canonical, $tsq_cursor );
$tsq_assignment_policy     = new Assignment_Object_Authorization( $tsq_assignment_repository );
g3_assert(
	$tsq_assignment_policy->authorize( 9, 'teacher_submission.list', $tsq_context, new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $tsq_assignment_id, 'view_submissions' ) )->is_allowed(),
	'Q13 assignment object policy must allow read-only submission monitoring.'
);

$tsq_original_receipt_hash = $tsq_database->tables['oj_ai_submission_receipts'][0]['content_hash'];
$tsq_database->tables['oj_ai_submission_receipts'][0]['content_hash'] = hash( 'sha256', 'tampered-teacher-receipt' );
g3_failure(
	static fn () => $tsq_reader->detail( $tsq_official_submission, 'class', 'class-21', 'class-r3' ),
	Assignment_Failure::STORED_DATA_INVALID,
	'Q14 must fail closed when immutable receipt evidence is tampered.'
);
$tsq_database->tables['oj_ai_submission_receipts'][0]['content_hash'] = $tsq_original_receipt_hash;

$tsq_controller_reflection = new ReflectionClass( Teacher_Submission_Rest_Controller::class );
$tsq_controller            = $tsq_controller_reflection->newInstanceWithoutConstructor();
$tsq_path                  = $tsq_controller_reflection->getMethod( 'path_id' );
g3_same( $tsq_official_submission, $tsq_path->invoke( $tsq_controller, new G3_Teacher_Request( array( 'submission_id' => $tsq_official_submission ) ), 'submission_id' ), 'Q14 path must preserve the public submission ID.' );
g3_invalid( static fn () => $tsq_path->invoke( $tsq_controller, new G3_Teacher_Request( array( 'submission_id' => '31' ) ), 'submission_id' ), 'Q14 must reject numeric internal submission IDs.' );

$tsq_sources        = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Teacher/Infrastructure/WordPress_Teacher_Submission_Query.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Teacher/Presentation/Teacher_Submission_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Submission_Object_Authorization.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Assignment/Infrastructure/Assignment_Object_Authorization.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
$tsq_joined_sources = implode( "\n", $tsq_sources );
foreach ( array( '/assignments/(?P<assignment_id>', '/submissions/(?P<submission_id>', 'teacher_submission.list', 'teacher_submission.view', 'teacher_submission.history', 'MANAGE_SUBMISSIONS', 'OPERATION_MANAGE' ) as $marker ) {
	g3_assert( str_contains( $tsq_joined_sources, $marker ), 'Q13-Q15 route or authorization marker is missing: ' . $marker );
}
foreach ( array( 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_submissions', 'oj_ai_submission_revisions', 'oj_ai_submission_receipts', 'oj_ai_submission_attachments' ) as $table ) {
	g3_assert( str_contains( $tsq_sources[0], $table ), 'Q13-Q15 literal table marker is missing: ' . $table );
}
g3_assert( ! str_contains( $tsq_sources[0], 'a.target_count' ), 'Q13 must not read the non-existent oj_ai_assignments.target_count column.' );
g3_assert( str_contains( $tsq_sources[0], 'counted_targets.assignment_id = a.id) AS target_count' ), 'Q13 must derive target_count from immutable assignment targets.' );
foreach ( $tsq_sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'G3-IMP-06-S02 must not use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'G3-IMP-06-S02 must not use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'G3-IMP-06-S02 production code must not depend on platform internals.' );
}

echo "G3-IMP-06-S02 passed: Q13 all-target monitoring, Q14 exact teacher detail, Q15 immutable revision/receipt history, Context policy, integrity, and privacy.\n";

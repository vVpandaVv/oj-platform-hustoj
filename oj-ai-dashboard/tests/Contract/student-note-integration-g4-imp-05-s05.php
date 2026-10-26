<?php
/**
 * G4-IMP-05-S05 student-note provenance and visibility integration freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-report-inclusion-g4-imp-05-s04.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion;
use OJAI\Dashboard\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Student_Note;
use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Report_Inclusion_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 s05 note assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_note_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s05 note same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_note_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4-IMP-05-S05 student-note provenance and visibility integration freeze.
 */
final class G4_S05_Student_Note_Repository implements Student_Note_Review_Repository_Port_Interface, Student_Note_Approval_Repository_Port_Interface, Student_Note_Report_Inclusion_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * History.
	 *
	 * @var array<int,array{event:string,record:array<string,mixed>,approved_by:?int,approved_at:?string}>
	 */
	public array $history = array();
	/**
	 * Append count.
	 *
	 * @var int
	 */
	public int $append_count = 0;
	/**
	 * Receipts.
	 *
	 * @var array<string,array<string,array{digest:string,result:Student_Note_Revision_Result}>>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 s05 student note repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find review receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_review_receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
		return $this->receipt( 'review', $key, $digest );
	}
	/**
	 * Find approval receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_approval_receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
		return $this->receipt( 'approval', $key, $digest );
	}
	/**
	 * Find report inclusion receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $target Target.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_report_inclusion_receipt( string $key, string $digest, bool $target ): ?Student_Note_Revision_Result {
		return $this->receipt( $target ? 'include' : 'exclude', $key, $digest );
	}

	/**
	 * Find owned review snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_review_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->owned_snapshot( $actor_user_id, $note_id );
	}
	/**
	 * Find owned approval snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_approval_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->owned_snapshot( $actor_user_id, $note_id );
	}
	/**
	 * Find owned report inclusion snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_report_inclusion_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->owned_snapshot( $actor_user_id, $note_id );
	}

	/**
	 * Append review revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_review_revision( array $record ): Student_Note_Revision_Result {
		return $this->append( 'review', $record );
	}
	/**
	 * Append approved revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_approved_revision( array $record ): Student_Note_Revision_Result {
		return $this->append( 'approval', $record );
	}
	/**
	 * Append report inclusion revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_report_inclusion_revision( array $record ): Student_Note_Revision_Result {
		return $this->append( $record['include_in_reports'] ? 'include' : 'exclude', $record );
	}

	/**
	 * Owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	private function owned_snapshot( int $actor_user_id, string $note_id ): ?array {
		if ( $actor_user_id < 1 ) {
			return null;
		}
		return hash_equals( (string) $this->snapshot['note_root_id'], $note_id ) ? $this->snapshot : null;
	}

	/**
	 * Receipt.
	 *
	 * @param string $event Event.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $event, string $key, string $digest ): ?Student_Note_Revision_Result {
		if ( ! isset( $this->receipts[ $event ][ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $event ][ $key ];
		if ( ! hash_equals( $receipt['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $receipt['result'];
		return new Student_Note_Revision_Result(
			$result->note_id(),
			$result->evaluation_id(),
			$result->official_review_id(),
			$result->official_review_hash(),
			$result->revision_id(),
			$result->revision_hash(),
			$result->revision_no(),
			$result->state(),
			$result->include_in_reports(),
			$result->previous_revision_id(),
			$result->source_evaluation_run_id(),
			$result->occurred_at_utc(),
			true
		);
	}

	/**
	 * Append.
	 *
	 * @param string              $event Event.
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append( string $event, array $record ): Student_Note_Revision_Result {
		$this->assert_locked_source( $record );
		if ( 'approval' === $event && ( 'teacher_draft' !== $this->snapshot['latest_state'] || $record['include_in_reports'] ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE );
		}
		if ( in_array( $event, array( 'include', 'exclude' ), true ) ) {
			if (
				'approved' !== $this->snapshot['latest_state']
				|| $record['include_in_reports'] === $this->snapshot['latest_include_in_reports']
				|| ! hash_equals( (string) $this->snapshot['latest_note_json'], (string) $record['note_json'] )
				|| ! hash_equals( (string) $this->snapshot['latest_note_hash'], (string) $record['note_hash'] )
			) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE );
			}
		}

		$approved_by = null;
		$approved_at = null;
		if ( 'approval' === $event ) {
			$approved_by = $record['actor_user_id'];
			$approved_at = $record['occurred_at_utc'];
		} elseif ( in_array( $event, array( 'include', 'exclude' ), true ) ) {
			$approved_by = $this->snapshot['latest_approved_by'];
			$approved_at = $this->snapshot['latest_approved_at'];
		}

		$result          = new Student_Note_Revision_Result(
			$record['note_root_id'],
			$record['evaluation_public_id'],
			$record['expected_official_review_id'],
			$record['expected_official_review_hash'],
			$record['revision_public_id'],
			$record['content_hash'],
			$record['revision_no'],
			$record['state'],
			$record['include_in_reports'],
			$record['previous_revision_id'],
			$record['source_evaluation_run_public_id'],
			$record['occurred_at_utc'],
			false
		);
		$this->history[] = array(
			'event'       => $event,
			'record'      => $record,
			'approved_by' => $approved_by,
			'approved_at' => $approved_at,
		);
		++$this->append_count;
		$this->receipts[ $event ][ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);

		$this->snapshot['latest_revision_id']        = $record['revision_public_id'];
		$this->snapshot['latest_revision_hash']      = $record['content_hash'];
		$this->snapshot['latest_revision_no']        = $record['revision_no'];
		$this->snapshot['latest_state']              = $record['state'];
		$this->snapshot['latest_include_in_reports'] = $record['include_in_reports'];
		$this->snapshot['latest_source_run_id']      = $record['source_evaluation_run_public_id'];
		$this->snapshot['latest_note_json']          = $record['note_json'];
		$this->snapshot['latest_note_hash']          = $record['note_hash'];
		$this->snapshot['latest_approved_by']        = $approved_by;
		$this->snapshot['latest_approved_at']        = $approved_at;
		return $result;
	}

	/**
	 * Reject invalid locked source.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_locked_source( array $record ): void {
		$matches =
			hash_equals( (string) $this->snapshot['note_root_id'], (string) $record['note_root_id'] )
			&& hash_equals( (string) $this->snapshot['evaluation_id'], (string) $record['evaluation_public_id'] )
			&& $this->snapshot['evaluation_lock_version'] === $record['expected_evaluation_lock_version']
			&& $this->snapshot['evaluation_state'] === $record['expected_evaluation_state']
			&& hash_equals( (string) $this->snapshot['official_review_id'], (string) $record['expected_official_review_id'] )
			&& hash_equals( (string) $this->snapshot['official_review_hash'], (string) $record['expected_official_review_hash'] )
			&& $this->snapshot['latest_revision_id'] === $record['expected_revision_id']
			&& $this->snapshot['latest_revision_hash'] === $record['expected_revision_hash']
			&& $this->snapshot['latest_revision_no'] === $record['expected_revision_no']
			&& $this->snapshot['latest_state'] === $record['expected_note_state']
			&& $this->snapshot['latest_include_in_reports'] === $record['expected_include_in_reports']
			&& $this->snapshot['latest_source_run_id'] === $record['expected_source_run_id'];
		if ( ! $matches ) {
			throw Submission_Evaluation_Failure::because(
				Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
				array(
					'current_include_in_reports' => (int) $this->snapshot['latest_include_in_reports'],
					'current_revision_hash'      => (string) $this->snapshot['latest_revision_hash'],
					'current_revision_id'        => (string) $this->snapshot['latest_revision_id'],
					'current_revision_no'        => (int) $this->snapshot['latest_revision_no'],
					'current_state'              => (string) $this->snapshot['latest_state'],
				)
			);
		}
	}
}

/**
 * G4 s05 approval command.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param int                 $actor Actor.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Approve_Student_Note_Command
 */
function g4_s05_approval_command( array $snapshot, Canonical_Json $canonical, int $actor, string $command_id, string $fingerprint ): Approve_Student_Note_Command {
	return new Approve_Student_Note_Command(
		$actor,
		$snapshot['note_root_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_revision_id'],
		$snapshot['latest_revision_hash'],
		$snapshot['latest_revision_no'],
		'teacher_draft',
		false,
		$fingerprint,
		'correlation-g4-s05-approval',
		$snapshot['context_type'],
		$snapshot['context_id'],
		$snapshot['context_revision'],
		$snapshot['latest_revision_hash'],
		'teacher_draft',
		'context_staff',
		$canonical
	);
}

/**
 * G4 s05 report command.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param int                 $actor Actor.
 * @param bool                $target Target.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Change_Student_Note_Report_Inclusion_Command
 */
function g4_s05_report_command( array $snapshot, Canonical_Json $canonical, int $actor, bool $target, string $command_id, string $fingerprint ): Change_Student_Note_Report_Inclusion_Command {
	return new Change_Student_Note_Report_Inclusion_Command(
		$actor,
		$snapshot['note_root_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_revision_id'],
		$snapshot['latest_revision_hash'],
		$snapshot['latest_revision_no'],
		'approved',
		$snapshot['latest_include_in_reports'],
		$target,
		$fingerprint,
		'correlation-g4-s05-report',
		$snapshot['context_type'],
		$snapshot['context_id'],
		$snapshot['context_revision'],
		$snapshot['latest_revision_hash'],
		'approved',
		'context_staff',
		$canonical
	);
}

$s05_canonical                         = new Canonical_Json();
$s05_source                            = g4_note_source( $s05_canonical, 3 );
$s05_gateway                           = ( new Student_Note_Input_Builder( $s05_canonical ) )->build( $s05_source )->gateway_input();
$s05_ai_profile                        = ( new Student_Note_Output_Normalizer( $s05_canonical ) )->normalize( g4_note_output( $s05_gateway ), $s05_gateway );
$s05_ai_revision_id                    = g4_note_id();
$s05_ai_run_id                         = g4_note_id();
$s05_ai_snapshot                       = g4_note_revision_snapshot(
	$s05_source,
	$s05_ai_revision_id,
	str_repeat( '1', 64 ),
	1,
	'ai_draft',
	$s05_ai_run_id,
	$s05_ai_profile->profile_json(),
	$s05_ai_profile->profile_hash()
);
$s05_ai_snapshot['latest_approved_by'] = null;
$s05_ai_snapshot['latest_approved_at'] = null;
$s05_repository                        = new G4_S05_Student_Note_Repository( $s05_ai_snapshot );
$s05_sources                           = new G4_Note_Repository( $s05_source );
$s05_ids                               = new Public_Id_Generator();
$s05_outputs                           = new Student_Note_Output_Normalizer( $s05_canonical );

$s05_teacher_note                    = g4_note_teacher_payload( $s05_gateway );
$s05_teacher_note['summary']['text'] = 'The submitted work explains the selected approach and links it to the recorded evidence.';
$s05_review                          = new Review_Student_Note(
	$s05_sources,
	$s05_repository,
	new Student_Note_Input_Builder( $s05_canonical ),
	$s05_outputs,
	$s05_ids,
	$s05_canonical,
	static fn (): string => '2026-08-24 22:00:00'
);
$s05_review_command                  = g4_note_review_command(
	$s05_source,
	$s05_ai_snapshot,
	$s05_canonical,
	'teacher_draft',
	'edit',
	$s05_teacher_note,
	null,
	'command-g4-s05-review',
	str_repeat( '1', 64 )
);
$s05_teacher                         = $s05_review->execute( $s05_review_command );

$s05_approval_snapshot = $s05_repository->snapshot;
$s05_approval          = new Approve_Student_Note(
	$s05_sources,
	$s05_repository,
	$s05_outputs,
	$s05_ids,
	$s05_canonical,
	static fn (): string => '2026-08-24 22:01:00'
);
$s05_approval_command  = g4_s05_approval_command( $s05_approval_snapshot, $s05_canonical, 17, 'command-g4-s05-approval', str_repeat( '2', 64 ) );
$s05_approved          = $s05_approval->execute( $s05_approval_command );

$s05_include_snapshot = $s05_repository->snapshot;
$s05_report           = new Change_Student_Note_Report_Inclusion(
	$s05_sources,
	$s05_repository,
	$s05_outputs,
	$s05_ids,
	$s05_canonical,
	static fn (): string => '2026-08-24 22:02:00'
);
$s05_include_command  = g4_s05_report_command( $s05_include_snapshot, $s05_canonical, 42, true, 'command-g4-s05-include', str_repeat( '3', 64 ) );
$s05_included         = $s05_report->execute( $s05_include_command );

$s05_exclude_snapshot = $s05_repository->snapshot;
$s05_report_exclude   = new Change_Student_Note_Report_Inclusion(
	$s05_sources,
	$s05_repository,
	$s05_outputs,
	$s05_ids,
	$s05_canonical,
	static fn (): string => '2026-08-24 22:03:00'
);
$s05_exclude_command  = g4_s05_report_command( $s05_exclude_snapshot, $s05_canonical, 43, false, 'command-g4-s05-exclude', str_repeat( '4', 64 ) );
$s05_excluded         = $s05_report_exclude->execute( $s05_exclude_command );

g4_s05_note_same( array( 'review', 'approval', 'include', 'exclude' ), array_column( $s05_repository->history, 'event' ), 'C39-C42 event order drifted.' );
g4_s05_note_same( array( 2, 3, 4, 5 ), array_map( static fn ( array $item ): int => $item['record']['revision_no'], $s05_repository->history ), 'C39-C42 revision sequence drifted.' );
g4_s05_note_same( array( false, false, true, false ), array_map( static fn ( array $item ): bool => $item['record']['include_in_reports'], $s05_repository->history ), 'Report inclusion must remain opt-in and reversible.' );
g4_s05_note_same( $s05_ai_revision_id, $s05_teacher->previous_revision_id(), 'C39 must link to the exact AI draft.' );
g4_s05_note_same( $s05_teacher->revision_id(), $s05_approved->previous_revision_id(), 'C40 must link to the exact teacher draft.' );
g4_s05_note_same( $s05_approved->revision_id(), $s05_included->previous_revision_id(), 'C41 must link to the exact excluded approval.' );
g4_s05_note_same( $s05_included->revision_id(), $s05_excluded->previous_revision_id(), 'C42 must link to the exact included approval.' );

$s05_teacher_record = $s05_repository->history[0]['record'];
foreach ( $s05_repository->history as $index => $item ) {
	g4_s05_note_same( $s05_source['official_review_id'], $item['record']['expected_official_review_id'], 'Official review ID provenance drifted at integrated revision ' . $index . '.' );
	g4_s05_note_same( $s05_source['official_review_hash'], $item['record']['expected_official_review_hash'], 'Official review hash provenance drifted at integrated revision ' . $index . '.' );
	g4_s05_note_same( $s05_ai_run_id, $item['record']['source_evaluation_run_public_id'], 'AI run provenance drifted at integrated revision ' . $index . '.' );
}
g4_s05_note_assert( ! hash_equals( $s05_ai_profile->profile_hash(), $s05_teacher_record['note_hash'] ), 'C39 edit fixture must produce a distinct teacher profile.' );
foreach ( array_slice( $s05_repository->history, 1 ) as $item ) {
	g4_s05_note_same( $s05_teacher_record['note_json'], $item['record']['note_json'], 'C40-C42 must copy the exact teacher profile JSON.' );
	g4_s05_note_same( $s05_teacher_record['note_hash'], $item['record']['note_hash'], 'C40-C42 must preserve the exact teacher profile hash.' );
}
g4_s05_note_same( 17, $s05_repository->history[1]['approved_by'], 'C40 must record the approving educator.' );
g4_s05_note_same( 17, $s05_repository->history[2]['approved_by'], 'C41 must preserve the original approving educator.' );
g4_s05_note_same( 17, $s05_repository->history[3]['approved_by'], 'C42 must preserve the original approving educator.' );
g4_s05_note_same( 42, $s05_repository->history[2]['record']['actor_user_id'], 'C41 audit actor fixture drifted.' );
g4_s05_note_same( 43, $s05_repository->history[3]['record']['actor_user_id'], 'C42 audit actor fixture drifted.' );
g4_s05_note_same( false, $s05_repository->snapshot['latest_include_in_reports'], 'C42 must affect only the new latest revision.' );

foreach (
	array(
		$s05_review->execute( $s05_review_command ),
		$s05_approval->execute( $s05_approval_command ),
		$s05_report->execute( $s05_include_command ),
		$s05_report_exclude->execute( $s05_exclude_command ),
	) as $replay
) {
	g4_s05_note_assert( $replay->replayed(), 'A completed C39-C42 command must replay before current-source comparison.' );
}
g4_s05_note_same( 4, $s05_repository->append_count, 'Durable C39-C42 replay must append no extra revisions.' );

$s05_controller_files   = (array) glob( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_*_Rest_Controller.php' );
$s05_controller_files[] = $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Rest_Controller.php';
$s05_controller_files   = array_values( array_unique( $s05_controller_files ) );
g4_s05_note_same( 6, count( ( $s05_controller_files ? $s05_controller_files : array() ) ), 'The frozen student-note REST boundary must contain six Console controllers.' );
foreach ( ( $s05_controller_files ? $s05_controller_files : array() ) as $controller_file ) {
	$source = (string) file_get_contents( $controller_file );
	g4_s05_note_assert( str_contains( $source, 'Access_Policy_Request::SHELL_CONSOLE' ), 'Every student-note route must remain in OJ Console.' );
	g4_s05_note_assert( ! str_contains( $source, 'SHELL_PUBLIC' ) && ! str_contains( $source, '/me/' ), 'Student-note routes must not expose a student/PublicUI endpoint.' );
}
$s05_query_source         = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Query_Rest_Controller.php' );
$s05_repository_source    = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Review_Repository.php' );
$s05_query_contract       = (string) file_get_contents( $addon_root . '/tests/Contract/student-note-query-g4-imp-05-s02.php' );
$s05_review_rest_contract = (string) file_get_contents( $addon_root . '/tests/Contract/student-note-review-approval-rest-g4-imp-05-s03.php' );
$s05_report_rest_contract = (string) file_get_contents( $addon_root . '/tests/Contract/student-note-report-inclusion-rest-g4-imp-05-s04.php' );
require_once dirname( __DIR__ ) . '/Support/class-source-contract-tokens.php';
g4_s05_note_assert( 2 === \OJAI\Dashboard\Tests\Support\Source_Contract_Tokens::array_string_value_count( $s05_query_source, 'methods', 'GET' ), 'Q18/Q19 must remain exactly two teacher GET routes.' );
foreach ( array( 'student_visible', 'report_auto_inclusion', 'zero student routes are frozen' ) as $marker ) {
	g4_s05_note_assert( str_contains( $s05_query_contract, $marker ), 'Student non-exposure regression marker is missing: ' . $marker );
}
foreach ( array( $s05_review_rest_contract, $s05_report_rest_contract ) as $contract ) {
	g4_s05_note_assert( str_contains( $contract, 'Permission revocation must deny' ) && str_contains( $contract, '403' ), 'Permission revocation regression must remain active for C40 and C42.' );
}
g4_s05_note_assert( str_contains( $s05_repository_source, 'START TRANSACTION' ) && str_contains( $s05_repository_source, 'FOR UPDATE' ), 'C39-C42 must share one transactional locked repository.' );
g4_s05_note_assert( 1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_student_note_revisions\b/i', $s05_repository_source ), 'Student-note revision history must remain append-only.' );
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_s05_note_assert( ! str_contains( $s05_repository_source . $s05_query_source, $forbidden ), 'S05 runtime boundary contains a forbidden marker: ' . $forbidden );
}

fwrite( STDOUT, "G4-IMP-05-S05 application integration passed: C39-C42 provenance, original approval metadata, explicit report opt-in, durable replay, Console-only visibility, and append-only history are frozen.\n" );

<?php
/**
 * G4-IMP-05-S04 C41/C42 student-note report inclusion application contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

ob_start();
require_once __DIR__ . '/student-note-review-approval-g4-imp-05-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion;
use OJAI\Dashboard\Submission\Assessment\Application\Change_Student_Note_Report_Inclusion_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Report_Inclusion_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4-IMP-05-S04 C41/C42 student-note report inclusion application contract.
 */
final class G4_Student_Note_Report_Inclusion_Repository implements Student_Note_Report_Inclusion_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Append count.
	 *
	 * @var int
	 */
	public int $append_count = 0;
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,target:bool,result:Student_Note_Revision_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 student note report inclusion repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find report inclusion receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $target Target.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_report_inclusion_receipt( string $key, string $digest, bool $target ): ?Student_Note_Revision_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( $receipt['target'] !== $target || ! hash_equals( $receipt['digest'], $digest ) ) {
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
	 * Find owned report inclusion snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_report_inclusion_snapshot( int $actor_user_id, string $note_id ): ?array {
		unset( $actor_user_id );
		return $note_id === $this->snapshot['note_root_id'] ? $this->snapshot : null;
	}

	/**
	 * Append report inclusion revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_report_inclusion_revision( array $record ): Student_Note_Revision_Result {
		$this->record = $record;
		++$this->append_count;
		$result = new Student_Note_Revision_Result(
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
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'target' => $record['include_in_reports'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4 note report command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param bool                $target Target.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Change_Student_Note_Report_Inclusion_Command
 */
function g4_note_report_command( array $source, array $snapshot, Canonical_Json $canonical, bool $target, string $command_id, string $fingerprint ): Change_Student_Note_Report_Inclusion_Command {
	return new Change_Student_Note_Report_Inclusion_Command(
		42,
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
		'correlation-g4-note-report-inclusion',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$snapshot['latest_revision_hash'],
		'approved',
		'context_staff',
		$canonical
	);
}

/**
 * G4 note report application.
 *
 * @param array<string,mixed>                         $source Source.
 * @param G4_Student_Note_Report_Inclusion_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                              $canonical Canonical JSON encoder used for stable digests.
 * @param string                                      $time Time.
 * @return Change_Student_Note_Report_Inclusion
 */
function g4_note_report_application( array $source, G4_Student_Note_Report_Inclusion_Repository $repository, Canonical_Json $canonical, string $time ): Change_Student_Note_Report_Inclusion {
	return new Change_Student_Note_Report_Inclusion(
		new G4_Note_Repository( $source ),
		$repository,
		new Student_Note_Output_Normalizer( $canonical ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => $time
	);
}

/**
 * G4 note approved snapshot.
 *
 * @param array<string,mixed> $source Source.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param bool                $included Included.
 * @return array<string,mixed>
 */
function g4_note_approved_snapshot( array $source, Canonical_Json $canonical, bool $included ): array {
	$gateway                               = ( new Student_Note_Input_Builder( $canonical ) )->build( $source )->gateway_input();
	$profile                               = ( new Student_Note_Output_Normalizer( $canonical ) )->normalize( g4_note_output( $gateway ), $gateway );
	$snapshot                              = g4_note_revision_snapshot( $source, g4_note_id(), str_repeat( $included ? '2' : '1', 64 ), 2, 'approved', g4_note_id(), $profile->profile_json(), $profile->profile_hash() );
	$snapshot['latest_include_in_reports'] = $included;
	$snapshot['latest_approved_by']        = 17;
	$snapshot['latest_approved_at']        = '2026-08-24 20:30:00';
	return $snapshot;
}

$report_canonical    = new Canonical_Json();
$report_source       = g4_note_source( $report_canonical, 3 );
$excluded_snapshot   = g4_note_approved_snapshot( $report_source, $report_canonical, false );
$include_repository  = new G4_Student_Note_Report_Inclusion_Repository( $excluded_snapshot );
$include_application = g4_note_report_application( $report_source, $include_repository, $report_canonical, '2026-08-24 20:31:00' );
$include_command     = g4_note_report_command( $report_source, $excluded_snapshot, $report_canonical, true, 'command-g4-note-include', str_repeat( '1', 64 ) );
$included            = $include_application->execute( $include_command );

g4_note_same( 'approved', $included->state(), 'C41 must keep the copied revision approved.' );
g4_note_same( true, $included->include_in_reports(), 'C41 must append an included revision.' );
g4_note_same( 3, $included->revision_no(), 'C41 must increment the exact approved revision.' );
g4_note_same( $excluded_snapshot['latest_revision_id'], $included->previous_revision_id(), 'C41 must link to the exact excluded approval.' );
g4_note_same( $excluded_snapshot['latest_source_run_id'], $included->source_evaluation_run_id(), 'C41 must preserve AI provenance.' );
g4_note_same( $excluded_snapshot['latest_note_json'], $include_repository->record['note_json'] ?? null, 'C41 must copy the approved canonical profile exactly.' );
g4_note_same( $excluded_snapshot['latest_note_hash'], $include_repository->record['note_hash'] ?? null, 'C41 must preserve the approved profile hash.' );
g4_note_same( 'include_in_reports', $include_repository->record['source_mode'] ?? null, 'C41 source mode drifted.' );

$include_replay = $include_application->execute( $include_command );
g4_note_same( true, $include_replay->replayed(), 'An exact C41 retry must replay its durable receipt.' );
g4_note_same( 1, $include_repository->append_count, 'An exact C41 retry must append no second revision.' );
g4_note_revision_failure(
	static fn () => $include_application->execute( g4_note_report_command( $report_source, $excluded_snapshot, $report_canonical, true, 'command-g4-note-include-changed', str_repeat( '1', 64 ) ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'A changed C41 request under one idempotency key must conflict.'
);

$already_included                              = $excluded_snapshot;
$already_included['latest_include_in_reports'] = true;
$stale_repository                              = new G4_Student_Note_Report_Inclusion_Repository( $already_included );
g4_note_revision_failure(
	static fn () => g4_note_report_application( $report_source, $stale_repository, $report_canonical, '2026-08-24 20:32:00' )->execute( g4_note_report_command( $report_source, $excluded_snapshot, $report_canonical, true, 'command-g4-note-include-stale', str_repeat( '3', 64 ) ) ),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'C41 must reject a current inclusion state that changed after authorization.'
);

$corrupt_snapshot                     = $excluded_snapshot;
$corrupt_snapshot['latest_note_json'] = '{}';
$corrupt_snapshot['latest_note_hash'] = hash( 'sha256', '{}' );
$corrupt_repository                   = new G4_Student_Note_Report_Inclusion_Repository( $corrupt_snapshot );
g4_note_revision_failure(
	static fn () => g4_note_report_application( $report_source, $corrupt_repository, $report_canonical, '2026-08-24 20:33:00' )->execute( g4_note_report_command( $report_source, $corrupt_snapshot, $report_canonical, true, 'command-g4-note-include-corrupt', str_repeat( '4', 64 ) ) ),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'C41 must revalidate the copied approved profile.'
);

$included_snapshot  = g4_note_approved_snapshot( $report_source, $report_canonical, true );
$exclude_repository = new G4_Student_Note_Report_Inclusion_Repository( $included_snapshot );
$excluded           = g4_note_report_application( $report_source, $exclude_repository, $report_canonical, '2026-08-24 20:34:00' )->execute(
	g4_note_report_command( $report_source, $included_snapshot, $report_canonical, false, 'command-g4-note-exclude', str_repeat( '5', 64 ) )
);
g4_note_same( 'approved', $excluded->state(), 'C42 must keep the copied revision approved.' );
g4_note_same( false, $excluded->include_in_reports(), 'C42 must append an excluded revision.' );
g4_note_same( 'exclude_from_reports', $exclude_repository->record['source_mode'] ?? null, 'C42 source mode drifted.' );
g4_note_same( $included_snapshot['latest_note_json'], $exclude_repository->record['note_json'] ?? null, 'C42 must not rewrite the approved note body.' );

$invalid_target_failed = false;
try {
	g4_note_report_command( $report_source, $excluded_snapshot, $report_canonical, false, 'command-g4-note-invalid-target', str_repeat( '6', 64 ) ); } catch ( InvalidArgumentException ) {
	$invalid_target_failed = true;
	}
	g4_note_assert( $invalid_target_failed, 'C41/C42 command must reject a no-op inclusion target.' );

	$repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Review_Repository.php' );
	$rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Report_Inclusion_Rest_Controller.php' );
	$root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
	g4_note_assert( str_contains( $repository_source, "'student_note.included_in_reports'" ) && str_contains( $repository_source, "'student_note.excluded_from_reports'" ), 'C41/C42 audit and outbox events drifted.' );
	g4_note_assert( Ojai_Php_Source_Contract::contains( $repository_source, "'include_in_reports' => \$record['include_in_reports'] ? 1 : 0" ) && ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'C41/C42 storage must use literal tables and the exact target value.' );
	g4_note_assert( str_contains( $rest_source, '/commands/include-in-reports' ) && str_contains( $rest_source, '/commands/exclude-from-reports' ) && str_contains( $rest_source, 'array() !== $payload' ), 'C41/C42 strict REST routes drifted.' );
	g4_note_assert( str_contains( $root_source, 'new Student_Note_Report_Inclusion_Rest_Module' ) && str_contains( $root_source, 'new Change_Student_Note_Report_Inclusion' ), 'Composition Root must activate C41/C42.' );

	fwrite( STDOUT, "G4-IMP-05-S04 C41/C42 exact include, exclude, profile copy, provenance, replay, stale-source, and safety contracts passed.\n" );

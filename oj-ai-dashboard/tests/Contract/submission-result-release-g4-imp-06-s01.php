<?php
/**
 * G4-IMP-06-S01 C43 first student result release contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-approval-g4-imp-04-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Release_Submission_Result_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Release_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Release_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 release assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_release_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G4 release same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_release_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G4 release failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_release_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Submission_Evaluation_Failure $failure ) {
		g4_release_same( $reason, $failure->reason(), $message );
		return;
	} throw new RuntimeException( $message );
}

/**
 * G4-IMP-06-S01 C43 first student result release contract.
 */
final class G4_Result_Release_Repository implements Submission_Result_Release_Repository_Port_Interface {
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
	 * @var array<string,array{digest:string,result:Submission_Result_Release_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 result release repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Submission_Result_Release_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Submission_Result_Release_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $this->receipts[ $key ]['result'];
		return new Submission_Result_Release_Result( $result->evaluation_id(), $result->official_review_id(), $result->official_review_hash(), $result->feedback_revision_id(), $result->release_id(), $result->release_hash(), $result->release_no(), $result->state(), $result->projection_hash(), $result->visible_score(), $result->released_at_utc(), true );
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return $actor_user_id > 0 && $evaluation_id === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
	}

	/**
	 * Append release.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Release_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_release( array $record ): Submission_Result_Release_Result {
		if ( $record['expected_current_release_id'] !== $this->snapshot['current_release_id'] || $record['expected_current_release_hash'] !== $this->snapshot['current_release_hash'] || $record['expected_official_review_id'] !== $this->snapshot['official_review_id'] || $record['expected_official_review_hash'] !== $this->snapshot['official_review_hash'] ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE );
		}
		$this->record = $record;
		++$this->append_count;
		$result = new Submission_Result_Release_Result( $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['expected_feedback_revision_id'], $record['release_public_id'], $record['content_hash'], $record['release_no'], $record['state'], $record['projection_hash'], $record['visible_score'], $record['released_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		$this->snapshot['current_release_id']             = $record['release_public_id'];
		$this->snapshot['current_release_hash']           = $record['content_hash'];
		$this->snapshot['current_release_no']             = $record['release_no'];
		$this->snapshot['current_release_state']          = $record['state'];
		$this->snapshot['current_projection_hash']        = $record['projection_hash'];
		$this->snapshot['release_count']                  = $record['release_no'];
		++$this->snapshot['submission_lock_version'];
		return $result;
	}
}

/**
 * G4 release fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{source:array<string,mixed>,snapshot:array<string,mixed>}
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_release_fixture( Canonical_Json $canonical ): array {
	$fixture             = g4_feedback_approval_fixture( $canonical, true );
	$approval_repository = new G4_Feedback_Approval_Repository( $fixture['snapshot'] );
	$approval            = new Approve_Submission_Feedback( new G4_Feedback_Review_Source( $fixture['source'] ), $approval_repository, new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 23:00:00' );
	$approved            = $approval->execute( g4_feedback_approval_command( $fixture['source'], $fixture['snapshot'], $canonical, 'command-g4-release-source-approval', str_repeat( '8', 64 ) ) );
	$record              = $approval_repository->record;
	if ( null === $record ) {
		throw new RuntimeException( 'C43 approved feedback fixture was not recorded.' );
	}
	$source = $fixture['source'];
	return array(
		'source'   => $source,
		'snapshot' => array(
			'evaluation_id'                 => $source['evaluation_id'],
			'evaluation_lock_version'       => $source['evaluation_lock_version'],
			'evaluation_state'              => 'reviewed',
			'submission_lock_version'       => $source['submission_lock_version'],
			'context_type'                  => $source['context_type'],
			'context_id'                    => $source['context_id'],
			'context_revision'              => $source['context_revision'],
			'worksheet_title'               => 'Fraction comparison worksheet',
			'official_review_id'            => $source['official_review_id'],
			'official_review_hash'          => $source['official_review_hash'],
			'latest_feedback_pk'            => 81,
			'latest_feedback_revision_id'   => $approved->feedback_revision_id(),
			'latest_feedback_revision_hash' => $approved->feedback_revision_hash(),
			'latest_feedback_state'         => 'approved',
			'latest_feedback_review_id'     => $source['official_review_id'],
			'latest_feedback_schema'        => $record['feedback_schema'],
			'latest_feedback_version'       => $record['feedback_version'],
			'latest_feedback_json'          => $record['feedback_json'],
			'latest_feedback_profile_hash'  => $record['feedback_hash'],
			'current_release_pk'            => null,
			'current_release_id'            => null,
			'current_release_hash'          => null,
			'current_release_no'            => 0,
			'current_release_state'         => null,
			'current_projection_hash'       => null,
			'release_count'                 => 0,
		),
	);
}

/**
 * G4 release command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @param string              $score Score.
 * @param string              $feedback Feedback.
 * @param string              $mode Mode.
 * @param string|null         $reason_code Reason code.
 * @param string|null         $reason_note Reason note.
 * @return Release_Submission_Result_Command
 */
function g4_release_command( array $source, array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-release', string $fingerprint = '', string $score = 'total_and_criteria', string $feedback = 'shown', string $mode = 'release', ?string $reason_code = null, ?string $reason_note = null ): Release_Submission_Result_Command {
	return new Release_Submission_Result_Command(
		42,
		$source['evaluation_id'],
		$command_id,
		$mode,
		$score,
		$feedback,
		$source['official_review_id'],
		$source['official_review_hash'],
		'shown' === $feedback ? $snapshot['latest_feedback_revision_id'] : null,
		'shown' === $feedback ? $snapshot['latest_feedback_revision_hash'] : null,
		'correction' === ( $mode ) ? $snapshot['current_release_id'] : null,
		'correction' === ( $mode ) ? $snapshot['current_release_hash'] : null,
		'' === $fingerprint ? str_repeat( '9', 64 ) : $fingerprint,
		'correlation-g4-release',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		(string) $source['evaluation_lock_version'],
		'reviewed',
		'context_staff',
		$canonical,
		$reason_code,
		$reason_note
	);
}

/**
 * G4 release application.
 *
 * @param array<string,mixed>          $source Source.
 * @param G4_Result_Release_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
 * @param string                       $time Time.
 * @return Release_Submission_Result
 */
function g4_release_application( array $source, G4_Result_Release_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 23:10:00' ): Release_Submission_Result {
	return new Release_Submission_Result( new G4_Feedback_Review_Source( $source ), $repository, new Submission_Result_Projection_Builder( new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => $time );
}

$release_canonical   = new Canonical_Json();
$release_fixture     = g4_release_fixture( $release_canonical );
$release_source      = $release_fixture['source'];
$release_snapshot    = $release_fixture['snapshot'];
$release_repository  = new G4_Result_Release_Repository( $release_snapshot );
$release_application = g4_release_application( $release_source, $release_repository, $release_canonical );
$release_command     = g4_release_command( $release_source, $release_snapshot, $release_canonical );
$released            = $release_application->execute( $release_command );

g4_release_same( 'released', $released->state(), 'C43 first release state drifted.' );
g4_release_same( 1, $released->release_no(), 'C43 first release number drifted.' );
g4_release_same( '8.0000', $released->visible_score(), 'C43 visible total must be server-calculated from official criterion decisions.' );
g4_release_same( '/oj-addons/oj-ai-dashboard/results/' . $released->release_id(), $released->release_path(), 'C43 safe deep link drifted.' );
g4_release_same( 1, $release_repository->append_count, 'C43 must append one release.' );
$release_record = $release_repository->record;
if ( null === $release_record ) {
	throw new RuntimeException( 'C43 release record is missing.' );
}
$release_projection = json_decode( $release_record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( Submission_Result_Projection::SCHEMA, $release_projection['schema_id'], 'Student projection schema drifted.' );
g4_release_same( '1.0.0', $release_projection['schema_version'], 'Student projection version drifted.' );
g4_release_same( 'total_and_criteria', $release_projection['score']['visibility'], 'Criterion visibility drifted.' );
g4_release_same( 2, count( $release_projection['score']['criteria'] ), 'Every official criterion must be projected.' );
g4_release_same( 'shown', $release_projection['feedback']['visibility'], 'Approved feedback visibility drifted.' );
g4_release_same( 2, count( $release_projection['feedback']['criteria'] ), 'Every approved criterion feedback item must be projected.' );
$release_json = $release_record['projection_json'];
foreach ( array( 'binding_key', 'teacher_assigned', 'manual_assessment', 'Teacher-only reason', 'The response uses evidence.', 'provider_key', 'model_key', 'student_note', 'safety_flags' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $release_json, $forbidden ), 'Student release projection leaked forbidden data: ' . $forbidden );
}
g4_release_same( hash( 'sha256', $release_json ), $release_record['projection_hash'], 'Student projection hash drifted.' );

$release_replay = $release_application->execute( $release_command );
g4_release_same( true, $release_replay->replayed(), 'Exact C43 retry must replay before current-pointer comparison.' );
g4_release_same( 1, $release_repository->append_count, 'Exact C43 retry must append zero release revisions.' );
g4_release_failure( static fn () => $release_application->execute( g4_release_command( $release_source, $release_snapshot, $release_canonical, 'command-g4-release-changed' ) ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Changed C43 body under one idempotency key must conflict.' );

$hidden_fixture    = g4_release_fixture( $release_canonical );
$hidden_repository = new G4_Result_Release_Repository( $hidden_fixture['snapshot'] );
$hidden            = g4_release_application( $hidden_fixture['source'], $hidden_repository, $release_canonical, '2026-08-24 23:11:00' )->execute( g4_release_command( $hidden_fixture['source'], $hidden_fixture['snapshot'], $release_canonical, 'command-g4-release-score-only', str_repeat( 'a', 64 ), 'total', 'hidden' ) );
$hidden_projection = json_decode( (string) $hidden_repository->record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( null, $hidden->feedback_revision_id(), 'Hidden feedback must not be pinned into a release.' );
g4_release_same( array(), $hidden_projection['feedback']['criteria'], 'Hidden feedback must project no criterion text.' );
g4_release_same( null, $hidden_projection['feedback']['overall_feedback'], 'Hidden feedback must project no overall text.' );
g4_release_same( array(), $hidden_projection['score']['criteria'], 'Total-only visibility must not project criterion scores.' );

$unapproved_fixture                                      = g4_release_fixture( $release_canonical );
$unapproved_fixture['snapshot']['latest_feedback_state'] = 'teacher_draft';
$unapproved_repository                                   = new G4_Result_Release_Repository( $unapproved_fixture['snapshot'] );
g4_release_failure( static fn () => g4_release_application( $unapproved_fixture['source'], $unapproved_repository, $release_canonical )->execute( g4_release_command( $unapproved_fixture['source'], $unapproved_fixture['snapshot'], $release_canonical, 'command-g4-release-unapproved', str_repeat( 'b', 64 ) ) ), Submission_Evaluation_Failure::FEEDBACK_NOT_APPROVED, 'Shown feedback must require an approved exact revision.' );
g4_release_same( 0, $unapproved_repository->append_count, 'Unapproved feedback must append no release.' );

$stale_fixture                                     = g4_release_fixture( $release_canonical );
$stale_command                                     = g4_release_command( $stale_fixture['source'], $stale_fixture['snapshot'], $release_canonical, 'command-g4-release-stale', str_repeat( 'c', 64 ) );
$stale_fixture['snapshot']['official_review_hash'] = str_repeat( 'f', 64 );
$stale_repository                                  = new G4_Result_Release_Repository( $stale_fixture['snapshot'] );
g4_release_failure( static fn () => g4_release_application( $stale_fixture['source'], $stale_repository, $release_canonical )->execute( $stale_command ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'Replaced official review must block C43.' );
g4_release_same( 0, $stale_repository->append_count, 'Stale C43 source must append no release.' );

try {
	g4_release_command( $release_source, $release_snapshot, $release_canonical, 'command-g4-release-empty', str_repeat( 'd', 64 ), 'hidden', 'hidden' );
	throw new RuntimeException( 'C43 must reject an empty student projection.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}

	$release_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Release_Repository.php' );
	$release_rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Result_Release_Rest_Controller.php' );
	$release_root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g4_release_assert( ! str_contains( $release_repository_source . $release_rest_source, $forbidden ), 'C43 boundary contains a forbidden dependency: ' . $forbidden );
	}
	g4_release_assert( str_contains( $release_rest_source, '/commands/release' ) && str_contains( $release_rest_source, 'PUBLISH_FEEDBACK' ) && str_contains( $release_rest_source, "'release'" ), 'C43 frozen route, capability, and object action must remain explicit.' );
	g4_release_assert( str_contains( $release_root_source, 'new Submission_Result_Release_Rest_Module' ), 'Composition Root must activate C43.' );

	fwrite( STDOUT, "G4-IMP-06-S01 C43 application passed: exact official source, approved feedback, allowlist projection, score-only release, stale rejection, and durable replay are frozen.\n" );

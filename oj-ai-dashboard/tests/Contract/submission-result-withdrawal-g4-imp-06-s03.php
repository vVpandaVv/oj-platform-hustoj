<?php
/**
 * G4-IMP-06-S03 C44 immutable student result withdrawal contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-release-g4-imp-06-s01.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Withdrawal_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Result_Command;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4-IMP-06-S03 C44 immutable student result withdrawal contract.
 */
final class G4_Result_Withdrawal_Repository implements Submission_Result_Withdrawal_Repository_Port_Interface {
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
	 * @var array<string,array{digest:string,result:Submission_Result_Withdrawal_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 result withdrawal repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Submission_Result_Withdrawal_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt( string $key, string $digest ): ?Submission_Result_Withdrawal_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $this->receipts[ $key ]['result'];
		return new Submission_Result_Withdrawal_Result( $result->evaluation_id(), $result->official_review_id(), $result->official_review_hash(), $result->feedback_revision_id(), $result->previous_release_id(), $result->release_id(), $result->release_hash(), $result->release_no(), $result->projection_hash(), $result->visible_score(), $result->reason_code(), $result->released_at_utc(), true );
	}

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return $actor_user_id > 0 && $evaluation_id === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
	}

	/**
	 * Append withdrawal.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Withdrawal_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_withdrawal( array $record ): Submission_Result_Withdrawal_Result {
		if (
		$record['expected_current_release_id'] !== $this->snapshot['current_release_id']
		|| $record['expected_current_release_hash'] !== $this->snapshot['current_release_hash']
		|| $record['expected_current_release_state'] !== $this->snapshot['current_release_state']
		|| $record['expected_submission_lock_version'] !== $this->snapshot['submission_lock_version']
		) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE );
		}
		$this->record = $record;
		++$this->append_count;
		$result = new Submission_Result_Withdrawal_Result( $record['evaluation_public_id'], $record['review_id'], $record['review_hash'], $record['feedback_revision_id'], $record['previous_release_id'], $record['release_public_id'], $record['content_hash'], $record['release_no'], $record['projection_hash'], $record['visible_score'], $record['reason_code'], $record['released_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		$this->snapshot['current_release_id']             = $record['release_public_id'];
		$this->snapshot['current_release_hash']           = $record['content_hash'];
		$this->snapshot['current_release_no']             = $record['release_no'];
		$this->snapshot['current_release_state']          = 'withdrawn';
		$this->snapshot['release_count']                  = $record['release_no'];
		++$this->snapshot['submission_lock_version'];
		return $result;
	}
}

/**
 * G4 withdrawal fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{source:array<string,mixed>,snapshot:array<string,mixed>,release_record:array<string,mixed>}
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_withdrawal_fixture( Canonical_Json $canonical ): array {
	$fixture            = g4_release_fixture( $canonical );
	$release_repository = new G4_Result_Release_Repository( $fixture['snapshot'] );
	$release            = g4_release_application( $fixture['source'], $release_repository, $canonical, '2026-08-25 00:00:00' )->execute( g4_release_command( $fixture['source'], $fixture['snapshot'], $canonical, 'command-g4-withdraw-source', str_repeat( '1', 64 ) ) );
	$record             = $release_repository->record;
	if ( null === $record ) {
		throw new RuntimeException( 'C44 source release was not recorded.' );
	}
	$snapshot                                       = $release_repository->snapshot + array();
	$snapshot['current_release_pk']                 = 901;
	$snapshot['current_release_feedback_pk']        = null === $record['expected_feedback_revision_id'] ? null : 81;
	$snapshot['current_release_feedback_id']        = $record['expected_feedback_revision_id'];
	$snapshot['current_release_previous_pk']        = null;
	$snapshot['current_release_previous_id']        = null;
	$snapshot['current_release_projection_schema']  = $record['projection_schema'];
	$snapshot['current_release_projection_version'] = $record['projection_version'];
	$snapshot['current_release_projection_json']    = $record['projection_json'];
	$snapshot['current_release_projection_hash']    = $record['projection_hash'];
	$snapshot['current_release_reason_code']        = null;
	$snapshot['current_release_reason_note']        = null;
	$snapshot['current_release_released_at_utc']    = $record['released_at_utc'];
	$snapshot['current_release_review_pk']          = 71;
	$snapshot['current_release_review_id']          = $record['expected_official_review_id'];
	$snapshot['current_release_review_hash']        = $record['expected_official_review_hash'];
	$snapshot['current_release_visible_score']      = $record['visible_score'];
	$snapshot['current_release_id']                 = $release->release_id();
	$snapshot['current_release_hash']               = $release->release_hash();
	return array(
		'source'         => $fixture['source'],
		'snapshot'       => $snapshot,
		'release_record' => $record,
	);
}

/**
 * G4 withdrawal command.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @param string              $reason_code Reason code.
 * @param string|null         $reason_note Reason note.
 * @return Withdraw_Submission_Result_Command
 */
function g4_withdrawal_command( array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-withdraw', string $fingerprint = '', string $reason_code = 'data_correction', ?string $reason_note = null ): Withdraw_Submission_Result_Command {
	return new Withdraw_Submission_Result_Command(
		42,
		$snapshot['evaluation_id'],
		$command_id,
		$snapshot['current_release_id'],
		$snapshot['current_release_hash'],
		$reason_code,
		$reason_note,
		'' === $fingerprint ? str_repeat( '2', 64 ) : $fingerprint,
		'correlation-g4-withdrawal',
		$snapshot['context_type'],
		$snapshot['context_id'],
		$snapshot['context_revision'],
		(string) $snapshot['evaluation_lock_version'],
		'reviewed',
		'context_staff',
		$canonical
	);
}

/**
 * G4 withdrawal application.
 *
 * @param G4_Result_Withdrawal_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                  $canonical Canonical JSON encoder used for stable digests.
 * @param string                          $time Time.
 * @return Withdraw_Submission_Result
 */
function g4_withdrawal_application( G4_Result_Withdrawal_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-25 00:10:00' ): Withdraw_Submission_Result {
	return new Withdraw_Submission_Result( $repository, new Public_Id_Generator(), $canonical, static fn (): string => $time );
}

$withdrawal_canonical   = new Canonical_Json();
$withdrawal_fixture     = g4_withdrawal_fixture( $withdrawal_canonical );
$withdrawal_original    = $withdrawal_fixture['release_record'];
$withdrawal_repository  = new G4_Result_Withdrawal_Repository( $withdrawal_fixture['snapshot'] );
$withdrawal_application = g4_withdrawal_application( $withdrawal_repository, $withdrawal_canonical );
$withdrawal_command     = g4_withdrawal_command( $withdrawal_fixture['snapshot'], $withdrawal_canonical );
$withdrawn              = $withdrawal_application->execute( $withdrawal_command );

g4_release_same( 'withdrawn', $withdrawn->state(), 'C44 state drifted.' );
g4_release_same( 2, $withdrawn->release_no(), 'C44 must append the next release number.' );
g4_release_same( $withdrawal_fixture['snapshot']['current_release_id'], $withdrawn->previous_release_id(), 'C44 previous release link drifted.' );
g4_release_same( 'data_correction', $withdrawn->reason_code(), 'C44 reason code drifted.' );
g4_release_same( $withdrawal_original['projection_hash'], $withdrawn->projection_hash(), 'C44 must preserve the exact prior projection hash.' );
g4_release_same( $withdrawal_original['visible_score'], $withdrawn->visible_score(), 'C44 must preserve the exact prior visible score.' );
$withdrawal_record = $withdrawal_repository->record;
if ( null === $withdrawal_record ) {
	throw new RuntimeException( 'C44 withdrawal record is missing.' );
}
g4_release_same( $withdrawal_original['projection_json'], $withdrawal_record['projection_json'], 'C44 must copy the prior projection byte for byte.' );
g4_release_same( $withdrawn->release_id(), $withdrawal_repository->snapshot['current_release_id'], 'C44 current pointer did not advance.' );
g4_release_same( 'withdrawn', $withdrawal_repository->snapshot['current_release_state'], 'C44 current pointer state drifted.' );

$withdrawal_replay = $withdrawal_application->execute( $withdrawal_command );
g4_release_same( true, $withdrawal_replay->replayed(), 'Exact C44 retry must replay before withdrawn-state comparison.' );
g4_release_same( 1, $withdrawal_repository->append_count, 'Exact C44 retry must append zero revisions.' );
g4_release_failure( static fn () => $withdrawal_application->execute( g4_withdrawal_command( $withdrawal_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-changed' ) ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Changed C44 body under one idempotency key must conflict.' );

$stale_fixture                                     = g4_withdrawal_fixture( $withdrawal_canonical );
$stale_command                                     = g4_withdrawal_command( $stale_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-stale', str_repeat( '3', 64 ) );
$stale_fixture['snapshot']['current_release_hash'] = str_repeat( 'f', 64 );
$stale_repository                                  = new G4_Result_Withdrawal_Repository( $stale_fixture['snapshot'] );
g4_release_failure( static fn () => g4_withdrawal_application( $stale_repository, $withdrawal_canonical )->execute( $stale_command ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'Changed current release pointer must block C44.' );
g4_release_same( 0, $stale_repository->append_count, 'Stale C44 must append no revision.' );

$state_fixture                                      = g4_withdrawal_fixture( $withdrawal_canonical );
$state_fixture['snapshot']['current_release_state'] = 'stale';
$state_repository                                   = new G4_Result_Withdrawal_Repository( $state_fixture['snapshot'] );
g4_release_failure( static fn () => g4_withdrawal_application( $state_repository, $withdrawal_canonical )->execute( g4_withdrawal_command( $state_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-state', str_repeat( '4', 64 ) ) ), Submission_Evaluation_Failure::STATE_CONFLICT, 'Stale release must not be withdrawn again.' );
g4_release_same( 0, $state_repository->append_count, 'Non-visible current state must append no withdrawal.' );

$review_fixture = g4_withdrawal_fixture( $withdrawal_canonical );
$review_fixture['snapshot']['current_release_review_hash'] = str_repeat( 'e', 64 );
$review_repository = new G4_Result_Withdrawal_Repository( $review_fixture['snapshot'] );
g4_release_failure( static fn () => g4_withdrawal_application( $review_repository, $withdrawal_canonical )->execute( g4_withdrawal_command( $review_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-review', str_repeat( '5', 64 ) ) ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'Release review drift must block C44.' );

try {
	g4_withdrawal_command( $withdrawal_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-other', str_repeat( '6', 64 ), 'other', null );
	throw new RuntimeException( 'Other C44 reason must require a note.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	try {
		g4_withdrawal_command( $withdrawal_fixture['snapshot'], $withdrawal_canonical, 'command-g4-withdraw-code', str_repeat( '7', 64 ), 'unknown_reason', null );
		throw new RuntimeException( 'Unknown C44 reason code must fail.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		}

		$withdrawal_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Withdrawal_Repository.php' );
		$withdrawal_rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Result_Withdrawal_Rest_Controller.php' );
		$withdrawal_root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
		foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
			g4_release_assert( ! str_contains( $withdrawal_repository_source . $withdrawal_rest_source, $forbidden ), 'C44 boundary contains a forbidden dependency: ' . $forbidden );
		}
		g4_release_assert( str_contains( $withdrawal_rest_source, '/commands/withdraw-release' ) && str_contains( $withdrawal_rest_source, 'PUBLISH_FEEDBACK' ) && str_contains( $withdrawal_rest_source, "'withdraw_release'" ), 'C44 frozen route, capability, and object action must remain explicit.' );
		g4_release_assert( str_contains( $withdrawal_root_source, 'new Submission_Result_Withdrawal_Rest_Module' ), 'Composition Root must activate C44.' );

		fwrite( STDOUT, "G4-IMP-06-S03 C44 application passed: exact current release, immutable withdrawn revision, projection preservation, state conflict, reason validation, and durable replay are frozen.\n" );

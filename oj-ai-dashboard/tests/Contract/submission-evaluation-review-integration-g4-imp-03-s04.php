<?php
/**
 * G4-IMP-03-S04 result, pointer, release, and permission race freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

define( 'OJAI_G4_S02_FIXTURES_ONLY', true );
require __DIR__ . '/submission-evaluation-review-storage-g4-imp-03-s02.php';

/**
 * G4 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s04 stale failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Submission_Evaluation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_stale_failure( callable $callback, string $message ): Submission_Evaluation_Failure {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s04_same( Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, $failure->reason(), $message );
		return $failure;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s04 result drift.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $fixture Fixture.
 * @return array<string,mixed>
 */
function g4_s04_result_drift( Canonical_Json $canonical, array $fixture ): array {
	$snapshot    = $fixture['snapshot'];
	$result_id   = g4_s02_storage_id();
	$run_id      = g4_s02_storage_id();
	$result_hash = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'          => $fixture['evaluation_id'],
				'normalized_result_hash' => $snapshot['normalized_result_hash'],
				'provider_output_digest' => $snapshot['provider_output_digest'],
				'run_id'                 => $run_id,
			)
		)
	);
	return array_replace(
		$snapshot,
		array(
			'result_pk'     => '42',
			'result_id'     => $result_id,
			'result_hash'   => $result_hash,
			'result_run_id' => $run_id,
			'run_result_id' => $result_id,
			'result_count'  => '2',
		)
	);
}

/**
 * G4 s04 release drift.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $fixture Fixture.
 * @return array<string,mixed>
 */
function g4_s04_release_drift( Canonical_Json $canonical, array $fixture ): array {
	$snapshot     = $fixture['snapshot'];
	$previous_id  = (string) $snapshot['current_release_id'];
	$release_id   = g4_s02_storage_id();
	$released_at  = '2026-08-23 17:55:00';
	$release_hash = g4_s02_release_hash(
		$canonical,
		(string) $snapshot['current_release_evaluation_id'],
		(string) $snapshot['current_release_review_id'],
		null,
		2,
		'corrected',
		(string) $snapshot['current_release_visible_score'],
		(string) $snapshot['current_student_projection_schema'],
		(int) $snapshot['current_student_projection_version'],
		(string) $snapshot['current_student_projection_hash'],
		$previous_id,
		'data_correction',
		'Teacher corrected the current release.',
		$released_at
	);
	return array_replace(
		$snapshot,
		array(
			'current_release_id'          => $release_id,
			'current_release_hash'        => $release_hash,
			'current_release_pk'          => '72',
			'joined_current_release_id'   => $release_id,
			'joined_current_release_hash' => $release_hash,
			'current_release_no'          => '2',
			'current_release_state'       => 'corrected',
			'current_release_previous_id' => $previous_id,
			'current_release_reason_code' => 'data_correction',
			'current_release_reason_note' => 'Teacher corrected the current release.',
			'current_released_at_utc'     => $released_at,
			'current_release_count'       => '2',
		)
	);
}

/**
 * G4 s04 prelock race.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $fixture Fixture.
 * @param array<string,mixed> $transaction_snapshot Transaction snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array
 */
function g4_s04_prelock_race(
	Canonical_Json $canonical,
	array $fixture,
	array $transaction_snapshot,
	string $command_id
): array {
	$database                       = new G4_S02_Storage_Database( $fixture['snapshot'], $fixture['items'] );
	$database->transaction_snapshot = $transaction_snapshot;
	$database->transaction_items    = $fixture['items'];
	$before                         = $database->snapshot;
	$failure                        = g4_s04_stale_failure(
		static fn () => g4_s02_storage_application( $canonical, $database )->execute(
			g4_s02_storage_command( $canonical, $fixture, 'accepted', $command_id )
		),
		'Concurrent source drift must return review_source_stale.'
	);
	g4_s04_same( $before, $database->snapshot, 'Pre-lock source race must preserve both mutable roots.' );
	g4_s04_same( array(), $database->inserts, 'Pre-lock source race must commit zero immutable history rows.' );
	g4_s04_assert( in_array( 'ROLLBACK', $database->queries, true ), 'Pre-lock source race must roll back.' );
	g4_s04_assert(
		0 === count( array_filter( $database->queries, static fn ( string $sql ): bool => str_starts_with( $sql, 'UPDATE ' ) ) ),
		'Pre-lock source race must fail before root mutation.'
	);
	return array(
		'failure'  => $failure,
		'database' => $database,
	);
}

$canonical = new Canonical_Json();

$result_fixture = g4_s02_storage_fixture( $canonical, false );
$result_drift   = g4_s04_result_drift( $canonical, $result_fixture );
$result_race    = g4_s04_prelock_race( $canonical, $result_fixture, $result_drift, 'command-g4-s04-result-race' );
g4_s04_same(
	$result_drift['result_id'],
	$result_race['failure']->safe_context()['current_result_id'] ?? null,
	'Result race recovery context must expose only the current safe result ID.'
);

$pointer_fixture = g4_s02_storage_fixture( $canonical, false );
$pointer_drift   = array_replace(
	$pointer_fixture['snapshot'],
	array(
		'official_review_id'   => g4_s02_storage_id(),
		'official_review_hash' => str_repeat( '7', 64 ),
	)
);
$pointer_race    = g4_s04_prelock_race( $canonical, $pointer_fixture, $pointer_drift, 'command-g4-s04-pointer-race' );
g4_s04_same(
	$pointer_drift['official_review_id'],
	$pointer_race['failure']->safe_context()['current_official_review_id'] ?? null,
	'Official pointer race recovery context must expose the current safe review ID.'
);

$release_fixture = g4_s02_storage_fixture( $canonical, true );
$release_drift   = g4_s04_release_drift( $canonical, $release_fixture );
$release_race    = g4_s04_prelock_race( $canonical, $release_fixture, $release_drift, 'command-g4-s04-release-race' );
g4_s04_same(
	$release_drift['current_release_id'],
	$release_race['failure']->safe_context()['current_release_id'] ?? null,
	'Release race recovery context must expose the current safe release ID.'
);

$evaluation_fixture                       = g4_s02_storage_fixture( $canonical, true );
$evaluation_database                      = new G4_S02_Storage_Database( $evaluation_fixture['snapshot'], $evaluation_fixture['items'] );
$evaluation_before                        = $evaluation_database->snapshot;
$evaluation_database->fail_evaluation_cas = true;
g4_s04_stale_failure(
	static fn () => g4_s02_storage_application( $canonical, $evaluation_database )->execute(
		g4_s02_storage_command( $canonical, $evaluation_fixture, 'accepted', 'command-g4-s04-evaluation-cas' )
	),
	'Evaluation CAS race must return review_source_stale.'
);
g4_s04_same( $evaluation_before, $evaluation_database->snapshot, 'Evaluation CAS rollback must restore both mutable roots.' );
g4_s04_same( array(), $evaluation_database->inserts, 'Evaluation CAS rollback must remove review, stale release, audit, and outbox rows.' );
g4_s04_assert( in_array( 'ROLLBACK', $evaluation_database->queries, true ), 'Evaluation CAS race must roll back.' );

$repository_source    = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Review_Repository.php' );
$ui_source            = (string) file_get_contents( $addon_root . '/resources/js/submission-evaluation-review-workbench.js' );
$s01_contract         = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-review-g4-imp-03-s01.php' );
$s02_storage_contract = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-review-storage-g4-imp-03-s02.php' );
$s02_rest_contract    = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-review-rest-g4-imp-03-s02.php' );
$s03_ui_contract      = (string) file_get_contents( $addon_root . '/tests/Contract/submission-evaluation-review-workbench-g4-imp-03-s03.mjs' );
$s03_e2e              = (string) file_get_contents( $addon_root . '/scripts/e2e-submission-evaluation-review-workbench.mjs' );

foreach ( array( $repository_source, $ui_source ) as $source ) {
	g4_s04_assert( ! str_contains( $source, '$wpdb->prefix' ), 'S04 must not use the WordPress table prefix.' );
	g4_s04_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'S04 must not use legacy prefixed tables.' );
	g4_s04_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S04 must not use OJ Platform internals.' );
}
g4_s04_assert(
	1 !== preg_match( '/\b(?:UPDATE|DELETE)\s+oj_ai_submission_(?:evaluation_results|evaluation_items|evaluation_reviews|feedback_revisions|release_revisions)\b/i', $repository_source ),
	'S04 must preserve result, item, review, feedback, and release history rows.'
);
foreach ( array(
	'Exact C34 replay must use the durable receipt.' => $s01_contract,
	'Submission pointer CAS failure must surface as review_source_stale.' => $s02_storage_contract,
	'Permission revocation must deny C34 before application execution.' => $s02_rest_contract,
	'error?.status === 409'                          => $s03_ui_contract,
	'409 did not refetch the current review source'  => $s03_e2e,
	'403 permission revocation did not remove mutation controls' => $s03_e2e,
) as $marker => $source ) {
	g4_s04_assert( str_contains( $source, $marker ), 'S04 vertical regression marker is missing: ' . $marker );
}
g4_s04_assert( str_contains( $ui_source, 'state.canReview = false;' ), '403 must revoke client-side review controls.' );
g4_s04_assert( str_contains( $ui_source, 'await loadEvaluations(false, detail.evaluation.id);' ), '409 must reload the current list and detail.' );
g4_s04_assert( str_contains( $ui_source, "showNotice(message, 'error');" ), '409 notice must remain visible after the reload.' );

fwrite( STDOUT, "G4-IMP-03-S04 passed: result, official pointer, release, root CAS, 409 reload, permission revocation, replay, and immutable history are frozen.\n" );
exit( 0 );

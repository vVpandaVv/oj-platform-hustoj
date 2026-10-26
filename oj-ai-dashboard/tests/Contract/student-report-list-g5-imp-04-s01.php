<?php
/**
 * G5-IMP-04-S01 student-lens roster report list contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Student_Roster_Reports;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Student_Roster_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Student_Roster_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Score_Lookup_Query;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Student_Roster_Report_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

foreach ( array(
	'src/Worksheet/Authoring/Domain/Public_Id.php',
	'src/Worksheet/Authoring/Domain/Canonical_Json.php',
	'src/Worksheet/Authoring/Application/Read_Worksheets.php',
	'src/Worksheet/Authoring/Infrastructure/Signed_Cursor_Codec.php',
	'src/Security/Policy/Context_Scope.php',
	'src/Security/Policy/Policy_Value.php',
	'src/Reporting/Report/Application/Report_Query_Failure.php',
	'src/Reporting/Report/Domain/Report_Filter.php',
	'src/Reporting/Report/Domain/Report_Source_Validator.php',
	'src/Reporting/Report/Domain/Report_Metrics.php',
	'src/Reporting/Report/Domain/Student_Roster_Report_Projector.php',
	'src/Reporting/Report/Port/Student_Roster_Report_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Official_Assessment_Score_Lookup_Port_Interface.php',
	'src/Reporting/Report/Application/Read_Student_Roster_Reports.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Student_Roster_Report_Source_Query.php',
	'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Score_Lookup_Query.php',
) as $file ) {
	require_once ABSPATH . $file;
}

/**
 * S01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s01_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S01 id.
 *
 * @param int $value Value.
 * @return string
 */
function s01_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * S01 hash.
 *
 * @param string $value Value.
 * @return string
 */
function s01_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * S01 rehash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $source Source.
 * @return array<string,mixed>
 */
function s01_rehash( Canonical_Json $canonical, array $source ): array {
	unset( $source['source_hash'] );
	$source['source_hash'] = hash( 'sha256', $canonical->encode( $source ) );
	return $source;
}

/**
 * G5-IMP-04-S01 student-lens roster report list contract.
 */
final class S01_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Pages.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public array $pages;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();

	/**
	 * Initialize s01 database dependencies and state.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $pages Pages.
	 */
	public function __construct( array $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/%(s|d)/', $sql, $match ) ) {
				return '';
			}
			$replacement = 'd' === $match[1]
				? (string) (int) $value
				: "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%' . $match[1] . '/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}

	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, string $format ): array {
		$this->sql[] = $sql;
		if ( 'ARRAY_A' !== $format || array() === $this->pages ) {
			$this->last_error = 'unexpected query';
			return array();
		}
		return array_shift( $this->pages );
	}
}

/**
 * G5-IMP-04-S01 student-lens roster report list contract.
 */
final class S01_Fixed_Roster implements Student_Roster_Report_Source_Query_Port_Interface {
	/**
	 * Initialize s01 fixed roster dependencies and state.
	 *
	 * @param array{items:array<int,array<string,mixed>>,next_cursor:string} $page Page.
	 */
	public function __construct( private array $page ) {
	}
	/**
	 * List context.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array
	 */
	public function list_context( int $actor_user_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit, ?Report_Filter $filter = null ): array {
		return $this->page;
	}
}

/**
 * G5-IMP-04-S01 student-lens roster report list contract.
 */
final class S01_Fixed_Scores implements Official_Assessment_Score_Lookup_Port_Interface {
	/**
	 * Initialize s01 fixed scores dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	public function __construct( private array $rows ) {
	}
	/**
	 * By review refs.
	 *
	 * @param array $review_refs Review refs.
	 * @return array
	 */
	public function by_review_refs( array $review_refs ): array {
		return $this->rows;
	}
}

$canonical    = new Canonical_Json();
$cursor_codec = new Signed_Cursor_Codec( $canonical, 's01-contract-secret-2026-09-06' );

// NOTE: this local PHP has no intl extension (same limitation affects the existing
// worksheet-report-list-g5-imp-01-s01.php contract test), so display names here are
// ASCII rather than Korean to exercise Canonical_Json without requiring intl.
$display_json_1 = $canonical->encode( array( 'display_name' => 'Kim MinJun' ) );
$display_json_2 = $canonical->encode( array( 'display_name' => 'Lee SeoYeon' ) );

$roster_rows     = array(
	array(
		'learner_user_id'           => 501,
		'assignment_count'          => '2',
		'official_submission_count' => '1',
		'official_review_count'     => '1',
		'latest_activity_at_utc'    => '2026-08-24 10:00:00',
	),
	array(
		'learner_user_id'           => 502,
		'assignment_count'          => '1',
		'official_submission_count' => '0',
		'official_review_count'     => '0',
		'latest_activity_at_utc'    => '2026-08-24 09:00:00',
	),
);
$target_rows     = array(
	array(
		'learner_user_id'          => 501,
		'target_id'                => s01_id( 100 ),
		'target_hash'              => s01_hash( 'target-100' ),
		'membership_revision'      => 'rev-1',
		'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
		'display_snapshot_version' => 1,
		'display_snapshot_json'    => $display_json_1,
		'display_snapshot_hash'    => hash( 'sha256', $display_json_1 ),
		'target_snapshot_hash'     => s01_hash( 'snapshot-100' ),
	),
	array(
		'learner_user_id'          => 502,
		'target_id'                => s01_id( 101 ),
		'target_hash'              => s01_hash( 'target-101' ),
		'membership_revision'      => 'rev-1',
		'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
		'display_snapshot_version' => 1,
		'display_snapshot_json'    => $display_json_2,
		'display_snapshot_hash'    => hash( 'sha256', $display_json_2 ),
		'target_snapshot_hash'     => s01_hash( 'snapshot-101' ),
	),
);
$review_id       = s01_id( 200 );
$review_hash     = s01_hash( 'review-200' );
$release_id      = s01_id( 300 );
$release_hash    = s01_hash( 'release-300' );
$review_ref_rows = array(
	array(
		'learner_user_id' => 501,
		'review_id'       => $review_id,
		'review_hash'     => $review_hash,
		'release_id'      => $release_id,
		'release_hash'    => $release_hash,
	),
);

$roster_database = new S01_Database( array( $roster_rows, $target_rows, $review_ref_rows ) );
$roster_adapter  = new WordPress_Student_Roster_Report_Source_Query( $roster_database, $canonical, $cursor_codec );
$roster_page     = $roster_adapter->list_context( 77, 'class', 'class-s01', 'revision-s01', '', 2 );
s01_same( 2, count( $roster_page['items'] ), 'Roster page must contain both students.' );
s01_same( '', $roster_page['next_cursor'], 'No cursor expected when the page fits exactly.' );
s01_same( 'Kim MinJun', $roster_page['items'][0]['target']['display_name'], 'First student display name drifted.' );
s01_assert( str_contains( implode( "\n", $roster_database->sql ), "a.context_revision = 'revision-s01'" ), 'Roster query must pin exact Context revision.' );

$paged_database = new S01_Database( array( $roster_rows, array( $target_rows[0] ), array( $review_ref_rows[0] ) ) );
$paged_adapter  = new WordPress_Student_Roster_Report_Source_Query( $paged_database, $canonical, $cursor_codec );
$paged_page     = $paged_adapter->list_context( 77, 'class', 'class-s01', 'revision-s01', '', 1 );
s01_same( 1, count( $paged_page['items'] ), 'Roster page must respect the requested limit.' );
s01_assert( '' !== $paged_page['next_cursor'], 'Roster must return a signed cursor when another student remains.' );
$decoded_cursor = $cursor_codec->decode( $paged_page['next_cursor'], 'student_roster_list' );
s01_same( 501, $decoded_cursor['l'] ?? null, 'Roster cursor must pin the last learner.' );

$score_database = new S01_Database(
	array(
		array(
			array(
				'review_id'      => $review_id,
				'review_hash'    => $review_hash,
				'decision'       => 'accepted',
				'official_score' => '8.0000',
				'maximum_score'  => '10.0000',
			),
		),
		array(
			array(
				'release_id'   => $release_id,
				'release_hash' => $release_hash,
				'state'        => 'released',
			),
		),
	)
);
$score_lookup   = new WordPress_Official_Assessment_Score_Lookup_Query( $score_database );
$refs           = array();
foreach ( $roster_page['items'] as $item ) {
	foreach ( $item['official_review_refs'] as $ref ) {
		$refs[] = $ref;
	}
}
$score_rows = $score_lookup->by_review_refs( $refs );
s01_same( 1, count( $score_rows ), 'Only one review pointer was carried forward.' );
s01_same( 8000, $score_rows[0]['normalized_basis_points'], 'Normalized score drifted.' );
s01_same( 'released', $score_rows[0]['release_state'], 'Release state drifted.' );

$projector  = new Student_Roster_Report_Projector( $canonical );
$projection = $projector->project( $roster_page['items'], $score_rows );
$first      = $projection['items'][0];
$second     = $projection['items'][1];
s01_same( 'Kim MinJun', $first['target']['display_name'], 'Projected first student drifted.' );
s01_same(
	array(
		'numerator'   => 1,
		'denominator' => 2,
		'value'       => '50.0',
	),
	$first['summary']['submission_rate'],
	'Submission rate drifted.'
);
s01_same(
	array(
		'numerator'   => 1,
		'denominator' => 1,
		'value'       => '100.0',
	),
	$first['summary']['assessment_rate'],
	'Assessment rate drifted.'
);
s01_same(
	array(
		'numerator'   => 1,
		'denominator' => 1,
		'value'       => '100.0',
	),
	$first['summary']['release_rate'],
	'Release rate drifted.'
);
s01_same(
	array(
		'value' => '80.0',
		'n'     => 1,
	),
	$first['summary']['average_score'],
	'A single student own average must never be small-group suppressed.'
);
s01_same( 0, $first['summary']['review_required_count'], 'Review-required count drifted.' );
s01_same(
	array(
		'value' => null,
		'n'     => 0,
	),
	$second['summary']['average_score'],
	'A student with no reviews must show no data, not a suppressed group.'
);
s01_same(
	array(
		'numerator'   => 0,
		'denominator' => 1,
		'value'       => '0.0',
	),
	$second['summary']['submission_rate'],
	'Zero-submission rate against a real denominator must show 0.0%, not be suppressed.'
);

$tampered                     = $roster_page['items'][0];
$tampered['assignment_count'] = 99;
try {
	$projector->project( array( $tampered ), $score_rows );
	throw new RuntimeException( 'Tampered roster source hash was accepted.' );
} catch ( Report_Query_Failure $failure ) {
	s01_same( Report_Query_Failure::SOURCE_STALE, $failure->reason(), 'Tampered roster source must fail as report_source_stale.' );
}

$application = new Read_Student_Roster_Reports(
	new S01_Fixed_Roster( $roster_page ),
	new S01_Fixed_Scores( $score_rows ),
	$projector,
	$canonical,
	static fn (): string => '2026-09-06 10:05:00'
);
$page        = $application->list( 77, 'class', 'class-s01', 'revision-s01', '', 2 );
s01_same( 'student', $page['report']['lens'], 'Student lens must report lens=student.' );
s01_same( '2026-09-06 10:05:00', $page['report']['baseline_at_utc'], 'Baseline time drifted.' );
s01_same( 2, count( $page['report']['items'] ), 'Application must forward both projected students.' );
foreach ( array( 'filter_hash', 'projection_hash' ) as $hash_key ) {
	s01_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $page['report'][ $hash_key ] ), 'Hash is missing: ' . $hash_key );
}

$production_paths = array(
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Student_Roster_Report_Source_Query.php',
	ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Score_Lookup_Query.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Student_Roster_Rest_Controller.php',
);
$production       = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_paths );
foreach ( $production as $source ) {
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		s01_assert( ! str_contains( $source, $forbidden ), 'Contains a forbidden boundary marker: ' . $forbidden );
	}
}
s01_assert(
	! str_contains( $production[0], 'oj_ai_submission_evaluation_' ) && ! str_contains( $production[0], 'oj_ai_worksheets' ),
	'Assignment-owned roster query crossed an owner table boundary.'
);
s01_assert(
	! str_contains( $production[1], 'oj_ai_assignments' ) && ! str_contains( $production[1], 'oj_ai_assignment_targets' ) && ! str_contains( $production[1], 'oj_ai_submissions' ),
	'Assessment-owned score lookup crossed an owner table boundary.'
);
s01_assert( str_contains( $production[2], 'Addon_Capabilities::VIEW_REPORTS' ), 'Must require view_reports.' );
s01_assert( str_contains( $production[2], 'Access_Policy_Request::SHELL_CONSOLE' ), 'Must use the Console shell policy.' );
s01_assert( str_contains( $production[2], 'Context_Scope::OPERATION_REPORT' ), 'Must authorize exact report Context.' );
s01_assert( str_contains( $production[2], '$this->permissions->take( $request )' ), 'Callback must consume the permission handoff.' );

echo "G5-IMP-04-S01 passed: student roster report owner DTOs, hashes, cursor, exact Context, and per-student average suppression rules are frozen.\n";

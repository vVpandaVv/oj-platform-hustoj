<?php
/**
 * G5-IMP-01-S03 canonical report filter and denominator contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Domain\Report_Denominator_Contract;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Filter_Query;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Progress_Report_Source_Query;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * Wp timezone string.
	 *
	 * @return string
	 */
	function wp_timezone_string(): string {
		return 'Asia/Seoul';
	}
}

foreach ( array(
	'src/Worksheet/Authoring/Domain/Public_Id.php',
	'src/Worksheet/Authoring/Domain/Canonical_Json.php',
	'src/Worksheet/Authoring/Application/Read_Worksheets.php',
	'src/Worksheet/Authoring/Infrastructure/Signed_Cursor_Codec.php',
	'src/Reporting/Report/Application/Report_Query_Failure.php',
	'src/Reporting/Report/Domain/Report_Filter.php',
	'src/Reporting/Report/Domain/Report_Source_Validator.php',
	'src/Reporting/Report/Domain/Report_Denominator_Contract.php',
	'src/Reporting/Report/Port/Assignment_Progress_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Assignment_Report_Detail_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Presentation/Report_Filter_Query.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Progress_Report_Source_Query.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Report_Detail_Source_Query.php',
) as $file ) {
	require_once ABSPATH . $file;
}

/**
 * S03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s03_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S03 id.
 *
 * @param int $value Value.
 * @return string
 */
function s03_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * S03 hash.
 *
 * @param string $value Value.
 * @return string
 */
function s03_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * G5-IMP-01-S03 canonical report filter and denominator contract.
 */
final class S03_Database {
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
	 * Initialize s03 database dependencies and state.
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
			$replacement = 'd' === $match[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
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

$filter = Report_Filter_Query::from_params(
	array(
		'period_from'       => '2026-08-01',
		'period_to'         => '2026-09-01',
		'assignment_states' => 'closed,open,closed',
		'submission_states' => 'official,not_started,official',
	)
);
s03_same( array( 'open', 'closed' ), $filter->assignment_states(), 'Assignment states were not canonicalized.' );
s03_same( array( 'not_started', 'official' ), $filter->submission_states(), 'Submission states were not canonicalized.' );
s03_same( 'Asia/Seoul', $filter->timezone(), 'Site timezone was not pinned.' );
s03_same( '2026-07-31 15:00:00', $filter->from_utc(), 'Local lower date was not converted to UTC.' );
s03_same( '2026-08-31 15:00:00', $filter->to_utc(), 'Exclusive local upper date was not converted to UTC.' );
s03_same( '[from,to)', $filter->payload()['period']['boundary'] ?? null, 'Period boundary drifted.' );

foreach ( array(
	static fn (): Report_Filter => Report_Filter::from_query( '2026-08-01', null, 'Asia/Seoul', array(), array() ),
	static fn (): Report_Filter => Report_Filter::from_query( '2026-09-01', '2026-08-01', 'Asia/Seoul', array(), array() ),
	static fn (): Report_Filter => Report_Filter::from_query( null, null, 'Asia/Seoul', array( 'withdrawn' ), array() ),
	static fn (): Report_Filter => Report_Filter::from_query( null, null, 'Asia/Seoul', array(), array( 'released' ) ),
) as $invalid_filter ) {
	try {
		$invalid_filter();
		throw new RuntimeException( 'An invalid report filter was accepted.' );
	} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
}

$denominators = Report_Denominator_Contract::payload();
s03_same( 'oj_ai.report_denominator_contract', $denominators['schema_id'] ?? null, 'Denominator schema drifted.' );
s03_same( '1.0.0', $denominators['schema_version'] ?? null, 'Denominator version drifted.' );
s03_same( 'target_count', $denominators['metrics']['submission_rate']['denominator_key'] ?? null, 'Submission denominator key drifted.' );
s03_same( 'official_submission_count', $denominators['metrics']['assessment_rate']['denominator_key'] ?? null, 'Assessment denominator key drifted.' );
s03_same( 'official_review_count', $denominators['metrics']['release_rate']['denominator_key'] ?? null, 'Release denominator key drifted.' );
s03_same( 'scored_official_review_count', $denominators['metrics']['average_score']['denominator_key'] ?? null, 'Average denominator key drifted.' );
s03_assert( in_array( 'release_stale', $denominators['metrics']['release_rate']['excluded_reason_codes'] ?? array(), true ), 'Stale release exclusion is missing.' );
s03_assert( in_array( 'submission_state_filtered', $denominators['metrics']['target_count']['excluded_reason_codes'] ?? array(), true ), 'Submission filter exclusion is missing.' );

$canonical             = new Canonical_Json();
$cursor_codec          = new Signed_Cursor_Codec( $canonical, 'g5-s03-filter-secret-2026-08-24' );
$publication_id        = s03_id( 10 );
$second_publication_id = s03_id( 11 );
$publication_hash      = s03_hash( 's03-publication' );
$assignment_id         = s03_id( 20 );

$summary_rows    = array(
	array(
		'publication_id'            => $publication_id,
		'publication_hash'          => $publication_hash,
		'assignment_count'          => '1',
		'target_count'              => '5',
		'official_submission_count' => '5',
		'official_review_count'     => '3',
		'latest_activity_at_utc'    => '2026-08-24 12:00:00',
	),
	array(
		'publication_id'            => $second_publication_id,
		'publication_hash'          => s03_hash( 's03-publication-2' ),
		'assignment_count'          => '1',
		'target_count'              => '1',
		'official_submission_count' => '1',
		'official_review_count'     => '0',
		'latest_activity_at_utc'    => '2026-08-23 12:00:00',
	),
);
$assignment_rows = array(
	array(
		'assignment_id'             => $assignment_id,
		'assignment_hash'           => s03_hash( 's03-assignment' ),
		'publication_id'            => $publication_id,
		'publication_hash'          => $publication_hash,
		'title_snapshot'            => '필터 활동지',
		'state'                     => 'open',
		'due_at_utc'                => '2026-08-30 09:00:00',
		'target_snapshot_hash'      => s03_hash( 's03-targets' ),
		'updated_at_utc'            => '2026-08-24 11:00:00',
		'target_count'              => '5',
		'official_submission_count' => '5',
		'official_review_count'     => '3',
	),
);
$review_rows     = array();
for ( $index = 1; $index <= 3; ++$index ) {
	$review_rows[] = array(
		'publication_id'   => $publication_id,
		'publication_hash' => $publication_hash,
		'review_id'        => s03_id( 100 + $index ),
		'review_hash'      => s03_hash( 's03-review-' . $index ),
		'release_id'       => null,
		'release_hash'     => null,
	);
}
$q20_database    = new S03_Database( array( $summary_rows, $assignment_rows, $review_rows ) );
$official_filter = Report_Filter::from_query( '2026-08-01', '2026-09-01', 'Asia/Seoul', array( 'open', 'open' ), array( 'official', 'official' ) );
$q20             = new WordPress_Assignment_Progress_Report_Source_Query( $q20_database, $canonical, $cursor_codec );
$q20_page        = $q20->list_context( 71, 'class', 'class-s03', 'context-s03-r1', '', 1, $official_filter );
s03_same( 5, $q20_page['items'][0]['target_count'], 'Q20 filtered target denominator drifted.' );
s03_same( 3, count( $q20_page['items'][0]['official_review_refs'] ), 'Q20 filtered review references drifted.' );
s03_assert( '' !== $q20_page['next_cursor'], 'Q20 filtered cursor was not generated.' );
$q20_sql = implode( "\n", $q20_database->sql );
s03_assert( str_contains( $q20_sql, "a.state IN ('open')" ), 'Q20 assignment state filter is missing.' );
s03_assert( str_contains( $q20_sql, "COALESCE(a.opens_at_utc, a.created_at_utc) >= '2026-07-31 15:00:00'" ), 'Q20 lower period boundary is missing.' );
s03_assert( str_contains( $q20_sql, "COALESCE(a.opens_at_utc, a.created_at_utc) < '2026-08-31 15:00:00'" ), 'Q20 upper period boundary is missing.' );
s03_assert( str_contains( $q20_sql, 's.official_revision_public_id IS NOT NULL' ), 'Q20 official submission stage is missing.' );

$different_filter = Report_Filter::from_query( '2026-08-01', '2026-09-01', 'Asia/Seoul', array( 'open' ), array( 'not_started' ) );
try {
	( new WordPress_Assignment_Progress_Report_Source_Query( new S03_Database( array() ), $canonical, $cursor_codec ) )
		->list_context( 71, 'class', 'class-s03', 'context-s03-r1', $q20_page['next_cursor'], 1, $different_filter );
	throw new RuntimeException( 'Q20 accepted a cursor from another filter scope.' );
} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
}

$display      = $canonical->encode( array( 'display_name' => '미제출 학생' ) );
$student_rows = array();
for ( $index = 1; $index <= 2; ++$index ) {
	$student_rows[] = array(
		'target_id'                => s03_id( 200 + $index ),
		'target_hash'              => s03_hash( 's03-student-target-' . $index ),
		'learner_user_id'          => (string) ( 80 + $index ),
		'membership_revision'      => 'member-s03-' . $index,
		'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
		'display_snapshot_version' => '1',
		'display_snapshot_json'    => $display,
		'display_snapshot_hash'    => hash( 'sha256', $display ),
		'target_snapshot_hash'     => s03_hash( 's03-student-snapshot-' . $index ),
		'submission_id'            => null,
		'submission_hash'          => null,
		'submission_state'         => null,
		'started_at_utc'           => null,
		'submitted_at_utc'         => null,
		'official_revision_id'     => null,
		'official_revision_hash'   => null,
	);
}
$detail_summary    = array(
	'assignment_count'          => '1',
	'target_count'              => '2',
	'official_submission_count' => '0',
	'official_review_count'     => '0',
	'latest_activity_at_utc'    => '2026-08-24 12:00:00',
);
$detail_assignment = array(
	'assignment_pk'             => '20',
	'assignment_id'             => $assignment_id,
	'assignment_hash'           => s03_hash( 's03-assignment' ),
	'title_snapshot'            => '필터 활동지',
	'state'                     => 'open',
	'opens_at_utc'              => '2026-08-20 09:00:00',
	'due_at_utc'                => '2026-08-30 09:00:00',
	'closes_at_utc'             => '2026-08-31 09:00:00',
	'target_snapshot_hash'      => s03_hash( 's03-targets' ),
	'updated_at_utc'            => '2026-08-24 11:00:00',
	'target_count'              => '2',
	'official_submission_count' => '0',
	'official_review_count'     => '0',
);
$q21_database      = new S03_Database( array( array( $detail_summary ), array( $detail_assignment ), $student_rows, array() ) );
$q21               = new WordPress_Assignment_Report_Detail_Source_Query( $q21_database, $canonical, $cursor_codec );
$q21_page          = $q21->read_publication( 71, 'class', 'class-s03', 'context-s03-r1', $publication_id, $publication_hash, $assignment_id, '', 1, $different_filter );
s03_assert( null !== $q21_page, 'Q21 not-started filter returned no detail.' );
s03_same( 2, $q21_page['source']['totals']['target_count'], 'Q21 not-started denominator drifted.' );
s03_same( 1, count( $q21_page['source']['students'] ), 'Q21 student page filter drifted.' );
s03_assert( '' !== $q21_page['next_cursor'], 'Q21 filtered student cursor was not generated.' );
$q21_sql = implode( "\n", $q21_database->sql );
s03_assert( str_contains( $q21_sql, 's.id IS NULL' ), 'Q21 not-started stage predicate is missing.' );
s03_assert( str_contains( $q21_sql, "a.state IN ('open')" ), 'Q21 assignment state filter is missing.' );
s03_assert( str_contains( $q21_sql, "COALESCE(a.opens_at_utc, a.created_at_utc) < '2026-08-31 15:00:00'" ), 'Q21 exclusive period boundary is missing.' );

$production_paths = array(
	ABSPATH . 'src/Reporting/Report/Domain/Report_Filter.php',
	ABSPATH . 'src/Reporting/Report/Domain/Report_Denominator_Contract.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Report_Filter_Query.php',
	ABSPATH . 'src/Reporting/Report/Application/Read_Worksheet_Reports.php',
	ABSPATH . 'src/Reporting/Report/Application/Read_Worksheet_Report_Detail.php',
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Progress_Report_Source_Query.php',
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Report_Detail_Source_Query.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Worksheet_Report_Rest_Controller.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Worksheet_Report_Detail_Rest_Controller.php',
);
$production       = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_paths );
foreach ( $production as $source ) {
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		s03_assert( ! str_contains( $source, $forbidden ), 'S03 contains a forbidden boundary marker: ' . $forbidden );
	}
}
s03_assert( str_contains( $production[3], 'Report_Denominator_Contract::payload()' ), 'Q20 denominator response contract is not connected.' );
s03_assert( str_contains( $production[4], 'Report_Denominator_Contract::payload()' ), 'Q21 denominator response contract is not connected.' );
s03_assert( str_contains( $production[7], 'Report_Filter_Query::KEYS' ) && str_contains( $production[8], 'Report_Filter_Query::KEYS' ), 'Q20/Q21 REST filter allowlists drifted.' );
s03_assert( ! str_contains( $production[5], 'oj_ai_worksheets' ) && ! str_contains( $production[6], 'oj_ai_worksheets' ), 'Assignment report filter crossed the Worksheet owner boundary.' );

echo "G5-IMP-01-S03 passed: canonical site-timezone period, assignment/submission filters, filter-bound cursors, and denominator reasons are frozen.\n";

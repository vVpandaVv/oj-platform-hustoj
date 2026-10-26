<?php
/**
 * G5-IMP-01-S02 Q21 worksheet report drill-down contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Worksheet_Report_Detail;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Metrics;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Worksheet_Report_Detail_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Report_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Report_Detail_Source_Query;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Official_Assessment_Report_Detail_Source_Query;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Report_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

foreach ( array(
	'src/Worksheet/Authoring/Domain/Public_Id.php',
	'src/Worksheet/Authoring/Domain/Canonical_Json.php',
	'src/Worksheet/Authoring/Application/Read_Worksheets.php',
	'src/Worksheet/Authoring/Infrastructure/Signed_Cursor_Codec.php',
	'src/Security/Policy/Policy_Value.php',
	'src/Security/Policy/Context_Scope.php',
	'src/Reporting/Report/Application/Report_Query_Failure.php',
	'src/Reporting/Report/Domain/Report_Filter.php',
	'src/Reporting/Report/Domain/Report_Source_Validator.php',
	'src/Reporting/Report/Domain/Report_Denominator_Contract.php',
	'src/Reporting/Report/Port/Assignment_Progress_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Official_Assessment_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Worksheet_Report_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Assignment_Report_Detail_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Official_Assessment_Detail_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Domain/Report_Metrics.php',
	'src/Reporting/Report/Domain/Worksheet_Report_Detail_Projector.php',
	'src/Reporting/Report/Application/Read_Worksheet_Report_Detail.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Report_Detail_Source_Query.php',
	'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Report_Source_Query.php',
	'src/Submission/Assessment/Infrastructure/Official_Assessment_Report_Detail_Source_Query.php',
) as $file ) {
	require_once ABSPATH . $file;
}

/**
 * Q21 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q21_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q21 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q21_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Q21 id.
 *
 * @param int $value Value.
 * @return string
 */
function q21_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * Q21 hash.
 *
 * @param string $value Value.
 * @return string
 */
function q21_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * Q21 rehash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $source Source.
 * @return array<string,mixed>
 */
function q21_rehash( Canonical_Json $canonical, array $source ): array {
	unset( $source['source_hash'] );
	$source['source_hash'] = hash( 'sha256', $canonical->encode( $source ) );
	return $source;
}

/**
 * G5-IMP-01-S02 Q21 worksheet report drill-down contract.
 */
final class Q21_Database {
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
	 * Initialize q21 database dependencies and state.
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

/**
 * G5-IMP-01-S02 Q21 worksheet report drill-down contract.
 */
final class Q21_Fixed_Detail implements Assignment_Report_Detail_Source_Query_Port_Interface {
	/**
	 * Initialize q21 fixed detail dependencies and state.
	 *
	 * @param array{source:array<string,mixed>,next_cursor:string} $page Page.
	 */
	public function __construct( private array $page ) {
	}
	/**
	 * Read publication.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $publication_id Publication id.
	 * @param string             $publication_hash Publication hash.
	 * @param string|null        $assignment_id Distribution public identifier.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array|null
	 */
	public function read_publication( int $actor_user_id, string $context_type, string $context_id, string $context_revision, string $publication_id, string $publication_hash, ?string $assignment_id, string $cursor, int $limit, ?Report_Filter $filter = null ): ?array {
		return $this->page;
	}
}

/**
 * G5-IMP-01-S02 Q21 worksheet report drill-down contract.
 */
final class Q21_Fixed_Worksheets implements Worksheet_Report_Source_Query_Port_Interface {
	/**
	 * Initialize q21 fixed worksheets dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * By publications.
	 *
	 * @param array $publications Publications.
	 * @return array
	 */
	public function by_publications( array $publications ): array {
		return $this->sources;
	}
}

/**
 * G5-IMP-01-S02 Q21 worksheet report drill-down contract.
 */
final class Q21_Fixed_Assessments implements Official_Assessment_Detail_Source_Query_Port_Interface {
	/**
	 * Initialize q21 fixed assessments dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * By report details.
	 *
	 * @param array $detail_sources Detail sources.
	 * @return array
	 */
	public function by_report_details( array $detail_sources ): array {
		return $this->sources;
	}
}

/**
 * Q21 assignment row.
 *
 * @param string $id Id.
 * @param string $title Title.
 * @param int    $targets Targets.
 * @param int    $submissions Submissions.
 * @param int    $reviews Reviews.
 * @param string $updated Updated.
 * @return array<string,mixed>
 */
function q21_assignment_row( string $id, string $title, int $targets, int $submissions, int $reviews, string $updated ): array {
	return array(
		'assignment_pk'             => (string) (int) substr( $id, -3 ),
		'assignment_id'             => $id,
		'assignment_hash'           => q21_hash( 'assignment-' . $id ),
		'title_snapshot'            => $title,
		'state'                     => 'open',
		'opens_at_utc'              => '2026-08-20 09:00:00',
		'due_at_utc'                => '2026-08-30 18:00:00',
		'closes_at_utc'             => '2026-08-31 18:00:00',
		'target_snapshot_hash'      => q21_hash( 'targets-' . $id ),
		'updated_at_utc'            => $updated,
		'target_count'              => (string) $targets,
		'official_submission_count' => (string) $submissions,
		'official_review_count'     => (string) $reviews,
	);
}

/**
 * Q21 student row.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $target_id Target id.
 * @param int            $learner_id Learner id.
 * @param string         $display_name Display name.
 * @param int|null       $submission_no Submission no.
 * @param int|null       $review_no Review no.
 * @return array<string,mixed>
 */
function q21_student_row( Canonical_Json $canonical, string $target_id, int $learner_id, string $display_name, ?int $submission_no, ?int $review_no ): array {
	$display_json  = $canonical->encode( array( 'display_name' => $display_name ) );
	$submission_id = null === $submission_no ? null : q21_id( $submission_no );
	$revision_id   = null === $review_no ? null : q21_id( 800 + $review_no );
	return array(
		'target_id'                 => $target_id,
		'target_hash'               => q21_hash( 'target-' . $target_id ),
		'learner_user_id'           => (string) $learner_id,
		'membership_revision'       => 'member-' . $learner_id,
		'display_snapshot_schema'   => 'oj_ai.assignment_target_display',
		'display_snapshot_version'  => '1',
		'display_snapshot_json'     => $display_json,
		'display_snapshot_hash'     => hash( 'sha256', $display_json ),
		'target_snapshot_hash'      => q21_hash( 'target-snapshot-' . $target_id ),
		'submission_id'             => $submission_id,
		'submission_updated_at_utc' => null === $submission_id ? null : '2026-08-24 09:45:00',
		'submission_state'          => null === $submission_id ? null : 'submitted',
		'started_at_utc'            => null === $submission_id ? null : '2026-08-24 09:00:00',
		'submitted_at_utc'          => null === $submission_id ? null : '2026-08-24 09:30:00',
		'official_revision_id'      => $revision_id,
		'official_revision_hash'    => null === $revision_id ? null : q21_hash( 'revision-' . $revision_id ),
	);
}

$canonical            = new Canonical_Json();
$cursor_codec         = new Signed_Cursor_Codec( $canonical, 'q21-contract-secret-2026-08-24' );
$worksheet_id         = q21_id( 10 );
$publication_id       = q21_id( 20 );
$publication_hash     = q21_hash( 'publication-q21' );
$assignment_id        = q21_id( 101 );
$second_assignment_id = q21_id( 102 );
$criterion_id         = q21_id( 30 );

$references   = array();
$review_rows  = array();
$release_rows = array();
for ( $index = 1; $index <= 5; ++$index ) {
	$review_id     = q21_id( 200 + $index );
	$release_id    = $index <= 4 ? q21_id( 300 + $index ) : null;
	$target_id     = q21_id( 400 + $index );
	$references[]  = array(
		'assignment_id' => $assignment_id,
		'target_id'     => $target_id,
		'review_id'     => $review_id,
		'review_hash'   => q21_hash( 'review-' . $index ),
		'release_id'    => $release_id,
		'release_hash'  => null === $release_id ? null : q21_hash( 'release-' . $index ),
	);
	$score_payload = array(
		'schema_id'      => 'oj_ai.submission_score_decisions',
		'schema_version' => '1.0.0',
		'items'          => array(
			array(
				'criterion_id'    => $criterion_id,
				'official_points' => '8.0000',
				'maximum_points'  => '10.0000',
			),
		),
	);
	$score_json    = $canonical->encode( $score_payload );
	$review_rows[] = array(
		'review_id'               => $review_id,
		'review_hash'             => q21_hash( 'review-' . $index ),
		'decision'                => 'accepted',
		'official_score'          => '8.0000',
		'score_overrides_schema'  => 'oj_ai.submission_score_decisions',
		'score_overrides_version' => '1',
		'score_overrides_json'    => $score_json,
		'score_overrides_hash'    => hash( 'sha256', $score_json ),
		'created_at_utc'          => '2026-08-24 10:00:0' . $index,
		'result_id'               => q21_id( 500 + $index ),
		'result_hash'             => q21_hash( 'result-' . $index ),
		'maximum_score'           => '10.0000',
	);
	if ( null !== $release_id ) {
		$release_rows[] = array(
			'release_id'      => $release_id,
			'release_hash'    => q21_hash( 'release-' . $index ),
			'state'           => 'released',
			'released_at_utc' => '2026-08-24 10:10:0' . $index,
			'review_id'       => $review_id,
		);
	}
}

$summary             = array(
	'assignment_count'          => '2',
	'target_count'              => '10',
	'official_submission_count' => '7',
	'official_review_count'     => '5',
	'latest_activity_at_utc'    => '2026-08-24 10:15:00',
);
$assignment_rows     = array(
	q21_assignment_row( $assignment_id, '분수의 덧셈 · 2학년 3반', 8, 6, 5, '2026-08-24 10:00:00' ),
	q21_assignment_row( $second_assignment_id, '분수의 덧셈 · 2학년 4반', 2, 1, 0, '2026-08-24 09:00:00' ),
);
$assignment_database = new Q21_Database( array( array( $summary ), $assignment_rows, $references ) );
$assignment_adapter  = new WordPress_Assignment_Report_Detail_Source_Query( $assignment_database, $canonical, $cursor_codec );
$assignment_page     = $assignment_adapter->read_publication( 77, 'class', 'class-q21', 'context-r1', $publication_id, $publication_hash, null, '', 1 );
q21_assert( null !== $assignment_page, 'Q21 assignment page was not found.' );
q21_same( 'assignments', $assignment_page['source']['level'], 'Q21 must enter at the assignment level.' );
q21_same( 2, $assignment_page['source']['totals']['assignment_count'], 'Q21 publication assignment denominator drifted.' );
q21_same( 1, count( $assignment_page['source']['assignments'] ), 'Q21 assignment page limit drifted.' );
q21_same( 5, count( $assignment_page['source']['official_review_refs'] ), 'Q21 publication source must preserve every official review reference.' );
q21_assert( '' !== $assignment_page['next_cursor'] && strlen( $assignment_page['next_cursor'] ) <= 191, 'Q21 assignment cursor is missing or too long.' );
$decoded_assignment_cursor = $cursor_codec->decode( $assignment_page['next_cursor'], 'report_detail_a' );
q21_same( $assignment_id, $decoded_assignment_cursor['i'] ?? null, 'Q21 assignment cursor position drifted.' );
$assignment_sql = implode( "\n", $assignment_database->sql );
q21_assert( str_contains( $assignment_sql, "a.context_revision = 'context-r1'" ), 'Q21 assignment source did not pin Context revision.' );
q21_assert( str_contains( $assignment_sql, "a.publication_hash = '" . $publication_hash . "'" ), 'Q21 assignment source did not pin publication hash.' );

$student_rows     = array(
	q21_student_row( $canonical, q21_id( 401 ), 11, '김하늘', 601, 1 ),
	q21_student_row( $canonical, q21_id( 402 ), 12, '이준서', 602, 2 ),
	q21_student_row( $canonical, q21_id( 406 ), 16, '박서윤', null, null ),
);
$student_summary  = array(
	'assignment_count'          => '1',
	'target_count'              => '8',
	'official_submission_count' => '6',
	'official_review_count'     => '5',
	'latest_activity_at_utc'    => '2026-08-24 10:15:00',
);
$student_database = new Q21_Database( array( array( $student_summary ), array( $assignment_rows[0] ), $student_rows, $references ) );
$student_adapter  = new WordPress_Assignment_Report_Detail_Source_Query( $student_database, $canonical, $cursor_codec );
$student_page     = $student_adapter->read_publication( 77, 'class', 'class-q21', 'context-r1', $publication_id, $publication_hash, $assignment_id, '', 2 );
q21_assert( null !== $student_page, 'Q21 student page was not found.' );
q21_same( 'students', $student_page['source']['level'], 'Q21 assignment selection must enter the student level.' );
q21_same( 2, count( $student_page['source']['students'] ), 'Q21 student page limit drifted.' );
q21_assert( '' !== $student_page['next_cursor'] && strlen( $student_page['next_cursor'] ) <= 191, 'Q21 student cursor is missing or too long.' );
$decoded_student_cursor = $cursor_codec->decode( $student_page['next_cursor'], 'report_detail_s' );
q21_same( 12, $decoded_student_cursor['u'] ?? null, 'Q21 student cursor learner position drifted.' );

$assessment_database = new Q21_Database( array( $review_rows, $release_rows ) );
$assessment_owner    = new WordPress_Official_Assessment_Report_Source_Query( $assessment_database, $canonical );
$assessment_bridge   = new Official_Assessment_Report_Detail_Source_Query( $assessment_owner, $canonical );
$assessment_sources  = $assessment_bridge->by_report_details( array( $assignment_page['source'] ) );
q21_same( 5, $assessment_sources[0]['official_review_count'], 'Q21 must resolve five official reviews.' );
q21_same( 4, $assessment_sources[0]['released_count'], 'Q21 must count only active current releases.' );

$worksheet_source = array(
	'schema_id'      => 'oj_ai.worksheet_report_source',
	'schema_version' => '1.0.0',
	'worksheet'      => array(
		'id'            => $worksheet_id,
		'title'         => '분수의 덧셈',
		'state'         => 'active',
		'subject_label' => '수학',
	),
	'revision'       => array(
		'id'   => q21_id( 40 ),
		'hash' => q21_hash( 'worksheet-revision' ),
	),
	'publication'    => array(
		'id'               => $publication_id,
		'hash'             => $publication_hash,
		'content_hash'     => $publication_hash,
		'number'           => 2,
		'state'            => 'active',
		'published_at_utc' => '2026-08-20 08:00:00',
	),
	'rubric'         => array(
		'revision_id'     => q21_id( 41 ),
		'content_hash'    => q21_hash( 'rubric' ),
		'total_points'    => '10',
		'criterion_count' => 1,
	),
	'updated_at_utc' => '2026-08-20 08:00:00',
);
$worksheet_source = q21_rehash( $canonical, $worksheet_source );

$metrics               = new Report_Metrics();
$projector             = new Worksheet_Report_Detail_Projector( $canonical, $metrics );
$assignment_projection = $projector->project( $worksheet_id, $assignment_page['source'], $worksheet_source, $assessment_sources[0] );
$detail                = $assignment_projection['detail'];
q21_same(
	array(
		'numerator'   => 7,
		'denominator' => 10,
		'value'       => '70.0',
	),
	$detail['summary']['submission_rate'],
	'Q21 worksheet submission denominator drifted.'
);
q21_same(
	array(
		'numerator'   => 5,
		'denominator' => 7,
		'value'       => '71.4',
	),
	$detail['summary']['assessment_rate'],
	'Q21 worksheet assessment denominator drifted.'
);
q21_same(
	array(
		'numerator'   => 4,
		'denominator' => 5,
		'value'       => '80.0',
	),
	$detail['summary']['release_rate'],
	'Q21 worksheet release denominator drifted.'
);
q21_same(
	array(
		'value'      => '80.0',
		'n'          => 5,
		'suppressed' => false,
		'reason'     => null,
	),
	$detail['summary']['average_score'],
	'Q21 worksheet official average drifted.'
);
q21_same( '80.0', $detail['criterion_averages'][0]['average']['value'], 'Q21 criterion average drifted.' );
q21_same(
	array(
		'numerator'   => 6,
		'denominator' => 8,
		'value'       => '75.0',
	),
	$detail['assignments'][0]['progress']['submission_rate'],
	'Q21 assignment submission rate drifted.'
);

$student_assessment_database = new Q21_Database( array( $review_rows, $release_rows ) );
$student_assessment_sources  = ( new Official_Assessment_Report_Detail_Source_Query( new WordPress_Official_Assessment_Report_Source_Query( $student_assessment_database, $canonical ), $canonical ) )->by_report_details( array( $student_page['source'] ) );
$student_projection          = $projector->project( $worksheet_id, $student_page['source'], $worksheet_source, $student_assessment_sources[0] );
$student_detail              = $student_projection['detail'];
q21_same( '김하늘', $student_detail['students'][0]['target']['display_name'], 'Q21 must use the assignment-time display snapshot.' );
q21_same( 'released', $student_detail['students'][0]['status'], 'Q21 released student status drifted.' );
q21_same( '80.0', $student_detail['students'][0]['official_assessment']['score']['value'], 'Q21 individual official score drifted.' );
q21_assert( ! array_key_exists( 'response', $student_detail['students'][0] ), 'Q21 must not expose student response content.' );

$small_progress                                    = $assignment_page['source'];
$small_progress['totals']['official_review_count'] = 4;
$small_progress['official_review_refs']            = array_slice( $small_progress['official_review_refs'], 0, 4 );
$small_progress['assignments'][0]['official_review_count'] = 4;
$small_progress['assignments'][0]['official_review_refs']  = array_slice( $small_progress['assignments'][0]['official_review_refs'], 0, 4 );
$small_progress                                  = q21_rehash( $canonical, $small_progress );
$small_assessment                                = $assessment_sources[0];
$small_assessment['official_review_count']       = 4;
$small_assessment['scored_review_count']         = 4;
$small_assessment['normalized_basis_points_sum'] = 32000;
$small_assessment['released_count']              = 4;
$small_assessment['reviews']                     = array_slice( $small_assessment['reviews'], 0, 4 );
$small_assessment['criterion_totals'][0]['normalized_basis_points_sum'] = 32000;
$small_assessment['criterion_totals'][0]['n']                           = 4;
$small_assessment = q21_rehash( $canonical, $small_assessment );
$small_projection = $projector->project( $worksheet_id, $small_progress, $worksheet_source, $small_assessment );
q21_same(
	array(
		'value'      => null,
		'n'          => 4,
		'suppressed' => true,
		'reason'     => 'small_group',
	),
	$small_projection['detail']['summary']['average_score'],
	'Q21 four-score aggregate must be suppressed.'
);
q21_same( true, $small_projection['detail']['criterion_averages'][0]['average']['suppressed'], 'Q21 four-student criterion average must be suppressed.' );

$tampered                           = $assignment_page['source'];
$tampered['totals']['target_count'] = 999;
try {
	$projector->project( $worksheet_id, $tampered, $worksheet_source, $assessment_sources[0] );
	throw new RuntimeException( 'Q21 accepted a tampered owner source.' );
} catch ( Report_Query_Failure $failure ) {
	q21_same( Report_Query_Failure::SOURCE_STALE, $failure->reason(), 'Q21 tampering must fail as report_source_stale.' );
}

$application      = new Read_Worksheet_Report_Detail(
	new Q21_Fixed_Detail( $assignment_page ),
	new Q21_Fixed_Worksheets( array( $worksheet_source ) ),
	new Q21_Fixed_Assessments( $assessment_sources ),
	$projector,
	$canonical,
	static fn (): string => '2026-08-24 10:20:00'
);
$application_page = $application->detail( 77, $worksheet_id, $publication_id, $publication_hash, 'class', 'class-q21', 'context-r1', null, '', 1 );
q21_same( 'assignments', $application_page['report']['level'], 'Q21 application level drifted.' );
q21_same( Report_Metrics::CONTRACT, $application_page['report']['metric_contract'], 'Q20/Q21 metric contract drifted.' );
q21_same( '2026-08-24 10:20:00', $application_page['report']['baseline_at_utc'], 'Q21 baseline time drifted.' );
foreach ( array( 'filter_hash', 'source_manifest_hash', 'projection_hash' ) as $hash_key ) {
	q21_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $application_page['report'][ $hash_key ] ), 'Q21 hash is missing: ' . $hash_key );
}

$production_paths = array(
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Report_Detail_Source_Query.php',
	ABSPATH . 'src/Submission/Assessment/Infrastructure/Official_Assessment_Report_Detail_Source_Query.php',
	ABSPATH . 'src/Reporting/Report/Domain/Report_Metrics.php',
	ABSPATH . 'src/Reporting/Report/Domain/Worksheet_Report_Detail_Projector.php',
	ABSPATH . 'src/Reporting/Report/Application/Read_Worksheet_Report_Detail.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Worksheet_Report_Detail_Rest_Controller.php',
);
$production       = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_paths );
foreach ( $production as $source ) {
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		q21_assert( ! str_contains( $source, $forbidden ), 'Q21 contains a forbidden boundary marker: ' . $forbidden );
	}
}
q21_assert( ! str_contains( $production[0], 'oj_ai_worksheets' ) && ! str_contains( $production[0], 'oj_ai_submission_evaluation_' ), 'Q21 Assignment source crossed an owner table boundary.' );
q21_assert( ! str_contains( $production[1], 'oj_ai_assignments' ) && ! str_contains( $production[1], 'oj_ai_submissions' ), 'Q21 Assessment bridge crossed an owner table boundary.' );
q21_assert( 1 === preg_match( "~ROUTE_DETAIL\\s*=\\s*'/reports/worksheets/~", $production[5] ), 'Q21 route marker is missing.' );
q21_assert( str_contains( $production[5], 'Addon_Capabilities::VIEW_REPORTS' ), 'Q21 must require view_reports.' );
q21_assert( str_contains( $production[5], 'Access_Policy_Request::SHELL_CONSOLE' ), 'Q21 must use the Console shell.' );
q21_assert( str_contains( $production[5], 'Context_Scope::OPERATION_REPORT' ), 'Q21 must authorize exact report Context.' );
q21_assert( str_contains( $production[5], '$this->permissions->take( $request )' ), 'Q21 callback must consume permission handoff.' );

echo "G5-IMP-01-S02 passed: Q21 assignment/student drill-down, exact source pointers, shared metrics, signed cursors, and identity boundary are frozen.\n";

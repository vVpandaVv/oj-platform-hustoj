<?php
/**
 * G5-IMP-04-S02 student-lens per-student cross-worksheet report detail contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Student_Report_Detail;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Student_Report_Detail_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Student_Report_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Score_Lookup_Query;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Student_Report_Detail_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Report_Source_Query;

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
	'src/Reporting/Report/Domain/Student_Report_Detail_Projector.php',
	'src/Reporting/Report/Port/Student_Report_Detail_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Worksheet_Report_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Official_Assessment_Score_Lookup_Port_Interface.php',
	'src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Report_Source_Query.php',
	'src/Reporting/Report/Application/Read_Student_Report_Detail.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Student_Report_Detail_Source_Query.php',
	'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Score_Lookup_Query.php',
) as $file ) {
	require_once ABSPATH . $file;
}

/**
 * S02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s02_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * S02 id.
 *
 * @param int $value Value.
 * @return string
 */
function s02_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * S02 hash.
 *
 * @param string $value Value.
 * @return string
 */
function s02_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * G5-IMP-04-S02 student-lens per-student cross-worksheet report detail contract.
 */
final class S02_Database {
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
	 * Initialize s02 database dependencies and state.
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
 * G5-IMP-04-S02 student-lens per-student cross-worksheet report detail contract.
 */
final class S02_Fixed_Detail implements Student_Report_Detail_Source_Query_Port_Interface {
	/**
	 * Initialize s02 fixed detail dependencies and state.
	 *
	 * @param null|array{source:array<string,mixed>,next_cursor:string} $page Page.
	 */
	public function __construct( private ?array $page ) {
	}
	/**
	 * For student.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $student_target_id Student target id.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array|null
	 */
	public function for_student( int $actor_user_id, string $context_type, string $context_id, string $context_revision, string $student_target_id, string $cursor, int $limit, ?Report_Filter $filter = null ): ?array {
		return $this->page;
	}
}

/**
 * G5-IMP-04-S02 student-lens per-student cross-worksheet report detail contract.
 */
final class S02_Fixed_Worksheets implements Worksheet_Report_Source_Query_Port_Interface {
	/**
	 * Initialize s02 fixed worksheets dependencies and state.
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
 * G5-IMP-04-S02 student-lens per-student cross-worksheet report detail contract.
 */
final class S02_Fixed_Scores implements Official_Assessment_Score_Lookup_Port_Interface {
	/**
	 * Initialize s02 fixed scores dependencies and state.
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

/**
 * S02 worksheet row.
 *
 * @param string         $worksheet_id Worksheet public identifier.
 * @param string         $title Title.
 * @param string         $publication_id Publication id.
 * @param string         $publication_hash Publication hash.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function s02_worksheet_row( string $worksheet_id, string $title, string $publication_id, string $publication_hash, Canonical_Json $canonical ): array {
	$criterion_id    = s02_id( 900 );
	$grading_payload = array(
		'bindings'    => array( array( 'criterion_id' => $criterion_id ) ),
		'policy'      => array(),
		'review_rule' => array(),
		'rubric'      => array(
			'content_hash' => s02_hash( 'rubric-' . $worksheet_id ),
			'revision_id'  => s02_id( 950 ),
			'total_points' => '10',
		),
	);
	$grading_profile = array(
		'payload'           => $grading_payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $grading_payload ) ),
		'projector_version' => '1.1.0',
		'schema_id'         => 'oj_ai.grading_schema',
		'schema_version'    => '1.1.0',
		'source_hash'       => s02_hash( 'revision-' . $worksheet_id ),
	);
	$grading_json    = $canonical->encode( $grading_profile );
	return array(
		'worksheet_id'             => $worksheet_id,
		'title'                    => $title,
		'worksheet_state'          => 'active',
		'worksheet_updated_at_utc' => '2026-08-01 09:00:00',
		'revision_id'              => s02_id( 950 ),
		'revision_hash'            => s02_hash( 'revision-' . $worksheet_id ),
		'publication_id'           => $publication_id,
		'publication_hash'         => $publication_hash,
		'publication_no'           => '1',
		'publication_state'        => 'active',
		'published_at_utc'         => '2026-08-02 09:00:00',
		'publication_content_hash' => $publication_hash,
		'grading_schema_json'      => $grading_json,
		'grading_schema_hash'      => hash( 'sha256', $grading_json ),
	);
}

$canonical    = new Canonical_Json();
$cursor_codec = new Signed_Cursor_Codec( $canonical, 's02-contract-secret-2026-09-06' );

$target_id          = s02_id( 100 );
$publication_a      = s02_id( 600 );
$publication_a_hash = s02_hash( 'pub-600' );
$publication_b      = s02_id( 601 );
$publication_b_hash = s02_hash( 'pub-601' );
$review_id          = s02_id( 200 );
$review_hash        = s02_hash( 'review-200' );
$release_id         = s02_id( 300 );
$release_hash       = s02_hash( 'release-300' );

$display_json    = $canonical->encode( array( 'display_name' => 'Kim MinJun' ) );
$target_row      = array(
	'target_id'                => $target_id,
	'target_hash'              => s02_hash( 'target-100' ),
	'learner_user_id'          => 501,
	'membership_revision'      => 'rev-1',
	'display_snapshot_schema'  => 'oj_ai.assignment_target_display',
	'display_snapshot_version' => 1,
	'display_snapshot_json'    => $display_json,
	'display_snapshot_hash'    => hash( 'sha256', $display_json ),
	'target_snapshot_hash'     => s02_hash( 'snapshot-100' ),
	'target_created_at_utc'    => '2026-08-01 00:00:00',
);
$assignment_rows = array(
	array(
		'assignment_id'             => s02_id( 400 ),
		'publication_id'            => $publication_a,
		'publication_hash'          => $publication_a_hash,
		'title_snapshot'            => 'Assignment A',
		'state'                     => 'open',
		'due_at_utc'                => '2026-08-25 09:00:00',
		'updated_at_utc'            => '2026-08-24 10:00:00',
		'submission_id'             => s02_id( 500 ),
		'submission_updated_at_utc' => '2026-08-24 09:59:00',
		'submission_state'          => 'official',
		'started_at_utc'            => '2026-08-20 09:00:00',
		'submitted_at_utc'          => '2026-08-24 09:58:00',
		'official_revision_id'      => s02_id( 501 ),
		'official_revision_hash'    => s02_hash( 'revision-501' ),
		'review_id'                 => $review_id,
		'review_hash'               => $review_hash,
		'release_id'                => $release_id,
		'release_hash'              => $release_hash,
	),
	array(
		'assignment_id'             => s02_id( 401 ),
		'publication_id'            => $publication_b,
		'publication_hash'          => $publication_b_hash,
		'title_snapshot'            => 'Assignment B',
		'state'                     => 'open',
		'due_at_utc'                => null,
		'updated_at_utc'            => '2026-08-20 09:00:00',
		'submission_id'             => null,
		'submission_updated_at_utc' => null,
		'submission_state'          => null,
		'started_at_utc'            => null,
		'submitted_at_utc'          => null,
		'official_revision_id'      => null,
		'official_revision_hash'    => null,
		'review_id'                 => null,
		'review_hash'               => null,
		'release_id'                => null,
		'release_hash'              => null,
	),
);

$detail_database = new S02_Database( array( array( $target_row ), $assignment_rows ) );
$detail_adapter  = new WordPress_Student_Report_Detail_Source_Query( $detail_database, $canonical, $cursor_codec );
$detail_page     = $detail_adapter->for_student( 77, 'class', 'class-s02', 'revision-s02', $target_id, '', 30 );
s02_assert( null !== $detail_page, 'Detail page must resolve for a known student target.' );
s02_same( 2, count( $detail_page['source']['assignments'] ), 'Detail source must carry both of this student\'s assignments.' );
s02_same( '', $detail_page['next_cursor'], 'No cursor expected when the page fits exactly.' );
s02_assert( str_contains( implode( "\n", $detail_database->sql ), "a.context_revision = 'revision-s02'" ), 'Detail query must pin exact Context revision.' );

$not_found_database = new S02_Database( array( array() ) );
$not_found_adapter  = new WordPress_Student_Report_Detail_Source_Query( $not_found_database, $canonical, $cursor_codec );
$not_found_page     = $not_found_adapter->for_student( 77, 'class', 'class-s02', 'revision-s02', $target_id, '', 30 );
s02_same( null, $not_found_page, 'An unknown student target must resolve to null, not an empty source.' );

$worksheet_database = new S02_Database(
	array(
		array(
			s02_worksheet_row( s02_id( 800 ), 'Worksheet A', $publication_a, $publication_a_hash, $canonical ),
			s02_worksheet_row( s02_id( 801 ), 'Worksheet B', $publication_b, $publication_b_hash, $canonical ),
		),
	)
);
$worksheet_sources  = ( new WordPress_Worksheet_Report_Source_Query( $worksheet_database, $canonical ) )->by_publications(
	array(
		array(
			'id'   => $publication_a,
			'hash' => $publication_a_hash,
		),
		array(
			'id'   => $publication_b,
			'hash' => $publication_b_hash,
		),
	)
);
s02_same( 2, count( $worksheet_sources ), 'Both referenced worksheets must resolve.' );

$score_database = new S02_Database(
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
$score_rows     = $score_lookup->by_review_refs(
	array(
		array(
			'review'  => array(
				'id'   => $review_id,
				'hash' => $review_hash,
			),
			'release' => array(
				'id'   => $release_id,
				'hash' => $release_hash,
			),
		),
	)
);
s02_same( 8000, $score_rows[0]['normalized_basis_points'], 'Normalized score drifted.' );

$projector  = new Student_Report_Detail_Projector( $canonical );
$projection = $projector->project( $detail_page['source'], $worksheet_sources, $score_rows );
s02_same( 'Kim MinJun', $projection['student']['display_name'], 'Projected student display name drifted.' );
s02_same( 2, count( $projection['items'] ), 'Both assignments must be projected.' );
$first_item  = $projection['items'][0];
$second_item = $projection['items'][1];
s02_same( 'Worksheet A', $first_item['worksheet']['title'], 'First item must be the most recently updated assignment (Worksheet A).' );
s02_same( 'released', $first_item['status'], 'Reviewed and released assignment must show status=released.' );
s02_same( '80.0', $first_item['official_assessment']['score']['value'], 'Individual score drifted.' );
s02_same( 'not_submitted', $second_item['status'], 'Never-submitted assignment must show status=not_submitted.' );
s02_same( null, $second_item['submission'], 'Never-submitted assignment must carry no submission.' );
s02_same( null, $second_item['official_assessment'], 'Never-submitted assignment must carry no assessment.' );

$application = new Read_Student_Report_Detail(
	new S02_Fixed_Detail( $detail_page ),
	new S02_Fixed_Worksheets( $worksheet_sources ),
	new S02_Fixed_Scores( $score_rows ),
	$projector,
	$canonical,
	static fn (): string => '2026-09-06 10:05:00'
);
$page        = $application->detail( 77, 'class', 'class-s02', 'revision-s02', $target_id, '', 30 );
s02_same( 'student', $page['report']['lens'], 'Detail lens must report lens=student.' );
s02_same( 'student_detail', $page['report']['level'], 'Detail level must report student_detail.' );
s02_same( 'Kim MinJun', $page['report']['student']['display_name'], 'Application must forward the resolved student.' );
s02_same( 2, count( $page['report']['items'] ), 'Application must forward both projected assignments.' );
foreach ( array( 'filter_hash', 'projection_hash' ) as $hash_key ) {
	s02_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $page['report'][ $hash_key ] ), 'Hash is missing: ' . $hash_key );
}

$not_found_application = new Read_Student_Report_Detail(
	new S02_Fixed_Detail( null ),
	new S02_Fixed_Worksheets( array() ),
	new S02_Fixed_Scores( array() ),
	$projector,
	$canonical
);
try {
	$not_found_application->detail( 77, 'class', 'class-s02', 'revision-s02', $target_id, '', 30 );
	throw new RuntimeException( 'An unresolved student must fail the use case.' );
} catch ( Report_Query_Failure $failure ) {
	s02_same( Report_Query_Failure::NOT_FOUND, $failure->reason(), 'Unresolved student must fail as report_not_found.' );
}

$production_paths = array(
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Student_Report_Detail_Source_Query.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Student_Report_Detail_Rest_Controller.php',
);
$production       = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_paths );
foreach ( $production as $source ) {
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		s02_assert( ! str_contains( $source, $forbidden ), 'Contains a forbidden boundary marker: ' . $forbidden );
	}
}
s02_assert(
	! str_contains( $production[0], 'oj_ai_submission_evaluation_' ) && ! str_contains( $production[0], 'oj_ai_worksheets' ),
	'Assignment-owned detail query crossed an owner table boundary.'
);
s02_assert( str_contains( $production[1], 'Addon_Capabilities::VIEW_REPORTS' ), 'Must require view_reports.' );
s02_assert( str_contains( $production[1], 'Access_Policy_Request::SHELL_CONSOLE' ), 'Must use the Console shell policy.' );
s02_assert( str_contains( $production[1], 'Context_Scope::OPERATION_REPORT' ), 'Must authorize exact report Context.' );
s02_assert( str_contains( $production[1], '$this->permissions->take( $request )' ), 'Callback must consume the permission handoff.' );

echo "G5-IMP-04-S02 passed: per-student cross-worksheet report owner DTOs, hashes, cursor, exact Context, and not-found handling are frozen.\n";

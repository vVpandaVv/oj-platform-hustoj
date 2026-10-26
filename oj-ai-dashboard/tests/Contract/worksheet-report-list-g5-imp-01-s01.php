<?php
/**
 * G5-IMP-01-S01 Q20 worksheet report list contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Worksheet_Reports;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Worksheet_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Assignment_Progress_Report_Source_Query;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Report_Source_Query;
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
	'src/Reporting/Report/Application/Report_Query_Failure.php',
	'src/Reporting/Report/Domain/Report_Filter.php',
	'src/Reporting/Report/Domain/Report_Source_Validator.php',
	'src/Reporting/Report/Domain/Report_Denominator_Contract.php',
	'src/Reporting/Report/Port/Assignment_Progress_Source_Query_Port_Interface.php',
	'src/Security/Policy/Policy_Value.php',
	'src/Reporting/Report/Port/Worksheet_Report_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Port/Official_Assessment_Source_Query_Port_Interface.php',
	'src/Reporting/Report/Domain/Report_Metrics.php',
	'src/Reporting/Report/Domain/Worksheet_Report_Projector.php',
	'src/Reporting/Report/Application/Read_Worksheet_Reports.php',
	'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Progress_Report_Source_Query.php',
	'src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Report_Source_Query.php',
	'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Report_Source_Query.php',
) as $file ) {
	require_once ABSPATH . $file;
}

/**
 * Q20 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q20_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Q20 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function q20_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * Q20 id.
 *
 * @param int $value Value.
 * @return string
 */
function q20_id( int $value ): string {
	return str_pad( (string) $value, 26, '0', STR_PAD_LEFT );
}

/**
 * Q20 hash.
 *
 * @param string $value Value.
 * @return string
 */
function q20_hash( string $value ): string {
	return hash( 'sha256', $value );
}

/**
 * Q20 rehash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $source Source.
 * @return array<string,mixed>
 */
function q20_rehash( Canonical_Json $canonical, array $source ): array {
	unset( $source['source_hash'] );
	$source['source_hash'] = hash( 'sha256', $canonical->encode( $source ) );
	return $source;
}

/**
 * G5-IMP-01-S01 Q20 worksheet report list contract.
 */
final class Q20_Database {
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
	 * Initialize q20 database dependencies and state.
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
 * G5-IMP-01-S01 Q20 worksheet report list contract.
 */
final class Q20_Fixed_Progress implements Assignment_Progress_Source_Query_Port_Interface {
	/**
	 * Initialize q20 fixed progress dependencies and state.
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
 * G5-IMP-01-S01 Q20 worksheet report list contract.
 */
final class Q20_Fixed_Worksheets implements Worksheet_Report_Source_Query_Port_Interface {
	/**
	 * Initialize q20 fixed worksheets dependencies and state.
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
 * G5-IMP-01-S01 Q20 worksheet report list contract.
 */
final class Q20_Fixed_Assessments implements Official_Assessment_Source_Query_Port_Interface {
	/**
	 * Initialize q20 fixed assessments dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * By official reviews.
	 *
	 * @param array $progress_sources Progress sources.
	 * @return array
	 */
	public function by_official_reviews( array $progress_sources ): array {
		return $this->sources;
	}
}

$canonical             = new Canonical_Json();
$cursor_codec          = new Signed_Cursor_Codec( $canonical, 'q20-contract-secret-2026-08-24' );
$publication_id        = q20_id( 100 );
$publication_hash      = q20_hash( 'publication-q20' );
$second_publication_id = q20_id( 101 );
$criterion_id          = q20_id( 200 );

$summary_rows    = array(
	array(
		'publication_id'            => $publication_id,
		'publication_hash'          => $publication_hash,
		'assignment_count'          => '1',
		'target_count'              => '8',
		'official_submission_count' => '6',
		'official_review_count'     => '5',
		'latest_activity_at_utc'    => '2026-08-24 10:00:00',
	),
	array(
		'publication_id'            => $second_publication_id,
		'publication_hash'          => q20_hash( 'publication-q20-second' ),
		'assignment_count'          => '1',
		'target_count'              => '1',
		'official_submission_count' => '0',
		'official_review_count'     => '0',
		'latest_activity_at_utc'    => '2026-08-24 09:00:00',
	),
);
$assignment_rows = array(
	array(
		'assignment_id'             => q20_id( 300 ),
		'assignment_hash'           => q20_hash( 'assignment-q20' ),
		'publication_id'            => $publication_id,
		'publication_hash'          => $publication_hash,
		'title_snapshot'            => '분수의 덧셈',
		'state'                     => 'open',
		'due_at_utc'                => '2026-08-30 09:00:00',
		'target_snapshot_hash'      => q20_hash( 'target-q20' ),
		'updated_at_utc'            => '2026-08-24 09:50:00',
		'target_count'              => '8',
		'official_submission_count' => '6',
		'official_review_count'     => '5',
	),
);
$review_refs     = array();
$review_rows     = array();
$release_rows    = array();
for ( $index = 0; $index < 5; ++$index ) {
	$review_id     = q20_id( 400 + $index );
	$review_hash   = q20_hash( 'review-q20-' . $index );
	$release_id    = $index < 4 ? q20_id( 500 + $index ) : null;
	$release_hash  = $index < 4 ? q20_hash( 'release-q20-' . $index ) : null;
	$review_refs[] = array(
		'publication_id'   => $publication_id,
		'publication_hash' => $publication_hash,
		'review_id'        => $review_id,
		'review_hash'      => $review_hash,
		'release_id'       => $release_id,
		'release_hash'     => $release_hash,
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
		'review_hash'             => $review_hash,
		'decision'                => 'accepted',
		'official_score'          => '8.0000',
		'score_overrides_schema'  => 'oj_ai.submission_score_decisions',
		'score_overrides_version' => '1',
		'score_overrides_json'    => $score_json,
		'score_overrides_hash'    => hash( 'sha256', $score_json ),
		'created_at_utc'          => '2026-08-24 09:55:0' . $index,
		'result_id'               => q20_id( 600 + $index ),
		'result_hash'             => q20_hash( 'result-q20-' . $index ),
		'maximum_score'           => '10.0000',
	);
	if ( null !== $release_id ) {
		$release_rows[] = array(
			'release_id'      => $release_id,
			'release_hash'    => $release_hash,
			'state'           => 'released',
			'released_at_utc' => '2026-08-24 09:58:0' . $index,
			'review_id'       => $review_id,
		);
	}
}

$progress_database = new Q20_Database( array( $summary_rows, $assignment_rows, $review_refs ) );
$progress_adapter  = new WordPress_Assignment_Progress_Report_Source_Query( $progress_database, $canonical, $cursor_codec );
$progress_page     = $progress_adapter->list_context( 77, 'class', 'class-q20', 'revision-q20', '', 1 );
q20_same( 1, count( $progress_page['items'] ), 'Q20 cursor page must contain one worksheet publication.' );
q20_assert( '' !== $progress_page['next_cursor'], 'Q20 must return a signed cursor when another worksheet remains.' );
q20_assert( strlen( $progress_page['next_cursor'] ) <= 191, 'Q20 signed cursor exceeds QueryInputV1.' );
$decoded_cursor = $cursor_codec->decode( $progress_page['next_cursor'], 'report_list' );
q20_same( $publication_id, $decoded_cursor['p'] ?? null, 'Q20 cursor must pin the last publication.' );
q20_assert( str_contains( implode( "\n", $progress_database->sql ), "a.context_revision = 'revision-q20'" ), 'Assignment source must pin exact Context revision.' );

$grading_payload    = array(
	'bindings'    => array( array( 'criterion_id' => $criterion_id ) ),
	'policy'      => array(),
	'review_rule' => array(),
	'rubric'      => array(
		'content_hash' => q20_hash( 'rubric-q20' ),
		'revision_id'  => q20_id( 700 ),
		'total_points' => '10',
	),
);
$grading_profile    = array(
	'payload'           => $grading_payload,
	'payload_hash'      => hash( 'sha256', $canonical->encode( $grading_payload ) ),
	'projector_version' => '1.1.0',
	'schema_id'         => 'oj_ai.grading_schema',
	'schema_version'    => '1.1.0',
	'source_hash'       => q20_hash( 'revision-q20' ),
);
$grading_json       = $canonical->encode( $grading_profile );
$worksheet_database = new Q20_Database(
	array(
		array(
			array(
				'worksheet_id'             => q20_id( 800 ),
				'title'                    => '분수의 덧셈',
				'worksheet_state'          => 'active',
				'worksheet_updated_at_utc' => '2026-08-24 09:40:00',
				'revision_id'              => q20_id( 801 ),
				'revision_hash'            => q20_hash( 'revision-q20' ),
				'publication_id'           => $publication_id,
				'publication_hash'         => $publication_hash,
				'publication_no'           => '1',
				'publication_state'        => 'active',
				'published_at_utc'         => '2026-08-20 09:00:00',
				'publication_content_hash' => $publication_hash,
				'grading_schema_json'      => $grading_json,
				'grading_schema_hash'      => hash( 'sha256', $grading_json ),
			),
		),
	)
);
$worksheet_sources  = ( new WordPress_Worksheet_Report_Source_Query( $worksheet_database, $canonical ) )->by_publications(
	array(
		array(
			'id'   => $publication_id,
			'hash' => $publication_hash,
		),
	)
);
q20_same( '분수의 덧셈', $worksheet_sources[0]['worksheet']['title'], 'Worksheet owner DTO title drifted.' );
q20_same( 1, $worksheet_sources[0]['rubric']['criterion_count'], 'Worksheet owner DTO rubric summary drifted.' );

$assessment_database = new Q20_Database( array( $review_rows, $release_rows ) );
$assessment_sources  = ( new WordPress_Official_Assessment_Report_Source_Query( $assessment_database, $canonical ) )->by_official_reviews( $progress_page['items'] );
q20_same( 5, $assessment_sources[0]['official_review_count'], 'Only five current official review pointers may be aggregated.' );
q20_same( 4, $assessment_sources[0]['released_count'], 'Only current released or corrected pointers may be counted.' );
q20_same( 40000, $assessment_sources[0]['normalized_basis_points_sum'], 'Official normalized score sum drifted.' );

$projector  = new Worksheet_Report_Projector( $canonical );
$projection = $projector->project( $progress_page['items'], $worksheet_sources, $assessment_sources );
$item       = $projection['items'][0];
q20_same(
	array(
		'numerator'   => 6,
		'denominator' => 8,
		'value'       => '75.0',
	),
	$item['progress']['submission_rate'],
	'Submission rate denominator drifted.'
);
q20_same(
	array(
		'numerator'   => 5,
		'denominator' => 6,
		'value'       => '83.3',
	),
	$item['progress']['assessment_rate'],
	'Assessment rate denominator drifted.'
);
q20_same(
	array(
		'numerator'   => 4,
		'denominator' => 5,
		'value'       => '80.0',
	),
	$item['progress']['release_rate'],
	'Release rate denominator drifted.'
);
q20_same(
	array(
		'value'      => '80.0',
		'n'          => 5,
		'suppressed' => false,
		'reason'     => null,
	),
	$item['progress']['average_score'],
	'Five official scores must be visible.'
);
q20_same( 1, $item['progress']['review_required_count'], 'Review-required count drifted.' );

$small_progress                          = $progress_page['items'][0];
$small_progress['official_review_count'] = 4;
$small_progress['official_review_refs']  = array_slice( $small_progress['official_review_refs'], 0, 4 );
$small_progress['assignments'][0]['official_review_count'] = 4;
$small_progress                                  = q20_rehash( $canonical, $small_progress );
$small_assessment                                = $assessment_sources[0];
$small_assessment['official_review_count']       = 4;
$small_assessment['scored_review_count']         = 4;
$small_assessment['normalized_basis_points_sum'] = 32000;
$small_assessment['reviews']                     = array_slice( $small_assessment['reviews'], 0, 4 );
$small_assessment['criterion_totals'][0]['normalized_basis_points_sum'] = 32000;
$small_assessment['criterion_totals'][0]['n']                           = 4;
$small_assessment = q20_rehash( $canonical, $small_assessment );
$small            = $projector->project( array( $small_progress ), $worksheet_sources, array( $small_assessment ) );
q20_same(
	array(
		'value'      => null,
		'n'          => 4,
		'suppressed' => true,
		'reason'     => 'small_group',
	),
	$small['items'][0]['progress']['average_score'],
	'Four-score aggregate must be suppressed.'
);

$tampered                   = $assessment_sources[0];
$tampered['released_count'] = 5;
try {
	$projector->project( $progress_page['items'], $worksheet_sources, array( $tampered ) );
	throw new RuntimeException( 'Tampered owner source hash was accepted.' );
} catch ( Report_Query_Failure $failure ) {
	q20_same( Report_Query_Failure::SOURCE_STALE, $failure->reason(), 'Tampered source must fail as report_source_stale.' );
}

$application = new Read_Worksheet_Reports(
	new Q20_Fixed_Progress( $progress_page ),
	new Q20_Fixed_Worksheets( $worksheet_sources ),
	new Q20_Fixed_Assessments( $assessment_sources ),
	$projector,
	$canonical,
	static fn (): string => '2026-08-24 10:05:00'
);
$page        = $application->list( 77, 'class', 'class-q20', 'revision-q20', '', 1 );
q20_same( 'worksheet', $page['report']['lens'], 'Q20 default lens must be worksheet.' );
q20_same( 'oj_ai.reporting_metrics@1.0.0', $page['report']['metric_contract'], 'Metric contract drifted.' );
q20_same( '2026-08-24 10:05:00', $page['report']['baseline_at_utc'], 'Q20 baseline time drifted.' );
foreach ( array( 'filter_hash', 'source_manifest_hash', 'projection_hash' ) as $hash_key ) {
	q20_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $page['report'][ $hash_key ] ), 'Q20 hash is missing: ' . $hash_key );
}

$production_paths = array(
	ABSPATH . 'src/Submission/Assignment/Infrastructure/WordPress_Assignment_Progress_Report_Source_Query.php',
	ABSPATH . 'src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Report_Source_Query.php',
	ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Official_Assessment_Report_Source_Query.php',
	ABSPATH . 'src/Reporting/Report/Domain/Worksheet_Report_Projector.php',
	ABSPATH . 'src/Reporting/Report/Application/Read_Worksheet_Reports.php',
	ABSPATH . 'src/Reporting/Report/Presentation/Worksheet_Report_Rest_Controller.php',
);
$production       = array_map( static fn ( string $path ): string => (string) file_get_contents( $path ), $production_paths );
foreach ( $production as $source ) {
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		q20_assert( ! str_contains( $source, $forbidden ), 'Q20 contains a forbidden boundary marker: ' . $forbidden );
	}
}
q20_assert( ! str_contains( $production[0], 'oj_ai_worksheets' ) && ! str_contains( $production[0], 'oj_ai_submission_evaluation_' ), 'Assignment source crossed an owner table boundary.' );
q20_assert( ! str_contains( $production[1], 'oj_ai_assignments' ) && ! str_contains( $production[1], 'oj_ai_submissions' ), 'Worksheet source crossed an owner table boundary.' );
q20_assert( ! str_contains( $production[2], 'oj_ai_assignments' ) && ! str_contains( $production[2], 'oj_ai_worksheets' ), 'Assessment source crossed an owner table boundary.' );
q20_assert( str_contains( $production[5], 'Addon_Capabilities::VIEW_REPORTS' ), 'Q20 must require view_reports.' );
q20_assert( str_contains( $production[5], 'Access_Policy_Request::SHELL_CONSOLE' ), 'Q20 must use the Console shell policy.' );
q20_assert( str_contains( $production[5], 'Context_Scope::OPERATION_REPORT' ), 'Q20 must authorize exact report Context.' );
q20_assert( str_contains( $production[5], '$this->permissions->take( $request )' ), 'Q20 callback must consume the permission handoff.' );

echo "G5-IMP-01-S01 passed: Q20 owner DTOs, hashes, cursor, exact Context, denominators, and small-group suppression are frozen.\n";

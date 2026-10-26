<?php
/**
 * Executes result and report SQL against the actual catalog's columns.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Report_Source_Query;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Official_Assessment_Score_Lookup_Query;

ob_start();
require __DIR__ . '/submission-result-query-g4-imp-06-s02.php';
ob_end_clean();

/**
 * Executes result and report SQL against the actual catalog's columns.
 */
final class Result_Report_Sql_Database {
	/**
	 * Connection.
	 *
	 * @var PDO
	 */
	public PDO $connection;
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Queries.
	 *
	 * @var array
	 */
	public array $queries = array();
	/**
	 * Initialize result report sql database dependencies and state.
	 */
	public function __construct() {
		$this->connection = new PDO( 'sqlite::memory:' );
		$this->connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$catalog = new Schema_Catalog();
		foreach ( array( 'oj_ai_submission_release_revisions', 'oj_ai_submission_evaluations', 'oj_ai_submission_revisions', 'oj_ai_submissions', 'oj_ai_assignments', 'oj_ai_assignment_targets', 'oj_ai_submission_evaluation_reviews', 'oj_ai_submission_feedback_revisions', 'oj_ai_submission_evaluation_results' ) as $name ) {
			$columns = array();
			foreach ( $catalog->table( $name )->columns() as $column => $type ) {
				$columns[] = $column . ( str_contains( $type, 'INT' ) ? ' INTEGER' : ' TEXT' );
			}
			$this->connection->exec( 'CREATE TABLE ' . $name . ' (' . implode( ',', $columns ) . ')' );
		}
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		$index = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ];
				return '%d' === $match[0] ? (string) (int) $value : $this->connection->quote( (string) $value );
			},
			$sql
		);
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $sql, string $format ): ?array {
		$this->queries[] = $sql;
		try {
			$row = $this->connection->query( $sql )->fetch( PDO::FETCH_ASSOC );
			return $row ? $row : null;
		} catch ( PDOException $error ) {
			$this->last_error = $error->getMessage();
			throw $error;
		}
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array
	 */
	public function get_results( string $sql, string $format ): array {
		$this->queries[] = $sql;
		return $this->connection->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $row Row.
	 * @return void
	 */
	public function insert( string $table, array $row ): void {
		$this->connection->prepare( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $row ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $row ), '?' ) ) . ')' )->execute( array_values( $row ) );
	}
}

$sql_db    = new Result_Report_Sql_Database();
$sql_query = new WordPress_Submission_Result_Query( $q33_canonical, $sql_db );
try {
	g4_release_same( null, $sql_query->authorization_snapshot( $q33_record['release_public_id'], 42 ), 'Empty result SQL must execute, not return a storage failure.' );
} catch ( Throwable $error ) {
	throw new RuntimeException( $sql_db->last_error, 0, $error );
}

$source        = $q33_fixture['source'];
$record        = $q33_record;
$revision_id   = ( new \OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator() )->create()->value();
$revision_hash = str_repeat( 'a', 64 );
$sql_db->insert(
	'oj_ai_assignments',
	array(
		'id'               => 1,
		'context_type'     => $source['context_type'],
		'context_id'       => $source['context_id'],
		'context_revision' => $source['context_revision'],
		'state'            => 'open',
	)
);
$sql_db->insert(
	'oj_ai_assignment_targets',
	array(
		'id'              => 1,
		'assignment_id'   => 1,
		'learner_user_id' => 42,
		'state'           => 'active',
	)
);
$sql_db->insert(
	'oj_ai_submissions',
	array(
		'id'                          => 1,
		'public_id'                   => $source['submission_id'],
		'assignment_id'               => 1,
		'learner_user_id'             => 42,
		'official_revision_public_id' => $revision_id,
		'official_review_public_id'   => $record['expected_official_review_id'],
		'official_review_hash'        => $record['expected_official_review_hash'],
		'current_release_public_id'   => $record['release_public_id'],
		'current_release_hash'        => $record['content_hash'],
	)
);
$sql_db->insert(
	'oj_ai_submission_revisions',
	array(
		'id'            => 1,
		'public_id'     => $revision_id,
		'content_hash'  => $revision_hash,
		'submission_id' => 1,
	)
);
$sql_db->insert(
	'oj_ai_submission_evaluations',
	array(
		'id'                            => 1,
		'public_id'                     => $record['evaluation_public_id'],
		'submission_revision_public_id' => $revision_id,
		'submission_revision_hash'      => $revision_hash,
		'state'                         => 'reviewed',
	)
);
$sql_db->insert(
	'oj_ai_submission_evaluation_reviews',
	array(
		'id'                       => 1,
		'public_id'                => $record['expected_official_review_id'],
		'content_hash'             => $record['expected_official_review_hash'],
		'submission_evaluation_id' => 1,
	)
);
$sql_db->insert(
	'oj_ai_submission_feedback_revisions',
	array(
		'id'                       => 1,
		'public_id'                => $record['expected_feedback_revision_id'],
		'submission_evaluation_id' => 1,
		'submission_review_id'     => 1,
	)
);
$sql_db->insert(
	'oj_ai_submission_release_revisions',
	array(
		'id'                         => 1,
		'public_id'                  => $record['release_public_id'],
		'content_hash'               => $record['content_hash'],
		'submission_evaluation_id'   => 1,
		'submission_review_id'       => 1,
		'feedback_revision_id'       => 1,
		'release_no'                 => 1,
		'state'                      => 'released',
		'visible_score'              => $record['visible_score'],
		'student_projection_schema'  => $record['projection_schema'],
		'student_projection_version' => $record['projection_version'],
		'student_projection_json'    => $record['projection_json'],
		'student_projection_hash'    => $record['projection_hash'],
		'released_at_utc'            => $record['released_at_utc'],
	)
);

try {
	$snapshot = $sql_query->authorization_snapshot( $record['release_public_id'], 42 );
	g4_release_same( $record['release_public_id'], $snapshot['release_id'] ?? null, 'Owner current release must be selected by real SQL.' );
	$context = $q33_contexts->for_actor( 42 )[ $source['context_type'] . ':' . $source['context_id'] ];
	$result  = $sql_query->find_current_for_actor( $record['release_public_id'], 42, $context );
	g4_release_same( $record['projection_hash'], hash( 'sha256', $q33_canonical->encode( $result['projection'] ) ), 'Real SQL must return the exact published projection.' );
} catch ( Throwable $error ) {
	$message = $sql_db->last_error;
	throw new RuntimeException( $message ? $message : $error->getMessage(), 0, $error );
}
g4_release_same( null, $sql_query->authorization_snapshot( $record['release_public_id'], 99 ), 'Another learner must not read the result.' );
$sql_db->connection->exec( "UPDATE oj_ai_submission_release_revisions SET state = 'withdrawn'" );
$withdrawn = $sql_query->authorization_snapshot( $record['release_public_id'], 42 );
g4_release_same( 'withdrawn', $withdrawn['release_state'], 'The policy must receive the withdrawn state.' );
g4_release_same( false, array_key_exists( 'projection', $withdrawn ), 'Authorization metadata must never contain a withdrawn result body.' );
$sql_db->connection->exec( "UPDATE oj_ai_submission_release_revisions SET state = 'released'" );
$sql_db->connection->exec( "UPDATE oj_ai_submission_revisions SET content_hash = 'invalid'" );
g4_release_same( null, $sql_query->authorization_snapshot( $record['release_public_id'], 42 ), 'A mismatched official revision hash must be denied.' );
$sql_db->connection->prepare( 'UPDATE oj_ai_submission_revisions SET content_hash = ?' )->execute( array( $revision_hash ) );
$sql_db->connection->exec( "UPDATE oj_ai_submissions SET official_review_hash = 'invalid'" );
g4_release_same( null, $sql_query->authorization_snapshot( $record['release_public_id'], 42 ), 'A stale official review must not expose a release.' );

$report_sql = new WordPress_Official_Assessment_Report_Source_Query( $sql_db, $q33_canonical );
foreach ( array( 'review_rows', 'release_rows' ) as $method ) {
	$read = new ReflectionMethod( $report_sql, $method );
	$read->invoke( $report_sql, array( $record['release_public_id'] ) );
}
$lookup_sql  = new WordPress_Official_Assessment_Score_Lookup_Query( $sql_db );
$lookup_read = new ReflectionMethod( $lookup_sql, 'release_rows' );
$lookup_read->invoke( $lookup_sql, array( $record['release_public_id'] ) );
// SQLite accepts this alias, but RELEASE is reserved in MySQL and MariaDB.
foreach ( $sql_db->queries as $sql ) {
	g4_release_same( 0, preg_match( '/\brelease\s*\./i', $sql ), 'Report SQL must not use the unquoted reserved alias RELEASE.' );
}

$criterion_id   = ( new \OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator() )->create()->value();
$publication_id = ( new \OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator() )->create()->value();
$score_items    = $source['score_decisions'];
foreach ( $score_items as &$score_item ) {
	$score_item['criterion_id'] = $criterion_id;
}
unset( $score_item );
$score_json = $q33_canonical->encode(
	array(
		'schema_id'      => 'oj_ai.submission_score_decisions',
		'schema_version' => '1.0.0',
		'items'          => $score_items,
	)
);
$sql_db->insert(
	'oj_ai_submission_evaluation_results',
	array(
		'id'            => 1,
		'public_id'     => $source['result_id'],
		'content_hash'  => $source['result_hash'],
		'maximum_score' => '10.0000',
	)
);
$sql_db->connection->prepare( 'UPDATE oj_ai_submission_evaluation_reviews SET submission_evaluation_result_id = 1, decision = ?, official_score = ?, score_overrides_schema = ?, score_overrides_version = 1, score_overrides_json = ?, score_overrides_hash = ?, created_at_utc = ?' )->execute( array( 'accepted', '8.0000', 'oj_ai.submission_score_decisions', $score_json, hash( 'sha256', $score_json ), $record['released_at_utc'] ) );
$progress                = array(
	'schema_id'             => 'oj_ai.assignment_progress_source',
	'schema_version'        => '1.0.0',
	'publication'           => array(
		'id'   => $publication_id,
		'hash' => str_repeat( 'b', 64 ),
	),
	'official_review_count' => 1,
	'official_review_refs'  => array(
		array(
			'review'  => array(
				'id'   => $record['expected_official_review_id'],
				'hash' => $record['expected_official_review_hash'],
			),
			'release' => array(
				'id'   => $record['release_public_id'],
				'hash' => $record['content_hash'],
			),
		),
	),
);
$progress['source_hash'] = hash( 'sha256', $q33_canonical->encode( $progress ) );
$report_sources          = $report_sql->by_official_reviews( array( $progress ) );
g4_release_same( 8000, $report_sources[0]['normalized_basis_points_sum'], 'Two responses using one criterion must aggregate official points over their full maximum.' );
g4_release_same( 1, count( $report_sources[0]['criterion_totals'] ), 'One criterion applied twice must not become two criteria.' );
g4_release_same( 8000, $report_sources[0]['criterion_totals'][0]['normalized_basis_points_sum'], 'Criterion metric must weight both response maxima.' );
g4_release_same( 1, $report_sources[0]['released_count'], 'A current released result must be included in report totals.' );
g4_release_same( $record['release_public_id'], $report_sources[0]['reviews'][0]['release']['id'], 'A report must retain the matching public release reference.' );
$lookup_result = $lookup_sql->by_review_refs( $progress['official_review_refs'] );
g4_release_same( 8000, $lookup_result[0]['normalized_basis_points'], 'Cross-worksheet student reports must resolve the official score.' );
g4_release_same( 'released', $lookup_result[0]['release_state'], 'Student report lookup must resolve the current release.' );

foreach ( array( 'duplicate_binding', 'legacy_duplicate', 'mixed_duplicate', 'corrupt_hash' ) as $case ) {
	$invalid_items = $score_items;
	if ( 'duplicate_binding' === $case ) {
		$invalid_items[1]['binding_key'] = $invalid_items[0]['binding_key'];
	}
	if ( 'legacy_duplicate' === $case ) {
		foreach ( $invalid_items as &$item ) {
			unset( $item['binding_key'] );
		} unset( $item );
	}
	if ( 'mixed_duplicate' === $case ) {
		unset( $invalid_items[0]['binding_key'] );
	}
	$invalid_json = $q33_canonical->encode(
		array(
			'schema_id'      => 'oj_ai.submission_score_decisions',
			'schema_version' => '1.0.0',
			'items'          => $invalid_items,
		)
	);
	$sql_db->connection->prepare( 'UPDATE oj_ai_submission_evaluation_reviews SET score_overrides_json = ?, score_overrides_hash = ?' )->execute( array( $invalid_json, 'corrupt_hash' === $case ? str_repeat( '0', 64 ) : hash( 'sha256', $invalid_json ) ) );
	$rejected = false;
	try {
		$report_sql->by_official_reviews( array( $progress ) ); } catch ( \OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure $error ) {
		$rejected = true;
		}
		g4_release_same( true, $rejected, $case . ' must remain rejected.' );
}
echo "Result/report SQL passed: catalog columns, owner projection, current pointers and report joins.\n";

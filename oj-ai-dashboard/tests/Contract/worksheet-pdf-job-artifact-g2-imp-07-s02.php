<?php
/**
 * G2-IMP-07-S02 worksheet PDF job, render, artifact, and download contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf_Command;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Enqueue_Worksheet_Pdf_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Print_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $root . DIRECTORY_SEPARATOR );
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $root . '/src' ) )->register();

/**
 * G2s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G2s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g2s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G2s02 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g2s02_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g2s02_assert( Public_Id::is_valid( $id ), 'Fixture public ID is invalid.' );
	return $id;
}
/**
 * G2s02 element.
 *
 * @param string     $type Type.
 * @param int        $id Id.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array
 */
function g2s02_element( string $type, int $id, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => g2s02_id( $id ),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * G2-IMP-07-S02 worksheet PDF job, render, artifact, and download contract.
 */
final class G2S02_Worksheet_Repository implements Worksheet_Repository_Port_Interface {
	/**
	 * Initialize g2 s02 worksheet repository dependencies and state.
	 *
	 * @param array $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Create initial.
	 *
	 * @param array $record Record.
	 * @return Create_Worksheet_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_initial( array $record ): Create_Worksheet_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * Append draft revision.
	 *
	 * @param array $record Record.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result {
		throw new RuntimeException( 'Not used.' );
	}
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array {
		return array(
			'items'       => array(),
			'next_cursor' => '',
		);
	}
	/**
	 * Find owned worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array|null
	 */
	public function find_owned_worksheet( int $actor_user_id, string $worksheet_id ): ?array {
		return null;
	}
	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 */
	public function list_owned_revisions( int $actor_user_id, string $worksheet_id, string $cursor, int $limit ): ?array {
		return null;
	}
	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array {
		return 42 === $actor_user_id && $revision_id === ( $this->source['revision']['id'] ?? null ) ? $this->source : null;
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $object_type Object type.
	 * @param string $object_id Object id.
	 * @return array|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $object_type, string $object_id ): ?array {
		return null;
	}
}
/**
 * G2-IMP-07-S02 worksheet PDF job, render, artifact, and download contract.
 */
final class G2S02_Pdf_Repository implements Worksheet_Pdf_Repository_Port_Interface {
	/**
	 * Record.
	 *
	 * @var array|null
	 */
	public ?array $record = null;
	/**
	 * Receipt.
	 *
	 * @var Enqueue_Worksheet_Pdf_Result|null
	 */
	public ?Enqueue_Worksheet_Pdf_Result $receipt = null;
	/**
	 * Key.
	 *
	 * @var string
	 */
	public string $key = '';
	/**
	 * Digest.
	 *
	 * @var string
	 */
	public string $digest = '';
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Enqueue_Worksheet_Pdf_Result|null
	 */
	public function find_command_receipt( string $key, string $digest ): ?Enqueue_Worksheet_Pdf_Result {
		return $this->key === $key && $this->digest === $digest ? $this->receipt : null;
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Worksheet_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Pdf_Result {
		$this->record  = $record;
		$this->key     = $record['command_storage_key'];
		$this->digest  = $record['request_digest'];
		$this->receipt = new Enqueue_Worksheet_Pdf_Result( $record['job_public_id'], 'queued', $record['source_public_id'], $record['source_hash'], $record['queued_at_utc'], false );
		return $this->receipt;
	}
}
/**
 * G2-IMP-07-S02 worksheet PDF job, render, artifact, and download contract.
 */
final class G2S02_Queue implements Worksheet_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Wakes.
	 *
	 * @var int
	 */
	public int $wakes = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->wakes;
	}
}

$worksheet_id = g2s02_id( 1 );
$revision_id  = g2s02_id( 2 );
$response_id  = g2s02_id( 3 );
$criterion_id = g2s02_id( 4 );
$document     = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => g2s02_id( 5 ),
	'locale'         => 'ko-KR',
	'title'          => '한글 활동지 PDF',
	'nodes'          => array(
		g2s02_element( 'h1', 10, array(), array( array( 'text' => '관찰 활동' ) ) ),
		g2s02_element(
			'p',
			11,
			array(),
			array(
				array(
					'text' => '관찰한 내용을 적으세요.',
					'bold' => true,
				),
			)
		),
		g2s02_element(
			'oj_ai_response',
			12,
			array(
				'response_id' => $response_id,
				'kind'        => 'long_text',
				'label'       => '관찰 내용',
				'required'    => true,
				'constraints' => array(
					'min_chars' => 1,
					'max_chars' => 500,
				),
				'grading'     => array(
					'grading_enabled'      => true,
					'max_points'           => '5',
					'example_answer'       => '비공개 정답',
					'rubric_criterion_ids' => array( $criterion_id ),
					'teacher_note'         => '비공개 메모',
				),
			)
		),
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$canonical    = new Canonical_Json();
$ids          = new Public_Id_Generator();
$documents    = new Worksheet_Document_Canonicalizer( $canonical, $ids );
$receipt      = $documents->canonicalize( $document );
$previews     = new Worksheet_Preview_Projector( $canonical );
$source       = array(
	'worksheet_id'     => $worksheet_id,
	'revision'         => array(
		'id'              => $revision_id,
		'state'           => 'approved',
		'document_hash'   => $receipt->document_hash(),
		'validation_hash' => $receipt->validation_hash(),
	),
	'teacher_document' => $receipt->document(),
	'questions'        => array(),
);
$worksheets   = new G2S02_Worksheet_Repository( $source );
$builder      = new Build_Worksheet_Print_Projection( new WordPress_Worksheet_Print_Source_Query( $worksheets, $canonical ), $documents, $previews, new Worksheet_Projection_Safety_Inspector( $canonical ), $canonical );
$repository   = new G2S02_Pdf_Repository();
$queue        = new G2S02_Queue();
$enqueue      = new Enqueue_Worksheet_Pdf( $builder, $repository, $queue, $ids, $canonical, static fn(): string=>'2026-08-22 10:00:00', static fn(): string=>'Asia/Seoul' );
$command      = new Enqueue_Worksheet_Pdf_Command( 42, $revision_id, 'cmd-pdf-0001', $revision_id, $receipt->document_hash(), 'approved', 'student_blank', hash( 'sha256', 'idempotency' ), 'corr-pdf-0001', $revision_id, 'approved', 'owner', $canonical );
$result       = $enqueue->execute( $command );
g2s02_same( 'queued', $result->state(), 'C48 must enqueue a job.' );
g2s02_same( 1, $queue->wakes, 'Committed queue must be woken once.' );
g2s02_assert( is_array( $repository->record ), 'C48 must persist a frozen request.' );
$snapshot = Worksheet_Pdf_Request_Snapshot::restore( $repository->record['snapshot_json'], $repository->record['snapshot_hash'], $canonical );
g2s02_same( 'Asia/Seoul', $snapshot->value()['print']['timezone'], 'Timezone must be frozen.' );
g2s02_same( 'worksheet_print.v1', $snapshot->profile_version(), 'Print profile version drifted.' );
$snapshot_json = $snapshot->json();
g2s02_assert( ! str_contains( $snapshot_json, '비공개 정답' ) && ! str_contains( $snapshot_json, '비공개 메모' ), 'Teacher-only fields leaked into the PDF snapshot.' );
$replay = $enqueue->execute( $command );
g2s02_same( $result->job_id(), $replay->job_id(), 'Same command must replay the same job.' );
g2s02_same( 1, $queue->wakes, 'Command receipt replay must not wake the queue again.' );

$tampered                      = json_decode( $snapshot->json(), true, 128, JSON_THROW_ON_ERROR );
$tampered['print']['timezone'] = 'UTC';
$tampered_json                 = $canonical->encode( $tampered );
try {
	Worksheet_Pdf_Request_Snapshot::restore( $tampered_json, $snapshot->hash(), $canonical );
	throw new RuntimeException( 'Tampered snapshot must fail.' );} catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}

	$serializer = new Worksheet_Print_Html_Serializer();
	$html       = $serializer->serialize( $snapshot->projection(), $revision_id );
	g2s02_assert( str_contains( $html, '한글 활동지 PDF' ) && str_contains( $html, 'answer-box' ), 'Serializer lost document or response content.' );
	g2s02_assert( ! str_contains( strtolower( $html ), '<script' ) && ! str_contains( $html, '비공개 정답' ), 'Serializer emitted unsafe or private content.' );
	$cache    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oj-ai-dompdf-g2-s02';
	$renderer = new Dompdf_Html_To_Pdf_Renderer( null, $root, $cache );
	$pdf      = $renderer->render( $html );
	g2s02_assert( str_starts_with( $pdf->bytes(), '%PDF-' ), 'Dompdf output is not a PDF.' );
	g2s02_assert( $pdf->page_count() >= 1, 'Dompdf page count is empty.' );
	g2s02_same( 'dompdf-3.1.6', $pdf->renderer_version(), 'Dompdf lock version drifted.' );

	$catalog     = new Schema_Catalog();
	$job_columns = $catalog->table( 'oj_ai_export_jobs' )->columns();
	foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $column ) {
		g2s02_assert( str_ends_with( $job_columns[ $column ], ' NULL' ), 'Context-free C48 requires nullable export context: ' . $column );
	}
	$font = $root . '/resources/fonts/noto-sans-kr/NotoSansKR-Regular.ttf';
	g2s02_same( 'a6a59711be2adcb13e78699eb7c748d72fca8ad6f571598e21ff9729ddb79d92', hash_file( 'sha256', $font ), 'Packaged static font hash drifted.' );
	g2s02_assert( is_file( $root . '/resources/fonts/noto-sans-kr/OFL.txt' ), 'Font license is missing.' );
	$renderer_source = file_get_contents( $root . '/src/Reporting/WorksheetPdf/Infrastructure/Dompdf_Html_To_Pdf_Renderer.php' );
	g2s02_assert( is_string( $renderer_source ), 'PDF renderer source is unreadable.' );
	foreach ( array( 'stage_packaged_font', 'hash_file', 'oj-ai-noto-sans-kr-', 'registerFont' ) as $marker ) {
		g2s02_assert( str_contains( $renderer_source, $marker ), 'Content-addressed Korean font cache marker missing: ' . $marker );
	}g2s02_assert( ! str_contains( $renderer_source, "'file:///" ), 'Windows-incompatible packaged font URI returned.' );

	$runtime_files = array(
		$root . '/src/Reporting/WorksheetPdf/Infrastructure/WordPress_Worksheet_Pdf_Repository.php',
		$root . '/src/Storage/Infrastructure/WordPress_Generated_Private_File_Metadata.php',
		$root . '/src/Reporting/WorksheetPdf/Presentation/Worksheet_Pdf_Rest_Controller.php',
		$root . '/src/Reporting/Export/Infrastructure/WordPress_Export_Query.php',
		$root . '/src/Reporting/Export/Presentation/Export_Rest_Controller.php',
	);
	foreach ( $runtime_files as $file ) {
		$source_code = file_get_contents( $file );
		g2s02_assert( is_string( $source_code ), 'Runtime source is unreadable.' );
		g2s02_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'Forbidden table prefix found: ' . $file );
	}
	$repository_source = file_get_contents( $runtime_files[0] );
	g2s02_assert( str_contains( $repository_source, "'oj_ai_export_jobs'" ) && str_contains( $repository_source, "'oj_ai_pdf_artifacts'" ) && str_contains( $repository_source, 'state IN (%s,%s)' ), 'Literal tables or active semantic idempotency query drifted.' );
	$pdf_controller_source = file_get_contents( $runtime_files[2] );
	g2s02_assert( str_contains( $pdf_controller_source, '/commands/export-pdf' ), 'C48 REST marker missing.' );
	$export_controller_source = file_get_contents( $runtime_files[4] );
	foreach ( array( '/export-jobs/', '/artifacts/', 'rest_pre_serve_request', 'attachment; filename*=UTF-8' ) as $marker ) {
		g2s02_assert( str_contains( $export_controller_source, $marker ), 'Q23/Q24 private stream marker missing: ' . $marker );
	}

	echo "G2-IMP-07-S02 passed: C48 snapshot/idempotency, context-free storage, allowlist HTML, Dompdf 3.1.6 Korean PDF, and shared Q23/Q24 private stream boundaries.\n";

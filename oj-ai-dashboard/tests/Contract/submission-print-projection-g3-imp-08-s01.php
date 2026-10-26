<?php
/**
 * G3-IMP-08-S01-C submission_print.v1 and ten-kind HTML contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Build_Worksheet_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Print_Source_Query_Port_Interface;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G3-IMP-08-S01-C submission_print.v1 and ten-kind HTML contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			return $value;
		}
	}
}
require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G3s01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G3s01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G3s01 failure.
 *
 * @param callable $callback Callback.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g3s01_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Submission_Pdf_Failure $failure ) {
		g3s01_same( $reason, $failure->reason(), $message );
		return;
	} throw new RuntimeException( $message );
}
/**
 * G3s01 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g3s01_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g3s01_assert( Public_Id::is_valid( $id ), 'Fixture PublicId is invalid.' );
	return $id;
}

/**
 * G3-IMP-08-S01-C submission_print.v1 and ten-kind HTML contract.
 */
final class G3S01_Worksheet_Source implements Worksheet_Print_Source_Query_Port_Interface, Worksheet_Publication_Print_Source_Query_Port_Interface {
	/**
	 * Initialize g3 s01 worksheet source dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_public_id Source public id.
	 * @return array|null
	 */
	public function find_owned( int $actor_user_id, string $source_type, string $source_public_id ): ?array {
		unset( $actor_user_id, $source_type, $source_public_id );
		return null;
	}
	/**
	 * Find exact publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $expected_publication_hash Expected publication hash.
	 * @return array|null
	 */
	public function find_exact_publication( string $publication_id, string $expected_publication_hash ): ?array {
		return $publication_id === $this->source['source_public_id'] && $expected_publication_hash === $this->source['source_hash'] ? $this->source : null;
	}
}

/**
 * G3-IMP-08-S01-C submission_print.v1 and ten-kind HTML contract.
 */
final class G3S01_Submission_Source implements Submission_Print_Source_Query_Port_Interface {
	/**
	 * Initialize g3 s01 submission source dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Find official revision.
	 *
	 * @param string $revision_id Revision id.
	 * @return array|null
	 */
	public function find_official_revision( string $revision_id ): ?array {
		return $revision_id === $this->source['official_revision_id'] ? $this->source : null;
	}
}

/**
 * G3s01 response node.
 *
 * @param array<string,mixed> $item Item.
 * @param int                 $node Node.
 * @return array
 */
function g3s01_response_node( array $item, int $node ): array {
	$result = array(
		'type'        => 'oj_ai_response',
		'node_id'     => g3s01_id( $node ),
		'children'    => array( array( 'text' => '' ) ),
		'response_id' => $item['response_id'],
		'kind'        => $item['kind'],
		'label'       => $item['label'],
		'required'    => $item['required'],
	);
	if ( array_key_exists( 'constraints', $item ) ) {
		$result['constraints'] = $item['constraints'];
	}
	return $result;
}

$canonical              = new Canonical_Json();
$worksheet_id           = g3s01_id( 1 );
$worksheet_revision_id  = g3s01_id( 2 );
$publication_id         = g3s01_id( 3 );
$submission_id          = g3s01_id( 4 );
$submission_revision_id = g3s01_id( 5 );
$assignment_id          = g3s01_id( 6 );
$target_id              = g3s01_id( 7 );
$receipt_id             = g3s01_id( 8 );
$document_id            = g3s01_id( 9 );
$response_ids           = array_map( 'g3s01_id', range( 20, 29 ) );
$option_ids             = array_map( 'g3s01_id', range( 30, 33 ) );
$row_id                 = g3s01_id( 34 );
$column_text            = g3s01_id( 35 );
$column_check           = g3s01_id( 36 );
$attachment_ids         = array_map( 'g3s01_id', range( 40, 43 ) );
$items                  = array(
	array(
		'response_id' => $response_ids[0],
		'kind'        => 'short_text',
		'label'       => '짧은 답',
		'required'    => true,
		'position'    => 0,
		'constraints' => array(
			'min_chars' => 1,
			'max_chars' => 100,
			'trim'      => true,
		),
	),
	array(
		'response_id' => $response_ids[1],
		'kind'        => 'long_text',
		'label'       => '긴 답',
		'required'    => false,
		'position'    => 1,
		'constraints' => array( 'max_chars' => 2000 ),
	),
	array(
		'response_id' => $response_ids[2],
		'kind'        => 'single_choice',
		'label'       => '하나 선택',
		'required'    => false,
		'position'    => 2,
		'constraints' => array(
			'options' => array(
				array(
					'option_id' => $option_ids[0],
					'value'     => 'spring',
					'label'     => '봄',
				),
				array(
					'option_id' => $option_ids[1],
					'value'     => 'summer',
					'label'     => '여름',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[3],
		'kind'        => 'multiple_choice',
		'label'       => '여러 선택',
		'required'    => false,
		'position'    => 3,
		'constraints' => array(
			'options'      => array(
				array(
					'option_id' => $option_ids[2],
					'value'     => 'east',
					'label'     => '동쪽',
				),
				array(
					'option_id' => $option_ids[3],
					'value'     => 'west',
					'label'     => '서쪽',
				),
			),
			'min_selected' => 0,
			'max_selected' => 2,
		),
	),
	array(
		'response_id' => $response_ids[4],
		'kind'        => 'checkbox',
		'label'       => '확인',
		'required'    => false,
		'position'    => 4,
		'constraints' => array(),
	),
	array(
		'response_id' => $response_ids[5],
		'kind'        => 'number',
		'label'       => '길이',
		'required'    => false,
		'position'    => 5,
		'constraints' => array(
			'min'            => '0',
			'max'            => '10',
			'step'           => '0.5',
			'unit'           => 'cm',
			'decimal_places' => 1,
		),
	),
	array(
		'response_id' => $response_ids[6],
		'kind'        => 'table',
		'label'       => '관찰표',
		'required'    => false,
		'position'    => 6,
		'constraints' => array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $row_id,
					'label'  => '첫째 날',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $column_text,
					'label'     => '내용',
					'cell_type' => 'short_text',
				),
				array(
					'column_id' => $column_check,
					'label'     => '완료',
					'cell_type' => 'checkbox',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[7],
		'kind'        => 'image_upload',
		'label'       => '사진',
		'required'    => false,
		'position'    => 7,
		'constraints' => array(
			'mime_types' => array( 'image/png' ),
			'max_files'  => 1,
			'max_bytes'  => 1048576,
		),
	),
	array(
		'response_id' => $response_ids[8],
		'kind'        => 'file_upload',
		'label'       => '파일',
		'required'    => false,
		'position'    => 8,
		'constraints' => array(
			'mime_types' => array( 'application/pdf' ),
			'max_files'  => 1,
			'max_bytes'  => 2097152,
		),
	),
	array(
		'response_id' => $response_ids[9],
		'kind'        => 'drawing',
		'label'       => '그림',
		'required'    => false,
		'position'    => 9,
		'constraints' => array(
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1.0.0',
			'preview_mime_type'     => 'image/png',
			'aspect_ratio'          => '1.333',
			'max_strokes'           => 2000,
		),
	),
);
$document               = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => $document_id,
	'locale'         => 'ko-KR',
	'title'          => '계절 관찰 활동지',
	'nodes'          => array_merge(
		array(
			array(
				'type'     => 'h1',
				'node_id'  => g3s01_id( 60 ),
				'children' => array( array( 'text' => '계절 관찰' ) ),
			),
		),
		array_map( static fn ( array $item, int $index ): array => g3s01_response_node( $item, 70 + $index ), $items, array_keys( $items ) )
	),
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);
$ids                    = new Public_Id_Generator( static fn ( int $length ): string => str_repeat( "\x31", $length ) );
$documents              = new Worksheet_Document_Canonicalizer( $canonical, $ids );
$canonical_document     = $documents->canonicalize( $document );
$previews               = new Worksheet_Preview_Projector( $canonical );
$p05                    = $previews->print( $canonical_document, 'student_blank' );
$p05_envelope           = $p05->projection()['print_projection'];
$p05_json               = $canonical->encode( $p05_envelope );
$publication_hash       = hash( 'sha256', 'g3-s01-publication' );
$worksheet_source       = new G3S01_Worksheet_Source(
	array(
		'source_type'                 => 'publication',
		'source_public_id'            => $publication_id,
		'source_hash'                 => $publication_hash,
		'worksheet_id'                => $worksheet_id,
		'revision_id'                 => $worksheet_revision_id,
		'revision_document_hash'      => $canonical_document->document_hash(),
		'revision_validation_hash'    => $canonical_document->validation_hash(),
		'publication_id'              => $publication_id,
		'publication_hash'            => $publication_hash,
		'publication_state'           => 'active',
		'projection_manifest_version' => '1.0.0',
		'teacher_document'            => null,
		'print_envelope'              => $p05_envelope,
		'print_profile_hash'          => hash( 'sha256', $p05_json ),
	)
);
$worksheet_builder      = new Build_Worksheet_Print_Projection( $worksheet_source, $documents, $previews, new Worksheet_Projection_Safety_Inspector( $canonical ), $canonical );

$answers        = array(
	$response_ids[0] => '<script>alert(1)</script>',
	$response_ids[1] => "첫 줄\n둘째 줄",
	$response_ids[2] => $option_ids[0],
	$response_ids[3] => array( $option_ids[2], $option_ids[3] ),
	$response_ids[4] => true,
	$response_ids[5] => '2',
	$response_ids[6] => array(
		$row_id => array(
			$column_text  => '새싹',
			$column_check => false,
		),
	),
	$response_ids[7] => array( $attachment_ids[0] ),
	$response_ids[8] => array( $attachment_ids[1] ),
	$response_ids[9] => array(
		'document_attachment_id' => $attachment_ids[2],
		'preview_attachment_id'  => $attachment_ids[3],
	),
);
$attachment_set = $attachment_ids;
sort( $attachment_set, SORT_STRING );
$attachment_set_hash = hash( 'sha256', $canonical->encode( $attachment_set ) );
$revision_hash       = hash( 'sha256', 'g3-s01-official-revision' );
$receipt_hash        = hash( 'sha256', 'g3-s01-receipt' );
$attachment_rows     = array(
	array(
		'id'             => $attachment_ids[0],
		'response_id'    => $response_ids[7],
		'state'          => 'bound',
		'name'           => '관찰 <사진>.png',
		'mime_type'      => 'image/png',
		'byte_size'      => 1024,
		'sha256'         => hash( 'sha256', 'image' ),
		'revision_scope' => array( 'current', 'official' ),
	),
	array(
		'id'             => $attachment_ids[1],
		'response_id'    => $response_ids[8],
		'state'          => 'bound',
		'name'           => '관찰.pdf',
		'mime_type'      => 'application/pdf',
		'byte_size'      => 2048,
		'sha256'         => hash( 'sha256', 'file' ),
		'revision_scope' => array( 'current', 'official' ),
	),
	array(
		'id'             => $attachment_ids[2],
		'response_id'    => $response_ids[9],
		'state'          => 'bound',
		'name'           => '그림.json',
		'mime_type'      => 'application/json',
		'byte_size'      => 512,
		'sha256'         => hash( 'sha256', 'drawing' ),
		'revision_scope' => array( 'current', 'official' ),
	),
	array(
		'id'             => $attachment_ids[3],
		'response_id'    => $response_ids[9],
		'state'          => 'bound',
		'name'           => '그림.png',
		'mime_type'      => 'image/png',
		'byte_size'      => 1536,
		'sha256'         => hash( 'sha256', 'preview' ),
		'revision_scope' => array( 'current', 'official' ),
	),
);
$submission_source   = new G3S01_Submission_Source(
	array(
		'id'                   => $submission_id,
		'assignment_id'        => $assignment_id,
		'assignment_title'     => '계절 관찰 활동지',
		'assignment_state'     => 'open',
		'context'              => array(
			'type'     => 'class',
			'id'       => 'class-21',
			'revision' => 'class-r3',
		),
		'target'               => array(
			'id'           => $target_id,
			'display_name' => '김하늘',
			'state'        => 'active',
		),
		'state'                => 'submitted',
		'lock_version'         => 2,
		'official_revision_id' => $submission_revision_id,
		'official_attempt_no'  => 1,
		'submitted_at_utc'     => '2026-08-23 09:30:00',
		'source'               => array(
			'worksheet_publication_id' => $publication_id,
			'publication_hash'         => $publication_hash,
		),
		'official_revision'    => array(
			'id'                      => $submission_revision_id,
			'revision_no'             => 2,
			'state'                   => 'saved',
			'response_schema_version' => '1.0.0',
			'response'                => array(
				'answers'                 => $answers,
				'publication_hash'        => $publication_hash,
				'publication_id'          => $publication_id,
				'response_schema_version' => '1.0.0',
			),
			'content_hash'            => $revision_hash,
			'attachment_set_hash'     => $attachment_set_hash,
			'created_at_utc'          => '2026-08-23 09:29:00',
		),
		'receipt'              => array(
			'id'                       => $receipt_id,
			'content_hash'             => $receipt_hash,
			'attempt_no'               => 1,
			'submission_revision_id'   => $submission_revision_id,
			'submission_revision_hash' => $revision_hash,
			'publication_hash'         => $publication_hash,
			'attachment_set_hash'      => $attachment_set_hash,
			'timeliness'               => 'on_time',
			'received_at_utc'          => '2026-08-23 09:30:00',
		),
		'attachments'          => $attachment_rows,
	)
);
$builder             = new Build_Submission_Print_Projection( $submission_source, $worksheet_builder, new Submission_Response_Validator( $canonical ), $canonical );
$result              = $builder->build( $submission_revision_id, $revision_hash, $publication_hash, $attachment_set_hash );
g3s01_same( 'submission_revision', $result['source']['type'], 'Submission print source type drifted.' );
g3s01_same( 'submission_print.v1', $result['print']['artifact_profile_version'], 'Submission artifact profile drifted.' );
g3s01_same( 'oj_ai.submission_print', $result['print']['schema_id'], 'Submission print schema drifted.' );
g3s01_same( 'worksheet_a4@1', $result['print']['page_profile'], 'Submission page profile drifted.' );
g3s01_same( $publication_hash, $result['publication']['hash'], 'Submission print must retain the exact publication hash.' );
g3s01_same( $canonical->encode( $result ), $canonical->encode( $builder->build( $submission_revision_id, $revision_hash, $publication_hash, $attachment_set_hash ) ), 'Submission print projection must be deterministic.' );

$projection_json = $canonical->encode( $result['print']['projection'] );
foreach ( array( 'grading', 'max_points', 'answer_key', 'rubric', 'teacher_note', 'file_public_id', 'storage_key', 'learner_user_id' ) as $forbidden ) {
	g3s01_assert( ! str_contains( $projection_json, '"' . $forbidden . '"' ), 'Submission print projection leaked ' . $forbidden . '.' );
}
$html = ( new Submission_Print_Html_Serializer( $canonical ) )->serialize( $result['print']['projection'], $submission_revision_id );
foreach ( array( '짧은 답', '긴 답', '하나 선택', '여러 선택', '확인', '길이', '관찰표', '사진', '파일', '그림', '봄', '동쪽', '서쪽', '새싹', '관찰.pdf', '김하늘' ) as $anchor ) {
	g3s01_assert( str_contains( $html, $anchor ), 'Submission HTML is missing: ' . $anchor );
}
g3s01_assert( str_contains( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'Student text must be escaped.' );
g3s01_assert( ! str_contains( $html, '<script>' ) && ! str_contains( $html, $attachment_ids[0] ), 'Submission HTML leaked active markup or attachment IDs.' );

g3s01_failure( static fn () => $builder->build( $submission_revision_id, hash( 'sha256', 'stale' ), $publication_hash, $attachment_set_hash ), Submission_Pdf_Failure::SOURCE_STALE, 'Stale official revision hash must fail.' );
g3s01_failure( static fn () => $builder->build( g3s01_id( 99 ), $revision_hash, $publication_hash, $attachment_set_hash ), Submission_Pdf_Failure::NOT_FOUND, 'Unknown official revision must be hidden.' );
$original_answer = $submission_source->source['official_revision']['response']['answers'][ $response_ids[2] ];
$submission_source->source['official_revision']['response']['answers'][ $response_ids[2] ] = g3s01_id( 98 );
g3s01_failure( static fn () => $builder->build( $submission_revision_id, $revision_hash, $publication_hash, $attachment_set_hash ), Submission_Pdf_Failure::SOURCE_INVALID, 'Unknown option IDs must fail closed.' );
$submission_source->source['official_revision']['response']['answers'][ $response_ids[2] ] = $original_answer;

foreach ( array(
	$addon_root . '/src/Reporting/SubmissionPdf/Application/Build_Submission_Print_Projection.php',
	$addon_root . '/src/Reporting/SubmissionPdf/Domain/Submission_Print_Html_Serializer.php',
	$addon_root . '/src/Submission/Teacher/Infrastructure/WordPress_Teacher_Submission_Query.php',
	$addon_root . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Print_Source_Query.php',
) as $source_file ) {
	$source_code = (string) file_get_contents( $source_file );
	g3s01_assert( ! str_contains( $source_code, '$wpdb->prefix' ) && ! str_contains( $source_code, 'wp_oj_ai_' ), 'S01-C must use literal no-prefix storage boundaries.' );
	g3s01_assert( ! str_contains( $source_code, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S01-C must not depend on platform internals.' );
}

echo "G3-IMP-08-S01-C passed: official submission and exact P05 provenance, ten response kinds, attachment evidence, deterministic submission_print.v1, and escaped allowlist HTML.\n";

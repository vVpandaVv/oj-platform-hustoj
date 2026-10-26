<?php
/**
 * Generates the representative G3-IMP-08-S01-G teacher submission PDF.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $root . DIRECTORY_SEPARATOR );
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $root . '/src' ) )->register();

$output = $argv[1] ?? '';
if ( ! is_string( $output ) || ! str_ends_with( strtolower( $output ), '.pdf' ) ) {
	throw new InvalidArgumentException( 'A PDF output path is required.' );
}
$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
	throw new RuntimeException( 'Could not create the submission PDF fixture directory.' );
}

/**
 * G3s01g text.
 *
 * @param string $text Text.
 * @param bool   $bold Bold.
 * @return array<string,mixed>
 */
function g3s01g_text( string $text, bool $bold = false ): array {
	return $bold ? array(
		'text' => $text,
		'bold' => true,
	) : array( 'text' => $text );
}

/**
 * G3s01g node.
 *
 * @param string                         $type Type.
 * @param array<int,array<string,mixed>> $children Children.
 * @param array<string,mixed>            $properties Properties.
 * @return array<string,mixed>
 */
function g3s01g_node( string $type, array $children = array(), array $properties = array() ): array {
	return array_merge(
		array(
			'type'     => $type,
			'children' => $children,
		),
		$properties
	);
}

/**
 * G3s01g response.
 *
 * @param string              $id Id.
 * @param string              $kind Kind.
 * @param string              $label Label.
 * @param array<string,mixed> $constraints Constraints.
 * @return array<string,mixed>
 */
function g3s01g_response( string $id, string $kind, string $label, array $constraints = array() ): array {
	return g3s01g_node(
		'oj_ai_response',
		array( g3s01g_text( '' ) ),
		array(
			'response_id' => $id,
			'kind'        => $kind,
			'label'       => $label,
			'constraints' => $constraints,
		)
	);
}

/**
 * Returns a stable 26-character fixture identifier.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g3s01g_id( int $suffix ): string {
	return '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
}

$responses          = array_map( 'g3s01g_id', range( 20, 29 ) );
$options            = array_map( 'g3s01g_id', range( 30, 33 ) );
$row_id             = g3s01g_id( 34 );
$column_observation = g3s01g_id( 35 );
$column_complete    = g3s01g_id( 36 );
$attachments        = array_map( 'g3s01g_id', range( 40, 43 ) );
$private_answer     = 'S01G_PRIVATE_ANSWER';
$private_storage    = 'S01G_PRIVATE_STORAGE_KEY';

$document = array(
	'locale' => 'ko-KR',
	'title'  => '우리 반 생태 관찰 제출 결과',
	'nodes'  => array(
		g3s01g_node( 'h1', array( g3s01g_text( '우리 반 생태 관찰', true ) ) ),
		g3s01g_node( 'p', array( g3s01g_text( '학교 주변에서 찾은 생물과 환경의 관계를 관찰하고 근거를 들어 설명했습니다.' ) ) ),
		g3s01g_node( 'callout', array( g3s01g_node( 'p', array( g3s01g_text( '관찰 날짜: 2026년 8월 23일 · 장소: 학교 화단' ) ) ) ) ),
		array_merge( g3s01g_response( $responses[0], 'short_text', '관찰 장소' ), array( 'grading' => array( 'example_answer' => $private_answer ) ) ),
		g3s01g_response( $responses[1], 'long_text', '관찰한 생물과 환경의 관계' ),
		g3s01g_response(
			$responses[2],
			'single_choice',
			'오늘의 날씨',
			array(
				'options' => array(
					array(
						'option_id' => $options[0],
						'label'     => '맑음',
					),
					array(
						'option_id' => $options[1],
						'label'     => '흐림',
					),
				),
			)
		),
		g3s01g_node( 'page_break' ),
		g3s01g_node( 'h2', array( g3s01g_text( '관찰 결과 정리' ) ) ),
		g3s01g_response(
			$responses[3],
			'multiple_choice',
			'관찰한 요소',
			array(
				'options' => array(
					array(
						'option_id' => $options[2],
						'label'     => '식물',
					),
					array(
						'option_id' => $options[3],
						'label'     => '곤충',
					),
				),
			)
		),
		g3s01g_response( $responses[4], 'checkbox', '안전 수칙 확인' ),
		g3s01g_response( $responses[5], 'number', '관찰한 생물의 수', array( 'unit' => '종' ) ),
		g3s01g_response(
			$responses[6],
			'table',
			'생물과 환경의 관계 표',
			array(
				'rows'    => array(
					array(
						'row_id' => $row_id,
						'label'  => '첫째 관찰',
					),
				),
				'columns' => array(
					array(
						'column_id' => $column_observation,
						'label'     => '관찰 내용',
					),
					array(
						'column_id' => $column_complete,
						'label'     => '확인',
					),
				),
			)
		),
		g3s01g_node( 'page_break' ),
		g3s01g_node( 'h2', array( g3s01g_text( '첨부 자료와 결론' ) ) ),
		g3s01g_response( $responses[7], 'image_upload', '관찰 사진' ),
		g3s01g_response( $responses[8], 'file_upload', '관찰 기록 파일' ),
		g3s01g_response( $responses[9], 'drawing', '관찰 그림' ),
		g3s01g_node( 'p', array( g3s01g_text( '결론: 화단의 그늘과 수분이 작은 생물이 머무는 데 도움을 주며, 생물은 주변 환경과 서로 영향을 주고받습니다.' ) ) ),
	),
);

$answer_map      = array(
	$responses[0] => '학교 화단',
	$responses[1] => "개미는 흙이 촉촉하고 낙엽이 쌓인 곳에서 많이 보였습니다.\n햇빛이 강한 곳보다 그늘진 곳에서 움직임이 활발했습니다.",
	$responses[2] => $options[0],
	$responses[3] => array( $options[2], $options[3] ),
	$responses[4] => true,
	$responses[5] => '5',
	$responses[6] => array(
		$row_id => array(
			$column_observation => '개미가 낙엽 아래로 이동함',
			$column_complete    => true,
		),
	),
	$responses[7] => array( $attachments[0] ),
	$responses[8] => array( $attachments[1] ),
	$responses[9] => array(
		'document_attachment_id' => $attachments[2],
		'preview_attachment_id'  => $attachments[3],
	),
);
$attachment_list = array(
	array(
		'id'          => $attachments[0],
		'name'        => '화단 관찰 사진.png',
		'mime_type'   => 'image/png',
		'byte_size'   => 245760,
		'storage_key' => $private_storage,
	),
	array(
		'id'        => $attachments[1],
		'name'      => '생태 관찰 기록.pdf',
		'mime_type' => 'application/pdf',
		'byte_size' => 524288,
	),
	array(
		'id'        => $attachments[2],
		'name'      => '관찰 그림.json',
		'mime_type' => 'application/json',
		'byte_size' => 32768,
	),
	array(
		'id'        => $attachments[3],
		'name'      => '관찰 그림 미리보기.png',
		'mime_type' => 'image/png',
		'byte_size' => 131072,
	),
);

$canonical     = new Canonical_Json();
$revision_id   = g3s01g_id( 5 );
$revision_hash = hash( 'sha256', 'g3-s01-g-official-revision' );
$payload       = array(
	'profile'         => Build_Submission_Print_Projection::PROFILE,
	'layout'          => array( 'print_token' => Build_Submission_Print_Projection::PAGE_PROFILE ),
	'document'        => $document,
	'response_schema' => array( 'version' => '1.0.0' ),
	'answers'         => $answer_map,
	'attachments'     => $attachment_list,
	'submission'      => array(
		'id'               => g3s01g_id( 4 ),
		'state'            => 'submitted',
		'attempt_no'       => 2,
		'submitted_at_utc' => '2026-08-23 09:30:00',
	),
	'assignment'      => array(
		'id'      => g3s01g_id( 6 ),
		'title'   => '우리 반 생태 관찰 제출 결과',
		'context' => array(
			'type'     => 'class',
			'id'       => 'class-21',
			'revision' => 'class-r3',
		),
	),
	'learner'         => array(
		'target_id'    => g3s01g_id( 7 ),
		'display_name' => '김하늘',
	),
	'provenance'      => array(
		'submission_revision_id'   => $revision_id,
		'submission_revision_hash' => $revision_hash,
		'publication_id'           => g3s01g_id( 3 ),
		'publication_hash'         => hash( 'sha256', 'g3-s01-g-publication' ),
		'attachment_set_hash'      => hash( 'sha256', 'g3-s01-g-attachments' ),
		'receipt_id'               => g3s01g_id( 8 ),
		'receipt_hash'             => hash( 'sha256', 'g3-s01-g-receipt' ),
	),
);
$envelope      = array(
	'schema_id'         => Build_Submission_Print_Projection::SCHEMA,
	'schema_version'    => Build_Submission_Print_Projection::VERSION,
	'projector_version' => Build_Submission_Print_Projection::PROJECTOR_VERSION,
	'source_hash'       => $revision_hash,
	'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
	'payload'           => $payload,
);
$projection    = array(
	'kind'                        => 'submission_print',
	'profile'                     => Build_Submission_Print_Projection::PROFILE,
	'submission_print_projection' => $envelope,
);

$serializer = new Submission_Print_Html_Serializer( $canonical );
$html       = $serializer->serialize( $projection, $revision_id );
if ( str_contains( $html, $private_answer ) || str_contains( $html, $private_storage ) || str_contains( strtolower( $html ), '<script' ) ) {
	throw new RuntimeException( 'Submission PDF HTML crossed the private or active-content boundary.' );
}
$cache    = $directory . '/.dompdf-cache';
$renderer = new Dompdf_Html_To_Pdf_Renderer( null, $root, $cache );
$pdf      = $renderer->render( $html );
if ( $pdf->page_count() < 3 || ! str_starts_with( $pdf->bytes(), '%PDF-' ) || strlen( $pdf->bytes() ) < 20_000 ) {
	throw new RuntimeException( 'Submission PDF fixture is incomplete: pages=' . $pdf->page_count() . ', bytes=' . strlen( $pdf->bytes() ) . '.' );
}
if ( strlen( $pdf->bytes() ) !== file_put_contents( $output, $pdf->bytes(), LOCK_EX ) ) {
	throw new RuntimeException( 'Could not write the submission PDF fixture.' );
}

echo json_encode(
	array(
		'byte_size'        => strlen( $pdf->bytes() ),
		'page_count'       => $pdf->page_count(),
		'renderer_version' => $pdf->renderer_version(),
		'sha256'           => hash( 'sha256', $pdf->bytes() ),
	),
	JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;

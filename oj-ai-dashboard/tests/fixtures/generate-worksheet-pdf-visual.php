<?php
/**
 * Generates the representative G2-IMP-07-S04 worksheet PDF visual fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;

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
	throw new RuntimeException( 'Could not create the visual fixture directory.' );
}

/**
 * G2s04 visual node.
 *
 * @param string                         $type Type.
 * @param array<int,array<string,mixed>> $children Children.
 * @param array<string,mixed>            $properties Properties.
 * @return array<string,mixed>
 */
function g2s04_visual_node( string $type, array $children = array(), array $properties = array() ): array {
	return array_merge(
		array(
			'type'     => $type,
			'children' => $children,
		),
		$properties
	);
}

/**
 * G2s04 visual text.
 *
 * @param string $text Text.
 * @param bool   $bold Bold.
 * @return array<string,string>
 */
function g2s04_visual_text( string $text, bool $bold = false ): array {
	return $bold ? array(
		'text' => $text,
		'bold' => true,
	) : array( 'text' => $text );
}

$private_answer = 'S04_VISUAL_PRIVATE_ANSWER';
$private_note   = 'S04_VISUAL_PRIVATE_NOTE';
$nodes          = array(
	g2s04_visual_node( 'h1', array( g2s04_visual_text( '우리 동네 생태 관찰', true ) ) ),
	g2s04_visual_node( 'p', array( g2s04_visual_text( '관찰한 생물과 주변 환경의 관계를 기록하고, 근거를 들어 설명해 보세요.' ) ) ),
	g2s04_visual_node( 'callout', array( g2s04_visual_node( 'p', array( g2s04_visual_text( '안전 수칙: 생물을 만지지 않고 눈으로 관찰합니다.' ) ) ) ) ),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'short_text',
			'label'       => '관찰 장소',
			'required'    => true,
			'constraints' => array(),
			'grading'     => array(
				'example_answer' => $private_answer,
				'teacher_note'   => $private_note,
			),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'single_choice',
			'label'       => '오늘의 날씨',
			'required'    => true,
			'constraints' => array( 'options' => array( array( 'label' => '맑음' ), array( 'label' => '흐림' ), array( 'label' => '비' ) ) ),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'multiple_choice',
			'label'       => '관찰한 요소',
			'required'    => false,
			'constraints' => array( 'options' => array( array( 'label' => '식물' ), array( 'label' => '곤충' ), array( 'label' => '새' ), array( 'label' => '흙과 돌' ) ) ),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'long_text',
			'label'       => '관찰 기록',
			'help_text'   => '색, 크기, 움직임을 포함해 자세히 적으세요.',
			'required'    => true,
			'constraints' => array(),
		)
	),
	g2s04_visual_node( 'page_break' ),
	g2s04_visual_node( 'h2', array( g2s04_visual_text( '관찰 결과 정리' ) ) ),
	g2s04_visual_node( 'p', array( g2s04_visual_text( '관찰 내용을 표와 그림으로 정리한 뒤 결론을 작성합니다.' ) ) ),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'table',
			'label'       => '생물과 환경의 관계',
			'required'    => true,
			'constraints' => array( 'columns' => array( array( 'label' => '관찰 대상' ), array( 'label' => '환경 특징' ), array( 'label' => '관계' ) ) ),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'number',
			'label'       => '관찰한 생물의 수',
			'required'    => false,
			'constraints' => array(),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'drawing',
			'label'       => '관찰 그림',
			'help_text'   => '특징이 잘 드러나도록 그려 보세요.',
			'required'    => true,
			'constraints' => array(),
		)
	),
	g2s04_visual_node(
		'oj_ai_response',
		array( g2s04_visual_text( '' ) ),
		array(
			'kind'        => 'long_text',
			'label'       => '나의 결론',
			'help_text'   => '관찰 근거를 한 가지 이상 포함하세요.',
			'required'    => true,
			'constraints' => array(),
		)
	),
	g2s04_visual_node( 'hr' ),
	g2s04_visual_node( 'p', array( g2s04_visual_text( '확인: 빠뜨린 문항이 없는지 살펴봅니다.' ) ) ),
);

$projection = array(
	'kind'             => 'print',
	'profile'          => 'student_blank',
	'print_projection' => array(
		'schema_id' => Worksheet_Preview_Projector::PRINT_SCHEMA,
		'payload'   => array(
			'profile'  => 'student_blank',
			'document' => array(
				'locale' => 'ko-KR',
				'title'  => '우리 동네 생태 관찰 활동지',
				'nodes'  => $nodes,
			),
		),
	),
);

$serializer = new Worksheet_Print_Html_Serializer();
$html       = $serializer->serialize( $projection, '01K00000000000000000000077' );
if ( str_contains( $html, $private_answer ) || str_contains( $html, $private_note ) || str_contains( strtolower( $html ), '<script' ) ) {
	throw new RuntimeException( 'Visual fixture HTML crossed the public PDF boundary.' );
}
$cache    = rtrim( sys_get_temp_dir(), '/\\' ) . '/oj-ai-dompdf-s04-visual';
$renderer = new Dompdf_Html_To_Pdf_Renderer( null, $root, $cache );
$pdf      = $renderer->render( $html );
if ( $pdf->page_count() < 2 || ! str_starts_with( $pdf->bytes(), '%PDF-' ) || strlen( $pdf->bytes() ) < 20_000 ) {
	throw new RuntimeException( 'Visual fixture PDF is incomplete: pages=' . $pdf->page_count() . ', bytes=' . strlen( $pdf->bytes() ) . '.' );
}
if ( strlen( $pdf->bytes() ) !== file_put_contents( $output, $pdf->bytes(), LOCK_EX ) ) {
	throw new RuntimeException( 'Could not write the visual fixture PDF.' );
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

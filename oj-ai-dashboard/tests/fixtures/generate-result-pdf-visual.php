<?php
/**
 * Generates the representative G4-IMP-07-S04 student result PDF.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
defined( 'OJ_AI_DASHBOARD_DIR' ) || define( 'OJ_AI_DASHBOARD_DIR', $root . DIRECTORY_SEPARATOR );
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $root . '/src' ) )->register();

$output = $argv[1] ?? '';
if ( ! is_string( $output ) || ! str_ends_with( strtolower( $output ), '.pdf' ) ) {
	throw new InvalidArgumentException( 'A result PDF output path is required.' );
}
$directory = dirname( $output );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
	throw new RuntimeException( 'Could not create the result PDF fixture directory.' );
}

$private_teacher_memo = 'S04_RESULT_PRIVATE_TEACHER_MEMO';
$private_student_note = 'S04_RESULT_PRIVATE_STUDENT_NOTE';
$private_provider     = 'S04_RESULT_PRIVATE_PROVIDER_KEY';
$labels               = array( '자료 읽기', '핵심 정보 찾기', '근거 연결하기', '계산 과정', '단위 표현', '문장 구성', '자기 점검', '확장 설명' );
$score_criteria       = array();
$feedback_criteria    = array();
foreach ( $labels as $index => $label ) {
	$points              = 8 - ( $index % 3 );
	$score_criteria[]    = array(
		'label'          => $label,
		'maximum_points' => '10.0000',
		'points'         => $points . '.0000',
	);
	$feedback_criteria[] = array(
		'label'     => $label,
		'strength'  => '표와 글에서 필요한 정보를 정확히 골라 답의 근거로 사용했습니다. 풀이 순서가 분명하여 생각한 과정을 쉽게 따라갈 수 있습니다.',
		'next_step' => '답을 다시 읽으며 단위와 근거 문장을 한 번 더 확인해 보세요. 비슷한 자료를 비교하면 설명을 더 구체적으로 만들 수 있습니다.',
	);
}

$source = array(
	'worksheet_title' => '우리 반 자료 해석 학습 결과',
	'teacher_memo'    => $private_teacher_memo,
	'student_note'    => $private_student_note,
	'provider_key'    => $private_provider,
);
$result = array(
	'feedback'       => array(
		'criteria'         => $feedback_criteria,
		'next_steps'       => array(
			'표의 제목과 단위를 먼저 확인하기',
			'답을 뒷받침하는 수치를 문장에 함께 쓰기',
			'계산 결과가 질문에 맞는지 다시 읽기',
			'비슷한 자료 두 개를 골라 차이 설명하기',
		),
		'overall_feedback' => "자료에서 필요한 값을 정확히 찾고 자신의 말로 설명했습니다.\n다음 활동에서는 단위와 비교 기준을 함께 적어 보세요.",
		'visibility'       => 'shown',
	),
	'release'        => array(
		'released_at_utc' => '2026-08-24 16:00:00',
		'state'           => 'corrected',
	),
	'schema_id'      => Submission_Result_Projection::SCHEMA,
	'schema_version' => Submission_Result_Projection::SCHEMA_VERSION,
	'score'          => array(
		'criteria'   => $score_criteria,
		'total'      => '57.0000',
		'visibility' => 'total_and_criteria',
	),
	'worksheet'      => array( 'title' => $source['worksheet_title'] ),
);

$canonical  = new Canonical_Json();
$payload    = array(
	'profile' => Build_Result_Print_Projection::PROFILE,
	'result'  => $result,
);
$envelope   = array(
	'payload'           => $payload,
	'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
	'projector_version' => Build_Result_Print_Projection::PROJECTOR_VERSION,
	'schema_id'         => Build_Result_Print_Projection::SCHEMA,
	'schema_version'    => Build_Result_Print_Projection::VERSION,
	'source_hash'       => str_repeat( 'a', 64 ),
);
$projection = array(
	'kind'                    => 'result_print',
	'profile'                 => Build_Result_Print_Projection::PROFILE,
	'result_print_projection' => $envelope,
);

$serializer = new Result_Print_Html_Serializer( $canonical );
$html       = $serializer->serialize( $projection, '1A040G2081040G2081040G2081' );
foreach ( array( $private_teacher_memo, $private_student_note, $private_provider ) as $private_value ) {
	if ( str_contains( $html, $private_value ) ) {
		throw new RuntimeException( 'Result PDF HTML crossed the student release boundary.' );
	}
}
if ( str_contains( strtolower( $html ), '<script' ) ) {
	throw new RuntimeException( 'Result PDF HTML contains executable content.' );
}

$cache    = rtrim( sys_get_temp_dir(), '/\\' ) . '/oj-ai-result-pdf-s04-visual';
$renderer = new Dompdf_Html_To_Pdf_Renderer( null, $root, $cache );
$pdf      = $renderer->render( $html );
if ( $pdf->page_count() < 2 || ! str_starts_with( $pdf->bytes(), '%PDF-' ) || strlen( $pdf->bytes() ) < 20_000 ) {
	throw new RuntimeException( 'Result PDF fixture is incomplete: pages=' . $pdf->page_count() . ', bytes=' . strlen( $pdf->bytes() ) . '.' );
}
if ( strlen( $pdf->bytes() ) !== file_put_contents( $output, $pdf->bytes(), LOCK_EX ) ) {
	throw new RuntimeException( 'Could not write the result PDF fixture.' );
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

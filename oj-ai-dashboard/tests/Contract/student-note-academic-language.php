<?php
/**
 * Academic AI terminology must not be treated as a learner intelligence inference.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-note-worker-g4-imp-05-s01.php';

use OJAI\Dashboard\Submission\Assessment\Application\Run_Student_Note_Draft_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;

$source                                     = g4_note_worker_source( $canonical );
$payload                                    = $source['score_input']['operation_payload'];
$payload['bindings'][0]['label']            = '디지털 사회의 변화와 실천 방안';
$payload['responses'][0]['answer']['text']  = '인공지능은 학습 결과를 분석하여 문제를 추천하지만 답을 확인하지 않고 쓰면 잘못된 정보가 전달될 수 있다. 개인정보 공유를 제한하고 저작권과 출처를 확인해야 한다.';
$source['score_input']['operation_payload'] = $payload;
$source['score_input']['payload_hash']      = hash( 'sha256', $canonical->encode( $payload ) );
$input                                      = ( new Student_Note_Input_Builder( $canonical ) )->build( $source )->gateway_input();

foreach ( array(
	'인공지능의 활용 사례를 비교하고 개인정보와 저작권 문제의 예방 방안을 설명함.',
	'인공 지능 서비스의 위험 요인을 분석하여 실천 방안을 제시함.',
	'The response compares applications of artificial intelligence using supplied examples.',
) as $text ) {
	$output                    = g4_note_worker_output( $input );
	$output['summary']['text'] = $text;
	$gateway                   = Submission_Evaluation_Gateway_Result::success( $output, $route, 'academic-language', 20 );
	$scenario                  = g4_note_worker_scenario( $canonical, $source, $gateway );
	g4_note_worker_same( Run_Student_Note_Draft_Result::SUCCEEDED, $scenario['worker']->run_once()->state(), 'Academic AI terminology must not fail as student_note_schema_invalid.' );
	g4_note_worker_same( $text, $scenario['work']->success->profile()['summary']['text'], 'Academic text must be preserved verbatim.' );
}

foreach ( array(
	'인공지능 사례를 분석함. 학생의 지능이 매우 높음.',
	'지능이 뛰어나고 성격이 좋음.',
	'Artificial intelligence is discussed. The learner has high intelligence.',
	'인공지능의 위험을 설명함. 부모의 지원으로 성장함.',
	'인공지능 사례를 비교함. 점수는 20점임.',
) as $text ) {
	$output                    = g4_note_worker_output( $input );
	$output['summary']['text'] = $text;
	$gateway                   = Submission_Evaluation_Gateway_Result::success( $output, $route, 'prohibited-inference', 20 );
	$scenario                  = g4_note_worker_scenario( $canonical, $source, $gateway );
	g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Learner inferences and disclosures must remain blocked even beside AI terminology.' );
	g4_note_worker_same( null, $scenario['work']->success, 'Unsafe prose must never append a draft.' );
	g4_note_worker_assert( str_contains( $scenario['work']->failure['error_message'], '추정' ), 'Prohibited prose needs a content-free explanation.' );
}

$valid                                 = g4_note_worker_output( $input );
$cases                                 = array();
$changed                               = $valid;
$changed['evidence'][0]['observation'] = 'student-private-fixture@example.invalid';
$cases[]                               = array( $changed, '연락처' );
$changed                               = $valid;
$changed['evidence'][0]['observation'] = '원문에 없는 관찰 기록임.';
$cases[]                               = array( $changed, '원문' );
$changed                               = $valid;
$changed['summary']['evidence_keys']   = array( str_repeat( '0', 64 ) );
$cases[]                               = array( $changed, '연결된 근거' );
$changed                               = $valid;
$changed['source_hash']                = str_repeat( '0', 64 );
$cases[]                               = array( $changed, '원본 평가' );
$changed                               = $valid;
$changed['safety_flags']               = array( 'unsupported_claim' );
$cases[]                               = array( $changed, '검토 필요' );
$changed                               = $valid;
unset( $changed['summary'] );
$cases[] = array( $changed, '구조' );
foreach ( $cases as [ $output, $expected_message ] ) {
	$gateway  = Submission_Evaluation_Gateway_Result::success( $output, $route, 'invalid-output', 20 );
	$scenario = g4_note_worker_scenario( $canonical, $source, $gateway );
	g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Invalid outputs must still fail closed.' );
	$message = $scenario['work']->failure['error_message'];
	g4_note_worker_assert( str_contains( $message, $expected_message ), 'The persisted diagnostic must identify the validation category.' );
	g4_note_worker_assert( strlen( $message ) <= 500 && ! str_contains( $message, 'student-private-fixture' ), 'Persisted diagnostics must meet the storage budget without provider content.' );
	g4_note_worker_same( null, $scenario['work']->success, 'Invalid outputs must never append a draft.' );
}
$message = Student_Note_Output_Normalizer::failure_message( new InvalidArgumentException( 'private-student@example.invalid Authorization: secret' ) );
g4_note_worker_assert( ! str_contains( $message, 'private-student' ) && ! str_contains( $message, 'secret' ), 'Unknown exceptions must never expose their contents.' );
echo "Academic student-note language passed: Korean/English AI terms, unchanged prose, and retained inference/disclosure rejection.\n";

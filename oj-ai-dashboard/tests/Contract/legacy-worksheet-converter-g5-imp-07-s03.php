<?php
/**
 * G5-IMP-07-S03 legacy Plate to current worksheet conversion contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Worksheet_Document_Converter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Plate_Document_Service;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03w assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03w_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03w same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03w_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03w invalid.
 *
 * @param callable():void $callback Callback.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03w_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message . ': invalid source was accepted.' );
}

/**
 * G5 s03w converter.
 *
 * @return Legacy_Worksheet_Document_Converter
 */
function g5_s03w_converter(): Legacy_Worksheet_Document_Converter {
	$canonical = new Canonical_Json();
	$ids       = new Public_Id_Generator();
	return new Legacy_Worksheet_Document_Converter(
		new Plate_Document_Service(),
		new Worksheet_Document_Canonicalizer( $canonical, $ids ),
		new Worksheet_Revision_Projector( $canonical, $ids ),
		$ids
	);
}

/**
 * G5 s03w record.
 *
 * @param array<int,array<string,mixed>> $nodes Nodes.
 * @return array<string,mixed>
 */
function g5_s03w_record( array $nodes ): array {
	return array(
		'title'                 => 'Fractions Practice',
		'plate_value_json'      => json_encode( $nodes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
		'rubric_mode'           => 'worksheet',
		'worksheet_rubric_json' => '{}',
		'curriculum_links_json' => '[]',
		'settings_json'         => '{}',
	);
}

$nodes = array(
	array(
		'type'     => 'h1',
		'children' => array( array( 'text' => 'Fractions Practice' ) ),
	),
	array(
		'type'     => 'p',
		'children' => array(
			array(
				'text' => 'Explain your reasoning.',
				'bold' => true,
			),
		),
	),
	array(
		'type'            => 'ojai_response',
		'question_id'     => 'essay_1',
		'input_type'      => 'textarea',
		'prompt'          => 'How did you compare the fractions?',
		'required'        => true,
		'points'          => '5.00',
		'grading_enabled' => true,
		'children'        => array( array( 'text' => '' ) ),
	),
	array(
		'type'            => 'worksheet_response',
		'question_id'     => 'choice_1',
		'response_type'   => 'radio',
		'prompt'          => 'Which fraction is larger?',
		'required'        => true,
		'points'          => '2.00',
		'grading_enabled' => true,
		'correct_answer'  => 'one_half',
		'options'         => array(
			array(
				'value' => 'one_half',
				'label' => '1/2',
			),
			array(
				'value' => 'one_third',
				'label' => '1/3',
			),
		),
		'children'        => array( array( 'text' => '' ) ),
	),
);

$converted  = g5_s03w_converter()->convert( g5_s03w_record( $nodes ) );
$document   = $converted['document'];
$projection = $converted['projection'];
$mapping    = $converted['legacy_question_response_ids'];
g5_s03w_same( 'Fractions Practice', $converted['title'], 'Converted worksheet title drifted.' );
g5_s03w_same( Worksheet_Document_Profile::SCHEMA_ID, $document->document()['schema_id'], 'Converted document schema drifted.' );
g5_s03w_same( Worksheet_Document_Profile::EDITOR_PROFILE, $document->document()['editor_profile'], 'Converted editor profile drifted.' );
g5_s03w_same( 2, $document->statistics()['response_count'], 'Converted document lost response nodes.' );
g5_s03w_same( 2, count( $projection->questions() ), 'Question projector count drifted from converted responses.' );
g5_s03w_same( array( 'q_essay_1', 'q_choice_1' ), array_keys( $mapping ), 'Legacy stored question identity order was not preserved.' );
foreach ( $mapping as $response_id ) {
	g5_s03w_assert( Public_Id::is_valid( $response_id ), 'Legacy question was not mapped to a current response public ID.' );
}
g5_s03w_same( $mapping['q_essay_1'], $projection->questions()[0]['question_key'], 'Essay question key drifted from the response mapping.' );
g5_s03w_same( $mapping['q_choice_1'], $projection->questions()[1]['question_key'], 'Choice question key drifted from the response mapping.' );
$choice_private = $projection->questions()[1]['private_metadata']['grading'] ?? null;
g5_s03w_assert( is_array( $choice_private ), 'Choice grading metadata was not kept private.' );
g5_s03w_assert( Public_Id::is_valid( (string) ( $choice_private['correct_answer'] ?? '' ) ), 'Choice answer was not remapped to an option public ID.' );
g5_s03w_assert( ! str_contains( $projection->questions()[1]['question_json'], 'one_half' ) || ! str_contains( $projection->questions()[1]['question_json'], 'correct_answer' ), 'Correct answer leaked into the public question JSON.' );
g5_s03w_same( hash( 'sha256', $document->document_json() ), $document->document_hash(), 'Converted document hash is not canonical.' );

$without_h1 = g5_s03w_converter()->convert(
	g5_s03w_record(
		array(
			array(
				'type'     => 'p',
				'children' => array( array( 'text' => 'Body' ) ),
			),
		)
	)
);
g5_s03w_same( 'h1', $without_h1['document']->document()['nodes'][0]['type'], 'Missing legacy H1 did not receive the title H1.' );

$unsupported_record = g5_s03w_record(
	array(
		array(
			'type'     => 'video',
			'url'      => 'https://example.test/video',
			'children' => array( array( 'text' => '' ) ),
		),
	)
);
g5_s03w_invalid( static fn () => g5_s03w_converter()->convert( $unsupported_record ), 'Unsupported media node was silently discarded.' );

$settings_record                  = g5_s03w_record( $nodes );
$settings_record['settings_json'] = '{"allow_late":true}';
g5_s03w_invalid( static fn () => g5_s03w_converter()->convert( $settings_record ), 'Unmapped legacy worksheet settings were silently discarded.' );

$duplicate_nodes                   = $nodes;
$duplicate_nodes[3]['question_id'] = 'essay_1';
g5_s03w_invalid( static fn () => g5_s03w_converter()->convert( g5_s03w_record( $duplicate_nodes ) ), 'Duplicate legacy question IDs were accepted.' );

$missing_plate                     = g5_s03w_record( $nodes );
$missing_plate['plate_value_json'] = null;
g5_s03w_invalid( static fn () => g5_s03w_converter()->convert( $missing_plate ), 'Missing Plate content was converted into an empty worksheet.' );

$source_code = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/Legacy_Worksheet_Document_Converter.php' );
foreach ( array( '$wpdb', 'oj_ai_import_', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'INSERT ', 'UPDATE ', 'DELETE ' ) as $forbidden ) {
	g5_s03w_assert( ! str_contains( (string) $source_code, $forbidden ), 'Pure worksheet converter crossed a storage/platform boundary: ' . $forbidden );
}

fwrite( STDOUT, "G5-IMP-07-S03 worksheet converter passed: legacy Plate content, response identity, private grading, current canonicalization, and unsupported-source fail-closed behavior are frozen.\n" );

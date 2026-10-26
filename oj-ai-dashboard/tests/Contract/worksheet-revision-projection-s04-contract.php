<?php
/**
 * G2-IMP-02-S04 worksheet revision projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S04 fails.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $path Path.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s04_fails( callable $callback, string $reason, string $path, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Document_Validation_Failure $failure ) {
		s04_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		s04_same( $path, $failure->diagnostic_path(), $message . ' Diagnostic path mismatch.' );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * S04 id.
 *
 * @return string
 */
function s04_id(): string {
	static $suffix = 1000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s04_assert( Public_Id::is_valid( $id ), 'S04 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * S04 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function s04_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * S04 element.
 *
 * @param string     $type Type.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function s04_element( string $type, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s04_id(),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

$asset_image      = s04_id();
$asset_video      = s04_id();
$asset_file       = s04_id();
$option_one       = s04_id();
$option_two       = s04_id();
$multiple_one     = s04_id();
$multiple_two     = s04_id();
$table_row        = s04_id();
$table_column     = s04_id();
$rubric_criterion = s04_id();

$response_specs = array(
	array(
		'kind'        => 'short_text',
		'constraints' => array(
			'min_chars' => 1,
			'max_chars' => 100,
			'trim'      => true,
		),
		'grading'     => array(
			'grading_enabled'      => true,
			'max_points'           => '5.0',
			'correct_answer'       => 'Answer',
			'rubric_criterion_ids' => array( $rubric_criterion ),
			'teacher_note'         => 'Private note',
		),
	),
	array(
		'kind'        => 'long_text',
		'constraints' => array( 'max_chars' => 2000 ),
	),
	array(
		'kind'        => 'single_choice',
		'constraints' => array(
			'options' => array(
				array(
					'option_id' => $option_one,
					'value'     => 'a',
					'label'     => 'A',
				),
				array(
					'option_id' => $option_two,
					'value'     => 'b',
					'label'     => 'B',
				),
			),
		),
		'grading'     => array( 'correct_answer' => $option_one ),
	),
	array(
		'kind'        => 'multiple_choice',
		'constraints' => array(
			'options'      => array(
				array(
					'option_id' => $multiple_one,
					'value'     => 'x',
					'label'     => 'X',
				),
				array(
					'option_id' => $multiple_two,
					'value'     => 'y',
					'label'     => 'Y',
				),
			),
			'min_selected' => 1,
			'max_selected' => 2,
		),
	),
	array( 'kind' => 'checkbox' ),
	array(
		'kind'        => 'number',
		'constraints' => array(
			'min'  => '0',
			'max'  => '10',
			'step' => '0.5',
		),
	),
	array(
		'kind'        => 'table',
		'constraints' => array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $table_row,
					'label'  => 'Row',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $table_column,
					'label'     => 'Value',
					'cell_type' => 'short_text',
				),
			),
		),
	),
	array(
		'kind'        => 'image_upload',
		'constraints' => array(
			'mime_types' => array( 'image/png' ),
			'max_files'  => 1,
		),
	),
	array(
		'kind'        => 'file_upload',
		'constraints' => array(
			'mime_types' => array( 'application/pdf' ),
			'max_files'  => 1,
		),
	),
	array(
		'kind'        => 'drawing',
		'constraints' => array(
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1.0.0',
			'preview_mime_type'     => 'image/png',
			'aspect_ratio'          => '1.5',
			'max_strokes'           => 1000,
		),
	),
);

$response_ids = array();
$nodes        = array(
	s04_element( 'h1', array(), array( array( 'text' => 'Projection worksheet' ) ) ),
	s04_element(
		'img',
		array(
			'asset_id' => $asset_image,
			'alt'      => 'Image',
		)
	),
	s04_element(
		'video',
		array(
			'asset_id'        => $asset_video,
			'poster_asset_id' => $asset_image,
			'transcript'      => 'Transcript',
		)
	),
	s04_element(
		'file',
		array(
			'asset_id' => $asset_file,
			'label'    => 'Reference',
		)
	),
);
foreach ( $response_specs as $index => $spec ) {
	$response_id    = s04_id();
	$response_ids[] = $response_id;
	$nodes[]        = array_merge(
		s04_element( 'oj_ai_response' ),
		array(
			'response_id' => $response_id,
			'kind'        => $spec['kind'],
			'label'       => 'Question ' . ( $index + 1 ),
			'required'    => 0 === $index,
			'help_text'   => 'Help ' . ( $index + 1 ),
		),
		array_diff_key( $spec, array( 'kind' => true ) )
	);
}

$document = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => s04_id(),
	'locale'         => 'ko-KR',
	'title'          => 'Projection worksheet',
	'nodes'          => $nodes,
	'settings'       => array(
		'student_navigation' => 'document',
		'print_profile'      => 'worksheet_a4@1',
	),
);

$canonical_json = new Canonical_Json();
$canonicalizer  = new Worksheet_Document_Canonicalizer( $canonical_json, s04_generator( 10 ) );
$source         = $canonicalizer->canonicalize( $document );

$standard_one      = s04_id();
$standard_two      = s04_id();
$achievement_links = array(
	array(
		'scope'              => 'worksheet',
		'response_id'        => null,
		'standard_public_id' => $standard_one,
		'alignment_type'     => 'primary',
	),
	array(
		'scope'              => 'worksheet',
		'response_id'        => null,
		'standard_public_id' => $standard_two,
		'alignment_type'     => 'supporting',
	),
	array(
		'scope'              => 'response',
		'response_id'        => $response_ids[0],
		'standard_public_id' => $standard_one,
		'alignment_type'     => 'primary',
	),
	array(
		'scope'              => 'response',
		'response_id'        => $response_ids[0],
		'standard_public_id' => $standard_two,
		'alignment_type'     => 'supporting',
	),
	array(
		'scope'              => 'response',
		'response_id'        => $response_ids[1],
		'standard_public_id' => $standard_two,
		'alignment_type'     => 'primary',
	),
);
$snapshots         = array(
	array(
		'standard_public_id'      => $standard_one,
		'standard_source_version' => '2022',
		'standard_code_snapshot'  => 'SCI-01',
		'standard_label_snapshot' => 'Observe and explain',
	),
	array(
		'standard_public_id'      => $standard_two,
		'standard_source_version' => '2022',
		'standard_code_snapshot'  => 'SCI-02',
		'standard_label_snapshot' => 'Compare evidence',
	),
);

$projector  = new Worksheet_Revision_Projector( $canonical_json, s04_generator( 100 ) );
$projection = $projector->project( $source, $achievement_links, $snapshots );
$questions  = $projection->questions();

s04_same( 10, count( $questions ), 'All ten response kinds must create question rows.' );
s04_same( Worksheet_Document_Profile::RESPONSE_KINDS, array_column( $questions, 'response_type' ), 'Question row order must follow document order.' );
s04_same( range( 0, 9 ), array_column( $questions, 'position' ), 'Question positions must be zero-based and contiguous.' );
s04_same( $response_ids, array_column( $questions, 'question_key' ), 'Stable response IDs must be question keys.' );
s04_same( Worksheet_Revision_Projector::QUESTION_SCHEMA, $questions[0]['question_schema'], 'Question schema drifted.' );
s04_same( Worksheet_Revision_Projector::QUESTION_VERSION, $questions[0]['question_version'], 'Question schema version drifted.' );
s04_assert( Public_Id::is_valid( $questions[0]['public_id'] ), 'Question rows need server PublicIdV1 identifiers.' );
s04_assert( ! str_contains( $questions[0]['question_json'], 'grading' ), 'Student-safe question JSON must exclude grading.' );
s04_assert( ! str_contains( $questions[0]['question_json'], 'teacher_note' ), 'Student-safe question JSON must exclude teacher notes.' );
s04_same( 'Private note', $questions[0]['private_metadata']['grading']['teacher_note'], 'Private grading metadata must remain available to the revision.' );
s04_same( Worksheet_Revision_Projector::PRIVATE_METADATA_SCHEMA, $questions[0]['private_metadata_schema'], 'Private metadata schema drifted.' );
s04_same( null, $questions[1]['private_metadata'], 'Questions without grading must use nullable private metadata columns.' );
foreach ( $questions as $question ) {
	s04_same( hash( 'sha256', $question['question_json'] ), $question['question_hash'], 'Question hash must cover canonical bytes.' );
	s04_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $question['content_hash'] ), 'Question content hash must be SHA-256.' );
}

$worksheet_links = $projection->worksheet_achievement_links();
$question_links  = $projection->question_achievement_links();
s04_same( 2, count( $worksheet_links ), 'Worksheet-scoped achievement links must be separated.' );
s04_same( array( 0, 1 ), array_column( $worksheet_links, 'position' ), 'Worksheet link positions must preserve request order.' );
s04_same( 'SCI-01', $worksheet_links[0]['standard_code_snapshot'], 'Server snapshot code must be projected.' );
s04_same( 3, count( $question_links ), 'Response-scoped achievement links must be separated.' );
s04_same( array( 0, 1, 0 ), array_column( $question_links, 'position' ), 'Question link positions must restart per response.' );
s04_same( $questions[0]['public_id'], $question_links[0]['question_public_id'], 'Question links must resolve to the generated row.' );
s04_same( $response_ids[0], $question_links[0]['question_key'], 'Question links must retain stable response identity.' );

$asset_bindings = $projection->asset_bindings();
s04_same( 3, count( $asset_bindings ), 'Unique body assets must create one binding each.' );
s04_same( array( 0, 1, 2 ), array_column( $asset_bindings, 'position' ), 'Asset positions must follow first document encounter.' );
s04_same( $asset_image, $asset_bindings[0]['asset_id'], 'The first asset binding must retain its public ID.' );
s04_same( 2, count( $asset_bindings[0]['references'] ), 'Repeated image/poster usage must remain in one binding with two references.' );
s04_same( array( 'asset_id', 'poster_asset_id' ), array_column( $asset_bindings[0]['references'], 'property' ), 'Asset reference roles must be explicit.' );
s04_same( 4, $projection->statistics()['asset_reference_count'], 'All body asset occurrences must be counted.' );
s04_same( hash( 'sha256', $projection->projection_json() ), $projection->projection_hash(), 'Projection hash must cover its canonical manifest.' );

$second = ( new Worksheet_Revision_Projector( $canonical_json, s04_generator( 500 ) ) )->project(
	$source,
	$achievement_links,
	array_reverse( $snapshots )
);
s04_assert( $projection->questions()[0]['public_id'] !== $second->questions()[0]['public_id'], 'Revision-local question row IDs may be regenerated.' );
s04_same( $projection->projection_json(), $second->projection_json(), 'Authoritative snapshot query order and row IDs must not alter the projection manifest.' );
s04_same( $projection->projection_hash(), $second->projection_hash(), 'Equivalent projection content must have one stable hash.' );

$empty_projection = ( new Worksheet_Revision_Projector( $canonical_json, s04_generator( 800 ) ) )->project( $source, array(), array() );
s04_same( array(), $empty_projection->worksheet_achievement_links(), 'Empty achievement input must remain empty.' );
s04_same( array(), $empty_projection->question_achievement_links(), 'Empty achievement input must not require snapshots.' );

$invalid = static function ( array $links, array $standards, string $reason, string $path, string $message ) use ( $source, $canonical_json ): void {
	s04_fails(
		static fn () => ( new Worksheet_Revision_Projector( $canonical_json, s04_generator( 1000 ) ) )->project( $source, $links, $standards ),
		$reason,
		$path,
		$message
	);
};

$bad                      = $achievement_links;
$bad[0]['alignment_type'] = 'extension';
$invalid( $bad, $snapshots, Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED, '$.achievement_links[0].alignment_type', 'Unknown alignment types must fail.' );

$bad                   = $achievement_links;
$bad[0]['response_id'] = $response_ids[0];
$invalid( $bad, $snapshots, Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED, '$.achievement_links[0].response_id', 'Worksheet scope cannot carry a response ID.' );

$bad                   = $achievement_links;
$bad[2]['response_id'] = s04_id();
$invalid( $bad, $snapshots, Worksheet_Document_Validation_Failure::ID_INVALID, '$.achievement_links[2].response_id', 'Response links must target a response in the exact document.' );

$bad   = $achievement_links;
$bad[] = $achievement_links[0];
$invalid( $bad, $snapshots, Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED, '$.achievement_links[5]', 'Duplicate target-standard-alignment tuples must fail.' );

$bad                              = $achievement_links;
$bad[0]['standard_code_snapshot'] = 'CLIENT-VALUE';
$invalid( $bad, $snapshots, Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED, '$.achievement_links[0]', 'Clients cannot supply authoritative standard snapshots.' );

$invalid( $achievement_links, array( $snapshots[0] ), Worksheet_Document_Validation_Failure::ID_INVALID, '$.standard_snapshots', 'Missing authoritative standard snapshots must fail.' );

$extra_snapshots   = $snapshots;
$extra_snapshots[] = array(
	'standard_public_id'      => s04_id(),
	'standard_source_version' => '2022',
	'standard_code_snapshot'  => 'EXTRA',
	'standard_label_snapshot' => 'Extra',
);
$invalid( $achievement_links, $extra_snapshots, Worksheet_Document_Validation_Failure::ID_INVALID, '$.standard_snapshots', 'Unscoped authoritative snapshots must fail closed.' );

$bad_snapshots                               = $snapshots;
$bad_snapshots[0]['standard_label_snapshot'] = "Bad\nLabel";
$invalid( $achievement_links, $bad_snapshots, Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED, '$.standard_snapshots[0].standard_label_snapshot', 'Snapshot display labels must be one safe line.' );

fwrite( STDOUT, "G2-IMP-02-S04 worksheet revision projection contract passed.\n" );

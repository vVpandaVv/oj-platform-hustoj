<?php
/**
 * G2-IMP-02-S03 worksheet_plate@1 validator contract.
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

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * S03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S03 fails.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Worksheet_Document_Validation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s03_fails( callable $callback, string $reason, string $message ): Worksheet_Document_Validation_Failure {
	try {
		$callback();
	} catch ( Worksheet_Document_Validation_Failure $failure ) {
		s03_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * S03 id.
 *
 * @return string
 */
function s03_id(): string {
	static $suffix = 0;
	++$suffix;
	$id = '01J' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	s03_assert( Public_Id::is_valid( $id ), 'S03 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * S03 element.
 *
 * @param string     $type Type.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function s03_element( string $type, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => s03_id(),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * S03 paragraph.
 *
 * @param string $text Text.
 * @return array<string,mixed>
 */
function s03_paragraph( string $text ): array {
	return s03_element( 'p', array(), array( array( 'text' => $text ) ) );
}

/**
 * S03 document.
 *
 * @param array<int,array<string,mixed>> $nodes Nodes.
 * @return array<string,mixed>
 */
function s03_document( array $nodes ): array {
	return array(
		'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
		'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
		'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
		'document_id'    => s03_id(),
		'locale'         => 'ko_KR',
		'title'          => "  Worksheet\r\nTitle  ",
		'nodes'          => $nodes,
		'settings'       => array(
			'student_navigation' => 'document',
			'print_profile'      => 'worksheet_a4@1',
		),
	);
}

/**
 * S03 canonicalizer.
 *
 * @return Worksheet_Document_Canonicalizer
 */
function s03_canonicalizer(): Worksheet_Document_Canonicalizer {
	$counter = 0;
	$entropy = static function () use ( &$counter ): string {
		++$counter;
		return pack( 'N4', 0, 0, 0, $counter );
	};
	return new Worksheet_Document_Canonicalizer( new Canonical_Json(), new Public_Id_Generator( $entropy ) );
}

$temporary_node_id     = 'tmp_10000000-0000-0000-0000-000000000001';
$temporary_response_id = 'tmp_10000000-0000-0000-0000-000000000002';
$temporary_option_id   = 'tmp_10000000-0000-0000-0000-000000000003';
$temporary_goal_id     = 'tmp_10000000-0000-0000-0000-000000000004';
$worksheet_goal_id     = s03_id();
$content_column_one    = s03_id();
$content_column_two    = s03_id();
$asset_image           = s03_id();
$asset_audio           = s03_id();
$asset_video           = s03_id();
$asset_poster          = s03_id();
$asset_file            = s03_id();
$asset_drawing         = s03_id();
$rubric_criterion      = s03_id();
$worksheet_criterion   = s03_id();

$nodes = array(
	s03_element(
		'h1',
		array( 'align' => 'left' ),
		array(
			array(
				'text'   => 'Worksheet title',
				'bold'   => true,
				'italic' => false,
			),
		)
	),
	s03_element( 'h3', array(), array( array( 'text' => 'Observation' ) ) ),
	array(
		'type'     => 'p',
		'node_id'  => $temporary_node_id,
		'align'    => 'center',
		'children' => array(
			array( 'text' => 'Open ' ),
			s03_element(
				'a',
				array(
					'url'   => 'https://example.com/data',
					'label' => 'Data',
				),
				array(
					array(
						'text'      => 'data',
						'underline' => true,
					),
				)
			),
			array( 'text' => ' and ' ),
			s03_element(
				'equation',
				array(
					'tex'     => 'x^2 + y^2',
					'display' => false,
				)
			),
		),
	),
	s03_element( 'blockquote', array(), array( s03_paragraph( 'Quotation' ) ) ),
	s03_element( 'ul', array(), array( s03_element( 'li', array(), array( s03_paragraph( 'Bullet' ) ) ) ) ),
	s03_element( 'ol', array( 'start' => 3 ), array( s03_element( 'li', array(), array( s03_paragraph( 'Number' ) ) ) ) ),
	s03_element(
		'table',
		array( 'header' => true ),
		array(
			s03_element(
				'tr',
				array( 'row_id' => s03_id() ),
				array(
					s03_element( 'th', array( 'column_id' => $content_column_one ), array( s03_paragraph( 'Item' ) ) ),
					s03_element( 'th', array( 'column_id' => $content_column_two ), array( s03_paragraph( 'Value' ) ) ),
				)
			),
			s03_element(
				'tr',
				array( 'row_id' => s03_id() ),
				array(
					s03_element( 'td', array( 'column_id' => $content_column_one ), array( s03_paragraph( 'Temperature' ) ) ),
					s03_element( 'td', array( 'column_id' => $content_column_two ), array( s03_paragraph( '20' ) ) ),
				)
			),
		)
	),
	s03_element(
		'img',
		array(
			'asset_id' => $asset_image,
			'alt'      => 'Observation image',
			'width'    => 80,
		),
		array( s03_element( 'caption', array(), array( array( 'text' => 'Image caption' ) ) ) )
	),
	s03_element(
		'audio',
		array(
			'asset_id'   => $asset_audio,
			'transcript' => 'Audio transcript',
		)
	),
	s03_element(
		'video',
		array(
			'asset_id'        => $asset_video,
			'poster_asset_id' => $asset_poster,
			'transcript'      => 'Video transcript',
		)
	),
	s03_element(
		'file',
		array(
			'asset_id'    => $asset_file,
			'label'       => 'Reference',
			'description' => 'PDF material',
		)
	),
	s03_element( 'code_block', array( 'language' => 'python' ), array( s03_element( 'code_line', array(), array( array( 'text' => 'print("ok")' ) ) ) ) ),
	s03_element(
		'callout',
		array(
			'tone' => 'notice',
			'icon' => 'idea',
		),
		array( s03_paragraph( 'Think about it' ) )
	),
	s03_element(
		'column_group',
		array(),
		array(
			s03_element( 'column', array(), array( s03_paragraph( 'Left' ) ) ),
			s03_element( 'column', array(), array( s03_paragraph( 'Right' ) ) ),
		)
	),
	s03_element( 'hr' ),
	s03_element( 'page_break' ),
	s03_element(
		'oj_ai_drawing',
		array(
			'asset_id'              => $asset_drawing,
			'alt'                   => 'Observation drawing',
			'aspect_ratio'          => '1.5000',
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1.0.0',
		),
		array(
			s03_element( 'caption', array(), array( array( 'text' => 'Drawing caption' ) ) ),
		)
	),
);

$choice_option_one         = s03_id();
$choice_option_two         = s03_id();
$multiple_option_one       = s03_id();
$multiple_option_two       = s03_id();
$response_table_row        = s03_id();
$response_table_column_one = s03_id();
$response_table_column_two = s03_id();

$responses = array(
	array(
		'kind'        => 'short_text',
		'constraints' => array(
			'min_chars' => 1,
			'max_chars' => 100,
			'trim'      => true,
		),
		'grading'     => array(
			'grading_enabled'      => true,
			'max_points'           => '10.0',
			'example_answer'       => 'Example',
			'rubric_criterion_ids' => array( $rubric_criterion ),
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
					'option_id' => $choice_option_one,
					'value'     => 'a',
					'label'     => 'First',
				),
				array(
					'option_id' => $choice_option_two,
					'value'     => 'b',
					'label'     => 'Second',
				),
			),
		),
		'grading'     => array( 'correct_answer' => $choice_option_one ),
	),
	array(
		'kind'        => 'multiple_choice',
		'constraints' => array(
			'options'      => array(
				array(
					'option_id' => $multiple_option_one,
					'value'     => 'x',
					'label'     => 'X',
				),
				array(
					'option_id' => $temporary_option_id,
					'value'     => 'y',
					'label'     => 'Y',
				),
			),
			'min_selected' => 1,
			'max_selected' => 2,
		),
		'grading'     => array( 'correct_answer' => array( $temporary_option_id, $multiple_option_one ) ),
	),
	array(
		'kind'        => 'checkbox',
		'constraints' => array(),
		'grading'     => array( 'correct_answer' => true ),
	),
	array(
		'kind'        => 'number',
		'constraints' => array(
			'min'            => '-1.00',
			'max'            => '10.50',
			'step'           => '0.50',
			'unit'           => 'cm',
			'decimal_places' => 2,
		),
		'grading'     => array( 'correct_answer' => '2.00' ),
	),
	array(
		'kind'        => 'table',
		'constraints' => array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $response_table_row,
					'label'  => 'Row 1',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $response_table_column_one,
					'label'     => 'Observation',
					'cell_type' => 'short_text',
				),
				array(
					'column_id' => $response_table_column_two,
					'label'     => 'Check',
					'cell_type' => 'checkbox',
				),
			),
		),
		'grading'     => array(
			'correct_answer' => array(
				'z' => 'Last',
				'a' => 'First',
			),
		),
	),
	array(
		'kind'        => 'image_upload',
		'constraints' => array(
			'mime_types'   => array( 'image/webp', 'image/png' ),
			'max_files'    => 2,
			'max_bytes'    => 1048576,
			'alt_required' => true,
		),
	),
	array(
		'kind'        => 'file_upload',
		'constraints' => array(
			'mime_types' => array( 'application/pdf', 'text/plain' ),
			'max_files'  => 3,
			'max_bytes'  => 20971520,
		),
	),
	array(
		'kind'        => 'drawing',
		'constraints' => array(
			'stroke_schema_version' => 'oj_ai.drawing_strokes@1.0.0',
			'preview_mime_type'     => 'image/png',
			'aspect_ratio'          => '1.3330',
			'max_strokes'           => 2000,
		),
	),
);

foreach ( $responses as $index => $response ) {
	$nodes[] = array_merge(
		s03_element( 'oj_ai_response' ),
		array(
			'response_id' => 0 === $index ? $temporary_response_id : s03_id(),
			'kind'        => $response['kind'],
			'label'       => 'Response ' . ( $index + 1 ),
			'required'    => 0 === $index,
		),
		array_diff_key( $response, array( 'kind' => true ) )
	);
}

$source                                    = s03_document( $nodes );
$source['settings']['assessment_bindings'] = array(
	array(
		'criterion_id' => $rubric_criterion,
		'scope'        => 'response',
		'response_id'  => $temporary_response_id,
	),
	array(
		'criterion_id' => $worksheet_criterion,
		'scope'        => 'worksheet',
		'response_id'  => null,
	),
);
$source['settings']['learning_goals']      = array(
	array(
		'goal_id'     => $temporary_goal_id,
		'text'        => "  Analyze evidence\r\nclearly  ",
		'scope'       => 'response',
		'response_id' => $temporary_response_id,
	),
	array(
		'goal_id'     => $worksheet_goal_id,
		'text'        => 'Explain the result',
		'scope'       => 'worksheet',
		'response_id' => null,
	),
);
$receipt                                   = s03_canonicalizer()->canonicalize( $source );
$canonical                                 = $receipt->document();

s03_same( 16, count( Worksheet_Document_Profile::CONTENT_NODE_FAMILIES ), 'The profile must freeze 16 content node families.' );
s03_same( 6, count( Worksheet_Document_Profile::STRUCTURAL_NODE_TYPES ), 'The profile must freeze six structural node types.' );
s03_same( 10, count( Worksheet_Document_Profile::RESPONSE_KINDS ), 'The profile must freeze ten response kinds.' );
s03_same( 4, count( Worksheet_Document_Profile::TEXT_MARKS ), 'The profile must freeze four text marks.' );
s03_same( 2097152, Worksheet_Document_Profile::MAX_DOCUMENT_BYTES, 'L01 must remain 2 MiB.' );
s03_same( 5000, Worksheet_Document_Profile::MAX_NODES, 'L02 must remain 5,000 nodes.' );
s03_same( 32, Worksheet_Document_Profile::MAX_DEPTH, 'L03 must remain 32 levels.' );
s03_same( 500000, Worksheet_Document_Profile::MAX_TOTAL_TEXT_CHARACTERS, 'L04 total text limit drifted.' );
s03_same( 32768, Worksheet_Document_Profile::MAX_TEXT_LEAF_CHARACTERS, 'L04 leaf limit drifted.' );
s03_same( 200, Worksheet_Document_Profile::MAX_RESPONSES, 'L05 response limit drifted.' );
s03_same( 100, Worksheet_Document_Profile::MAX_ASSESSMENT_BINDINGS, 'Assessment binding limit drifted.' );
s03_same( 50, Worksheet_Document_Profile::MAX_LEARNING_GOALS, 'Learning goal limit drifted.' );
s03_same( 1000, Worksheet_Document_Profile::MAX_LEARNING_GOAL_CHARACTERS, 'Learning goal text limit drifted.' );
s03_same( 100, Worksheet_Document_Profile::MAX_OPTIONS_PER_RESPONSE, 'L06 option limit drifted.' );
s03_same( 100, Worksheet_Document_Profile::MAX_CONTENT_TABLE_ROWS, 'L07 row limit drifted.' );
s03_same( 20, Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS, 'L07 column limit drifted.' );
s03_same( 3000, Worksheet_Document_Profile::MAX_RESPONSE_TABLE_CELLS, 'L08 cell limit drifted.' );
s03_same( 100, Worksheet_Document_Profile::MAX_BODY_ASSETS, 'L09 asset limit drifted.' );
s03_same( 2048, Worksheet_Document_Profile::MAX_LINK_BYTES, 'L10 URL limit drifted.' );
s03_same( 255, Worksheet_Document_Profile::MAX_TITLE_CHARACTERS, 'L11 title limit drifted.' );
s03_same( 20971520, Worksheet_Document_Profile::MAX_FILE_BYTES, 'L12 file limit drifted.' );

s03_same( 'ko-KR', $canonical['locale'], 'Locale must be canonicalized.' );
s03_same( "Worksheet\nTitle", $canonical['title'], 'Title whitespace and line endings must be canonicalized.' );
s03_same( true, $canonical['nodes'][0]['children'][0]['bold'], 'Allowed true marks must remain.' );
s03_assert( ! isset( $canonical['nodes'][0]['children'][0]['italic'] ), 'False marks must be omitted canonically.' );
s03_same( '1.5', $canonical['nodes'][16]['aspect_ratio'], 'Decimal strings must use one canonical representation.' );
$table_responses = array_values(
	array_filter(
		$canonical['nodes'],
		static fn ( array $node ): bool => 'oj_ai_response' === ( $node['type'] ?? '' ) && 'table' === ( $node['kind'] ?? '' )
	)
);
s03_same( array( 'a', 'z' ), array_keys( $table_responses[0]['grading']['correct_answer'] ), 'Table answers must be normalized in the canonical PHP document.' );
s03_assert( ! str_contains( $receipt->document_json(), 'tmp_' ), 'Canonical JSON must not retain browser temporary IDs.' );
s03_same( 4, count( $receipt->temporary_id_map() ), 'Node, response, option, and learning-goal temporary IDs must be mapped.' );
foreach ( $receipt->temporary_id_map() as $temporary_id => $stable_id ) {
	s03_assert( 1 === preg_match( Worksheet_Document_Profile::TEMPORARY_ID_PATTERN, $temporary_id ), 'Temporary map key is invalid.' );
	s03_assert( Public_Id::is_valid( $stable_id ), 'Temporary map value must be PublicIdV1.' );
}
s03_same( $rubric_criterion, $canonical['settings']['assessment_bindings'][0]['criterion_id'], 'External rubric criterion IDs must remain stable.' );
s03_same( $receipt->temporary_id_map()[ $temporary_response_id ], $canonical['settings']['assessment_bindings'][0]['response_id'], 'Assessment response targets must follow temporary ID mapping.' );
s03_same( null, $canonical['settings']['assessment_bindings'][1]['response_id'], 'Worksheet-wide assessment targets must remain null.' );
s03_same( $receipt->temporary_id_map()[ $temporary_goal_id ], $canonical['settings']['learning_goals'][0]['goal_id'], 'Learning-goal IDs must become stable document IDs.' );
s03_same( $receipt->temporary_id_map()[ $temporary_response_id ], $canonical['settings']['learning_goals'][0]['response_id'], 'Response-scoped learning goals must follow response ID mapping.' );
s03_same( "Analyze evidence\nclearly", $canonical['settings']['learning_goals'][0]['text'], 'Learning-goal text must be canonicalized.' );
s03_same( null, $canonical['settings']['learning_goals'][1]['response_id'], 'Worksheet-wide learning goals must retain a null target.' );
s03_same( 10, $receipt->statistics()['response_count'], 'All ten response kinds must be counted.' );
s03_same( 6, $receipt->statistics()['body_asset_count'], 'Unique body asset and poster references must be counted.' );
s03_same( 1, $receipt->statistics()['warning_count'], 'Skipped heading level must create one deterministic warning.' );
s03_same( 'worksheet_heading_level_skipped', $receipt->warnings()[0]['code'], 'Heading warning code drifted.' );
s03_same( hash( 'sha256', $receipt->document_json() ), $receipt->document_hash(), 'Document hash must cover canonical UTF-8 bytes.' );
s03_same( hash( 'sha256', $receipt->validation_json() ), $receipt->validation_hash(), 'Validation hash must cover the validator receipt.' );
s03_assert( strlen( $receipt->document_json() ) <= Worksheet_Document_Profile::MAX_DOCUMENT_BYTES, 'Valid canonical bytes must fit L01.' );

$stable_reordered = array_reverse( $canonical, true );
$second_receipt   = s03_canonicalizer()->canonicalize( $stable_reordered );
s03_same( $receipt->document_json(), $second_receipt->document_json(), 'Object key order must not alter canonical bytes.' );
s03_same( $receipt->document_hash(), $second_receipt->document_hash(), 'Object key order must not alter document hash.' );

$invalid = static function ( callable $mutator, string $reason, string $message ) use ( $source ): Worksheet_Document_Validation_Failure {
	$document = $source;
	$mutator( $document );
	return s03_fails( static fn () => s03_canonicalizer()->canonicalize( $document ), $reason, $message );
};

$invalid(
	static function ( array &$document ): void {
		$document['schema_version'] = '2.0.0';
	},
	Worksheet_Document_Validation_Failure::SCHEMA_UNSUPPORTED,
	'Unsupported schema must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][1]['type'] = 'hws_response';
	},
	Worksheet_Document_Validation_Failure::NODE_NOT_ALLOWED,
	'Legacy response aliases must fail canonical input.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][2]['style'] = 'color:red';
	},
	Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED,
	'Unknown properties must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][0]['children'][0]['color'] = '#fff';
	},
	Worksheet_Document_Validation_Failure::NODE_NOT_ALLOWED,
	'Unknown marks must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][1]['node_id'] = $document['nodes'][0]['node_id'];
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Duplicate IDs must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][2]['node_id'] = 'bad';
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Malformed IDs must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['settings']['assessment_bindings'][1]['criterion_id'] = $document['settings']['assessment_bindings'][0]['criterion_id'];
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Duplicate assessment criteria must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['settings']['assessment_bindings'][0]['response_id'] = s03_id();
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Assessment bindings must target an existing response.'
);
$invalid(
	static function ( array &$document ): void {
		$document['settings']['learning_goals'][1]['goal_id'] = $document['settings']['learning_goals'][0]['goal_id'];
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Learning-goal IDs must be unique.'
);
$invalid(
	static function ( array &$document ): void {
		$document['settings']['learning_goals'][0]['response_id'] = s03_id();
	},
	Worksheet_Document_Validation_Failure::ID_INVALID,
	'Learning goals must target an existing response.'
);
$invalid(
	static function ( array &$document ): void {
		$document['settings']['learning_goals'][0]['text'] = str_repeat( 'a', Worksheet_Document_Profile::MAX_LEARNING_GOAL_CHARACTERS + 1 );
	},
	Worksheet_Document_Validation_Failure::LIMIT_EXCEEDED,
	'Oversized learning goals must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][2]['children'][1]['url'] = 'http://example.com';
	},
	Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED,
	'Non-HTTPS links must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][2]['children'][3]['tex'] = '\\newcommand{\\x}{1}';
	},
	Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED,
	'TeX macros must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][3]['children'] = array( s03_element( 'li', array(), array( s03_paragraph( 'Wrong parent' ) ) ) );
	},
	Worksheet_Document_Validation_Failure::NODE_NOT_ALLOWED,
	'Structural nodes outside their parent must fail.'
);
$invalid(
	static function ( array &$document ): void {
		$document['nodes'][0]['children'][0]['text'] = str_repeat( 'a', Worksheet_Document_Profile::MAX_TEXT_LEAF_CHARACTERS + 1 );
	},
	Worksheet_Document_Validation_Failure::LIMIT_EXCEEDED,
	'Oversized text leaves must fail.'
);
$url_failure = $invalid(
	static function ( array &$document ): void {
		$document['nodes'][2]['children'][1]['url'] = 'https://example.com/' . str_repeat( 'a', 2048 );
	},
	Worksheet_Document_Validation_Failure::PROPERTY_NOT_ALLOWED,
	'Oversized URLs must fail.'
);
s03_same( '$.nodes[2].children[1].url', $url_failure->diagnostic_path(), 'Failures must retain an exact internal path.' );

$deep = s03_paragraph( 'Depth' );
for ( $depth = 0; $depth < Worksheet_Document_Profile::MAX_DEPTH; ++$depth ) {
	$deep = s03_element( 'callout', array( 'tone' => 'info' ), array( $deep ) );
}
$deep_document = s03_document( array( s03_element( 'h1', array(), array( array( 'text' => 'Title' ) ) ), $deep ) );
s03_fails( static fn () => s03_canonicalizer()->canonicalize( $deep_document ), Worksheet_Document_Validation_Failure::LIMIT_EXCEEDED, 'Tree depth beyond L03 must fail.' );

$asset_nodes = array( s03_element( 'h1', array(), array( array( 'text' => 'Title' ) ) ) );
for ( $index = 0; $index <= Worksheet_Document_Profile::MAX_BODY_ASSETS; ++$index ) {
	$asset_nodes[] = s03_element(
		'img',
		array(
			'asset_id' => s03_id(),
			'alt'      => 'Image',
		)
	);
}
$asset_document = s03_document( $asset_nodes );
s03_fails( static fn () => s03_canonicalizer()->canonicalize( $asset_document ), Worksheet_Document_Validation_Failure::LIMIT_EXCEEDED, 'Unique assets beyond L09 must fail.' );

$option_nodes = array( s03_element( 'h1', array(), array( array( 'text' => 'Title' ) ) ) );
$options      = array();
for ( $index = 0; $index <= Worksheet_Document_Profile::MAX_OPTIONS_PER_RESPONSE; ++$index ) {
	$options[] = array(
		'option_id' => s03_id(),
		'value'     => 'v' . $index,
		'label'     => 'Option ' . $index,
	);
}
$option_nodes[]  = array_merge(
	s03_element( 'oj_ai_response' ),
	array(
		'response_id' => s03_id(),
		'kind'        => 'single_choice',
		'label'       => 'Choose',
		'required'    => true,
		'constraints' => array( 'options' => $options ),
	)
);
$option_document = s03_document( $option_nodes );
s03_fails( static fn () => s03_canonicalizer()->canonicalize( $option_document ), Worksheet_Document_Validation_Failure::LIMIT_EXCEEDED, 'Options beyond L06 must fail.' );

fwrite( STDOUT, "G2-IMP-02-S03 worksheet_plate@1 validator contract passed.\n" );

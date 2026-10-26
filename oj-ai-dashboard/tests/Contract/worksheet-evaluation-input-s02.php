<?php
/**
 * G2-IMP-05-S02 deterministic worksheet evaluation input contract.
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Failure;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S02 deterministic worksheet evaluation input contract.
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
			unset( $form );
			return $value;
		}
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Eval s02 fails.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $path Path.
 * @param string           $message Message.
 * @return Worksheet_Evaluation_Input_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s02_fails( callable $callback, string $reason, string $path, string $message ): Worksheet_Evaluation_Input_Failure {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Input_Failure $failure ) {
		eval_s02_same( $reason, $failure->reason(), $message . ' Stable reason mismatch.' );
		eval_s02_same( $path, $failure->diagnostic_path(), $message . ' Diagnostic path mismatch.' );
		return $failure;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s02 id.
 *
 * @return string
 */
function eval_s02_id(): string {
	static $suffix = 2000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s02_assert( Public_Id::is_valid( $id ), 'S02 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s02 generator.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function eval_s02_generator( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * Eval s02 element.
 *
 * @param string     $type Type.
 * @param array      $properties Properties.
 * @param array|null $children Children.
 * @return array<string,mixed>
 */
function eval_s02_element( string $type, array $properties = array(), ?array $children = null ): array {
	return array_merge(
		array(
			'type'     => $type,
			'node_id'  => eval_s02_id(),
			'children' => $children ?? array( array( 'text' => '' ) ),
		),
		$properties
	);
}

/**
 * Eval s02 dimensions.
 *
 * @return array<int,array<string,mixed>>
 */
function eval_s02_dimensions(): array {
	$labels = array(
		'learning_goal_alignment' => 'Learning goal alignment',
		'instruction_clarity'     => 'Instruction clarity',
		'answerability'           => 'Answerability',
		'difficulty_fit'          => 'Difficulty fit',
		'rubric_quality'          => 'Rubric quality',
		'safety'                  => 'Safety',
	);
	$result = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $key ) {
		$result[] = array(
			'dimension_key'     => $key,
			'label'             => $labels[ $key ],
			'description'       => 'Assess ' . strtolower( $labels[ $key ] ) . ' using explicit worksheet evidence.',
			'evidence_required' => true,
		);
	}
	return $result;
}

/**
 * Eval s02 configuration.
 *
 * @param string $criterion_id Criterion id.
 * @return array<string,mixed>
 */
function eval_s02_configuration( string $criterion_id ): array {
	return array(
		'policy'         => array(
			'revision_id'  => eval_s02_id(),
			'content_hash' => str_repeat( 'b', 64 ),
			'subject_type' => 'worksheet_quality',
		),
		'rubric'         => array(
			'revision_id'  => eval_s02_id(),
			'content_hash' => str_repeat( 'c', 64 ),
			'dimensions'   => eval_s02_dimensions(),
		),
		'grading_rubric' => array(
			'assessment_enabled' => true,
			'revision_id'        => eval_s02_id(),
			'content_hash'       => str_repeat( 'a', 64 ),
			'total_points'       => '10.0',
			'criteria'           => array(
				array(
					'criterion_id'   => $criterion_id,
					'label'          => 'Evidence and reasoning',
					'description'    => 'Check whether the response selects relevant evidence and explains how it supports the claim.',
					'maximum_points' => '10.0',
					'position'       => 0,
					'scope'          => 'response',
					'levels'         => array(
						array(
							'label'     => 'Uses relevant evidence and explains the connection.',
							'level_key' => eval_s02_id(),
							'points'    => '10.0',
							'position'  => 0,
						),
						array(
							'label'     => 'Evidence is missing or the connection is not explained.',
							'level_key' => eval_s02_id(),
							'points'    => '0',
							'position'  => 1,
						),
					),
				),
			),
		),
		'review_rule'    => array(
			'revision_id'               => eval_s02_id(),
			'content_hash'              => str_repeat( 'd', 64 ),
			'review_required'           => true,
			'explicit_release_required' => true,
			'confidence_threshold'      => '0.8000',
			'abstain_handling'          => 'require_review',
			'safety_handling'           => 'require_explicit_review',
		),
		'prompt'         => array(
			'contract_version' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
			'content_hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
		),
		'input_schema'   => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
			'content_hash'   => str_repeat( 'e', 64 ),
		),
		'output_schema'  => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
			'content_hash'   => str_repeat( 'f', 64 ),
		),
	);
}

/**
 * Eval s02 collect node ids.
 *
 * @param array<int,array<string,mixed>> $nodes Nodes.
 * @param array<int,string>              &$ids Ids.
 * @return void
 */
function eval_s02_collect_node_ids( array $nodes, array &$ids ): void {
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) || array_key_exists( 'text', $node ) ) {
			continue;
		}
		$ids[] = (string) $node['node_id'];
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			eval_s02_collect_node_ids( $node['children'], $ids );
		}
	}
}

$worksheet_id        = eval_s02_id();
$revision_id         = eval_s02_id();
$image_asset_id      = eval_s02_id();
$file_asset_id       = eval_s02_id();
$response_id         = eval_s02_id();
$criterion_id        = eval_s02_id();
$unused_criterion_id = eval_s02_id();
$standard_id         = eval_s02_id();

$link     = eval_s02_element(
	'a',
	array(
		'url'   => 'https://Example.COM/resource/path?token=private#section',
		'label' => 'Reference source',
	),
	array( array( 'text' => 'reference page' ) )
);
$image    = eval_s02_element(
	'img',
	array(
		'asset_id' => $image_asset_id,
		'alt'      => 'A labeled triangle used by the question.',
		'width'    => 70,
	),
	array( eval_s02_element( 'caption', array(), array( array( 'text' => 'Triangle diagram' ) ) ) )
);
$file     = eval_s02_element(
	'file',
	array(
		'asset_id'    => $file_asset_id,
		'label'       => 'Data table',
		'description' => 'A text-accessible reference table.',
	)
);
$response = eval_s02_element(
	'oj_ai_response',
	array(
		'response_id' => $response_id,
		'kind'        => 'long_text',
		'label'       => 'Explain how the evidence supports your answer.',
		'required'    => true,
		'help_text'   => 'Use the diagram and reference table.',
		'constraints' => array(
			'min_chars' => 20,
			'max_chars' => 1000,
		),
		'grading'     => array(
			'grading_enabled'      => true,
			'max_points'           => '5.0',
			'correct_answer'       => 'The explanation connects the diagram to the evidence.',
			'example_answer'       => 'The triangle labels support the stated relationship.',
			'rubric_criterion_ids' => array( $criterion_id ),
			'teacher_note'         => 'Private teacher guidance must never enter P04.',
		),
	)
);
$document = array(
	'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
	'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
	'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
	'document_id'    => eval_s02_id(),
	'locale'         => 'ko-KR',
	'title'          => 'Evidence worksheet',
	'nodes'          => array(
		eval_s02_element( 'h1', array(), array( array( 'text' => 'Evidence worksheet' ) ) ),
		eval_s02_element(
			'p',
			array( 'align' => 'left' ),
			array( array( 'text' => 'Read the ' ), $link, array( 'text' => ' before answering.' ) )
		),
		$image,
		$file,
		$response,
	),
	'settings'       => array(
		'student_navigation'  => 'document',
		'print_profile'       => 'worksheet_a4@1',
		'assessment_bindings' => array(
			array(
				'criterion_id' => $criterion_id,
				'scope'        => 'response',
				'response_id'  => $response_id,
			),
		),
	),
);

$canonical                                     = new Canonical_Json();
$source                                        = ( new Worksheet_Document_Canonicalizer( $canonical, eval_s02_generator( 10 ) ) )->canonicalize( $document );
$projection                                    = ( new Worksheet_Revision_Projector( $canonical, eval_s02_generator( 100 ) ) )->project(
	$source,
	array(
		array(
			'scope'              => 'worksheet',
			'response_id'        => null,
			'standard_public_id' => $standard_id,
			'alignment_type'     => 'primary',
		),
		array(
			'scope'              => 'response',
			'response_id'        => $response_id,
			'standard_public_id' => $standard_id,
			'alignment_type'     => 'supporting',
		),
	),
	array(
		array(
			'standard_public_id'      => $standard_id,
			'standard_source_version' => '2022',
			'standard_code_snapshot'  => 'SCI-EVIDENCE-01',
			'standard_label_snapshot' => 'Explain claims using evidence',
		),
	)
);
$asset_snapshots                               = array(
	array(
		'asset_id'            => $image_asset_id,
		'kind'                => 'image',
		'mime_type'           => 'image/png',
		'sha256'              => str_repeat( '1', 64 ),
		'text_representation' => 'A triangle with three labeled vertices.',
	),
	array(
		'asset_id'            => $file_asset_id,
		'kind'                => 'file',
		'mime_type'           => 'application/pdf',
		'sha256'              => str_repeat( '2', 64 ),
		'text_representation' => 'Reference values: A equals 3, B equals 4, and C equals 5.',
	),
);
$configuration                                 = eval_s02_configuration( $criterion_id );
$configuration['grading_rubric']['criteria'][] = array(
	'criterion_id'   => $unused_criterion_id,
	'label'          => 'Unused overall presentation',
	'description'    => 'This global criterion is not selected for this worksheet.',
	'maximum_points' => '3',
	'position'       => 1,
	'scope'          => 'submission',
	'levels'         => array(
		array(
			'label'     => 'Presentation is complete.',
			'level_key' => eval_s02_id(),
			'points'    => '3',
			'position'  => 0,
		),
		array(
			'label'     => 'Presentation is incomplete.',
			'level_key' => eval_s02_id(),
			'points'    => '0',
			'position'  => 1,
		),
	),
);
$configuration['grading_rubric']['total_points'] = '13';
$builder = new Worksheet_Evaluation_Input_Builder( $canonical );
$receipt = $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $configuration );
$input   = $receipt->input();
$payload = $input['worksheet']['payload'];

eval_s02_same( Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA, $input['schema_id'], 'Request schema drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::REQUEST_VERSION, $input['schema_version'], 'Request version drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::OPERATION, $input['operation'], 'Operation drifted.' );
eval_s02_same( $worksheet_id, $input['subject']['worksheet_id'], 'Subject worksheet ID drifted.' );
eval_s02_same( $revision_id, $input['subject']['worksheet_revision_id'], 'Subject revision ID drifted.' );
eval_s02_same( $source->document_hash(), $input['subject']['source_hash'], 'Subject source hash drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::P04_SCHEMA, $input['worksheet']['schema_id'], 'P04 schema drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::P04_VERSION, $input['worksheet']['schema_version'], 'P04 version drifted.' );
eval_s02_same( hash( 'sha256', $canonical->encode( $payload ) ), $input['worksheet']['payload_hash'], 'P04 payload hash must cover canonical bytes.' );
eval_s02_same( hash( 'sha256', $canonical->encode( $input['worksheet'] ) ), $receipt->worksheet_projection_hash(), 'P04 envelope hash must cover canonical bytes.' );
eval_s02_same( $canonical->encode( $input ), $receipt->input_json(), 'Gateway input must use canonical JSON.' );
eval_s02_same( hash( 'sha256', $receipt->input_json() ), $receipt->input_hash(), 'Input hash must cover exact canonical bytes.' );
eval_s02_assert( $receipt->byte_size() <= Worksheet_Evaluation_Input_Builder::MAXIMUM_INPUT_BYTES, 'Normal input must remain under the local 900 KiB limit.' );

eval_s02_same( Worksheet_Evaluation_Input_Builder::INPUT_CONTRACT, $input['input_contract']['contract_version'], 'Manifest input contract drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT, $input['prompt']['contract_version'], 'Prompt contract drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH, $input['prompt']['content_hash'], 'Prompt content hash drifted.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA, $input['output_contract']['schema_id'], 'Output schema drifted.' );
eval_s02_same( '0.8', $input['review_rule']['confidence_threshold'], 'Confidence threshold must use canonical decimal text.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS, array_column( $input['rubric']['dimensions'], 'dimension_key' ), 'Core dimensions must remain exact and ordered.' );
eval_s02_same( $criterion_id, $payload['grading_rubric']['criteria'][0]['criterion_id'], 'P04 must include the active student grading criterion.' );
eval_s02_same( 1, count( $payload['grading_rubric']['criteria'] ), 'P04 must exclude global criteria not selected for this worksheet.' );
eval_s02_same( '10', $payload['grading_rubric']['total_points'], 'P04 must total only the criteria selected for this worksheet.' );
eval_s02_assert(
	! in_array( $unused_criterion_id, array_column( $payload['grading_rubric']['criteria'], 'criterion_id' ), true ),
	'P04 must not expose an unselected global criterion to worksheet-quality AI.'
);
eval_s02_same( 'response', $payload['grading_rubric']['criteria'][0]['scope'], 'P04 must preserve the grading criterion scope.' );
eval_s02_same( $response_id, $payload['grading_rubric']['criteria'][0]['response_id'], 'P04 must identify the response targeted by the selected criterion.' );
eval_s02_same( true, $payload['grading_rubric']['assessment_enabled'], 'P04 must report whether student assessment is enabled.' );
eval_s02_same( 'Check whether the response selects relevant evidence and explains how it supports the claim.', $payload['grading_rubric']['criteria'][0]['description'], 'P04 must include the observable assessment method.' );
eval_s02_same( 'Uses relevant evidence and explains the connection.', $payload['grading_rubric']['criteria'][0]['levels'][0]['label'], 'P04 must include observable level descriptions.' );

$long_configuration = $configuration;
$long_description   = str_repeat( '가', 249 ) . "\n" . str_repeat( '나', 250 );
$long_configuration['grading_rubric']['criteria'][0]['levels'][0]['label'] = $long_description;
$long_input = $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $long_configuration )->input();
eval_s02_same( $long_description, $long_input['worksheet']['payload']['grading_rubric']['criteria'][0]['levels'][0]['label'], 'Worksheet review must preserve 500-character multiline performance descriptions.' );
eval_s02_assert( ! array_key_exists( 'review', $input ), 'Only review_rule may appear in the request.' );

$projected_link = $payload['document']['nodes'][1]['children'][1];
eval_s02_same( 'example.com', $projected_link['link_host'], 'Links must retain only a normalized host.' );
eval_s02_same( true, $projected_link['link_required'], 'Link dependency must remain explicit.' );
eval_s02_assert( ! array_key_exists( 'url', $projected_link ), 'P04 links must not retain full URLs.' );
eval_s02_same( 'reference page', $projected_link['children'][0]['text'], 'Link display text must remain available.' );

eval_s02_same( 2, count( $payload['media'] ), 'All media references must be represented.' );
eval_s02_same( str_repeat( '1', 64 ), $payload['media'][0]['sha256'], 'Media digest must remain available.' );
eval_s02_same( 'A triangle with three labeled vertices.', $payload['media'][0]['text_representation'], 'Inspected text representation must remain available.' );
eval_s02_assert( ! array_key_exists( 'asset_id', $payload['media'][0] ), 'Private asset IDs must not enter P04 media descriptors.' );
eval_s02_same( 'A labeled triangle used by the question.', $payload['document']['nodes'][2]['alt'], 'Safe image alt text must remain in the document.' );
eval_s02_same( 'Triangle diagram', $payload['document']['nodes'][2]['children'][0]['children'][0]['text'], 'Safe media captions must remain available.' );

eval_s02_same( 1, count( $payload['responses'] ), 'Response design must be projected exactly once.' );
eval_s02_same( $response_id, $payload['responses'][0]['response_id'], 'Response identity drifted.' );
eval_s02_same( '5', $payload['responses'][0]['grading']['max_points'], 'Canonical maximum points must remain available.' );
eval_s02_same( 'The explanation connects the diagram to the evidence.', $payload['responses'][0]['grading']['correct_answer'], 'Answerability evidence must remain available.' );
eval_s02_assert( ! array_key_exists( 'teacher_note', $payload['responses'][0]['grading'] ), 'Teacher notes must never enter P04.' );

eval_s02_same( null, $payload['achievements']['worksheet'][0]['description'], 'Missing frozen curriculum descriptions must remain explicit null.' );
eval_s02_same( 'SCI-EVIDENCE-01', $payload['achievements']['worksheet'][0]['code'], 'Frozen curriculum code must remain available.' );
eval_s02_same( $standard_id, $payload['achievements']['responses'][0]['anchor_id'], 'Response achievement anchor drifted.' );

$expected_node_ids = array();
eval_s02_collect_node_ids( $source->document()['nodes'], $expected_node_ids );
$expected_node_ids = array_values( array_unique( $expected_node_ids ) );
sort( $expected_node_ids, SORT_STRING );
eval_s02_same( $expected_node_ids, $receipt->anchor_manifest()['node'], 'Node evidence anchors must cover the exact document.' );
eval_s02_same( array( $response_id ), $receipt->anchor_manifest()['response'], 'Response evidence anchors drifted.' );
eval_s02_same( array( $standard_id ), $receipt->anchor_manifest()['achievement'], 'Achievement evidence anchors must be unique.' );

$serialized = $receipt->input_json();
foreach ( array( 'teacher_note', 'asset_id', 'public_url', 'storage_key', 'Authorization', 'https://', '?token=', '#section', 'Private teacher guidance' ) as $forbidden ) {
	eval_s02_assert( ! str_contains( $serialized, $forbidden ), 'P04 contains forbidden data: ' . $forbidden );
}

$second = $builder->build( $worksheet_id, $revision_id, $source, $projection, array_reverse( $asset_snapshots ), $configuration );
eval_s02_same( $receipt->input_json(), $second->input_json(), 'Equivalent asset snapshot order must produce identical canonical bytes.' );
eval_s02_same( $receipt->input_hash(), $second->input_hash(), 'Equivalent input must produce one stable hash.' );
eval_s02_same( $receipt->configuration_hash(), $second->configuration_hash(), 'Equivalent configuration must produce one stable hash.' );

$changed_configuration                           = $configuration;
$changed_configuration['policy']['content_hash'] = str_repeat( 'a', 64 );
$changed = $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $changed_configuration );
eval_s02_assert( $receipt->configuration_hash() !== $changed->configuration_hash(), 'Configuration changes must change the frozen configuration hash.' );
eval_s02_assert( $receipt->input_hash() !== $changed->input_hash(), 'Configuration changes must change the exact request hash.' );

eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, array( $asset_snapshots[0] ), $configuration ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.asset_snapshots',
	'Missing asset snapshots must fail closed.'
);
$bad_assets                  = $asset_snapshots;
$bad_assets[0]['public_url'] = 'https://cdn.example.invalid/private.png';
eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, $bad_assets, $configuration ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.asset_snapshots[0]',
	'Unknown or public asset fields must fail closed.'
);
$bad_dimensions                            = $configuration;
$first                                     = $bad_dimensions['rubric']['dimensions'][0];
$bad_dimensions['rubric']['dimensions'][0] = $bad_dimensions['rubric']['dimensions'][1];
$bad_dimensions['rubric']['dimensions'][1] = $first;
eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $bad_dimensions ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.configuration.rubric.dimensions[0].dimension_key',
	'Core dimension order drift must fail closed.'
);
$bad_review                                   = $configuration;
$bad_review['review_rule']['review_required'] = false;
eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $bad_review ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.configuration.review_rule',
	'Human review cannot be disabled.'
);
$bad_prompt                           = $configuration;
$bad_prompt['prompt']['content_hash'] = str_repeat( '0', 64 );
eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $bad_prompt ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.configuration.prompt',
	'Prompt hash drift must fail closed.'
);
$bad_configuration             = $configuration;
$bad_configuration['provider'] = array();
eval_s02_fails(
	static fn () => $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $bad_configuration ),
	Worksheet_Evaluation_Input_Failure::INPUT_INVALID,
	'$.configuration',
	'Unknown provider configuration must fail closed.'
);

$large_nodes = array(
	eval_s02_element( 'h1', array(), array( array( 'text' => 'Large' ) ) ),
);
$large_text  = array(
	'text'          => str_repeat( 'x', 200 ),
	'bold'          => true,
	'italic'        => true,
	'underline'     => true,
	'strikethrough' => true,
);
for ( $index = 0; $index < 2499; ++$index ) {
	$large_nodes[] = eval_s02_element( 'p', array( 'align' => 'justify' ), array( $large_text ) );
}
$large_source     = ( new Worksheet_Document_Canonicalizer( $canonical, eval_s02_generator( 1000 ) ) )->canonicalize(
	array(
		'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
		'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
		'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
		'document_id'    => eval_s02_id(),
		'locale'         => 'ko-KR',
		'title'          => 'Large worksheet',
		'nodes'          => $large_nodes,
		'settings'       => array(
			'student_navigation' => 'document',
			'print_profile'      => 'worksheet_a4@1',
		),
	)
);
$large_projection = ( new Worksheet_Revision_Projector( $canonical, eval_s02_generator( 2000 ) ) )->project( $large_source, array(), array() );
$too_large        = eval_s02_fails(
	static fn () => $builder->build( eval_s02_id(), eval_s02_id(), $large_source, $large_projection, array(), $configuration ),
	Worksheet_Evaluation_Input_Failure::INPUT_TOO_LARGE,
	'$',
	'P04 input above 900 KiB must fail before queue or Gateway execution.'
);
eval_s02_assert( ( $too_large->safe_context()['actual_bytes'] ?? 0 ) > Worksheet_Evaluation_Input_Builder::MAXIMUM_INPUT_BYTES, 'Oversize diagnostics must expose only safe byte counts.' );
eval_s02_same( Worksheet_Evaluation_Input_Builder::MAXIMUM_INPUT_BYTES, $too_large->safe_context()['maximum_bytes'] ?? null, 'Oversize diagnostics must retain the local hard limit.' );

fwrite( STDOUT, "G2-IMP-05-S02 deterministic worksheet evaluation input contract passed.\n" );

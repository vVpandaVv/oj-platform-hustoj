<?php
/**
 * G4-IMP-01-S02 official submission score input projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G4-IMP-01-S02 official submission score input projection contract.
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

require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s02 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Submission_Score_Input_Failure $failure ) {
		g4_s02_same( $reason, $failure->reason(), $message );
		g4_s02_assert( ! str_contains( $failure->getMessage(), 'student@example.test' ), 'Safe failure message leaked fixture identity.' );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G4 s02 id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function g4_s02_id( int $suffix ): string {
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s02_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s02 profile.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $schema Schema.
 * @param string              $version Version.
 * @param string              $projector Projector.
 * @param string              $source_hash Source hash.
 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
 * @return array<string,mixed>
 */
function g4_s02_profile( Canonical_Json $canonical, string $schema, string $version, string $projector, string $source_hash, array $payload ): array {
	return array(
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		'projector_version' => $projector,
		'schema_id'         => $schema,
		'schema_version'    => $version,
		'source_hash'       => $source_hash,
	);
}

/**
 * G4 s02 rehash publication.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> &$publication Publication.
 * @return void
 */
function g4_s02_rehash_publication( Canonical_Json $canonical, array &$publication ): void {
	$publication['grading_schema']['payload_hash'] = hash( 'sha256', $canonical->encode( $publication['grading_schema']['payload'] ) );
	foreach ( array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) as $field ) {
		$publication['manifest']['profiles']['grading_schema'][ $field ] = $publication['grading_schema'][ $field ];
	}
	$publication['publication_hash'] = hash( 'sha256', $canonical->encode( $publication['manifest'] ) );
}

/**
 * G4 s02 rebind official.
 *
 * @param Canonical_Json                $canonical Canonical JSON encoder used for stable digests.
 * @param Submission_Revision_Integrity $integrity Integrity.
 * @param string                        $submission_id Submission public identifier.
 * @param string                        $publication_hash Publication hash.
 * @param array<string,mixed>           &$revision Revision.
 * @return void
 */
function g4_s02_rebind_official(
	Canonical_Json $canonical,
	Submission_Revision_Integrity $integrity,
	string $submission_id,
	string $publication_hash,
	array &$revision
): void {
	$revision['response']['publication_hash'] = $publication_hash;
	$response_hash                            = hash( 'sha256', $canonical->encode( $revision['response'] ) );
	$revision['content_hash']                 = $integrity->content_hash(
		$submission_id,
		$revision['revision_no'],
		$revision['base_revision_no'],
		$revision['state'],
		$revision['response_schema_version'],
		$response_hash,
		$revision['attachment_set_hash']
	);
}

/**
 * G4 s02 artifact.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $attachment_id Attachment id.
 * @param string              $response_id Response id.
 * @param string              $kind Kind.
 * @param string              $role Role.
 * @param string              $source_hash Source hash.
 * @param array<string,mixed> $manifest Manifest.
 * @param string|null         $text Text.
 * @param array               $anchors Anchors.
 * @return array<string,mixed>
 */
function g4_s02_artifact(
	Canonical_Json $canonical,
	string $attachment_id,
	string $response_id,
	string $kind,
	string $role,
	string $source_hash,
	array $manifest,
	?string $text,
	array $anchors
): array {
	return array(
		'anchors'             => $anchors,
		'attachment_id'       => $attachment_id,
		'kind'                => $kind,
		'representation'      => $manifest,
		'representation_hash' => hash( 'sha256', $canonical->encode( $manifest ) ),
		'response_id'         => $response_id,
		'role'                => $role,
		'source_hash'         => $source_hash,
		'text'                => $text,
	);
}

$canonical = new Canonical_Json();
$integrity = new Submission_Revision_Integrity( $canonical );
$validator = new Submission_Response_Validator( $canonical );
$builder   = new Submission_Score_Input_Builder( $canonical, $integrity, $validator );

$submission_id           = g4_s02_id( 1 );
$revision_id             = g4_s02_id( 2 );
$publication_id          = g4_s02_id( 3 );
$response_ids            = array_map( 'g4_s02_id', range( 10, 19 ) );
$option_ids              = array_map( 'g4_s02_id', range( 20, 23 ) );
$row_id                  = g4_s02_id( 24 );
$column_text             = g4_s02_id( 25 );
$column_check            = g4_s02_id( 26 );
$attachment_ids          = array_map( 'g4_s02_id', range( 30, 33 ) );
$derived_file_ids        = array_map( 'g4_s02_id', range( 40, 42 ) );
$policy_id               = g4_s02_id( 50 );
$rubric_id               = g4_s02_id( 51 );
$review_id               = g4_s02_id( 52 );
$response_criterion_id   = g4_s02_id( 53 );
$submission_criterion_id = g4_s02_id( 54 );

$items = array(
	array(
		'response_id' => $response_ids[0],
		'kind'        => 'short_text',
		'label'       => 'Short',
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
		'label'       => 'Long',
		'required'    => false,
		'position'    => 1,
		'constraints' => array( 'max_chars' => 2000 ),
	),
	array(
		'response_id' => $response_ids[2],
		'kind'        => 'single_choice',
		'label'       => 'One',
		'required'    => false,
		'position'    => 2,
		'constraints' => array(
			'options' => array(
				array(
					'option_id' => $option_ids[0],
					'value'     => 'a',
					'label'     => 'Alpha',
				),
				array(
					'option_id' => $option_ids[1],
					'value'     => 'b',
					'label'     => 'Beta',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[3],
		'kind'        => 'multiple_choice',
		'label'       => 'Many',
		'required'    => false,
		'position'    => 3,
		'constraints' => array(
			'options'      => array(
				array(
					'option_id' => $option_ids[2],
					'value'     => 'c',
					'label'     => 'Gamma',
				),
				array(
					'option_id' => $option_ids[3],
					'value'     => 'd',
					'label'     => 'Delta',
				),
			),
			'min_selected' => 0,
			'max_selected' => 2,
		),
	),
	array(
		'response_id' => $response_ids[4],
		'kind'        => 'checkbox',
		'label'       => 'Check',
		'required'    => false,
		'position'    => 4,
		'constraints' => array(),
	),
	array(
		'response_id' => $response_ids[5],
		'kind'        => 'number',
		'label'       => 'Number',
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
		'label'       => 'Table',
		'required'    => false,
		'position'    => 6,
		'constraints' => array(
			'row_mode' => 'fixed',
			'rows'     => array(
				array(
					'row_id' => $row_id,
					'label'  => 'Row one',
				),
			),
			'columns'  => array(
				array(
					'column_id' => $column_text,
					'label'     => 'Text',
					'cell_type' => 'short_text',
				),
				array(
					'column_id' => $column_check,
					'label'     => 'Done',
					'cell_type' => 'checkbox',
				),
			),
		),
	),
	array(
		'response_id' => $response_ids[7],
		'kind'        => 'image_upload',
		'label'       => 'Image',
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
		'label'       => 'File',
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
		'label'       => 'Drawing',
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

$worksheet_source_hash = hash( 'sha256', 'g4-s02-worksheet-source' );
$student_profile       = g4_s02_profile(
	$canonical,
	Worksheet_Preview_Projector::STUDENT_DOCUMENT_SCHEMA,
	Worksheet_Preview_Projector::STUDENT_DOCUMENT_VERSION,
	Worksheet_Preview_Projector::PROJECTOR_VERSION,
	$worksheet_source_hash,
	array(
		'locale' => 'ko-KR',
		'title'  => 'Official input fixture',
		'nodes'  => array(
			array(
				'type'     => 'p',
				'children' => array( array( 'text' => 'Explain the difference between the two samples using the table.' ) ),
			),
			array(
				'type'     => 'table',
				'children' => array(
					array(
						'type'     => 'tr',
						'children' => array(
							array(
								'type'     => 'td',
								'children' => array( array( 'text' => 'Sample A: 42' ) ),
							),
						),
					),
				),
			),
			array(
				'type'     => 'equation',
				'tex'      => 'x+y=42',
				'children' => array( array( 'text' => '' ) ),
			),
			array(
				'type'     => 'img',
				'asset_id' => 'PRIVATE-ASSET-IDENTITY',
				'alt'      => 'Two samples under equal light.',
				'url'      => 'https://private.invalid/image',
				'children' => array( array( 'text' => '' ) ),
			),
			array(
				'type'         => 'oj_ai_response',
				'response_id'  => $response_ids[1],
				'label'        => 'Explanation',
				'teacher_note' => 'PRIVATE-TEACHER-NOTE',
				'children'     => array( array( 'text' => '' ) ),
			),
		),
	)
);
$response_profile      = g4_s02_profile(
	$canonical,
	Worksheet_Preview_Projector::RESPONSE_SCHEMA,
	Worksheet_Preview_Projector::RESPONSE_VERSION,
	Worksheet_Preview_Projector::PROJECTOR_VERSION,
	$worksheet_source_hash,
	array( 'items' => $items )
);

$policy_value     = array(
	'assessment_enabled'      => true,
	'review_rule_revision_id' => $review_id,
	'rubric_revision_id'      => $rubric_id,
);
$rubric_value     = array(
	'criteria' => array(
		array(
			'criterion_id'   => $response_criterion_id,
			'label'          => 'Reasoning',
			'description'    => 'Assess whether the response explains its claim with specific evidence.',
			'maximum_points' => '7',
			'position'       => 0,
			'scope'          => 'response',
		),
		array(
			'criterion_id'   => $submission_criterion_id,
			'label'          => 'Completeness',
			'description'    => 'Assess whether every required response is present.',
			'maximum_points' => '3',
			'position'       => 1,
			'scope'          => 'submission',
		),
	),
);
$review_value     = array(
	'abstain_handling' => 'require_review',
	'safety_handling'  => 'require_explicit_review',
);
$policy_hash      = hash( 'sha256', $canonical->encode( $policy_value ) );
$rubric_hash      = hash( 'sha256', $canonical->encode( $rubric_value ) );
$review_hash      = hash( 'sha256', $canonical->encode( $review_value ) );
$binding_key      = static function ( string $scope, ?string $response_id, string $criterion_id ) use ( $canonical, $worksheet_source_hash, $rubric_hash ): string {
	return hash(
		'sha256',
		$canonical->encode(
			array(
				'criterion_id'         => $criterion_id,
				'response_id'          => $response_id,
				'rubric_hash'          => $rubric_hash,
				'scope'                => $scope,
				'source_revision_hash' => $worksheet_source_hash,
			)
		)
	);
};
$grading_payload  = array(
	'bindings'    => array(
		array(
			'binding_key'    => $binding_key( 'response', $response_ids[1], $response_criterion_id ),
			'criterion_id'   => $response_criterion_id,
			'label'          => 'Reasoning',
			'maximum_points' => '7',
			'position'       => 0,
			'response_id'    => $response_ids[1],
			'scope'          => 'response',
			'example_answer' => 'A reasoned explanation.',
		),
		array(
			'binding_key'    => $binding_key( 'submission', null, $submission_criterion_id ),
			'criterion_id'   => $submission_criterion_id,
			'label'          => 'Completeness',
			'maximum_points' => '3',
			'position'       => 1,
			'response_id'    => null,
			'scope'          => 'submission',
		),
	),
	'policy'      => array(
		'content_hash' => $policy_hash,
		'revision_id'  => $policy_id,
	),
	'review_rule' => array(
		'content_hash'              => $review_hash,
		'explicit_release_required' => true,
		'review_required'           => true,
		'revision_id'               => $review_id,
	),
	'rubric'      => array(
		'content_hash' => $rubric_hash,
		'revision_id'  => $rubric_id,
		'total_points' => '10',
	),
);
$grading_profile  = g4_s02_profile(
	$canonical,
	Worksheet_Publication_Projector::GRADING_SCHEMA,
	Worksheet_Publication_Projector::GRADING_VERSION,
	Worksheet_Publication_Projector::GRADING_PROJECTOR_VERSION,
	$worksheet_source_hash,
	$grading_payload
);
$print_descriptor = array(
	'payload_hash'      => hash( 'sha256', 'g4-s02-print-payload' ),
	'projector_version' => Worksheet_Preview_Projector::PROJECTOR_VERSION,
	'schema_id'         => Worksheet_Preview_Projector::PRINT_SCHEMA,
	'schema_version'    => Worksheet_Preview_Projector::PRINT_VERSION,
);
$manifest         = array(
	'manifest_version'     => Worksheet_Publication_Projector::MANIFEST_VERSION,
	'profiles'             => array(
		'grading_schema'   => array_intersect_key( $grading_profile, array_flip( array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) ) ),
		'print_projection' => $print_descriptor,
		'response_schema'  => array_intersect_key( $response_profile, array_flip( array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) ) ),
		'student_document' => array_intersect_key( $student_profile, array_flip( array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) ) ),
	),
	'source_revision_hash' => $worksheet_source_hash,
);
$publication      = array(
	'grading_schema'   => $grading_profile,
	'id'               => $publication_id,
	'manifest'         => $manifest,
	'manifest_version' => Worksheet_Publication_Projector::MANIFEST_VERSION,
	'publication_hash' => hash( 'sha256', $canonical->encode( $manifest ) ),
	'response_schema'  => $response_profile,
	'student_document' => $student_profile,
);
$configuration    = array(
	'policy'      => array(
		'content_hash' => $policy_hash,
		'revision_id'  => $policy_id,
		'state'        => 'active',
		'subject_type' => 'student_submission',
		'value'        => $policy_value,
	),
	'rubric'      => array(
		'content_hash' => $rubric_hash,
		'revision_id'  => $rubric_id,
		'state'        => 'active',
		'subject_type' => 'student_submission',
		'total_points' => '10',
		'value'        => $rubric_value,
	),
	'review_rule' => array(
		'confidence_threshold'      => '0.8',
		'content_hash'              => $review_hash,
		'explicit_release_required' => true,
		'review_required'           => true,
		'revision_id'               => $review_id,
		'state'                     => 'active',
		'subject_type'              => 'student_submission',
		'value'                     => $review_value,
	),
);

$answers        = array(
	$response_ids[0] => 'hello',
	$response_ids[1] => "line one\nline two",
	$response_ids[2] => $option_ids[0],
	$response_ids[3] => array( $option_ids[2], $option_ids[3] ),
	$response_ids[4] => true,
	$response_ids[5] => '2',
	$response_ids[6] => array(
		$row_id => array(
			$column_text  => 'cell',
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
$response            = array(
	'answers'                 => $answers,
	'publication_hash'        => $publication['publication_hash'],
	'publication_id'          => $publication_id,
	'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
);
$response_hash       = hash( 'sha256', $canonical->encode( $response ) );
$revision_hash       = $integrity->content_hash( $submission_id, 1, null, 'saved', Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION, $response_hash, $attachment_set_hash );
$official_revision   = array(
	'attachment_set_hash'     => $attachment_set_hash,
	'base_revision_no'        => null,
	'content_hash'            => $revision_hash,
	'id'                      => $revision_id,
	'response'                => $response,
	'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
	'revision_no'             => 1,
	'state'                   => 'saved',
);

$artifact_specs = array(
	array( $attachment_ids[0], $response_ids[7], 'image_upload', 'file', 'Recognized diagram text.', 'page:1', $derived_file_ids[0] ),
	array( $attachment_ids[1], $response_ids[8], 'file_upload', 'file', 'The learner identity was [REDACTED].', 'page:2', $derived_file_ids[1] ),
	array( $attachment_ids[2], $response_ids[9], 'drawing', 'document', 'Drawing labels and notes.', 'drawing:1', $derived_file_ids[2] ),
);
$artifacts      = array();
foreach ( $artifact_specs as $spec ) {
	list( $attachment_id, $response_id, $kind, $role, $text, $anchor, $derived_file_id ) = $spec;
	$source_hash    = hash( 'sha256', 'source-' . $attachment_id );
	$representation = array(
		'derived_byte_size'  => strlen( $text ),
		'derived_file_id'    => $derived_file_id,
		'derived_sha256'     => hash( 'sha256', $text ),
		'extractor_contract' => 'safe_text@1.0.0',
		'reason_code'        => null,
		'redaction_version'  => Submission_Score_Input_Builder::REDACTION_VERSION,
		'schema_id'          => Submission_Score_Input_Builder::REPRESENTATION_SCHEMA,
		'schema_version'     => Submission_Score_Input_Builder::REPRESENTATION_VERSION,
		'source_hash'        => $source_hash,
		'state'              => 'ready',
	);
	$artifacts[]    = g4_s02_artifact(
		$canonical,
		$attachment_id,
		$response_id,
		$kind,
		$role,
		$source_hash,
		$representation,
		$text,
		array(
			array(
				'anchor' => $anchor,
				'quote'  => $text,
			),
		)
	);
}
$unavailable_source_hash = hash( 'sha256', 'source-' . $attachment_ids[3] );
$unavailable             = array(
	'derived_byte_size'  => 0,
	'derived_file_id'    => null,
	'derived_sha256'     => null,
	'extractor_contract' => 'safe_text@1.0.0',
	'reason_code'        => 'no_text_representation',
	'redaction_version'  => Submission_Score_Input_Builder::REDACTION_VERSION,
	'schema_id'          => Submission_Score_Input_Builder::REPRESENTATION_SCHEMA,
	'schema_version'     => Submission_Score_Input_Builder::REPRESENTATION_VERSION,
	'source_hash'        => $unavailable_source_hash,
	'state'              => 'unavailable',
);
$artifacts[]             = g4_s02_artifact( $canonical, $attachment_ids[3], $response_ids[9], 'drawing', 'preview', $unavailable_source_hash, $unavailable, null, array() );

$evaluation_ref = 'EV_' . str_repeat( 'A', 32 );
$input          = $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, array_reverse( $artifacts ) );
$gateway        = $input->gateway_input();
g4_s02_same(
	array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ),
	array_keys( $gateway ),
	'Gateway input must contain exactly the frozen outer envelope fields.'
);
g4_s02_same( Submission_Score_Input_Builder::OPERATION, $gateway['operation'], 'Score operation drifted.' );
g4_s02_same( Submission_Score_Input_Builder::OPERATION_CONTRACT, $gateway['operation_contract'], 'Inner contract drifted.' );
g4_s02_same( Submission_Score_Input_Builder::OPERATION_SCHEMA, $gateway['operation_schema'], 'Operation schema token drifted.' );
g4_s02_same( hash( 'sha256', $canonical->encode( $gateway['operation_payload'] ) ), $gateway['payload_hash'], 'Outer payload hash is not canonical.' );
g4_s02_same( hash( 'sha256', $input->gateway_input_json() ), $input->gateway_input_hash(), 'Gateway input receipt hash drifted.' );
g4_s02_assert( $input->byte_size() <= Submission_Score_Input_Builder::MAXIMUM_INPUT_BYTES, 'Gateway input exceeded the local 900KiB limit.' );
g4_s02_same( $revision_hash, $gateway['operation_payload']['subject']['source_hash'], 'Provider source must pin the official revision hash.' );
g4_s02_same( $publication['publication_hash'], $gateway['operation_payload']['subject']['publication_hash'], 'Provider source must pin the immutable publication hash.' );
g4_s02_same( $input->representation_set_hash(), $gateway['operation_payload']['subject']['representation_set_hash'], 'Representation set hash drifted.' );
g4_s02_same( Submission_Score_Input_Builder::PROMPT_CONTENT_HASH, $gateway['operation_payload']['instruction']['content_hash'], 'Task instruction hash drifted.' );
g4_s02_same( 'Alpha', $gateway['operation_payload']['responses'][2]['answer']['option']['label'], 'Single choice must include the student-visible option label.' );
g4_s02_same( '2', $gateway['operation_payload']['responses'][5]['answer']['decimal'], 'Number must remain a canonical decimal string.' );
g4_s02_same( 'Row one', $gateway['operation_payload']['responses'][6]['answer']['rows'][0]['label'], 'Table row snapshot drifted.' );
g4_s02_assert( str_starts_with( $gateway['operation_payload']['responses'][7]['answer']['artifacts'][0], 'AR_' ), 'Upload answers must use evaluation-local artifact refs.' );
g4_s02_assert( str_starts_with( $gateway['operation_payload']['responses'][9]['answer']['document_artifact'], 'AR_' ), 'Drawing document must use an evaluation-local artifact ref.' );
g4_s02_same( 'unavailable', $gateway['operation_payload']['artifacts'][3]['state'], 'Unavailable representation must remain a normal provider input state.' );
g4_s02_same( 'no_text_representation', $gateway['operation_payload']['artifacts'][3]['unavailable_reason'], 'Unavailable reason drifted.' );
g4_s02_same( 4, count( $input->artifact_references() ), 'Every official attachment needs one private provenance reference.' );
g4_s02_same( $submission_id, $input->source_snapshot()['submission_id'], 'Internal source snapshot must preserve the root needed for integrity verification.' );
g4_s02_same( Submission_Score_Input_Builder::REDACTION_VERSION, $input->redaction_version(), 'Redaction version drifted.' );
g4_s02_same( 2, count( $input->binding_manifest() ), 'Every frozen rubric criterion must have one binding.' );
g4_s02_same( array( 'B1', 'B2' ), array_column( $gateway['operation_payload']['bindings'], 'binding_ref' ), 'Provider bindings need unique short references.' );
g4_s02_same( array( 'B1', 'B2' ), $gateway['operation_payload']['output_contract']['allowed_binding_keys'], 'Output descriptor must list only this request references.' );
g4_s02_same( array_column( $input->binding_manifest(), 'binding_key' ), array_column( $gateway['operation_payload']['bindings'], 'binding_key' ), 'Canonical grading identities must remain unchanged.' );
g4_s02_assert( ! array_key_exists( 'binding_ref', $input->binding_manifest()[0] ), 'Provider references must not alter publication identities.' );

$gateway_json = $input->gateway_input_json();
foreach ( array( 'Explain the difference between the two samples using the table.', 'Sample A: 42', 'x+y=42', 'Two samples under equal light.', 'description_only' ) as $context_text ) {
	g4_s02_assert( str_contains( $gateway_json, $context_text ), 'Immutable question/material context is missing: ' . $context_text );
}
foreach ( array( 'PRIVATE-ASSET-IDENTITY', 'private.invalid', 'PRIVATE-TEACHER-NOTE' ) as $private_context ) {
	g4_s02_assert( ! str_contains( $gateway_json, $private_context ), 'Question context leaked a private field: ' . $private_context );
}
$context_responses = array_values( array_filter( $gateway['operation_payload']['worksheet_context']['blocks'], static fn ( array $block ): bool => 'oj_ai_response' === $block['type'] ) );
g4_s02_same( $response_ids[1], $context_responses[0]['response_id'], 'Question context must retain the response binding.' );
g4_s02_same( 'Assess whether the response explains its claim with specific evidence.', $gateway['operation_payload']['bindings'][0]['description'], 'Response criterion instructions must reach the provider binding.' );
g4_s02_same( 'Assess whether every required response is present.', $gateway['operation_payload']['rubric']['criteria'][1]['description'], 'Submission criterion instructions must reach the provider rubric.' );
g4_s02_assert( ! array_key_exists( 'description', $input->binding_manifest()[0] ), 'P03 binding identity must remain unchanged by provider-only descriptions.' );
foreach ( array( $submission_id, $revision_id, $attachment_ids[0], $derived_file_ids[0], 'student@example.test', 'learner_user_id', 'original_name', 'storage_key', 'private_url' ) as $private_value ) {
	g4_s02_assert( ! str_contains( $gateway_json, $private_value ), 'Gateway input leaked private identity or file provenance: ' . $private_value );
}
g4_s02_assert( str_contains( $input->artifact_references_json(), $attachment_ids[0] ), 'Private artifact references must retain the attachment ID outside Gateway input.' );
g4_s02_assert( str_contains( $input->artifact_references_json(), $derived_file_ids[0] ), 'Private artifact references must retain derived-file provenance outside Gateway input.' );
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'OJ\Platform\\' ) as $forbidden_source ) {
	$builder_source = file_get_contents( $addon_root . '/src/Submission/Assessment/Domain/Submission_Score_Input_Builder.php' );
	g4_s02_assert( is_string( $builder_source ) && ! str_contains( $builder_source, $forbidden_source ), 'S02 builder crossed an addon boundary: ' . $forbidden_source );
}

$same_input = $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, $artifacts );
g4_s02_same( $input->gateway_input_hash(), $same_input->gateway_input_hash(), 'Artifact input order must not change canonical Gateway input.' );
g4_s02_same( $input->artifact_references_hash(), $same_input->artifact_references_hash(), 'Artifact input order must not change private provenance.' );
$subset_publication = $publication;
$subset_publication['grading_schema']['payload']['bindings']               = array(
	$subset_publication['grading_schema']['payload']['bindings'][0],
);
$subset_publication['grading_schema']['payload']['rubric']['total_points'] = '7';
g4_s02_rehash_publication( $canonical, $subset_publication );
$subset_revision = $official_revision;
g4_s02_rebind_official( $canonical, $integrity, $submission_id, $subset_publication['publication_hash'], $subset_revision );
$subset_input   = $builder->build(
	$evaluation_ref,
	$submission_id,
	$revision_id,
	$subset_revision,
	$subset_publication,
	$configuration,
	$artifacts
);
$subset_payload = $subset_input->gateway_input()['operation_payload'];
g4_s02_same( 1, count( $subset_payload['bindings'] ), 'Student scoring must include only criteria selected for the worksheet.' );
g4_s02_same( 1, count( $subset_payload['rubric']['criteria'] ), 'Provider rubric must exclude unselected global criteria.' );
g4_s02_same( $response_criterion_id, $subset_payload['rubric']['criteria'][0]['criterion_id'], 'Selected criterion identity drifted.' );
g4_s02_same( '7', $subset_payload['rubric']['total_points'], 'Provider total must include only selected criteria.' );
g4_s02_same( 1, count( $subset_input->binding_manifest() ), 'Private binding manifest must match the selected criterion subset.' );

$wrong_official = g4_s02_id( 99 );
g4_s02_failure(
	static fn () => $builder->build( $evaluation_ref, $submission_id, $wrong_official, $official_revision, $publication, $configuration, $artifacts ),
	Submission_Score_Input_Failure::SUBMISSION_NOT_OFFICIAL,
	'Non-official revision pointers must fail closed.'
);

$old_publication                                        = $publication;
$old_publication['grading_schema']['projector_version'] = Worksheet_Publication_Projector::PROJECTOR_VERSION;
g4_s02_rehash_publication( $canonical, $old_publication );
g4_s02_failure(
	static fn () => $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $old_publication, $configuration, $artifacts ),
	Submission_Score_Input_Failure::GRADING_SCHEMA_UNSUPPORTED,
	'P03 generated by the pre-correction projector must require a new publication.'
);

$bad_binding_publication = $publication;
$bad_binding_publication['grading_schema']['payload']['bindings'][0]['binding_key'] = hash( 'sha256', 'legacy-binding-key' );
g4_s02_rehash_publication( $canonical, $bad_binding_publication );
$bad_binding_revision = $official_revision;
g4_s02_rebind_official( $canonical, $integrity, $submission_id, $bad_binding_publication['publication_hash'], $bad_binding_revision );
g4_s02_failure(
	static fn () => $builder->build( $evaluation_ref, $submission_id, $revision_id, $bad_binding_revision, $bad_binding_publication, $configuration, $artifacts ),
	Submission_Score_Input_Failure::GRADING_SCHEMA_INVALID,
	'P03 binding keys that omit source or rubric identity must fail closed.'
);

$pending_artifacts                               = $artifacts;
$pending_artifacts[0]['representation']['state'] = 'pending';
$pending_artifacts[0]['representation_hash']     = hash( 'sha256', $canonical->encode( $pending_artifacts[0]['representation'] ) );
g4_s02_failure(
	static fn () => $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, $pending_artifacts ),
	Submission_Score_Input_Failure::ARTIFACT_NOT_READY,
	'Pending artifact extraction must block evaluation creation.'
);

echo "G4-IMP-01-S02 passed: exact official revision, corrected P03 binding identity, 10 response kinds, private artifact separation, redaction, limits and fail-closed projection.\n";

$long_description   = str_repeat( '가', 249 ) . "\n" . str_repeat( '나', 250 );
$long_configuration = $configuration;
$long_levels        = array(
	array(
		'label'     => $long_description,
		'level_key' => g4_s02_id( 99010 ),
		'points'    => '7',
		'position'  => 0,
	),
	array(
		'label'     => 'No evidence.',
		'level_key' => g4_s02_id( 99011 ),
		'points'    => '0',
		'position'  => 1,
	),
);
$long_configuration['rubric']['value']['criteria'][0]['levels'] = $long_levels;
$long_hash                                    = hash( 'sha256', $canonical->encode( $long_configuration['rubric']['value'] ) );
$long_configuration['rubric']['content_hash'] = $long_hash;
$long_publication                             = $publication;
$long_publication['grading_schema']['payload']['rubric']['content_hash'] = $long_hash;
$long_publication['grading_schema']['payload']['bindings'][0]['levels']  = $long_levels;
foreach ( $long_publication['grading_schema']['payload']['bindings'] as &$long_binding ) {
	$long_binding['binding_key'] = hash(
		'sha256',
		$canonical->encode(
			array(
				'criterion_id'         => $long_binding['criterion_id'],
				'response_id'          => $long_binding['response_id'],
				'rubric_hash'          => $long_hash,
				'scope'                => $long_binding['scope'],
				'source_revision_hash' => $worksheet_source_hash,
			)
		)
	);
}
unset( $long_binding );
g4_s02_rehash_publication( $canonical, $long_publication );
$long_revision = $official_revision;
g4_s02_rebind_official( $canonical, $integrity, $submission_id, $long_publication['publication_hash'], $long_revision );
$long_input = $builder->build( $evaluation_ref, $submission_id, $revision_id, $long_revision, $long_publication, $long_configuration, $artifacts );
g4_s02_same( $long_description, $long_input->gateway_input()['operation_payload']['rubric']['criteria'][0]['levels'][0]['label'], 'Student AI rubric truncated a long performance description.' );
g4_s02_same( $long_levels, $long_input->gateway_input()['operation_payload']['bindings'][0]['levels'], 'Student AI binding lost multiline performance descriptions.' );
echo "Student scoring preserved 500-character multiline performance descriptions and immutable bindings.\n";

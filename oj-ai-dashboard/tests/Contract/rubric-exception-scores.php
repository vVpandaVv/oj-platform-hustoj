<?php
/**
 * Optional rubric score configuration and official AI input compatibility.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Assessment\Configuration\Domain\Rubric_Exception_Scores;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;

require __DIR__ . '/submission-assessment-input-g4-imp-01-s02.php';

g4_s02_same(
	array(
		'not_submitted' => '0',
		'long_absence'  => '2.5',
	),
	Rubric_Exception_Scores::normalize(
		array(
			'not_submitted' => '0.0000',
			'long_absence'  => '2.5000',
		),
		'10'
	),
	'Canonical optional scores changed.'
);
foreach ( array(
	array(),
	array(
		'not_submitted' => -1,
		'long_absence'  => null,
	),
	array(
		'not_submitted' => '11',
		'long_absence'  => null,
	),
	array(
		'not_submitted' => '0',
		'long_absence'  => '-1',
	),
	array(
		'not_submitted' => '0',
		'long_absence'  => '1e1',
	),
) as $invalid ) {
	$rejected = false;
	try {
		Rubric_Exception_Scores::normalize( $invalid, '10' );
	} catch ( InvalidArgumentException ) {
		$rejected = true; }
	g4_s02_assert( $rejected, 'Invalid exception configuration was accepted.' );
}
$config_profile = new Assessment_Configuration_Profile( $canonical );
$shared_value   = array(
	'criteria' => array(
		array(
			'criterion_id'   => g4_s02_id( 99020 ),
			'label'          => 'Criterion',
			'description'    => 'Explain evidence',
			'scope'          => 'submission',
			'position'       => 0,
			'maximum_points' => '10',
			'levels'         => array(
				array(
					'level_key' => g4_s02_id( 99021 ),
					'label'     => 'Complete',
					'points'    => '10',
					'position'  => 0,
				),
				array(
					'level_key' => g4_s02_id( 99022 ),
					'label'     => 'Partial',
					'points'    => '2',
					'position'  => 1,
				),
			),
		),
	),
);
$prepared       = $config_profile->prepare(
	'rubric',
	array(
		'subject_type' => 'student_submission',
		'total_points' => '10',
		'value'        => $shared_value,
	)
);
g4_s02_assert( ! array_key_exists( 'exception_scores', $prepared['value'] ), 'Legacy shared rubric hash changed.' );
$shared_value['exception_scores'] = array(
	'not_submitted' => '0',
	'long_absence'  => null,
);
$prepared                         = $config_profile->prepare(
	'rubric',
	array(
		'subject_type' => 'student_submission',
		'total_points' => '10',
		'value'        => $shared_value,
	)
);
g4_s02_same( $canonical->encode( $shared_value ), $canonical->encode( $prepared['value'] ), 'Shared rubric lost optional scores.' );

$configuration['rubric']['value']['exception_scores'] = array(
	'not_submitted' => '0',
	'long_absence'  => '2',
);
$new_hash                                = hash( 'sha256', $canonical->encode( $configuration['rubric']['value'] ) );
$configuration['rubric']['content_hash'] = $new_hash;
$publication['grading_schema']['payload']['rubric']['content_hash']     = $new_hash;
$publication['grading_schema']['payload']['rubric']['exception_scores'] = $configuration['rubric']['value']['exception_scores'];
foreach ( $publication['grading_schema']['payload']['bindings'] as &$binding ) {
	$binding['binding_key'] = hash(
		'sha256',
		$canonical->encode(
			array(
				'criterion_id'         => $binding['criterion_id'],
				'response_id'          => $binding['response_id'],
				'rubric_hash'          => $new_hash,
				'scope'                => $binding['scope'],
				'source_revision_hash' => $worksheet_source_hash,
			)
		)
	);
}
unset( $binding );
g4_s02_rehash_publication( $canonical, $publication );
g4_s02_rebind_official( $canonical, $integrity, $submission_id, $publication['publication_hash'], $official_revision );
$with_exceptions = $builder->build( $evaluation_ref, $submission_id, $revision_id, $official_revision, $publication, $configuration, $artifacts );
g4_s02_assert( ! str_contains( $canonical->encode( $with_exceptions->gateway_input() ), 'exception_scores' ), 'Exception scores were sent to AI.' );
g4_s02_same( $configuration['rubric']['value']['exception_scores'], $with_exceptions->configuration_snapshot()['rubric']['value']['exception_scores'], 'Pinned teacher metadata was lost.' );
echo "PASS optional rubric exception scores: bounds, legacy hashes, shared settings and official AI input.\n";

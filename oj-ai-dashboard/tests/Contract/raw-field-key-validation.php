<?php
/**
 * Numeric keys and unexpected fields must remain rejected by raw-input validators.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once ABSPATH . 'src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( ABSPATH . 'src' ) )->register();

$validators = array(
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Application\\Enqueue_Assignment_Evaluations', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Application\\Enqueue_Assignment_Evaluations_Result', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Domain\\Submission_Evaluation_Output_Normalizer', 'assert_exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Domain\\Submission_Score_Input_Builder', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Infrastructure\\WordPress_Assignment_Evaluation_Batch_Receipt', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assessment\\Infrastructure\\WordPress_Assignment_Evaluation_Batch_Source', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assignment\\Application\\Change_Assignment_Command', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assignment\\Application\\Create_Assignment_Command', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Assignment\\Infrastructure\\WordPress_Assignment_Repository', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Student\\Application\\Autosave_Own_Submission_Result', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Student\\Application\\Submit_Own_Submission_Result', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Student\\Infrastructure\\WordPress_Student_Assignment_Repository', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Student\\Infrastructure\\WordPress_Student_Attachment_Repository', 'stored_exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\Teacher\\Application\\Control_Teacher_Submission_Result', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Submission\\ZipImport\\Infrastructure\\WordPress_Submission_Zip_Repository', 'exact_keys' ),
	array( 'OJAI\\Dashboard\\Worksheet\\Evaluation\\Domain\\Worksheet_Evaluation_Input_Builder', 'assert_exact_keys' ),
	array( 'OJAI\\Dashboard\\Worksheet\\Evaluation\\Domain\\Worksheet_Evaluation_Output_Normalizer', 'assert_exact_keys' ),
);
$assertions = 0;
set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new ErrorException( $message, 0, $severity );
	}
);
try {
	foreach ( $validators as [ $class_name, $method_name ] ) {
		$reflection = new ReflectionClass( $class_name );
		$instance   = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( $method_name );
		foreach ( array(
			array( array( 'value' => array( 'nested' ) ), true ),
			array( array(), false ),
			array( array( 'list value' ), false ),
			array( array( 3 => 'numeric key' ), false ),
			array( array( 'unexpected' => 'value' ), false ),
			array(
				array(
					'value' => 1,
					'extra' => 2,
				),
				false,
			),
			array(
				array(
					'value' => 1,
					3       => 'mixed keys',
				),
				false,
			),
		) as [ $input, $valid ] ) {
			$args = array( $input, array( 'value' ) );
			if ( 3 === $method->getNumberOfParameters() ) {
				$args[] = '$.fixture';
			}
			$accepted = true;
			try {
				$method->invokeArgs( $instance, $args );
			} catch ( Throwable $failure ) {
				if ( $failure instanceof Error || $failure instanceof ErrorException ) {
					throw $failure;
				}
				$accepted = false;
			}
			if ( $accepted !== $valid ) {
				throw new RuntimeException( 'Field validation changed: ' . $class_name . '::' . $method_name );
			}
			++$assertions;
		}
	}
} finally {
	restore_error_handler();
}
if ( 119 !== $assertions ) {
	throw new RuntimeException( 'Field validation regression coverage is incomplete.' );
}
$roster  = static fn ( array $learners ): OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot => new OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot(
	'group',
	'group:10',
	'revision-1',
	'roster-1',
	$learners
);
$learner = array(
	'learner_user_id'     => 1,
	'membership_revision' => 'membership-1',
);
if ( 0 !== ( $roster( array() )->count() ) || $roster( array( $learner ) )->learners() !== array( $learner ) ) {
	throw new RuntimeException( 'Valid roster projection changed.' );
}
$invalid_rosters = array(
	array( null ),
	array( false ),
	array( 1 ),
	array( 'invalid' ),
	array( new stdClass() ),
	array( array() ),
	array( array( 'list item' ) ),
	array( array_replace( $learner, array( 'learner_user_id' => '1' ) ) ),
	array( array_replace( $learner, array( 'learner_user_id' => 0 ) ) ),
	array( array_replace( $learner, array( 'membership_revision' => array() ) ) ),
	array( array_replace( $learner, array( 'membership_revision' => '' ) ) ),
	array( array_replace( $learner, array( 'unexpected' => true ) ) ),
	array( $learner, $learner ),
	array( 'sparse' => $learner ),
);
foreach ( $invalid_rosters as $invalid_roster ) {
	try {
		$roster( $invalid_roster );
		throw new RuntimeException( 'Malformed roster was accepted.' );
	} catch ( InvalidArgumentException ) {
		continue;
	}
}
echo "Raw field validators PASS: 17 validators, 119 field checks, 14 malformed rosters.\n";

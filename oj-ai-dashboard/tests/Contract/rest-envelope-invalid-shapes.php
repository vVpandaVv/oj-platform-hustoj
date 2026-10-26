<?php
/**
 * Regression: invalid envelope fields fail validation without PHP warnings.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/rest-foundation.php';

$ojai_factory         = new \OJAI\Dashboard\Rest\Rest_Envelope_Factory();
$ojai_invalid_actions = array( array( 'x', array() ), array( 'x', new stdClass() ), array( null ), array( 1 ), array( 'x', 'x' ), array( 'named' => 'x' ) );
$ojai_rejected        = 0;
set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new ErrorException( $message, 0, $severity );
	}
);
try {
	foreach ( $ojai_invalid_actions as $ojai_actions ) {
		try {
			$ojai_factory->success( array(), 'flow-quality-envelope-1', 200, array(), array(), $ojai_actions );
			throw new RuntimeException( 'Invalid action shape was accepted.' );
		} catch ( InvalidArgumentException ) {
			++$ojai_rejected;
		}
	}
	foreach ( array( array( 'self' => array() ), array( 0 => 'https://example.test/' ), array( 'self' => "https://example.test/\r\nheader" ) ) as $ojai_links ) {
		try {
			$ojai_factory->success( array(), 'flow-quality-envelope-1', 200, array(), $ojai_links );
			throw new RuntimeException( 'Invalid link shape was accepted.' );
		} catch ( InvalidArgumentException ) {
			++$ojai_rejected;
		}
	}
	$ojai_problem = \OJAI\Dashboard\Rest\Rest_Problem::for_status( 422, 'flow-quality-envelope-1' );
	foreach ( array( array(), array( 'x' ), array( 'status' => '200' ), array( 'retry' => array() ), array( 'retry' => "x\ny" ), array( 'retry' => str_repeat( 'x', 129 ) ) ) as $ojai_safe_data ) {
		try {
			$ojai_factory->problem_with_safe_data( $ojai_problem, $ojai_safe_data );
			throw new RuntimeException( 'Invalid recovery fields were accepted.' );
		} catch ( InvalidArgumentException ) {
			++$ojai_rejected;
		}
	}
} finally {
	restore_error_handler();
}
if ( 15 !== $ojai_rejected ) {
	throw new RuntimeException( 'Not all malformed envelope fields were rejected.' );
}
echo "REST envelope invalid shapes: 15 checks passed without warnings.\n";

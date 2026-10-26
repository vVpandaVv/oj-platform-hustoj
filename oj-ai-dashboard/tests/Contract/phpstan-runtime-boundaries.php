<?php
/**
 * Static-analysis cleanup must preserve rejection of malformed runtime inputs.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Migration_Submission_Response_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Queue_Wakeup;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;
use OJAI\Dashboard\Operations\Purge\Infrastructure\WordPress_Purge_Queue_Wakeup;
use OJAI\Dashboard\Reporting\Report\Presentation\Report_Filter_Query;
use OJAI\Dashboard\Storage\Infrastructure\Worksheet_Asset_Readiness_Checker;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Presentation\Worksheet_Asset_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once ABSPATH . 'src/Core/Autoloader.php';
( new OJAI\Dashboard\Core\Autoloader( ABSPATH . 'src' ) )->register();

$assertions = 0;
$same       = static function ( mixed $expected, mixed $actual ): void {
	global $assertions;
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Runtime boundary result changed.' );
	}
	++$assertions;
};
$reject     = static function ( callable $operation, string $exception = InvalidArgumentException::class ) use ( $same ): void {
	try {
		$operation();
	} catch ( Throwable $failure ) {
		// PHP warnings and TypeErrors are failures, not successful input rejection.
		if ( $failure instanceof Error || $failure instanceof ErrorException ) {
			throw $failure;
		}
		$same( true, $failure instanceof $exception );
		return;
	}
	throw new RuntimeException( 'Invalid runtime input was accepted.' );
};
$invoke     = static function ( string $class, string $method, array $arguments ): mixed {
	$reflection = new ReflectionClass( $class );
	return $reflection->getMethod( $method )->invokeArgs( $reflection->newInstanceWithoutConstructor(), $arguments );
};

// Unfiltered types are intentional: WordPress filters can replace their values.
/**
 * Wp timezone string.
 *
 * @return mixed
 */
function wp_timezone_string() {
	return $GLOBALS['boundary_timezone'];
}
/**
 * Rest url.
 *
 * @param mixed $path Path.
 * @param mixed $scheme Scheme.
 * @return mixed
 */
function rest_url( $path = '', $scheme = 'rest' ) {
	return $GLOBALS['boundary_rest_url'];
}
/**
 * Wp next scheduled.
 *
 * @param mixed $hook Hook.
 * @param mixed $args Args.
 * @return mixed
 */
function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}
/**
 * Wp schedule single event.
 *
 * @param mixed $timestamp Timestamp.
 * @param mixed $hook Hook.
 * @param mixed $args Args.
 * @param mixed $wp_error Wp error.
 * @return mixed
 */
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	$GLOBALS['boundary_scheduled'][] = array( $hook, $args, $wp_error );
	return $GLOBALS['boundary_schedule_result'];
}
/**
 * Static-analysis cleanup must preserve rejection of malformed runtime inputs.
 */
class WP_Error {
}

set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new ErrorException( $message, 0, $severity );
	}
);
try {
	$id       = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
	$other_id = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
	$counts   = array( 'worksheets' => 0 );
	$same( 0, ( new Purge_Inventory_Snapshot( $counts, 0, 0, 0, $counts ) )->total_row_count() );
	foreach ( array(
		array(),
		array( 0 ),
		array( 3 => 0 ),
		array(
			'worksheets' => 0,
			2            => 0,
		),
		array( 'worksheets' => '0' ),
		array( 'worksheets' => -1 ),
		array( 'worksheets' => null ),
		array( 'worksheets' => array() ),
		array( 'worksheets' => 1.5 ),
	) as $bad ) {
		$reject( static fn () => new Purge_Inventory_Snapshot( $bad, 0, 0, 0, $counts ) );
		$reject( static fn () => new Purge_Inventory_Snapshot( $counts, 0, 0, 0, $bad ) );
	}

	$items   = array( $id => array( 'kind' => 'long_text' ) );
	$binding = array(
		'response_id' => $id,
		'kind'        => 'long_text',
		'question'    => array( 'prompt' => 'Question' ),
	);
	$same( null, $invoke( Legacy_Migration_Submission_Response_Projector::class, 'assert_bindings', array( $items, array( 'legacy-1' => $binding ) ) ) );
	foreach ( array( null, false, 42, 'invalid', new stdClass(), array(), array( 'list' ) ) as $bad ) {
		$reject( static fn () => $invoke( Legacy_Migration_Submission_Response_Projector::class, 'assert_bindings', array( $items, array( 'legacy-1' => $bad ) ) ) );
	}
	$reject( static fn () => $invoke( Legacy_Migration_Submission_Response_Projector::class, 'assert_bindings', array( $items, array( 4 => $binding ) ) ) );

	$assets  = new class() implements Worksheet_Asset_Lifecycle_Port_Interface {
		/**
		 * Reads.
		 *
		 * @var int
		 */
		public int $reads = 0;
		/**
		 * Find by public id.
		 *
		 * @param string $asset_id Asset id.
		 * @return array|null
		 */
		public function find_by_public_id( string $asset_id ): ?array {
			++$this->reads;
			return null;
		}
		/**
		 * Mark quarantined purged.
		 *
		 * @param int    $actor_user_id Authenticated actor from the access context.
		 * @param string $worksheet_id Worksheet public identifier.
		 * @param string $asset_id Asset id.
		 * @param string $purged_at_utc Purged at utc.
		 * @return bool
		 * @throws \Throwable Propagates validation or dependency failures.
		 */
		public function mark_quarantined_purged( int $actor_user_id, string $worksheet_id, string $asset_id, string $purged_at_utc ): bool {
			throw new RuntimeException( 'Readiness must not purge files.' );
		}
	};
	$checker = new Worksheet_Asset_Readiness_Checker( $assets );
	$same( true, $checker->inspect( 1, $id, array() )['ready'] );
	foreach ( array( array( null ), array( false ), array( 42 ), array( array() ), array( new stdClass() ), array( '' ), array( $id, $id ), array( $id, array() ) ) as $bad ) {
		$reject( static fn () => $checker->inspect( 1, $id, $bad ) );
	}
	$same( 0, $assets->reads );
	$same( 2, $checker->inspect( 1, $id, array( $id, $other_id ) )['statistics']['asset_count'] );
	$same( 2, $assets->reads );

	$builder   = new Worksheet_Evaluation_Input_Builder( new Canonical_Json() );
	$asset_ids = new ReflectionMethod( $builder, 'revision_asset_ids' );
	$same( array( $id ), $asset_ids->invoke( $builder, array( array( 'asset_id' => $id ) ) ) );
	foreach ( array( null, false, 42, 'invalid', new stdClass(), array(), array( 'asset_id' => array() ) ) as $bad ) {
		$reject( static fn () => $asset_ids->invoke( $builder, array( $bad ) ), OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Failure::class );
	}

	$validation = ( new ReflectionClass( Validate_Worksheet_Revision_Result::class ) )->newInstanceWithoutConstructor();
	$policy     = ( new ReflectionClass( Worksheet_Approval_Policy::class ) )->newInstanceWithoutConstructor();
	foreach ( array( null, false, 42, 'invalid', new stdClass(), array() ) as $bad ) {
		$reject( static fn () => $invoke( Approve_Worksheet_Revision::class, 'decide_evidence', array( $validation, array( 'policy' => $bad ) ) ) );
	}
	foreach ( array( false, 42, 'invalid', new stdClass(), array(), array( 'list' ) ) as $bad ) {
		$reject(
			static fn () => $invoke(
				Approve_Worksheet_Revision::class,
				'decide_evidence',
				array(
					$validation,
					array(
						'policy'            => $policy,
						'evaluation_detail' => $bad,
					),
				)
			)
		);
	}
	$record = array( 'asset_bindings' => array( array( 'asset_id' => $id ) ) );
	foreach ( array( null, false, 42, 'invalid', new stdClass(), array(), array( 'list' ) ) as $bad ) {
		$reject( static fn () => $invoke( WordPress_Worksheet_Repository::class, 'normalize_asset_receipts', array( $record, array( $bad ) ) ), OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure::class );
	}

	foreach ( array( null, false, 42, array(), new stdClass(), '' ) as $bad ) {
		$GLOBALS['boundary_timezone'] = $bad;
		$GLOBALS['boundary_rest_url'] = $bad;
		$same( 'UTC', $invoke( Report_Filter_Query::class, 'site_timezone', array() ) );
		$same( array(), $invoke( Worksheet_Asset_Rest_Controller::class, 'links_for', array( $id, $other_id ) ) );
	}
	$GLOBALS['boundary_timezone'] = ' Asia/Seoul ';
	$same( 'Asia/Seoul', $invoke( Report_Filter_Query::class, 'site_timezone', array() ) );
	$GLOBALS['boundary_rest_url'] = 'https://example.invalid/wp-json/oj-ai/v1';
	$same( true, count( $invoke( Worksheet_Asset_Rest_Controller::class, 'links_for', array( $id, $other_id ) ) ) > 0 );

	$GLOBALS['boundary_scheduled'] = array();
	foreach ( array( new WordPress_Legacy_Migration_Queue_Wakeup(), new WordPress_Purge_Queue_Wakeup() ) as $queue ) {
		$GLOBALS['boundary_schedule_result'] = true;
		$same( null, $queue->wake( $id ) );
		foreach ( array( false, null, 1, 'true', new WP_Error() ) as $bad ) {
			$GLOBALS['boundary_schedule_result'] = $bad;
			$reject( static fn () => $queue->wake( $id ), RuntimeException::class );
		}
	}
	foreach ( $GLOBALS['boundary_scheduled'] as $call ) {
		$same( array( $id ), $call[1] );
		$same( true, $call[2] );
	}
	$same( true, Worksheet_Document_Profile::MAX_CONTENT_TABLE_ROWS * Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS <= Worksheet_Document_Profile::MAX_CONTENT_TABLE_CELLS );
} finally {
	restore_error_handler();
}
echo 'PHPStan runtime boundaries passed: ' . (int) $assertions . " checks.\n";

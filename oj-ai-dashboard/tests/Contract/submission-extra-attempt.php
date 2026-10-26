<?php
/**
 * Individual teacher allowances do not change assignment-wide attempt limits.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Submission/Student/Domain/Submission_Attempt_Allowance.php';

use OJAI\Dashboard\Submission\Student\Domain\Submission_Attempt_Allowance;

$cases = array(
	array( 2, 0, 'draft', 2 ),
	array( 2, 1, 'reopened', 1 ),
	array( 2, 2, 'reopened', 1 ),
	array( 2, 3, 'reopened', 1 ),
	array( 10, 10, 'reopened', 1 ),
	array( 2, 2, 'submitted', 0 ),
	array( 2, 3, 'submitted', 0 ),
	array( 2, 2, 'auto_submitted', 0 ),
	array( 2, 2, 'proxy_submitted', 0 ),
	array( 2, 2, 'locked', 0 ),
	array( 2, 2, 'draft', 0 ),
	array( 2, 2, 'conflict', 0 ),
);
foreach ( $cases as list( $maximum, $used, $state, $expected ) ) {
	if ( $expected !== Submission_Attempt_Allowance::remaining( $maximum, $used, $state ) ) {
		throw new RuntimeException( 'Individual attempt allowance mismatch.' );
	}
}
echo "PASS individual attempt allowance: baseline, teacher-only pending attempt, consumption, locking and repeated grants.\n";

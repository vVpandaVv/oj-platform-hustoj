<?php
/**
 * Teacher grades independent of student submissions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Non_Submission_Grade_Repository_Port_Interface {
	/**
	 * Read target grades and frozen grading limits in an authorized assignment.
	 *
	 * @param array $scope Assignment and context scope.
	 * @return array
	 */
	public function read( array $scope ): array;

	/**
	 * Atomically record a teacher decision without creating a submission.
	 *
	 * @param array $record Validated command and server identity.
	 * @return array
	 */
	public function save( array $record ): array;

	/**
	 * Save a deployment-specific exception policy without changing existing grades.
	 *
	 * @param array $record Validated command and server identity.
	 * @return array
	 */
	public function save_policy( array $record ): array;
}

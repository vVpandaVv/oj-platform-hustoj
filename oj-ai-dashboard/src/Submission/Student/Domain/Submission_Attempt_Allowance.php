<?php
/**
 * Remaining attempts including a teacher-authorized pending resubmission.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C27 alone opens an official submission; committing or locking consumes that state.
 */
final class Submission_Attempt_Allowance {
	/**
	 * Compute remaining attempts from validated, server-owned submission values.
	 *
	 * @param int    $maximum Assignment-wide attempt limit.
	 * @param int    $used Official attempts already committed.
	 * @param string $state Persisted submission state, never a client override.
	 * @return int
	 */
	public static function remaining( int $maximum, int $used, string $state ): int {
		$teacher_allowance = 'reopened' === $state && $used > 0 ? 1 : 0;
		return max( 0, $maximum - $used, $teacher_allowance );
	}
}

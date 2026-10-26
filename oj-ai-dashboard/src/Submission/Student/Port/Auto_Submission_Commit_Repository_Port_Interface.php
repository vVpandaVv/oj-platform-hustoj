<?php
/**
 * Automatic official submission transaction boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic official submission transaction boundary.
 */
interface Auto_Submission_Commit_Repository_Port_Interface {
	public const AUTO_SUBMITTED = 'auto_submitted';
	public const STALE          = 'stale';

	/**
	 * Auto submit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	public function auto_submit( array $record ): string;
}

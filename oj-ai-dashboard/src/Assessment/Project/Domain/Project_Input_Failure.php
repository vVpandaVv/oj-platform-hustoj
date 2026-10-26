<?php
/**
 * Public validation guidance for assessment-project input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Project\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only static, user-facing validation messages belong to this exception.
 */
final class Project_Input_Failure extends \InvalidArgumentException {
}

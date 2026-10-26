<?php
/**
 * Project failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Assessment\Project\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Project failure.
 */
final class Project_Failure extends \RuntimeException {
	/**
	 * Initialize failure dependencies and state.
	 *
	 * @param int    $status Status.
	 * @param string $message Message.
	 */
	public function __construct( public readonly int $status, string $message ) {
		parent::__construct( $message );
	}
}

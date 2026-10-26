<?php
/**
 * WordPress purge site.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress purge site.
 */
final class WordPress_Purge_Site implements Purge_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function site_id(): string {
		if ( ! function_exists( 'get_current_blog_id' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::SITE_UNAVAILABLE );
		}
		$site_id = get_current_blog_id();
		if ( $site_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::SITE_UNAVAILABLE );
		}
		return (string) $site_id;
	}
}

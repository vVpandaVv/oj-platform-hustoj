<?php
/**
 * WordPress legacy migration site.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress legacy migration site.
 */
final class WordPress_Legacy_Migration_Site implements Legacy_Migration_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function site_id(): string {
		if ( ! function_exists( 'get_current_blog_id' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SITE_UNAVAILABLE );
		}
		$site_id = get_current_blog_id();
		if ( $site_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SITE_UNAVAILABLE );
		}
		return (string) $site_id;
	}
}

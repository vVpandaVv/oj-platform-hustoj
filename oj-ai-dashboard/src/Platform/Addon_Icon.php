<?php
/**
 * Shared Lucide icon renderer for add-on surfaces.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Platform;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Lucide icon renderer for add-on surfaces.
 */
final class Addon_Icon {
	private const ALLOWED = array(
		'arrow-left',
		'database',
		'download',
		'edit',
		'ellipsis',
		'eye',
		'list',
		'plus',
		'refresh-cw',
		'save',
		'search',
		'settings',
		'trash',
		'upload',
		'x',
	);

	/**
	 * Markup.
	 *
	 * @param string $name Name.
	 * @param string $class Class.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function markup( string $name, string $class = 'oj-ai-icon' ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		if ( ! in_array( $name, self::ALLOWED, true ) ) {
			throw new InvalidArgumentException( 'Unsupported add-on icon.' );
		}

		$href = self::sprite_url() . '#' . $name;

		return '<svg class="' . self::escape( $class ) . '" aria-hidden="true" focusable="false">'
			. '<use href="' . self::escape( $href ) . '"></use></svg>';
	}

	/**
	 * Sprite url.
	 *
	 * @return string
	 */
	public static function sprite_url(): string {
		$base_url = defined( 'OJ_AI_DASHBOARD_URL' ) ? (string) constant( 'OJ_AI_DASHBOARD_URL' ) : '';

		return $base_url . 'resources/icons/lucide.svg';
	}

	/**
	 * Escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

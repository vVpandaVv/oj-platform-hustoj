<?php
/**
 * Plugin activation handler.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation tasks.
 */
final class Activation {
	/**
	 * Run activation tasks.
	 *
	 * @param bool $network_wide Whether WordPress requested network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		$problem = Activation_Preflight::inspect( array( 'network_wide' => (bool) $network_wide ) );
		if ( null !== $problem ) {
			self::abort( $problem );
		}

		$problem = Installation_Manager::run( 'activation' );
		if ( null !== $problem ) {
			self::abort( $problem );
		}
	}

	/**
	 * Stop activation before any add-on schema or schedule is changed.
	 *
	 * @param array{code:string,message:string,correlation_id?:string,diagnostic_code?:string} $problem Activation problem.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function abort( array $problem ) {
		$details = $problem['code'] . ', addon:' . (string) OJ_AI_DASHBOARD_VERSION;
		if (
			isset( $problem['diagnostic_code'] )
			&& 1 === preg_match( '/^[a-z][a-z0-9_]{2,99}$/', (string) $problem['diagnostic_code'] )
		) {
			$details .= ', diagnostic:' . (string) $problem['diagnostic_code'];
		}
		if (
			isset( $problem['correlation_id'] )
			&& 1 === preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', (string) $problem['correlation_id'] )
		) {
			$details .= ', ' . (string) $problem['correlation_id'];
		}
		$message = $problem['message'] . ' (' . $details . ')';

		if ( function_exists( 'wp_die' ) ) {
			wp_die(
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native fallback uses explicit HTML quote escaping and UTF-8 substitution.
				function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
				'AI 평가 애드온을 활성화할 수 없습니다.',
				array(
					'back_link' => true,
					'response'  => 500,
				)
			);
		}

		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
	}
}

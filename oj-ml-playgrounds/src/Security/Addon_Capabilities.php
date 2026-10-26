<?php
/**
 * Canonical OJ Platform add-on capability catalog.
 *
 * @package OJML\Playgrounds
 */

namespace OJML\Playgrounds\Security;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the declared keys used by manifest pages and REST authorization.
 */
final class Addon_Capabilities {
	public const USE      = 'oj_ml_playgrounds_use';
	public const SUBMIT   = 'oj_ml_playgrounds_submit';
	public const MANAGE   = 'oj_ml_playgrounds_manage';
	public const REVIEW   = 'oj_ml_playgrounds_review';
	public const REPORT   = 'oj_ml_playgrounds_report';
	public const SETTINGS = 'oj_ml_playgrounds_settings';

	/**
	 * @return array<int,array{key:string,label:string,audience:string}>
	 */
	public static function definitions() {
		return array(
			array( 'key' => self::USE, 'label' => 'ML 실습 보기', 'audience' => 'learner' ),
			array( 'key' => self::SUBMIT, 'label' => 'ML 실습 저장·제출', 'audience' => 'learner' ),
			array( 'key' => self::MANAGE, 'label' => 'ML 실습 과제 관리', 'audience' => 'educator' ),
			array( 'key' => self::REVIEW, 'label' => 'ML 실습 제출 검토', 'audience' => 'educator' ),
			array( 'key' => self::REPORT, 'label' => 'ML 실습 결과 보기', 'audience' => 'educator' ),
			array( 'key' => self::SETTINGS, 'label' => 'ML 실습 설정', 'audience' => 'educator' ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function keys() {
		return array_column( self::definitions(), 'key' );
	}

	/**
	 * Convert a declared key with the platform's public mapper.
	 *
	 * @param string $declared_key Manifest capability key.
	 * @return string
	 */
	public static function wordpress( $declared_key ) {
		if (
			! class_exists( AddonCapabilityKeyV1::class )
			|| ! is_string( $declared_key )
			|| ! in_array( $declared_key, self::keys(), true )
		) {
			return '';
		}

		return AddonCapabilityKeyV1::wordpress( $declared_key );
	}

	/**
	 * Settings gate for the current user: site administrators, or anyone
	 * granted the SETTINGS capability through the OJ role screen.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_settings() {
		if ( ! function_exists( 'get_current_user_id' ) || get_current_user_id() < 1 ) {
			return false;
		}
		if ( function_exists( 'is_super_admin' ) && is_super_admin() ) {
			return true;
		}
		$capability = self::wordpress( self::SETTINGS );

		return '' !== $capability && function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	/**
	 * Same settings gate for an explicit actor (console page requests).
	 *
	 * @param int $user_id Actor user ID.
	 * @return bool
	 */
	public static function user_can_manage_settings( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
			return true;
		}
		$capability = self::wordpress( self::SETTINGS );

		return '' !== $capability && function_exists( 'user_can' ) && user_can( $user_id, $capability );
	}
}

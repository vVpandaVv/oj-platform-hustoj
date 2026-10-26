<?php
/**
 * Activation compatibility preflight.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Infrastructure\Local_Private_Storage_Path_Diagnostics;
use OJAI\Dashboard\Platform\Addon_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates every external prerequisite before local state can change.
 */
final class Activation_Preflight {
	private const MIN_WORDPRESS = '6.6';
	private const MIN_PHP       = '8.1';

	/** PHP extensions required by the frozen runtime and release manifest. */
	private const REQUIRED_EXTENSIONS = array( 'dom', 'fileinfo', 'intl', 'mbstring', 'mysqli', 'openssl', 'xmlwriter', 'zip' );

	/**
	 * Inspect activation prerequisites.
	 *
	 * The optional environment exists for deterministic contract tests.
	 *
	 * @param array<string,mixed> $environment Environment overrides.
	 * @return array{code:string,message:string}|null
	 */
	public static function inspect( array $environment = array() ) {
		$wp_version        = isset( $environment['wordpress_version'] )
			? (string) $environment['wordpress_version']
			: ( function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '0.0.0' );
		$php_version       = isset( $environment['php_version'] ) ? (string) $environment['php_version'] : PHP_VERSION;
		$is_multisite      = array_key_exists( 'is_multisite', $environment )
			? (bool) $environment['is_multisite']
			: ( function_exists( 'is_multisite' ) && is_multisite() );
		$network_wide      = array_key_exists( 'network_wide', $environment )
			? (bool) $environment['network_wide']
			: false;
		$platform_version  = array_key_exists( 'platform_version', $environment )
			? (string) $environment['platform_version']
			: ( defined( 'OJ_PLATFORM_VERSION' ) ? (string) OJ_PLATFORM_VERSION : '' );
		$addon_api_version = array_key_exists( 'addon_api_version', $environment )
			? (string) $environment['addon_api_version']
			: ( defined( 'OJ_PLATFORM_ADDON_API_VERSION' ) ? (string) OJ_PLATFORM_ADDON_API_VERSION : '' );
		$api_available     = array_key_exists( 'api_contract_available', $environment )
			? (bool) $environment['api_contract_available']
			: Addon_Integration::api_contract_available();

		if ( version_compare( $wp_version, self::MIN_WORDPRESS, '<' ) ) {
			return self::problem( 'addon_wordpress_incompatible', 'WordPress 6.6 이상이 필요합니다.' );
		}

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			return self::problem( 'addon_php_incompatible', 'PHP 8.1 이상이 필요합니다.' );
		}

		if ( $is_multisite || $network_wide ) {
			return self::problem( 'multisite_unsupported', '현재 버전은 단일 사이트에서만 사용할 수 있습니다.' );
		}

		$missing_extensions = array();
		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			$loaded = isset( $environment['extensions'] ) && is_array( $environment['extensions'] )
				? in_array( $extension, $environment['extensions'], true )
				: extension_loaded( $extension );
			if ( ! $loaded ) {
				$missing_extensions[] = $extension;
			}
		}

		if ( ! empty( $missing_extensions ) ) {
			return self::problem(
				'addon_php_extension_missing',
				'필수 PHP 확장이 필요합니다: ' . implode( ', ', $missing_extensions )
			);
		}

		if ( '' === $platform_version ) {
			return self::problem( 'platform_missing', 'OJ Platform이 필요합니다.' );
		}

		if ( ! Addon_Integration::version_in_range( $platform_version, Addon_Integration::MIN_PLATFORM, Addon_Integration::MAX_PLATFORM ) ) {
			return self::problem( 'addon_platform_incompatible', 'OJ Platform 2.0.0-dev.33 이상 3.0.0 미만이 필요합니다.' );
		}

		if ( '' === $addon_api_version ) {
			return self::problem( 'addon_api_missing', 'OJ Platform Addon API를 찾을 수 없습니다.' );
		}

		if ( ! Addon_Integration::version_in_range( $addon_api_version, Addon_Integration::MIN_ADDON_API, Addon_Integration::MAX_ADDON_API ) ) {
			return self::problem( 'addon_api_incompatible', 'OJ Platform Addon API 1.1 이상 2.0 미만이 필요합니다.' );
		}

		if ( ! $api_available ) {
			return self::problem( 'addon_api_unavailable', 'OJ Platform Addon API를 불러올 수 없습니다.' );
		}

		$storage_problem = self::private_storage_problem( $environment );
		if ( null !== $storage_problem ) {
			return $storage_problem;
		}

		return null;
	}

	/**
	 * Build a stable preflight problem.
	 *
	 * @param string $code Stable code.
	 * @param string $message User-facing message.
	 * @return array{code:string,message:string}
	 */
	private static function problem( $code, $message ) {
		return array(
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}

	/**
	 * Verify the private storage root without creating files or directories.
	 *
	 * @param array<string,mixed> $environment Environment overrides.
	 * @return array{code:string,message:string}|null
	 */
	private static function private_storage_problem( array $environment ) {
		if ( array_key_exists( 'private_storage_ready', $environment ) ) {
			if ( true === (bool) $environment['private_storage_ready'] ) {
				return null;
			}
			$code = isset( $environment['private_storage_code'] )
				? (string) $environment['private_storage_code']
				: 'storage_path_unavailable';
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
				$code = 'storage_path_unavailable';
			}
			return self::problem( $code, self::private_storage_message() );
		}

		$result = ( new Local_Private_Storage_Path_Diagnostics() )->inspect();
		if ( Storage_Path_Diagnostics::READY === $result->status() ) {
			return null;
		}

		return self::problem( $result->code(), self::private_storage_message() );
	}

	/**
	 * Private storage message.
	 *
	 * @return string
	 */
	private static function private_storage_message(): string {
		return '비공개 파일 저장 폴더를 준비할 수 없습니다. '
			. '웹 루트 밖의 쓰기 가능한 절대 경로를 wp-config.php의 '
			. 'OJ_AI_PRIVATE_STORAGE_DIR에 설정하세요.';
	}
}

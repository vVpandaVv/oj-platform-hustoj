<?php
/**
 * Per-user browser-storage credentials for shared computers.
 *
 * @package OJML\Playgrounds
 */

declare(strict_types=1);

namespace OJML\Playgrounds\Security;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Derives a stable opaque scope and encryption key for the current site user. */
final class Local_Storage_Credentials {
	/** @var int */
	private const SCOPE_BYTES = 18;
	/** @var int */
	private const SESSION_BYTES = 18;

	/**
	 * @param int $user_id Current authenticated WordPress user ID.
	 * @return array{scope:string,session:string,key:string,database:string,keyVersion:int}
	 */
	public static function for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'wp_salt' ) || ! function_exists( 'wp_get_session_token' ) ) {
			throw new RuntimeException( 'Authenticated local-storage credentials are unavailable.' );
		}

		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$session_token = (string) wp_get_session_token();
		if ( '' === $session_token ) {
			throw new RuntimeException( 'Authenticated browser session is unavailable.' );
		}
		$subject = $site_id . "\0" . $user_id;
		$scope   = hash_hmac( 'sha256', "oj-ml-storage-scope-v1\0" . $subject, (string) wp_salt( 'secure_auth' ), true );
		$key     = hash_hmac( 'sha256', "oj-ml-storage-key-v1\0" . $subject, (string) wp_salt( 'auth' ), true );
		$session = hash_hmac( 'sha256', "oj-ml-storage-session-v1\0" . $subject . "\0" . $session_token, (string) wp_salt( 'logged_in' ), true );
		$scope   = self::base64_url( substr( $scope, 0, self::SCOPE_BYTES ) );
		$session = self::base64_url( substr( $session, 0, self::SESSION_BYTES ) );

		return array(
			'scope'      => $scope,
			'session'    => $session,
			'key'        => self::base64_url( $key ),
			'database'   => 'ml-playgrounds-oj-v1-' . $scope,
			'keyVersion' => 1,
		);
	}

	/** @param string $bytes Raw bytes. */
	private static function base64_url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}

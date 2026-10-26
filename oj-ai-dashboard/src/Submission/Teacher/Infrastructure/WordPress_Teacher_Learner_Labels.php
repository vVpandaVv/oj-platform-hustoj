<?php
/**
 * Current learner labels for authorized teacher read responses only.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps live account labels separate from immutable display snapshots and AI input. */
final class WordPress_Teacher_Learner_Labels {
	/**
	 * Enrich only the explicit learner/target identity of an authorized read item.
	 *
	 * @param array<string,mixed> $item Teacher response item.
	 * @return array<string,mixed>
	 */
	public function present( array $item ): array {
		foreach ( array( 'learner', 'target' ) as $key ) {
			if ( ! isset( $item[ $key ] ) || ! is_array( $item[ $key ] ) ) {
				continue;
			}
			$identity               = $item[ $key ];
			$user_id                = $identity['learner_user_id'] ?? $identity['user_id'] ?? 0;
			$user                   = is_int( $user_id ) && $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
			$item[ $key ]['labels'] = array(
				'management_name' => is_object( $user ) && function_exists( 'get_user_meta' ) ? $this->text( get_user_meta( $user_id, 'nickname', true ) ) : '',
				'login'           => is_object( $user ) ? $this->text( $user->user_login ?? '' ) : '',
				'display_name'    => is_object( $user ) ? $this->text( $user->display_name ?? '' ) : '',
			);
		}
		return $item;
	}

	/**
	 * Reject malformed metadata without breaking the existing submission view.
	 *
	 * @param mixed $value WordPress account value.
	 * @return string
	 */
	private function text( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 255 && 1 === preg_match( '//u', $value ) ? trim( $value ) : '';
	}
}

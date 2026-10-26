<?php
/**
 * Common REST success and failure envelopes.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Security\Policy\Policy_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the single frozen REST response shape used by every controller.
 */
final class Rest_Envelope_Factory {
	/**
	 * Validates metadata and creates a success response.
	 *
	 * @param array<string,mixed>    $data Projection data.
	 * @param string                 $correlation_id Server-generated correlation identifier.
	 * @param int                    $status Supported success status.
	 * @param array<string,mixed>    $meta Safe response metadata.
	 * @param array<array-key,mixed> $links Server-generated links, validated before emission.
	 * @param array<array-key,mixed> $actions Authorized action keys, validated before emission.
	 * @return \WP_REST_Response
	 * @throws InvalidArgumentException When response fields violate the contract.
	 * @throws LogicException When WordPress REST support is unavailable.
	 */
	public function success(
		array $data,
		string $correlation_id,
		int $status = 200,
		array $meta = array(),
		array $links = array(),
		array $actions = array()
	) {
		if ( ! in_array( $status, array( 200, 201, 202 ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported frozen REST success status.' );
		}
		Policy_Value::correlation_id( $correlation_id );

		if ( array_key_exists( 'correlation_id', $meta ) ) {
			throw new InvalidArgumentException( 'REST metadata cannot replace the server correlation ID.' );
		}
		$this->validate_links( $links );
		$this->validate_actions( $actions );

		if ( ! class_exists( '\\WP_REST_Response' ) ) {
			throw new LogicException( 'WordPress REST response support is unavailable.' );
		}

		return new \WP_REST_Response(
			array(
				'data'    => $data,
				'meta'    => array_merge( array( 'correlation_id' => $correlation_id ), $meta ),
				'links'   => $links,
				'actions' => $actions,
			),
			$status
		);
	}

	/**
	 * Converts a stable problem into a WordPress error.
	 *
	 * @param Rest_Problem $problem Validated problem.
	 * @return \WP_Error
	 * @throws LogicException When WordPress error support is unavailable.
	 */
	public function problem( Rest_Problem $problem ) {
		if ( ! class_exists( '\\WP_Error' ) ) {
			throw new LogicException( 'WordPress REST error support is unavailable.' );
		}

		return new \WP_Error( $problem->code(), $problem->message(), $problem->data() );
	}

	/**
	 * Adds a flat, non-sensitive recovery projection to one operation problem.
	 *
	 * @param Rest_Problem           $problem Validated problem.
	 * @param array<array-key,mixed> $safe_data Recovery fields to validate before emission.
	 * @return \WP_Error
	 * @throws InvalidArgumentException When recovery fields are invalid.
	 * @throws LogicException When WordPress error support is unavailable.
	 */
	public function problem_with_safe_data( Rest_Problem $problem, array $safe_data ) {
		if ( array() === $safe_data || array_is_list( $safe_data ) ) {
			throw new InvalidArgumentException( 'REST problem safe data must be a non-empty object.' );
		}
		foreach ( $safe_data as $key => $value ) {
			if (
				! is_string( $key )
				|| in_array( $key, array( 'status', 'correlation_id' ), true )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key )
				|| ! is_string( $value )
				|| '' === $value
				|| strlen( $value ) > 128
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
			) {
				throw new InvalidArgumentException( 'REST problem safe data is invalid.' );
			}
		}
		if ( ! class_exists( '\\WP_Error' ) ) {
			throw new LogicException( 'WordPress REST error support is unavailable.' );
		}

		return new \WP_Error(
			$problem->code(),
			$problem->message(),
			array_merge( $problem->data(), $safe_data )
		);
	}

	/**
	 * Converts a denied authorization result without widening its disclosure.
	 *
	 * @param Rest_Authorization_Result $result Authorization result.
	 * @return \WP_Error
	 * @throws InvalidArgumentException When the result is not a denial.
	 */
	public function authorization_error( Rest_Authorization_Result $result ) {
		$problem = $result->problem();
		if ( $result->is_allowed() || ! $problem instanceof Rest_Problem ) {
			throw new InvalidArgumentException( 'An allowed REST authorization result is not an error.' );
		}

		return $this->problem( $problem );
	}

	/**
	 * Rejects invalid relation names and unsafe link values.
	 *
	 * @param array<array-key,mixed> $links Unvalidated links.
	 * @throws InvalidArgumentException When a link violates the contract.
	 * @return void
	 */
	private function validate_links( array $links ): void {
		foreach ( $links as $relation => $url ) {
			if (
				! is_string( $relation )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/D', $relation )
				|| ! is_string( $url )
				|| '' === $url
				|| strlen( $url ) > 2048
				|| 1 === preg_match( '/[\r\n]/', $url )
			) {
				throw new InvalidArgumentException( 'Invalid REST response link.' );
			}
		}
	}

	/**
	 * Validates each action before checking list uniqueness.
	 *
	 * @param array<array-key,mixed> $actions Unvalidated action list.
	 * @throws InvalidArgumentException When an action violates the contract.
	 * @return void
	 */
	private function validate_actions( array $actions ): void {
		if ( ! array_is_list( $actions ) ) {
			throw new InvalidArgumentException( 'REST response actions must be a unique list.' );
		}

		foreach ( $actions as $action ) {
			if ( ! is_string( $action ) ) {
				throw new InvalidArgumentException( 'Invalid REST response action.' );
			}
			Policy_Value::simple_key( $action, 'rest_action' );
		}
		if ( count( $actions ) !== count( array_unique( $actions ) ) ) {
			throw new InvalidArgumentException( 'REST response actions must be a unique list.' );
		}
	}
}

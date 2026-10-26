<?php
/**
 * WordPress REST request metadata adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Request_Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts only method, server actor, standard nonce, and idempotency key.
 */
final class WordPress_Rest_Request_Adapter {
	/**
	 * Actors.
	 *
	 * @var Current_Actor_Port_Interface
	 */
	private Current_Actor_Port_Interface $actors;

	/**
	 * Initialize WordPress rest request adapter dependencies and state.
	 *
	 * @param Current_Actor_Port_Interface $actors Actors.
	 */
	public function __construct( Current_Actor_Port_Interface $actors ) {
		$this->actors = $actors;
	}

	/**
	 * Capture.
	 *
	 * @param object $request WordPress REST request compatible object.
	 * @return Rest_Request_Metadata
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function capture( object $request ): Rest_Request_Metadata {
		if ( ! method_exists( $request, 'get_method' ) || ! method_exists( $request, 'get_header' ) ) {
			throw new InvalidArgumentException( 'A WordPress REST request is required.' );
		}

		return new Rest_Request_Metadata(
			$this->actors->current_actor_user_id(),
			(string) $request->get_method(),
			$this->header( $request, 'X-WP-Nonce' ),
			$this->header( $request, 'Idempotency-Key' )
		);
	}

	/**
	 * Header.
	 *
	 * @param object $request Request.
	 * @param string $name Name.
	 * @return string
	 */
	private function header( object $request, string $name ): string {
		$value = $request->get_header( $name );
		return is_scalar( $value ) ? (string) $value : '';
	}
}

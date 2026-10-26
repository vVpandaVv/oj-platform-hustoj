<?php
/**
 * Authorized REST request handoff value.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries trusted transport metadata and its matching policy decision.
 */
final class Rest_Permission_Grant {
	/**
	 * Metadata.
	 *
	 * @var Rest_Request_Metadata
	 */
	private Rest_Request_Metadata $metadata;
	/**
	 * Authorization.
	 *
	 * @var Rest_Authorization_Result
	 */
	private Rest_Authorization_Result $authorization;

	/**
	 * Initialize rest permission grant dependencies and state.
	 *
	 * @param Rest_Request_Metadata     $metadata Metadata.
	 * @param Rest_Authorization_Result $authorization Authorization.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		Rest_Request_Metadata $metadata,
		Rest_Authorization_Result $authorization
	) {
		if ( ! $authorization->is_allowed() ) {
			throw new InvalidArgumentException( 'A denied REST request cannot create a permission grant.' );
		}

		$this->metadata      = $metadata;
		$this->authorization = $authorization;
	}

	/**
	 * Metadata.
	 *
	 * @return Rest_Request_Metadata
	 */
	public function metadata(): Rest_Request_Metadata {
		return $this->metadata;
	}

	/**
	 * Authorization.
	 *
	 * @return Rest_Authorization_Result
	 */
	public function authorization(): Rest_Authorization_Result {
		return $this->authorization;
	}
}

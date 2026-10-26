<?php
/**
 * Trusted REST transport metadata.
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
 * Carries server-resolved actor and allowlisted request headers.
 */
final class Rest_Request_Metadata {
	public const METHOD_GET  = 'GET';
	public const METHOD_POST = 'POST';

	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Method.
	 *
	 * @var string
	 */
	private string $method;
	/**
	 * Nonce.
	 *
	 * @var string
	 */
	private string $nonce;
	/**
	 * Idempotency key.
	 *
	 * @var string
	 */
	private string $idempotency_key;

	/**
	 * Initialize rest request metadata dependencies and state.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $method Method.
	 * @param string $nonce Nonce.
	 * @param string $idempotency_key Idempotency key.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $method,
		string $nonce = '',
		string $idempotency_key = ''
	) {
		if ( $actor_user_id < 0 ) {
			throw new InvalidArgumentException( 'REST actor user ID cannot be negative.' );
		}

		$method = strtoupper( $method );
		if ( ! in_array( $method, array( self::METHOD_GET, self::METHOD_POST ), true ) ) {
			throw new InvalidArgumentException( 'Only frozen REST GET and POST methods are supported.' );
		}

		$this->actor_user_id   = $actor_user_id;
		$this->method          = $method;
		$this->nonce           = $nonce;
		$this->idempotency_key = $idempotency_key;
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}

	/**
	 * Method.
	 *
	 * @return string
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Is mutation.
	 *
	 * @return bool
	 */
	public function is_mutation(): bool {
		return self::METHOD_POST === $this->method;
	}

	/**
	 * Nonce.
	 *
	 * @return string
	 */
	public function nonce(): string {
		return $this->nonce;
	}

	/**
	 * Idempotency key.
	 *
	 * @return string
	 */
	public function idempotency_key(): string {
		return $this->idempotency_key;
	}
}

<?php
/**
 * Neutral teaching Context query result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces platform errors with stable add-on reasons.
 */
final class Teaching_Context_Query_Result {
	public const REASON_ALLOWED                   = 'context_list_allowed';
	public const REASON_RUNTIME_UNAVAILABLE       = 'runtime_unavailable';
	public const REASON_CONTEXT_DENIED            = 'context_denied';
	public const REASON_CONTEXT_UNAVAILABLE       = 'context_unavailable';
	public const REASON_INVALID_PLATFORM_RESPONSE = 'invalid_platform_response';

	/**
	 * Page.
	 *
	 * @var Teaching_Context_Page|null
	 */
	private ?Teaching_Context_Page $page;
	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;
	/**
	 * Platform correlation id.
	 *
	 * @var string|null
	 */
	private ?string $platform_correlation_id;

	/**
	 * Initialize teaching context query result dependencies and state.
	 *
	 * @param Teaching_Context_Page|null $page Page.
	 * @param string                     $reason Reason.
	 * @param string|null                $platform_correlation_id Platform correlation id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		?Teaching_Context_Page $page,
		string $reason,
		?string $platform_correlation_id = null
	) {
		$allowed_reasons = array(
			self::REASON_ALLOWED,
			self::REASON_RUNTIME_UNAVAILABLE,
			self::REASON_CONTEXT_DENIED,
			self::REASON_CONTEXT_UNAVAILABLE,
			self::REASON_INVALID_PLATFORM_RESPONSE,
		);
		if (
			! in_array( $reason, $allowed_reasons, true )
			|| ( $page instanceof Teaching_Context_Page ) !== ( self::REASON_ALLOWED === $reason )
		) {
			throw new InvalidArgumentException( 'Teaching Context query result state is invalid.' );
		}
		if ( null !== $platform_correlation_id ) {
			Context_Value::safe_token( $platform_correlation_id, 'platform_correlation_id', 128 );
		}

		$this->page                    = $page;
		$this->reason                  = $reason;
		$this->platform_correlation_id = $platform_correlation_id;
	}

	/**
	 * Successful.
	 *
	 * @param Teaching_Context_Page $page Page.
	 * @return self
	 */
	public static function successful( Teaching_Context_Page $page ): self {
		return new self( $page, self::REASON_ALLOWED );
	}

	/**
	 * Failed.
	 *
	 * @param string      $reason Reason.
	 * @param string|null $platform_correlation_id Platform correlation id.
	 * @return self
	 */
	public static function failed( string $reason, ?string $platform_correlation_id = null ): self {
		return new self( null, $reason, $platform_correlation_id );
	}

	/**
	 * Is successful.
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return $this->page instanceof Teaching_Context_Page;
	}

	/**
	 * Page.
	 *
	 * @return Teaching_Context_Page|null
	 */
	public function page(): ?Teaching_Context_Page {
		return $this->page;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Platform correlation id.
	 *
	 * @return string|null
	 */
	public function platform_correlation_id(): ?string {
		return $this->platform_correlation_id;
	}
}

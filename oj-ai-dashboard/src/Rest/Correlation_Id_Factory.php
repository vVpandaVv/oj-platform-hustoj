<?php
/**
 * Server-generated REST correlation identifiers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use Closure;
use OJAI\Dashboard\Security\Policy\Policy_Value;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a new safe identifier for every authorization attempt.
 */
final class Correlation_Id_Factory {
	/**
	 * Generator.
	 *
	 * @var Closure|null
	 */
	private ?Closure $generator;

	/**
	 * Initialize correlation id factory dependencies and state.
	 *
	 * @param callable|null $generator Generator.
	 */
	public function __construct( ?callable $generator = null ) {
		$this->generator = null === $generator ? null : Closure::fromCallable( $generator );
	}

	/**
	 * Create correlation id factory.
	 *
	 * @return string
	 */
	public function create(): string {
		$candidate = $this->candidate();
		if ( $this->is_valid( $candidate ) ) {
			return $candidate;
		}

		try {
			$fallback = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			$fallback = hash( 'sha256', uniqid( 'oj-ai-', true ) );
		}

		Policy_Value::correlation_id( $fallback );
		return $fallback;
	}

	/**
	 * Candidate.
	 *
	 * @return string
	 */
	private function candidate(): string {
		if ( $this->generator instanceof Closure ) {
			try {
				$value = ( $this->generator )();
				return is_string( $value ) ? $value : '';
			} catch ( Throwable ) {
				return '';
			}
		}

		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$value = wp_generate_uuid4();
			return $value;
		}

		return '';
	}

	/**
	 * Is valid.
	 *
	 * @param string $candidate Candidate.
	 * @return bool
	 */
	private function is_valid( string $candidate ): bool {
		try {
			Policy_Value::correlation_id( $candidate );
			return true;
		} catch ( Throwable ) {
			return false;
		}
	}
}

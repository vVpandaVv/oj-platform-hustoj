<?php
/**
 * Schema postcondition result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries stable structural diagnostics without exposing SQL or credentials.
 */
final class Schema_Postcondition_Result {
	/**
	 * Issues.
	 *
	 * @var array<int,array{code:string,table?:string,object?:string}>
	 */
	private array $issues;

	/**
	 * Verified fingerprint.
	 *
	 * @var string|null
	 */
	private ?string $verified_fingerprint;

	/**
	 * Initialize schema postcondition result dependencies and state.
	 *
	 * @param array<int,array{code:string,table?:string,object?:string}> $issues Issues.
	 * @param string|null                                                $verified_fingerprint Verified fingerprint.
	 */
	public function __construct( array $issues, ?string $verified_fingerprint = null ) {
		$this->issues               = $issues;
		$this->verified_fingerprint = $verified_fingerprint;
	}

	/**
	 * Passed.
	 *
	 * @return bool
	 */
	public function passed(): bool {
		return array() === $this->issues;
	}

	/**
	 * Issues.
	 *
	 * @return array<int,array{code:string,table?:string,object?:string}>
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * Verified fingerprint.
	 *
	 * @return string|null
	 */
	public function verified_fingerprint(): ?string {
		return $this->verified_fingerprint;
	}
}

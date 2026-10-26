<?php
/**
 * Accepted onReady Runtime readiness gate.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use LogicException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects Platform Runtime contracts only after local schema verification.
 */
final class Addon_Runtime_Readiness_Gate {
	/**
	 * Runtime.
	 *
	 * @var Addon_Runtime_Bridge
	 */
	private Addon_Runtime_Bridge $runtime;
	/**
	 * Schema readiness.
	 *
	 * @var Schema_Runtime_Readiness_Port_Interface
	 */
	private Schema_Runtime_Readiness_Port_Interface $schema_readiness;
	/**
	 * Attempted.
	 *
	 * @var bool
	 */
	private bool $attempted = false;

	/**
	 * Initialize addon runtime readiness gate dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge                    $runtime Runtime.
	 * @param Schema_Runtime_Readiness_Port_Interface $schema_readiness Schema readiness.
	 */
	public function __construct(
		Addon_Runtime_Bridge $runtime,
		Schema_Runtime_Readiness_Port_Interface $schema_readiness
	) {
		$this->runtime          = $runtime;
		$this->schema_readiness = $schema_readiness;
	}

	/**
	 * Connect.
	 *
	 * @param AddonRuntimeContextV1 $context Access or execution context for this operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function connect( AddonRuntimeContextV1 $context ): void {
		if ( $this->attempted ) {
			throw new LogicException( 'The OJ AI add-on onReady gate can only run once per boot.' );
		}
		$this->attempted = true;

		try {
			$result = $this->schema_readiness->verify();
		} catch ( Throwable ) {
			$this->runtime->mark_unavailable( 'schema_runtime_verification_failed' );
			throw new RuntimeException( 'schema_runtime_verification_failed' );
		}

		if ( ! $result->is_ready() ) {
			$this->runtime->mark_unavailable( $result->code() );
			throw new RuntimeException( $result->code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}

		$this->runtime->connect( $context );
	}
}

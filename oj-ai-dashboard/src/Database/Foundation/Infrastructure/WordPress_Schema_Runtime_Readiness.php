<?php
/**
 * Lazy WordPress Runtime schema readiness adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use Closure;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Verifier;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the WordPress database only when accepted onReady verification runs.
 */
final class WordPress_Schema_Runtime_Readiness implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Database provider.
	 *
	 * @var Closure
	 */
	private Closure $database_provider;

	/**
	 * Initialize WordPress schema runtime readiness dependencies and state.
	 *
	 * @param Closure|null $database_provider Database provider.
	 */
	public function __construct( ?Closure $database_provider = null ) {
		$this->database_provider = $database_provider ?? static function () {
			global $wpdb;

			return $wpdb ?? null;
		};
	}

	/**
	 * Verify WordPress schema runtime readiness.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		try {
			$database = ( $this->database_provider )();
			if ( ! is_object( $database ) ) {
				return $this->unavailable( 'schema_database_unavailable' );
			}

			$catalog   = new Schema_Catalog();
			$inspector = new WordPress_Schema_Inspector( $database );
			$verifier  = new Schema_Runtime_Readiness_Verifier(
				$catalog,
				new Migration_Plan( $catalog ),
				new Schema_Preflight( $catalog ),
				$inspector,
				new WordPress_Schema_Ledger_Reader( $database ),
				new Schema_Postcondition_Verifier( $catalog, $inspector )
			);

			return $verifier->verify();
		} catch ( Throwable ) {
			return $this->unavailable( 'schema_runtime_verification_failed' );
		}
	}

	/**
	 * Unavailable.
	 *
	 * @param string $code Code.
	 * @return Schema_Runtime_Readiness_Result
	 */
	private function unavailable( string $code ): Schema_Runtime_Readiness_Result {
		return new Schema_Runtime_Readiness_Result(
			Schema_Runtime_Readiness_Result::UNAVAILABLE,
			$code
		);
	}
}

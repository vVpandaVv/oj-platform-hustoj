<?php
/**
 * WordPress student note execution consent.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use Closure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress student note execution consent.
 */
final class WordPress_Student_Note_Execution_Consent implements Student_Note_Execution_Consent_Port_Interface {
	/**
	 * Loader.
	 *
	 * @var Closure
	 */
	private Closure $loader;
	/**
	 * Initialize WordPress student note execution consent dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param callable       $loader Loader.
	 */
	public function __construct( private Canonical_Json $canonical, callable $loader ) {
		$this->loader = Closure::fromCallable( $loader );
	}
	/**
	 * Allows.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Student_Note_Work_Item $item ): bool {
		try {
			return Submission_External_Ai_Consent_Record::from_array( ( $this->loader )(), $this->canonical )->matches_routing( $item->planned_routing() );
		} catch ( Throwable ) {
			return false;
		}
	}
}

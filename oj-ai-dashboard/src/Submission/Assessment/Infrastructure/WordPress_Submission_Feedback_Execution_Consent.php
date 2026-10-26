<?php
/**
 * WordPress submission feedback execution consent.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use Closure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress submission feedback execution consent.
 */
final class WordPress_Submission_Feedback_Execution_Consent implements Submission_Feedback_Execution_Consent_Port_Interface {
	/**
	 * Loader.
	 *
	 * @var Closure
	 */
	private Closure $loader;
	/**
	 * Initialize WordPress submission feedback execution consent dependencies and state.
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
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Submission_Feedback_Work_Item $item ): bool {
		try {
			return Submission_External_Ai_Consent_Record::from_array( ( $this->loader )(), $this->canonical )->matches_routing( $item->planned_routing() );
		} catch ( Throwable ) {
			return false;
		}
	}
}

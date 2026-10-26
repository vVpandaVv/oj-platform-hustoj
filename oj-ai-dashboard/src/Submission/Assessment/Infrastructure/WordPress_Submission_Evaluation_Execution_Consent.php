<?php
/**
 * WordPress submission evaluation execution consent.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use Closure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress submission evaluation execution consent.
 */
final class WordPress_Submission_Evaluation_Execution_Consent implements Submission_Evaluation_Execution_Consent_Port_Interface {
	public const SCHEMA          = Submission_External_Ai_Consent_Record::SCHEMA;
	public const VERSION         = Submission_External_Ai_Consent_Record::VERSION;
	public const DATA_CATEGORIES = Submission_External_Ai_Consent_Record::DATA_CATEGORIES;

	/**
	 * Loader.
	 *
	 * @var Closure
	 */
	private Closure $loader;

	/**
	 * Initialize WordPress submission evaluation execution consent dependencies and state.
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
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Submission_Evaluation_Work_Item $item ): bool {
		try {
			$record = Submission_External_Ai_Consent_Record::from_array( ( $this->loader )(), $this->canonical );
			return $record->matches_routing( $item->planned_routing() );
		} catch ( Throwable ) {
			return false;
		}
	}
}

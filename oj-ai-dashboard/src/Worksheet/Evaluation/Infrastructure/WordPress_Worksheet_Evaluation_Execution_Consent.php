<?php
/**
 * Explicit external AI processing consent bound to one safe routing identity.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use Closure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\External_Ai_Consent_Record;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Execution_Consent_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit external AI processing consent bound to one safe routing identity.
 */
final class WordPress_Worksheet_Evaluation_Execution_Consent implements Worksheet_Evaluation_Execution_Consent_Port_Interface {
	public const OPTION          = 'oj_ai_dashboard_external_ai_consent';
	public const SCHEMA          = External_Ai_Consent_Record::SCHEMA;
	public const VERSION         = External_Ai_Consent_Record::VERSION;
	public const DATA_CATEGORIES = External_Ai_Consent_Record::DATA_CATEGORIES;

	/**
	 * Loader.
	 *
	 * @var Closure
	 */
	private Closure $loader;

	/**
	 * Initialize WordPress worksheet evaluation execution consent dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null  $loader Loader.
	 */
	public function __construct( private Canonical_Json $canonical, ?callable $loader = null ) {
		$this->loader = null === $loader
			? static fn () => function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null
			: Closure::fromCallable( $loader );
	}

	/**
	 * Allows.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Worksheet_Evaluation_Work_Item $item ): bool {
		try {
			$record = External_Ai_Consent_Record::from_array( ( $this->loader )(), $this->canonical );
			return $record->matches_routing( $item->planned_routing() );
		} catch ( Throwable ) {
			return false;
		}
	}
}

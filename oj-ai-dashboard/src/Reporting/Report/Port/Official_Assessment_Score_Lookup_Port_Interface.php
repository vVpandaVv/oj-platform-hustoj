<?php
/**
 * Official assessment score lookup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official assessment score lookup port interface.
 */
interface Official_Assessment_Score_Lookup_Port_Interface {
	/**
	 * Resolves a flat list of official-review pointers to their current
	 * normalized score and release state, without any per-publication or
	 * per-criterion grouping. Used by cross-worksheet student report views.
	 *
	 * @param array<int,array{review:array{id:string,hash:string},release:?array{id:string,hash:string}}> $review_refs Review refs.
	 * @return array<int,array{review_id:string,normalized_basis_points:int,release_state:?string}>
	 */
	public function by_review_refs( array $review_refs ): array;
}

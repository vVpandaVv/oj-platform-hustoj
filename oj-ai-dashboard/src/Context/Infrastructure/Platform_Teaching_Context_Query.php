<?php
/**
 * OJ Platform teaching Context query adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSummaryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use OJAI\Dashboard\Context\Context_Value;
use OJAI\Dashboard\Context\Port\Teaching_Context_Query_Port_Interface;
use OJAI\Dashboard\Context\Teaching_Context_Page;
use OJAI\Dashboard\Context\Teaching_Context_Query;
use OJAI\Dashboard\Context\Teaching_Context_Query_Result;
use OJAI\Dashboard\Context\Teaching_Context_Summary;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects the public Addon API page into the add-on-owned Context contract.
 */
final class Platform_Teaching_Context_Query implements Teaching_Context_Query_Port_Interface {
	/**
	 * Runtime.
	 *
	 * @var Platform_Context_Runtime_Port_Interface
	 */
	private Platform_Context_Runtime_Port_Interface $runtime;

	/**
	 * Initialize platform teaching context query dependencies and state.
	 *
	 * @param Platform_Context_Runtime_Port_Interface $runtime Runtime.
	 */
	public function __construct( Platform_Context_Runtime_Port_Interface $runtime ) {
		$this->runtime = $runtime;
	}

	/**
	 * Query.
	 *
	 * @param Teaching_Context_Query $query Query.
	 * @return Teaching_Context_Query_Result
	 */
	public function query( Teaching_Context_Query $query ): Teaching_Context_Query_Result {
		if ( ! $this->runtime->is_ready() ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_RUNTIME_UNAVAILABLE
			);
		}

		$gateway = $this->runtime->contexts();
		if ( null === $gateway ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_RUNTIME_UNAVAILABLE
			);
		}

		try {
			$page = $gateway->listTeachingContexts(
				new AddonTeachingContextQueryV1(
					$query->actor_user_id(),
					$query->types(),
					$query->search(),
					$query->cursor(),
					$query->limit()
				)
			);
		} catch ( Throwable ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_CONTEXT_UNAVAILABLE
			);
		}

		if ( ! $page->isSuccessful() ) {
			return $this->map_problem( $page );
		}

		return $this->project_page( $query, $page );
	}

	/**
	 * Map problem.
	 *
	 * @param AddonContextPageV1 $page Page.
	 * @return Teaching_Context_Query_Result
	 */
	private function map_problem( AddonContextPageV1 $page ): Teaching_Context_Query_Result {
		$problem = $page->problem();
		if ( ! $problem instanceof AddonApiProblemV1 || '' !== $page->nextCursor() ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE
			);
		}

		$reasons = array(
			'addon_context_denied'      => Teaching_Context_Query_Result::REASON_CONTEXT_DENIED,
			'addon_context_unavailable' => Teaching_Context_Query_Result::REASON_CONTEXT_UNAVAILABLE,
		);
		$reason  = $reasons[ $problem->code() ]
			?? Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE;

		return Teaching_Context_Query_Result::failed( $reason, $problem->correlationId() );
	}

	/**
	 * Project page.
	 *
	 * @param Teaching_Context_Query $query Query.
	 * @param AddonContextPageV1     $platform_page Platform page.
	 * @return Teaching_Context_Query_Result
	 */
	private function project_page(
		Teaching_Context_Query $query,
		AddonContextPageV1 $platform_page
	): Teaching_Context_Query_Result {
		$next_cursor = $platform_page->nextCursor();
		if ( '' !== $query->cursor() && hash_equals( $query->cursor(), $next_cursor ) ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE
			);
		}

		$seen_ids       = array();
		$items          = array();
		$excluded_count = 0;
		foreach ( $platform_page->items() as $platform_item ) {
			$id = $platform_item->id();
			if ( isset( $seen_ids[ $id ] ) ) {
				return Teaching_Context_Query_Result::failed(
					Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE
				);
			}
			$seen_ids[ $id ] = true;

			try {
				$items[] = $this->project_item( $platform_item );
			} catch ( Throwable ) {
				++$excluded_count;
			}
		}

		try {
			return Teaching_Context_Query_Result::successful(
				new Teaching_Context_Page( $items, $next_cursor, $excluded_count )
			);
		} catch ( Throwable ) {
			return Teaching_Context_Query_Result::failed(
				Teaching_Context_Query_Result::REASON_INVALID_PLATFORM_RESPONSE
			);
		}
	}

	/**
	 * Project item.
	 *
	 * @param AddonContextSummaryV1 $item Item.
	 * @return Teaching_Context_Summary
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project_item( AddonContextSummaryV1 $item ): Teaching_Context_Summary {
		$attributes = $item->attributes();
		if ( ! array_key_exists( 'enabled', $attributes ) || ! is_bool( $attributes['enabled'] ) ) {
			throw new \InvalidArgumentException( 'Context enabled attribute is unavailable.' );
		}

		return new Teaching_Context_Summary(
			$item->type(),
			$item->id(),
			$item->label(),
			$item->lifecycleState(),
			$item->revision(),
			$attributes['enabled'],
			$this->optional_academic_year( $attributes['academic_year'] ?? null ),
			$this->optional_grade_label( $attributes['grade_label'] ?? null )
		);
	}

	/**
	 * Optional academic year.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_academic_year( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		try {
			Context_Value::text( $value, 'academic_year', 32 );
			return $value;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Optional grade label.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_grade_label( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		try {
			Context_Value::label( $value, 'grade_label' );
			return $value;
		} catch ( Throwable ) {
			return null;
		}
	}
}

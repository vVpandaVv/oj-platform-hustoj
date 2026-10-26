<?php
/**
 * Student-lens roster report list use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Source_Validator;
use OJAI\Dashboard\Reporting\Report\Domain\Student_Roster_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Student_Roster_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-lens roster report list use case.
 */
final class Read_Student_Roster_Reports {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 191;
	public const FILTER_SCHEMA  = Report_Filter::SCHEMA_ID;
	public const FILTER_VERSION = Report_Filter::VERSION;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize student roster reports dependencies and state.
	 *
	 * @param Student_Roster_Report_Source_Query_Port_Interface $roster Roster.
	 * @param Official_Assessment_Score_Lookup_Port_Interface   $scores Scores.
	 * @param Student_Roster_Report_Projector                   $projector Projector.
	 * @param Canonical_Json                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                     $clock Clock.
	 */
	public function __construct(
		private Student_Roster_Report_Source_Query_Port_Interface $roster,
		private Official_Assessment_Score_Lookup_Port_Interface $scores,
		private Student_Roster_Report_Projector $projector,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * List student roster reports.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array{report:array<string,mixed>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): array {
		if ( $actor_user_id < 1 || strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Student roster report page is invalid.' );
		}
		new Context_Scope( $context_type, $context_id, Context_Scope::OPERATION_REPORT, null, $context_revision );
		$filter ??= Report_Filter::defaults();

		$page = $this->roster->list_context( $actor_user_id, $context_type, $context_id, $context_revision, $cursor, $limit, $filter );
		if ( ! Report_Source_Validator::list_page( $page, $limit, self::MAXIMUM_CURSOR ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
		}

		$refs = array();
		foreach ( $page['items'] as $source ) {
			if ( ! is_array( $source['official_review_refs'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
			}
			foreach ( $source['official_review_refs'] as $ref ) {
				$refs[] = $ref;
			}
		}

		$projection                = $this->projector->project( $page['items'], $this->scores->by_review_refs( $refs ) );
		$filter_payload            = array_merge(
			array(
				'lens'    => 'student',
				'context' => array(
					'type'     => $context_type,
					'id'       => $context_id,
					'revision' => $context_revision,
				),
			),
			$filter->payload()
		);
		$report                    = array(
			'lens'            => 'student',
			'baseline_at_utc' => $this->now(),
			'metric_contract' => Student_Roster_Report_Projector::METRIC_CONTRACT,
			'context'         => $filter_payload['context'],
			'filters'         => array(
				'applied'   => $filter->payload(),
				'available' => array_merge( array( 'lenses' => array( 'student' ) ), $filter->available() ),
			),
			'filter_hash'     => hash(
				'sha256',
				$this->canonical->encode(
					array(
						'schema_id'      => self::FILTER_SCHEMA,
						'schema_version' => self::FILTER_VERSION,
						'payload'        => $filter_payload,
					)
				)
			),
			'items'           => $projection['items'],
		);
		$report['projection_hash'] = hash( 'sha256', $this->canonical->encode( $report ) );

		return array(
			'report'      => $report,
			'next_cursor' => $page['next_cursor'],
		);
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Student roster report clock is invalid.' );
		}
		return $value;
	}
}

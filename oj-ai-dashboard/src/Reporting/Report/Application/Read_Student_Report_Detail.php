<?php
/**
 * Student-lens per-student cross-worksheet report drill-down use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Source_Validator;
use OJAI\Dashboard\Reporting\Report\Domain\Student_Report_Detail_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Score_Lookup_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Student_Report_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-lens per-student cross-worksheet report drill-down use case.
 */
final class Read_Student_Report_Detail {
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
	 * Initialize student report detail dependencies and state.
	 *
	 * @param Student_Report_Detail_Source_Query_Port_Interface $detail Detail.
	 * @param Worksheet_Report_Source_Query_Port_Interface      $worksheets Worksheets.
	 * @param Official_Assessment_Score_Lookup_Port_Interface   $scores Scores.
	 * @param Student_Report_Detail_Projector                   $projector Projector.
	 * @param Canonical_Json                                    $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                     $clock Clock.
	 */
	public function __construct(
		private Student_Report_Detail_Source_Query_Port_Interface $detail,
		private Worksheet_Report_Source_Query_Port_Interface $worksheets,
		private Official_Assessment_Score_Lookup_Port_Interface $scores,
		private Student_Report_Detail_Projector $projector,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Detail.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $student_target_id Student target id.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return array{report:array<string,mixed>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $student_target_id,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): array {
		if (
			$actor_user_id < 1 || ! Public_Id::is_valid( $student_target_id )
			|| strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT
		) {
			throw new InvalidArgumentException( 'Student report detail input is invalid.' );
		}
		new Context_Scope( $context_type, $context_id, Context_Scope::OPERATION_REPORT, null, $context_revision );
		$filter ??= Report_Filter::defaults();

		$page = $this->detail->for_student( $actor_user_id, $context_type, $context_id, $context_revision, $student_target_id, $cursor, $limit, $filter );
		if ( null === $page ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::NOT_FOUND );
		}
		if ( ! Report_Source_Validator::detail_page( $page, self::MAXIMUM_CURSOR ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
		}
		$source      = $page['source'];
		$assignments = $source['assignments'] ?? null;
		if ( ! is_array( $assignments ) || ! array_is_list( $assignments ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
		}

		$publications      = array();
		$seen_publications = array();
		$refs              = array();
		foreach ( $assignments as $assignment ) {
			if ( ! is_array( $assignment ) || ! is_array( $assignment['publication'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
			}
			$publication = $assignment['publication'];
			$id          = $publication['id'] ?? null;
			$hash        = $publication['hash'] ?? null;
			if (
				! is_string( $id ) || ! Public_Id::is_valid( $id )
				|| ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
			}
			if ( ! isset( $seen_publications[ $id ] ) ) {
				$seen_publications[ $id ] = true;
				$publications[]           = array(
					'id'   => $id,
					'hash' => $hash,
				);
			}
			if ( null !== ( $assignment['official_review_ref'] ?? null ) ) {
				$refs[] = $assignment['official_review_ref'];
			}
		}

		$worksheet_sources = $this->worksheets->by_publications( $publications );
		$score_rows        = $this->scores->by_review_refs( $refs );
		$projection        = $this->projector->project( $source, $worksheet_sources, $score_rows );

		$filter_payload            = array_merge(
			array(
				'lens'       => 'student',
				'level'      => 'student_detail',
				'context'    => $projection['context'],
				'student_id' => $student_target_id,
			),
			$filter->payload()
		);
		$report                    = array(
			'lens'            => 'student',
			'level'           => 'student_detail',
			'baseline_at_utc' => $this->now(),
			'metric_contract' => Student_Report_Detail_Projector::METRIC_CONTRACT,
			'context'         => $projection['context'],
			'student'         => $projection['student'],
			'filters'         => array(
				'applied'   => array_merge( array( 'student_id' => $student_target_id ), $filter->payload() ),
				'available' => array_merge( array( 'levels' => array( 'student_detail' ) ), $filter->available() ),
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
			throw new InvalidArgumentException( 'Student report detail clock is invalid.' );
		}
		return $value;
	}
}

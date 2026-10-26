<?php
/**
 * Q20 worksheet report list use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Denominator_Contract;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Source_Validator;
use OJAI\Dashboard\Reporting\Report\Domain\Worksheet_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q20 worksheet report list use case.
 */
final class Read_Worksheet_Reports {
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
	 * Initialize worksheet reports dependencies and state.
	 *
	 * @param Assignment_Progress_Source_Query_Port_Interface $progress Progress.
	 * @param Worksheet_Report_Source_Query_Port_Interface    $worksheets Worksheets.
	 * @param Official_Assessment_Source_Query_Port_Interface $assessments Assessments.
	 * @param Worksheet_Report_Projector                      $projector Projector.
	 * @param Canonical_Json                                  $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                   $clock Clock.
	 */
	public function __construct(
		private Assignment_Progress_Source_Query_Port_Interface $progress,
		private Worksheet_Report_Source_Query_Port_Interface $worksheets,
		private Official_Assessment_Source_Query_Port_Interface $assessments,
		private Worksheet_Report_Projector $projector,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * List worksheet reports.
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
			throw new InvalidArgumentException( 'Worksheet report page is invalid.' );
		}
		new Context_Scope( $context_type, $context_id, Context_Scope::OPERATION_REPORT, null, $context_revision );
		$filter ??= Report_Filter::defaults();

		$page = $this->progress->list_context(
			$actor_user_id,
			$context_type,
			$context_id,
			$context_revision,
			$cursor,
			$limit,
			$filter
		);
		if ( ! Report_Source_Validator::list_page( $page, $limit, self::MAXIMUM_CURSOR ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
		}

		$publications = array();
		foreach ( $page['items'] as $source ) {
			if ( ! is_array( $source['publication'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
			}
			$id   = $source['publication']['id'] ?? null;
			$hash = $source['publication']['hash'] ?? null;
			if ( ! is_string( $id ) || ! Public_Id::is_valid( $id ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
			}
			$publications[] = array(
				'id'   => $id,
				'hash' => $hash,
			);
		}

		$projection                = $this->projector->project(
			$page['items'],
			$this->worksheets->by_publications( $publications ),
			$this->assessments->by_official_reviews( $page['items'] )
		);
		$filter_payload            = array_merge(
			array(
				'lens'    => 'worksheet',
				'context' => array(
					'type'     => $context_type,
					'id'       => $context_id,
					'revision' => $context_revision,
				),
			),
			$filter->payload()
		);
		$report                    = array(
			'lens'                 => 'worksheet',
			'baseline_at_utc'      => $this->now(),
			'metric_contract'      => Worksheet_Report_Projector::METRIC_CONTRACT,
			'context'              => $filter_payload['context'],
			'filters'              => array(
				'applied'   => $filter->payload(),
				'available' => array_merge( array( 'lenses' => array( 'worksheet' ) ), $filter->available() ),
			),
			'denominator_contract' => Report_Denominator_Contract::payload(),
			'filter_hash'          => hash(
				'sha256',
				$this->canonical->encode(
					array(
						'schema_id'      => self::FILTER_SCHEMA,
						'schema_version' => self::FILTER_VERSION,
						'payload'        => $filter_payload,
					)
				)
			),
			'source_manifest_hash' => $projection['source_manifest_hash'],
			'items'                => $projection['items'],
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
			throw new InvalidArgumentException( 'Worksheet report clock is invalid.' );
		}
		return $value;
	}
}

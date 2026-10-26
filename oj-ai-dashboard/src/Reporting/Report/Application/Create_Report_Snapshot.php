<?php
/**
 * C45 immutable worksheet report snapshot use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Source_Validator;
use OJAI\Dashboard\Reporting\Report\Domain\Worksheet_Report_Projector;
use OJAI\Dashboard\Reporting\Report\Port\Assignment_Progress_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Worksheet_Report_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C45 immutable worksheet report snapshot use case.
 */
final class Create_Report_Snapshot {
	public const MAXIMUM_ROWS = 10000;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize report snapshot dependencies and state.
	 *
	 * @param Assignment_Progress_Source_Query_Port_Interface $progress Progress.
	 * @param Worksheet_Report_Source_Query_Port_Interface    $worksheets Worksheets.
	 * @param Official_Assessment_Source_Query_Port_Interface $assessments Assessments.
	 * @param Worksheet_Report_Projector                      $projector Projector.
	 * @param Report_Snapshot_Repository_Port_Interface       $repository Persistence boundary used by this operation.
	 * @param Report_Snapshot_Profile                         $profiles Profiles.
	 * @param Public_Id_Generator                             $ids Ids.
	 * @param Canonical_Json                                  $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                   $clock Clock.
	 */
	public function __construct(
		private Assignment_Progress_Source_Query_Port_Interface $progress,
		private Worksheet_Report_Source_Query_Port_Interface $worksheets,
		private Official_Assessment_Source_Query_Port_Interface $assessments,
		private Worksheet_Report_Projector $projector,
		private Report_Snapshot_Repository_Port_Interface $repository,
		private Report_Snapshot_Profile $profiles,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute report snapshot.
	 *
	 * @param Create_Report_Snapshot_Command $command Command.
	 * @return Create_Report_Snapshot_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Create_Report_Snapshot_Command $command ): Create_Report_Snapshot_Result {
		$receipt = $this->repository->find_command_receipt( $command->idempotency_storage_key(), $command->request_digest() );
		if ( $receipt instanceof Create_Report_Snapshot_Result ) {
			return $receipt;
		}

		$filter_profile = $this->profiles->filter(
			$command->context_type(),
			$command->context_id(),
			$command->context_revision(),
			$command->filter()
		);
		if ( ! hash_equals( $command->expected_filter_hash(), $filter_profile['hash'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::SOURCE_STALE );
		}

		try {
			$first = $this->collect( $command );
			if ( ! hash_equals( $command->expected_live_source_manifest_hash(), $first['first_page_source_manifest_hash'] ) ) {
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::SOURCE_STALE );
			}
			$second = $this->collect( $command );
			if (
				! hash_equals( $first['source_manifest_hash'], $second['source_manifest_hash'] )
				|| ! hash_equals( $first['items_hash'], $second['items_hash'] )
			) {
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::SOURCE_STALE );
			}
		} catch ( Report_Query_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Report_Query_Failure::SOURCE_STALE => Report_Snapshot_Failure::SOURCE_STALE,
				Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED => Report_Snapshot_Failure::SOURCE_SCHEMA_UNSUPPORTED,
				default => Report_Snapshot_Failure::STORED_DATA_INVALID,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( $reason );
		}

		$generated_at_utc = $this->now();
		$snapshot_id      = $this->id();
		$profiles         = $this->profiles->snapshot(
			$snapshot_id,
			$generated_at_utc,
			$command->context_type(),
			$command->context_id(),
			$command->context_revision(),
			$command->filter(),
			$filter_profile,
			array(
				'items'                => $first['items'],
				'source_manifest'      => $first['source_manifest'],
				'source_manifest_hash' => $first['source_manifest_hash'],
			)
		);

		return $this->repository->create(
			array_merge(
				array(
					'actor_user_id'          => $command->actor_user_id(),
					'audit_public_id'        => $this->id(),
					'command_id'             => $command->command_id(),
					'command_storage_key'    => $command->idempotency_storage_key(),
					'context_actor_relation' => $command->context_actor_relation(),
					'context_id'             => $command->context_id(),
					'context_revision'       => $command->context_revision(),
					'context_type'           => $command->context_type(),
					'correlation_id'         => $command->correlation_id(),
					'generated_at_utc'       => $generated_at_utc,
					'outbox_public_id'       => $this->id(),
					'request_digest'         => $command->request_digest(),
					'request_hash'           => $command->expected_request_hash(),
					'snapshot_public_id'     => $snapshot_id,
				),
				$profiles
			)
		);
	}

	/**
	 * Collect.
	 *
	 * @param Create_Report_Snapshot_Command $command Command.
	 * @return array{items:array<int,array<string,mixed>>,items_hash:string,source_manifest:array<string,mixed>,source_manifest_hash:string,first_page_source_manifest_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function collect( Create_Report_Snapshot_Command $command ): array {
		$cursor            = '';
		$seen_cursors      = array();
		$seen_publications = array();
		$items             = array();
		$entries           = array();
		$first_page_hash   = null;

		do {
			if ( isset( $seen_cursors[ $cursor ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORED_DATA_INVALID );
			}
			$seen_cursors[ $cursor ] = true;
			$page                    = $this->progress->list_context(
				$command->actor_user_id(),
				$command->context_type(),
				$command->context_id(),
				$command->context_revision(),
				$cursor,
				$command->source_page_size(),
				$command->filter()
			);
			if (
				! Report_Source_Validator::list_page( $page, $command->source_page_size(), Read_Worksheet_Reports::MAXIMUM_CURSOR )
				|| ( array() === $page['items'] && '' !== $page['next_cursor'] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORED_DATA_INVALID );
			}

			$publications = array();
			foreach ( $page['items'] as $source ) {
				if ( ! is_array( $source['publication'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORED_DATA_INVALID );
				}
				$id   = $source['publication']['id'] ?? null;
				$hash = $source['publication']['hash'] ?? null;
				if (
					! is_string( $id ) || ! Public_Id::is_valid( $id )
					|| ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
					|| isset( $seen_publications[ $id ] )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORED_DATA_INVALID );
				}
				$seen_publications[ $id ] = true;
				$publications[]           = array(
					'id'   => $id,
					'hash' => $hash,
				);
			}

			$projection        = $this->projector->project(
				$page['items'],
				$this->worksheets->by_publications( $publications ),
				$this->assessments->by_official_reviews( $page['items'] )
			);
			$first_page_hash ??= $projection['source_manifest_hash'];
			foreach ( $projection['items'] as $item ) {
				$items[] = $item;
				if ( count( $items ) > self::MAXIMUM_ROWS ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::TOO_LARGE );
				}
			}
			foreach ( $projection['source_manifest']['entries'] as $entry ) {
				$entries[] = $entry;
			}

			$next = $page['next_cursor'];
			if ( '' !== $next && isset( $seen_cursors[ $next ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORED_DATA_INVALID );
			}
			$cursor = $next;
		} while ( '' !== $cursor );

		$manifest      = array(
			'schema_id'      => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
			'schema_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION,
			'entries'        => $entries,
		);
		$manifest_hash = hash( 'sha256', $this->canonical->encode( $manifest ) );
		return array(
			'items'                           => $items,
			'items_hash'                      => hash( 'sha256', $this->canonical->encode( $items ) ),
			'source_manifest'                 => $manifest,
			'source_manifest_hash'            => $manifest_hash,
			'first_page_source_manifest_hash' => (string) $first_page_hash,
		);
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	private function id(): string {
		return $this->ids->create()->value();
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		try {
			$value = ( $this->clock )();
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot clock is invalid.' );
		}
		return $value;
	}
}

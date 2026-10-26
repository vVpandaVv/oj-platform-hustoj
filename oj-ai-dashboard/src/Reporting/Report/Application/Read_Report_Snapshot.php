<?php
/**
 * Q22 immutable report snapshot query with current source authorization.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use JsonException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Denominator_Contract;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Metrics;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Scope;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Report_Publication_Access_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q22 immutable report snapshot query with current source authorization.
 */
final class Read_Report_Snapshot {
	public const ACTION_READ = 'report.snapshot.read';

	/**
	 * Initialize report snapshot dependencies and state.
	 *
	 * @param Report_Snapshot_Query_Port_Interface          $snapshots Snapshots.
	 * @param Object_Authorization_Port_Interface           $objects Objects.
	 * @param Report_Snapshot_Profile                       $profiles Profiles.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 * @param Report_Publication_Access_Port_Interface|null $publications Publications.
	 */
	public function __construct(
		private Report_Snapshot_Query_Port_Interface $snapshots,
		private Object_Authorization_Port_Interface $objects,
		private Report_Snapshot_Profile $profiles,
		private Canonical_Json $canonical,
		private ?Report_Publication_Access_Port_Interface $publications = null
	) {
	}

	/**
	 * Scope.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return Report_Snapshot_Scope
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function scope( string $snapshot_id ): Report_Snapshot_Scope {
		$this->snapshot_id( $snapshot_id );
		$scope = $this->snapshots->scope( $snapshot_id );
		if ( null === $scope || 'ready' !== $scope->state() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::NOT_FOUND );
		}
		return $scope;
	}

	/**
	 * Read report snapshot.
	 *
	 * @param int                          $actor_user_id Authenticated actor from the access context.
	 * @param string                       $snapshot_id Snapshot id.
	 * @param Context_Authorization_Result $context Access or execution context for this operation.
	 * @return Read_Report_Snapshot_Result
	 */
	public function read(
		int $actor_user_id,
		string $snapshot_id,
		Context_Authorization_Result $context
	): Read_Report_Snapshot_Result {
		$validated = $this->authorize_and_validate( $actor_user_id, $snapshot_id, $context );

		return new Read_Report_Snapshot_Result(
			$snapshot_id,
			'ready',
			$validated['snapshot_hash'],
			$validated['filter'],
			$validated['filter_hash'],
			$validated['source_manifest_hash'],
			$validated['projection'],
			$validated['projection_hash'],
			$validated['row_count'],
			$validated['generated_at_utc'],
			$validated['authorized_worksheet_count']
		);
	}

	/**
	 * Read source manifest.
	 *
	 * @param int                          $actor_user_id Authenticated actor from the access context.
	 * @param string                       $snapshot_id Snapshot id.
	 * @param Context_Authorization_Result $context Access or execution context for this operation.
	 * @return Read_Report_Source_Manifest_Result
	 */
	public function read_source_manifest(
		int $actor_user_id,
		string $snapshot_id,
		Context_Authorization_Result $context
	): Read_Report_Source_Manifest_Result {
		$validated = $this->authorize_and_validate( $actor_user_id, $snapshot_id, $context );

		return new Read_Report_Source_Manifest_Result(
			$snapshot_id,
			$validated['snapshot_hash'],
			$validated['context_type'],
			$validated['context_id'],
			$validated['context_revision'],
			$validated['generated_at_utc'],
			$validated['filter_hash'],
			$validated['source_manifest'],
			$validated['source_manifest_json'],
			$validated['source_manifest_hash'],
			$validated['projection_hash'],
			$validated['row_count'],
			$validated['source_count'],
			$validated['authorized_worksheet_count']
		);
	}

	/**
	 * Authorize and validate.
	 *
	 * @param int                          $actor_user_id Authenticated actor from the access context.
	 * @param string                       $snapshot_id Snapshot id.
	 * @param Context_Authorization_Result $context Access or execution context for this operation.
	 * @return array{snapshot_hash:string,filter:array<string,mixed>,filter_hash:string,source_manifest:array<string,mixed>,source_manifest_json:string,source_manifest_hash:string,source_count:int,projection:array<string,mixed>,projection_hash:string,row_count:int,generated_at_utc:string,context_type:string,context_id:string,context_revision:string,worksheet_ids:array<int,string>,authorized_worksheet_count:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorize_and_validate(
		int $actor_user_id,
		string $snapshot_id,
		Context_Authorization_Result $context
	): array {
		if ( $actor_user_id < 1 || ! $context->is_allowed() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED );
		}
		$this->snapshot_id( $snapshot_id );
		$record = $this->snapshots->find_record( $snapshot_id );
		if ( null === $record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::NOT_FOUND );
		}

		$validated = $this->validate_record( $snapshot_id, $record );
		if (
			$validated['context_type'] !== $context->type()
			|| $validated['context_id'] !== $context->id()
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED );
		}

		if ( null !== $this->publications ) {
			$sources = array();
			foreach ( $validated['projection']['items'] as $item ) {
				$sources[] = array(
					'worksheet_id'     => $this->public_id( $item['worksheet']['id'] ),
					'publication_id'   => $this->public_id( $item['publication']['id'] ),
					'publication_hash' => $this->hash_value( $item['publication']['hash'] ),
				);
			}
			try {
				$decision = $this->publications->authorize( $actor_user_id, $context, $sources );
			} catch ( Throwable ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE );
			}
			if ( $decision->is_denied() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED );
			}
			if ( ! $decision->is_allowed() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE );
			}
			$validated['authorized_worksheet_count'] = count( $validated['worksheet_ids'] );
			return $validated;
		}

		$authorized = 0;
		foreach ( $validated['worksheet_ids'] as $worksheet_id ) {
			try {
				$decision = $this->objects->authorize(
					$actor_user_id,
					self::ACTION_READ,
					$context,
					new Object_Scope( Object_Scope::TYPE_WORKSHEET, $worksheet_id, 'view' )
				);
			} catch ( Throwable ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE );
			}
			if ( $decision->is_denied() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED );
			}
			if ( ! $decision->is_allowed() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::SOURCE_ACCESS_UNAVAILABLE );
			}
			++$authorized;
		}

		$validated['authorized_worksheet_count'] = $authorized;
		return $validated;
	}

	/**
	 * Validate record.
	 *
	 * @param string              $snapshot_id Snapshot id.
	 * @param array<string,mixed> $record Record.
	 * @return array{snapshot_hash:string,filter:array<string,mixed>,filter_hash:string,source_manifest:array<string,mixed>,source_manifest_json:string,source_manifest_hash:string,source_count:int,projection:array<string,mixed>,projection_hash:string,row_count:int,generated_at_utc:string,context_type:string,context_id:string,context_revision:string,worksheet_ids:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_record( string $snapshot_id, array $record ): array {
		$this->exact(
			$record,
			array(
				'public_id',
				'report_type',
				'subject_type',
				'subject_public_id',
				'state',
				'context_type',
				'context_id',
				'context_revision',
				'filter_schema',
				'filter_version',
				'filter_json',
				'filter_hash',
				'source_watermark',
				'source_manifest_schema',
				'source_manifest_version',
				'source_manifest_json',
				'source_manifest_hash',
				'projection_schema',
				'projection_version',
				'projection_json',
				'projection_hash',
				'generated_by_user_id',
				'generated_at_utc',
				'expires_at_utc',
			)
		);
		if (
			$snapshot_id !== ( $record['public_id'] ?? null )
			|| 'worksheet_progress' !== ( $record['report_type'] ?? null )
			|| 'context' !== ( $record['subject_type'] ?? null )
			|| null !== ( $record['subject_public_id'] ?? null )
			|| 'ready' !== ( $record['state'] ?? null )
		) {
			$this->stored_invalid();
		}
		$context_type     = $this->required_string( $record['context_type'] ?? null );
		$context_id       = $this->required_string( $record['context_id'] ?? null );
		$context_revision = $this->required_string( $record['context_revision'] ?? null );
		$generated_at     = $this->utc( $record['generated_at_utc'] ?? null );
		$this->positive_integer( $record['generated_by_user_id'] ?? null );
		if ( null !== ( $record['expires_at_utc'] ?? null ) ) {
			$this->utc( $record['expires_at_utc'] );
		}

		if (
			Report_Filter::SCHEMA_ID !== ( $record['filter_schema'] ?? null )
			|| Report_Snapshot_Profile::FILTER_DATABASE_VERSION !== $this->database_version( $record['filter_version'] ?? null )
			|| Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA !== ( $record['source_manifest_schema'] ?? null )
			|| Report_Snapshot_Profile::SOURCE_MANIFEST_DATABASE_VERSION !== $this->database_version( $record['source_manifest_version'] ?? null )
			|| Report_Snapshot_Profile::PROJECTION_SCHEMA !== ( $record['projection_schema'] ?? null )
			|| Report_Snapshot_Profile::PROJECTION_DATABASE_VERSION !== $this->database_version( $record['projection_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED );
		}

		$filter_hash     = $this->hash_value( $record['filter_hash'] ?? null );
		$source_hash     = $this->hash_value( $record['source_manifest_hash'] ?? null );
		$projection_hash = $this->hash_value( $record['projection_hash'] ?? null );
		if ( ! hash_equals( $source_hash, $this->hash_value( $record['source_watermark'] ?? null ) ) ) {
			$this->stored_invalid();
		}
		$filter     = $this->decode( $record['filter_json'] ?? null, $filter_hash );
		$manifest   = $this->decode( $record['source_manifest_json'] ?? null, $source_hash );
		$projection = $this->decode( $record['projection_json'] ?? null, $projection_hash );

		$report_filter    = $this->validate_filter( $filter, $context_type, $context_id, $context_revision, $filter_hash );
		$manifest_entries = $this->validate_manifest( $manifest );
		$worksheet_ids    = $this->validate_projection(
			$projection,
			$manifest_entries,
			$report_filter,
			$context_type,
			$context_id,
			$context_revision,
			$generated_at,
			$filter_hash,
			$source_hash
		);

		$identity_json = $this->canonical->encode(
			array(
				'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
				'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
				'snapshot_id'          => $snapshot_id,
				'filter_hash'          => $filter_hash,
				'source_manifest_hash' => $source_hash,
				'projection_hash'      => $projection_hash,
			)
		);

		return array(
			'snapshot_hash'        => hash( 'sha256', $identity_json ),
			'filter'               => $filter,
			'filter_hash'          => $filter_hash,
			'source_manifest'      => $manifest,
			'source_manifest_json' => (string) $record['source_manifest_json'],
			'source_manifest_hash' => $source_hash,
			'source_count'         => count( $manifest_entries ) * 3,
			'projection'           => $projection,
			'projection_hash'      => $projection_hash,
			'row_count'            => count( $manifest_entries ),
			'generated_at_utc'     => $generated_at,
			'context_type'         => $context_type,
			'context_id'           => $context_id,
			'context_revision'     => $context_revision,
			'worksheet_ids'        => $worksheet_ids,
		);
	}

	/**
	 * Validate filter.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $context_type Context type.
	 * @param string              $context_id Context id.
	 * @param string              $context_revision Context revision.
	 * @param string              $stored_hash Stored hash.
	 * @return Report_Filter
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_filter( array $document, string $context_type, string $context_id, string $context_revision, string $stored_hash ): Report_Filter {
		$this->exact( $document, array( 'schema_id', 'schema_version', 'payload' ) );
		$payload = $document['payload'] ?? null;
		if (
			Report_Filter::SCHEMA_ID !== ( $document['schema_id'] ?? null )
			|| Report_Filter::VERSION !== ( $document['schema_version'] ?? null )
			|| ! is_array( $payload ) || array_is_list( $payload )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED );
		}
		$this->exact( $payload, array( 'lens', 'context', 'assignment_states', 'submission_states', 'timezone', 'period' ) );
		$stored_context = $payload['context'] ?? null;
		if ( ! is_array( $stored_context ) || array_is_list( $stored_context ) ) {
			$this->stored_invalid();
		}
		$this->exact( $stored_context, array( 'type', 'id', 'revision' ) );
		if (
			'worksheet' !== ( $payload['lens'] ?? null )
			|| $context_type !== ( $stored_context['type'] ?? null )
			|| $context_id !== ( $stored_context['id'] ?? null )
			|| $context_revision !== ( $stored_context['revision'] ?? null )
			|| ! is_array( $payload['assignment_states'] ?? null )
			|| ! is_array( $payload['submission_states'] ?? null )
			|| ! is_string( $payload['timezone'] ?? null )
		) {
			$this->stored_invalid();
		}
		$period = $payload['period'] ?? null;
		$from   = null;
		$to     = null;
		if ( null !== $period ) {
			if ( ! is_array( $period ) || array_is_list( $period ) ) {
				$this->stored_invalid();
			}
			$this->exact( $period, array( 'from', 'to', 'boundary', 'from_utc', 'to_utc' ) );
			if ( ! is_string( $period['from'] ?? null ) || ! is_string( $period['to'] ?? null ) ) {
				$this->stored_invalid();
			}
			$from = $period['from'];
			$to   = $period['to'];
		}

		try {
			$filter  = Report_Filter::from_query( $from, $to, $payload['timezone'], $payload['assignment_states'], $payload['submission_states'] );
			$rebuilt = $this->profiles->filter( $context_type, $context_id, $context_revision, $filter );
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
		if ( ! hash_equals( $stored_hash, $rebuilt['hash'] ) || ! hash_equals( $this->canonical->encode( $document ), $rebuilt['json'] ) ) {
			$this->stored_invalid();
		}
		return $filter;
	}

	/**
	 * Validate manifest.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array<int,array{publication_id:string,sources:array<string,array{schema_id:string,schema_version:string,hash:string}>}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_manifest( array $manifest ): array {
		$this->exact( $manifest, array( 'schema_id', 'schema_version', 'entries' ) );
		if (
			Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA !== ( $manifest['schema_id'] ?? null )
			|| Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION !== ( $manifest['schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED );
		}
		$entries = $manifest['entries'] ?? null;
		if ( ! is_array( $entries ) || ! array_is_list( $entries ) || count( $entries ) > Create_Report_Snapshot::MAXIMUM_ROWS ) {
			$this->stored_invalid();
		}
		$expected_sources = array(
			'Worksheet'            => 'oj_ai.worksheet_report_source',
			'AssignmentSubmission' => 'oj_ai.assignment_progress_source',
			'Assessment'           => 'oj_ai.official_assessment_source',
		);
		$seen             = array();
		$validated        = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || array_is_list( $entry ) ) {
				$this->stored_invalid();
			}
			$this->exact( $entry, array( 'publication_id', 'sources' ) );
			$publication_id = $this->public_id( $entry['publication_id'] ?? null );
			$sources        = $entry['sources'] ?? null;
			if ( isset( $seen[ $publication_id ] ) || ! is_array( $sources ) || ! array_is_list( $sources ) || count( $sources ) !== count( $expected_sources ) ) {
				$this->stored_invalid();
			}
			$seen[ $publication_id ] = true;
			$source_map              = array();
			$position                = 0;
			foreach ( $expected_sources as $owner => $schema ) {
				$source = $sources[ $position++ ] ?? null;
				if ( ! is_array( $source ) || array_is_list( $source ) ) {
					$this->stored_invalid();
				}
				$this->exact( $source, array( 'owner', 'schema_id', 'schema_version', 'hash' ) );
				if ( $owner !== ( $source['owner'] ?? null ) || $schema !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED );
				}
				$source_map[ $owner ] = array(
					'schema_id'      => $schema,
					'schema_version' => '1.0.0',
					'hash'           => $this->hash_value( $source['hash'] ?? null ),
				);
			}
			$validated[] = array(
				'publication_id' => $publication_id,
				'sources'        => $source_map,
			);
		}
		return $validated;
	}

	/**
	 * Validate projection.
	 *
	 * @param array<string,mixed>                                                                                                     $projection Projection.
	 * @param array<int,array{publication_id:string,sources:array<string,array{schema_id:string,schema_version:string,hash:string}>}> $entries Entries.
	 * @param Report_Filter                                                                                                           $filter Filter.
	 * @param string                                                                                                                  $context_type Context type.
	 * @param string                                                                                                                  $context_id Context id.
	 * @param string                                                                                                                  $context_revision Context revision.
	 * @param string                                                                                                                  $generated_at Generated at.
	 * @param string                                                                                                                  $filter_hash Filter hash.
	 * @param string                                                                                                                  $source_hash Source hash.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_projection(
		array $projection,
		array $entries,
		Report_Filter $filter,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $generated_at,
		string $filter_hash,
		string $source_hash
	): array {
		$this->exact(
			$projection,
			array(
				'schema_id',
				'schema_version',
				'lens',
				'baseline_at_utc',
				'metric_contract',
				'context',
				'filters',
				'denominator_contract',
				'filter_hash',
				'source_manifest_hash',
				'items',
			)
		);
		if (
			Report_Snapshot_Profile::PROJECTION_SCHEMA !== ( $projection['schema_id'] ?? null )
			|| Report_Snapshot_Profile::PROJECTION_VERSION !== ( $projection['schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED );
		}
		$context = $projection['context'] ?? null;
		$filters = $projection['filters'] ?? null;
		$items   = $projection['items'] ?? null;
		if ( ! is_array( $context ) || array_is_list( $context ) || ! is_array( $filters ) || array_is_list( $filters ) || ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->stored_invalid();
		}
		$this->exact( $context, array( 'type', 'id', 'revision' ) );
		$this->exact( $filters, array( 'applied', 'available' ) );
		if (
			'worksheet' !== ( $projection['lens'] ?? null )
			|| $generated_at !== ( $projection['baseline_at_utc'] ?? null )
			|| Report_Metrics::CONTRACT !== ( $projection['metric_contract'] ?? null )
			|| ! $this->same_document(
				$context,
				array(
					'type'     => $context_type,
					'id'       => $context_id,
					'revision' => $context_revision,
				)
			)
			|| ! hash_equals( $filter_hash, (string) ( $projection['filter_hash'] ?? '' ) )
			|| ! hash_equals( $source_hash, (string) ( $projection['source_manifest_hash'] ?? '' ) )
			|| count( $items ) !== count( $entries )
			|| ! $this->same_document( $filters['applied'], $filter->payload() )
			|| ! $this->same_document( $filters['available'], array_merge( array( 'lenses' => array( 'worksheet' ) ), $filter->available() ) )
			|| ! $this->same_document( $projection['denominator_contract'] ?? null, Report_Denominator_Contract::payload() )
		) {
			$this->stored_invalid();
		}

		$worksheet_ids = array();
		foreach ( $items as $position => $item ) {
			$worksheet_id                   = $this->validate_item( $item, $entries[ $position ] );
			$worksheet_ids[ $worksheet_id ] = true;
		}
		return array_keys( $worksheet_ids );
	}

	/**
	 * Validate item.
	 *
	 * @param mixed                                                                                                        $value Value.
	 * @param array{publication_id:string,sources:array<string,array{schema_id:string,schema_version:string,hash:string}>} $entry Entry.
	 * @return string
	 */
	private function validate_item( mixed $value, array $entry ): string {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact( $value, array( 'worksheet', 'publication', 'rubric', 'progress', 'updated_at_utc', 'source' ) );
		$worksheet   = $this->object_value( $value['worksheet'] ?? null, array( 'id', 'title', 'state', 'subject_label' ) );
		$publication = $this->object_value( $value['publication'] ?? null, array( 'id', 'hash', 'number', 'state', 'published_at_utc' ) );
		$rubric      = $this->object_value( $value['rubric'] ?? null, array( 'revision_id', 'content_hash', 'total_points', 'criterion_count' ) );
		$progress    = $this->object_value( $value['progress'] ?? null, array( 'assignment_count', 'target_count', 'submission_rate', 'assessment_rate', 'release_rate', 'average_score', 'review_required_count' ) );
		$source      = $this->object_value( $value['source'] ?? null, array( 'worksheet', 'assignment_progress', 'official_assessment' ) );

		$worksheet_id = $this->public_id( $worksheet['id'] ?? null );
		$this->required_text( $worksheet['title'] ?? null );
		$this->required_string( $worksheet['state'] ?? null );
		if ( null !== ( $worksheet['subject_label'] ?? null ) ) {
			$this->required_text( $worksheet['subject_label'] );
		}
		if ( $entry['publication_id'] !== $this->public_id( $publication['id'] ?? null ) ) {
			$this->stored_invalid();
		}
		$this->hash_value( $publication['hash'] ?? null );
		$this->positive_integer( $publication['number'] ?? null );
		$this->required_string( $publication['state'] ?? null );
		$this->utc( $publication['published_at_utc'] ?? null );
		$this->public_id( $rubric['revision_id'] ?? null );
		$this->hash_value( $rubric['content_hash'] ?? null );
		if ( ! is_string( $rubric['total_points'] ?? null ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $rubric['total_points'] ) ) {
			$this->stored_invalid();
		}
		$this->non_negative_integer( $rubric['criterion_count'] ?? null );
		$this->utc( $value['updated_at_utc'] ?? null );

		$assignment_count = $this->non_negative_integer( $progress['assignment_count'] ?? null );
		$target_count     = $this->non_negative_integer( $progress['target_count'] ?? null );
		$submission       = $this->ratio( $progress['submission_rate'] ?? null );
		$assessment       = $this->ratio( $progress['assessment_rate'] ?? null );
		$release          = $this->ratio( $progress['release_rate'] ?? null );
		$review_required  = $this->non_negative_integer( $progress['review_required_count'] ?? null );
		$average_n        = $this->average( $progress['average_score'] ?? null );
		if (
			$assignment_count < 0 || $target_count !== $submission['denominator']
			|| $submission['numerator'] !== $assessment['denominator']
			|| $assessment['numerator'] !== $release['denominator']
			|| $review_required !== $submission['numerator'] - $assessment['numerator']
			|| $average_n > $assessment['numerator']
		) {
			$this->stored_invalid();
		}

		$this->source_link( $source['worksheet'] ?? null, 'oj_ai.worksheet_report_source', $entry['sources']['Worksheet']['hash'] );
		$this->source_link( $source['assignment_progress'] ?? null, 'oj_ai.assignment_progress_source', $entry['sources']['AssignmentSubmission']['hash'] );
		$this->source_link( $source['official_assessment'] ?? null, 'oj_ai.official_assessment_source', $entry['sources']['Assessment']['hash'] );
		return $worksheet_id;
	}

	/**
	 * Ratio.
	 *
	 * @param mixed $value Value.
	 * @return array{numerator:int,denominator:int}
	 */
	private function ratio( mixed $value ): array {
		$ratio       = $this->object_value( $value, array( 'numerator', 'denominator', 'value' ) );
		$numerator   = $this->non_negative_integer( $ratio['numerator'] ?? null );
		$denominator = $this->non_negative_integer( $ratio['denominator'] ?? null );
		if ( $numerator > $denominator ) {
			$this->stored_invalid();
		}
		$expected = ( new Report_Metrics() )->ratio( $numerator, $denominator );
		if ( ! $this->same_document( $ratio, $expected ) ) {
			$this->stored_invalid();
		}
		return array(
			'numerator'   => $numerator,
			'denominator' => $denominator,
		);
	}

	/**
	 * Average.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function average( mixed $value ): int {
		$average = $this->object_value( $value, array( 'value', 'n', 'suppressed', 'reason' ) );
		$n       = $this->non_negative_integer( $average['n'] ?? null );
		if ( ! is_bool( $average['suppressed'] ?? null ) ) {
			$this->stored_invalid();
		}
		if ( 0 === $n && ! $this->same_document(
			$average,
			array(
				'value'      => null,
				'n'          => 0,
				'suppressed' => false,
				'reason'     => 'no_data',
			)
		) ) {
			$this->stored_invalid();
		}
		if ( $n > 0 && $n < Report_Metrics::MINIMUM_AGGREGATE_SIZE && ! $this->same_document(
			$average,
			array(
				'value'      => null,
				'n'          => $n,
				'suppressed' => true,
				'reason'     => 'small_group',
			)
		) ) {
			$this->stored_invalid();
		}
		if ( $n >= Report_Metrics::MINIMUM_AGGREGATE_SIZE ) {
			if ( true === $average['suppressed'] || null !== ( $average['reason'] ?? null ) || ! is_string( $average['value'] ?? null ) || 1 !== preg_match( '/^(?:100\.0|[0-9]{1,2}\.[0-9])$/D', $average['value'] ) ) {
				$this->stored_invalid();
			}
		}
		return $n;
	}

	/**
	 * Source link.
	 *
	 * @param mixed  $value Value.
	 * @param string $schema Schema.
	 * @param string $expected_hash Expected hash.
	 * @return void
	 */
	private function source_link( mixed $value, string $schema, string $expected_hash ): void {
		$source = $this->object_value( $value, array( 'schema_id', 'schema_version', 'hash' ) );
		if ( $schema !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) || ! hash_equals( $expected_hash, $this->hash_value( $source['hash'] ?? null ) ) ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Object value.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $keys Keys.
	 * @return array<string,mixed>
	 */
	private function object_value( mixed $value, array $keys ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$this->stored_invalid();
		}
		$this->exact( $value, $keys );
		return $value;
	}

	/**
	 * Same document.
	 *
	 * @param mixed $left Left.
	 * @param mixed $right Right.
	 * @return bool
	 */
	private function same_document( mixed $left, mixed $right ): bool {
		try {
			return hash_equals( $this->canonical->encode( $left ), $this->canonical->encode( $right ) );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Decode report snapshot.
	 *
	 * @param mixed  $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode( mixed $json, string $hash ): array {
		if ( ! is_string( $json ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $keys Keys.
	 * @return void
	 */
	private function exact( array $value, array $keys ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		if ( $actual !== $keys ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Snapshot id.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot_id( string $value ): void {
		try {
			new Public_Id( $value );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::NOT_FOUND );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Required string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_string( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Required text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_text( mixed $value ): string {
		$value = $this->required_string( $value );
		try {
			return $this->canonical->text( $value );
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Database version.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function database_version( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
	}
}

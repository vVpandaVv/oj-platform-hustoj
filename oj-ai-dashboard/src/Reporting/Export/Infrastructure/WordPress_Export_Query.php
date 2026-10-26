<?php
/**
 * WordPress export query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ResultPdf\Domain\Result_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress export query.
 */
final class WordPress_Export_Query implements Export_Query_Port_Interface {
	private const TABLE_JOBS      = 'oj_ai_export_jobs';
	private const TABLE_PDF       = 'oj_ai_pdf_artifacts';
	private const TABLE_FILES     = 'oj_ai_private_files';
	private const PACKAGE_MIME    = 'application/vnd.oj.ai-worksheet+zip';
	private const DIRECT_PROFILES = array(
		'worksheet_package'   => array(
			'purpose'   => 'worksheet_package',
			'mime_type' => self::PACKAGE_MIME,
		),
		'report_csv'          => array(
			'purpose'   => 'report_csv',
			'mime_type' => 'text/csv',
		),
		'report_json_package' => array(
			'purpose'   => 'report_json_package',
			'mime_type' => 'application/zip',
		),
		'submission_bundle'   => array(
			'purpose'   => 'submission_bundle',
			'mime_type' => 'application/zip',
		),
	);
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress export query dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Find job for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $job_id Job id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_job_for_actor( int $actor_user_id, string $job_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Export query actor is invalid.' );
		}
		new Public_Id( $job_id );
		$sql = 'SELECT j.public_id, j.state, j.artifact_kind, j.source_type, j.source_public_id, j.source_hash, j.print_profile_version, '
			. 'j.context_type, j.context_id, j.context_revision, j.request_contract_version, j.request_snapshot_schema, '
			. 'j.request_snapshot_version, j.request_snapshot_json, j.request_snapshot_hash, j.request_hash, '
			. 'j.queued_at_utc, j.started_at_utc, j.finished_at_utc, j.error_code, j.result_file_public_id, '
			. 'p.public_id AS pdf_artifact_public_id, p.file_public_id AS pdf_file_public_id, p.expires_at_utc AS pdf_expires_at_utc, '
			. 'f.public_id AS file_public_id, f.owner_type, f.owner_public_id, f.purpose, f.mime_type, f.state AS file_state, '
			. 'f.scan_state, f.expires_at_utc AS file_expires_at_utc FROM ' . self::TABLE_JOBS . ' j '
			. 'LEFT JOIN ' . self::TABLE_PDF . ' p ON p.export_job_id = j.id '
			. 'LEFT JOIN ' . self::TABLE_FILES . ' f ON f.public_id = j.result_file_public_id '
			. 'WHERE j.public_id = %s AND j.requested_by_user_id = %d AND ( '
			. '(j.source_type = %s AND j.artifact_kind IN (%s,%s)) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind IN (%s,%s,%s)) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) ) LIMIT 1';
		$row = $this->row(
			$this->prepare(
				$sql,
				array(
					$job_id,
					$actor_user_id,
					'worksheet_revision',
					'worksheet_pdf',
					'worksheet_package',
					'submission_revision',
					'submission_pdf',
					'worksheet_publication',
					'worksheet_pdf',
					'submission_release',
					'result_pdf',
					'report_snapshot',
					'report_csv',
					'report_pdf',
					'report_json_package',
					'assignment',
					'submission_bundle',
				)
			)
		);
		return null === $row ? null : $this->normalize_job( $row );
	}

	/**
	 * Find artifact for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $artifact_id Artifact id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_artifact_for_actor( int $actor_user_id, string $artifact_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Export artifact actor is invalid.' );
		}
		new Public_Id( $artifact_id );
		$pdf_sql = 'SELECT j.artifact_kind, j.source_type, j.source_public_id, j.source_hash, j.print_profile_version, '
			. 'j.context_type, j.context_id, j.context_revision, j.request_contract_version, j.request_snapshot_schema, '
			. 'j.request_snapshot_version, j.request_snapshot_json, j.request_snapshot_hash, j.request_hash, '
			. 'j.state AS job_state, j.result_file_public_id, a.public_id AS artifact_public_id, a.state AS artifact_state, '
			. 'a.source_type AS artifact_source_type, a.source_public_id AS artifact_source_public_id, a.source_hash AS artifact_source_hash, '
			. 'a.expires_at_utc, a.page_count, j.public_id AS job_public_id, f.public_id AS file_public_id, f.owner_type, f.owner_public_id, '
			. 'f.purpose, f.storage_key, f.original_name, f.mime_type, f.byte_size, f.sha256, f.state AS file_state, f.scan_state, '
			. 'f.expires_at_utc AS file_expires_at_utc FROM ' . self::TABLE_PDF . ' a '
			. 'INNER JOIN ' . self::TABLE_JOBS . ' j ON j.id = a.export_job_id '
			. 'INNER JOIN ' . self::TABLE_FILES . ' f ON f.public_id = a.file_public_id '
			. 'WHERE a.public_id = %s AND j.requested_by_user_id = %d '
			. 'AND a.source_type = j.source_type AND a.source_public_id = j.source_public_id AND a.source_hash = j.source_hash AND ( '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR (j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR (j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) ) LIMIT 1';
		$row     = $this->row(
			$this->prepare(
				$pdf_sql,
				array(
					$artifact_id,
					$actor_user_id,
					'worksheet_revision',
					'worksheet_pdf',
					'submission_revision',
					'submission_pdf',
					'worksheet_publication',
					'worksheet_pdf',
					'submission_release',
					'result_pdf',
					'report_snapshot',
					'report_pdf',
				)
			)
		);
		if ( null !== $row ) {
			return $this->normalize_artifact( $row );
		}
		$package_sql = 'SELECT j.artifact_kind, j.source_type, j.source_public_id, j.source_hash, j.print_profile_version, '
			. 'j.context_type, j.context_id, j.context_revision, j.request_contract_version, j.request_snapshot_schema, '
			. 'j.request_snapshot_version, j.request_snapshot_json, j.request_snapshot_hash, j.request_hash, '
			. 'j.state AS job_state, j.result_file_public_id, f.public_id AS artifact_public_id, j.state AS artifact_state, '
			. 'NULL AS artifact_source_type, NULL AS artifact_source_public_id, NULL AS artifact_source_hash, '
			. 'f.expires_at_utc, NULL AS page_count, j.public_id AS job_public_id, f.public_id AS file_public_id, f.owner_type, f.owner_public_id, '
			. 'f.purpose, f.storage_key, f.original_name, f.mime_type, f.byte_size, f.sha256, f.state AS file_state, f.scan_state, '
			. 'f.expires_at_utc AS file_expires_at_utc FROM ' . self::TABLE_JOBS . ' j '
			. 'INNER JOIN ' . self::TABLE_FILES . ' f ON f.public_id = j.result_file_public_id '
			. 'WHERE f.public_id = %s AND j.requested_by_user_id = %d AND ( '
			. '(j.source_type = %s AND j.artifact_kind = %s) OR '
			. '(j.source_type = %s AND j.artifact_kind IN (%s,%s)) OR '
			. '(j.source_type = %s AND j.artifact_kind = %s) ) LIMIT 1';
		$row         = $this->row(
			$this->prepare(
				$package_sql,
				array(
					$artifact_id,
					$actor_user_id,
					'worksheet_revision',
					'worksheet_package',
					'report_snapshot',
					'report_csv',
					'report_json_package',
					'assignment',
					'submission_bundle',
				)
			)
		);
		return null === $row ? null : $this->normalize_artifact( $row );
	}

	/**
	 * Normalize job.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_job( array $row ): array {
		new Public_Id( (string) $row['public_id'] );
		new Public_Id( (string) $row['source_public_id'] );
		$this->hash( (string) $row['source_hash'] );
		$kind        = (string) ( $row['artifact_kind'] ?? '' );
		$state       = (string) ( $row['state'] ?? '' );
		$source_type = (string) ( $row['source_type'] ?? '' );
		$valid_pair  = ( 'worksheet_revision' === $source_type && in_array( $kind, array( 'worksheet_pdf', 'worksheet_package' ), true ) )
			|| ( 'submission_revision' === $source_type && 'submission_pdf' === $kind )
			|| ( 'worksheet_publication' === $source_type && 'worksheet_pdf' === $kind )
			|| ( 'submission_release' === $source_type && 'result_pdf' === $kind )
			|| ( 'report_snapshot' === $source_type && in_array( $kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) )
			|| ( 'assignment' === $source_type && 'submission_bundle' === $kind );
		if ( ! $valid_pair
			|| ! in_array( $state, array( 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$artifact_id = null;
		$expires     = null;
		if ( 'ready' === $state ) {
			$file_id = is_string( $row['result_file_public_id'] ?? null ) ? $row['result_file_public_id'] : '';
			new Public_Id( $file_id );
			if ( ! hash_equals( $file_id, (string) ( $row['file_public_id'] ?? '' ) ) || 'ready' !== ( $row['file_state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			if ( in_array( $kind, array( 'worksheet_pdf', 'submission_pdf', 'result_pdf', 'report_pdf' ), true ) ) {
				$artifact_id = is_string( $row['pdf_artifact_public_id'] ?? null ) ? $row['pdf_artifact_public_id'] : '';
				new Public_Id( $artifact_id );
				if ( ! hash_equals( $file_id, (string) ( $row['pdf_file_public_id'] ?? '' ) ) || 'pdf_artifact' !== ( $row['owner_type'] ?? null )
					|| ! hash_equals( $artifact_id, (string) ( $row['owner_public_id'] ?? '' ) ) || 'generated_pdf' !== ( $row['purpose'] ?? null )
					|| 'application/pdf' !== ( $row['mime_type'] ?? null ) || ! hash_equals( (string) $row['pdf_expires_at_utc'], (string) $row['file_expires_at_utc'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
				}
				$expires = (string) $row['pdf_expires_at_utc'];
			} else {
				$artifact_id = $file_id;
				$profile     = self::DIRECT_PROFILES[ $kind ];
				if (
					'export_job' !== ( $row['owner_type'] ?? null )
					|| ! hash_equals( (string) $row['public_id'], (string) ( $row['owner_public_id'] ?? '' ) )
					|| $profile['purpose'] !== ( $row['purpose'] ?? null )
					|| $profile['mime_type'] !== ( $row['mime_type'] ?? null )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
				}
				$expires = (string) $row['file_expires_at_utc'];
			}
			$this->utc( $expires );
		}
		$authorization = $this->authorization( $row, $source_type, $kind );
		return array(
			'job_id'          => (string) $row['public_id'],
			'state'           => $state,
			'artifact_kind'   => $kind,
			'source_type'     => $source_type,
			'source_id'       => (string) $row['source_public_id'],
			'source_hash'     => (string) $row['source_hash'],
			'profile_version' => (string) $row['print_profile_version'],
			'queued_at_utc'   => (string) $row['queued_at_utc'],
			'started_at_utc'  => $row['started_at_utc'],
			'finished_at_utc' => $row['finished_at_utc'],
			'error_code'      => $row['error_code'],
			'artifact_id'     => $artifact_id,
			'expires_at_utc'  => $expires,
			'authorization'   => $authorization,
		);
	}

	/**
	 * Normalize artifact.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_artifact( array $row ): array {
		foreach ( array( 'artifact_public_id', 'source_public_id', 'job_public_id', 'file_public_id' ) as $key ) {
			new Public_Id( (string) $row[ $key ] );
		}
		$this->hash( (string) $row['source_hash'] );
		$this->hash( (string) $row['sha256'] );
		$kind        = (string) ( $row['artifact_kind'] ?? '' );
		$source_type = (string) ( $row['source_type'] ?? '' );
		$valid_pair  = ( 'worksheet_revision' === $source_type && in_array( $kind, array( 'worksheet_pdf', 'worksheet_package' ), true ) )
			|| ( 'submission_revision' === $source_type && 'submission_pdf' === $kind )
			|| ( 'worksheet_publication' === $source_type && 'worksheet_pdf' === $kind )
			|| ( 'submission_release' === $source_type && 'result_pdf' === $kind )
			|| ( 'report_snapshot' === $source_type && in_array( $kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) )
			|| ( 'assignment' === $source_type && 'submission_bundle' === $kind );
		if ( ! $valid_pair || 'ready' !== ( $row['job_state'] ?? null ) || 'ready' !== ( $row['artifact_state'] ?? null )
			|| 'ready' !== ( $row['file_state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null )
			|| ! hash_equals( (string) ( $row['result_file_public_id'] ?? '' ), (string) $row['file_public_id'] )
			|| ( ! in_array( $kind, array( 'worksheet_pdf', 'submission_pdf', 'result_pdf', 'report_pdf' ), true )
				&& ! hash_equals( (string) $row['artifact_public_id'], (string) $row['file_public_id'] ) )
			|| ! hash_equals( (string) $row['expires_at_utc'], (string) $row['file_expires_at_utc'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		if ( in_array( $kind, array( 'worksheet_pdf', 'submission_pdf', 'result_pdf', 'report_pdf' ), true ) ) {
			if (
				! hash_equals( $source_type, (string) ( $row['artifact_source_type'] ?? '' ) )
				|| ! hash_equals( (string) $row['source_public_id'], (string) ( $row['artifact_source_public_id'] ?? '' ) )
				|| ! hash_equals( (string) $row['source_hash'], (string) ( $row['artifact_source_hash'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			if ( 'pdf_artifact' !== ( $row['owner_type'] ?? null ) || ! hash_equals( (string) $row['artifact_public_id'], (string) $row['owner_public_id'] )
				|| 'generated_pdf' !== ( $row['purpose'] ?? null ) || 'application/pdf' !== ( $row['mime_type'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
		} else {
			$profile = self::DIRECT_PROFILES[ $kind ];
			if (
				'export_job' !== ( $row['owner_type'] ?? null )
				|| ! hash_equals( (string) $row['job_public_id'], (string) $row['owner_public_id'] )
				|| $profile['purpose'] !== ( $row['purpose'] ?? null )
				|| $profile['mime_type'] !== ( $row['mime_type'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
		}
		$this->utc( (string) $row['expires_at_utc'] );
		$authorization = $this->authorization( $row, $source_type, $kind );
		return array(
			'artifact_id'    => (string) $row['artifact_public_id'],
			'artifact_kind'  => $kind,
			'job_id'         => (string) $row['job_public_id'],
			'source_type'    => $source_type,
			'source_id'      => (string) $row['source_public_id'],
			'source_hash'    => (string) $row['source_hash'],
			'artifact_state' => (string) $row['artifact_state'],
			'expires_at_utc' => (string) $row['expires_at_utc'],
			'page_count'     => null === $row['page_count'] ? null : $this->positive_int( $row['page_count'] ),
			'file_id'        => (string) $row['file_public_id'],
			'storage_key'    => (string) $row['storage_key'],
			'original_name'  => (string) $row['original_name'],
			'mime_type'      => (string) $row['mime_type'],
			'byte_size'      => $this->positive_int( $row['byte_size'] ),
			'sha256'         => (string) $row['sha256'],
			'file_state'     => (string) $row['file_state'],
			'scan_state'     => (string) $row['scan_state'],
			'authorization'  => $authorization,
		);
	}

	/**
	 * Authorization.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $source_type Source type.
	 * @param string              $kind Kind.
	 * @return array{context:?array<string,string>,object:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorization( array $row, string $source_type, string $kind ): array {
		if ( 'worksheet_revision' === $source_type ) {
			if ( null !== ( $row['context_type'] ?? null ) || null !== ( $row['context_id'] ?? null ) || null !== ( $row['context_revision'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => null,
				'object'  => array(
					'action'   => 'view',
					'id'       => (string) $row['source_public_id'],
					'relation' => 'owner',
					'revision' => (string) $row['source_public_id'],
					'state'    => null,
					'type'     => 'worksheet_revision',
				),
			);
		}
		if ( 'report_snapshot' === $source_type && in_array( $kind, array( 'report_csv', 'report_pdf', 'report_json_package' ), true ) ) {
			try {
				$snapshot = Report_Export_Request_Snapshot::restore(
					(string) ( $row['request_snapshot_json'] ?? '' ),
					(string) ( $row['request_snapshot_hash'] ?? '' ),
					$this->canonical
				);
			} catch ( InvalidArgumentException ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			$context = $snapshot->context();
			if (
				Report_Export_Request_Snapshot::VERSION !== ( $row['request_contract_version'] ?? null )
				|| Report_Export_Request_Snapshot::SCHEMA !== ( $row['request_snapshot_schema'] ?? null )
				|| Report_Export_Request_Snapshot::DATABASE_VERSION !== $this->positive_int( $row['request_snapshot_version'] ?? null )
				|| ! hash_equals( $snapshot->hash(), (string) ( $row['request_hash'] ?? '' ) )
				|| ! hash_equals( $snapshot->source_id(), (string) $row['source_public_id'] )
				|| ! hash_equals( $snapshot->source_hash(), (string) $row['source_hash'] )
				|| ! hash_equals( $snapshot->artifact_kind(), $kind )
				|| ! hash_equals( $snapshot->profile(), (string) ( $row['print_profile_version'] ?? '' ) )
				|| ! hash_equals( (string) $context['type'], (string) ( $row['context_type'] ?? '' ) )
				|| ! hash_equals( (string) $context['id'], (string) ( $row['context_id'] ?? '' ) )
				|| ! hash_equals( (string) $context['revision'], (string) ( $row['context_revision'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => array(
					'id'       => (string) $context['id'],
					'revision' => (string) $context['revision'],
					'type'     => (string) $context['type'],
				),
				'object'  => array(
					'action'   => 'view',
					'id'       => $snapshot->source_id(),
					'relation' => 'context_reporter',
					'revision' => $snapshot->source_hash(),
					'state'    => $snapshot->source_state(),
					'type'     => 'report',
				),
			);
		}
		if ( 'assignment' === $source_type && 'submission_bundle' === $kind ) {
			try {
				$snapshot = Submission_Bundle_Request_Snapshot::restore(
					(string) ( $row['request_snapshot_json'] ?? '' ),
					(string) ( $row['request_snapshot_hash'] ?? '' ),
					$this->canonical
				);
			} catch ( InvalidArgumentException ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			$context = $snapshot->context();
			if (
				Submission_Bundle_Request_Snapshot::VERSION !== ( $row['request_contract_version'] ?? null )
				|| Submission_Bundle_Request_Snapshot::SCHEMA !== ( $row['request_snapshot_schema'] ?? null )
				|| Submission_Bundle_Request_Snapshot::DATABASE_VERSION !== $this->positive_int( $row['request_snapshot_version'] ?? null )
				|| ! hash_equals( $snapshot->hash(), (string) ( $row['request_hash'] ?? '' ) )
				|| ! hash_equals( $snapshot->source_id(), (string) $row['source_public_id'] )
				|| ! hash_equals( $snapshot->source_hash(), (string) $row['source_hash'] )
				|| ! hash_equals( $snapshot->profile(), (string) ( $row['print_profile_version'] ?? '' ) )
				|| ! hash_equals( (string) $context['type'], (string) ( $row['context_type'] ?? '' ) )
				|| ! hash_equals( (string) $context['id'], (string) ( $row['context_id'] ?? '' ) )
				|| ! hash_equals( (string) $context['revision'], (string) ( $row['context_revision'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => array(
					'id'       => (string) $context['id'],
					'revision' => (string) $context['revision'],
					'type'     => (string) $context['type'],
				),
				'object'  => array(
					'action'   => 'export_submissions',
					'id'       => $snapshot->source_id(),
					'relation' => 'context_staff',
					'revision' => (string) $snapshot->schedule_version(),
					'state'    => $snapshot->source_state(),
					'type'     => 'assignment',
				),
			);
		}
		if ( 'worksheet_publication' === $source_type && 'worksheet_pdf' === $kind ) {
			try {
				$snapshot = Worksheet_Pdf_Request_Snapshot::restore(
					(string) ( $row['request_snapshot_json'] ?? '' ),
					(string) ( $row['request_snapshot_hash'] ?? '' ),
					$this->canonical
				);
			} catch ( InvalidArgumentException ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			$context    = $snapshot->context();
			$assignment = $snapshot->assignment();
			if ( ! is_array( $context ) || ! is_array( $assignment )
				|| Worksheet_Pdf_Request_Snapshot::OWN_VERSION !== ( $row['request_contract_version'] ?? null )
				|| Worksheet_Pdf_Request_Snapshot::SCHEMA !== ( $row['request_snapshot_schema'] ?? null )
				|| Worksheet_Pdf_Request_Snapshot::DATABASE_VERSION !== $this->positive_int( $row['request_snapshot_version'] ?? null )
				|| ! hash_equals( $snapshot->hash(), (string) ( $row['request_hash'] ?? '' ) )
				|| ! hash_equals( $snapshot->source_id(), (string) $row['source_public_id'] )
				|| ! hash_equals( $snapshot->source_hash(), (string) $row['source_hash'] )
				|| ! hash_equals( $snapshot->profile_version(), (string) ( $row['print_profile_version'] ?? '' ) )
				|| ! hash_equals( (string) $context['type'], (string) ( $row['context_type'] ?? '' ) )
				|| ! hash_equals( (string) $context['id'], (string) ( $row['context_id'] ?? '' ) )
				|| ! hash_equals( (string) $context['revision'], (string) ( $row['context_revision'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => array(
					'id'       => (string) $context['id'],
					'revision' => (string) $context['revision'],
					'type'     => (string) $context['type'],
				),
				'object'  => array(
					'action'   => 'export_pdf',
					'id'       => (string) $assignment['id'],
					'relation' => 'self_target',
					'revision' => null,
					'state'    => null,
					'type'     => 'assignment',
				),
			);
		}
		if ( 'submission_release' === $source_type && 'result_pdf' === $kind ) {
			try {
				$snapshot = Result_Pdf_Request_Snapshot::restore(
					(string) ( $row['request_snapshot_json'] ?? '' ),
					(string) ( $row['request_snapshot_hash'] ?? '' ),
					$this->canonical
				);
			} catch ( InvalidArgumentException ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			$context = $snapshot->context();
			if (
				Result_Pdf_Request_Snapshot::VERSION !== ( $row['request_contract_version'] ?? null )
				|| Result_Pdf_Request_Snapshot::SCHEMA !== ( $row['request_snapshot_schema'] ?? null )
				|| Result_Pdf_Request_Snapshot::DATABASE_VERSION !== $this->positive_int( $row['request_snapshot_version'] ?? null )
				|| ! hash_equals( $snapshot->hash(), (string) ( $row['request_hash'] ?? '' ) )
				|| ! hash_equals( $snapshot->source_id(), (string) $row['source_public_id'] )
				|| ! hash_equals( $snapshot->source_hash(), (string) $row['source_hash'] )
				|| ! hash_equals( $snapshot->profile_version(), (string) ( $row['print_profile_version'] ?? '' ) )
				|| ! hash_equals( (string) $context['type'], (string) ( $row['context_type'] ?? '' ) )
				|| ! hash_equals( (string) $context['id'], (string) ( $row['context_id'] ?? '' ) )
				|| ! hash_equals( (string) $context['revision'], (string) ( $row['context_revision'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => array(
					'id'       => (string) $context['id'],
					'revision' => (string) $context['revision'],
					'type'     => (string) $context['type'],
				),
				'object'  => array(
					'action'   => 'export_pdf',
					'id'       => $snapshot->source_id(),
					'relation' => 'self_release',
					'revision' => $snapshot->source_hash(),
					'state'    => $snapshot->source_state(),
					'type'     => 'release',
				),
			);
		}
		if ( 'submission_revision' !== $source_type || 'submission_pdf' !== $kind ) {

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		try {
			$snapshot = Submission_Pdf_Request_Snapshot::restore(
				(string) ( $row['request_snapshot_json'] ?? '' ),
				(string) ( $row['request_snapshot_hash'] ?? '' ),
				$this->canonical
			);
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		$context    = $snapshot->context();
		$submission = $snapshot->submission();
		if (
			! hash_equals( $snapshot->version(), (string) ( $row['request_contract_version'] ?? '' ) )
			|| Submission_Pdf_Request_Snapshot::SCHEMA !== ( $row['request_snapshot_schema'] ?? null )
			|| Submission_Pdf_Request_Snapshot::DATABASE_VERSION !== $this->positive_int( $row['request_snapshot_version'] ?? null )
			|| ! hash_equals( $snapshot->hash(), (string) ( $row['request_hash'] ?? '' ) )
			|| ! hash_equals( $snapshot->source_id(), (string) $row['source_public_id'] )
			|| ! hash_equals( $snapshot->source_hash(), (string) $row['source_hash'] )
			|| ! hash_equals( $snapshot->profile_version(), (string) ( $row['print_profile_version'] ?? '' ) )
			|| ! hash_equals( (string) $context['type'], (string) ( $row['context_type'] ?? '' ) )
			|| ! hash_equals( (string) $context['id'], (string) ( $row['context_id'] ?? '' ) )
			|| ! hash_equals( (string) $context['revision'], (string) ( $row['context_revision'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		if ( $snapshot->is_own() ) {
			$ownership = $snapshot->ownership();
			if ( ! is_array( $ownership ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
			}
			return array(
				'context' => array(
					'id'       => (string) $context['id'],
					'revision' => (string) $context['revision'],
					'type'     => (string) $context['type'],
				),
				'object'  => array(
					'action'   => 'export_pdf',
					'id'       => (string) $submission['id'],
					'relation' => 'self_submission',
					'revision' => (string) $submission['lock_version'],
					'state'    => (string) $submission['state'],
					'type'     => 'submission',
				),
			);
		}
		return array(
			'context' => array(
				'id'       => (string) $context['id'],
				'revision' => (string) $context['revision'],
				'type'     => (string) $context['type'],
			),
			'object'  => array(
				'action'   => 'teacher_view',
				'id'       => (string) $submission['id'],
				'relation' => 'context_staff',
				'revision' => (string) $submission['lock_version'],
				'state'    => (string) $submission['state'],
				'type'     => 'submission',
			),
		);
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param array  $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
		}return $prepared;
	}
	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$db = $this->db();
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
		}return $row;
	}
	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
		}return $db;
	}
	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
	}
	/**
	 * Positive int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_int( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		}throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
	}
}

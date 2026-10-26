<?php
/**
 * Reclaim and inspect at most one staged submission ZIP.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Verdict;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Scan_Job;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Suggestion_Matcher;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps extraction, scanning, and private binary I/O outside the metadata transaction.
 */
final class Run_Next_Submission_Zip_Scan {
	public const LEASE_SECONDS = 300;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize submission zip scan dependencies and state.
	 *
	 * @param Submission_Zip_Scan_Repository_Port_Interface        $repository Persistence boundary used by this operation.
	 * @param Private_File_Read_Port_Interface                     $reader Reader.
	 * @param Private_File_Store_Port_Interface                    $files Files.
	 * @param Private_File_Staging_Port_Interface                  $metadata Metadata.
	 * @param Submission_Attachment_Malware_Scanner_Port_Interface $malware Malware.
	 * @param Submission_Zip_Entry_Reader                          $archives Archives.
	 * @param Submission_Zip_Item_Content_Inspector                $content Content.
	 * @param Submission_Zip_Suggestion_Matcher                    $suggestions Suggestions.
	 * @param Public_Id_Generator                                  $ids Ids.
	 * @param callable|null                                        $clock Clock.
	 */
	public function __construct(
		private Submission_Zip_Scan_Repository_Port_Interface $repository,
		private Private_File_Read_Port_Interface $reader,
		private Private_File_Store_Port_Interface $files,
		private Private_File_Staging_Port_Interface $metadata,
		private Submission_Attachment_Malware_Scanner_Port_Interface $malware,
		private Submission_Zip_Entry_Reader $archives,
		private Submission_Zip_Item_Content_Inspector $content,
		private Submission_Zip_Suggestion_Matcher $suggestions,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Run once.
	 *
	 * @return Run_Submission_Zip_Scan_Result
	 */
	public function run_once(): Run_Submission_Zip_Scan_Result {
		$now = $this->now();
		$job = $this->repository->claim_due( $now, $this->minus_seconds( $now, self::LEASE_SECONDS ) );
		if ( null === $job ) {
			return new Run_Submission_Zip_Scan_Result( Run_Submission_Zip_Scan_Result::IDLE );
		}
		if ( ! $job->source_metadata_matches() ) {
			return $this->reject_root( $job, 'zip_source_metadata_mismatch' );
		}

		try {
			$archive_path = $this->reader->readable_path( $job->source_storage_key(), $job->source_sha256() );
			$size         = filesize( $archive_path );
			if ( ! is_int( $size ) || $size !== $job->source_byte_size() ) {
				return $this->reject_root( $job, 'zip_source_metadata_mismatch' );
			}
		} catch ( Throwable ) {
			return $this->retry( $job, 'zip_source_storage_unavailable' );
		}

		try {
			$source_malware = $this->malware->scan( $archive_path ); } catch ( Throwable ) {
			return $this->retry( $job, 'zip_scanner_unavailable' );
			}
			if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $source_malware->state() ) {
				return $this->retry( $job, 'zip_scanner_unavailable' );
			}
			if ( Submission_Attachment_Malware_Verdict::INFECTED === $source_malware->state() ) {
				return $this->reject_root( $job, 'zip_source_malware_detected' );
			}

			try {
				$entries = $this->archives->entries( $archive_path, $job->source_sha256() );
				if ( count( $entries ) !== (int) ( $job->mapping_source()['entry_count'] ?? -1 ) ) {
					return $this->reject_root( $job, 'zip_archive_invalid' );
				}
			} catch ( Zip_Import_Failure ) {
				return $this->reject_root( $job, 'zip_archive_invalid' );
			} catch ( Throwable ) {
				return $this->retry( $job, 'zip_inspector_unavailable' );
			}

			$prepared = array();
			try {
				foreach ( $entries as $entry ) {
					$prepared[] = $this->inspect_entry( $archive_path, $entry, $job );
				}
			} catch ( Zip_Import_Failure ) {
				$this->remove_temporaries( $prepared );
				return $this->reject_root( $job, 'zip_archive_invalid' );
			} catch ( RuntimeException $failure ) {
				$this->remove_temporaries( $prepared );
				return $this->retry( $job, $this->safe_retry_code( $failure->getMessage() ) );
			} catch ( Throwable ) {
				$this->remove_temporaries( $prepared );
				return $this->retry( $job, 'zip_inspector_unavailable' );
			}

			try {
				$items         = array();
				$mapping_items = array();
				foreach ( $prepared as &$item ) {
					if ( 'ready' === $item['state'] ) {
						$item['file_public_id'] = $this->stage_item( $job, $item );
					}
					$items[]         = array(
						'byte_size'      => $item['byte_size'],
						'error_code'     => $item['error_code'],
						'file_public_id' => $item['file_public_id'],
						'item_no'        => $item['item_no'],
						'relative_path'  => $item['relative_path'],
						'sha256'         => $item['sha256'],
						'state'          => $item['state'],
					);
					$mapping_items[] = array(
						'compatible_response_ids' => $item['compatible_response_ids'],
						'detected_mime_type'      => $item['mime_type'],
						'item_no'                 => $item['item_no'],
						'selection'               => null,
						'suggestion'              => $item['suggestion'],
					);
				}
				unset( $item );
				$mapping   = array(
					'items'            => $mapping_items,
					'mapping_revision' => $job->mapping_revision() + 1,
					'scan_error_code'  => null,
					'source'           => $job->mapping_source(),
				);
				$committed = $this->repository->complete(
					$job,
					$items,
					$mapping,
					$this->ids->create()->value(),
					$this->ids->create()->value(),
					$this->now()
				);
				if ( ! $committed ) {
					// A newer lease may already reference the same deterministic files.
					// Keep private rows for idempotent reuse and expiry-based cleanup.
					return new Run_Submission_Zip_Scan_Result( Run_Submission_Zip_Scan_Result::STALE, $job->upload_id() );
				}
				return new Run_Submission_Zip_Scan_Result( Run_Submission_Zip_Scan_Result::READY, $job->upload_id() );
			} catch ( Throwable ) {
				// Successfully registered quarantine rows are safe to reuse on retry.
				return $this->retry( $job, 'zip_item_storage_unavailable' );
			} finally {
				$this->remove_temporaries( $prepared );
			}
	}

	/**
	 * Inspect entry.
	 *
	 * @param string                  $archive_path Archive path.
	 * @param Submission_Zip_Entry    $entry Entry.
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function inspect_entry( string $archive_path, Submission_Zip_Entry $entry, Submission_Zip_Scan_Job $job ): array {
		$temporary = $this->archives->extract_to_temporary_file( $archive_path, $entry );
		$sha256    = hash_file( 'sha256', $temporary );
		$size      = filesize( $temporary );
		if ( ! is_string( $sha256 ) || ! is_int( $size ) || $size !== $entry->byte_size() ) {
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}

		try {
			$malware = $this->malware->scan( $temporary ); } catch ( Throwable ) {
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw new RuntimeException( 'zip_scanner_unavailable' );
			}
			if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $malware->state() ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw new RuntimeException( 'zip_scanner_unavailable' );
			}
			if ( Submission_Attachment_Malware_Verdict::INFECTED === $malware->state() ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				return $this->rejected_item( $entry, $size, $sha256, 'zip_item_malware_detected' );
			}

			$verdict = $this->content->inspect( $temporary, $entry->relative_path(), $size, $sha256 );
			if ( Submission_Zip_Item_Content_Verdict::UNAVAILABLE === $verdict->state() ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw new RuntimeException( (string) $verdict->reason_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
			if ( Submission_Zip_Item_Content_Verdict::REJECTED === $verdict->state() ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				return $this->rejected_item( $entry, $size, $sha256, (string) $verdict->reason_code() );
			}
			$mime       = (string) $verdict->mime_type();
			$compatible = $this->compatible_responses( $job->upload_responses(), $mime, $size );
			if ( array() === $compatible ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				return $this->rejected_item( $entry, $size, $sha256, 'zip_item_response_not_allowed' );
			}
			return array(
				'byte_size'               => $size,
				'compatible_response_ids' => $compatible,
				'error_code'              => null,
				'file_public_id'          => null,
				'item_no'                 => $entry->item_no(),
				'mime_type'               => $mime,
				'relative_path'           => $entry->relative_path(),
				'sha256'                  => $sha256,
				'state'                   => 'ready',
				'suggestion'              => $this->suggestions->suggest( $entry->relative_path(), $compatible, $job->targets() ),
				'temporary_path'          => $temporary,
			);
	}

	/**
	 * Rejected item.
	 *
	 * @param Submission_Zip_Entry $entry Entry.
	 * @param int                  $size Size.
	 * @param string               $sha256 Sha256.
	 * @param string               $reason Reason.
	 * @return array<string,mixed>
	 */
	private function rejected_item( Submission_Zip_Entry $entry, int $size, string $sha256, string $reason ): array {
		return array(
			'byte_size'               => $size,
			'compatible_response_ids' => array(),
			'error_code'              => $reason,
			'file_public_id'          => null,
			'item_no'                 => $entry->item_no(),
			'mime_type'               => null,
			'relative_path'           => $entry->relative_path(),
			'sha256'                  => $sha256,
			'state'                   => 'rejected',
			'suggestion'              => array(
				'learner'  => null,
				'response' => null,
			),
			'temporary_path'          => null,
		);
	}

	/**
	 * Compatible responses.
	 *
	 * @param array<int,array<string,mixed>> $responses Responses.
	 * @param string                         $mime Mime.
	 * @param int                            $byte_size Byte size.
	 * @return array<int,string>
	 */
	private function compatible_responses( array $responses, string $mime, int $byte_size ): array {
		$result = array();
		foreach ( $responses as $response ) {
			if (
				is_string( $response['id'] ?? null )
				&& is_int( $response['max_bytes'] ?? null ) && $byte_size <= $response['max_bytes']
				&& is_array( $response['mime_types'] ?? null ) && in_array( $mime, $response['mime_types'], true )
			) {
				$result[] = $response['id'];
			}
		}
		sort( $result, SORT_STRING );
		return array_values( array_unique( $result ) );
	}

	/**
	 * Stage item.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param array<string,mixed>     $item Item.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stage_item( Submission_Zip_Scan_Job $job, array $item ): string {
		$extension = strtolower( (string) pathinfo( $item['relative_path'], PATHINFO_EXTENSION ) );
		$key       = 'submission-zips/items/' . strtolower( $job->upload_id() ) . '/'
			. sprintf( '%04d', $item['item_no'] ) . '-' . $item['sha256'] . '.' . $extension;
		$existing  = $this->metadata->find_by_storage_key( $key );
		if ( is_array( $existing ) ) {
			return $this->matching_item_file( $existing, $job, $item );
		}
		$file_created = $this->files->put_quarantined( $item['temporary_path'], $key, $item['sha256'] );
		$file_id      = $this->ids->create()->value();
		try {
			$this->metadata->insert_quarantined(
				array(
					'actor_user_id'   => $job->actor_user_id(),
					'byte_size'       => $item['byte_size'],
					'created_at_utc'  => $this->now(),
					'expires_at_utc'  => $job->source_expires_at_utc(),
					'mime_type'       => $item['mime_type'],
					'original_name'   => basename( $item['relative_path'] ),
					'owner_public_id' => $job->upload_id(),
					'owner_type'      => 'submission_zip',
					'public_id'       => $file_id,
					'purpose'         => 'submission_zip_item',
					'retention_class' => 'submission_zip_staging',
					'scan_state'      => 'clean',
					'sha256'          => $item['sha256'],
					'storage_key'     => $key,
				)
			);
			return $file_id;
		} catch ( Throwable $failure ) {
			$race = $this->metadata->find_by_storage_key( $key );
			if ( ! is_array( $race ) ) {
				if ( $file_created ) {
					try {
						$this->files->delete( $key ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
						// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
						}
				}
				throw $failure;
			}
			return $this->matching_item_file( $race, $job, $item );
		}
	}

	/**
	 * Matching item file.
	 *
	 * @param array<string,mixed>     $stored Stored.
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param array<string,mixed>     $item Item.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function matching_item_file( array $stored, Submission_Zip_Scan_Job $job, array $item ): string {
		$matches = (int) ( $stored['created_by_user_id'] ?? 0 ) === $job->actor_user_id()
			&& 'submission_zip' === ( $stored['owner_type'] ?? null )
			&& $job->upload_id() === ( $stored['owner_public_id'] ?? null )
			&& 'submission_zip_item' === ( $stored['purpose'] ?? null )
			&& basename( $item['relative_path'] ) === ( $stored['original_name'] ?? null )
			&& $item['mime_type'] === ( $stored['mime_type'] ?? null )
			&& $item['byte_size'] === (int) ( $stored['byte_size'] ?? -1 )
			&& hash_equals( $item['sha256'], (string) ( $stored['sha256'] ?? '' ) )
			&& 'quarantined' === ( $stored['state'] ?? null ) && 'clean' === ( $stored['scan_state'] ?? null )
			&& 'submission_zip_staging' === ( $stored['retention_class'] ?? null );
		if ( ! $matches ) {
			throw new RuntimeException( 'zip_item_storage_conflict' );
		}
		$id = (string) ( $stored['public_id'] ?? '' );
		new Public_Id( $id );
		return $id;
	}

	/**
	 * Remove temporaries.
	 *
	 * @param array<int,array<string,mixed>> $prepared Prepared.
	 * @return void
	 */
	private function remove_temporaries( array $prepared ): void {
		foreach ( $prepared as $item ) {
			$path = $item['temporary_path'] ?? null;
			if ( is_string( $path ) && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Reject root.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $reason Reason.
	 * @return Run_Submission_Zip_Scan_Result
	 */
	private function reject_root( Submission_Zip_Scan_Job $job, string $reason ): Run_Submission_Zip_Scan_Result {
		try {
			$committed = $this->repository->reject( $job, $reason, $this->ids->create()->value(), $this->ids->create()->value(), $this->now() );
		} catch ( Throwable ) {
			return $this->retry( $job, 'zip_scan_storage_unavailable' );
		}
		return new Run_Submission_Zip_Scan_Result(
			$committed ? Run_Submission_Zip_Scan_Result::REJECTED : Run_Submission_Zip_Scan_Result::STALE,
			$job->upload_id(),
			$committed ? $reason : null
		);
	}

	/**
	 * Retry.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $reason Reason.
	 * @return Run_Submission_Zip_Scan_Result
	 */
	private function retry( Submission_Zip_Scan_Job $job, string $reason ): Run_Submission_Zip_Scan_Result {
		return new Run_Submission_Zip_Scan_Result( Run_Submission_Zip_Scan_Result::RETRY_WAIT, $job->upload_id(), $reason );
	}

	/**
	 * Safe retry code.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function safe_retry_code( string $message ): string {
		return 1 === preg_match( '/^zip_[a-z0-9_]{2,95}$/D', $message ) ? $message : 'zip_inspector_unavailable';
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new RuntimeException( 'Submission ZIP scan clock is invalid.' );
		}
		return $value;
	}

	/**
	 * Minus seconds.
	 *
	 * @param string $utc Utc.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function minus_seconds( string $utc, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			throw new RuntimeException( 'Submission ZIP scan clock cannot be converted.' );
		}
		return $date->modify( '-' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
}

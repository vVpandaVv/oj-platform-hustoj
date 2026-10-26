<?php
/**
 * Submission bundle request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable C50 assignment, official submission, and optional original-file manifest.
 */
final class Submission_Bundle_Request_Snapshot {
	public const SCHEMA                 = 'oj_ai.submission_bundle_request';
	public const VERSION                = '1.0.0';
	public const DATABASE_VERSION       = 1;
	public const SOURCE_SCHEMA          = 'oj_ai.assignment_submission_bundle_source';
	public const SOURCE_VERSION         = '1.0.0';
	public const PROFILE                = 'submission_bundle.v1';
	public const ARTIFACT_KIND          = 'submission_bundle';
	public const MAXIMUM_ARCHIVE_FILES  = 500;
	public const MAXIMUM_ARCHIVE_BYTES  = 524_288_000;
	public const MAXIMUM_SNAPSHOT_BYTES = 52_428_800;

	/**
	 * Initialize submission bundle request snapshot dependencies and state.
	 *
	 * @param array  $value Value.
	 * @param string $json Json.
	 * @param string $hash Hash.
	 */
	private function __construct( private array $value, private string $json, private string $hash ) {
	}

	/**
	 * Freeze.
	 *
	 * @param array<string,mixed>    $source Source.
	 * @param array<array-key,mixed> $context Access or execution context for this operation.
	 * @param string                 $locale Locale.
	 * @param bool                   $include_allowed_originals Include allowed originals.
	 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $source, array $context, string $locale, bool $include_allowed_originals, Canonical_Json $canonical ): self {
		self::assert_source( $source, $include_allowed_originals, $canonical );
		$assignment = self::map( $source['assignment'] ?? null );
		$entries    = self::calculate_entry_count( $source, $include_allowed_originals );
		$value      = array(
			'schema_id'      => self::SCHEMA,
			'schema_version' => self::VERSION,
			'source'         => array(
				'type'             => 'assignment',
				'id'               => $assignment['id'] ?? null,
				'hash'             => $source['source_hash'] ?? null,
				'state'            => $assignment['state'] ?? null,
				'assignment_hash'  => $assignment['hash'] ?? null,
				'schedule_version' => $assignment['schedule_version'] ?? null,
			),
			'context'        => array(
				'type'     => $context['type'] ?? null,
				'id'       => $context['id'] ?? null,
				'revision' => $context['revision'] ?? null,
			),
			'export'         => array(
				'artifact_kind'             => self::ARTIFACT_KIND,
				'profile'                   => self::PROFILE,
				'locale'                    => $locale,
				'include_allowed_originals' => $include_allowed_originals,
				'entry_count'               => $entries,
				'submission_count'          => count( $source['submissions'] ),
				'bundle_manifest'           => $source,
			),
		);
		self::assert_value( $value, $canonical );
		$json = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAXIMUM_SNAPSHOT_BYTES ) {
			throw new InvalidArgumentException( 'Submission bundle snapshot is too large.' );
		}
		return new self( $value, $json, hash( 'sha256', $json ) );
	}

	/**
	 * Restore.
	 *
	 * @param string         $json Json.
	 * @param string         $hash Hash.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function restore( string $json, string $hash, Canonical_Json $canonical ): self {
		if ( '' === $json || strlen( $json ) > self::MAXIMUM_SNAPSHOT_BYTES || ! self::is_hash( $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Submission bundle snapshot integrity failed.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw new InvalidArgumentException( 'Submission bundle snapshot JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Submission bundle snapshot contract is invalid.' );
			}
			self::assert_value( $value, $canonical );
			return new self( $value, $json, $hash );
	}

	/**
	 * Reject invalid value.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_value( array $value, Canonical_Json $canonical ): void {
		self::exact( $value, array( 'context', 'export', 'schema_id', 'schema_version', 'source' ) );
		if ( self::SCHEMA !== ( $value['schema_id'] ?? null ) || self::VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission bundle snapshot schema is invalid.' );
		}
		$source  = self::map( $value['source'] ?? null );
		$context = self::map( $value['context'] ?? null );
		$export  = self::map( $value['export'] ?? null );
		self::exact( $source, array( 'assignment_hash', 'hash', 'id', 'schedule_version', 'state', 'type' ) );
		self::exact( $context, array( 'id', 'revision', 'type' ) );
		self::exact( $export, array( 'artifact_kind', 'bundle_manifest', 'entry_count', 'include_allowed_originals', 'locale', 'profile', 'submission_count' ) );
		try {
			new Public_Id( is_string( $source['id'] ?? null ) ? $source['id'] : '' ); } catch ( Throwable ) {
			throw new InvalidArgumentException( 'Submission bundle source identity is invalid.' );
			}
			if (
			'assignment' !== ( $source['type'] ?? null ) || ! self::is_hash( $source['hash'] ?? null ) || ! self::is_hash( $source['assignment_hash'] ?? null )
			|| ! is_int( $source['schedule_version'] ?? null ) || $source['schedule_version'] < 1 || ! in_array( $source['state'] ?? null, array( 'open', 'closed' ), true )
			|| ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true ) || ! self::is_token( $context['id'] ?? null ) || ! self::is_token( $context['revision'] ?? null )
			|| self::ARTIFACT_KIND !== ( $export['artifact_kind'] ?? null ) || self::PROFILE !== ( $export['profile'] ?? null )
			|| ! is_string( $export['locale'] ?? null ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $export['locale'] )
			|| ! is_bool( $export['include_allowed_originals'] ?? null ) || ! is_int( $export['entry_count'] ?? null ) || ! is_int( $export['submission_count'] ?? null )
			) {
				throw new InvalidArgumentException( 'Submission bundle snapshot values are invalid.' );
			}

			$manifest = self::map( $export['bundle_manifest'] ?? null );
			self::assert_source( $manifest, $export['include_allowed_originals'], $canonical );
			$assignment       = self::map( $manifest['assignment'] ?? null );
			$manifest_context = self::map( $manifest['context'] ?? null );
			if (
			! hash_equals( (string) $source['id'], (string) ( $assignment['id'] ?? '' ) )
			|| ! hash_equals( (string) $source['hash'], (string) ( $manifest['source_hash'] ?? '' ) )
			|| ! hash_equals( (string) $source['assignment_hash'], (string) ( $assignment['hash'] ?? '' ) )
			|| $source['state'] !== ( $assignment['state'] ?? null ) || $source['schedule_version'] !== ( $assignment['schedule_version'] ?? null )
			|| ! self::same( $context, $manifest_context, $canonical )
			|| $export['submission_count'] !== count( $manifest['submissions'] )
			|| $export['entry_count'] !== self::calculate_entry_count( $manifest, $export['include_allowed_originals'] )
			) {
				throw new InvalidArgumentException( 'Submission bundle snapshot evidence drifted.' );
			}
	}

	/**
	 * Reject invalid source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param bool                $include_allowed_originals Include allowed originals.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function assert_source( array $source, bool $include_allowed_originals, Canonical_Json $canonical ): void {
		self::exact( $source, array( 'assignment', 'context', 'schema_id', 'schema_version', 'source_hash', 'submissions' ) );
		if ( self::SOURCE_SCHEMA !== ( $source['schema_id'] ?? null ) || self::SOURCE_VERSION !== ( $source['schema_version'] ?? null ) || ! self::is_hash( $source['source_hash'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission bundle source profile is invalid.' );
		}
		$without_hash = $source;
		unset( $without_hash['source_hash'] );
		if ( ! hash_equals( (string) $source['source_hash'], hash( 'sha256', $canonical->encode( $without_hash ) ) ) ) {
			throw new InvalidArgumentException( 'Submission bundle source hash drifted.' );
		}
		$context    = self::map( $source['context'] ?? null );
		$assignment = self::map( $source['assignment'] ?? null );
		self::exact( $context, array( 'id', 'revision', 'type' ) );
		self::exact( $assignment, array( 'hash', 'id', 'publication_hash', 'publication_id', 'schedule_hash', 'schedule_version', 'state', 'target_roster_revision', 'target_snapshot_hash', 'title' ) );
		try {
			new Public_Id( is_string( $assignment['id'] ?? null ) ? $assignment['id'] : '' );
			new Public_Id( is_string( $assignment['publication_id'] ?? null ) ? $assignment['publication_id'] : '' ); } catch ( Throwable ) {
			throw new InvalidArgumentException( 'Submission bundle assignment identity is invalid.' );
			}
			if (
			! in_array( $context['type'] ?? null, array( 'class', 'group' ), true ) || ! self::is_token( $context['id'] ?? null ) || ! self::is_token( $context['revision'] ?? null )
			|| ! self::is_hash( $assignment['hash'] ?? null ) || ! self::is_hash( $assignment['publication_hash'] ?? null ) || ! self::is_hash( $assignment['schedule_hash'] ?? null )
			|| ! self::is_hash( $assignment['target_snapshot_hash'] ?? null ) || ! is_int( $assignment['schedule_version'] ?? null ) || $assignment['schedule_version'] < 1
			|| ! in_array( $assignment['state'] ?? null, array( 'open', 'closed' ), true ) || ! self::is_token( $assignment['target_roster_revision'] ?? null )
			|| ! is_string( $assignment['title'] ?? null ) || '' === trim( $assignment['title'] ) || strlen( $assignment['title'] ) > 1000
			) {
				throw new InvalidArgumentException( 'Submission bundle assignment values are invalid.' );
			}

			$submissions = $source['submissions'] ?? null;
			if ( ! is_array( $submissions ) || ! array_is_list( $submissions ) || array() === $submissions ) {
				throw new InvalidArgumentException( 'Submission bundle requires official submissions.' );
			}
			$ids             = array(
				'target'     => array(),
				'submission' => array(),
				'revision'   => array(),
				'attachment' => array(),
				'file'       => array(),
			);
			$paths           = array(
				'manifest.csv'     => true,
				'checksums.sha256' => true,
			);
			$previous_target = '';
			$total_bytes     = 0;
			foreach ( $submissions as $item ) {
				$item = self::map( $item );
				self::exact( $item, array( 'attachments', 'official_revision', 'pdf_path', 'submission', 'target' ) );
				$target     = self::map( $item['target'] ?? null );
				$submission = self::map( $item['submission'] ?? null );
				$revision   = self::map( $item['official_revision'] ?? null );
				self::exact( $target, array( 'display_name', 'hash', 'id', 'membership_revision', 'snapshot_hash' ) );
				self::exact( $submission, array( 'hash', 'id', 'state', 'submitted_at_utc' ) );
				self::exact( $revision, array( 'attachment_set_hash', 'hash', 'id' ) );
				foreach ( array(
					'target'     => $target['id'] ?? null,
					'submission' => $submission['id'] ?? null,
					'revision'   => $revision['id'] ?? null,
				) as $kind => $id ) {
					try {
						new Public_Id( is_string( $id ) ? $id : '' );
					} catch ( Throwable ) {
								throw new InvalidArgumentException( 'Submission bundle item identity is invalid.' );
					}
					if ( isset( $ids[ $kind ][ $id ] ) ) {
						throw new InvalidArgumentException( 'Submission bundle item identity is duplicated.' );
					}
					$ids[ $kind ][ $id ] = true;
				}
				if ( '' !== $previous_target && strcmp( $previous_target, (string) $target['id'] ) >= 0 ) {
					throw new InvalidArgumentException( 'Submission bundle items are not deterministic.' );
				}
				$previous_target = (string) $target['id'];
				if (
				! self::is_hash( $target['hash'] ?? null ) || ! self::is_hash( $target['snapshot_hash'] ?? null ) || ! self::is_token( $target['membership_revision'] ?? null )
				|| ! is_string( $target['display_name'] ?? null ) || '' === trim( $target['display_name'] ) || strlen( $target['display_name'] ) > 1000
				|| ! self::is_hash( $submission['hash'] ?? null ) || ! in_array( $submission['state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true )
				|| ! self::is_utc( $submission['submitted_at_utc'] ?? null ) || ! self::is_hash( $revision['hash'] ?? null ) || ! self::is_hash( $revision['attachment_set_hash'] ?? null )
				) {
					throw new InvalidArgumentException( 'Submission bundle item values are invalid.' );
				}
				$pdf_path = $item['pdf_path'] ?? null;
				if ( ! is_string( $pdf_path ) || 1 !== preg_match( '/^submissions\/[0-9]{4}-[0-9a-hjkmnp-tv-z]{26}\.pdf$/D', $pdf_path ) || isset( $paths[ $pdf_path ] ) ) {
					throw new InvalidArgumentException( 'Submission bundle PDF path is unsafe.' );
				}
				$paths[ $pdf_path ] = true;
				$attachments        = $item['attachments'] ?? null;
				if ( ! is_array( $attachments ) || ! array_is_list( $attachments ) || ( ! $include_allowed_originals && array() !== $attachments ) ) {
					throw new InvalidArgumentException( 'Submission bundle attachment selection is invalid.' );
				}
				$attachment_ids      = array();
				$previous_attachment = '';
				foreach ( $attachments as $attachment ) {
					$attachment = self::map( $attachment );
					self::exact( $attachment, array( 'archive_path', 'byte_size', 'file_id', 'id', 'mime_type', 'original_name', 'response_key', 'sha256' ) );
					foreach ( array(
						'attachment' => $attachment['id'] ?? null,
						'file'       => $attachment['file_id'] ?? null,
					) as $kind => $id ) {
						try {
							new Public_Id( is_string( $id ) ? $id : '' );
						} catch ( Throwable ) {
										throw new InvalidArgumentException( 'Submission bundle attachment identity is invalid.' );
						}
						if ( isset( $ids[ $kind ][ $id ] ) ) {
							throw new InvalidArgumentException( 'Submission bundle attachment identity is duplicated.' );
						}
						$ids[ $kind ][ $id ] = true;
					}
					if ( '' !== $previous_attachment && strcmp( $previous_attachment, (string) $attachment['id'] ) >= 0 ) {
						throw new InvalidArgumentException( 'Submission bundle attachments are not deterministic.' );
					}
					$previous_attachment = (string) $attachment['id'];
					$attachment_ids[]    = (string) $attachment['id'];
					$path                = $attachment['archive_path'] ?? null;
					if (
					! is_string( $path ) || 1 !== preg_match( '/^attachments\/[0-9]{4}-[0-9a-hjkmnp-tv-z]{26}\/[0-9a-hjkmnp-tv-z]{26}\.[a-z0-9]{2,5}$/D', $path ) || isset( $paths[ $path ] )
					|| ! is_int( $attachment['byte_size'] ?? null ) || $attachment['byte_size'] < 1 || ! self::is_hash( $attachment['sha256'] ?? null )
					|| ! is_string( $attachment['mime_type'] ?? null ) || strlen( $attachment['mime_type'] ) > 191
					|| ! is_string( $attachment['original_name'] ?? null ) || '' === trim( $attachment['original_name'] ) || strlen( $attachment['original_name'] ) > 1000
					|| ! is_string( $attachment['response_key'] ?? null ) || ! self::is_token( $attachment['response_key'], 100 )
					) {
						throw new InvalidArgumentException( 'Submission bundle attachment values are invalid.' );
					}
					$paths[ $path ] = true;
					$total_bytes   += $attachment['byte_size'];
				}
				if ( $include_allowed_originals && ! hash_equals( (string) $revision['attachment_set_hash'], hash( 'sha256', $canonical->encode( $attachment_ids ) ) ) ) {
					throw new InvalidArgumentException( 'Submission bundle attachment set drifted.' );
				}
			}
			if ( count( $paths ) > self::MAXIMUM_ARCHIVE_FILES || $total_bytes > self::MAXIMUM_ARCHIVE_BYTES ) {
				throw new InvalidArgumentException( 'Submission bundle source is too large.' );
			}
	}

	/**
	 * Calculate entry count.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param bool                $include_allowed_originals Include allowed originals.
	 * @return int
	 */
	private static function calculate_entry_count( array $source, bool $include_allowed_originals ): int {
		$count = 2;
		foreach ( $source['submissions'] as $item ) {
			++$count;
			if ( $include_allowed_originals ) {
				$count += count( $item['attachments'] );
			}
		}
		return $count;
	}

	/**
	 * Json.
	 *
	 * @return string
	 */
	public function json(): string {
		return $this->json;
	}
	/**
	 * Hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}
	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return (string) $this->value['source']['id'];
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return (string) $this->value['source']['hash'];
	}
	/**
	 * Source state.
	 *
	 * @return string
	 */
	public function source_state(): string {
		return (string) $this->value['source']['state'];
	}
	/**
	 * Assignment hash.
	 *
	 * @return string
	 */
	public function assignment_hash(): string {
		return (string) $this->value['source']['assignment_hash'];
	}
	/**
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return self::ARTIFACT_KIND;
	}
	/**
	 * Schedule version.
	 *
	 * @return int
	 */
	public function schedule_version(): int {
		return (int) $this->value['source']['schedule_version'];
	}
	/**
	 * Profile.
	 *
	 * @return string
	 */
	public function profile(): string {
		return (string) $this->value['export']['profile'];
	}
	/**
	 * Locale.
	 *
	 * @return string
	 */
	public function locale(): string {
		return (string) $this->value['export']['locale'];
	}
	/**
	 * Include allowed originals.
	 *
	 * @return bool
	 */
	public function include_allowed_originals(): bool {
		return (bool) $this->value['export']['include_allowed_originals'];
	}
	/**
	 * Entry count.
	 *
	 * @return int
	 */
	public function entry_count(): int {
		return (int) $this->value['export']['entry_count'];
	}
	/**
	 * Submission count.
	 *
	 * @return int
	 */
	public function submission_count(): int {
		return (int) $this->value['export']['submission_count'];
	}
	/**
	 * Context.
	 *
	 * @return array{type:string,id:string,revision:string}
	 */
	public function context(): array {
		return $this->value['context'];
	}
	/**
	 * Manifest.
	 *
	 * @return array<string,mixed>
	 */
	public function manifest(): array {
		return $this->value['export']['bundle_manifest'];
	}

	/**
	 * Map submission bundle request snapshot.
	 *
	 * @param mixed $value Value.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Submission bundle object is invalid.' );
		} return $value;
	}
	/**
	 * Exact.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission bundle fields are invalid.' );
		}
	}
	/**
	 * Is hash.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_hash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
	/**
	 * Is utc.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_utc( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value );
	}
	/**
	 * Is token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return bool
	 */
	private static function is_token( mixed $value, int $maximum = 191 ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $maximum && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value );
	}
	/**
	 * Same.
	 *
	 * @param array          $left Left.
	 * @param array          $right Right.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return bool
	 */
	private static function same( array $left, array $right, Canonical_Json $canonical ): bool {
		return hash_equals( $canonical->encode( $left ), $canonical->encode( $right ) );
	}
}

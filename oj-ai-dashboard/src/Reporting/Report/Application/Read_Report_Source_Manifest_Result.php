<?php
/**
 * Authorized internal source-manifest handoff for an immutable report snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorized internal source-manifest handoff for an immutable report snapshot.
 */
final class Read_Report_Source_Manifest_Result {
	/**
	 * Initialize report source manifest result dependencies and state.
	 *
	 * @param string              $snapshot_id Snapshot id.
	 * @param string              $snapshot_hash Snapshot hash.
	 * @param string              $context_type Context type.
	 * @param string              $context_id Context id.
	 * @param string              $context_revision Context revision.
	 * @param string              $generated_at_utc Generated at utc.
	 * @param string              $filter_hash Filter hash.
	 * @param array<string,mixed> $source_manifest Source manifest.
	 * @param string              $source_manifest_json Source manifest json.
	 * @param string              $source_manifest_hash Source manifest hash.
	 * @param string              $projection_hash Projection hash.
	 * @param int                 $entry_count Entry count.
	 * @param int                 $source_count Source count.
	 * @param int                 $authorized_worksheet_count Authorized worksheet count.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $snapshot_id,
		private string $snapshot_hash,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $generated_at_utc,
		private string $filter_hash,
		private array $source_manifest,
		private string $source_manifest_json,
		private string $source_manifest_hash,
		private string $projection_hash,
		private int $entry_count,
		private int $source_count,
		private int $authorized_worksheet_count
	) {
		new Public_Id( $snapshot_id );
		foreach ( array( $snapshot_hash, $filter_hash, $source_manifest_hash, $projection_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Report source manifest result hash is invalid.' );
			}
		}
		if (
			'' === $context_type || '' === $context_id || '' === $context_revision
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $generated_at_utc )
			|| $entry_count < 0 || $source_count !== $entry_count * 3
			|| $authorized_worksheet_count < 0 || $authorized_worksheet_count > $entry_count
		) {
			throw new InvalidArgumentException( 'Report source manifest result metadata is invalid.' );
		}
		if (
			Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA !== ( $source_manifest['schema_id'] ?? null )
			|| Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION !== ( $source_manifest['schema_version'] ?? null )
			|| ! is_array( $source_manifest['entries'] ?? null )
			|| ! array_is_list( $source_manifest['entries'] )
			|| count( $source_manifest['entries'] ) !== $entry_count
			|| ! hash_equals( $source_manifest_hash, hash( 'sha256', $source_manifest_json ) )
		) {
			throw new InvalidArgumentException( 'Report source manifest result document is invalid.' );
		}
	}

	/**
	 * Payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return array(
			'snapshot_id'                => $this->snapshot_id,
			'snapshot_hash'              => $this->snapshot_hash,
			'context'                    => array(
				'type'              => $this->context_type,
				'id'                => $this->context_id,
				'snapshot_revision' => $this->context_revision,
			),
			'generated_at_utc'           => $this->generated_at_utc,
			'filter_hash'                => $this->filter_hash,
			'source_manifest'            => $this->source_manifest,
			'source_manifest_json'       => $this->source_manifest_json,
			'source_manifest_hash'       => $this->source_manifest_hash,
			'projection_hash'            => $this->projection_hash,
			'entry_count'                => $this->entry_count,
			'source_count'               => $this->source_count,
			'authorized_worksheet_count' => $this->authorized_worksheet_count,
		);
	}

	/**
	 * Source manifest json.
	 *
	 * @return string
	 */
	public function source_manifest_json(): string {
		return $this->source_manifest_json;
	}

	/**
	 * Source manifest hash.
	 *
	 * @return string
	 */
	public function source_manifest_hash(): string {
		return $this->source_manifest_hash;
	}

	/**
	 * Snapshot hash.
	 *
	 * @return string
	 */
	public function snapshot_hash(): string {
		return $this->snapshot_hash;
	}
}

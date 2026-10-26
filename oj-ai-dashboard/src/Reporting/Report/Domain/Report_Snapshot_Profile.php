<?php
/**
 * Canonical filter, source and projection profiles for report snapshots.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds report projections to their canonical source and filter hashes.
 */
final class Report_Snapshot_Profile {
	public const SOURCE_MANIFEST_SCHEMA           = 'oj_ai.report_source_manifest';
	public const SOURCE_MANIFEST_VERSION          = '1.0.0';
	public const SOURCE_MANIFEST_DATABASE_VERSION = 1;
	public const FILTER_DATABASE_VERSION          = 1;
	public const PROJECTION_SCHEMA                = 'oj_ai.report_projection';
	public const PROJECTION_VERSION               = '1.0.0';
	public const PROJECTION_DATABASE_VERSION      = 1;
	public const IDENTITY_SCHEMA                  = 'oj_ai.report_snapshot_identity';
	public const IDENTITY_VERSION                 = '1.0.0';

	/**
	 * Share the canonical encoder used by report collection.
	 *
	 * @param Canonical_Json $canonical Canonical report encoder.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Freeze the filter and context into a canonical profile.
	 *
	 * @param string        $context_type Authorized context kind.
	 * @param string        $context_id Authorized context identifier.
	 * @param string        $context_revision Membership revision at collection time.
	 * @param Report_Filter $filter Validated report filter.
	 * @return array{document:array<string,mixed>,json:string,hash:string}
	 */
	public function filter( string $context_type, string $context_id, string $context_revision, Report_Filter $filter ): array {
		$document = array(
			'schema_id'      => Report_Filter::SCHEMA_ID,
			'schema_version' => Report_Filter::VERSION,
			'payload'        => array_merge(
				array(
					'lens'    => 'worksheet',
					'context' => array(
						'type'     => $context_type,
						'id'       => $context_id,
						'revision' => $context_revision,
					),
				),
				$filter->payload()
			),
		);
		$json     = $this->canonical->encode( $document );
		return array(
			'document' => $document,
			'json'     => $json,
			'hash'     => hash( 'sha256', $json ),
		);
	}

	/**
	 * Build immutable report profiles after validating their source hashes.
	 *
	 * @param string              $snapshot_id New public snapshot identifier.
	 * @param string              $generated_at_utc UTC collection time.
	 * @param string              $context_type Authorized context kind.
	 * @param string              $context_id Authorized context identifier.
	 * @param string              $context_revision Membership revision at collection time.
	 * @param Report_Filter       $filter Validated report filter.
	 * @param array<string,mixed> $filter_profile Filter profile to validate.
	 * @param array<string,mixed> $collected Collected source profile to validate.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When profile identity, shape or hashes differ.
	 */
	public function snapshot(
		string $snapshot_id,
		string $generated_at_utc,
		string $context_type,
		string $context_id,
		string $context_revision,
		Report_Filter $filter,
		array $filter_profile,
		array $collected
	): array {
		new Public_Id( $snapshot_id );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $generated_at_utc ) ) {
			throw new InvalidArgumentException( 'Report snapshot generation time is invalid.' );
		}
		$manifest = $collected['source_manifest'] ?? null;
		$items    = $collected['items'] ?? null;
		if (
			! is_array( $manifest ) || array_is_list( $manifest ) || ! is_array( $items ) || ! array_is_list( $items )
			|| self::SOURCE_MANIFEST_SCHEMA !== ( $manifest['schema_id'] ?? null )
			|| self::SOURCE_MANIFEST_VERSION !== ( $manifest['schema_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Report snapshot source manifest is invalid.' );
		}
		$source_json = $this->canonical->encode( $manifest );
		$source_hash = hash( 'sha256', $source_json );
		if (
			! is_string( $collected['source_manifest_hash'] ?? null )
			|| ! hash_equals( $source_hash, $collected['source_manifest_hash'] )
		) {
			throw new InvalidArgumentException( 'Report snapshot source manifest hash is invalid.' );
		}
		$filter_json = $filter_profile['json'] ?? null;
		$filter_hash = $filter_profile['hash'] ?? null;
		if (
			! is_string( $filter_json ) || ! is_string( $filter_hash )
			|| ! hash_equals( $filter_hash, hash( 'sha256', $filter_json ) )
		) {
			throw new InvalidArgumentException( 'Report snapshot filter profile is invalid.' );
		}

		$projection      = array(
			'schema_id'            => self::PROJECTION_SCHEMA,
			'schema_version'       => self::PROJECTION_VERSION,
			'lens'                 => 'worksheet',
			'baseline_at_utc'      => $generated_at_utc,
			'metric_contract'      => Worksheet_Report_Projector::METRIC_CONTRACT,
			'context'              => array(
				'type'     => $context_type,
				'id'       => $context_id,
				'revision' => $context_revision,
			),
			'filters'              => array(
				'applied'   => $filter->payload(),
				'available' => array_merge( array( 'lenses' => array( 'worksheet' ) ), $filter->available() ),
			),
			'denominator_contract' => Report_Denominator_Contract::payload(),
			'filter_hash'          => $filter_hash,
			'source_manifest_hash' => $source_hash,
			'items'                => $items,
		);
		$projection_json = $this->canonical->encode( $projection );
		$projection_hash = hash( 'sha256', $projection_json );
		$identity_json   = $this->canonical->encode(
			array(
				'schema_id'            => self::IDENTITY_SCHEMA,
				'schema_version'       => self::IDENTITY_VERSION,
				'snapshot_id'          => $snapshot_id,
				'filter_hash'          => $filter_hash,
				'source_manifest_hash' => $source_hash,
				'projection_hash'      => $projection_hash,
			)
		);

		return array(
			'filter_json'          => $filter_json,
			'filter_hash'          => $filter_hash,
			'source_manifest_json' => $source_json,
			'source_manifest_hash' => $source_hash,
			'projection_json'      => $projection_json,
			'projection_hash'      => $projection_hash,
			'snapshot_hash'        => hash( 'sha256', $identity_json ),
			'row_count'            => count( $items ),
		);
	}
}

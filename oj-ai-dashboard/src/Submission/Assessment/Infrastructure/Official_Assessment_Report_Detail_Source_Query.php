<?php
/**
 * Q21 detail reference bridge for the Assessment owner.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Detail_Source_Query_Port_Interface;
use OJAI\Dashboard\Reporting\Report\Port\Official_Assessment_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuses the official-review owner query without exposing Assessment tables to Reporting.
 */
final class Official_Assessment_Report_Detail_Source_Query implements Official_Assessment_Detail_Source_Query_Port_Interface {
	/**
	 * Initialize official assessment report detail source query dependencies and state.
	 *
	 * @param Official_Assessment_Source_Query_Port_Interface $assessments Assessments.
	 * @param Canonical_Json                                  $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Official_Assessment_Source_Query_Port_Interface $assessments,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * By report details.
	 *
	 * @param array<array-key,mixed> $detail_sources Detail sources.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function by_report_details( array $detail_sources ): array {
		if ( ! array_is_list( $detail_sources ) || count( $detail_sources ) > 100 ) {
			$this->stored_invalid();
		}
		$bridges = array();
		foreach ( $detail_sources as $source ) {
			if ( ! is_array( $source ) || 'oj_ai.assignment_report_detail_source' !== ( $source['schema_id'] ?? null ) || '1.0.0' !== ( $source['schema_version'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED );
			}
			$stored_hash = $this->hash_value( $source['source_hash'] ?? null );
			$unsigned    = $source;
			unset( $unsigned['source_hash'] );
			if ( ! hash_equals( $stored_hash, hash( 'sha256', $this->canonical->encode( $unsigned ) ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Query_Failure::because( Report_Query_Failure::SOURCE_STALE );
			}
			$publication = $source['publication'] ?? null;
			$references  = $source['official_review_refs'] ?? null;
			if ( ! is_array( $publication ) || array_keys( $publication ) !== array( 'id', 'hash' ) || ! is_array( $references ) || ! array_is_list( $references ) ) {
				$this->stored_invalid();
			}
			$this->public_id( $publication['id'] ?? null );
			$this->hash_value( $publication['hash'] ?? null );

			$review_refs = array();
			$seen        = array();
			foreach ( $references as $reference ) {
				if ( ! is_array( $reference ) || array_keys( $reference ) !== array( 'assignment_id', 'target_id', 'review', 'release' ) ) {
					$this->stored_invalid();
				}
				$this->public_id( $reference['assignment_id'] ?? null );
				$this->public_id( $reference['target_id'] ?? null );
				$review  = $this->reference_value( $reference['review'] ?? null );
				$release = null === ( $reference['release'] ?? null ) ? null : $this->reference_value( $reference['release'] );
				if ( isset( $seen[ $review['id'] ] ) ) {
					$this->stored_invalid();
				}
				$seen[ $review['id'] ] = true;
				$review_refs[]         = array(
					'review'  => $review,
					'release' => $release,
				);
			}

			$bridge                = array(
				'schema_id'             => 'oj_ai.assignment_progress_source',
				'schema_version'        => '1.0.0',
				'publication'           => $publication,
				'official_review_count' => count( $review_refs ),
				'official_review_refs'  => $review_refs,
			);
			$bridge['source_hash'] = hash( 'sha256', $this->canonical->encode( $bridge ) );
			$bridges[]             = $bridge;
		}

		return $this->assessments->by_official_reviews( $bridges );
	}

	/**
	 * Reference value.
	 *
	 * @param mixed $value Value.
	 * @return array{id:string,hash:string}
	 */
	private function reference_value( mixed $value ): array {
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'id', 'hash' ) ) {
			$this->stored_invalid();
		}
		return array(
			'id'   => $this->public_id( $value['id'] ?? null ),
			'hash' => $this->hash_value( $value['hash'] ?? null ),
		);
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
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Report_Query_Failure::because( Report_Query_Failure::STORED_DATA_INVALID );
	}
}

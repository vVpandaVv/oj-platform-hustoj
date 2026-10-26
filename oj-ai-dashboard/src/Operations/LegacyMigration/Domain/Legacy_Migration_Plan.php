<?php
/**
 * Legacy migration plan.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and verifies the deterministic, PII-free C61/C70 migration plan.
 */
final class Legacy_Migration_Plan {
	public const PROFILE          = 'oj_ai.legacy_migration_plan@1.2.0';
	public const PREVIOUS_PROFILE = 'oj_ai.legacy_migration_plan@1.1.0';
	public const LEGACY_PROFILE   = 'oj_ai.legacy_migration_plan@1.0.0';

	/**
	 * Initialize legacy migration plan dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Build.
	 *
	 * @param string                 $site_id Site id.
	 * @param Legacy_Source_Snapshot $source Source.
	 * @param array<array-key,mixed> $mapping_decisions Raw mapping decisions validated before use.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build( string $site_id, Legacy_Source_Snapshot $source, array $mapping_decisions = array() ): array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $site_id ) ) {
			throw new InvalidArgumentException( 'Legacy migration plan site is invalid.' );
		}

		$plan              = array(
			'profile'              => self::PROFILE,
			'site'                 => $site_id,
			'source'               => array(
				'profile'     => $source->profile(),
				'version'     => $source->source_version(),
				'fingerprint' => $source->source_fingerprint(),
			),
			'inventory'            => array(
				'table_count' => $source->table_count(),
				'row_count'   => $source->row_count(),
				'blob_count'  => $source->blob_count(),
				'blob_bytes'  => $source->blob_bytes(),
				'tables'      => $source->tables(),
			),
			'mapping_decisions'    => $this->normalize_decisions( $source->mapping_manifest(), $mapping_decisions ),
			'mapping_requirements' => $source->mapping_requirements(),
			'mapping_manifest'     => $source->mapping_manifest(),
			'exclusions'           => $source->exclusions(),
			'conflicts'            => $source->conflicts(),
			'counts'               => $source->counts(),
		);
		$plan['plan_hash'] = hash( 'sha256', $this->canonical->encode( $plan ) );

		return $plan;
	}

	/**
	 * Rebuild.
	 *
	 * @param string                 $site_id Site id.
	 * @param Legacy_Source_Snapshot $source Source.
	 * @param array<string,mixed>    $approved_plan Approved plan.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function rebuild( string $site_id, Legacy_Source_Snapshot $source, array $approved_plan ): array {
		$this->assert_valid( $approved_plan );
		if ( self::PREVIOUS_PROFILE === $approved_plan['profile'] ) {
			throw new InvalidArgumentException( 'Legacy migration mapping plan requires reapproval.' );
		}
		if ( self::LEGACY_PROFILE === $approved_plan['profile'] ) {
			return $this->build_legacy( $site_id, $source );
		}
		return $this->build( $site_id, $source, $approved_plan['mapping_decisions'] );
	}

	/**
	 * Reject invalid valid.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assert_valid( array $plan ): void {
		if ( self::LEGACY_PROFILE === ( $plan['profile'] ?? null ) ) {
			$this->assert_legacy_valid( $plan );
			return;
		}
		$profile       = $plan['profile'] ?? null;
		$expected_keys = array(
			'profile',
			'site',
			'source',
			'inventory',
			'mapping_decisions',
			'mapping_requirements',
			'mapping_manifest',
			'exclusions',
			'conflicts',
			'counts',
			'plan_hash',
		);
		$actual_keys   = array_keys( $plan );
		sort( $actual_keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );
		$source       = $plan['source'] ?? null;
		$counts       = $plan['counts'] ?? null;
		$requirements = $plan['mapping_requirements'] ?? null;
		$manifest     = $plan['mapping_manifest'] ?? null;
		$decisions    = $plan['mapping_decisions'] ?? null;
		if (
			$actual_keys !== $expected_keys
			|| ! in_array( $profile, array( self::PROFILE, self::PREVIOUS_PROFILE ), true )
			|| ! is_string( $plan['site'] ?? null )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $plan['site'] )
			|| ! is_array( $source )
			|| ! is_array( $counts )
			|| ! is_array( $requirements ) || array_is_list( $requirements )
			|| ! is_array( $manifest )
			|| ! array_is_list( $manifest )
			|| ! is_array( $decisions )
			|| ! array_is_list( $decisions )
			|| ! is_int( $counts['migration_candidates'] ?? null )
			|| $counts['migration_candidates'] < 0
			|| ! is_string( $plan['plan_hash'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_hash'] )
		) {
			throw new InvalidArgumentException( 'Legacy migration plan is invalid.' );
		}
		$this->assert_mapping_contract( $manifest, $requirements, $counts, self::PROFILE === $profile );
		$normalized = $this->normalize_decisions( $manifest, $decisions );
		if ( $normalized !== $decisions ) {
			throw new InvalidArgumentException( 'Legacy migration mapping decisions are not canonical.' );
		}
		$without_hash  = $plan;
		$expected_hash = (string) $without_hash['plan_hash'];
		unset( $without_hash['plan_hash'] );
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $this->canonical->encode( $without_hash ) ) ) ) {
			throw new InvalidArgumentException( 'Legacy migration plan hash is invalid.' );
		}
	}

	/**
	 * Reject invalid executable.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assert_executable( array $plan ): void {
		$this->assert_valid( $plan );
		if ( self::PREVIOUS_PROFILE === $plan['profile'] ) {
			throw new InvalidArgumentException( 'Legacy migration mapping plan requires reapproval.' );
		}
		$counts = $plan['counts'];
		if ( ( $counts['conflicts'] ?? 0 ) > 0 ) {
			throw new InvalidArgumentException( 'Legacy migration source conflicts remain.' );
		}
		if ( self::LEGACY_PROFILE === $plan['profile'] ) {
			if ( 0 !== ( $counts['mapping_needed'] ?? -1 ) ) {
				throw new InvalidArgumentException( 'Legacy migration mapping decisions are required.' );
			}
			return;
		}
		$required = array_column( $plan['mapping_manifest'], 'source_ref' );
		$approved = array_column( $plan['mapping_decisions'], 'source_ref' );
		sort( $required, SORT_STRING );
		sort( $approved, SORT_STRING );
		if ( $required !== $approved ) {
			throw new InvalidArgumentException( 'Legacy migration mapping decisions are incomplete.' );
		}
	}

	/**
	 * Normalize decisions.
	 *
	 * @param array<array-key,mixed> $manifest Raw mapping requirements validated before lookup.
	 * @param array<array-key,mixed> $decisions Raw mapping decisions validated before normalization.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_decisions( array $manifest, array $decisions ): array {
		if ( ! array_is_list( $manifest ) || ! array_is_list( $decisions ) || count( $decisions ) > count( $manifest ) ) {
			throw new InvalidArgumentException( 'Legacy migration mapping decisions are invalid.' );
		}
		$requirements = array();
		foreach ( $manifest as $requirement ) {
			if ( ! is_array( $requirement ) || array_is_list( $requirement ) ) {
				throw new InvalidArgumentException( 'Legacy migration mapping manifest is invalid.' );
			}
			$source_ref = $requirement['source_ref'] ?? null;
			$kind       = $requirement['kind'] ?? null;
			if (
				! is_string( $source_ref )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_ref )
				|| ! in_array( $kind, array( 'context', 'user' ), true )
				|| isset( $requirements[ $source_ref ] )
			) {
				throw new InvalidArgumentException( 'Legacy migration mapping manifest is invalid.' );
			}
			$requirements[ $source_ref ] = $kind;
		}

		$normalized = array();
		$seen       = array();
		foreach ( $decisions as $decision ) {
			if ( ! is_array( $decision ) || array_is_list( $decision ) ) {
				throw new InvalidArgumentException( 'Legacy migration mapping decision shape is invalid.' );
			}
			$this->exact_keys( $decision, array( 'kind', 'source_ref', 'target' ) );
			$kind       = $decision['kind'];
			$source_ref = $decision['source_ref'];
			$target     = $decision['target'];
			if (
				! is_string( $source_ref )
				|| ! isset( $requirements[ $source_ref ] )
				|| $requirements[ $source_ref ] !== $kind
				|| isset( $seen[ $source_ref ] )
				|| ! is_array( $target )
				|| array_is_list( $target )
			) {
				throw new InvalidArgumentException( 'Legacy migration mapping decision identity is invalid.' );
			}
			$seen[ $source_ref ] = true;
			if ( 'context' === $kind ) {
				$this->exact_keys( $target, array( 'context_id', 'context_revision', 'context_type', 'roster_revision' ) );
				if ( ! in_array( $target['context_type'] ?? null, array( 'class', 'group' ), true ) ) {
					throw new InvalidArgumentException( 'Legacy migration Context target type is invalid.' );
				}
				foreach ( array( 'context_id', 'context_revision', 'roster_revision' ) as $field ) {
					$this->safe_token( $target[ $field ] ?? null, $field );
				}
				$target = array(
					'context_id'       => $target['context_id'],
					'context_revision' => $target['context_revision'],
					'context_type'     => $target['context_type'],
					'roster_revision'  => $target['roster_revision'],
				);
			} else {
				$this->exact_keys( $target, array( 'learner_user_id', 'membership_revision' ) );
				if ( ! is_int( $target['learner_user_id'] ?? null ) || $target['learner_user_id'] < 1 ) {
					throw new InvalidArgumentException( 'Legacy migration learner target is invalid.' );
				}
				$this->safe_token( $target['membership_revision'] ?? null, 'membership_revision' );
				$target = array(
					'learner_user_id'     => $target['learner_user_id'],
					'membership_revision' => $target['membership_revision'],
				);
			}
			$normalized[] = array(
				'kind'       => $kind,
				'source_ref' => $source_ref,
				'target'     => $target,
			);
		}
		usort( $normalized, static fn ( array $left, array $right ): int => array( $left['kind'], $left['source_ref'] ) <=> array( $right['kind'], $right['source_ref'] ) );
		return $normalized;
	}

	/**
	 * Reject invalid mapping contract.
	 *
	 * @param array<array-key,mixed> $manifest Raw mapping requirements validated before field access.
	 * @param array<string,mixed>    $requirements Requirements.
	 * @param array<string,mixed>    $counts Counts.
	 * @param bool                   $assignment_bound Whether learner requirements include their assignment relation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_mapping_contract( array $manifest, array $requirements, array $counts, bool $assignment_bound ): void {
		$this->exact_keys( $requirements, array( 'context', 'user' ) );
		$this->exact_keys( $counts, array( 'source_rows', 'migration_candidates', 'excluded', 'conflicts', 'mapping_needed' ) );
		foreach ( $requirements as $count ) {
			if ( ! is_int( $count ) || $count < 0 ) {
				throw new InvalidArgumentException( 'Legacy migration mapping requirement count is invalid.' );
			}
		}
		foreach ( $counts as $count ) {
			if ( ! is_int( $count ) || $count < 0 ) {
				throw new InvalidArgumentException( 'Legacy migration plan count is invalid.' );
			}
		}
		if (
			$counts['source_rows'] !== $counts['migration_candidates'] + $counts['excluded']
			|| $counts['mapping_needed'] !== $requirements['context'] + $requirements['user']
			|| $counts['mapping_needed'] !== count( $manifest )
			|| count( $manifest ) > Legacy_Mapping_Manifest::MAXIMUM_REQUIREMENTS
		) {
			throw new InvalidArgumentException( 'Legacy migration mapping totals drifted.' );
		}

		$seen            = array();
		$contexts        = array();
		$manifest_counts = array(
			'context' => 0,
			'user'    => 0,
		);
		foreach ( $manifest as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Legacy migration mapping manifest is invalid.' );
			}
			$kind       = $item['kind'] ?? null;
			$source_ref = $item['source_ref'] ?? null;
			if (
				! in_array( $kind, array( 'context', 'user' ), true )
				|| ! is_string( $source_ref )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_ref )
				|| isset( $seen[ $source_ref ] )
			) {
				throw new InvalidArgumentException( 'Legacy migration mapping manifest identity is invalid.' );
			}
			$seen[ $source_ref ] = true;
			++$manifest_counts[ $kind ];
			if ( 'context' === $kind ) {
				$this->exact_keys( $item, array( 'kind', 'source_ref', 'source_type' ) );
				if ( ! in_array( $item['source_type'] ?? null, array( 'class', 'group' ), true ) ) {
					throw new InvalidArgumentException( 'Legacy migration Context requirement is invalid.' );
				}
				$contexts[ $source_ref ] = true;
			} else {
				$expected = $assignment_bound
					? array( 'assignment_source_ref', 'context_source_ref', 'kind', 'source_ref' )
					: array( 'context_source_ref', 'kind', 'source_ref' );
				$this->exact_keys( $item, $expected );
				if ( ! is_string( $item['context_source_ref'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $item['context_source_ref'] ) ) {
					throw new InvalidArgumentException( 'Legacy migration learner requirement is invalid.' );
				}
				if ( $assignment_bound && ( ! is_string( $item['assignment_source_ref'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $item['assignment_source_ref'] ) ) ) {
					throw new InvalidArgumentException( 'Legacy migration learner assignment requirement is invalid.' );
				}
			}
		}
		foreach ( $manifest as $item ) {
			if ( 'user' === $item['kind'] && ! isset( $contexts[ $item['context_source_ref'] ] ) ) {
				throw new InvalidArgumentException( 'Legacy migration learner Context requirement is missing.' );
			}
		}
		if ( $manifest_counts['context'] !== $requirements['context'] || $manifest_counts['user'] !== $requirements['user'] ) {
			throw new InvalidArgumentException( 'Legacy migration mapping requirement count drifted.' );
		}
	}

	/**
	 * Build legacy.
	 *
	 * @param string                 $site_id Site id.
	 * @param Legacy_Source_Snapshot $source Source.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function build_legacy( string $site_id, Legacy_Source_Snapshot $source ): array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/D', $site_id ) ) {
			throw new InvalidArgumentException( 'Legacy migration plan site is invalid.' );
		}
		$plan              = array(
			'profile'              => self::LEGACY_PROFILE,
			'site'                 => $site_id,
			'source'               => array(
				'profile'     => $source->profile(),
				'version'     => $source->source_version(),
				'fingerprint' => $source->source_fingerprint(),
			),
			'inventory'            => array(
				'table_count' => $source->table_count(),
				'row_count'   => $source->row_count(),
				'blob_count'  => $source->blob_count(),
				'blob_bytes'  => $source->blob_bytes(),
				'tables'      => $source->tables(),
			),
			'mapping_decisions'    => array(),
			'mapping_requirements' => $source->mapping_requirements(),
			'exclusions'           => $source->exclusions(),
			'conflicts'            => $source->conflicts(),
			'counts'               => $source->counts(),
		);
		$plan['plan_hash'] = hash( 'sha256', $this->canonical->encode( $plan ) );
		return $plan;
	}

	/**
	 * Reject invalid legacy valid.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_legacy_valid( array $plan ): void {
		$expected = array( 'profile', 'site', 'source', 'inventory', 'mapping_decisions', 'mapping_requirements', 'exclusions', 'conflicts', 'counts', 'plan_hash' );
		$actual   = array_keys( $plan );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if (
			$actual !== $expected
			|| array() !== ( $plan['mapping_decisions'] ?? null )
			|| ! is_array( $plan['source'] ?? null )
			|| ! is_array( $plan['counts'] ?? null )
			|| ! is_string( $plan['plan_hash'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_hash'] )
		) {
			throw new InvalidArgumentException( 'Legacy migration plan is invalid.' );
		}
		$without_hash = $plan;
		$hash         = $without_hash['plan_hash'];
		unset( $without_hash['plan_hash'] );
		if ( ! hash_equals( (string) $hash, hash( 'sha256', $this->canonical->encode( $without_hash ) ) ) ) {
			throw new InvalidArgumentException( 'Legacy migration plan hash is invalid.' );
		}
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Legacy migration mapping target keys are invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( $value, string $label ): void {
		if ( ! is_string( $value ) || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy migration ' . $label . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}

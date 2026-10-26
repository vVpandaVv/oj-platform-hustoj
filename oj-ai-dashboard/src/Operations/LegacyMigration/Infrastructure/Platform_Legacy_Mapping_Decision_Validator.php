<?php
/**
 * Platform legacy mapping decision validator.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Mapping_Decision_Validator_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Roster_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirms approved Context and learner targets against the current platform roster.
 */
final class Platform_Legacy_Mapping_Decision_Validator implements Legacy_Mapping_Decision_Validator_Port_Interface {
	/**
	 * Initialize platform legacy mapping decision validator dependencies and state.
	 *
	 * @param Assignment_Roster_Port_Interface $rosters Rosters.
	 */
	public function __construct( private Assignment_Roster_Port_Interface $rosters ) {
	}

	/**
	 * Validate platform legacy mapping decision validator.
	 *
	 * @param int   $actor_user_id Authenticated actor from the access context.
	 * @param array $manifest Manifest.
	 * @param array $decisions Decisions.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function validate( int $actor_user_id, array $manifest, array $decisions ): void {
		try {
			$requirements = array();
			foreach ( $manifest as $requirement ) {
				$requirements[ (string) $requirement['source_ref'] ] = $requirement;
			}
			$approved = array();
			foreach ( $decisions as $decision ) {
				$approved[ (string) $decision['source_ref'] ] = $decision;
			}
			if ( count( $requirements ) !== count( $approved ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
			}

			$rosters      = array();
			$roster_cache = array();
			foreach ( $requirements as $source_ref => $requirement ) {
				if ( 'context' !== ( $requirement['kind'] ?? null ) ) {
					continue;
				}
				$decision = $approved[ $source_ref ] ?? null;
				$target   = is_array( $decision ) ? ( $decision['target'] ?? null ) : null;
				if ( ! is_array( $target ) ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
				}
				$cache_key = implode( '|', array( $target['context_type'], $target['context_id'], $target['context_revision'], $target['roster_revision'] ) );
				if ( ! isset( $roster_cache[ $cache_key ] ) ) {
					$snapshot = $this->rosters->collect(
						$actor_user_id,
						(string) $target['context_type'],
						(string) $target['context_id'],
						(string) $target['context_revision']
					);
					if ( ! hash_equals( (string) $target['roster_revision'], $snapshot->roster_revision() ) ) {
						throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
					}
					$learners = array();
					foreach ( $snapshot->learners() as $learner ) {
						$learners[ $learner['learner_user_id'] ] = $learner['membership_revision'];
					}
					$roster_cache[ $cache_key ] = $learners;
				}
				$rosters[ $source_ref ] = $roster_cache[ $cache_key ];
			}

			foreach ( $requirements as $source_ref => $requirement ) {
				if ( 'user' !== ( $requirement['kind'] ?? null ) ) {
					continue;
				}
				$context_ref = $requirement['context_source_ref'] ?? null;
				$decision    = $approved[ $source_ref ] ?? null;
				$target      = is_array( $decision ) ? ( $decision['target'] ?? null ) : null;
				$user_id     = is_array( $target ) ? ( $target['learner_user_id'] ?? null ) : null;
				$membership  = is_array( $target ) ? ( $target['membership_revision'] ?? null ) : null;
				if (
					! is_string( $context_ref )
					|| ! is_int( $user_id )
					|| ! is_string( $membership )
					|| ! isset( $rosters[ $context_ref ][ $user_id ] )
					|| ! hash_equals( $membership, (string) $rosters[ $context_ref ][ $user_id ] )
				) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED );
				}
			}
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::MAPPING_REQUIRED, $error );
		}
	}
}

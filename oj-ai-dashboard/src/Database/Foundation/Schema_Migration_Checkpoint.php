<?php
/**
 * Versioned schema migration checkpoint envelope and integrity rules.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use JsonException;
use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encodes checkpoints and verifies stored restart evidence before it is trusted.
 */
final class Schema_Migration_Checkpoint {
	public const SCHEMA  = 'oj_ai.schema_migration_checkpoint';
	public const VERSION = 1;

	/**
	 * Columns.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint payload.
	 * @return array{checkpoint_schema:string,checkpoint_version:int,checkpoint_json:string,checkpoint_hash:string}
	 */
	public static function columns( array $checkpoint ): array {
		$json = self::encode( $checkpoint );

		return array(
			'checkpoint_schema'  => self::SCHEMA,
			'checkpoint_version' => self::VERSION,
			'checkpoint_json'    => $json,
			'checkpoint_hash'    => hash( 'sha256', $json ),
		);
	}

	/**
	 * Issues.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $record Stored migration record.
	 * @return array<int,array{code:string,migration_id:string}>
	 */
	public static function issues( Migration_Definition $migration, array $record ): array {
		$migration_id = $migration->id();
		$required     = array( 'checkpoint_schema', 'checkpoint_version', 'checkpoint_json', 'checkpoint_hash' );
		foreach ( $required as $field ) {
			if ( ! isset( $record[ $field ] ) ) {
				return array( self::issue( 'schema_checkpoint_missing', $migration_id ) );
			}
		}

		$schema = (string) $record['checkpoint_schema'];
		if ( ! hash_equals( self::SCHEMA, $schema ) ) {
			return array( self::issue( 'schema_checkpoint_schema_invalid', $migration_id ) );
		}

		$version = $record['checkpoint_version'];
		if ( ! is_int( $version ) && ! ( is_string( $version ) && ctype_digit( $version ) ) ) {
			return array( self::issue( 'schema_checkpoint_version_invalid', $migration_id ) );
		}
		if ( self::VERSION !== (int) $version ) {
			return array( self::issue( 'schema_checkpoint_version_invalid', $migration_id ) );
		}

		$json = $record['checkpoint_json'];
		$hash = (string) $record['checkpoint_hash'];
		if ( ! is_string( $json ) || '' === $json || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return array( self::issue( 'schema_checkpoint_envelope_invalid', $migration_id ) );
		}
		if ( ! hash_equals( hash( 'sha256', $json ), $hash ) ) {
			return array( self::issue( 'schema_checkpoint_hash_mismatch', $migration_id ) );
		}

		try {
			$checkpoint = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return array( self::issue( 'schema_checkpoint_json_invalid', $migration_id ) );
		}
		if ( ! is_array( $checkpoint ) || array_is_list( $checkpoint ) ) {
			return array( self::issue( 'schema_checkpoint_payload_invalid', $migration_id ) );
		}
		if ( ! hash_equals( $json, self::encode( $checkpoint ) ) ) {
			return array( self::issue( 'schema_checkpoint_json_noncanonical', $migration_id ) );
		}
		if ( ! self::payload_is_valid( $migration, $checkpoint )
			|| ! self::state_matches_payload( (string) ( $record['state'] ?? '' ), $checkpoint ) ) {
			return array( self::issue( 'schema_checkpoint_payload_invalid', $migration_id ) );
		}

		return array();
	}

	/**
	 * Return the canonical payload only after the stored envelope is verified.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $record Stored migration record.
	 * @return array<string,mixed>|null
	 */
	public static function validated_payload( Migration_Definition $migration, array $record ): ?array {
		if ( array() !== self::issues( $migration, $record ) ) {
			return null;
		}

		try {
			$checkpoint = json_decode( (string) $record['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}

		return is_array( $checkpoint ) && ! array_is_list( $checkpoint ) ? $checkpoint : null;
	}

	/**
	 * Encode schema migration checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function encode( array $checkpoint ): string {
		$json = json_encode( $checkpoint, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json ) {
			throw new LogicException( 'Unable to encode a schema migration checkpoint.' );
		}

		return $json;
	}

	/**
	 * Payload is valid.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @return bool
	 */
	private static function payload_is_valid( Migration_Definition $migration, array $checkpoint ): bool {
		$required      = array(
			'phase',
			'cursor',
			'completed_tables',
			'next_table',
			'postcondition',
			'correlation_id',
			'repair',
		);
		$repair_fields = array( 'approval_token_hash', 'backup_evidence_hash', 'backup_verified_at_utc' );
		$allowed       = array_merge( $required, $repair_fields );
		if ( array() !== array_diff( $required, array_keys( $checkpoint ) )
			|| array() !== array_diff( array_keys( $checkpoint ), $allowed ) ) {
			return false;
		}

		$phase         = $checkpoint['phase'];
		$postcondition = $checkpoint['postcondition'];
		$correlation   = $checkpoint['correlation_id'];
		if ( ! is_string( $phase ) || ! in_array( $phase, array( 'applying', 'completed', 'failed' ), true )
			|| ! is_string( $postcondition ) || ! in_array( $postcondition, array( 'pending', 'passed', 'failed' ), true )
			|| ! is_string( $correlation ) || 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $correlation )
			|| ! is_bool( $checkpoint['repair'] ) ) {
			return false;
		}
		if ( ( 'completed' === $phase && 'passed' !== $postcondition )
			|| ( 'failed' === $phase && 'failed' !== $postcondition )
			|| ( 'applying' === $phase && 'failed' === $postcondition ) ) {
			return false;
		}

		$completed = $checkpoint['completed_tables'];
		if ( ! is_array( $completed ) || ! array_is_list( $completed ) || ! is_int( $checkpoint['cursor'] ) ) {
			return false;
		}
		foreach ( $completed as $table_name ) {
			if ( ! is_string( $table_name ) ) {
				return false;
			}
		}
		$ordered = array_values( array_intersect( $migration->table_names(), $completed ) );
		if ( $ordered !== $completed || count( $completed ) !== count( array_unique( $completed ) )
			|| count( $completed ) !== $checkpoint['cursor'] ) {
			return false;
		}

		$remaining = array_values( array_diff( $migration->table_names(), $completed ) );
		$next      = $checkpoint['next_table'];
		if ( null !== $next && ! is_string( $next ) ) {
			return false;
		}
		if ( ( $remaining[0] ?? null ) !== $next ) {
			return false;
		}
		if ( 'completed' === $phase && array() !== $remaining ) {
			return false;
		}

		if ( true === $checkpoint['repair'] ) {
			foreach ( $repair_fields as $field ) {
				if ( ! isset( $checkpoint[ $field ] ) || ! is_string( $checkpoint[ $field ] ) ) {
					return false;
				}
			}
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkpoint['approval_token_hash'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkpoint['backup_evidence_hash'] )
				|| 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $checkpoint['backup_verified_at_utc'] ) ) {
				return false;
			}
		} elseif ( array() !== array_intersect( $repair_fields, array_keys( $checkpoint ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * State matches payload.
	 *
	 * @param string              $state State.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return bool
	 */
	private static function state_matches_payload( string $state, array $checkpoint ): bool {
		$phase         = (string) $checkpoint['phase'];
		$postcondition = (string) $checkpoint['postcondition'];

		return match ( $state ) {
			Migration_Ledger_State::RUNNING => 'applying' === $phase,
			Migration_Ledger_State::APPLIED => 'completed' === $phase && 'passed' === $postcondition,
			Migration_Ledger_State::REPAIR_REQUIRED,
			Migration_Ledger_State::BLOCKED => 'failed' === $phase && 'failed' === $postcondition,
			Migration_Ledger_State::PLANNED => true,
			default => false,
		};
	}

	/**
	 * Issue.
	 *
	 * @param string $code Code.
	 * @param string $migration_id Migration id.
	 * @return array{code:string,migration_id:string}
	 */
	private static function issue( string $code, string $migration_id ): array {
		return array(
			'code'         => $code,
			'migration_id' => $migration_id,
		);
	}
}

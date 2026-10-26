<?php
/**
 * Frozen database column profiles.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands the A-DS-05 PK, PID, MUT, IMM, SYS, JSON, and CTX profiles.
 */
final class Schema_Profile {
	public const PK  = 'PK';
	public const PID = 'PID';
	public const MUT = 'MUT';
	public const IMM = 'IMM';
	public const SYS = 'SYS';
	public const CTX = 'CTX';

	/**
	 * Expand fixed profiles into ordered SQL column definitions.
	 *
	 * @param array<int,string> $profiles Profile names.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function columns( array $profiles ): array {
		$columns = array();

		foreach ( $profiles as $profile ) {
			switch ( $profile ) {
				case self::PK:
					$columns['id'] = 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
					break;
				case self::PID:
					$columns['public_id'] = 'CHAR(26) NOT NULL';
					break;
				case self::MUT:
					$columns['lock_version']       = 'BIGINT UNSIGNED NOT NULL DEFAULT 0';
					$columns['created_by_user_id'] = 'BIGINT UNSIGNED NOT NULL';
					$columns['created_at_utc']     = 'DATETIME NOT NULL';
					$columns['updated_by_user_id'] = 'BIGINT UNSIGNED NOT NULL';
					$columns['updated_at_utc']     = 'DATETIME NOT NULL';
					break;
				case self::IMM:
					$columns['content_hash']       = 'CHAR(64) NOT NULL';
					$columns['created_by_user_id'] = 'BIGINT UNSIGNED NOT NULL';
					$columns['created_at_utc']     = 'DATETIME NOT NULL';
					break;
				case self::SYS:
					$columns['created_at_utc'] = 'DATETIME NOT NULL';
					break;
				case self::CTX:
					$columns = array_merge( $columns, self::context_columns() );
					break;
				default:
					throw new InvalidArgumentException( 'Unknown schema profile: ' . $profile ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
		}

		return $columns;
	}

	/**
	 * Expand a versioned JSON payload profile.
	 *
	 * @param string $name Column stem.
	 * @param bool   $nullable Whether all four physical columns are nullable.
	 * @return array<string,string>
	 */
	public static function json_columns( string $name, bool $nullable = false ): array {
		self::assert_identifier( $name );
		$nullability = $nullable ? 'NULL' : 'NOT NULL';

		return array(
			$name . '_schema'  => 'VARCHAR(100) ' . $nullability,
			$name . '_version' => 'SMALLINT UNSIGNED ' . $nullability,
			$name . '_json'    => 'LONGTEXT ' . $nullability,
			$name . '_hash'    => 'CHAR(64) ' . $nullability,
		);
	}

	/**
	 * Expand a platform context snapshot profile.
	 *
	 * @param bool $nullable Whether the context is optional.
	 * @return array<string,string>
	 */
	public static function context_columns( bool $nullable = false ): array {
		$nullability = $nullable ? 'NULL' : 'NOT NULL';

		return array(
			'context_type'     => 'VARCHAR(16) ' . $nullability,
			'context_id'       => 'VARCHAR(191) ' . $nullability,
			'context_revision' => 'VARCHAR(191) ' . $nullability,
		);
	}

	/**
	 * Merge ordered column maps while rejecting accidental redefinition.
	 *
	 * @param array<string,string> ...$sets Column maps.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function merge_columns( array ...$sets ): array {
		$columns = array();

		foreach ( $sets as $set ) {
			foreach ( $set as $name => $definition ) {
				self::assert_identifier( (string) $name );
				if ( isset( $columns[ $name ] ) ) {
					throw new InvalidArgumentException( 'Duplicate schema column: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
				}
				$columns[ $name ] = trim( (string) $definition );
			}
		}

		return $columns;
	}

	/**
	 * Reject invalid identifier.
	 *
	 * @param string $identifier SQL identifier.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $identifier ) ) {
			throw new InvalidArgumentException( 'Invalid schema identifier: ' . $identifier ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}

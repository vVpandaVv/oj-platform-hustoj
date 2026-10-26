<?php
/**
 * WordPress private-file participant for worksheet saves.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Binder_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps private-file SQL in the Storage owner while sharing the save transaction.
 */
final class WordPress_Worksheet_Asset_Binder implements Worksheet_Asset_Binder_Port_Interface {
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const MAX_FILE_BYTES      = 20 * 1024 * 1024;
	private const MAX_REVISION_BYTES  = 200 * 1024 * 1024;

	private const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );
	private const AUDIO_MIMES = array( 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav' );
	private const VIDEO_MIMES = array( 'video/mp4', 'video/webm' );
	private const FILE_MIMES  = array(
		'application/pdf',
		'text/plain',
		'text/csv',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);

	/**
	 * Database.
	 *
	 * @var object|null
	 */
	private ?object $database;

	/**
	 * Initialize WordPress worksheet asset binder dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( ?object $database = null ) {
		$this->database = $database;
	}

	/**
	 * Bind for revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_public_id Worksheet public id.
	 * @param array  $asset_bindings Asset bindings.
	 * @param array  $staged_receipt_ids Staged receipt ids.
	 * @param string $saved_at_utc Saved at utc.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function bind_for_revision(
		int $actor_user_id,
		string $worksheet_public_id,
		array $asset_bindings,
		array $staged_receipt_ids,
		string $saved_at_utc
	): array {
		if ( array() === $asset_bindings ) {
			if ( array() !== $staged_receipt_ids ) {
				throw Worksheet_Failure::asset_not_ready();
			}
			return array();
		}

		$asset_ids = array_column( $asset_bindings, 'asset_id' );
		$rows      = $this->load_rows_for_update( $asset_ids );
		$by_id     = array();
		foreach ( $rows as $row ) {
			$id = is_string( $row['public_id'] ?? null ) ? $row['public_id'] : '';
			try {
				new Public_Id( $id );
			} catch ( \InvalidArgumentException ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			if ( isset( $by_id[ $id ] ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$by_id[ $id ] = $row;
		}
		if ( count( $by_id ) !== count( $asset_ids ) ) {
			throw Worksheet_Failure::asset_not_ready();
		}

		$staged      = array_fill_keys( $staged_receipt_ids, true );
		$used_staged = array();
		$total_bytes = 0;
		$receipts    = array();
		foreach ( $asset_bindings as $binding ) {
			$asset_id = (string) $binding['asset_id'];
			$row      = $by_id[ $asset_id ] ?? null;
			if ( ! is_array( $row ) ) {
				throw Worksheet_Failure::asset_not_ready();
			}
			if (
				'worksheet' !== (string) ( $row['owner_type'] ?? '' )
				|| $worksheet_public_id !== (string) ( $row['owner_public_id'] ?? '' )
				|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
				|| 'clean' !== (string) ( $row['scan_state'] ?? '' )
			) {
				throw Worksheet_Failure::asset_not_ready();
			}

			$state = (string) ( $row['state'] ?? '' );
			if ( isset( $staged[ $asset_id ] ) ) {
				if ( 'quarantined' !== $state ) {
					throw Worksheet_Failure::asset_not_ready();
				}
				$used_staged[ $asset_id ] = true;
			} elseif ( 'ready' !== $state ) {
				throw Worksheet_Failure::asset_not_ready();
			}

			$byte_size    = $this->positive_integer( $row['byte_size'] ?? null );
			$total_bytes += $byte_size;
			if ( $byte_size > self::MAX_FILE_BYTES || $total_bytes > self::MAX_REVISION_BYTES ) {
				throw Worksheet_Failure::asset_not_ready();
			}
			$mime_type = (string) ( $row['mime_type'] ?? '' );
			$this->assert_reference_mime( $mime_type, $binding['references'] );
			$file_hash = (string) ( $row['sha256'] ?? '' );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $file_hash ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}

			$receipts[] = array(
				'asset_id'     => $asset_id,
				'binding_hash' => (string) $binding['content_hash'],
				'file_hash'    => $file_hash,
				'mime_type'    => $mime_type,
				'byte_size'    => $byte_size,
			);
		}
		if ( count( $used_staged ) !== count( $staged ) ) {
			throw Worksheet_Failure::asset_not_ready();
		}

		foreach ( array_keys( $used_staged ) as $asset_id ) {
			$this->promote_staged( $actor_user_id, $worksheet_public_id, $asset_id, $saved_at_utc );
		}

		return $receipts;
	}

	/**
	 * Load rows for update.
	 *
	 * @param array<int,string> $asset_ids Asset ids.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function load_rows_for_update( array $asset_ids ): array {
		$placeholders = implode( ', ', array_fill( 0, count( $asset_ids ), '%s' ) );
		$sql          = 'SELECT public_id, owner_type, owner_public_id, purpose, mime_type, byte_size, sha256, state, scan_state '
			. 'FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id IN (' . $placeholders . ') FOR UPDATE';
		$database     = $this->database();
		$this->reset_error( $database );
		$prepared = $database->prepare( $sql, ...$asset_ids );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		$rows = $database->get_results( $prepared, $this->array_output() );
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}
		return $rows;
	}

	/**
	 * Promote staged.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_public_id Worksheet public id.
	 * @param string $asset_id Asset id.
	 * @param string $saved_at_utc Saved at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function promote_staged(
		int $actor_user_id,
		string $worksheet_public_id,
		string $asset_id,
		string $saved_at_utc
	): void {
		$sql      = 'UPDATE ' . self::TABLE_PRIVATE_FILES
			. ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
			. 'updated_at_utc = %s, expires_at_utc = NULL WHERE public_id = %s AND owner_type = %s '
			. 'AND owner_public_id = %s AND purpose = %s AND state = %s AND scan_state = %s';
		$database = $this->database();
		$prepared = $database->prepare(
			$sql,
			'ready',
			$actor_user_id,
			$saved_at_utc,
			$asset_id,
			'worksheet',
			$worksheet_public_id,
			'worksheet_asset',
			'quarantined',
			'clean'
		);
		if ( ! is_string( $prepared ) || 1 !== $database->query( $prepared ) ) {
			throw new RuntimeException( 'Worksheet asset promotion failed.' );
		}
	}

	/**
	 * Reject invalid reference mime.
	 *
	 * @param string                         $mime_type Mime type.
	 * @param array<int,array<string,mixed>> $references References.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_reference_mime( string $mime_type, array $references ): void {
		foreach ( $references as $reference ) {
			$node_type = (string) ( $reference['node_type'] ?? '' );
			$property  = (string) ( $reference['property'] ?? '' );
			$allowed   = self::FILE_MIMES;
			if ( 'poster_asset_id' === $property || in_array( $node_type, array( 'img', 'oj_ai_drawing' ), true ) ) {
				$allowed = self::IMAGE_MIMES;
			} elseif ( 'audio' === $node_type ) {
				$allowed = self::AUDIO_MIMES;
			} elseif ( 'video' === $node_type ) {
				$allowed = self::VIDEO_MIMES;
			}
			if ( ! in_array( $mime_type, $allowed, true ) ) {
				throw Worksheet_Failure::asset_not_ready();
			}
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$value = (int) $value;
		if ( $value < 1 ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $value;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		foreach ( array( 'prepare', 'get_results', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw Worksheet_Failure::storage_unavailable();
			}
		}
		return $database;
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( property_exists( $database, 'last_error' ) && '' !== trim( (string) $database->last_error ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
	}

	/**
	 * Array output.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}
}

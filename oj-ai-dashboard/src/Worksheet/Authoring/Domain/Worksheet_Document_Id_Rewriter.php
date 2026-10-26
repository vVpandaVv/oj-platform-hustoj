<?php
/**
 * Reissues worksheet-owned document identifiers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shares the same deterministic identity rules between import and duplicate.
 */
final class Worksheet_Document_Id_Rewriter {
	private const ALPHABET         = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	private const INTERNAL_ID_KEYS = array( 'column_id', 'document_id', 'goal_id', 'node_id', 'option_id', 'response_id', 'row_id' );
	private const ASSET_ID_KEYS    = array( 'asset_id', 'poster_asset_id' );

	/**
	 * Rewrite.
	 *
	 * @param array<string,mixed>            $teacher_document Teacher document.
	 * @param array<int,array<string,mixed>> $achievement_links Achievement links.
	 * @param array<string,string>           $asset_id_map Asset id map.
	 * @param string                         $seed Seed.
	 * @return array{teacher_document:array<string,mixed>,achievement_links:array<int,array<string,mixed>>,reissued_id_count:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function rewrite(
		array $teacher_document,
		array $achievement_links,
		array $asset_id_map,
		string $seed
	): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $seed ) ) {
			throw new InvalidArgumentException( 'Worksheet ID rewrite seed is invalid.' );
		}
		$this->assert_asset_manifest( $teacher_document, $asset_id_map );

		$source_ids = array();
		$this->collect_internal_ids( $teacher_document, $source_ids );
		if ( ! is_string( $teacher_document['document_id'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet document identity is invalid.' );
		}

		$reserved = array_fill_keys( array_values( $asset_id_map ), true );
		$id_map   = array();
		foreach ( array_keys( $source_ids ) as $source_id ) {
			$id_map[ $source_id ]              = $this->deterministic_id( $seed, $source_id, $reserved );
			$reserved[ $id_map[ $source_id ] ] = true;
		}

		$document = $this->rewrite_value( $teacher_document, null, null, $id_map, $asset_id_map );
		$links    = $this->rewrite_value( $achievement_links, null, null, $id_map, $asset_id_map );
		if ( ! is_array( $document ) || array_is_list( $document ) || ! is_array( $links ) || ! array_is_list( $links ) ) {
			throw new InvalidArgumentException( 'Worksheet rewritten document is invalid.' );
		}

		$document['settings']['preparation'] = Worksheet_Preparation::draft();
		if ( isset( $document['settings']['assessment_project'] ) ) {
			unset( $document['settings']['assessment_project'] );
			$document['settings']['assessment_bindings'] = array();
		}
		return array(
			'teacher_document'  => $document,
			'achievement_links' => $links,
			'reissued_id_count' => count( $id_map ) + count( $asset_id_map ),
		);
	}

	/**
	 * Asset ids.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<int,string>
	 */
	public function asset_ids( array $document ): array {
		$ids = array();
		$this->collect_asset_ids( $document, $ids );
		$result = array_keys( $ids );
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Reject invalid asset manifest.
	 *
	 * @param array<string,mixed>  $document Document.
	 * @param array<string,string> $asset_id_map Asset id map.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assert_asset_manifest( array $document, array $asset_id_map ): void {
		$source_ids = array_keys( $asset_id_map );
		foreach ( $source_ids as $source_id ) {
			new Public_Id( $source_id );
			new Public_Id( $asset_id_map[ $source_id ] );
		}
		sort( $source_ids, SORT_STRING );
		if ( $source_ids !== $this->asset_ids( $document ) ) {
			throw new InvalidArgumentException( 'Worksheet asset manifest is invalid.' );
		}
	}

	/**
	 * Collect internal ids.
	 *
	 * @param mixed              $value Value.
	 * @param array<string,bool> &$ids Ids.
	 * @param string|null        $key Key.
	 * @return void
	 */
	private function collect_internal_ids( $value, array &$ids, ?string $key = null ): void {
		if ( is_string( $value ) && null !== $key && in_array( $key, self::INTERNAL_ID_KEYS, true ) ) {
			new Public_Id( $value );
			$ids[ $value ] = true;
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $child_key => $child ) {
			$this->collect_internal_ids( $child, $ids, is_string( $child_key ) ? $child_key : null );
		}
	}

	/**
	 * Collect asset ids.
	 *
	 * @param mixed              $value Value.
	 * @param array<string,bool> &$ids Ids.
	 * @param string|null        $key Key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function collect_asset_ids( $value, array &$ids, ?string $key = null ): void {
		if ( null !== $key && in_array( $key, self::ASSET_ID_KEYS, true ) ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Worksheet asset identity is invalid.' );
			}
			new Public_Id( $value );
			$ids[ $value ] = true;
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $child_key => $child ) {
			$this->collect_asset_ids( $child, $ids, is_string( $child_key ) ? $child_key : null );
		}
	}

	/**
	 * Rewrite value.
	 *
	 * @param mixed                $value Value.
	 * @param string|null          $key Key.
	 * @param string|null          $parent_key Parent key.
	 * @param array<string,string> $id_map Id map.
	 * @param array<string,string> $asset_map Asset map.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rewrite_value( $value, ?string $key, ?string $parent_key, array $id_map, array $asset_map ) {
		if ( is_string( $value ) ) {
			if ( null !== $key && in_array( $key, self::ASSET_ID_KEYS, true ) ) {
				return $asset_map[ $value ] ?? throw new InvalidArgumentException( 'Worksheet asset mapping is incomplete.' );
			}
			if ( 'standard_public_id' === $key || 'criterion_id' === $key || 'rubric_criterion_ids' === $parent_key ) {
				return $value;
			}
			return $id_map[ $value ] ?? $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$result = array();
		foreach ( $value as $child_key => $child ) {
			$child_name           = is_string( $child_key ) ? $child_key : null;
			$result[ $child_key ] = $this->rewrite_value( $child, $child_name, $key, $id_map, $asset_map );
		}
		return $result;
	}

	/**
	 * Deterministic id.
	 *
	 * @param string             $seed Seed.
	 * @param string             $source_id Source id.
	 * @param array<string,bool> $reserved Reserved.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deterministic_id( string $seed, string $source_id, array $reserved ): string {
		for ( $nonce = 0; $nonce < 16; ++$nonce ) {
			$bytes = substr( hash( 'sha256', $seed . '|' . $source_id . '|' . $nonce, true ), 0, 16 );
			$id    = $this->encode( $bytes );
			if ( $id !== $source_id && ! isset( $reserved[ $id ] ) ) {
				return $id;
			}
		}
		throw new InvalidArgumentException( 'Worksheet ID rewrite exhausted its collision limit.' );
	}

	/**
	 * Encode worksheet document id rewriter.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private function encode( string $bytes ): string {
		$output     = '';
		$buffer     = 0;
		$bits       = 0;
		$byte_count = strlen( $bytes );
		for ( $index = 0; $index < $byte_count; ++$index ) {
			$buffer = ( $buffer << 8 ) | ord( $bytes[ $index ] );
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits   -= 5;
				$output .= self::ALPHABET[ ( $buffer >> $bits ) & 31 ];
			}
			$buffer = 0 === $bits ? 0 : $buffer & ( ( 1 << $bits ) - 1 );
		}
		if ( $bits > 0 ) {
			$output .= self::ALPHABET[ ( $buffer << ( 5 - $bits ) ) & 31 ];
		}
		return $output;
	}
}

<?php
/**
 * Reissues document-owned IDs during portable template import.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Import_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reissues document-owned IDs during portable template import.
 */
final class Worksheet_Template_Id_Rewriter {
	private const ASSET_ID_KEYS = array( 'asset_id', 'poster_asset_id' );
	/**
	 * Rewriter.
	 *
	 * @var Worksheet_Document_Id_Rewriter
	 */
	private Worksheet_Document_Id_Rewriter $rewriter;

	/**
	 * Initialize worksheet template id rewriter dependencies and state.
	 *
	 * @param Worksheet_Document_Id_Rewriter|null $rewriter Rewriter.
	 */
	public function __construct( ?Worksheet_Document_Id_Rewriter $rewriter = null ) {
		$this->rewriter = $rewriter ?? new Worksheet_Document_Id_Rewriter();
	}


	/**
	 * Rewrite.
	 *
	 * @param array<string,mixed>            $teacher_document Teacher document.
	 * @param array<int,array<string,mixed>> $achievement_links Achievement links.
	 * @param array<string,string>           $asset_id_map Source asset ID to staged destination ID.
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
		try {
			return $this->rewriter->rewrite( $teacher_document, $achievement_links, $asset_id_map, $seed );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
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
		$references = array();
		$this->collect_asset_ids( $document, $references );
		try {
			$this->rewriter->assert_asset_manifest( $document, $asset_id_map );
			return;
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
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
				throw Worksheet_Template_Import_Failure::package_invalid();
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
}

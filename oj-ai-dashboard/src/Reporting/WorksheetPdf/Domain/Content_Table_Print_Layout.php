<?php
/**
 * Shared content-table widths for worksheet and submission output.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Applies validated column proportions to both browser and PDF table cells. */
final class Content_Table_Print_Layout {
	/**
	 * Render a sized content table using the caller's allowlisted child renderer.
	 *
	 * @param array<string,mixed> $node Table node.
	 * @param callable            $render Child renderer.
	 * @return string
	 * @throws InvalidArgumentException When widths or table structure are invalid.
	 */
	public static function render( array $node, callable $render ): string {
		$percent  = $node['width_percent'] ?? null;
		$relative = array_key_exists( 'width_percent', $node ) || array_key_exists( 'column_ratios', $node );
		if ( $relative && ( ! is_int( $percent ) || $percent < 1 || $percent > 100 || array_key_exists( 'column_widths', $node ) ) ) {
			throw new InvalidArgumentException( 'Content table percentage is invalid.' );
		}
		$widths = $relative ? ( $node['column_ratios'] ?? null ) : ( $node['column_widths'] ?? null );
		if ( ! is_array( $widths ) || ! array_is_list( $widths ) || empty( $widths ) || count( $widths ) > Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS ) {
			throw new InvalidArgumentException( 'Content table widths are invalid.' );
		}
		foreach ( $widths as $width ) {
			if ( ! is_int( $width ) || $width < ( $relative ? 1 : 40 ) || $width > ( $relative ? 10000 : 1600 ) ) {
				throw new InvalidArgumentException( 'Content table width is invalid.' );
			}
		}
		$total = array_sum( $widths );
		if ( $relative && 10000 !== $total ) {
			throw new InvalidArgumentException( 'Content table ratios are invalid.' );
		}
		$body = '';
		foreach ( $node['children'] ?? array() as $row ) {
			$cells = $row['children'] ?? null;
			if ( 'tr' !== ( $row['type'] ?? null ) || ! is_array( $cells ) || ! array_is_list( $cells ) || count( $cells ) !== count( $widths ) ) {
				throw new InvalidArgumentException( 'Content table row is invalid.' );
			}
			$body .= '<tr>';
			foreach ( $cells as $index => $cell ) {
				$type = $cell['type'] ?? null;
				if ( ! in_array( $type, array( 'td', 'th' ), true ) ) {
					throw new InvalidArgumentException( 'Content table cell is invalid.' );
				}
				$body .= '<' . $type . ' style="width:' . ( $widths[ $index ] / $total * 100 ) . '%">';
				foreach ( $cell['children'] ?? array() as $child ) {
					$body .= $render( $child );
				}
				$body .= '</' . $type . '>';
			}
			$body .= '</tr>';
		}
		// Dompdf reads cell widths, not colgroups, and constrains block max-width reliably.
		$width = $relative ? $percent . '%' : $total . 'px';
		return '<div style="width:' . $width . ';max-width:100%"><table style="width:100%;table-layout:fixed"><tbody>' . $body . '</tbody></table></div>';
	}
}

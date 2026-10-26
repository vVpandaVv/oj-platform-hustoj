<?php
/**
 * Worksheet print html serializer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes only the P05 allowlist; user HTML is never accepted.
 */
final class Worksheet_Print_Html_Serializer {
	/**
	 * Serialize.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $document_id Document id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serialize( array $projection, string $document_id ): string {
		$envelope = $projection['print_projection'] ?? null;
		$payload  = is_array( $envelope ) ? ( $envelope['payload'] ?? null ) : null;
		$document = is_array( $payload ) ? ( $payload['document'] ?? null ) : null;
		if ( ! is_array( $envelope ) || ! is_array( $payload ) || ! is_array( $document )
			|| Worksheet_Preview_Projector::PRINT_SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| 'student_blank' !== ( $payload['profile'] ?? null ) || ! is_array( $document['nodes'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet print projection is invalid.' );
		}
		$title = $this->escape( (string) ( $document['title'] ?? '' ) );
		$body  = '';
		foreach ( $document['nodes'] as $node ) {
			$body .= $this->node( $node );
		}
		$id = $this->escape( $document_id );
		return '<!doctype html><html lang="' . $this->escape( (string) ( $document['locale'] ?? 'ko-KR' ) ) . '"><head><meta charset="UTF-8"><style>'
			. $this->css() . '.question{background:#fff;border:0;border-top:1px solid #ccc;margin:6mm 0;padding:4mm 0 0;font-style:normal}.question>h2:first-child{margin-top:0}</style></head><body><header><strong>' . $title . '</strong><span>' . $id . '</span></header><footer><span>'
			. $id . '</span><span class="page-number"></span></footer><main><h1 class="document-title">' . $title . '</h1>' . $body . '</main></body></html>';
	}

	/**
	 * Node.
	 *
	 * @param mixed $node Node.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function node( $node ): string {
		if ( ! is_array( $node ) || array_is_list( $node ) ) {
			throw new InvalidArgumentException( 'Worksheet print node is invalid.' );
		}
		if ( array_key_exists( 'text', $node ) ) {
			$text = $this->escape( (string) $node['text'] );
			foreach ( array(
				'bold'          => 'strong',
				'italic'        => 'em',
				'underline'     => 'u',
				'strikethrough' => 's',
			) as $mark => $tag ) {
				if ( true === ( $node[ $mark ] ?? false ) ) {
					$text = '<' . $tag . '>' . $text . '</' . $tag . '>';
				}
			}
			return $text;
		}
		$type = $node['type'] ?? null;
		if ( ! is_string( $type ) ) {
			throw new InvalidArgumentException( 'Worksheet print node type is invalid.' );
		}
		if ( 'table' === $type && ( array_key_exists( 'column_widths', $node ) || array_key_exists( 'width_percent', $node ) || array_key_exists( 'column_ratios', $node ) ) ) {
			return Content_Table_Print_Layout::render( $node, fn ( $child ): string => $this->node( $child ) );
		}
		$children = '';
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			$children .= $this->node( $child );
		}
		$align = in_array( $node['align'] ?? null, array( 'left', 'center', 'right', 'justify' ), true ) ? ' style="text-align:' . $node['align'] . '"' : '';
		if ( in_array( $type, array( 'p', 'h1', 'h2', 'h3' ), true ) ) {
			return '<' . $type . $align . '>' . $children . '</' . $type . '>';
		}
		if ( in_array( $type, array( 'blockquote', 'ul', 'li', 'tr', 'td', 'th' ), true ) ) {
			return '<' . $type . '>' . $children . '</' . $type . '>';
		}
		if ( 'ol' === $type ) {
			$start = isset( $node['start'] ) && is_int( $node['start'] ) && $node['start'] > 0 ? ' start="' . $node['start'] . '"' : '';
			return '<ol' . $start . '>' . $children . '</ol>';
		}
		if ( 'a' === $type ) {
			return '<span class="link">' . ( '' !== $children ? $children : $this->escape( (string) ( $node['label'] ?? $node['url'] ?? '' ) ) ) . '</span>';
		}
		if ( 'table' === $type ) {
			return '<table><tbody>' . $children . '</tbody></table>';
		}
		if ( 'code_block' === $type ) {
			return '<pre><code>' . $children . '</code></pre>';
		}
		if ( 'code_line' === $type || 'caption' === $type || 'column' === $type ) {
			return '<div class="' . $type . '">' . $children . '</div>';
		}
		if ( Worksheet_Document_Profile::is_question_node( $node ) ) {
			return '<section class="question">' . $children . '</section>';
		}
		if ( 'callout' === $type ) {
			return '<aside class="callout">' . $children . '</aside>';
		}
		if ( 'column_group' === $type ) {
			return '<div class="column-group">' . $children . '</div>';
		}
		if ( 'hr' === $type ) {
			return '<hr>';
		}
		if ( 'page_break' === $type ) {
			return '<div class="page-break"></div>';
		}
		if ( 'equation' === $type ) {
			return '<div class="equation">' . $this->escape( (string) ( $node['tex'] ?? '' ) ) . '</div>';
		}
		if ( in_array( $type, array( 'img', 'audio', 'video', 'file', 'oj_ai_drawing' ), true ) ) {
			$label = (string) ( $node['alt'] ?? $node['label'] ?? $node['description'] ?? $node['transcript'] ?? 'Media' );
			return '<div class="media-placeholder">' . $this->escape( $label ) . '</div>';
		}
		if ( 'oj_ai_response' === $type ) {
			return $this->response( $node );
		}
		throw new InvalidArgumentException( 'Unsupported worksheet print node.' );
	}

	/**
	 * Response.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response( array $node ): string {
		$kind        = (string) ( $node['kind'] ?? '' );
		$label       = $this->escape( (string) ( $node['label'] ?? '' ) );
		$help        = '' === (string) ( $node['help_text'] ?? '' ) ? '' : '<small>' . $this->escape( (string) $node['help_text'] ) . '</small>';
		$constraints = is_array( $node['constraints'] ?? null ) ? $node['constraints'] : array();
		$field       = '';
		if ( in_array( $kind, array( 'short_text', 'number' ), true ) ) {
			$field = '<div class="answer-line"></div>'; } elseif ( 'long_text' === $kind ) {
			$field = '<div class="answer-box"></div>';
			} elseif ( in_array( $kind, array( 'single_choice', 'multiple_choice' ), true ) ) {
				foreach ( is_array( $constraints['options'] ?? null ) ? $constraints['options'] : array() as $option ) {
					$field .= '<div class="choice">' . ( 'single_choice' === $kind ? '&#9675;' : '&#9633;' ) . ' ' . $this->escape( (string) ( $option['label'] ?? '' ) ) . '</div>';
				}
			} elseif ( 'checkbox' === $kind ) {
				$field = '<div class="choice">&#9633;</div>';
			} elseif ( 'table' === $kind ) {
						$columns = is_array( $constraints['columns'] ?? null ) ? $constraints['columns'] : array();
						$field   = '<table class="response-table"><tr>';
				foreach ( $columns as $column ) {
					$field .= '<th>' . $this->escape( (string) ( $column['label'] ?? '' ) ) . '</th>';
				}
						$field .= '</tr><tr>' . str_repeat( '<td>&nbsp;</td>', max( 1, count( $columns ) ) ) . '</tr></table>';
			} elseif ( in_array( $kind, array( 'image_upload', 'file_upload', 'drawing' ), true ) ) {
				$field = '<div class="upload-box"></div>';
			} else {
							throw new InvalidArgumentException( 'Unsupported worksheet response kind.' );
			}
					return '<section class="response"><strong>' . $label . ( true === ( $node['required'] ?? false ) ? ' *' : '' ) . '</strong>' . $help . $field . '</section>';
	}

	/**
	 * Escape.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
	/**
	 * Css.
	 *
	 * @return string
	 */
	private function css(): string {
		return '@page{size:A4 portrait;margin:18mm 14mm 18mm 14mm}*{box-sizing:border-box}body{font-family:"OJ Noto Sans KR",sans-serif;font-size:10.5pt;line-height:1.55;color:#171717;word-wrap:break-word}header,footer{position:fixed;left:0;right:0;font-size:8pt;color:#555}header{top:-11mm;border-bottom:1px solid #bbb;padding-bottom:2mm}header span,footer span:last-child{float:right}footer{bottom:-11mm;border-top:1px solid #bbb;padding-top:2mm}.page-number:after{content:counter(page)}main{width:100%}.document-title{font-size:18pt;margin:0 0 8mm}h1{font-size:16pt}h2{font-size:14pt}h3{font-size:12pt}p{margin:0 0 3mm}table{border-collapse:collapse;width:100%;margin:3mm 0;page-break-inside:auto}tr{page-break-inside:avoid}th,td{border:1px solid #777;padding:2mm;vertical-align:top}th{background:#eee}blockquote,.callout{border-left:3px solid #555;margin:3mm 0;padding:2mm 4mm;background:#f5f5f5}pre{white-space:pre-wrap;border:1px solid #bbb;padding:3mm;background:#f7f7f7}.link{text-decoration:underline}.column-group{width:100%}.column{display:inline-block;width:48%;vertical-align:top}.media-placeholder,.upload-box{border:1px dashed #888;min-height:20mm;padding:4mm;margin:3mm 0;color:#555}.response{page-break-inside:avoid;margin:5mm 0}.response small{display:block;color:#555}.answer-line{height:10mm;border-bottom:1px solid #444}.answer-box{height:36mm;border:1px solid #777;margin-top:2mm}.choice{margin:2mm 0}.response-table{margin-top:2mm}.upload-box{height:35mm}.equation{text-align:center;font-family:serif;margin:4mm}.page-break{page-break-after:always}hr{border:0;border-top:1px solid #888;margin:5mm 0}';
	}
}

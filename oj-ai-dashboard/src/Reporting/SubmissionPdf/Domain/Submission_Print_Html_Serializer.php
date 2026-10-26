<?php
/**
 * Allowlist HTML serializer for a teacher submission PDF.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Content_Table_Print_Layout;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Attachment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts only submission_print.v1; student strings are always escaped.
 */
final class Submission_Print_Html_Serializer {
	/**
	 * Initialize submission print html serializer dependencies and state.
	 *
	 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
	 * @param Read_Teacher_Attachment|null $images Images.
	 */
	public function __construct( private Canonical_Json $canonical, private ?Read_Teacher_Attachment $images = null ) {
	}

	/**
	 * Serialize.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $document_id Document id.
	 * @param bool                $screen Use responsive screen layout instead of print furniture.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serialize( array $projection, string $document_id, bool $screen = false ): string {
		$envelope    = $this->map( $projection['submission_print_projection'] ?? null );
		$payload     = $this->map( $envelope['payload'] ?? null );
		$document    = $this->map( $payload['document'] ?? null );
		$assignment  = $this->map( $payload['assignment'] ?? null );
		$learner     = $this->map( $payload['learner'] ?? null );
		$submission  = $this->map( $payload['submission'] ?? null );
		$provenance  = $this->map( $payload['provenance'] ?? null );
		$answers     = $this->map( $payload['answers'] ?? null );
		$attachments = $this->attachment_map( $payload['attachments'] ?? null );
		if (
			'submission_print' !== ( $projection['kind'] ?? null )
			|| Build_Submission_Print_Projection::PROFILE !== ( $projection['profile'] ?? null )
			|| Build_Submission_Print_Projection::SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Build_Submission_Print_Projection::VERSION !== ( $envelope['schema_version'] ?? null )
			|| Build_Submission_Print_Projection::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| Build_Submission_Print_Projection::PROFILE !== ( $payload['profile'] ?? null )
			|| ! hash_equals( (string) ( $envelope['payload_hash'] ?? '' ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
			|| ! is_array( $document['nodes'] ?? null ) || ! array_is_list( $document['nodes'] )
		) {
			throw new InvalidArgumentException( 'Submission print projection is invalid.' );
		}
		foreach ( $attachments as $key => $attachment ) {
			// Inline sources can only originate from integrity-checked private bytes, never payload URLs.
			unset( $attachments[ $key ]['data_uri'] );
			if ( null !== $this->images && in_array( $attachment['mime_type'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
				$attachments[ $key ]['data_uri'] = $this->images->image_data_uri( $submission['id'], $attachment, $assignment['context'] );
			}
		}
		$seen = array();
		$body = '';
		foreach ( $document['nodes'] as $node ) {
			$body .= $this->node( $node, $answers, $attachments, $seen );
		}
		foreach ( array_keys( $answers ) as $response_id ) {
			if ( ! isset( $seen[ $response_id ] ) ) {
				throw new InvalidArgumentException( 'Submission answer is not present in the worksheet.' );
			}
		}
		$title      = $this->escape( (string) ( $assignment['title'] ?? $document['title'] ?? '' ) );
		$student    = $this->escape( (string) ( $learner['display_name'] ?? '' ) );
		$id         = $this->escape( $document_id );
		$submitted  = $this->escape( (string) ( $submission['submitted_at_utc'] ?? '' ) );
		$attempt    = (int) ( $submission['attempt_no'] ?? 0 );
		$revision   = $this->escape( (string) ( $provenance['submission_revision_id'] ?? '' ) );
		$locale     = $this->escape( (string) ( $document['locale'] ?? 'ko-KR' ) );
		$screen_css = $screen ? '@media screen{body{margin:0;padding:20px;font-size:16px;overflow-wrap:anywhere}main{max-width:960px;margin:auto}header,footer{display:none}.submission-meta{display:flex;flex-wrap:wrap}.submission-meta div{flex:1 1 180px;min-width:0}.document-title{font-size:24px}.answer{background:#fff}.attachments img{max-width:100%;height:auto}table{table-layout:fixed}pre{overflow:auto}.column{width:100%}@media(max-width:600px){body{padding:12px}.submission-meta div{flex-basis:100%}}}' : '';
		$csp        = $screen ? '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">' : '';
		return '<!doctype html><html lang="' . $locale . '"><head><meta charset="UTF-8">' . $csp . '<style>' . $this->css() . '.question{background:#fff;border:0;border-top:1px solid #ccc;margin:6mm 0;padding:4mm 0 0;font-style:normal}.question>h2:first-child{margin-top:0}' . $screen_css . '</style></head><body><header><strong>'
			. $title . '</strong><span>' . $student . '</span></header><footer><span>'
			. $id . '</span><span class="page-number"></span></footer><main><h1 class="document-title">'
			. $title . '</h1><dl class="submission-meta"><div><dt>학생</dt><dd>' . $student . '</dd></div><div><dt>제출</dt><dd>'
			. $submitted . '</dd></div><div><dt>제출 횟수</dt><dd>' . $attempt . '회</dd></div><div><dt>문서</dt><dd>'
			. $revision . '</dd></div></dl>' . $body . '</main></body></html>';
	}

	/**
	 * Node.
	 *
	 * @param mixed                             $node Node.
	 * @param array<string,mixed>               $answers Answers.
	 * @param array<string,array<string,mixed>> $attachments Attachments.
	 * @param array<string,bool>                &$seen Seen.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function node( mixed $node, array $answers, array $attachments, array &$seen ): string {
		if ( ! is_array( $node ) || array_is_list( $node ) ) {
			throw new InvalidArgumentException( 'Submission print node is invalid.' );
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
			throw new InvalidArgumentException( 'Submission print node type is invalid.' );
		}
		if ( 'oj_ai_response' === $type ) {
			return $this->response( $node, $answers, $attachments, $seen );
		}
		if ( 'table' === $type && ( array_key_exists( 'column_widths', $node ) || array_key_exists( 'width_percent', $node ) || array_key_exists( 'column_ratios', $node ) ) ) {
			return Content_Table_Print_Layout::render(
				$node,
				function ( $child ) use ( $answers, $attachments, &$seen ): string {
					return $this->node( $child, $answers, $attachments, $seen );
				}
			);
		}
		$children = '';
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			$children .= $this->node( $child, $answers, $attachments, $seen );
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
		if ( in_array( $type, array( 'code_line', 'caption', 'column' ), true ) ) {
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
			$label = (string) ( $node['alt'] ?? $node['label'] ?? $node['description'] ?? $node['transcript'] ?? '미디어' );
			return '<div class="media-placeholder">' . $this->escape( $label ) . '</div>';
		}
		throw new InvalidArgumentException( 'Unsupported submission print node.' );
	}

	/**
	 * Response.
	 *
	 * @param array<string,mixed>               $node Node.
	 * @param array<string,mixed>               $answers Answers.
	 * @param array<string,array<string,mixed>> $attachments Attachments.
	 * @param array<string,bool>                &$seen Seen.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response( array $node, array $answers, array $attachments, array &$seen ): string {
		$id = (string) ( $node['response_id'] ?? '' );
		if ( '' === $id || isset( $seen[ $id ] ) ) {
			throw new InvalidArgumentException( 'Submission response identity is invalid.' );
		}
		$seen[ $id ] = true;
		$kind        = (string) ( $node['kind'] ?? '' );
		$label       = $this->escape( (string) ( $node['label'] ?? '' ) );
		$constraints = is_array( $node['constraints'] ?? null ) ? $node['constraints'] : array();
		$has_answer  = array_key_exists( $id, $answers );
		$value       = $has_answer ? $answers[ $id ] : null;
		$content     = $has_answer ? $this->answer( $kind, $value, $constraints, $attachments ) : '<span class="empty">미작성</span>';
		return '<section class="response"><h4>' . $label . '</h4><div class="answer">' . $content . '</div></section>';
	}

	/**
	 * Answer.
	 *
	 * @param string                            $kind Kind.
	 * @param mixed                             $value Value.
	 * @param array<string,mixed>               $constraints Constraints.
	 * @param array<string,array<string,mixed>> $attachments Attachments.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function answer( string $kind, mixed $value, array $constraints, array $attachments ): string {
		if ( in_array( $kind, array( 'short_text', 'long_text' ), true ) && is_string( $value ) ) {
			return '<div class="answer-text">' . $this->escape( $value ) . '</div>';
		}
		if ( 'single_choice' === $kind && is_string( $value ) ) {
			return '<div class="choice selected">● ' . $this->escape( $this->option_label( $constraints, $value ) ) . '</div>';
		}
		if ( 'multiple_choice' === $kind && is_array( $value ) && array_is_list( $value ) ) {
			$html = '';
			foreach ( $value as $option_id ) {
				if ( ! is_string( $option_id ) ) {
					throw new InvalidArgumentException( 'Submission choice is invalid.' );
				} $html .= '<div class="choice selected">✓ ' . $this->escape( $this->option_label( $constraints, $option_id ) ) . '</div>';
			}
			return '' === $html ? '<span class="empty">선택 없음</span>' : $html;
		}
		if ( 'checkbox' === $kind && is_bool( $value ) ) {
			return $value ? '선택함' : '선택하지 않음';
		}
		if ( 'number' === $kind && is_string( $value ) ) {
			$unit = is_string( $constraints['unit'] ?? null ) ? ' ' . $this->escape( $constraints['unit'] ) : '';
			return $this->escape( $value ) . $unit;
		}
		if ( 'table' === $kind && is_array( $value ) ) {
			return $this->table_answer( $value, $constraints );
		}
		if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) && is_array( $value ) && array_is_list( $value ) ) {
			return $this->attachment_list( $value, $attachments );
		}
		if ( 'drawing' === $kind && is_array( $value ) && ! array_is_list( $value ) ) {
			$ids = array( $value['preview_attachment_id'] ?? null, $value['document_attachment_id'] ?? null );
			return '<div class="drawing-answer">' . $this->attachment_list( $ids, $attachments ) . '</div>';
		}
		throw new InvalidArgumentException( 'Submission response value is invalid.' );
	}

	/**
	 * Option label.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param string              $selected Selected.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function option_label( array $constraints, string $selected ): string {
		$options = $constraints['options'] ?? null;
		if ( ! is_array( $options ) || ! array_is_list( $options ) ) {
			throw new InvalidArgumentException( 'Submission option list is invalid.' );
		}
		foreach ( $options as $option ) {
			if ( is_array( $option ) && hash_equals( $selected, (string) ( $option['option_id'] ?? '' ) ) && is_string( $option['label'] ?? null ) ) {
				return $option['label'];
			}
		}
		throw new InvalidArgumentException( 'Submission selected option is missing.' );
	}

	/**
	 * Table answer.
	 *
	 * @param array<array-key,mixed> $value Raw response row identifiers require validation.
	 * @param array<string,mixed>    $constraints Constraints.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function table_answer( array $value, array $constraints ): string {
		$columns = $constraints['columns'] ?? null;
		$rows    = $constraints['rows'] ?? null;
		if ( ! is_array( $columns ) || ! array_is_list( $columns ) || ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			throw new InvalidArgumentException( 'Submission table schema is invalid.' );
		}
		$row_labels = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$row_labels[ (string) ( $row['row_id'] ?? '' ) ] = (string) ( $row['label'] ?? '' );
			}
		}
		$html = '<table class="response-table"><thead><tr><th>행</th>';
		foreach ( $columns as $column ) {
			$html .= '<th>' . $this->escape( (string) ( is_array( $column ) ? ( $column['label'] ?? '' ) : '' ) ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		$index = 0;
		foreach ( $value as $row_id => $cells ) {
			if ( ! is_string( $row_id ) || ! is_array( $cells ) ) {
				throw new InvalidArgumentException( 'Submission table row is invalid.' );
			}
			++$index;
			$html .= '<tr><th>' . $this->escape( $row_labels[ $row_id ] ?? ( '행 ' . $index ) ) . '</th>';
			foreach ( $columns as $column ) {
				if ( ! is_array( $column ) ) {
					throw new InvalidArgumentException( 'Submission table column is invalid.' );
				}
				$column_id = (string) ( $column['column_id'] ?? '' );
				$cell      = $cells[ $column_id ] ?? null;
				$text      = is_bool( $cell ) ? ( $cell ? '선택함' : '선택하지 않음' ) : ( null === $cell ? '' : (string) $cell );
				$html     .= '<td>' . $this->escape( $text ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Attachment list.
	 *
	 * @param array<int,mixed>                  $ids Ids.
	 * @param array<string,array<string,mixed>> $attachments Attachments.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_list( array $ids, array $attachments ): string {
		$html = '<ul class="attachments">';
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $attachments[ $id ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment reference is invalid.' );
			}
			$item  = $attachments[ $id ];
			$html .= '<li><strong>' . $this->escape( (string) $item['name'] ) . '</strong><span>' . $this->escape( (string) $item['mime_type'] ) . ' · ' . $this->bytes( (int) $item['byte_size'] ) . '</span></li>';
			if ( isset( $item['data_uri'] ) ) {
				$html .= '<li><img style="display:block;max-width:100%;max-height:180mm" src="' . $this->escape( $item['data_uri'] ) . '" alt="' . $this->escape( (string) $item['name'] ) . '"></li>';
			}
		}
		return $html . '</ul>';
	}

	/**
	 * Attachment map.
	 *
	 * @param mixed $value Value.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_map( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Submission attachments are invalid.' );
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['id'] ?? null ) || isset( $result[ $item['id'] ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment is invalid.' );
			}
			$result[ $item['id'] ] = $item;
		}
		return $result;
	}

	/**
	 * Map submission print html serializer.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Submission print value is invalid.' );
		}
		return $value;
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
	 * Bytes.
	 *
	 * @param int $value Value.
	 * @return string
	 */
	private function bytes( int $value ): string {
		return $value < 1024 ? $value . ' B' : ( $value < 1048576 ? number_format( $value / 1024, 1 ) . ' KB' : number_format( $value / 1048576, 1 ) . ' MB' );
	}
	/**
	 * Css.
	 *
	 * @return string
	 */
	private function css(): string {
		return '@page{size:A4 portrait;margin:18mm 14mm 18mm 14mm}*{box-sizing:border-box}body{font-family:"OJ Noto Sans KR",sans-serif;font-size:10.5pt;line-height:1.55;color:#171717;word-wrap:break-word}header,footer{position:fixed;left:0;right:0;font-size:8pt;color:#555}header{top:-11mm;border-bottom:1px solid #bbb;padding-bottom:2mm}header span,footer span:last-child{float:right}footer{bottom:-11mm;border-top:1px solid #bbb;padding-top:2mm}.page-number:after{content:counter(page)}main{width:100%}.document-title{font-size:18pt;margin:0 0 5mm}.submission-meta{display:table;width:100%;border:1px solid #aaa;margin:0 0 7mm}.submission-meta div{display:table-cell;padding:2mm 3mm}.submission-meta dt{font-size:8pt;color:#555}.submission-meta dd{margin:0;font-weight:bold}h1{font-size:16pt}h2{font-size:14pt}h3{font-size:12pt}p{margin:0 0 3mm}table{border-collapse:collapse;width:100%;margin:3mm 0;page-break-inside:auto}tr{page-break-inside:avoid}th,td{border:1px solid #777;padding:2mm;vertical-align:top}th{background:#eee}blockquote,.callout{border-left:3px solid #555;margin:3mm 0;padding:2mm 4mm;background:#f5f5f5}pre{white-space:pre-wrap;border:1px solid #bbb;padding:3mm;background:#f7f7f7}.link{text-decoration:underline}.column-group{width:100%}.column{display:inline-block;width:48%;vertical-align:top}.media-placeholder{border:1px dashed #888;min-height:14mm;padding:4mm;margin:3mm 0;color:#555}.response{page-break-inside:avoid;margin:5mm 0}.response h4{font-size:10.5pt;margin:0 0 2mm}.answer{border:1px solid #aaa;background:#fafafa;min-height:11mm;padding:3mm}.answer-text{white-space:pre-wrap}.choice{margin:1mm 0}.selected{font-weight:600}.empty{color:#666}.response-table{margin:0}.attachments{list-style:none;margin:0;padding:0}.attachments li{border-bottom:1px solid #ddd;padding:2mm 0}.attachments li:last-child{border-bottom:0}.attachments span{display:block;font-size:8.5pt;color:#555}.equation{text-align:center;font-family:serif;margin:4mm}.page-break{page-break-after:always}hr{border:0;border-top:1px solid #888;margin:5mm 0}';
	}
}

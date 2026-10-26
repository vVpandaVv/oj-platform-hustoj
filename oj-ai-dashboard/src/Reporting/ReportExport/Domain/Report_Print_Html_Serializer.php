<?php
/**
 * Report print html serializer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escaped, print-focused rendering of the exact report snapshot projection.
 */
final class Report_Print_Html_Serializer {
	/**
	 * Serialize.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serialize( Report_Export_Request_Snapshot $snapshot ): string {
		if ( 'report_pdf' !== $snapshot->artifact_kind() || Report_Export_Request_Snapshot::PDF_PROFILE !== $snapshot->profile() ) {
			throw new InvalidArgumentException( 'Report print profile is invalid.' );
		}
		$projection = $snapshot->projection();
		$items      = $projection['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) !== $snapshot->row_count() ) {
			throw new InvalidArgumentException( 'Report print rows are invalid.' );
		}
		$context     = $snapshot->context();
		$filter      = $snapshot->value()['export']['filter']['payload'] ?? array();
		$period      = is_array( $filter['period'] ?? null ) ? $filter['period'] : null;
		$period_text = null === $period ? '전체 기간' : (string) ( $period['from'] ?? '' ) . ' - ' . (string) ( $period['to'] ?? '' );
		$body        = '';
		foreach ( $items as $item ) {
			$body .= $this->item( $item );
		}
		if ( '' === $body ) {
			$body = '<p class="empty">조건에 맞는 활동지가 없습니다.</p>';
		}
		$lang = $this->escape( $snapshot->locale() );
		return '<!doctype html><html lang="' . $lang . '"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>'
			. '<header><strong>활동지 보고서</strong><span>' . $this->escape( (string) $projection['baseline_at_utc'] ) . '</span></header>'
			. '<footer><span>' . $this->escape( $snapshot->source_id() ) . '</span><span class="page-number"></span></footer><main>'
			. '<h1>활동지 보고서</h1><dl class="summary">'
			. $this->pair( '범위', $context['type'] . ' · ' . $context['id'] )
			. $this->pair( '기준 시각', (string) $projection['baseline_at_utc'] . ' UTC' )
			. $this->pair( '기간', $period_text )
			. $this->pair( '시간대', $snapshot->timezone() )
			. $this->pair( '활동지', number_format( $snapshot->row_count() ) . '개' )
			. $this->pair( 'Snapshot', $snapshot->source_id() ) . '</dl>' . $body . '</main></body></html>';
	}

	/**
	 * Item.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function item( mixed $value ): string {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Report print item is invalid.' );
		}
		$worksheet   = $this->map( $value['worksheet'] ?? null );
		$publication = $this->map( $value['publication'] ?? null );
		$rubric      = $this->map( $value['rubric'] ?? null );
		$progress    = $this->map( $value['progress'] ?? null );
		return '<section class="worksheet"><div class="worksheet-head"><div><h2>' . $this->escape( (string) ( $worksheet['title'] ?? '' ) ) . '</h2><p>'
			. $this->escape( (string) ( $worksheet['subject_label'] ?? '과목 미지정' ) ) . '</p></div><span>' . $this->escape( (string) ( $worksheet['state'] ?? '' ) ) . '</span></div>'
			. '<dl class="identity">' . $this->pair( '활동지 ID', (string) ( $worksheet['id'] ?? '' ) )
			. $this->pair( '발행', '#' . (string) ( $publication['number'] ?? '' ) . ' · ' . (string) ( $publication['state'] ?? '' ) )
			. $this->pair( '루브릭', (string) ( $rubric['criterion_count'] ?? 0 ) . '개 · ' . (string) ( $rubric['total_points'] ?? '0' ) . '점' )
			. $this->pair( '갱신', (string) ( $value['updated_at_utc'] ?? '' ) . ' UTC' ) . '</dl>'
			. '<table><thead><tr><th>배포</th><th>대상</th><th>제출률</th><th>평가율</th><th>공개율</th><th>평균</th><th>검토 필요</th></tr></thead><tbody><tr>'
			. $this->cell( $progress['assignment_count'] ?? 0 ) . $this->cell( $progress['target_count'] ?? 0 )
			. $this->cell( $this->ratio( $progress['submission_rate'] ?? null ) ) . $this->cell( $this->ratio( $progress['assessment_rate'] ?? null ) )
			. $this->cell( $this->ratio( $progress['release_rate'] ?? null ) ) . $this->cell( $this->average( $progress['average_score'] ?? null ) )
			. $this->cell( $progress['review_required_count'] ?? 0 ) . '</tr></tbody></table></section>';
	}

	/**
	 * Pair.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return string
	 */
	private function pair( string $label, string $value ): string {
		return '<div><dt>' . $this->escape( $label ) . '</dt><dd>' . $this->escape( $value ) . '</dd></div>';
	}
	/**
	 * Cell.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function cell( mixed $value ): string {
		return '<td>' . $this->escape( null === $value ? '-' : (string) $value ) . '</td>';
	}
	/**
	 * Ratio.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function ratio( mixed $value ): string {
		$metric      = $this->map( $value );
		$numerator   = $metric['numerator'] ?? null;
		$denominator = $metric['denominator'] ?? null;
		$percentage  = $metric['value'] ?? null;
		if ( ! is_int( $numerator ) || ! is_int( $denominator ) || $numerator < 0 || $denominator < $numerator || ( null !== $percentage && ! is_string( $percentage ) ) ) {
			throw new InvalidArgumentException( 'Report print ratio is invalid.' );
		}
		return $numerator . ' / ' . $denominator . ( null === $percentage ? '' : ' (' . $percentage . '%)' );
	}

	/**
	 * Average.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function average( mixed $value ): string {
		$metric     = $this->map( $value );
		$count      = $metric['n'] ?? null;
		$suppressed = $metric['suppressed'] ?? null;
		$percentage = $metric['value'] ?? null;
		if ( ! is_int( $count ) || $count < 0 || ! is_bool( $suppressed ) || ( null !== $percentage && ! is_string( $percentage ) ) ) {
			throw new InvalidArgumentException( 'Report print average is invalid.' );
		}
		if ( $suppressed ) {
			return '표시 안 함 (' . $count . '명)';
		}
		return null === $percentage ? '자료 없음' : $percentage . '% (' . $count . '명)';
	}
	/**
	 * Map report print html serializer.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Report print value is invalid.' );
		} return $value;
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
		return '@page{size:A4 portrait;margin:18mm 14mm 18mm 14mm}*{box-sizing:border-box}body{font-family:"OJ Noto Sans KR",sans-serif;font-size:9.5pt;line-height:1.45;color:#171717;word-wrap:break-word}header,footer{position:fixed;left:0;right:0;font-size:8pt;color:#555}header{top:-11mm;border-bottom:1px solid #bbb;padding-bottom:2mm}header span,footer span:last-child{float:right}footer{bottom:-11mm;border-top:1px solid #bbb;padding-top:2mm}.page-number:after{content:counter(page)}h1{font-size:20pt;margin:0 0 6mm}h2{font-size:13pt;margin:0 0 1mm}.summary,.identity{display:table;width:100%;border:1px solid #aaa;margin:0 0 6mm}.summary div,.identity div{display:table-cell;padding:2mm 3mm}.summary dt,.identity dt{font-size:7.5pt;color:#555}.summary dd,.identity dd{margin:0;font-weight:600}.worksheet{page-break-inside:avoid;margin:0 0 7mm}.worksheet-head{display:table;width:100%;border-bottom:2px solid #333;padding-bottom:2mm}.worksheet-head>div,.worksheet-head>span{display:table-cell}.worksheet-head>span{text-align:right;vertical-align:middle}.worksheet-head p{margin:0;color:#555}.identity{border:0;border-bottom:1px solid #bbb;margin:0}.identity div{padding-left:0}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #777;padding:2mm;text-align:center}th{background:#eee;font-size:8pt}.empty{border:1px solid #bbb;padding:8mm;text-align:center;color:#666}';
	}
}

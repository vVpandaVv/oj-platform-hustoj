<?php
/**
 * Result print html serializer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes only the frozen student-visible result allowlist.
 */
final class Result_Print_Html_Serializer {
	/**
	 * Initialize result print html serializer dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Serialize.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $document_id Document id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function serialize( array $projection, string $document_id ): string {
		$this->exact( $projection, array( 'kind', 'profile', 'result_print_projection' ) );
		$envelope = $this->map( $projection['result_print_projection'] ?? null );
		$this->exact( $envelope, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
		$payload = $this->map( $envelope['payload'] ?? null );
		$this->exact( $payload, array( 'profile', 'result' ) );
		$result = $this->map( $payload['result'] ?? null );
		$this->exact( $result, array( 'feedback', 'release', 'schema_id', 'schema_version', 'score', 'worksheet' ) );
		if (
			'result_print' !== ( $projection['kind'] ?? null )
			|| Build_Result_Print_Projection::PROFILE !== ( $projection['profile'] ?? null )
			|| Build_Result_Print_Projection::SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Build_Result_Print_Projection::VERSION !== ( $envelope['schema_version'] ?? null )
			|| Build_Result_Print_Projection::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| Build_Result_Print_Projection::PROFILE !== ( $payload['profile'] ?? null )
			|| ! hash_equals( (string) ( $envelope['payload_hash'] ?? '' ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			throw new InvalidArgumentException( 'Result print projection is invalid.' );
		}

		$worksheet = $this->map( $result['worksheet'] ?? null );
		$release   = $this->map( $result['release'] ?? null );
		$score     = $this->map( $result['score'] ?? null );
		$feedback  = $this->map( $result['feedback'] ?? null );
		$this->exact( $worksheet, array( 'title' ) );
		$this->exact( $release, array( 'released_at_utc', 'state' ) );
		$this->exact( $score, array( 'criteria', 'total', 'visibility' ) );
		$this->exact( $feedback, array( 'criteria', 'next_steps', 'overall_feedback', 'visibility' ) );
		$title = $this->required_text( $worksheet['title'] ?? null );
		$state = $release['state'] ?? null;
		if ( ! in_array( $state, array( 'released', 'corrected' ), true ) ) {
			throw new InvalidArgumentException( 'Result release state is invalid.' );
		}
		$released      = $this->required_text( $release['released_at_utc'] ?? null );
		$score_html    = $this->score( $score );
		$feedback_html = $this->feedback( $feedback );
		$id            = $this->escape( $document_id );
		$status        = 'corrected' === $state ? '정정 공개' : '공개';

		return '<!doctype html><html lang="ko-KR"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>'
			. '<header><strong>' . $this->escape( $title ) . '</strong><span>학습 결과</span></header>'
			. '<footer><span>' . $id . '</span><span class="page-number"></span></footer><main>'
			. '<h1>' . $this->escape( $title ) . '</h1><dl class="meta"><div><dt>상태</dt><dd>' . $status . '</dd></div>'
			. '<div><dt>공개 시각</dt><dd>' . $this->escape( $released ) . '</dd></div></dl>'
			. $score_html . $feedback_html . '</main></body></html>';
	}

	/**
	 * Score.
	 *
	 * @param array<string,mixed> $score Score.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score( array $score ): string {
		$visibility = $score['visibility'] ?? null;
		$criteria   = $score['criteria'] ?? null;
		if ( ! in_array( $visibility, array( 'hidden', 'total', 'total_and_criteria' ), true ) || ! is_array( $criteria ) || ! array_is_list( $criteria ) ) {
			throw new InvalidArgumentException( 'Result score projection is invalid.' );
		}
		if ( 'hidden' === $visibility ) {
			if ( null !== ( $score['total'] ?? null ) || array() !== $criteria ) {
				throw new InvalidArgumentException( 'Hidden result score leaked values.' );
			}
			return '';
		}
		$total = $this->decimal( $score['total'] ?? null );
		$html  = '<section><h2>점수</h2><div class="score-total"><strong>' . $this->display_decimal( $total ) . '</strong><span>점</span></div>';
		if ( 'total' === $visibility ) {
			if ( array() !== $criteria ) {
				throw new InvalidArgumentException( 'Total-only result score leaked criteria.' );
			}
			return $html . '</section>';
		}
		if ( array() === $criteria ) {
			throw new InvalidArgumentException( 'Criterion result score is empty.' );
		}
		$html .= '<table><thead><tr><th>평가 기준</th><th>점수</th><th>배점</th></tr></thead><tbody>';
		$sum   = 0;
		foreach ( $criteria as $criterion ) {
			$criterion = $this->map( $criterion );
			$this->exact( $criterion, array( 'label', 'maximum_points', 'points' ) );
			$points  = $this->decimal( $criterion['points'] ?? null );
			$maximum = $this->decimal( $criterion['maximum_points'] ?? null );
			if ( $this->units( $points ) > $this->units( $maximum ) ) {
				throw new InvalidArgumentException( 'Result criterion score exceeds its maximum.' );
			}
			$sum  += $this->units( $points );
			$html .= '<tr><th>' . $this->escape( $this->required_text( $criterion['label'] ?? null ) ) . '</th><td>' . $this->display_decimal( $points ) . '</td><td>' . $this->display_decimal( $maximum ) . '</td></tr>';
		}
		if ( $sum !== $this->units( $total ) ) {
			throw new InvalidArgumentException( 'Result total score drifted.' );
		}
		return $html . '</tbody></table></section>';
	}

	/**
	 * Feedback.
	 *
	 * @param array<string,mixed> $feedback Feedback.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function feedback( array $feedback ): string {
		$visibility = $feedback['visibility'] ?? null;
		$criteria   = $feedback['criteria'] ?? null;
		$steps      = $feedback['next_steps'] ?? null;
		$overall    = $feedback['overall_feedback'] ?? null;
		if ( ! in_array( $visibility, array( 'hidden', 'shown' ), true ) || ! is_array( $criteria ) || ! array_is_list( $criteria ) || ! is_array( $steps ) || ! array_is_list( $steps ) ) {
			throw new InvalidArgumentException( 'Result feedback projection is invalid.' );
		}
		if ( 'hidden' === $visibility ) {
			if ( array() !== $criteria || array() !== $steps || null !== $overall ) {
				throw new InvalidArgumentException( 'Hidden result feedback leaked values.' );
			}
			return '';
		}
		if ( array() === $criteria ) {
			throw new InvalidArgumentException( 'Visible result feedback is empty.' );
		}
		$html = '<section><h2>피드백</h2>';
		if ( null !== $overall ) {
			$html .= '<div class="overall">' . nl2br( $this->escape( $this->text( $overall ) ), false ) . '</div>';
		}
		foreach ( $criteria as $criterion ) {
			$criterion = $this->map( $criterion );
			$this->exact( $criterion, array( 'label', 'next_step', 'strength' ) );
			$html .= '<article class="criterion"><h3>' . $this->escape( $this->required_text( $criterion['label'] ?? null ) ) . '</h3>'
				. '<div><strong>잘한 점</strong><p>' . nl2br( $this->escape( $this->required_text( $criterion['strength'] ?? null ) ), false ) . '</p></div>'
				. '<div><strong>다음 단계</strong><p>' . nl2br( $this->escape( $this->required_text( $criterion['next_step'] ?? null ) ), false ) . '</p></div></article>';
		}
		if ( array() !== $steps ) {
			$html .= '<h3>다음에 해볼 일</h3><ol class="steps">';
			$seen  = array();
			foreach ( $steps as $step ) {
				$step = $this->required_text( $step );
				if ( isset( $seen[ $step ] ) ) {
					throw new InvalidArgumentException( 'Duplicate result next step.' );
				}
				$seen[ $step ] = true;
				$html         .= '<li>' . $this->escape( $step ) . '</li>';
			}
			$html .= '</ol>';
		}
		return $html . '</section>';
	}

	/**
	 * Map result print html serializer.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Result print value is invalid.' );
		} return $value;
	}
	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Result print shape is invalid.' );
		}
	}
	/**
	 * Required text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_text( mixed $value ): string {
		$value = $this->text( $value );
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Result print text is empty.' );
		} return $value;
	}
	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value ): string {
		if ( ! is_string( $value ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Result print text is invalid.' );
		} return $value;
	}
	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Result print decimal is invalid.' );
		} return $value;
	}
	/**
	 * Units.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function units( string $value ): int {
		[ $whole, $fraction ] = explode( '.', $value );
		return ( (int) $whole * 10000 ) + (int) $fraction;
	}
	/**
	 * Display decimal.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function display_decimal( string $value ): string {
		return rtrim( rtrim( $value, '0' ), '.' );
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
		return '@page{size:A4 portrait;margin:18mm 14mm 18mm 14mm}*{box-sizing:border-box}body{font-family:"OJ Noto Sans KR",sans-serif;font-size:10.5pt;line-height:1.55;color:#171717;word-wrap:break-word}header,footer{position:fixed;left:0;right:0;font-size:8pt;color:#555}header{top:-11mm;border-bottom:1px solid #bbb;padding-bottom:2mm}header span,footer span:last-child{float:right}footer{bottom:-11mm;border-top:1px solid #bbb;padding-top:2mm}.page-number:after{content:counter(page)}main{width:100%}h1{font-size:18pt;margin:0 0 5mm}h2{font-size:14pt;margin:8mm 0 3mm}h3{font-size:11pt;margin:4mm 0 2mm}.meta{display:table;width:100%;border:1px solid #aaa;margin:0 0 7mm}.meta div{display:table-cell;padding:2mm 3mm}.meta dt{font-size:8pt;color:#555}.meta dd{margin:0;font-weight:bold}.score-total{display:inline-flex;align-items:baseline;gap:1.5mm;border-left:4px solid #1f6b45;background:#f1f7f4;padding:3mm 5mm;margin-bottom:3mm}.score-total strong{font-size:22pt}.score-total span{font-weight:bold}table{border-collapse:collapse;width:100%;margin:3mm 0}tr,.criterion{page-break-inside:avoid}th,td{border:1px solid #777;padding:2.5mm;vertical-align:top}th{background:#eee;text-align:left}td:nth-child(2),td:nth-child(3){width:22mm;text-align:right}.overall{border-left:4px solid #315b8a;background:#f2f6fa;padding:3mm 4mm;white-space:normal}.criterion{border:1px solid #bbb;margin:3mm 0;padding:3mm}.criterion h3{margin-top:0}.criterion div{margin-top:2mm}.criterion strong{font-size:9pt;color:#444}.criterion p{margin:1mm 0 0;white-space:normal}.steps{padding-left:6mm}.steps li{margin:1.5mm 0}';
	}
}

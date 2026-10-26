<?php
/**
 * Dompdf html to pdf renderer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure;

use Composer\InstalledVersions;
use Dompdf\Dompdf;
use Dompdf\Options;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Failure;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only adapter allowed to depend on Dompdf classes.
 */
final class Dompdf_Html_To_Pdf_Renderer implements Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Font path.
	 *
	 * @var string
	 */
	private string $font_path;
	/**
	 * Chroot.
	 *
	 * @var string
	 */
	private string $chroot;
	/**
	 * Cache.
	 *
	 * @var string
	 */
	private string $cache;

	/**
	 * Initialize dompdf html to pdf renderer dependencies and state.
	 *
	 * @param string|null $font_path Font path.
	 * @param string|null $chroot Chroot.
	 * @param string|null $cache Cache.
	 */
	public function __construct( ?string $font_path = null, ?string $chroot = null, ?string $cache = null ) {
		$this->chroot    = rtrim( $chroot ?? (string) OJ_AI_DASHBOARD_DIR, '/\\' );
		$this->font_path = $font_path ?? $this->chroot . '/resources/fonts/noto-sans-kr/NotoSansKR-Regular.ttf';
		$this->cache     = $cache ?? rtrim( sys_get_temp_dir(), '/\\' ) . '/oj-ai-dompdf-cache';
	}

	/**
	 * Render dompdf html to pdf renderer.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result {
		if ( '' === trim( $html ) || str_contains( strtolower( $html ), '<script' ) || ! is_file( $this->font_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::RENDER_FAILED );
		}
		try {
			if ( ! is_dir( $this->cache ) && ! mkdir( $this->cache, 0700, true ) && ! is_dir( $this->cache ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw new \RuntimeException( 'Cache unavailable.' );
			}
			$font_file = $this->stage_packaged_font();
			$options   = new Options();
			$options->setIsRemoteEnabled( false );
			$options->setIsPhpEnabled( false );
			$options->setIsJavascriptEnabled( false );
			$options->setChroot( array( $this->chroot, $this->cache ) );
			$options->setTempDir( $this->cache );
			$options->setFontDir( $this->cache );
			$options->setFontCache( $this->cache );
			$options->setDefaultFont( 'OJ Noto Sans KR' );
			$dompdf = new Dompdf( $options );
			$fonts  = $dompdf->getFontMetrics();
			foreach ( array( 400, 700 ) as $weight ) {
				if ( ! $fonts->registerFont(
					array(
						'family' => 'OJ Noto Sans KR',
						'weight' => $weight,
						'style'  => 'normal',
					),
					$font_file
				) ) {
					throw new \RuntimeException( 'Packaged Korean font registration failed.' );
				}
			}
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();
			$bytes      = $dompdf->output();
			$page_count = (int) $dompdf->getCanvas()->get_page_count();
			$version    = InstalledVersions::getPrettyVersion( 'dompdf/dompdf' ) ?? '3.1';
			return new Worksheet_Pdf_Render_Result( $bytes, $page_count, 'dompdf-' . ltrim( $version, 'v' ) );
		} catch ( Worksheet_Pdf_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::RENDER_FAILED );
		}
	}

	/**
	 * Stage packaged font.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stage_packaged_font(): string {
		$digest = hash_file( 'sha256', $this->font_path );
		if ( false === $digest ) {
			throw new \RuntimeException( 'Packaged Korean font hash failed.' );
		}
		$staged = $this->cache . '/oj-ai-noto-sans-kr-' . $digest . '.ttf';
		if ( is_file( $staged ) ) {
			$cached_digest = hash_file( 'sha256', $staged );
			if ( is_string( $cached_digest ) && hash_equals( $digest, $cached_digest ) ) {
				return $staged;
			}
			if ( ! unlink( $staged ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw new \RuntimeException( 'Stale Korean font cache could not be replaced.' );
			}
		}
		$temporary = tempnam( $this->cache, 'oj-ai-font-' );
		if ( false === $temporary || ! copy( $this->font_path, $temporary ) ) {
			if ( is_string( $temporary ) && is_file( $temporary ) ) {
				unlink( $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw new \RuntimeException( 'Packaged Korean font could not be staged.' );
		}
		$temporary_digest = hash_file( 'sha256', $temporary );
		if ( ! is_string( $temporary_digest ) || ! hash_equals( $digest, $temporary_digest ) ) {
			unlink( $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw new \RuntimeException( 'Staged Korean font integrity failed.' );
		}
		chmod( $temporary, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( ! rename( $temporary, $staged ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			$winner_digest = is_file( $staged ) ? hash_file( 'sha256', $staged ) : false;
			unlink( $temporary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( ! is_string( $winner_digest ) || ! hash_equals( $digest, $winner_digest ) ) {
				throw new \RuntimeException( 'Packaged Korean font cache race failed.' );
			}
		}
		return $staged;
	}
}

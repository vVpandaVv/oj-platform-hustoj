<?php
/**
 * Html to pdf renderer port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Port;

use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Pdf_Render_Result;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Html to pdf renderer port interface.
 */
interface Html_To_Pdf_Renderer_Port_Interface {
	/**
	 * Render html to pdf renderer port interface.
	 *
	 * @param string $html Html.
	 * @return Worksheet_Pdf_Render_Result
	 */
	public function render( string $html ): Worksheet_Pdf_Render_Result;
}

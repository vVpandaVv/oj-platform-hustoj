<?php
/**
 * Color scheme response and legacy API compatibility regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Platform\Addon_Page_Response;

ob_start();
require dirname( __DIR__ ) . '/fixtures/render-workflow-page.php';
ob_end_clean();

$response = Addon_Page_Response::html( '활동지', '<p>검사</p>', 403 );
if ( 'html' !== $response->kind() || 403 !== $response->status() || '<p>검사</p>' !== $response->body()
	|| 'standard' !== $response->layout() || ! $response->supportsDarkMode() ) {
	throw new RuntimeException( 'Color scheme opt-in changed the response contract.' );
}
foreach ( glob( dirname( __DIR__, 2 ) . '/src/Platform/*Page_Handler.php' ) as $handler ) {
	if ( str_contains( file_get_contents( $handler ), 'AddonPageResponseV1::html(' ) ) {
		throw new RuntimeException( 'Page bypasses the theme-aware response: ' . basename( $handler ) );
	}
}
echo "Page color scheme contract passed.\n";

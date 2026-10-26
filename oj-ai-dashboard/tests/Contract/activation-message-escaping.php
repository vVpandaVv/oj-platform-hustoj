<?php
/**
 * Verify activation diagnostics are escaped even without WordPress escaping helpers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'OJ_AI_DASHBOARD_VERSION', 'test' );

/** Capture wp_die output without rendering HTML or terminating the contract process. */
final class Ojai_Activation_Output extends RuntimeException {
}

/**
 * Replace the WordPress fatal-page renderer for this isolated contract.
 *
 * @param string $message Already escaped diagnostic.
 * @param string $title Page title.
 * @param array  $args Renderer settings.
 * @return never
 * @throws Ojai_Activation_Output Captures the exact diagnostic.
 */
function wp_die( string $message, string $title, array $args ): never {
	throw new Ojai_Activation_Output( $message );
}

require dirname( __DIR__, 2 ) . '/src/Core/Activation.php';

$abort = new ReflectionMethod( OJAI\Dashboard\Core\Activation::class, 'abort' );
$cases = array( '<script>alert("x")</script>', '"quoted" & <tag>', "invalid\xffutf8" );
foreach ( $cases as $text ) {
	try {
		$abort->invoke(
			null,
			array(
				'code'    => 'activation_failed',
				'message' => $text,
			)
		);
		throw new RuntimeException( 'Activation failure did not reach the fatal renderer.' );
	} catch ( Ojai_Activation_Output $output ) {
		$expected = htmlspecialchars( $text . ' (activation_failed, addon:test)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		if ( $expected !== $output->getMessage() || str_contains( $output->getMessage(), '<script>' ) ) {
			throw new RuntimeException( 'Activation fallback did not escape diagnostic HTML.' );
		}
	}
}
echo "PASS activation fallback: script markup, quotes, ampersands and invalid UTF-8 are escaped.\n";

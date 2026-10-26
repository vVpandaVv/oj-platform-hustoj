<?php
/**
 * Server recovery authorization regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
define( 'ABSPATH', '/wordpress/' );
require dirname( __DIR__, 3 ) . '/oj-integration-rebuild/oj-platform/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register( dirname( __DIR__, 3 ) . '/oj-integration-rebuild/oj-platform' );
$GLOBALS['serverAdmin'] = true;
/**
 * Check the fixture administrator capability.
 *
 * @param string $cap Capability.
 * @return bool
 */
function current_user_can( string $cap ): bool {
	return $GLOBALS['serverAdmin'] && 'install_plugins' === $cap; }
/**
 * Escape fixture attributes.
 *
 * @param string $value Value.
 * @return string
 */
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
/**
 * Escape fixture HTML.
 *
 * @param string $value Value.
 * @return string
 */
function esc_html( string $value ): string {
	return esc_attr( $value ); }
/**
 * Escape fixture textarea text.
 *
 * @param string $value Value.
 * @return string
 */
function esc_textarea( string $value ): string {
	return esc_attr( $value ); }
/**
 * Assert recovery behavior.
 *
 * @param bool   $value Expected condition.
 * @param string $message Failure message.
 * @return void
 * @throws RuntimeException When the assertion fails.
 */
function setup_assert( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new RuntimeException( $message ); } }

require dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new \OJAI\Dashboard\Core\Autoloader( dirname( __DIR__, 2 ) . '/src' ) )->register();
$policy   = new class() implements \OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface {
	/**
	 * Fixture denial status.
	 *
	 * @var int
	 */
	public int $status = 503;
	/**
	 * Return the fixture policy decision.
	 *
	 * @param \OJAI\Dashboard\Security\Policy\Access_Policy_Request $request Request.
	 * @return \OJAI\Dashboard\Security\Policy\Access_Policy_Decision
	 */
	public function authorize( \OJAI\Dashboard\Security\Policy\Access_Policy_Request $request ): \OJAI\Dashboard\Security\Policy\Access_Policy_Decision {
		return \OJAI\Dashboard\Security\Policy\Access_Policy_Decision::denied( $this->status, 'runtime_missing', $request->correlation_id() );
	}
};
$route    = 'addon.oj_ai_dashboard.console.settings';
$request  = new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1( $route, 1, array(), array(), 'ko_KR', 'server-setup-test' );
$handler  = new \OJAI\Dashboard\Platform\External_Ai_Consent_Settings_Page_Handler( $policy, $route );
$response = $handler->handle( $request );
setup_assert( 200 === $response->status() && str_contains( (string) $response->body(), 'install.sh' ), 'Administrator must be able to prepare unavailable runtime' );
$GLOBALS['serverAdmin'] = false;
$response               = $handler->handle( $request );
setup_assert( 503 === $response->status() && ! str_contains( (string) $response->body(), 'install.sh' ), 'Unavailable runtime must not expose installer to unauthorized user' );
$GLOBALS['serverAdmin'] = true;
$policy->status         = 403;
$response               = $handler->handle( $request );
setup_assert( 403 === $response->status() && ! str_contains( (string) $response->body(), 'install.sh' ), 'Installer recovery must not override policy denial' );
echo "AI server setup recovery and authorization passed.\n";

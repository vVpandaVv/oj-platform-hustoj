<?php
/**
 * Workspace navigation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Assessment_Project_Page_Handler;
use OJAI\Dashboard\Platform\Worksheet_Workflow;
use OJAI\Dashboard\Security\Addon_Capabilities as Caps;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$argv = array( __FILE__, 'projects' );
ob_start();
require dirname( __DIR__ ) . '/fixtures/render-workflow-page.php';
ob_end_clean();
$request = new AddonPageRequestV1( Addon_Manifest::CONSOLE_ASSIGNMENTS, 41, array(), array(), 'ko_KR', 'workspace-navigation' );
$check   = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
};
$cases   = array(
	array( array( Caps::VIEW_WORKSPACE ), array( 'projects' ), array() ),
	array( array( Caps::VIEW_WORKSPACE, Caps::VIEW_WORKSHEETS, Caps::MANAGE_WORKSHEETS ), array( 'projects', 'worksheets' ), array( 'criteria', 'author', 'review' ) ),
	array( array( Caps::VIEW_WORKSPACE, Caps::ASSIGN_WORKSHEETS ), array( 'projects', 'assignments' ), array( 'assignment' ) ),
	array( array( Caps::VIEW_WORKSPACE, Caps::ASSESS_SUBMISSIONS ), array( 'projects', 'assessments' ), array( 'assessment' ) ),
	array( array( Caps::VIEW_WORKSPACE, Caps::REVIEW_ASSESSMENTS, Caps::PUBLISH_FEEDBACK ), array( 'projects', 'assessments' ), array( 'assessment', 'report', 'release' ) ),
	array( array( Caps::VIEW_WORKSPACE, Caps::VIEW_REPORTS ), array( 'projects', 'reports' ), array() ),
	array( array( Caps::VIEW_WORKSPACE, Caps::MANAGE_POLICIES ), array( 'projects', 'criteria', 'settings' ), array() ),
);
foreach ( $cases as [$capabilities, $links, $stages] ) {
	$limited = new class($capabilities) implements Access_Policy_Port_Interface {
		/**
		 * Initialize workspace-navigation dependencies and state.
		 *
		 * @param array $capabilities Capabilities.
		 */
		public function __construct( private array $capabilities ) {
		}
		/**
		 * Authorize.
		 *
		 * @param Access_Policy_Request $request Request.
		 * @return Access_Policy_Decision
		 */
		public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
			return array_diff( $request->required_capabilities(), $this->capabilities )
				? Access_Policy_Decision::denied( 403, 'capability_denied', $request->correlation_id() )
				: Access_Policy_Decision::allowed( $request->correlation_id() );
		}
	};
	$markup  = Worksheet_Workflow::workspace( $request, $limited );
	preg_match_all( '/data-workspace-link="([^"]+)"/', $markup, $found );
	$check( $found[1] === $links, 'Navigation must match actual permissions' );
	$markup = Worksheet_Workflow::navigation( $request, 'assignment', '01K00000000000000000000001', false, false, $limited );
	$dom    = new DOMDocument();
	@$dom->loadHTML( '<meta charset="utf-8">' . $markup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The fixture deliberately parses browser HTML without emitting libxml diagnostics.
	$xp = new DOMXPath( $dom );
	$check( 7 === ( $xp->query( '//ol/li' )->length ), 'Every workflow stage remains identifiable' );
	$actual = array();
	foreach ( $xp->query( '//a[@data-workflow-step]' ) as $link ) {
		$actual[] = $link->getAttribute( 'data-workflow-step' );
	}
	$check( $actual === $stages, 'Unauthorized stages must not generate links' );
	$check( $xp->query( '//option[@disabled]' )->length === 7 - count( $stages ), 'Mobile options must enforce the same permissions' );
	if ( ! in_array( Caps::MANAGE_WORKSHEETS, $capabilities, true ) ) {
		$projectRequest = new AddonPageRequestV1( Addon_Manifest::CONSOLE_PROJECTS, 41, array(), array(), 'ko_KR', 'workspace-navigation' );
		$response       = ( new Assessment_Project_Page_Handler( $limited ) )->handle( $projectRequest );
		$check( $response->status() === ( count( $links ) > 1 ? 200 : 403 ), 'Authorized operators must reach their visible workspaces from the landing page' );
	}
}
echo "Workspace navigation passed: seven permission profiles, visible entry points, disabled steps, mobile parity.\n";

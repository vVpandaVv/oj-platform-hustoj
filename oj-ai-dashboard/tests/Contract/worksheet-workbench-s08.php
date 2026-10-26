<?php
/**
 * G2-IMP-02-S08 OJ Console worksheet workbench Page contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Platform\Worksheet_Workbench_Page_Handler;

$addon_root    = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $addon_root ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) ) {
	fwrite( STDERR, "Adjacent OJ Platform source was not found.\n" );
	exit( 1 );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $platform_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
PlatformAutoloader::register( $platform_root );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Wp create nonce.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	function wp_create_nonce( string $action ): string {
		return 'wp_rest' === $action ? 's08-rest-nonce' : '';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Home url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

/**
 * S08 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s08_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S08 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s08_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G2-IMP-02-S08 OJ Console worksheet workbench Page contract.
 */
final class S08Policy implements Access_Policy_Port_Interface {
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();
	/**
	 * Denied status.
	 *
	 * @var int|null
	 */
	public ?int $denied_status = null;
	/**
	 * Deny export.
	 *
	 * @var bool
	 */
	public bool $deny_export = false;
	/**
	 * Denied capabilities.
	 *
	 * @var array
	 */
	public array $denied_capabilities = array();

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if ( array_intersect( $this->denied_capabilities, $request->required_capabilities() ) ) {
			return Access_Policy_Decision::denied( 403, 's08_capability_denied', $request->correlation_id() );
		}
		if ( null !== $this->denied_status ) {
			return Access_Policy_Decision::denied(
				$this->denied_status,
				's08_policy_denied',
				$request->correlation_id()
			);
		}
		if ( $this->deny_export && 'worksheet.export.open' === $request->action_key() ) {
			return Access_Policy_Decision::denied( 403, 's08_export_denied', $request->correlation_id() );
		}

		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

$worksheet_id = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
$list_route   = 'addon.oj_ai_dashboard.console.worksheets';
$edit_route   = \OJAI\Dashboard\Platform\Addon_Manifest::CONSOLE_PROJECTS;

$list_policy   = new S08Policy();
$list_handler  = new Worksheet_Workbench_Page_Handler(
	$list_policy,
	$list_route,
	Worksheet_Workbench_Page_Handler::MODE_LIST
);
$list_response = $list_handler->handle(
	new AddonPageRequestV1( $list_route, 31, array(), array(), 'ko_KR', 's08-list-page' )
);
s08_same( 'html', $list_response->kind(), 'The worksheet list must return shell HTML.' );
s08_same( 200, $list_response->status(), 'The worksheet list must return 200.' );
$list_body = (string) $list_response->body();
foreach ( array( 'oj-ai-worksheet-authoring-app', 'data-view="list"', '새 평가에서 작성', 'data-field="search"', 'data-role="worksheet-list"' ) as $marker ) {
	s08_assert( str_contains( $list_body, $marker ), 'Worksheet list marker is missing: ' . $marker );
}
s08_assert( ! str_contains( $list_body, '준비 중입니다' ), 'The implemented list must not render a placeholder.' );
s08_assert( str_contains( $list_body, 'data-workspace-navigation' ), 'The list must expose the shared permission-scoped workspace navigation.' );
s08_same(
	array( Addon_Capabilities::VIEW_WORKSHEETS ),
	$list_policy->requests[0]->required_capabilities(),
	'The list Page must require view_worksheets.'
);
s08_same(
	array( Addon_Capabilities::MANAGE_WORKSHEETS ),
	$list_policy->requests[1]->required_capabilities(),
	'The create control must require manage_worksheets.'
);

$edit_policy   = new S08Policy();
$edit_handler  = new Worksheet_Workbench_Page_Handler(
	$edit_policy,
	$edit_route,
	Worksheet_Workbench_Page_Handler::MODE_EDIT
);
$edit_response = $edit_handler->handle(
	new AddonPageRequestV1(
		$edit_route,
		31,
		array( 'worksheet_id' => $worksheet_id ),
		array(),
		'ko_KR',
		's08-edit-page'
	)
);
s08_same( 'html', $edit_response->kind(), 'The worksheet editor must return shell HTML.' );
s08_same( 200, $edit_response->status(), 'The worksheet editor must return 200.' );
$edit_body = (string) $edit_response->body();
foreach (
	array(
		'data-view="edit"',
		'id="ws-template-title"',
		'id="ws-builder-schema"',
		'id="ws-render-schema"',
		'id="ws-plate-template-builder-root"',
		'data-action="save"',
		'data-action="asset-upload"',
		'data-action="import-template"',
		'data-action="duplicate"',
		'data-action="validate"',
		'data-action="preview-open"',
		'data-role="validation-result"',
		'data-role="preview-shell"',
		'data-action="preview-student"',
		'data-action="preview-print"',
		'data-field="asset-file"',
		'data-field="template-package"',
		'data-role="asset-tray"',
		'data-role="recovery"',
		'data-role="conflict"',
		'data-inspector-tab="question"',
		'data-inspector-tab="goals"',
		'data-inspector-tab="assessment"',
		'data-role="achievement-links"',
		'data-role="achievement-standards"',
		'data-role="assessment-map"',
	) as $marker
) {
	s08_assert( str_contains( $edit_body, $marker ), 'Worksheet editor marker is missing: ' . $marker );
}
$feature_requests = array_values( array_filter( $edit_policy->requests, static fn( Access_Policy_Request $r ): bool => 'workflow.navigation' !== $r->action_key() ) );
s08_same( 4, count( $feature_requests ), 'The editor must authorize edit, export, assessment and review separately.' );
s08_same( array( Addon_Capabilities::ASSESS_WORKSHEETS ), $feature_requests[2]->required_capabilities(), 'Embedded assessment must require assessment permission.' );
s08_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $feature_requests[3]->required_capabilities(), 'Embedded review must require review permission.' );
$edit_request = $edit_policy->requests[0];
s08_same(
	array( Addon_Capabilities::MANAGE_WORKSHEETS ),
	$edit_request->required_capabilities(),
	'The editor Page must require manage_worksheets.'
);
s08_assert( $edit_request->object() instanceof Object_Scope, 'The editor Page must include a Worksheet Object scope.' );
s08_same( Object_Scope::TYPE_WORKSHEET, $edit_request->object()?->type(), 'The editor Object type drifted.' );
s08_same( $worksheet_id, $edit_request->object()?->id(), 'The editor Object ID drifted.' );
s08_same( 'edit', $edit_request->object()?->action(), 'The editor Object action must be edit.' );
$export_request = $edit_policy->requests[1];
s08_same( 'worksheet.export.open', $export_request->action_key(), 'The export visibility action drifted.' );
s08_same(
	array( Addon_Capabilities::EXPORT_REPORTS ),
	$export_request->required_capabilities(),
	'The export controls must require export_reports.'
);
s08_same( null, $export_request->object(), 'Export visibility must not replace endpoint object authorization.' );
s08_assert( str_contains( $edit_body, 'data-role="export-controls"' ), 'An authorized editor must see export controls.' );
s08_assert( str_contains( $edit_body, 'data-can-export="1"' ), 'The editor export projection must expose the allowed state.' );

$export_denied_policy              = new S08Policy();
$export_denied_policy->deny_export = true;
$export_denied                     = ( new Worksheet_Workbench_Page_Handler(
	$export_denied_policy,
	$edit_route,
	Worksheet_Workbench_Page_Handler::MODE_EDIT
) )->handle(
	new AddonPageRequestV1(
		$edit_route,
		31,
		array( 'worksheet_id' => $worksheet_id ),
		array(),
		'ko_KR',
		's08-export-denied-page'
	)
);
s08_same( 200, $export_denied->status(), 'An export denial must not deny worksheet editing.' );
s08_same( 4, count( array_filter( $export_denied_policy->requests, static fn( Access_Policy_Request $r ): bool => 'workflow.navigation' !== $r->action_key() ) ), 'An export denial must not bypass embedded assessment permissions.' );
$export_denied_body = (string) $export_denied->body();
s08_assert( ! str_contains( $export_denied_body, 'data-role="export-controls"' ), 'Export controls must be hidden when export_reports is denied.' );
s08_assert( str_contains( $export_denied_body, 'data-can-export="0"' ), 'The editor export projection must fail closed.' );

$invalid_policy = new S08Policy();
$invalid        = ( new Worksheet_Workbench_Page_Handler(
	$invalid_policy,
	$edit_route,
	Worksheet_Workbench_Page_Handler::MODE_EDIT
) )->handle(
	new AddonPageRequestV1(
		$edit_route,
		31,
		array( 'worksheet_id' => 'invalid' ),
		array(),
		'ko_KR',
		's08-invalid-page'
	)
);
s08_same( 404, $invalid->status(), 'An invalid PublicId must be hidden as 404.' );
s08_same( 0, count( $invalid_policy->requests ), 'An invalid PublicId must not reach object authorization.' );

$blocked_policy                = new S08Policy();
$blocked_policy->denied_status = 503;
$blocked                       = ( new Worksheet_Workbench_Page_Handler(
	$blocked_policy,
	$list_route,
	Worksheet_Workbench_Page_Handler::MODE_LIST
) )->handle(
	new AddonPageRequestV1( $list_route, 31, array(), array(), 'ko_KR', 's08-blocked-page' )
);
s08_same( 'unavailable', $blocked->kind(), 'A Runtime denial must fail closed.' );

$review_only                      = new S08Policy();
$review_only->denied_capabilities = array( Addon_Capabilities::VIEW_WORKSHEETS, Addon_Capabilities::MANAGE_WORKSHEETS, Addon_Capabilities::ASSIGN_WORKSHEETS );
$review_home                      = ( new Worksheet_Workbench_Page_Handler( $review_only, $list_route, Worksheet_Workbench_Page_Handler::MODE_LIST ) )->handle( new AddonPageRequestV1( $list_route, 31, array(), array(), 'ko_KR', 'review-home' ) );
s08_same( 200, $review_home->status(), 'A workspace reviewer must retain a task entry point without worksheet read access.' );
s08_assert( ! str_contains( (string) $review_home->body(), 'oj-ai-worksheet-authoring-app' ), 'The reviewer entry must not load unauthorized worksheet data.' );
s08_assert( str_contains( (string) $review_home->body(), 'data-workspace-link="projects"' ), 'Reviewer entry must link to the evaluation landing page with authorized overview tasks.' );
$no_library                      = new S08Policy();
$no_library->denied_capabilities = array( Addon_Capabilities::MANAGE_POLICIES );
$limited_editor                  = ( new Worksheet_Workbench_Page_Handler( $no_library, $edit_route, Worksheet_Workbench_Page_Handler::MODE_EDIT ) )->handle( new AddonPageRequestV1( $edit_route, 31, array( 'worksheet_id' => $worksheet_id ), array(), 'ko_KR', 'limited-editor' ) );
s08_assert( ! str_contains( (string) $limited_editor->body(), 'data-criteria-library' ), 'Global library links must be hidden without management permission.' );
echo "G2-IMP-02-S08 workbench Page contract passed: list, Plate editor, capability, Object scope, recovery and conflict entrypoints.\n";

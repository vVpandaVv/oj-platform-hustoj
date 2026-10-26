<?php
/**
 * G4-IMP-03-S03 student submission score and evidence review Page contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as PlatformAutoloader;
use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Worksheet_Evaluation_Workbench_Page_Handler;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

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
		return 'wp_rest' === $action ? 'submission-s03-rest-nonce' : '';
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
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Get option.
	 *
	 * @param string $name Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $name, mixed $default = false ): mixed {
		return $default;
	}
}

/**
 * G4 s03 ui assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s03 ui same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s03_ui_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G4-IMP-03-S03 student submission score and evidence review Page contract.
 */
final class G4_S03_UI_Policy implements Access_Policy_Port_Interface {
	/**
	 * Requests.
	 *
	 * @var array<int,Access_Policy_Request>
	 */
	public array $requests = array();
	/**
	 * Denied actions.
	 *
	 * @var array<int,string>
	 */
	public array $denied_actions = array();
	/**
	 * Deny shell.
	 *
	 * @var bool
	 */
	public bool $deny_shell = false;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if (
			( $this->deny_shell && Addon_Manifest::CONSOLE_ASSESSMENTS === $request->action_key() )
			|| in_array( $request->action_key(), $this->denied_actions, true )
		) {
			return Access_Policy_Decision::denied( 403, 'g4_s03_ui_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G4 s03 ui page.
 *
 * @param G4_S03_UI_Policy $policy Policy.
 * @param string           $correlation_id Request correlation identifier for diagnostics.
 * @return object
 */
function g4_s03_ui_page( G4_S03_UI_Policy $policy, string $correlation_id ): object {
	$route = Addon_Manifest::CONSOLE_ASSESSMENTS;
	return ( new Worksheet_Evaluation_Workbench_Page_Handler( $policy, $route ) )->handle(
		new AddonPageRequestV1( $route, 41, array(), array(), 'ko_KR', $correlation_id )
	);
}

$policy   = new G4_S03_UI_Policy();
$response = g4_s03_ui_page( $policy, 'g4-s03-ui-all' );
g4_s03_ui_same( 'html', $response->kind(), 'The integrated assessment workbench must return HTML.' );
g4_s03_ui_same( 200, $response->status(), 'The integrated assessment workbench must return 200.' );
$body = (string) $response->body();
foreach (
	array(
		'data-oj-ai-assessment-shell',
		'data-default-tab="submission"',
		'data-assessment-tab="submission"',
		'data-assessment-tab="worksheet"',
		'id="oj-ai-submission-review-app"',
		'id="oj-ai-worksheet-evaluation-app"',
		'data-rest-root="https://example.test/wp-json/oj-ai/v1"',
		'data-rest-nonce="submission-s03-rest-nonce"',
		'data-review-field="context"',
		'data-review-field="state"',
		'<option value="not_evaluated">평가 전</option>',
		'data-can-assess="1"',
		'data-review-field="search"',
		'data-review-role="list"',
		'data-review-role="detail"',
		'data-review-role="reject-dialog"',
		'data-review-action="confirm-reject"',
	) as $marker
) {
	g4_s03_ui_assert( str_contains( $body, $marker ), 'Student review Page marker is missing: ' . $marker );
}
foreach ( array( 'expected_result_hash', 'provider_key', 'schema_version', 'task_key', 'raw_response' ) as $forbidden ) {
	g4_s03_ui_assert( ! str_contains( $body, $forbidden ), 'Internal contract copy must not be rendered: ' . $forbidden );
}
$policy->requests = array_values( array_filter( $policy->requests, static fn( Access_Policy_Request $r ): bool => 'workflow.navigation' !== $r->action_key() ) );
g4_s03_ui_same( 7, count( $policy->requests ), 'The Page must perform one shell and six feature checks.' );
$expected_capabilities = array(
	array( Addon_Capabilities::VIEW_WORKSPACE ),
	array( Addon_Capabilities::ASSESS_WORKSHEETS ),
	array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
	array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
	array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
	array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
	array( Addon_Capabilities::PUBLISH_FEEDBACK ),
);
foreach ( $expected_capabilities as $index => $capabilities ) {
	g4_s03_ui_same( $capabilities, $policy->requests[ $index ]->required_capabilities(), 'Capability order drifted at request ' . $index . '.' );
}

$reviewer                 = new G4_S03_UI_Policy();
$reviewer->denied_actions = array( 'worksheet.evaluation.assess', 'submission.evaluation.list' );
$reviewer_body            = (string) g4_s03_ui_page( $reviewer, 'g4-s03-ui-reviewer' )->body();
g4_s03_ui_assert( str_contains( $reviewer_body, 'id="oj-ai-submission-review-app"' ), 'A reviewer must receive the submission review workbench.' );
g4_s03_ui_assert( str_contains( $reviewer_body, 'data-can-review="1"' ), 'A reviewer must receive submission review controls.' );
g4_s03_ui_assert( str_contains( $reviewer_body, 'data-can-assess="0"' ), 'A reviewer without assess permission must not receive evaluation controls.' );
g4_s03_ui_assert( ! str_contains( $reviewer_body, '<option value="not_evaluated">평가 전</option>' ), 'A reviewer without assess permission must not receive the unevaluated filter.' );
g4_s03_ui_assert( ! str_contains( $reviewer_body, 'id="oj-ai-worksheet-evaluation-app"' ), 'Worksheet assessment must remain hidden without assess_worksheets.' );

$assessor                 = new G4_S03_UI_Policy();
$assessor->denied_actions = array( 'worksheet.evaluation.assess', 'worksheet.evaluation.review', 'submission.evaluation.review' );
$assessor_body            = (string) g4_s03_ui_page( $assessor, 'g4-s03-ui-assessor' )->body();
g4_s03_ui_assert( str_contains( $assessor_body, 'id="oj-ai-submission-review-app"' ), 'A submission assessor must receive the submission list.' );
g4_s03_ui_assert( str_contains( $assessor_body, 'data-can-assess="1"' ), 'A submission assessor must receive unevaluated submission controls.' );
g4_s03_ui_assert( str_contains( $assessor_body, 'data-can-review="0"' ), 'A submission assessor without review permission must receive read-only evaluation detail.' );

$worksheet                 = new G4_S03_UI_Policy();
$worksheet->denied_actions = array( 'worksheet.evaluation.review', 'submission.evaluation.list', 'submission.evaluation.review' );
$worksheet_body            = (string) g4_s03_ui_page( $worksheet, 'g4-s03-ui-worksheet' )->body();
g4_s03_ui_assert( str_contains( $worksheet_body, 'data-default-tab="worksheet"' ), 'Worksheet-only access must select the worksheet tab.' );
g4_s03_ui_assert( ! str_contains( $worksheet_body, 'id="oj-ai-submission-review-app"' ), 'Submission review must remain hidden without either submission capability.' );

$none                 = new G4_S03_UI_Policy();
$none->denied_actions = array(
	'worksheet.evaluation.assess',
	'worksheet.evaluation.review',
	'submission.evaluation.list',
	'submission.evaluation.review',
);
$none_response        = g4_s03_ui_page( $none, 'g4-s03-ui-none' );
g4_s03_ui_same( 403, $none_response->status(), 'No feature capability must fail closed.' );
g4_s03_ui_assert( ! str_contains( (string) $none_response->body(), 'oj-ai-submission-review-app' ), 'Denied users must not receive the review application.' );

$shell_denied             = new G4_S03_UI_Policy();
$shell_denied->deny_shell = true;
$shell_response           = g4_s03_ui_page( $shell_denied, 'g4-s03-ui-shell-denied' );
g4_s03_ui_same( 403, $shell_response->status(), 'Workspace denial must fail closed before feature rendering.' );
g4_s03_ui_same( 1, count( $shell_denied->requests ), 'Workspace denial must stop later feature checks.' );

$no_publish                 = new G4_S03_UI_Policy();
$no_publish->denied_actions = array( 'submission.result.publish' );
$no_publish_body            = (string) g4_s03_ui_page( $no_publish, 'g4-no-publish' )->body();
g4_s03_ui_assert( str_contains( $no_publish_body, 'data-can-publish="0"' ), 'Review permission must not imply release permission.' );
g4_s03_ui_assert( str_contains( $no_publish_body, 'data-can-review="1"' ), 'Release denial must preserve review permission.' );
echo "G4-IMP-03-S03 Page contract passed: integrated tabs, unevaluated submission controls, independent capabilities and fail-closed feature visibility.\n";

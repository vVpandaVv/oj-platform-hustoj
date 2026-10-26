<?php
/**
 * G4-IMP-04-S04 teacher feedback workbench Page contract.
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
		return 'wp_rest' === $action ? 'submission-feedback-s04-nonce' : '';
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
		unset( $name );
		return $default;
	}
}

/**
 * G4 s04 feedback ui assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_feedback_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s04 feedback ui same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_feedback_ui_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G4-IMP-04-S04 teacher feedback workbench Page contract.
 */
final class G4_S04_Feedback_UI_Policy implements Access_Policy_Port_Interface {
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
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if ( in_array( $request->action_key(), $this->denied_actions, true ) ) {
			return Access_Policy_Decision::denied( 403, 'g4_s04_feedback_ui_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

/**
 * G4 s04 feedback ui page.
 *
 * @param G4_S04_Feedback_UI_Policy $policy Policy.
 * @param string                    $correlation_id Request correlation identifier for diagnostics.
 * @return object
 */
function g4_s04_feedback_ui_page( G4_S04_Feedback_UI_Policy $policy, string $correlation_id ): object {
	$route = Addon_Manifest::CONSOLE_ASSESSMENTS;
	return ( new Worksheet_Evaluation_Workbench_Page_Handler( $policy, $route ) )->handle(
		new AddonPageRequestV1( $route, 41, array(), array(), 'ko_KR', $correlation_id )
	);
}

$policy   = new G4_S04_Feedback_UI_Policy();
$response = g4_s04_feedback_ui_page( $policy, 'g4-s04-feedback-ui-all' );
g4_s04_feedback_ui_same( 'html', $response->kind(), 'The feedback workbench must return HTML.' );
g4_s04_feedback_ui_same( 200, $response->status(), 'The feedback workbench must return 200.' );
$body = (string) $response->body();
foreach (
	array(
		'id="oj-ai-submission-review-app"',
		'data-can-review="1"',
		'data-feedback-role="reject-dialog"',
		'id="oj-ai-submission-feedback-reject-title"',
		'data-feedback-field="reject-reason"',
		'data-feedback-role="reject-status"',
		'data-feedback-action="confirm-reject"',
		'피드백 기각',
		'기각 사유',
	) as $marker
) {
	g4_s04_feedback_ui_assert( str_contains( $body, $marker ), 'Feedback Page marker is missing: ' . $marker );
}
foreach (
	array(
		'expected_feedback_revision_id',
		'expected_official_review_hash',
		'source_mode',
		'feedback_schema',
		'Q17',
		'C37',
		'CAS',
	) as $forbidden
) {
	g4_s04_feedback_ui_assert( ! str_contains( $body, $forbidden ), 'Internal contract copy must not be rendered: ' . $forbidden );
}
$page_requests = array_values( array_filter( $policy->requests, static fn ( Access_Policy_Request $request ): bool => 'workflow.navigation' !== $request->action_key() ) );
g4_s04_feedback_ui_same( 7, count( $page_requests ), 'The Page must retain one shell and six feature checks independently of navigation visibility.' );
g4_s04_feedback_ui_same( array( Addon_Capabilities::PUBLISH_FEEDBACK ), $page_requests[6]->required_capabilities(), 'Result release requires independent authorization.' );
g4_s04_feedback_ui_same(
	array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
	$page_requests[4]->required_capabilities(),
	'Submission feedback mutations must remain behind review_assessments.'
);
g4_s04_feedback_ui_same(
	array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
	$page_requests[5]->required_capabilities(),
	'Student-note controls must remain behind manage_student_notes.'
);

$reviewer                 = new G4_S04_Feedback_UI_Policy();
$reviewer->denied_actions = array( 'worksheet.evaluation.assess', 'submission.evaluation.list' );
$reviewer_body            = (string) g4_s04_feedback_ui_page( $reviewer, 'g4-s04-feedback-ui-reviewer' )->body();
g4_s04_feedback_ui_assert( str_contains( $reviewer_body, 'data-can-review="1"' ), 'A reviewer must receive feedback controls.' );
g4_s04_feedback_ui_assert( ! str_contains( $reviewer_body, 'id="oj-ai-worksheet-evaluation-app"' ), 'Worksheet UI must remain hidden from a submission-only reviewer.' );

$assessor                 = new G4_S04_Feedback_UI_Policy();
$assessor->denied_actions = array( 'worksheet.evaluation.assess', 'worksheet.evaluation.review', 'submission.evaluation.review' );
$assessor_body            = (string) g4_s04_feedback_ui_page( $assessor, 'g4-s04-feedback-ui-assessor' )->body();
g4_s04_feedback_ui_assert( str_contains( $assessor_body, 'data-can-review="0"' ), 'An assessor without review permission must receive a read-only client state.' );

$denied                 = new G4_S04_Feedback_UI_Policy();
$denied->denied_actions = array(
	'worksheet.evaluation.assess',
	'worksheet.evaluation.review',
	'submission.evaluation.list',
	'submission.evaluation.review',
);
$denied_response        = g4_s04_feedback_ui_page( $denied, 'g4-s04-feedback-ui-denied' );
g4_s04_feedback_ui_same( 403, $denied_response->status(), 'A user without either assessment feature must fail closed.' );
g4_s04_feedback_ui_assert(
	! str_contains( (string) $denied_response->body(), 'data-feedback-role="reject-dialog"' ),
	'Denied users must not receive feedback mutation markup.'
);

echo "G4-IMP-04-S04 Page contract passed: concise feedback dialog, review capability, read-only downgrade and fail-closed feature visibility.\n";

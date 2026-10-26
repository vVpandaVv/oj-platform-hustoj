<?php
/**
 * G2-IMP-05-S08 worksheet evaluation workbench Page contract.
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
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Worksheet_Evaluation_Execution_Consent;

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

$GLOBALS['eval_s08_consent'] = null;
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
		return 'wp_rest' === $action ? 'evaluation-s08-rest-nonce' : '';
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
		if ( WordPress_Worksheet_Evaluation_Execution_Consent::OPTION === $name ) {
			return $GLOBALS['eval_s08_consent'];
		}
		return $default;
	}
}

/**
 * Eval s08 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s08_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s08 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s08_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G2-IMP-05-S08 worksheet evaluation workbench Page contract.
 */
final class Eval_S08_Policy implements Access_Policy_Port_Interface {
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
	 * Deny review.
	 *
	 * @var bool
	 */
	public bool $deny_review = false;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		if (
			null !== $this->denied_status
			|| ( $this->deny_review && 'worksheet.evaluation.review' === $request->action_key() )
		) {
			return Access_Policy_Decision::denied(
				$this->denied_status ?? 403,
				'evaluation_s08_policy_denied',
				$request->correlation_id()
			);
		}
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
}

$route    = Addon_Manifest::CONSOLE_ASSESSMENTS;
$policy   = new Eval_S08_Policy();
$handler  = new Worksheet_Evaluation_Workbench_Page_Handler( $policy, $route );
$response = $handler->handle(
	new AddonPageRequestV1( $route, 31, array(), array(), 'ko_KR', 'evaluation-s08-page' )
);
eval_s08_same( 'html', $response->kind(), 'The evaluation workbench must return shell HTML.' );
eval_s08_same( 200, $response->status(), 'The evaluation workbench must return 200.' );
$body = (string) $response->body();
foreach (
	array(
		'oj-ai-worksheet-evaluation-app',
		'data-rest-root="https://example.test/wp-json/oj-ai/v1"',
		'data-rest-nonce="evaluation-s08-rest-nonce"',
		'data-can-review="1"',
		'data-consent-recorded="0"',
		'data-role="evaluation-list"',
		'data-role="evaluation-detail"',
		'data-action="new-evaluation"',
		'data-action="enqueue"',
		'data-field="external-processing-ack"',
		'<option value="review_required">교사 검토 대기</option>',
		'<option value="reviewed">교사 검토 완료</option>',
		'/oj-console/addons/oj-ai-dashboard/settings',
	) as $marker
) {
	eval_s08_assert( str_contains( $body, $marker ), 'Evaluation workbench marker is missing: ' . $marker );
}
eval_s08_assert( ! str_contains( $body, '준비 중입니다' ), 'The implemented evaluation Page must not render a placeholder.' );
$policy->requests = array_values( array_filter( $policy->requests, static fn( Access_Policy_Request $r ): bool => 'workflow.navigation' !== $r->action_key() ) );
eval_s08_same( 7, count( $policy->requests ), 'The integrated Page must check shell and feature capabilities independently.' );
eval_s08_same( array( Addon_Capabilities::PUBLISH_FEEDBACK ), $policy->requests[6]->required_capabilities(), 'Result release must have its own permission check.' );
eval_s08_same(
	array( Addon_Capabilities::VIEW_WORKSPACE ),
	$policy->requests[0]->required_capabilities(),
	'The integrated workbench must require workspace access.'
);
eval_s08_same(
	array( Addon_Capabilities::ASSESS_WORKSHEETS ),
	$policy->requests[1]->required_capabilities(),
	'The workbench must require assess_worksheets.'
);
eval_s08_same(
	array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
	$policy->requests[2]->required_capabilities(),
	'Review controls must require review_assessments.'
);
eval_s08_same(
	array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
	$policy->requests[3]->required_capabilities(),
	'The integrated workbench must check submission assessment access.'
);
eval_s08_same(
	array( Addon_Capabilities::REVIEW_ASSESSMENTS ),
	$policy->requests[4]->required_capabilities(),
	'Submission review controls must require review_assessments.'
);
eval_s08_same(
	array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
	$policy->requests[5]->required_capabilities(),
	'Student-note controls must require manage_student_notes.'
);

$policy_without_review              = new Eval_S08_Policy();
$policy_without_review->deny_review = true;
$without_review                     = ( new Worksheet_Evaluation_Workbench_Page_Handler( $policy_without_review, $route ) )->handle(
	new AddonPageRequestV1( $route, 31, array(), array(), 'ko_KR', 'evaluation-s08-no-review' )
);
eval_s08_assert(
	str_contains( (string) $without_review->body(), 'data-can-review="0"' ),
	'A teacher without worksheet review permission must receive a read-only worksheet workbench.'
);

$GLOBALS['eval_s08_consent'] = array(
	'approved_at_utc'     => '2026-08-21 01:00:00',
	'approved_by_user_id' => 31,
	'consent_hash'        => str_repeat( 'a', 64 ),
	'destination'         => array(
		'model_key'           => 'model',
		'profile_fingerprint' => str_repeat( 'b', 64 ),
		'provider_key'        => 'provider',
	),
	'input_categories'    => WordPress_Worksheet_Evaluation_Execution_Consent::DATA_CATEGORIES,
	'schema_id'           => WordPress_Worksheet_Evaluation_Execution_Consent::SCHEMA,
	'schema_version'      => WordPress_Worksheet_Evaluation_Execution_Consent::VERSION,
	'state'               => 'approved',
	'task_key'            => Addon_Manifest::TASK_WORKSHEET_QUALITY,
);
$recorded                    = ( new Worksheet_Evaluation_Workbench_Page_Handler( new Eval_S08_Policy(), $route ) )->handle(
	new AddonPageRequestV1( $route, 31, array(), array(), 'ko_KR', 'evaluation-s08-consent' )
);
eval_s08_assert(
	str_contains( (string) $recorded->body(), 'data-consent-recorded="1"' ),
	'A structurally valid approval record must be projected only as recorded.'
);

$wrong_route = $handler->handle(
	new AddonPageRequestV1( 'addon.other.route', 31, array(), array(), 'ko_KR', 'evaluation-s08-wrong-route' )
);
eval_s08_same( 'unavailable', $wrong_route->kind(), 'A route mismatch must fail closed.' );

$blocked_policy                = new Eval_S08_Policy();
$blocked_policy->denied_status = 503;
$blocked                       = ( new Worksheet_Evaluation_Workbench_Page_Handler( $blocked_policy, $route ) )->handle(
	new AddonPageRequestV1( $route, 31, array(), array(), 'ko_KR', 'evaluation-s08-blocked' )
);
eval_s08_same( 'unavailable', $blocked->kind(), 'A Runtime denial must fail closed.' );

echo "G2-IMP-05-S08 evaluation Page contract passed: assess/review capability split, consent record projection, Console URLs and real workbench entrypoints.\n";

<?php
/**
 * G4-IMP-06-S05 student result PublicUI Page contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as Platform_Autoloader;
use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Student_Result_Page_Handler;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;

$addon_root    = dirname( __DIR__, 2 );
$platform_root = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platform_root ) || '' === $platform_root ) {
	$platform_root = dirname( $addon_root ) . '/oj-integration-rebuild/oj-platform';
}
$platform_root = realpath( $platform_root );
if ( ! is_string( $platform_root ) || ! is_file( $platform_root . '/src/AddonApi/V1/AddonPageRequestV1.php' ) ) {
	throw new RuntimeException( 'Adjacent OJ Platform Addon API source was not found.' );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $platform_root . '/src/Kernel/Autoloader.php';
Platform_Autoloader::register( $platform_root );
require_once $addon_root . '/src/Core/Autoloader.php';
( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4-IMP-06-S05 student result PublicUI Page contract.
 */
final class G4_S05_Result_Query implements Submission_Result_Query_Port_Interface {
	/**
	 * Throw.
	 *
	 * @var bool
	 */
	public bool $throw = false;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $snapshot;

	/**
	 * Initialize g4 s05 result query dependencies and state.
	 *
	 * @param string $release_id Release id.
	 */
	public function __construct( string $release_id ) {
		$this->snapshot = array(
			'release_id'       => $release_id,
			'release_hash'     => str_repeat( 'a', 64 ),
			'release_state'    => 'released',
			'submission_id'    => '15040G2081040G2081040G2081',
			'context_type'     => 'class',
			'context_id'       => 'class-21',
			'context_revision' => 'member-r2',
			'assignment_state' => 'open',
			'target_state'     => 'active',
		);
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $release_id Release id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $release_id, int $actor_user_id ): ?array {
		++$this->calls;
		if ( $this->throw ) {
			throw new RuntimeException( 'fixture unavailable' );
		}
		if ( 42 !== $actor_user_id || null === $this->snapshot || $release_id !== $this->snapshot['release_id'] ) {
			return null;
		}
		return $this->snapshot;
	}

	/**
	 * Find current for actor.
	 *
	 * @param string $release_id Release id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $context Access or execution context for this operation.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_current_for_actor( string $release_id, int $actor_user_id, array $context ): ?array {
		unset( $release_id, $actor_user_id, $context );
		throw new RuntimeException( 'The Page handler must not read the result body.' );
	}
}

/**
 * G4-IMP-06-S05 student result PublicUI Page contract.
 */
final class G4_S05_Page_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Denied status.
	 *
	 * @var int|null
	 */
	public ?int $denied_status = null;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		$this->last_request = $request;
		if ( null !== $this->denied_status ) {
			return Access_Policy_Decision::denied( $this->denied_status, 'result_page_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if ( ! $context instanceof Context_Scope || ! $object instanceof Object_Scope ) {
			return Access_Policy_Decision::denied( 403, 'result_scope_missing', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( $context->type(), $context->id(), 'active', (string) $context->expected_revision(), 'learner' ),
			Object_Authorization_Result::allowed( str_repeat( 'a', 64 ), 'released', 'self_release' )
		);
	}
}

$release_id = '1A040G2081040G2081040G2081';
$query      = new G4_S05_Result_Query( $release_id );
$policy     = new G4_S05_Page_Policy();
$handler    = new Student_Result_Page_Handler( $policy, $query );
$request    = new AddonPageRequestV1(
	Addon_Manifest::PUBLIC_RESULT,
	42,
	array( 'release_id' => $release_id ),
	array(),
	'ko_KR',
	'g4-s05-page'
);
$response   = $handler->handle( $request );
$body       = (string) $response->body();
g4_s05_same( 'html', $response->kind(), 'S05 result Page must render through AddonPageResponseV1.' );
g4_s05_same( 200, $response->status(), 'S05 authorized result Page status drifted.' );
g4_s05_same( '활동지 결과', $response->title(), 'S05 result Page title drifted.' );
g4_s05_assert( str_contains( $body, 'id="oj-ai-student-result-app"' ), 'S05 result application root is missing.' );
g4_s05_assert( str_contains( $body, 'data-release-id="' . $release_id . '"' ), 'S05 result shell omitted the exact release ID.' );
g4_s05_assert( str_contains( $body, '활동지 목록' ) && str_contains( $body, '불러오는 중...' ), 'S05 direct navigation or loading state is missing.' );
foreach ( array( 'teacher_memo', 'student_note', 'provider_key', 'model_key', 'answer_key' ) as $private ) {
	g4_s05_assert( ! str_contains( $body, $private ), 'S05 server shell leaked a private field: ' . $private );
}

$policy_request = $policy->last_request;
g4_s05_assert( $policy_request instanceof Access_Policy_Request, 'S05 Page policy handoff is missing.' );
g4_s05_same( Access_Policy_Request::SHELL_PUBLIC, $policy_request->shell(), 'S05 Page must use PublicUI shell policy.' );
g4_s05_same( Access_Policy_Request::SURFACE_PAGE, $policy_request->surface(), 'S05 Page surface drifted.' );
g4_s05_same( Addon_Manifest::PUBLIC_RESULT, $policy_request->action_key(), 'S05 Page action key drifted.' );
g4_s05_same( array( Addon_Capabilities::PARTICIPATE ), $policy_request->required_capabilities(), 'S05 Page capability drifted.' );
$context = $policy_request->context();
$object  = $policy_request->object();
g4_s05_assert( $context instanceof Context_Scope && $object instanceof Object_Scope, 'S05 exact Context and release scope are required.' );
g4_s05_same( 'member-r2', $context->expected_revision(), 'S05 must pin the Q33 Context revision.' );
g4_s05_same( 42, $context->learner_user_id(), 'S05 must pin the authenticated learner.' );
g4_s05_same( Object_Scope::TYPE_RELEASE, $object->type(), 'S05 object type drifted.' );
g4_s05_same( $release_id, $object->id(), 'S05 must authorize the exact release.' );
g4_s05_same( 'self_view', $object->action(), 'S05 release action drifted.' );

$invalid = $handler->handle( new AddonPageRequestV1( Addon_Manifest::PUBLIC_RESULT, 42, array( 'release_id' => 'bad' ), array(), 'ko_KR', 'g4-s05-invalid' ) );
g4_s05_same( 404, $invalid->status(), 'S05 invalid release ID must fail closed as not found.' );
$query->snapshot['target_state'] = 'revoked';
$calls_before_revocation         = $policy->calls;
$revoked                         = $handler->handle( $request );
g4_s05_same( 404, $revoked->status(), 'S05 revoked learner target must fail closed.' );
g4_s05_same( $calls_before_revocation, $policy->calls, 'S05 revoked target must not reach Page policy.' );
$query->snapshot['target_state'] = 'active';

$policy->denied_status = 403;
$denied                = $handler->handle( $request );
g4_s05_same( 403, $denied->status(), 'S05 permission revocation must remain an explicit denial.' );
g4_s05_assert( str_contains( (string) $denied->body(), '결과를 확인할 수 없습니다.' ), 'S05 denial copy drifted.' );
g4_s05_assert( ! str_contains( (string) $denied->body(), $release_id ), 'S05 denial must not echo the requested release ID.' );
$policy->denied_status = null;

$query->throw = true;
$unavailable  = $handler->handle( $request );
g4_s05_same( 'unavailable', $unavailable->kind(), 'S05 query failure must use the Addon API unavailable response.' );
g4_s05_same( 503, $unavailable->status(), 'S05 query failure status drifted.' );

$wrong_route = $handler->handle( new AddonPageRequestV1( Addon_Manifest::PUBLIC_WORKSHEETS, 42, array(), array(), 'ko_KR', 'g4-s05-route' ) );
g4_s05_same( 'unavailable', $wrong_route->kind(), 'S05 handler must reject another contribution route.' );

$handler_source  = (string) file_get_contents( $addon_root . '/src/Platform/Student_Result_Page_Handler.php' );
$manifest_source = (string) file_get_contents( $addon_root . '/src/Platform/Addon_Manifest.php' );
$root_source     = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_s05_assert( ! str_contains( $handler_source . $manifest_source . $root_source, $forbidden ), 'S05 runtime contains a forbidden boundary marker: ' . $forbidden );
}
g4_s05_assert( str_contains( $manifest_source, 'resources/css/student-results.css' ) && str_contains( $manifest_source, 'resources/js/student-results.js' ), 'S05 result assets are not route-bundle registered.' );
g4_s05_assert( str_contains( $root_source, 'new Student_Result_Page_Handler' ) && Ojai_Php_Source_Contract::contains( $root_source, 'Addon_Manifest::PUBLIC_RESULT => $student_result_page_handler' ), 'S05 result Page handler is not wired into Composition Root.' );

fwrite( STDOUT, "G4-IMP-06-S05 Page passed: current release, exact Context, self-view authorization, revocation, unavailable state, and PublicUI asset wiring are frozen.\n" );

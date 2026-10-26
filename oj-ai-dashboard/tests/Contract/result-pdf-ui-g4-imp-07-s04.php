<?php
/**
 * G4-IMP-07-S04 student result PDF UI integration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Autoloader as Platform_Autoloader;
use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Platform\Student_Result_Page_Handler;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
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
 * G4 s04 ui assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4-IMP-07-S04 student result PDF UI integration contract.
 */
final class G4_S04_Ui_Result_Query implements Submission_Result_Query_Port_Interface {
	/**
	 * Authorization snapshot.
	 *
	 * @param string $release_id Release id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $release_id, int $actor_user_id ): ?array {
		if ( 42 !== $actor_user_id || '1A040G2081040G2081040G2081' !== $release_id ) {
			return null;
		}
		return array(
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
		throw new RuntimeException( 'The S04 Page shell must not read result or PDF bodies.' );
	}
}

/**
 * G4-IMP-07-S04 student result PDF UI integration contract.
 */
final class G4_S04_Ui_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		$context            = $request->context();
		$object             = $request->object();
		if ( null === $context || null === $object ) {
			return Access_Policy_Decision::denied( 403, 'scope_missing', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( $context->type(), $context->id(), 'active', (string) $context->expected_revision(), 'learner' ),
			Object_Authorization_Result::allowed( str_repeat( 'a', 64 ), 'released', 'self_release' )
		);
	}
}

$release_id = '1A040G2081040G2081040G2081';
$policy     = new G4_S04_Ui_Policy();
$handler    = new Student_Result_Page_Handler( $policy, new G4_S04_Ui_Result_Query() );
$response   = $handler->handle(
	new AddonPageRequestV1(
		Addon_Manifest::PUBLIC_RESULT,
		42,
		array( 'release_id' => $release_id ),
		array(),
		'ko_KR',
		'g4-s04-result-pdf-ui'
	)
);
$body       = (string) $response->body();
g4_s04_ui_assert( 200 === $response->status(), 'S04 authorized result Page failed.' );
g4_s04_ui_assert( str_contains( $body, 'id="oj-ai-student-result-app"' ), 'S04 result application root is missing.' );
g4_s04_ui_assert( str_contains( $body, 'data-release-id="' . $release_id . '"' ), 'S04 exact release ID is missing.' );
g4_s04_ui_assert( str_contains( $body, 'data-locale="ko-KR"' ), 'S04 Page locale was not normalized for C52.' );

$fallback = $handler->handle(
	new AddonPageRequestV1(
		Addon_Manifest::PUBLIC_RESULT,
		42,
		array( 'release_id' => $release_id ),
		array(),
		'unsafe_locale',
		'g4-s04-result-pdf-locale'
	)
);
g4_s04_ui_assert( str_contains( (string) $fallback->body(), 'data-locale="ko-KR"' ), 'S04 invalid locale did not fail closed to ko-KR.' );

$runtime_files  = array(
	$addon_root . '/src/Platform/Student_Result_Page_Handler.php',
	$addon_root . '/resources/js/student-results.js',
	$addon_root . '/resources/css/student-results.css',
	$addon_root . '/tests/fixtures/student-result-visual.html',
	$addon_root . '/tests/fixtures/generate-result-pdf-visual.php',
);
$runtime_source = '';
foreach ( $runtime_files as $file ) {
	$runtime_source .= (string) file_get_contents( $file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_s04_ui_assert( ! str_contains( $runtime_source, $forbidden ), 'S04 runtime contains a forbidden boundary marker: ' . $forbidden );
}

$javascript = (string) file_get_contents( $addon_root . '/resources/js/student-results.js' );
foreach ( array(
	'/commands/export-pdf',
	'/export-jobs/',
	'/artifacts/',
	"'Idempotency-Key': token",
	'expected_release_hash',
	'expected_release_state',
	"payload: { locale, profile: 'student_result' }",
	"job.artifact_kind === 'result_pdf'",
	"job.source_type === 'submission_release'",
	"setPdfUi('download-result-pdf', 'PDF 다운로드', '준비됨'",
	"setPdfUi('create-result-pdf', '다시 만들기', 'PDF가 만료되었습니다.'",
	'[401, 403, 404, 409].includes(status)',
	"Accept: 'application/pdf'",
	"kind !== 'result_pdf'",
	'blob.size !== expectedBytes',
	'cancelPdf();',
) as $marker ) {
	g4_s04_ui_assert( str_contains( $javascript, $marker ), 'S04 result PDF UI marker is missing: ' . $marker );
}
foreach ( array( 'localStorage', 'sessionStorage' ) as $storage_marker ) {
	g4_s04_ui_assert( ! str_contains( $javascript, $storage_marker ), 'S04 persisted a private PDF capability in browser storage.' );
}

$css = (string) file_get_contents( $addon_root . '/resources/css/student-results.css' );
foreach ( array( '.oj-ai-result-pdf', '.oj-ai-result-pdf__button', '.oj-ai-result-pdf__status', 'min-height: 44px', '@media (max-width: 700px)', 'overflow-wrap: anywhere' ) as $marker ) {
	g4_s04_ui_assert( str_contains( $css, $marker ), 'S04 responsive PDF UI marker is missing: ' . $marker );
}

fwrite( STDOUT, "G4-IMP-07-S04 UI contract passed: normalized locale, exact C52, allowlisted Q23, private Q24, revocation clearing, expiry remake, and responsive state controls are frozen.\n" );

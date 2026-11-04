<?php
/**
 * Screen rendering shares the immutable PDF projection and teacher scope.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/submission-pdf-enqueue-g3-imp-08-s01.php';

use OJAI\Dashboard\Reporting\SubmissionPdf\Presentation\Submission_Pdf_Rest_Controller;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

/**
 * WordPress response fixture.
 */
class WP_REST_Response {
	/**
	 * Captured response headers.
	 *
	 * @var array<string,string>
	 */
	public array $headers = array();
	/**
	 * Retain the response body and status.
	 *
	 * @param mixed $data Response body.
	 * @param int   $status HTTP status.
	 */
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	/**
	 * Capture a response header.
	 *
	 * @param string $name Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function header( string $name, string $value ): void {
		$this->headers[ $name ] = $value; }
}
/**
 * WordPress error fixture.
 */
class WP_Error {
	/**
	 * Retain the error details.
	 *
	 * @param string              $code Error code.
	 * @param string              $message Error message.
	 * @param array<string,mixed> $data Error data.
	 */
	public function __construct( public string $code, public string $message, public array $data ) {}
}
/**
 * Return the synthetic current actor.
 *
 * @return int
 */
function get_current_user_id(): int {
	return $GLOBALS['screen_actor'] ?? 42; }
/**
 * Capture the registered route configuration.
 *
 * @param string              $namespace REST namespace.
 * @param string              $route Route path.
 * @param array<string,mixed> $args Route configuration.
 * @return void
 */
function register_rest_route( string $namespace, string $route, array $args ): void {
	$GLOBALS['screen_routes'][ $route ] = $args; }

$screen_policy       = new class($submission_id) implements Access_Policy_Port_Interface {
	/**
	 * Whether all requests should be denied.
	 *
	 * @var bool
	 */
	public bool $deny = false;
	/**
	 * Whether only the teacher-review capability is available.
	 *
	 * @var bool
	 */
	public bool $review_only = false;
	/**
	 * Retain the authorized submission identifier.
	 *
	 * @param string $submission_id Submission identifier.
	 */
	public function __construct( private string $submission_id ) {}
	/**
	 * Enforce the fixture actor, context and capability rules.
	 *
	 * @param Access_Policy_Request $request Authorization request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		g3s01_assert( ! in_array( Addon_Capabilities::EXPORT_REPORTS, $request->required_capabilities(), true ), 'Screen view must not require export capability.' );
		if ( $this->review_only && $request->required_capabilities() !== array( Addon_Capabilities::REVIEW_ASSESSMENTS ) ) {
			return Access_Policy_Decision::denied( 403, 'capability_denied', $request->correlation_id() );
		}
		g3s01_same( $this->submission_id, $request->object()->id(), 'Permission must bind the submission.' );
		if ( $this->deny || 0 === $request->actor_user_id() || 'class-21' !== $request->context()->id() ) {
			return Access_Policy_Decision::denied( 403, 'context_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id(), Context_Authorization_Result::allowed( 'class', 'class-21', 'active', 'class-r3', 'staff' ), Object_Authorization_Result::allowed( '2', 'submitted', 'context_staff' ) );
	}
};
$screen_correlations = new Correlation_Id_Factory();
$screen_envelopes    = new Rest_Envelope_Factory();
$screen_permissions  = new Rest_Permission_Handoff( new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ), new Rest_Request_Authorizer( $screen_policy, new WordPress_Rest_Nonce_Verifier(), $screen_correlations ), $screen_envelopes, $screen_correlations );
$screen_controller   = new Submission_Pdf_Rest_Controller( $screen_permissions, $enqueue, $submission_source, $canonical, $screen_envelopes, $screen_correlations, $builder, new Submission_Print_Html_Serializer( $canonical ) );
$screen_controller->register_routes();
g3s01_same( 'GET', $GLOBALS['screen_routes'][ Submission_Pdf_Rest_Controller::ROUTE_VIEW ]['methods'], 'View must be read-only.' );
$screen_request = new class($submission_revision_id) {
	/**
	 * Context selected for the synthetic request.
	 *
	 * @var string
	 */
	public string $context_id = 'class-21';
	/**
	 * Retain the requested revision identifier.
	 *
	 * @param string $revision Submission revision identifier.
	 */
	public function __construct( public string $revision ) {}
	/**
	 * Return the read-only request method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET'; }
	/**
	 * Return an absent optional header.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		return ''; }
	/**
	 * Return the requested revision path parameter.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'revision_id' => $this->revision ); }
	/**
	 * Return the selected context parameters.
	 *
	 * @return array<string,string>
	 */
	public function get_query_params(): array {
		return array(
			'context_type'     => 'class',
			'context_id'       => $this->context_id,
			'context_revision' => 'class-r3',
		); }
};
g3s01_same( true, $screen_controller->permissions_view( $screen_request ), 'Teacher should be authorized.' );
$screen_response = $screen_controller->view( $screen_request );
g3s01_assert( $screen_response instanceof WP_REST_Response, 'Screen view failed: ' . json_encode( $screen_response ) );
g3s01_same( 'private, no-store', $screen_response->headers['Cache-Control'], 'Student answers cannot be cached.' );
$screen_html = $screen_response->data['data']['submission_view']['html'];
foreach ( array( '계절 관찰', '김하늘', '긴 답', '관찰표', '봄', '동쪽', '서쪽', '&lt;script&gt;' ) as $text ) {
	g3s01_assert( str_contains( $screen_html, $text ), 'Missing full document content: ' . $text );
}
g3s01_assert( str_contains( $screen_html, '@media screen' ) && str_contains( $screen_html, 'Content-Security-Policy' ), 'Responsive sandbox content is required.' );
g3s01_assert( ! str_contains( $screen_html, '<script>' ), 'Answers must remain escaped.' );
$screen_policy->deny = true;
g3s01_assert( $screen_controller->permissions_view( $screen_request ) instanceof WP_Error, 'Denied teacher may not read.' );
$screen_policy->deny        = false;
$screen_policy->review_only = true;
g3s01_same( true, $screen_controller->permissions_view( $screen_request ), 'Teacher-only review capability must be sufficient.' );
g3s01_assert( $screen_controller->view( $screen_request ) instanceof WP_REST_Response, 'Teacher-first grading must be able to view answers.' );
$screen_policy->review_only = false;
$GLOBALS['screen_actor']    = 0;
g3s01_assert( $screen_controller->permissions_view( $screen_request ) instanceof WP_Error, 'Anonymous access must fail.' );
$GLOBALS['screen_actor']    = 42;
$screen_request->context_id = 'class-99';
g3s01_assert( $screen_controller->permissions_view( $screen_request ) instanceof WP_Error, 'Cross-context read must fail.' );
$screen_request->context_id = 'class-21';
g3s01_same( true, $screen_controller->permissions_view( $screen_request ), 'Fresh grant required.' );
$saved_source = $submission_source->source;
$submission_source->source['official_revision']['id'] = g3s01_id( 99 );
g3s01_assert( $screen_controller->view( $screen_request ) instanceof WP_Error, 'An obsolete official revision must fail after authorization.' );
$submission_source->source = $saved_source;
if ( in_array( '--fixture', $argv, true ) ) {
	$path = dirname( __DIR__ ) . '/artifacts/submission-screen.html';
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $screen_html );
}
echo "Submission screen view passed: full questions, ten answer kinds, escaping, no AI, teacher scope, stale official revision, no-store.\n";

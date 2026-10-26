<?php
/**
 * REST permission, nonce, receipt and append command wiring.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Read_Assignments;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\Assignment_Object_Authorization;
use OJAI\Dashboard\Submission\Assignment\Presentation\Assignment_Rest_Controller;

ob_start();
require __DIR__ . '/assignment-late-joiners.php';
$late = array( $db, $repo, $service, $id, $context, $canonical );
require __DIR__ . '/rest-foundation.php';
ob_end_clean();
list( $db, $repo, $service, $id, $context, $canonical ) = $late;
$db->query( "UPDATE oj_ai_assignments SET state = 'open', due_at_utc = '2099-09-20 00:00:00'" );
$policy     = new Rest_Foundation_Policy_Fixture();
$handoff    = new Rest_Permission_Handoff( $adapter, new Rest_Request_Authorizer( $policy, $nonces, $correlations ), $envelopes, $correlations );
$controller = new Assignment_Rest_Controller( $handoff, new Read_Assignments( $repo ), ( new ReflectionClass( Create_Assignment::class ) )->newInstanceWithoutConstructor(), $canonical, $envelopes, $correlations, $service );

/** Synthetic REST request with mutable headers for negative tests. */
final class Late_Target_Request {
	/**
	 * Request fixture.
	 *
	 * @param string $id Assignment ID.
	 * @param array  $query Context query.
	 * @param array  $body Command body.
	 * @param string $method Method.
	 * @param array  $headers Standard headers.
	 */
	public function __construct( public string $id, public array $query, public array $body = array(), public string $method = 'GET', public array $headers = array() ) {
	}
	/**
	 * URL params.
	 *
	 * @return array
	 */
	public function get_url_params(): array {
		return array( 'assignment_id' => $this->id );
	}
	/**
	 * Context params.
	 *
	 * @return array
	 */
	public function get_query_params(): array {
		return $this->query;
	}
	/**
	 * Body.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return $this->body;
	}
	/**
	 * HTTP method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}
	/**
	 * HTTP header.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function get_header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
}

$object_policy = new Assignment_Object_Authorization( $repo );
$object        = new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $id, 'add_targets' );
$authorized    = Context_Authorization_Result::allowed( $context['context_type'], $context['context_id'], 'active', $context['context_revision'], 'educator' );
g3_assert( $object_policy->authorize( 42, 'assignment.add_targets', $authorized, $object )->is_allowed(), 'Exact staff context can add targets.' );
$wrong = Context_Authorization_Result::allowed( $context['context_type'], 'other-context', 'active', $context['context_revision'], 'educator' );
g3_assert( $object_policy->authorize( 42, 'assignment.add_targets', $wrong, $object )->is_denied(), 'Different context cannot add targets.' );
$db->query( "UPDATE oj_ai_assignments SET state = 'closed'" );
g3_assert( $object_policy->authorize( 42, 'assignment.add_targets', $authorized, $object )->is_denied(), 'Object authorization blocks closed assignment mutation.' );
$db->query( "UPDATE oj_ai_assignments SET state = 'open'" );

$get                         = new Late_Target_Request( $id, $context );
$GLOBALS['oj_ai_rest_actor'] = 42;
g3_same( true, $controller->permissions_show( $get ), 'Preview requires assignment view permission.' );
$preview = $controller->target_candidates( $get );
g3_assert( $preview instanceof WP_REST_Response, 'Preview is a standard REST response.' );
$body   = array(
	'expected'         => $preview->get_data()['data']['expected'],
	'learner_user_ids' => array( 10003 ),
);
$post   = new Late_Target_Request(
	$id,
	$context,
	$body,
	'POST',
	array(
		'x-wp-nonce'      => 'invalid-rest-nonce',
		'idempotency-key' => 'late-rest-00001',
	)
);
$denied = $controller->permissions_add_targets( $post );
g3_assert( $denied instanceof WP_Error && 403 === $denied->get_error_data()['status'], 'Invalid nonce must fail before mutation.' );
$post->headers['x-wp-nonce']      = 'valid-rest-nonce';
$post->headers['idempotency-key'] = '';
$denied                           = $controller->permissions_add_targets( $post );
g3_assert( $denied instanceof WP_Error && 422 === $denied->get_error_data()['status'], 'Missing idempotency key must fail.' );
$post->headers['idempotency-key'] = 'late-rest-00001';
$post->body['actor_user_id']      = 999;
g3_assert( $controller->permissions_add_targets( $post ) instanceof WP_Error, 'Caller cannot inject an actor or additional keys.' );
unset( $post->body['actor_user_id'] );
$GLOBALS['oj_ai_rest_actor'] = 0;
$denied                      = $controller->permissions_add_targets( $post );
g3_assert( $denied instanceof WP_Error && 401 === $denied->get_error_data()['status'], 'Anonymous mutation fails.' );
$GLOBALS['oj_ai_rest_actor'] = 42;
g3_same( true, $controller->permissions_add_targets( $post ), 'Authorized teacher command passes common checks.' );
g3_same( array( Addon_Capabilities::ASSIGN_WORKSHEETS ), $policy->last_request->required_capabilities(), 'The route requires assignment management, not only reading.' );
g3_same( 'add_targets', $policy->last_request->object()->action(), 'The explicit object transition reaches authorization.' );
$response = $controller->add_targets( $post );
g3_assert( $response instanceof WP_REST_Response, 'Append produces a standard response.' );
g3_same( 1, $response->get_data()['data']['added_count'], 'The REST route reaches the real transaction.' );
g3_assert( $controller->add_targets( $post ) instanceof WP_Error, 'A permission grant is consumed only once.' );
g3_same( true, $controller->permissions_add_targets( $post ), 'A repeated request is reauthorized.' );
g3_same( true, $controller->add_targets( $post )->get_data()['data']['replayed'], 'REST retries read the original receipt.' );
echo "PASS late-joiner REST: scoped object action, required capability, authenticated actor, nonce, idempotency, exact input, real commit and receipt replay.\n";

<?php
/**
 * Project REST errors distinguish public input guidance from internal exceptions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

/**
 * Project REST errors distinguish public input guidance from internal exceptions.
 */
final class WP_Error {
	/**
	 * Initialize wp error dependencies and state.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param mixed  $data Data.
	 */
	public function __construct( private string $code, private string $message, private mixed $data = array() ) {
	}
	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}
	/**
	 * Get error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
	/**
	 * Get error data.
	 *
	 * @return mixed
	 */
	public function get_error_data(): mixed {
		return $this->data;
	}
}

require __DIR__ . '/worksheet-validation-s01.php';

use OJAI\Dashboard\Assessment\Project\Domain\Project_Input_Failure;
use OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface;
use OJAI\Dashboard\Assessment\Project\Presentation\Project_Rest_Controller;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

$project_policy      = new class() implements Access_Policy_Port_Interface {
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		s17_same( 42, $request->actor_user_id(), 'Project read must retain the authenticated actor.' );
		s17_same( array( Addon_Capabilities::MANAGE_WORKSHEETS ), $request->required_capabilities(), 'Project read capability drifted.' );
		s17_assert( $request->request_integrity_verified(), 'Project read must verify the nonce.' );
		return Access_Policy_Decision::allowed( $request->correlation_id() );
	}
};
$project_permissions = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $project_policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$project_repository  = new class() implements Project_Repository_Port_Interface {
	/**
	 * Failure.
	 *
	 * @var Throwable
	 */
	public Throwable $failure;
	/**
	 * Listing.
	 *
	 * @param int  $actor Actor.
	 * @param int  $after After.
	 * @param bool $archived Archived.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function listing( int $actor, int $after = 0, bool $archived = false ): array {
		throw new LogicException( 'Unexpected listing' );
	}
	/**
	 * Archive mutation failure boundary.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param int    $expected Expected version.
	 * @param bool   $archived Archived.
	 * @return array
	 * @throws Throwable Injected boundary failure.
	 */
	public function set_archived( int $actor, string $id, int $expected, bool $archived ): array {
		throw $this->failure;
	}
	/**
	 * Read assessment-project-error-privacy.
	 *
	 * @param int         $actor Actor.
	 * @param string      $id Id.
	 * @param string|null $revision Revision.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $actor, string $id, ?string $revision = null ): array {
		throw $this->failure;
	}
	/**
	 * Create assessment-project-error-privacy.
	 *
	 * @param int    $actor Actor.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( int $actor, array $payload, string $key, string $digest ): array {
		throw new LogicException( 'Unexpected mutation' );
	}
	/**
	 * Save assessment-project-error-privacy.
	 *
	 * @param int    $actor Actor.
	 * @param string $id Id.
	 * @param string $expected Expected.
	 * @param array  $payload Input payload at this boundary; see the declared shape.
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function save( int $actor, string $id, string $expected, array $payload, string $key, string $digest ): array {
		throw new LogicException( 'Unexpected mutation' );
	}
	/**
	 * Attach created.
	 *
	 * @param int   $worksheet_pk Worksheet pk.
	 * @param int   $actor Actor.
	 * @param array $pin Pin.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function attach_created( int $worksheet_pk, int $actor, array $pin ): void {
		throw new LogicException( 'Unexpected mutation' );
	}
	/**
	 * For worksheet.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int|null   $actor Actor.
	 * @param bool       $workspace Include navigation-only migrated membership.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_worksheet( string $worksheet, ?array $pin = null, ?int $actor = null, bool $workspace = false ): ?array {
		throw new LogicException( 'Unexpected lookup' );
	}
	/**
	 * For publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $revision_id Revision id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_publication( string $publication_id, string $revision_id ): ?array {
		throw new LogicException( 'Unexpected lookup' );
	}
	/**
	 * Reject unexpected writes in the page fixture.
	 *
	 * @param int    $actor Owner.
	 * @param string $worksheet Worksheet ID.
	 * @return array
	 * @throws RuntimeException Unexpected write.
	 */
	public function adopt_worksheet( int $actor, string $worksheet ): array {
		throw new RuntimeException( 'Unexpected adoption in page fixture.' );
	}

	/**
	 * For draft save.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function for_draft_save( string $worksheet, ?array $pin, int $actor ): ?array {
		throw new LogicException( 'Unexpected lookup' );
	}
	/**
	 * Sync draft pin.
	 *
	 * @param string     $worksheet Worksheet.
	 * @param array|null $pin Pin.
	 * @param int        $actor Actor.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function sync_draft_pin( string $worksheet, ?array $pin, int $actor ): void {
		throw new LogicException( 'Unexpected mutation' );
	}
};

// Exercise the read boundary with real permission handling; mutation services must not run.
$reflection         = new ReflectionClass( Project_Rest_Controller::class );
$project_controller = $reflection->newInstanceWithoutConstructor();
foreach ( array(
	'permissions'  => $project_permissions,
	'projects'     => $project_repository,
	'envelopes'    => $envelopes,
	'correlations' => $correlations,
) as $name => $dependency ) {
	$reflection->getProperty( $name )->setValue( $project_controller, $dependency );
}
foreach ( array(
	array( new InvalidArgumentException( 'Authorization: Bearer private-fixture-token' ), 422, false ),
	array( new RuntimeException( '/home/private/fixture-secret.php' ), 503, false ),
	array( new Project_Input_Failure( 'Public input guidance fixture.' ), 422, true ),
) as [$failure, $status, $public] ) {
	$project_repository->failure = $failure;
	$project_request             = new S17_Request( 'GET', array( 'X-WP-Nonce' => 's17-valid-nonce' ), array(), array( 'project_id' => s17_id( 912 ) ) );
	s17_same( true, $project_permissions->check( $project_request, Access_Policy_Request::SHELL_CONSOLE, 'assessment_project.read', array( Addon_Capabilities::MANAGE_WORKSHEETS ) ), 'Project permission must be granted normally.' );
	$error = $project_controller->execute( $project_request, 'read' );
	s17_assert( $error instanceof WP_Error, 'Project failures must remain standard REST errors.' );
	s17_same( $status, $error->get_error_data()['status'], 'Project error status changed.' );
	s17_same( $public, $error->get_error_message() === $failure->getMessage(), 'Only designated public input guidance may be exposed.' );
	s17_assert( ! str_contains( $error->get_error_message(), 'private-fixture-token' ) && ! str_contains( $error->get_error_message(), '/home/private/' ), 'Internal exception content leaked.' );
	s17_assert( is_string( $error->get_error_data()['correlation_id'] ), 'Recovery correlation must remain available.' );
}
echo "Assessment project REST exception privacy passed.\n";

$reflection->getProperty( 'canonical' )->setValue( $project_controller, new \OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json() );
$project_repository->failure = new RuntimeException( '/home/private/archive-secret.php' );
foreach ( array(
	array( array( 'lock_version' => 0 ), array( 'archived' => true ), 503 ),
	array( array( 'lock_version' => 0 ), array( 'archived' => false ), 503 ),
	array( array( 'lock_version' => '0' ), array( 'archived' => true ), 422 ),
	array( array( 'lock_version' => 0 ), array( 'archived' => 'false' ), 422 ),
	array( array( 'lock_version' => 0 ), array( 'archived' => array() ), 422 ),
	array( array( 'revision_id' => s17_id( 912 ) ), array( 'archived' => true ), 422 ),
	array(
		array( 'lock_version' => 0 ),
		array(
			'archived'      => true,
			'owner_user_id' => 41,
		),
		422,
	),
) as [$expected, $payload, $status] ) {
	$archive_request = new S17_Request(
		'POST',
		array(
			'X-WP-Nonce'      => 's17-valid-nonce',
			'Idempotency-Key' => 'archive-test-0001',
		),
		array(
			'command_id' => 'archive-test-0001',
			'expected'   => $expected,
			'payload'    => $payload,
		),
		array( 'project_id' => s17_id( 912 ) )
	);
	s17_same( true, $project_permissions->check( $archive_request, Access_Policy_Request::SHELL_CONSOLE, 'assessment_project.archive', array( Addon_Capabilities::MANAGE_WORKSHEETS ) ), 'Archive requires normal mutation authorization.' );
	$error = $project_controller->execute( $archive_request, 'archive' );
	s17_assert( $error instanceof WP_Error, 'Expected archive boundary error.' );
	s17_same( $status, $error->get_error_data()['status'], 'Archive input shape boundary changed.' );
	s17_assert( ! str_contains( $error->get_error_message(), 'archive-secret' ), 'Archive leaked internal storage error.' );
}
$missing_nonce = new S17_Request( 'POST', array( 'Idempotency-Key' => 'archive-test-0002' ), array(), array( 'project_id' => s17_id( 912 ) ) );
s17_assert( true !== $project_permissions->check( $missing_nonce, Access_Policy_Request::SHELL_CONSOLE, 'assessment_project.archive', array( Addon_Capabilities::MANAGE_WORKSHEETS ) ), 'Archive accepted missing nonce.' );
$missing_key = new S17_Request( 'POST', array( 'X-WP-Nonce' => 's17-valid-nonce' ), array(), array( 'project_id' => s17_id( 912 ) ) );
s17_assert( true !== $project_permissions->check( $missing_key, Access_Policy_Request::SHELL_CONSOLE, 'assessment_project.archive', array( Addon_Capabilities::MANAGE_WORKSHEETS ) ), 'Archive accepted missing idempotency key.' );
echo "Project archive REST passed: authenticated actor, capability, nonce/key, strict boolean/version input, owner injection rejected, exception privacy.\n";

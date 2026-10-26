<?php
/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class WP_REST_Response {
	/**
	 * Initialize wp rest response dependencies and state.
	 *
	 * @param mixed $data Data.
	 * @param int   $status Status.
	 * @param array $headers Headers.
	 */
	public function __construct( private mixed $data = null, private int $status = 200, private array $headers = array() ) {
	}
	/**
	 * Get data.
	 *
	 * @return mixed
	 */
	public function get_data(): mixed {
		return $this->data;
	}
	/**
	 * Get status.
	 *
	 * @return int
	 */
	public function get_status(): int {
		return $this->status;
	}
	/**
	 * Set status.
	 *
	 * @param int $status Status.
	 * @return void
	 */
	public function set_status( int $status ): void {
		$this->status = $status;
	}
	/**
	 * Get headers.
	 *
	 * @return array
	 */
	public function get_headers(): array {
		return $this->headers;
	}
	/**
	 * Header.
	 *
	 * @param string $name Name.
	 * @param string $value Value.
	 * @return void
	 */
	public function header( string $name, string $value ): void {
		$this->headers[ $name ] = $value;
	}
}
require __DIR__ . '/student-result-navigation.php';
require __DIR__ . '/rest-foundation.php';

use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Application\Read_Student_Assignments;
use OJAI\Dashboard\Submission\Student\Application\Read_Own_Submission;
use OJAI\Dashboard\Submission\Student\Application\Read_Student_File_Content;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Assignment_Repository;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_File_Query;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;

/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class File_Contexts implements Learning_Context_Query_Port_Interface {
	/**
	 * Active.
	 *
	 * @var bool
	 */
	public bool $active = true;
	/**
	 * Initialize file contexts dependencies and state.
	 *
	 * @param array $items Items.
	 */
	public function __construct( public array $items ) {
	}
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array
	 */
	public function for_actor( int $actor_user_id ): array {
		return $this->active ? $this->items : array();
	}
}
// Q31 and authorization snapshots use production SQL. Q32 response bodies are controlled fixtures.
/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class File_Assignments implements Student_Assignment_Repository_Port_Interface {
	/**
	 * Submission.
	 *
	 * @var array
	 */
	public array $submission = array();
	/**
	 * Initialize file assignments dependencies and state.
	 *
	 * @param WordPress_Student_Assignment_Repository $sql Sql.
	 */
	public function __construct( public WordPress_Student_Assignment_Repository $sql ) {
	}
	/**
	 * List for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $contexts Contexts.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param string $now_utc Now utc.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_for_actor( int $actor_user_id, array $contexts, string $cursor, int $limit, string $now_utc ): array {
		throw new LogicException();
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		return $this->sql->authorization_snapshot( $assignment_id, $actor_user_id );
	}
	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		return $this->sql->submission_authorization_snapshot( $submission_id, $actor_user_id );
	}
	/**
	 * Find for actor.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $context Access or execution context for this operation.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 */
	public function find_for_actor( string $assignment_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		return $this->sql->find_for_actor( $assignment_id, $actor_user_id, $context, $now_utc );
	}
	/**
	 * Find submission for actor.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param array  $context Access or execution context for this operation.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 */
	public function find_submission_for_actor( string $submission_id, int $actor_user_id, array $context, string $now_utc ): ?array {
		return null === $this->submission_authorization_snapshot( $submission_id, $actor_user_id ) ? null : array( 'submission' => $this->submission );
	}
	/**
	 * Find start receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Start_Own_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_start_receipt( string $idempotency_storage_key, string $request_digest ): ?Start_Own_Submission_Result {
		throw new LogicException();
	}
	/**
	 * Start.
	 *
	 * @param array $record Record.
	 * @return Start_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start( array $record ): Start_Own_Submission_Result {
		throw new LogicException();
	}
	/**
	 * Find autosave receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Autosave_Own_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_autosave_receipt( string $idempotency_storage_key, string $request_digest ): ?Autosave_Own_Submission_Result {
		throw new LogicException();
	}
	/**
	 * Autosave.
	 *
	 * @param array $record Record.
	 * @return Autosave_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function autosave( array $record ): Autosave_Own_Submission_Result {
		throw new LogicException();
	}
}
/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class File_Policy implements Access_Policy_Port_Interface {
	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/**
	 * Requests.
	 *
	 * @var array
	 */
	public array $requests = array();
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->requests[] = $request;
		return $this->allowed && 7 === $request->actor_user_id()
			? Access_Policy_Decision::allowed( $request->correlation_id() )
			: Access_Policy_Decision::denied( 403, 'forbidden', $request->correlation_id() );
	}
}
/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class File_Request {
	/**
	 * Method.
	 *
	 * @var string
	 */
	public string $method = 'GET';
	/**
	 * Nonce.
	 *
	 * @var string
	 */
	public string $nonce = 'valid-rest-nonce';
	/**
	 * Origin.
	 *
	 * @var string|null
	 */
	public ?string $origin = null;
	/**
	 * Fetch site.
	 *
	 * @var string|null
	 */
	public ?string $fetch_site = null;
	/**
	 * Query.
	 *
	 * @var array
	 */
	public array $query = array();
	/**
	 * Initialize file request dependencies and state.
	 *
	 * @param array $path Path.
	 */
	public function __construct( public array $path ) {
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	public function get_header( string $name ): ?string {
		return match ( $name ) {
			'X-WP-Nonce' => $this->nonce, 'Origin' => $this->origin, 'Sec-Fetch-Site' => $this->fetch_site, default => null };
	}
	/**
	 * Get query params.
	 *
	 * @return array
	 */
	public function get_query_params(): array {
		return $this->query;
	}
	/**
	 * Get url params.
	 *
	 * @return array
	 */
	public function get_url_params(): array {
		return $this->path;
	}
	/**
	 * Get param.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_param( string $name ): mixed {
		return 999;
	}
}
/**
 * Approved self-file routes: production SQL, projection authorization and pre-stream revocation.
 */
final class File_Server {
	/**
	 * Headers.
	 *
	 * @var array
	 */
	public array $headers = array();
	/**
	 * Send header.
	 *
	 * @param string $name Name.
	 * @param string $value Value.
	 * @return void
	 */
	public function send_header( string $name, string $value ): void {
		$this->headers[ $name ] = $value;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string $namespace Namespace.
	 * @param string $route Route.
	 * @param array  $args Args.
	 * @return void
	 */
	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['file_routes'][ $namespace . $route ] = $args;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Add filter.
	 *
	 * @param mixed ...$args Args.
	 * @return void
	 */
	function add_filter( mixed ...$args ): void {
	}

}
if ( ! function_exists( 'status_header' ) ) {
	/**
	 * Status header.
	 *
	 * @param int $status Status.
	 * @return void
	 */
	function status_header( int $status ): void {
		$GLOBALS['file_status'] = $status;
	}

}
if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Rest url.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://school.test/wp-json/' . ltrim( $path, '/' );
	}

}

$checks                               = 0;
$assert                               = static function ( bool $value, string $message ) use ( &$checks ): void {
	++$checks;
	g3_assert( $value, $message );
};
$denied                               = static function ( callable $action, string $message ) use ( $assert ): void {
	$failed = false;
	try {
		$action();
	} catch ( Throwable ) {
		$failed = true;
	}
	$assert( $failed, $message );
};
$db                                   = new Student_Result_Navigation_Database();
$id                                   = static fn ( int $number ): string => str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
$asset_id                             = $id( 501 );
$attachment_id                        = $id( 502 );
$file_id                              = $id( 503 );
$response_id                          = $id( 504 );
$worksheet_id                         = $id( 505 );
$publication['worksheet_id']          = 505;
$publication['id']                    = 506;
$document                             = g3s_profile(
	$canonical,
	'oj_ai.student_document',
	array(
		'nodes' => array(
			array(
				'type'     => 'file',
				'asset_id' => $asset_id,
				'label'    => 'Material',
			),
		),
	),
	$publication_hash
);
$schema                               = g3s_profile(
	$canonical,
	'oj_ai.student_response_schema',
	array(
		'items' => array(
			array(
				'response_id' => $response_id,
				'kind'        => 'file_upload',
			),
		),
	),
	$publication_hash
);
$publication['student_document_json'] = $document['json'];
$publication['student_document_hash'] = $document['hash'];
$publication['response_schema_json']  = $schema['json'];
$publication['response_schema_hash']  = $schema['hash'];
$db->insert( 'oj_ai_assignments', $assignment );
foreach ( $targets as $target ) {
	$db->insert( 'oj_ai_assignment_targets', $target );
}
$db->insert( 'oj_ai_worksheet_publications', $publication );
$db->insert(
	'oj_ai_worksheets',
	array(
		'id'        => 505,
		'public_id' => $worksheet_id,
	)
);
$submission_root = $student_database->tables['oj_ai_submissions'][0];
$submission_id   = $submission_root['public_id'];
$db->insert( 'oj_ai_submissions', $submission_root );
$contexts    = new File_Contexts( $learning_contexts );
$assignments = new File_Assignments( new WordPress_Student_Assignment_Repository( $db, $canonical ) );
$now         = static fn (): string => '2026-08-22 10:00:00';
$bytes       = "Authorized student content\n";
$hash        = hash( 'sha256', $bytes );
$temporary   = sys_get_temp_dir() . '/ojai-file-security-' . bin2hex( random_bytes( 8 ) );
mkdir( $temporary, 0700 );
$source = $temporary . '/fixture.txt';
file_put_contents( $source, $bytes );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$store                    = new Local_Private_File_Store( $temporary . '/private' );
$store->put_quarantined( $source, 'files/material.txt', $hash );
$store->put_quarantined( $source, 'files/answer.txt', $hash );
$asset       = array(
	'id'                 => 501,
	'public_id'          => $asset_id,
	'created_by_user_id' => 19,
	'owner_type'         => 'worksheet',
	'owner_public_id'    => $worksheet_id,
	'purpose'            => 'worksheet_asset',
	'storage_key'        => 'files/material.txt',
	'original_name'      => 'material.txt',
	'mime_type'          => 'text/plain',
	'byte_size'          => strlen( $bytes ),
	'sha256'             => $hash,
	'state'              => 'ready',
	'scan_state'         => 'clean',
	'expires_at_utc'     => null,
	'purged_at_utc'      => null,
);
$answer_file = array_replace(
	$asset,
	array(
		'id'                 => 503,
		'public_id'          => $file_id,
		'created_by_user_id' => 7,
		'owner_type'         => 'submission',
		'owner_public_id'    => $submission_id,
		'purpose'            => 'submission_attachment',
		'storage_key'        => 'files/answer.txt',
		'original_name'      => 'answer.txt',
	)
);
$db->insert( 'oj_ai_private_files', $asset );
$db->insert( 'oj_ai_private_files', $answer_file );
$attachment = array(
	'id'                     => 502,
	'public_id'              => $attachment_id,
	'submission_id'          => $submission_root['id'],
	'submission_revision_id' => null,
	'response_key'           => $response_id,
	'file_public_id'         => $file_id,
	'state'                  => 'ready',
	'created_by_user_id'     => 7,
	'original_name'          => 'answer.txt',
	'mime_type'              => 'text/plain',
	'byte_size'              => strlen( $bytes ),
	'sha256'                 => $hash,
);
$db->insert( 'oj_ai_submission_attachments', $attachment );
$listed                  = array(
	'id'             => $attachment_id,
	'response_id'    => $response_id,
	'name'           => 'answer.txt',
	'mime_type'      => 'text/plain',
	'byte_size'      => strlen( $bytes ),
	'state'          => 'ready',
	'revision_scope' => array( 'staged' ),
);
$assignments->submission = array(
	'id'                => $submission_id,
	'assignment_id'     => $assignment_id,
	'attachments'       => array( $listed ),
	'current_revision'  => null,
	'official_revision' => null,
);
$query                   = new WordPress_Student_File_Query( $db );
$reader                  = new Read_Student_File_Content( new Read_Student_Assignments( $assignments, $contexts, $now ), new Read_Own_Submission( $assignments, $contexts, $now ), $query, $store, $now );
$open_asset              = static fn () => $reader->open( 7, 'asset', $assignment_id, $asset_id );
$open_attachment         = static fn () => $reader->open( 7, 'attachment', $submission_id, $attachment_id );
$read_bytes              = static function ( $content ): string {
	ob_start();
	$content->emit();
	return ob_get_clean();
};
try {
	$assert( $bytes === $read_bytes( $open_asset() ), 'Assigned publication asset must download.' );
	$assert( $bytes === $read_bytes( $open_attachment() ), 'Own clean staged attachment must download.' );
	foreach ( array( 0, 8, 19 ) as $foreign ) {
		$denied( static fn () => $reader->open( $foreign, 'asset', $assignment_id, $asset_id ), 'Foreign actor must not read asset.' );
		$denied( static fn () => $reader->open( $foreign, 'attachment', $submission_id, $attachment_id ), 'Foreign actor must not read answer.' );
	}
	$denied( static fn () => $reader->open( 7, 'asset', $assignment_id, $file_id ), 'Same hash is not permission.' );
	$denied( static fn () => $reader->open( 7, 'asset', $assignment_id, '../secret' ), 'Path traversal must fail.' );
	$denied( static fn () => $reader->open( 7, 'attachment', $submission_id, $asset_id ), 'Different object type must fail.' );
	foreach ( array( array( 'state', 'quarantined' ), array( 'scan_state', 'pending' ), array( 'scan_state', 'infected' ), array( 'purged_at_utc', '2026-08-22 09:00:00' ), array( 'expires_at_utc', '2026-08-22 09:59:59' ), array( 'expires_at_utc', '2026-08-22 10:00:00' ), array( 'expires_at_utc', 'invalid' ), array( 'owner_public_id', $id( 999 ) ), array( 'owner_type', 'submission' ), array( 'purpose', 'worksheet_pdf' ), array( 'sha256', hash( 'sha256', 'wrong' ) ), array( 'byte_size', 1 ), array( 'byte_size', 999999999 ), array( 'original_name', "bad\r\nX-Leak: yes" ), array( 'original_name', '../answer.txt' ) ) as [ $column, $bad ] ) {
		$db->change( 'oj_ai_private_files', $column, $bad, 501 );
		$denied( $open_asset, 'Unsafe asset metadata: ' . $column );
		$db->change( 'oj_ai_private_files', $column, $asset[ $column ], 501 );
	}
	foreach ( array( array( 'state', 'quarantined' ), array( 'scan_state', 'pending' ), array( 'purged_at_utc', '2026-08-22 09:00:00' ), array( 'expires_at_utc', '2026-08-22 10:00:00' ), array( 'owner_public_id', $id( 999 ) ), array( 'purpose', 'report_export' ), array( 'sha256', hash( 'sha256', 'wrong' ) ), array( 'mime_type', 'text/html' ), array( 'byte_size', 1 ), array( 'created_by_user_id', 8 ) ) as [ $column, $bad ] ) {
		$db->change( 'oj_ai_private_files', $column, $bad, 503 );
		$denied( $open_attachment, 'Unsafe attachment metadata: ' . $column );
		$db->change( 'oj_ai_private_files', $column, $answer_file[ $column ], 503 );
	}
	$db->change( 'oj_ai_submission_attachments', 'submission_id', 999, 502 );
	$denied( $open_attachment, 'Other submission attachment.' );
	$db->change( 'oj_ai_submission_attachments', 'submission_id', $submission_root['id'], 502 );
	$db->query( "UPDATE oj_ai_assignment_targets SET state = 'revoked' WHERE learner_user_id = 7" );
	$denied( $open_asset, 'Revoked target asset.' );
	$denied( $open_attachment, 'Revoked target answer.' );
	$db->query( "UPDATE oj_ai_assignment_targets SET state = 'active' WHERE learner_user_id = 7" );
	$db->change( 'oj_ai_assignments', 'state', 'withdrawn', $assignment['id'] );
	$denied( $open_asset, 'Withdrawn assignment asset.' );
	$denied( $open_attachment, 'Withdrawn assignment answer.' );
	$db->change( 'oj_ai_assignments', 'state', 'open', $assignment['id'] );
	$contexts->active = false;
	$denied( $open_asset, 'Inactive platform membership asset.' );
	$denied( $open_attachment, 'Inactive platform membership answer.' );
	$contexts->active = true;
	$db->change( 'oj_ai_assignments', 'opens_at_utc', '2026-08-23 00:00:00', $assignment['id'] );
	$denied( $open_asset, 'Not-yet-open publication.' );
	$db->change( 'oj_ai_assignments', 'opens_at_utc', $assignment['opens_at_utc'], $assignment['id'] );
	foreach ( array(
		array(
			'type'     => 'p',
			'asset_id' => $asset_id,
		),
		array(
			'type' => 'p',
			'text' => $asset_id,
		),
		array(
			'type'     => 'file',
			'asset_id' => $id( 999 ),
		),
	) as $node ) {
		$hidden = g3s_profile( $canonical, 'oj_ai.student_document', array( 'nodes' => array( $node ) ), $publication_hash );
		$db->change( 'oj_ai_worksheet_publications', 'student_document_json', $hidden['json'], $publication['id'] );
		$db->change( 'oj_ai_worksheet_publications', 'student_document_hash', $hidden['hash'], $publication['id'] );
		$denied( $open_asset, 'Teacher-only or untyped asset reference must not authorize.' );
	}
	$db->change( 'oj_ai_worksheet_publications', 'student_document_json', $document['json'], $publication['id'] );
	$db->change( 'oj_ai_worksheet_publications', 'student_document_hash', $document['hash'], $publication['id'] );
	$assignments->submission['attachments'] = array();
	$denied( $open_attachment, 'Historical unlisted attachment.' );
	$bound                                  = array_replace(
		$listed,
		array(
			'state'          => 'bound',
			'revision_scope' => array( 'current' ),
		)
	);
	$assignments->submission['attachments'] = array( $bound );
	$db->change( 'oj_ai_submission_attachments', 'state', 'bound', 502 );
	$db->change( 'oj_ai_submission_attachments', 'submission_revision_id', 601, 502 );
	$assignments->submission['current_revision'] = array( 'response' => array( 'answers' => array( $response_id => array( $attachment_id ) ) ) );
	$assert( $bytes === $read_bytes( $open_attachment() ), 'Current response-bound attachment.' );
	$db->change( 'oj_ai_private_files', 'created_by_user_id', 19, 503 );
	$db->change( 'oj_ai_submission_attachments', 'created_by_user_id', 19, 502 );
	$assert( $bytes === $read_bytes( $open_attachment() ), 'Teacher proxy upload bound to own answer remains readable.' );
	$db->change( 'oj_ai_private_files', 'created_by_user_id', 7, 503 );
	$db->change( 'oj_ai_submission_attachments', 'created_by_user_id', 7, 502 );
	$assignments->submission['official_revision']                = $assignments->submission['current_revision'];
	$assignments->submission['current_revision']                 = null;
	$assignments->submission['attachments'][0]['revision_scope'] = array( 'official' );
	$assert( $bytes === $read_bytes( $open_attachment() ), 'Own official answer attachment remains readable.' );
	$assignments->submission['current_revision']                        = $assignments->submission['official_revision'];
	$assignments->submission['official_revision']                       = null;
	$assignments->submission['attachments'][0]['revision_scope']        = array( 'current' );
	$assignments->submission['current_revision']['response']['answers'] = array( $id( 999 ) => array( $attachment_id ) );
	$denied( $open_attachment, 'Attachment in another response must fail.' );
	$assignments->submission['current_revision']['response']['answers'] = array( $response_id => $attachment_id );
	$denied( $open_attachment, 'Text containing an ID is not an attachment binding.' );
	$assignments->submission['attachments']      = array( $listed );
	$assignments->submission['current_revision'] = null;
	$db->change( 'oj_ai_submission_attachments', 'state', 'ready', 502 );
	$db->change( 'oj_ai_submission_attachments', 'submission_revision_id', null, 502 );
	$assignments->submission['attachments'][0]['response_id'] = $id( 999 );
	$db->change( 'oj_ai_submission_attachments', 'response_key', $id( 999 ), 502 );
	$denied( $open_attachment, 'A staged attachment cannot reference a nonexistent response.' );
	$assignments->submission['attachments'][0]['response_id'] = $response_id;
	$db->change( 'oj_ai_submission_attachments', 'response_key', $response_id, 502 );
	$content = $open_asset();
	$path    = $store->readable_path( 'files/material.txt', $hash );
	file_put_contents( $path, 'replacement secret' );
	$assert( $bytes === $read_bytes( $content ), 'Pending response must use validated snapshot, not a reopened path.' );
	$denied( $open_asset, 'Modified backing file must fail checksum.' );
	file_put_contents( $path, $bytes );
	$denied( static fn () => $store->readable_path( '../fixture.txt', $hash ), 'Private-store traversal.' );

	$policy       = new File_Policy();
	$actor        = new WordPress_Current_Actor();
	$nonces       = new WordPress_Rest_Nonce_Verifier();
	$correlations = new Correlation_Id_Factory();
	$envelopes    = new Rest_Envelope_Factory();
	$permissions  = new Rest_Permission_Handoff( new WordPress_Rest_Request_Adapter( $actor ), new Rest_Request_Authorizer( $policy, $nonces, $correlations ), $envelopes, $correlations );
	$reflection   = new ReflectionClass( Student_Assignment_Rest_Controller::class );
	$controller   = $reflection->newInstanceWithoutConstructor();
	foreach ( array(
		'permissions'   => $permissions,
		'current_actor' => $actor,
		'access'        => $assignments,
		'read_files'    => $reader,
		'file_nonces'   => $nonces,
		'envelopes'     => $envelopes,
		'correlations'  => $correlations,
	) as $property => $value ) {
		$reflection->getProperty( $property )->setValue( $controller, $value );
	}
	$controller->register_routes();
	$GLOBALS['oj_ai_rest_actor'] = 7;
	$request                     = static fn () => new File_Request(
		array(
			'assignment_id' => $assignment_id,
			'asset_id'      => $asset_id,
		)
	);
	$prepare                     = static function ( File_Request $request ) use ( $controller, $assert ) {
		$assert( true === $controller->permissions_asset_content( $request ), 'Owned route permission.' );
		$response = $controller->asset_content( $request );
		$assert( $response instanceof WP_REST_Response, 'Owned route callback.' );
		return $response;
	};
	$assert( $controller->asset_content( $request() ) instanceof WP_Error, 'Callback cannot bypass permission handoff.' );
	foreach ( array( '', 'invalid' ) as $nonce ) {
		$r        = $request();
		$r->nonce = $nonce;
		$assert( true !== $controller->permissions_asset_content( $r ), 'GET must require header nonce.' );
	}
	foreach ( array( 'https://other.test', 'null', 'http://school.test', 'https://school.test:8443' ) as $origin ) {
		$r         = $request();
		$r->origin = $origin;
		$assert( true !== $controller->permissions_asset_content( $r ), 'Foreign Origin must be denied even with nonce.' );
	}
	$r         = $request();
	$r->origin = 'https://school.test';
	$assert( true === $controller->permissions_asset_content( $r ), 'Same-origin browser request.' );
	$r             = $request();
	$r->fetch_site = 'cross-site';
	$assert( true !== $controller->permissions_asset_content( $r ), 'Cross-site browser request without Origin.' );
	foreach ( array( 'HEAD', 'POST' ) as $method ) {
		$r         = $request();
		$r->method = $method;
		$assert( true !== $controller->permissions_asset_content( $r ), 'Unsupported file method.' );
	}
	$r        = $request();
	$r->query = array( '_wpnonce' => 'valid-rest-nonce' );
	$assert( true !== $controller->permissions_asset_content( $r ), 'No URL nonce or query overrides.' );
	$r        = $request();
	$response = $prepare( $r );
	$server   = new File_Server();
	ob_start();
	$controller->serve_file( false, $response, $r, $server );
	$output = ob_get_clean();
	$assert( $output === $bytes, 'REST binary content.' );
	$assert( null === $response->get_data(), 'No private metadata in REST JSON.' );
	$last_policy = $policy->requests[ count( $policy->requests ) - 1 ];
	$assert( array( Addon_Capabilities::PARTICIPATE ) === $last_policy->required_capabilities() && Access_Policy_Request::SHELL_PUBLIC === $last_policy->shell(), 'Use student participant policy, not teacher capability.' );
	$assert( null !== $last_policy->context() && null !== $last_policy->object(), 'Context and object authorization must both be requested.' );
	$assert( 'application/octet-stream' === $server->headers['Content-Type'] && str_starts_with( $server->headers['Content-Disposition'], 'attachment;' ), 'Never inline active content.' );
	$assert( str_contains( $server->headers['Cache-Control'], 'no-store' ) && 'nosniff' === $server->headers['X-Content-Type-Options'], 'No caching or MIME sniffing.' );
	$assert( ! str_contains( json_encode( $server->headers ), $hash ) && ! str_contains( json_encode( $server->headers ), 'files/material' ), 'No hash or storage key in headers.' );
	ob_start();
	$controller->serve_file( false, $response, $r, $server );
	$assert( '' === ob_get_clean(), 'One-use stream.' );
	foreach ( array( 'logout', 'capability', 'membership', 'target', 'purge', 'nonce', 'foreign_request', 'changed_path' ) as $case ) {
		$r               = $request();
		$response        = $prepare( $r );
		$serving_request = $r;
		if ( 'logout' === $case ) {
			$GLOBALS['oj_ai_rest_actor'] = 0;
		}
		if ( 'capability' === $case ) {
			$policy->allowed = false;
		}
		if ( 'membership' === $case ) {
			$contexts->active = false;
		}
		if ( 'target' === $case ) {
			$db->query( "UPDATE oj_ai_assignment_targets SET state = 'revoked' WHERE learner_user_id = 7" );
		}
		if ( 'purge' === $case ) {
			$db->change( 'oj_ai_private_files', 'state', 'purged', 501 );
		}
		if ( 'nonce' === $case ) {
			$r->nonce = 'expired';
		}
		if ( 'foreign_request' === $case ) {
			$serving_request = $request();
		}
		if ( 'changed_path' === $case ) {
			$r->path['asset_id'] = $file_id;
		}
		$server = new File_Server();
		ob_start();
		$controller->serve_file( false, $response, $serving_request, $server );
		$output = ob_get_clean();
		$assert( '' === $output && 404 === $response->get_status() && '0' === $server->headers['Content-Length'], 'Pre-stream revocation must send zero bytes: ' . $case );
		$GLOBALS['oj_ai_rest_actor'] = 7;
		$policy->allowed             = true;
		$contexts->active            = true;
		$db->query( "UPDATE oj_ai_assignment_targets SET state = 'active' WHERE learner_user_id = 7" );
		$db->change( 'oj_ai_private_files', 'state', 'ready', 501 );
	}
	$r = new File_Request(
		array(
			'submission_id' => $submission_id,
			'attachment_id' => $attachment_id,
		)
	);
	$assert( true === $controller->permissions_attachment_content( $r ), 'Attachment route permission.' );
	$response = $controller->attachment_content( $r );
	$assert( $response instanceof WP_REST_Response, 'Attachment route callback.' );
	ob_start();
	$controller->serve_file( false, $response, $r, new File_Server() );
	$assert( $bytes === ob_get_clean(), 'Attachment route bytes.' );
	$assert( true === $controller->permissions_attachment_content( $r ), 'Attachment permission before revocation.' );
	$response = $controller->attachment_content( $r );
	$db->change( 'oj_ai_submission_attachments', 'state', 'rejected', 502 );
	$server = new File_Server();
	ob_start();
	$controller->serve_file( false, $response, $r, $server );
	$assert( '' === ob_get_clean() && 404 === $response->get_status(), 'Attachment rejected between authorization and stream sends no bytes.' );
	$db->change( 'oj_ai_submission_attachments', 'state', 'ready', 502 );
	$r = $request();
	$assert( true === $controller->permissions_asset_content( $r ), 'Valid handoff before nonce change.' );
	$r->nonce = 'invalid';
	$assert( $controller->asset_content( $r ) instanceof WP_Error, 'Callback rechecks request changed after permission.' );
	$r        = $request();
	$response = $prepare( $r );
	ob_start();
	$controller->serve_file( true, $response, $r, new File_Server() );
	$assert( '' === ob_get_clean(), 'An already-served response cannot append file bytes.' );
	echo 'Student file exposure checks passed: ' . (int) $checks . ". SQL ownership, student projection, binding, checksum, nonce, no-store, download-only, and pre-stream revocation.\n";
} finally {
	// No recursive cleanup: remove only the three files created by this test.
	foreach ( array( '/fixture.txt', '/private/files/material.txt', '/private/files/answer.txt' ) as $name ) {
		if ( is_file( $temporary . $name ) ) {
			unlink( $temporary . $name );
		}
	}
	rmdir( $temporary . '/private/files' );
	rmdir( $temporary . '/private' );
	rmdir( $temporary );
}

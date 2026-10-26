<?php
/**
 * Staff attachment SQL, official binding and first-byte authorization regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
require __DIR__ . '/student-file-exposure.php';

use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Submissions;
use OJAI\Dashboard\Submission\Teacher\Application\Read_Teacher_Attachment;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Attachment_Query;
use OJAI\Dashboard\Submission\Teacher\Presentation\Teacher_Submission_Rest_Controller;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;

/**
 * Staff attachment SQL, official binding and first-byte authorization regression.
 */
final class Teacher_File_Submissions implements Teacher_Submission_Query_Port_Interface {
	/**
	 * Visible.
	 *
	 * @var bool
	 */
	public bool $visible = true;
	/**
	 * Initialize teacher file submissions dependencies and state.
	 *
	 * @param array $submission Submission.
	 */
	public function __construct( public array $submission ) {
	}
	/**
	 * Find submission.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 */
	public function find_submission( string $submission_id, string $context_type, string $context_id, string $context_revision ): ?array {
		return $this->visible && $submission_id === $this->submission['id'] && 'class' === $context_type && 'class:3' === $context_id && 'revision-1' === $context_revision
			? array( 'submission' => $this->submission ) : null;
	}
	/**
	 * List assignment submissions.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_assignment_submissions( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		throw new LogicException();
	}
	/**
	 * List submission history.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_submission_history( string $submission_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array {
		throw new LogicException();
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $submission_id ): ?array {
		throw new LogicException();
	}
}

$checks    = 0;
$temporary = sys_get_temp_dir() . '/ojai-teacher-file-' . bin2hex( random_bytes( 8 ) );
mkdir( $temporary, 0700 );
$source = $temporary . '/fixture.txt';
file_put_contents( $source, $bytes );
$store = new Local_Private_File_Store( $temporary . '/private' );
$store->put_quarantined( $source, 'files/answer.txt', $hash );
$db->change( 'oj_ai_submission_attachments', 'state', 'bound', 502 );
$db->change( 'oj_ai_submission_attachments', 'submission_revision_id', 42, 502 );
$listed           = array(
	'id'             => $attachment_id,
	'response_id'    => $response_id,
	'name'           => 'answer.txt',
	'mime_type'      => 'text/plain',
	'byte_size'      => strlen( $bytes ),
	'sha256'         => $hash,
	'state'          => 'bound',
	'revision_scope' => array( 'official' ),
);
$projection       = new Teacher_File_Submissions(
	array(
		'id'                => $submission_id,
		'attachments'       => array( $listed ),
		'official_revision' => array( 'response' => array( 'answers' => array( $response_id => array( $attachment_id ) ) ) ),
	)
);
$read_submissions = new Read_Teacher_Submissions( $projection );
$reader           = new Read_Teacher_Attachment( $read_submissions, new WordPress_Teacher_Attachment_Query( $db ), $store, $now );
$args             = array( $submission_id, $attachment_id, 'class', 'class:3', 'revision-1' );
$open             = static fn () => $reader->open( ...$args );
try {
	$assert( $bytes === $read_bytes( $open() ), 'Official staff attachment bytes.' );
	foreach ( array(
		'owner_public_id'    => $id( 998 ),
		'purpose'            => 'worksheet_asset',
		'owner_type'         => 'worksheet',
		'scan_state'         => 'infected',
		'state'              => 'quarantined',
		'sha256'             => str_repeat( 'b', 64 ),
		'byte_size'          => 1,
		'original_name'      => 'foreign.txt',
		'mime_type'          => 'text/html',
		'created_by_user_id' => 99,
		'expires_at_utc'     => '2026-08-01 00:00:00',
		'purged_at_utc'      => '2026-08-01 00:00:00',
	) as $field => $value ) {
		$db->change( 'oj_ai_private_files', $field, $value, 503 );
		$denied( $open, 'Reject changed private file: ' . $field );
		$db->change( 'oj_ai_private_files', $field, $answer_file[ $field ], 503 );
	}
	foreach ( array( 'staged', 'scanning', 'ready', 'rejected' ) as $state ) {
		$db->change( 'oj_ai_submission_attachments', 'state', $state, 502 );
		$denied( $open, 'No unbound/unsafe attachment: ' . $state );
	}
	$db->change( 'oj_ai_submission_attachments', 'state', 'bound', 502 );
	$projection->submission['attachments'][0]['revision_scope'] = array( 'current' );
	$denied( $open, 'Draft-only file cannot be exposed as official.' );
	$projection->submission['attachments'][0]    = $listed;
	$official                                    = $projection->submission['official_revision'];
	$projection->submission['official_revision'] = null;
	$denied( $open, 'No official submission.' );
	$projection->submission['official_revision'] = array( 'response' => array( 'answers' => array( $id( 999 ) => array( $attachment_id ) ) ) );
	$denied( $open, 'Attachment must belong to the same response.' );
	$projection->submission['official_revision'] = $official;
	$denied( static fn () => $reader->open( $submission_id, $id( 999 ), 'class', 'class:3', 'revision-1' ), 'Foreign attachment ID.' );
	$denied( static fn () => $reader->open( $submission_id, $attachment_id, 'class', 'class:1', 'revision-1' ), 'Foreign context.' );
	$denied( static fn () => $reader->open( $submission_id, $attachment_id, 'class', 'class:3', 'old-revision' ), 'Stale context.' );
	$reflection = new ReflectionClass( Teacher_Submission_Rest_Controller::class );
	$controller = $reflection->newInstanceWithoutConstructor();
	foreach ( array(
		'permissions'   => $permissions,
		'submissions'   => $read_submissions,
		'current_actor' => $actor,
		'read_files'    => $reader,
		'file_nonces'   => $nonces,
		'envelopes'     => $envelopes,
		'correlations'  => $correlations,
	) as $property => $value ) {
		$reflection->getProperty( $property )->setValue( $controller, $value );
	}
	$controller->register_routes();
	$GLOBALS['oj_ai_rest_actor'] = 7;
	$policy->allowed             = true;
	$request                     = static function () use ( $submission_id, $attachment_id ) {
		$r        = new File_Request(
			array(
				'submission_id' => $submission_id,
				'attachment_id' => $attachment_id,
			)
		);
		$r->query = array(
			'context_type'     => 'class',
			'context_id'       => 'class:3',
			'context_revision' => 'revision-1',
		);
		return $r;
	};
	$prepare                     = static function ( $r ) use ( $controller, $assert ) {
		$assert( true === $controller->permissions_attachment_content( $r ), 'Staff permission granted.' );
		$response = $controller->attachment_content( $r );
		$assert( $response instanceof WP_REST_Response, 'Staff file callback.' );
		return $response;
	};
	$assert( $controller->attachment_content( $request() ) instanceof WP_Error, 'No callback bypass.' );
	foreach ( array( 0, 8 ) as $other_actor ) {
		$GLOBALS['oj_ai_rest_actor'] = $other_actor;
		$assert( true !== $controller->permissions_attachment_content( $request() ), 'Non-staff actor denied.' );
	}
	$GLOBALS['oj_ai_rest_actor'] = 7;
	foreach ( array( '', 'expired' ) as $nonce ) {
		$r        = $request();
		$r->nonce = $nonce;
		$assert( true !== $controller->permissions_attachment_content( $r ), 'Header nonce required.' );
	}
	foreach ( array( 'https://foreign.test', 'null', 'http://school.test' ) as $origin ) {
		$r         = $request();
		$r->origin = $origin;
		$assert( true !== $controller->permissions_attachment_content( $r ), 'Foreign origin denied.' );
	}
	$r             = $request();
	$r->fetch_site = 'cross-site';
	$assert( true !== $controller->permissions_attachment_content( $r ), 'Cross-site denied.' );
	$r                         = $request();
	$r->query['actor_user_id'] = 7;
	$assert( true !== $controller->permissions_attachment_content( $r ), 'No actor/query override.' );
	$r        = $request();
	$response = $prepare( $r );
	$server   = new File_Server();
	ob_start();
	$controller->serve_attachment( false, $response, $r, $server );
	$assert( $bytes === ob_get_clean(), 'Staff binary response.' );
	$last = $policy->requests[ count( $policy->requests ) - 1 ];
	$assert( Access_Policy_Request::SHELL_CONSOLE === $last->shell() && array( Addon_Capabilities::MANAGE_SUBMISSIONS ) === $last->required_capabilities(), 'Staff capability, not participant impersonation.' );
	$assert( null !== $last->context() && null !== $last->object(), 'Context and submission object authorization.' );
	$assert( null === $response->get_data() && 'application/octet-stream' === $server->headers['Content-Type'] && str_contains( $server->headers['Cache-Control'], 'no-store' ), 'No public file URL, inline active content or caching.' );
	foreach ( array( 'logout', 'policy', 'context', 'nonce', 'path', 'request', 'scan', 'binding' ) as $case ) {
		$r        = $request();
		$response = $prepare( $r );
		$serving  = $r;
		if ( 'logout' === $case ) {
			$GLOBALS['oj_ai_rest_actor'] = 0;
		}
		if ( 'policy' === $case ) {
			$policy->allowed = false;
		}
		if ( 'context' === $case ) {
			$projection->visible = false;
		}
		if ( 'nonce' === $case ) {
			$r->nonce = 'expired';
		}
		if ( 'path' === $case ) {
			$r->path['attachment_id'] = $id( 999 );
		}
		if ( 'request' === $case ) {
			$serving = $request();
		}
		if ( 'scan' === $case ) {
			$db->change( 'oj_ai_private_files', 'scan_state', 'infected', 503 );
		}
		if ( 'binding' === $case ) {
			$projection->submission['official_revision'] = null;
		}
		$server = new File_Server();
		ob_start();
		$controller->serve_attachment( false, $response, $serving, $server );
		$assert( '' === ob_get_clean() && 404 === $response->get_status() && '0' === $server->headers['Content-Length'], 'Zero bytes after revocation: ' . $case );
		$GLOBALS['oj_ai_rest_actor'] = 7;
		$policy->allowed             = true;
		$projection->visible         = true;
		$db->change( 'oj_ai_private_files', 'scan_state', 'clean', 503 );
		$projection->submission['official_revision'] = $official;
	}
	$context = array(
		'type'     => 'class',
		'id'       => 'class:3',
		'revision' => 'revision-1',
	);
	$denied( static fn () => $reader->image_data_uri( $submission_id, $listed, $context ), 'Non-image cannot become an inline PDF source.' );
	$assert( function_exists( 'imagecreatetruecolor' ), 'GD is required for production PDF image rendering.' );
	$canvas = imagecreatetruecolor( 640, 240 );
	imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
	$ink = imagecolorallocate( $canvas, 8, 100, 90 );
	imagestring( $canvas, 5, 30, 28, 'Official drawing answer', $ink );
	imagestring( $canvas, 5, 30, 70, '5 - 2 = 3', $ink );
	imagerectangle( $canvas, 20, 16, 620, 220, $ink );
	ob_start();
	imagepng( $canvas );
	$png = (string) ob_get_clean();
	unset( $canvas );
	file_put_contents( $store->readable_path( 'files/answer.txt', $hash ), $png );
	$image_hash = hash( 'sha256', $png );
	foreach ( array(
		'oj_ai_private_files'          => 503,
		'oj_ai_submission_attachments' => 502,
	) as $table => $row_id ) {
		foreach ( array(
			'original_name' => 'drawing.png',
			'mime_type'     => 'image/png',
			'sha256'        => $image_hash,
			'byte_size'     => strlen( $png ),
		) as $field => $value ) {
			$db->change( $table, $field, $value, $row_id );
		}
	}
	$listed                                   = array_replace(
		$listed,
		array(
			'name'      => 'drawing.png',
			'mime_type' => 'image/png',
			'sha256'    => $image_hash,
			'byte_size' => strlen( $png ),
		)
	);
	$projection->submission['attachments'][0] = $listed;
	$uri                                      = $reader->image_data_uri( $submission_id, $listed, $context );
	$assert( 'data:image/png;base64,' . base64_encode( $png ) === $uri, 'PDF image is exactly the verified official file.' );
	foreach ( array(
		'sha256'    => str_repeat( 'a', 64 ),
		'mime_type' => 'image/jpeg',
		'byte_size' => 1,
		'name'      => 'other.png',
	) as $field => $value ) {
		$changed = array_replace( $listed, array( $field => $value ) );
		$denied( static fn () => $reader->image_data_uri( $submission_id, $changed, $context ), 'PDF snapshot integrity: ' . $field );
	}
	$payload    = array(
		'profile'     => 'teacher_submission',
		'document'    => array(
			'locale' => 'ko-KR',
			'nodes'  => array(
				array(
					'type'        => 'oj_ai_response',
					'response_id' => $response_id,
					'kind'        => 'image_upload',
					'label'       => '공식 제출 그림',
					'constraints' => array(),
				),
			),
		),
		'assignment'  => array(
			'title'   => '교사 제출 PDF 그림 검증',
			'context' => $context,
		),
		'learner'     => array( 'display_name' => '시험 학생' ),
		'submission'  => array(
			'id'               => $submission_id,
			'attempt_no'       => 1,
			'submitted_at_utc' => '2026-08-22 09:00:00',
		),
		'provenance'  => array( 'submission_revision_id' => $id( 42 ) ),
		'answers'     => array( $response_id => array( $attachment_id ) ),
		'attachments' => array( array_replace( $listed, array( 'data_uri' => 'https://foreign.test/secret.png' ) ) ),
	);
	$print      = array(
		'kind'                        => 'submission_print',
		'profile'                     => 'teacher_submission',
		'submission_print_projection' => array(
			'schema_id'         => 'oj_ai.submission_print',
			'schema_version'    => '1.0.0',
			'projector_version' => '1.0.0',
			'payload'           => $payload,
			'payload_hash'      => hash( 'sha256', $canonical->encode( $payload ) ),
		),
	);
	$serializer = new OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer( $canonical, $reader );
	$html       = $serializer->serialize( $print, $id( 42 ) );
	$assert( str_contains( $html, 'src="' . $uri . '"' ) && ! str_contains( $html, 'foreign.test' ) && ! str_contains( $html, 'storage_key' ), 'PDF uses verified image bytes, never a payload URL.' );
	$projection->visible = false;
	$denied( static fn () => $serializer->serialize( $print, $id( 42 ) ), 'Revoked submission stops PDF image access.' );
	$projection->visible = true;
	file_put_contents( $store->readable_path( 'files/answer.txt', $image_hash ), $bytes );
	$denied( static fn () => $serializer->serialize( $print, $id( 42 ) ), 'Changed image bytes stop PDF generation.' );
	file_put_contents( $temporary . '/private/files/answer.txt', $png );
	$root = dirname( __DIR__, 2 );
	require_once $root . '/vendor/autoload.php';
	$renderer = new OJAI\Dashboard\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer( null, $root, $root . '/.cache/teacher-pdf' );
	$pdf      = $renderer->render( $html );
	$assert( 1 === $pdf->page_count() && str_contains( $pdf->bytes(), '/Subtype /Image' ), 'Production renderer embeds the official drawing in one PDF page.' );
	file_put_contents( $root . '/tests/artifacts/teacher-submission-image.pdf', $pdf->bytes() );
	echo 'Teacher attachment content checks passed: ' . (int) $checks . ".\n";
} finally {
	foreach ( array( '/fixture.txt', '/private/files/answer.txt' ) as $file ) {
		if ( is_file( $temporary . $file ) ) {
			unlink( $temporary . $file );
		}
	}
	rmdir( $temporary . '/private/files' );
	rmdir( $temporary . '/private' );
	rmdir( $temporary );
}

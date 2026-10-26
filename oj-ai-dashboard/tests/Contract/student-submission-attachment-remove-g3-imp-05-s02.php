<?php
/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/assignment-create-g3-imp-01.php';

use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Remove_Own_Staged_Attachment;
use OJAI\Dashboard\Submission\Student\Application\Remove_Own_Staged_Attachment_Command;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Removal_Port_Interface;
use OJAI\Dashboard\Submission\Student\Presentation\Student_Assignment_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * C25 id.
 *
 * @param int $number Number.
 * @return string
 */
function c25_id( int $number ): string {
	return str_pad( (string) $number, 26, '0', STR_PAD_LEFT );
}

/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 */
final class C25_Access implements Student_Assignment_Access_Port_Interface {
	/**
	 * Initialize c25 access dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( public array $snapshot ) {
	}
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array {
		unset( $assignment_id, $actor_user_id );
		return null;
	}
	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array {
		return 7 === $actor_user_id && $submission_id === $this->snapshot['submission_id'] ? $this->snapshot : null;
	}
}

/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 */
final class C25_Contexts implements Learning_Context_Query_Port_Interface {
	/**
	 * Active.
	 *
	 * @var bool
	 */
	public bool $active = true;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		g3_same( 7, $actor_user_id, 'C25 Context lookup must use the authenticated learner.' );
		if ( ! $this->active ) {
			return array();
		}
		return array(
			'class:class-21' => array(
				'type'            => 'class',
				'id'              => 'class-21',
				'label'           => '2학년 1반',
				'lifecycle_state' => 'active',
				'revision'        => 'member-r5',
				'attributes'      => array(),
			),
		);
	}
}

/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 */
final class C25_Removals implements Student_Attachment_Removal_Port_Interface {
	/**
	 * Receipts.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $receipts = array();
	/**
	 * Attachment.
	 *
	 * @var array<string,mixed>
	 */
	public array $attachment;
	/**
	 * Writes.
	 *
	 * @var int
	 */
	public int $writes = 0;
	/**
	 * Initialize c25 removals dependencies and state.
	 *
	 * @param array<string,mixed> $attachment Attachment.
	 */
	public function __construct( array $attachment ) {
		$this->attachment = $attachment;
	}

	/**
	 * Find removal receipt.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_removal_receipt( string $submission_id, int $actor_user_id, string $storage_key, string $request_digest ): ?array {
		if ( 7 !== $actor_user_id || $submission_id !== $this->attachment['submission_id'] ) {
			return null;
		}
		$receipt = $this->receipts[ $storage_key ] ?? null;
		if ( null === $receipt ) {
			return null;
		}
		if ( ! hash_equals( $request_digest, $receipt['request_digest'] ) ) {
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		return array_merge( $receipt['result'], array( 'replayed' => true ) );
	}

	/**
	 * Remove staged.
	 *
	 * @param array $record Record.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function remove_staged( array $record ): array {
		$replay = $this->find_removal_receipt(
			$record['submission_public_id'],
			$record['actor_user_id'],
			$record['idempotency_storage_key'],
			$record['request_digest']
		);
		if ( is_array( $replay ) ) {
			return $replay;
		}
		if (
			$record['attachment_public_id'] !== $this->attachment['attachment_id']
			|| $record['submission_public_id'] !== $this->attachment['submission_id']
			|| 7 !== $record['actor_user_id']
		) {
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		if (
			null !== $this->attachment['submission_revision_id'] || null !== $this->attachment['bound_at_utc']
			|| ! in_array( $this->attachment['state'], array( 'staged', 'scanning', 'ready' ), true )
			|| null !== $this->attachment['legal_hold_until_utc']
		) {
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		++$this->writes;
		$this->attachment['state']      = 'removed';
		$this->attachment['file_state'] = 'purged';
		$result                         = array(
			'attachment_id'  => $this->attachment['attachment_id'],
			'file_id'        => $this->attachment['file_id'],
			'response_id'    => $this->attachment['response_id'],
			'state'          => 'removed',
			'removed_at_utc' => $record['occurred_at_utc'],
			'storage_key'    => $this->attachment['storage_key'],
			'replayed'       => false,
		);
		$this->receipts[ $record['idempotency_storage_key'] ] = array(
			'request_digest' => $record['request_digest'],
			'result'         => $result,
		);
		return $result;
	}
}

/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 */
final class C25_Files implements Private_File_Store_Port_Interface {
	/**
	 * Rows.
	 *
	 * @var array<string,bool>
	 */
	public array $rows = array();
	/**
	 * Delete calls.
	 *
	 * @var int
	 */
	public int $delete_calls = 0;
	/**
	 * Fail next delete.
	 *
	 * @var bool
	 */
	public bool $fail_next_delete = false;
	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		unset( $source_path, $sha256 );
		$this->rows[ $storage_key ] = true;
		return true;
	}
	/**
	 * Delete c25 files.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function delete( string $storage_key ): void {
		++$this->delete_calls;
		if ( $this->fail_next_delete ) {
			$this->fail_next_delete = false;
			throw new RuntimeException( 'Injected C25 binary delete failure.' );
		}
		unset( $this->rows[ $storage_key ] );
	}
}

/**
 * G3-IMP-05-S02 C25 staged attachment removal contract.
 */
final class C25_Request {
	/**
	 * Initialize c25 request dependencies and state.
	 *
	 * @param array<string,mixed> $input Input.
	 */
	public function __construct( private array $input ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->input;
	}
}

/**
 * C25 attachment.
 *
 * @param string $submission_id Submission public identifier.
 * @param string $attachment_id Attachment id.
 * @param string $file_id File id.
 * @param string $response_id Response id.
 * @return array<string,mixed>
 */
function c25_attachment( string $submission_id, string $attachment_id, string $file_id, string $response_id ): array {
	return array(
		'submission_id'          => $submission_id,
		'attachment_id'          => $attachment_id,
		'file_id'                => $file_id,
		'response_id'            => $response_id,
		'state'                  => 'scanning',
		'file_state'             => 'quarantined',
		'submission_revision_id' => null,
		'bound_at_utc'           => null,
		'legal_hold_until_utc'   => null,
		'storage_key'            => 'submission-attachments/aa/remove-me.png',
	);
}

$canonical        = new Canonical_Json();
$submission_id    = c25_id( 1 );
$attachment_id    = c25_id( 2 );
$file_id          = c25_id( 3 );
$response_id      = c25_id( 4 );
$publication_hash = hash( 'sha256', 'c25-publication' );
$schedule_hash    = hash( 'sha256', 'c25-schedule' );
$save_token       = hash( 'sha256', 'c25-save-token' );
$snapshot         = array(
	'submission_id'    => $submission_id,
	'assignment_id'    => c25_id( 5 ),
	'context_type'     => 'class',
	'context_id'       => 'class-21',
	'context_revision' => 'target-r1',
	'current_revision' => '0',
	'state'            => 'draft',
	'assignment_state' => 'open',
	'target_state'     => 'active',
);
$contexts         = new C25_Contexts();
$removals         = new C25_Removals( c25_attachment( $submission_id, $attachment_id, $file_id, $response_id ) );
$files            = new C25_Files();
$files->rows['submission-attachments/aa/remove-me.png'] = true;
$seed     = 190;
$ids      = new Public_Id_Generator(
	static function ( int $length ) use ( &$seed ): string {
		++$seed;
		return str_repeat( chr( $seed ), $length );
	}
);
$use_case = new Remove_Own_Staged_Attachment(
	new C25_Access( $snapshot ),
	$contexts,
	$removals,
	$files,
	$ids,
	static fn (): string => '2026-08-22 11:00:00'
);
$command  = new Remove_Own_Staged_Attachment_Command(
	7,
	$submission_id,
	$attachment_id,
	'attachment-remove-0001',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	hash( 'sha256', 'c25-key-1' ),
	'attachment-remove-correlation-1',
	$canonical
);
$removed  = $use_case->execute( $command );
g3_same(
	array(
		'id'    => $attachment_id,
		'state' => 'removed',
	),
	$removed->attachment(),
	'C25 must return only the removed attachment projection.'
);
g3_same( '2026-08-22 11:00:00', $removed->removed_at_utc(), 'C25 must preserve the transaction time.' );
g3_same( false, $removed->replayed(), 'The first C25 command must mutate once.' );
g3_same( 'removed', $removals->attachment['state'], 'C25 must retain a terminal removed attachment row.' );
g3_same( 'purged', $removals->attachment['file_state'], 'C25 must mark private metadata purged in the same logical command.' );
g3_same( 0, count( $files->rows ), 'C25 must remove private bytes after metadata commit.' );
g3_same( 2, $contexts->calls, 'C25 must recheck current Context immediately before writing.' );

$replayed = $use_case->execute( $command );
g3_same( true, $replayed->replayed(), 'An exact C25 retry must replay the durable receipt.' );
g3_same( 1, $removals->writes, 'C25 replay must not repeat attachment or file metadata writes.' );

$changed_object = new Remove_Own_Staged_Attachment_Command(
	7,
	$submission_id,
	c25_id( 99 ),
	'attachment-remove-0002',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	hash( 'sha256', 'c25-key-1' ),
	'attachment-remove-correlation-2',
	$canonical
);
g3_failure( static fn () => $use_case->execute( $changed_object ), Assignment_Failure::IDEMPOTENCY_CONFLICT, 'One C25 key must not remove another attachment.' );

$bound_removals = new C25_Removals(
	array_merge(
		c25_attachment( $submission_id, c25_id( 6 ), c25_id( 7 ), $response_id ),
		array(
			'state'                  => 'bound',
			'submission_revision_id' => 11,
			'bound_at_utc'           => '2026-08-22 10:59:00',
		)
	)
);
$bound_case     = new Remove_Own_Staged_Attachment( new C25_Access( $snapshot ), new C25_Contexts(), $bound_removals, new C25_Files(), $ids, static fn (): string => '2026-08-22 11:01:00' );
$bound_command  = new Remove_Own_Staged_Attachment_Command(
	7,
	$submission_id,
	c25_id( 6 ),
	'attachment-remove-bound',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	hash( 'sha256', 'c25-key-bound' ),
	'attachment-remove-bound-correlation',
	$canonical
);
g3_failure( static fn () => $bound_case->execute( $bound_command ), Assignment_Failure::STATE_CONFLICT, 'C25 must never remove an attachment bound to an immutable revision.' );
g3_same( 0, $bound_removals->writes, 'A bound attachment rejection must perform zero writes.' );

$contexts->active = false;
$context_command  = new Remove_Own_Staged_Attachment_Command(
	7,
	$submission_id,
	c25_id( 8 ),
	'attachment-remove-context',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	hash( 'sha256', 'c25-key-context' ),
	'attachment-remove-context-correlation',
	$canonical
);
g3_failure( static fn () => $use_case->execute( $context_command ), Assignment_Failure::NOT_FOUND, 'C25 must fail closed after current Context participation is lost.' );
$contexts->active = true;

$retry_removals = new C25_Removals( c25_attachment( $submission_id, c25_id( 9 ), c25_id( 10 ), $response_id ) );
$retry_files    = new C25_Files();
$retry_files->rows['submission-attachments/aa/remove-me.png'] = true;
$retry_files->fail_next_delete                                = true;
$retry_case    = new Remove_Own_Staged_Attachment( new C25_Access( $snapshot ), new C25_Contexts(), $retry_removals, $retry_files, $ids, static fn (): string => '2026-08-22 11:02:00' );
$retry_command = new Remove_Own_Staged_Attachment_Command(
	7,
	$submission_id,
	c25_id( 9 ),
	'attachment-remove-retry',
	'draft',
	0,
	null,
	1,
	$schedule_hash,
	$publication_hash,
	$save_token,
	hash( 'sha256', 'c25-key-retry' ),
	'attachment-remove-retry-correlation',
	$canonical
);
g3_failure( static fn () => $retry_case->execute( $retry_command ), Assignment_Failure::STORAGE_UNAVAILABLE, 'C25 must expose a post-commit binary cleanup failure as retryable.' );
g3_same( 1, $retry_removals->writes, 'A cleanup failure must not roll back terminal DB metadata.' );
$retry_result = $retry_case->execute( $retry_command );
g3_same( true, $retry_result->replayed(), 'The same C25 key must resume binary cleanup from its durable receipt.' );
g3_same( 0, count( $retry_files->rows ), 'C25 replay must finish the pending private byte deletion.' );
g3_same( 1, $retry_removals->writes, 'C25 cleanup recovery must add zero DB writes.' );

$controller   = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->newInstanceWithoutConstructor();
$input_method = ( new ReflectionClass( Student_Assignment_Rest_Controller::class ) )->getMethod( 'remove_attachment_input' );
$rest_input   = array(
	'command_id' => 'attachment-remove-rest',
	'expected'   => array(
		'expected_current_revision_id' => null,
		'expected_lock_version'        => 0,
		'expected_publication_hash'    => $publication_hash,
		'expected_save_token'          => $save_token,
		'expected_schedule_hash'       => $schedule_hash,
		'expected_schedule_version'    => 1,
		'expected_state'               => 'draft',
	),
	'payload'    => array(),
);
$parsed       = $input_method->invoke( $controller, new C25_Request( $rest_input ) );
g3_same( null, $parsed['expected_current_revision_id'], 'C25 REST must preserve a null current revision.' );
$rest_input['payload']['unknown'] = true;
g3_invalid( static fn () => $input_method->invoke( $controller, new C25_Request( $rest_input ) ), 'C25 REST must reject a non-empty payload.' );

$sources = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Student_Attachment_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Presentation/Student_Assignment_Rest_Controller.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Submission_Object_Authorization.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ), 'C25 must never use a WordPress table prefix.' );
	g3_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'C25 must never use a legacy prefixed table.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C25 must not consume OJ Platform internals.' );
}
foreach ( array( "'oj_ai_submission_attachments'", "'oj_ai_private_files'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", 'START TRANSACTION', 'submission_revision_id IS NULL', 'legal_hold_until_utc IS NULL', 'submission.attachment.removed' ) as $marker ) {
	g3_assert( str_contains( $sources[0], $marker ), 'C25 transaction marker is missing: ' . $marker );
}
foreach ( array( 'commands/remove', 'student_submission.attachment.remove', 'remove_attachment_input', "'remove_attachment'" ) as $marker ) {
	g3_assert( str_contains( $sources[1], $marker ), 'C25 REST marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[2], "'remove_attachment'" ), 'C25 Object Scope action must be registered.' );
g3_assert( str_contains( $sources[3], 'Remove_Own_Staged_Attachment' ), 'The single Composition Root must wire C25.' );

echo "G3-IMP-05-S02 passed: C25 owner-only unbound removal, atomic metadata closure, durable replay, cleanup recovery, and literal-table boundaries.\n";

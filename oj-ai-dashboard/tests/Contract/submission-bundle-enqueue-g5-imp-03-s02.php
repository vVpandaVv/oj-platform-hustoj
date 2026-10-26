<?php
/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Command;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Result;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Infrastructure\WordPress_Submission_Bundle_Repository;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Repository_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Presentation\Submission_Bundle_Rest_Controller;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Submission_Bundle_Source_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/report-export-enqueue-g5-imp-03-s01.php';

/**
 * G5 bundle assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_bundle_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G5 bundle same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_bundle_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G5 bundle failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_bundle_failure( callable $operation, string $reason, string $message ): void {
	try {
		$operation();
	} catch ( Submission_Bundle_Failure $failure ) {
		g5_bundle_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * G5 bundle source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param bool           $include_originals Include originals.
 * @param int            $attachment_bytes Attachment bytes.
 * @return array<string,mixed>
 */
function g5_bundle_source( Canonical_Json $canonical, bool $include_originals = true, int $attachment_bytes = 4096 ): array {
	$assignment_id         = g5_snapshot_q22_id( 2100 );
	$publication_id        = g5_snapshot_q22_id( 2101 );
	$target_id             = g5_snapshot_q22_id( 2200 );
	$submission_id         = g5_snapshot_q22_id( 2300 );
	$revision_id           = g5_snapshot_q22_id( 2400 );
	$attachment_id         = g5_snapshot_q22_id( 2500 );
	$file_id               = g5_snapshot_q22_id( 2600 );
	$attachment_ids        = array( $attachment_id );
	$attachments           = $include_originals ? array(
		array(
			'id'            => $attachment_id,
			'file_id'       => $file_id,
			'response_key'  => 'response-file-1',
			'original_name' => 'student answer final.pdf',
			'mime_type'     => 'application/pdf',
			'byte_size'     => $attachment_bytes,
			'sha256'        => hash( 'sha256', 'attachment-content' ),
			'archive_path'  => sprintf( 'attachments/0001-%s/%s.pdf', strtolower( $target_id ), strtolower( $attachment_id ) ),
		),
	) : array();
	$source                = array(
		'schema_id'      => Submission_Bundle_Request_Snapshot::SOURCE_SCHEMA,
		'schema_version' => Submission_Bundle_Request_Snapshot::SOURCE_VERSION,
		'context'        => array(
			'type'     => 'class',
			'id'       => 'class-g5-bundle',
			'revision' => 'class-g5-bundle-r3',
		),
		'assignment'     => array(
			'id'                     => $assignment_id,
			'hash'                   => hash( 'sha256', $assignment_id . '|2026-08-25 15:00:00' ),
			'publication_id'         => $publication_id,
			'publication_hash'       => hash( 'sha256', 'publication-g5-bundle' ),
			'title'                  => 'Algebra practice',
			'state'                  => 'open',
			'schedule_version'       => 3,
			'schedule_hash'          => hash( 'sha256', 'schedule-g5-bundle' ),
			'target_roster_revision' => 'roster-g5-bundle-r1',
			'target_snapshot_hash'   => hash( 'sha256', 'target-snapshot-g5-bundle' ),
		),
		'submissions'    => array(
			array(
				'target'            => array(
					'id'                  => $target_id,
					'hash'                => hash( 'sha256', 'target-g5-bundle' ),
					'display_name'        => 'Student One',
					'membership_revision' => 'membership-g5-bundle-r1',
					'snapshot_hash'       => hash( 'sha256', 'target-item-snapshot-g5-bundle' ),
				),
				'submission'        => array(
					'id'               => $submission_id,
					'hash'             => hash( 'sha256', $submission_id . '|2026-08-25 16:30:00' ),
					'state'            => 'submitted',
					'submitted_at_utc' => '2026-08-25 16:00:00',
				),
				'official_revision' => array(
					'id'                  => $revision_id,
					'hash'                => hash( 'sha256', 'revision-g5-bundle' ),
					'attachment_set_hash' => hash( 'sha256', $canonical->encode( $attachment_ids ) ),
				),
				'pdf_path'          => sprintf( 'submissions/0001-%s.pdf', strtolower( $target_id ) ),
				'attachments'       => $attachments,
			),
		),
	);
	$source['source_hash'] = hash( 'sha256', $canonical->encode( $source ) );
	return $source;
}

/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 */
final class G5_Bundle_Memory_Source implements Submission_Bundle_Source_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Initialize g5 bundle memory source dependencies and state.
	 *
	 * @param array<int,array<string,mixed>|null> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * Read g5 bundle memory source.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param bool   $include_allowed_originals Include allowed originals.
	 * @return array|null
	 */
	public function read( string $assignment_id, string $context_type, string $context_id, string $context_revision, bool $include_allowed_originals ): ?array {
		unset( $assignment_id, $context_type, $context_id, $context_revision, $include_allowed_originals );
		$index = min( $this->reads, count( $this->sources ) - 1 );
		++$this->reads;
		return $this->sources[ $index ];
	}
}

/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 */
final class G5_Bundle_Memory_Repository implements Submission_Bundle_Repository_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Enqueue_Submission_Bundle_Result}>
	 */
	private array $receipts = array();
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Enqueue_Submission_Bundle_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Enqueue_Submission_Bundle_Result {
		$receipt = $this->receipts[ $key ] ?? null;
		if ( null === $receipt ) {
			return null;
		}
		if ( ! hash_equals( $receipt['digest'], $digest ) ) {
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $receipt['result'];
		return new Enqueue_Submission_Bundle_Result( $result->job_id(), $result->state(), $result->assignment_id(), $result->source_hash(), $result->queued_at_utc(), true );
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Submission_Bundle_Result
	 */
	public function enqueue( array $record ): Enqueue_Submission_Bundle_Result {
		$result          = new Enqueue_Submission_Bundle_Result( $record['job_public_id'], 'queued', $record['source_public_id'], $record['source_hash'], $record['queued_at_utc'], false );
		$this->records[] = $record;
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 */
final class G5_Bundle_Queue implements Submission_Bundle_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->calls;
	}
}

/**
 * G5 bundle command.
 *
 * @param array          $source Source.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command_id Command identifier used for idempotency.
 * @param string         $fingerprint_seed Fingerprint seed.
 * @param string|null    $expected_hash Expected hash.
 * @param bool           $include_originals Include originals.
 * @return Enqueue_Submission_Bundle_Command
 */
function g5_bundle_command( array $source, Canonical_Json $canonical, string $command_id, string $fingerprint_seed, ?string $expected_hash = null, bool $include_originals = true ): Enqueue_Submission_Bundle_Command {
	return new Enqueue_Submission_Bundle_Command(
		77,
		$source['assignment']['id'],
		$command_id,
		$source['assignment']['id'],
		$expected_hash ?? $source['assignment']['hash'],
		$source['assignment']['schedule_version'],
		$source['assignment']['state'],
		'ko-KR',
		Submission_Bundle_Request_Snapshot::PROFILE,
		$include_originals,
		hash( 'sha256', $fingerprint_seed ),
		'correlation-' . $command_id,
		(string) $source['assignment']['schedule_version'],
		$source['assignment']['state'],
		'context_staff',
		$source['context']['type'],
		$source['context']['id'],
		$source['context']['revision'],
		'educator',
		$canonical
	);
}

$bundle_canonical  = new Canonical_Json();
$bundle_source     = g5_bundle_source( $bundle_canonical );
$memory_source     = new G5_Bundle_Memory_Source( array( $bundle_source, $bundle_source ) );
$memory_repository = new G5_Bundle_Memory_Repository();
$memory_queue      = new G5_Bundle_Queue();
$enqueue           = new Enqueue_Submission_Bundle(
	$memory_source,
	$memory_repository,
	$memory_queue,
	new Public_Id_Generator(),
	$bundle_canonical,
	static fn (): string => '2026-08-25 17:00:00'
);
$command           = g5_bundle_command( $bundle_source, $bundle_canonical, 'command-g5-bundle-c50', 'bundle-key' );
$result            = $enqueue->execute( $command );
g5_bundle_same( 2, $memory_source->reads, 'C50 did not collect the full official submission source twice.' );
g5_bundle_same( 1, count( $memory_repository->records ), 'C50 did not enqueue exactly one export job.' );
g5_bundle_same( 1, $memory_queue->calls, 'C50 did not wake the private bundle queue once.' );
$record   = $memory_repository->records[0];
$snapshot = Submission_Bundle_Request_Snapshot::restore( $record['snapshot_json'], $record['snapshot_hash'], $bundle_canonical );
$manifest = $snapshot->manifest();
g5_bundle_same( 4, $snapshot->entry_count(), 'C50 entry count did not include PDF, original, manifest, and checksum.' );
g5_bundle_same( $bundle_canonical->encode( $bundle_source ), $bundle_canonical->encode( $manifest ), 'C50 changed the exact assignment and official revision manifest.' );
g5_bundle_same( 'submissions/0001-' . strtolower( g5_snapshot_q22_id( 2200 ) ) . '.pdf', $manifest['submissions'][0]['pdf_path'], 'C50 PDF archive path drifted.' );
g5_bundle_same( 'attachments/0001-' . strtolower( g5_snapshot_q22_id( 2200 ) ) . '/' . strtolower( g5_snapshot_q22_id( 2500 ) ) . '.pdf', $manifest['submissions'][0]['attachments'][0]['archive_path'], 'C50 attachment archive path drifted.' );
g5_bundle_assert( ! str_contains( $manifest['submissions'][0]['attachments'][0]['archive_path'], 'student answer' ), 'C50 placed a user filename in the archive path.' );

$replay = $enqueue->execute( $command );
g5_bundle_assert( $replay->replayed(), 'C50 durable retry was not marked as replayed.' );
g5_bundle_same( $result->job_id(), $replay->job_id(), 'C50 durable replay changed the export job ID.' );
g5_bundle_same( 2, $memory_source->reads, 'C50 durable replay re-read mutable submission sources.' );
g5_bundle_same( 1, $memory_queue->calls, 'C50 durable replay woke the queue again.' );

$stale_source  = new G5_Bundle_Memory_Source( array( $bundle_source, $bundle_source ) );
$stale_enqueue = new Enqueue_Submission_Bundle( $stale_source, new G5_Bundle_Memory_Repository(), new G5_Bundle_Queue(), new Public_Id_Generator(), $bundle_canonical );
g5_bundle_failure(
	static fn () => $stale_enqueue->execute( g5_bundle_command( $bundle_source, $bundle_canonical, 'command-g5-bundle-stale', 'stale-bundle-key', hash( 'sha256', 'stale-assignment' ) ) ),
	Submission_Bundle_Failure::SOURCE_STALE,
	'C50 accepted a stale expected assignment hash.'
);

$drifted                        = $bundle_source;
$drifted['assignment']['title'] = 'Changed title';
unset( $drifted['source_hash'] );
$drifted['source_hash'] = hash( 'sha256', $bundle_canonical->encode( $drifted ) );
$drift_source           = new G5_Bundle_Memory_Source( array( $bundle_source, $drifted ) );
$drift_repository       = new G5_Bundle_Memory_Repository();
$drift_enqueue          = new Enqueue_Submission_Bundle( $drift_source, $drift_repository, new G5_Bundle_Queue(), new Public_Id_Generator(), $bundle_canonical );
g5_bundle_failure(
	static fn () => $drift_enqueue->execute( g5_bundle_command( $bundle_source, $bundle_canonical, 'command-g5-bundle-race', 'race-bundle-key' ) ),
	Submission_Bundle_Failure::SOURCE_STALE,
	'C50 accepted a source that changed between the two complete reads.'
);
g5_bundle_same( 0, count( $drift_repository->records ), 'C50 source race left a partial job.' );

$too_large     = g5_bundle_source( $bundle_canonical, true, Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_BYTES + 1 );
$large_source  = new G5_Bundle_Memory_Source( array( $too_large, $too_large ) );
$large_enqueue = new Enqueue_Submission_Bundle( $large_source, new G5_Bundle_Memory_Repository(), new G5_Bundle_Queue(), new Public_Id_Generator(), $bundle_canonical );
g5_bundle_failure(
	static fn () => $large_enqueue->execute( g5_bundle_command( $too_large, $bundle_canonical, 'command-g5-bundle-large', 'large-bundle-key' ) ),
	Submission_Bundle_Failure::TOO_LARGE,
	'C50 accepted an assignment bundle larger than 500 MiB.'
);

$too_many_files                = g5_bundle_source( $bundle_canonical, false );
$item_template                 = $too_many_files['submissions'][0];
$too_many_files['submissions'] = array();
for ( $index = 1; $index <= 499; ++$index ) {
	$item                              = $item_template;
	$target_id                         = g5_snapshot_q22_id( 3000 + $index );
	$item['target']['id']              = $target_id;
	$item['target']['hash']            = hash( 'sha256', 'target-file-limit-' . $index );
	$item['target']['snapshot_hash']   = hash( 'sha256', 'target-snapshot-file-limit-' . $index );
	$item['submission']['id']          = g5_snapshot_q22_id( 4000 + $index );
	$item['submission']['hash']        = hash( 'sha256', 'submission-file-limit-' . $index );
	$item['official_revision']['id']   = g5_snapshot_q22_id( 5000 + $index );
	$item['official_revision']['hash'] = hash( 'sha256', 'revision-file-limit-' . $index );
	$item['pdf_path']                  = sprintf( 'submissions/%04d-%s.pdf', $index, strtolower( $target_id ) );
	$too_many_files['submissions'][]   = $item;
}
unset( $too_many_files['source_hash'] );
$too_many_files['source_hash'] = hash( 'sha256', $bundle_canonical->encode( $too_many_files ) );
$file_limit_source             = new G5_Bundle_Memory_Source( array( $too_many_files, $too_many_files ) );
$file_limit_enqueue            = new Enqueue_Submission_Bundle( $file_limit_source, new G5_Bundle_Memory_Repository(), new G5_Bundle_Queue(), new Public_Id_Generator(), $bundle_canonical );
g5_bundle_failure(
	static fn () => $file_limit_enqueue->execute( g5_bundle_command( $too_many_files, $bundle_canonical, 'command-g5-bundle-files', 'file-limit-bundle-key', null, false ) ),
	Submission_Bundle_Failure::TOO_LARGE,
	'C50 accepted more than 500 archive files.'
);

$without_originals = g5_bundle_source( $bundle_canonical, false );
$without_snapshot  = Submission_Bundle_Request_Snapshot::freeze( $without_originals, $without_originals['context'], 'ko-KR', false, $bundle_canonical );
g5_bundle_same( 3, $without_snapshot->entry_count(), 'C50 PDF-only bundle entry count drifted.' );
g5_bundle_same( array(), $without_snapshot->manifest()['submissions'][0]['attachments'], 'C50 included originals when the option was disabled.' );

/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 */
final class G5_Bundle_Source_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Attachment reads.
	 *
	 * @var int
	 */
	public int $attachment_reads = 0;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();
	/**
	 * Initialize g5 bundle source database dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'G5BS:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}
	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array|null
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		[ $sql ]     = $this->statement( $prepared );
		$this->sql[] = $sql;
		return array(
			'assignment_pk'          => '11',
			'assignment_id'          => g5_snapshot_q22_id( 2100 ),
			'updated_at_utc'         => '2026-08-25 15:00:00',
			'publication_id'         => g5_snapshot_q22_id( 2101 ),
			'publication_hash'       => hash( 'sha256', 'publication-g5-bundle' ),
			'title_snapshot'         => 'Algebra practice',
			'state'                  => 'open',
			'schedule_version'       => '3',
			'schedule_hash'          => hash( 'sha256', 'schedule-g5-bundle' ),
			'target_roster_revision' => 'roster-g5-bundle-r1',
			'target_snapshot_hash'   => hash( 'sha256', 'target-snapshot-g5-bundle' ),
		);
	}
	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array
	 */
	public function get_results( string $prepared, mixed $output = null ): array {
		unset( $output );
		[ $sql ]     = $this->statement( $prepared );
		$this->sql[] = $sql;
		if ( str_contains( $sql, 'FROM oj_ai_submission_attachments' ) ) {
			++$this->attachment_reads;
			return array(
				array(
					'public_id'              => g5_snapshot_q22_id( 2500 ),
					'submission_revision_id' => '15',
					'response_key'           => 'response-file-1',
					'file_public_id'         => g5_snapshot_q22_id( 2600 ),
					'original_name'          => 'student answer final.pdf',
					'mime_type'              => 'application/pdf',
					'byte_size'              => '4096',
					'sha256'                 => hash( 'sha256', 'attachment-content' ),
				),
			);
		}
		$display = $this->canonical->encode( array( 'display_name' => 'Student One' ) );
		return array(
			array(
				'target_id'                 => g5_snapshot_q22_id( 2200 ),
				'target_hash'               => hash( 'sha256', 'target-g5-bundle' ),
				'membership_revision'       => 'membership-g5-bundle-r1',
				'display_snapshot_schema'   => 'oj_ai.assignment_target_display',
				'display_snapshot_version'  => '1',
				'display_snapshot_json'     => $display,
				'display_snapshot_hash'     => hash( 'sha256', $display ),
				'target_snapshot_hash'      => hash( 'sha256', 'target-item-snapshot-g5-bundle' ),
				'submission_id'             => g5_snapshot_q22_id( 2300 ),
				'submission_updated_at_utc' => '2026-08-25 16:30:00',
				'submission_state'          => 'submitted',
				'submitted_at_utc'          => '2026-08-25 16:00:00',
				'revision_pk'               => '15',
				'revision_id'               => g5_snapshot_q22_id( 2400 ),
				'revision_hash'             => hash( 'sha256', 'revision-g5-bundle' ),
				'attachment_set_hash'       => hash( 'sha256', $this->canonical->encode( array( g5_snapshot_q22_id( 2500 ) ) ) ),
			),
		);
	}
	/**
	 * Statement.
	 *
	 * @param string $prepared Prepared.
	 * @return array
	 */
	private function statement( string $prepared ): array {
		$value = unserialize( base64_decode( substr( $prepared, 5 ), true ), array( 'allowed_classes' => false ) );
		return array( $value[0], array_values( $value[1] ) );
	}
}

$source_database  = new G5_Bundle_Source_Database( $bundle_canonical );
$owner_source     = new WordPress_Submission_Bundle_Source_Query( $source_database, $bundle_canonical );
$owner_projection = $owner_source->read( g5_snapshot_q22_id( 2100 ), 'class', 'class-g5-bundle', 'class-g5-bundle-r3', true );
g5_bundle_assert( is_array( $owner_projection ), 'C50 owner adapter did not return the exact assignment projection.' );
g5_bundle_same( $bundle_canonical->encode( $bundle_source ), $bundle_canonical->encode( $owner_projection ), 'C50 owner adapter projection drifted from the frozen source profile.' );
g5_bundle_same( 1, $source_database->attachment_reads, 'C50 owner adapter did not read allowed bound originals exactly once.' );

$controller_reflection = new ReflectionClass( Submission_Bundle_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input                 = $controller_reflection->getMethod( 'input' );
$request               = new class( $bundle_source ) {
	/**
	 * Initialize submission-bundle-enqueue-g5-imp-03-s02 dependencies and state.
	 *
	 * @param array $source Source.
	 */
	public function __construct( private array $source ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return array(
			'command_id' => 'command-g5-rest-c50',
			'expected'   => array(
				'expected_assignment_id'    => $this->source['assignment']['id'],
				'expected_assignment_hash'  => $this->source['assignment']['hash'],
				'expected_schedule_version' => $this->source['assignment']['schedule_version'],
				'expected_assignment_state' => $this->source['assignment']['state'],
			),
			'payload'    => array(
				'locale'                    => 'ko-KR',
				'profile'                   => Submission_Bundle_Request_Snapshot::PROFILE,
				'include_allowed_originals' => true,
			),
		);
	}
};
$parsed                = $input->invoke( $controller, $request );
g5_bundle_same( 3, $parsed['expected_schedule_version'], 'C50 REST parser lost the exact assignment schedule version.' );
g5_bundle_assert( $parsed['include_allowed_originals'], 'C50 REST parser lost the original-file selection.' );
$GLOBALS['g5_snapshot_q22_routes'] = array();
$controller->register_routes();
g5_bundle_same( 1, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'C50 did not register exactly one REST route.' );
g5_bundle_same( Submission_Bundle_Rest_Controller::ROUTE, $GLOBALS['g5_snapshot_q22_routes'][0][1], 'C50 route drifted.' );

/**
 * G5-IMP-03-S02 C50 exact assignment submission bundle enqueue contract.
 */
final class G5_Bundle_Storage_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_insert_table = null;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_export_jobs'   => array(),
		'oj_ai_audit_events'  => array(),
		'oj_ai_outbox_events' => array(),
	);
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array(
		'oj_ai_export_jobs'   => 1,
		'oj_ai_audit_events'  => 1,
		'oj_ai_outbox_events' => 1,
	);
	/**
	 * Transaction.
	 *
	 * @var array|null
	 */
	private ?array $transaction = null;
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'G5BR:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}
	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array|null
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		$this->last_error    = '';
		[ $sql, $arguments ] = $this->statement( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( (string) $row['idempotency_key'] === (string) $arguments[0] ) {
					return $row;
				}
			} return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_export_jobs' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
				if ( $row['active_identity_key'] === $arguments[0] && $row['artifact_kind'] === $arguments[1] && $row['source_type'] === $arguments[2] && in_array( $row['state'], array( $arguments[3], $arguments[4] ), true ) ) {
					return $row;
				}
			} return null;
		}
		$this->last_error = 'unexpected select';
		return null;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		$this->last_error = '';
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$this->last_error = 'unexpected scalar';
			return null;
		} $rows = $this->tables['oj_ai_audit_events'];
		return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
	}
	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		$this->last_error = '';
		if ( ! isset( $this->tables[ $table ] ) || $this->fail_insert_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( isset( $data['public_id'] ) && ( $row['public_id'] ?? null ) === $data['public_id'] ) {
				$this->last_error = 'duplicate public id';
				return false;
			}
			if ( 'oj_ai_outbox_events' === $table && ( $row['idempotency_key'] ?? null ) === ( $data['idempotency_key'] ?? null ) ) {
				$this->last_error = 'duplicate command key';
				return false;
			}
		}
		$data['id']               = $this->next_ids[ $table ]++;
		$this->tables[ $table ][] = $data;
		return 1;
	}
	/**
	 * Query.
	 *
	 * @param string $prepared Prepared.
	 * @return int|false
	 */
	public function query( string $prepared ): int|false {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $prepared ) {
			$this->transaction = unserialize( serialize( array( $this->tables, $this->next_ids ) ) );
			return 1;
		}
		if ( 'COMMIT' === $prepared ) {
			$this->transaction = null;
			return 1;
		}
		if ( 'ROLLBACK' === $prepared ) {
			if ( null !== $this->transaction ) {
				[ $this->tables, $this->next_ids ] = $this->transaction;
			} $this->transaction = null;
			return 1;
		}
		$this->last_error = 'unexpected query';
		return false;
	}
	/**
	 * Statement.
	 *
	 * @param string $prepared Prepared.
	 * @return array
	 */
	private function statement( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5BR:' ) ) {
			return array( $prepared, array() );
		} $value = unserialize( base64_decode( substr( $prepared, 5 ), true ), array( 'allowed_classes' => false ) );
		return array( $value[0], array_values( $value[1] ) );
	}
}

$storage_database = new G5_Bundle_Storage_Database();
$storage          = new WordPress_Submission_Bundle_Repository( $bundle_canonical, $storage_database );
$storage_result   = $storage->enqueue( $record );
g5_bundle_same( 1, count( $storage_database->tables['oj_ai_export_jobs'] ), 'C50 storage did not create one export job.' );
g5_bundle_same( 1, count( $storage_database->tables['oj_ai_audit_events'] ), 'C50 storage did not create one audit event.' );
g5_bundle_same( 1, count( $storage_database->tables['oj_ai_outbox_events'] ), 'C50 storage did not create one durable receipt.' );
$stored_job = $storage_database->tables['oj_ai_export_jobs'][0];
g5_bundle_same( null, $stored_job['report_snapshot_id'], 'C50 incorrectly bound a report snapshot foreign key.' );
g5_bundle_same( 'assignment', $stored_job['source_type'], 'C50 source owner drifted.' );
g5_bundle_same( 'submission_bundle', $stored_job['artifact_kind'], 'C50 artifact kind drifted.' );
g5_bundle_same( $record['snapshot_json'], $stored_job['request_snapshot_json'], 'C50 storage changed immutable request bytes.' );
$durable = $storage->find_command_receipt( $record['command_storage_key'], $record['request_digest'] );
g5_bundle_assert( null !== $durable && $durable->replayed(), 'C50 durable receipt was not restored.' );
g5_bundle_same( $storage_result->job_id(), $durable->job_id(), 'C50 durable replay changed the job ID.' );
g5_bundle_failure(
	static fn () => $storage->find_command_receipt( $record['command_storage_key'], hash( 'sha256', 'different-c50-digest' ) ),
	Submission_Bundle_Failure::IDEMPOTENCY_CONFLICT,
	'C50 storage accepted a reused command key with a different digest.'
);

$rollback_database                    = new G5_Bundle_Storage_Database();
$rollback_database->fail_insert_table = 'oj_ai_audit_events';
$rollback_storage                     = new WordPress_Submission_Bundle_Repository( $bundle_canonical, $rollback_database );
g5_bundle_failure( static fn () => $rollback_storage->enqueue( $record ), Submission_Bundle_Failure::STORAGE_UNAVAILABLE, 'C50 did not surface a transactional audit failure.' );
g5_bundle_same( 0, count( $rollback_database->tables['oj_ai_export_jobs'] ), 'C50 rollback left a partial export job.' );
g5_bundle_same( 0, count( $rollback_database->tables['oj_ai_outbox_events'] ), 'C50 rollback left a partial outbox receipt.' );

$runtime_files  = array(
	dirname( __DIR__, 2 ) . '/src/Reporting/SubmissionBundle/Application/Enqueue_Submission_Bundle.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/SubmissionBundle/Domain/Submission_Bundle_Request_Snapshot.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/SubmissionBundle/Infrastructure/WordPress_Submission_Bundle_Repository.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/SubmissionBundle/Presentation/Submission_Bundle_Rest_Controller.php',
	dirname( __DIR__, 2 ) . '/src/Submission/Assignment/Infrastructure/WordPress_Submission_Bundle_Source_Query.php',
	dirname( __DIR__, 2 ) . '/src/Submission/Assignment/Infrastructure/Assignment_Object_Authorization.php',
	dirname( __DIR__, 2 ) . '/src/Core/Composition_Root.php',
);
$runtime_source = '';
foreach ( $runtime_files as $runtime_file ) {
	$runtime_source .= (string) file_get_contents( $runtime_file );
}
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g5_bundle_assert( ! str_contains( $runtime_source, $forbidden ), 'C50 runtime contains a forbidden boundary marker: ' . $forbidden );
}
foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_assignments'", "'oj_ai_submission_revisions'", "'oj_ai_submission_attachments'", "'submission_bundle'", "'source_type' => 'assignment'", "'report_snapshot_id' => null", 'START TRANSACTION', 'active_identity_key', 'export_submissions' ) as $marker ) {
	g5_bundle_assert( Ojai_Php_Source_Contract::marker( $runtime_source, $marker ), 'C50 runtime marker is missing: ' . $marker );
}
g5_bundle_assert( str_contains( $runtime_source, 'new Submission_Bundle_Rest_Module' ), 'C50 Composition Root wiring is missing.' );

fwrite( STDOUT, "G5-IMP-03-S02 passed: C50 exact assignment and official revisions, safe archive paths, bounded immutable request, durable idempotency, and atomic enqueue are frozen.\n" );

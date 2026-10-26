<?php
/**
 * G2-IMP-02-S06 asset, audit, outbox, and idempotency transaction contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worksheet-save-draft-s05-transaction.php';

/**
 * S06 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s06_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * S06 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s06_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * S06 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return Worksheet_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function s06_failure( callable $callback, string $reason, string $message ): Worksheet_Failure {
	try {
		$callback();
	} catch ( Worksheet_Failure $failure ) {
		s06_same( $reason, $failure->reason(), $message );
		return $failure;
	}
	throw new RuntimeException( $message . ' Expected failure was not raised.' );
}

/**
 * S06 document.
 *
 * @param string $document_id Document id.
 * @param string $response_one Response one.
 * @param string $response_two Response two.
 * @param string $asset_id Asset id.
 * @param string $title Title.
 * @return array<string,mixed>
 */
function s06_document( string $document_id, string $response_one, string $response_two, string $asset_id, string $title ): array {
	$document = s05_document( $document_id, $response_one, $response_two, $title );
	array_splice(
		$document['nodes'],
		1,
		0,
		array(
			array(
				'type'     => 'img',
				'node_id'  => s05_id( 620 ),
				'asset_id' => $asset_id,
				'alt'      => 'Observation evidence',
				'children' => array( array( 'text' => '' ) ),
			),
		)
	);
	return $document;
}

/**
 * S06 context.
 *
 * @param string      $asset_state Asset state.
 * @param string      $scan_state Scan state.
 * @param string|null $asset_owner_id Asset owner id.
 * @return array{database:S05_Wpdb_Fixture,save:Save_Worksheet_Draft,worksheet_id:string,revision_id:string,document:array<string,mixed>,asset_id:string}
 */
function s06_context( string $asset_state = 'quarantined', string $scan_state = 'clean', ?string $asset_owner_id = null ): array {
	$canonical                               = new Canonical_Json();
	$worksheet_id                            = s05_id( 601 );
	$revision_id                             = s05_id( 602 );
	$document_id                             = s05_id( 603 );
	$response_one                            = s05_id( 604 );
	$response_two                            = s05_id( 605 );
	$asset_id                                = s05_id( 606 );
	$database                                = new S05_Wpdb_Fixture( $worksheet_id, $revision_id, str_repeat( 'a', 64 ) );
	$database->tables['oj_ai_private_files'] = array(
		array(
			'id'                 => 1,
			'public_id'          => $asset_id,
			'owner_type'         => 'worksheet',
			'owner_public_id'    => $asset_owner_id ?? $worksheet_id,
			'purpose'            => 'worksheet_asset',
			'mime_type'          => 'image/png',
			'byte_size'          => 4096,
			'sha256'             => str_repeat( 'b', 64 ),
			'state'              => $asset_state,
			'scan_state'         => $scan_state,
			'lock_version'       => 0,
			'updated_by_user_id' => 42,
			'updated_at_utc'     => '2026-08-20 09:00:00',
			'expires_at_utc'     => '2026-08-21 09:00:00',
		),
	);
	$repository                              = new WordPress_Worksheet_Repository( $database, $canonical );
	$save                                    = new Save_Worksheet_Draft(
		$repository,
		new S05_Standard_Snapshots( array() ),
		new Worksheet_Document_Canonicalizer( $canonical, s05_generator( 11000 ) ),
		new Worksheet_Revision_Projector( $canonical, s05_generator( 12000 ) ),
		s05_generator( 13000 ),
		$canonical,
		static fn (): string => '2026-08-20 11:00:00'
	);

	return array(
		'database'     => $database,
		'save'         => $save,
		'worksheet_id' => $worksheet_id,
		'revision_id'  => $revision_id,
		'document'     => s06_document( $document_id, $response_one, $response_two, $asset_id, 'Asset worksheet' ),
		'asset_id'     => $asset_id,
	);
}

/**
 * S06 command.
 *
 * @param string              $worksheet_id Worksheet public identifier.
 * @param string              $expected_revision_id Revision the caller read before this operation.
 * @param array<string,mixed> $document Document.
 * @param array<int,string>   $staged_receipts Staged receipts.
 * @param string              $command_suffix Command suffix.
 * @param string              $fingerprint Fingerprint.
 * @return Save_Worksheet_Draft_Command
 */
function s06_command(
	string $worksheet_id,
	string $expected_revision_id,
	array $document,
	array $staged_receipts,
	string $command_suffix,
	string $fingerprint
): Save_Worksheet_Draft_Command {
	return new Save_Worksheet_Draft_Command(
		42,
		$worksheet_id,
		'command-' . $command_suffix,
		'draft',
		$expected_revision_id,
		$document,
		array(),
		$staged_receipts,
		$fingerprint,
		'corr-' . $command_suffix,
		new Canonical_Json()
	);
}

$context       = s06_context();
$database      = $context['database'];
$save          = $context['save'];
$first_command = s06_command(
	$context['worksheet_id'],
	$context['revision_id'],
	$context['document'],
	array( $context['asset_id'] ),
	's06-save-0001',
	hash( 'sha256', 's06-fingerprint-0001' )
);
$first         = $save->execute( $first_command );
s06_same( 201, $first->http_status(), 'A changed asset-backed draft must append one revision.' );
s06_same( false, $first->replayed(), 'The first accepted command must not be a replay.' );
s06_same( 'ready', $database->tables['oj_ai_private_files'][0]['state'], 'The staged asset must be promoted in the save transaction.' );
s06_same( 1, $database->tables['oj_ai_private_files'][0]['lock_version'], 'Asset promotion must use one CAS update.' );
s06_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'A changed save must append one audit event.' );
s06_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'A changed save must append one outbox receipt.' );

$revision = $database->tables['oj_ai_worksheet_revisions'][1];
$audit    = $database->tables['oj_ai_audit_events'][0];
$outbox   = $database->tables['oj_ai_outbox_events'][0];
s06_same( str_repeat( 'a', 64 ), $audit['before_digest'], 'Audit before_digest must identify the locked source revision.' );
s06_same( $revision['content_hash'], $audit['after_digest'], 'Audit after_digest must identify the committed aggregate.' );
s06_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $audit['event_hash'] ), 'Audit event_hash must be a SHA-256 chain value.' );
s06_same( hash( 'sha256', $outbox['payload_json'] ), $outbox['payload_hash'], 'Outbox payload hash must cover canonical payload JSON.' );
$outbox_payload = json_decode( $outbox['payload_json'], true, 512, JSON_THROW_ON_ERROR );
s06_same( 1, count( $outbox_payload['asset_receipt_hashes'] ), 'Outbox metadata must freeze one safe asset receipt hash.' );
s06_same( $first->revision_id(), $outbox_payload['receipt']['revision_id'], 'The replay receipt must freeze the accepted revision ID.' );

$counts_after_first = $database->counts();
$asset_after_first  = $database->tables['oj_ai_private_files'][0];
$replay             = $save->execute( $first_command );
s06_same( true, $replay->replayed(), 'The same key and digest must replay the durable receipt.' );
s06_same( $first->revision_id(), $replay->revision_id(), 'Replay must return the exact first revision ID.' );
s06_same( $counts_after_first, $database->counts(), 'Replay must write zero additional rows.' );
s06_same( $asset_after_first, $database->tables['oj_ai_private_files'][0], 'Replay must not promote the asset twice.' );

$conflicting_document          = $context['document'];
$conflicting_document['title'] = 'Conflicting payload';
$conflicting_key               = s06_command(
	$context['worksheet_id'],
	$context['revision_id'],
	$conflicting_document,
	array( $context['asset_id'] ),
	's06-save-conflict',
	hash( 'sha256', 's06-fingerprint-0001' )
);
s06_failure(
	static fn () => $save->execute( $conflicting_key ),
	Worksheet_Failure::IDEMPOTENCY_CONFLICT,
	'The same storage key with a different request digest must fail closed.'
);
s06_same( $counts_after_first, $database->counts(), 'An idempotency conflict must write zero rows.' );

$unchanged_command = s06_command(
	$context['worksheet_id'],
	$first->revision_id(),
	$context['document'],
	array(),
	's06-save-unchanged',
	hash( 'sha256', 's06-fingerprint-unchanged' )
);
$unchanged         = $save->execute( $unchanged_command );
s06_same( true, $unchanged->unchanged(), 'Equivalent content under a new key must return the current revision.' );
s06_same( false, $unchanged->replayed(), 'The first equivalent-content request is a new accepted command.' );
$counts_after_unchanged = $counts_after_first;
++$counts_after_unchanged['oj_ai_outbox_events'];
s06_same( $counts_after_unchanged, $database->counts(), 'Equivalent content must add only one durable idempotency receipt.' );
s06_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'Equivalent content must not append a domain audit event.' );
s06_same( $asset_after_first, $database->tables['oj_ai_private_files'][0], 'Equivalent content must leave a ready asset unchanged.' );
$unchanged_replay = $save->execute( $unchanged_command );
s06_same( true, $unchanged_replay->replayed(), 'Equivalent-content retry must replay its first receipt.' );
s06_same( $counts_after_unchanged, $database->counts(), 'Equivalent-content replay must write zero rows.' );

$wrong_owner        = s06_context( 'quarantined', 'clean', s05_id( 699 ) );
$wrong_owner_counts = $wrong_owner['database']->counts();
s06_failure(
	static fn () => $wrong_owner['save']->execute(
		s06_command(
			$wrong_owner['worksheet_id'],
			$wrong_owner['revision_id'],
			$wrong_owner['document'],
			array( $wrong_owner['asset_id'] ),
			's06-wrong-owner',
			hash( 'sha256', 's06-wrong-owner' )
		)
	),
	Worksheet_Failure::ASSET_NOT_READY,
	'An asset owned by another aggregate must fail closed.'
);
s06_same( $wrong_owner_counts, $wrong_owner['database']->counts(), 'Wrong-owner asset rejection must roll back every write.' );

$not_clean        = s06_context( 'quarantined', 'pending' );
$not_clean_counts = $not_clean['database']->counts();
s06_failure(
	static fn () => $not_clean['save']->execute(
		s06_command(
			$not_clean['worksheet_id'],
			$not_clean['revision_id'],
			$not_clean['document'],
			array( $not_clean['asset_id'] ),
			's06-not-clean',
			hash( 'sha256', 's06-not-clean' )
		)
	),
	Worksheet_Failure::ASSET_NOT_READY,
	'A staged asset without a clean scan receipt must fail closed.'
);
s06_same( $not_clean_counts, $not_clean['database']->counts(), 'Scan rejection must roll back every write.' );

$outbox_failure                                     = s06_context();
$failure_counts                                     = $outbox_failure['database']->counts();
$failure_root                                       = $outbox_failure['database']->tables['oj_ai_worksheets'][0];
$failure_asset                                      = $outbox_failure['database']->tables['oj_ai_private_files'][0];
$outbox_failure['database']->fail_next_insert_table = 'oj_ai_outbox_events';
s06_failure(
	static fn () => $outbox_failure['save']->execute(
		s06_command(
			$outbox_failure['worksheet_id'],
			$outbox_failure['revision_id'],
			$outbox_failure['document'],
			array( $outbox_failure['asset_id'] ),
			's06-outbox-failure',
			hash( 'sha256', 's06-outbox-failure' )
		)
	),
	Worksheet_Failure::STORAGE_UNAVAILABLE,
	'An outbox insert failure must fail the entire save transaction.'
);
s06_same( $failure_counts, $outbox_failure['database']->counts(), 'Outbox failure must roll back domain, audit, and outbox rows.' );
s06_same( $failure_root, $outbox_failure['database']->tables['oj_ai_worksheets'][0], 'Outbox failure must roll back the root pointer.' );
s06_same( $failure_asset, $outbox_failure['database']->tables['oj_ai_private_files'][0], 'Outbox failure must roll back staged asset promotion.' );

$repository_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Repository.php' );
$asset_source      = file_get_contents( dirname( __DIR__, 2 ) . '/src/Storage/Infrastructure/WordPress_Worksheet_Asset_Binder.php' );
s06_assert( is_string( $repository_source ) && is_string( $asset_source ), 'S06 source files must be readable.' );
s06_assert( ! str_contains( $repository_source . $asset_source, '->prefix' ), 'S06 storage must never derive add-on tables from the WordPress prefix.' );
s06_assert( str_contains( $asset_source, "'oj_ai_private_files'" ), 'The asset adapter must use the literal add-on table.' );
s06_assert( ! str_contains( $repository_source . $asset_source, 'OJ\\Platform\\' ), 'S06 persistence must not depend on platform internals.' );

echo "G2-IMP-02-S06 operations transaction contract passed.\n";

<?php
/**
 * G2-IMP-02-S02 C02 save-draft DTO contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Command;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * C02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function c02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * C02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function c02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * C02 fails.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Throwable
 * @throws \Throwable Propagates validation or dependency failures.
 */
function c02_fails( callable $callback, string $message ): Throwable {
	try {
		$callback();
	} catch ( Throwable $failure ) {
		return $failure;
	}

	throw new RuntimeException( $message );
}

/**
 * C02 public id.
 *
 * @param int $suffix Suffix.
 * @return string
 */
function c02_public_id( int $suffix ): string {
	return '01J' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
}

$canonical               = new Canonical_Json();
$worksheet_id            = c02_public_id( 1 );
$source_revision_id      = c02_public_id( 2 );
$new_revision_id         = c02_public_id( 3 );
$document_id             = c02_public_id( 4 );
$response_id             = c02_public_id( 5 );
$achievement_id          = c02_public_id( 6 );
$receipt_id_one          = c02_public_id( 7 );
$receipt_id_two          = c02_public_id( 8 );
$mapped_node_id          = c02_public_id( 9 );
$temporary_node_id       = 'tmp_12345678-1234-1234-1234-123456789abc';
$document_hash           = hash( 'sha256', 'c02-document' );
$validation_hash         = hash( 'sha256', 'c02-validation' );
$idempotency_fingerprint = hash( 'sha256', 'c02-idempotency-key' );

foreach (
	array(
		$worksheet_id,
		$source_revision_id,
		$new_revision_id,
		$document_id,
		$response_id,
		$achievement_id,
		$receipt_id_one,
		$receipt_id_two,
		$mapped_node_id,
	) as $public_id
) {
	c02_assert( Public_Id::is_valid( $public_id ), 'The C02 fixture must use PublicIdV1 values.' );
}

$teacher_document  = array(
	'schema_id'      => 'oj_ai.worksheet_document',
	'schema_version' => '1.0.0',
	'editor_profile' => 'worksheet_plate@1',
	'document_id'    => $document_id,
	'locale'         => 'ko-KR',
	'title'          => 'C02 draft',
	'nodes'          => array(
		array(
			'id'       => $temporary_node_id,
			'type'     => 'p',
			'children' => array( array( 'text' => 'First' ) ),
		),
		array(
			'id'       => c02_public_id( 10 ),
			'type'     => 'p',
			'children' => array( array( 'text' => 'Second' ) ),
		),
	),
	'settings'       => array(),
);
$achievement_links = array(
	array(
		'scope'              => 'response',
		'response_id'        => $response_id,
		'standard_public_id' => $achievement_id,
		'alignment_type'     => 'primary',
	),
);
$receipt_ids       = array( $receipt_id_one, $receipt_id_two );

$command_defaults = array(
	'actor_user_id'            => 42,
	'worksheet_id'             => $worksheet_id,
	'command_id'               => 'command-c02-0001',
	'expected_state'           => 'draft',
	'expected_revision_id'     => $source_revision_id,
	'teacher_document'         => $teacher_document,
	'achievement_links'        => $achievement_links,
	'staged_asset_receipt_ids' => $receipt_ids,
	'idempotency_fingerprint'  => $idempotency_fingerprint,
	'correlation_id'           => 'correlation-c02-0001',
);

/**
 * Worksheet save draft c02 contract.
 *
 * @param array<string,mixed> $overrides
 */
$make_command = static function ( array $overrides = array() ) use ( $canonical, $command_defaults ): Save_Worksheet_Draft_Command {
	$values = array_replace( $command_defaults, $overrides );
	return new Save_Worksheet_Draft_Command(
		$values['actor_user_id'],
		$values['worksheet_id'],
		$values['command_id'],
		$values['expected_state'],
		$values['expected_revision_id'],
		$values['teacher_document'],
		$values['achievement_links'],
		$values['staged_asset_receipt_ids'],
		$values['idempotency_fingerprint'],
		$values['correlation_id'],
		$canonical
	);
};

$command = $make_command();
c02_same( 42, $command->actor_user_id(), 'C02 must retain trusted actor metadata.' );
c02_same( $worksheet_id, $command->worksheet_id(), 'C02 must retain the path worksheet ID.' );
c02_same( 'command-c02-0001', $command->command_id(), 'C02 must retain command_id.' );
c02_same( 'draft', $command->expected_state(), 'C02 must retain expected_state.' );
c02_same( $source_revision_id, $command->expected_revision_id(), 'C02 must use expected_revision_id.' );
c02_same( $teacher_document, $command->teacher_document(), 'C02 must retain teacher_document.' );
c02_same( $achievement_links, $command->achievement_links(), 'C02 must retain achievement links.' );
c02_same( $receipt_ids, $command->staged_asset_receipt_ids(), 'C02 must retain staged receipts.' );
c02_same( 'correlation-c02-0001', $command->correlation_id(), 'C02 must retain the server correlation ID.' );
c02_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $command->request_digest() ), 'C02 request digest must be SHA-256.' );
c02_assert(
	strlen( $canonical->encode( $teacher_document ) ) <= Save_Worksheet_Draft_Command::MAXIMUM_DOCUMENT_BYTES,
	'The valid C02 document must fit the canonical byte limit.'
);

$reordered_document = array_reverse( $teacher_document, true );
$reordered_command  = $make_command( array( 'teacher_document' => $reordered_document ) );
c02_same( $command->request_digest(), $reordered_command->request_digest(), 'JSON object key order must not change the C02 digest.' );

$different_list_order = $make_command(
	array( 'staged_asset_receipt_ids' => array_reverse( $receipt_ids ) )
);
c02_assert(
	! hash_equals( $command->request_digest(), $different_list_order->request_digest() ),
	'JSON list order must remain significant in the C02 digest.'
);

$different_correlation = $make_command( array( 'correlation_id' => 'correlation-c02-0002' ) );
c02_same( $command->request_digest(), $different_correlation->request_digest(), 'Correlation metadata must not alter the request digest.' );
c02_same(
	hash( 'sha256', '42|worksheet.save-draft|' . $worksheet_id . '|' . $idempotency_fingerprint ),
	$command->idempotency_storage_key(),
	'C02 storage key must be scoped by actor, operation, worksheet, and key fingerprint.'
);
c02_assert(
	! hash_equals( $command->idempotency_storage_key(), $make_command( array( 'actor_user_id' => 43 ) )->idempotency_storage_key() ),
	'C02 idempotency keys must be actor-scoped.'
);
c02_assert(
	! hash_equals(
		$command->idempotency_storage_key(),
		$make_command( array( 'worksheet_id' => c02_public_id( 11 ) ) )->idempotency_storage_key()
	),
	'C02 idempotency keys must be worksheet-scoped.'
);

$invalid_commands = array(
	'actor'              => array( 'actor_user_id' => 0 ),
	'worksheet ID'       => array( 'worksheet_id' => 'bad' ),
	'command token'      => array( 'command_id' => 'short' ),
	'archived state'     => array( 'expected_state' => 'archived' ),
	'expected revision'  => array( 'expected_revision_id' => 'bad' ),
	'fingerprint'        => array( 'idempotency_fingerprint' => 'bad' ),
	'correlation'        => array( 'correlation_id' => 'bad value' ),
	'empty document'     => array( 'teacher_document' => array() ),
	'list document'      => array( 'teacher_document' => array( 'not-an-object' ) ),
	'links object'       => array( 'achievement_links' => array( 'link' => $achievement_links[0] ) ),
	'link list item'     => array( 'achievement_links' => array( array( 'not-an-object' ) ) ),
	'scalar link item'   => array( 'achievement_links' => array( 'not-an-object' ) ),
	'receipts object'    => array( 'staged_asset_receipt_ids' => array( 'receipt' => $receipt_id_one ) ),
	'duplicate receipts' => array( 'staged_asset_receipt_ids' => array( $receipt_id_one, $receipt_id_one ) ),
	'float value'        => array( 'teacher_document' => array_replace( $teacher_document, array( 'score' => 1.5 ) ) ),
);
foreach ( $invalid_commands as $label => $overrides ) {
	$failure = c02_fails(
		static fn () => $make_command( $overrides ),
		'C02 must reject invalid ' . $label . '.'
	);
	c02_assert( $failure instanceof InvalidArgumentException, 'Invalid C02 ' . $label . ' must use InvalidArgumentException.' );
}

$oversized_document = array(
	'body' => str_repeat( 'a', Save_Worksheet_Draft_Command::MAXIMUM_DOCUMENT_BYTES ),
);
c02_assert(
	c02_fails(
		static fn () => $make_command( array( 'teacher_document' => $oversized_document ) ),
		'C02 must reject a canonical document over 2 MiB.'
	) instanceof InvalidArgumentException,
	'C02 oversize failure must use InvalidArgumentException.'
);

$result_defaults = array(
	'worksheet_id'       => $worksheet_id,
	'revision_id'        => $new_revision_id,
	'revision_no'        => 2,
	'revision_state'     => 'draft',
	'source_revision_id' => $source_revision_id,
	'document_id'        => $document_id,
	'document_hash'      => $document_hash,
	'validation_hash'    => $validation_hash,
	'title'              => 'C02 draft',
	'worksheet_state'    => 'draft',
	'saved_at_utc'       => '2026-08-20 12:34:56',
	'temporary_id_map'   => array( $temporary_node_id => $mapped_node_id ),
	'replayed'           => false,
	'unchanged'          => false,
);

/**
 * Worksheet save draft c02 contract.
 *
 * @param array<string,mixed> $overrides
 */
$make_result = static function ( array $overrides = array() ) use ( $result_defaults ): Save_Worksheet_Draft_Result {
	$values = array_replace( $result_defaults, $overrides );
	return new Save_Worksheet_Draft_Result(
		$values['worksheet_id'],
		$values['revision_id'],
		$values['revision_no'],
		$values['revision_state'],
		$values['source_revision_id'],
		$values['document_id'],
		$values['document_hash'],
		$values['validation_hash'],
		$values['title'],
		$values['worksheet_state'],
		$values['saved_at_utc'],
		$values['temporary_id_map'],
		$values['replayed'],
		$values['unchanged']
	);
};

$result = $make_result();
c02_same( $worksheet_id, $result->worksheet_id(), 'C02 result must retain worksheet ID.' );
c02_same( $new_revision_id, $result->current_revision_id(), 'C02 result must move the root pointer to its receipt revision.' );
c02_same( $new_revision_id, $result->revision_id(), 'C02 result must expose the receipt revision.' );
c02_same( 2, $result->revision_no(), 'C02 result must expose revision_no.' );
c02_same( 'draft', $result->revision_state(), 'C02 saves an immutable draft revision.' );
c02_same( $source_revision_id, $result->source_revision_id(), 'C02 result must expose its source revision.' );
c02_same( $document_id, $result->document_id(), 'C02 result must expose document ID.' );
c02_same( $document_hash, $result->document_hash(), 'C02 result must expose document hash.' );
c02_same( $validation_hash, $result->validation_hash(), 'C02 result must expose validation hash.' );
c02_same( 'C02 draft', $result->title(), 'C02 result must expose the normalized title.' );
c02_same( 'draft', $result->worksheet_state(), 'C02 result must expose root state.' );
c02_same( '2026-08-20 12:34:56', $result->saved_at_utc(), 'C02 result must expose UTC save time.' );
c02_same( array( $temporary_node_id => $mapped_node_id ), $result->temporary_id_map(), 'C02 result must expose temporary ID replacements.' );
c02_same( false, $result->replayed(), 'A first C02 result must not be a replay.' );
c02_same( false, $result->unchanged(), 'A changed C02 result must not be marked unchanged.' );
c02_same( 201, $result->http_status(), 'A newly appended C02 revision must use HTTP 201.' );

$replayed_result = $make_result( array( 'replayed' => true ) );
c02_same( true, $replayed_result->replayed(), 'An idempotent C02 retry must be marked replayed.' );
c02_same( 201, $replayed_result->http_status(), 'A replay must preserve the original changed receipt status.' );

$unchanged_result = $make_result(
	array(
		'revision_id'      => $source_revision_id,
		'revision_state'   => 'approved',
		'temporary_id_map' => array(),
		'unchanged'        => true,
	)
);
c02_same( true, $unchanged_result->unchanged(), 'A same-hash C02 save must be marked unchanged.' );
c02_same( 200, $unchanged_result->http_status(), 'A same-hash C02 save must use HTTP 200.' );
c02_same( 'approved', $unchanged_result->revision_state(), 'A same-hash C02 save must preserve the current receipt state.' );

$invalid_results = array(
	'worksheet ID'       => array( 'worksheet_id' => 'bad' ),
	'revision number'    => array( 'revision_no' => 0 ),
	'revision state'     => array( 'revision_state' => 'retired' ),
	'new approved state' => array( 'revision_state' => 'approved' ),
	'document hash'      => array( 'document_hash' => 'bad' ),
	'validation hash'    => array( 'validation_hash' => 'bad' ),
	'title'              => array( 'title' => '' ),
	'worksheet state'    => array( 'worksheet_state' => 'archived' ),
	'save time'          => array( 'saved_at_utc' => '2026-08-20T12:34:56Z' ),
	'temporary key'      => array( 'temporary_id_map' => array( 'tmp_bad' => $mapped_node_id ) ),
	'temporary value'    => array( 'temporary_id_map' => array( $temporary_node_id => 'bad' ) ),
	'changed relation'   => array( 'revision_id' => $source_revision_id ),
	'unchanged relation' => array( 'unchanged' => true ),
);
foreach ( $invalid_results as $label => $overrides ) {
	$failure = c02_fails(
		static fn () => $make_result( $overrides ),
		'C02 result must reject invalid ' . $label . '.'
	);
	c02_assert( $failure instanceof InvalidArgumentException, 'Invalid C02 result ' . $label . ' must use InvalidArgumentException.' );
}

$second_temporary_id = 'tmp_abcdefab-cdef-cdef-cdef-abcdefabcdef';
c02_assert(
	c02_fails(
		static fn () => $make_result(
			array(
				'temporary_id_map' => array(
					$temporary_node_id   => $mapped_node_id,
					$second_temporary_id => $mapped_node_id,
				),
			)
		),
		'C02 result must reject duplicate stable mapped IDs.'
	) instanceof InvalidArgumentException,
	'C02 duplicate stable ID failure must use InvalidArgumentException.'
);

$revision_conflict = Worksheet_Failure::revision_conflict(
	$source_revision_id,
	$new_revision_id,
	$document_hash
);
c02_same( Worksheet_Failure::REVISION_CONFLICT, $revision_conflict->reason(), 'C02 stale revision must have a stable application reason.' );
c02_same(
	array(
		'expected_revision_id'  => $source_revision_id,
		'current_revision_id'   => $new_revision_id,
		'current_revision_hash' => $document_hash,
	),
	$revision_conflict->safe_context(),
	'C02 conflict context must expose only the three frozen recovery values.'
);
c02_same( array(), Worksheet_Failure::idempotency_conflict()->safe_context(), 'Idempotency conflict must not leak context.' );
c02_assert(
	c02_fails(
		static fn () => Worksheet_Failure::revision_conflict( $source_revision_id, $new_revision_id, 'bad' ),
		'C02 conflict must reject an invalid current hash.'
	) instanceof InvalidArgumentException,
	'C02 conflict hash validation must fail safely.'
);

fwrite( STDOUT, "G2-IMP-02-S02 C02 save-draft DTO contract passed.\n" );

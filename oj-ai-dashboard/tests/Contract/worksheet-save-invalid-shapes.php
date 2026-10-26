<?php
/**
 * Regression: malformed persistence projections fail before unsafe conversion.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/worksheet-revision-projection-s04-contract.php';

$ojai_canonical_document = $source->document();
$ojai_record             = array(
	'actor_user_id'               => 42,
	'command_id'                  => 'flow-quality-save-command',
	'correlation_id'              => 'flow-quality-save-correlation',
	'saved_at_utc'                => '2026-09-11 10:00:00',
	'idempotency_storage_key'     => hash( 'sha256', 'flow-quality-save-key' ),
	'request_digest'              => hash( 'sha256', 'flow-quality-save-request' ),
	'worksheet_public_id'         => s04_id(),
	'expected_state'              => 'draft',
	'expected_revision_public_id' => s04_id(),
	'revision_public_id'          => s04_id(),
	'audit_public_id'             => s04_id(),
	'outbox_public_id'            => s04_id(),
	'title'                       => $ojai_canonical_document['title'],
	'locale'                      => $ojai_canonical_document['locale'],
	'document_id'                 => $ojai_canonical_document['document_id'],
	'document_json'               => $source->document_json(),
	'document_hash'               => $source->document_hash(),
	'validation_json'             => $source->validation_json(),
	'validation_hash'             => $source->validation_hash(),
	'content_hash'                => hash(
		'sha256',
		$canonical_json->encode(
			array(
				'document_hash'                     => $source->document_hash(),
				'worksheet_achievement_link_hashes' => array_column( $projection->worksheet_achievement_links(), 'content_hash' ),
				'question_achievement_link_hashes'  => array_column( $projection->question_achievement_links(), 'content_hash' ),
			)
		)
	),
	'temporary_id_map'            => $source->temporary_id_map(),
	'questions'                   => $projection->questions(),
	'worksheet_achievement_links' => $projection->worksheet_achievement_links(),
	'question_achievement_links'  => $projection->question_achievement_links(),
	'asset_bindings'              => $projection->asset_bindings(),
	'projection_json'             => $projection->projection_json(),
	'staged_asset_receipt_ids'    => array_column( $projection->asset_bindings(), 'asset_id' ),
	'projection_hash'             => $projection->projection_hash(),
);
$ojai_validator          = new \OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Worksheet_Draft_Save_Record_Validator( $canonical_json );
$ojai_validator->assert_valid( $ojai_record );
$ojai_invalid_records = array();
$ojai_rejected        = 0;
foreach ( array( array(), new stdClass(), 1 ) as $ojai_invalid_id ) {
	$ojai_bad_record                                  = $ojai_record;
	$ojai_bad_record['asset_bindings'][0]['asset_id'] = $ojai_invalid_id;
	$ojai_invalid_records[]                           = $ojai_bad_record;
}
foreach ( array( 'questions', 'worksheet_achievement_links', 'question_achievement_links', 'asset_bindings', 'staged_asset_receipt_ids' ) as $ojai_field ) {
	$ojai_bad_record                = $ojai_record;
	$ojai_bad_record[ $ojai_field ] = array( null );
	$ojai_invalid_records[]         = $ojai_bad_record;
}
set_error_handler(
	static function ( int $severity, string $message ): never {
		throw new ErrorException( $message, 0, $severity );
	}
);
try {
	foreach ( $ojai_invalid_records as $ojai_bad_record ) {
		try {
			$ojai_validator->assert_valid( $ojai_bad_record );
			throw new RuntimeException( 'Malformed persistence record was accepted.' );
		} catch ( InvalidArgumentException ) {
			++$ojai_rejected;
		}
	}
} finally {
	restore_error_handler();
}
if ( 8 !== $ojai_rejected ) {
	throw new RuntimeException( 'Not all malformed persistence records were rejected.' );
}
echo "Worksheet save invalid shapes: valid projection accepted; 8 malformed projections rejected without warnings.\n";

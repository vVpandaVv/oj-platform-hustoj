<?php
/**
 * G5-IMP-02-S03 authorized source-manifest handoff contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

require __DIR__ . DIRECTORY_SEPARATOR . 'report-snapshot-query-g5-imp-02-s02.php';

$canonical       = new Canonical_Json();
$fixture         = g5_snapshot_q22_record( $canonical );
$query           = new G5_Snapshot_Q22_Query( $fixture['record'], $canonical );
$objects         = new G5_Snapshot_Q22_Objects();
$reader          = new Read_Report_Snapshot( $query, $objects, new Report_Snapshot_Profile( $canonical ), $canonical );
$current_context = Context_Authorization_Result::allowed(
	'class',
	'class-g5-snapshot',
	'active',
	'class-g5-snapshot-r99',
	'educator',
	'correlation-manifest-s03'
);

$stored_manifest = json_decode( $fixture['record']['source_manifest_json'], true, 128, JSON_THROW_ON_ERROR );
$result          = $reader->read_source_manifest( 77, $fixture['record']['public_id'], $current_context );
$payload         = $result->payload();
$expected_keys   = array(
	'authorized_worksheet_count',
	'context',
	'entry_count',
	'filter_hash',
	'generated_at_utc',
	'projection_hash',
	'snapshot_hash',
	'snapshot_id',
	'source_count',
	'source_manifest',
	'source_manifest_hash',
	'source_manifest_json',
);
$actual_keys     = array_keys( $payload );
sort( $actual_keys, SORT_STRING );
g5_snapshot_q22_same( $expected_keys, $actual_keys, 'S03 internal manifest handoff allowlist drifted.' );
g5_snapshot_q22_same( $stored_manifest, $payload['source_manifest'], 'S03 did not return the exact validated source manifest.' );
g5_snapshot_q22_same( $fixture['record']['source_manifest_json'], $payload['source_manifest_json'], 'S03 changed the canonical source manifest bytes.' );
g5_snapshot_q22_same( $fixture['record']['source_manifest_hash'], $payload['source_manifest_hash'], 'S03 source manifest hash drifted.' );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $payload['snapshot_hash'], 'S03 snapshot identity linkage drifted.' );
g5_snapshot_q22_same( $fixture['record']['filter_hash'], $payload['filter_hash'], 'S03 filter hash linkage drifted.' );
g5_snapshot_q22_same( $fixture['record']['projection_hash'], $payload['projection_hash'], 'S03 projection hash linkage drifted.' );
g5_snapshot_q22_same(
	array(
		'type'              => 'class',
		'id'                => 'class-g5-snapshot',
		'snapshot_revision' => 'class-g5-snapshot-r1',
	),
	$payload['context'],
	'S03 must preserve the snapshot Context revision while authorizing the current revision.'
);
g5_snapshot_q22_same( 2, $payload['entry_count'], 'S03 manifest entry count drifted.' );
g5_snapshot_q22_same( 6, $payload['source_count'], 'S03 owner source count drifted.' );
g5_snapshot_q22_same( 2, $payload['authorized_worksheet_count'], 'S03 worksheet authorization count drifted.' );
g5_snapshot_q22_same( $fixture['worksheet_ids'], $objects->calls, 'S03 did not reauthorize every worksheet before manifest handoff.' );
g5_snapshot_q22_same( $fixture['record']['source_manifest_json'], $result->source_manifest_json(), 'S03 exact manifest byte accessor drifted.' );
g5_snapshot_q22_same( $fixture['record']['source_manifest_hash'], $result->source_manifest_hash(), 'S03 manifest hash accessor drifted.' );
g5_snapshot_q22_same( $fixture['snapshot_hash'], $result->snapshot_hash(), 'S03 snapshot hash accessor drifted.' );

foreach ( $payload['source_manifest']['entries'] as $entry ) {
	g5_snapshot_q22_same(
		array( 'Worksheet', 'AssignmentSubmission', 'Assessment' ),
		array_column( $entry['sources'], 'owner' ),
		'S03 owner order must remain canonical.'
	);
}

$public_payload = $reader->read( 77, $fixture['record']['public_id'], $current_context )->payload();
g5_snapshot_q22_assert( ! array_key_exists( 'source_manifest', $public_payload ), 'S03 exposed the internal manifest through Q22.' );
g5_snapshot_q22_assert( ! array_key_exists( 'source_manifest_json', $public_payload ), 'S03 exposed canonical manifest bytes through Q22.' );
g5_snapshot_q22_same( 1, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'S03 must not register another REST route.' );

$revoked_objects          = new G5_Snapshot_Q22_Objects();
$revoked_objects->deny_id = $fixture['worksheet_ids'][1];
try {
	( new Read_Report_Snapshot( $query, $revoked_objects, new Report_Snapshot_Profile( $canonical ), $canonical ) )
		->read_source_manifest( 77, $fixture['record']['public_id'], $current_context );
	throw new RuntimeException( 'S03 disclosed provenance after worksheet source access was revoked.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED, $failure->reason(), 'S03 source revocation must fail closed.' );
}

$noncanonical                                    = $fixture['record'];
$noncanonical['source_manifest_json']            = json_encode( $stored_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
$noncanonical['source_manifest_hash']            = hash( 'sha256', $noncanonical['source_manifest_json'] );
$noncanonical['source_watermark']                = $noncanonical['source_manifest_hash'];
$noncanonical_projection                         = json_decode( $noncanonical['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$noncanonical_projection['source_manifest_hash'] = $noncanonical['source_manifest_hash'];
$noncanonical['projection_json']                 = $canonical->encode( $noncanonical_projection );
$noncanonical['projection_hash']                 = hash( 'sha256', $noncanonical['projection_json'] );
try {
	( new Read_Report_Snapshot( new G5_Snapshot_Q22_Query( $noncanonical, $canonical ), new G5_Snapshot_Q22_Objects(), new Report_Snapshot_Profile( $canonical ), $canonical ) )
		->read_source_manifest( 77, $noncanonical['public_id'], $current_context );
	throw new RuntimeException( 'S03 accepted noncanonical manifest bytes with internally consistent hashes.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::STORED_DATA_INVALID, $failure->reason(), 'S03 noncanonical manifest must fail closed.' );
}

$unlinked          = $fixture['record'];
$unlinked_manifest = $stored_manifest;
$unlinked_manifest['entries'][0]['sources'][0]['hash'] = hash( 'sha256', 'unlinked-worksheet-source' );
$unlinked['source_manifest_json']                      = $canonical->encode( $unlinked_manifest );
$unlinked['source_manifest_hash']                      = hash( 'sha256', $unlinked['source_manifest_json'] );
$unlinked['source_watermark']                          = $unlinked['source_manifest_hash'];
$unlinked_projection                                   = json_decode( $unlinked['projection_json'], true, 128, JSON_THROW_ON_ERROR );
$unlinked_projection['source_manifest_hash']           = $unlinked['source_manifest_hash'];
$unlinked['projection_json']                           = $canonical->encode( $unlinked_projection );
$unlinked['projection_hash']                           = hash( 'sha256', $unlinked['projection_json'] );
try {
	( new Read_Report_Snapshot( new G5_Snapshot_Q22_Query( $unlinked, $canonical ), new G5_Snapshot_Q22_Objects(), new Report_Snapshot_Profile( $canonical ), $canonical ) )
		->read_source_manifest( 77, $unlinked['public_id'], $current_context );
	throw new RuntimeException( 'S03 accepted a manifest source hash not linked by the immutable projection.' );
} catch ( Report_Snapshot_Query_Failure $failure ) {
	g5_snapshot_q22_same( Report_Snapshot_Query_Failure::STORED_DATA_INVALID, $failure->reason(), 'S03 projection-to-manifest linkage must fail closed.' );
}

$presentation_source = '';
foreach ( glob( dirname( __DIR__, 2 ) . '/src/Reporting/Report/Presentation/*.php' ) as $path ) {
	$presentation_source .= (string) file_get_contents( $path );
}
g5_snapshot_q22_assert( ! str_contains( $presentation_source, 'read_source_manifest' ), 'S03 internal manifest handoff must not have a presentation adapter.' );

$runtime_files = array(
	dirname( __DIR__, 2 ) . '/src/Reporting/Report/Application/Read_Report_Snapshot.php',
	dirname( __DIR__, 2 ) . '/src/Reporting/Report/Application/Read_Report_Source_Manifest_Result.php',
);
foreach ( $runtime_files as $path ) {
	$source = (string) file_get_contents( $path );
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_snapshot_q22_assert( ! str_contains( $source, $forbidden ), 'S03 crossed a frozen boundary: ' . $forbidden );
	}
}

echo "G5-IMP-02-S03 passed: exact canonical source manifest bytes and hashes stay snapshot-linked, currently authorized and internal-only.\n";

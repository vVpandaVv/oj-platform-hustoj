<?php
/**
 * G2-IMP-04-S03 renderer parity and private projection contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'worksheet-preview-s02.php';

use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision;
use OJAI\Dashboard\Worksheet\Authoring\Application\Validate_Worksheet_Revision_Command;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Asset_Readiness_Port_Interface;

/**
 * G2-IMP-04-S03 renderer parity and private projection contract.
 */
final class S19_Blocked_Asset_Readiness implements Worksheet_Asset_Readiness_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $worksheet_id Worksheet public identifier.
	 * @param array<int,string> $asset_ids Asset ids.
	 * @return array
	 */
	public function inspect( int $actor_user_id, string $worksheet_id, array $asset_ids ): array {
		return array(
			'ready'      => false,
			'blockers'   => array( array( 'code' => 'worksheet_asset_not_ready' ) ),
			'statistics' => array(
				'asset_count'       => count( $asset_ids ),
				'ready_asset_count' => 0,
				'total_asset_bytes' => 1200,
			),
		);
	}
}

/**
 * S19 projection.
 *
 * @param array<string,mixed> $projection Projection.
 * @param string              $kind Kind.
 * @param string              $profile Profile.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @return Worksheet_Preview_Projection
 */
function s19_projection( array $projection, string $kind, string $profile, Canonical_Json $canonical ): Worksheet_Preview_Projection {
	return new Worksheet_Preview_Projection(
		$kind,
		$profile,
		$projection,
		hash( 'sha256', $canonical->encode( $projection ) )
	);
}

/**
 * S19 refresh student.
 *
 * @param array<string,mixed> $projection Projection.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @return Worksheet_Preview_Projection
 */
function s19_refresh_student( array $projection, Canonical_Json $canonical ): Worksheet_Preview_Projection {
	$projection['student_document']['payload_hash'] = hash(
		'sha256',
		$canonical->encode( $projection['student_document']['payload'] )
	);
	return s19_projection( $projection, 'student', 'student', $canonical );
}

/**
 * S19 refresh print.
 *
 * @param array<string,mixed> $projection Projection.
 * @param string              $profile Profile.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @return Worksheet_Preview_Projection
 */
function s19_refresh_print( array $projection, string $profile, Canonical_Json $canonical ): Worksheet_Preview_Projection {
	$projection['print_projection']['payload_hash'] = hash(
		'sha256',
		$canonical->encode( $projection['print_projection']['payload'] )
	);
	return s19_projection( $projection, 'print', $profile, $canonical );
}

$inspector     = new Worksheet_Projection_Safety_Inspector( $canonical );
$print_student = $projector->print( $receipt, 'student_blank' );
$report        = $inspector->inspect( $receipt, $student, $print_student );
s18_same( true, $report['ready'], 'S03 safety inspection must pass exact S02 projections.' );
s18_same( 'passed', $report['renderer_parity']['status'], 'Renderer parity must pass.' );
s18_same( 'passed', $report['private_field_scan']['status'], 'Private field scan must pass.' );
s18_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $report['renderer_parity']['manifest_hash'] ), 'Renderer manifest hash must be deterministic.' );
s18_same( 2, $report['statistics']['renderer_parity_projection_count'], 'Both student and print projections must be compared.' );
s18_same( 0, $report['statistics']['forbidden_field_count'], 'Exact projections must have zero forbidden fields.' );
s18_same( 0, $report['statistics']['private_asset_url_count'], 'Exact projections must have zero private asset URLs.' );
s18_same( 0, $report['statistics']['invalid_asset_reference_count'], 'Exact projections must retain valid private asset IDs.' );

foreach ( array( 'student_blank', 'teacher_blank' ) as $profile ) {
	$profile_report = $inspector->inspect( $receipt, $student, $projector->print( $receipt, $profile ) );
	s18_same( true, $profile_report['ready'], 'Every frozen blank print profile must pass parity.' );
	s18_same( $report['renderer_parity']['manifest_hash'], $profile_report['renderer_parity']['manifest_hash'], 'The semantic manifest must not vary by blank print profile.' );
}

$content_drift = $student->projection();
$content_drift['student_document']['payload']['title'] = '변조된 제목';
$content_report                                        = $inspector->inspect( $receipt, s19_refresh_student( $content_drift, $canonical ), $print_student );
s18_same( 'blocked', $content_report['renderer_parity']['status'], 'Student content drift must block renderer parity.' );
s18_same( 'passed', $content_report['private_field_scan']['status'], 'Public content drift is not a private-field leak.' );

$private_drift = $student->projection();
$private_drift['student_document']['payload']['grading'] = array( 'correct_answer' => $option_two );
$private_report = $inspector->inspect( $receipt, s19_refresh_student( $private_drift, $canonical ), $print_student );
s18_same( 'blocked', $private_report['renderer_parity']['status'], 'Extra private fields must also break exact parity.' );
s18_same( 'blocked', $private_report['private_field_scan']['status'], 'Nested grading data must block the private scan.' );
s18_assert( $private_report['statistics']['forbidden_field_count'] >= 2, 'Every forbidden key depth must be counted.' );
s18_same( 'worksheet_private_projection_leak', $private_report['blockers'][1]['code'], 'Private leak blocker code drifted.' );

$asset_url_drift = $student->projection();
$asset_url_drift['student_document']['payload']['nodes'][2]['asset_id'] = 'https://private.example.test/signed/file';
$asset_report = $inspector->inspect( $receipt, s19_refresh_student( $asset_url_drift, $canonical ), $print_student );
s18_same( 'blocked', $asset_report['private_field_scan']['status'], 'A private asset URL in asset_id must block.' );
s18_assert( $asset_report['statistics']['private_asset_url_count'] >= 1, 'Private asset URL must be counted.' );
s18_assert( $asset_report['statistics']['invalid_asset_reference_count'] >= 1, 'URL-shaped asset IDs must be rejected.' );

$print_drift = $print_student->projection();
$print_drift['print_projection']['payload']['layout']['orientation'] = 'landscape';
$print_report = $inspector->inspect( $receipt, $student, s19_refresh_print( $print_drift, 'student_blank', $canonical ) );
s18_same( 'blocked', $print_report['renderer_parity']['status'], 'Print layout drift must block renderer parity.' );
s18_same( 'passed', $print_report['private_field_scan']['status'], 'Public print layout drift is not a private leak.' );

$safe_link_drift                                       = $student->projection();
$safe_link_drift['student_document']['payload']['url'] = 'https://public.example.test/reference';
$safe_link_report                                      = $inspector->inspect( $receipt, s19_refresh_student( $safe_link_drift, $canonical ), $print_student );
s18_same( 'passed', $safe_link_report['private_field_scan']['status'], 'An ordinary HTTPS link must not be misclassified as a private asset URL.' );

$validator_s19 = new Validate_Worksheet_Revision(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Document_Id_Rewriter(),
	new S18_Asset_Readiness(),
	$canonical,
	static fn (): string => '2026-08-21 16:00:00',
	$projector,
	$inspector
);
$command_s19   = new Validate_Worksheet_Revision_Command(
	42,
	$revision_id,
	'command-s19-validate-0001',
	'draft',
	$revision_id,
	hash( 'sha256', 's19-idempotency' ),
	's19-validation-correlation',
	$canonical
);
$ready         = $validator_s19->execute( $command_s19 );
s18_same( 'ready', $ready->status(), 'C05 must become ready only after every S03 check passes.' );
s18_same( true, $ready->release_ready(), 'C05 release_ready must open after exact S03 inspection.' );
s18_same( 'passed', $ready->checks()[6]['status'], 'C05 renderer parity check must pass.' );
s18_same( 'passed', $ready->checks()[7]['status'], 'C05 private field scan must pass.' );
s18_same( array(), $ready->blockers(), 'A ready worksheet must have no blockers.' );
s18_same( 0, $ready->statistics()['forbidden_field_count'], 'C05 must expose the S03 private field count.' );
s18_same( $ready->projection_hash(), $validator_s19->execute( $command_s19 )->projection_hash(), 'C05 S03 projection must be deterministic.' );

$blocked_validator = new Validate_Worksheet_Revision(
	$repository,
	new Worksheet_Document_Canonicalizer( $canonical, $ids ),
	new Worksheet_Document_Id_Rewriter(),
	new S19_Blocked_Asset_Readiness(),
	$canonical,
	static fn (): string => '2026-08-21 16:00:00',
	$projector,
	$inspector
);
$blocked           = $blocked_validator->execute( $command_s19 );
s18_same( 'blocked', $blocked->status(), 'Asset blockers must keep C05 closed after S03 passes.' );
s18_same( false, $blocked->release_ready(), 'S03 cannot bypass private asset readiness.' );
s18_same( 'passed', $blocked->checks()[6]['status'], 'Asset failure must not hide renderer parity evidence.' );
s18_same( 'passed', $blocked->checks()[7]['status'], 'Asset failure must not hide private scan evidence.' );

$inspector_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Worksheet/Authoring/Domain/Worksheet_Projection_Safety_Inspector.php' );
s18_assert( is_string( $inspector_source ), 'S03 inspector source must be readable.' );
s18_assert( ! str_contains( $inspector_source, '$wpdb->prefix' ), 'S03 must never use a WordPress table prefix.' );
s18_assert( ! str_contains( $inspector_source, 'unset(' ), 'S03 must independently derive an allowlisted manifest.' );

echo "G2-IMP-04-S03 passed: exact renderer parity, deep private-field scan, asset-ID-only media and fail-closed C05 release readiness.\n";

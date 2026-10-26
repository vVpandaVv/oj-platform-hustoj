<?php
/**
 * G6-IMP-05 boundary and final package acceptance contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );

/**
 * G6 imp05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G6 imp05 source.
 *
 * @param string $root Root.
 * @param string $relative_path Relative path.
 * @return string
 */
function g6_imp05_source( string $root, string $relative_path ): string {
	$source = file_get_contents( $root . '/' . $relative_path );
	g6_imp05_assert( is_string( $source ), 'Acceptance source could not be read: ' . $relative_path );

	return $source;
}

$dimensions = array(
	'G6-IMP-05-S01' => 'platform_internal_dependency_zero',
	'G6-IMP-05-S02' => 'platform_file_change_zero',
	'G6-IMP-05-S03' => 'api_schema_route_fingerprint',
	'G6-IMP-05-S04' => 'html_changelog_consistency',
	'G6-IMP-05-S05' => 'clean_production_zip',
);
g6_imp05_assert( 5 === count( $dimensions ), 'The frozen G6-IMP-05 ledger must remain exactly S01-S05.' );

$runtime_paths = array( $root . '/oj-ai-dashboard.php' );
$iterator      = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$runtime_paths[] = $file->getPathname();
	}
}
foreach ( $runtime_paths as $path ) {
	$source = file_get_contents( $path );
	g6_imp05_assert( is_string( $source ), 'Runtime source could not be read: ' . $path );
	g6_imp05_assert(
		1 !== preg_match( '/CodingPanda\\\\OjPlatform\\\\(?!AddonApi\\\\V1\\\\)/', $source ),
		'Runtime depends on an OJ Platform internal namespace: ' . $path
	);
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'oj-integration-rebuild/oj-platform/src' ) as $forbidden ) {
		g6_imp05_assert( ! str_contains( $source, $forbidden ), 'Runtime crossed a frozen boundary: ' . $path . ' -> ' . $forbidden );
	}
}

$package_metadata = json_decode( g6_imp05_source( $root, 'package.json' ), true, 512, JSON_THROW_ON_ERROR );
$addon_version    = is_array( $package_metadata ) ? (string) ( $package_metadata['version'] ?? '' ) : '';
g6_imp05_assert( 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $addon_version ), 'Package add-on version is invalid.' );

$fingerprint_path = $root . '/resources/contracts/release-boundary-fingerprint-v1.json';
$fingerprint      = json_decode( g6_imp05_source( $root, 'resources/contracts/release-boundary-fingerprint-v1.json' ), true, 512, JSON_THROW_ON_ERROR );
g6_imp05_assert( is_array( $fingerprint ), 'Release boundary fingerprint must decode to an object.' );
g6_imp05_assert( 'oj_ai.release_boundary_fingerprint@1' === ( $fingerprint['contract'] ?? null ), 'Boundary contract version drifted.' );
g6_imp05_assert( 'sha256-path-nul-bytes-nul-v1' === ( $fingerprint['algorithm'] ?? null ), 'Boundary hash algorithm drifted.' );
g6_imp05_assert( $addon_version === ( $fingerprint['addon_version'] ?? null ), 'Boundary add-on version drifted.' );
$platform_addon_api_version = (string) ( $fingerprint['platform_addon_api_version'] ?? '' );
g6_imp05_assert(
	1 === preg_match( '/^\d+\.\d+\.\d+$/D', $platform_addon_api_version )
		&& version_compare( $platform_addon_api_version, '1.1.0', '>=' )
		&& version_compare( $platform_addon_api_version, '2.0.0', '<' ),
	'Platform Addon API version drifted outside the supported >=1.1.0 <2.0.0 range.'
);
g6_imp05_assert(
	array( 'addon_api', 'database_schema', 'rest_routes', 'platform_addon_api' ) === array_keys( $fingerprint['groups'] ?? array() ),
	'Boundary fingerprint groups drifted.'
);
foreach ( $fingerprint['groups'] as $group => $evidence ) {
	g6_imp05_assert( ( $evidence['file_count'] ?? 0 ) > 0, 'Boundary group has no files: ' . $group );
	g6_imp05_assert( count( $evidence['files'] ?? array() ) === $evidence['file_count'], 'Boundary file count drifted: ' . $group );
	g6_imp05_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $evidence['sha256'] ?? '' ) ), 'Boundary hash is invalid: ' . $group );
}
g6_imp05_assert( is_file( $fingerprint_path ), 'Packaged boundary contract is missing.' );

$release_source = g6_imp05_source( $root, 'scripts/build-release.ps1' );
foreach ( array(
	'Release packaging requires source_dirty=false.',
	'$requiredEntries = @(',
	'$forbiddenDirectories',
	'release-manifest.json',
	'source_dirty',
	'source_commit',
	'System.Security.Cryptography.SHA256',
	'.zip.sha256',
	'resources\icons',
	'lucide.svg',
	'System.IO.Compression.ZipArchiveMode',
	'CreateEntry',
	"Replace('\\', '/')",
	"entryName.Contains('\\')",
	'oj-ai-dashboard/oj-ai-dashboard.php',
) as $marker ) {
	g6_imp05_assert( str_contains( $release_source, $marker ), 'Release builder marker is missing: ' . $marker );
}
g6_imp05_assert( ! str_contains( $release_source, 'AllowDirty' ), 'Production packaging must not expose an AllowDirty bypass.' );
g6_imp05_assert( ! str_contains( $release_source, 'Get-FileHash' ), 'Production packaging must use the PowerShell-compatible .NET SHA-256 implementation.' );
g6_imp05_assert( ! str_contains( $release_source, 'Compress-Archive' ), 'Production ZIP entries must use portable forward-slash paths.' );

$gate_source = g6_imp05_source( $root, 'scripts/check-boundary-final-package-g6-imp-05.ps1' );
foreach ( array_keys( $dimensions ) as $dimension ) {
	g6_imp05_assert( str_contains( $gate_source, "'" . $dimension . "'" ), 'Final gate does not name ' . $dimension );
}
foreach ( array(
	'src/AddonApi/V1',
	'fingerprint-release-boundaries.mjs',
	'release-boundary-fingerprint-v1.json',
	'SkipProductionPackage',
	'source_dirty=true',
) as $marker ) {
	g6_imp05_assert( str_contains( $gate_source, $marker ), 'Final gate marker is missing: ' . $marker );
}

$documents = array(
	'docs/ai-assessment/g6-imp-05-boundary-final-package.html',
	'docs/ai-assessment/release-changelog.html',
	'docs/ai-assessment/implementation-roadmap.html',
	'docs/ai-assessment/a-ds-16-cross-validation-freeze.html',
	'docs/ai-assessment/platform-api-implementation-audit.html',
);
foreach ( $documents as $relative_path ) {
	$source = g6_imp05_source( $root, $relative_path );
	foreach ( array( 'G6-IMP-05', 'S05', 'source_dirty=false' ) as $marker ) {
		g6_imp05_assert( str_contains( $source, $marker ), 'Final package HTML marker is missing: ' . $relative_path . ' -> ' . $marker );
	}
}

$package_source = g6_imp05_source( $root, 'package.json' );
$runner_source  = g6_imp05_source( $root, 'scripts/run-tests.mjs' );
foreach ( array( 'check:boundary-final-package-g6', 'check-boundary-final-package-g6-imp-05.ps1' ) as $marker ) {
	g6_imp05_assert( str_contains( $package_source, $marker ), 'Package command marker is missing: ' . $marker );
}
foreach ( array(
	'check-boundary-final-package-g6-imp-05.ps1',
	'-SkipConstituentChecks',
	'-SkipProductionPackage',
	'G6-IMP-05 S01-S04 prepackage checks passed',
) as $marker ) {
	g6_imp05_assert( str_contains( $runner_source, $marker ), 'Aggregate runner marker is missing: ' . $marker );
}

fwrite( STDOUT, "G6-IMP-05 prepackage contract passed: S01-S04 are verified; S05 remains fail-closed until source_dirty=false.\n" );

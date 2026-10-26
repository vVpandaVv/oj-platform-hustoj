<?php
/**
 * Release package boundary contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$root   = dirname( __DIR__, 2 );
$source = file_get_contents( $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-release.ps1' );

if ( ! is_string( $source ) || '' === $source ) {
	throw new RuntimeException( 'Release build script must be readable.' );
}

/**
 * Release boundary assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function release_boundary_assert( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

foreach (
	array(
		"throw 'Release packaging requires source_dirty=false.",
		'$requiredEntries = @(',
		"'oj-ai-dashboard.php'",
		"'uninstall.php'",
		"'src'",
		"'resources\\assets'",
		"'resources\\contracts'",
		"'resources\\css'",
		"'resources\\fonts'",
		"'resources\\icons'",
		"'resources\\js'",
		"'composer.lock'",
		'--no-dev --no-scripts --no-plugins',
		'validate-production-vendor.php',
		'$forbiddenDirectories',
		"'node_modules'",
		'$vendorAutoload',
		'$iconSprite',
		"'resources\\icons\\lucide.svg'",
		"'resources\\plate\\whole-editor\\node_modules'",
		'System.IO.Compression.ZipArchiveMode',
		'CreateEntry',
		"Replace('\\', '/')",
		"entryName.Contains('\\')",
		'oj-ai-dashboard/oj-ai-dashboard.php',
	) as $marker
) {
	release_boundary_assert( str_contains( $source, $marker ), 'Release boundary marker missing: ' . $marker );
}

foreach ( array(
	'[switch] $ValidationPackage',
	'if (-not $ValidationPackage)',
	"'validation-manifest.json'",
	"'oj_ai.validation_package@1'",
	'$releaseManifest.source_dirty = ($dirtyState.Count -gt 0)',
	"\$releaseManifest['production_approved'] = \$false",
	"\$releaseManifest['boundary_approved'] = \$false",
	'Source changed during packaging',
	'Packaged file differs from the source snapshot',
	'Package version must match package.json, the plugin header and runtime constant',
	'Validation packages require a distinct -rc.N version',
	'Production packages require a stable version',
	'Production packaging requires the exact approved release boundary fingerprint',
) as $marker ) {
	release_boundary_assert( str_contains( $source, $marker ), 'Explicit validation package boundary missing: ' . $marker );
}

$required_start = strpos( $source, '$requiredEntries = @(' );
$optional_start = strpos( $source, '$optionalEntries = @(' );
$copy_start     = strpos( $source, 'foreach ($relativePath in $requiredEntries)' );
release_boundary_assert( false !== $required_start && false !== $optional_start && false !== $copy_start, 'Release entry sections must be readable.' );
$required_source = substr( $source, $required_start, $optional_start - $required_start );
$optional_source = substr( $source, $optional_start, $copy_start - $optional_start );
release_boundary_assert( str_contains( $required_source, "'uninstall.php'" ), 'The preservation-only uninstall entry must be required in every release package.' );
release_boundary_assert( ! str_contains( $optional_source, "'uninstall.php'" ), 'The preservation-only uninstall entry must not be optional.' );
release_boundary_assert( ! str_contains( $required_source, "'vendor'" ), 'Development vendor must not be copied into a release.' );

release_boundary_assert( ! str_contains( $source, '$excludeTop' ), 'Release packaging must not use a root-copy exclusion list.' );
release_boundary_assert( ! str_contains( $source, 'Compress-Archive' ), 'Release packaging must not emit Windows-only ZIP entry separators.' );
release_boundary_assert(
	! str_contains( $source, 'Get-ChildItem -LiteralPath $root -Force |' ),
	'Release packaging must not enumerate and copy the repository root.'
);

$dirty_guard = strpos( $source, 'if ($dirtyState.Count -gt 0)' );
$dist_write  = strpos( $source, 'New-Item -ItemType Directory -Path $dist' );
$registration_check = strpos( $source, "& \$php (Join-Path \$root 'tests\\Contract\\addon-api-consumer.php')" );
$staged_registration_check = strpos( $source, "& \$php (Join-Path \$root 'tests\\Contract\\addon-api-consumer.php') \$packageRoot" );
$archive_write = strpos( $source, '$archive = [System.IO.Compression.ZipFile]::Open(' );
release_boundary_assert( false !== $registration_check && $registration_check < $dist_write, 'Source registration must be checked before staging.' );
release_boundary_assert( false !== $staged_registration_check && $staged_registration_check < $archive_write, 'The staged package must pass registration before creating a ZIP.' );
release_boundary_assert( str_contains( $source, "throw 'Add-on registration contract failed; no package was created.'" ), 'Source registration failure must block packaging.' );
release_boundary_assert( str_contains( $source, "throw 'Staged package registration contract failed; no ZIP was created.'" ), 'Staged registration failure must block packaging.' );
release_boundary_assert( false !== $dirty_guard, 'Release source dirty guard must exist.' );
release_boundary_assert( false !== $dist_write, 'Release dist creation must exist.' );
release_boundary_assert( $dirty_guard < $dist_write, 'Release dirty guard must run before any dist write.' );

fwrite( STDOUT, "Release package boundary contract passed.\n" );

<?php
/**
 * Retired implementation must not return to the runtime or asset graph.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
require $root . '/vendor/autoload.php';
$inventory = json_decode( (string) file_get_contents( $root . '/tests/fixtures/retired-runtime.json' ), true, 512, JSON_THROW_ON_ERROR );
$retired   = array();
foreach ( $inventory['php'] as $file ) {
	$retired[ 'OJAI\\Dashboard\\' . str_replace( '/', '\\', substr( $file, 4, -4 ) ) ] = $file;
}
foreach ( array_merge( $inventory['php'], $inventory['assets'] ) as $file ) {
	if ( file_exists( $root . '/' . $file ) ) {
		throw new RuntimeException( 'Retired runtime was restored: ' . $file );
	}
}
$parser = ( new PhpParser\ParserFactory() )->createForNewestSupportedVersion();
$finder = new PhpParser\NodeFinder();
$count  = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $entry ) {
	if ( ! $entry->isFile() || 'php' !== $entry->getExtension() ) {
		continue;
	}
	++$count;
	$nodes     = $parser->parse( (string) file_get_contents( $entry->getPathname() ) );
	$traverser = new PhpParser\NodeTraverser( new PhpParser\NodeVisitor\NameResolver() );
	$nodes     = $traverser->traverse( $nodes );
	foreach ( $finder->findInstanceOf( $nodes, PhpParser\Node\Name::class ) as $name ) {
		if ( isset( $retired[ $name->toString() ] ) ) {
			throw new RuntimeException( $entry->getPathname() . ' references ' . $name->toString() );
		}
	}
	foreach ( $finder->findInstanceOf( $nodes, PhpParser\Node\Scalar\String_::class ) as $literal ) {
		if ( isset( $retired[ ltrim( $literal->value, '\\' ) ] ) || in_array( $literal->value, $inventory['assets'], true ) ) {
			throw new RuntimeException( $entry->getPathname() . ' registers a retired dependency: ' . $literal->value );
		}
	}
}
$manifest = (string) file_get_contents( $root . '/src/Platform/Addon_Manifest.php' );
preg_match_all( '~[\'"](resources/[^\'"]+\.(?:js|css))[\'"]~', $manifest, $assets );
foreach ( $assets[1] as $asset ) {
	if ( ! is_file( $root . '/' . $asset ) ) {
		throw new RuntimeException( 'Registered asset is missing: ' . $asset );
	}
}
$author = (string) file_get_contents( $root . '/resources/js/worksheet-authoring-workbench.js' );
if ( str_contains( $author, 'renderLegacyDocumentOutline' ) ) {
	throw new RuntimeException( 'Unused outline renderer was restored.' );
}
// These are live compatibility boundaries, not the retired standalone runtime.
foreach ( array(
	'src/Operations/LegacyMigration/Infrastructure/Legacy_Migration_Worker_Module.php',
	'src/Worksheet/Plate_Document_Service.php',
	'src/Storage/Infrastructure/Local_Private_Storage_Root.php',
	'src/Submission/Student/Infrastructure/WordPress_Bound_Attachment_Retention_Repair.php',
) as $required ) {
	if ( ! is_file( $root . '/' . $required ) ) {
		throw new RuntimeException( 'Required data compatibility was removed: ' . $required );
	}
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only numeric regression summary, not browser HTML.
echo 'Retired runtime PASS: ', count( $inventory['php'] ), ' PHP files + ', count( $inventory['assets'] ),
	' assets absent; ', $count, ' remaining PHP files reference no retired class; active assets exist.', PHP_EOL;
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

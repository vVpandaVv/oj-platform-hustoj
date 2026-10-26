<?php
/**
 * OJ Console learning-menu integration compatibility contract.
 *
 * The add-on contributes its console menu below the platform-owned `classes`
 * anchor. Core learning pages therefore must identify their current
 * menu with keys from config/menus.php so ConsoleShell expands that anchor.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$addonRoot    = dirname( __DIR__, 2 );
$platformRoot = getenv( 'OJ_PLATFORM_SOURCE' );
if ( ! is_string( $platformRoot ) || '' === ( $platformRoot ) ) {
	$platformRoot = dirname( $addonRoot ) . DIRECTORY_SEPARATOR . 'oj-integration-rebuild'
		. DIRECTORY_SEPARATOR . 'oj-platform';
}
$platformRoot = realpath( $platformRoot );

if ( ! is_string( $platformRoot ) ) {
	fwrite( STDERR, "Adjacent OJ Platform source was not found.\n" );
	exit( 1 );
}

$menuConfig = $platformRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'menus.php';
if ( ! is_file( $menuConfig ) ) {
	fwrite( STDERR, "OJ Platform config/menus.php was not found.\n" );
	exit( 1 );
}

$menus = require $menuConfig;
if ( ! is_array( $menus ) ) {
	fwrite( STDERR, "OJ Platform config/menus.php must return an array.\n" );
	exit( 1 );
}

$menuKeys = array();
foreach ( $menus as $menu ) {
	if ( is_array( $menu ) && isset( $menu['key'] ) && is_string( $menu['key'] ) ) {
		$menuKeys[ $menu['key'] ] = true;
	}
}

$expectedKeys = array(
	'classes',
	'classes.index',
	'classes.new',
	'classes.deleted',
	'classes.groups',
	'classes.groups_new',
	'classes.groups_deleted',
	// OJ Platform 2.2.0 (D-010) folded the 대회·평가 root into 수업; its pages keep their keys.
	'contests.index',
	'contests.new',
	'contests.deleted_submissions',
);
$failures     = array();
foreach ( $expectedKeys as $expectedKey ) {
	if ( ! isset( $menuKeys[ $expectedKey ] ) ) {
		$failures[] = 'Missing platform menu key: ' . $expectedKey;
	}
}

$replacementByLegacyKey = array(
	'learning.classes'                      => 'classes.index',
	'learning.classes_new'                  => 'classes.new',
	'learning.classes_deleted'              => 'classes.deleted',
	'learning.contests'                     => 'contests.index',
	'learning.contests_new'                 => 'contests.new',
	'learning.contests_deleted_submissions' => 'contests.deleted_submissions',
	'learning.groups'                       => 'classes.groups',
	'learning.groups_new'                   => 'classes.groups_new',
	'learning.groups_deleted'               => 'classes.groups_deleted',
);
$presentationRoots      = array(
	$platformRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'Classes'
		. DIRECTORY_SEPARATOR . 'Presentation',
	$platformRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'Contests'
		. DIRECTORY_SEPARATOR . 'Presentation',
	$platformRoot . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'Groups'
		. DIRECTORY_SEPARATOR . 'Presentation',
	$platformRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Kernel'
		. DIRECTORY_SEPARATOR . 'Integration' . DIRECTORY_SEPARATOR . 'Learning',
);

foreach ( $presentationRoots as $presentationRoot ) {
	if ( ! is_dir( $presentationRoot ) ) {
		$failures[] = 'Missing platform presentation root: '
			. str_replace( $platformRoot . DIRECTORY_SEPARATOR, '', $presentationRoot );
		continue;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $presentationRoot ) );
	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== ( strtolower( $file->getExtension() ) ) ) {
			continue;
		}

		$source = file_get_contents( $file->getPathname() );
		if ( ! is_string( $source ) ) {
			$failures[] = 'Unable to read platform source: ' . $file->getPathname();
			continue;
		}

		foreach ( $replacementByLegacyKey as $legacyKey => $replacementKey ) {
			$offset   = 0;
			$position = strpos( $source, "'" . $legacyKey . "'", $offset );
			while ( false !== $position ) {
				$line         = substr_count( substr( $source, 0, $position ), "\n" ) + 1;
				$relativePath = str_replace(
					array( $platformRoot . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ),
					array( '', '/' ),
					$file->getPathname()
				);
				$failures[]   = sprintf(
					'%s:%d uses %s; replace it with %s.',
					$relativePath,
					$line,
					$legacyKey,
					$replacementKey
				);
				$offset       = $position + strlen( $legacyKey ) + 2;
				$position     = strpos( $source, "'" . $legacyKey . "'", $offset );
			}
		}
	}
}

if ( $failures !== array() ) {
	fwrite( STDERR, "OJ Platform console learning-menu compatibility failed.\n" );
	fwrite(
		STDERR,
		"The add-on manifest is valid, but core pages use menu keys that are absent from config/menus.php.\n"
	);
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '- ' . $failure . "\n" );
	}
	fwrite(
		STDERR,
		"Fix ownership: OJ Platform. Add-on workaround: prohibited. Platform API change: 0.\n"
	);
	exit( 1 );
}

echo "OJ Platform console learning-menu compatibility passed: handlers, access rules, and config/menus.php use the same current keys.\n";

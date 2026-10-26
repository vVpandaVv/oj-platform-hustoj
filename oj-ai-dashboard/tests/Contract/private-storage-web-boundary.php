<?php
/**
 * A sibling of WordPress can still be inside the public document root.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
$fixture = sys_get_temp_dir() . '/ojai-web-boundary-' . bin2hex( random_bytes( 6 ) );
mkdir( $fixture . '/public/wordpress', 0700, true );
mkdir( $fixture . '/public/shared', 0700, true );
mkdir( $fixture . '/private', 0700, true );
define( 'ABSPATH', $fixture . '/public/wordpress/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_Storage_Root;
use OJAI\Dashboard\Storage\Infrastructure\Local_Private_File_Store;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;

try {
	$_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
	foreach ( array( '/public', '/public/shared', '/public/shared/new', '/public/wordpress' ) as $suffix ) {
		$rejected = false;
		try {
			Local_Private_Storage_Root::assert_private( $fixture . $suffix ); } catch ( RuntimeException ) {
			$rejected = true;
			}
			if ( ! $rejected ) {
				throw new RuntimeException( 'Public path accepted: ' . $suffix );
			}
	}
	Local_Private_Storage_Root::assert_private( $fixture . '/private' );
	Local_Private_Storage_Root::assert_private( $fixture . '/private/new' );
	$rejected = false;
	try {
		( new Local_Private_File_Store( $fixture . '/public/shared' ) )->delete( 'not-present.bin' ); } catch ( Worksheet_Asset_Failure ) {
		$rejected = true;
		}
		if ( ! $rejected ) {
			throw new RuntimeException( 'Actual file store bypassed the public-root guard.' );
		}
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$rejected = false;
		try {
			Local_Private_Storage_Root::assert_private( $fixture . '/private' ); } catch ( RuntimeException ) {
				$rejected = true;
			}
			if ( ! $rejected ) {
				throw new RuntimeException( 'Unknown document root was accepted.' );
			}
			echo "Private storage web boundary passed: WordPress subdirectory, public siblings, missing paths, unknown root, actual file-store enforcement.\n";
} finally {
	foreach ( array( '/public/wordpress', '/public/shared', '/public', '/private', '' ) as $suffix ) {
		rmdir( $fixture . $suffix );
	}
}

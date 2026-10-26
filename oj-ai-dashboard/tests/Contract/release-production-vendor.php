<?php
/**
 * Exercises production dependency validation without changing the source vendor.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/scripts/validate-production-vendor.php';
$root = sys_get_temp_dir() . '/ojai-release-vendor-' . bin2hex( random_bytes( 12 ) );
mkdir( $root . '/vendor/composer', 0700, true );
$lock      = array(
	'packages'     => array(
		array(
			'name'    => 'example/runtime',
			'version' => '1.0.0',
		),
	),
	'packages-dev' => array(
		array(
			'name'    => 'example/dev',
			'version' => '1.0.0',
		),
	),
);
$installed = array(
	'packages'          => $lock['packages'],
	'dev'               => false,
	'dev-package-names' => array(),
);
$write     = static function ( array $value ) use ( $root ): void {
	file_put_contents( $root . '/vendor/composer/installed.json', json_encode( $value, JSON_THROW_ON_ERROR ) );
};
$reject    = static function () use ( $root ): void {
	try {
		oj_ai_dashboard_validate_production_vendor( $root ); } catch ( RuntimeException $expected ) {
		return;
		}
		throw new LogicException( 'An invalid release vendor was accepted.' );
};
try {
	file_put_contents( $root . '/composer.lock', json_encode( $lock, JSON_THROW_ON_ERROR ) );
	file_put_contents( $root . '/vendor/autoload.php', '<?php' );
	$write( $installed );
	oj_ai_dashboard_validate_production_vendor( $root );
	$changed        = $installed;
	$changed['dev'] = true;
	$write( $changed );
	$reject();
	$changed                      = $installed;
	$changed['dev-package-names'] = array( 'example/dev' );
	$write( $changed );
	$reject();
	$changed               = $installed;
	$changed['packages'][] = $lock['packages-dev'][0];
	$write( $changed );
	$reject();
	$changed                           = $installed;
	$changed['packages'][0]['version'] = '2.0.0';
	$write( $changed );
	$reject();
	$changed               = $installed;
	$changed['packages'][] = $changed['packages'][0];
	$write( $changed );
	$reject();
	$changed             = $installed;
	$changed['packages'] = array();
	$write( $changed );
	$reject();
	$write( $installed );
	mkdir( $root . '/vendor/example/dev', 0700, true );
	$reject();
	rmdir( $root . '/vendor/example/dev' );
	rmdir( $root . '/vendor/example' );
	unlink( $root . '/vendor/autoload.php' );
	$reject();
	fwrite( STDOUT, "Production vendor: valid lock accepted; eight invalid package states rejected.\n" );
} finally {
	foreach ( array( '/composer.lock', '/vendor/autoload.php', '/vendor/composer/installed.json' ) as $file ) {
		if ( is_file( $root . $file ) ) {
			unlink( $root . $file );
		}
	}
	foreach ( array( '/vendor/example/dev', '/vendor/example', '/vendor/composer', '/vendor', '' ) as $directory ) {
		if ( is_dir( $root . $directory ) ) {
			rmdir( $root . $directory );
		}
	}
}

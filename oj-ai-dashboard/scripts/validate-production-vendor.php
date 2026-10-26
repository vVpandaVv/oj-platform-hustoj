<?php
/** Validate an isolated release's installed dependencies against its production lock. */
declare(strict_types=1);

function oj_ai_dashboard_validate_production_vendor( string $root ): void {
	$lock = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
	$installed = json_decode( (string) file_get_contents( $root . '/vendor/composer/installed.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $lock['packages'] ?? null ) || ! is_array( $installed['packages'] ?? null )
		|| false !== ( $installed['dev'] ?? null ) || array() !== ( $installed['dev-package-names'] ?? null ) ) {
		throw new RuntimeException( 'Release vendor must explicitly exclude development dependencies.' );
	}
	$expected = array();
	foreach ( $lock['packages'] as $package ) {
		$expected[ $package['name'] ] = $package['version'];
	}
	$actual = array();
	foreach ( $installed['packages'] as $package ) {
		if ( isset( $actual[ $package['name'] ] ) ) { throw new RuntimeException( 'Duplicate installed package.' ); }
		$actual[ $package['name'] ] = $package['version'];
	}
	ksort( $expected );
	ksort( $actual );
	if ( array() === $expected || $expected !== $actual ) { throw new RuntimeException( 'Release vendor differs from the production lock.' ); }
	foreach ( $lock['packages-dev'] ?? array() as $package ) {
		$name = $package['name'];
		if ( ! isset( $expected[ $name ] ) && file_exists( $root . '/vendor/' . $name ) ) {
			throw new RuntimeException( 'A development package remains in the release vendor.' );
		}
	}
	if ( ! is_file( $root . '/vendor/autoload.php' ) ) { throw new RuntimeException( 'Release autoloader is missing.' ); }
}

if ( PHP_SAPI === 'cli' && realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		if ( 2 !== $argc ) { throw new InvalidArgumentException( 'Expected one isolated package directory.' ); }
		oj_ai_dashboard_validate_production_vendor( $argv[1] );
		fwrite( STDOUT, "Production vendor boundary passed.\n" );
	} catch ( Throwable $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" );
		exit( 1 );
	}
}

<?php
/**
 * Runs the existing executable contracts in isolated PHP processes.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OJAI\Dashboard\Tests\Support\Release_Acceptance_Fixture;

require_once dirname( __DIR__ ) . '/Support/class-release-acceptance-fixture.php';

/**
 * Runs the existing executable contracts in isolated PHP processes.
 */
final class ContractSuiteTest extends TestCase {
	/**
	 * Release fixture.
	 *
	 * @var Release_Acceptance_Fixture|null
	 */
	private static ?Release_Acceptance_Fixture $release_fixture = null;

	/**
	 * Release policy fixtures must never modify the real candidate checkout.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if ( null !== self::$release_fixture ) {
			try {
				self::$release_fixture->assert_checkout_unchanged();
			} finally {
				self::$release_fixture->remove();
				self::$release_fixture = null;
			}
		}
		parent::tearDownAfterClass();
	}
	/**
	 * Contracts.
	 *
	 * @return iterable<string,array{string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function contracts(): iterable {
		$files = glob( dirname( __DIR__ ) . '/Contract/*.php' );
		if ( false === $files || array() === $files ) {
			throw new \RuntimeException( 'The contract suite must not be empty.' );
		}
		sort( $files );
		foreach ( $files as $file ) {
			yield basename( $file ) => array( $file );
		}
	}

	/**
	 * Test contract.
	 *
	 * @param string $file File.
	 * @return void
	 */
	#[DataProvider( 'contracts' )]
	public function testContract( string $file ): void {
		$name = basename( $file );
		if ( in_array( $name, Release_Acceptance_Fixture::CONTRACTS, true ) ) {
			self::$release_fixture ??= new Release_Acceptance_Fixture( dirname( __DIR__, 2 ) );
			foreach ( Release_Acceptance_Fixture::scenarios( $name ) as $scenario => $failure ) {
				$this->run_contract( self::$release_fixture->prepare( $name, $scenario ), $failure, $name . ':' . $scenario );
			}
			return;
		}
		$this->run_contract( $file );
	}

	/**
	 * Run every contract, including expected rejection cases, in its own process.
	 *
	 * @param string  $file Contract source path.
	 * @param ?string $failure Required failure message, or null for success.
	 * @param string  $label Scenario name for assertion output.
	 * @return void
	 */
	private function run_contract( string $file, ?string $failure = null, string $label = '' ): void {
		$output = tmpfile();
		self::assertIsResource( $output );
		$command = array( PHP_BINARY );
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$command = array_merge( $command, array( '-d', 'extension=pdo_sqlite' ) );
		}
		if ( in_array( basename( $file ), array( 'teacher-attachment-content.php', 'bound-attachment-retention.php' ), true ) && ! extension_loaded( 'gd' ) ) {
			$command = array_merge( $command, array( '-d', 'extension=gd' ) );
		}
		$command[] = $file;
		$process   = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs an isolated local contract process with controlled command arguments.
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => $output,
				2 => $output,
			),
			$pipes,
			dirname( __DIR__, 2 )
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$deadline  = microtime( true ) + 180;
		$timed_out = false;
		try {
			do {
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					break;
				}
				if ( microtime( true ) >= $deadline ) {
					$timed_out = true;
					proc_terminate( $process );
					break;
				}
				usleep( 20000 );
			} while ( true );
			$closed  = proc_close( $process );
			$process = null;
			rewind( $output );
			$log = stream_get_contents( $output );
			self::assertFalse( $timed_out, basename( $file ) . ' exceeded 180 seconds.' );
			self::assertSame( null === $failure ? 0 : 255, $status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $label . "\n" . (string) $log );
			if ( null !== $failure ) {
				self::assertStringContainsString( $failure, (string) $log, $label );
			}
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				proc_close( $process );
			}
			fclose( $output );
		}
	}
}

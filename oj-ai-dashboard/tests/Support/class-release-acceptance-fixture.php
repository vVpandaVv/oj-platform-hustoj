<?php
/**
 * Isolated candidate inputs for regression testing the real release contracts.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Keep release policy tests independent of whether this checkout is approved.
 */
final class Release_Acceptance_Fixture {

	/** Contract basenames whose release inputs need an isolated candidate. */
	public const CONTRACTS = array(
		'boundary-final-package-g6-imp-05.php',
		'upgrade-rollback-g6-imp-04.php',
	);

	/**
	 * Directory.
	 *
	 * @var string
	 */
	private string $directory;
	/**
	 * Parent.
	 *
	 * @var string
	 */
	private string $parent;
	/**
	 * Version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Baseline.
	 *
	 * @var array<string,string> Original contents of scenario-specific inputs.
	 */
	private array $baseline = array();

	/**
	 * Checkout hashes.
	 *
	 * @var array<string,string> Checkout hashes used to detect accidental writes.
	 */
	private array $checkout_hashes = array();

	/**
	 * Copy current source; only the fixture's release metadata is synthesized.
	 *
	 * @param string $checkout Real checkout, never a write target.
	 * @throws RuntimeException If metadata is inconsistent or copying fails.
	 */
	public function __construct( private string $checkout ) {
		$package = json_decode( $this->read( $checkout . '/package.json' ), true, 512, JSON_THROW_ON_ERROR );
		$entry   = $this->read( $checkout . '/oj-ai-dashboard.php' );
		$version = $package['version'] ?? null;
		if ( ! is_string( $version ) || 1 !== preg_match( '/^(\d+\.\d+\.\d+)(?:-rc\.\d+)?$/D', $version, $parts ) ) {
			throw new RuntimeException( 'Checkout candidate version is invalid.' );
		}
		preg_match( '/^ \* Version:\s*([^\s]+)$/m', $entry, $header );
		preg_match( "/define\( 'OJ_AI_DASHBOARD_VERSION', '([^']+)' \)/", $entry, $constant );
		if ( $version !== ( $header[1] ?? null ) || $version !== ( $constant[1] ?? null ) ) {
			throw new RuntimeException( 'Checkout package, header and runtime versions must agree.' );
		}
		$this->version   = $parts[1];
		$this->parent    = (string) realpath( sys_get_temp_dir() );
		$this->directory = $this->parent . DIRECTORY_SEPARATOR . 'ojai-release-contract-' . bin2hex( random_bytes( 12 ) );
		if ( '' === $this->parent || ! mkdir( $this->directory, 0700 ) ) {
			throw new RuntimeException( 'Release fixture directory could not be created.' );
		}
		try {
			foreach ( array( 'src', 'scripts', 'tests/Contract', 'tests/Support', 'docs/ai-assessment' ) as $relative ) {
				$source = $checkout . '/' . $relative;
				$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $files as $file ) {
					if ( ! $file->isFile() || $file->isLink() || ! in_array( $file->getExtension(), array( 'php', 'ps1', 'mjs', 'html' ), true ) ) {
						continue;
					}
					$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $checkout ) + 1 ) );
					$this->write( $path, $this->read( $file->getPathname() ) );
				}
			}
			$mutable = array(
				'package.json',
				'oj-ai-dashboard.php',
				'resources/contracts/release-boundary-fingerprint-v1.json',
				'src/Core/Deactivation.php',
				'src/Database/Foundation/Schema_Catalog.php',
				'scripts/build-release.ps1',
			);
			foreach ( $mutable as $path ) {
				$contents                       = $this->read( $checkout . '/' . $path );
				$this->checkout_hashes[ $path ] = hash( 'sha256', $contents );
				$this->write( $path, $contents );
			}
			$package['version'] = $this->version;
			$this->write( 'package.json', json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
			$entry = str_replace(
				array( 'Version: ' . $version, "OJ_AI_DASHBOARD_VERSION', '" . $version . "'" ),
				array( 'Version: ' . $this->version, "OJ_AI_DASHBOARD_VERSION', '" . $this->version . "'" ),
				$entry
			);
			$this->write( 'oj-ai-dashboard.php', $entry );
			$boundary                  = json_decode( $this->read( $checkout . '/' . $mutable[2] ), true, 512, JSON_THROW_ON_ERROR );
			$boundary['addon_version'] = $this->version;
			$this->write( $mutable[2], json_encode( $boundary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
			foreach ( $mutable as $path ) {
				$this->baseline[ $path ] = $this->read( $this->directory . '/' . $path );
			}
		} catch ( \Throwable $error ) {
			$this->remove();
			throw $error;
		}
	}

	/**
	 * Expected failures must identify policy rejection, not arbitrary crashes.
	 *
	 * @param string $contract Contract basename.
	 * @return array<string,?string> Scenario and required failure message.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function scenarios( string $contract ): array {
		$cases = array(
			'valid'            => null,
			'rc'               => 'Package add-on version is invalid.',
			'invalid-version'  => 'Package add-on version is invalid.',
			'trailing-newline' => 'Package add-on version is invalid.',
			'runtime-boundary' => 'Runtime crossed a frozen boundary:',
		);
		if ( self::CONTRACTS[0] === $contract ) {
			return $cases + array(
				'boundary-version' => 'Boundary add-on version drifted.',
				'boundary-hash'    => 'Boundary hash is invalid:',
				'dirty-bypass'     => 'Production packaging must not expose an AllowDirty bypass.',
			);
		}
		if ( self::CONTRACTS[1] === $contract ) {
			return $cases + array(
				'header-version'   => 'Plugin release marker is missing:',
				'constant-version' => 'Plugin release marker is missing:',
				'schema-version'   => 'The target schema is not 1.0.4.',
			);
		}
		throw new RuntimeException( 'Unknown release contract.' );
	}

	/**
	 * Restore the positive fixture before mutating one policy input.
	 *
	 * @param string $contract Contract basename.
	 * @param string $scenario Scenario identifier.
	 * @return string Executable copy of the unchanged acceptance contract.
	 * @throws RuntimeException If a scenario or file is invalid.
	 */
	public function prepare( string $contract, string $scenario ): string {
		if ( ! array_key_exists( $scenario, self::scenarios( $contract ) ) ) {
			throw new RuntimeException( 'Unknown release scenario.' );
		}
		foreach ( $this->baseline as $path => $contents ) {
			$this->write( $path, $contents );
		}
		$boundary_path = 'resources/contracts/release-boundary-fingerprint-v1.json';
		if ( in_array( $scenario, array( 'rc', 'invalid-version', 'trailing-newline' ), true ) ) {
			$package            = json_decode( $this->baseline['package.json'], true, 512, JSON_THROW_ON_ERROR );
			$package['version'] = match ( $scenario ) {
				'rc' => $this->version . '-rc.1',
				'trailing-newline' => $this->version . "\n",
				default => 'invalid',
			};
			$this->write( 'package.json', json_encode( $package, JSON_THROW_ON_ERROR ) );
		} elseif ( str_starts_with( $scenario, 'boundary-' ) ) {
			$boundary = json_decode( $this->baseline[ $boundary_path ], true, 512, JSON_THROW_ON_ERROR );
			if ( 'boundary-version' === $scenario ) {
				$boundary['addon_version'] = $this->version . '-rc.1';
			} else {
				$boundary['groups']['addon_api']['sha256'] = 'invalid';
			}
			$this->write( $boundary_path, json_encode( $boundary, JSON_THROW_ON_ERROR ) );
		} elseif ( 'runtime-boundary' === $scenario ) {
			$path = 'src/Core/Deactivation.php';
			$this->write( $path, $this->baseline[ $path ] . "\n// wp_oj_ai_forbidden_fixture\n" );
		} elseif ( 'dirty-bypass' === $scenario ) {
			$path = 'scripts/build-release.ps1';
			$this->write( $path, $this->baseline[ $path ] . "\n# AllowDirty\n" );
		} elseif ( 'schema-version' === $scenario ) {
			$path = 'src/Database/Foundation/Schema_Catalog.php';
			$this->write( $path, str_replace( "SCHEMA_VERSION = '1.0.4'", "SCHEMA_VERSION = '0.0.0'", $this->baseline[ $path ] ) );
		} elseif ( 'header-version' === $scenario || 'constant-version' === $scenario ) {
			$needle      = 'header-version' === $scenario ? 'Version: ' . $this->version : "OJ_AI_DASHBOARD_VERSION', '" . $this->version . "'";
			$replacement = 'header-version' === $scenario ? $needle . '-invalid' : "OJ_AI_DASHBOARD_VERSION', '" . $this->version . "-invalid'";
			$this->write( 'oj-ai-dashboard.php', str_replace( $needle, $replacement, $this->baseline['oj-ai-dashboard.php'] ) );
		}
		return $this->directory . '/tests/Contract/' . $contract;
	}

	/**
	 * Fail if any candidate metadata or mutable source escaped its fixture.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function assert_checkout_unchanged(): void {
		foreach ( $this->checkout_hashes as $path => $hash ) {
			if ( ! hash_equals( $hash, hash( 'sha256', $this->read( $this->checkout . '/' . $path ) ) ) ) {
				throw new RuntimeException( 'Release fixture changed the checkout: ' . $path );
			}
		}
	}

	/**
	 * Remove only this fixture's verified temporary directory.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function remove(): void {
		$resolved = realpath( $this->directory );
		if ( false === $resolved || $resolved !== $this->directory || dirname( $resolved ) !== $this->parent || is_link( $resolved ) ) {
			throw new RuntimeException( 'Refusing to remove an unverified release fixture.' );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $resolved, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isLink() || ! str_starts_with( (string) $file->getRealPath(), $resolved . DIRECTORY_SEPARATOR ) ) {
				throw new RuntimeException( 'Release fixture contains an unowned path.' );
			}
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $resolved );
	}

	/**
	 * Read a required fixture source.
	 *
	 * @param string $path Readable source path.
	 * @return string
	 * @throws RuntimeException If a source cannot be read.
	 */
	private function read( string $path ): string {
		$source = file_get_contents( $path );
		if ( false === $source ) {
			throw new RuntimeException( 'Release fixture source could not be read.' );
		}
		return $source;
	}

	/**
	 * Write a copied input inside the owned fixture, never in the checkout.
	 *
	 * @param string $relative Fixture-relative file path.
	 * @param string $contents Copied or deliberately mutated test input.
	 * @return void
	 * @throws RuntimeException If the path is unsafe or writing fails.
	 */
	private function write( string $relative, string $contents ): void {
		if ( str_starts_with( $relative, '/' ) || str_contains( $relative, '..' ) || str_contains( $relative, ':' ) || str_contains( $relative, '\\' ) ) {
			throw new RuntimeException( 'Invalid fixture-relative path.' );
		}
		$path = $this->directory . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0700, true ) ) {
			throw new RuntimeException( 'Release fixture subdirectory could not be created.' );
		}
		if ( strlen( $contents ) !== file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( 'Release fixture file could not be written.' );
		}
	}
}

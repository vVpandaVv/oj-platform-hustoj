<?php
/**
 * Verify the exact path contract used by the add-on's PSR-4 loader.
 *
 * @package OJAI\Quality
 */

namespace OJAI\Sniffs\Files;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHPCSUtils\Utils\Namespaces;

/** Checks source declarations without renaming runtime files. */
final class AutoloadPathSniff implements Sniff {

	/**
	 * Optional absolute source override; otherwise use this checkout's src root.
	 *
	 * @var string
	 */
	public $sourceRoot = '';

	/**
	 * Namespace owned by the add-on.
	 *
	 * @var string
	 */
	public $namespacePrefix = 'OJAI\\Dashboard\\';

	/**
	 * Inspect each source file once.
	 *
	 * @return array<int>
	 */
	public function register() {
		return array( T_OPEN_TAG );
	}

	/**
	 * Require one named declaration at its exact autoload path.
	 *
	 * @param File $phpcsFile Tokenized source.
	 * @param int  $stackPtr Opening PHP token.
	 * @return int
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$root   = realpath( '' === $this->sourceRoot ? dirname( __DIR__, 5 ) . '/src' : $this->sourceRoot );
		if ( false === $root ) {
			$phpcsFile->addError( 'The configured autoload source root is unavailable.', $stackPtr, 'MissingRoot' );
			return count( $tokens );
		}
		$root = str_replace( '\\', '/', $root ) . '/';
		$file = str_replace( '\\', '/', $phpcsFile->getFilename() );
		if ( 0 !== strncasecmp( $file, $root, strlen( $root ) ) ) {
			return count( $tokens );
		}
		$declarations = 0;
		foreach ( $tokens as $index => $token ) {
			if ( ! in_array( $token['code'], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
				continue;
			}
			$name = $phpcsFile->getDeclarationName( $index );
			if ( null === $name ) {
				continue;
			}
			++$declarations;
			$qualified = Namespaces::determineNamespace( $phpcsFile, $index ) . '\\' . $name;
			if ( ! str_starts_with( $qualified, $this->namespacePrefix ) ) {
				$phpcsFile->addError( 'Source declaration must belong to %s.', $index, 'NamespaceMismatch', array( $this->namespacePrefix ) );
				continue;
			}
			$expected = str_replace( '\\', '/', substr( $qualified, strlen( $this->namespacePrefix ) ) ) . '.php';
			if ( substr( $file, strlen( $root ) ) !== $expected ) {
				$phpcsFile->addError( 'Autoload path must be exactly %s, including case.', $index, 'PathMismatch', array( $expected ) );
			}
		}
		if ( 1 !== $declarations ) {
			$phpcsFile->addError( 'Each autoload source must contain exactly one named declaration.', $stackPtr, 'DeclarationCount' );
		}
		return count( $tokens );
	}
}

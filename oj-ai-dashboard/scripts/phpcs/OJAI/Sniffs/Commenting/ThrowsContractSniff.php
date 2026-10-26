<?php
/**
 * Require failure documentation for the function that actually throws.
 *
 * @package OJAI\Quality
 */

namespace OJAI\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/** Keeps closures and exception factories separate from their containing methods. */
final class ThrowsContractSniff implements Sniff {

	/** @return array<int> */
	public function register() {
		return array( T_FUNCTION );
	}

	/**
	 * Check named function failure contracts; PHPStan verifies the tagged types.
	 *
	 * @param File $phpcsFile Tokenized source.
	 * @param int  $stackPtr Function token.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		if ( ! isset( $tokens[ $stackPtr ]['scope_closer'] ) ) {
			return;
		}
		$end = $tokens[ $stackPtr ]['scope_closer'];
		$has_throw = false;
		for ( $i = $tokens[ $stackPtr ]['scope_opener'] + 1; $i < $end; ++$i ) {
			if ( in_array( $tokens[ $i ]['code'], array( T_CLOSURE, T_ANON_CLASS, T_FUNCTION ), true ) && isset( $tokens[ $i ]['scope_closer'] ) ) {
				$i = $tokens[ $i ]['scope_closer'];
				continue;
			}
			if ( T_THROW === $tokens[ $i ]['code'] ) {
				$has_throw = true;
				break;
			}
		}
		if ( ! $has_throw ) {
			return;
		}
		$skip = Tokens::$methodPrefixes + array( T_WHITESPACE => T_WHITESPACE );
		$comment = $phpcsFile->findPrevious( $skip, $stackPtr - 1, null, true );
		while ( false !== $comment && T_ATTRIBUTE_END === $tokens[ $comment ]['code'] ) {
			$comment = $phpcsFile->findPrevious( $skip, $tokens[ $comment ]['attribute_opener'] - 1, null, true );
		}
		if ( false !== $comment && T_DOC_COMMENT_CLOSE_TAG === $tokens[ $comment ]['code'] ) {
			$start = $tokens[ $comment ]['comment_opener'];
			$text = $phpcsFile->getTokensAsString( $start, $comment - $start + 1 );
			if ( preg_match( '/@throws\s+[^\s*]+/', $text ) ) {
				return;
			}
		}
		$phpcsFile->addError( 'Document the validation or dependency failures propagated by this function with @throws.', $stackPtr, 'Missing' );
	}
}

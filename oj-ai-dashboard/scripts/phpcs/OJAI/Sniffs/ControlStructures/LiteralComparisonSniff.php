<?php
/**
 * Enforce literal-first comparisons without changing evaluation order of calls.
 *
 * @package OJAI\Quality
 */

namespace OJAI\Sniffs\ControlStructures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/** Detects simple literal comparisons only; two evaluated expressions retain their order. */
final class LiteralComparisonSniff implements Sniff {

	/** @return array<int> */
	public function register() {
		return array( T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL );
	}

	/**
	 * Require the literal on the left of a simple equality check.
	 *
	 * @param File $phpcsFile Tokenized source.
	 * @param int  $stackPtr Equality operator.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$right = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );
		$left = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		$literal = array( T_TRUE, T_FALSE, T_NULL, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING );
		if ( false === $right || false === $left || ! in_array( $tokens[ $right ]['code'], $literal, true ) || in_array( $tokens[ $left ]['code'], $literal, true ) ) {
			return;
		}
		$next = $phpcsFile->findNext( Tokens::$emptyTokens, $right + 1, null, true );
		$boundary = array( T_CLOSE_PARENTHESIS, T_SEMICOLON, T_COMMA, T_BOOLEAN_AND, T_BOOLEAN_OR, T_INLINE_THEN, T_INLINE_ELSE, T_CLOSE_SQUARE_BRACKET );
		if ( false !== $next && in_array( $tokens[ $next ]['code'], $boundary, true ) ) {
			$phpcsFile->addError( 'Place the side-effect-free comparison literal on the left.', $stackPtr, 'LiteralOnRight' );
		}
	}
}

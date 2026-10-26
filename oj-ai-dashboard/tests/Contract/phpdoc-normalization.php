<?php
/**
 * PHPDoc tag normalization must not rewrite code or strings.
 *
 * @package OJAI\Dashboard
 */

// Load the PHPDoc transformation under test.
require dirname( __DIR__, 2 ) . '/scripts/normalize-phpdoc-tags.php';

$ojai_input  = <<<'PHP'
<?php
/** @param mixed $value @return array<string,mixed> */
function sample($value) { return array('text' => '/** @param x @return y */'); }
/** @return int */
function single() { return 1; }
/**
 * Description.
 * @param array $first @param string $second @return array
 */
function multiple(array $first, string $second) { return $first; }
PHP;
$ojai_output = ojai_normalize_phpdoc_tags( $ojai_input );
if ( ! str_contains( $ojai_output, " * @param mixed \$value\n * @return array<string,mixed>" ) ) {
	throw new RuntimeException( 'Combined tags were not split.' );
}
if ( ! str_contains( $ojai_output, "'/** @param x @return y */'" ) ) {
	throw new RuntimeException( 'A string literal was changed.' );
}
if ( ! str_contains( $ojai_output, '/** @return int */' ) || ! str_contains( $ojai_output, " * @param string \$second\n * @return array" ) ) {
	throw new RuntimeException( 'Single or multiline tags were mishandled.' );
}
if ( ojai_normalize_phpdoc_tags( $ojai_output ) !== $ojai_output ) {
	throw new RuntimeException( 'Normalization is not idempotent.' );
}
$ojai_crlf = str_replace( "\n", "\r\n", $ojai_input );
if ( str_replace( "\r\n", "\n", ojai_normalize_phpdoc_tags( $ojai_crlf ) ) !== $ojai_output ) {
	throw new RuntimeException( 'Line endings were not preserved.' );
}
echo "PHPDoc normalization PASS: tags, strings, idempotence and line endings.\n";

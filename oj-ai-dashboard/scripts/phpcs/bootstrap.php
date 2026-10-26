<?php
/**
 * Scope rule exceptions to exact checkout paths, including when an ancestor is named tests.
 *
 * @package OJAI\Quality
 */

// PHPCS includes bootstrap files in Runner scope before constructing File instances.
$ojai_quality_root = preg_quote( str_replace( '\\', '/', dirname( __DIR__, 2 ) ), '`' );
$ojai_quality_scopes = array(
	'WordPress.Files.FileName' => 'src',
	'WordPress.NamingConventions.PrefixAllGlobals' => 'tests',
	'WordPress.Security.EscapeOutput.ExceptionNotEscaped' => 'tests',
	'WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler' => 'tests',
	// Standalone contract processes declare their own doubles and have no WordPress filesystem API.
	'Generic.Files.OneObjectStructurePerFile' => 'tests',
	'Generic.Classes.DuplicateClassName' => 'tests',
	'Universal.Files.SeparateFunctionsFromOO' => 'tests',
	'WordPress.Files.FileName.InvalidClassFileName' => 'tests',
	'WordPress.WP.AlternativeFunctions' => 'tests',
	'WordPress.WP.GlobalVariablesOverride' => 'tests',
	'WordPress.PHP.DevelopmentFunctions.error_log_var_export' => 'tests',
	'WordPress.DB.RestrictedClasses.mysql__PDO' => 'tests',
	// Interface doubles preserve their required signature even when an unsupported operation only throws.
	'Squiz.Commenting.FunctionComment.InvalidNoReturn' => 'tests',
	'Generic.CodeAnalysis.UnusedFunctionParameter' => 'tests',
	'Universal.NamingConventions.NoReservedKeywordParameterNames' => 'tests',
	'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase' => 'tests',
	// These encode owned, in-memory fixture payloads and clone test doubles; no external payload is deserialized.
	'WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize' => 'tests',
	'WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize' => 'tests',
	'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode' => 'tests',
	'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode' => 'tests',
);
foreach ( $ojai_quality_scopes as $ojai_quality_code => $ojai_quality_directory ) {
	$this->ruleset->ignorePatterns[ $ojai_quality_code ][ '^' . $ojai_quality_root . '/' . $ojai_quality_directory . '/' ] = 'absolute';
}
unset( $ojai_quality_root, $ojai_quality_scopes, $ojai_quality_code, $ojai_quality_directory );

// PHPUnit discovery requires *Test.php; this standalone support class is explicitly required by legacy contracts.
$ojai_quality_root = preg_quote( str_replace( '\\', '/', dirname( __DIR__, 2 ) ), '`' );
foreach ( array( 'tests/PHPUnit/ContractSuiteTest.php', 'tests/Support/Php_Source_Contract.php' ) as $ojai_quality_file ) {
	$this->ruleset->ignorePatterns['WordPress.Files.FileName.NotHyphenatedLowercase'][ '^' . $ojai_quality_root . '/' . preg_quote( $ojai_quality_file, '`' ) . '$' ] = 'absolute';
}
unset( $ojai_quality_root, $ojai_quality_file );

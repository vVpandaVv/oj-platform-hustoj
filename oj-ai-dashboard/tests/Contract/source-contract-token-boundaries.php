<?php
/**
 * Prevent formatting-only failures without weakening route or release assertions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Tests\Support\Source_Contract_Tokens;

require dirname( __DIR__ ) . '/Support/class-source-contract-tokens.php';

foreach ( array( "<?php const API = '1.1.0';", '<?php const API="1.1.0";' ) as $source ) {
	if ( '1.1.0' !== Source_Contract_Tokens::string_constant( $source, 'API' ) ) {
		throw new RuntimeException( 'Whitespace or quote formatting changed the constant contract.' );
	}
}
foreach ( array( "<?php /* const API = '1.1.0'; */", "<?php const OTHER_API = '1.1.0';", "<?php const API = '2.0.0';" ) as $source ) {
	if ( '1.1.0' === Source_Contract_Tokens::string_constant( $source, 'API' ) ) {
		throw new RuntimeException( 'Comment, suffix name or changed version bypassed the constant contract.' );
	}
}
$get  = "<?php \$routes = array(array('methods' => 'GET'), array('methods'\n=>\n'GET'));";
$post = str_replace( "'GET'", "'POST'", $get );
if ( 2 !== Source_Contract_Tokens::array_string_value_count( $get, 'methods', 'GET' ) || 0 !== Source_Contract_Tokens::array_string_value_count( $post, 'methods', 'GET' ) ) {
	throw new RuntimeException( 'The route method contract did not check executable array entries.' );
}
if ( 0 !== Source_Contract_Tokens::array_string_value_count( "<?php /* 'methods' => 'GET' */ \$example = \"'methods' => 'GET'\";", 'methods', 'GET' ) ) {
	throw new RuntimeException( 'A comment or example string spoofed a route.' );
}
echo "PASS source contracts: exact names, versions and route methods; comments and example strings cannot spoof code.\n";

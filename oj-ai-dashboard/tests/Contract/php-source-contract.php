<?php
/**
 * Regression coverage for format-independent PHP source assertions.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

$source = "<?php\nprivate const LIMIT   = 300;\n\$a = array( 'state'   => 'pending' );\n\$this->authorize( );\n\$this->write( );";
$checks = array(
	Ojai_Php_Source_Contract::contains( $source, 'private const LIMIT = 300;' ),
	Ojai_Php_Source_Contract::contains( $source, "'state' => 'pending'" ),
	! Ojai_Php_Source_Contract::contains( $source, 'private const LIMIT = 301;' ),
	! Ojai_Php_Source_Contract::contains( $source, "'state' => 'approved'" ),
	! Ojai_Php_Source_Contract::contains( '<?php /* LIMIT = 300; */', 'LIMIT = 300;' ),
	! Ojai_Php_Source_Contract::contains( "<?php \$a = 'LIMIT = 300;';", 'LIMIT = 300;' ),
	! Ojai_Php_Source_Contract::contains( "<?php \$a = 'two  spaces';", "\$a = 'two spaces';" ),
	! Ojai_Php_Source_Contract::contains( '<?php $a == $b;', '$a === $b;' ),
	Ojai_Php_Source_Contract::position( $source, '$this->authorize(' ) < Ojai_Php_Source_Contract::position( $source, '$this->write(' ),
	2 === Ojai_Php_Source_Contract::count( "<?php array('methods'=>'GET'); array( 'methods'  => 'GET' );", "'methods' => 'GET'" ),
	Ojai_Php_Source_Contract::marker( "<?php \$sql = 'SELECT id FROM table_name';", 'SELECT id FROM table_name' ),
);
if ( in_array( false, $checks, true ) ) {
	throw new RuntimeException( 'PHP source contract comparison failed.' );
}
echo 'PHP source contract comparison passed: ' . count( $checks ) . " checks.\n";

<?php
/**
 * CLI-only fixture for the local scanner protocol contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$scanner = new OJAI\Dashboard\Submission\Student\Infrastructure\Clamav_Instream_Submission_Attachment_Scanner( $argv[1] ?? '', 1 );
$result  = 'scan' === ( $argv[2] ?? '' )
	? array( 'state' => $scanner->scan( __FILE__ )->state() )
	: array( 'responding' => $scanner->is_available() );
echo json_encode( $result, JSON_THROW_ON_ERROR );

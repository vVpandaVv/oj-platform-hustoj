<?php
/**
 * Server configuration helper safety regression.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
require dirname( __DIR__, 2 ) . '/resources/server/wp-config-helper.php';
/**
 * Assert safe configuration handling.
 *
 * @param bool   $condition Expected condition.
 * @param string $message Failure message.
 * @return void
 * @throws RuntimeException When the assertion fails.
 */
function server_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
$source = "<?php\nrequire_once ABSPATH . 'wp-settings.php';\n";
$result = ojPrepareConfig( $source, 'OJ_AI_PRIVATE_STORAGE_DIR', '/home/judge/oj-ai-private' );
server_assert( str_contains( $result, "define('OJ_AI_PRIVATE_STORAGE_DIR', '/home/judge/oj-ai-private');" ), 'Storage definition missing' );
server_assert( ojPrepareConfig( $result, 'OJ_AI_PRIVATE_STORAGE_DIR', '/home/judge/oj-ai-private' ) === $result, 'Rerun must preserve config' );
foreach ( array(
	"<?php define('OJ_AI_PRIVATE_STORAGE_DIR', '/different');",
	"<?php define('OJ_AI_PRIVATE_STORAGE_DIR', getenv('STORAGE'));",
	"<?php // OJ_AI_PRIVATE_STORAGE_DIR is maintained manually\n",
	'<?php declare(strict_types=1);',
) as $conflict ) {
	$rejected = false;
	try {
		ojPrepareConfig( $conflict, 'OJ_AI_PRIVATE_STORAGE_DIR', '/home/judge/oj-ai-private' );
	} catch ( RuntimeException ) {
		$rejected = true;
	}
	server_assert( $rejected, 'Unsafe config must be refused' );
}
// Quote/backslash input remains a PHP string and never becomes code.
$quoted = ojPrepareConfig( $source, 'OJ_AI_PRIVATE_STORAGE_DIR', "/private/a'b\\c" );
token_get_all( $quoted, TOKEN_PARSE );
server_assert( ojPrepareConfig( $quoted, 'OJ_AI_PRIVATE_STORAGE_DIR', "/private/a'b\\c" ) === $quoted, 'Escaped path rerun must preserve config' );

$fixture = dirname( __DIR__, 3 ) . '/_notes/tmp/server-config-' . bin2hex( random_bytes( 4 ) );
mkdir( $fixture, 0700, true );
try {
	file_put_contents( $fixture . '/wp-settings.php', '<?php' );
	file_put_contents( $fixture . '/wp-config.php', $source );
	ob_start();
	ojPrepareMain( array( 'helper', '--check', $fixture, 'OJ_AI_PRIVATE_STORAGE_DIR', '/private/ai' ) );
	$checkOutput = (string) ob_get_clean();
	server_assert( str_contains( $checkOutput, 'Config check passed' ), 'Helper preflight failed' );
	server_assert( file_get_contents( $fixture . '/wp-config.php' ) === $source, 'Preflight must not write config' );
	file_put_contents( $fixture . '/wp-config.php', $result );
	$rejected = false;
	try {
		ojPrepareMain( array( 'helper', '--check', $fixture, 'OJ_AI_PRIVATE_STORAGE_DIR', '/private/different' ) );
	} catch ( RuntimeException ) {
		$rejected = true;
	}
	server_assert( $rejected && file_get_contents( $fixture . '/wp-config.php' ) === $result, 'Conflicting preflight must leave config intact' );
} finally {
	unlink( $fixture . '/wp-settings.php' );
	unlink( $fixture . '/wp-config.php' );
	rmdir( $fixture );
}

fwrite( STDOUT, "Server config helper safety passed.\n" );

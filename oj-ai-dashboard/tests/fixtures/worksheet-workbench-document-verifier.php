<?php
/**
 * Browser-test helper: accepts teacher_document on STDIN through the real canonicalizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * Test-only NFC pass-through for the minimal local PHP runtime.
	 */
	final class Normalizer {
		public const FORM_C = 4;

		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 * @throws \Throwable Propagates validation or dependency failures.
		 */
		public static function normalize( string $value, int $form ): string {
			if ( self::FORM_C !== $form ) {
				throw new RuntimeException( 'Unexpected normalization form.' );
			}
			return $value;
		}
	}
}

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

try {
	$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $input ) ) {
		throw new RuntimeException( 'Expected a teacher_document object.' );
	}
	$canonical = ( new Worksheet_Document_Canonicalizer(
		new Canonical_Json(),
		new Public_Id_Generator()
	) )->canonicalize( $input );
	echo json_encode(
		array(
			'statistics'       => $canonical->statistics(),
			'temporary_id_map' => $canonical->temporary_id_map(),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
	);
} catch ( Throwable $failure ) {
	fwrite( STDERR, get_class( $failure ) . ': ' . $failure->getMessage() . "\n" );
	exit( 1 );
}

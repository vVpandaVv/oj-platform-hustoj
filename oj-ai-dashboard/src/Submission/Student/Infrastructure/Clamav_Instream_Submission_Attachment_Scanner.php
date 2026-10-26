<?php
/**
 * Local ClamAV INSTREAM adapter for quarantined submission attachments.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams bytes to a local clamd process and never stores its raw response.
 */
final class Clamav_Instream_Submission_Attachment_Scanner implements Submission_Attachment_Malware_Scanner_Port_Interface {
	private const DEFAULT_ENDPOINT   = 'tcp://127.0.0.1:3310';
	private const CHUNK_BYTES        = 1024 * 1024;
	private const MAX_RESPONSE_BYTES = 4096;

	/**
	 * Endpoint.
	 *
	 * @var string|null
	 */
	private ?string $endpoint;

	/**
	 * Initialize clamav instream submission attachment scanner dependencies and state.
	 *
	 * @param string|null $endpoint Endpoint.
	 * @param int         $timeout_seconds Timeout seconds.
	 */
	public function __construct( ?string $endpoint = null, private int $timeout_seconds = 10 ) {
		$configured = $endpoint;
		if ( null === $configured && defined( 'OJ_AI_CLAMD_SOCKET' ) ) {
			$value      = constant( 'OJ_AI_CLAMD_SOCKET' );
			$configured = is_string( $value ) ? $value : null;
		}
		$this->endpoint = $this->local_endpoint( null === $configured ? self::DEFAULT_ENDPOINT : trim( $configured ) );
		if ( $timeout_seconds < 1 || $timeout_seconds > 30 ) {
			$this->endpoint = null;
		}
	}

	/**
	 * Scan.
	 *
	 * @param string $path Path.
	 * @return Submission_Attachment_Malware_Verdict
	 */
	public function scan( string $path ): Submission_Attachment_Malware_Verdict {
		if (
			null === $this->endpoint
			|| ! function_exists( 'stream_socket_client' )
			|| ! is_file( $path )
			|| ! is_readable( $path )
			|| is_link( $path )
		) {
			return Submission_Attachment_Malware_Verdict::unavailable();
		}

		$socket   = null;
		$file     = null;
		$deadline = microtime( true ) + $this->timeout_seconds;
		try {
			$error_number  = 0;
			$error_message = '';
			$socket        = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error.
				$this->endpoint,
				$error_number,
				$error_message,
				$this->timeout_seconds,
				STREAM_CLIENT_CONNECT
			);
			unset( $error_number, $error_message );
			if ( false === $socket ) {
				return Submission_Attachment_Malware_Verdict::unavailable();
			}
			if ( ! $this->write_all( $socket, "zINSTREAM\0", $deadline ) ) {
				return Submission_Attachment_Malware_Verdict::unavailable();
			}

			$file = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( false === $file ) {
				return Submission_Attachment_Malware_Verdict::unavailable();
			}
			while ( ! feof( $file ) ) {
				$chunk = fread( $file, self::CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $chunk ) {
					return Submission_Attachment_Malware_Verdict::unavailable();
				}
				if ( '' !== $chunk && ! $this->write_all( $socket, pack( 'N', strlen( $chunk ) ) . $chunk, $deadline ) ) {
					return Submission_Attachment_Malware_Verdict::unavailable();
				}
			}
			if ( ! $this->write_all( $socket, pack( 'N', 0 ), $deadline ) ) {
				return Submission_Attachment_Malware_Verdict::unavailable();
			}

			$response = '';
			while ( strlen( $response ) < self::MAX_RESPONSE_BYTES && ! feof( $socket ) ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Enforce the byte cap against each partial network read.
				if ( ! $this->remaining_timeout( $socket, $deadline ) ) {
					return Submission_Attachment_Malware_Verdict::unavailable();
				}
				$part = fread( $socket, min( 512, self::MAX_RESPONSE_BYTES - strlen( $response ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $part || '' === $part ) {
					return Submission_Attachment_Malware_Verdict::unavailable();
				}
				$response .= $part;
				if ( str_contains( $response, "\0" ) ) {
					break;
				}
			}
			if ( microtime( true ) >= $deadline ) {
				return Submission_Attachment_Malware_Verdict::unavailable();
			}
			if ( "stream: OK\0" === $response ) {
				return Submission_Attachment_Malware_Verdict::clean();
			}
			if ( 1 === preg_match( '/^stream: [^\x00\r\n]+ FOUND\x00$/D', $response ) ) {
				return Submission_Attachment_Malware_Verdict::infected();
			}
			return Submission_Attachment_Malware_Verdict::unavailable();
		} catch ( Throwable ) {
			return Submission_Attachment_Malware_Verdict::unavailable();
		} finally {
			if ( is_resource( $file ) ) {
				fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( is_resource( $socket ) ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Checks only the configured local daemon; no file bytes or paths are sent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( null === $this->endpoint || ! function_exists( 'stream_socket_client' ) ) {
			return false;
		}
		$socket   = null;
		$deadline = microtime( true ) + $this->timeout_seconds;
		try {
			$error_number  = 0;
			$error_message = '';
			$socket        = @stream_socket_client( $this->endpoint, $error_number, $error_message, $this->timeout_seconds, STREAM_CLIENT_CONNECT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error.
			if ( false === $socket ) {
				return false;
			}
			if ( ! $this->write_all( $socket, "zPING\0", $deadline ) ) {
				return false;
			}
			$response = '';
			while ( strlen( $response ) < 5 && ! feof( $socket ) ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Each partial network read changes the response length.
				if ( ! $this->remaining_timeout( $socket, $deadline ) ) {
					return false;
				}
				$part = fread( $socket, 5 - strlen( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $part || '' === $part ) {
					return false;
				}
				$response .= $part;
			}
			return microtime( true ) < $deadline && "PONG\0" === $response;
		} catch ( Throwable ) {
			return false;
		} finally {
			if ( is_resource( $socket ) ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
		}
	}

	/**
	 * Write all.
	 *
	 * @param resource $stream Stream.
	 * @param string   $bytes Bytes.
	 * @param float    $deadline Deadline.
	 * @return bool
	 */
	private function write_all( $stream, string $bytes, float $deadline ): bool {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			if ( ! $this->remaining_timeout( $stream, $deadline ) ) {
				return false;
			}
			$written = fwrite( $stream, substr( $bytes, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/**
	 * Remaining timeout.
	 *
	 * @param resource $stream Stream.
	 * @param float    $deadline Deadline.
	 * @return bool
	 */
	private function remaining_timeout( $stream, float $deadline ): bool {
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return false;
		}
		$seconds = (int) $remaining;
		return stream_set_timeout( $stream, $seconds, (int) ( ( $remaining - $seconds ) * 1000000 ) );
	}

	/**
	 * Local endpoint.
	 *
	 * @param string $endpoint Endpoint.
	 * @return string|null
	 */
	private function local_endpoint( string $endpoint ): ?string {
		if ( 1 === preg_match( '/^tcp:\/\/(?:127\.0\.0\.1|\[::1\]):([0-9]{1,5})$/D', $endpoint, $match ) ) {
			$port = (int) $match[1];
			return $port >= 1 && $port <= 65535 ? $endpoint : null;
		}
		if ( 1 === preg_match( '/^unix:\/\/\/(?:[^\x00]+)$/D', $endpoint ) && ! str_contains( $endpoint, '..' ) ) {
			return $endpoint;
		}
		return null;
	}
}

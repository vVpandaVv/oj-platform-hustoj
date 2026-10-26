<?php
/**
 * Export download.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use InvalidArgumentException;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Export download.
 */
final class Export_Download {
	private const PROFILES = array(
		'result_pdf'          => array(
			'mime_type' => 'application/pdf',
			'suffix'    => '.pdf',
			'max_bytes' => 104_857_600,
		),
		'submission_pdf'      => array(
			'mime_type' => 'application/pdf',
			'suffix'    => '.pdf',
			'max_bytes' => 104_857_600,
		),
		'worksheet_pdf'       => array(
			'mime_type' => 'application/pdf',
			'suffix'    => '.pdf',
			'max_bytes' => 104_857_600,
		),
		'worksheet_package'   => array(
			'mime_type' => 'application/vnd.oj.ai-worksheet+zip',
			'suffix'    => '.ojws',
			'max_bytes' => 230_686_720,
		),
		'report_csv'          => array(
			'mime_type' => 'text/csv',
			'suffix'    => '.csv',
			'max_bytes' => 52_428_800,
		),
		'report_pdf'          => array(
			'mime_type' => 'application/pdf',
			'suffix'    => '.pdf',
			'max_bytes' => 104_857_600,
		),
		'report_json_package' => array(
			'mime_type' => 'application/zip',
			'suffix'    => '.zip',
			'max_bytes' => 52_428_800,
		),
		'submission_bundle'   => array(
			'mime_type' => 'application/zip',
			'suffix'    => '.zip',
			'max_bytes' => 524_288_000,
		),
	);

	/**
	 * Initialize export download dependencies and state.
	 *
	 * @param string $path Path.
	 * @param string $name Name.
	 * @param string $mime_type Mime type.
	 * @param string $artifact_kind Artifact kind.
	 * @param int    $bytes Bytes.
	 * @param string $sha256 Sha256.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $path, private string $name, private string $mime_type, private string $artifact_kind, private int $bytes, private string $sha256 ) {
		$profile = self::PROFILES[ $artifact_kind ] ?? null;
		if ( ! is_file( $path ) || ! is_array( $profile ) || $bytes < 1 || $bytes > $profile['max_bytes']
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || '' === $name || str_contains( $name, "\r" ) || str_contains( $name, "\n" )
			|| $profile['mime_type'] !== $mime_type || ! str_ends_with( strtolower( $name ), $profile['suffix'] ) ) {
			throw new InvalidArgumentException( 'Export download is invalid.' );
		}
	}
	/**
	 * Path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}
	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}
	/**
	 * Mime type.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->mime_type;
	}
	/**
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
	}
	/**
	 * Bytes.
	 *
	 * @return int
	 */
	public function bytes(): int {
		return $this->bytes;
	}
	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->sha256;
	}
}

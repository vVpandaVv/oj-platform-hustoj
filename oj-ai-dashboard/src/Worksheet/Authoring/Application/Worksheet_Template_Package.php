<?php
/**
 * Validated portable worksheet package.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validated portable worksheet package.
 */
final class Worksheet_Template_Package {
	/**
	 * Release callback.
	 *
	 * @var Closure
	 */
	private Closure $release_callback;
	/**
	 * Released.
	 *
	 * @var bool
	 */
	private bool $released = false;

	/**
	 * Initialize worksheet template package dependencies and state.
	 *
	 * @param string                         $package_hash Package hash.
	 * @param string                         $source_revision_id Source revision id.
	 * @param string                         $source_revision_hash Source revision hash.
	 * @param array<array-key,mixed>         $teacher_document Teacher document.
	 * @param array<int,array<string,mixed>> $achievement_links Achievement links.
	 * @param array<array-key,mixed>         $assets Assets.
	 * @param callable|null                  $release_callback Release callback.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $package_hash,
		private string $source_revision_id,
		private string $source_revision_hash,
		private array $teacher_document,
		private array $achievement_links,
		private array $assets,
		?callable $release_callback = null
	) {
		new Public_Id( $source_revision_id );
		foreach ( array( $package_hash, $source_revision_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Worksheet template package hash is invalid.' );
			}
		}
		if ( array() === $teacher_document || array_is_list( $teacher_document ) ) {
			throw new InvalidArgumentException( 'Worksheet template document is invalid.' );
		}
		if ( ! array_is_list( $achievement_links ) || ! array_is_list( $assets ) ) {
			throw new InvalidArgumentException( 'Worksheet template package lists are invalid.' );
		}

		$seen = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || array_is_list( $asset ) ) {
				throw new InvalidArgumentException( 'Worksheet template asset is invalid.' );
			}
			$keys     = array_keys( $asset );
			$expected = array( 'byte_size', 'mime_type', 'original_name', 'sha256', 'source_asset_id', 'tmp_name' );
			sort( $keys, SORT_STRING );
			if ( $keys !== $expected ) {
				throw new InvalidArgumentException( 'Worksheet template asset shape is invalid.' );
			}
			$source_id = is_string( $asset['source_asset_id'] ) ? $asset['source_asset_id'] : '';
			new Public_Id( $source_id );
			if ( isset( $seen[ $source_id ] ) ) {
				throw new InvalidArgumentException( 'Worksheet template asset IDs must be unique.' );
			}
			$seen[ $source_id ] = true;
			if (
				! is_string( $asset['original_name'] ) || '' === $asset['original_name']
				|| ! is_string( $asset['mime_type'] ) || '' === $asset['mime_type']
				|| ! is_int( $asset['byte_size'] ) || $asset['byte_size'] < 1
				|| ! is_string( $asset['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $asset['sha256'] )
				|| ! is_string( $asset['tmp_name'] ) || '' === $asset['tmp_name']
			) {
				throw new InvalidArgumentException( 'Worksheet template asset value is invalid.' );
			}
		}

		$this->release_callback = null === $release_callback
			? static function (): void {
			}
			: Closure::fromCallable( $release_callback );
	}

	/**
	 * Package hash.
	 *
	 * @return string
	 */
	public function package_hash(): string {
		return $this->package_hash;
	}

	/**
	 * Source revision id.
	 *
	 * @return string
	 */
	public function source_revision_id(): string {
		return $this->source_revision_id;
	}

	/**
	 * Source revision hash.
	 *
	 * @return string
	 */
	public function source_revision_hash(): string {
		return $this->source_revision_hash;
	}

	/**
	 * Teacher document.
	 *
	 * @return array<string,mixed>
	 */
	public function teacher_document(): array {
		return $this->teacher_document;
	}

	/**
	 * Achievement links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function achievement_links(): array {
		return $this->achievement_links;
	}

	/**
	 * Assets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function assets(): array {
		return $this->assets;
	}

	/**
	 * Release.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}
		$this->released = true;
		try {
			( $this->release_callback )();
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// Temporary import files are also covered by scheduled cleanup.
		}
	}

	/**
	 * Release resources owned by this instance.
	 */
	public function __destruct() {
		$this->release();
	}
}

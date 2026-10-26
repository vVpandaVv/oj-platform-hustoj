<?php
/**
 * Build worksheet package source.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Storage\Application\Read_Worksheet_Asset_Content;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Package_Source_Query_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build worksheet package source.
 */
final class Build_Worksheet_Package_Source {
	/**
	 * Initialize worksheet package source dependencies and state.
	 *
	 * @param Worksheet_Package_Source_Query_Port_Interface $sources Sources.
	 * @param Read_Worksheet_Asset_Content                  $asset_content Asset content.
	 * @param Worksheet_Document_Id_Rewriter                $ids Ids.
	 * @param Canonical_Json                                $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Worksheet_Package_Source_Query_Port_Interface $sources,
		private Read_Worksheet_Asset_Content $asset_content,
		private Worksheet_Document_Id_Rewriter $ids,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @param string $expected_hash Expected hash.
	 * @param string $expected_state Expected state.
	 * @return Worksheet_Package_Source
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build( int $actor_user_id, string $revision_id, string $expected_hash, string $expected_state ): Worksheet_Package_Source {
		try {
			$source = $this->sources->find_owned_package_source( $actor_user_id, $revision_id );
			if ( null === $source ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::NOT_FOUND );
			}
			if ( ! hash_equals( $expected_hash, (string) ( $source['source_hash'] ?? '' ) )
				|| ! hash_equals( $expected_state, (string) ( $source['source_state'] ?? '' ) ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_STALE );
			}
			$document = $source['teacher_document'] ?? null;
			if ( ! is_array( $document ) || array_is_list( $document ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_INVALID );
			}
			$contents = array();
			$manifest = array();
			foreach ( $this->ids->asset_ids( $document ) as $asset_id ) {
				$content               = $this->asset_content->execute( $actor_user_id, $asset_id, 'revision', $revision_id );
				$extension             = strtolower( (string) pathinfo( $content->original_name(), PATHINFO_EXTENSION ) );
				$manifest[]            = array(
					'byte_size'       => $content->byte_size(),
					'extension'       => $extension,
					'mime_type'       => $content->mime_type(),
					'original_name'   => $content->original_name(),
					'path'            => 'assets/' . $content->sha256() . '.' . $extension,
					'sha256'          => $content->sha256(),
					'source_asset_id' => $asset_id,
				);
				$contents[ $asset_id ] = $content;
			}
			usort( $manifest, static fn ( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] ) );
			$snapshot = Worksheet_Package_Request_Snapshot::freeze( $source, $manifest, $this->canonical );
			return new Worksheet_Package_Source( $snapshot, $contents );
		} catch ( Worksheet_Package_Failure $failure ) {
			throw $failure;
		} catch ( Worksheet_Asset_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Worksheet_Asset_Failure::NOT_FOUND, Worksheet_Asset_Failure::EXPIRED => Worksheet_Package_Failure::SOURCE_STALE,
				Worksheet_Asset_Failure::LIMIT_EXCEEDED => Worksheet_Package_Failure::TOO_LARGE,
				Worksheet_Asset_Failure::STORAGE_UNAVAILABLE => Worksheet_Package_Failure::STORAGE_UNAVAILABLE,
				default => Worksheet_Package_Failure::SOURCE_INVALID,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( $reason );
		} catch ( Worksheet_Failure $failure ) {
			$reason = match ( $failure->reason() ) {
				Worksheet_Failure::NOT_FOUND => Worksheet_Package_Failure::NOT_FOUND,
				Worksheet_Failure::STORED_DATA_INVALID => Worksheet_Package_Failure::SOURCE_INVALID,
				default => Worksheet_Package_Failure::STORAGE_UNAVAILABLE,
			};
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( $reason );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_INVALID );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
	}
}

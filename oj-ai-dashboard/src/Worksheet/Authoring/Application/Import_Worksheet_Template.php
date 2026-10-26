<?php
/**
 * ImportWorksheetTemplate use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset;
use OJAI\Dashboard\Storage\Application\Stage_Worksheet_Asset_Command;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Template_Id_Rewriter;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Template_Package_Reader_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuses C64 validation and C02 persistence, compensating unbound imports on failure.
 */
final class Import_Worksheet_Template {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize import worksheet template dependencies and state.
	 *
	 * @param Read_Worksheets                                  $read_worksheets Read worksheets.
	 * @param Worksheet_Template_Package_Reader_Port_Interface $packages Packages.
	 * @param Worksheet_Document_Canonicalizer                 $documents Documents.
	 * @param Worksheet_Revision_Projector                     $projector Projector.
	 * @param Worksheet_Template_Id_Rewriter                   $ids Ids.
	 * @param Stage_Worksheet_Asset                            $stage_asset Stage asset.
	 * @param Save_Worksheet_Draft                             $save_draft Save draft.
	 * @param Worksheet_Asset_Repository_Port_Interface        $asset_repository Asset repository.
	 * @param Worksheet_Asset_Lifecycle_Port_Interface         $asset_lifecycle Asset lifecycle.
	 * @param Private_File_Store_Port_Interface                $private_files Private files.
	 * @param Canonical_Json                                   $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                    $clock Clock.
	 */
	public function __construct(
		private Read_Worksheets $read_worksheets,
		private Worksheet_Template_Package_Reader_Port_Interface $packages,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Revision_Projector $projector,
		private Worksheet_Template_Id_Rewriter $ids,
		private Stage_Worksheet_Asset $stage_asset,
		private Save_Worksheet_Draft $save_draft,
		private Worksheet_Asset_Repository_Port_Interface $asset_repository,
		private Worksheet_Asset_Lifecycle_Port_Interface $asset_lifecycle,
		private Private_File_Store_Port_Interface $private_files,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute import worksheet template.
	 *
	 * @param Import_Worksheet_Template_Command $command Command.
	 * @return Import_Worksheet_Template_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Import_Worksheet_Template_Command $command ): Import_Worksheet_Template_Result {
		$destination  = $this->read_worksheets->revision( $command->actor_user_id(), $command->revision_id() );
		$worksheet_id = is_string( $destination['worksheet_id'] ?? null ) ? $destination['worksheet_id'] : '';

		$package = null;
		$staged  = array();
		try {
			$package = $this->packages->read( $command->upload() );
			$source  = $this->documents->canonicalize( $package->teacher_document() );
			if ( ! hash_equals( $package->source_revision_hash(), $source->document_hash() ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$this->projector->required_standard_public_ids( $package->achievement_links() );

			$identity_asset_map = array();
			foreach ( $package->assets() as $asset ) {
				$identity_asset_map[ $asset['source_asset_id'] ] = $asset['source_asset_id'];
			}
			$this->ids->assert_asset_manifest( $source->document(), $identity_asset_map );

			$asset_map   = array();
			$receipt_ids = array();
			foreach ( $package->assets() as $position => $asset ) {
				$existing = null;
				for ( $attempt = 0; $attempt < 8; ++$attempt ) {
					$stage_command = new Stage_Worksheet_Asset_Command(
						$command->actor_user_id(),
						$worksheet_id,
						$command->asset_command_id( $position, $attempt ),
						$command->expected_state(),
						$command->expected_revision_id(),
						array(
							'name'     => $asset['original_name'],
							'type'     => $asset['mime_type'],
							'tmp_name' => $asset['tmp_name'],
							'error'    => UPLOAD_ERR_OK,
							'size'     => $asset['byte_size'],
						),
						$command->asset_idempotency_fingerprint( $position, $attempt ),
						$command->correlation_id()
					);
					$existing      = $this->existing_stage_receipt( $stage_command, $asset );
					if ( null !== $existing && 'purged' === $existing['state'] ) {
						$existing = null;
						continue;
					}
					if ( null === $existing ) {
						$result   = $this->stage_asset->execute( $stage_command );
						$existing = array(
							'asset_id' => $result->asset_id(),
							'state'    => $result->state(),
						);
					}
					break;
				}
				if ( null === $existing ) {
					throw \OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure::storage_unavailable();
				}
				$asset_map[ $asset['source_asset_id'] ] = $existing['asset_id'];
				$receipt_ids[]                          = $existing['asset_id'];
				$staged[]                               = array(
					'asset_id' => $existing['asset_id'],
					'state'    => $existing['state'],
				);
			}

			$rewritten            = $this->ids->rewrite(
				$source->document(),
				$package->achievement_links(),
				$asset_map,
				$command->rewrite_seed()
			);
			$destination_settings = $destination['teacher_document']['settings'] ?? array();
			if ( isset( $destination_settings['assessment_project'] ) ) {
				$rewritten['teacher_document']['settings']['assessment_project']  = $destination_settings['assessment_project'];
				$rewritten['teacher_document']['settings']['learning_goals']      = array_map(
					static fn ( array $goal ): array => array_replace(
						$goal,
						array(
							'scope'       => 'worksheet',
							'response_id' => null,
						)
					),
					$destination_settings['learning_goals'] ?? array()
				);
				$rewritten['teacher_document']['settings']['assessment_bindings'] = array();
				$rewritten['achievement_links']                                   = array();
			}
			$saved = $this->save_draft->execute(
				new Save_Worksheet_Draft_Command(
					$command->actor_user_id(),
					$worksheet_id,
					$command->command_id(),
					$command->expected_state(),
					$command->expected_revision_id(),
					$rewritten['teacher_document'],
					$rewritten['achievement_links'],
					$receipt_ids,
					$command->save_idempotency_fingerprint(),
					$command->correlation_id(),
					$this->canonical
				)
			);

			return new Import_Worksheet_Template_Result(
				$saved,
				$package->package_hash(),
				$package->source_revision_id(),
				count( $asset_map ),
				$rewritten['reissued_id_count']
			);
		} catch ( Worksheet_Document_Validation_Failure ) {
			$this->compensate( $command, $worksheet_id, $staged );
			throw Worksheet_Template_Import_Failure::package_invalid();
		} catch ( Throwable $failure ) {
			$this->compensate( $command, $worksheet_id, $staged );
			throw $failure;
		} finally {
			if ( $package instanceof Worksheet_Template_Package ) {
				$package->release();
			}
		}
	}

	/**
	 * Existing stage receipt.
	 *
	 * @param Stage_Worksheet_Asset_Command $command Command.
	 * @param array<string,mixed>           $asset Asset.
	 * @return array{asset_id:string,state:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function existing_stage_receipt( Stage_Worksheet_Asset_Command $command, array $asset ): ?array {
		$extension = strtolower( (string) pathinfo( (string) $asset['original_name'], PATHINFO_EXTENSION ) );
		$row       = $this->asset_repository->find_by_storage_key( $command->storage_key( $extension ) );
		if ( null === $row ) {
			return null;
		}
		$matches = $command->actor_user_id() === (int) ( $row['created_by_user_id'] ?? 0 )
			&& 'worksheet' === (string) ( $row['owner_type'] ?? '' )
			&& hash_equals( $command->worksheet_id(), (string) ( $row['owner_public_id'] ?? '' ) )
			&& 'worksheet_asset' === (string) ( $row['purpose'] ?? '' )
			&& hash_equals( (string) $asset['sha256'], (string) ( $row['sha256'] ?? '' ) )
			&& (int) $asset['byte_size'] === (int) ( $row['byte_size'] ?? 0 )
			&& hash_equals( (string) $asset['mime_type'], (string) ( $row['mime_type'] ?? '' ) )
			&& hash_equals( (string) $asset['original_name'], (string) ( $row['original_name'] ?? '' ) )
			&& 'clean' === (string) ( $row['scan_state'] ?? '' )
			&& in_array( (string) ( $row['state'] ?? '' ), array( 'quarantined', 'ready', 'purged' ), true );
		if ( ! $matches ) {
			throw \OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure::idempotency_conflict();
		}
		$asset_id = is_string( $row['public_id'] ?? null ) ? $row['public_id'] : '';
		new \OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id( $asset_id );
		return array(
			'asset_id' => $asset_id,
			'state'    => (string) $row['state'],
		);
	}

	/**
	 * Compensate.
	 *
	 * @param Import_Worksheet_Template_Command              $command Command.
	 * @param string                                         $worksheet_id Worksheet public identifier.
	 * @param array<int,array{asset_id:string,state:string}> $staged Staged.
	 * @return void
	 */
	private function compensate(
		Import_Worksheet_Template_Command $command,
		string $worksheet_id,
		array $staged
	): void {
		$purged_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $purged_at ) ) {
			return;
		}
		foreach ( array_reverse( $staged ) as $receipt ) {
			try {
				$row = $this->asset_lifecycle->find_by_public_id( $receipt['asset_id'] );
				if (
					! is_array( $row )
					|| 'quarantined' !== (string) ( $row['state'] ?? '' )
					|| 'clean' !== (string) ( $row['scan_state'] ?? '' )
					|| $command->actor_user_id() !== (int) ( $row['created_by_user_id'] ?? 0 )
					|| 'worksheet' !== (string) ( $row['owner_type'] ?? '' )
					|| ! hash_equals( $worksheet_id, (string) ( $row['owner_public_id'] ?? '' ) )
					|| 'worksheet_asset' !== (string) ( $row['purpose'] ?? '' )
				) {
					continue;
				}
				$changed = $this->asset_lifecycle->mark_quarantined_purged(
					$command->actor_user_id(),
					$worksheet_id,
					$receipt['asset_id'],
					$purged_at
				);
				if ( $changed ) {
					$this->private_files->delete( (string) ( $row['storage_key'] ?? '' ) );
				}
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
				// A purged metadata row or orphan quarantine is handled by retention cleanup.
			}
		}
	}
}

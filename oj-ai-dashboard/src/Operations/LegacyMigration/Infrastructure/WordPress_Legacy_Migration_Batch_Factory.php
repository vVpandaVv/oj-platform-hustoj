<?php
/**
 * WordPress legacy migration batch factory.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Infrastructure\WordPress_Learner_Display_Snapshot;
use OJAI\Dashboard\Submission\Student\Infrastructure\Clamav_Instream_Submission_Attachment_Scanner;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the complete production batch graph without registering a REST route or worker hook.
 */
final class WordPress_Legacy_Migration_Batch_Factory {
	/**
	 * Create WordPress legacy migration batch factory.
	 *
	 * @param Canonical_Json                    $canonical Canonical JSON encoder used for stable digests.
	 * @param Private_File_Store_Port_Interface $private_files Private files.
	 * @param object|null                       $database Database.
	 * @param Public_Id_Generator|null          $ids Ids.
	 * @return Process_Legacy_Migration_Batch
	 */
	public static function create(
		Canonical_Json $canonical,
		Private_File_Store_Port_Interface $private_files,
		?object $database = null,
		?Public_Id_Generator $ids = null
	): Process_Legacy_Migration_Batch {
		$ids             ??= new Public_Id_Generator();
		$profile           = new Legacy_Source_Profile_0_1_2();
		$owners            = new Legacy_Migration_Owner_Matrix( $profile );
		$source_blobs      = new WordPress_Legacy_Source_Blob_Reader();
		$malware           = new Clamav_Instream_Submission_Attachment_Scanner();
		$database_provider = null === $database ? null : static fn (): object => $database;

		$assignment = new WordPress_Legacy_Migration_Assignment_Writer(
			$canonical,
			new WordPress_Legacy_Migration_Publication_Writer( $canonical, $database, $ids ),
			new WordPress_Learner_Display_Snapshot(),
			$database,
			$ids
		);
		$submission = new WordPress_Legacy_Migration_Submission_Writer(
			$canonical,
			$database,
			$ids,
			$profile,
			null,
			$source_blobs,
			$private_files,
			$malware
		);
		$evaluation = new WordPress_Legacy_Migration_Evaluation_Writer( $canonical, $database, $ids, $profile );
		$zip        = new WordPress_Legacy_Migration_Zip_Writer(
			$canonical,
			$source_blobs,
			$private_files,
			$malware,
			$database,
			$ids,
			$profile
		);
		$targets    = new WordPress_Legacy_Target_Writer(
			$canonical,
			$database,
			$ids,
			$profile,
			null,
			$assignment,
			$submission,
			$evaluation,
			$zip,
			$owners
		);

		return new Process_Legacy_Migration_Batch(
			new WordPress_Prefixed_Legacy_Source_Inspector( $canonical, $profile, $database_provider ),
			new WordPress_Legacy_Migration_Site(),
			new WordPress_Prefixed_Legacy_Source_Item_Reader( $canonical, $profile, $database_provider ),
			new WordPress_Legacy_Migration_Batch_Repository( $targets, $canonical, $database, $ids ),
			new WordPress_Legacy_Migration_Queue_Wakeup(),
			$canonical
		);
	}
}

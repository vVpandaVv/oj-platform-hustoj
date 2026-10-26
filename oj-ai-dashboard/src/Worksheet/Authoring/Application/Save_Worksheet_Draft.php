<?php
/**
 * SaveWorksheetDraft use case.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use Closure;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Achievement_Standard_Snapshot_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates one C02 request and delegates one atomic revision append.
 */
final class Save_Worksheet_Draft {
	/**
	 * Worksheets.
	 *
	 * @var Worksheet_Repository_Port_Interface
	 */
	private Worksheet_Repository_Port_Interface $worksheets;
	/**
	 * Standards.
	 *
	 * @var Achievement_Standard_Snapshot_Port_Interface
	 */
	private Achievement_Standard_Snapshot_Port_Interface $standards;
	/**
	 * Documents.
	 *
	 * @var Worksheet_Document_Canonicalizer
	 */
	private Worksheet_Document_Canonicalizer $documents;
	/**
	 * Projector.
	 *
	 * @var Worksheet_Revision_Projector
	 */
	private Worksheet_Revision_Projector $projector;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize save worksheet draft dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface                                            $worksheets Worksheets.
	 * @param Achievement_Standard_Snapshot_Port_Interface                                   $standards Standards.
	 * @param Worksheet_Document_Canonicalizer                                               $documents Documents.
	 * @param Worksheet_Revision_Projector                                                   $projector Projector.
	 * @param Public_Id_Generator                                                            $ids Ids.
	 * @param Canonical_Json                                                                 $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                                                  $clock Clock.
	 * @param \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct(
		Worksheet_Repository_Port_Interface $worksheets,
		Achievement_Standard_Snapshot_Port_Interface $standards,
		Worksheet_Document_Canonicalizer $documents,
		Worksheet_Revision_Projector $projector,
		Public_Id_Generator $ids,
		Canonical_Json $canonical,
		?callable $clock = null,
		private ?\OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface $projects = null
	) {
		$this->worksheets = $worksheets;
		$this->standards  = $standards;
		$this->documents  = $documents;
		$this->projector  = $projector;
		$this->ids        = $ids;
		$this->canonical  = $canonical;
		$this->clock      = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute save worksheet draft.
	 *
	 * @param Save_Worksheet_Draft_Command $command Command.
	 * @return Save_Worksheet_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Save_Worksheet_Draft_Command $command ): Save_Worksheet_Draft_Result {
		$saved_at = ( $this->clock )();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $saved_at ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft clock returned an invalid UTC value.' );
		}

		$document = $this->documents->canonicalize( $command->teacher_document() );
		$pin      = $document->document()['settings']['assessment_project'] ?? null;
		$project  = $this->projects?->for_draft_save( $command->worksheet_id(), $pin, $command->actor_user_id() );
		if ( ( null !== $pin && null === $project ) || ( null !== $project && null === $pin ) ) {
			throw new InvalidArgumentException( '활동지의 평가 연결을 변경할 수 없습니다.' );
		}
		$achievement_links = $this->remap_achievement_links(
			$command->achievement_links(),
			$document->temporary_id_map()
		);
		$standard_ids      = $this->projector->required_standard_public_ids( $achievement_links );
		if ( 'confirmed' === ( $document->document()['settings']['preparation']['state'] ?? null ) ) {
			\OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preparation::assert_ready( $document->document() );
			if ( array() === $standard_ids && empty( $project['configuration']['achievement_standards'] ) ) {
				throw \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure::property_not_allowed( '$.achievement_links' );
			}
		}
		$standard_snapshots = $this->standards->find_snapshots( $standard_ids );
		$projection         = $this->projector->project(
			$document,
			$achievement_links,
			$standard_snapshots
		);
		$revision_id        = $this->ids->create()->value();
		$audit_id           = $this->ids->create()->value();
		$outbox_id          = $this->ids->create()->value();

		$generated_row_ids = array_merge(
			array( $revision_id, $audit_id, $outbox_id ),
			array_column( $projection->questions(), 'public_id' )
		);
		$issued_ids        = array_merge(
			$generated_row_ids,
			array_values( $document->temporary_id_map() )
		);
		if ( count( $issued_ids ) !== count( array_unique( $issued_ids ) ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft generated duplicate public IDs.' );
		}
		foreach ( $generated_row_ids as $issued_id ) {
			if ( str_contains( $document->document_json(), (string) $issued_id ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft generated an ID already present in the source document.' );
			}
		}

		$worksheet_links    = $projection->worksheet_achievement_links();
		$question_links     = $projection->question_achievement_links();
		$content_hash       = $this->revision_content_hash(
			$document->document_hash(),
			$worksheet_links,
			$question_links
		);
		$canonical_document = $document->document();

		return $this->worksheets->append_draft_revision(
			array(
				'actor_user_id'               => $command->actor_user_id(),
				'command_id'                  => $command->command_id(),
				'correlation_id'              => $command->correlation_id(),
				'saved_at_utc'                => $saved_at,
				'idempotency_storage_key'     => $command->idempotency_storage_key(),
				'request_digest'              => $command->request_digest(),
				'worksheet_public_id'         => $command->worksheet_id(),
				'expected_state'              => $command->expected_state(),
				'expected_revision_public_id' => $command->expected_revision_id(),
				'revision_public_id'          => $revision_id,
				'audit_public_id'             => $audit_id,
				'outbox_public_id'            => $outbox_id,
				'title'                       => (string) $canonical_document['title'],
				'locale'                      => (string) $canonical_document['locale'],
				'document_id'                 => (string) $canonical_document['document_id'],
				'document_json'               => $document->document_json(),
				'document_hash'               => $document->document_hash(),
				'validation_json'             => $document->validation_json(),
				'validation_hash'             => $document->validation_hash(),
				'content_hash'                => $content_hash,
				'temporary_id_map'            => $document->temporary_id_map(),
				'questions'                   => $projection->questions(),
				'worksheet_achievement_links' => $worksheet_links,
				'question_achievement_links'  => $question_links,
				'asset_bindings'              => $projection->asset_bindings(),
				'projection_json'             => $projection->projection_json(),
				'staged_asset_receipt_ids'    => $command->staged_asset_receipt_ids(),
				'projection_hash'             => $projection->projection_hash(),
			)
		);
	}

	/**
	 * Remap achievement links.
	 *
	 * @param array<int,array<string,mixed>> $links Links.
	 * @param array<string,string>           $temporary_id_map Temporary id map.
	 * @return array<int,array<string,mixed>>
	 */
	private function remap_achievement_links( array $links, array $temporary_id_map ): array {
		return array_map(
			static function ( array $link ) use ( $temporary_id_map ): array {
				$response_id = $link['response_id'] ?? null;
				if (
					'response' === ( $link['scope'] ?? null )
					&& is_string( $response_id )
					&& isset( $temporary_id_map[ $response_id ] )
				) {
					$link['response_id'] = $temporary_id_map[ $response_id ];
				}
				return $link;
			},
			$links
		);
	}

	/**
	 * Revision content hash.
	 *
	 * @param string                         $document_hash Document hash.
	 * @param array<int,array<string,mixed>> $worksheet_links Worksheet links.
	 * @param array<int,array<string,mixed>> $question_links Question links.
	 * @return string
	 */
	private function revision_content_hash(
		string $document_hash,
		array $worksheet_links,
		array $question_links
	): string {
		if ( array() === $worksheet_links && array() === $question_links ) {
			return $document_hash;
		}

		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'document_hash'                     => $document_hash,
					'worksheet_achievement_link_hashes' => array_column( $worksheet_links, 'content_hash' ),
					'question_achievement_link_hashes'  => array_column( $question_links, 'content_hash' ),
				)
			)
		);
	}
}

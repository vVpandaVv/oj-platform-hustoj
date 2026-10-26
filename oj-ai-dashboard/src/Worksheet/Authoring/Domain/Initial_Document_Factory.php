<?php
/**
 * Initial canonical worksheet document factory.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the server-owned empty Plate revision used by CreateWorksheet.
 */
final class Initial_Document_Factory {
	public const DOCUMENT_SCHEMA         = Worksheet_Document_Profile::SCHEMA_ID;
	public const DOCUMENT_SCHEMA_VERSION = Worksheet_Document_Profile::SCHEMA_VERSION;
	public const EDITOR_PROFILE          = Worksheet_Document_Profile::EDITOR_PROFILE;
	public const VALIDATION_SCHEMA       = Worksheet_Document_Profile::VALIDATION_SCHEMA;
	public const VALIDATION_VERSION      = Worksheet_Document_Profile::VALIDATION_VERSION;

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
	 * Initialize initial document factory dependencies and state.
	 *
	 * @param Public_Id_Generator $ids Ids.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( Public_Id_Generator $ids, Canonical_Json $canonical ) {
		$this->ids       = $ids;
		$this->canonical = $canonical;
	}

	/**
	 * Create initial document factory.
	 *
	 * @param string     $title Title.
	 * @param string     $locale Locale.
	 * @param array|null $project Project.
	 * @return array{
	 *   document:array<string,mixed>,document_json:string,document_hash:string,
	 *   validation_hash:string,document_id:string,node_ids:array<int,string>
	 * }
	 */
	public function create( string $title, string $locale, ?array $project = null ): array {
		$document_id  = $this->ids->create()->value();
		$heading_id   = $this->ids->create()->value();
		$paragraph_id = $this->ids->create()->value();
		$document     = array(
			'schema_id'      => self::DOCUMENT_SCHEMA,
			'schema_version' => self::DOCUMENT_SCHEMA_VERSION,
			'editor_profile' => self::EDITOR_PROFILE,
			'document_id'    => $document_id,
			'locale'         => $locale,
			'title'          => $title,
			'nodes'          => array(
				array(
					'type'     => 'h1',
					'node_id'  => $heading_id,
					'children' => array( array( 'text' => $title ) ),
				),
				array(
					'type'     => 'p',
					'node_id'  => $paragraph_id,
					'children' => array( array( 'text' => '' ) ),
				),
			),
			'settings'       => array(
				'preparation'        => Worksheet_Preparation::draft(),
				'student_navigation' => 'document',
				'print_profile'      => 'worksheet_a4@1',
			),
		);
		if ( null !== $project ) {
			$document = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::seed( $document, $project );
		}
		$document_json = $this->canonical->encode( $document );
		$validation    = array(
			'schema_id'      => self::VALIDATION_SCHEMA,
			'schema_version' => self::VALIDATION_VERSION,
			'status'         => 'valid',
			'errors'         => array(),
			'warnings'       => array(),
		);

		return array(
			'document'        => $document,
			'document_json'   => $document_json,
			'document_hash'   => hash( 'sha256', $document_json ),
			'validation_hash' => hash( 'sha256', $this->canonical->encode( $validation ) ),
			'document_id'     => $document_id,
			'node_ids'        => array( $heading_id, $paragraph_id ),
		);
	}
}

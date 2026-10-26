<?php
/**
 * Frozen worksheet document profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps worksheet_plate@1 vocabulary and hard limits in one server contract.
 */
final class Worksheet_Document_Profile {
	public const SCHEMA_ID          = 'oj_ai.worksheet_document';
	public const SCHEMA_VERSION     = '1.0.0';
	public const EDITOR_PROFILE     = 'worksheet_plate@1';
	public const VALIDATION_SCHEMA  = 'oj_ai.worksheet_validation';
	public const VALIDATION_VERSION = '1.0.0';

	public const MAX_DOCUMENT_BYTES            = 2097152;
	public const MAX_NODES                     = 5000;
	public const MAX_DEPTH                     = 32;
	public const MAX_TOTAL_TEXT_CHARACTERS     = 500000;
	public const MAX_TEXT_LEAF_CHARACTERS      = 32768;
	public const MAX_RESPONSES                 = 200;
	public const MAX_LEARNING_GOALS            = 50;
	public const MAX_LEARNING_GOAL_CHARACTERS  = 1000;
	public const MAX_ASSESSMENT_BINDINGS       = 100;
	public const MAX_OPTIONS_PER_RESPONSE      = 100;
	public const MAX_OPTION_LABEL_CHARACTERS   = 500;
	public const MAX_CONTENT_TABLE_ROWS        = 100;
	public const MAX_CONTENT_TABLE_COLUMNS     = 20;
	public const MAX_CONTENT_TABLE_CELLS       = 2000;
	public const MAX_RESPONSE_TABLE_ROWS       = 100;
	public const MAX_RESPONSE_TABLE_COLUMNS    = 30;
	public const MAX_RESPONSE_TABLE_CELLS      = 3000;
	public const MAX_BODY_ASSETS               = 100;
	public const MAX_LINK_BYTES                = 2048;
	public const MAX_TITLE_CHARACTERS          = 255;
	public const MAX_RESPONSE_LABEL_CHARACTERS = 1000;
	public const MAX_FILE_BYTES                = 20971520;
	public const MAX_REVISION_ASSET_BYTES      = 209715200;

	public const TEMPORARY_ID_PATTERN = '/^tmp_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

	/**
	 * Worksheet document profile.
	 *
	 * @var array<string,array<int,string>>
	 */
	public const CONTENT_NODE_FAMILIES = array(
		'N01' => array( 'p' ),
		'N02' => array( 'h1', 'h2', 'h3' ),
		'N03' => array( 'blockquote' ),
		'N04' => array( 'ul' ),
		'N05' => array( 'ol' ),
		'N06' => array( 'li' ),
		'N07' => array( 'a' ),
		'N08' => array( 'table' ),
		'N09' => array( 'img', 'audio', 'video' ),
		'N10' => array( 'file' ),
		'N11' => array( 'equation' ),
		'N12' => array( 'code_block' ),
		'N13' => array( 'callout' ),
		'N14' => array( 'column_group' ),
		'N15' => array( 'hr', 'page_break' ),
		'N16' => array( 'oj_ai_drawing' ),
	);

	public const STRUCTURAL_NODE_TYPES   = array( 'tr', 'td', 'th', 'column', 'code_line', 'caption' );
	public const RESPONSE_NODE_TYPE      = 'oj_ai_response';
	public const LEGACY_RESPONSE_ALIASES = array( 'hws_response', 'ojai_response', 'worksheet_response', 'response_field' );
	public const TEXT_MARKS              = array( 'bold', 'italic', 'underline', 'strikethrough' );

	public const RESPONSE_KINDS = array(
		'short_text',
		'long_text',
		'single_choice',
		'multiple_choice',
		'checkbox',
		'number',
		'table',
		'image_upload',
		'file_upload',
		'drawing',
	);

	public const CODE_LANGUAGES = array(
		'plaintext',
		'bash',
		'c',
		'cpp',
		'csharp',
		'css',
		'go',
		'html',
		'java',
		'javascript',
		'json',
		'kotlin',
		'markdown',
		'php',
		'python',
		'ruby',
		'rust',
		'sql',
		'swift',
		'typescript',
		'xml',
		'yaml',
	);

	public const CALLOUT_TONES             = array( 'info', 'notice', 'warning' );
	public const CALLOUT_ICONS             = array( 'info', 'idea', 'notice', 'warning' );
	public const IMAGE_MIME_TYPES          = array( 'image/jpeg', 'image/png', 'image/webp' );
	public const RESPONSE_TABLE_CELL_TYPES = array( 'short_text', 'number', 'checkbox' );

	/**
	 * Recognizes the existing authoring question encoding without changing stored nodes.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return bool
	 */
	public static function is_question_node( array $node ): bool {
		return 'callout' === ( $node['type'] ?? null )
			&& 'notice' === ( $node['tone'] ?? null )
			&& 'idea' === ( $node['icon'] ?? null )
			&& self::contains_response( $node );
	}

	/**
	 * Contains response.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return bool
	 */
	private static function contains_response( array $node ): bool {
		if ( self::RESPONSE_NODE_TYPE === ( $node['type'] ?? null ) ) {
			return true;
		}
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			if ( is_array( $child ) && self::contains_response( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Content node types.
	 *
	 * @return array<int,string>
	 */
	public static function content_node_types(): array {
		$types = array();
		foreach ( self::CONTENT_NODE_FAMILIES as $family ) {
			array_push( $types, ...$family );
		}
		return $types;
	}

	/**
	 * Element node types.
	 *
	 * @return array<int,string>
	 */
	public static function element_node_types(): array {
		return array_merge(
			self::content_node_types(),
			self::STRUCTURAL_NODE_TYPES,
			array( self::RESPONSE_NODE_TYPE )
		);
	}
}

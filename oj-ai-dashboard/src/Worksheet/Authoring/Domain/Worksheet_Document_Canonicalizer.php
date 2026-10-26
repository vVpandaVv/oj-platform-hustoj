<?php
/**
 * Validator and canonicalizer for worksheet_plate@1.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejects profile drift and returns the one canonical document representation.
 */
final class Worksheet_Document_Canonicalizer {
	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;

	/**
	 * Temporary id map.
	 *
	 * @var array<string,string>
	 */
	private array $temporary_id_map = array();

	/**
	 * Defined ids.
	 *
	 * @var array<string,bool>
	 */
	private array $defined_ids = array();
	/**
	 * Response ids.
	 *
	 * @var array<string,bool>
	 */
	private array $response_ids = array();

	/**
	 * Body asset ids.
	 *
	 * @var array<string,bool>
	 */
	private array $body_asset_ids = array();

	/**
	 * Warnings.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $warnings = array();

	/**
	 * Node count.
	 *
	 * @var int
	 */
	private int $node_count = 0;
	/**
	 * Text character count.
	 *
	 * @var int
	 */
	private int $text_character_count = 0;
	/**
	 * Response count.
	 *
	 * @var int
	 */
	private int $response_count = 0;
	/**
	 * H1 count.
	 *
	 * @var int
	 */
	private int $h1_count = 0;
	/**
	 * Last heading level.
	 *
	 * @var int
	 */
	private int $last_heading_level = 0;

	/**
	 * Initialize worksheet document canonicalizer dependencies and state.
	 *
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @param Public_Id_Generator $ids Ids.
	 */
	public function __construct( Canonical_Json $canonical, Public_Id_Generator $ids ) {
		$this->canonical = $canonical;
		$this->ids       = $ids;
	}

	/**
	 * Canonicalize.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return Canonical_Worksheet_Document
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function canonicalize( array $input ): Canonical_Worksheet_Document {
		$this->reset();
		$this->assert_object( $input, '$' );
		$this->assert_properties(
			$input,
			array( 'schema_id', 'schema_version', 'editor_profile', 'document_id', 'locale', 'title', 'nodes', 'settings' ),
			'$'
		);

		foreach ( array( 'schema_id', 'schema_version', 'editor_profile' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_string( $input[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::schema_unsupported( '$.' . $key );
			}
		}
		if (
			Worksheet_Document_Profile::SCHEMA_ID !== $input['schema_id']
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== $input['schema_version']
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== $input['editor_profile']
		) {
			throw Worksheet_Document_Validation_Failure::schema_unsupported( '$' );
		}

		if ( ! array_key_exists( 'document_id', $input ) ) {
			throw Worksheet_Document_Validation_Failure::id_invalid( '$.document_id' );
		}
		$document_id = $this->defined_id( $input['document_id'], '$.document_id', false );
		$locale      = $this->normalize_locale( $this->required_string( $input, 'locale', '$.locale' ) );
		$title       = $this->text_value(
			trim( $this->required_string( $input, 'title', '$.title' ) ),
			'$.title',
			Worksheet_Document_Profile::MAX_TITLE_CHARACTERS,
			true
		);
		if ( '' === $title ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.title' );
		}

		if ( ! array_key_exists( 'nodes', $input ) || ! is_array( $input['nodes'] ) || ! array_is_list( $input['nodes'] ) || array() === $input['nodes'] ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.nodes' );
		}
		$nodes = array();
		foreach ( $input['nodes'] as $index => $node ) {
			$nodes[] = $this->canonical_node( $node, '$.nodes[' . $index . ']', 'root', 1, array() );
		}
		if ( 1 !== $this->h1_count ) {
			throw Worksheet_Document_Validation_Failure::node_not_allowed( '$.nodes' );
		}

		$settings = $this->canonical_settings( $input['settings'] ?? null, '$.settings' );
		if ( count( $this->body_asset_ids ) > Worksheet_Document_Profile::MAX_BODY_ASSETS ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				'$.nodes',
				'body_assets',
				count( $this->body_asset_ids ),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_BODY_ASSETS
			);
		}

		$document      = array(
			'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
			'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
			'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
			'document_id'    => $document_id,
			'locale'         => $locale,
			'title'          => $title,
			'nodes'          => $nodes,
			'settings'       => $settings,
		);
		$document_json = $this->encode_document( $document );
		ksort( $this->temporary_id_map, SORT_STRING );

		$statistics      = array(
			'node_count'           => $this->node_count,
			'response_count'       => $this->response_count,
			'text_character_count' => $this->text_character_count,
			'body_asset_count'     => count( $this->body_asset_ids ),
			'warning_count'        => count( $this->warnings ),
		);
		$validation      = array(
			'schema_id'      => Worksheet_Document_Profile::VALIDATION_SCHEMA,
			'schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
			'status'         => 'valid',
			'errors'         => array(),
			'warnings'       => $this->warnings,
			'statistics'     => $statistics,
		);
		$validation_json = $this->canonical->encode( $validation );

		return new Canonical_Worksheet_Document(
			$document,
			$document_json,
			hash( 'sha256', $document_json ),
			$validation_json,
			hash( 'sha256', $validation_json ),
			$this->temporary_id_map,
			$this->warnings,
			$statistics
		);
	}

	/**
	 * Reset.
	 *
	 * @return void
	 */
	private function reset(): void {
		$this->temporary_id_map     = array();
		$this->defined_ids          = array();
		$this->response_ids         = array();
		$this->body_asset_ids       = array();
		$this->warnings             = array();
		$this->node_count           = 0;
		$this->text_character_count = 0;
		$this->response_count       = 0;
		$this->h1_count             = 0;
		$this->last_heading_level   = 0;
	}

	/**
	 * Canonical node.
	 *
	 * @param mixed             $node Node.
	 * @param string            $path Path.
	 * @param string            $parent_type Parent type.
	 * @param int               $depth Depth.
	 * @param array<int,string> $ancestors Ancestors.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_node( $node, string $path, string $parent_type, int $depth, array $ancestors ): array {
		if ( ! is_array( $node ) || ( array() !== $node && array_is_list( $node ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
		}
		$this->count_node( $path, $depth );

		if ( array_key_exists( 'text', $node ) ) {
			return $this->canonical_text_leaf( $node, $path );
		}
		if ( ! isset( $node['type'] ) || ! is_string( $node['type'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.type' );
		}
		$type = $node['type'];
		if ( ! in_array( $type, Worksheet_Document_Profile::element_node_types(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.type' );
		}
		$this->assert_parent( $type, $parent_type, $ancestors, $path );
		$this->assert_properties( $node, $this->node_properties( $type ), $path );
		if ( ! array_key_exists( 'node_id', $node ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path . '.node_id' );
		}

		$result = array(
			'type'    => $type,
			'node_id' => $this->defined_id( $node['node_id'], $path . '.node_id', true ),
		);
		$this->canonical_node_properties( $node, $result, $type, $path );

		if ( ! array_key_exists( 'children', $node ) || ! is_array( $node['children'] ) || ! array_is_list( $node['children'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.children' );
		}
		$children = array();
		foreach ( $node['children'] as $index => $child ) {
			$children[] = $this->canonical_node(
				$child,
				$path . '.children[' . $index . ']',
				$type,
				$depth + 1,
				array_merge( $ancestors, array( $type ) )
			);
		}
		$this->assert_children( $type, $children, $path );
		$result['children'] = $children;

		if ( 'table' === $type ) {
			$this->assert_content_table( $result, $path );
		}
		$this->track_heading( $type, $path );

		return $result;
	}

	/**
	 * Canonical text leaf.
	 *
	 * @param array<array-key,mixed> $node Node.
	 * @param string                 $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_text_leaf( array $node, string $path ): array {
		$allowed = array_merge( array( 'text' ), Worksheet_Document_Profile::TEXT_MARKS );
		foreach ( $node as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.' . (string) $key );
			}
		}
		if ( ! is_string( $node['text'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.text' );
		}
		$text   = $this->normalize_text( $node['text'], $path . '.text' );
		$length = $this->character_length( $text );
		if ( $length > Worksheet_Document_Profile::MAX_TEXT_LEAF_CHARACTERS ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$path . '.text',
				'text_leaf_characters',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$length,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_TEXT_LEAF_CHARACTERS
			);
		}
		$this->add_text_count( $length, $path . '.text' );
		$result = array( 'text' => $text );
		foreach ( Worksheet_Document_Profile::TEXT_MARKS as $mark ) {
			if ( ! array_key_exists( $mark, $node ) ) {
				continue;
			}
			if ( ! is_bool( $node[ $mark ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.' . $mark );
			}
			if ( true === $node[ $mark ] ) {
				$result[ $mark ] = true;
			}
		}
		return $result;
	}

	/**
	 * Canonical node properties.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param array<string,mixed> &$result Result.
	 * @param string              $type Type.
	 * @param string              $path Path.
	 * @return void
	 * @throws Worksheet_Document_Validation_Failure When a property is invalid.
	 */
	private function canonical_node_properties( array $node, array &$result, string $type, string $path ): void {
		if ( in_array( $type, array( 'p', 'h1', 'h2', 'h3' ), true ) && array_key_exists( 'align', $node ) ) {
			$result['align'] = $this->enum_value( $node['align'], array( 'left', 'center', 'right', 'justify' ), $path . '.align' );
		}
		if ( 'ol' === $type && array_key_exists( 'start', $node ) ) {
			$result['start'] = $this->integer_value( $node['start'], $path . '.start', 1, 1000000 );
		}
		if ( 'a' === $type ) {
			$result['url'] = $this->https_url( $this->required_string( $node, 'url', $path . '.url' ), $path . '.url' );
			if ( array_key_exists( 'label', $node ) ) {
				$result['label'] = $this->nonempty_text( $node['label'], $path . '.label', 500 );
			}
		}
		if ( 'table' === $type && array_key_exists( 'header', $node ) ) {
			$result['header'] = $this->boolean_value( $node['header'], $path . '.header' );
		}
		if ( 'table' === $type && ( array_key_exists( 'width_percent', $node ) || array_key_exists( 'column_ratios', $node ) ) ) {
			$result['width_percent'] = $this->integer_value( $node['width_percent'] ?? null, $path . '.width_percent', 1, 100 );
			$ratios                  = $node['column_ratios'] ?? null;
			if ( array_key_exists( 'column_widths', $node ) || ! is_array( $ratios ) || ! array_is_list( $ratios ) || empty( $ratios ) || count( $ratios ) > Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS ) {
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.column_ratios' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation failure.
			}
			$result['column_ratios'] = array();
			foreach ( $ratios as $index => $ratio ) {
				$result['column_ratios'][] = $this->integer_value( $ratio, $path . '.column_ratios[' . $index . ']', 1, 10000 );
			}
			if ( 10000 !== array_sum( $result['column_ratios'] ) ) {
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.column_ratios' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation failure.
			}
		}
		if ( 'table' === $type && array_key_exists( 'column_widths', $node ) ) {
			$widths = $node['column_widths'];
			if ( ! is_array( $widths ) || ! array_is_list( $widths ) || empty( $widths ) || count( $widths ) > Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS ) {
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.column_widths' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation failure.
			}
			$result['column_widths'] = array();
			foreach ( $widths as $index => $width ) {
				$result['column_widths'][] = $this->integer_value( $width, $path . '.column_widths[' . $index . ']', 40, 1600 );
			}
		}
		if ( 'tr' === $type ) {
			$result['row_id'] = $this->defined_id( $node['row_id'] ?? null, $path . '.row_id', true );
		}
		if ( in_array( $type, array( 'td', 'th' ), true ) ) {
			$result['column_id'] = $this->reference_id( $node['column_id'] ?? null, $path . '.column_id', true );
		}
		if ( in_array( $type, array( 'img', 'audio', 'video', 'file', 'oj_ai_drawing' ), true ) ) {
			$result['asset_id'] = $this->asset_id( $node['asset_id'] ?? null, $path . '.asset_id' );
		}
		if ( 'img' === $type ) {
			$result['alt'] = $this->nonempty_text( $node['alt'] ?? null, $path . '.alt', 2000 );
			if ( array_key_exists( 'width', $node ) ) {
				$result['width'] = $this->integer_value( $node['width'], $path . '.width', 1, 100 );
			}
		}
		if ( in_array( $type, array( 'audio', 'video' ), true ) ) {
			$result['transcript'] = $this->nonempty_text( $node['transcript'] ?? null, $path . '.transcript', 20000 );
		}
		if ( 'video' === $type && array_key_exists( 'poster_asset_id', $node ) ) {
			$result['poster_asset_id'] = $this->asset_id( $node['poster_asset_id'], $path . '.poster_asset_id' );
		}
		if ( 'file' === $type ) {
			$result['label'] = $this->nonempty_text( $node['label'] ?? null, $path . '.label', 500 );
			if ( array_key_exists( 'description', $node ) ) {
				$result['description'] = $this->nonempty_text( $node['description'], $path . '.description', 2000 );
			}
		}
		if ( 'equation' === $type ) {
			$result['tex']     = $this->tex_value( $node['tex'] ?? null, $path . '.tex' );
			$result['display'] = $this->boolean_value( $node['display'] ?? null, $path . '.display' );
		}
		if ( 'code_block' === $type ) {
			$result['language'] = $this->enum_value( $node['language'] ?? null, Worksheet_Document_Profile::CODE_LANGUAGES, $path . '.language' );
		}
		if ( 'callout' === $type ) {
			$result['tone'] = $this->enum_value( $node['tone'] ?? null, Worksheet_Document_Profile::CALLOUT_TONES, $path . '.tone' );
			if ( array_key_exists( 'icon', $node ) ) {
				$result['icon'] = $this->enum_value( $node['icon'], Worksheet_Document_Profile::CALLOUT_ICONS, $path . '.icon' );
			}
		}
		if ( 'oj_ai_drawing' === $type ) {
			$result['alt']                   = $this->nonempty_text( $node['alt'] ?? null, $path . '.alt', 2000 );
			$result['aspect_ratio']          = $this->positive_decimal( $node['aspect_ratio'] ?? null, $path . '.aspect_ratio' );
			$result['stroke_schema_version'] = $this->safe_token( $node['stroke_schema_version'] ?? null, $path . '.stroke_schema_version', 64 );
		}
		if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === $type ) {
			$this->canonical_response_properties( $node, $result, $path );
		}
	}

	/**
	 * Canonical response properties.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param array<string,mixed> &$result Result.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_response_properties( array $node, array &$result, string $path ): void {
		++$this->response_count;
		if ( $this->response_count > Worksheet_Document_Profile::MAX_RESPONSES ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$path,
				'responses',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->response_count,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_RESPONSES
			);
		}
		$result['response_id']                        = $this->defined_id( $node['response_id'] ?? null, $path . '.response_id', true );
		$this->response_ids[ $result['response_id'] ] = true;
		$result['kind']                               = $this->enum_value( $node['kind'] ?? null, Worksheet_Document_Profile::RESPONSE_KINDS, $path . '.kind' );
		$result['label']                              = $this->nonempty_text(
			$node['label'] ?? null,
			$path . '.label',
			Worksheet_Document_Profile::MAX_RESPONSE_LABEL_CHARACTERS
		);
		$result['required']                           = $this->boolean_value( $node['required'] ?? null, $path . '.required' );
		if ( array_key_exists( 'help_text', $node ) ) {
			$result['help_text'] = $this->nonempty_text( $node['help_text'], $path . '.help_text', 4000 );
		}

		$option_ids = array();
		if ( array_key_exists( 'constraints', $node ) ) {
			$result['constraints'] = $this->canonical_response_constraints(
				$result['kind'],
				$node['constraints'],
				$path . '.constraints',
				$option_ids
			);
		} elseif ( in_array( $result['kind'], array( 'single_choice', 'multiple_choice', 'table', 'file_upload' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.constraints' );
		}
		if ( array_key_exists( 'grading', $node ) ) {
			$result['grading'] = $this->canonical_grading( $result['kind'], $node['grading'], $path . '.grading', $option_ids );
		}
	}

	/**
	 * Canonical response constraints.
	 *
	 * @param string            $kind Kind.
	 * @param mixed             $value Value.
	 * @param string            $path Path.
	 * @param array<int,string> &$option_ids Option ids.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_response_constraints( string $kind, $value, string $path, array &$option_ids ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$this->assert_object( $value, $path );
		$allowed = array(
			'short_text'      => array( 'min_chars', 'max_chars', 'trim' ),
			'long_text'       => array( 'min_chars', 'max_chars' ),
			'single_choice'   => array( 'options' ),
			'multiple_choice' => array( 'options', 'min_selected', 'max_selected' ),
			'checkbox'        => array(),
			'number'          => array( 'min', 'max', 'step', 'unit', 'decimal_places' ),
			'table'           => array( 'row_mode', 'rows', 'columns', 'min_rows', 'max_rows' ),
			'image_upload'    => array( 'mime_types', 'max_files', 'max_bytes', 'alt_required' ),
			'file_upload'     => array( 'mime_types', 'max_files', 'max_bytes' ),
			'drawing'         => array( 'stroke_schema_version', 'preview_mime_type', 'aspect_ratio', 'max_strokes' ),
		)[ $kind ];
		$this->assert_properties( $value, $allowed, $path );
		$result = array();

		if ( in_array( $kind, array( 'short_text', 'long_text' ), true ) ) {
			$minimum = array_key_exists( 'min_chars', $value ) ? $this->integer_value( $value['min_chars'], $path . '.min_chars', 0, 32768 ) : 0;
			$maximum = array_key_exists( 'max_chars', $value ) ? $this->integer_value( $value['max_chars'], $path . '.max_chars', 1, 32768 ) : 32768;
			if ( $minimum > $maximum ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			if ( array_key_exists( 'min_chars', $value ) ) {
				$result['min_chars'] = $minimum;
			}
			if ( array_key_exists( 'max_chars', $value ) ) {
				$result['max_chars'] = $maximum;
			}
			if ( 'short_text' === $kind && array_key_exists( 'trim', $value ) ) {
				$result['trim'] = $this->boolean_value( $value['trim'], $path . '.trim' );
			}
		}
		if ( in_array( $kind, array( 'single_choice', 'multiple_choice' ), true ) ) {
			$result['options'] = $this->canonical_options( $value['options'] ?? null, $path . '.options', $option_ids );
			if ( 'multiple_choice' === $kind ) {
				$minimum = array_key_exists( 'min_selected', $value ) ? $this->integer_value( $value['min_selected'], $path . '.min_selected', 0, count( $option_ids ) ) : 0;
				$maximum = array_key_exists( 'max_selected', $value ) ? $this->integer_value( $value['max_selected'], $path . '.max_selected', 1, count( $option_ids ) ) : count( $option_ids );
				if ( $minimum > $maximum ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
				}
				if ( array_key_exists( 'min_selected', $value ) ) {
					$result['min_selected'] = $minimum;
				}
				if ( array_key_exists( 'max_selected', $value ) ) {
					$result['max_selected'] = $maximum;
				}
			}
		}
		if ( 'number' === $kind ) {
			foreach ( array( 'min', 'max' ) as $key ) {
				if ( array_key_exists( $key, $value ) ) {
					$result[ $key ] = $this->decimal_value( $value[ $key ], $path . '.' . $key );
				}
			}
			if ( isset( $result['min'], $result['max'] ) && $this->compare_decimals( $result['min'], $result['max'] ) > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			if ( array_key_exists( 'step', $value ) ) {
				$result['step'] = $this->positive_decimal( $value['step'], $path . '.step' );
			}
			if ( array_key_exists( 'unit', $value ) ) {
				$result['unit'] = $this->nonempty_text( $value['unit'], $path . '.unit', 50 );
			}
			if ( array_key_exists( 'decimal_places', $value ) ) {
				$result['decimal_places'] = $this->integer_value( $value['decimal_places'], $path . '.decimal_places', 0, 12 );
			}
		}
		if ( 'table' === $kind ) {
			$result = $this->canonical_response_table_constraints( $value, $path );
		}
		if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) ) {
			$result = $this->canonical_upload_constraints( $kind, $value, $path );
		}
		if ( 'drawing' === $kind ) {
			foreach ( array( 'stroke_schema_version', 'preview_mime_type', 'aspect_ratio', 'max_strokes' ) as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.' . $key );
				}
			}
			$result = array(
				'stroke_schema_version' => $this->safe_token( $value['stroke_schema_version'], $path . '.stroke_schema_version', 64 ),
				'preview_mime_type'     => $this->enum_value( $value['preview_mime_type'], array( 'image/png' ), $path . '.preview_mime_type' ),
				'aspect_ratio'          => $this->positive_decimal( $value['aspect_ratio'], $path . '.aspect_ratio' ),
				'max_strokes'           => $this->integer_value( $value['max_strokes'], $path . '.max_strokes', 1, 10000 ),
			);
		}
		return $result;
	}

	/**
	 * Canonical options.
	 *
	 * @param mixed             $value Value.
	 * @param string            $path Path.
	 * @param array<int,string> &$option_ids Option ids.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_options( $value, string $path, array &$option_ids ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 2 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		if ( count( $value ) > Worksheet_Document_Profile::MAX_OPTIONS_PER_RESPONSE ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$path,
				'options_per_response',
				count( $value ),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_OPTIONS_PER_RESPONSE
			);
		}
		$result = array();
		$values = array();
		foreach ( $value as $index => $option ) {
			$option_path = $path . '[' . $index . ']';
			if ( ! is_array( $option ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $option_path );
			}
			$this->assert_object( $option, $option_path );
			$this->assert_properties( $option, array( 'option_id', 'value', 'label' ), $option_path );
			$option_id    = $this->defined_id( $option['option_id'] ?? null, $option_path . '.option_id', true );
			$option_value = $this->nonempty_text( $option['value'] ?? null, $option_path . '.value', 255 );
			if ( isset( $values[ $option_value ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $option_path . '.value' );
			}
			$values[ $option_value ] = true;
			$option_ids[]            = $option_id;
			$result[]                = array(
				'option_id' => $option_id,
				'value'     => $option_value,
				'label'     => $this->nonempty_text( $option['label'] ?? null, $option_path . '.label', Worksheet_Document_Profile::MAX_OPTION_LABEL_CHARACTERS ),
			);
		}
		return $result;
	}

	/**
	 * Canonical response table constraints.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_response_table_constraints( array $value, string $path ): array {
		$row_mode = $this->enum_value( $value['row_mode'] ?? null, array( 'fixed', 'dynamic' ), $path . '.row_mode' );
		$rows     = $this->canonical_table_axis( $value['rows'] ?? null, $path . '.rows', 'row_id', Worksheet_Document_Profile::MAX_RESPONSE_TABLE_ROWS, false );
		$columns  = $this->canonical_table_axis( $value['columns'] ?? null, $path . '.columns', 'column_id', Worksheet_Document_Profile::MAX_RESPONSE_TABLE_COLUMNS, true );
		if ( array() === $columns || ( 'fixed' === $row_mode && array() === $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result         = array(
			'row_mode' => $row_mode,
			'rows'     => $rows,
			'columns'  => $columns,
		);
		$effective_rows = count( $rows );
		if ( 'fixed' === $row_mode ) {
			if ( array_key_exists( 'min_rows', $value ) || array_key_exists( 'max_rows', $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
		} else {
			if ( ! array_key_exists( 'min_rows', $value ) || ! array_key_exists( 'max_rows', $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$minimum = $this->integer_value( $value['min_rows'], $path . '.min_rows', 0, Worksheet_Document_Profile::MAX_RESPONSE_TABLE_ROWS );
			$maximum = $this->integer_value( $value['max_rows'], $path . '.max_rows', 1, Worksheet_Document_Profile::MAX_RESPONSE_TABLE_ROWS );
			if ( $minimum > $maximum || count( $rows ) > $maximum ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$result['min_rows'] = $minimum;
			$result['max_rows'] = $maximum;
			$effective_rows     = $maximum;
		}
		$cells = $effective_rows * count( $columns );
		if ( $cells > Worksheet_Document_Profile::MAX_RESPONSE_TABLE_CELLS ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$path,
				'response_table_cells',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$cells,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_RESPONSE_TABLE_CELLS
			);
		}
		return $result;
	}

	/**
	 * Canonical table axis.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param string $id_key Id key.
	 * @param int    $maximum Maximum.
	 * @param bool   $with_cell_type With cell type.
	 * @return array<int,array<string,string>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_table_axis( $value, string $path, string $id_key, int $maximum, bool $with_cell_type ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result = array();
		foreach ( $value as $index => $entry ) {
			$entry_path = $path . '[' . $index . ']';
			if ( ! is_array( $entry ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $entry_path );
			}
			$this->assert_object( $entry, $entry_path );
			$allowed = $with_cell_type ? array( $id_key, 'label', 'cell_type' ) : array( $id_key, 'label' );
			$this->assert_properties( $entry, $allowed, $entry_path );
			$item = array(
				$id_key => $this->defined_id( $entry[ $id_key ] ?? null, $entry_path . '.' . $id_key, true ),
				'label' => $this->nonempty_text( $entry['label'] ?? null, $entry_path . '.label', 500 ),
			);
			if ( $with_cell_type ) {
				$item['cell_type'] = $this->enum_value( $entry['cell_type'] ?? null, Worksheet_Document_Profile::RESPONSE_TABLE_CELL_TYPES, $entry_path . '.cell_type' );
			}
			$result[] = $item;
		}
		return $result;
	}

	/**
	 * Canonical upload constraints.
	 *
	 * @param string              $kind Kind.
	 * @param array<string,mixed> $value Value.
	 * @param string              $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_upload_constraints( string $kind, array $value, string $path ): array {
		$result = array();
		if ( array_key_exists( 'mime_types', $value ) ) {
			$result['mime_types'] = $this->mime_types( $value['mime_types'], $path . '.mime_types', 'image_upload' === $kind );
		} elseif ( 'file_upload' === $kind ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.mime_types' );
		}
		if ( array_key_exists( 'max_files', $value ) ) {
			$result['max_files'] = $this->integer_value( $value['max_files'], $path . '.max_files', 1, 20 );
		}
		if ( array_key_exists( 'max_bytes', $value ) ) {
			$result['max_bytes'] = $this->integer_value( $value['max_bytes'], $path . '.max_bytes', 1, Worksheet_Document_Profile::MAX_FILE_BYTES );
		}
		if ( 'image_upload' === $kind && array_key_exists( 'alt_required', $value ) ) {
			$result['alt_required'] = $this->boolean_value( $value['alt_required'], $path . '.alt_required' );
		}
		return $result;
	}

	/**
	 * Mime types.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param bool   $image_only Image only.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mime_types( $value, string $path, bool $image_only ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > 20 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result = array();
		foreach ( $value as $index => $mime ) {
			if ( ! is_string( $mime ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/D', $mime ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '[' . $index . ']' );
			}
			if ( $image_only && ! in_array( $mime, Worksheet_Document_Profile::IMAGE_MIME_TYPES, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '[' . $index . ']' );
			}
			if ( in_array( $mime, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '[' . $index . ']' );
			}
			$result[] = $mime;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Canonical grading.
	 *
	 * @param string            $kind Kind.
	 * @param mixed             $value Value.
	 * @param string            $path Path.
	 * @param array<int,string> $option_ids Option ids.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_grading( string $kind, $value, string $path, array $option_ids ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$this->assert_object( $value, $path );
		$this->assert_properties(
			$value,
			array( 'grading_enabled', 'max_points', 'correct_answer', 'example_answer', 'rubric_criterion_ids', 'teacher_note' ),
			$path
		);
		$result = array();
		if ( array_key_exists( 'grading_enabled', $value ) ) {
			$result['grading_enabled'] = $this->boolean_value( $value['grading_enabled'], $path . '.grading_enabled' );
		}
		if ( array_key_exists( 'max_points', $value ) ) {
			$result['max_points'] = $this->nonnegative_decimal( $value['max_points'], $path . '.max_points' );
		}
		if ( true === ( $result['grading_enabled'] ?? false ) && ! isset( $result['max_points'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.max_points' );
		}
		foreach ( array( 'correct_answer', 'example_answer' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$result[ $key ] = $this->canonical_answer( $kind, $value[ $key ], $path . '.' . $key, $option_ids );
			}
		}
		if ( array_key_exists( 'rubric_criterion_ids', $value ) ) {
			$result['rubric_criterion_ids'] = $this->public_id_list( $value['rubric_criterion_ids'], $path . '.rubric_criterion_ids' );
		}
		if ( array_key_exists( 'teacher_note', $value ) ) {
			$result['teacher_note'] = $this->nonempty_text( $value['teacher_note'], $path . '.teacher_note', 10000 );
		}
		return $result;
	}

	/**
	 * Canonical answer.
	 *
	 * @param string            $kind Kind.
	 * @param mixed             $value Value.
	 * @param string            $path Path.
	 * @param array<int,string> $option_ids Option ids.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_answer( string $kind, $value, string $path, array $option_ids ) {
		if ( null === $value ) {
			return null;
		}
		if ( in_array( $kind, array( 'short_text', 'long_text' ), true ) ) {
			return $this->nonempty_text( $value, $path, 32768 );
		}
		if ( 'single_choice' === $kind ) {
			$id = $this->reference_id( $value, $path, false );
			if ( ! in_array( $id, $option_ids, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $path );
			}
			return $id;
		}
		if ( 'multiple_choice' === $kind ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			$result = array();
			foreach ( $value as $index => $id_value ) {
				$id = $this->reference_id( $id_value, $path . '[' . $index . ']', false );
				if ( ! in_array( $id, $option_ids, true ) || in_array( $id, $result, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::id_invalid( $path . '[' . $index . ']' );
				}
				$result[] = $id;
			}
			sort( $result, SORT_STRING );
			return $result;
		}
		if ( 'checkbox' === $kind ) {
			return $this->boolean_value( $value, $path );
		}
		if ( 'number' === $kind ) {
			return $this->decimal_value( $value, $path );
		}
		if ( 'table' === $kind ) {
			if ( ! is_array( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			try {
				$canonical_json = $this->canonical->encode( $value );
			} catch ( InvalidArgumentException $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
			}
			return json_decode( $canonical_json, true, 512, JSON_THROW_ON_ERROR );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
	}

	/**
	 * Reject invalid content table.
	 *
	 * @param array<string,mixed> $result Result.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_content_table( array $result, string $path ): void {
		$rows      = $result['children'];
		$row_count = count( $rows );
		if ( $row_count > Worksheet_Document_Profile::MAX_CONTENT_TABLE_ROWS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( $path, 'content_table_rows', $row_count, Worksheet_Document_Profile::MAX_CONTENT_TABLE_ROWS );
		}
		$column_ids = array();
		foreach ( $rows[0]['children'] as $cell ) {
			$column_ids[] = $cell['column_id'];
			$this->register_defined_id( $cell['column_id'], $path . '.children[0].column_id' );
		}
		$column_count = count( $column_ids );
		if ( isset( $result['column_widths'] ) && count( $result['column_widths'] ) !== $column_count ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.column_widths' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation failure.
		}
		if ( isset( $result['column_ratios'] ) && count( $result['column_ratios'] ) !== $column_count ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.column_ratios' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation failure.
		}
		if ( $column_count > Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( $path, 'content_table_columns', $column_count, Worksheet_Document_Profile::MAX_CONTENT_TABLE_COLUMNS );
		}
		foreach ( $rows as $row_index => $row ) {
			$current = array_map( static fn ( array $cell ): string => $cell['column_id'], $row['children'] );
			if ( $current !== $column_ids ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $path . '.children[' . $row_index . '].children' );
			}
		}
	}

	/**
	 * Reject invalid children.
	 *
	 * @param string                         $type Type.
	 * @param array<int,array<string,mixed>> $children Children.
	 * @param string                         $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_children( string $type, array $children, string $path ): void {
		$kinds = array_map(
			static fn ( array $child ): string => array_key_exists( 'text', $child ) ? '#text' : (string) $child['type'],
			$children
		);
		if ( in_array( $type, array( 'p', 'h1', 'h2', 'h3' ), true ) ) {
			$this->require_child_types( $kinds, array( '#text', 'a', 'equation' ), $path, true );
			return;
		}
		if ( in_array( $type, array( 'a', 'caption', 'code_line' ), true ) ) {
			$this->require_child_types( $kinds, array( '#text' ), $path, true );
			return;
		}
		if ( in_array( $type, array( 'ul', 'ol' ), true ) ) {
			$this->require_child_types( $kinds, array( 'li' ), $path, true );
			return;
		}
		if ( 'table' === $type ) {
			$this->require_child_types( $kinds, array( 'tr' ), $path, true );
			return;
		}
		if ( 'tr' === $type ) {
			$this->require_child_types( $kinds, array( 'td', 'th' ), $path, true );
			return;
		}
		if ( 'code_block' === $type ) {
			$this->require_child_types( $kinds, array( 'code_line' ), $path, true );
			return;
		}
		if ( 'column_group' === $type ) {
			if ( array( 'column', 'column' ) !== $kinds ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.children' );
			}
			return;
		}
		if ( in_array( $type, array( 'blockquote', 'li', 'td', 'th', 'column', 'callout' ), true ) ) {
			$this->require_child_types(
				$kinds,
				array( 'p', 'h1', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'table', 'img', 'audio', 'video', 'file', 'equation', 'code_block', 'callout', 'column_group', 'hr', 'page_break', 'oj_ai_drawing', 'oj_ai_response' ),
				$path,
				true
			);
			return;
		}
		if ( in_array( $type, array( 'img', 'audio', 'video', 'oj_ai_drawing' ), true ) ) {
			if ( array( '#text' ) === $kinds && '' === $children[0]['text'] ) {
				return;
			}
			if ( array( 'caption' ) === $kinds ) {
				return;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.children' );
		}
		if ( in_array( $type, array( 'file', 'equation', 'hr', 'page_break', Worksheet_Document_Profile::RESPONSE_NODE_TYPE ), true ) ) {
			if ( array( '#text' ) !== $kinds || '' !== $children[0]['text'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.children' );
			}
		}
	}

	/**
	 * Require child types.
	 *
	 * @param array<int,string> $actual Actual.
	 * @param array<int,string> $allowed Allowed.
	 * @param string            $path Path.
	 * @param bool              $nonempty Nonempty.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function require_child_types( array $actual, array $allowed, string $path, bool $nonempty ): void {
		if ( $nonempty && array() === $actual ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.children' );
		}
		foreach ( $actual as $index => $type ) {
			if ( ! in_array( $type, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path . '.children[' . $index . ']' );
			}
		}
	}

	/**
	 * Reject invalid parent.
	 *
	 * @param string            $type Type.
	 * @param string            $parent Parent.
	 * @param array<int,string> $ancestors Ancestors.
	 * @param string            $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_parent( string $type, string $parent, array $ancestors, string $path ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$root_types = array( 'p', 'h1', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'table', 'img', 'audio', 'video', 'file', 'equation', 'code_block', 'callout', 'column_group', 'hr', 'page_break', 'oj_ai_drawing', Worksheet_Document_Profile::RESPONSE_NODE_TYPE );
		if ( 'root' === $parent && ! in_array( $type, $root_types, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
		}
		$parents = array(
			'tr'        => array( 'table' ),
			'td'        => array( 'tr' ),
			'th'        => array( 'tr' ),
			'column'    => array( 'column_group' ),
			'code_line' => array( 'code_block' ),
			'caption'   => array( 'img', 'audio', 'video', 'oj_ai_drawing' ),
			'li'        => array( 'ul', 'ol' ),
			'a'         => array( 'p', 'h1', 'h2', 'h3' ),
		);
		if ( isset( $parents[ $type ] ) && ! in_array( $parent, $parents[ $type ], true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
		}
		if ( 'blockquote' === $type && count( array_filter( $ancestors, static fn ( string $ancestor ): bool => 'blockquote' === $ancestor ) ) >= 3 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
		}
		if ( in_array( $type, array( 'ul', 'ol' ), true ) ) {
			$list_depth = count( array_filter( $ancestors, static fn ( string $ancestor ): bool => in_array( $ancestor, array( 'ul', 'ol' ), true ) ) ) + 1;
			if ( $list_depth > 4 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
			}
		}
	}

	/**
	 * Track heading.
	 *
	 * @param string $type Type.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function track_heading( string $type, string $path ): void {
		if ( ! in_array( $type, array( 'h1', 'h2', 'h3' ), true ) ) {
			return;
		}
		$level = (int) substr( $type, 1 );
		if ( 1 === $level ) {
			++$this->h1_count;
			if ( $this->h1_count > 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::node_not_allowed( $path );
			}
		}
		if ( $level > $this->last_heading_level + 1 ) {
			$this->warnings[] = array(
				'code'           => 'worksheet_heading_level_skipped',
				'path'           => $path,
				'previous_level' => $this->last_heading_level,
				'level'          => $level,
			);
		}
		$this->last_heading_level = $level;
	}

	/**
	 * Canonical settings.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_settings( $value, string $path ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$this->assert_object( $value, $path );
		$this->assert_properties( $value, array( 'student_navigation', 'print_profile', 'learning_goals', 'assessment_bindings', 'preparation', 'assessment_project' ), $path );
		$result = array(
			'student_navigation' => $this->enum_value( $value['student_navigation'] ?? null, array( 'document' ), $path . '.student_navigation' ),
			'print_profile'      => $this->enum_value( $value['print_profile'] ?? null, array( 'worksheet_a4@1' ), $path . '.print_profile' ),
		);
		if ( array_key_exists( 'assessment_project', $value ) ) {
			$result['assessment_project'] = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::context( $value['assessment_project'] );
		}
		if ( array_key_exists( 'assessment_bindings', $value ) ) {
			$result['assessment_bindings'] = $this->canonical_assessment_bindings(
				$value['assessment_bindings'],
				$path . '.assessment_bindings'
			);
		}
		if ( array_key_exists( 'learning_goals', $value ) ) {
			$result['learning_goals'] = $this->canonical_learning_goals(
				$value['learning_goals'],
				$path . '.learning_goals'
			);
		}
		if ( array_key_exists( 'preparation', $value ) ) {
			$result['preparation'] = Worksheet_Preparation::canonicalize( $value['preparation'] );
			if ( 'confirmed' === $result['preparation']['state'] && ( empty( $result['learning_goals'] ) || empty( $result['assessment_bindings'] ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.preparation' );
			}
		}
		return $result;
	}

	/**
	 * Canonical learning goals.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_learning_goals( $value, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > Worksheet_Document_Profile::MAX_LEARNING_GOALS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $index => $goal ) {
			$goal_path = $path . '[' . $index . ']';
			if ( ! is_array( $goal ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $goal_path );
			}
			$this->assert_object( $goal, $goal_path );
			$this->assert_properties( $goal, array( 'goal_id', 'text', 'scope', 'response_id' ), $goal_path );
			if ( ! array_key_exists( 'goal_id', $goal ) || ! array_key_exists( 'text', $goal ) || ! array_key_exists( 'scope', $goal ) || ! array_key_exists( 'response_id', $goal ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $goal_path );
			}
			$goal_id = $this->defined_id( $goal['goal_id'], $goal_path . '.goal_id', true );
			if ( isset( $seen[ $goal_id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $goal_path . '.goal_id' );
			}
			$scope       = $this->enum_value( $goal['scope'], array( 'worksheet', 'response' ), $goal_path . '.scope' );
			$response_id = null;
			if ( 'response' === $scope ) {
				$response_id = $this->reference_id( $goal['response_id'], $goal_path . '.response_id', false );
				if ( ! isset( $this->response_ids[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::id_invalid( $goal_path . '.response_id' );
				}
			} elseif ( null !== $goal['response_id'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $goal_path . '.response_id' );
			}
			$seen[ $goal_id ] = true;
			$result[]         = array(
				'goal_id'     => $goal_id,
				'text'        => $this->nonempty_text( $goal['text'], $goal_path . '.text', Worksheet_Document_Profile::MAX_LEARNING_GOAL_CHARACTERS ),
				'scope'       => $scope,
				'response_id' => $response_id,
			);
		}
		return $result;
	}

	/**
	 * Canonical assessment bindings.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_assessment_bindings( $value, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > Worksheet_Document_Profile::MAX_ASSESSMENT_BINDINGS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $index => $binding ) {
			$binding_path = $path . '[' . $index . ']';
			if ( ! is_array( $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $binding_path );
			}
			$this->assert_object( $binding, $binding_path );
			$this->assert_properties( $binding, array( 'criterion_id', 'scope', 'response_id' ), $binding_path );
			if ( ! array_key_exists( 'criterion_id', $binding ) || ! array_key_exists( 'scope', $binding ) || ! array_key_exists( 'response_id', $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $binding_path );
			}
			$criterion_ids = $this->public_id_list( array( $binding['criterion_id'] ), $binding_path . '.criterion_id' );
			$criterion_id  = $criterion_ids[0];
			$scope         = $this->enum_value( $binding['scope'], array( 'worksheet', 'response' ), $binding_path . '.scope' );
			if ( isset( $seen[ $criterion_id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $binding_path . '.criterion_id' );
			}
			$response_id = null;
			if ( 'response' === $scope ) {
				$response_id = $this->reference_id( $binding['response_id'], $binding_path . '.response_id', false );
				if ( ! isset( $this->response_ids[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Document_Validation_Failure::id_invalid( $binding_path . '.response_id' );
				}
			} elseif ( null !== $binding['response_id'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $binding_path . '.response_id' );
			}
			$seen[ $criterion_id ] = true;
			$result[]              = array(
				'criterion_id' => $criterion_id,
				'scope'        => $scope,
				'response_id'  => $response_id,
			);
		}
		return $result;
	}

	/**
	 * Node properties.
	 *
	 * @param string $type Type.
	 * @return array<int,string>
	 */
	private function node_properties( string $type ): array {
		$properties = array( 'type', 'node_id', 'children' );
		$extra      = array(
			'p'              => array( 'align' ),
			'h1'             => array( 'align' ),
			'h2'             => array( 'align' ),
			'h3'             => array( 'align' ),
			'ol'             => array( 'start' ),
			'a'              => array( 'url', 'label' ),
			'table'          => array( 'header', 'column_widths', 'width_percent', 'column_ratios' ),
			'tr'             => array( 'row_id' ),
			'td'             => array( 'column_id' ),
			'th'             => array( 'column_id' ),
			'img'            => array( 'asset_id', 'alt', 'width' ),
			'audio'          => array( 'asset_id', 'transcript' ),
			'video'          => array( 'asset_id', 'transcript', 'poster_asset_id' ),
			'file'           => array( 'asset_id', 'label', 'description' ),
			'equation'       => array( 'tex', 'display' ),
			'code_block'     => array( 'language' ),
			'callout'        => array( 'tone', 'icon' ),
			'oj_ai_drawing'  => array( 'asset_id', 'alt', 'aspect_ratio', 'stroke_schema_version' ),
			'oj_ai_response' => array( 'response_id', 'kind', 'label', 'required', 'help_text', 'constraints', 'grading' ),
		)[ $type ] ?? array();
		return array_merge( $properties, $extra );
	}

	/**
	 * Reject invalid properties.
	 *
	 * @param array<array-key,mixed> $value Value.
	 * @param array<int,string>      $allowed Allowed.
	 * @param string                 $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_properties( array $value, array $allowed, string $path ): void {
		foreach ( $value as $key => $item ) {
			unset( $item );
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::property_not_allowed( $path . '.' . (string) $key );
			}
		}
	}

	/**
	 * Reject invalid object.
	 *
	 * @param array<mixed> $value Value.
	 * @param string       $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_object( array $value, string $path ): void {
		if ( array() !== $value && array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
	}

	/**
	 * Required string.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $key Key.
	 * @param string              $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_string( array $value, string $key, string $path ): string {
		if ( ! array_key_exists( $key, $value ) || ! is_string( $value[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value[ $key ];
	}

	/**
	 * Nonempty text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nonempty_text( $value, string $path, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$result = $this->text_value( trim( $value ), $path, $maximum, true );
		if ( '' === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $result;
	}

	/**
	 * Text value.
	 *
	 * @param string $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @param bool   $reject_controls Reject controls.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_value( string $value, string $path, int $maximum, bool $reject_controls ): string {
		$value = $this->normalize_text( $value, $path );
		if ( $reject_controls && 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$length = $this->character_length( $value );
		if ( $length > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( $path, 'text_characters', $length, $maximum );
		}
		$this->add_text_count( $length, $path );
		return $value;
	}

	/**
	 * Normalize text.
	 *
	 * @param string $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_text( string $value, string $path ): string {
		try {
			return $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		} catch ( InvalidArgumentException $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
	}

	/**
	 * Add text count.
	 *
	 * @param int    $length Length.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function add_text_count( int $length, string $path ): void {
		$this->text_character_count += $length;
		if ( $this->text_character_count > Worksheet_Document_Profile::MAX_TOTAL_TEXT_CHARACTERS ) {
			throw Worksheet_Document_Validation_Failure::limit_exceeded(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$path,
				'total_text_characters',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->text_character_count,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Worksheet_Document_Profile::MAX_TOTAL_TEXT_CHARACTERS
			);
		}
	}

	/**
	 * Count node.
	 *
	 * @param string $path Path.
	 * @param int    $depth Depth.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function count_node( string $path, int $depth ): void {
		if ( $depth > Worksheet_Document_Profile::MAX_DEPTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( $path, 'tree_depth', $depth, Worksheet_Document_Profile::MAX_DEPTH );
		}
		++$this->node_count;
		if ( $this->node_count > Worksheet_Document_Profile::MAX_NODES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( $path, 'nodes', $this->node_count, Worksheet_Document_Profile::MAX_NODES );
		}
	}

	/**
	 * Defined id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param bool   $allow_temporary Allow temporary.
	 * @return string
	 */
	private function defined_id( $value, string $path, bool $allow_temporary ): string {
		$id = $this->normalize_id( $value, $path, $allow_temporary );
		$this->register_defined_id( $id, $path );
		return $id;
	}

	/**
	 * Register defined id.
	 *
	 * @param string $id Id.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function register_defined_id( string $id, string $path ): void {
		if ( isset( $this->defined_ids[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		$this->defined_ids[ $id ] = true;
	}

	/**
	 * Reference id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param bool   $allow_new_temporary Allow new temporary.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reference_id( $value, string $path, bool $allow_new_temporary ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		if ( Public_Id::is_valid( $value ) ) {
			return $value;
		}
		if ( 1 !== preg_match( Worksheet_Document_Profile::TEMPORARY_ID_PATTERN, $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		if ( isset( $this->temporary_id_map[ $value ] ) ) {
			return $this->temporary_id_map[ $value ];
		}
		if ( ! $allow_new_temporary ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		return $this->map_temporary_id( $value );
	}

	/**
	 * Normalize id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param bool   $allow_temporary Allow temporary.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_id( $value, string $path, bool $allow_temporary ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		if ( Public_Id::is_valid( $value ) ) {
			return $value;
		}
		if ( $allow_temporary && 1 === preg_match( Worksheet_Document_Profile::TEMPORARY_ID_PATTERN, $value ) ) {
			return $this->temporary_id_map[ $value ] ?? $this->map_temporary_id( $value );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Worksheet_Document_Validation_Failure::id_invalid( $path );
	}

	/**
	 * Map temporary id.
	 *
	 * @param string $temporary_id Temporary id.
	 * @return string
	 */
	private function map_temporary_id( string $temporary_id ): string {
		do {
			$stable_id = $this->ids->create()->value();
		} while ( isset( $this->defined_ids[ $stable_id ] ) || in_array( $stable_id, $this->temporary_id_map, true ) );
		$this->temporary_id_map[ $temporary_id ] = $stable_id;
		return $stable_id;
	}

	/**
	 * Asset id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function asset_id( $value, string $path ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		$this->body_asset_ids[ $value ] = true;
		return $value;
	}

	/**
	 * Public id list.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id_list( $value, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::id_invalid( $path );
		}
		$result = array();
		foreach ( $value as $index => $id ) {
			if ( ! is_string( $id ) || ! Public_Id::is_valid( $id ) || in_array( $id, $result, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Document_Validation_Failure::id_invalid( $path . '[' . $index . ']' );
			}
			$result[] = $id;
		}
		return $result;
	}

	/**
	 * Enum value.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @param string            $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function enum_value( $value, array $allowed, string $path ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value;
	}

	/**
	 * Boolean value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function boolean_value( $value, string $path ): bool {
		if ( ! is_bool( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value;
	}

	/**
	 * Integer value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer_value( $value, string $path, int $minimum, int $maximum ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value;
	}

	/**
	 * Safe token.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( $value, string $path, int $maximum ): string {
		if ( ! is_string( $value ) || strlen( $value ) < 1 || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value;
	}

	/**
	 * Https url.
	 *
	 * @param string $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function https_url( string $value, string $path ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > Worksheet_Document_Profile::MAX_LINK_BYTES || 1 === preg_match( '/[\x00-\x20\x7F]/', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$parts = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves the exact absolute-URL validation contract.
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $value;
	}

	/**
	 * Tex value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function tex_value( $value, string $path ): string {
		$tex = $this->nonempty_text( $value, $path, 10000 );
		if ( 1 === preg_match( '/\\\\(?:def|gdef|edef|xdef|newcommand|renewcommand|providecommand|input|include|write|catcode|csname|usepackage|href|url)\b/i', $tex ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $tex;
	}

	/**
	 * Decimal value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_value( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', trim( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		$value      = trim( $value );
		$negative   = str_starts_with( $value, '-' );
		$unsigned   = $negative ? substr( $value, 1 ) : $value;
		$parts      = explode( '.', $unsigned, 2 );
		$integer    = ltrim( $parts[0], '0' );
		$integer    = '' === $integer ? '0' : $integer;
		$fraction   = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		$normalized = $integer . ( '' === $fraction ? '' : '.' . $fraction );
		if ( '0' === $normalized ) {
			return '0';
		}
		return $negative ? '-' . $normalized : $normalized;
	}

	/**
	 * Nonnegative decimal.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nonnegative_decimal( $value, string $path ): string {
		$decimal = $this->decimal_value( $value, $path );
		if ( str_starts_with( $decimal, '-' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $decimal;
	}

	/**
	 * Positive decimal.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_decimal( $value, string $path ): string {
		$decimal = $this->nonnegative_decimal( $value, $path );
		if ( '0' === $decimal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::property_not_allowed( $path );
		}
		return $decimal;
	}

	/**
	 * Compare decimals.
	 *
	 * @param string $left Left.
	 * @param string $right Right.
	 * @return int
	 */
	private function compare_decimals( string $left, string $right ): int {
		$left_negative  = str_starts_with( $left, '-' );
		$right_negative = str_starts_with( $right, '-' );
		if ( $left_negative !== $right_negative ) {
			return $left_negative ? -1 : 1;
		}
		$left_parts  = explode( '.', ltrim( $left, '-' ), 2 );
		$right_parts = explode( '.', ltrim( $right, '-' ), 2 );
		$comparison  = strlen( $left_parts[0] ) <=> strlen( $right_parts[0] );
		if ( 0 === $comparison ) {
			$comparison = strcmp( $left_parts[0], $right_parts[0] );
		}
		if ( 0 === $comparison ) {
			$length     = max( strlen( $left_parts[1] ?? '' ), strlen( $right_parts[1] ?? '' ) );
			$comparison = strcmp( str_pad( $left_parts[1] ?? '', $length, '0' ), str_pad( $right_parts[1] ?? '', $length, '0' ) );
		}
		$comparison = $comparison <=> 0;
		return $left_negative ? -$comparison : $comparison;
	}

	/**
	 * Normalize locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_locale( string $locale ): string {
		$locale = str_replace( '_', '-', trim( $locale ) );
		if ( strlen( $locale ) > 16 || 1 !== preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $locale ) ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$.locale' );
		}
		$parts      = explode( '-', $locale );
		$parts[0]   = strtolower( $parts[0] );
		$part_count = count( $parts );
		for ( $index = 1; $index < $part_count; ++$index ) {
			$parts[ $index ] = 4 === strlen( $parts[ $index ] ) ? ucfirst( strtolower( $parts[ $index ] ) ) : strtoupper( $parts[ $index ] );
		}
		return implode( '-', $parts );
	}

	/**
	 * Encode document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function encode_document( array $document ): string {
		try {
			$json = $this->canonical->encode( $document );
		} catch ( InvalidArgumentException $failure ) {
			throw Worksheet_Document_Validation_Failure::property_not_allowed( '$' );
		}
		$bytes = strlen( $json );
		if ( $bytes > Worksheet_Document_Profile::MAX_DOCUMENT_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Document_Validation_Failure::limit_exceeded( '$', 'document_bytes', $bytes, Worksheet_Document_Profile::MAX_DOCUMENT_BYTES );
		}
		return $json;
	}

	/**
	 * Character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function character_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? PHP_INT_MAX : $count;
	}
}

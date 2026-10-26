<?php
/**
 * Legacy worksheet document converter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Plate_Document_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a strict legacy Plate template into the current canonical worksheet aggregate input.
 */
final class Legacy_Worksheet_Document_Converter {
	private const TYPE_ALIASES = array(
		'paragraph'     => 'p',
		'heading_one'   => 'h1',
		'heading_two'   => 'h2',
		'heading_three' => 'h3',
		'block_quote'   => 'blockquote',
		'bulleted_list' => 'ul',
		'bulleted-list' => 'ul',
		'numbered_list' => 'ol',
		'numbered-list' => 'ol',
		'list_item'     => 'li',
		'list-item'     => 'li',
		'pagebreak'     => 'page_break',
	);

	private const RESPONSE_ALIASES = array(
		'short'          => 'short_text',
		'short_answer'   => 'short_text',
		'text'           => 'short_text',
		'textarea'       => 'long_text',
		'long_answer'    => 'long_text',
		'essay'          => 'long_text',
		'select'         => 'single_choice',
		'radio'          => 'single_choice',
		'choice'         => 'single_choice',
		'single_select'  => 'single_choice',
		'checkbox_group' => 'multiple_choice',
		'checklist'      => 'multiple_choice',
		'multi_select'   => 'multiple_choice',
		'image'          => 'image_upload',
		'file'           => 'file_upload',
		'paint'          => 'drawing',
	);

	private const RESPONSE_NODE_TYPES = array( 'ojai_response', 'worksheet_response', 'response_field', 'hws_response', 'oj_ai_response' );

	/**
	 * Question index.
	 *
	 * @var int
	 */
	private int $question_index = 0;
	/**
	 * H1 count.
	 *
	 * @var int
	 */
	private int $h1_count = 0;
	/**
	 * Questions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $questions = array();
	/**
	 * Legacy question response ids.
	 *
	 * @var array<string,string>
	 */
	private array $legacy_question_response_ids = array();
	/**
	 * Legacy questions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $legacy_questions = array();

	/**
	 * Initialize legacy worksheet document converter dependencies and state.
	 *
	 * @param Plate_Document_Service           $legacy Legacy.
	 * @param Worksheet_Document_Canonicalizer $documents Documents.
	 * @param Worksheet_Revision_Projector     $projector Projector.
	 * @param Public_Id_Generator              $ids Ids.
	 */
	public function __construct(
		private Plate_Document_Service $legacy,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Revision_Projector $projector,
		private Public_Id_Generator $ids
	) {
	}

	/**
	 * Convert.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array{title:string,document:Canonical_Worksheet_Document,projection:Worksheet_Revision_Projection,legacy_worksheet_links:array<int,mixed>,legacy_question_response_ids:array<string,string>,legacy_questions:array<string,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function convert( array $record ): array {
		$this->reset();
		$title            = $this->required_text( $record['title'] ?? null, 255 );
		$plate            = $this->decode_plate( $record['plate_value_json'] ?? null );
		$worksheet_rubric = $this->decode_object( $record['worksheet_rubric_json'] ?? null );
		$settings         = $this->decode_object( $record['settings_json'] ?? null );
		$curriculum_links = $this->decode_list( $record['curriculum_links_json'] ?? null );
		if ( array() !== $worksheet_rubric || array() !== $settings ) {
			throw new InvalidArgumentException( 'Legacy worksheet settings require an explicit modern mapping.' );
		}
		$rubric_mode = $record['rubric_mode'] ?? null;
		if ( ! is_string( $rubric_mode ) || ! in_array( $rubric_mode, array( 'worksheet', 'question' ), true ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet rubric mode is invalid.' );
		}

		$legacy_projection = $this->legacy->build_schemas(
			$plate,
			array(
				'title'            => $title,
				'rubric_mode'      => $rubric_mode,
				'worksheet_rubric' => $worksheet_rubric,
				'curriculum_links' => $curriculum_links,
			)
		);
		$normalized_nodes  = $legacy_projection['plate_value'] ?? null;
		$questions         = $legacy_projection['teacher_schema']['questions'] ?? null;
		if ( ! is_array( $normalized_nodes ) || ! array_is_list( $normalized_nodes ) || ! is_array( $questions ) || ! array_is_list( $questions ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet projection is invalid.' );
		}
		$this->questions = $questions;
		$nodes           = array();
		foreach ( $normalized_nodes as $index => $node ) {
			$nodes[] = $this->node( $node, '$.nodes[' . $index . ']' );
		}
		if ( 0 === $this->h1_count ) {
			array_unshift(
				$nodes,
				array(
					'type'     => 'h1',
					'node_id'  => $this->id(),
					'children' => array( array( 'text' => $title ) ),
				)
			);
			$this->h1_count = 1;
		}
		if ( 1 !== $this->h1_count || $this->question_index !== count( $this->questions ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet node inventory is inconsistent.' );
		}

		$document   = $this->documents->canonicalize(
			array(
				'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
				'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
				'editor_profile' => Worksheet_Document_Profile::EDITOR_PROFILE,
				'document_id'    => $this->id(),
				'locale'         => 'ko-KR',
				'title'          => $title,
				'nodes'          => $nodes,
				'settings'       => array(
					'student_navigation' => 'document',
					'print_profile'      => 'worksheet_a4@1',
				),
			)
		);
		$projection = $this->projector->project( $document, array(), array() );
		return array(
			'title'                        => $title,
			'document'                     => $document,
			'projection'                   => $projection,
			'legacy_worksheet_links'       => $curriculum_links,
			'legacy_question_response_ids' => $this->legacy_question_response_ids,
			'legacy_questions'             => $this->legacy_questions,
		);
	}

	/**
	 * Reset.
	 *
	 * @return void
	 */
	private function reset(): void {
		$this->question_index               = 0;
		$this->h1_count                     = 0;
		$this->questions                    = array();
		$this->legacy_question_response_ids = array();
		$this->legacy_questions             = array();
	}

	/**
	 * Decode plate.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_plate( mixed $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > Worksheet_Document_Profile::MAX_DOCUMENT_BYTES ) {
			throw new InvalidArgumentException( 'Legacy Plate document is missing or too large.' );
		}
		try {
			$decoded = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Legacy Plate document is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( is_array( $decoded ) && ! array_is_list( $decoded ) && isset( $decoded['plate_value'] ) && is_array( $decoded['plate_value'] ) ) {
			$decoded = $decoded['plate_value'];
		}
		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) || array() === $decoded ) {
			throw new InvalidArgumentException( 'Legacy Plate document is invalid.' );
		}
		return $decoded;
	}

	/**
	 * Decode object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_object( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		$value = $this->decode_json( $value );
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet object JSON is invalid.' );
		}
		return $value;
	}

	/**
	 * Decode list.
	 *
	 * @param mixed $value Value.
	 * @return array<int,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_list( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		$value = $this->decode_json( $value );
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet list JSON is invalid.' );
		}
		return $value;
	}

	/**
	 * Decode json.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_json( mixed $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > Worksheet_Document_Profile::MAX_DOCUMENT_BYTES ) {
			throw new InvalidArgumentException( 'Legacy worksheet JSON is invalid.' );
		}
		try {
			return json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Legacy worksheet JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
	}

	/**
	 * Node.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function node( mixed $value, string $path ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'Legacy Plate node is invalid at ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		if ( array_key_exists( 'text', $value ) ) {
			return $this->text_leaf( $value, $path );
		}
		if ( $this->is_response( $value ) ) {
			return $this->response( $value, $path );
		}
		$type = $value['type'] ?? null;
		if ( ! is_string( $type ) || '' === $type ) {
			throw new InvalidArgumentException( 'Legacy Plate node type is invalid.' );
		}
		$type      = self::TYPE_ALIASES[ $type ] ?? $type;
		$supported = array( 'p', 'h1', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'li', 'a', 'equation', 'hr', 'page_break' );
		if ( ! in_array( $type, $supported, true ) ) {
			throw new InvalidArgumentException( 'Legacy Plate node type requires an explicit mapping: ' . $type ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		if ( 'h1' === $type ) {
			++$this->h1_count;
		}
		$result = array(
			'type'    => $type,
			'node_id' => $this->id(),
		);
		if ( in_array( $type, array( 'p', 'h1', 'h2', 'h3' ), true ) && isset( $value['align'] ) ) {
			$result['align'] = $value['align'];
		}
		if ( 'ol' === $type && isset( $value['start'] ) ) {
			$result['start'] = $this->integer( $value['start'], 1, 1000000 );
		}
		if ( 'a' === $type ) {
			$result['url'] = $this->required_text( $value['url'] ?? null, Worksheet_Document_Profile::MAX_LINK_BYTES );
			if ( isset( $value['label'] ) ) {
				$result['label'] = $this->required_text( $value['label'], 500 );
			}
		}
		if ( 'equation' === $type ) {
			$result['tex']     = $this->required_text( $value['tex'] ?? null, 10000 );
			$result['display'] = (bool) ( $value['display'] ?? false );
		}
		if ( in_array( $type, array( 'equation', 'hr', 'page_break' ), true ) ) {
			$result['children'] = array( array( 'text' => '' ) );
			return $result;
		}
		$children = $value['children'] ?? null;
		if ( ! is_array( $children ) || ! array_is_list( $children ) || array() === $children ) {
			$children = array( array( 'text' => '' ) );
		}
		$result['children'] = array();
		foreach ( $children as $index => $child ) {
			$result['children'][] = $this->node( $child, $path . '.children[' . $index . ']' );
		}
		if ( in_array( $type, array( 'blockquote', 'li' ), true ) && $this->text_children_only( $result['children'] ) ) {
			$result['children'] = array(
				array(
					'type'     => 'p',
					'node_id'  => $this->id(),
					'children' => $result['children'],
				),
			);
		}
		return $result;
	}

	/**
	 * Text leaf.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text_leaf( array $value, string $path ): array {
		if ( ! is_string( $value['text'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy Plate text is invalid at ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		$result = array( 'text' => $value['text'] );
		foreach ( array( 'bold', 'italic', 'underline', 'strikethrough' ) as $mark ) {
			if ( true === ( $value[ $mark ] ?? false ) ) {
				$result[ $mark ] = true;
			}
		}
		foreach ( $value as $key => $item ) {
			if ( ! in_array( $key, array( 'text', 'bold', 'italic', 'underline', 'strikethrough' ), true ) && ! in_array( $item, array( null, false, '' ), true ) ) {
				throw new InvalidArgumentException( 'Legacy Plate text mark requires an explicit mapping: ' . (string) $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
		}
		return $result;
	}

	/**
	 * Is response.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return bool
	 */
	private function is_response( array $node ): bool {
		$type = (string) ( $node['type'] ?? '' );
		return in_array( $type, self::RESPONSE_NODE_TYPES, true ) || isset( $node['input_type'] ) || isset( $node['response_type'] ) || isset( $node['response_kind'] );
	}

	/**
	 * Response.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param string              $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response( array $node, string $path ): array {
		$question = $this->questions[ $this->question_index ] ?? null;
		if ( ! is_array( $question ) ) {
			throw new InvalidArgumentException( 'Legacy response projection is missing at ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		++$this->question_index;
		$kind = $this->response_kind( $node );
		if ( $kind !== ( $question['type'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy response kind changed during projection.' );
		}
		$legacy_question_id = $this->required_text( $question['question_id'] ?? null, 191 );
		if ( isset( $this->legacy_question_response_ids[ $legacy_question_id ] ) || isset( $this->legacy_questions[ $legacy_question_id ] ) ) {
			throw new InvalidArgumentException( 'Legacy question ID is duplicated.' );
		}
		$response_id = $this->id();
		$this->legacy_question_response_ids[ $legacy_question_id ] = $response_id;
		$this->legacy_questions[ $legacy_question_id ]             = $question;
		$result      = array(
			'type'        => Worksheet_Document_Profile::RESPONSE_NODE_TYPE,
			'node_id'     => $this->id(),
			'response_id' => $response_id,
			'kind'        => $kind,
			'label'       => $this->required_text( $question['prompt'] ?? $question['title'] ?? null, Worksheet_Document_Profile::MAX_RESPONSE_LABEL_CHARACTERS ),
			'required'    => (bool) ( $question['required'] ?? false ),
			'children'    => array( array( 'text' => '' ) ),
		);
		$option_ids  = array();
		$constraints = $this->response_constraints( $kind, $question, $option_ids );
		if ( null !== $constraints ) {
			$result['constraints'] = $constraints;
		}
		$grading = $this->grading( $kind, $question, $option_ids );
		if ( array() !== $grading ) {
			$result['grading'] = $grading;
		}
		return $result;
	}

	/**
	 * Response kind.
	 *
	 * @param array<string,mixed> $node Node.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_kind( array $node ): string {
		$value = null;
		foreach ( array( 'input_type', 'response_type', 'response_kind', 'kind' ) as $key ) {
			if ( is_string( $node[ $key ] ?? null ) && '' !== $node[ $key ] ) {
				$value = strtolower( $node[ $key ] );
				break;
			}
		}
		$value = null === $value ? null : ( self::RESPONSE_ALIASES[ $value ] ?? $value );
		if ( ! is_string( $value ) || ! in_array( $value, Worksheet_Document_Profile::RESPONSE_KINDS, true ) ) {
			throw new InvalidArgumentException( 'Legacy response kind requires an explicit mapping.' );
		}
		return $value;
	}

	/**
	 * Response constraints.
	 *
	 * @param string               $kind Kind.
	 * @param array<string,mixed>  $question Question.
	 * @param array<string,string> &$option_ids Option ids.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_constraints( string $kind, array $question, array &$option_ids ): ?array {
		if ( in_array( $kind, array( 'single_choice', 'multiple_choice' ), true ) ) {
			$options = $question['options'] ?? null;
			if ( ! is_array( $options ) || ! array_is_list( $options ) || count( $options ) < 2 ) {
				throw new InvalidArgumentException( 'Legacy choice options are invalid.' );
			}
			$result = array();
			foreach ( $options as $option ) {
				if ( ! is_array( $option ) ) {
					throw new InvalidArgumentException( 'Legacy choice option is invalid.' );
				}
				$value = $this->required_text( $option['value'] ?? null, 255 );
				if ( isset( $option_ids[ $value ] ) ) {
					throw new InvalidArgumentException( 'Legacy choice option value is duplicated.' );
				}
				$option_id            = $this->id();
				$option_ids[ $value ] = $option_id;
				$result[]             = array(
					'option_id' => $option_id,
					'value'     => $value,
					'label'     => $this->required_text( $option['label'] ?? null, Worksheet_Document_Profile::MAX_OPTION_LABEL_CHARACTERS ),
				);
			}
			return array( 'options' => $result );
		}
		if ( 'table' === $kind ) {
			$table = $question['table'] ?? null;
			if ( ! is_array( $table ) ) {
				throw new InvalidArgumentException( 'Legacy response table is invalid.' );
			}
			$rows = array();
			foreach ( $table['rows'] ?? array() as $row ) {
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( 'Legacy response table row is invalid.' );
				}
				$rows[] = array(
					'row_id' => $this->id(),
					'label'  => $this->required_text( $row['label'] ?? null, 500 ),
				);
			}
			$columns = array();
			foreach ( $table['columns'] ?? array() as $column ) {
				if ( ! is_array( $column ) ) {
					throw new InvalidArgumentException( 'Legacy response table column is invalid.' );
				}
				$cell_type = array(
					'text'       => 'short_text',
					'short_text' => 'short_text',
					'number'     => 'number',
					'checkbox'   => 'checkbox',
				)[ (string) ( $column['input_type'] ?? '' ) ] ?? null;
				if ( null === $cell_type ) {
					throw new InvalidArgumentException( 'Legacy response table cell type requires an explicit mapping.' );
				}
				$columns[] = array(
					'column_id' => $this->id(),
					'label'     => $this->required_text( $column['label'] ?? null, 500 ),
					'cell_type' => $cell_type,
				);
			}
			if ( array() === $rows || array() === $columns ) {
				throw new InvalidArgumentException( 'Legacy response table axis is empty.' );
			}
			return array(
				'row_mode' => 'fixed',
				'rows'     => $rows,
				'columns'  => $columns,
			);
		}
		if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) ) {
			$upload = $question['upload'] ?? null;
			if ( ! is_array( $upload ) ) {
				throw new InvalidArgumentException( 'Legacy upload constraint is invalid.' );
			}
			$mimes = $upload['accepted_file_types'] ?? null;
			if ( ! is_array( $mimes ) || ! array_is_list( $mimes ) || array() === $mimes ) {
				throw new InvalidArgumentException( 'Legacy upload MIME list is invalid.' );
			}
			foreach ( $mimes as $mime ) {
				if ( ! is_string( $mime ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/D', $mime ) ) {
					throw new InvalidArgumentException( 'Legacy upload MIME is invalid.' );
				}
			}
			$result = array(
				'mime_types' => array_values( array_unique( $mimes ) ),
				'max_files'  => $this->integer( $upload['max_files'] ?? null, 1, 20 ),
				'max_bytes'  => $this->integer( $upload['max_file_size_mb'] ?? null, 1, 20 ) * 1048576,
			);
			if ( 'image_upload' === $kind ) {
				$result['alt_required'] = false;
			}
			return $result;
		}
		if ( 'drawing' === $kind ) {
			return array(
				'stroke_schema_version' => 'oj_ai.drawing_strokes@1',
				'preview_mime_type'     => 'image/png',
				'aspect_ratio'          => '1',
				'max_strokes'           => 10000,
			);
		}
		return null;
	}

	/**
	 * Grading.
	 *
	 * @param string               $kind Kind.
	 * @param array<string,mixed>  $question Question.
	 * @param array<string,string> $option_ids Option ids.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function grading( string $kind, array $question, array $option_ids ): array {
		if ( is_array( $question['rubric'] ?? null ) && array() !== $question['rubric'] ) {
			throw new InvalidArgumentException( 'Legacy question rubric requires an explicit modern mapping.' );
		}
		$enabled = (bool) ( $question['grading_enabled'] ?? false );
		$result  = array( 'grading_enabled' => $enabled );
		if ( $enabled ) {
			$result['max_points'] = $this->decimal( $question['points'] ?? null );
		}
		foreach ( array( 'correct_answer', 'example_answer' ) as $key ) {
			$value = $question[ $key ] ?? null;
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$result[ $key ] = $this->answer( $kind, $value, $option_ids );
		}
		if ( is_string( $question['teacher_note'] ?? null ) && '' !== trim( $question['teacher_note'] ) ) {
			$result['teacher_note'] = $this->required_text( $question['teacher_note'], 10000 );
		}
		return $result;
	}

	/**
	 * Answer.
	 *
	 * @param string               $kind Kind.
	 * @param mixed                $value Value.
	 * @param array<string,string> $option_ids Option ids.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function answer( string $kind, mixed $value, array $option_ids ) {
		if ( in_array( $kind, array( 'short_text', 'long_text' ), true ) ) {
			return $this->required_text( $value, 32768 );
		}
		if ( 'single_choice' === $kind ) {
			$key = (string) $value;
			if ( ! isset( $option_ids[ $key ] ) ) {
				throw new InvalidArgumentException( 'Legacy single-choice answer is invalid.' );
			}
			return $option_ids[ $key ];
		}
		if ( 'multiple_choice' === $kind ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				throw new InvalidArgumentException( 'Legacy multiple-choice answer is invalid.' );
			}
			$result = array();
			foreach ( $value as $entry ) {
				$key = (string) $entry;
				if ( ! isset( $option_ids[ $key ] ) ) {
					throw new InvalidArgumentException( 'Legacy multiple-choice answer is invalid.' );
				}
				$result[] = $option_ids[ $key ];
			}
			return array_values( array_unique( $result ) );
		}
		if ( 'checkbox' === $kind ) {
			if ( true === $value || 1 === $value || '1' === $value || 'true' === $value ) {
				return true;
			}
			if ( false === $value || 0 === $value || '0' === $value || 'false' === $value ) {
				return false;
			}
			throw new InvalidArgumentException( 'Legacy checkbox answer is invalid.' );
		}
		if ( 'number' === $kind ) {
			return $this->decimal( $value );
		}
		if ( 'table' === $kind && is_array( $value ) ) {
			return $value;
		}
		throw new InvalidArgumentException( 'Legacy answer requires an explicit modern mapping.' );
	}

	/**
	 * Text children only.
	 *
	 * @param array<int,array<string,mixed>> $children Children.
	 * @return bool
	 */
	private function text_children_only( array $children ): bool {
		foreach ( $children as $child ) {
			if ( ! array_key_exists( 'text', $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Required text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy text is invalid.' );
		}
		$value  = trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( '' === $value || $length > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Legacy text is invalid.' );
		}
		return $value;
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( mixed $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || $value < 0 ) {
				throw new InvalidArgumentException( 'Legacy decimal is invalid.' );
			}
			$value = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
			if ( '' === $value ) {
				$value = '0';
			}
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy decimal is invalid.' );
		}
		return $value;
	}

	/**
	 * Integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @param int   $maximum Maximum.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function integer( mixed $value, int $minimum, int $maximum ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			throw new InvalidArgumentException( 'Legacy integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	private function id(): string {
		return $this->ids->create()->value();
	}
}

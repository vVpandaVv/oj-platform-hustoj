<?php
/**
 * Plate document schema extraction.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Worksheet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Plate values into teacher, student, and grading schemas.
 */
final class Plate_Document_Service {
	/**
	 * Response node types accepted by the integrated editor.
	 *
	 * @var string[]
	 */
	private $response_node_types = array(
		'ojai_response',
		'worksheet_response',
		'response_field',
		'hws_response',
	);

	/**
	 * Return a default editable Plate document.
	 *
	 * @param string $title Worksheet title.
	 * @return array
	 */
	public function default_plate_value( $title = '' ) {
		return array(
			array(
				'type'     => 'h1',
				'children' => array(
					array( 'text' => '' !== (string) $title ? (string) $title : '활동지' ),
				),
			),
			array(
				'type'     => 'p',
				'children' => array(
					array( 'text' => '' ),
				),
			),
		);
	}

	/**
	 * Resolve Plate value from a template payload.
	 *
	 * @param array $payload Request or import payload.
	 * @return array
	 */
	public function resolve_plate_value( array $payload ) {
		$candidates = array(
			isset( $payload['plate_value'] ) ? $payload['plate_value'] : null,
			isset( $payload['plate_value_json'] ) ? $payload['plate_value_json'] : null,
			isset( $payload['schema_json'] ) ? $payload['schema_json'] : null,
			isset( $payload['template']['plate_value'] ) ? $payload['template']['plate_value'] : null,
			isset( $payload['template']['plate_value_json'] ) ? $payload['template']['plate_value_json'] : null,
		);

		foreach ( $candidates as $candidate ) {
			$value = $this->extract_plate_value( $candidate );
			if ( ! empty( $value ) ) {
				return $this->normalize_plate_value( $value );
			}
		}

		return $this->default_plate_value( isset( $payload['title'] ) ? (string) $payload['title'] : '' );
	}

	/**
	 * Normalize a Plate value.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function normalize_plate_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $this->default_plate_value();
		}

		$nodes = array();
		foreach ( $value as $node ) {
			if ( is_array( $node ) ) {
				$nodes[] = $this->normalize_node( $node );
			}
		}

		if ( empty( $nodes ) ) {
			return $this->default_plate_value();
		}

		$last = $nodes[ count( $nodes ) - 1 ];
		if ( ! isset( $last['type'] ) || 'p' !== (string) $last['type'] ) {
			$nodes[] = array(
				'type'     => 'p',
				'children' => array(
					array( 'text' => '' ),
				),
			);
		}

		return $nodes;
	}

	/**
	 * Build separated schemas and question rows.
	 *
	 * @param array $plate_value Plate value.
	 * @param array $context Schema context.
	 * @return array
	 */
	public function build_schemas( array $plate_value, array $context = array() ) {
		$title            = isset( $context['title'] ) ? (string) $context['title'] : '';
		$rubric_mode      = isset( $context['rubric_mode'] ) ? $this->rubric_mode( $context['rubric_mode'] ) : 'worksheet';
		$worksheet_rubric = isset( $context['worksheet_rubric'] ) && is_array( $context['worksheet_rubric'] ) ? $context['worksheet_rubric'] : array();
		$curriculum_links = isset( $context['curriculum_links'] ) && is_array( $context['curriculum_links'] ) ? $context['curriculum_links'] : array();
		$normalized       = $this->normalize_plate_value( $plate_value );
		$questions        = $this->collect_questions( $normalized );
		$rubric_policy    = $this->normalize_rubric_policy( $rubric_mode, $worksheet_rubric, $questions );
		$rubric_mode      = $rubric_policy['rubric_mode'];
		$worksheet_rubric = $rubric_policy['worksheet_rubric'];
		$questions        = $rubric_policy['questions'];
		$normalized       = $this->normalize_plate_rubrics( $normalized, $rubric_mode );
		$teacher_schema   = $this->teacher_schema( $normalized, $questions, $title, $rubric_mode, $worksheet_rubric, $curriculum_links, $rubric_policy );
		$student_schema   = $this->student_schema( $questions, $title, $curriculum_links );
		$grading_schema   = $this->grading_schema( $questions, $title, $rubric_mode, $worksheet_rubric, $curriculum_links, $rubric_policy );

		return array(
			'version'          => 1,
			'engine'           => 'plate',
			'plate_value'      => $normalized,
			'rubric_mode'      => $rubric_mode,
			'worksheet_rubric' => $worksheet_rubric,
			'curriculum_links' => $curriculum_links,
			'rubric_policy'    => $rubric_policy,
			'teacher_schema'   => $teacher_schema,
			'student_schema'   => $student_schema,
			'grading_schema'   => $grading_schema,
			'questions'        => $this->question_rows( $questions ),
		);
	}

	/**
	 * Generate the teacher schema.
	 *
	 * @param array  $plate_value Plate value.
	 * @param array  $questions Questions.
	 * @param string $title Title.
	 * @param string $rubric_mode Rubric mode.
	 * @param array  $worksheet_rubric Worksheet rubric.
	 * @param array  $curriculum_links Curriculum links.
	 * @param array  $rubric_policy Rubric policy.
	 * @return array
	 */
	private function teacher_schema( array $plate_value, array $questions, $title, $rubric_mode, array $worksheet_rubric, array $curriculum_links, array $rubric_policy ) {
		return array(
			'version'             => 1,
			'engine'              => 'plate',
			'title'               => $title,
			'plate_value'         => $plate_value,
			'rubric_mode'         => $rubric_mode,
			'worksheet_rubric'    => $worksheet_rubric,
			'rubric_policy'       => $rubric_policy,
			'curriculum_links'    => $curriculum_links,
			'questions'           => $questions,
			'hidden_from_student' => array(
				'points',
				'correct_answer',
				'example_answer',
				'teacher_note',
				'rubric',
			),
		);
	}

	/**
	 * Generate the student schema without answer keys or scoring metadata.
	 *
	 * @param array  $questions Questions.
	 * @param string $title Title.
	 * @param array  $curriculum_links Curriculum links.
	 * @return array
	 */
	private function student_schema( array $questions, $title, array $curriculum_links ) {
		$student_questions = array();
		foreach ( $questions as $question ) {
			$student_questions[] = $this->student_question( $question );
		}

		return array(
			'version'          => 1,
			'engine'           => 'plate_student',
			'title'            => $title,
			'curriculum_links' => $curriculum_links,
			'questions'        => $student_questions,
		);
	}

	/**
	 * Generate the grading schema.
	 *
	 * @param array  $questions Questions.
	 * @param string $title Title.
	 * @param string $rubric_mode Rubric mode.
	 * @param array  $worksheet_rubric Worksheet rubric.
	 * @param array  $curriculum_links Curriculum links.
	 * @param array  $rubric_policy Rubric policy.
	 * @return array
	 */
	private function grading_schema( array $questions, $title, $rubric_mode, array $worksheet_rubric, array $curriculum_links, array $rubric_policy ) {
		$grading_questions = array();
		foreach ( $questions as $question ) {
			$grading_questions[] = array(
				'question_id'      => $question['question_id'],
				'block_id'         => $question['block_id'],
				'type'             => $question['type'],
				'title'            => $question['title'],
				'points'           => $question['points'],
				'grading_enabled'  => $question['grading_enabled'],
				'correct_answer'   => $question['correct_answer'],
				'example_answer'   => $question['example_answer'],
				'teacher_note'     => $question['teacher_note'],
				'answer_schema'    => $this->answer_schema( $question ),
				'rubric'           => $question['rubric'],
				'curriculum_links' => $question['curriculum_links'],
			);
		}

		return array(
			'version'          => 1,
			'engine'           => 'plate_grading',
			'title'            => $title,
			'rubric_mode'      => $rubric_mode,
			'worksheet_rubric' => $worksheet_rubric,
			'rubric_policy'    => $rubric_policy,
			'curriculum_links' => $curriculum_links,
			'questions'        => $grading_questions,
		);
	}

	/**
	 * Extract an array Plate value from mixed input.
	 *
	 * @param mixed $candidate Candidate value.
	 * @return array
	 */
	private function extract_plate_value( $candidate ) {
		if ( is_array( $candidate ) ) {
			if ( isset( $candidate['plate_value'] ) && is_array( $candidate['plate_value'] ) ) {
				return $candidate['plate_value'];
			}

			return $candidate;
		}

		if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
			return array();
		}

		$decoded = json_decode( $candidate, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		if ( isset( $decoded['plate_value'] ) && is_array( $decoded['plate_value'] ) ) {
			return $decoded['plate_value'];
		}

		return $decoded;
	}

	/**
	 * Normalize one Plate node.
	 *
	 * @param array $node Raw node.
	 * @return array
	 */
	private function normalize_node( array $node ) {
		if ( isset( $node['text'] ) ) {
			$node['text'] = (string) $node['text'];
			return $node;
		}

		if ( empty( $node['type'] ) ) {
			$node['type'] = 'p';
		}

		$children = array();
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$children[] = $this->normalize_node( $child );
				}
			}
		}

		if ( empty( $children ) ) {
			$children[] = array( 'text' => '' );
		}

		$node['children'] = $children;
		return $node;
	}

	/**
	 * Collect response blocks from Plate value.
	 *
	 * @param array $plate_value Plate value.
	 * @return array
	 */
	private function collect_questions( array $plate_value ) {
		$questions = array();

		foreach ( $plate_value as $index => $node ) {
			$this->visit_node( $node, array( $index ), $questions );
		}

		return $questions;
	}

	/**
	 * Visit a Plate node recursively.
	 *
	 * @param array $node Node.
	 * @param array $path Node path.
	 * @param array &$questions Collected questions.
	 * @return void
	 */
	private function visit_node( array $node, array $path, array &$questions ) {
		if ( $this->is_response_node( $node ) ) {
			$questions[] = $this->question_from_node( $node, $path, count( $questions ) );
		}

		if ( empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
			return;
		}

		foreach ( $node['children'] as $index => $child ) {
			if ( is_array( $child ) ) {
				$next_path   = $path;
				$next_path[] = $index;
				$this->visit_node( $child, $next_path, $questions );
			}
		}
	}

	/**
	 * Determine whether a Plate node is a custom response block.
	 *
	 * @param array $node Node.
	 * @return bool
	 */
	private function is_response_node( array $node ) {
		$type = isset( $node['type'] ) ? (string) $node['type'] : '';

		if ( in_array( $type, $this->response_node_types, true ) ) {
			return true;
		}

		return isset( $node['input_type'] ) || isset( $node['response_type'] ) || isset( $node['response_kind'] );
	}

	/**
	 * Convert a response node into a question schema item.
	 *
	 * @param array $node Node.
	 * @param array $path Node path.
	 * @param int   $index Question index.
	 * @return array
	 */
	private function question_from_node( array $node, array $path, $index ) {
		$type        = $this->response_type( $node );
		$question_id = $this->stable_id( 'q', $node, $path, $index, array( 'question_id', 'qid', 'name', 'id' ) );
		$block_id    = $this->stable_id( 'b', $node, $path, $index, array( 'block_id', 'id', 'name' ) );
		$title       = $this->node_label( $node, $type, $index );
		$table       = 'table' === $type ? $this->table_config( $node ) : array();
		$upload      = $this->upload_config( $node, $type );

		return array(
			'question_id'      => $question_id,
			'block_id'         => $block_id,
			'type'             => $type,
			'title'            => $title,
			'prompt'           => isset( $node['prompt'] ) ? $this->text( $node['prompt'] ) : $title,
			'required'         => ! empty( $node['required'] ),
			'sort_order'       => (int) $index,
			'points'           => $this->decimal( isset( $node['points'] ) ? $node['points'] : 0 ),
			'grading_enabled'  => ! isset( $node['grading_enabled'] ) || (bool) $node['grading_enabled'],
			'correct_answer'   => $this->answer_value( $node ),
			'example_answer'   => isset( $node['example_answer'] ) ? $node['example_answer'] : ( isset( $node['example'] ) ? $node['example'] : '' ),
			'teacher_note'     => isset( $node['teacher_note'] ) ? $node['teacher_note'] : ( isset( $node['teacher_memo'] ) ? $node['teacher_memo'] : '' ),
			'options'          => $this->options( isset( $node['options'] ) ? $node['options'] : array() ),
			'table'            => $table,
			'attachments'      => $this->attachments_config( $node, $type ),
			'upload'           => $upload,
			'rubric'           => isset( $node['rubric'] ) && is_array( $node['rubric'] ) ? $node['rubric'] : array(),
			'curriculum_links' => isset( $node['curriculum_links'] ) && is_array( $node['curriculum_links'] ) ? $node['curriculum_links'] : array(),
		);
	}

	/**
	 * Return student-visible question configuration.
	 *
	 * @param array $question Teacher question.
	 * @return array
	 */
	private function student_question( array $question ) {
		$student = array(
			'question_id'   => $question['question_id'],
			'block_id'      => $question['block_id'],
			'type'          => $question['type'],
			'title'         => $question['title'],
			'prompt'        => $question['prompt'],
			'required'      => $question['required'],
			'options'       => $this->student_options( $question['options'] ),
			'table'         => $this->student_table( $question['table'] ),
			'attachments'   => $question['attachments'],
			'upload'        => $question['upload'],
			'answer_schema' => $this->answer_schema( $question ),
		);

		if ( empty( $student['options'] ) ) {
			unset( $student['options'] );
		}

		if ( empty( $student['table'] ) ) {
			unset( $student['table'] );
		}

		if ( empty( $student['attachments'] ) ) {
			unset( $student['attachments'] );
		}

		if ( empty( $student['upload'] ) ) {
			unset( $student['upload'] );
		}

		return $student;
	}

	/**
	 * Return the student answer shape for one question.
	 *
	 * @param array $question Question schema.
	 * @return array
	 */
	private function answer_schema( array $question ) {
		$type = isset( $question['type'] ) ? (string) $question['type'] : 'long_text';

		$schema = array(
			'type'     => $type,
			'required' => ! empty( $question['required'] ),
			'default'  => $this->default_answer_value( $question ),
		);

		if ( ! empty( $question['options'] ) ) {
			$schema['allowed_values'] = array_map(
				function ( $option ) {
					return isset( $option['value'] ) ? (string) $option['value'] : '';
				},
				$question['options']
			);
		}

		if ( 'table' === $type ) {
			$schema['table'] = $this->table_answer_schema( isset( $question['table'] ) && is_array( $question['table'] ) ? $question['table'] : array() );
		}

		if ( in_array( $type, array( 'image_upload', 'file_upload', 'drawing' ), true ) ) {
			$schema['upload'] = isset( $question['upload'] ) && is_array( $question['upload'] ) ? $question['upload'] : array();
		}

		return $schema;
	}

	/**
	 * Return a default answer value for an input type.
	 *
	 * @param array $question Question schema.
	 * @return mixed
	 */
	private function default_answer_value( array $question ) {
		$type = isset( $question['type'] ) ? (string) $question['type'] : 'long_text';

		if ( 'checkbox' === $type ) {
			return false;
		}

		if ( 'multiple_choice' === $type ) {
			return array();
		}

		if ( 'number' === $type ) {
			return null;
		}

		if ( 'table' === $type ) {
			return $this->default_table_answer( isset( $question['table'] ) && is_array( $question['table'] ) ? $question['table'] : array() );
		}

		if ( in_array( $type, array( 'image_upload', 'file_upload' ), true ) ) {
			return array(
				'files' => array(),
			);
		}

		if ( 'drawing' === $type ) {
			return array(
				'drawing' => null,
			);
		}

		return '';
	}

	/**
	 * Return a table answer schema.
	 *
	 * @param array $table Table schema.
	 * @return array
	 */
	private function table_answer_schema( array $table ) {
		$cells = array();
		foreach ( isset( $table['cells'] ) && is_array( $table['cells'] ) ? $table['cells'] : array() as $cell ) {
			$cells[] = array(
				'row_key'    => isset( $cell['row_key'] ) ? (string) $cell['row_key'] : '',
				'column_key' => isset( $cell['column_key'] ) ? (string) $cell['column_key'] : '',
				'input_type' => isset( $cell['input_type'] ) ? (string) $cell['input_type'] : 'text',
				'default'    => $this->default_cell_value( isset( $cell['input_type'] ) ? (string) $cell['input_type'] : 'text' ),
			);
		}

		return array(
			'cells' => $cells,
		);
	}

	/**
	 * Return a default table answer keyed by row and column.
	 *
	 * @param array $table Table schema.
	 * @return array
	 */
	private function default_table_answer( array $table ) {
		$answer = array();
		$rows   = isset( $table['rows'] ) && is_array( $table['rows'] ) ? $table['rows'] : array();
		$cols   = isset( $table['columns'] ) && is_array( $table['columns'] ) ? $table['columns'] : array();

		foreach ( $rows as $row ) {
			$row_key = isset( $row['key'] ) ? (string) $row['key'] : '';
			if ( '' === $row_key ) {
				continue;
			}

			$answer[ $row_key ] = array();
			foreach ( $cols as $col ) {
				$col_key = isset( $col['key'] ) ? (string) $col['key'] : '';
				if ( '' === $col_key ) {
					continue;
				}

				$answer[ $row_key ][ $col_key ] = $this->default_cell_value( isset( $col['input_type'] ) ? (string) $col['input_type'] : 'text' );
			}
		}

		return $answer;
	}

	/**
	 * Return a default table cell value.
	 *
	 * @param string $type Cell type.
	 * @return mixed
	 */
	private function default_cell_value( $type ) {
		if ( 'checkbox' === $type ) {
			return false;
		}

		if ( 'multiple_choice' === $type ) {
			return array();
		}

		if ( 'number' === $type ) {
			return null;
		}

		return '';
	}

	/**
	 * Return rows stored in worksheet_questions.
	 *
	 * @param array $questions Questions.
	 * @return array
	 */
	private function question_rows( array $questions ) {
		$rows = array();
		foreach ( $questions as $question ) {
			$rows[] = array(
				'question_id'          => $question['question_id'],
				'title'                => $question['title'],
				'sort_order'           => (int) $question['sort_order'],
				'points'               => $this->decimal( $question['points'] ),
				'grading_enabled'      => ! empty( $question['grading_enabled'] ) ? 1 : 0,
				'question_schema_json' => $this->json( $question ),
			);
		}

		return $rows;
	}

	/**
	 * Normalize rubric use so worksheet and question rubrics cannot mix.
	 *
	 * @param string $rubric_mode Rubric mode.
	 * @param array  $worksheet_rubric Worksheet rubric.
	 * @param array  $questions Questions.
	 * @return array
	 */
	private function normalize_rubric_policy( $rubric_mode, array $worksheet_rubric, array $questions ) {
		$rubric_mode              = $this->rubric_mode( $rubric_mode );
		$warnings                 = array();
		$question_rubric_map      = array();
		$missing_question_rubrics = array();

		if ( 'worksheet' === $rubric_mode ) {
			foreach ( $questions as $index => $question ) {
				if ( $this->has_rubric( isset( $question['rubric'] ) && is_array( $question['rubric'] ) ? $question['rubric'] : array() ) ) {
					$warnings[] = array(
						'code'        => 'question_rubric_ignored',
						'question_id' => isset( $question['question_id'] ) ? (string) $question['question_id'] : '',
					);
				}

				$questions[ $index ]['rubric'] = array();
			}

			return array(
				'rubric_mode'              => 'worksheet',
				'worksheet_rubric'         => $worksheet_rubric,
				'questions'                => $questions,
				'question_rubric_map'      => $question_rubric_map,
				'missing_question_rubrics' => $missing_question_rubrics,
				'mixed_use_allowed'        => false,
				'warnings'                 => $warnings,
			);
		}

		if ( $this->has_rubric( $worksheet_rubric ) ) {
			$warnings[] = array(
				'code' => 'worksheet_rubric_ignored',
			);
		}

		foreach ( $questions as $index => $question ) {
			$question_id                         = isset( $question['question_id'] ) ? (string) $question['question_id'] : '';
			$rubric                              = isset( $question['rubric'] ) && is_array( $question['rubric'] ) ? $question['rubric'] : array();
			$question_rubric_map[ $question_id ] = $rubric;

			if ( ! empty( $question['grading_enabled'] ) && ! $this->has_rubric( $rubric ) ) {
				$missing_question_rubrics[] = $question_id;
			}

			$questions[ $index ]['rubric'] = $rubric;
		}

		return array(
			'rubric_mode'              => 'question',
			'worksheet_rubric'         => array(),
			'questions'                => $questions,
			'question_rubric_map'      => $question_rubric_map,
			'missing_question_rubrics' => array_values( array_filter( $missing_question_rubrics ) ),
			'mixed_use_allowed'        => false,
			'warnings'                 => $warnings,
		);
	}

	/**
	 * Check whether a rubric payload has usable content.
	 *
	 * @param array $rubric Rubric.
	 * @return bool
	 */
	private function has_rubric( array $rubric ) {
		foreach ( $rubric as $value ) {
			if ( is_array( $value ) ) {
				if ( $this->has_rubric( $value ) ) {
					return true;
				}

				continue;
			}

			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize rubric fields inside the stored Plate document.
	 *
	 * @param array  $nodes Plate nodes.
	 * @param string $rubric_mode Rubric mode.
	 * @return array
	 */
	private function normalize_plate_rubrics( array $nodes, $rubric_mode ) {
		$normalized = array();
		foreach ( $nodes as $node ) {
			$normalized[] = $this->normalize_plate_node_rubric( is_array( $node ) ? $node : array(), $rubric_mode );
		}

		return $normalized;
	}

	/**
	 * Normalize rubric fields for one Plate node.
	 *
	 * @param array  $node Plate node.
	 * @param string $rubric_mode Rubric mode.
	 * @return array
	 */
	private function normalize_plate_node_rubric( array $node, $rubric_mode ) {
		if ( 'worksheet' === $rubric_mode && isset( $node['rubric'] ) ) {
			unset( $node['rubric'] );
		}

		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			$children = array();
			foreach ( $node['children'] as $child ) {
				$children[] = is_array( $child ) ? $this->normalize_plate_node_rubric( $child, $rubric_mode ) : $child;
			}

			$node['children'] = $children;
		}

		return $node;
	}

	/**
	 * Normalize a response type.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function response_type( array $node ) {
		$type = '';
		foreach ( array( 'input_type', 'response_type', 'response_kind', 'kind' ) as $key ) {
			if ( isset( $node[ $key ] ) && '' !== (string) $node[ $key ] ) {
				$type = strtolower( (string) $node[ $key ] );
				break;
			}
		}

		$map = array(
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
			'image_upload'   => 'image_upload',
			'file'           => 'file_upload',
			'file_upload'    => 'file_upload',
			'paint'          => 'drawing',
		);

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}

		$allowed = array(
			'short_text',
			'long_text',
			'single_choice',
			'multiple_choice',
			'checkbox',
			'table',
			'number',
			'image_upload',
			'file_upload',
			'drawing',
		);

		return in_array( $type, $allowed, true ) ? $type : 'long_text';
	}

	/**
	 * Return a stable id from explicit node ids or a hash.
	 *
	 * @param string   $prefix Prefix.
	 * @param array    $node Node.
	 * @param array    $path Node path.
	 * @param int      $index Question index.
	 * @param string[] $keys Candidate keys.
	 * @return string
	 */
	private function stable_id( $prefix, array $node, array $path, $index, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! empty( $node[ $key ] ) ) {
				return $this->safe_id( $prefix, (string) $node[ $key ] );
			}
		}

		$seed = $this->json(
			array(
				'type'  => isset( $node['type'] ) ? (string) $node['type'] : '',
				'label' => isset( $node['label'] ) ? (string) $node['label'] : $this->node_text( $node ),
				'path'  => $path,
				'index' => (int) $index,
			)
		);

		return $prefix . '_' . substr( hash( 'sha256', $seed ), 0, 16 );
	}

	/**
	 * Sanitize an identifier.
	 *
	 * @param string $prefix Prefix.
	 * @param string $value Value.
	 * @return string
	 */
	private function safe_id( $prefix, $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '_', (string) $value );
		$value = trim( (string) $value, '_' );

		if ( '' === $value ) {
			$value = substr( hash( 'sha256', (string) $prefix ), 0, 16 );
		}

		if ( 0 !== strpos( $value, $prefix . '_' ) ) {
			$value = $prefix . '_' . $value;
		}

		return substr( $value, 0, 191 );
	}

	/**
	 * Return a label for a question.
	 *
	 * @param array  $node Node.
	 * @param string $type Response type.
	 * @param int    $index Index.
	 * @return string
	 */
	private function node_label( array $node, $type, $index ) {
		foreach ( array( 'title', 'label', 'name' ) as $key ) {
			if ( ! empty( $node[ $key ] ) ) {
				return $this->text( $node[ $key ] );
			}
		}

		$text = $this->node_text( $node );
		if ( '' !== $text ) {
			return mb_substr( $text, 0, 80 );
		}

		return ucfirst( str_replace( '_', ' ', $type ) ) . ' ' . ( (int) $index + 1 );
	}

	/**
	 * Read plain text from a node.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function node_text( array $node ) {
		if ( isset( $node['text'] ) ) {
			return trim( (string) $node['text'] );
		}

		$text = '';
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$text .= ' ' . $this->node_text( $child );
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Normalize choice options.
	 *
	 * @param mixed $options Options.
	 * @return array
	 */
	private function options( $options ) {
		if ( is_string( $options ) ) {
			$options = preg_split( '/\r\n|\r|\n|,/', $options );
		}

		if ( ! is_array( $options ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $options as $index => $option ) {
			if ( is_array( $option ) ) {
				$label = isset( $option['label'] ) ? $this->text( $option['label'] ) : $this->text( isset( $option['value'] ) ? $option['value'] : '' );
				$value = isset( $option['value'] ) ? $this->text( $option['value'] ) : $this->safe_id( 'opt', $label );
				if ( '' === $label ) {
					continue;
				}

				$normalized[] = array(
					'value'      => $value,
					'label'      => $label,
					'is_correct' => ! empty( $option['is_correct'] ),
				);
				continue;
			}

			$label = $this->text( $option );
			if ( '' === $label ) {
				continue;
			}

			$normalized[] = array(
				'value'      => $this->safe_id( 'opt', (string) $index . '_' . $label ),
				'label'      => $label,
				'is_correct' => false,
			);
		}

		return $normalized;
	}

	/**
	 * Return student-visible options.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	private function student_options( array $options ) {
		$student_options = array();
		foreach ( $options as $option ) {
			$student_options[] = array(
				'value' => isset( $option['value'] ) ? $option['value'] : '',
				'label' => isset( $option['label'] ) ? $option['label'] : '',
			);
		}

		return $student_options;
	}

	/**
	 * Normalize table configuration.
	 *
	 * @param array $node Node.
	 * @return array
	 */
	private function table_config( array $node ) {
		$row_source    = isset( $node['rows'] ) ? $node['rows'] : ( isset( $node['row_titles'] ) ? $node['row_titles'] : ( isset( $node['row_headers'] ) ? $node['row_headers'] : array() ) );
		$column_source = isset( $node['columns'] ) ? $node['columns'] : ( isset( $node['column_titles'] ) ? $node['column_titles'] : ( isset( $node['column_headers'] ) ? $node['column_headers'] : array() ) );
		$rows          = $this->axis_items( $row_source, 'row' );
		$columns       = $this->table_columns( $column_source );

		if ( empty( $rows ) ) {
			$rows = $this->axis_items( array( '1', '2', '3' ), 'row' );
		}

		if ( empty( $columns ) ) {
			$columns = $this->table_columns( array( 'Response' ) );
		}

		return array(
			'rows'           => $rows,
			'columns'        => $columns,
			'row_headers'    => $this->axis_labels( $rows ),
			'column_headers' => $this->axis_labels( $columns ),
			'cells'          => $this->table_cells( $rows, $columns ),
		);
	}

	/**
	 * Return student-visible table configuration.
	 *
	 * @param array $table Table.
	 * @return array
	 */
	private function student_table( array $table ) {
		if ( empty( $table ) ) {
			return array();
		}

		$columns = array();
		foreach ( isset( $table['columns'] ) && is_array( $table['columns'] ) ? $table['columns'] : array() as $column ) {
			$student_column = array(
				'key'        => isset( $column['key'] ) ? $column['key'] : '',
				'label'      => isset( $column['label'] ) ? $column['label'] : '',
				'input_type' => isset( $column['input_type'] ) ? $column['input_type'] : 'text',
			);

			if ( ! empty( $column['options'] ) && is_array( $column['options'] ) ) {
				$student_column['options'] = $this->student_options( $column['options'] );
			}

			$columns[] = $student_column;
		}

		return array(
			'rows'           => isset( $table['rows'] ) ? $table['rows'] : array(),
			'columns'        => $columns,
			'row_headers'    => isset( $table['row_headers'] ) ? $table['row_headers'] : array(),
			'column_headers' => isset( $table['column_headers'] ) ? $table['column_headers'] : array(),
			'cells'          => $this->student_table_cells( isset( $table['cells'] ) && is_array( $table['cells'] ) ? $table['cells'] : array() ),
		);
	}

	/**
	 * Return labels from axis items.
	 *
	 * @param array $items Axis items.
	 * @return array
	 */
	private function axis_labels( array $items ) {
		$labels = array();
		foreach ( $items as $item ) {
			if ( isset( $item['label'] ) ) {
				$labels[] = (string) $item['label'];
			}
		}

		return $labels;
	}

	/**
	 * Build table cell schema items.
	 *
	 * @param array $rows Rows.
	 * @param array $columns Columns.
	 * @return array
	 */
	private function table_cells( array $rows, array $columns ) {
		$cells = array();
		foreach ( $rows as $row_index => $row ) {
			foreach ( $columns as $column_index => $column ) {
				$cell = array(
					'row'        => (int) $row_index,
					'column'     => (int) $column_index,
					'row_key'    => isset( $row['key'] ) ? (string) $row['key'] : '',
					'column_key' => isset( $column['key'] ) ? (string) $column['key'] : '',
					'input_type' => isset( $column['input_type'] ) ? (string) $column['input_type'] : 'text',
				);

				if ( ! empty( $column['options'] ) && is_array( $column['options'] ) ) {
					$cell['options'] = $column['options'];
				}

				$cells[] = $cell;
			}
		}

		return $cells;
	}

	/**
	 * Remove answer keys from student table cells.
	 *
	 * @param array $cells Cells.
	 * @return array
	 */
	private function student_table_cells( array $cells ) {
		$student_cells = array();
		foreach ( $cells as $cell ) {
			$student_cell = array(
				'row'        => isset( $cell['row'] ) ? (int) $cell['row'] : 0,
				'column'     => isset( $cell['column'] ) ? (int) $cell['column'] : 0,
				'row_key'    => isset( $cell['row_key'] ) ? (string) $cell['row_key'] : '',
				'column_key' => isset( $cell['column_key'] ) ? (string) $cell['column_key'] : '',
				'input_type' => isset( $cell['input_type'] ) ? (string) $cell['input_type'] : 'text',
			);

			if ( ! empty( $cell['options'] ) && is_array( $cell['options'] ) ) {
				$student_cell['options'] = $this->student_options( $cell['options'] );
			}

			$student_cells[] = $student_cell;
		}

		return $student_cells;
	}

	/**
	 * Normalize row or column labels.
	 *
	 * @param mixed  $items Items.
	 * @param string $prefix Key prefix.
	 * @return array
	 */
	private function axis_items( $items, $prefix ) {
		if ( is_string( $items ) ) {
			$items = preg_split( '/\r\n|\r|\n|,/', $items );
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $items as $index => $item ) {
			$label = is_array( $item ) && isset( $item['label'] ) ? $this->text( $item['label'] ) : $this->text( $item );
			if ( '' === $label ) {
				continue;
			}

			$key          = is_array( $item ) && ! empty( $item['key'] ) ? $this->safe_id( $prefix, $item['key'] ) : $this->safe_id( $prefix, (string) $index . '_' . $label );
			$normalized[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize table columns.
	 *
	 * @param mixed $columns Columns.
	 * @return array
	 */
	private function table_columns( $columns ) {
		if ( is_string( $columns ) ) {
			$columns = preg_split( '/\r\n|\r|\n/', $columns );
		}

		if ( ! is_array( $columns ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $columns as $index => $column ) {
			if ( is_array( $column ) ) {
				$label = isset( $column['label'] ) ? $this->text( $column['label'] ) : $this->text( isset( $column['title'] ) ? $column['title'] : '' );
				$type  = isset( $column['input_type'] ) ? $this->table_input_type( $column['input_type'] ) : 'text';
				$key   = ! empty( $column['key'] ) ? $this->safe_id( 'col', $column['key'] ) : $this->safe_id( 'col', (string) $index . '_' . $label );
				$opts  = isset( $column['options'] ) ? $this->options( $column['options'] ) : array();
			} else {
				$parts = explode( '|', (string) $column );
				$label = $this->text( $parts[0] );
				$type  = isset( $parts[1] ) ? $this->table_input_type( $parts[1] ) : 'text';
				$key   = $this->safe_id( 'col', (string) $index . '_' . $label );
				$opts  = isset( $parts[2] ) ? $this->options( $parts[2] ) : array();
			}

			if ( '' === $label ) {
				continue;
			}

			$item = array(
				'key'        => $key,
				'label'      => $label,
				'input_type' => $type,
			);

			if ( ! empty( $opts ) ) {
				$item['options'] = $opts;
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize table cell input type.
	 *
	 * @param mixed $type Type.
	 * @return string
	 */
	private function table_input_type( $type ) {
		$type = strtolower( (string) $type );
		$map  = array(
			'select'          => 'single_choice',
			'choice'          => 'single_choice',
			'single'          => 'single_choice',
			'single_choice'   => 'single_choice',
			'multiple'        => 'multiple_choice',
			'multiple_choice' => 'multiple_choice',
			'multi_select'    => 'multiple_choice',
			'checkbox'        => 'checkbox',
			'check'           => 'checkbox',
			'number'          => 'number',
			'numeric'         => 'number',
			'text'            => 'text',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'text';
	}

	/**
	 * Normalize attachment configuration.
	 *
	 * @param array $node Node.
	 * @param mixed $type Type.
	 * @return array
	 */
	private function attachments_config( array $node, $type = '' ) {
		$attachments = array();
		if ( ! empty( $node['allow_image'] ) || 'image_upload' === $type ) {
			$attachments[] = 'image';
		}
		if ( ! empty( $node['allow_file'] ) || 'file_upload' === $type ) {
			$attachments[] = 'file';
		}
		if ( ! empty( $node['allow_drawing'] ) || 'drawing' === $this->response_type( $node ) ) {
			$attachments[] = 'drawing';
		}

		return array_values( array_unique( $attachments ) );
	}

	/**
	 * Normalize upload configuration for upload-like inputs.
	 *
	 * @param array  $node Node.
	 * @param string $type Input type.
	 * @return array
	 */
	private function upload_config( array $node, $type ) {
		if ( ! in_array( $type, array( 'image_upload', 'file_upload', 'drawing' ), true ) ) {
			return array();
		}

		$kind = 'file';
		if ( 'image_upload' === $type ) {
			$kind = 'image';
		} elseif ( 'drawing' === $type ) {
			$kind = 'drawing';
		}

		return array(
			'kind'                => $kind,
			'max_files'           => $this->positive_int( isset( $node['max_files'] ) ? $node['max_files'] : ( 'file_upload' === $type ? 3 : 1 ), 1, 20 ),
			'max_file_size_mb'    => $this->positive_int( isset( $node['max_file_size_mb'] ) ? $node['max_file_size_mb'] : 20, 1, 200 ),
			'accepted_file_types' => $this->accepted_file_types( isset( $node['accepted_file_types'] ) ? $node['accepted_file_types'] : $this->default_accepts( $type ) ),
		);
	}

	/**
	 * Return default accepted file types.
	 *
	 * @param string $type Input type.
	 * @return array
	 */
	private function default_accepts( $type ) {
		if ( 'image_upload' === $type || 'drawing' === $type ) {
			return array( 'image/png', 'image/jpeg', 'image/webp' );
		}

		return array( 'application/pdf', 'image/png', 'image/jpeg', 'text/plain' );
	}

	/**
	 * Normalize accepted file type list.
	 *
	 * @param mixed $value Accepted file types.
	 * @return array
	 */
	private function accepted_file_types( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n|,/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$types = array();
		foreach ( $value as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}

			$types[] = preg_replace( '/[^a-z0-9.+*\/_-]/', '', $item );
		}

		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Normalize a positive integer range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min Minimum.
	 * @param int   $max Maximum.
	 * @return int
	 */
	private function positive_int( $value, $min, $max ) {
		return max( (int) $min, min( (int) $max, (int) $value ) );
	}

	/**
	 * Resolve answer value.
	 *
	 * @param array $node Node.
	 * @return mixed
	 */
	private function answer_value( array $node ) {
		foreach ( array( 'correct_answer', 'answer', 'answers' ) as $key ) {
			if ( isset( $node[ $key ] ) ) {
				return $node[ $key ];
			}
		}

		return '';
	}

	/**
	 * Normalize rubric mode.
	 *
	 * @param mixed $mode Mode.
	 * @return string
	 */
	private function rubric_mode( $mode ) {
		return 'question' === (string) $mode ? 'question' : 'worksheet';
	}

	/**
	 * Return a safe text value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function text( $value ) {
		if ( function_exists( 'sanitize_text_field' ) ) {
			return sanitize_text_field( (string) $value );
		}

		return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Text projection preserves its established canonical text and whitespace contract.
	}

	/**
	 * Return a decimal value.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private function decimal( $value ) {
		return round( (float) $value, 2 );
	}

	/**
	 * Encode JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function json( $value ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
	}
}

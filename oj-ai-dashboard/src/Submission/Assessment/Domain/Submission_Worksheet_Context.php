<?php
/**
 * Student-visible question context for the immutable grading input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-visible question context for the immutable grading input.
 */
final class Submission_Worksheet_Context {
	/**
	 * Project submission worksheet context.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function project( array $document ): array {
		if ( ! is_string( $document['title'] ?? null ) || ! is_array( $document['nodes'] ?? null ) || ! array_is_list( $document['nodes'] ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.student_document.payload' );
		}
		$blocks = array();
		$this->append( $document['nodes'], null, 1, $blocks );
		return array(
			'title'  => $document['title'],
			'blocks' => $blocks,
		);
	}

	/**
	 * Append.
	 *
	 * @param array<int,mixed>               $nodes Nodes.
	 * @param int|null                       $parent Parent.
	 * @param int                            $depth Depth.
	 * @param array<int,array<string,mixed>> &$blocks Blocks.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append( array $nodes, ?int $parent, int $depth, array &$blocks ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		if ( $depth > Worksheet_Document_Profile::MAX_DEPTH ) {
			throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.depth' );
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) || count( $blocks ) >= Worksheet_Document_Profile::MAX_NODES ) {
				throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.blocks' );
			}
			$position = count( $blocks );
			$block    = array(
				'position' => $position,
				'parent'   => $parent,
			);
			if ( array_key_exists( 'text', $node ) ) {
				if ( ! is_string( $node['text'] ) ) {
					throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.text' );
				}
				$blocks[] = $block + array(
					'type' => 'text',
					'text' => $node['text'],
				);
				continue;
			}
			$type = $node['type'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, Worksheet_Document_Profile::element_node_types(), true ) ) {
				throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.type' );
			}
			$block['type'] = $type;
			// Deliberately exclude asset identifiers, URLs, filenames and all grading metadata.
			$properties = array(
				'img'            => array( 'alt' ),
				'oj_ai_drawing'  => array( 'alt' ),
				'audio'          => array( 'transcript' ),
				'video'          => array( 'transcript' ),
				'file'           => array( 'label', 'description' ),
				'a'              => array( 'label' ),
				'equation'       => array( 'tex' ),
				'code_block'     => array( 'language' ),
				'oj_ai_response' => array( 'response_id', 'label', 'help_text' ),
			);
			foreach ( $properties[ $type ] ?? array() as $property ) {
				if ( ! array_key_exists( $property, $node ) ) {
					continue;
				}
				if ( ! is_string( $node[ $property ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.' . $property );
				}
				$block[ $property ] = $node[ $property ];
			}
			if ( in_array( $type, array( 'img', 'oj_ai_drawing', 'audio', 'video', 'file' ), true ) ) {
				$block['material_state'] = 'description_only';
			}
			$children = $node['children'] ?? null;
			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				throw Submission_Score_Input_Failure::invalid( '$.worksheet_context.children' );
			}
			$blocks[] = $block;
			$this->append( $children, $position, $depth + 1, $blocks );
		}
	}
}

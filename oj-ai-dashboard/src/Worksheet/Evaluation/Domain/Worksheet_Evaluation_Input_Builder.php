<?php
/**
 * Deterministic P04 worksheet evaluation input builder.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts an exact canonical revision and frozen configuration into Gateway input.
 */
final class Worksheet_Evaluation_Input_Builder {
	public const INPUT_CONTRACT            = 'worksheet_quality.v1';
	public const REQUEST_SCHEMA            = 'oj_ai.worksheet_quality_request';
	public const REQUEST_VERSION           = '1.0.0';
	public const P04_SCHEMA                = 'oj_ai.worksheet_evaluation_input';
	public const P04_VERSION               = '1.1.0';
	public const PROJECTOR_VERSION         = '1.1.0';
	public const OUTPUT_SCHEMA             = 'oj_ai.worksheet_quality_output';
	public const OUTPUT_VERSION            = '1.0.0';
	public const PROMPT_CONTRACT           = Worksheet_Quality_Prompt_Contract::VERSION;
	public const PROMPT_CONTENT_HASH       = Worksheet_Quality_Prompt_Contract::CONTENT_HASH;
	public const OPERATION                 = 'quality_assessment';
	public const MAXIMUM_INPUT_BYTES       = 921600;
	public const MAXIMUM_DIMENSIONS        = 12;
	public const MAXIMUM_CUSTOM_DIMENSIONS = 6;

	public const CORE_DIMENSIONS = array(
		'learning_goal_alignment',
		'instruction_clarity',
		'answerability',
		'difficulty_fit',
		'rubric_quality',
		'safety',
	);

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize worksheet evaluation input builder dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( Canonical_Json $canonical ) {
		$this->canonical = $canonical;
	}

	/**
	 * Build.
	 *
	 * @param string                         $worksheet_id Worksheet public identifier.
	 * @param string                         $revision_id Revision id.
	 * @param Canonical_Worksheet_Document   $source Source.
	 * @param Worksheet_Revision_Projection  $revision_projection Revision projection.
	 * @param array<int,array<string,mixed>> $asset_snapshots Asset snapshots.
	 * @param array<string,mixed>            $configuration Configuration.
	 * @param array                          $project_standards Project standards.
	 * @return Worksheet_Evaluation_Input
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		string $worksheet_id,
		string $revision_id,
		Canonical_Worksheet_Document $source,
		Worksheet_Revision_Projection $revision_projection,
		array $asset_snapshots,
		array $configuration,
		array $project_standards = array()
	): Worksheet_Evaluation_Input {
		$worksheet_id = $this->public_id( $worksheet_id, '$.subject.worksheet_id' );
		$revision_id  = $this->public_id( $revision_id, '$.subject.worksheet_revision_id' );
		$document     = $this->source_document( $source );
		$revision     = $this->revision_projection( $source, $revision_projection );
		$assets       = $this->asset_snapshots( $asset_snapshots, $revision['asset_ids'] );
		$config       = $this->configuration( $configuration );
		try {
			\OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preparation::assert_ready( $document, $config['grading_rubric'] );
		} catch ( \InvalidArgumentException ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.document.settings.preparation' );
		}

		$responses = array();
		$node_ids  = array();
		$media     = array();
		$nodes     = $this->project_nodes(
			$document['nodes'],
			$assets,
			$responses,
			$node_ids,
			$media,
			'$.worksheet.payload.document.nodes'
		);
		$this->assert_revision_questions( $revision_projection->questions(), $responses );

		$achievements = $this->project_achievements(
			$revision_projection->worksheet_achievement_links(),
			$revision_projection->question_achievement_links(),
			array_column( $responses, 'response_id' )
		);
		foreach ( $project_standards as $standard ) {
			\OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::keys( $standard, array( 'standard_id', 'code', 'text' ) );
			$achievements['worksheet'][] = array(
				'anchor_id'      => $this->public_id( $standard['standard_id'], '$.assessment_project.standard_id' ),
				'source_version' => (string) ( $document['settings']['assessment_project']['revision_id'] ?? '' ),
				'code'           => \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::text( $standard['code'], 100, true ),
				'label'          => '평가 성취기준',
				'description'    => \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::text( $standard['text'], 2000 ),
				'alignment_type' => 'primary',
				'position'       => count( $achievements['worksheet'] ),
			);
		}
		$achievement_ids = array_values(
			array_unique(
				array_merge(
					array_column( $achievements['worksheet'], 'anchor_id' ),
					array_column( $achievements['responses'], 'anchor_id' )
				)
			)
		);

		$anchor_manifest           = array(
			'worksheet'   => array( $worksheet_id ),
			'node'        => $this->sorted_unique( $node_ids ),
			'response'    => $this->sorted_unique( array_column( $responses, 'response_id' ) ),
			'achievement' => $this->sorted_unique( $achievement_ids ),
		);
		$effective_grading_rubric  = $this->effective_grading_rubric( $config['grading_rubric'], $document, $responses );
		$payload                   = array(
			'worksheet_id'          => $worksheet_id,
			'worksheet_revision_id' => $revision_id,
			'document'              => array(
				'document_id'    => $document['document_id'],
				'schema_id'      => $document['schema_id'],
				'schema_version' => $document['schema_version'],
				'editor_profile' => $document['editor_profile'],
				'locale'         => $document['locale'],
				'title'          => $document['title'],
				'settings'       => $document['settings'],
				'nodes'          => $nodes,
			),
			'responses'             => $responses,
			'achievements'          => $achievements,
			'media'                 => $media,
			'anchor_manifest'       => $anchor_manifest,
			'grading_rubric'        => $effective_grading_rubric,
			'statistics'            => array(
				'node_count'            => count( $node_ids ),
				'response_count'        => count( $responses ),
				'achievement_count'     => count( $achievement_ids ),
				'media_reference_count' => count( $media ),
			),
		);
		$payload_json              = $this->encode( $payload, '$.worksheet.payload' );
		$worksheet_projection      = array(
			'schema_id'         => self::P04_SCHEMA,
			'schema_version'    => self::P04_VERSION,
			'projector_version' => self::PROJECTOR_VERSION,
			'source_hash'       => $source->document_hash(),
			'payload_hash'      => hash( 'sha256', $payload_json ),
			'payload'           => $payload,
		);
		$worksheet_projection_json = $this->encode( $worksheet_projection, '$.worksheet' );
		$worksheet_projection_hash = hash( 'sha256', $worksheet_projection_json );

		$configuration_hash = hash( 'sha256', $this->encode( $config, '$.configuration' ) );
		$input              = array(
			'schema_id'       => self::REQUEST_SCHEMA,
			'schema_version'  => self::REQUEST_VERSION,
			'operation'       => self::OPERATION,
			'subject'         => array(
				'type'                  => 'worksheet_revision',
				'worksheet_id'          => $worksheet_id,
				'worksheet_revision_id' => $revision_id,
				'source_hash'           => $source->document_hash(),
				'locale'                => $document['locale'],
			),
			'worksheet'       => $worksheet_projection,
			'policy'          => $config['policy'],
			'rubric'          => $config['rubric'],
			'review_rule'     => $config['review_rule'],
			'prompt'          => $config['prompt'],
			'input_contract'  => array(
				'contract_version' => self::INPUT_CONTRACT,
				'schema'           => $config['input_schema'],
			),
			'output_contract' => $config['output_schema'],
		);

		$this->assert_safe_projection( $input, '$' );
		$input_json = $this->encode( $input, '$' );
		$bytes      = strlen( $input_json );
		if ( $bytes > self::MAXIMUM_INPUT_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::too_large( $bytes, self::MAXIMUM_INPUT_BYTES );
		}

		return new Worksheet_Evaluation_Input(
			$input,
			$input_json,
			hash( 'sha256', $input_json ),
			$worksheet_projection,
			$worksheet_projection_hash,
			$configuration_hash,
			$anchor_manifest
		);
	}

	/**
	 * Source document.
	 *
	 * @param Canonical_Worksheet_Document $source Source.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_document( Canonical_Worksheet_Document $source ): array {
		$document = $source->document();
		$this->assert_exact_keys(
			$document,
			array( 'schema_id', 'schema_version', 'editor_profile', 'document_id', 'locale', 'title', 'nodes', 'settings' ),
			'$.worksheet.source'
		);
		$json = $this->encode( $document, '$.worksheet.source' );
		if (
			array() !== $source->temporary_id_map()
			|| ! hash_equals( $source->document_json(), $json )
			|| ! hash_equals( $source->document_hash(), hash( 'sha256', $json ) )
			|| Worksheet_Document_Profile::SCHEMA_ID !== ( $document['schema_id'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== ( $document['schema_version'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $document['editor_profile'] ?? null )
			|| ! is_array( $document['nodes'] ?? null )
			|| ! array_is_list( $document['nodes'] )
			|| ! is_array( $document['settings'] ?? null )
		) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet.source' );
		}
		$this->public_id( $document['document_id'] ?? null, '$.worksheet.source.document_id' );

		return $document;
	}

	/**
	 * Revision projection.
	 *
	 * @param Canonical_Worksheet_Document  $source Source.
	 * @param Worksheet_Revision_Projection $projection Projection.
	 * @return array{asset_ids:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_projection(
		Canonical_Worksheet_Document $source,
		Worksheet_Revision_Projection $projection
	): array {
		$json = $projection->projection_json();
		if ( ! hash_equals( $projection->projection_hash(), hash( 'sha256', $json ) ) ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection' );
		}
		try {
			$manifest = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection' );
		}
		if (
			! is_array( $manifest ) || array_is_list( $manifest )
			|| Worksheet_Revision_Projector::PROJECTION_SCHEMA !== ( $manifest['schema_id'] ?? null )
			|| Worksheet_Revision_Projector::PROJECTION_VERSION !== ( $manifest['schema_version'] ?? null )
			|| ! hash_equals( $source->document_hash(), (string) ( $manifest['source_document_hash'] ?? '' ) )
		) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection' );
		}
		return array( 'asset_ids' => $this->revision_asset_ids( $projection->asset_bindings() ) );
	}

	/**
	 * Revision asset ids.
	 *
	 * @param array<array-key,mixed> $bindings Stored asset bindings to validate.
	 * @return list<string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision_asset_ids( array $bindings ): array {
		$asset_ids = array();
		foreach ( $bindings as $index => $binding ) {
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection.asset_bindings[' . $index . ']' );
			}
			$id = $this->public_id( $binding['asset_id'] ?? null, '$.worksheet_revision_projection.asset_bindings[' . $index . '].asset_id' );
			if ( in_array( $id, $asset_ids, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection.asset_bindings[' . $index . '].asset_id' );
			}
			$asset_ids[] = $id;
		}

		return $asset_ids;
	}

	/**
	 * Asset snapshots.
	 *
	 * @param array<array-key,mixed> $snapshots Snapshots.
	 * @param array<int,string>      $expected_ids Expected ids.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function asset_snapshots( array $snapshots, array $expected_ids ): array {
		if ( ! array_is_list( $snapshots ) ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.asset_snapshots' );
		}
		$result = array();
		foreach ( $snapshots as $index => $snapshot ) {
			$path = '$.asset_snapshots[' . $index . ']';
			if ( ! is_array( $snapshot ) || array_is_list( $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$this->assert_exact_keys( $snapshot, array( 'asset_id', 'kind', 'mime_type', 'sha256', 'text_representation' ), $path );
			$id     = $this->public_id( $snapshot['asset_id'] ?? null, $path . '.asset_id' );
			$kind   = $snapshot['kind'] ?? null;
			$mime   = $snapshot['mime_type'] ?? null;
			$sha256 = $snapshot['sha256'] ?? null;
			if (
				isset( $result[ $id ] )
				|| ! is_string( $kind )
				|| ! in_array( $kind, array( 'image', 'audio', 'video', 'file', 'drawing' ), true )
				|| ! is_string( $mime )
				|| strlen( $mime ) > 127
				|| 1 !== preg_match( '#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#D', $mime )
				|| ! is_string( $sha256 )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$text = $snapshot['text_representation'];
			if ( null !== $text ) {
				$text = $this->bounded_text( $text, $path . '.text_representation', 32768, true );
			}
			$result[ $id ] = array(
				'kind'                => $kind,
				'mime_type'           => $mime,
				'sha256'              => $sha256,
				'text_representation' => $text,
			);
		}
		$actual_ids = array_keys( $result );
		sort( $actual_ids, SORT_STRING );
		sort( $expected_ids, SORT_STRING );
		if ( $actual_ids !== $expected_ids ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.asset_snapshots' );
		}

		return $result;
	}

	/**
	 * Project nodes.
	 *
	 * @param array<array-key,mixed>            $nodes Nodes.
	 * @param array<string,array<string,mixed>> $assets Assets.
	 * @param array<int,array<string,mixed>>    &$responses Responses.
	 * @param array<int,string>                 &$node_ids Node ids.
	 * @param array<int,array<string,mixed>>    &$media Media.
	 * @param string                            $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project_nodes(
		array $nodes,
		array $assets,
		array &$responses,
		array &$node_ids,
		array &$media,
		string $path
	): array {
		$result = array();
		foreach ( $nodes as $index => $node ) {
			$node_path = $path . '[' . $index . ']';
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $node_path );
			}
			if ( array_key_exists( 'text', $node ) ) {
				$text = array( 'text' => $node['text'] );
				foreach ( Worksheet_Document_Profile::TEXT_MARKS as $mark ) {
					if ( true === ( $node[ $mark ] ?? false ) ) {
						$text[ $mark ] = true;
					}
				}
				$result[] = $text;
				continue;
			}

			$type = $node['type'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, Worksheet_Document_Profile::element_node_types(), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $node_path . '.type' );
			}
			$node_id = $this->public_id( $node['node_id'] ?? null, $node_path . '.node_id' );
			if ( in_array( $node_id, $node_ids, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $node_path . '.node_id' );
			}
			$node_ids[] = $node_id;
			$safe       = array(
				'type'    => $type,
				'node_id' => $node_id,
			);
			foreach ( $this->safe_node_properties( $type ) as $property ) {
				if ( array_key_exists( $property, $node ) ) {
					$safe[ $property ] = $node[ $property ];
				}
			}
			if ( 'a' === $type ) {
				$safe['link_host']     = $this->link_host( $node['url'] ?? null, $node_path . '.url' );
				$safe['link_required'] = true;
			}
			foreach ( array(
				'asset_id'        => 'primary',
				'poster_asset_id' => 'poster',
			) as $property => $role ) {
				if ( ! array_key_exists( $property, $node ) ) {
					continue;
				}
				$asset_id = $this->public_id( $node[ $property ], $node_path . '.' . $property );
				if ( ! isset( $assets[ $asset_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Input_Failure::invalid( $node_path . '.' . $property );
				}
				$key          = 'primary' === $role ? 'asset' : 'poster';
				$safe[ $key ] = $assets[ $asset_id ];
				$media[]      = array_merge(
					array(
						'node_id' => $node_id,
						'role'    => $role,
					),
					$assets[ $asset_id ]
				);
			}
			if ( Worksheet_Document_Profile::RESPONSE_NODE_TYPE === $type ) {
				$responses[] = $this->project_response( $node, count( $responses ), $node_path );
			}
			$children = $node['children'] ?? null;
			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $node_path . '.children' );
			}
			$safe['children'] = $this->project_nodes(
				$children,
				$assets,
				$responses,
				$node_ids,
				$media,
				$node_path . '.children'
			);
			$result[]         = $safe;
		}

		return $result;
	}

	/**
	 * Safe node properties.
	 *
	 * @param string $type Type.
	 * @return array<int,string>
	 */
	private function safe_node_properties( string $type ): array {
		return array(
			'p'              => array( 'align' ),
			'h1'             => array( 'align' ),
			'h2'             => array( 'align' ),
			'h3'             => array( 'align' ),
			'ol'             => array( 'start' ),
			'a'              => array( 'label' ),
			'table'          => array( 'header' ),
			'tr'             => array( 'row_id' ),
			'td'             => array( 'column_id' ),
			'th'             => array( 'column_id' ),
			'img'            => array( 'alt', 'width' ),
			'audio'          => array( 'transcript' ),
			'video'          => array( 'transcript' ),
			'file'           => array( 'label', 'description' ),
			'equation'       => array( 'tex', 'display' ),
			'code_block'     => array( 'language' ),
			'callout'        => array( 'tone', 'icon' ),
			'oj_ai_drawing'  => array( 'alt', 'aspect_ratio', 'stroke_schema_version' ),
			'oj_ai_response' => array( 'response_id', 'kind', 'label', 'required', 'help_text', 'constraints' ),
		)[ $type ] ?? array();
	}

	/**
	 * Project response.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param int                 $position Position.
	 * @param string              $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project_response( array $node, int $position, string $path ): array {
		$response = array(
			'response_id' => $this->public_id( $node['response_id'] ?? null, $path . '.response_id' ),
			'kind'        => $node['kind'] ?? null,
			'label'       => $node['label'] ?? null,
			'required'    => $node['required'] ?? null,
			'position'    => $position,
		);
		foreach ( array( 'help_text', 'constraints' ) as $property ) {
			if ( array_key_exists( $property, $node ) ) {
				$response[ $property ] = $node[ $property ];
			}
		}
		if ( array_key_exists( 'grading', $node ) ) {
			$grading = $node['grading'];
			if ( ! is_array( $grading ) || array_is_list( $grading ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path . '.grading' );
			}
			$safe_grading = array();
			foreach ( array( 'grading_enabled', 'max_points', 'correct_answer', 'example_answer', 'rubric_criterion_ids' ) as $property ) {
				if ( array_key_exists( $property, $grading ) ) {
					$safe_grading[ $property ] = $grading[ $property ];
				}
			}
			if ( array() !== $safe_grading ) {
				$response['grading'] = $safe_grading;
			}
		}

		return $response;
	}

	/**
	 * Reject invalid revision questions.
	 *
	 * @param array<array-key,mixed>         $questions Questions.
	 * @param array<int,array<string,mixed>> $responses Responses.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_revision_questions( array $questions, array $responses ): void {
		$expected = array_map(
			static fn ( array $response ): array => array( $response['response_id'], $response['kind'] ),
			$responses
		);
		$actual   = array();
		foreach ( $questions as $index => $question ) {
			if ( ! is_array( $question ) || array_is_list( $question ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection.questions[' . $index . ']' );
			}
			$actual[] = array( $question['question_key'] ?? null, $question['response_type'] ?? null );
		}
		if ( $expected !== $actual ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet_revision_projection.questions' );
		}
	}

	/**
	 * Project achievements.
	 *
	 * @param array<int,array<string,mixed>> $worksheet_links Worksheet links.
	 * @param array<int,array<string,mixed>> $question_links Question links.
	 * @param array<int,string>              $response_ids Response ids.
	 * @return array{worksheet:array<int,array<string,mixed>>,responses:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function project_achievements( array $worksheet_links, array $question_links, array $response_ids ): array {
		$result = array(
			'worksheet' => array(),
			'responses' => array(),
		);
		foreach ( $worksheet_links as $index => $link ) {
			$result['worksheet'][] = $this->achievement( $link, '$.achievements.worksheet[' . $index . ']', null );
		}
		foreach ( $question_links as $index => $link ) {
			$response_id = $this->public_id( $link['question_key'] ?? null, '$.achievements.responses[' . $index . '].response_id' );
			if ( ! in_array( $response_id, $response_ids, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( '$.achievements.responses[' . $index . '].response_id' );
			}
			$result['responses'][] = $this->achievement( $link, '$.achievements.responses[' . $index . ']', $response_id );
		}

		return $result;
	}

	/**
	 * Achievement.
	 *
	 * @param array<string,mixed> $link Link.
	 * @param string              $path Path.
	 * @param string|null         $response_id Response id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function achievement( array $link, string $path, ?string $response_id ): array {
		$anchor_id = $this->public_id( $link['standard_public_id'] ?? null, $path . '.standard_public_id' );
		$position  = $link['position'] ?? null;
		$alignment = $link['alignment_type'] ?? null;
		if ( ! is_int( $position ) || $position < 0 || ! in_array( $alignment, Worksheet_Revision_Projector::ALIGNMENT_TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$result = array(
			'anchor_id'      => $anchor_id,
			'source_version' => $this->bounded_text( $link['standard_source_version'] ?? null, $path . '.source_version', 64, false ),
			'code'           => $this->bounded_text( $link['standard_code_snapshot'] ?? null, $path . '.code', 100, false ),
			'label'          => $this->bounded_text( $link['standard_label_snapshot'] ?? null, $path . '.label', 255, false ),
			'description'    => null,
			'alignment_type' => $alignment,
			'position'       => $position,
		);
		if ( null !== $response_id ) {
			$result['response_id'] = $response_id;
		}

		return $result;
	}

	/**
	 * Configuration.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration( array $configuration ): array {
		$this->assert_exact_keys(
			$configuration,
			array( 'policy', 'rubric', 'review_rule', 'prompt', 'input_schema', 'output_schema', 'grading_rubric' ),
			'$.configuration'
		);
		foreach ( array( 'policy', 'rubric', 'review_rule', 'prompt', 'input_schema', 'output_schema', 'grading_rubric' ) as $key ) {
			if ( ! is_array( $configuration[ $key ] ?? null ) || array_is_list( $configuration[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.' . $key );
			}
		}

		$policy = $configuration['policy'];
		$this->assert_exact_keys( $policy, array( 'revision_id', 'content_hash', 'subject_type' ), '$.configuration.policy' );
		if ( 'worksheet_quality' !== ( $policy['subject_type'] ?? null ) ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.policy.subject_type' );
		}
		$policy = array(
			'revision_id'  => $this->public_id( $policy['revision_id'] ?? null, '$.configuration.policy.revision_id' ),
			'content_hash' => $this->sha256( $policy['content_hash'] ?? null, '$.configuration.policy.content_hash' ),
			'subject_type' => 'worksheet_quality',
		);

		$rubric = $configuration['rubric'];
		$this->assert_exact_keys( $rubric, array( 'revision_id', 'content_hash', 'dimensions' ), '$.configuration.rubric' );
		$rubric = array(
			'revision_id'  => $this->public_id( $rubric['revision_id'] ?? null, '$.configuration.rubric.revision_id' ),
			'content_hash' => $this->sha256( $rubric['content_hash'] ?? null, '$.configuration.rubric.content_hash' ),
			'dimensions'   => $this->dimensions( $rubric['dimensions'] ?? null ),
		);

		$review = $configuration['review_rule'];
		$this->assert_exact_keys(
			$review,
			array( 'revision_id', 'content_hash', 'review_required', 'explicit_release_required', 'confidence_threshold', 'abstain_handling', 'safety_handling' ),
			'$.configuration.review_rule'
		);
		if (
			true !== ( $review['review_required'] ?? null )
			|| true !== ( $review['explicit_release_required'] ?? null )
			|| 'require_review' !== ( $review['abstain_handling'] ?? null )
			|| 'require_explicit_review' !== ( $review['safety_handling'] ?? null )
		) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.review_rule' );
		}
		$review = array(
			'revision_id'               => $this->public_id( $review['revision_id'] ?? null, '$.configuration.review_rule.revision_id' ),
			'content_hash'              => $this->sha256( $review['content_hash'] ?? null, '$.configuration.review_rule.content_hash' ),
			'review_required'           => true,
			'explicit_release_required' => true,
			'confidence_threshold'      => $this->confidence( $review['confidence_threshold'] ?? null, '$.configuration.review_rule.confidence_threshold' ),
			'abstain_handling'          => 'require_review',
			'safety_handling'           => 'require_explicit_review',
		);

		$prompt = $configuration['prompt'];
		$this->assert_exact_keys( $prompt, array( 'contract_version', 'content_hash' ), '$.configuration.prompt' );
		if (
			self::PROMPT_CONTRACT !== ( $prompt['contract_version'] ?? null )
			|| self::PROMPT_CONTENT_HASH !== ( $prompt['content_hash'] ?? null )
		) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.prompt' );
		}
		$prompt = array(
			'contract_version' => self::PROMPT_CONTRACT,
			'content_hash'     => self::PROMPT_CONTENT_HASH,
		);

		$input_schema   = $this->schema_descriptor(
			$configuration['input_schema'],
			self::REQUEST_SCHEMA,
			self::REQUEST_VERSION,
			'$.configuration.input_schema'
		);
		$grading_rubric = $this->grading_rubric( $configuration['grading_rubric'] );
		$output_schema  = $this->schema_descriptor(
			$configuration['output_schema'],
			self::OUTPUT_SCHEMA,
			self::OUTPUT_VERSION,
			'$.configuration.output_schema'
		);

		return array(
			'policy'         => $policy,
			'rubric'         => $rubric,
			'review_rule'    => $review,
			'prompt'         => $prompt,
			'input_schema'   => $input_schema,
			'output_schema'  => $output_schema,
			'grading_rubric' => $grading_rubric,
		);
	}

	/**
	 * Dimensions.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function dimensions( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 6 || count( $value ) > self::MAXIMUM_DIMENSIONS ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.rubric.dimensions' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $index => $dimension ) {
			$path = '$.configuration.rubric.dimensions[' . $index . ']';
			if ( ! is_array( $dimension ) || array_is_list( $dimension ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$this->assert_exact_keys( $dimension, array( 'dimension_key', 'label', 'description', 'evidence_required' ), $path );
			$key = $dimension['dimension_key'] ?? null;
			if (
				! is_string( $key )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $key )
				|| isset( $seen[ $key ] )
				|| true !== ( $dimension['evidence_required'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			if ( $index < count( self::CORE_DIMENSIONS ) && self::CORE_DIMENSIONS[ $index ] !== $key ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path . '.dimension_key' );
			}
			if ( $index >= count( self::CORE_DIMENSIONS ) && in_array( $key, self::CORE_DIMENSIONS, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path . '.dimension_key' );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'dimension_key'     => $key,
				'label'             => $this->bounded_text( $dimension['label'] ?? null, $path . '.label', 120, false ),
				'description'       => $this->bounded_text( $dimension['description'] ?? null, $path . '.description', 1000, true ),
				'evidence_required' => true,
			);
		}
		if ( count( $result ) - count( self::CORE_DIMENSIONS ) > self::MAXIMUM_CUSTOM_DIMENSIONS ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.rubric.dimensions' );
		}

		return $result;
	}

	/**
	 * Grading rubric.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function grading_rubric( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.grading_rubric' );
		}
		$this->assert_exact_keys( $value, array( 'assessment_enabled', 'revision_id', 'content_hash', 'total_points', 'criteria' ), '$.configuration.grading_rubric' );
		$criteria = $value['criteria'] ?? null;
		if ( ! is_bool( $value['assessment_enabled'] ?? null ) || ! is_array( $criteria ) || ! array_is_list( $criteria ) || array() === $criteria ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.grading_rubric.criteria' );
		}
		$result      = array();
		$seen        = array();
		$total_units = 0;
		foreach ( $criteria as $position => $criterion ) {
			$path = '$.configuration.grading_rubric.criteria[' . $position . ']';
			if ( ! is_array( $criterion ) || array_is_list( $criterion ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$has_description = array_key_exists( 'description', $criterion );
			$this->assert_exact_keys(
				$criterion,
				$has_description
					? array( 'criterion_id', 'label', 'description', 'levels', 'maximum_points', 'position', 'scope' )
					: array( 'criterion_id', 'label', 'levels', 'maximum_points', 'position', 'scope' ),
				$path
			);
			$id    = $this->public_id( $criterion['criterion_id'] ?? null, $path . '.criterion_id' );
			$scope = $criterion['scope'] ?? null;
			if ( isset( $seen[ $id ] ) || $position !== ( $criterion['position'] ?? null ) || ! in_array( $scope, array( 'response', 'submission' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$seen[ $id ]  = true;
			$points       = $this->decimal_units( $criterion['maximum_points'] ?? null, false, $path . '.maximum_points' );
			$total_units += $points;
			$item         = array(
				'criterion_id'   => $id,
				'label'          => $this->bounded_text( $criterion['label'] ?? null, $path . '.label', 120, false ),
				'maximum_points' => $this->decimal_string( $points ),
				'position'       => $position,
				'scope'          => $scope,
				'levels'         => $this->grading_levels( $criterion['levels'] ?? null, $points, $path . '.levels' ),
			);
			if ( $has_description ) {
				$item['description'] = $this->bounded_text( $criterion['description'] ?? null, $path . '.description', 1000, true );
			}
			$result[] = $item;
		}
		$expected_total = $this->decimal_units( $value['total_points'] ?? null, false, '$.configuration.grading_rubric.total_points' );
		if ( $expected_total !== $total_units ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.configuration.grading_rubric.total_points' );
		}
		return array(
			'assessment_enabled' => $value['assessment_enabled'],
			'revision_id'        => $this->public_id( $value['revision_id'] ?? null, '$.configuration.grading_rubric.revision_id' ),
			'content_hash'       => $this->sha256( $value['content_hash'] ?? null, '$.configuration.grading_rubric.content_hash' ),
			'total_points'       => $this->decimal_string( $expected_total ),
			'criteria'           => $result,
		);
	}

	/**
	 * Effective grading rubric.
	 *
	 * @param array<string,mixed>            $rubric Rubric.
	 * @param array<string,mixed>            $document Document.
	 * @param array<int,array<string,mixed>> $responses Responses.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function effective_grading_rubric( array $rubric, array $document, array $responses ): array {
		$settings = $document['settings'] ?? null;
		if ( ! is_array( $settings ) || ! array_key_exists( 'assessment_bindings', $settings ) ) {
			return $rubric;
		}
		$bindings = $settings['assessment_bindings'];
		if ( ! is_array( $bindings ) || ! array_is_list( $bindings ) || count( $bindings ) > Worksheet_Document_Profile::MAX_ASSESSMENT_BINDINGS ) {
			throw Worksheet_Evaluation_Input_Failure::invalid( '$.worksheet.payload.document.settings.assessment_bindings' );
		}
		$criteria = array();
		foreach ( $rubric['criteria'] as $criterion ) {
			$criteria[ $criterion['criterion_id'] ] = $criterion;
		}
		$response_lookup = array_fill_keys( array_column( $responses, 'response_id' ), true );
		$selected        = array();
		$seen            = array();
		$total           = 0;
		foreach ( $bindings as $position => $binding ) {
			$path = '$.worksheet.payload.document.settings.assessment_bindings[' . $position . ']';
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$this->assert_exact_keys( $binding, array( 'criterion_id', 'scope', 'response_id' ), $path );
			$criterion_id = $this->public_id( $binding['criterion_id'] ?? null, $path . '.criterion_id' );
			$scope        = $binding['scope'] ?? null;
			if ( isset( $seen[ $criterion_id ] ) || ! isset( $criteria[ $criterion_id ] ) || ! in_array( $scope, array( 'worksheet', 'response' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			if ( 'response' === $scope ) {
				$response_id = $this->public_id( $binding['response_id'] ?? null, $path . '.response_id' );
				if ( ! isset( $response_lookup[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Worksheet_Evaluation_Input_Failure::invalid( $path . '.response_id' );
				}
			} elseif ( null !== ( $binding['response_id'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path . '.response_id' );
			}
			$criterion             = $criteria[ $criterion_id ];
			$criterion['position'] = $position;
			$criterion['scope']    = 'worksheet' === $scope ? 'submission' : 'response';
			if ( 'response' === $scope ) {
				$criterion['response_id'] = $response_id;
			}
			$total                += $this->decimal_units( $criterion['maximum_points'], false, $path . '.maximum_points' );
			$selected[]            = $criterion;
			$seen[ $criterion_id ] = true;
		}
		return array(
			'assessment_enabled' => true === $rubric['assessment_enabled'] && array() !== $selected,
			'revision_id'        => $rubric['revision_id'],
			'content_hash'       => $rubric['content_hash'],
			'total_points'       => $this->decimal_string( $total ),
			'criteria'           => $selected,
		);
	}
	/**
	 * Grading levels.
	 *
	 * @param mixed  $value Value.
	 * @param int    $maximum_units Maximum units.
	 * @param string $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function grading_levels( $value, int $maximum_units, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < 2 || count( $value ) > 10 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$result      = array();
		$seen_ids    = array();
		$seen_points = array();
		$max         = 0;
		foreach ( $value as $position => $level ) {
			$level_path = $path . '[' . $position . ']';
			if ( ! is_array( $level ) || array_is_list( $level ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $level_path );
			}
			$this->assert_exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ), $level_path );
			$id     = $this->public_id( $level['level_key'] ?? null, $level_path . '.level_key' );
			$points = $this->decimal_units( $level['points'] ?? null, true, $level_path . '.points' );
			if ( isset( $seen_ids[ $id ] ) || isset( $seen_points[ $points ] ) || $position !== ( $level['position'] ?? null ) || $points > $maximum_units ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $level_path );
			}
			$seen_ids[ $id ]        = true;
			$seen_points[ $points ] = true;
			$max                    = max( $max, $points );
			$result[]               = array(
				'label'     => $this->bounded_text( $level['label'] ?? null, $level_path . '.label', Assessment_Configuration_Profile::MAXIMUM_LEVEL_LABEL_LENGTH, true ),
				'level_key' => $id,
				'points'    => $this->decimal_string( $points ),
				'position'  => $position,
			);
		}
		if ( $max !== $maximum_units ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		return $result;
	}

	/**
	 * Decimal units.
	 *
	 * @param mixed  $value Value.
	 * @param bool   $allow_zero Allow zero.
	 * @param string $path Path.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_units( $value, bool $allow_zero, string $path ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$parts = explode( '.', $value, 2 );
		$units = ( (int) $parts[0] * 10000 ) + (int) str_pad( $parts[1] ?? '', 4, '0' );
		if ( ! $allow_zero && $units < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		return $units;
	}

	/**
	 * Decimal string.
	 *
	 * @param int $units Units.
	 * @return string
	 */
	private function decimal_string( int $units ): string {
		$whole    = intdiv( $units, 10000 );
		$fraction = rtrim( str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT ), '0' );
		return '' === $fraction ? (string) $whole : $whole . '.' . $fraction;
	}

	/**
	 * Schema descriptor.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $schema_id Schema id.
	 * @param string              $version Version.
	 * @param string              $path Path.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function schema_descriptor( array $value, string $schema_id, string $version, string $path ): array {
		$this->assert_exact_keys( $value, array( 'schema_id', 'schema_version', 'content_hash' ), $path );
		if ( $schema_id !== ( $value['schema_id'] ?? null ) || $version !== ( $value['schema_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}

		return array(
			'schema_id'      => $schema_id,
			'schema_version' => $version,
			'content_hash'   => $this->sha256( $value['content_hash'] ?? null, $path . '.content_hash' ),
		);
	}

	/**
	 * Link host.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function link_host( $value, string $path ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$parts = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves the exact absolute-URL validation contract.
		$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		if ( '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		if ( function_exists( 'idn_to_ascii' ) ) {
			$flags   = defined( 'IDNA_DEFAULT' ) ? constant( 'IDNA_DEFAULT' ) : 0;
			$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? constant( 'INTL_IDNA_VARIANT_UTS46' ) : 1;
			$ascii   = idn_to_ascii( $host, $flags, $variant );
			if ( ! is_string( $ascii ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Evaluation_Input_Failure::invalid( $path );
			}
			$host = $ascii;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		if ( strlen( $host ) > 253 || 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}

		return $host;
	}

	/**
	 * Confidence.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confidence( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0(?:\.[0-9]{1,4})?|1(?:\.0{1,4})?)$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$value = rtrim( rtrim( $value, '0' ), '.' );

		return '' === $value ? '0' : $value;
	}

	/**
	 * Sha256.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}

		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value, string $path ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}

		return $value;
	}

	/**
	 * Bounded text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_newline Allow newline.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bounded_text( $value, string $path, int $maximum, bool $allow_newline ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		try {
			$value = $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", trim( $value ) ) );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$count = preg_match_all( '/./us', $value, $matches );
		if (
			'' === $value
			|| false === $count
			|| $count > $maximum
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}

		return $value;
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @param string                 $path Input path for validation errors.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected, string $path ): void {
		if ( array() !== $value && array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
	}

	/**
	 * Reject invalid safe projection.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_safe_projection( $value, string $path ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$next = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
				if ( is_string( $key ) ) {
					$normalized = strtolower( str_replace( '-', '_', $key ) );
					foreach ( array(
						'teacher_note',
						'actor',
						'actor_id',
						'user_id',
						'learner_id',
						'student_name',
						'student_response',
						'submission',
						'attachment',
						'endpoint',
						'secret',
						'authorization',
						'storage_key',
						'public_url',
						'url',
						'raw_html',
						'html',
						'css',
						'script',
						'provider',
						'model',
						'asset_id',
						'poster_asset_id',
					) as $forbidden ) {
						if ( $normalized === $forbidden || str_ends_with( $normalized, '_' . $forbidden ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
							throw Worksheet_Evaluation_Input_Failure::invalid( $next );
						}
					}
				}
				$this->assert_safe_projection( $item, $next );
			}
			return;
		}
		if ( is_string( $value ) && 1 === preg_match( '#(?:https?|blob|data|file):#i', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
	}

	/**
	 * Encode worksheet evaluation input builder.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function encode( $value, string $path ): string {
		try {
			return $this->canonical->encode( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Evaluation_Input_Failure::invalid( $path );
		}
	}

	/**
	 * Sorted unique.
	 *
	 * @param array<int,string> $values Values.
	 * @return array<int,string>
	 */
	private function sorted_unique( array $values ): array {
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );

		return $values;
	}
}

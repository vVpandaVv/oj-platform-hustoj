<?php
/**
 * Deterministic official student submission score input builder.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the exact addon_payload map without learner or private-file identity.
 */
final class Submission_Score_Input_Builder {
	public const OUTER_INPUT_CONTRACT   = 'student_submission_assessment.v1';
	public const OPERATION              = 'score';
	public const OPERATION_CONTRACT     = 'submission_score.v1';
	public const REQUEST_SCHEMA         = 'oj_ai.submission_score_request';
	public const REQUEST_VERSION        = '1.0.0';
	public const OPERATION_SCHEMA       = 'oj_ai.submission_score_request@1.0.0';
	public const OUTPUT_SCHEMA          = 'oj_ai.submission_score_output';
	public const OUTPUT_VERSION         = '1.0.0';
	public const PROMPT_CONTRACT        = 'student_submission_assessment_prompt.v10';
	public const PROMPT_SECTION         = 'submission_score_prompt.v1';
	public const PROMPT_CONTENT_HASH    = '3db2e87732bcc18606a3c00acd4d9c1873dc05e21d1dd9aeeee07330172fccd6';
	public const REDACTION_VERSION      = 'submission_pii_redaction.v1';
	public const REPRESENTATION_SCHEMA  = 'oj_ai.submission_artifact_representation';
	public const REPRESENTATION_VERSION = '1.0.0';

	public const MAXIMUM_INPUT_BYTES           = 921600;
	public const MAXIMUM_RESPONSES             = 200;
	public const MAXIMUM_BINDINGS              = 400;
	public const MAXIMUM_BINDINGS_PER_RESPONSE = 20;
	public const MAXIMUM_ARTIFACTS             = 100;
	public const MAXIMUM_ARTIFACT_TEXT         = 20000;
	public const MAXIMUM_ARTIFACT_TEXT_TOTAL   = 300000;
	public const MAXIMUM_JSON_DEPTH            = 12;

	/**
	 * Initialize submission score input builder dependencies and state.
	 *
	 * @param Canonical_Json                $canonical Canonical JSON encoder used for stable digests.
	 * @param Submission_Revision_Integrity $integrity Integrity.
	 * @param Submission_Response_Validator $responses Responses.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Submission_Revision_Integrity $integrity,
		private Submission_Response_Validator $responses
	) {
	}

	/**
	 * Build.
	 *
	 * @param string                         $evaluation_ref Evaluation ref.
	 * @param string                         $submission_id Submission public identifier.
	 * @param string                         $official_revision_id Official revision id.
	 * @param array<string,mixed>            $official_revision Official revision.
	 * @param array<string,mixed>            $publication Publication.
	 * @param array<string,mixed>            $configuration Configuration.
	 * @param array<int,array<string,mixed>> $artifact_representations Artifact representations.
	 * @return Submission_Score_Input
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		string $evaluation_ref,
		string $submission_id,
		string $official_revision_id,
		array $official_revision,
		array $publication,
		array $configuration,
		array $artifact_representations
	): Submission_Score_Input {
		$evaluation_ref = $this->evaluation_ref( $evaluation_ref );
		$official       = $this->official_revision( $submission_id, $official_revision_id, $official_revision );
		$published      = $this->publication( $publication );

		$response = $official['response'];
		if (
			$published['publication_id'] !== ( $response['publication_id'] ?? null )
			|| ! hash_equals( $published['publication_hash'], (string) ( $response['publication_hash'] ?? '' ) )
		) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.response.publication_id' );
		}

		try {
			$validated = $this->responses->validate_complete( $published['response_schema'], $response['answers'] );
		} catch ( \Throwable ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.response.answers' );
		}
		if ( ! hash_equals( $this->encode( $response['answers'], '$.official_revision.response.answers' ), $this->encode( $validated['answers'], '$.official_revision.response.answers' ) ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.response.answers' );
		}

		$attachment_ids = array_keys( $validated['attachments'] );
		sort( $attachment_ids, SORT_STRING );
		$attachment_set_hash = hash( 'sha256', $this->encode( $attachment_ids, '$.official_revision.attachment_set' ) );
		if ( ! hash_equals( $official['attachment_set_hash'], $attachment_set_hash ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.attachment_set_hash' );
		}

		$config             = $this->configuration( $configuration, $published['grading_schema']['payload'] );
		$binding_result     = $this->bindings(
			$published['grading_schema']['payload'],
			$config,
			$published['worksheet_source_revision_hash'],
			array_column( $published['response_items'], 'response_id' )
		);
		$artifact_result    = $this->artifacts(
			$evaluation_ref,
			$artifact_representations,
			$validated['attachments']
		);
		$provider_responses = $this->provider_responses(
			$published['response_items'],
			$validated['answers'],
			$artifact_result['references_by_attachment']
		);

		$configuration_snapshot      = array(
			'input_schema'  => array(
				'content_hash'   => hash( 'sha256', self::REQUEST_SCHEMA . '@' . self::REQUEST_VERSION ),
				'schema_id'      => self::REQUEST_SCHEMA,
				'schema_version' => self::REQUEST_VERSION,
			),
			'output_schema' => array(
				'content_hash'   => hash( 'sha256', self::OUTPUT_SCHEMA . '@' . self::OUTPUT_VERSION ),
				'schema_id'      => self::OUTPUT_SCHEMA,
				'schema_version' => self::OUTPUT_VERSION,
			),
			'policy'        => $config['policy'],
			'prompt'        => array(
				'content_hash'     => self::PROMPT_CONTENT_HASH,
				'contract_version' => self::PROMPT_CONTRACT,
				'section_contract' => self::PROMPT_SECTION,
			),
			'review_rule'   => $config['review_rule'],
			'rubric'        => $config['rubric'],
		);
		$configuration_snapshot_json = $this->encode( $configuration_snapshot, '$.configuration' );
		$configuration_snapshot_hash = hash( 'sha256', $configuration_snapshot_json );

		$source_snapshot      = array(
			'attachment_set_hash'            => $official['attachment_set_hash'],
			'grading_schema_hash'            => $published['grading_schema_hash'],
			'official_revision_id'           => $official['revision_id'],
			'publication_hash'               => $published['publication_hash'],
			'representation_set_hash'        => $artifact_result['representation_set_hash'],
			'response_hash'                  => $official['response_hash'],
			'submission_id'                  => $official['submission_id'],
			'submission_revision_hash'       => $official['content_hash'],
			'worksheet_publication_id'       => $published['publication_id'],
			'worksheet_source_revision_hash' => $published['worksheet_source_revision_hash'],
		);
		$source_snapshot_json = $this->encode( $source_snapshot, '$.source_snapshot' );
		$source_snapshot_hash = hash( 'sha256', $source_snapshot_json );

		$evidence_catalog       = Submission_Score_Evidence_Catalog::build( $provider_responses, $artifact_result['provider'] );
		$operation_payload      = array(
			'evidence_catalog'  => $evidence_catalog,
			'artifacts'         => $artifact_result['provider'],
			'bindings'          => $binding_result['provider'],
			'instruction'       => array(
				'content_hash'     => self::PROMPT_CONTENT_HASH,
				'contract_version' => self::PROMPT_CONTRACT,
				'section_contract' => self::PROMPT_SECTION,
			),
			'operation'         => self::OPERATION,
			'output_contract'   => array(
				'json_schema'          => Submission_Score_Output_Schema::build( array_column( $binding_result['provider'], 'binding_ref' ), array_column( $evidence_catalog, 'evidence_ref' ), $evaluation_ref, $official['content_hash'] ),
				'allowed_binding_keys' => array_column( $binding_result['provider'], 'binding_ref' ),
				'schema_id'            => self::OUTPUT_SCHEMA,
				'schema_version'       => self::OUTPUT_VERSION,
			),
			'policy'            => array(
				'content_hash' => $config['policy']['content_hash'],
				'revision_ref' => $config['policy']['revision_id'],
				'rules'        => $config['policy']['value'],
			),
			'responses'         => $provider_responses,
			'worksheet_context' => $published['worksheet_context'],
			'rubric'            => array(
				'content_hash' => $config['rubric']['content_hash'],
				'criteria'     => $binding_result['criteria'],
				'revision_ref' => $config['rubric']['revision_id'],
				'total_points' => $binding_result['total_points'],
			),
			'schema_id'         => self::REQUEST_SCHEMA,
			'schema_version'    => self::REQUEST_VERSION,
			'subject'           => array(
				'evaluation_ref'          => $evaluation_ref,
				'grading_schema_hash'     => $published['grading_schema_hash'],
				'locale'                  => $published['locale'],
				'publication_hash'        => $published['publication_hash'],
				'representation_set_hash' => $artifact_result['representation_set_hash'],
				'source_hash'             => $official['content_hash'],
			),
		);
		$operation_payload_json = $this->encode( $operation_payload, '$.operation_payload' );
		$operation_payload_hash = hash( 'sha256', $operation_payload_json );
		$gateway_input          = array(
			'operation'          => self::OPERATION,
			'operation_contract' => self::OPERATION_CONTRACT,
			'operation_payload'  => $operation_payload,
			'operation_schema'   => self::OPERATION_SCHEMA,
			'payload_hash'       => $operation_payload_hash,
		);
		$this->assert_safe_projection( $gateway_input, '$' );
		$this->assert_depth( $gateway_input, 1, '$' );
		$gateway_input_json = $this->encode( $gateway_input, '$' );
		$bytes              = strlen( $gateway_input_json );
		if ( $bytes > self::MAXIMUM_INPUT_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::too_large( $bytes, self::MAXIMUM_INPUT_BYTES );
		}

		$artifact_references_json = $this->encode( $artifact_result['references'], '$.artifact_references' );

		return new Submission_Score_Input(
			$gateway_input,
			$gateway_input_json,
			hash( 'sha256', $gateway_input_json ),
			$operation_payload,
			$operation_payload_hash,
			$source_snapshot,
			$source_snapshot_hash,
			$configuration_snapshot,
			$configuration_snapshot_hash,
			$artifact_result['references'],
			$artifact_references_json,
			hash( 'sha256', $artifact_references_json ),
			$artifact_result['representation_set_hash'],
			$binding_result['manifest'],
			self::REDACTION_VERSION
		);
	}

	/**
	 * Official revision.
	 *
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $official_revision_id Official revision id.
	 * @param array<string,mixed> $revision Revision.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function official_revision( string $submission_id, string $official_revision_id, array $revision ): array {
		$submission_id        = $this->public_id( $submission_id, '$.submission_id' );
		$official_revision_id = $this->public_id( $official_revision_id, '$.official_revision_id' );
		$this->exact_keys(
			$revision,
			array( 'attachment_set_hash', 'base_revision_no', 'content_hash', 'id', 'response', 'response_schema_version', 'revision_no', 'state' ),
			'$.official_revision'
		);
		$revision_id = $this->public_id( $revision['id'] ?? null, '$.official_revision.id' );
		if ( ! hash_equals( $official_revision_id, $revision_id ) ) {
			throw Submission_Score_Input_Failure::not_official();
		}
		$revision_no      = $revision['revision_no'] ?? null;
		$base_revision_no = $revision['base_revision_no'] ?? null;
		$state            = $revision['state'] ?? null;
		if (
			! is_int( $revision_no ) || $revision_no < 1
			|| ( null !== $base_revision_no && ( ! is_int( $base_revision_no ) || $base_revision_no < 1 || $base_revision_no >= $revision_no ) )
			|| ! is_string( $state ) || ! in_array( $state, Submission_Revision_Integrity::STATES, true )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $revision['response_schema_version'] ?? null )
		) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision' );
		}
		$response = $revision['response'] ?? null;
		if ( ! is_array( $response ) || array_is_list( $response ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.response' );
		}
		$this->exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ), '$.official_revision.response' );
		if (
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
			|| ! is_array( $response['answers'] ?? null )
			|| ( array() !== $response['answers'] && array_is_list( $response['answers'] ) )
		) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.response' );
		}
		$this->public_id( $response['publication_id'] ?? null, '$.official_revision.response.publication_id' );
		$this->sha256( $response['publication_hash'] ?? null, '$.official_revision.response.publication_hash' );
		$response_json       = $this->encode( $response, '$.official_revision.response' );
		$response_hash       = hash( 'sha256', $response_json );
		$attachment_set_hash = $this->sha256( $revision['attachment_set_hash'] ?? null, '$.official_revision.attachment_set_hash' );
		try {
			$expected_content_hash = $this->integrity->content_hash(
				$submission_id,
				$revision_no,
				$base_revision_no,
				$state,
				Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				$response_hash,
				$attachment_set_hash
			);
		} catch ( InvalidArgumentException ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.content_hash' );
		}
		$content_hash = $this->sha256( $revision['content_hash'] ?? null, '$.official_revision.content_hash' );
		if ( ! hash_equals( $content_hash, $expected_content_hash ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.official_revision.content_hash' );
		}

		return array(
			'attachment_set_hash' => $attachment_set_hash,
			'content_hash'        => $content_hash,
			'response'            => $response,
			'response_hash'       => $response_hash,
			'revision_id'         => $revision_id,
			'submission_id'       => $submission_id,
		);
	}

	/**
	 * Publication.
	 *
	 * @param array<string,mixed> $publication Publication.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function publication( array $publication ): array {
		$this->exact_keys(
			$publication,
			array( 'grading_schema', 'id', 'manifest', 'manifest_version', 'publication_hash', 'response_schema', 'student_document' ),
			'$.publication'
		);
		$publication_id   = $this->public_id( $publication['id'] ?? null, '$.publication.id' );
		$publication_hash = $this->sha256( $publication['publication_hash'] ?? null, '$.publication.publication_hash' );
		if ( Worksheet_Publication_Projector::MANIFEST_VERSION !== ( $publication['manifest_version'] ?? null ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest_version' );
		}
		$manifest = $publication['manifest'] ?? null;
		if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest' );
		}
		$this->exact_keys( $manifest, array( 'manifest_version', 'profiles', 'source_revision_hash' ), '$.publication.manifest' );
		$source_revision_hash = $this->sha256( $manifest['source_revision_hash'] ?? null, '$.publication.manifest.source_revision_hash' );
		if (
			Worksheet_Publication_Projector::MANIFEST_VERSION !== ( $manifest['manifest_version'] ?? null )
			|| ! hash_equals( $publication_hash, hash( 'sha256', $this->encode( $manifest, '$.publication.manifest' ) ) )
		) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest' );
		}

		$student       = $this->profile(
			$publication['student_document'] ?? null,
			Worksheet_Preview_Projector::STUDENT_DOCUMENT_SCHEMA,
			Worksheet_Preview_Projector::STUDENT_DOCUMENT_VERSION,
			Worksheet_Preview_Projector::PROJECTOR_VERSION,
			$source_revision_hash,
			'$.publication.student_document'
		);
		$response      = $this->profile(
			$publication['response_schema'] ?? null,
			Worksheet_Preview_Projector::RESPONSE_SCHEMA,
			Worksheet_Preview_Projector::RESPONSE_VERSION,
			Worksheet_Preview_Projector::PROJECTOR_VERSION,
			$source_revision_hash,
			'$.publication.response_schema'
		);
		$grading_value = $publication['grading_schema'] ?? null;
		if (
			! is_array( $grading_value ) || array_is_list( $grading_value )
			|| Worksheet_Publication_Projector::GRADING_SCHEMA !== ( $grading_value['schema_id'] ?? null )
			|| Worksheet_Publication_Projector::GRADING_VERSION !== ( $grading_value['schema_version'] ?? null )
			|| Worksheet_Publication_Projector::GRADING_PROJECTOR_VERSION !== ( $grading_value['projector_version'] ?? null )
		) {
			throw Submission_Score_Input_Failure::grading_unsupported();
		}
		$grading = $this->profile(
			$grading_value,
			Worksheet_Publication_Projector::GRADING_SCHEMA,
			Worksheet_Publication_Projector::GRADING_VERSION,
			Worksheet_Publication_Projector::GRADING_PROJECTOR_VERSION,
			$source_revision_hash,
			'$.publication.grading_schema'
		);

		$profiles = $manifest['profiles'] ?? null;
		if ( ! is_array( $profiles ) || array_is_list( $profiles ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest.profiles' );
		}
		$this->exact_keys( $profiles, array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' ), '$.publication.manifest.profiles' );
		foreach ( array(
			'grading_schema'   => $grading,
			'response_schema'  => $response,
			'student_document' => $student,
		) as $name => $profile ) {
			$descriptor = $profiles[ $name ] ?? null;
			if ( ! is_array( $descriptor ) || array_is_list( $descriptor ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( '$.publication.manifest.profiles.' . $name );
			}
			$this->exact_keys( $descriptor, array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ), '$.publication.manifest.profiles.' . $name );
			foreach ( array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ) as $field ) {
				if ( (string) ( $profile[ $field ] ?? '' ) !== (string) ( $descriptor[ $field ] ?? '' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::invalid( '$.publication.manifest.profiles.' . $name . '.' . $field );
				}
			}
		}
		$print = $profiles['print_projection'];
		if ( ! is_array( $print ) || array_is_list( $print ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest.profiles.print_projection' );
		}
		$this->exact_keys( $print, array( 'payload_hash', 'projector_version', 'schema_id', 'schema_version' ), '$.publication.manifest.profiles.print_projection' );
		if (
			Worksheet_Preview_Projector::PRINT_SCHEMA !== ( $print['schema_id'] ?? null )
			|| Worksheet_Preview_Projector::PRINT_VERSION !== ( $print['schema_version'] ?? null )
			|| Worksheet_Preview_Projector::PROJECTOR_VERSION !== ( $print['projector_version'] ?? null )
		) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.manifest.profiles.print_projection' );
		}
		$this->sha256( $print['payload_hash'] ?? null, '$.publication.manifest.profiles.print_projection.payload_hash' );

		$student_payload = $student['payload'];
		$locale          = $student_payload['locale'] ?? null;
		if ( ! is_string( $locale ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?$/D', $locale ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.student_document.payload.locale' );
		}
		$response_payload = $response['payload'];
		$this->exact_keys( $response_payload, array( 'items' ), '$.publication.response_schema.payload' );
		$items = $response_payload['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > self::MAXIMUM_RESPONSES ) {
			throw Submission_Score_Input_Failure::invalid( '$.publication.response_schema.payload.items' );
		}

		return array(
			'grading_schema'                 => $grading,
			'grading_schema_hash'            => hash( 'sha256', $this->encode( $grading, '$.publication.grading_schema' ) ),
			'locale'                         => $locale,
			'publication_hash'               => $publication_hash,
			'publication_id'                 => $publication_id,
			'response_items'                 => $items,
			'response_schema'                => $response,
			'worksheet_context'              => ( new Submission_Worksheet_Context() )->project( $student_payload ),
			'worksheet_source_revision_hash' => $source_revision_hash,
		);
	}

	/**
	 * Profile.
	 *
	 * @param mixed  $value Value.
	 * @param string $schema Schema.
	 * @param string $version Version.
	 * @param string $projector Projector.
	 * @param string $source_hash Source hash.
	 * @param string $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function profile( $value, string $schema, string $version, string $projector, string $source_hash, string $path ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$this->exact_keys( $value, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ), $path );
		$payload = $value['payload'] ?? null;
		if (
			! is_array( $payload ) || array_is_list( $payload )
			|| $schema !== ( $value['schema_id'] ?? null )
			|| $version !== ( $value['schema_version'] ?? null )
			|| $projector !== ( $value['projector_version'] ?? null )
			|| ! hash_equals( $source_hash, (string) ( $value['source_hash'] ?? '' ) )
			|| ! hash_equals( hash( 'sha256', $this->encode( $payload, $path . '.payload' ) ), (string) ( $value['payload_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return $value;
	}

	/**
	 * Configuration.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 * @param array<string,mixed> $p03 P03.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration( array $configuration, array $p03 ): array {
		$this->exact_keys( $configuration, array( 'policy', 'review_rule', 'rubric' ), '$.configuration' );
		$policy = $this->configuration_record( $configuration['policy'] ?? null, '$.configuration.policy' );
		$rubric = $this->configuration_record( $configuration['rubric'] ?? null, '$.configuration.rubric' );
		$review = $this->configuration_record( $configuration['review_rule'] ?? null, '$.configuration.review_rule' );
		if ( 'student_submission' !== $policy['subject_type'] || 'student_submission' !== $rubric['subject_type'] || 'student_submission' !== $review['subject_type'] ) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.configuration' );
		}

		$this->exact_keys( $policy['value'], array( 'assessment_enabled', 'review_rule_revision_id', 'rubric_revision_id' ), '$.configuration.policy.value' );
		if (
			true !== ( $policy['value']['assessment_enabled'] ?? null )
			|| $rubric['revision_id'] !== ( $policy['value']['rubric_revision_id'] ?? null )
			|| $review['revision_id'] !== ( $policy['value']['review_rule_revision_id'] ?? null )
		) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.configuration.policy.value' );
		}
		$this->public_id( $policy['value']['rubric_revision_id'] ?? null, '$.configuration.policy.value.rubric_revision_id' );
		$this->public_id( $policy['value']['review_rule_revision_id'] ?? null, '$.configuration.policy.value.review_rule_revision_id' );

		$this->exact_keys( $rubric['value'], array( 'criteria' ), '$.configuration.rubric.value' );
		$criteria = $rubric['value']['criteria'] ?? null;
		if ( ! is_array( $criteria ) || ! array_is_list( $criteria ) || array() === $criteria ) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.configuration.rubric.value.criteria' );
		}
		$normalized_criteria = array();
		$seen                = array();
		$total_units         = 0;
		foreach ( $criteria as $position => $criterion ) {
			$path = '$.configuration.rubric.value.criteria[' . $position . ']';
			if ( ! is_array( $criterion ) || array_is_list( $criterion ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
			$has_levels      = array_key_exists( 'levels', $criterion );
			$has_description = array_key_exists( 'description', $criterion );
			$keys            = array( 'criterion_id', 'label', 'maximum_points', 'position', 'scope' );
			if ( $has_levels ) {
				$keys[] = 'levels';
			}
			if ( $has_description ) {
				$keys[] = 'description';
			}
			$this->exact_keys( $criterion, $keys, $path );
			$id    = $this->public_id( $criterion['criterion_id'] ?? null, $path . '.criterion_id' );
			$scope = $criterion['scope'] ?? null;
			if ( isset( $seen[ $id ] ) || $position !== ( $criterion['position'] ?? null ) || ! in_array( $scope, array( 'response', 'submission' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
			$seen[ $id ]          = true;
			$points               = $this->decimal_units( $criterion['maximum_points'] ?? null, false, $path . '.maximum_points' );
			$total_units         += $points;
			$normalized_criterion = array(
				'criterion_id'   => $id,
				'label'          => $this->bounded_text( $criterion['label'] ?? null, $path . '.label', 120, false, false ),
				'maximum_points' => $this->decimal_string( $points ),
				'position'       => $position,
				'scope'          => $scope,
			);
			if ( $has_description ) {
				$normalized_criterion['description'] = $this->bounded_text( $criterion['description'] ?? null, $path . '.description', 1000, false, true );
			}
			if ( $has_levels ) {
				$normalized_criterion['levels'] = $this->criterion_levels( $criterion['levels'] ?? null, $points, $path . '.levels' );
			}
			$normalized_criteria[] = $normalized_criterion;
		}
		$total_points = $rubric['total_points'] ?? null;
		if ( $total_units !== $this->decimal_units( $total_points, false, '$.configuration.rubric.total_points' ) ) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.configuration.rubric.total_points' );
		}
		$rubric['total_points'] = $this->decimal_string( $total_units );
		$rubric['value']        = array( 'criteria' => $normalized_criteria );

		$this->exact_keys( $review['value'], array( 'abstain_handling', 'safety_handling' ), '$.configuration.review_rule.value' );
		if (
			true !== ( $review['review_required'] ?? null )
			|| true !== ( $review['explicit_release_required'] ?? null )
			|| 'require_review' !== ( $review['value']['abstain_handling'] ?? null )
			|| 'require_explicit_review' !== ( $review['value']['safety_handling'] ?? null )
		) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.configuration.review_rule' );
		}
		$review['confidence_threshold'] = $this->confidence( $review['confidence_threshold'] ?? null, '$.configuration.review_rule.confidence_threshold' );

		$this->assert_p03_pin( $p03, $policy, $rubric, $review );

		return array(
			'policy'      => $policy,
			'rubric'      => $rubric,
			'review_rule' => $review,
		);
	}

	/**
	 * Configuration record.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration_record( $value, string $path ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		$this->required_keys( $value, array( 'content_hash', 'revision_id', 'state', 'subject_type', 'value' ), $path );
		$revision_id = $this->public_id( $value['revision_id'] ?? null, $path . '.revision_id' );
		$state       = $value['state'] ?? null;
		$subject     = $value['subject_type'] ?? null;
		$content     = $value['value'] ?? null;
		if (
			! is_string( $state ) || ! in_array( $state, array( 'active', 'retired' ), true )
			|| ! is_string( $subject )
			|| ! is_array( $content ) || array_is_list( $content )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		$content_hash = $this->sha256( $value['content_hash'] ?? null, $path . '.content_hash' );
		if ( ! hash_equals( $content_hash, hash( 'sha256', $this->encode( $content, $path . '.value' ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path . '.content_hash' );
		}
		$result = array(
			'content_hash' => $content_hash,
			'revision_id'  => $revision_id,
			'state'        => $state,
			'subject_type' => $subject,
			'value'        => $content,
		);
		foreach ( array( 'confidence_threshold', 'explicit_release_required', 'review_required', 'total_points' ) as $field ) {
			if ( array_key_exists( $field, $value ) ) {
				$result[ $field ] = $value[ $field ];
			}
		}
		return $result;
	}

	/**
	 * Criterion levels.
	 *
	 * @param mixed  $raw Raw.
	 * @param int    $maximum_units Maximum units.
	 * @param string $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function criterion_levels( $raw, int $maximum_units, string $path ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 2 || count( $raw ) > 10 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		$result      = array();
		$seen_ids    = array();
		$seen_points = array();
		$max_units   = 0;
		foreach ( $raw as $position => $level ) {
			$level_path = $path . '[' . $position . ']';
			if ( ! is_array( $level ) || array_is_list( $level ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $level_path );
			}
			$this->exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ), $level_path );
			$id = $this->public_id( $level['level_key'] ?? null, $level_path . '.level_key' );
			if ( isset( $seen_ids[ $id ] ) || $position !== ( $level['position'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $level_path );
			}
			$units = $this->decimal_units( $level['points'] ?? null, true, $level_path . '.points' );
			if ( isset( $seen_points[ $units ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $level_path . '.points' );
			}
			$seen_ids[ $id ]       = true;
			$seen_points[ $units ] = true;
			$max_units             = max( $max_units, $units );
			$result[]              = array(
				'label'     => $this->bounded_text( $level['label'] ?? null, $level_path . '.label', Assessment_Configuration_Profile::MAXIMUM_LEVEL_LABEL_LENGTH, false, true ),
				'level_key' => $id,
				'points'    => $this->decimal_string( $units ),
				'position'  => $position,
			);
		}
		if ( $max_units !== $maximum_units ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		return $result;
	}

	/**
	 * Reject invalid p03 pin.
	 *
	 * @param array<string,mixed> $p03 P03.
	 * @param array<string,mixed> $policy Policy.
	 * @param array<string,mixed> $rubric Rubric.
	 * @param array<string,mixed> $review Review.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_p03_pin( array $p03, array $policy, array $rubric, array $review ): void {
		$this->exact_keys( $p03, array( 'bindings', 'policy', 'review_rule', 'rubric' ), '$.publication.grading_schema.payload' );
		foreach ( array( 'policy', 'review_rule', 'rubric' ) as $name ) {
			if ( ! is_array( $p03[ $name ] ?? null ) || array_is_list( $p03[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( '$.publication.grading_schema.payload.' . $name );
			}
		}
		$this->exact_keys( $p03['policy'], array( 'content_hash', 'revision_id' ), '$.publication.grading_schema.payload.policy' );
		$this->exact_keys( $p03['rubric'], array( 'content_hash', 'revision_id', 'total_points' ), '$.publication.grading_schema.payload.rubric' );
		$this->exact_keys( $p03['review_rule'], array( 'content_hash', 'explicit_release_required', 'review_required', 'revision_id' ), '$.publication.grading_schema.payload.review_rule' );
		if (
			$policy['revision_id'] !== ( $p03['policy']['revision_id'] ?? null )
			|| ! hash_equals( $policy['content_hash'], (string) ( $p03['policy']['content_hash'] ?? '' ) )
			|| $rubric['revision_id'] !== ( $p03['rubric']['revision_id'] ?? null )
			|| ! hash_equals( $rubric['content_hash'], (string) ( $p03['rubric']['content_hash'] ?? '' ) )
			|| $review['revision_id'] !== ( $p03['review_rule']['revision_id'] ?? null )
			|| ! hash_equals( $review['content_hash'], (string) ( $p03['review_rule']['content_hash'] ?? '' ) )
			|| true !== ( $p03['review_rule']['review_required'] ?? null )
			|| true !== ( $p03['review_rule']['explicit_release_required'] ?? null )
		) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.publication.grading_schema.payload' );
		}
		$this->decimal_units( $p03['rubric']['total_points'] ?? null, true, '$.publication.grading_schema.payload.rubric.total_points' );
	}

	/**
	 * Bindings.
	 *
	 * @param array<string,mixed> $p03 P03.
	 * @param array<string,mixed> $configuration Configuration.
	 * @param string              $source_revision_hash Source revision hash.
	 * @param array<int,string>   $response_ids Response ids.
	 * @return array{provider:array<int,array<string,mixed>>,manifest:array<int,array<string,mixed>>,criteria:array<int,array<string,mixed>>,total_points:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bindings( array $p03, array $configuration, string $source_revision_hash, array $response_ids ): array {
		$raw = $p03['bindings'] ?? null;
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || array() === $raw || count( $raw ) > self::MAXIMUM_BINDINGS ) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.publication.grading_schema.payload.bindings' );
		}
		$criteria = array();
		foreach ( $configuration['rubric']['value']['criteria'] as $criterion ) {
			$criteria[ $criterion['criterion_id'] ] = $criterion;
		}
		$response_lookup    = array_fill_keys( $response_ids, true );
		$seen_bindings      = array();
		$seen_criteria      = array();
		$response_counts    = array();
		$total_units        = 0;
		$provider           = array();
		$manifest           = array();
		$effective_criteria = array();
		foreach ( $raw as $position => $binding ) {
			$path = '$.publication.grading_schema.payload.bindings[' . $position . ']';
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
			$this->allowed_keys( $binding, array( 'binding_key', 'correct_answer', 'criterion_id', 'example_answer', 'label', 'levels', 'maximum_points', 'position', 'response_id', 'scope' ), $path );
			$this->required_keys( $binding, array( 'binding_key', 'criterion_id', 'label', 'maximum_points', 'position', 'response_id', 'scope' ), $path );
			$binding_key  = $this->sha256( $binding['binding_key'] ?? null, $path . '.binding_key' );
			$criterion_id = $this->public_id( $binding['criterion_id'] ?? null, $path . '.criterion_id' );
			$scope        = $binding['scope'] ?? null;
			$response_id  = $binding['response_id'] ?? null;
			if (
				isset( $seen_bindings[ $binding_key ] ) || isset( $seen_criteria[ $criterion_id ] )
				|| ! isset( $criteria[ $criterion_id ] ) || $position !== ( $binding['position'] ?? null )
				|| ! in_array( $scope, array( 'response', 'submission' ), true )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
			if ( 'response' === $scope ) {
				$response_id = $this->public_id( $response_id, $path . '.response_id' );
				if ( ! isset( $response_lookup[ $response_id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::grading_invalid( $path . '.response_id' );
				}
				$response_counts[ $response_id ] = ( $response_counts[ $response_id ] ?? 0 ) + 1;
				if ( $response_counts[ $response_id ] > self::MAXIMUM_BINDINGS_PER_RESPONSE ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::grading_invalid( $path . '.response_id' );
				}
			} elseif ( null !== $response_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path . '.response_id' );
			}
			$criterion = $criteria[ $criterion_id ];
			$points    = $this->decimal_units( $binding['maximum_points'] ?? null, false, $path . '.maximum_points' );
			if (
				$criterion['label'] !== ( $binding['label'] ?? null )
				|| $criterion['maximum_points'] !== $this->decimal_string( $points )
				|| array_key_exists( 'levels', $criterion ) !== array_key_exists( 'levels', $binding )
				|| ( array_key_exists( 'levels', $criterion ) && $criterion['levels'] !== $binding['levels'] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
			$expected_key = $this->binding_key( $source_revision_hash, $configuration['rubric']['content_hash'], $scope, $response_id, $criterion_id );
			if ( ! hash_equals( $expected_key, $binding_key ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path . '.binding_key' );
			}
			$seen_bindings[ $binding_key ]  = true;
			$seen_criteria[ $criterion_id ] = true;
			$total_units                   += $points;
			$item                           = array(
				'binding_key'    => $binding_key,
				'binding_ref'    => 'B' . ( $position + 1 ),
				'criterion_id'   => $criterion_id,
				'label'          => $criterion['label'],
				'maximum_points' => $criterion['maximum_points'],
				'position'       => $position,
				'response_id'    => $response_id,
				'scope'          => $scope,
			);
			if ( array_key_exists( 'description', $criterion ) ) {
				$item['description'] = $criterion['description'];
			}
			if ( array_key_exists( 'levels', $criterion ) ) {
				$item['levels'] = $criterion['levels'];
			}
			foreach ( array( 'correct_answer', 'example_answer' ) as $answer ) {
				if ( array_key_exists( $answer, $binding ) ) {
					$item[ $answer ] = $binding[ $answer ];
				}
			}
			$provider[]                      = $item;
			$manifest[]                      = array_intersect_key( $item, array_flip( array( 'binding_key', 'criterion_id', 'levels', 'maximum_points', 'position', 'response_id', 'scope' ) ) );
			$effective_criterion             = $criterion;
			$effective_criterion['position'] = $position;
			$effective_criterion['scope']    = $scope;
			$effective_criteria[]            = $effective_criterion;
		}
		if ( count( $seen_criteria ) !== count( $raw ) || $total_units !== $this->decimal_units( $p03['rubric']['total_points'] ?? null, true, '$.publication.grading_schema.payload.rubric.total_points' ) ) {
			throw Submission_Score_Input_Failure::grading_invalid( '$.publication.grading_schema.payload.bindings' );
		}

		return array(
			'provider'     => $provider,
			'manifest'     => $manifest,
			'criteria'     => $effective_criteria,
			'total_points' => $this->decimal_string( $total_units ),
		);
	}

	/**
	 * Binding key.
	 *
	 * @param string      $source_hash Source hash.
	 * @param string      $rubric_hash Rubric hash.
	 * @param string      $scope Scope.
	 * @param string|null $response_id Response id.
	 * @param string      $criterion_id Criterion id.
	 * @return string
	 */
	private function binding_key( string $source_hash, string $rubric_hash, string $scope, ?string $response_id, string $criterion_id ): string {
		return hash(
			'sha256',
			$this->encode(
				array(
					'criterion_id'         => $criterion_id,
					'response_id'          => $response_id,
					'rubric_hash'          => $rubric_hash,
					'scope'                => $scope,
					'source_revision_hash' => $source_hash,
				),
				'$.binding_identity'
			)
		);
	}

	/**
	 * Artifacts.
	 *
	 * @param string                                                                                                     $evaluation_ref Evaluation ref.
	 * @param array<array-key,mixed>                                                                                     $snapshots Snapshots.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> $expected Expected.
	 * @return array{provider:array<int,array<string,mixed>>,references:array<int,array<string,mixed>>,references_by_attachment:array<string,string>,representation_set_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function artifacts( string $evaluation_ref, array $snapshots, array $expected ): array {
		if ( ! array_is_list( $snapshots ) || count( $snapshots ) > self::MAXIMUM_ARTIFACTS || count( $expected ) > self::MAXIMUM_ARTIFACTS ) {
			throw Submission_Score_Input_Failure::invalid( '$.artifact_representations' );
		}
		$by_id = array();
		foreach ( $snapshots as $index => $snapshot ) {
			$path = '$.artifact_representations[' . $index . ']';
			if ( ! is_array( $snapshot ) || array_is_list( $snapshot ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path );
			}
			$this->exact_keys( $snapshot, array( 'anchors', 'attachment_id', 'kind', 'representation', 'representation_hash', 'response_id', 'role', 'source_hash', 'text' ), $path );
			$id = $this->public_id( $snapshot['attachment_id'] ?? null, $path . '.attachment_id' );
			if ( isset( $by_id[ $id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path . '.attachment_id' );
			}
			$by_id[ $id ] = array(
				'snapshot' => $snapshot,
				'path'     => $path,
			);
		}
		$actual_ids   = array_keys( $by_id );
		$expected_ids = array_keys( $expected );
		sort( $actual_ids, SORT_STRING );
		sort( $expected_ids, SORT_STRING );
		if ( $actual_ids !== $expected_ids ) {
			throw Submission_Score_Input_Failure::invalid( '$.artifact_representations' );
		}
		ksort( $by_id, SORT_STRING );

		$provider                 = array();
		$references               = array();
		$references_by_attachment = array();
		$identities               = array();
		$total_text               = 0;
		foreach ( $by_id as $attachment_id => $entry ) {
			$snapshot      = $entry['snapshot'];
			$path          = $entry['path'];
			$expected_item = $expected[ $attachment_id ];
			if (
				$expected_item['response_id'] !== ( $snapshot['response_id'] ?? null )
				|| $expected_item['kind'] !== ( $snapshot['kind'] ?? null )
				|| $expected_item['role'] !== ( $snapshot['role'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path );
			}
			$response_id = $this->public_id( $snapshot['response_id'], $path . '.response_id' );
			$source_hash = $this->sha256( $snapshot['source_hash'] ?? null, $path . '.source_hash' );
			$manifest    = $snapshot['representation'] ?? null;
			if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path . '.representation' );
			}
			$this->exact_keys(
				$manifest,
				array( 'derived_byte_size', 'derived_file_id', 'derived_sha256', 'extractor_contract', 'reason_code', 'redaction_version', 'schema_id', 'schema_version', 'source_hash', 'state' ),
				$path . '.representation'
			);
			$state     = $manifest['state'] ?? null;
			$extractor = $manifest['extractor_contract'] ?? null;
			if (
				self::REPRESENTATION_SCHEMA !== ( $manifest['schema_id'] ?? null )
				|| self::REPRESENTATION_VERSION !== ( $manifest['schema_version'] ?? null )
				|| self::REDACTION_VERSION !== ( $manifest['redaction_version'] ?? null )
				|| ! hash_equals( $source_hash, (string) ( $manifest['source_hash'] ?? '' ) )
				|| ! is_string( $state )
				|| ! is_string( $extractor ) || 1 !== preg_match( '/^[a-z][a-z0-9_.@-]{2,99}$/D', $extractor )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path . '.representation' );
			}
			$representation_hash = $this->sha256( $snapshot['representation_hash'] ?? null, $path . '.representation_hash' );
			if ( ! hash_equals( $representation_hash, hash( 'sha256', $this->encode( $manifest, $path . '.representation' ) ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path . '.representation_hash' );
			}
			if ( ! in_array( $state, array( 'ready', 'unavailable' ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::artifact_not_ready( $path . '.representation.state', $state );
			}

			$text            = $snapshot['text'];
			$anchors         = $snapshot['anchors'];
			$derived_file_id = $manifest['derived_file_id'] ?? null;
			$derived_sha256  = $manifest['derived_sha256'] ?? null;
			$derived_bytes   = $manifest['derived_byte_size'] ?? null;
			$reason          = $manifest['reason_code'] ?? null;
			if ( 'ready' === $state ) {
				$derived_file_id = $this->public_id( $derived_file_id, $path . '.representation.derived_file_id' );
				$derived_sha256  = $this->sha256( $derived_sha256, $path . '.representation.derived_sha256' );
				$text            = $this->bounded_text( $text, $path . '.text', self::MAXIMUM_ARTIFACT_TEXT, false, true );
				if ( ! is_int( $derived_bytes ) || $derived_bytes !== strlen( $text ) || ! hash_equals( $derived_sha256, hash( 'sha256', $text ) ) || null !== $reason ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::invalid( $path . '.representation' );
				}
				$anchors     = $this->anchors( $anchors, $text, $path . '.anchors' );
				$total_text += $this->character_length( $text );
			} elseif (
					null !== $derived_file_id || null !== $derived_sha256 || 0 !== $derived_bytes || null !== $text
					|| ! is_array( $anchors ) || array() !== $anchors
					|| ! is_string( $reason ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $reason )
				) {

					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::invalid( $path . '.representation' );
			}
			if ( $total_text > self::MAXIMUM_ARTIFACT_TEXT_TOTAL ) {
				throw Submission_Score_Input_Failure::invalid( '$.artifact_representations' );
			}

			$artifact_ref = 'AR_' . strtoupper(
				substr(
					hash(
						'sha256',
						$this->encode(
							array(
								'attachment_id'  => $attachment_id,
								'evaluation_ref' => $evaluation_ref,
							),
							'$.artifact_reference'
						)
					),
					0,
					32
				)
			);
			if ( in_array( $artifact_ref, $references_by_attachment, true ) ) {
				throw Submission_Score_Input_Failure::invalid( '$.artifact_representations' );
			}
			$references_by_attachment[ $attachment_id ] = $artifact_ref;
			$provider_item                              = array(
				'anchors'             => $anchors,
				'artifact_ref'        => $artifact_ref,
				'extractor_contract'  => $extractor,
				'kind'                => $expected_item['kind'],
				'redaction_version'   => self::REDACTION_VERSION,
				'representation_hash' => $representation_hash,
				'response_id'         => $response_id,
				'role'                => $expected_item['role'],
				'source_hash'         => $source_hash,
				'state'               => $state,
				'text'                => $text,
				'unavailable_reason'  => $reason,
			);
			$provider[]                                 = $provider_item;
			$references[]                               = array(
				'artifact_ref'        => $artifact_ref,
				'attachment_id'       => $attachment_id,
				'kind'                => $expected_item['kind'],
				'representation'      => $manifest,
				'representation_hash' => $representation_hash,
				'response_id'         => $response_id,
				'role'                => $expected_item['role'],
				'source_hash'         => $source_hash,
			);
			$identity                                   = $provider_item;
			unset( $identity['artifact_ref'] );
			$identity['attachment_id'] = $attachment_id;
			$identities[]              = $identity;
		}

		return array(
			'provider'                 => $provider,
			'references'               => $references,
			'references_by_attachment' => $references_by_attachment,
			'representation_set_hash'  => hash( 'sha256', $this->encode( $identities, '$.representation_set' ) ),
		);
	}

	/**
	 * Anchors.
	 *
	 * @param mixed  $value Value.
	 * @param string $text Text.
	 * @param string $path Path.
	 * @return array<int,array{anchor:string,quote:string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function anchors( $value, string $text, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > 200 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $index => $anchor ) {
			$item_path = $path . '[' . $index . ']';
			if ( ! is_array( $anchor ) || array_is_list( $anchor ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $item_path );
			}
			$this->exact_keys( $anchor, array( 'anchor', 'quote' ), $item_path );
			$key   = $anchor['anchor'] ?? null;
			$quote = $this->bounded_text( $anchor['quote'] ?? null, $item_path . '.quote', 1000, false, true );
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $key ) || isset( $seen[ $key ] ) || ! str_contains( $text, $quote ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $item_path );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'anchor' => $key,
				'quote'  => $quote,
			);
		}
		return $result;
	}

	/**
	 * Provider responses.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @param array<string,mixed>    $answers Answers.
	 * @param array<string,string>   $artifact_refs Artifact refs.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function provider_responses( array $items, array $answers, array $artifact_refs ): array {
		$result = array();
		foreach ( $items as $position => $item ) {
			$path = '$.publication.response_schema.payload.items[' . $position . ']';
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path );
			}
			$response_id = $this->public_id( $item['response_id'] ?? null, $path . '.response_id' );
			$kind        = $item['kind'] ?? null;
			$constraints = $item['constraints'] ?? array();
			if ( ! is_string( $kind ) || ! is_array( $constraints ) || ( array() !== $constraints && array_is_list( $constraints ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path );
			}
			$value    = array_key_exists( $response_id, $answers ) ? $answers[ $response_id ] : null;
			$result[] = array(
				'answer'      => $this->provider_answer( $kind, $value, $constraints, $artifact_refs, $path . '.answer' ),
				'kind'        => $kind,
				'label'       => $this->bounded_text( $item['label'] ?? null, $path . '.label', 255, false, false ),
				'position'    => $position,
				'response_id' => $response_id,
			);
		}
		return $result;
	}

	/**
	 * Provider answer.
	 *
	 * @param string               $kind Kind.
	 * @param mixed                $value Value.
	 * @param array<string,mixed>  $constraints Constraints.
	 * @param array<string,string> $artifact_refs Artifact refs.
	 * @param string               $path Path.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function provider_answer( string $kind, $value, array $constraints, array $artifact_refs, string $path ): array {
		if ( null === $value ) {
			return array( 'value' => null );
		}
		return match ( $kind ) {
			'short_text', 'long_text' => array( 'text' => $this->bounded_text( $value, $path . '.text', 32768, true, true ) ),
			'single_choice' => array( 'option' => $this->option( $constraints, $value, $path . '.option' ) ),
			'multiple_choice' => array( 'options' => $this->options( $constraints, $value, $path . '.options' ) ),
			'checkbox' => array( 'checked' => $value ),
			'number' => array(
				'constraints' => $this->number_constraints( $constraints, $path . '.constraints' ),
				'decimal'     => $value,
			),
			'table' => array( 'rows' => $this->table_rows( $constraints, $value, $path . '.rows' ) ),
			'image_upload', 'file_upload' => array( 'artifacts' => $this->artifact_refs( $value, $artifact_refs, $path . '.artifacts' ) ),
			'drawing' => $this->drawing_refs( $value, $artifact_refs, $path ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			default => throw Submission_Score_Input_Failure::invalid( $path ),
		};
	}

	/**
	 * Option.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $value Value.
	 * @param string              $path Path.
	 * @return array{option_id:string,label:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function option( array $constraints, $value, string $path ): array {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$options = $constraints['options'] ?? null;
		if ( ! is_array( $options ) || ! array_is_list( $options ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		foreach ( $options as $option ) {
			if ( is_array( $option ) && $value === ( $option['option_id'] ?? null ) ) {
				return array(
					'option_id' => $this->public_id( $value, $path . '.option_id' ),
					'label'     => $this->bounded_text( $option['label'] ?? null, $path . '.label', 255, false, false ),
				);
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Score_Input_Failure::invalid( $path );
	}

	/**
	 * Options.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $value Value.
	 * @param string              $path Path.
	 * @return array<int,array{option_id:string,label:string}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function options( array $constraints, $value, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$result = array();
		foreach ( $value as $index => $id ) {
			$result[] = $this->option( $constraints, $id, $path . '[' . $index . ']' );
		}
		return $result;
	}

	/**
	 * Number constraints.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param string              $path Path.
	 * @return array<string,int|string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function number_constraints( array $constraints, string $path ): array {
		$result = array();
		foreach ( array( 'min', 'max', 'step' ) as $field ) {
			if ( array_key_exists( $field, $constraints ) ) {
				$this->decimal_value( $constraints[ $field ], $path . '.' . $field );
				$result[ $field ] = $constraints[ $field ];
			}
		}
		if ( array_key_exists( 'decimal_places', $constraints ) ) {
			if ( ! is_int( $constraints['decimal_places'] ) || $constraints['decimal_places'] < 0 || $constraints['decimal_places'] > 12 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path . '.decimal_places' );
			}
			$result['decimal_places'] = $constraints['decimal_places'];
		}
		if ( array_key_exists( 'unit', $constraints ) ) {
			$result['unit'] = $this->bounded_text( $constraints['unit'], $path . '.unit', 32, false, false );
		}
		return $result;
	}

	/**
	 * Table rows.
	 *
	 * @param array<string,mixed> $constraints Constraints.
	 * @param mixed               $value Value.
	 * @param string              $path Path.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function table_rows( array $constraints, $value, string $path ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$rows     = $constraints['rows'] ?? null;
		$columns  = $constraints['columns'] ?? null;
		$row_mode = $constraints['row_mode'] ?? null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || ! is_array( $columns ) || ! array_is_list( $columns ) || ! in_array( $row_mode, array( 'fixed', 'dynamic' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$row_labels = array();
		foreach ( $rows as $row ) {
			$id                = $this->public_id( is_array( $row ) ? ( $row['row_id'] ?? null ) : null, $path . '.row_id' );
			$row_labels[ $id ] = $this->bounded_text( $row['label'] ?? null, $path . '.row_label', 255, false, false );
		}
		$column_profiles = array();
		foreach ( $columns as $column ) {
			$id                     = $this->public_id( is_array( $column ) ? ( $column['column_id'] ?? null ) : null, $path . '.column_id' );
			$column_profiles[ $id ] = array(
				'kind'  => $column['cell_type'] ?? null,
				'label' => $this->bounded_text( $column['label'] ?? null, $path . '.column_label', 255, false, false ),
			);
		}
		$row_ids = array_keys( $value );
		if ( 'fixed' === $row_mode ) {
			$row_ids = array_values( array_filter( array_keys( $row_labels ), static fn ( string $id ): bool => array_key_exists( $id, $value ) ) );
		} else {
			sort( $row_ids, SORT_STRING );
		}
		$result = array();
		foreach ( $row_ids as $row_id ) {
			$this->public_id( $row_id, $path . '.row_id' );
			$cells       = $value[ $row_id ];
			$cell_result = array();
			foreach ( $column_profiles as $column_id => $profile ) {
				if ( ! array_key_exists( $column_id, $cells ) ) {
					continue;
				}
				$cell_result[] = array(
					'column_id' => $column_id,
					'kind'      => $profile['kind'],
					'label'     => $profile['label'],
					'value'     => $cells[ $column_id ],
				);
			}
			$result[] = array(
				'cells'  => $cell_result,
				'label'  => $row_labels[ $row_id ] ?? null,
				'row_id' => $row_id,
			);
		}
		return $result;
	}

	/**
	 * Artifact refs.
	 *
	 * @param mixed                $value Value.
	 * @param array<string,string> $artifact_refs Artifact refs.
	 * @param string               $path Path.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function artifact_refs( $value, array $artifact_refs, string $path ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$result = array();
		foreach ( $value as $id ) {
			if ( ! is_string( $id ) || ! isset( $artifact_refs[ $id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::invalid( $path );
			}
			$result[] = $artifact_refs[ $id ];
		}
		return $result;
	}

	/**
	 * Drawing refs.
	 *
	 * @param mixed                $value Value.
	 * @param array<string,string> $artifact_refs Artifact refs.
	 * @param string               $path Path.
	 * @return array{document_artifact:string,preview_artifact:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function drawing_refs( $value, array $artifact_refs, string $path ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$document = $value['document_attachment_id'] ?? null;
		$preview  = $value['preview_attachment_id'] ?? null;
		if ( ! is_string( $document ) || ! is_string( $preview ) || ! isset( $artifact_refs[ $document ], $artifact_refs[ $preview ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return array(
			'document_artifact' => $artifact_refs[ $document ],
			'preview_artifact'  => $artifact_refs[ $preview ],
		);
	}

	/**
	 * Evaluation ref.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_ref( string $value ): string {
		if ( 1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', $value ) ) {
			throw Submission_Score_Input_Failure::invalid( '$.evaluation_ref' );
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
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return $value;
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
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return $value;
	}

	/**
	 * Bounded text.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_empty Allow empty.
	 * @param bool   $allow_newline Allow newline.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bounded_text( $value, string $path, int $maximum, bool $allow_empty, bool $allow_newline ): string {
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		try {
			$value = $this->canonical->text( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$count = $this->character_length( $value );
		if (
			( ! $allow_empty && '' === trim( $value ) ) || $count > $maximum
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return $value;
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
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		$parts = explode( '.', $value, 2 );
		$units = ( (int) $parts[0] * 10000 ) + (int) str_pad( $parts[1] ?? '', 4, '0' );
		if ( ! $allow_zero && $units < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::grading_invalid( $path );
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
	 * Decimal value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_value( $value, string $path ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 100 || 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		return $value;
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
			throw Submission_Score_Input_Failure::grading_invalid( $path );
		}
		$value = rtrim( rtrim( $value, '0' ), '.' );
		return '' === $value ? '0' : $value;
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
	private function exact_keys( array $value, array $expected, string $path ): void {
		if ( array() !== $value && array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
	}

	/**
	 * Required keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $required Required.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function required_keys( array $value, array $required, string $path ): void {
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
		}
	}

	/**
	 * Allowed keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $allowed Allowed.
	 * @param string              $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $value, array $allowed, string $path ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Score_Input_Failure::grading_invalid( $path );
			}
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
		if ( ! is_array( $value ) ) {
			return;
		}
		$forbidden = array(
			'submission_id',
			'submission_revision_id',
			'learner_id',
			'learner_user_id',
			'user_id',
			'student_id',
			'student_name',
			'student_email',
			'student_login',
			'email',
			'login',
			'class_id',
			'class_name',
			'class_label',
			'group_id',
			'group_name',
			'group_label',
			'attachment_id',
			'file_id',
			'file_public_id',
			'derived_file_id',
			'private_file_id',
			'original_name',
			'filename',
			'storage_key',
			'storage_path',
			'private_url',
			'url',
			'exif',
			'binary',
			'provider',
			'model',
			'endpoint',
			'secret',
			'authorization',
		);
		foreach ( $value as $key => $item ) {
			$next = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
			if ( is_string( $key ) ) {
				$normalized = strtolower( str_replace( '-', '_', $key ) );
				if ( in_array( $normalized, $forbidden, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Submission_Score_Input_Failure::invalid( $next );
				}
			}
			$this->assert_safe_projection( $item, $next );
		}
	}

	/**
	 * Reject invalid depth.
	 *
	 * @param mixed  $value Value.
	 * @param int    $depth Depth.
	 * @param string $path Path.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_depth( $value, int $depth, string $path ): void {
		if ( $depth > self::MAXIMUM_JSON_DEPTH ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Score_Input_Failure::invalid( $path );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$this->assert_depth( $item, $depth + 1, $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key ) );
			}
		}
	}

	/**
	 * Encode submission score input builder.
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
			throw Submission_Score_Input_Failure::invalid( $path );
		}
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

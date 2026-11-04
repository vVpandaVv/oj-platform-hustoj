<?php
/**
 * Read an unpersisted teacher rubric without starting an assessment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use OJAI\Dashboard\Submission\Assessment\Domain\Manual_Submission_Score;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only reuse of the same immutable rubric validation used when saving. */
final class Read_Submission_Rubric {
	/**
	 * Initialize read-only dependencies; no queue or write repository is available.
	 *
	 * @param Submission_Evaluation_Source_Port_Interface $sources Official source reader.
	 * @param Submission_Score_Input_Builder              $inputs Validated rubric builder.
	 */
	public function __construct( private Submission_Evaluation_Source_Port_Interface $sources, private Submission_Score_Input_Builder $inputs ) {
	}

	/**
	 * Preview after teacher submission/context authorization.
	 *
	 * @param array $submission Authorized official submission.
	 * @param array $context Authorized context query.
	 * @return array
	 * @throws Submission_Evaluation_Failure Rejects a changed or mismatched source.
	 */
	public function read( array $submission, array $context ): array {
		$source = $this->sources->find_official( $submission['id'], $submission['official_revision']['id'], true );
		if ( null === $source ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed failure is mapped and escaped by the REST boundary.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		if (
			$source->submission_id() !== $submission['id']
			|| $source->official_revision_id() !== $submission['official_revision']['id']
			|| $source->source_hash() !== $submission['official_revision']['content_hash']
			|| $source->context_type() !== $context['context_type']
			|| $source->context_id() !== $context['context_id']
			|| $source->context_revision() !== $context['context_revision']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed failure is mapped and escaped by the REST boundary.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SOURCE_STALE );
		}
		// This local reference is neither persisted nor returned as an evaluation identity.
		$input = $this->inputs->build(
			'EV_' . str_repeat( 'A', 32 ),
			$source->submission_id(),
			$source->official_revision_id(),
			$source->official_revision(),
			$source->publication(),
			$source->configuration(),
			$source->artifact_representations()
		);
		$sheet = Manual_Submission_Score::prepare( $input );
		return array(
			'items'         => $sheet['items'],
			'maximum_score' => $sheet['maximum_score'],
			'bindings'      => array_map(
				static fn ( array $binding ): array => array_intersect_key( $binding, array_flip( array( 'binding_key', 'label', 'response_id' ) ) ),
				$input->operation_payload()['bindings']
			),
		);
	}
}

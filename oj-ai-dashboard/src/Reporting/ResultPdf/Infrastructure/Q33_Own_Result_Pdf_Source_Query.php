<?php
/**
 * Q33 adapter for the student result PDF source boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Result_Pdf_Failure;
use OJAI\Dashboard\Reporting\ResultPdf\Port\Own_Result_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Own_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuses Q33 so PDF source authorization cannot drift from the student screen.
 */
final class Q33_Own_Result_Pdf_Source_Query implements Own_Result_Pdf_Source_Query_Port_Interface {
	/**
	 * Initialize q33 own result pdf source query dependencies and state.
	 *
	 * @param Read_Own_Submission_Result $results Results.
	 * @param Canonical_Json             $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Read_Own_Submission_Result $results,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Find current for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_current_for_actor( int $actor_user_id, string $release_id ): ?array {
		try {
			$result = $this->results->read( $actor_user_id, $release_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( Submission_Evaluation_Failure::NOT_FOUND === $failure->reason() ) {
				return null;
			}
			$reason = Submission_Evaluation_Failure::STORAGE_UNAVAILABLE === $failure->reason()
				? Result_Pdf_Failure::STORAGE_UNAVAILABLE
				: Result_Pdf_Failure::SOURCE_INVALID;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( $reason );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}

		$release    = $result['release'] ?? null;
		$projection = $result['projection'] ?? null;
		if ( ! is_array( $release ) || array_is_list( $release ) || ( null !== $projection && ( ! is_array( $projection ) || array_is_list( $projection ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Result_Pdf_Failure::because( Result_Pdf_Failure::SOURCE_INVALID );
		}

		$projection_json = null === $projection ? null : $this->canonical->encode( $projection );
		return array(
			'release_id'      => $release['id'] ?? null,
			'release_hash'    => $release['hash'] ?? null,
			'release_state'   => $release['state'] ?? null,
			'released_at_utc' => $release['released_at_utc'] ?? null,
			'projection'      => $projection,
			'projection_hash' => null === $projection_json ? null : hash( 'sha256', $projection_json ),
		);
	}
}

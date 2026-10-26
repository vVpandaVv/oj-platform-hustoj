<?php
/**
 * Stable worksheet self-evaluation application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable worksheet self-evaluation application failure.
 */
final class Worksheet_Evaluation_Failure extends RuntimeException {
	public const NOT_FOUND                    = 'worksheet_evaluation_not_found';
	public const SOURCE_STALE                 = 'worksheet_evaluation_source_stale';
	public const SOURCE_INVALID               = 'worksheet_evaluation_source_invalid';
	public const INPUT_INVALID                = 'worksheet_evaluation_input_invalid';
	public const PREPARATION_REQUIRED         = 'worksheet_preparation_required';
	public const INPUT_TOO_LARGE              = 'worksheet_evaluation_input_too_large';
	public const CONFIGURATION_UNAVAILABLE    = 'worksheet_evaluation_configuration_unavailable';
	public const PROMPT_UNAVAILABLE           = 'worksheet_evaluation_prompt_unavailable';
	public const NOT_ROUTABLE                 = 'worksheet_evaluation_not_routable';
	public const ROUTING_SNAPSHOT_INVALID     = 'worksheet_evaluation_routing_snapshot_invalid';
	public const IDEMPOTENCY_CONFLICT         = 'worksheet_evaluation_idempotency_conflict';
	public const STORAGE_UNAVAILABLE          = 'worksheet_evaluation_storage_unavailable';
	public const STORED_DATA_INVALID          = 'worksheet_evaluation_stored_data_invalid';
	public const REVIEW_CONFLICT              = 'worksheet_evaluation_review_conflict';
	public const REVIEW_INCOMPLETE            = 'worksheet_evaluation_review_incomplete';
	public const STATE_CONFLICT               = 'worksheet_evaluation_state_conflict';
	public const CONFIGURATION_CHANGED        = 'worksheet_evaluation_configuration_changed';
	public const RESULT_SCHEMA_INVALID        = 'worksheet_evaluation_result_schema_invalid';
	public const RESULT_COLUMNS_DRIFTED       = 'worksheet_evaluation_result_columns_drifted';
	public const RESULT_FLAGS_DRIFTED         = 'worksheet_evaluation_result_flags_drifted';
	public const RESULT_CONTENT_HASH_INVALID  = 'worksheet_evaluation_result_content_hash_invalid';
	public const FINDING_ROWS_INCOMPLETE      = 'worksheet_evaluation_finding_rows_incomplete';
	public const FINDING_ROW_DRIFTED          = 'worksheet_evaluation_finding_row_drifted';
	public const FINDING_CONTENT_HASH_INVALID = 'worksheet_evaluation_finding_content_hash_invalid';
	public const LIST_ITEM_INVALID            = 'worksheet_evaluation_list_item_invalid';
	public const DIMENSIONS_INVALID           = 'worksheet_evaluation_dimensions_invalid';
	public const FINDINGS_INVALID             = 'worksheet_evaluation_findings_invalid';
	public const EVIDENCE_INVALID             = 'worksheet_evaluation_evidence_invalid';
	public const RUN_HISTORY_INVALID          = 'worksheet_evaluation_run_history_invalid';
	public const REVIEW_HISTORY_INVALID       = 'worksheet_evaluation_review_history_invalid';
	public const DETAIL_LINKS_INVALID         = 'worksheet_evaluation_detail_links_invalid';
	public const FINDINGS_WITHOUT_RESULT      = 'worksheet_evaluation_findings_without_result';
	public const RUN_NUMBER_DUPLICATED        = 'worksheet_evaluation_run_number_duplicated';
	public const RUN_SUCCEEDED_WITHOUT_RESULT = 'worksheet_evaluation_run_succeeded_without_result';
	public const RUN_HISTORY_NOT_CONTIGUOUS   = 'worksheet_evaluation_run_history_not_contiguous';
	public const RUN_ERROR_CODE_INVALID       = 'worksheet_evaluation_run_error_code_invalid';
	public const RUN_ERROR_MESSAGE_INVALID    = 'worksheet_evaluation_run_error_message_invalid';

	/**
	 * Safe context.
	 *
	 * @var array<string,string>
	 */
	private array $safe_context;

	/**
	 * Initialize worksheet evaluation failure dependencies and state.
	 *
	 * @param string               $reason Reason.
	 * @param array<string,string> $safe_context Safe context.
	 */
	private function __construct( private string $reason, array $safe_context = array() ) {
		parent::__construct( $reason );
		$this->safe_context = $safe_context;
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string               $reason Reason.
	 * @param array<string,string> $safe_context Safe context.
	 * @return self
	 */
	public static function because( string $reason, array $safe_context = array() ): self {
		return new self( $reason, $safe_context );
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Safe context.
	 *
	 * @return array<string,string>
	 */
	public function safe_context(): array {
		return $this->safe_context;
	}
}

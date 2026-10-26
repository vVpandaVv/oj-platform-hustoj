<?php
/**
 * Shared SQL eligibility for disposable export artifacts.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

/**
 * Shared, fail-closed eligibility for cleanup and its read-only diagnostics.
 */
final class Export_Retention_Query {
	public const RETENTION_CLASS = 'generated_export_24h';

	/**
	 * SQL aliases f, j and p are private file, owning export job and PDF artifact.
	 *
	 * @return string
	 */
	public static function joins(): string {
		return " LEFT JOIN oj_ai_pdf_artifacts p ON f.owner_type = 'pdf_artifact' AND p.public_id = f.owner_public_id"
			. " LEFT JOIN oj_ai_export_jobs j ON (f.owner_type = 'export_job' AND j.public_id = f.owner_public_id)"
			. " OR (f.owner_type = 'pdf_artifact' AND j.id = p.export_job_id)";
	}

	/**
	 * Expiration itself is checked by the caller using a bound UTC parameter.
	 *
	 * @return string
	 */
	public static function eligible(): string {
		return "f.retention_class = 'generated_export_24h' AND f.state = 'ready' AND f.scan_state = 'clean'"
			. ' AND f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL'
			. " AND j.state = 'ready' AND j.lease_token IS NULL AND j.lease_expires_at_utc IS NULL"
			. ' AND j.result_file_public_id = f.public_id AND j.requested_by_user_id = f.created_by_user_id'
			. " AND ((f.owner_type = 'export_job' AND f.purpose = j.artifact_kind AND ("
			. "(j.artifact_kind = 'worksheet_package' AND j.source_type = 'worksheet_revision' AND f.mime_type = 'application/vnd.oj.ai-worksheet+zip')"
			. " OR (j.artifact_kind = 'report_csv' AND j.source_type = 'report_snapshot' AND f.mime_type = 'text/csv')"
			. " OR (j.artifact_kind = 'report_json_package' AND j.source_type = 'report_snapshot' AND f.mime_type = 'application/zip')"
			. " OR (j.artifact_kind = 'submission_bundle' AND j.source_type = 'assignment' AND f.mime_type = 'application/zip')))"
			. " OR (f.owner_type = 'pdf_artifact' AND f.purpose = 'generated_pdf' AND f.mime_type = 'application/pdf'"
			. " AND p.file_public_id = f.public_id AND p.state = 'ready' AND p.expires_at_utc = f.expires_at_utc"
			. ' AND p.source_public_id = j.source_public_id AND p.source_type = j.source_type AND p.source_hash = j.source_hash'
			. " AND ((j.artifact_kind = 'worksheet_pdf' AND j.source_type IN ('worksheet_revision','worksheet_publication'))"
			. " OR (j.artifact_kind = 'submission_pdf' AND j.source_type = 'submission_revision')"
			. " OR (j.artifact_kind = 'result_pdf' AND j.source_type = 'submission_release')"
			. " OR (j.artifact_kind = 'report_pdf' AND j.source_type = 'report_snapshot'))))"
			. self::unreferenced();
	}

	/**
	 * Excludes original submissions, ZIP inputs and other artifact owners.
	 *
	 * @return string
	 */
	private static function unreferenced(): string {
		return ' AND NOT EXISTS (SELECT 1 FROM oj_ai_submission_attachments a WHERE a.file_public_id = f.public_id)'
			. ' AND NOT EXISTS (SELECT 1 FROM oj_ai_zip_uploads u WHERE u.file_public_id = f.public_id)'
			. ' AND NOT EXISTS (SELECT 1 FROM oj_ai_zip_upload_items i WHERE i.file_public_id = f.public_id)'
			. ' AND NOT EXISTS (SELECT 1 FROM oj_ai_export_jobs other_job WHERE other_job.result_file_public_id = f.public_id AND other_job.id <> j.id)'
			. ' AND NOT EXISTS (SELECT 1 FROM oj_ai_pdf_artifacts other_pdf WHERE other_pdf.file_public_id = f.public_id AND (p.id IS NULL OR other_pdf.id <> p.id))';
	}

	/**
	 * Disjoint categories: retained files never count as cleanup failures.
	 *
	 * @return string
	 */
	public static function disposition(): string {
		return "CASE WHEN f.legal_hold_until_utc IS NOT NULL OR f.state = 'bound' THEN 'preserved'"
			. " WHEN j.state IN ('queued','running') THEN 'in_use'"
			. ' WHEN ' . self::eligible() . " THEN 'eligible' ELSE 'blocked' END";
	}
}

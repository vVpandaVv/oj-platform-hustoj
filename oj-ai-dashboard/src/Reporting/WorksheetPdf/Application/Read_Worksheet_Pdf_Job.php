<?php
/**
 * Read worksheet pdf job.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use Closure;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Query_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use Throwable;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read worksheet pdf job.
 */
final class Read_Worksheet_Pdf_Job {
	public const ACTION = 'worksheet.pdf.job.read';
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize worksheet pdf job dependencies and state.
	 *
	 * @param Worksheet_Pdf_Query_Port_Interface $query Query.
	 * @param Access_Policy_Port_Interface       $policy Policy.
	 * @param callable|null                      $clock Clock.
	 */
	public function __construct( private Worksheet_Pdf_Query_Port_Interface $query, private Access_Policy_Port_Interface $policy, ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn(): string=>gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute worksheet pdf job.
	 *
	 * @param int    $actor Actor.
	 * @param string $job_id Job id.
	 * @param string $correlation Correlation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( int $actor, string $job_id, string $correlation ): array {
		$row = $this->query->find_job_for_actor( $actor, $job_id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::NOT_FOUND );
		}
		if ( ! $this->authorized( $actor, (string) $row['source_id'], $correlation ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::ACCESS_DENIED );
		}
		if ( 'ready' === $row['state'] && is_string( $row['expires_at_utc'] ) && $row['expires_at_utc'] <= (string) ( $this->clock )() ) {
			$row['state']       = 'expired';
			$row['artifact_id'] = null;
		}
		return $row;
	}
	/**
	 * Authorized.
	 *
	 * @param int    $actor Actor.
	 * @param string $revision Revision.
	 * @param string $correlation Correlation.
	 * @return bool
	 */
	private function authorized( int $actor, string $revision, string $correlation ): bool {
		try {
			$d = $this->policy->authorize( new Access_Policy_Request( $actor, Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_REST, self::ACTION, array( Addon_Capabilities::EXPORT_REPORTS ), null, new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $revision, 'view', $revision ), true, $correlation ) );
			return $d->is_allowed() && null !== $d->object() && 'owner' === $d->object()->actor_relation();
		} catch ( Throwable ) {
			return false;
		}
	}
}

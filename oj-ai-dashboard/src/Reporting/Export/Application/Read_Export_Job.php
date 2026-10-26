<?php
/**
 * Read export job.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use Closure;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Read export job.
 */
final class Read_Export_Job {
	public const ACTION = 'export.job.read';
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Access.
	 *
	 * @var Export_Access_Guard
	 */
	private Export_Access_Guard $access;
	/**
	 * Initialize export job dependencies and state.
	 *
	 * @param Export_Query_Port_Interface  $query Query.
	 * @param Access_Policy_Port_Interface $policy Policy.
	 * @param callable|null                $clock Clock.
	 */
	public function __construct( private Export_Query_Port_Interface $query, Access_Policy_Port_Interface $policy, ?callable $clock = null ) {
		$this->access = new Export_Access_Guard( $policy );
		$this->clock  = null === $clock ? static fn(): string=>gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute export job.
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
			throw Export_Failure::because( Export_Failure::NOT_FOUND );
		}
		if ( ! $this->access->allows( $actor, $row, Access_Policy_Request::SURFACE_REST, self::ACTION, $correlation ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::ACCESS_DENIED );
		}
		unset( $row['authorization'] );
		if ( 'ready' === $row['state'] && is_string( $row['expires_at_utc'] ) && $row['expires_at_utc'] <= (string) ( $this->clock )() ) {
			$row['state']       = 'expired';
			$row['artifact_id'] = null;
		}
		return $row;
	}
}

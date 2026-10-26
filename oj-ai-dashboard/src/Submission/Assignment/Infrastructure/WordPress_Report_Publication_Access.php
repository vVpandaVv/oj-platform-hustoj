<?php
/**
 * WordPress report publication access.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Port\Report_Publication_Access_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

/**
 * Checks current report permission and the owning assignment's publication binding.
 */
final class WordPress_Report_Publication_Access implements Report_Publication_Access_Port_Interface {

	/**
	 * Initialize WordPress report publication access dependencies and state.
	 *
	 * @param Context_Authorization_Port_Interface $contexts Contexts.
	 * @param object|null                          $database Database.
	 */
	public function __construct( private Context_Authorization_Port_Interface $contexts, private ?object $database = null ) {
	}

	/**
	 * Authorize.
	 *
	 * @param int                          $actor Actor.
	 * @param Context_Authorization_Result $context Access or execution context for this operation.
	 * @param array                        $sources Sources.
	 * @return Object_Authorization_Result
	 */
	public function authorize( int $actor, Context_Authorization_Result $context, array $sources ): Object_Authorization_Result {
		if ( $actor < 1 || ! $context->is_allowed() || count( $sources ) > Create_Report_Snapshot::MAXIMUM_ROWS ) {
			return Object_Authorization_Result::denied();
		}
		try {
			$current = $this->contexts->authorize(
				$actor,
				new Context_Scope(
					(string) $context->type(),
					(string) $context->id(),
					Context_Scope::OPERATION_REPORT,
					null,
					$context->revision()
				)
			);
			if ( $current->is_denied() ) {
				return Object_Authorization_Result::denied();
			}
			if ( ! $current->is_allowed() ) {
				return Object_Authorization_Result::unavailable();
			}
			if ( $current->type() !== $context->type() || $current->id() !== $context->id()
				|| $current->revision() !== $context->revision() || $current->actor_relation() !== $context->actor_relation() ) {
				return Object_Authorization_Result::denied();
			}
			$expected = array();
			foreach ( $sources as $source ) {
				if ( ! Public_Id::is_valid( $source['worksheet_id'] ) || ! Public_Id::is_valid( $source['publication_id'] )
					|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source['publication_hash'] ) || isset( $expected[ $source['publication_id'] ] ) ) {
					return Object_Authorization_Result::denied();
				}
				$expected[ $source['publication_id'] ] = $source;
			}
			if ( array() !== $expected ) {
				$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
				if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_results' ) ) {
					return Object_Authorization_Result::unavailable();
				}
				foreach ( array_chunk( $expected, 200, true ) as $chunk ) {
					$sql      = 'SELECT DISTINCT w.public_id AS worksheet_id, p.public_id AS publication_id, p.publication_hash '
						. 'FROM oj_ai_assignments a INNER JOIN oj_ai_worksheet_publications p '
						. 'ON p.public_id = a.worksheet_publication_public_id AND p.publication_hash = a.publication_hash '
						. 'INNER JOIN oj_ai_worksheets w ON w.id = p.worksheet_id '
						. 'WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
						. "AND a.state IN ('scheduled','open','closed') AND p.state IN ('active','retired') "
						. 'AND p.public_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
					$prepared = $db->prepare( $sql, $context->type(), $context->id(), $context->revision(), ...array_keys( $chunk ) );
					if ( ! is_string( $prepared ) || '' === $prepared ) {
						return Object_Authorization_Result::unavailable();
					}
					if ( property_exists( $db, 'last_error' ) ) {
						$db->last_error = '';
					}
					$rows = $db->get_results( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
					if ( ! is_array( $rows ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
						return Object_Authorization_Result::unavailable();
					}
					$found = array();
					foreach ( $rows as $row ) {
						$id = $row['publication_id'] ?? null;
						if ( ! is_string( $id ) || ! isset( $chunk[ $id ] ) || isset( $found[ $id ] )
							|| ( $row['worksheet_id'] ?? null ) !== $chunk[ $id ]['worksheet_id']
							|| ( $row['publication_hash'] ?? null ) !== $chunk[ $id ]['publication_hash'] ) {
							return Object_Authorization_Result::denied();
						}
						$found[ $id ] = true;
					}
					if ( count( $found ) !== count( $chunk ) ) {
						return Object_Authorization_Result::denied();
					}
				}
			}
			return Object_Authorization_Result::allowed( (string) $current->revision(), 'ready', 'context_reporter' );
		} catch ( Throwable ) {
			return Object_Authorization_Result::unavailable();
		}
	}
}

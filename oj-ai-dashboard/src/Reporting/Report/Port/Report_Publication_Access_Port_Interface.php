<?php
/**
 * Report publication access port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;

/**
 * Authorizes the exact published sources of a context report, not editable drafts.
 */
interface Report_Publication_Access_Port_Interface {

	/**
	 * Authorize.
	 *
	 * @param int                                                                            $actor Actor.
	 * @param Context_Authorization_Result                                                   $context Access or execution context for this operation.
	 * @param list<array{worksheet_id:string,publication_id:string,publication_hash:string}> $sources Sources.
	 * @return Object_Authorization_Result
	 */
	public function authorize( int $actor, Context_Authorization_Result $context, array $sources ): Object_Authorization_Result;
}

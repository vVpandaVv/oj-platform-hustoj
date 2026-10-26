<?php
/**
 * Schema metadata inspection port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the literal add-on namespace and normalized table metadata.
 */
interface Schema_Inspector_Port_Interface {
	/**
	 * Literal table names.
	 *
	 * @return array<int,string>
	 */
	public function literal_table_names(): array;

	/**
	 * Metadata.
	 *
	 * @param array<int,string> $table_names Exact literal names.
	 * @return array<string,array<string,mixed>> Metadata keyed by table name.
	 */
	public function metadata( array $table_names ): array;
}

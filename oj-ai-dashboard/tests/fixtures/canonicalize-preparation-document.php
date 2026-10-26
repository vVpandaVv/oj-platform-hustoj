<?php
/**
 * Canonicalize preparation document.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
ob_start();
require __DIR__ . '/worksheet-workbench-document-verifier.php';
ob_end_clean();
$previews = new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector(
	new \OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json()
);
echo json_encode(
	array(
		'document'         => $canonical->document(),
		'temporary_id_map' => $canonical->temporary_id_map(),
		'document_hash'    => $canonical->document_hash(),
		'student_preview'  => $previews->student( $canonical )->projection(),
		'print_preview'    => $previews->print( $canonical, 'student_blank' )->projection(),
	),
	JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);

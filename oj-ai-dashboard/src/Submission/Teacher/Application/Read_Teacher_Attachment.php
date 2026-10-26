<?php
/**
 * Read teacher attachment.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Teacher\Application;

use Closure;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Student_File_Content;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Attachment_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only the official submission projection can authorize an attachment read.
 */
final class Read_Teacher_Attachment {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize teacher attachment dependencies and state.
	 *
	 * @param Read_Teacher_Submissions                $submissions Submissions.
	 * @param Teacher_Attachment_Query_Port_Interface $metadata Metadata.
	 * @param Private_File_Read_Port_Interface        $files Files.
	 * @param callable|null                           $clock Clock.
	 */
	public function __construct(
		private Read_Teacher_Submissions $submissions,
		private Teacher_Attachment_Query_Port_Interface $metadata,
		private Private_File_Read_Port_Interface $files,
		?callable $clock = null
	) { $this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Open.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @return Student_File_Content
	 */
	public function open( string $submission_id, string $attachment_id, string $type, string $id, string $revision ): Student_File_Content {
		$row = $this->authorize( $submission_id, $attachment_id, $type, $id, $revision );
		return new Student_File_Content( $this->files->readable_path( $row['storage_key'], $row['sha256'] ), $row );
	}

	/**
	 * Image data uri.
	 *
	 * @param string                                       $submission_id Submission public identifier.
	 * @param array<string,mixed>                          $expected Expected.
	 * @param array{type:string,id:string,revision:string} $context Access or execution context for this operation.
	 * @return string
	 */
	public function image_data_uri( string $submission_id, array $expected, array $context ): string {
		$args = array( $submission_id, $expected['id'], $context['type'], $context['id'], $context['revision'] );
		$row  = $this->authorize( ...$args );
		if ( ! in_array( $row['mime_type'], array( 'image/png', 'image/jpeg', 'image/webp' ), true )
			|| $row['mime_type'] !== $expected['mime_type'] || $row['sha256'] !== $expected['sha256']
			|| $row['byte_size'] !== $expected['byte_size'] || $row['original_name'] !== $expected['name'] ) {
			$this->deny();
		}
		$content = $this->open( ...$args );
		if ( ! $content->matches( $row ) ) {
			$content->close();
			$this->deny();
		}
		ob_start();
		try {
			$content->emit();
			$bytes = (string) ob_get_contents(); } finally {
			ob_end_clean();
			$content->close();
			}
			$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error.
			if ( false === $info || $info['mime'] !== $row['mime_type'] ) {
				$this->deny();
			}
			return 'data:' . $row['mime_type'] . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is the explicit cursor/binary representation codec, not code obfuscation.
	}

	/**
	 * Authorize.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @return array<string,mixed> Internal integrity evidence, not a client payload.
	 */
	public function authorize( string $submission_id, string $attachment_id, string $type, string $id, string $revision ): array {
		new Public_Id( $attachment_id );
		$submission = $this->submissions->detail( $submission_id, $type, $id, $revision )['submission'];
		$listed     = null;
		foreach ( $submission['attachments'] ?? array() as $item ) {
			if ( $attachment_id === ( $item['id'] ?? null ) ) {
				$listed = $item;
				break;
			}
		}
		if ( null === $listed || 'bound' !== ( $listed['state'] ?? null )
			|| ! in_array( 'official', $listed['revision_scope'] ?? array(), true )
			|| ! $this->contains( $submission['official_revision']['response']['answers'][ $listed['response_id'] ] ?? null, $attachment_id ) ) {
			$this->deny();
		}
		$row      = $this->metadata->find( $submission_id, $attachment_id );
		$required = array(
			'storage_key',
			'original_name',
			'mime_type',
			'byte_size',
			'sha256',
			'state',
			'scan_state',
			'expires_at_utc',
			'purged_at_utc',
			'attachment_id',
			'response_key',
			'attachment_state',
			'submission_revision_id',
			'attachment_creator',
			'created_by_user_id',
			'attachment_name',
			'attachment_mime',
			'attachment_size',
			'attachment_hash',
		);
		if ( null === $row || array() !== array_diff( $required, array_keys( $row ) ) ) {
			$this->deny();
		}
		foreach ( array( 'storage_key', 'original_name', 'mime_type', 'sha256' ) as $field ) {
			if ( ! is_string( $row[ $field ] ) || '' === $row[ $field ] ) {
				$this->deny();
			}
		}
		if ( $attachment_id !== $row['attachment_id'] || $listed['response_id'] !== $row['response_key']
			|| 'bound' !== $row['attachment_state'] || (int) $row['submission_revision_id'] < 1
			|| (int) $row['attachment_creator'] < 1 || (int) $row['attachment_creator'] !== (int) $row['created_by_user_id']
			|| 'ready' !== $row['state'] || 'clean' !== $row['scan_state'] || null !== $row['purged_at_utc'] ) {
			$this->deny();
		}
		foreach ( array(
			'name'      => array( 'original_name', 'attachment_name' ),
			'mime_type' => array( 'mime_type', 'attachment_mime' ),
			'sha256'    => array( 'sha256', 'attachment_hash' ),
		) as $field => $keys ) {
			if ( ! is_string( $listed[ $field ] ?? null ) || $listed[ $field ] !== $row[ $keys[0] ] || $listed[ $field ] !== $row[ $keys[1] ] ) {
				$this->deny();
			}
		}
		$size   = (int) $row['byte_size'];
		$now    = ( $this->clock )();
		$expiry = $row['expires_at_utc'];
		if ( $size < 1 || $size > Submission_Attachment_Upload_Profile::MAX_FILE_BYTES
			|| $size !== (int) $row['attachment_size'] || $size !== (int) ( $listed['byte_size'] ?? -1 )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $row['sha256'] )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now )
			|| ( null !== $expiry && ( ! is_string( $expiry ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expiry ) || $expiry <= $now ) )
			|| strlen( $row['original_name'] ) > 255 || 1 !== preg_match( '//u', $row['original_name'] )
			|| preg_match( '/[\x00-\x1f\x7f\/\\\\]/', $row['original_name'] )
			|| preg_match( '/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $row['original_name'] ) ) {
			$this->deny();
		}
		$row['byte_size'] = $size;
		return $row;
	}

	/**
	 * Contains.
	 *
	 * @param mixed  $value Value.
	 * @param string $id Id.
	 * @param int    $depth Depth.
	 * @return bool
	 */
	private function contains( mixed $value, string $id, int $depth = 0 ): bool {
		if ( $depth > 32 ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return hash_equals( $id, $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( $this->contains( $item, $id, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Deny.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deny(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
	}
}

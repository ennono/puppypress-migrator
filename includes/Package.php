<?php
/**
 * The migration package (ZIP).
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Layout:
 *   data.json        All records (see Exporter::collect()).
 *   uploads/{path}   Media files, relative to the uploads folder.
 */
final class Package {

	const FORMAT  = 'puppypress-migration';
	/**
	 * Format version. 2 added file sizes and media transfer by download.
	 */
	const VERSION = 2;

	const DATA_ENTRY    = 'data.json';
	const UPLOADS_ENTRY = 'uploads/';

	/**
	 * File extensions that are never extracted, even if WordPress would allow them.
	 */
	const BLOCKED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'exe', 'js', 'html', 'htm', 'svg', 'svgz' );

	/**
	 * Whether ZIP support is available.
	 *
	 * @return bool
	 */
	public static function supported() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Validates the relative path of a media file inside the package.
	 *
	 * @param string $path Path relative to uploads/ (e.g. "2020/04/dog.jpg").
	 * @return bool
	 */
	public static function is_allowed_upload_path( $path ) {
		return '' === self::rejection_reason( $path );
	}

	/**
	 * Why a media path is not transferred.
	 *
	 * @param string $path Path relative to uploads/.
	 * @return string Empty string if the path is allowed, otherwise a short reason.
	 */
	public static function rejection_reason( $path ) {
		$path = (string) $path;

		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) || '/' === $path[0] || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || preg_match( '#^[a-z]:#i', $path ) || 0 !== validate_file( $path ) ) {
			return __( 'invalid path', 'puppypress-migrator' );
		}

		$basename = basename( $path );

		if ( '.' === $basename[0] ) {
			return __( 'hidden file', 'puppypress-migrator' );
		}

		$parts     = explode( '.', strtolower( $basename ) );
		$extension = count( $parts ) > 1 ? end( $parts ) : '';
		array_shift( $parts );

		// Reject blocked types, also hidden in double extensions like "dog.php.jpg".
		foreach ( $parts as $part ) {
			if ( in_array( $part, self::blocked_extensions(), true ) ) {
				return $part === $extension
					/* translators: %s: file extension */
					? sprintf( __( 'file type .%s is blocked for security reasons', 'puppypress-migrator' ), $part )
					/* translators: %s: file extension */
					: sprintf( __( 'double extension with .%s', 'puppypress-migrator' ), $part );
			}
		}

		$type = wp_check_filetype( $basename, wp_get_mime_types() );

		if ( empty( $type['ext'] ) ) {
			/* translators: %s: file extension */
			return sprintf( __( 'file type .%s is unknown to WordPress', 'puppypress-migrator' ), $extension );
		}

		return '';
	}

	/**
	 * Blocked extensions (filterable, e.g. to allow SVG between trusted sites).
	 *
	 * @return string[]
	 */
	public static function blocked_extensions() {
		/**
		 * Filters the file extensions that are never transferred.
		 *
		 * @param string[] $extensions Lower case extensions without dot.
		 */
		return (array) apply_filters( 'ppm_blocked_extensions', self::BLOCKED_EXTENSIONS );
	}
}

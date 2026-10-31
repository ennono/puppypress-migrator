<?php
/**
 * Parallel download of media files from the old site.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads files straight to disk, several at a time.
 *
 * Every file is written to a temporary name first and only moved into place
 * after the HTTP status and (if known) the file size have been checked.
 */
final class Downloader {

	/**
	 * Parallel connections.
	 */
	const CONCURRENCY = 8;

	/**
	 * Downloads a batch of files.
	 *
	 * @param array $items List of array{url: string, target: string, size: int|null}.
	 * @return array<int, true|string> Per item index: true or an error message.
	 */
	public static function fetch( array $items ) {
		/**
		 * Short-circuits the download, e.g. for tests or custom transports.
		 *
		 * @param array|null $results Null to download normally, or results per item index.
		 * @param array      $items   Items.
		 */
		$results = apply_filters( 'ppm_download_batch', null, $items );

		if ( is_array( $results ) ) {
			return $results;
		}

		$results = array();

		foreach ( array_chunk( $items, self::CONCURRENCY, true ) as $chunk ) {
			$results += self::requests_class() ? self::fetch_parallel( $chunk ) : self::fetch_sequential( $chunk );
		}

		return $results;
	}

	/**
	 * URL of a file below an uploads base URL; every path segment is encoded.
	 *
	 * @param string $base     Uploads base URL.
	 * @param string $relative Relative path.
	 * @return string
	 */
	public static function url( $base, $relative ) {
		return untrailingslashit( $base ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * Parallel download with the Requests library bundled with WordPress.
	 *
	 * @param array $items Items (keys preserved).
	 * @return array<int, true|string>
	 */
	private static function fetch_parallel( array $items ) {
		$class    = self::requests_class();
		$requests = array();
		$temps    = array();

		foreach ( $items as $index => $item ) {
			wp_mkdir_p( dirname( $item['target'] ) );
			$temps[ $index ] = $item['target'] . '.ppm-part';

			$requests[ $index ] = array(
				'url'     => $item['url'],
				'type'    => 'GET',
				'headers' => array(),
				'data'    => array(),
				'options' => array(
					'filename'        => $temps[ $index ],
					'timeout'         => 120,
					'connect_timeout' => 15,
					'useragent'       => 'PuppyPress Migrator/' . PPM_VERSION,
					'verify'          => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
					'redirects'       => 5,
				),
			);
		}

		try {
			$responses = $class::request_multiple( $requests );
		} catch ( \Throwable $e ) {
			return self::fetch_sequential( $items );
		}

		$results = array();

		foreach ( $items as $index => $item ) {
			$response = isset( $responses[ $index ] ) ? $responses[ $index ] : null;

			if ( ! is_object( $response ) || $response instanceof \Throwable || ! isset( $response->status_code ) ) {
				$results[ $index ] = $response instanceof \Throwable ? $response->getMessage() : 'no response';
			} else {
				$results[ $index ] = self::finalize( $item, $temps[ $index ], (int) $response->status_code );
				continue;
			}

			@unlink( $temps[ $index ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		return $results;
	}

	/**
	 * Fallback: one file after another with the WordPress HTTP API.
	 *
	 * @param array $items Items (keys preserved).
	 * @return array<int, true|string>
	 */
	private static function fetch_sequential( array $items ) {
		$results = array();

		foreach ( $items as $index => $item ) {
			wp_mkdir_p( dirname( $item['target'] ) );
			$temp     = $item['target'] . '.ppm-part';
			$response = wp_remote_get(
				$item['url'],
				array(
					'timeout'  => 120,
					'stream'   => true,
					'filename' => $temp,
				)
			);

			if ( is_wp_error( $response ) ) {
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$results[ $index ] = $response->get_error_message();
				continue;
			}

			$results[ $index ] = self::finalize( $item, $temp, (int) wp_remote_retrieve_response_code( $response ) );
		}

		return $results;
	}

	/**
	 * Checks a downloaded file and moves it into place.
	 *
	 * @param array  $item   Item.
	 * @param string $temp   Temporary file.
	 * @param int    $status HTTP status.
	 * @return true|string
	 */
	private static function finalize( array $item, $temp, $status ) {
		clearstatcache( true, $temp );

		if ( 200 !== $status ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 'HTTP ' . $status;
		}

		$size = is_file( $temp ) ? filesize( $temp ) : false;

		if ( false === $size || ( null !== $item['size'] && (int) $item['size'] !== $size ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			/* translators: 1: received bytes, 2: expected bytes */
			return sprintf( __( 'incomplete (%1$d of %2$d bytes)', 'puppypress-migrator' ), (int) $size, (int) $item['size'] );
		}

		if ( ! rename( $temp, $item['target'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'could not be saved', 'puppypress-migrator' );
		}

		return true;
	}

	/**
	 * Requests class of the running WordPress version.
	 *
	 * @return string Class name or empty string.
	 */
	private static function requests_class() {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return '\WpOrg\Requests\Requests';
		}

		return class_exists( '\Requests' ) ? '\Requests' : '';
	}
}

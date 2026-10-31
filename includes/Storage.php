<?php
/**
 * Protected working directory for export and import jobs.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Files live in wp-content/uploads/puppypress-migrator/. The folder is
 * protected against direct web access (Apache/IIS); job folders use long
 * random names so that URLs cannot be guessed on other servers either.
 */
final class Storage {

	const DIR = 'puppypress-migrator';

	/**
	 * Jobs without activity for this long are removed by the daily cleanup.
	 */
	const MAX_AGE = 3 * DAY_IN_SECONDS;

	/**
	 * Base directory (created and protected on first use).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::protect( $dir );

		foreach ( array( 'jobs', 'incoming' ) as $sub ) {
			if ( ! is_dir( $dir . '/' . $sub ) ) {
				wp_mkdir_p( $dir . '/' . $sub );
			}
			self::protect( $dir . '/' . $sub );
		}

		return $dir;
	}

	/**
	 * Folder for ZIP files uploaded via FTP/SFTP.
	 *
	 * @return string
	 */
	public static function incoming_dir() {
		return self::base_dir() . '/incoming';
	}

	/**
	 * ZIP files waiting in the incoming folder.
	 *
	 * @return string[] File names.
	 */
	public static function incoming_files() {
		$files = glob( self::incoming_dir() . '/*.zip' );

		return $files ? array_map( 'basename', $files ) : array();
	}

	/**
	 * Creates a new job folder.
	 *
	 * @return string Job ID.
	 */
	public static function create_job_dir() {
		$id = strtolower( wp_generate_password( 32, false, false ) );
		wp_mkdir_p( self::job_dir( $id ) );
		self::protect( self::job_dir( $id ) );

		return $id;
	}

	/**
	 * Path of a job folder.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	public static function job_dir( $id ) {
		return self::base_dir() . '/jobs/' . self::sanitize_id( $id );
	}

	/**
	 * Validates a job ID.
	 *
	 * @param string $id Raw ID.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
	}

	/**
	 * Deletes a job folder recursively.
	 *
	 * @param string $id Job ID.
	 */
	public static function delete_job( $id ) {
		$id = self::sanitize_id( $id );

		if ( '' !== $id ) {
			self::rmdir( self::job_dir( $id ) );
		}
	}

	/**
	 * Removes old jobs (daily cron).
	 */
	public static function cleanup() {
		foreach ( (array) glob( self::base_dir() . '/jobs/*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! $dir ) {
				continue;
			}

			// The state file is rewritten on every step, so this is the time of the last activity.
			$state = $dir . '/state.json';
			$time  = is_file( $state ) ? filemtime( $state ) : filemtime( $dir );

			if ( $time < time() - self::MAX_AGE ) {
				self::rmdir( $dir );
			}
		}
	}

	/**
	 * Adds files that block web access and directory listings.
	 *
	 * @param string $dir Directory.
	 */
	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# PuppyPress Migrator: no web access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $dir Directory.
	 */
	private static function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

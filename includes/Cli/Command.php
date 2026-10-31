<?php
/**
 * WP-CLI commands.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator\Cli;

use PuppyPressMigrator\Exporter;
use PuppyPressMigrator\Importer;
use PuppyPressMigrator\Job;
use PuppyPressMigrator\Runner;
use PuppyPressMigrator\Storage;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and imports PuppyPress migration packages.
 *
 * Recommended for large sites: no upload limits and no request timeouts.
 */
final class Command {

	/**
	 * Creates a migration package of this site.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Directory for the ZIP file. Default: current directory.
	 *
	 * [--media=<media>]
	 * : Media files to include.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - referenced
	 *   - none
	 * ---
	 *
	 * [--media-transfer=<transfer>]
	 * : How media files get to the new site.
	 * ---
	 * default: download
	 * options:
	 *   - download
	 *   - zip
	 * ---
	 *
	 * [--skip-content]
	 * : Do not export posts and pages.
	 *
	 * [--skip-menus]
	 * : Do not export menus.
	 *
	 * [--skip-comments]
	 * : Do not export comments.
	 *
	 * [--skip-widgets]
	 * : Do not export widgets.
	 *
	 * ## EXAMPLES
	 *
	 *     wp puppypress-migrator export --dir=/tmp
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$dir = isset( $assoc_args['dir'] ) ? $assoc_args['dir'] : getcwd();

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			WP_CLI::error( "Directory not writable: {$dir}" );
		}

		$job = Job::create(
			'export',
			array(
				'content'  => empty( $assoc_args['skip-content'] ),
				'menus'    => empty( $assoc_args['skip-menus'] ),
				'comments' => empty( $assoc_args['skip-comments'] ),
				'widgets'  => empty( $assoc_args['skip-widgets'] ),
				'media'    => isset( $assoc_args['media'] ) ? $assoc_args['media'] : 'all',
				'transfer' => isset( $assoc_args['media-transfer'] ) && 'zip' === $assoc_args['media-transfer'] ? 'zip' : 'download',
			)
		);

		$this->run( $job );

		$target = trailingslashit( $dir ) . Exporter::download_name( $job );

		if ( ! copy( $job->path( Exporter::ZIP_NAME ), $target ) ) {
			WP_CLI::error( "Could not write {$target}" );
		}

		Storage::delete_job( $job->id );
		WP_CLI::success( "Export written to {$target}" );
	}

	/**
	 * Imports a migration package into this site.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the ZIP file.
	 *
	 * [--author=<user>]
	 * : Login or ID of the user who receives content of unknown authors. Default: first administrator.
	 *
	 * [--create-users]
	 * : Create missing authors with random passwords.
	 *
	 * [--media-source=<url>]
	 * : Uploads URL of the old site, if it moved since the export.
	 *
	 * [--no-replace-urls]
	 * : Keep links to the old domain.
	 *
	 * [--skip-widgets]
	 * : Do not import widgets.
	 *
	 * [--skip-settings]
	 * : Do not change front page, sticky posts and menu locations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp puppypress-migrator import puppypress-export-example.org-2026-10-31.zip --author=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "File not readable: {$file}" );
		}

		$author = 0;
		if ( ! empty( $assoc_args['author'] ) ) {
			$user = is_numeric( $assoc_args['author'] ) ? get_userdata( (int) $assoc_args['author'] ) : get_user_by( 'login', $assoc_args['author'] );
			if ( ! $user ) {
				WP_CLI::error( "User not found: {$assoc_args['author']}" );
			}
			$author = $user->ID;
		}

		$job = Job::create(
			'import',
			array(
				'create_users' => ! empty( $assoc_args['create-users'] ),
				'author'       => $author,
				'replace_urls' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'replace-urls', true ),
				'widgets'      => empty( $assoc_args['skip-widgets'] ),
				'settings'     => empty( $assoc_args['skip-settings'] ),
				'media_source' => isset( $assoc_args['media-source'] ) ? $assoc_args['media-source'] : '',
			)
		);

		if ( ! copy( $file, $job->path( Importer::ZIP_NAME ) ) ) {
			WP_CLI::error( 'Could not copy the package into the job folder.' );
		}

		$this->run( $job );

		WP_CLI::success( 'Import finished.' );
	}

	/**
	 * Runs a job to completion and prints its log.
	 *
	 * @param Job $job Job.
	 */
	private function run( Job $job ) {
		$shown = 0;
		$stage = '';

		while ( ! $job->state['done'] ) {
			Runner::run( $job, 30.0 );

			$progress = Runner::progress( $job );
			if ( $progress['stage'] && $progress['stage'] !== $stage ) {
				$stage = $progress['stage'];
				WP_CLI::log( "» {$stage}" );
			}

			$stats = $job->state['stats'];
			if ( ! $job->state['done'] && ! empty( $stats['files_total'] ) && isset( $stats['files_done'] ) && $stats['files_done'] < $stats['files_total'] ) {
				WP_CLI::log( sprintf( '  %d / %d', $stats['files_done'], $stats['files_total'] ) );
			}

			foreach ( $job->log_since( $shown ) as $entry ) {
				$method = 'warning' === $entry['level'] ? 'warning' : 'log';
				if ( 'error' !== $entry['level'] ) {
					WP_CLI::$method( $entry['message'] );
				}
			}
			$shown = count( $job->state['log'] );
		}

		if ( ! empty( $job->state['error'] ) ) {
			WP_CLI::error( $job->state['error'] );
		}
	}
}

<?php
/**
 * Runs job stages within a time budget.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * Executes as many stage chunks as fit into one request.
 */
final class Runner {

	/**
	 * Runs a job for up to $seconds seconds.
	 *
	 * @param Job   $job     Job.
	 * @param float $seconds Time budget (0 = until done).
	 * @return Job
	 */
	public static function run( Job $job, $seconds = 15.0 ) {
		if ( $job->state['done'] ) {
			return $job;
		}

		// Only one request may work on a job at a time (e.g. two open browser tabs).
		$lock = fopen( $job->path( 'lock' ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			$job->busy = true;
			return $job;
		}

		$job->busy = false;
		$job->state = Job::load( $job->id )->state; // Pick up progress made by the previous lock holder.

		$worker = 'export' === $job->state['type'] ? new Exporter() : new Importer();
		$stages = $worker->stages();
		$start  = microtime( true );

		$job->deadline = $seconds > 0 ? $start + $seconds : INF;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, (int) $seconds * 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		try {
			while ( ! $job->state['done'] ) {
				$stage = $stages[ $job->state['stage'] ];

				$job->state['stage_name'] = $stage;

				if ( $worker->$stage( $job ) ) {
					$job->state['stage']++;
					$job->state['cursor'] = 0;

					if ( $job->state['stage'] >= count( $stages ) ) {
						$job->state['done']     = true;
						$job->state['finished'] = time();
					}
				}

				$job->save();

				if ( $seconds > 0 && microtime( true ) - $start > $seconds ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$job->state['done']  = true;
			$job->state['error'] = $e->getMessage();
			$job->log( $e->getMessage(), 'error' );
			$job->save();
		}

		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $job;
	}

	/**
	 * Progress information for the UI.
	 *
	 * @param Job $job Job.
	 * @return array{percent: int, stage: string}
	 */
	public static function progress( Job $job ) {
		$worker = 'export' === $job->state['type'] ? new Exporter() : new Importer();
		$stages = $worker->stages();
		$count  = count( $stages );

		if ( $job->state['done'] ) {
			return array(
				'percent' => 100,
				'stage'   => '',
			);
		}

		$index = min( (int) $job->state['stage'], $count - 1 );

		return array(
			'percent' => (int) floor( 100 * $index / $count ),
			'stage'   => self::stage_label( $stages[ $index ] ),
		);
	}

	/**
	 * Human readable counters of a job ("12 of 40 files: 10 downloaded …").
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	public static function summary( Job $job ) {
		$s     = $job->state['stats'];
		$n     = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ] ) ? (int) $s[ $key ] : 0;
		};
		$lines = array();

		if ( 'export' === $job->state['type'] ) {
			if ( $n( 'files_total' ) ) {
				/* translators: 1: files packed, 2: total files */
				$lines[] = sprintf( __( 'Files packed: %1$s of %2$s', 'puppypress-migrator' ), number_format_i18n( $n( 'files_done' ) ), number_format_i18n( $n( 'files_total' ) ) );
			}
			if ( $n( 'size' ) ) {
				/* translators: %s: file size */
				$lines[] = sprintf( __( 'Package size: %s', 'puppypress-migrator' ), size_format( $n( 'size' ), 1 ) );
			}
			return $lines;
		}

		if ( $n( 'files_total' ) ) {
			$lines[] = sprintf(
				/* translators: 1: files done, 2: total, 3: downloaded, 4: extracted, 5: already present, 6: failed */
				__( 'Media files: %1$s of %2$s checked (%3$s downloaded, %4$s extracted, %5$s already present, %6$s failed)', 'puppypress-migrator' ),
				number_format_i18n( $n( 'files_done' ) ),
				number_format_i18n( $n( 'files_total' ) ),
				number_format_i18n( $n( 'files_downloaded' ) ),
				number_format_i18n( $n( 'files_extracted' ) ),
				number_format_i18n( $n( 'files_existing' ) ),
				number_format_i18n( $n( 'files_failed' ) )
			);
		}

		if ( $n( 'terms_created' ) || $n( 'terms_reused' ) ) {
			/* translators: 1: created, 2: reused */
			$lines[] = sprintf( __( 'Terms: %1$s created, %2$s reused', 'puppypress-migrator' ), number_format_i18n( $n( 'terms_created' ) ), number_format_i18n( $n( 'terms_reused' ) ) );
		}

		if ( $n( 'posts_created' ) ) {
			/* translators: %s: number of posts */
			$lines[] = sprintf( __( 'Posts and media library entries created: %s', 'puppypress-migrator' ), number_format_i18n( $n( 'posts_created' ) ) );
		}

		if ( $n( 'comments_created' ) ) {
			/* translators: %s: number of comments */
			$lines[] = sprintf( __( 'Comments: %s', 'puppypress-migrator' ), number_format_i18n( $n( 'comments_created' ) ) );
		}

		return $lines;
	}

	/**
	 * Human readable stage name.
	 *
	 * @param string $stage Stage method.
	 * @return string
	 */
	public static function stage_label( $stage ) {
		$labels = array(
			'collect'          => __( 'Collecting data', 'puppypress-migrator' ),
			'media'            => __( 'Packing media files', 'puppypress-migrator' ),
			'prepare'          => __( 'Checking package', 'puppypress-migrator' ),
			'files'            => __( 'Transferring media files', 'puppypress-migrator' ),
			'terms'            => __( 'Creating kennels, breeders and other terms', 'puppypress-migrator' ),
			'term_meta'        => __( 'Adding term details', 'puppypress-migrator' ),
			'posts'            => __( 'Creating posts', 'puppypress-migrator' ),
			'post_meta'        => __( 'Adding fields, pedigrees and assignments', 'puppypress-migrator' ),
			'comments'         => __( 'Importing comments', 'puppypress-migrator' ),
			'widgets_settings' => __( 'Importing widgets and settings', 'puppypress-migrator' ),
			'finish'           => __( 'Finishing', 'puppypress-migrator' ),
		);

		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : $stage;
	}
}

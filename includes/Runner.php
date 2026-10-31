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

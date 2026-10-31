<?php
/**
 * Import job.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Imports a package in resumable stages and remaps every ID.
 *
 * Every imported post, term and comment is marked with the meta key
 * `_ppm_source` ("{source home}|{old ID}"). Running the same package again
 * therefore skips records that already exist instead of duplicating them.
 */
final class Importer {

	const SOURCE_META = '_ppm_source';

	const ZIP_NAME  = 'import.zip';
	const DATA_FILE = 'data.json';
	const MAPS_FILE = 'maps.json';

	/**
	 * Items per step and stage.
	 */
	const BATCH = array(
		'terms'     => 200,
		'term_meta' => 300,
		'posts'     => 100,
		'post_meta' => 100,
		'comments'  => 200,
		'finish'    => 200,
	);

	/**
	 * Package data (lazy loaded).
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * ID maps and bookkeeping (lazy loaded).
	 *
	 * @var array|null
	 */
	private $maps = null;

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'create_users' => false, // Create missing authors (otherwise: fallback author).
			'author'       => 0,     // Fallback author; 0 = user who started the import.
			'replace_urls' => true,  // Replace old site and upload URLs.
			'widgets'      => true,
			'settings'     => true,  // Front page, posts page, sticky posts, menu locations.
			'media_source' => '',    // Uploads URL of the old site; empty = URL stored in the package.
		);
	}

	/**
	 * Stage methods in order.
	 *
	 * @return string[]
	 */
	public function stages() {
		return array( 'prepare', 'files', 'terms', 'term_meta', 'posts', 'post_meta', 'comments', 'widgets_settings', 'finish' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Stages
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Validates the package, extracts data.json and builds the initial maps.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function prepare( Job $job ) {
		if ( ! Package::supported() ) {
			throw new \RuntimeException( __( 'The PHP extension "zip" (ZipArchive) is required.', 'puppypress-migrator' ) );
		}

		$zip = $this->open_zip( $job );
		$raw = $zip->getFromName( Package::DATA_ENTRY );

		if ( false === $raw ) {
			throw new \RuntimeException( __( 'This is not a PuppyPress migration package (data.json is missing).', 'puppypress-migrator' ) );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || Package::FORMAT !== ( isset( $data['format'] ) ? $data['format'] : '' ) ) {
			throw new \RuntimeException( __( 'This is not a PuppyPress migration package.', 'puppypress-migrator' ) );
		}

		if ( (int) $data['version'] > Package::VERSION ) {
			throw new \RuntimeException( __( 'The package was created by a newer version of PuppyPress Migrator. Please update the plugin.', 'puppypress-migrator' ) );
		}

		file_put_contents( $job->path( self::DATA_FILE ), $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->data = $data;

		$source = untrailingslashit( $data['source']['home'] );

		if ( untrailingslashit( home_url() ) === $source ) {
			$job->log( __( 'The package was exported from this site. Existing records are skipped.', 'puppypress-migrator' ), 'warning' );
		}

		/* translators: 1: site name, 2: URL, 3: date */
		$job->log( sprintf( __( 'Package from "%1$s" (%2$s), exported %3$s.', 'puppypress-migrator' ), $data['source']['name'], $source, $data['source']['exported_at'] ) );

		if ( ! class_exists( 'PuppyPress\\Plugin' ) ) {
			$job->log( __( 'PuppyPress is not active. Club post types and taxonomies are missing; activate PuppyPress before importing.', 'puppypress-migrator' ), 'warning' );
		}

		$missing_types = array_diff( array_unique( wp_list_pluck( $data['posts'], 'post_type' ) ), get_post_types() );
		if ( $missing_types ) {
			/* translators: %s: list of post types */
			$job->log( sprintf( __( 'These post types are not registered on this site and will still be imported: %s', 'puppypress-migrator' ), implode( ', ', $missing_types ) ), 'warning' );
		}

		$missing_taxonomies = array_diff( array_unique( wp_list_pluck( $data['terms'], 'taxonomy' ) ), get_taxonomies() );
		if ( $missing_taxonomies ) {
			/* translators: %s: list of taxonomies */
			$job->log( sprintf( __( 'These taxonomies are not registered on this site and will be skipped: %s', 'puppypress-migrator' ), implode( ', ', $missing_taxonomies ) ), 'warning' );
		}

		$this->maps = array(
			'source'        => $source,
			'posts'         => $this->existing_sources( 'post', $source ),
			'terms'         => $this->existing_sources( 'term', $source ),
			'comments'      => $this->existing_sources( 'comment', $source ),
			'users'         => $this->map_users( $data['users'], $job ),
			'files'         => array(),
			'new_posts'     => array(),
			'new_terms'     => array(),
			'new_comments'  => array(),
			'term_order'    => wp_list_pluck( Remapper::order_terms( $data['terms'] ), 'term_id' ),
			'skipped_taxes' => array_values( $missing_taxonomies ),
		);

		if ( $this->maps['posts'] ) {
			/* translators: %d: number of posts */
			$job->log( sprintf( __( '%d posts from this package already exist and will be skipped.', 'puppypress-migrator' ), count( $this->maps['posts'] ) ) );
		}

		$options = wp_parse_args( $job->state['options'], self::default_options() );
		$source_uploads = '' !== trim( (string) $options['media_source'] ) ? esc_url_raw( trim( $options['media_source'] ) ) : $data['source']['uploads_baseurl'];

		$this->maps['media_source'] = untrailingslashit( $source_uploads );
		$this->plan_files( $zip, $job );
		$zip->close();

		$job->state['stats']['total'] = array(
			'files'    => count( $this->maps['files'] ),
			'terms'    => count( $data['terms'] ),
			'posts'    => count( $data['posts'] ),
			'comments' => count( $data['comments'] ),
		);

		$this->save_maps( $job );

		return true;
	}

	/**
	 * Transfers media files: extracts them from the package or downloads them
	 * from the old site. Works until the request's time budget is used up.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function files( Job $job ) {
		$this->load( $job );

		$plan    = array_keys( $this->maps['files'] );
		$total   = count( $plan );
		$basedir = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$zip     = null;
		$queue   = array();

		$job->state['stats']['files_total'] = $total;
		$processed = 0;

		// Always make progress, even if the time budget is already used up.
		while ( $job->state['cursor'] < $total && ( $processed < Downloader::CONCURRENCY * 4 || $job->has_time( 5.0 ) ) ) {
			$old  = $plan[ $job->state['cursor'] ];
			$file = $this->maps['files'][ $old ];
			$job->state['cursor']++;
			$processed++;

			if ( 'skip' === $file['action'] ) {
				$job->count( 'files_existing' );
				continue;
			}

			$target = $basedir . $file['path'];

			if ( 'download' === $file['action'] ) {
				$queue[] = array(
					'url'    => Downloader::url( $this->maps['media_source'], $old ),
					'target' => $target,
					'size'   => isset( $file['size'] ) ? $file['size'] : null,
					'old'    => $old,
				);

				if ( count( $queue ) >= Downloader::CONCURRENCY * 4 ) {
					$this->download( $queue, $job );
					$queue = array();
				}
				continue;
			}

			$zip = $zip ? $zip : $this->open_zip( $job );
			$this->extract( $zip, $old, $target, $job );
		}

		if ( $queue ) {
			$this->download( $queue, $job );
		}

		if ( $zip ) {
			$zip->close();
		}

		$job->state['stats']['files_done'] = $job->state['cursor'];

		if ( $job->state['cursor'] < $total ) {
			return false;
		}

		$stats = $job->state['stats'];

		if ( ! empty( $stats['files_failed'] ) ) {
			/* translators: 1: number of files, 2: URL */
			$job->log( sprintf( __( '%1$d media files could not be transferred. Check that the old site is reachable at %2$s and start the import again; existing files are skipped.', 'puppypress-migrator' ), $stats['files_failed'], $this->maps['media_source'] ), 'warning' );
		}

		/* translators: 1: downloaded, 2: extracted, 3: already present */
		$job->log( sprintf( __( 'Media files: %1$d downloaded, %2$d extracted from the package, %3$d already present.', 'puppypress-migrator' ), isset( $stats['files_downloaded'] ) ? $stats['files_downloaded'] : 0, isset( $stats['files_extracted'] ) ? $stats['files_extracted'] : 0, isset( $stats['files_existing'] ) ? $stats['files_existing'] : 0 ) );

		return true;
	}

	/**
	 * Extracts one file from the package.
	 *
	 * @param ZipArchive $zip    Package.
	 * @param string     $old    Path in the package.
	 * @param string     $target Absolute target path.
	 * @param Job        $job    Job.
	 */
	private function extract( ZipArchive $zip, $old, $target, Job $job ) {
		wp_mkdir_p( dirname( $target ) );

		$stream = $zip->getStream( Package::UPLOADS_ENTRY . $old );
		$out    = $stream ? fopen( $target, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $stream || ! $out ) {
			$this->file_failed( $job, $old, __( 'not readable in the package', 'puppypress-migrator' ) );
			return;
		}

		stream_copy_to_stream( $stream, $out );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$job->count( 'files_extracted' );
	}

	/**
	 * Downloads a queue of files in parallel.
	 *
	 * @param array $queue Items with url, target, size, old.
	 * @param Job   $job   Job.
	 */
	private function download( array $queue, Job $job ) {
		foreach ( Downloader::fetch( $queue ) as $index => $result ) {
			if ( true === $result ) {
				$job->count( 'files_downloaded' );
			} else {
				$this->file_failed( $job, $queue[ $index ]['old'], (string) $result );
			}
		}
	}

	/**
	 * Records a file that could not be transferred.
	 *
	 * @param Job    $job    Job.
	 * @param string $old    Path.
	 * @param string $reason Reason.
	 */
	private function file_failed( Job $job, $old, $reason ) {
		$job->count( 'files_failed' );

		if ( $job->state['stats']['files_failed'] <= 50 ) {
			/* translators: 1: file path, 2: reason */
			$job->log( sprintf( __( 'Media file not transferred (%2$s): %1$s', 'puppypress-migrator' ), $old, $reason ), 'warning' );
		} elseif ( 51 === $job->state['stats']['files_failed'] ) {
			$job->log( __( 'Further failed files are only counted.', 'puppypress-migrator' ), 'warning' );
		}
	}

	/**
	 * Creates terms, parents first. Existing terms with the same slug are reused.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function terms( Job $job ) {
		$this->load( $job );

		$terms = $this->index( $this->data['terms'], 'term_id' );
		$batch = array_slice( $this->maps['term_order'], $job->state['cursor'], self::BATCH['terms'] );

		foreach ( $batch as $old_id ) {
			$term = $terms[ $old_id ];

			if ( isset( $this->maps['terms'][ $old_id ] ) ) {
				continue;
			}

			if ( ! taxonomy_exists( $term['taxonomy'] ) ) {
				$job->count( 'terms_skipped' );
				continue;
			}

			$parent   = $term['parent'] && isset( $this->maps['terms'][ $term['parent'] ] ) ? (int) $this->maps['terms'][ $term['parent'] ] : 0;
			$existing = get_term_by( 'slug', $term['slug'], $term['taxonomy'] );

			if ( $existing ) {
				$this->maps['terms'][ $old_id ] = (int) $existing->term_id;
				$job->count( 'terms_reused' );
				continue;
			}

			$result = wp_insert_term(
				wp_slash( $term['name'] ),
				$term['taxonomy'],
				array(
					'slug'        => $term['slug'],
					'description' => wp_slash( $term['description'] ),
					'parent'      => $parent,
				)
			);

			if ( is_wp_error( $result ) ) {
				$existing_id = $result->get_error_data( 'term_exists' );

				if ( $existing_id ) {
					$this->maps['terms'][ $old_id ] = (int) $existing_id;
					$job->count( 'terms_reused' );
				} else {
					/* translators: 1: term name, 2: error message */
					$job->log( sprintf( __( 'Term "%1$s" could not be created: %2$s', 'puppypress-migrator' ), $term['name'], $result->get_error_message() ), 'warning' );
				}
				continue;
			}

			$this->maps['terms'][ $old_id ] = (int) $result['term_id'];
			$this->maps['new_terms'][]      = (int) $old_id;
			add_term_meta( $result['term_id'], self::SOURCE_META, $this->maps['source'] . '|' . $old_id );
			$job->count( 'terms_created' );
		}

		$job->state['cursor'] += count( $batch );
		$this->save_maps( $job );

		return $job->state['cursor'] >= count( $this->maps['term_order'] );
	}

	/**
	 * Adds term meta. For reused terms only keys that do not exist yet are added.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function term_meta( Job $job ) {
		$this->load( $job );

		$remapper = $this->remapper( $job );
		$new      = array_flip( $this->maps['new_terms'] );
		$batch    = array_slice( $this->data['terms'], $job->state['cursor'], self::BATCH['term_meta'] );
		$id_keys  = Config::term_id_term_meta_keys();

		foreach ( $batch as $term ) {
			$term_id = $remapper->term( $term['term_id'] );

			if ( ! $term_id || empty( $term['meta'] ) ) {
				continue;
			}

			foreach ( $term['meta'] as $key => $values ) {
				if ( ! isset( $new[ $term['term_id'] ] ) && metadata_exists( 'term', $term_id, $key ) ) {
					continue;
				}

				foreach ( $values as $value ) {
					if ( in_array( $key, $id_keys, true ) ) {
						$value = $remapper->term( $value );
						$value = $value ? (string) $value : '';
					} else {
						$value = $remapper->urls( Remapper::unserialize( $value ) );
					}

					add_term_meta( $term_id, $key, wp_slash( $value ) );
				}
			}
		}

		$job->state['cursor'] += count( $batch );

		return $job->state['cursor'] >= count( $this->data['terms'] );
	}

	/**
	 * Creates posts and attachments (without meta, which needs the full ID map).
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function posts( Job $job ) {
		$this->load( $job );
		$this->suspend_filters();

		$remapper = $this->remapper( $job );
		$batch    = array_slice( $this->data['posts'], $job->state['cursor'], self::BATCH['posts'] );
		$baseurl  = trailingslashit( wp_upload_dir( null, false )['baseurl'] );
		$fallback = $this->fallback_author( $job );

		foreach ( $batch as $post ) {
			$old_id = (int) $post['ID'];

			if ( isset( $this->maps['posts'][ $old_id ] ) ) {
				continue;
			}

			$author = isset( $this->maps['users'][ $post['post_author'] ] ) ? (int) $this->maps['users'][ $post['post_author'] ] : $fallback;

			$postarr = array(
				'post_author'    => $author,
				'post_date'      => $post['post_date'],
				'post_date_gmt'  => $post['post_date_gmt'],
				'post_content'   => $remapper->urls( $post['post_content'] ),
				'post_title'     => $post['post_title'],
				'post_excerpt'   => $remapper->urls( $post['post_excerpt'] ),
				'post_status'    => $post['post_status'],
				'comment_status' => $post['comment_status'],
				'ping_status'    => $post['ping_status'],
				'post_password'  => $post['post_password'],
				'post_name'      => $post['post_name'],
				'menu_order'     => $post['menu_order'],
				'post_type'      => $post['post_type'],
				'post_mime_type' => $post['post_mime_type'],
			);

			if ( 'attachment' === $post['post_type'] ) {
				$file = isset( $post['meta']['_wp_attached_file'][0] ) ? $this->file_path( $post['meta']['_wp_attached_file'][0] ) : '';

				if ( '' === $file ) {
					$job->count( 'attachments_without_file' );
				}

				$postarr['guid'] = $file ? $baseurl . $file : '';
			}

			$new_id = wp_insert_post( wp_slash( $postarr ), true, false );

			if ( is_wp_error( $new_id ) ) {
				/* translators: 1: post title, 2: error message */
				$job->log( sprintf( __( '"%1$s" could not be imported: %2$s', 'puppypress-migrator' ), $post['post_title'], $new_id->get_error_message() ), 'warning' );
				continue;
			}

			add_post_meta( $new_id, self::SOURCE_META, $this->maps['source'] . '|' . $old_id );

			$this->maps['posts'][ $old_id ] = (int) $new_id;
			$this->maps['new_posts'][]      = $old_id;
			$job->count( 'posts_created' );
		}

		$job->state['cursor'] += count( $batch );
		$this->save_maps( $job );

		return $job->state['cursor'] >= count( $this->data['posts'] );
	}

	/**
	 * Adds post meta and terms, fixes parents and modification dates.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function post_meta( Job $job ) {
		global $wpdb;

		$this->load( $job );
		$this->suspend_filters();

		$remapper = $this->remapper( $job );
		$new      = array_flip( $this->maps['new_posts'] );
		$batch    = array_slice( $this->data['posts'], $job->state['cursor'], self::BATCH['post_meta'] );
		$id_keys  = Config::post_id_meta_keys();
		$list_keys = Config::post_id_list_meta_keys();

		foreach ( $batch as $post ) {
			$old_id = (int) $post['ID'];

			if ( ! isset( $new[ $old_id ] ) ) {
				// Imported by an earlier run: only add term assignments that are
				// still missing, e.g. because a taxonomy was not registered then.
				if ( $remapper->post( $old_id ) ) {
					$this->assign_terms( $remapper->post( $old_id ), $post['terms'], $remapper, $job, true );
				}
				continue;
			}

			$new_id = $remapper->post( $old_id );
			$meta   = $post['meta'];

			foreach ( $meta as $key => $values ) {
				foreach ( $values as $value ) {
					if ( in_array( $key, $id_keys, true ) ) {
						$mapped = $remapper->post( $value );

						if ( ! $mapped ) {
							if ( '' !== (string) $value && '0' !== (string) $value ) {
								$job->count( 'unresolved_references' );
							}

							// A missing featured image is simply not set.
							if ( '_thumbnail_id' === $key ) {
								continue;
							}
						}

						if ( $mapped ) {
							$value = (string) $mapped;
						} else {
							$value = '_menu_item_menu_item_parent' === $key ? '0' : '';
						}
					} elseif ( in_array( $key, $list_keys, true ) ) {
						$value = $remapper->post_list( $value );
					} elseif ( '_menu_item_object_id' === $key ) {
						$type  = isset( $meta['_menu_item_type'][0] ) ? $meta['_menu_item_type'][0] : '';
						$value = 'post_type' === $type ? (string) $remapper->post( $value ) : ( 'taxonomy' === $type ? (string) $remapper->term( $value ) : $value );
					} elseif ( '_wp_attached_file' === $key ) {
						$value = $this->file_path( $value );
					} elseif ( '_wp_attachment_metadata' === $key ) {
						$value = Remapper::unserialize( $value );
						if ( is_array( $value ) && isset( $value['file'] ) ) {
							$value['file'] = $this->file_path( $value['file'] );
						}
					} else {
						$value = $remapper->urls( Remapper::unserialize( $value ) );
					}

					add_post_meta( $new_id, $key, wp_slash( $value ) );
				}
			}

			$this->assign_terms( $new_id, $post['terms'], $remapper, $job, false );

			$update = array(
				'post_modified'     => $post['post_modified'],
				'post_modified_gmt' => $post['post_modified_gmt'],
			);

			if ( $post['post_parent'] ) {
				$update['post_parent'] = $remapper->post( $post['post_parent'] );
			}

			$wpdb->update( $wpdb->posts, $update, array( 'ID' => $new_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $new_id );
		}

		$job->state['cursor'] += count( $batch );

		if ( $job->state['cursor'] < count( $this->data['posts'] ) ) {
			return false;
		}

		$this->log_skipped_assignments( $job );

		return true;
	}

	/**
	 * Assigns the terms of a post.
	 *
	 * @param int      $post_id  New post ID.
	 * @param array    $terms    Taxonomy => old term IDs.
	 * @param Remapper $remapper Remapper.
	 * @param Job      $job      Job.
	 * @param bool     $append   Keep existing assignments (repeated runs).
	 */
	private function assign_terms( $post_id, array $terms, Remapper $remapper, Job $job, $append ) {
		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				if ( ! $append ) {
					$job->state['stats']['skipped_assignments'][ $taxonomy ] = ( isset( $job->state['stats']['skipped_assignments'][ $taxonomy ] ) ? $job->state['stats']['skipped_assignments'][ $taxonomy ] : 0 ) + 1;
				}
				continue;
			}

			$mapped = array_values( array_filter( array_map( array( $remapper, 'term' ), $term_ids ) ) );

			if ( $mapped ) {
				wp_set_object_terms( $post_id, $mapped, $taxonomy, $append );
			}
		}
	}

	/**
	 * Logs how many posts lost assignments to unregistered taxonomies.
	 *
	 * @param Job $job Job.
	 */
	private function log_skipped_assignments( Job $job ) {
		if ( empty( $job->state['stats']['skipped_assignments'] ) ) {
			return;
		}

		foreach ( $job->state['stats']['skipped_assignments'] as $taxonomy => $count ) {
			/* translators: 1: number of posts, 2: taxonomy */
			$job->log( sprintf( __( '%1$d posts lost their assignment to "%2$s" because this taxonomy is not registered. Activate the theme or plugin that provides it and start the import again to add the assignments.', 'puppypress-migrator' ), $count, $taxonomy ), 'warning' );
		}
	}

	/**
	 * Imports comments of newly created posts.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function comments( Job $job ) {
		$this->load( $job );

		$remapper = $this->remapper( $job );
		$new      = array_flip( $this->maps['new_posts'] );
		$batch    = array_slice( $this->data['comments'], $job->state['cursor'], self::BATCH['comments'] );

		foreach ( $batch as $comment ) {
			$old_id = (int) $comment['comment_ID'];

			if ( isset( $this->maps['comments'][ $old_id ] ) || ! isset( $new[ (int) $comment['comment_post_ID'] ] ) ) {
				continue;
			}

			$parent = (int) $comment['comment_parent'];

			$new_id = wp_insert_comment(
				wp_slash(
					array(
						'comment_post_ID'      => $remapper->post( $comment['comment_post_ID'] ),
						'comment_author'       => $comment['comment_author'],
						'comment_author_email' => $comment['comment_author_email'],
						'comment_author_url'   => $comment['comment_author_url'],
						'comment_author_IP'    => $comment['comment_author_IP'],
						'comment_date'         => $comment['comment_date'],
						'comment_date_gmt'     => $comment['comment_date_gmt'],
						'comment_content'      => $remapper->urls( $comment['comment_content'] ),
						'comment_karma'        => (int) $comment['comment_karma'],
						'comment_approved'     => $comment['comment_approved'],
						'comment_agent'        => $comment['comment_agent'],
						'comment_type'         => $comment['comment_type'],
						'comment_parent'       => $parent && isset( $this->maps['comments'][ $parent ] ) ? (int) $this->maps['comments'][ $parent ] : 0,
						'user_id'              => isset( $this->maps['users'][ $comment['user_id'] ] ) ? (int) $this->maps['users'][ $comment['user_id'] ] : 0,
					)
				)
			);

			if ( ! $new_id ) {
				$job->count( 'comments_failed' );
				continue;
			}

			foreach ( $comment['meta'] as $key => $values ) {
				foreach ( $values as $value ) {
					add_comment_meta( $new_id, $key, wp_slash( Remapper::unserialize( $value ) ) );
				}
			}

			add_comment_meta( $new_id, self::SOURCE_META, $this->maps['source'] . '|' . $old_id );
			$this->maps['comments'][ $old_id ] = (int) $new_id;
			$job->count( 'comments_created' );
		}

		$job->state['cursor'] += count( $batch );
		$this->save_maps( $job );

		return $job->state['cursor'] >= count( $this->data['comments'] );
	}

	/**
	 * Widgets, sticky posts, front page and menu locations.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function widgets_settings( Job $job ) {
		$this->load( $job );

		$options  = wp_parse_args( $job->state['options'], self::default_options() );
		$remapper = $this->remapper( $job );
		$settings = isset( $this->data['settings'] ) ? $this->data['settings'] : array();
		$marker   = 'ppm_widgets_' . md5( $this->maps['source'] );

		if ( $options['widgets'] && ! empty( $this->data['widgets']['instances'] ) ) {
			if ( get_option( $marker ) ) {
				$job->log( __( 'Widgets from this source were imported before and are skipped.', 'puppypress-migrator' ) );
			} else {
				$this->import_widgets( $this->data['widgets'], $remapper, $job );
				update_option( $marker, time(), false );
			}
		}

		if ( $options['settings'] ) {
			if ( ! empty( $settings['sticky_posts'] ) ) {
				$sticky = array_filter( array_map( array( $remapper, 'post' ), $settings['sticky_posts'] ) );
				update_option( 'sticky_posts', array_values( array_unique( array_merge( (array) get_option( 'sticky_posts', array() ), $sticky ) ) ) );
			}

			if ( ! empty( $settings['page_on_front'] ) && $remapper->post( $settings['page_on_front'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $remapper->post( $settings['page_on_front'] ) );
				$job->log( __( 'Front page set.', 'puppypress-migrator' ) );
			}

			if ( ! empty( $settings['page_for_posts'] ) && $remapper->post( $settings['page_for_posts'] ) ) {
				update_option( 'page_for_posts', $remapper->post( $settings['page_for_posts'] ) );
			}

			if ( ! empty( $settings['nav_menu_locations'] ) ) {
				$locations = (array) get_theme_mod( 'nav_menu_locations', array() );

				foreach ( $settings['nav_menu_locations'] as $location => $menu_id ) {
					$new = $remapper->term( $menu_id );
					if ( $new ) {
						$locations[ $location ] = $new;
					}
				}

				set_theme_mod( 'nav_menu_locations', $locations );
				$job->log( __( 'Menu locations assigned.', 'puppypress-migrator' ) );
			}
		}

		return true;
	}

	/**
	 * Rewrites attachment IDs in content, recounts terms and cleans up.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function finish( Job $job ) {
		global $wpdb;

		$this->load( $job );

		$remapper = $this->remapper( $job );
		$new_ids  = $this->maps['new_posts'];
		$batch    = array_slice( $new_ids, $job->state['cursor'], self::BATCH['finish'] );

		foreach ( $batch as $old_id ) {
			$new_id = $remapper->post( $old_id );
			$post   = $new_id ? get_post( $new_id ) : null;

			if ( ! $post || '' === $post->post_content ) {
				continue;
			}

			$content = $remapper->content_ids( $post->post_content );

			if ( $content !== $post->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $new_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $new_id );
			}
		}

		$job->state['cursor'] += count( $batch );

		if ( $job->state['cursor'] < count( $new_ids ) ) {
			return false;
		}

		// Recount all terms of the affected taxonomies.
		$taxonomies = array_unique( wp_list_pluck( $this->data['terms'], 'taxonomy' ) );
		foreach ( $taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$tt_ids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'tt_ids',
					)
				);
				if ( $tt_ids && ! is_wp_error( $tt_ids ) ) {
					wp_update_term_count_now( $tt_ids, $taxonomy );
				}
			}
		}

		delete_option( 'rewrite_rules' );

		// The package is no longer needed; keep only state and maps.
		@unlink( $job->path( self::ZIP_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $job->path( self::DATA_FILE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		$stats = $job->state['stats'];
		$job->log(
			sprintf(
				/* translators: 1: posts, 2: terms created, 3: terms reused, 4: comments, 5: files */
				__( 'Import finished: %1$d posts, %2$d new terms (%3$d reused), %4$d comments, %5$d files.', 'puppypress-migrator' ),
				isset( $stats['posts_created'] ) ? $stats['posts_created'] : 0,
				isset( $stats['terms_created'] ) ? $stats['terms_created'] : 0,
				isset( $stats['terms_reused'] ) ? $stats['terms_reused'] : 0,
				isset( $stats['comments_created'] ) ? $stats['comments_created'] : 0,
				isset( $stats['files_extracted'] ) ? $stats['files_extracted'] : 0
			)
		);

		if ( ! empty( $stats['unresolved_references'] ) ) {
			/* translators: %d: number of references */
			$job->log( sprintf( __( '%d references pointed to content that is not part of the package and were removed.', 'puppypress-migrator' ), $stats['unresolved_references'] ), 'warning' );
		}

		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Opens the package.
	 *
	 * @param Job $job Job.
	 * @return ZipArchive
	 */
	private function open_zip( Job $job ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $job->path( self::ZIP_NAME ) ) ) {
			throw new \RuntimeException( __( 'The ZIP file could not be opened.', 'puppypress-migrator' ) );
		}

		return $zip;
	}

	/**
	 * Loads data and maps of the job.
	 *
	 * @param Job $job Job.
	 */
	private function load( Job $job ) {
		if ( null === $this->data ) {
			$this->data = json_decode( (string) file_get_contents( $job->path( self::DATA_FILE ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( null === $this->maps ) {
			$this->maps = json_decode( (string) file_get_contents( $job->path( self::MAPS_FILE ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( ! is_array( $this->data ) || ! is_array( $this->maps ) ) {
			throw new \RuntimeException( __( 'The job data is incomplete. Please start the import again.', 'puppypress-migrator' ) );
		}
	}

	/**
	 * Persists the maps.
	 *
	 * @param Job $job Job.
	 */
	private function save_maps( Job $job ) {
		file_put_contents( $job->path( self::MAPS_FILE ), wp_json_encode( $this->maps ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remapper with the current maps and URL replacements.
	 *
	 * @param Job $job Job.
	 * @return Remapper
	 */
	private function remapper( Job $job ) {
		$options = wp_parse_args( $job->state['options'], self::default_options() );
		$urls    = array();

		if ( $options['replace_urls'] ) {
			$source_uploads = untrailingslashit( $this->data['source']['uploads_baseurl'] );
			$target_uploads = untrailingslashit( wp_upload_dir( null, false )['baseurl'] );

			// Files that had to be moved because of name conflicts.
			foreach ( $this->maps['files'] as $old => $file ) {
				if ( $file['path'] !== $old ) {
					$urls[ $source_uploads . '/' . $old ] = $target_uploads . '/' . $file['path'];
				}
			}

			$urls[ $source_uploads ]                       = $target_uploads;
			$urls[ untrailingslashit( $this->data['source']['home'] ) ]    = untrailingslashit( home_url() );
			$urls[ untrailingslashit( $this->data['source']['siteurl'] ) ] = untrailingslashit( site_url() );
		}

		return new Remapper( $this->maps['posts'], $this->maps['terms'], $urls );
	}

	/**
	 * Indexes a list by a key.
	 *
	 * @param array  $list List.
	 * @param string $key  Key.
	 * @return array
	 */
	private function index( array $list, $key ) {
		$indexed = array();

		foreach ( $list as $item ) {
			$indexed[ (int) $item[ $key ] ] = $item;
		}

		return $indexed;
	}

	/**
	 * Records imported earlier from the same source.
	 *
	 * @param string $type   post|term|comment.
	 * @param string $source Source home URL.
	 * @return array<int, int> Old ID => new ID.
	 */
	private function existing_sources( $type, $source ) {
		global $wpdb;

		$tables = array(
			'post'    => array( $wpdb->postmeta, 'post_id' ),
			'term'    => array( $wpdb->termmeta, 'term_id' ),
			'comment' => array( $wpdb->commentmeta, 'comment_id' ),
		);

		list( $table, $column ) = $tables[ $type ];

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT {$column} AS new_id, meta_value FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::SOURCE_META,
				$wpdb->esc_like( $source . '|' ) . '%'
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$old         = (int) substr( $row['meta_value'], strlen( $source ) + 1 );
			$map[ $old ] = (int) $row['new_id'];
		}

		return $map;
	}

	/**
	 * Maps source users to users of this site (by login, then email).
	 *
	 * @param array $users Exported users.
	 * @param Job   $job   Job.
	 * @return array<int, int>
	 */
	private function map_users( array $users, Job $job ) {
		$options = wp_parse_args( $job->state['options'], self::default_options() );
		$map     = array();

		foreach ( $users as $user ) {
			$existing = get_user_by( 'login', $user['user_login'] );

			if ( ! $existing && is_email( $user['user_email'] ) ) {
				$existing = get_user_by( 'email', $user['user_email'] );
			}

			if ( $existing ) {
				$map[ (int) $user['ID'] ] = (int) $existing->ID;
				continue;
			}

			if ( ! $options['create_users'] ) {
				continue;
			}

			$role   = ! empty( $user['roles'] ) && get_role( $user['roles'][0] ) && 'administrator' !== $user['roles'][0] ? $user['roles'][0] : 'author';
			$new_id = wp_insert_user(
				array(
					'user_login'   => $user['user_login'],
					'user_email'   => $user['user_email'],
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => $user['display_name'],
					'first_name'   => $user['first_name'],
					'last_name'    => $user['last_name'],
					'user_url'     => $user['user_url'],
					'role'         => $role,
				)
			);

			if ( is_wp_error( $new_id ) ) {
				/* translators: 1: user login, 2: error message */
				$job->log( sprintf( __( 'User "%1$s" could not be created: %2$s', 'puppypress-migrator' ), $user['user_login'], $new_id->get_error_message() ), 'warning' );
				continue;
			}

			$map[ (int) $user['ID'] ] = (int) $new_id;
			/* translators: 1: user login, 2: role */
			$job->log( sprintf( __( 'User "%1$s" created (role: %2$s, random password).', 'puppypress-migrator' ), $user['user_login'], $role ) );
		}

		return $map;
	}

	/**
	 * Author for posts whose author could not be mapped.
	 *
	 * @param Job $job Job.
	 * @return int
	 */
	private function fallback_author( Job $job ) {
		$options = wp_parse_args( $job->state['options'], self::default_options() );

		if ( $options['author'] && get_userdata( (int) $options['author'] ) ) {
			return (int) $options['author'];
		}

		if ( ! empty( $job->state['user'] ) ) {
			return (int) $job->state['user'];
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : 0;
	}

	/**
	 * Decides for every media file how it gets to this site.
	 *
	 * Actions: "skip" (identical file exists), "extract" (from the package) or
	 * "download" (from the old site). If any file of an attachment collides
	 * with a different existing file, all files of that attachment move into
	 * a separate folder together, so its metadata stays valid.
	 *
	 * @param ZipArchive $zip Package.
	 * @param Job        $job Job.
	 */
	private function plan_files( ZipArchive $zip, Job $job ) {
		$basedir     = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$prefix      = 'ppm-import-' . gmdate( 'Ymd-His' ) . '/';
		$rejected    = 0;
		$unavailable = 0;
		$downloads   = 0;

		foreach ( $this->data['posts'] as $post ) {
			if ( empty( $post['files'] ) ) {
				continue;
			}

			$sizes    = isset( $post['file_sizes'] ) && is_array( $post['file_sizes'] ) ? $post['file_sizes'] : array();
			$files    = array();
			$conflict = false;

			// Attachment imported by an earlier run: keep its folder, only fill gaps.
			$existing_prefix = null;
			if ( isset( $this->maps['posts'][ (int) $post['ID'] ] ) && ! empty( $post['meta']['_wp_attached_file'][0] ) ) {
				$main     = ltrim( $post['meta']['_wp_attached_file'][0], '/' );
				$attached = (string) get_post_meta( $this->maps['posts'][ (int) $post['ID'] ], '_wp_attached_file', true );

				if ( '' !== $attached && substr( $attached, -strlen( $main ) ) === $main ) {
					$existing_prefix = substr( $attached, 0, -strlen( $main ) );
				}
			}

			foreach ( $post['files'] as $old ) {
				if ( ! Package::is_allowed_upload_path( $old ) ) {
					$rejected++;
					continue;
				}

				$stat = $zip->statName( Package::UPLOADS_ENTRY . $old );

				if ( $stat ) {
					$source = 'extract';
					$size   = (int) $stat['size'];
				} elseif ( isset( $sizes[ $old ] ) ) {
					$source = 'download';
					$size   = (int) $sizes[ $old ];
				} else {
					$unavailable++;
					continue;
				}

				$target = $basedir . ( null !== $existing_prefix ? $existing_prefix : '' ) . $old;
				$action = $source;

				if ( file_exists( $target ) ) {
					if ( filesize( $target ) === $size ) {
						$action = 'skip';
					} elseif ( null === $existing_prefix ) {
						$conflict = true;
					}
				}

				$files[ $old ] = array(
					'source' => $source,
					'action' => $action,
					'size'   => $size,
				);
			}

			foreach ( $files as $old => $file ) {
				$action = $conflict ? $file['source'] : $file['action'];

				if ( null !== $existing_prefix ) {
					$path = $existing_prefix . $old;
				} else {
					$path = $conflict ? $prefix . $old : $old;
				}

				$this->maps['files'][ $old ] = array(
					'path'   => $path,
					'action' => $action,
					'size'   => $file['size'],
				);

				$downloads += (int) ( 'download' === $action );
			}
		}

		if ( $rejected ) {
			/* translators: %d: number of files */
			$job->log( sprintf( __( '%d files in the package have a forbidden name or type and are ignored.', 'puppypress-migrator' ), $rejected ), 'warning' );
		}

		if ( $unavailable ) {
			/* translators: %d: number of files */
			$job->log( sprintf( __( '%d media files are neither in the package nor marked for download and are skipped.', 'puppypress-migrator' ), $unavailable ), 'warning' );
		}

		if ( $downloads ) {
			/* translators: 1: number of files, 2: URL */
			$job->log( sprintf( __( '%1$d media files will be downloaded from %2$s.', 'puppypress-migrator' ), $downloads, $this->maps['media_source'] ) );
		}
	}

	/**
	 * New relative path of an uploaded file.
	 *
	 * @param string $old Old relative path.
	 * @return string
	 */
	private function file_path( $old ) {
		$old = ltrim( (string) $old, '/' );

		return isset( $this->maps['files'][ $old ] ) ? $this->maps['files'][ $old ]['path'] : $old;
	}

	/**
	 * Adds widget instances and places them in the same sidebars.
	 *
	 * @param array    $widgets  Exported widgets.
	 * @param Remapper $remapper Remapper.
	 * @param Job      $job      Job.
	 */
	private function import_widgets( array $widgets, Remapper $remapper, Job $job ) {
		global $wp_registered_sidebars;

		$sidebars = get_option( 'sidebars_widgets', array() );
		$sidebars = is_array( $sidebars ) ? $sidebars : array();
		$count    = 0;

		foreach ( $widgets['sidebars'] as $sidebar => $widget_ids ) {
			$target = isset( $wp_registered_sidebars[ $sidebar ] ) ? $sidebar : 'wp_inactive_widgets';

			if ( 'wp_inactive_widgets' === $target ) {
				/* translators: %s: sidebar ID */
				$job->log( sprintf( __( 'Sidebar "%s" does not exist in the active theme; its widgets were added to the inactive widgets.', 'puppypress-migrator' ), $sidebar ), 'warning' );
			}

			foreach ( $widget_ids as $widget_id ) {
				if ( ! isset( $widgets['instances'][ $widget_id ] ) || ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
					continue;
				}

				$base     = $m[1];
				$instance = $remapper->urls( $widgets['instances'][ $widget_id ] );

				// Widgets that reference content by ID.
				if ( 'nav_menu' === $base && ! empty( $instance['nav_menu'] ) ) {
					$instance['nav_menu'] = $remapper->term( $instance['nav_menu'] );
				}
				if ( ! empty( $instance['attachment_id'] ) ) {
					$instance['attachment_id'] = $remapper->post( $instance['attachment_id'] );
				}
				if ( 'media_gallery' === $base && ! empty( $instance['ids'] ) && is_array( $instance['ids'] ) ) {
					$instance['ids'] = array_values( array_filter( array_map( array( $remapper, 'post' ), $instance['ids'] ) ) );
				}
				if ( isset( $instance['content'] ) && is_string( $instance['content'] ) ) {
					$instance['content'] = $remapper->content_ids( $instance['content'] );
				}

				$option   = get_option( 'widget_' . $base, array() );
				$option   = is_array( $option ) ? $option : array();
				$numbers  = array_filter( array_keys( $option ), 'is_int' );
				$number   = $numbers ? max( $numbers ) + 1 : 2;

				$option[ $number ]      = $instance;
				$option['_multiwidget'] = 1;
				update_option( 'widget_' . $base, $option );

				$sidebars[ $target ][] = $base . '-' . $number;
				$count++;
			}
		}

		update_option( 'sidebars_widgets', $sidebars );

		/* translators: %d: number of widgets */
		$job->log( sprintf( __( '%d widgets imported.', 'puppypress-migrator' ), $count ) );
	}

	/**
	 * Keeps content exactly as exported (no kses filtering, no term counting per post).
	 */
	private function suspend_filters() {
		kses_remove_filters();
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
	}
}

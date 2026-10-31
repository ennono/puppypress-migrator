<?php
/**
 * Export job.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Reads everything straight from the database, so it works with the old theme,
 * with PuppyPress or with neither of them active.
 *
 * Stages: collect (data.json) → media (files in batches) → finish.
 */
final class Exporter {

	const ZIP_NAME   = 'export.zip';
	const FILES_LIST = 'files.json';

	/**
	 * Media files added per step.
	 */
	const FILES_PER_STEP = 150;

	/**
	 * Above this number of files, packing into the ZIP is discouraged.
	 */
	const ZIP_FILES_WARNING = 3000;

	/**
	 * Individual log lines per problem type before only counting.
	 */
	const LOG_LIMIT = 50;

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'content'  => true,  // Posts and pages.
			'menus'    => true,
			'comments' => true,
			'widgets'  => true,
			'media'    => 'all',      // all|referenced|none.
			'transfer' => 'download', // download: the import fetches files from this site; zip: files are packed.
		);
	}

	/**
	 * Stage methods in order.
	 *
	 * @return string[]
	 */
	public function stages() {
		return array( 'collect', 'media', 'finish' );
	}

	/**
	 * Builds data.json and the list of media files.
	 *
	 * @param Job $job Job.
	 * @return bool Stage complete.
	 */
	public function collect( Job $job ) {
		if ( ! Package::supported() ) {
			throw new \RuntimeException( __( 'The PHP extension "zip" (ZipArchive) is required.', 'puppypress-migrator' ) );
		}

		$options = wp_parse_args( $job->state['options'], self::default_options() );
		$data    = $this->collect_data( $options, $job );
		$files   = array();
		$bytes   = 0;

		foreach ( $data['posts'] as $post ) {
			if ( ! empty( $post['file_sizes'] ) ) {
				$files  = array_merge( $files, array_keys( $post['file_sizes'] ) );
				$bytes += array_sum( $post['file_sizes'] );
			}
		}

		$files = array_values( array_unique( $files ) );

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			/* translators: %s: technical error message */
			throw new \RuntimeException( sprintf( __( 'The data could not be encoded: %s', 'puppypress-migrator' ), json_last_error_msg() ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $job->path( self::ZIP_NAME ), ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( __( 'The ZIP file could not be created.', 'puppypress-migrator' ) );
		}
		$zip->addFromString( Package::DATA_ENTRY, $json );
		$zip->close();

		$pack = 'zip' === $options['transfer'];

		// Only packed files go through the "media" stage.
		file_put_contents( $job->path( self::FILES_LIST ), wp_json_encode( $pack ? $files : array() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$counts = array();
		foreach ( $data['posts'] as $post ) {
			$counts[ $post['post_type'] ] = ( isset( $counts[ $post['post_type'] ] ) ? $counts[ $post['post_type'] ] : 0 ) + 1;
		}
		ksort( $counts );

		foreach ( $counts as $type => $count ) {
			/* translators: 1: number, 2: post type */
			$job->log( sprintf( __( '%1$d × %2$s', 'puppypress-migrator' ), $count, $type ) );
		}

		/* translators: 1: number of terms, 2: number of comments, 3: number of media files, 4: size */
		$job->log( sprintf( __( '%1$d terms, %2$d comments, %3$d media files (%4$s).', 'puppypress-migrator' ), count( $data['terms'] ), count( $data['comments'] ), count( $files ), size_format( $bytes, 1 ) ) );

		$this->log_file_problems( $job );

		if ( $files && ! $pack ) {
			$job->log( __( 'Media files are not packed. The import downloads them from this site, so it must stay online until the import is finished. Files that were already copied to the new site (e.g. via FTP or rsync) are only checked.', 'puppypress-migrator' ) );
		} elseif ( $pack && count( $files ) > self::ZIP_FILES_WARNING ) {
			/* translators: %d: number of files */
			$job->log( sprintf( __( 'Packing %d files into one ZIP takes long and needs a lot of disk space. If the export stops, choose "Download during import" instead.', 'puppypress-migrator' ), count( $files ) ), 'warning' );
		}

		$job->state['stats']['files_total'] = $pack ? count( $files ) : 0;
		$job->state['stats']['files_done']  = 0;

		return true;
	}

	/**
	 * Adds a batch of media files to the ZIP.
	 *
	 * @param Job $job Job.
	 * @return bool Stage complete.
	 */
	public function media( Job $job ) {
		$files = json_decode( (string) file_get_contents( $job->path( self::FILES_LIST ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$batch = array_slice( (array) $files, $job->state['cursor'], self::FILES_PER_STEP );

		if ( ! $batch ) {
			return true;
		}

		$basedir = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$zip     = new ZipArchive();

		if ( true !== $zip->open( $job->path( self::ZIP_NAME ) ) ) {
			throw new \RuntimeException( __( 'The ZIP file could not be opened.', 'puppypress-migrator' ) );
		}

		foreach ( $batch as $relative ) {
			$entry = Package::UPLOADS_ENTRY . $relative;
			$zip->addFile( $basedir . $relative, $entry );

			// Images are already compressed; storing them saves a lot of CPU time.
			if ( method_exists( $zip, 'setCompressionName' ) && preg_match( '/\.(jpe?g|png|gif|webp|avif|heic|mp4|mov|mp3|zip|pdf)$/i', $relative ) ) {
				$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
			}
		}

		$zip->close();

		$job->state['cursor']              += count( $batch );
		$job->state['stats']['files_done'] = $job->state['cursor'];

		return $job->state['cursor'] >= count( $files );
	}

	/**
	 * Final step.
	 *
	 * @param Job $job Job.
	 * @return bool Stage complete.
	 */
	public function finish( Job $job ) {
		$size = filesize( $job->path( self::ZIP_NAME ) );
		@unlink( $job->path( self::FILES_LIST ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		$job->state['stats']['size'] = $size;
		/* translators: %s: file size */
		$job->log( sprintf( __( 'Export finished (%s).', 'puppypress-migrator' ), size_format( $size, 1 ) ) );

		return true;
	}

	/**
	 * File name offered for download.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	public static function download_name( Job $job ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return sanitize_file_name( 'puppypress-export-' . $host . '-' . gmdate( 'Y-m-d', (int) $job->state['created'] ) . '.zip' );
	}

	/**
	 * Collects all records.
	 *
	 * @param array $options Options.
	 * @param Job   $job     Job (for warnings).
	 * @return array
	 */
	public function collect_data( array $options, Job $job ) {
		global $wpdb;

		$post_types = Config::club_post_types();
		if ( $options['content'] ) {
			$post_types = array_merge( $post_types, array( 'post', 'page' ) );
		}
		if ( $options['menus'] ) {
			$post_types[] = 'nav_menu_item';
		}

		$statuses = array_diff( Config::post_statuses(), array( 'inherit' ) );

		$posts = $this->query_posts(
			"post_type IN (" . $this->placeholders( $post_types ) . ") AND post_status IN (" . $this->placeholders( $statuses ) . ')',
			array_merge( $post_types, $statuses )
		);

		$meta = $this->query_post_meta( array_keys( $posts ) );

		// Attachments.
		if ( 'all' === $options['media'] ) {
			$attachments = $this->query_posts( "post_type = 'attachment'", array() );
		} elseif ( 'referenced' === $options['media'] ) {
			$ids         = $this->referenced_attachment_ids( $posts, $meta );
			$attachments = $ids ? $this->query_posts( 'post_type = \'attachment\' AND ( ID IN (' . implode( ',', $ids ) . ') OR post_parent IN (' . implode( ',', array_merge( array( 0 ), array_keys( $posts ) ) ) . ') )', array() ) : array();
		} else {
			$attachments = array();
		}

		if ( $attachments ) {
			$meta  += $this->query_post_meta( array_keys( $attachments ) );
			$posts += $attachments;
		}

		$excluded = Config::excluded_post_meta_keys();
		$basedir  = trailingslashit( wp_upload_dir( null, false )['basedir'] );

		foreach ( $posts as $id => $post ) {
			$posts[ $id ]['meta']  = array();
			$posts[ $id ]['terms'] = array();

			foreach ( isset( $meta[ $id ] ) ? $meta[ $id ] : array() as $row ) {
				if ( ! in_array( $row['meta_key'], $excluded, true ) ) {
					$posts[ $id ]['meta'][ $row['meta_key'] ][] = $row['meta_value'];
				}
			}

			if ( 'attachment' === $post['post_type'] ) {
				$posts[ $id ]['file_sizes'] = $this->attachment_files( $posts[ $id ]['meta'], $basedir, $job );
				$posts[ $id ]['files']      = array_keys( $posts[ $id ]['file_sizes'] );
			}
		}

		// Term relationships.
		$taxonomies = Config::club_taxonomies();
		if ( $options['menus'] ) {
			$taxonomies[] = 'nav_menu';
		}

		foreach ( array_chunk( array_keys( $posts ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT tr.object_id, tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$posts[ $row['object_id'] ]['terms'][ $row['taxonomy'] ][] = (int) $row['term_id'];
				$taxonomies[] = $row['taxonomy'];
			}
		}

		$taxonomies = array_values( array_unique( $taxonomies ) );

		// Terms with meta.
		$terms = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT t.term_id, t.name, t.slug, t.term_group, tt.taxonomy, tt.description, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN (" . $this->placeholders( $taxonomies ) . ') ORDER BY t.term_id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$taxonomies
			),
			ARRAY_A
		);

		$term_meta = array();
		$term_ids  = wp_list_pluck( $terms, 'term_id' );
		foreach ( array_chunk( $term_ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY meta_id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				if ( Importer::SOURCE_META !== $row['meta_key'] ) {
					$term_meta[ $row['term_id'] ][ $row['meta_key'] ][] = $row['meta_value'];
				}
			}
		}

		foreach ( $terms as $index => $term ) {
			$terms[ $index ]['term_id'] = (int) $term['term_id'];
			$terms[ $index ]['parent']  = (int) $term['parent'];
			$terms[ $index ]['meta']    = isset( $term_meta[ $term['term_id'] ] ) ? $term_meta[ $term['term_id'] ] : array();
		}

		// Comments.
		$comments = array();
		if ( $options['comments'] ) {
			$comments = $this->query_comments( array_keys( $posts ) );
		}

		// Users (authors of posts and comments).
		$user_ids = array_merge( wp_list_pluck( $posts, 'post_author' ), wp_list_pluck( $comments, 'user_id' ) );
		$users    = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$users[] = array(
					'ID'           => $user->ID,
					'user_login'   => $user->user_login,
					'user_email'   => $user->user_email,
					'display_name' => $user->display_name,
					'first_name'   => $user->first_name,
					'last_name'    => $user->last_name,
					'user_url'     => $user->user_url,
					'roles'        => array_values( $user->roles ),
				);
			}
		}

		$uploads = wp_upload_dir( null, false );

		return array(
			'format'   => Package::FORMAT,
			'version'  => Package::VERSION,
			'source'   => array(
				'home'            => home_url(),
				'siteurl'         => site_url(),
				'uploads_baseurl' => $uploads['baseurl'],
				'name'            => get_bloginfo( 'name' ),
				'theme'           => get_stylesheet(),
				'wp_version'      => get_bloginfo( 'version' ),
				'exported_at'     => gmdate( 'c' ),
				'exporter'        => PPM_VERSION,
			),
			'options'  => $options,
			'users'    => $users,
			'terms'    => $terms,
			'posts'    => array_values( $posts ),
			'comments' => $comments,
			'widgets'  => $options['widgets'] ? $this->collect_widgets() : array(),
			'settings' => array(
				'sticky_posts'       => array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ),
				'show_on_front'      => get_option( 'show_on_front' ),
				'page_on_front'      => (int) get_option( 'page_on_front' ),
				'page_for_posts'     => (int) get_option( 'page_for_posts' ),
				'nav_menu_locations' => $options['menus'] ? array_map( 'intval', (array) get_theme_mod( 'nav_menu_locations', array() ) ) : array(),
			),
		);
	}

	/**
	 * Selects posts.
	 *
	 * @param string $where  WHERE clause with placeholders.
	 * @param array  $values Values.
	 * @return array<int, array> Keyed by ID.
	 */
	private function query_posts( $where, array $values ) {
		global $wpdb;

		$sql  = "SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type, post_mime_type, guid FROM {$wpdb->posts} WHERE {$where} ORDER BY ID";
		$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

		$posts = array();
		foreach ( $rows as $row ) {
			$row['ID']          = (int) $row['ID'];
			$row['post_author'] = (int) $row['post_author'];
			$row['post_parent'] = (int) $row['post_parent'];
			$row['menu_order']  = (int) $row['menu_order'];
			$posts[ $row['ID'] ] = $row;
		}

		return $posts;
	}

	/**
	 * Selects post meta.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, array[]>
	 */
	private function query_post_meta( array $ids ) {
		global $wpdb;

		$meta = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY meta_id', ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$meta[ (int) $row['post_id'] ][] = $row;
			}
		}

		return $meta;
	}

	/**
	 * Selects approved and pending comments with meta.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array[]
	 */
	private function query_comments( array $post_ids ) {
		global $wpdb;

		$comments = array();

		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_approved IN ('0','1') AND comment_post_ID IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY comment_ID', ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$row['meta'] = array();
				$comments[ (int) $row['comment_ID'] ] = $row;
			}
		}

		foreach ( array_chunk( array_keys( $comments ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT comment_id, meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY meta_id', ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$comments[ (int) $row['comment_id'] ]['meta'][ $row['meta_key'] ][] = $row['meta_value'];
			}
		}

		ksort( $comments );

		return array_values( $comments );
	}

	/**
	 * Attachment IDs used by the exported posts.
	 *
	 * @param array $posts Posts.
	 * @param array $meta  Post meta rows.
	 * @return int[]
	 */
	private function referenced_attachment_ids( array $posts, array $meta ) {
		$ids = array();

		foreach ( $meta as $rows ) {
			foreach ( $rows as $row ) {
				if ( '_thumbnail_id' === $row['meta_key'] ) {
					$ids[] = (int) $row['meta_value'];
				} elseif ( in_array( $row['meta_key'], Config::post_id_list_meta_keys(), true ) ) {
					$ids = array_merge( $ids, array_map( 'intval', explode( ',', $row['meta_value'] ) ) );
				}
			}
		}

		foreach ( $posts as $post ) {
			if ( preg_match_all( '/\bwp-image-(\d+)|"id":(\d+)|\bids=["\']([\d,\s]+)/', $post['post_content'], $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$found = array_filter( array_slice( $match, 1 ) );
					foreach ( $found as $value ) {
						$ids = array_merge( $ids, array_map( 'intval', explode( ',', $value ) ) );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Files (original, scaled original, sizes) of an attachment that exist on disk.
	 *
	 * @param array  $meta    Attachment meta.
	 * @param string $basedir Uploads base dir with trailing slash.
	 * @param Job    $job     Job.
	 * @return array<string, int> Paths relative to uploads => size in bytes.
	 */
	private function attachment_files( array $meta, $basedir, Job $job ) {
		if ( empty( $meta['_wp_attached_file'][0] ) ) {
			return array();
		}

		$main     = ltrim( $meta['_wp_attached_file'][0], '/' );
		$dir      = dirname( $main );
		$dir      = '.' === $dir ? '' : $dir . '/';
		$files    = array( $main );
		$metadata = isset( $meta['_wp_attachment_metadata'][0] ) ? Remapper::unserialize( $meta['_wp_attachment_metadata'][0] ) : array();

		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['original_image'] ) ) {
				$files[] = $dir . $metadata['original_image'];
			}

			foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}

		$existing = array();
		foreach ( array_unique( $files ) as $file ) {
			$reason = Package::rejection_reason( $file );

			if ( '' !== $reason ) {
				if ( $file === $main ) {
					$this->file_problem( $job, 'rejected', $file, $reason );
				}
				continue;
			}

			if ( ! is_file( $basedir . $file ) ) {
				// Missing generated sizes are common and harmless; only report the original.
				if ( $file === $main ) {
					$this->file_problem( $job, 'missing', $file );
				}
				continue;
			}

			$existing[ $file ] = (int) filesize( $basedir . $file );
		}

		return $existing;
	}

	/**
	 * Records a problem with a media file; logs the first ones individually.
	 *
	 * @param Job    $job    Job.
	 * @param string $type   missing|rejected.
	 * @param string $file   Relative path.
	 * @param string $reason Reason for rejected files.
	 */
	private function file_problem( Job $job, $type, $file, $reason = '' ) {
		$job->count( 'files_' . $type );
		$count = $job->state['stats'][ 'files_' . $type ];

		if ( $count > self::LOG_LIMIT ) {
			return;
		}

		if ( 'missing' === $type ) {
			/* translators: %s: file path */
			$job->log( sprintf( __( 'File is missing on the server: %s', 'puppypress-migrator' ), $file ), 'warning' );
		} else {
			/* translators: 1: file path, 2: reason */
			$job->log( sprintf( __( 'Not transferred (%2$s): %1$s', 'puppypress-migrator' ), $file, $reason ), 'warning' );
		}
	}

	/**
	 * Summary of media problems.
	 *
	 * @param Job $job Job.
	 */
	private function log_file_problems( Job $job ) {
		$stats = $job->state['stats'];

		if ( ! empty( $stats['files_missing'] ) ) {
			/* translators: %d: number of files */
			$job->log( sprintf( __( '%d media library entries point to files that do not exist on the server (deleted, moved or stored externally).', 'puppypress-migrator' ), $stats['files_missing'] ), 'warning' );
		}

		if ( ! empty( $stats['files_rejected'] ) ) {
			/* translators: %d: number of files */
			$job->log( sprintf( __( '%d media files were not transferred because of their file type or name (see above).', 'puppypress-migrator' ), $stats['files_rejected'] ), 'warning' );
		}

		foreach ( array( 'files_missing', 'files_rejected' ) as $key ) {
			if ( ! empty( $stats[ $key ] ) && $stats[ $key ] > self::LOG_LIMIT ) {
				/* translators: %d: number of files */
				$job->log( sprintf( __( 'Only the first %d of these files are listed individually.', 'puppypress-migrator' ), self::LOG_LIMIT ), 'warning' );
				break;
			}
		}
	}

	/**
	 * Widgets placed in sidebars, including their settings.
	 *
	 * @return array{sidebars: array, instances: array}
	 */
	private function collect_widgets() {
		$sidebars  = get_option( 'sidebars_widgets', array() );
		$export    = array();
		$instances = array();

		foreach ( (array) $sidebars as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || 'array_version' === $sidebar || ! is_array( $widgets ) || ! $widgets ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
					continue;
				}

				$settings = get_option( 'widget_' . $m[1], array() );

				if ( isset( $settings[ $m[2] ] ) ) {
					$export[ $sidebar ][]    = $widget_id;
					$instances[ $widget_id ] = $settings[ $m[2] ];
				}
			}
		}

		return array(
			'sidebars'  => $export,
			'instances' => $instances,
		);
	}

	/**
	 * Placeholder list for IN().
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private function placeholders( array $values ) {
		return implode( ',', array_fill( 0, count( $values ), '%s' ) );
	}
}

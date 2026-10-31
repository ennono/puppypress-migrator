<?php
/**
 * Admin screen: Tools → PuppyPress Migration.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator\Admin;

use PuppyPressMigrator\Exporter;
use PuppyPressMigrator\Importer;
use PuppyPressMigrator\Job;
use PuppyPressMigrator\Package;
use PuppyPressMigrator\Runner;
use PuppyPressMigrator\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Screen, AJAX endpoints and download handler.
 */
final class Page {

	const SLUG       = 'puppypress-migrator';
	const CAPABILITY = 'manage_options';
	const NONCE      = 'ppm_admin';

	/**
	 * Hook suffix of the screen.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_ppm_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ppm_step', array( $this, 'ajax_step' ) );
		add_action( 'admin_post_ppm_download', array( $this, 'download' ) );
		add_action( 'admin_post_ppm_delete', array( $this, 'delete' ) );
	}

	/**
	 * Adds the screen to the Tools menu.
	 */
	public function menu() {
		$this->hook = (string) add_management_page(
			__( 'PuppyPress Migration', 'puppypress-migrator' ),
			__( 'PuppyPress Migration', 'puppypress-migrator' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Script and styles.
	 *
	 * @param string $hook Current screen.
	 */
	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'ppm-admin', PPM_URL . 'assets/admin.css', array(), PPM_VERSION );
		wp_enqueue_script( 'ppm-admin', PPM_URL . 'assets/admin.js', array(), PPM_VERSION, true );
		wp_localize_script(
			'ppm-admin',
			'ppmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'starting'  => __( 'Starting …', 'puppypress-migrator' ),
					'done'      => __( 'Done.', 'puppypress-migrator' ),
					'failed'    => __( 'Failed:', 'puppypress-migrator' ),
					'download'  => __( 'Download export', 'puppypress-migrator' ),
					'network'   => __( 'The request failed. The job continues where it stopped when you retry.', 'puppypress-migrator' ),
					'retry'     => __( 'Retry', 'puppypress-migrator' ),
					'noFile'    => __( 'Please choose a ZIP file.', 'puppypress-migrator' ),
					'confirm'   => __( 'Start the import into this site now? Please make sure you have a backup.', 'puppypress-migrator' ),
					'leaving'   => __( 'A migration is running. Leaving the page pauses it.', 'puppypress-migrator' ),
					'busy'      => __( 'This job is currently running in another browser window. Waiting …', 'puppypress-migrator' ),
					'reload'    => __( 'Refresh overview', 'puppypress-migrator' ),
				),
			)
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render() {
		$incoming = Storage::incoming_files();
		$jobs     = Job::all();
		?>
		<div class="wrap ppm">
			<h1><?php esc_html_e( 'PuppyPress Migration', 'puppypress-migrator' ); ?></h1>
			<p class="ppm-lead"><?php esc_html_e( 'Moves all club data from a site with the former "Extra Child" theme to a site with PuppyPress: stud dogs with pedigrees, litters, dogs to place, approvals, breeding licenses, results, kennels, breeders, owners, media, menus, widgets and more. All references between records are rewritten to the new IDs.', 'puppypress-migrator' ); ?></p>

			<?php if ( ! Package::supported() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The PHP extension "zip" (ZipArchive) is missing. Please ask your host to enable it.', 'puppypress-migrator' ); ?></p></div>
			<?php endif; ?>

			<div class="ppm-columns">
				<section class="ppm-card" aria-labelledby="ppm-export-title">
					<h2 id="ppm-export-title"><span class="ppm-step">1</span> <?php esc_html_e( 'Export (old site)', 'puppypress-migrator' ); ?></h2>
					<form data-ppm-form="export">
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'What to export', 'puppypress-migrator' ); ?></legend>
							<p><label><input type="checkbox" checked disabled data-ppm-always-disabled> <?php esc_html_e( 'Club data (always included)', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="content" value="1" checked> <?php esc_html_e( 'Posts and pages', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="menus" value="1" checked> <?php esc_html_e( 'Menus', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="comments" value="1" checked> <?php esc_html_e( 'Comments', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="widgets" value="1" checked> <?php esc_html_e( 'Widgets', 'puppypress-migrator' ); ?></label></p>
							<p>
								<label for="ppm-media"><?php esc_html_e( 'Media files', 'puppypress-migrator' ); ?></label><br>
								<select name="media" id="ppm-media">
									<option value="all"><?php esc_html_e( 'Entire media library', 'puppypress-migrator' ); ?></option>
									<option value="referenced"><?php esc_html_e( 'Only media used by the exported content', 'puppypress-migrator' ); ?></option>
									<option value="none"><?php esc_html_e( 'No media files', 'puppypress-migrator' ); ?></option>
								</select>
							</p>
							<fieldset class="ppm-transfer">
								<legend><?php esc_html_e( 'How media files are transferred', 'puppypress-migrator' ); ?></legend>
								<p>
									<label><input type="radio" name="transfer" value="download" checked> <?php esc_html_e( 'Download during import (recommended)', 'puppypress-migrator' ); ?></label><br>
									<span class="description"><?php esc_html_e( 'The package stays small. The new site downloads the files from this site, which must stay online until the import is finished. Alternatively copy the uploads folder via FTP/rsync beforehand; files that are already there are only checked.', 'puppypress-migrator' ); ?></span>
								</p>
								<p>
									<label><input type="radio" name="transfer" value="zip"> <?php esc_html_e( 'Pack into the ZIP file', 'puppypress-migrator' ); ?></label><br>
									<span class="description"><?php esc_html_e( 'Self-contained package. Only suitable for small media libraries (a few thousand files).', 'puppypress-migrator' ); ?></span>
								</p>
							</fieldset>
						</fieldset>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create export', 'puppypress-migrator' ); ?></button></p>
					</form>

				</section>

				<section class="ppm-card" aria-labelledby="ppm-import-title">
					<h2 id="ppm-import-title"><span class="ppm-step">2</span> <?php esc_html_e( 'Import (new site with PuppyPress)', 'puppypress-migrator' ); ?></h2>

					<?php if ( ! class_exists( 'PuppyPress\\Plugin' ) ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'PuppyPress is not active on this site. Activate it before importing, otherwise club taxonomies cannot be assigned.', 'puppypress-migrator' ); ?></p></div>
					<?php endif; ?>

					<form data-ppm-form="import" enctype="multipart/form-data">
						<p>
							<label for="ppm-package"><?php esc_html_e( 'Migration package (ZIP)', 'puppypress-migrator' ); ?></label><br>
							<input type="file" name="package" id="ppm-package" accept=".zip,application/zip">
							<br><span class="description">
								<?php
								/* translators: %s: maximum upload size */
								echo esc_html( sprintf( __( 'Maximum upload size: %s.', 'puppypress-migrator' ), size_format( wp_max_upload_size() ) ) );
								?>
							</span>
						</p>

						<?php if ( $incoming ) : ?>
							<p>
								<label for="ppm-incoming"><?php esc_html_e( 'Or use a file from the incoming folder', 'puppypress-migrator' ); ?></label><br>
								<select name="incoming" id="ppm-incoming">
									<option value=""><?php esc_html_e( '— Upload above —', 'puppypress-migrator' ); ?></option>
									<?php foreach ( $incoming as $file ) : ?>
										<option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php endif; ?>

						<p class="description">
							<?php
							/* translators: %s: folder path */
							echo esc_html( sprintf( __( 'Large packages: upload them via FTP/SFTP to %s and reload this page.', 'puppypress-migrator' ), str_replace( ABSPATH, '', Storage::incoming_dir() ) . '/' ) );
							?>
						</p>

						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Import options', 'puppypress-migrator' ); ?></legend>
							<p>
								<label for="ppm-media-source"><?php esc_html_e( 'Download media from (optional)', 'puppypress-migrator' ); ?></label><br>
								<input type="url" class="regular-text" name="media_source" id="ppm-media-source" placeholder="https://alte-domain.de/wp-content/uploads">
								<br><span class="description"><?php esc_html_e( 'Only needed if the old site moved since the export. Empty: the address stored in the package.', 'puppypress-migrator' ); ?></span>
							</p>
							<p><label><input type="checkbox" name="replace_urls" value="1" checked> <?php esc_html_e( 'Replace links to the old domain with this site', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="widgets" value="1" checked> <?php esc_html_e( 'Import widgets', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="settings" value="1" checked> <?php esc_html_e( 'Apply front page, sticky posts and menu locations', 'puppypress-migrator' ); ?></label></p>
							<p><label><input type="checkbox" name="create_users" value="1"> <?php esc_html_e( 'Create missing authors (with random passwords)', 'puppypress-migrator' ); ?></label></p>
							<p>
								<label for="ppm-author"><?php esc_html_e( 'Otherwise assign their content to', 'puppypress-migrator' ); ?></label><br>
								<?php
								wp_dropdown_users(
									array(
										'name'     => 'author',
										'id'       => 'ppm-author',
										'selected' => get_current_user_id(),
										'role__in' => array( 'administrator', 'editor', 'author' ),
									)
								);
								?>
							</p>
						</fieldset>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Start import', 'puppypress-migrator' ); ?></button></p>
					</form>
					<p class="description"><?php esc_html_e( 'Records that already exist from an earlier run of the same package are skipped, so an interrupted import can simply be started again.', 'puppypress-migrator' ); ?></p>
				</section>
			</div>

			<section class="ppm-card ppm-progress" data-ppm-progress hidden aria-live="polite">
				<h2 data-ppm-title></h2>
				<div class="ppm-bar"><span data-ppm-bar></span></div>
				<p class="ppm-stage" data-ppm-stage></p>
				<ul class="ppm-summary" data-ppm-summary></ul>
				<p data-ppm-result></p>
				<ol class="ppm-log" data-ppm-log></ol>
			</section>

			<?php if ( $jobs ) : ?>
				<section class="ppm-card ppm-jobs" aria-labelledby="ppm-jobs-title">
					<h2 id="ppm-jobs-title"><?php esc_html_e( 'Jobs', 'puppypress-migrator' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A job only runs while this page is open. Paused jobs continue where they stopped. Exports contain personal data (addresses, phone numbers, emails); all job files are deleted automatically three days after their last activity.', 'puppypress-migrator' ); ?></p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Job', 'puppypress-migrator' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'puppypress-migrator' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Progress', 'puppypress-migrator' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'puppypress-migrator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $jobs as $job ) : ?>
								<?php $this->render_job_row( $job ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One row of the jobs table.
	 *
	 * @param Job $job Job.
	 */
	private function render_job_row( Job $job ) {
		$status   = $job->status();
		$progress = Runner::progress( $job );
		$labels   = array(
			'running' => __( 'running', 'puppypress-migrator' ),
			'paused'  => __( 'paused', 'puppypress-migrator' ),
			'done'    => __( 'finished', 'puppypress-migrator' ),
			'error'   => __( 'failed', 'puppypress-migrator' ),
		);
		$type     = 'export' === $job->state['type'] ? __( 'Export', 'puppypress-migrator' ) : __( 'Import', 'puppypress-migrator' );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$resumable = ! $job->state['done'] && ( 'export' === $job->state['type'] || is_readable( $job->path( Importer::ZIP_NAME ) ) );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $type ); ?></strong><br>
				<span class="description">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'started %s', 'puppypress-migrator' ), wp_date( $format, (int) $job->state['created'] ) ) );
					if ( ! empty( $job->state['updated'] ) ) {
						echo '<br>';
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'last activity %s', 'puppypress-migrator' ), wp_date( $format, (int) $job->state['updated'] ) ) );
					}
					?>
				</span>
			</td>
			<td><span class="ppm-status ppm-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></span></td>
			<td>
				<?php if ( ! $job->state['done'] ) : ?>
					<div class="ppm-bar ppm-bar--small"><span style="width: <?php echo (int) $progress['percent']; ?>%"></span></div>
					<?php echo esc_html( $progress['stage'] ); ?>
				<?php elseif ( ! empty( $job->state['error'] ) ) : ?>
					<?php echo esc_html( $job->state['error'] ); ?>
				<?php endif; ?>
				<?php $summary = Runner::summary( $job ); ?>
				<?php if ( $summary ) : ?>
					<ul class="ppm-summary">
						<?php foreach ( $summary as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $job->state['log'] ) : ?>
					<details class="ppm-job-log">
						<summary>
							<?php
							/* translators: %d: number of log lines */
							echo esc_html( sprintf( _n( 'Log (%d line)', 'Log (%d lines)', count( $job->state['log'] ), 'puppypress-migrator' ), count( $job->state['log'] ) ) );
							?>
						</summary>
						<ol class="ppm-log">
							<?php foreach ( array_slice( $job->state['log'], -300 ) as $entry ) : ?>
								<li class="ppm-log__<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['message'] ); ?></li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endif; ?>
			</td>
			<td class="ppm-actions">
				<?php if ( $resumable ) : ?>
					<button type="button" class="button button-primary" data-ppm-resume="<?php echo esc_attr( $job->id ); ?>" data-ppm-title="<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Continue', 'puppypress-migrator' ); ?></button>
				<?php endif; ?>
				<?php if ( 'export' === $job->state['type'] && 'done' === $status && is_readable( $job->path( Exporter::ZIP_NAME ) ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $this->action_url( 'ppm_download', $job->id ) ); ?>"><?php esc_html_e( 'Download', 'puppypress-migrator' ); ?></a>
					<span class="description"><?php echo esc_html( size_format( isset( $job->state['stats']['size'] ) ? $job->state['stats']['size'] : 0, 1 ) ); ?></span>
				<?php endif; ?>
				<a class="ppm-delete" href="<?php echo esc_url( $this->action_url( 'ppm_delete', $job->id ) ); ?>" data-ppm-confirm="<?php esc_attr_e( 'Delete this job and its files? Imported content is not affected.', 'puppypress-migrator' ); ?>"><?php esc_html_e( 'Delete', 'puppypress-migrator' ); ?></a>
			</td>
		</tr>
		<?php
	}

	/**
	 * AJAX: creates a job.
	 */
	public function ajax_start() {
		$this->verify();

		$type = isset( $_POST['type'] ) && 'import' === $_POST['type'] ? 'import' : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().

		try {
			$job = 'export' === $type ? $this->start_export() : $this->start_import();
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		wp_send_json_success( $this->response( $job, 0 ) );
	}

	/**
	 * AJAX: runs the next chunk of a job.
	 */
	public function ajax_step() {
		$this->verify();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
		$job = Job::load( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );
		$log = isset( $_POST['log'] ) ? absint( $_POST['log'] ) : 0;
		// phpcs:enable

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'puppypress-migrator' ) ), 404 );
		}

		/**
		 * Filters the seconds a single request works on a job.
		 *
		 * @param float $seconds Default 15.
		 */
		Runner::run( $job, (float) apply_filters( 'ppm_step_seconds', 15.0 ) );

		wp_send_json_success( $this->response( $job, $log ) );
	}

	/**
	 * Streams a finished export.
	 */
	public function download() {
		$job = $this->job_from_request();
		$zip = $job ? $job->path( Exporter::ZIP_NAME ) : '';

		if ( ! $job || 'export' !== $job->state['type'] || ! $job->state['done'] || ! is_readable( $zip ) ) {
			wp_die( esc_html__( 'Export not found.', 'puppypress-migrator' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . Exporter::download_name( $job ) . '"' );
		header( 'Content-Length: ' . filesize( $zip ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Deletes a job.
	 */
	public function delete() {
		$job = $this->job_from_request();

		if ( $job ) {
			Storage::delete_job( $job->id );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Creates an export job from the form.
	 *
	 * @return Job
	 */
	private function start_export() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
		$media = isset( $_POST['media'] ) ? sanitize_key( $_POST['media'] ) : 'all';

		return Job::create(
			'export',
			array(
				'content'  => ! empty( $_POST['content'] ),
				'menus'    => ! empty( $_POST['menus'] ),
				'comments' => ! empty( $_POST['comments'] ),
				'widgets'  => ! empty( $_POST['widgets'] ),
				'media'    => in_array( $media, array( 'all', 'referenced', 'none' ), true ) ? $media : 'all',
				'transfer' => isset( $_POST['transfer'] ) && 'zip' === $_POST['transfer'] ? 'zip' : 'download',
			)
		);
		// phpcs:enable
	}

	/**
	 * Creates an import job from the form and moves the package into it.
	 *
	 * @return Job
	 */
	private function start_import() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
		$options = array(
			'create_users' => ! empty( $_POST['create_users'] ),
			'author'       => isset( $_POST['author'] ) ? absint( $_POST['author'] ) : 0,
			'replace_urls' => ! empty( $_POST['replace_urls'] ),
			'widgets'      => ! empty( $_POST['widgets'] ),
			'settings'     => ! empty( $_POST['settings'] ),
			'media_source' => isset( $_POST['media_source'] ) ? esc_url_raw( wp_unslash( $_POST['media_source'] ) ) : '',
		);

		$incoming = isset( $_POST['incoming'] ) ? sanitize_file_name( wp_unslash( $_POST['incoming'] ) ) : '';
		$upload   = isset( $_FILES['package'] ) && is_array( $_FILES['package'] ) ? $_FILES['package'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable

		if ( '' !== $incoming ) {
			$source = Storage::incoming_dir() . '/' . $incoming;

			if ( ! in_array( $incoming, Storage::incoming_files(), true ) || ! is_file( $source ) ) {
				throw new \RuntimeException( __( 'The selected file does not exist.', 'puppypress-migrator' ) );
			}

			$job = Job::create( 'import', $options );
			rename( $source, $job->path( Importer::ZIP_NAME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

			return $job;
		}

		if ( ! $upload || UPLOAD_ERR_OK !== (int) $upload['error'] || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			throw new \RuntimeException( __( 'The upload failed. The file may be larger than the maximum upload size; use the incoming folder instead.', 'puppypress-migrator' ) );
		}

		if ( 'zip' !== strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) ) ) {
			throw new \RuntimeException( __( 'Please choose a ZIP file.', 'puppypress-migrator' ) );
		}

		$job = Job::create( 'import', $options );

		if ( ! move_uploaded_file( $upload['tmp_name'], $job->path( Importer::ZIP_NAME ) ) ) {
			Storage::delete_job( $job->id );
			throw new \RuntimeException( __( 'The uploaded file could not be stored.', 'puppypress-migrator' ) );
		}

		return $job;
	}

	/**
	 * JSON response for the UI.
	 *
	 * @param Job $job       Job.
	 * @param int $log_since Log index already shown.
	 * @return array
	 */
	private function response( Job $job, $log_since ) {
		$progress = Runner::progress( $job );
		$stats    = $job->state['stats'];

		// Finer progress for long stages.
		if ( ! $job->state['done'] && in_array( isset( $job->state['stage_name'] ) ? $job->state['stage_name'] : '', array( 'media', 'files' ), true ) && ! empty( $stats['files_total'] ) ) {
			/* translators: 1: files done, 2: total files */
			$progress['stage'] .= ' ' . sprintf( __( '(%1$d of %2$d)', 'puppypress-migrator' ), $stats['files_done'], $stats['files_total'] );
		}

		return array(
			'job'      => $job->id,
			'type'     => $job->state['type'],
			'done'     => (bool) $job->state['done'],
			'error'    => isset( $job->state['error'] ) ? $job->state['error'] : '',
			'percent'  => $progress['percent'],
			'stage'    => $progress['stage'],
			'log'      => array_map(
				static function ( $entry ) {
					return array(
						'level'   => $entry['level'],
						'message' => $entry['message'],
					);
				},
				$job->log_since( $log_since )
			),
			'logTotal' => count( $job->state['log'] ),
			'summary'  => Runner::summary( $job ),
			'busy'     => (bool) $job->busy,
			'download' => 'export' === $job->state['type'] && $job->state['done'] && empty( $job->state['error'] ) ? $this->action_url( 'ppm_download', $job->id ) : '',
		);
	}

	/**
	 * URL of an admin-post action for a job.
	 *
	 * @param string $action Action.
	 * @param string $id     Job ID.
	 * @return string
	 */
	private function action_url( $action, $id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&job=' . $id ), $action . '_' . $id );
	}

	/**
	 * Job referenced by an admin-post request (with nonce and capability check).
	 *
	 * @return Job|null
	 */
	private function job_from_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'puppypress-migrator' ), '', array( 'response' => 403 ) );
		}

		$id     = isset( $_GET['job'] ) ? Storage::sanitize_id( sanitize_key( wp_unslash( $_GET['job'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.

		check_admin_referer( $action . '_' . $id );

		return Job::load( $id );
	}

	/**
	 * Capability and nonce check for AJAX requests.
	 */
	private function verify() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'puppypress-migrator' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}
}

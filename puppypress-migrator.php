<?php
/**
 * Plugin Name:       PuppyPress Migrator
 * Plugin URI:        https://github.com/ennono/puppypress-migrator
 * Description:       Exports all club data (stud dogs, litters, kennels, pedigrees, media, menus, widgets …) from a site running the former "Extra Child" theme and imports it into a site running PuppyPress, including remapping of all IDs.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Timo Fricke
 * Author URI:        https://www.fricke-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       puppypress-migrator
 * Domain Path:       /languages
 *
 * @package PuppyPressMigrator
 */

defined( 'ABSPATH' ) || exit;

define( 'PPM_VERSION', '1.2.0' );
define( 'PPM_FILE', __FILE__ );
define( 'PPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPM_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'PuppyPressMigrator\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$file = PPM_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'PuppyPressMigrator\\Plugin', 'boot' ) );
register_deactivation_hook( __FILE__, array( 'PuppyPressMigrator\\Plugin', 'deactivate' ) );

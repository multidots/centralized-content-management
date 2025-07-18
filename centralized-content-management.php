<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.multidots.com/
 * @since             1.0.0
 * @package           Md_Centralized_Content_Management
 *
 * @wordpress-plugin
 * Plugin Name:       Centralized Content Management
 * Plugin URI:        https://www.multidots.com/
 * Description:       The Centralized Content Management system enables WordPress users to create and manage content from a central site, which can then be synchronized across multiple subsites within a Multisite Network.
 * Version:           1.1.0
 * Requires PHP:      7.2.5
 * Author:            Multidots
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       centralized-content-management
 * Domain Path:       /languages
 */

namespace Centralized_Content_Management;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'CENTRALIZED_CONTENT_MANAGEMENT_VERSION', '1.1.0' );
define( 'CENTRALIZED_CONTENT_MANAGEMENT_URL', plugin_dir_url( __FILE__ ) );
define( 'CENTRALIZED_CONTENT_MANAGEMENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CENTRALIZED_CONTENT_MANAGEMENT_BASEPATH', plugin_basename( __FILE__ ) );
define( 'CENTRALIZED_CONTENT_MANAGEMENT_SRC_BLOCK_DIR_PATH', untrailingslashit( CENTRALIZED_CONTENT_MANAGEMENT_DIR . 'assets/build/js/blocks' ) );
define( 'CENTRALIZED_CONTENT_MANAGEMENT_LOGO_ICON', CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/src/images/ccm-icon.svg' );

if ( ! defined( 'CENTRALIZED_CONTENT_MANAGEMENT_PATH' ) ) {
	define( 'CENTRALIZED_CONTENT_MANAGEMENT_PATH', __DIR__ );
}

// Load the autoloader.
require_once plugin_dir_path( __FILE__ ) . '/inc/helpers/autoloader.php';


register_activation_hook( __FILE__, array( \Centralized_Content_Management\Inc\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Centralized_Content_Management\Inc\Deactivator::class, 'deactivate' ) );

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_md_scaffold() {
	$plugin = new \Centralized_Content_Management\Inc\Centralized_Content_Management();
}
run_md_scaffold();

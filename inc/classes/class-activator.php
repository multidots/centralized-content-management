<?php
/**
 * The activation functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/admin
 * @author     Multidots <info@multidots.com>
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Activator class file.
 */
class Activator {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Activate hooks.
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Check if Multisite is enabled.
		if ( ! is_multisite() ) {
			// Display an error message.
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'This plugin can only be activated on a WordPress Multisite network.', 'centralized-content-management' ), 'Plugin Activation Error', array( 'back_link' => true ) );
		}
	}
}

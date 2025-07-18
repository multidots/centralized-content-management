<?php
/**
 * The core plugin class.
 *
 * @since      1.0.0
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/includes
 * @author     Multidots <info@multidots.com>
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Blocks;
use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Main class File.
 */
class Centralized_Content_Management {


	use Singleton;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Centralized_Content_Management_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'CENTRALIZED_CONTENT_MANAGEMENT_VERSION' ) ) {
			$this->version = CENTRALIZED_CONTENT_MANAGEMENT_VERSION;
		} else {
			$this->version = '1.1.0';
		}
		$this->plugin_name = 'centralized-content-management';

		Front::get_instance();
		Admin::get_instance();
		Activator::get_instance();
		Deactivator::get_instance();
		I18::get_instance();
		Blocks::get_instance();
		Network_Manager::get_instance();
		Central_Manager::get_instance();
		Rest_Routes::get_instance();
		Utils::get_instance();
		Subsite_Manager::get_instance();
		Bulk_Sync_Manager::get_instance();
		Logs_Manager::get_instance();
		Central_Content_Queue::get_instance();
		Subsite_Content_Queue::get_instance();
		Content_Queue_Helper::get_instance();
		Sync_Process::get_instance();
	}
}

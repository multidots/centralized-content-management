<?php
/**
 * Utility functions for the MD Centralized Content Management plugin.
 *
 * This file contains various helper functions and methods that provide
 * reusable code for the plugin's functionality. These utility functions
 * are designed to enhance code modularity, improve maintainability, and
 * streamline common tasks across the plugin.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/admin
 * @author     Multidots <info@multidots.com>
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;
use WP_Query;

/**
 * Main class file.
 */
class Utils {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->setup_utils_hooks();
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_utils_hooks() {
		// Hooks init here.
	}

	/**
	 * Function is used to get the central site id from network settings.
	 *
	 * @return int|null The network site ID, or null if not exists.
	 */
	public static function centralized_content_management_get_central_site_id() {
		$centralized_content_manager_network_settings = get_network_option( null, 'centralized_content_manager_network_settings' );
		$central_site_id      = null;

		if ( isset( $centralized_content_manager_network_settings['central_site'] ) && ! empty( $centralized_content_manager_network_settings['central_site'] ) ) {
			$central_site_id = $centralized_content_manager_network_settings['central_site'];
		}

		return $central_site_id;
	}

	/**
	 * Retrieves the API key for the site based on the provided blog ID.
	 *
	 * @param int $blog_id The ID of the blog for which to retrieve the API key.
	 * @return string|false The API key for the specified blog ID, or false if not found.
	 */
	public static function get_current_site_api_key( $blog_id ) {
		$api_keys = get_network_option( null, 'centralized_content_management_api_keys', array() );

		if ( ! isset( $api_keys[ $blog_id ] ) ) {
			return false;
		}

		return $api_keys[ $blog_id ];
	}

	/**
	 * Retrieve the network settings for the Centralized Content Management plugin.
	 *
	 * @return array The network settings for the CCM plugin.
	 */
	public static function get_network_settings() {
		$centralized_content_manager_network_settings = get_network_option( null, 'centralized_content_manager_network_settings', array() );

		return $centralized_content_manager_network_settings;
	}

	/**
	 * Get the central site ID from the network settings.
	 *
	 * @return int|null The central site ID, or null if not found.
	 */
	public static function get_central_site_id() {
		$network_settings = self::get_network_settings();

		if ( isset( $network_settings['central_site'] ) ) {
			return $network_settings['central_site'];
		}

		return null;
	}

	/**
	 * Checks if the current site is the central site.
	 *
	 * @return bool True if the current site is the central site, false otherwise.
	 */
	public static function is_central_site() {
		$central_site_id = self::get_central_site_id();
		$current_blog_id = get_current_blog_id();

		return $central_site_id === $current_blog_id;
	}

	/**
	 * Insert a sync log into the central site's log table.
	 *
	 * @param array $data {
	 *     An array of log data to insert.
	 *
	 *     @type int    $post_id     Post ID to be synced.
	 *     @type int    $site_id     Site ID where the sync occurred.
	 *     @type string $sync_status Status of the sync process (e.g., 'Success', 'Failed').
	 *     @type string $sync_time   (Optional) The time the sync occurred. Defaults to the current time.
	 * }
	 */
	public static function insert_log( $data ) {
		global $wpdb;

		// Retrieve the current site ID
		$site_id = self::get_central_site_id();

		// Define the table name for the current site using its prefix.
		$table_name = $wpdb->get_blog_prefix( $site_id ) . 'ccm_sync_logs';

		// Insert data into the logs table.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table_name,
			array(
				'post_id'    => isset( $data['post_id'] ) ? $data['post_id'] : 0,
				'post_name'  => isset( $data['post_name'] ) ? $data['post_name'] : '',
				'sync_sites' => isset( $data['sync_sites'] ) ? maybe_serialize( $data['sync_sites'] ) : maybe_serialize( array() ),
			),
			array(
				'%d',  // post_id (integer)
				'%s',  // post_name (string)
				'%s',  // sync_sites (string)
			)
		);

	}

	/**
	* Default pagination function.
	*
	* @param int $page max_num_pages.
	* @param int $current current page.
	* @param array $query_args Additional query arguments.
	*/
	public static function centralized_content_management_pagination( $page, $current = 1, $query_args = array() ) {
		// Bail if there is only one page.
		if ( $page <= 1 ) {
			return;
		}

		// Ensure $query_args is an array.
		$query_args = is_array( $query_args ) ? $query_args : array();

		// Merge query arguments with pagination.
		$base_url = add_query_arg( array_merge( array( 'paged' => '%#%' ), $query_args ), remove_query_arg( 'paged' ) );

		// Allowed tags for pagination links.
		$allowed_tags = array(
			'span' => array(
				'class' => array(),
			),
			'a'    => array(
				'class' => array(),
				'href'  => array(),
			),
		);

		$args = array(
			'base'      => $base_url,
			'format'    => '?paged=%#%',
			'current'   => $current,
			'total'     => $page,
			'prev_text' => sprintf( '%1$s', __( '&#8592;', 'centralized-content-management' ) ),
			'next_text' => sprintf( '%1$s', __( '&#8594;', 'centralized-content-management' ) ),
			'mid_size'  => 2,
		);

		printf( '<nav><div class="md-ccm-pagination__default">%s</div></nav>', wp_kses( paginate_links( $args ), $allowed_tags ) );
	}

	/**
	 * Check if the content queue feature is enabled.
	 *
	 * @return bool True if the content queue is enabled, false otherwise.
	 */
	public static function is_content_queue_enabled() {
		$centralized_content_management_settings  = get_option( 'centralized_content_management_settings' );
		$content_queue_enabled = isset( $centralized_content_management_settings['post_approval'] ) && 1 === (int) $centralized_content_management_settings['post_approval'] ? true : false;

		return $content_queue_enabled;
	}

	/**
	 * Check if the post type is allowed for syncing.
	 *
	 * @param string $post_type The post type to check.
	 * @return bool True if the post type is allowed for syncing, false otherwise.
	 */
	public static function is_post_type_allowed_for_sync( $post_type ) {
		if ( empty( $post_type ) ) {
			return false;
		}

		// Get post types allowed for sync from the settings
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$allowed_post_types   = isset( $centralized_content_management_settings['post_types'] ) ? (array) $centralized_content_management_settings['post_types'] : array();

		return in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Send Emails
	 *
	 * @param string|array $to Recipients Email address
	 * @param string $subject Email Subject
	 * @param string $body Email Body
	 */
	public static function centralized_content_management_send_email( $to, $subject, $body ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $to, $subject, $body, $headers ); // phpcs:ignore
	}

	/**
	 * Retrieves the admin email address for a given site ID.
	 *
	 * @param int $site_id The ID of the site to retrieve the admin email for. Default is 1 (central site).
	 * @return string The admin email address for the specified site.
	 * @since 1.0.0
	 */
	public static function centralized_content_management_get_admin_email( $site_id = 1 ) {
		// get admin email from site id.
		$admin_email = get_blog_option( $site_id, 'admin_email' );

		return $admin_email;
	}

	/**
	 * Get blogname by blog_id.
	 */
	public static function get_blogname( $blog_id ) {
		$blog_details = get_blog_details(
			array(
				'blog_id' => $blog_id,
			)
		);

		return $blog_details->blogname;
	}

	/**
	 * Function is used to check content queue is empty or not.
	 */
	public static function check_content_queue_entries() {
		global $wpdb;

		$centralized_content_manager_network_settings = self::get_network_settings();
		$sync_subsites        = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : 0;
		$has_queue_data       = false;
		$pending_rows         = array();

		if ( ! empty( $sync_subsites ) ) {
			foreach ( $sync_subsites as $sync_subsite ) {
				$subsite_id = $sync_subsite;

				// Dynamically construct the table name.
				$table_name = $wpdb->get_blog_prefix( $subsite_id ) . 'ccm_subsite_queue';

				$sync_status = 'pending';
				$results     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "SELECT * FROM $table_name WHERE sync_status= %s", $sync_status ),
					ARRAY_A
				);

				// If rows are found, store them in the pending_rows array.
				if ( ! empty( $results ) ) {
					$pending_rows = array_merge( $pending_rows, $results );
				}
			}

			// Check if we have any rows with pending status across subsites.
			if ( count( $pending_rows ) > 0 ) {
				$has_queue_data = true;
			}
		}

		return $has_queue_data;
	}

	/**
	 * Funcstion is used to render plugin footer.
	 */
	public static function centralized_content_management_footer() {
		?>
		<div class="md-ccm-footer">
			<p>
				<?php
					// translators: %1$s is the company name, %2$s is the platform (e.g., WordPress)
					echo sprintf(
						'Crafted by the experts at %1$s, designed for professionals who build with %2$s.',
						'<a href="https://www.multidots.com/" target="_blank">Multidots</a>',
						'WordPress'
					);

				?>
			</p>
		</div>
		<?php
	}
}

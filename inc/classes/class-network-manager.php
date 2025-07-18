<?php
/**
 * Network Management functionality for the MD Centralized Content Management plugin.
 *
 * This class manages network-level settings and synchronization options
 * for centralized content across subsites in a WordPress Multisite environment.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/admin
 * @author     Multidots <info@multidots.com>
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Main class file.
 */
class Network_Manager {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->setup_network_admin_hooks();
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_network_admin_hooks() {
		add_action( 'network_admin_menu', array( $this, 'centralized_content_management_network_settings_page' ) );
		add_action( 'wp_ajax_centralized_content_management_save_network_settings', array( $this, 'centralized_content_management_save_network_settings' ) );
		add_action( 'wp_ajax_get_subsites', array( $this, 'centralized_content_management_get_subsites_ajax_callback' ) );
	}

	/**
	 * Function is used to create plugin page to network.
	 */
	public function centralized_content_management_network_settings_page() {
		add_menu_page(
			__( 'Centralized Content Management', 'centralized-content-management' ),
			__( 'Centralized Content Management', 'centralized-content-management' ),
			'manage_network_options',
			'md-ccm-network-settings',
			array( $this, 'centralized_content_management_network_settings_page_content' ),
			CENTRALIZED_CONTENT_MANAGEMENT_LOGO_ICON,
			2
		);
	}

	/**
	 * Function to render the network setting page content.
	 */
	public function centralized_content_management_network_settings_page_content() {
		$centralized_content_manager_network_settings = get_network_option( null, 'centralized_content_manager_network_settings' );
		$central_site         = isset( $centralized_content_manager_network_settings['central_site'] ) ? $centralized_content_manager_network_settings['central_site'] : 0;
		$selected_subsites    = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();
		$subsites             = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'public'     => 1,
			)
		);

		?>
		<div class="md-ccm-wrap">
			<div id="md-ccm-header" class="md-ccm-header" style="position: static; width: auto;">
				<div class="md-ccm-header__left">
					<a href="#" target="_blank" class="main-logo"> <img src="<?php echo esc_url( CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/src/images/ccm-logo.svg' ); //phpcs:ignore ?>" width="130" height="75" class="md-ccm-logo" alt="md logo"> </a>
				</div>
				<div class="md-ccm-header__right">
					<a href="https://www.multidots.com/" target="_blank" class="md-logo"> <img src="<?php echo esc_url( CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/src/images/MD-Logo.svg' ); //phpcs:ignore ?>" width="130" height="75" class="md-ccm-header__logo" alt="md logo"> </a>
				</div>
			</div>
			<div class="md-ccm-desc">
				<p><?php esc_html_e( 'Set up the synchronization options for your network here. Choose the central site for syncing and select the subsites that will receive the synced content. Manage how content flows between the central site and subsites to keep your network in sync.', 'centralized-content-management' ); ?></p>
			</div>
			<div class="md-ccm-body">
				<div class="md-ccm-body__network-wrap">
					<form id="ccm-network-settings-form" method="post">
						<?php wp_nonce_field( 'centralized_content_manager_network_settings_nonce_action', 'centralized_content_manager_network_settings_nonce' ); ?>
						<h2 class="title"><?php esc_html_e( 'Network Site Settings', 'centralized-content-management' ); ?></h2>

						<table class="form-table ccm-form-table">
							<tr valign="top">
								<th scope="row">
									<label for="central_site"><?php esc_html_e( 'Central Site*', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<select name="central_site" id="central_site">
										<option value=""><?php esc_html_e( 'Select Central Site', 'centralized-content-management' ); ?></option>
										<?php
										foreach ( $subsites as $site ) {
											?>
											<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $central_site, $site->blog_id ); ?>>
												<?php echo esc_html( get_blog_option( $site->blog_id, 'blogname' ) ); ?>
											</option>
											<?php
										}
										?>
									</select>
									<p class="description"><i><?php esc_html_e( 'Select one of the subsites to be designated as the Central Site. This site will act as the primary source for content synchronization across other subsites.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr valign="top" id="subsites-row" style="display: <?php echo ! empty( $central_site ) ? esc_attr( 'table-row' ) : esc_attr( 'none' ); ?>;">
								<th scope="row">
									<label><?php esc_html_e( 'Subsites for Sync', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-group">
										<?php
										if ( ! empty( $subsites ) && is_array( $subsites ) ) {
											foreach ( $subsites as $site ) {
												if ( $central_site === (int) $site->blog_id ) {
													continue;
												}
												?>
												<div class="ccm-checkbox-wrap subsite-item">
													<label for="<?php echo esc_attr( 'site' . $site->blog_id ); ?>">
														<input type="checkbox" name="sync_subsites[]" class="ccm-checkbox" id="<?php echo esc_attr( 'site' . $site->blog_id ); ?>" value="<?php echo esc_attr( $site->blog_id ); ?>" <?php checked( in_array( $site->blog_id, $selected_subsites ) ); ?> />
														<span class="ccm-switch"></span>
														<span><?php echo esc_html( get_blog_option( $site->blog_id, 'blogname' ) ); ?></span>
													</label>
												</div>
												<?php
											}
										}
										?>
									</div>
									<p class="description"><i><?php esc_html_e( 'Choose which subsites should participate in the synchronization process. These selected subsites will receive the content and settings synced from the central site.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'centralized-content-management' ); ?></button>
							<span class="spinner" style="float:none; margin: 0 0 3px 5px; visibility: hidden;"></span>
						</p>
						<div id="ccm-notice"></div>
					</form>
				</div>
			</div>
			<?php Utils::centralized_content_management_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Function is used to handle network settings form submission using ajax.
	 */
	public function centralized_content_management_save_network_settings() {
		$nonce          = filter_input( INPUT_POST, 'centralized_content_manager_network_settings_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		$central_site   = filter_input( INPUT_POST, 'central_site', FILTER_VALIDATE_INT );

		$sync_subsites = filter_input( INPUT_POST, 'sync_subsites', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		$sync_subsites = is_array( $sync_subsites ) ? array_map( 'absint', $sync_subsites ) : array();

		$has_queue_data = Utils::check_content_queue_entries();

		// If $post_approval is not checked (0) and $has_queue_data is true, prevent unchecking.
		if ( Utils::is_content_queue_enabled() && $has_queue_data ) {
			wp_send_json_error(
				array(
					'message'    => __( 'There are posts in the content queue. Please clear the queue before turning off this setting.', 'centralized-content-management' ),
					'success'    => false,
					'is_checked' => true,
				)
			);
		}

		// Check the nonce.
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'centralized_content_manager_network_settings_nonce_action' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce verification failed!', 'centralized-content-management' ),
					'success' => false,
				)
			);
		}

		// Validate the central site field.
		if ( empty( $central_site ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Central Site is required.', 'centralized-content-management' ),
					'success' => false,
				)
			);
		}

		// Save the options to the network settings.
		$centralized_content_manager_network_settings = array(
			'central_site'  => $central_site,
			'sync_subsites' => $sync_subsites,
		);

		// Retrieve existing network settings.
		$network_settings = get_network_option( null, 'centralized_content_manager_network_settings', array() );

		// Get the list of all previous central sites if it exists, or initialize an empty array.
		$all_central_sites = isset( $network_settings['all_central_sites'] ) ? $network_settings['all_central_sites'] : array();

		// Add the current central site to the list, ensuring no duplicates.
		if ( ! in_array( $central_site, $all_central_sites, true ) ) {
			$all_central_sites[] = $central_site;
		}

		// Update the settings array with the updated list of all central sites.
		$centralized_content_manager_network_settings['all_central_sites'] = $all_central_sites;

		// Save the updated network settings in one call.
		update_network_option( null, 'centralized_content_manager_network_settings', $centralized_content_manager_network_settings );

		// Generate API keys for each subsite.
		if ( ! empty( $sync_subsites ) ) {
			foreach ( $sync_subsites as $subsite_id ) {
				$this->generate_api_key( $subsite_id );
			}

			// Generate API key for the central site.
			$this->generate_api_key( $central_site );
		}

		// Create the sync logs table on the central site.
		if ( $central_site ) {
			$this->create_ccm_sync_logs_table( $central_site );
		}

		// Return success response.
		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully!', 'centralized-content-management' ),
				'success' => true,
			)
		);
	}

	/**
	 * AJAX callback to get the list of subsites excluding the selected central site.
	 *
	 * This function is called via AJAX when a central site is selected,
	 * and it returns the HTML for the subsites available for synchronization.
	 */
	public function centralized_content_management_get_subsites_ajax_callback() {
		// Initialize response array.
		$response = array();

		// Get the central site ID from the AJAX request, ensuring it's an integer.
		$central_site_id = filter_input( INPUT_POST, 'central_site_id', FILTER_VALIDATE_INT );

		// Get the netwrok settings.
		$centralized_content_manager_network_settings = get_network_option( null, 'centralized_content_manager_network_settings' );
		$selected_subsites    = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();

		// Check if a valid central site ID is provided.
		if ( empty( $central_site_id ) ) {
			$response['success'] = false;
			$response['message'] = __( 'Invalid central site ID.', 'centralized-content-management' );

			// Send an error response and terminate script execution.
			wp_send_json_error( $response );
		}

		// Fetch all subsites in the network.
		$subsites = get_sites();

		// Initialize an output buffer to capture the generated HTML.
		ob_start();
		?>
		<div class="ccm-checkbox-group">
			<?php
			// Loop through each subsite and generate the HTML for the checkboxes.
			foreach ( $subsites as $subsite ) {
				// Skip the selected central site.
				if ( $subsite->blog_id == $central_site_id ) {
					continue;
				}
				?>
				<div class="ccm-checkbox-wrap subsite-item">
					<label for="site<?php echo esc_attr( $subsite->blog_id ); ?>">
						<input type="checkbox" name="sync_subsites[]" class="ccm-checkbox" id="site<?php echo esc_attr( $subsite->blog_id ); ?>" value="<?php echo esc_attr( $subsite->blog_id ); ?>" <?php checked( in_array( $subsite->blog_id, $selected_subsites ) ); ?> />
						<span class="ccm-switch"></span>
						<span><?php echo esc_html( get_blog_option( $subsite->blog_id, 'blogname' ) ); ?></span>
					</label>
				</div>
				<?php
			}
			// Append the description after the list of subsites.
			?>
		</div>
		<p class="description">
			<i><?php esc_html_e( 'Choose which subsites should participate in the synchronization process. These selected subsites will receive the content and settings synced from the central site.', 'centralized-content-management' ); ?></i>
		</p>
		<?php

		// Capture the generated HTML from the output buffer and store it in the response array.
		$response['html'] = ob_get_clean();

		// Send a success response with the generated HTML.
		wp_send_json_success( $response );
	}

	/**
	 * Generates an API key for a given blog ID if it doesn't already exist.
	 *
	 * @param int $blog_id The ID of the blog for which to generate the API key.
	 * @return string The generated or existing API key for the specified blog ID.
	 */
	private function generate_api_key( $blog_id ) {
		$api_keys = get_network_option( null, 'centralized_content_management_api_keys', array() );
		if ( ! isset( $api_keys[ $blog_id ] ) ) {
			$api_keys[ $blog_id ] = wp_generate_password( 32, false );

			update_network_option( null, 'centralized_content_management_api_keys', $api_keys );
		}

		return $api_keys[ $blog_id ];
	}

	/**
	 * Creates the `ccm_sync_logs` table for a specified site in the WordPress Multisite network.
	 *
	 * @param int $site_id The ID of the site where the table should be created.
	 */
	public function create_ccm_sync_logs_table( $site_id ) {
		global $wpdb;

		// Get the charset and collation settings for the database.
		$charset_collate = $wpdb->get_charset_collate();

		// Use the site-specific table prefix to target the correct site
		$table_name = $wpdb->get_blog_prefix( $site_id ) . 'ccm_sync_logs';

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_name text NOT NULL,
			sync_sites longtext NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

}

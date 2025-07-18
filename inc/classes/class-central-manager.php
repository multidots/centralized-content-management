<?php
/**
 * Central management functionality for the MD Centralized Content Management plugin.
 *
 * This class handles the core logic and operations for managing content
 * across multiple subsites within the WordPress multisite network. It
 * provides methods for synchronizing post types, taxonomies, media,
 * user associations, and other content-related functionalities.
 * The Central Manage class acts as the main controller for the plugin,
 * coordinating the interactions between the central site and its subsites.
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
class Central_Manager {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Check if the current site is the central site.
		if ( Utils::is_central_site() ) {
			$this->setup_central_admin_hooks();
		}
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_central_admin_hooks() {
		add_action( 'admin_menu', array( $this, 'centralized_content_management_add_plugin_page_to_central_site' ) );
		add_action(
			'admin_menu',
			function() {
				remove_submenu_page( 'md-ccm', 'md-ccm' );
			},
			999
		);
		add_action( 'wp_ajax_centralized_content_management_save_central_site_settings', array( $this, 'centralized_content_management_save_central_site_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'centralized_content_management_add_sync_subsites_metabox_to_central' ) );
		add_action( 'save_post', array( $this, 'centralized_content_management_sync_post_to_subsites' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'centralized_content_management_trashed_post' ), 20, 1 );
		add_action( 'untrashed_post', array( $this, 'centralized_content_management_untrashed_post' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'centralized_content_management_delete_post' ), 20, 2 );

		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$sync_post_types      = isset( $centralized_content_management_settings['post_types'] ) ? (array) $centralized_content_management_settings['post_types'] : array();
		if ( ! empty( $sync_post_types ) ) {
			foreach ( $sync_post_types as $sync_post_type ) {
				add_filter( 'manage_' . $sync_post_type . '_posts_columns', array( $this, 'centralized_content_management_add_custom_column_to_sync_post_types' ) );
				add_action( 'manage_' . $sync_post_type . '_posts_custom_column', array( $this, 'centralized_content_management_populate_custom_column_data' ), 10, 2 );
			}
		}
	}

	/**
	 * Function is used to create plugin page to central site.
	 */
	public function centralized_content_management_add_plugin_page_to_central_site() {
		$central_site_id = Utils::get_central_site_id();
		$current_site_id = get_current_blog_id();

		// Check if the current site is the selected central site.
		if ( $current_site_id === $central_site_id ) {
			// MD CCM page.
			add_menu_page(
				__( 'Centralized Content Management', 'centralized-content-management' ),
				__( 'Centralized Content Management', 'centralized-content-management' ),
				'manage_options',
				'md-ccm',
				'__return_null',
				CENTRALIZED_CONTENT_MANAGEMENT_LOGO_ICON,
				2
			);

			// Sync settings page.
			add_submenu_page(
				'md-ccm',
				'Sync Settings',
				'Sync Settings',
				'manage_options',
				'md-ccm-sync-settings',
				array( $this, 'centralized_content_management_central_site_settings_page_content' ),
				2
			);
		}
	}

	/**
	 * Function to render the central setting page content.
	 */
	public function centralized_content_management_central_site_settings_page_content() {
		// Check if the user is allowed to update options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get central settings option data.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
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
				<p><?php esc_html_e( 'Manage the sync settings for your central site here. Configure which post types, taxonomies, and media should be synchronized across subsites. Customize the sync options to control how content is managed and shared within your network.', 'centralized-content-management' ); ?></p>
			</div>
			<div class="md-ccm-body">
				<div class="nav-tab-wrapper">
					<?php
					if ( Utils::is_central_site() ) { // VT - 20241112.
						?>
						<div class="nav-tab-item nav-tab-active">
							<a href="?page=md-ccm-sync-settings" class="nav-tab"><span><?php esc_html_e( 'Sync Settings', 'centralized-content-management' ); ?></span></a>
						</div>
						<div class="nav-tab-item">
							<a href="?page=md-ccm-bulk-sync" class="nav-tab"><span><?php esc_html_e( 'Bulk Sync', 'centralized-content-management' ); ?></span></a>
						</div>
						<?php
					}
					?>
					<div class="nav-tab-item">
						<a href="?page=md-ccm-sync-logs" class="nav-tab"><span><?php esc_html_e( 'Sync Logs', 'centralized-content-management' ); ?></span></a>
					</div>
				</div>
				<div class="md-ccm-body__tab-content">
					<form id="ccm-central-settings-form" method="post">
						<?php wp_nonce_field( 'centralized_content_management_settings_nonce_action', 'centralized_content_management_settings_nonc' ); ?>
						<table class="form-table ccm-form-table">
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Post Types for Sync', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-group">
										<?php
										$post_types_selected = isset( $centralized_content_management_settings['post_types'] ) && is_array( $centralized_content_management_settings['post_types'] ) ? $centralized_content_management_settings['post_types'] : array();
										$post_types          = get_post_types( array( 'public' => true ), 'objects' );
										if ( is_array( $post_types ) && ! empty( $post_types ) ) {
											foreach ( $post_types as $post_type ) {
												// Skip the iteration if the post type is 'attachment'.
												if ( 'attachment' === $post_type->name ) {
													continue;
												}

												// Check if the post_types are already selected.
												$post_type_checked = in_array( $post_type->name, $post_types_selected, true ) ? 'checked="checked"' : '';
												?>
												<div class="ccm-checkbox-wrap">
													<label for="<?php echo esc_attr( $post_type->name ); ?>">
														<input type="checkbox" name="ccm_post_types[]" id="<?php echo esc_attr( $post_type->name ); ?>" class="ccm-checkbox" value="<?php echo esc_attr( $post_type->name ); ?>" <?php echo esc_attr( $post_type_checked ); ?>>
														<span class="ccm-switch"></span>
														<span><?php echo esc_html( $post_type->label ); ?></span>
													</label>
												</div>
												<?php
											}
										}
										?>
									</div>
									<p class="description"><i><?php esc_html_e( 'Select the post types that you want to synchronize across the subsites. This allows you to manage which type of content are included in the synchronization process.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Taxonomies for Sync', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-group">
										<?php
										$taxonomies_selected = isset( $centralized_content_management_settings['taxonomies'] ) && is_array( $centralized_content_management_settings['taxonomies'] ) ? $centralized_content_management_settings['taxonomies'] : array();
										$taxonomies          = get_taxonomies( array( 'public' => true ), 'objects' );
										if ( is_array( $taxonomies ) && ! empty( $taxonomies ) ) {
											foreach ( $taxonomies as $taxonomy ) {
												// Check if the taxonomyies are already selected.
												$taxonomy_checked = in_array( $taxonomy->name, $taxonomies_selected, true ) ? 'checked="checked"' : '';
												?>
												<div class="ccm-checkbox-wrap">
													<label for="<?php echo esc_attr( $taxonomy->name ); ?>">
														<input type="checkbox" name="ccm_taxonomies[]" class="ccm-checkbox" id="<?php echo esc_attr( $taxonomy->name ); ?>" value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php echo esc_attr( $taxonomy_checked ); ?>>
														<span class="ccm-switch"></span>
														<span><?php echo esc_html( $taxonomy->label ); ?></span>
													</label>
												</div>
												<?php
											}
										}
										?>
									</div>
									<p class="description"><i><?php esc_html_e( 'Select the taxonomies to be synchronized. Taxonomies help in organizing and categorizing content, and selecting them here will ensure they are synced across subsites.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Sync Post Meta', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_sync_post_meta">
											<input type="checkbox" name="ccm_sync_post_meta" id="ccm_sync_post_meta" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['sync_post_meta'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Enable Syncing of Post Meta', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( 'Enable or disable the synchronization of post meta information. Post meta includes additional data associated with posts, such as custom fields.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Sync Post Media', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_sync_media">
											<input type="checkbox" name="ccm_sync_media" id="ccm_sync_media" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['sync_media'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Enable Syncing of Media', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( 'Enable or disable this option to synchronize media files (like images, videos, etc.) across subsites. When enabled, media uploads on the central site will be mirrored on all subsites.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Sync User Associations', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_sync_users">
											<input type="checkbox" name="ccm_sync_users" id="ccm_sync_users" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['sync_users'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Enable Syncing of User Associations', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( 'Enable or disable this option to sync user associations with posts. This ensures that users associated with posts on the central site are also associated with those posts on the subsites.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Allow Subsite Post Content Modification', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_allow_modification">
											<input type="checkbox" name="ccm_allow_modification" id="ccm_allow_modification" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['allow_modification'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Allow Subsite to Modify Synced Content', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( 'Decide whether subsites are allowed to modify content that has been synced from the central site. If enabled, subsites can make changes to the synced content.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Delete Post Content on Subsites', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_delete_on_subsite">
											<input type="checkbox" name="ccm_delete_on_subsite" id="ccm_delete_on_subsite" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['delete_on_subsite'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Delete Content in Subsites when Deleted in Central Site', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( "Select this option to allow deletion of content on subsites if it is deleted from the central site. This ensures consistency by removing content across all sites when it's deleted centrally.", 'centralized-content-management' ); ?></i></p>
									<p class="description"><strong><?php esc_html_e( 'Note: ', 'centralized-content-management' ); ?></strong><?php esc_html_e( 'This feature will not work with posts synced via bulk sync. It will only work with single sync.', 'centralized-content-management' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Post Approval', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-wrap">
										<label for="ccm_post_approval">
											<input type="checkbox" name="ccm_post_approval" id="ccm_post_approval" class="ccm-checkbox" value="1" <?php checked( $centralized_content_management_settings['post_approval'] ?? 0, 1 ); ?>>
											<span class="ccm-switch"></span>
											<span><?php esc_html_e( 'Post Approval', 'centralized-content-management' ); ?></span>
										</label>
									</div>
									<p class="description"><i><?php esc_html_e( 'Enable post approval to review and approve content changes before they are synced across subsites.', 'centralized-content-management' ); ?></i></p>
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
	 * Function to handle central site settings form submission using AJAX.
	 */
	public function centralized_content_management_save_central_site_settings() {
		// Check the nonce.
		$nonce = filter_input( INPUT_POST, 'centralized_content_management_settings_nonc', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'centralized_content_management_settings_nonce_action' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce verification failed!', 'centralized-content-management' ),
					'success' => false,
				)
			);
		}

		// Sanitize inputs.
		// For arrays
		$post_types         = filter_input( INPUT_POST, 'ccm_post_types', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		$post_types         = isset( $post_types ) ? array_map( 'sanitize_text_field', wp_unslash( $post_types ) ) : array();

		$taxonomies         = filter_input( INPUT_POST, 'ccm_taxonomies', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		$taxonomies         = isset( $taxonomies ) ? array_map( 'sanitize_text_field', wp_unslash( $taxonomies ) ) : array();

		// For flags (1 or 0)
		$sync_post_meta     = filter_input( INPUT_POST, 'ccm_sync_post_meta', FILTER_VALIDATE_INT ) ? 1 : 0;
		$sync_media         = filter_input( INPUT_POST, 'ccm_sync_media', FILTER_VALIDATE_INT ) ? 1 : 0;
		$sync_users         = filter_input( INPUT_POST, 'ccm_sync_users', FILTER_VALIDATE_INT ) ? 1 : 0;
		$allow_modification = filter_input( INPUT_POST, 'ccm_allow_modification', FILTER_VALIDATE_INT ) ? 1 : 0;
		$delete_on_subsite  = filter_input( INPUT_POST, 'ccm_delete_on_subsite', FILTER_VALIDATE_INT ) ? 1 : 0;
		$post_approval      = filter_input( INPUT_POST, 'ccm_post_approval', FILTER_VALIDATE_INT ) ? 1 : 0;

		$has_queue_data     = Utils::check_content_queue_entries();

		// If $post_approval is not checked (0) and $has_queue_data is true, prevent unchecking.
		if ( ! $post_approval && $has_queue_data ) {
			wp_send_json_error(
				array(
					'message'    => __( 'There are posts in the content queue. Please clear the queue before turning off this setting.', 'centralized-content-management' ),
					'success'    => false,
					'is_checked' => true,
				)
			);
		}

		// Ensure $post_approval remains checked (1) if queue data exists.
		if ( $has_queue_data ) {
			$post_approval = 1;
		}

		// Retrieve the existing settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );

		// Update settings array.
		$centralized_content_management_settings = array(
			'post_types'         => $post_types,
			'taxonomies'         => $taxonomies,
			'sync_post_meta'     => $sync_post_meta,
			'sync_media'         => $sync_media,
			'sync_users'         => $sync_users,
			'allow_modification' => $allow_modification,
			'delete_on_subsite'  => $delete_on_subsite,
			'post_approval'      => $post_approval,
		);

		// Save the updated settings.
		update_option( 'centralized_content_management_settings', $centralized_content_management_settings );

		// Create DB tables.
		$this->create_db_tables();

		// Save selected central setting's post types data to subsite option table.
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		if ( isset( $centralized_content_manager_network_settings['sync_subsites'] ) && ! empty( $centralized_content_manager_network_settings['sync_subsites'] ) ) {
			$sync_subsites = $centralized_content_manager_network_settings['sync_subsites'];
			$summary       = array();

			foreach ( $sync_subsites as $sync_subsite ) {
				update_blog_option( $sync_subsite, 'central_setting_data', $centralized_content_management_settings );
			}
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
	 * Add a custom meta box for post types in central settings.
	 */
	public function centralized_content_management_add_sync_subsites_metabox_to_central() {
		global $post;

		// Retrieve the post types from the central settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		$ccm_post_types       = isset( $centralized_content_management_settings['post_types'] ) ? $centralized_content_management_settings['post_types'] : array();
		$current_blog_id      = get_current_blog_id();

		if ( ( isset( $centralized_content_manager_network_settings['central_site'] ) && ! empty( $centralized_content_manager_network_settings['central_site'] ) && $current_blog_id === $centralized_content_manager_network_settings['central_site'] ) && ! empty( $ccm_post_types ) ) {
			add_meta_box(
				'sync_subsites_metabox',
				__( 'Sync Subsites', 'centralized-content-management' ),
				array( $this, 'centralized_content_management_render_sync_subsites_metabox_render' ),
				$ccm_post_types,
				'side',
				'high'
			);

			add_meta_box(
				'disable_sync_post_metabox',
				__( 'Disable Sync', 'centralized-content-management' ),
				array( $this, 'centralized_content_management_disable_sync_metabox_render' ),
				$ccm_post_types,
				'side',
				'low'
			);

			// Metabox will appear only if $synced_subsite_data is not empty.
			$synced_subsite_data = get_post_meta( $post->ID, '_synced_subsite_data', true );
			if ( ! empty( $synced_subsite_data ) ) {
				add_meta_box(
					'sync_subsite_status_metabox',
					__( 'Sync Status Indicator', 'centralized-content-management' ),
					array( $this, 'centralized_content_management_render_sync_subsites_status_metabox_render' ),
					$ccm_post_types,
					'normal',
					'high'
				);
			}
		}
	}

	/**
	 * Renders the content of the "Sync Subsites" meta box on the post edit screen.
	 *
	 * This function retrieves the selected subsites for the post, fetches the list of available subsites
	 * from the network settings, and displays checkboxes for each subsite so the user can choose which
	 * subsites to sync content with. The selected subsites are stored as post meta data for the post.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function centralized_content_management_render_sync_subsites_metabox_render( $post ) {
		// Retrieve the saved subsites for this post.
		$selected_subsites    = get_post_meta( $post->ID, '_ccm_selected_subsites', true );
		$centralized_content_manager_network_settings = Utils::get_network_settings();

		if ( isset( $centralized_content_manager_network_settings['sync_subsites'] ) && ! empty( $centralized_content_manager_network_settings['sync_subsites'] ) ) {
			foreach ( $centralized_content_manager_network_settings['sync_subsites'] as $the_subsite_id ) {
				$the_subsite_name = Utils::get_blogname( $the_subsite_id );

				// Check if the term is already selected.
				$subsite_checked = in_array( $the_subsite_id, (array) $selected_subsites, true ) ? 'checked="checked"' : '';
				?>
				<div class="ccm-checkbox-wrap subsite-item">
					<label for="<?php echo esc_attr( 'site' . $the_subsite_id ); ?>">
						<input type="checkbox" name="ccm_selected_subsites[]" id="<?php echo esc_attr( 'site' . $the_subsite_id ); ?>" value="<?php echo esc_attr( $the_subsite_id ); ?>" <?php echo esc_attr( $subsite_checked ); ?> />
						<span class="ccm-switch"></span>
						<span><?php echo esc_html( $the_subsite_name ); ?></span>
					</label>
				</div>
				<?php
			}
		}
	}

	/**
	 * Renders the content of the "Sync Status" meta box on the post edit screen.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function centralized_content_management_render_sync_subsites_status_metabox_render( $post ) {
		$synced_subsite_data = get_post_meta( $post->ID, '_synced_subsite_data', true );
		?>
		<div class="sync-subsites-status-indicator-wrap">
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site Name', 'centralized-content-management' ); ?></th>
						<th><?php esc_html_e( 'Last Sync Time', 'centralized-content-management' ); ?></th>
						<th width="10%"><?php esc_html_e( 'Sync Status', 'centralized-content-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $synced_subsite_data ) ) {
						foreach ( $synced_subsite_data as $sync_data ) {
							// Retirve sync data.
							$sync_data   = isset( $sync_data['log_data'] ) ? $sync_data['log_data'] : array();
							$site_name   = isset( $sync_data['site_name'] ) ? $sync_data['site_name'] : 'Unknown Post';
							$sync_time   = isset( $sync_data['sync_time'] ) ? $sync_data['sync_time'] : '';
							$sync_status = isset( $sync_data['sync_status'] ) ? $sync_data['sync_status'] : 'Unknown';
							?>
							<tr>
								<td><?php echo esc_html( $site_name ); ?></td>
								<td><?php echo esc_html( $sync_time ); ?></td>
								<td><?php echo esc_html( $sync_status ); ?></td>
							</tr>
							<?php
						}
					} else {
						?>
						<tr>
							<td colspan="3" align="center"><?php esc_html_e( 'No data found', 'centralized-content-management' ); ?></td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the content of the "Disable Sync" meta box on the post edit screen.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function centralized_content_management_disable_sync_metabox_render( $post ) {
		// Retrive sync disable setting.
		$disable_sync = get_post_meta( $post->ID, '_ccm_disable_sync', true );
		?>
		<div class="ccm-checkbox-wrap disable-sync">
			<label for="disable_sync_<?php echo esc_attr( $post->ID ); ?>">
				<input type="checkbox" name="ccm_disable_sync" value="1" class="ccm-checkbox" id="disable_sync_<?php echo esc_attr( $post->ID ); ?>" <?php echo 'true' === $disable_sync ? 'checked' : ''; ?> />
				<span class="ccm-switch"></span>
			</label>
			<p class="description"><?php esc_html_e( 'A button to disable sync for this particular post.', 'centralized-content-management' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Save the selected subsites when the post is saved and sync the post to those subsites.
	 *
	 * This function handles the saving of selected subsites for syncing when a post is saved.
	 * It checks nonce validation, user permissions, autosave status, and the allowed post types for syncing.
	 * If subsites are selected, it syncs the post to each subsite by sending post data via a REST API.
	 *
	 * @param int    $post_id The ID of the post being saved.
	 * @param object $post    The post object of the current post being saved.
	 */
	public function centralized_content_management_sync_post_to_subsites( $post_id, $post ) {
		// Check if doing autosave to avoid unintended execution during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check the user's permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Get post types allowed for sync from the settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		if ( ! isset( $post->post_type ) || ! Utils::is_post_type_allowed_for_sync( $post->post_type ) ) {
			return;
		}

		if ( isset( $post->post_status ) && 'trash' === $post->post_status ) {
			return;
		}

		if ( empty( $_POST ) ) { // phpcs:ignore
			return;
		}

		$selected_subsites = filter_input( INPUT_POST, 'ccm_selected_subsites', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		if ( $selected_subsites && is_array( $selected_subsites ) ) {
			$selected_subsites = array_map( 'intval', $selected_subsites );
		} else {
			$selected_subsites = array();
		}

		update_post_meta( $post_id, '_ccm_selected_subsites', $selected_subsites );


		// Sanitize and save the disable sync setting.
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS );
		$ccm_disable_sync = filter_input( INPUT_POST, 'ccm_disable_sync', FILTER_SANITIZE_SPECIAL_CHARS );

		if ( $action === 'inline-save' ) {
			$disable_sync_data = get_post_meta( $post_id, '_ccm_disable_sync', true );
			$disable_sync_data = ( isset( $disable_sync_data ) && 'true' === $disable_sync_data ) ? 'true' : 'false';
		} else {
			if ( $ccm_disable_sync === '1' ) {
				$disable_sync_data = 'true';
				update_post_meta( $post_id, '_ccm_disable_sync', $disable_sync_data );
			} else {
				$disable_sync_data = 'false';
				update_post_meta( $post_id, '_ccm_disable_sync', $disable_sync_data );
			}
		}


		// Check if content queue is enabled.
		if ( Utils::is_content_queue_enabled() ) {
			return;
		}

		// Get the selected subsites for syncing from the post's custom metabox.
		$selected_subsites = get_post_meta( $post_id, '_ccm_selected_subsites', true );
		if ( ! isset( $selected_subsites ) || empty( $selected_subsites ) ) {
			return;
		}

		// Prepare post data for syncing.
		$post_data = array(
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_status'       => $post->post_status,
			'post_type'         => $post->post_type,
			'post_name'         => $post->post_name,
			'post_date'         => $post->post_date,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified'     => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'central_post_id'   => $post_id,
			'selected_subsites' => $selected_subsites,
			'disable_sync'      => $disable_sync_data,
		);

		// Check if taxonomy syncing setting is enable or not.
		if ( isset( $centralized_content_management_settings['taxonomies'] ) && ! empty( $centralized_content_management_settings['taxonomies'] ) ) {
			$post_data['taxonomies'] = Sync_Process::centralized_content_management_get_taxonomies_for_post( $post_id );
		}

		// Check if post meta syncing setting is enable or not.
		if ( isset( $centralized_content_management_settings['sync_post_meta'] ) && ! empty( $centralized_content_management_settings['sync_post_meta'] ) ) {
			$post_data['meta_fields'] = array();
			$post_data['acf_fields']  = array();
			$meta_fields              = get_post_meta( $post_id, '', true );

			if ( ! empty( $meta_fields ) ) {
				$not_to_be_synced = array(
					'_edit_lock',
					'_edit_last',
					'_thumbnail_id',
					'_ccm_selected_subsites',
					'_ccm_disable_sync',
					'_synced_subsite_data',
				);

				$acf_relational_fields = array( 'link', 'post_object', 'taxonomy', 'user', 'relationship', 'page_link', 'file', 'image', 'gallery' );

				foreach ( $meta_fields as $meta_key => $meta_val ) {
					if ( false === in_array( $meta_key, $not_to_be_synced, true ) ) {

						if ( function_exists( 'get_field_object' ) ) {
							// Check if the field is ACF field.
							$acf_field_obj = get_field_object( $meta_key, $post_id, false );

							if ( ! empty( $acf_field_obj ) && isset( $acf_field_obj['type'] ) ) {

								$field_type = $acf_field_obj['type'];
								if ( in_array( $field_type, $acf_relational_fields, true ) ) {
									$post_data['acf_fields'][ $meta_key ] = Sync_Process::centralized_content_management_sync_prepare_acf_rel_data( $acf_field_obj, $field_type );
								} else {
									if ( '_yoast_wpseo_primary_category' === $meta_key && ! empty( $meta_val[0] ) ) {
										$primary_term_id = (int) $meta_val[0];
										$primary_cat     = get_term( $primary_term_id, 'category' );
										if ( ! empty( $primary_cat ) && ! is_wp_error( $primary_cat ) ) {
											$post_data['meta_fields']['_yoast_wpseo_primary_category_slug'] = $primary_cat->slug;
										} else {
											$post_data['meta_fields'][ $meta_key ] = $meta_val[0];
										}
									} else {
										$post_data['meta_fields'][ $meta_key ] = $meta_val[0];
									}
								}
							} else {
								$post_data['meta_fields'][ $meta_key ] = $meta_val[0];
							}
						} else {
							$post_data['meta_fields'][ $meta_key ] = $meta_val[0];
						}
					}
				}
			}

			$post_data['source_url'] = site_url();
		}

		// Prepare image data array.
		$central_image_id = get_post_thumbnail_id( $post_id );
		$upload_dir       = wp_get_upload_dir();
		$central_images   = array(
			'central_post_id' => $post_id,
			'thumbnail'       => array(),
			'upload_url'      => $upload_dir['url'],
			'baseurl'         => $upload_dir['baseurl'],
		);

		if ( ! empty( $central_image_id ) ) {
			$central_images['thumbnail'] = array(
				'id'           => $central_image_id,
				'filepath'     => get_attached_file( $central_image_id ),
				'url'          => wp_get_attachment_image_url( $central_image_id, 'full' ),
				'image_author' => get_post_field( 'post_author', $central_image_id ),
			);
		}

		// Check if media syncing setting is enable or not.
		if ( isset( $centralized_content_management_settings['sync_media'] ) && ! empty( $centralized_content_management_settings['sync_media'] ) ) {
			$post_data['central_images'] = $central_images;
			$post_data['content_media']  = Sync_Process::centralized_content_management_get_media_urls_and_ids_from_post_content( $post->post_content );
		}

		// Syncing of User Associations.
		if ( isset( $centralized_content_management_settings['sync_users'] ) && $centralized_content_management_settings['sync_users'] ) {
			$post_data['post_author'] = $post->post_author;
		}

		// Sync post data to selected subsites.
		Sync_Process::centralized_content_management_sync_process( $post_id, $selected_subsites, $post_data, 'single' );
	}

	/**
	 * Handles the trashing of a post on all synced subsites.
	 *
	 * This function is triggered when a post is moved to the trash on the central site.
	 * It sends a request to all synced subsites to trash the corresponding post.
	 *
	 * @param int $central_post_id The ID of the post on the central site.
	 */
	public function centralized_content_management_trashed_post( $central_post_id ) {
		// Check if doing autosave to avoid unintended execution during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check if content queue is enabled.
		if ( Utils::is_content_queue_enabled() ) {
			return;
		}

		// Get central settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$delete_on_subsite    = isset( $centralized_content_management_settings['delete_on_subsite'] ) ? $centralized_content_management_settings['delete_on_subsite'] : 0;

		// Get the central post by post ID.
		$central_post = get_post( $central_post_id );
		if ( ! $central_post ) {
			return;
		}

		// Get the synced subsite data from the central post's metadata.
		$synced_subsite_data = get_post_meta( $central_post_id, '_synced_subsite_data', true );

		if ( ! empty( $synced_subsite_data ) ) {
			// Initialize summary array to store results for each subsite.
			$summary = array();

			foreach ( $synced_subsite_data as $subsite_post_data ) {
				$subsite_blog_id    = isset( $subsite_post_data['current_site_id'] ) ? $subsite_post_data['current_site_id'] : 0;
				$trash_endpoint_url = get_rest_url( $subsite_blog_id ) . 'md-ccm/v1/trash-post';
				$api_key            = Utils::get_current_site_api_key( $subsite_blog_id );
				$subsite_post_id    = isset( $subsite_post_data['subsite_post_id'] ) ? $subsite_post_data['subsite_post_id'] : 0;
				$trash_post_data    = array(
					'subsite_post_id'   => $subsite_post_id,
					'delete_on_subsite' => $delete_on_subsite,
				);

				// Send the post data to the subsite using wp_remote_post.
				$response = wp_remote_post(
					$trash_endpoint_url,
					array(
						'body'    => wp_json_encode( $trash_post_data ),
						'headers' => array(
							'Content-Type' => 'application/json',
							'X-API-KEY'    => $api_key,
						),
						'timeout' => 10, // phpcs:ignore
					)
				);

				// Check api response.
				if ( is_wp_error( $response ) ) {
					$summary['error']         = true;
					$summary['message']       = __( 'Error trashing post on subsite.', 'centralized-content-management' );
					$summary['debug_message'] = sprintf(
						// Translators: %1$d is the subsite ID, %2$s is the error message.
						__( 'Error trashing post on subsite ID %1$d: %2$s', 'centralized-content-management' ),
						$subsite_blog_id,
						$response->get_error_message()
					);
				} else {
					// Get the response code and body.
					$response_code = wp_remote_retrieve_response_code( $response );

					if ( 200 === $response_code ) {
						$summary['error']   = false;
						$summary['message'] = __( 'Successfully trashed post on subsite.', 'centralized-content-management' );
					} else {
						$summary['error']   = true;
						$summary['message'] = sprintf(
							// Translators: %1$d is the subsite ID, %2$d is the HTTP response code.
							__( 'Error trashing post on subsite ID %1$d: HTTP Response Code %2$d', 'centralized-content-management' ),
							$subsite_blog_id,
							intval( $response_code )
						);
					}
				}
			}
		}
	}

	/**
	 * Handles the trashing of a post on all synced subsites.
	 *
	 * This function is triggered when a post is moved to the trash on the central site.
	 * It sends a request to all synced subsites to trash the corresponding post.
	 *
	 * @param int $central_post_id The ID of the post on the central site.
	 */
	public function centralized_content_management_untrashed_post( $central_post_id ) {
		// Check if doing autosave to avoid unintended execution during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Get central settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$delete_on_subsite    = isset( $centralized_content_management_settings['delete_on_subsite'] ) ? $centralized_content_management_settings['delete_on_subsite'] : 0;

		// Get the central post by post ID.
		$central_post = get_post( $central_post_id );
		if ( ! $central_post ) {
			return;
		}

		// Get the synced subsite data from the central post's metadata.
		$synced_subsite_data = get_post_meta( $central_post_id, '_synced_subsite_data', true );

		if ( ! empty( $synced_subsite_data ) ) {
			// Initialize summary array to store results for each subsite.
			$summary = array();

			foreach ( $synced_subsite_data as $subsite_post_data ) {
				$subsite_blog_id      = isset( $subsite_post_data['current_site_id'] ) ? $subsite_post_data['current_site_id'] : 0;
				$untrash_endpoint_url = get_rest_url( $subsite_blog_id ) . 'md-ccm/v1/untrash-post';
				$api_key              = Utils::get_current_site_api_key( $subsite_blog_id );
				$subsite_post_id      = isset( $subsite_post_data['subsite_post_id'] ) ? $subsite_post_data['subsite_post_id'] : 0;
				$untrash_post_data    = array(
					'subsite_post_id'   => $subsite_post_id,
					'delete_on_subsite' => $delete_on_subsite,
				);

				// Send the post data to the subsite using wp_remote_post.
				$response = wp_remote_post(
					$untrash_endpoint_url,
					array(
						'body'    => wp_json_encode( $untrash_post_data ),
						'headers' => array(
							'Content-Type' => 'application/json',
							'X-API-KEY'    => $api_key,
						),
						'timeout' => 10, // phpcs:ignore
					)
				);

				if ( is_wp_error( $response ) ) {
					$summary['error']         = true;
					$summary['message']       = __( 'Error trashing post on subsite.', 'centralized-content-management' );
					$summary['debug_message'] = sprintf(
						// Translators: %1$d is the subsite ID, %2$s is the error message from the response.
						__( 'Error trashing post on subsite ID %1$d: %2$s', 'centralized-content-management' ),
						$subsite_blog_id,
						$response->get_error_message()
					);
				} else {
					// Get the response code and body.
					$response_code = wp_remote_retrieve_response_code( $response );

					if ( 200 === $response_code ) {
						$summary['error']   = false;
						$summary['message'] = __( 'Successfully untrashed post on subsite.', 'centralized-content-management' );
					} else {
						$summary['error']   = true;
						$summary['message'] = sprintf(
							// Translators: %1$d is the subsite ID, %2$d is the HTTP response code.
							__( 'Error untrashing post on subsite ID %1$d: HTTP Response Code %2$d', 'centralized-content-management' ),
							$subsite_blog_id,
							intval( $response_code )
						);
					}
				}
			}
		}
	}

	/**
	 * Handles the deleting of a post on all synced subsites.
	 *
	 * This function is triggered when a post is moved to the trash on the central site.
	 * It sends a request to all synced subsites to trash the corresponding post.
	 *
	 * @param int $central_post_id The ID of the post on the central site.
	 */
	public function centralized_content_management_delete_post( $central_post_id ) {
		// Check if doing autosave to avoid unintended execution during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Get central settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$delete_on_subsite    = isset( $centralized_content_management_settings['delete_on_subsite'] ) ? $centralized_content_management_settings['delete_on_subsite'] : 0;

		// Get the central post by post ID.
		$central_post = get_post( $central_post_id );
		if ( ! $central_post ) {
			return;
		}

		// Get the synced subsite data from the central post's metadata.
		$synced_subsite_data = get_post_meta( $central_post_id, '_synced_subsite_data', true );

		if ( ! empty( $synced_subsite_data ) ) {
			// Initialize summary array to store results for each subsite.
			$summary = array();

			foreach ( $synced_subsite_data as $subsite_post_data ) {
				$subsite_blog_id     = isset( $subsite_post_data['current_site_id'] ) ? $subsite_post_data['current_site_id'] : 0;
				$delete_endpoint_url = get_rest_url( $subsite_blog_id ) . 'md-ccm/v1/delete-post';
				$api_key             = Utils::get_current_site_api_key( $subsite_blog_id );
				$subsite_post_id     = isset( $subsite_post_data['subsite_post_id'] ) ? $subsite_post_data['subsite_post_id'] : 0;
				$delete_post_data    = array(
					'subsite_post_id'   => $subsite_post_id,
					'delete_on_subsite' => $delete_on_subsite,
				);

				// Send the post data to the subsite using wp_remote_request.
				$response = wp_remote_post(
					$delete_endpoint_url,
					array(
						'body'    => wp_json_encode( $delete_post_data ),
						'headers' => array(
							'Content-Type' => 'application/json',
							'X-API-KEY'    => $api_key,
						),
						'timeout' => 10, // phpcs:ignore
					)
				);

				if ( is_wp_error( $response ) ) {
					$summary['error']         = true;
					$summary['message']       = __( 'Error deleting post on subsite.', 'centralized-content-management' );
					$summary['debug_message'] = sprintf(
						// Translators: %1$d is the subsite ID, %2$s is the error message returned from the response.
						__( 'Error deleting post on subsite ID %1$d: %2$s', 'centralized-content-management' ),
						$subsite_blog_id,
						$response->get_error_message()
					);
				} else {
					// Get the response code and body.
					$response_code = wp_remote_retrieve_response_code( $response );

					if ( 200 === $response_code ) {
						$summary['error']   = false;
						$summary['message'] = __( 'Successfully deleted post on subsite.', 'centralized-content-management' );
					} else {
						$summary['error']   = true;
						$summary['message'] = sprintf(
							// Translators: %1$d is the subsite ID, %2$d is the HTTP response code returned from the request.
							__( 'Error deleting post on subsite ID %1$d: HTTP Response Code %2$d', 'centralized-content-management' ),
							$subsite_blog_id,
							intval( $response_code )
						);
					}
				}
			}
		}
	}

	/**
	 * Add a custom column to the sync post types list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function centralized_content_management_add_custom_column_to_sync_post_types( $columns ) {
		// Add a custom column after the title column.
		$columns['synced_sites'] = __( 'Synced Sites', 'centralized-content-management' );

		return $columns;
	}

	/**
	 * Populate the custom column with data.
	 *
	 * @param string $column The name of the column to populate.
	 * @param int    $post_id The ID of the current post.
	 */
	public function centralized_content_management_populate_custom_column_data( $column, $post_id ) {
		if ( 'synced_sites' === $column ) {
			$synced_subsite_data = get_post_meta( $post_id, '_synced_subsite_data', true );

			if ( ! empty( $synced_subsite_data ) ) {
				$total_sites   = count( $synced_subsite_data );
				$visible_sites = 3;
				?>
				<div class="synced-data <?php echo $total_sites > $visible_sites ? 'sync-data-wrap' : ''; ?>">
					<?php
					$index = 0;
					foreach ( $synced_subsite_data as $subsite_data ) {
						// Retirve sync data.
						$synced_data      = ( isset( $subsite_data['log_data'] ) && ! empty( $subsite_data['log_data'] ) ) ? $subsite_data['log_data'] : array();
						$synced_site_name = ( isset( $synced_data['site_name'] ) && ! empty( $synced_data['site_name'] ) ) ? $synced_data['site_name'] : '-';
						$synced_time      = ( isset( $synced_data['sync_time'] ) && ! empty( $synced_data['sync_time'] ) ) ? $synced_data['sync_time'] : '-';
						$synced_status    = ( isset( $synced_data['sync_status'] ) && ! empty( $synced_data['sync_status'] ) ) ? $synced_data['sync_status'] : '-';
						$item_class       = ( $index >= $visible_sites ) ? 'synced-data-hidden' : '';
						?>
						<div class="synced-data__item <?php echo esc_attr( $item_class ); ?>">
							<span class="synced-data-item__site_name"><?php echo esc_html( $synced_site_name ); ?></span>
							<span class="synced-data-item__sync_status"><?php echo esc_html( $synced_status ); ?></span>
							<span class="synced-data-item__sync_time"><?php echo esc_html( $synced_time ); ?></span>
						</div>
						<?php
						$index++;
					}
					?>
				</div>
				<?php
				if ( $total_sites > $visible_sites ) {
					?>
					<div class="show-more-toggle">
						<span><?php esc_html_e( 'Show More +', 'centralized-content-management' ); ?></span>
					</div>
					<?php
				}
			} else {
				echo '-';
			}
		}
	}

	/**
	 * Creates the database tables for content queue.
	 */
	private function create_db_tables() {
		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$charset_collate      = $wpdb->get_charset_collate();
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		$sync_subsites        = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();

		// Central queue table
		$central_queue_table = $wpdb->prefix . 'ccm_central_queue';
		$sql                 = "CREATE TABLE IF NOT EXISTS {$central_queue_table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL COMMENT 'Original post ID',
			post_type varchar(20) NOT NULL,
			target_sites text NOT NULL COMMENT 'Serialized array of target site IDs',
			sync_status text DEFAULT NULL COMMENT 'Serialized array storing sync status for each site',
			post_author bigint(20) NOT NULL,
			post_object longtext NOT NULL COMMENT 'Serialized post data',
			post_object_compare longtext NOT NULL COMMENT 'Serialized post compare data',
			sync_type varchar(20) NOT NULL DEFAULT 'create' COMMENT 'create/update/delete',
			created_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id_type (post_id, post_type)
		) $charset_collate;";
		dbDelta( $sql );

		if ( ! empty( $sync_subsites ) ) {
			foreach ( $sync_subsites as $site_id ) {
				$site_prefix         = $wpdb->get_blog_prefix( $site_id );
				$subsite_queue_table = $site_prefix . 'ccm_subsite_queue';

				$sql = "CREATE TABLE IF NOT EXISTS {$subsite_queue_table} (
					id bigint(20) NOT NULL AUTO_INCREMENT,
					central_id bigint(20) NOT NULL COMMENT 'ID from central queue table',
					central_post_id bigint(20) NOT NULL COMMENT 'Original post ID from central site',
					post_type varchar(20) NOT NULL,
					local_post_id bigint(20) DEFAULT 0 COMMENT 'Post ID in the subsite if exists',
					sync_status varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected/failed',
					sync_type varchar(20) NOT NULL DEFAULT 'create' COMMENT 'create/update/delete',
					approved_by bigint(20) DEFAULT NULL,
					reject_comment text DEFAULT NULL,
					created_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
					modified_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY central_post (central_post_id),
					KEY sync_status (sync_status)
				) $charset_collate;";

				dbDelta( $sql );
			}
		}

	}
}

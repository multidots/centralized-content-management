<?php
/**
 * Bulk Sync Manager for the MD Centralized Content Management plugin.
 *
 * This class handles bulk synchronization operations across multiple subsites
 * within the WordPress multisite network. It provides methods to efficiently
 * process large batches of posts, taxonomies, and media, ensuring consistent
 * content management across all selected subsites.
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
class Bulk_Sync_Manager {

	use Singleton;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Central database table name prefix.
	 *
	 * @var string
	 */
	private $cenral_db_prefix = '';

	/**
	 * Central table name for the content queue.
	 *
	 * @var string
	 */
	private $central_table_name = '';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Check if the current site is the central site.
		if ( Utils::is_central_site() ) {
			global $wpdb;

			$this->wpdb               = $wpdb;
			$this->central_table_name = $this->wpdb->prefix . 'ccm_central_queue';

			$this->setup_bulk_sync_hooks();
		}
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_bulk_sync_hooks() {
		add_action( 'admin_menu', array( $this, 'centralized_content_management_add_plugin_page_to_central_site' ) );
		add_action( 'wp_ajax_handle_bulk_sync_filter', array( $this, 'centralized_content_management_handle_bulk_sync_filter' ) );
		add_action( 'wp_ajax_handle_bulk_sync_process', array( $this, 'centralized_content_management_handle_bulk_sync_process' ) );
		add_action( 'wp_ajax_add_all_post_records', array( $this, 'centralized_content_management_add_all_post_records' ) );
	}

	/**
	 * Function is used to create bulk sync manager page to central site.
	 */
	public function centralized_content_management_add_plugin_page_to_central_site() {
		$central_site_id = Utils::get_central_site_id();
		$current_site_id = get_current_blog_id();

		// Check if the current site is the selected central site.
		if ( $current_site_id === $central_site_id ) {
			add_submenu_page(
				'md-ccm',
				__( 'Bulk Sync', 'centralized-content-management' ),
				__( 'Bulk Sync', 'centralized-content-management' ),
				'manage_options',
				'md-ccm-bulk-sync',
				array( $this, 'centralized_content_management_render_bulk_sync_manager_page' )
			);
		}
	}

	/**
	 * Function to render the central setting page content.
	 */
	public function centralized_content_management_render_bulk_sync_manager_page() {
		// Get central settings option data.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		$selected_subsites    = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();
		$central_site         = isset( $centralized_content_manager_network_settings['central_site'] ) ? $centralized_content_manager_network_settings['central_site'] : 0;
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
				<p><?php esc_html_e( 'Use this page to sync multiple posts across selected subsites in bulk. You can easily select the posts you want to sync, and the system will ensure the content is synchronized across all the specified subsites with just a few clicks.', 'centralized-content-management' ); ?></p>
			</div>
			<div class="md-ccm-body">
				<div class="nav-tab-wrapper">
					<?php
					if ( Utils::is_central_site() ) { // VT - 20241112.
						?>
						<div class="nav-tab-item">
							<a href="?page=md-ccm-sync-settings" class="nav-tab"><span><?php esc_html_e( 'Sync Settings', 'centralized-content-management' ); ?></span></a>
						</div>
						<div class="nav-tab-item nav-tab-active">
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
					<form type="POST" id="bulkSyncFilterForm">
						<table class="form-table ccm-form-table">
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Select Subsites', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="ccm-checkbox-group">
										<?php
										if ( ! empty( $selected_subsites ) && is_array( $selected_subsites ) ) {
											foreach ( $selected_subsites as $selected_subsite ) {
												$subsite_id   = $selected_subsite;
												$subsite_name = get_blog_option( $subsite_id, 'blogname' );

												if ( $central_site === (int) $subsite_id ) {
													continue;
												}
												?>
												<div class="ccm-checkbox-wrap">
													<label for="<?php echo esc_attr( 'site' . $subsite_id ); ?>">
														<input type="checkbox" name="sync_sites_list[]" class="ccm-checkbox bulk-sync-subsite" id="<?php echo esc_attr( 'site' . $subsite_id ); ?>" value="<?php echo esc_attr( $subsite_id ); ?>" />
														<span class="ccm-switch"></span>
														<span><?php echo esc_html( $subsite_name ); ?></span>
													</label>
												</div>
												<?php
											}
										} else {
											?>
											<div class="ccm-notice ccm-notice-info">
												<p><?php esc_html_e( 'To include subsites in the sync feature, please select them from the "Subsites for Sync" field on the Centralized Content Management settings page in the Network.', 'centralized-content-management' ); ?></p>
											</div>
											<?php
										}
										?>
									</div>
									<p class="description"><i><?php esc_html_e( 'Choose which subsites should participate in the synchronization process. These selected subsites will receive the content and settings synced from the central site.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Select Posts', 'centralized-content-management' ); ?></label>
								</th>
								<td>
									<div class="md-ccm-bulk-sync-filter-wrapper">
										<div id="post-type-taxonomy-selection">
											<select id="filter_by_post_type" name="filter_by_post_type">
												<option value=""><?php esc_html_e( 'Select Post Type', 'centralized-content-management' ); ?></option>
												<?php
												if ( isset( $centralized_content_management_settings['post_types'] ) && ! empty( $centralized_content_management_settings['post_types'] ) ) {
													foreach ( $centralized_content_management_settings['post_types'] as $post_type ) {
														$post_type_object = get_post_type_object( $post_type );
														?>
														<option value="<?php echo esc_attr( $post_type_object->name ); ?>"><?php echo esc_html( $post_type_object->label ); ?></option>
														<?php
													}
												}
												?>
											</select>
											<input type="text" name="filter_by_post_title" class="regular-text" id="filter_by_post_title" placeholder="Search..." autocomplete="off" />
										</div>
										<div id="post-selection-container">
											<div class="filtered-posts">
												<ul id="filtered-posts-list">
													<li class="response-item"><span><?php esc_html_e( 'No data found.', 'centralized-content-management' ); ?></span></li>
												</ul>
											</div>
											<div class="separator"></div>
											<div class="selected-posts">
												<ul id="selected-posts-list"></ul>
												<input type="hidden" id="selected-post-ids" name="selected_post_ids" value="">
											</div>
										</div>
									</div>
									<p class="description"><i><?php esc_html_e( 'To sync posts, select the desired post type to filter the list, then choose the posts you want to sync and click to add them to the sync list.', 'centralized-content-management' ); ?></i></p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" class="button button-primary" id="bulkSyncButton"><?php esc_html_e( 'Sync Posts', 'centralized-content-management' ); ?></button>
						</p>
						<div id="ccm-notice"></div>
						<div class="ccm-notice ccm-notice-info">
							<p><strong><?php esc_html_e( 'Note: ', 'centralized-content-management' ); ?></strong><?php esc_html_e( 'When syncing posts to the selected sites, please note that if the sites are using different themes, the styling may not appear as intended. However, rest assured that the data will sync correctly across all sites.', 'centralized-content-management' ); ?></p>
						</div>
					</form>
					<div id="syncProgressContainer" style="display: none;">
						<h2 class="title"><?php esc_html_e( 'Sync Progress', 'centralized-content-management' ); ?></h2>
						<div class="progress-bar-container">
							<progress id="syncProgressBar" value="0" max="100" class="progress-bar"></progress>
							<div class="progress-info">
								<span id="syncProgressBarPercentage">0%</span>
							</div>
						</div>
						<div id="syncProgressBarMessage" class="progress-message"></div>
						<div id="syncProgressTableWrap">
							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Post Name', 'centralized-content-management' ); ?></th>
										<th><?php esc_html_e( 'Site Name', 'centralized-content-management' ); ?></th>
										<th><?php esc_html_e( 'Sync Status', 'centralized-content-management' ); ?></th>
									</tr>
								</thead>
								<tbody id="syncProgress"></tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<?php Utils::centralized_content_management_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Filter bulk sync data ajax callback.
	 */
	public function centralized_content_management_handle_bulk_sync_filter() {
		$filter_by_post_title = filter_input( INPUT_POST, 'filter_by_post_title', FILTER_SANITIZE_SPECIAL_CHARS );
		$filter_by_post_type  = filter_input( INPUT_POST, 'filter_by_post_type', FILTER_SANITIZE_SPECIAL_CHARS );
		$paged                = filter_input( INPUT_POST, 'paged', FILTER_VALIDATE_INT );
		$filter_response      = array();
		$filter_posts_list    = array();

		// Prepare filter query arguments array.
		$filter_args = array(
			'post_type'      => ! empty( $filter_by_post_type ) ? $filter_by_post_type : '-',
			'posts_per_page' => 10,
			'post_status'    => 'publish',
			'paged'          => $paged,
		);

		// Filter by post title.
		if ( $filter_by_post_title ) {
			$filter_args['s'] = $filter_by_post_title;
		}

		// Filter WP_Query.
		$filter_query = new WP_Query( $filter_args );

		// Check if posts are exists or not.
		if ( $filter_query->have_posts() ) {
			while ( $filter_query->have_posts() ) {
				$filter_query->the_post();

				$filter_posts_list[] = array(
					'post_id'    => get_the_ID(),
					'post_title' => esc_html( get_the_title() ),
				);
			}

			$filter_response['success'] = true;
			$filter_response['message'] = sprintf(
				// Translators: %1$d is the number of posts retrieved, %2$d is the page number.
				__( '%1$d posts successfully retrieved on page %2$d', 'centralized-content-management' ),
				$filter_query->post_count,
				$paged
			);
			$filter_response['posts']       = $filter_posts_list;
			$filter_response['found_posts'] = $filter_query->found_posts;
			$filter_response['post_type']   = $filter_by_post_type;

			// Reset postdata.
			wp_reset_postdata();
		} else {
			$filter_response['success']     = false;
			$filter_response['message']     = __( 'No data found', 'centralized-content-management' );
			$filter_response['found_posts'] = $filter_query->found_posts;
			$filter_response['post_type']   = $filter_by_post_type;
		}

		wp_send_json( $filter_response );
	}

	/**
	 * Function is used to implement bulk sync.
	 */
	public function centralized_content_management_handle_bulk_sync_process() {
		// Define WP_IMPORTING.
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		$selected_sites             = filter_input( INPUT_POST, 'selected_sites', FILTER_SANITIZE_SPECIAL_CHARS );
		$selected_sites_array       = ! empty( $selected_sites ) ? explode( ',', $selected_sites ) : array();
		$selected_posts             = filter_input( INPUT_POST, 'selected_posts', FILTER_SANITIZE_SPECIAL_CHARS );
		$selected_posts_array       = ! empty( $selected_posts ) ? explode( ',', $selected_posts ) : array();
		$current_batch              = filter_input( INPUT_POST, 'current_batch', FILTER_VALIDATE_INT );
		$batch_size                 = 2;
		$centralized_content_management_settings       = get_option( 'centralized_content_management_settings' );
		$total_posts                = count( $selected_posts_array );
		$bulk_sync_response         = array();
		$bulk_sync_response['logs'] = array();

		// Validate sites selection.
		if ( empty( $selected_sites_array ) ) {
			$bulk_sync_response['success'] = false;
			$bulk_sync_response['message'] = __( 'Please select at least one site to proceed with the bulk sync.', 'centralized-content-management' );

			wp_send_json( $bulk_sync_response );
		}

		// Validate posts selection.
		if ( empty( $selected_posts_array ) ) {
			$bulk_sync_response['success'] = false;
			$bulk_sync_response['message'] = __( 'Please select at least one post to proceed with the bulk sync.', 'centralized-content-management' );

			wp_send_json( $bulk_sync_response );
		}

		// Chunk the posts array into batches of 10.
		$posts_batches = array_chunk( $selected_posts_array, $batch_size );

		// Check if the current batch exists.
		if ( isset( $posts_batches[ $current_batch ] ) ) {
			// Get the current batch of posts.
			$posts_to_sync = $posts_batches[ $current_batch ];

			// Initialize empty array for bulk sync site ids and primary ids.
			$bulk_sync_subsite_ids = array();

			// Initalize empty array for disable sync posts.
			$disable_sync_post_ids = array();

			foreach ( $posts_to_sync as $post_id ) {
				// Get post data by post_id.
				$post_obj = get_post( $post_id );

				// Check if content queue is enabled.
				if ( Utils::is_content_queue_enabled() ) {
					// Prepare post data.
					$update               = true;
					$post_type            = $post_obj->post_type;
					$current_user         = get_current_user_id();
					$subsite_synced_data  = get_post_meta( $post_id, '_synced_subsite_data', true );
					$prepared_post_object = Content_Queue_Helper::centralized_content_management_sync_prepare_post_object( $post_id, $post_obj, 'bulk' );
					$disable_sync_data    = get_post_meta( $post_id, '_ccm_disable_sync', true );
					$disable_sync_data    = isset( $disable_sync_data ) && 'true' === $disable_sync_data ? 'true' : 'false';

					// Check if disable sync setting enable or not for the post.
					if ( 'true' === $disable_sync_data ) {
						$disable_sync_post_ids[] = $post_id;

						foreach ( $selected_sites_array as $subsite ) {
							$log_array = array();

							// Prepare log array for response.
							$log_array['log_data']        = array(
								'post_name'   => $post_obj->post_title,
								'site_name'   => Utils::get_blogname( $subsite ),
								'sync_status' => __( 'Skipped', 'centralized-content-management' ),
								'sync_note'   => __( 'The sync setting for this post is disabled, so it will not be synchronized.', 'centralized-content-management' ),
							);
							$bulk_sync_response['logs'][] = $log_array;
						}

						$bulk_sync_response['success'] = true;
						// $bulk_sync_response['message'] = sprintf(
						// 	// translators: %1$d represents the current batch number, %2$d represents the total number of batches.
						// 	__( 'Batch %1$d of %2$d processed successfully.', 'centralized-content-management' ),
						// 	$current_batch + 1,
						// 	count( $posts_batches )
						// );
						// $bulk_sync_response['current_batch']   = $current_batch;
						// $bulk_sync_response['total_batches']   = count( $posts_batches );
						// $bulk_sync_response['posts_processed'] = $posts_to_sync;
						// $bulk_sync_response['total_posts']     = $total_posts;
						// $bulk_sync_response['process_message'] = 50 < $total_posts ? __( 'The sync process has started and you have selected more than 50 posts to sync, so the sync process may take some time. Please be patient and stay on this page without reloading it until the process is complete.', 'centralized-content-management' ) : __( 'The sync process has started, so sync process may take some time. Please be patient and stay on this page without reloading it until the process is complete.!!', 'centralized-content-management' );

						// wp_send_json( $bulk_sync_response );
					}

					if ( ! empty( $disable_sync_post_ids ) && in_array( $post_id, $disable_sync_post_ids, true ) ) {
						continue;
					}

					if ( ! $update ) {
						$sync_type = 'create';
					} else {
						$sync_type = ( isset( $post_obj->post_status ) && 'trash' === $post_obj->post_status ) ? 'delete' : 'update';
					}

					// Set initial sync status for each subsite.
					$sync_status = array();
					if ( ! empty( $selected_sites_array ) && is_array( $selected_sites_array ) ) {
						foreach ( $selected_sites_array as $subsite ) {
							$sync_status[ $subsite ] = 'pending';
						}
					}

					// Prepare data for insertion.
					$data = array(
						'post_id'             => $post_id,
						'post_type'           => $post_type,
						'target_sites'        => maybe_serialize( $selected_sites_array ),
						'sync_type'           => $sync_type,
						'sync_status'         => maybe_serialize( $sync_status ),
						'created_time'        => current_time( 'mysql' ),
						'modified_time'       => current_time( 'mysql' ),
						'post_author'         => $current_user,
						'post_object'         => $prepared_post_object['post_object'],
						'post_object_compare' => $prepared_post_object['post_object_compare'],
					);

					// Check if this post is already in custom table or not.
					$central_table_name = esc_sql( $this->central_table_name );
					$existing_post      = $this->wpdb->get_row(
						$this->wpdb->prepare( // phpcs:ignore
							"SELECT `id`, `sync_status` FROM {$this->central_table_name} WHERE `post_id` = %d ORDER BY `id` DESC", // phpcs:ignore
							$post_id // phpcs:ignore
						),
						ARRAY_A
					);

					if ( ! empty( $existing_post ) && isset( $existing_post['id'] ) ) {
						// Deserialize the existing sync_status.
						$sync_status_array = maybe_unserialize( $existing_post['sync_status'] );

						if ( is_array( $sync_status_array ) ) {
							// Mark all subsites' sync statuses as 'expired'.
							foreach ( $sync_status_array as $subsite => $status ) {
								$sync_status_array[ $subsite ] = 'expired';
							}

							// Serialize the updated array.
							$updated_sync_status = maybe_serialize( $sync_status_array );

							// Update the sync_status and sync_type fields for this specific record.
							$this->wpdb->update(
								$this->central_table_name,
								array(
									'sync_status' => $updated_sync_status,
									'sync_type'   => 'expired',
								),
								array( 'id' => $existing_post['id'] ),
								array( '%s', '%s' ),
								array( '%d' )
							);
						}
					}

					// Insert new central queue record.
					$central_record = $this->wpdb->insert(
						$this->central_table_name,
						$data,
						array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
					);

					if ( false !== $central_record && ! empty( $selected_sites_array ) ) {
						$inserted_id = $this->wpdb->insert_id;

						$post_data                    = array();
						$post_data['central_id']      = $inserted_id;
						$post_data['central_post_id'] = $post_id;
						$post_data['post_type']       = $post_type;
						$post_data['sync_status']     = 'pending';
						$post_data['sync_type']       = $sync_type;
						$post_data['created_time']    = current_time( 'mysql' );
						$post_data['modified_time']   = current_time( 'mysql' );

						// Insert new entry in central queue for each selected subsite.
						foreach ( $selected_sites_array as $subsite ) {
							// Initialize log array for content queue logs.
							$log_array                  = array();
							$post_data['local_post_id'] = isset( $subsite_synced_data[ $subsite ]['subsite_post_id'] ) ? $subsite_synced_data[ $subsite ]['subsite_post_id'] : 0;
							$site_prefix                = $this->wpdb->get_blog_prefix( $subsite );
							$subsite_queue_table        = $site_prefix . 'ccm_subsite_queue';

							$existing_posts = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT `central_id`, `sync_status` FROM {$subsite_queue_table} WHERE `central_post_id` = %d AND `sync_status` = 'pending'", $post_data['central_post_id'] ), ARRAY_A ); // phpcs:ignore

							if ( ! empty( $existing_posts ) ) {
								// Update Subsite Entries.
								$this->wpdb->query(
									$this->wpdb->prepare( // phpcs:ignore
										// phpcs:ignore
										"UPDATE {$subsite_queue_table} SET `sync_status` = 'expired'
											WHERE `central_post_id` = %d AND `sync_status` = 'pending'",
										$post_data['central_post_id'] // phpcs:ignore
									)
								);
							}

							// Continue if local post id is 0 in subsites.
							if ( ( isset( $post_data['sync_type'] ) && 'delete' === $post_data['sync_type'] ) && ( isset( $post_data['local_post_id'] ) && 0 === (int) $post_data['local_post_id'] ) ) {
								continue;
							}

							$sql_response = $this->wpdb->query(
								$this->wpdb->prepare( // phpcs:ignore
									// phpcs:ignore
									"INSERT INTO {$subsite_queue_table}
								(`central_id`, `central_post_id`, `post_type`, `sync_status`, `sync_type`, `created_time`, `modified_time`, `local_post_id`)
								VALUES ( %d, %d, %s, %s, %s, %s, %s, %d )",
									$post_data // phpcs:ignore
								)
							);

							// Get the last inserted ID
							$last_inserted_id = $this->wpdb->insert_id;

							// Prepare array.
							$bulk_sync_subsite_ids[ $subsite ] = $last_inserted_id;

							// Add selected subsite ids into central postmeta.
							if ( isset( $post_data['local_post_id'] ) && 0 === (int) $post_data['local_post_id'] ) {
								update_post_meta( $post_id, '_bulk_sync_subsite_ids', $bulk_sync_subsite_ids );
							}

							// Prepare log array for response.
							$log_array['log_data']        = array(
								'post_name'   => $post_obj->post_title,
								'site_name'   => Utils::get_blogname( $subsite ),
								'sync_status' => __( 'Added to Queue', 'centralized-content-management' ),
							);
							$bulk_sync_response['logs'][] = $log_array;
						}
					}

					// Send response.
					$bulk_sync_response['success'] = true;
				} else {
					// Get the disable sync setting from the post's custom metabox.
					$disable_sync_data = get_post_meta( $post_id, '_ccm_disable_sync', true );

					// Prepare post data for syncing.
					$post_data = array(
						'post_title'        => $post_obj->post_title,
						'post_content'      => $post_obj->post_content,
						'post_status'       => $post_obj->post_status,
						'post_type'         => $post_obj->post_type,
						'post_name'         => $post_obj->post_name,
						'post_date'         => $post_obj->post_date,
						'post_date_gmt'     => $post_obj->post_date_gmt,
						'post_modified'     => $post_obj->post_modified,
						'post_modified_gmt' => $post_obj->post_modified_gmt,
						'central_post_id'   => $post_id,
						'selected_subsites' => $selected_sites_array,
						'disable_sync'      => $disable_sync_data,
					);

					// Check if taxonomy syncing setting is enable or not.
					if ( isset( $centralized_content_management_settings['taxonomies'] ) && ! empty( $centralized_content_management_settings['taxonomies'] ) ) {
						$post_data['taxonomies'] = Sync_Process::centralized_content_management_get_taxonomies_for_post( $post_id );
					}

					// Check if post meta syncing setting is enable or not (Reference).
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
						$post_data['content_media']  = Sync_Process::centralized_content_management_get_media_urls_and_ids_from_post_content( $post_obj->post_content );
					}

					// Syncing of User Associations.
					if ( isset( $centralized_content_management_settings['sync_users'] ) && $centralized_content_management_settings['sync_users'] ) {
						$post_data['post_author'] = $post_obj->post_author;
					}

					// Sync post data to selected subsites.
					$response = Sync_Process::centralized_content_management_sync_process( $post_id, $selected_sites_array, $post_data, 'bulk' );

					// Combine all post sync response to logs array.
					$bulk_sync_response['logs']    = array_merge( $bulk_sync_response['logs'], $response );
					$bulk_sync_response['success'] = true;
				}
			}

			$bulk_sync_response['success'] = true;
			$bulk_sync_response['message'] = sprintf(
				// translators: %1$d represents the current batch number, %2$d represents the total number of batches.
				__( 'Batch %1$d of %2$d processed successfully.', 'centralized-content-management' ),
				$current_batch + 1,
				count( $posts_batches )
			);
			$bulk_sync_response['current_batch']   = $current_batch;
			$bulk_sync_response['total_batches']   = count( $posts_batches );
			$bulk_sync_response['posts_processed'] = $posts_to_sync;
			$bulk_sync_response['total_posts']     = $total_posts;
			$bulk_sync_response['process_message'] = 50 < $total_posts ? __( 'The sync process has started and you have selected more than 50 posts to sync, so the sync process may take some time. Please be patient and stay on this page without reloading it until the process is complete.', 'centralized-content-management' ) : __( 'The sync process has started, so sync process may take some time. Please be patient and stay on this page without reloading it until the process is complete.!!', 'centralized-content-management' );
		} else {
			// No more batches to process.
			$bulk_sync_response['success'] = false;
			$bulk_sync_response['message'] = __( 'No posts were processed.', 'centralized-content-management' );
		}

		wp_send_json( $bulk_sync_response );
	}

	/**
	 * FUnction is used to add all post records ajax callback.
	 */
	public function centralized_content_management_add_all_post_records() {
		$the_post_type = filter_input( INPUT_POST, 'post_type', FILTER_SANITIZE_SPECIAL_CHARS );
		$post_response = array();
		$posts_array   = array();

		if ( empty( $the_post_type ) ) {
			$post_response['success'] = false;
			$post_response['message'] = __( 'Post type does not exists.', 'centralized-content-management' );

			wp_send_json( $post_response );
		}

		// Prepare query args array.
		$post_query_args = array(
			'post_type'      => $the_post_type,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		// Post query.
		$post_query = new WP_Query( $post_query_args );

		if ( $post_query->have_posts() ) {
			while ( $post_query->have_posts() ) {
				$post_query->the_post();

				$post_title    = get_the_title();
				$post_id       = get_the_ID();
				$posts_array[] = array(
					'post_id'    => $post_id,
					'post_title' => $post_title,
				);
			}
		} else {
			$post_response['success'] = false;
			$post_response['message'] = __( 'Posts not found.', 'centralized-content-management' );
		}

		$post_response['success'] = true;
		$post_response['message'] = sprintf(
			// Translators: %d is the number of posts found.
			__( '%d posts found.', 'centralized-content-management' ),
			$post_query->found_posts
		);
		$post_response['posts_array'] = $posts_array;

		wp_send_json( $post_response );
	}
}
